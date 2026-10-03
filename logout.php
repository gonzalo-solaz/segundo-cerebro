<?php
// Salir. Un solo «Salir» para las dos apps (3/10/2026):
//  · POST con token (el botón de aquí): cierra esta sesión y pasa por
//    finanzas/salir.php con un pase firmado para cerrar también la de allí.
//  · GET con pase firmado de tipo «salir» (viene del «Salir» de finanzas, que
//    ya cerró la suya): cierra esta y termina en el login.
// Sin una de las dos cosas no hace nada: un enlace o una imagen en otra web
// no pueden cerrarte la sesión.
require_once __DIR__ . '/includes/sesion.php';
require_once __DIR__ . '/includes/pase.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    cerrar_sesion();
    if (PASE_CLAVE !== '' && FINANZAS_URL !== '') {
        redirigir(rtrim(FINANZAS_URL, '/') . '/salir.php?pase=' . rawurlencode(pase_crear(PASE_CLAVE, ['t' => 'salir'])));
    }
    redirigir('login.php?motivo=salida');
}
if (isset($_GET['pase']) && pase_leer(PASE_CLAVE, (string)$_GET['pase'], 'salir')) {
    cerrar_sesion();
    redirigir('login.php?motivo=salida');
}
redirigir('index.php');
