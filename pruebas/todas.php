<?php
// =====================================================================
//  Todas las pruebas, de una vez:
//      php pruebas/todas.php            (salida completa)
//      php pruebas/todas.php --breve    (solo el resumen)
//
//  Cada prueba corre en su propio proceso: una que reviente no se lleva a
//  las demás. Código de salida 0 solo si pasan todas. No necesitan MySQL,
//  ni private/, ni conexión: van contra SQLite con el esquema real.
//  Tienen que dar todo ✓ antes de decir que algo está listo para subir.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }
require_once __DIR__ . '/../includes/cli.php';
cli_asegurar_extensiones(['pdo_sqlite']);

$breve = in_array('--breve', $argv, true);
$pruebas = [
    'prueba-esquema'  => 'las migraciones se aplican una vez y SQLite entiende el SQL de MySQL',
    'prueba-dominio'  => 'fechas, campos, avisos automáticos, repeticiones e historial',
    'prueba-avisos'   => 'el correo diario avisa una vez, no cada día',
    'prueba-api'      => 'la API graba igual que los formularios',
    'prueba-hipoteca' => 'el Euríbor, el cuadro de amortización y la hipoteca que se pone al día sola',
    'prueba-paginas'  => 'cada página se pinta y los formularios guardan lo que deben',
];

$fallos = [];
foreach ($pruebas as $nombre => $que) {
    $t = microtime(true);
    $salida = [];
    exec(cli_php() . ' ' . escapeshellarg(__DIR__ . "/{$nombre}.php") . ' 2>&1', $salida, $codigo);
    $ms = (int)round((microtime(true) - $t) * 1000);
    if ($codigo !== 0) $fallos[] = $nombre;
    printf("%s %-16s %-72s %5d ms\n", $codigo === 0 ? '✓' : '✗', $nombre, $que, $ms);
    if (!$breve || $codigo !== 0) echo implode("\n", $salida), "\n\n";
}
echo $fallos ? "\n✗ Fallan: " . implode(', ', $fallos) . "\n" : "\n✓ " . count($pruebas) . '/' . count($pruebas) . " pruebas bien.\n";
exit($fallos ? 1 : 0);
