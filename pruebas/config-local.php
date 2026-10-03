<?php
// Configuración de servidor-local.php: SQLite en private/local.sqlite.
// Datos de juguete para ver la app en este ordenador; no tocan el servidor.
define('DB_DRIVER', 'sqlite');
define('DB_SQLITE', dirname(__DIR__) . '/private/local.sqlite');
define('BASE_URL', '');
define('URL_APP', 'http://127.0.0.1:8090');
define('NOMBRE_APP', 'Segundo cerebro');
define('ASSETS_VERSION', (string)time());   // en local, sin caché de CSS/JS
define('EMAIL_AVISOS', '');
// Para probar remoto.php contra el servidor local (solo escucha en 127.0.0.1):
//   SC_ACCESO=pruebas/acceso-local.json php remoto.php estado
define('API_CLAVE', 'clave-local');
define('FINANZAS_URL', 'https://gonzalosolaz.tech/finanzas-personales/');
define('DIR_ARCHIVOS', dirname(__DIR__) . '/private/archivos-local');
date_default_timezone_set('Europe/Madrid');
@ini_set('display_errors', '1');
error_reporting(E_ALL);
