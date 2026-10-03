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

if (empty($_SESSION['usuario_id'])) redirigir('login.php');

$ahora = time();
$expirada =
    (!empty($_SESSION['ultima_actividad']) && $ahora - $_SESSION['ultima_actividad'] > SESION_INACTIVIDAD)
    || (!empty($_SESSION['inicio_sesion']) && $ahora - $_SESSION['inicio_sesion'] > SESION_MAXIMA);
if ($expirada) {
    cerrar_sesion();
    redirigir('login.php?motivo=expirada');
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

if ((int)$usuario_actual['debe_cambiar'] === 1
    && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['cuenta.php', 'logout.php'], true)) {
    redirigir('cuenta.php');
}
