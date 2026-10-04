<?php
// =====================================================================
//  Helpers sin dependencias: formato, fechas, URLs y redirecciones.
//  Los usan las páginas, la API, el cron y las pruebas. Aquí no se toca
//  la base de datos (flash() solo escribe en $_SESSION).
// =====================================================================

// Error de validación con la lista de problemas, para enseñarlos todos de
// golpe en el formulario (o devolverlos en la API) en vez de uno a uno.
class ErrorValidacion extends RuntimeException {
    public array $errores;
    public function __construct(array $errores) {
        parent::__construct(implode(' ', $errores));
        $this->errores = $errores;
    }
}

// ---------------------------------------------------------------------
//  HTML, URLs y cabeceras
// ---------------------------------------------------------------------
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $ruta = ''): string {
    return BASE_URL . '/' . ltrim($ruta, '/');
}

// La versión es la fecha del archivo: cada despliegue lo reescribe, así que
// los móviles piden el CSS/JS nuevo sin que nadie suba ASSETS_VERSION a mano
// (con el despliegue automático desde GitHub, nadie se acordaría).
function asset(string $ruta): string {
    $archivo = dirname(__DIR__) . '/assets/' . $ruta;
    $v = is_file($archivo) ? (string)filemtime($archivo) : (string)ASSETS_VERSION;
    return url('assets/' . $ruta) . '?v=' . rawurlencode($v);
}

// Hostinger sirve PHP detrás de un proxy: %{HTTPS} no siempre llega a "on",
// pero X-Forwarded-Proto sí. Solo se usa para marcar la cookie como segura.
function es_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function enviar_cabeceras_seguridad(): void {
    if (headers_sent()) return;
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Referrer-Policy: no-referrer', true);
    header('X-Content-Type-Options: nosniff', true);
    header('X-Frame-Options: DENY', true);
    // Solo se ejecuta JS servido desde aquí mismo (assets/*.js): ni CDN ni
    // scripts en línea. Es una app con datos de salud de la familia.
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
         . "script-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'", true);
    // Que el botón «atrás» del navegador no enseñe una página privada desde
    // la caché después de cerrar sesión.
    header('Cache-Control: private, no-store', true);
}

function redirigir(string $ruta): void {
    $destino = preg_match('#^https?://#', $ruta) ? $ruta : url($ruta);
    $GLOBALS['SC_REDIRECCION'] = $destino;   // las pruebas lo leen (en CLI no hay cabeceras)
    if (!headers_sent()) header('Location: ' . $destino);
    exit;
}

// Solo deja volver a páginas de esta misma app ("elemento.php?id=3"):
// nunca a una URL de fuera que alguien haya colado en el formulario.
function volver_seguro($volver, string $defecto = 'index.php'): string {
    $v = (string)$volver;
    return preg_match('/^[a-z0-9\-]+\.php(?:[?#][A-Za-z0-9_\-=&#.:]*)?$/', $v) ? $v : $defecto;
}

function flash(string $tipo, string $texto): void {
    $_SESSION['flash'][] = ['tipo' => $tipo, 'texto' => $texto];
}

function flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

// ---------------------------------------------------------------------
//  Fechas. Siempre 'AAAA-MM-DD' por dentro; el formato humano, al pintar.
// ---------------------------------------------------------------------
const MESES       = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                     'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

// SC_HOY solo existe en las pruebas, para que el resultado no dependa del día.
function hoy(): string {
    return defined('SC_HOY') ? SC_HOY : date('Y-m-d');
}

function ahora(): string {
    return date('Y-m-d H:i:s');
}

function fecha_valida(string $f): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $f);
    return $d !== false && $d->format('Y-m-d') === $f;
}

// Acepta 2026-10-03, 3/10/2026 y 03-10-2026. Devuelve 'AAAA-MM-DD' o null.
function leer_fecha($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
        $f = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    } elseif (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $v, $m)) {
        $f = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    } else {
        return null;
    }
    return fecha_valida($f) ? $f : null;
}

// Días de $desde a $hasta (negativo si $hasta es anterior). Con DateTime y
// no restando timestamps: el cambio de hora deja días de 23 o 25 horas.
function dias_entre(string $desde, string $hasta): int {
    $a = new DateTimeImmutable($desde);
    $b = new DateTimeImmutable($hasta);
    $d = $a->diff($b);
    return $d->invert ? -$d->days : $d->days;
}

