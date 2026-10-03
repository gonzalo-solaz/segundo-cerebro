<?php
// =====================================================================
//  Arranque de sesión endurecida. Lo incluyen el login y el guardián.
//  Adaptado de finanzas-personales y registro-exclusives, con sus lecciones:
//
//  · Carpeta de sesiones PROPIA (private/sesiones, bloqueada por .htaccess).
//    En Hostinger la carpeta por defecto es COMÚN a todas las apps de la
//    cuenta y el recolector de basura de cualquiera de ellas —24 minutos de
//    serie— borraba también las de aquí: «se me cierra la sesión sola».
//  · SameSite=Lax y no Strict: aquí SÍ hay enlaces entrantes (el correo de
//    avisos, un enlace que te pasa tu pareja por WhatsApp). Con Strict
//    llegaban sin cookie y aterrizaban en el login con la sesión viva
//    (registro-exclusives, julio 2026). Los POST llevan todos token CSRF.
//  · Cookie con nombre y ruta propios (/segundo-cerebro/): no viaja a la
//    web de la raíz del dominio ni choca con la de finanzas.
//
//  En línea de comandos (pruebas) no se arranca sesión: $_SESSION es un
//  array normal que rellena quien llama.
// =====================================================================
require_once __DIR__ . '/config-carga.php';
require_once __DIR__ . '/funciones.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $ses_dir = dirname(__DIR__) . '/private/sesiones';
    if (!is_dir($ses_dir)) @mkdir($ses_dir, 0700, true);
    if (is_dir($ses_dir) && is_writable($ses_dir)) {
        ini_set('session.save_path', $ses_dir);
        // Con carpeta propia, el recolector tiene que correr aquí (nadie más limpia).
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string)SESION_MAXIMA);
    // Un ID de sesión que el servidor no conozca NO se reutiliza: corta la
    // fijación de sesión de raíz.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_name('cerebro_sesion');
    session_set_cookie_params([
        'lifetime' => (int)SESION_MAXIMA,
        'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => es_https(),
    ]);
    session_start();
}
if (!isset($_SESSION) || !is_array($_SESSION)) $_SESSION = [];

// ---- CSRF: token anti-falsificación de formularios ----
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_input(): string {
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}
function csrf_ok(): bool {
    return isset($_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
}

function cerrar_sesion(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}
