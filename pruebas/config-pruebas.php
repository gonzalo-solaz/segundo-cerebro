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
define('PASE_CLAVE', 'clave-de-pase-pruebas');
// Con clave, la ficha del empleo lee las nóminas de la copia de SC_CACHE (sin red).
define('FINANZAS_API_CLAVE', getenv('SC_FINANZAS_CLAVE') ?: '');
define('IPC_URL', '');   // sin red: el IPC sale de la copia de DIR_CACHE (ipc.json)
define('EURIBOR_URL', '');       // sin red: el Euríbor lo siembran las pruebas en su tabla
define('EURIBOR_BCE_URL', '');
define('DIR_CACHE', getenv('SC_CACHE') ?: sys_get_temp_dir() . '/sc-cache-pruebas');
define('DIR_ARCHIVOS', getenv('SC_ARCHIVOS') ?: sys_get_temp_dir() . '/sc-archivos-pruebas');
define('SC_HOY', getenv('SC_HOY') ?: '2026-10-03');
date_default_timezone_set('Europe/Madrid');
