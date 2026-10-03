<?php
// =====================================================================
//  Arranque de los scripts de línea de comandos (pruebas, servidor local,
//  remoto.php).
//
//  El PHP de este ordenador (instalado con winget, sin php.ini) trae las
//  extensiones en su carpeta ext/ pero no las carga: ni pdo_sqlite, ni
//  mbstring, ni openssl. En vez de pedir que se toque la instalación de PHP
//  (que comparten todos los proyectos), el script se relanza a sí mismo con
//  «-d extension=...». En Hostinger nada de esto se usa: allí viene todo.
// =====================================================================

function cli_asegurar_extensiones(array $extensiones): void {
    if (PHP_SAPI !== 'cli') return;
    $faltan = array_values(array_filter($extensiones, static fn($x) => !extension_loaded($x)));
    if (!$faltan) return;

    if (getenv('SC_REEJECUTADO') === '1') {
        fwrite(STDERR, "A este PHP le faltan extensiones: " . implode(', ', $faltan) . ".\n"
            . "Actívalas en php.ini (extension=" . implode(', extension=', $faltan) . ") y vuelve a probar.\n");
        exit(1);
    }
    $flags = '';
    $dir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
    if (is_dir($dir)) $flags .= ' -d ' . escapeshellarg('extension_dir=' . $dir);
    foreach ($faltan as $x) $flags .= ' -d extension=' . $x;

    putenv('SC_REEJECUTADO=1');
    putenv('SC_PHP_FLAGS=' . $flags);
    $cmd = escapeshellarg(PHP_BINARY) . $flags;
    foreach ($GLOBALS['argv'] as $a) $cmd .= ' ' . escapeshellarg($a);
    passthru($cmd, $codigo);
    exit($codigo);
}

// Orden para lanzar OTRO proceso de PHP con las mismas extensiones.
function cli_php(): string {
    return escapeshellarg(PHP_BINARY) . (string)getenv('SC_PHP_FLAGS');
}