function sumar_dias(string $fecha, int $dias): string {
    return (new DateTimeImmutable($fecha))->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
}

// Suma meses SIN desbordar: 31 de enero + 1 mes = 28 (o 29) de febrero, no
// 3 de marzo, que es lo que haría DateTime::modify('+1 month').
function sumar_meses(string $fecha, int $meses): string {
    [$a, $m, $d] = array_map('intval', explode('-', $fecha));
    $total = $a * 12 + ($m - 1) + $meses;
    $na = intdiv($total, 12);
    $nm = $total % 12 + 1;
    $ultimo = (int)date('t', mktime(0, 0, 0, $nm, 1, $na));
    return sprintf('%04d-%02d-%02d', $na, $nm, min($d, $ultimo));
}

function fecha_es(?string $f): string {
    if (!$f) return '';
    [$a, $m, $d] = array_map('intval', explode('-', substr($f, 0, 10)));
    return $d . ' ' . MESES_CORTOS[$m - 1] . ' ' . $a;
}

// Sin el año si es el año en curso: «12 nov».
function fecha_corta(?string $f): string {
    if (!$f) return '';
    [$a, $m, $d] = array_map('intval', explode('-', substr($f, 0, 10)));
    return $d . ' ' . MESES_CORTOS[$m - 1] . ($a === (int)substr(hoy(), 0, 4) ? '' : ' ' . $a);
}

function fecha_larga(string $f): string {
    $t = strtotime($f . ' 12:00');
    return DIAS_SEMANA[(int)date('w', $t)] . ', ' . (int)date('j', $t) . ' de '
         . MESES[(int)date('n', $t) - 1] . ' de ' . date('Y', $t);
}

function relativo(int $dias): string {
    if ($dias === 0)  return 'hoy';
    if ($dias === 1)  return 'mañana';
    if ($dias === -1) return 'ayer';
    $abs = abs($dias);
    if ($abs < 45) $txt = $abs . ' días';
    elseif ($abs < 548) { $n = (int)round($abs / 30.44); $txt = $n . ($n === 1 ? ' mes' : ' meses'); }
    else { $n = round($abs / 365.25, 1); $txt = str_replace('.', ',', (string)$n) . ' años'; }
    return $dias > 0 ? 'en ' . $txt : 'hace ' . $txt;
}

function edad(?string $nacimiento): ?int {
    if (!$nacimiento) return null;
    return (new DateTimeImmutable($nacimiento))->diff(new DateTimeImmutable(hoy()))->y;
}

function saludo(): string {
    $h = (int)date('G');
    if ($h >= 6 && $h < 14)  return 'Buenos días';
    if ($h >= 14 && $h < 21) return 'Buenas tardes';
    return 'Buenas noches';
}

// ---------------------------------------------------------------------
//  Números
// ---------------------------------------------------------------------
// Espacio duro entre la cifra y su «€» o «%»: en el móvil, «543,02 / €»
// partido en dos líneas se leía mal (revisión del móvil, 4/10/2026).
const NBSP = "\u{00A0}";

function eur($n): string {
    return number_format((float)$n, 2, ',', '.') . NBSP . '€';
}

// 154300 → «154.300»; 85.5 → «85,5». Sin decimales inútiles.
// $decimales: los de un dato escrito a mano (una cuota de 8,355 %) no se redondean.
function numero_es($n, int $decimales = 2): string {
    $n = (float)$n;
    $tolerancia = 0.5 / (10 ** $decimales);
    if (abs($n - round($n)) < $tolerancia) return number_format($n, 0, ',', '.');
    return rtrim(rtrim(number_format($n, $decimales, ',', '.'), '0'), ',');
}

// Lee números escritos «a la española»: '1.234,56', '154.300' (miles),
// '12,5', '12.5'. Un punto seguido de grupos de exactamente tres cifras se
// toma como separador de miles: quien escribe los km del coche pone
// «154.300», no 154,3. Devuelve null si no es un número.
function leer_numero($v): ?float {
    $v = str_replace([' ', "\xC2\xA0", '€'], '', trim((string)$v));
    if ($v === '') return null;
    if (str_contains($v, ',')) {
        $v = str_replace(',', '.', str_replace('.', '', $v));
    } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $v)) {
        $v = str_replace('.', '', $v);
    }
    return is_numeric($v) ? (float)$v : null;
}

