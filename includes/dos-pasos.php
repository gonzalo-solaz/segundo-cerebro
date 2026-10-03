<?php
// =====================================================================
//  Verificación en dos pasos (TOTP, RFC 6238): el código de 6 cifras que
//  cambia cada 30 s en Google Authenticator, Microsoft Authenticator, 1Password...
//
//  Obligatoria para los administradores desde el 3/10/2026: con un solo
//  acceso, la contraseña del segundo cerebro abre también finanzas (datos
//  bancarios), y la sesión dura días en el móvil. Los miembros pueden
//  activarla si quieren.
//
//  Sin librerías ni QR (cero recursos externos): se da la clave para
//  escribirla en la app y un enlace otpauth:// que, en el móvil, la abre sola.
//  Si se pierde el móvil: uno de los 8 códigos de recuperación, o que otro
//  administrador la quite desde Ajustes.
// =====================================================================

const TOTP_PERIODO = 30;
const TOTP_CIFRAS = 6;
const RECUPERACION_CODIGOS = 8;

function base32_codificar(string $bin): string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $trozo) $out .= $alfabeto[bindec(str_pad($trozo, 5, '0'))];
    return $out;
}

function base32_decodificar(string $txt): string {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $txt = strtoupper(preg_replace('/[\s=-]/', '', $txt));
    $bits = '';
    foreach (str_split($txt) as $c) {
        $p = strpos($alfabeto, $c);
        if ($p === false) return '';
        $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_nuevo_secreto(): string {
    return base32_codificar(random_bytes(20));   // 160 bits, lo que recomienda el RFC
}

function totp_codigo(string $secreto_b32, int $paso, int $cifras = TOTP_CIFRAS): string {
    $hash = hash_hmac('sha1', pack('N2', intdiv($paso, 0x100000000), $paso & 0xFFFFFFFF), base32_decodificar($secreto_b32), true);
    $o = ord($hash[19]) & 0x0F;
    $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($n % (10 ** $cifras)), $cifras, '0', STR_PAD_LEFT);
}

/**
 * El paso (intervalo de 30 s) en que vale el código, o null. Admite el
 * anterior y el siguiente por si el reloj del móvil va algo desfasado, y
 * nunca uno igual o anterior a $ultimo_paso (un código no vale dos veces).
 */
function totp_paso_valido(string $secreto_b32, string $codigo, ?int $ultimo_paso, ?int $ahora = null): ?int {
    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== TOTP_CIFRAS) return null;
    $actual = intdiv($ahora ?? time(), TOTP_PERIODO);
    foreach ([0, -1, 1] as $d) {
        $paso = $actual + $d;
        if ($ultimo_paso !== null && $paso <= $ultimo_paso) continue;
        if (hash_equals(totp_codigo($secreto_b32, $paso), $codigo)) return $paso;
    }
    return null;
}

function totp_enlace(string $secreto_b32, string $email): string {
    $emisor = NOMBRE_APP;
    return 'otpauth://totp/' . rawurlencode($emisor . ':' . $email) . '?secret=' . $secreto_b32
         . '&issuer=' . rawurlencode($emisor) . '&digits=' . TOTP_CIFRAS . '&period=' . TOTP_PERIODO;
}

// En grupos de 4 para teclearla sin perderse.
function totp_clave_legible(string $secreto_b32): string {
    return implode(' ', str_split($secreto_b32, 4));
}

function dos_pasos_activa(array $u): bool {
    return trim((string)($u['totp_secreto'] ?? '')) !== '';
}

function dos_pasos_obligatoria(array $u): bool {
    return ($u['rol'] ?? '') === 'admin';
}

/** Genera los códigos de recuperación, guarda sus hashes y los devuelve en claro (se enseñan UNA vez). */
function nuevos_codigos_recuperacion(PDO $pdo, int $usuario_id): array {
    $codigos = [];
    $hashes = [];
    for ($i = 0; $i < RECUPERACION_CODIGOS; $i++) {
        $c = substr(contrasena_temporal(), 0, 9);   // «abcd-efgh»
        $codigos[] = $c;
        $hashes[] = password_hash($c, PASSWORD_DEFAULT);
    }
    $pdo->prepare('UPDATE usuarios SET totp_recuperacion = ? WHERE id = ?')->execute([json_texto($hashes), $usuario_id]);
    return $codigos;
}

function activar_dos_pasos(PDO $pdo, int $usuario_id, string $secreto_b32, string $codigo): array {
    $paso = totp_paso_valido($secreto_b32, $codigo, null);
    if ($paso === null) throw new ErrorValidacion(['Ese código no es el que da la app ahora mismo. Comprueba la clave y la hora del móvil.']);
    $pdo->prepare('UPDATE usuarios SET totp_secreto = ?, totp_ultimo_paso = ? WHERE id = ?')->execute([$secreto_b32, $paso, $usuario_id]);
    anotar($pdo, $usuario_id, 'activó la verificación en dos pasos');
    return nuevos_codigos_recuperacion($pdo, $usuario_id);
}

function quitar_dos_pasos(PDO $pdo, int $usuario_id, ?int $quien = null): void {
    $pdo->prepare('UPDATE usuarios SET totp_secreto = NULL, totp_ultimo_paso = NULL, totp_recuperacion = NULL WHERE id = ?')
        ->execute([$usuario_id]);
    $u = usuario($pdo, $usuario_id);
    anotar($pdo, $quien ?? $usuario_id, 'quitó la verificación en dos pasos de ' . ($u['nombre'] ?? '#' . $usuario_id));
}

/**
 * Comprueba un código de la app o uno de recuperación (que se gasta).
 * Devuelve 'app', 'recuperacion' o null.
 */
function comprobar_segundo_paso(PDO $pdo, array $u, string $codigo): ?string {
    if (!dos_pasos_activa($u)) return null;
    $codigo = trim($codigo);
    $paso = totp_paso_valido((string)$u['totp_secreto'], $codigo, isset($u['totp_ultimo_paso']) ? (int)$u['totp_ultimo_paso'] : null);
    if ($paso !== null) {
        $pdo->prepare('UPDATE usuarios SET totp_ultimo_paso = ? WHERE id = ?')->execute([$paso, $u['id']]);
        return 'app';
    }
    $limpio = strtolower(preg_replace('/\s/', '', $codigo));
    $hashes = json_array($u['totp_recuperacion'] ?? null);
    foreach ($hashes as $i => $h) {
        if (password_verify($limpio, (string)$h)) {
            unset($hashes[$i]);
            $pdo->prepare('UPDATE usuarios SET totp_recuperacion = ? WHERE id = ?')->execute([json_texto(array_values($hashes)), $u['id']]);
            anotar($pdo, (int)$u['id'], 'entró con un código de recuperación (le quedan ' . count($hashes) . ')');
            return 'recuperacion';
        }
    }
    return null;
}

function codigos_recuperacion_restantes(array $u): int {
    return count(json_array($u['totp_recuperacion'] ?? null));
}
