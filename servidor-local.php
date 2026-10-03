<?php
// =====================================================================
//  Ver la app en este ordenador, sin MySQL ni Hostinger:
//
//      php servidor-local.php          → http://127.0.0.1:8090/
//      php servidor-local.php 8091     (otro puerto)
//
//  Usa SQLite (private/local.sqlite) y pruebas/config-local.php. La
//  primera vez te lleva a crear el administrador. Los datos de aquí son
//  de juguete: no se mezclan con los del servidor. No se sube.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }
require __DIR__ . '/includes/cli.php';
cli_asegurar_extensiones(['pdo_sqlite']);

$puerto = (int)($argv[1] ?? 8090) ?: 8090;
if (!is_dir(__DIR__ . '/private')) mkdir(__DIR__ . '/private', 0700, true);
putenv('SC_CONFIG=' . __DIR__ . '/pruebas/config-local.php');

echo "\n  Segundo cerebro en local → http://127.0.0.1:{$puerto}/\n";
echo "  (Ctrl+C para pararlo. Base de datos: private/local.sqlite)\n\n";
passthru(cli_php() . ' -S 127.0.0.1:' . $puerto . ' -t ' . escapeshellarg(__DIR__));
