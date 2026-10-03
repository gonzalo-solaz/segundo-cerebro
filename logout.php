<?php
// Salir. Solo por POST con token: un enlace o una imagen en otra web no
// pueden cerrarte la sesión.
require_once __DIR__ . '/includes/sesion.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    cerrar_sesion();
    redirigir('login.php?motivo=salida');
}
redirigir('index.php');
