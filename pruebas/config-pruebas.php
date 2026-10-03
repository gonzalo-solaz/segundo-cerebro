<?php
// Configuración de las pruebas: SQLite (en memoria o en el archivo que
// diga SC_SQLITE) y un «hoy» fijo para que nada dependa del día.
define('DB_DRIVER', 'sqlite');
define('DB_SQLITE', getenv('SC_SQLITE') ?: ':memory:');
define('BASE_URL', '/segundo-cerebro');
define('URL_APP', 'https://ejemplo.test/segundo-cerebro');
define('NOMBRE_APP', 'Segundo cerebro');
define('ASSETS_VERSION', 'pruebas');
define('EMAIL_AVISOS', 'yo@ejemplo.test, ana@ejemplo.test');
define('CRON_CLAVE', '');
define('API_CLAVE', 'clave-de-pruebas');
define('FINANZAS_URL', 'https://ejemplo.test/finanzas-personales/');
define('DIR_ARCHIVOS', getenv('SC_ARCHIVOS') ?: sys_get_temp_dir() . '/sc-archivos-pruebas');
define('SC_HOY', getenv('SC_HOY') ?: '2026-10-03');
date_default_timezone_set('Europe/Madrid');
