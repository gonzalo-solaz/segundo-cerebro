<?php
// =====================================================================
//  Pase firmado entre el segundo cerebro y finanzas (3/10/2026: un solo
//  acceso). El segundo cerebro es la puerta: a un administrador le da un
//  pase de un minuto y un solo uso con el que finanzas abre su propia sesión
//  sin pedir contraseña. Al salir, otro pase cierra la sesión de la otra app.
//
//  Formato: base64url(JSON) «.» base64url(HMAC-SHA256(lo anterior, PASE_CLAVE)).
//  El JSON lleva t (tipo: entrar | salir), e (email), x (caduca, unix),
//  n (nonce) y a (adónde ir dentro de finanzas).
//
//  ESTE ARCHIVO ESTÁ COPIADO TAL CUAL en finanzas-personales/includes/pase.php.
//  Si cambias uno, cambia el otro: las dos pruebas comprueban el mismo pase
//  de ejemplo (pruebas: «pase de ejemplo compartido»).
// =====================================================================

const PASE_SEGUNDOS = 60;

function pase_b64(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function pase_b64_leer(string $txt): string {
    return (string)base64_decode(strtr($txt, '-_', '+/'), true);
}

function pase_crear(string $clave, array $datos, ?int $ahora = null): string {
    if ($clave === '') throw new RuntimeException('Falta PASE_CLAVE.');
    $datos += ['x' => ($ahora ?? time()) + PASE_SEGUNDOS, 'n' => bin2hex(random_bytes(12))];
    $cuerpo = pase_b64(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $cuerpo . '.' . pase_b64(hash_hmac('sha256', $cuerpo, $clave, true));
}

/** Los datos del pase si la firma es buena, es del tipo pedido y no ha caducado; si no, null. */
function pase_leer(string $clave, string $pase, string $tipo, ?int $ahora = null): ?array {
    if ($clave === '' || substr_count($pase, '.') !== 1) return null;
    [$cuerpo, $firma] = explode('.', $pase);
    if (!hash_equals(pase_b64(hash_hmac('sha256', $cuerpo, $clave, true)), $firma)) return null;
    $datos = json_decode(pase_b64_leer($cuerpo), true);
    if (!is_array($datos) || ($datos['t'] ?? '') !== $tipo) return null;
    $ahora = $ahora ?? time();
    $x = (int)($datos['x'] ?? 0);
    // Ni caducado ni con una caducidad absurda (un pase no vale más de 2 minutos).
    if ($x < $ahora || $x > $ahora + 2 * PASE_SEGUNDOS) return null;
    return $datos;
}

// Solo rutas de la propia app: «nomina.php», «revisar.php?mes=2026-09». Nada
// de URLs completas ni subir de carpeta.
function pase_destino_valido(string $a): string {
    return preg_match('#^[a-z0-9_-]+\.php(\?[A-Za-z0-9_=&%.-]*)?$#', $a) ? $a : 'index.php';
}
