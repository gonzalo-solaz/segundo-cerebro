<?php
// =====================================================================
//  Vigilancia diaria: manda por correo lo vencido y lo que entra en su
//  ventana de aviso. Se programa UNA vez en hPanel → Avanzado → Trabajos
//  Cron, una vez al día:
//
//      /usr/bin/php /home/USUARIO/domains/gonzalosolaz.tech/public_html/segundo-cerebro/cron/diario.php
//
//  La ruta exacta, lista para copiar, la enseña Ajustes → Sistema. OJO: sin
//  «domains/gonzalosolaz.tech/» en medio el cron no corre nunca (pasó en
//  finanzas): /home/USUARIO/public_html es OTRO dominio de la cuenta.
//
//  Por URL solo responde con ?clave=CRON_CLAVE; sin ella, 404 con 0 bytes.
//  (Así, sin SSH, se puede comprobar que el archivo está subido: 404 con
//  0 bytes = está; 404 con una página de error de varios KB = no está.)
// =====================================================================
require_once __DIR__ . '/../includes/config-carga.php';

if (PHP_SAPI !== 'cli') {
    if (CRON_CLAVE === '' || !hash_equals((string)CRON_CLAVE, (string)($_GET['clave'] ?? ''))) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/app.php';

esquema_al_dia($pdo, true);
// Antes de vigilar: el Euríbor y las hipotecas al día (la subida de la cuota sale en el correo).
$al_dia = mantenimiento($pdo);
$informe = ejecutar_vigilancia($pdo);
$linea = date('Y-m-d H:i:s') . ' · ' . $informe['resumen']
       . ($al_dia['euribor']['error'] ? ' · Euríbor: ' . $al_dia['euribor']['error'] : '')
       . ($al_dia['hipotecas'] ? ' · ' . implode(' · ', $al_dia['hipotecas']) : '');
$log = dirname(__DIR__) . '/private/cron.log';
if (is_dir(dirname($log))) @file_put_contents($log, $linea . "\n", FILE_APPEND | LOCK_EX);
echo $linea, "\n";
