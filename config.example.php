<?php
// =====================================================================
//  CONFIGURACIÓN — Segundo cerebro  (PLANTILLA)
//  Copia este archivo como  config.php  y rellena tus datos reales.
//  config.php NO va al repositorio: lleva la contraseña de la base de
//  datos y las claves. Se sube UNA vez a mano con FileZilla.
//
//  Si una versión nueva de la app añade una constante, NO hace falta
//  tocar config.php: includes/config-carga.php pone un valor por defecto
//  a todo lo que falte. Solo se añade aquí si quieres cambiar ese valor.
// =====================================================================

// ---- Errores: nunca mostrarlos al visitante (solo registrar) ----
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

// ---- Base de datos (hPanel → Bases de datos → Bases de datos MySQL) ----
define('DB_HOST', 'localhost');            // Casi siempre "localhost" en Hostinger
define('DB_NAME', 'u123456789_cerebro');
define('DB_USER', 'u123456789_cerebro');
define('DB_PASS', 'la-contraseña-de-la-base');

// ---- Ruta pública desde la raíz del dominio ----
// En una subcarpeta → '/segundo-cerebro' (empieza y NO termina en "/").
define('BASE_URL', '/segundo-cerebro');

// URL completa y canónica (SIN www: ver .htaccess). La usan los correos.
define('URL_APP', 'https://gonzalosolaz.tech/segundo-cerebro');

define('NOMBRE_APP', 'Segundo cerebro');
date_default_timezone_set('Europe/Madrid');

// Súbelo (2, 3...) cuando cambies assets/app.css o assets/app.js, para que
// los móviles no se queden con la versión vieja en caché.
define('ASSETS_VERSION', '1');

// ---- Sesión (segundos) ----
// La usa la familia desde el móvil: pedir la contraseña cada hora haría que
// nadie la usara. Dos días sin entrar o catorce en total → hay que volver a
// identificarse. Endurécelo si quieres (finanzas usa 1 h / 12 h).
define('SESION_INACTIVIDAD', 2 * 24 * 3600);
define('SESION_MAXIMA', 14 * 24 * 3600);

// ---- Avisos por correo (cron/diario.php) ----
// Una o varias direcciones separadas por comas. Vacío = no se manda correo.
define('EMAIL_AVISOS', 'tu@correo.com');
// Solo si el cron se lanza por URL en vez de por línea de comandos:
// https://.../cron/diario.php?clave=ESTA_CLAVE   (algo largo y aleatorio).
define('CRON_CLAVE', '');

// ---- API para que Claude grabe desde fuera (remoto.php) ----
// Vacío = la API no existe (404). Larga y aleatoria; la misma va en
// acceso.json en tu ordenador.
define('API_CLAVE', '');

// ---- Enlace a la app de finanzas (solo lo ven los administradores) ----
// Vacío = no se muestra.
define('FINANZAS_URL', 'https://gonzalosolaz.tech/finanzas-personales/');
