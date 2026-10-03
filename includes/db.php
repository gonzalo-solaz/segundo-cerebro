<?php
// Conexión a la base de datos. Deja $pdo listo para la página que lo incluye.
require_once __DIR__ . '/config-carga.php';
require_once __DIR__ . '/esquema.php';

$pdo = $pdo ?? conectar_bd();
