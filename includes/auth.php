<?php
// =====================================================================
//  EL GUARDIÁN — se incluye al principio de TODAS las páginas privadas.
//  - Sin sesión válida → al login.
//  - Revalida en cada carga que la cuenta sigue activa: un acceso que el
//    administrador suspende se expulsa al instante.
//  - Con contraseña temporal, solo se puede ir a «Mi cuenta» a cambiarla.
// =====================================================================
require_once __DIR__ . '/sesion.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app.php';

enviar_cabeceras_seguridad();

// Sin sesión, al login recordando la página (GET) para volver a ella después.
function al_login(string $motivo = ''): void {
    $pagina = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $q = (string)($_SERVER['QUERY_STRING'] ?? '');
    $volver = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? volver_seguro($pagina . ($q !== '' ? '?' . $q : ''), '') : '';
    $p = array_filter(['motivo' => $motivo, 'volver' => $volver]);
    redirigir('login.php' . ($p ? '?' . http_build_query($p) : ''));
}

if (empty($_SESSION['usuario_id'])) al_login();

$ahora = time();
$expirada =
    (!empty($_SESSION['ultima_actividad']) && $ahora - $_SESSION['ultima_actividad'] > SESION_INACTIVIDAD)
    || (!empty($_SESSION['inicio_sesion']) && $ahora - $_SESSION['inicio_sesion'] > SESION_MAXIMA);
if ($expirada) {
    cerrar_sesion();
    al_login('expirada');
}
$_SESSION['ultima_actividad'] = $ahora;
if (empty($_SESSION['inicio_sesion'])) $_SESSION['inicio_sesion'] = $ahora;

// La base de datos se pone al día sola: cualquier archivo nuevo en
// sql/migraciones/ se aplica aquí la primera vez.
esquema_al_dia($pdo);

$usuario_actual = usuario($pdo, (int)$_SESSION['usuario_id']);
if (!$usuario_actual || $usuario_actual['estado'] !== 'activo') {
    cerrar_sesion();
    redirigir('login.php?motivo=desactivada');
}

// Si la contraseña ha cambiado desde que se abrió esta sesión, se cierra (usuarios.php,
// huella_password). Una sesión de antes de existir la huella la recibe ahora, sin echar a nadie.
$huella = huella_password($usuario_actual);
if (!isset($_SESSION['huella'])) {
    $_SESSION['huella'] = $huella;
} elseif (!hash_equals((string)$_SESSION['huella'], $huella)) {
    cerrar_sesion();
    al_login('expirada');
}

if ((int)$usuario_actual['debe_cambiar'] === 1
    && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['cuenta.php', 'logout.php'], true)) {
    redirigir('cuenta.php');
}

// Los administradores, con la verificación en dos pasos sí o sí (abren también
// finanzas): sin ella, solo pueden ir a «Mi cuenta» a activarla.
if (dos_pasos_obligatoria($usuario_actual) && !dos_pasos_activa($usuario_actual)
    && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['cuenta.php', 'logout.php'], true)) {
    redirigir('cuenta.php#dos-pasos');
}

// Euríbor e hipotecas al día sin que nadie espere: si hace más de una hora, después de mandar la página.
mantenimiento_en_segundo_plano($pdo);