// Para rellenar un input: 154300 → «154300»; 8.355 → «8,355»; un importe de 12.5 → «12,50».
function numero_input($n, bool $importe = false): string {
    if ($n === null || $n === '') return '';
    // Lo que ya es texto (lo que el usuario escribió, al repintar un
    // formulario con errores) se devuelve tal cual.
    if (!is_int($n) && !is_float($n)) return (string)$n;
    $n = (float)$n;
    if ($importe) return number_format($n, 2, ',', '');
    // Sin redondear a 2 decimales: al guardar la ficha sin tocar nada, la cuota de
    // 8,355 % se quedaba en 8,36 % (4/10/2026).
    if (abs($n - round($n)) < 0.00005) return (string)(int)round($n);
    return rtrim(rtrim(number_format($n, 4, ',', ''), '0'), ',');
}

// ---------------------------------------------------------------------
//  Texto
// ---------------------------------------------------------------------
// Sin depender de mbstring: el PHP de este ordenador no la carga, y
// Hostinger sí. Así funciona igual en los dos.
function longitud(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : (int)preg_match_all('/./us', $s);
}

/**
 * Un texto de varias líneas como lista: cada línea es un punto; una línea que
 * acaba en «:» abre un grupo con ese título; «Etiqueta: valor» pone la etiqueta
 * en negrita. Es el formato de los campos con 'lista' => true (secciones.php).
 */
function lista_campo(string $texto): string {
    $html = '';
    $abierta = false;
    foreach (preg_split('/\R/u', trim($texto)) as $linea) {
        $linea = trim($linea);
        if ($linea === '') continue;
        if (str_ends_with($linea, ':')) {
            if ($abierta) $html .= '</ul>';
            $html .= '<h3 class="subtitulo">' . e(rtrim($linea, ':')) . '</h3><ul class="lista-campo">';
            $abierta = true;
            continue;
        }
        if (!$abierta) { $html .= '<ul class="lista-campo">'; $abierta = true; }
        if (preg_match('/^([^:]{1,40}):\s+(.+)$/u', $linea, $m)) {
            $html .= '<li><strong>' . e($m[1]) . ':</strong> ' . e($m[2]) . '</li>';
        } else {
            $html .= '<li>' . e($linea) . '</li>';
        }
    }
    return $html . ($abierta ? '</ul>' : '');
}

function recortar(string $s, int $max): string {
    if (longitud($s) <= $max) return $s;
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max - 1, 'UTF-8') . '…';
    preg_match('/^.{0,' . ($max - 1) . '}/us', $s, $m);
    return $m[0] . '…';
}

function nombre_corto(string $nombre): string {
    $p = preg_split('/\s+/u', trim($nombre));
    return $p[0] ?? $nombre;
}

function iniciales(string $nombre): string {
    $out = '';
    foreach (array_slice(preg_split('/\s+/u', trim($nombre)) ?: [], 0, 2) as $p) {
        if (preg_match('/^./u', $p, $m)) $out .= $m[0];
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($out, 'UTF-8') : strtoupper($out);
}

function json_array(?string $txt): array {
    if ($txt === null || $txt === '') return [];
    $d = json_decode($txt, true);
    return is_array($d) ? $d : [];
}

// PRESERVE_ZERO_FRACTION: sin él, 154300.0 se guarda como 154300 y vuelve
// como entero; los números de un campo cambiarían de tipo según el valor.
function json_texto(array $datos): string {
    return json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

// Contraseña temporal legible para dictarla por WhatsApp: «k7mf-3xqa-p9tz».
// Sin 0/o, 1/l/i: nadie las distingue en una pantalla de móvil.
function contrasena_temporal(): string {
    $alfabeto = 'abcdefghjkmnpqrstuvwxyz23456789';
    $grupos = [];
    for ($g = 0; $g < 3; $g++) {
        $t = '';
        for ($i = 0; $i < 4; $i++) $t .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        $grupos[] = $t;
    }
    return implode('-', $grupos);
}
