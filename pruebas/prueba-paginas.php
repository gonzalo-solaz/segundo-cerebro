<?php
// =====================================================================
//  Prueba de humo de las páginas: cada una se pinta, entera y sin avisos
//  de PHP, contra una base SQLite con la familia de ejemplo; y los
//  formularios principales guardan lo que deben. Cada página corre en su
//  propio proceso (pruebas/render.php), con una sesión simulada.
//
//  No sustituye a mirarlo en el navegador (php servidor-local.php), pero
//  caza lo típico: una variable sin definir, una función que no existe,
//  una consulta que falla.
// =====================================================================
require __DIR__ . '/arranque.php';

$bd = tempnam(sys_get_temp_dir(), 'sc-bd');
$archivos = sys_get_temp_dir() . '/sc-archivos-' . getmypid();
putenv('SC_SQLITE=' . $bd);
putenv('SC_ARCHIVOS=' . $archivos);
// La conexión de ESTE proceso se abrió con el config ya cargado (:memory:):
// para sembrar el archivo, se abre a mano.
$pdo = new PDO('sqlite:' . $bd, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
esquema_al_dia($pdo, true);
$id = sembrar($pdo);
// sembrar() guardó el documento en el DIR_ARCHIVOS de este proceso; el
// de los procesos hijos es otro. Se copia para que archivo.php lo encuentre.
$doc = documento($pdo, $id['documento']);
@mkdir($archivos . '/' . dirname($doc['archivo']), 0777, true);
copy(DIR_ARCHIVOS . '/' . $doc['archivo'], $archivos . '/' . $doc['archivo']);

function pedir(string $pagina, array $get = [], ?array $post = null, int $usuario = 1): array {
    $f = tempnam(sys_get_temp_dir(), 'sc-pet');
    file_put_contents($f, json_encode(['pagina' => $pagina, 'get' => $get, 'post' => $post, 'usuario' => $usuario]));
    $salida = [];
    exec(cli_php() . ' ' . escapeshellarg(__DIR__ . '/render.php') . ' ' . escapeshellarg($f) . ' 2>&1', $salida, $codigo);
    @unlink($f);
    $txt = implode("\n", $salida);
    preg_match('/\[\[REDIRECCION:([^\]]*)\]\]/', $txt, $m);
    return ['codigo' => $codigo, 'html' => $txt, 'redireccion' => $m[1] ?? null];
}

function pinta_bien(string $que, array $r, string $debe_contener = ''): void {
    $limpio = $r['codigo'] === 0
        && str_contains($r['html'], '</html>')
        && !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|ErrorException)/', $r['html']);
    $contiene = $debe_contener === '' || str_contains($r['html'], $debe_contener);
    comprueba($que, $limpio && $contiene, $limpio ? "no aparece «{$debe_contener}»" : substr($r['html'], 0, 1500));
}

echo "Páginas (GET)\n";
pinta_bien('panel', pedir('index.php'), 'Lo que viene');
pinta_bien('el panel enseña el aviso vencido de la ITV', pedir('index.php'), 'Pasar la ITV · Furgo');
pinta_bien('el panel enseña el gasto fijo', pedir('index.php'), '127,99');
foreach (array_keys(secciones()) as $s) {
    pinta_bien("sección {$s}", pedir('seccion.php', ['s' => $s]), e(seccion($s)['nombre']));
    pinta_bien("sección {$s} (archivados)", pedir('seccion.php', ['s' => $s, 'archivados' => '1']));
    foreach (array_keys(seccion($s)['tipos']) as $t) {
        pinta_bien("alta de {$s}/{$t}", pedir('elemento-editar.php', ['s' => $s, 't' => $t]), 'Guardar');
    }
}
pinta_bien('el gasto en suministros suma las facturas del año', pedir('gasto-suministros.php'), '181,02');
pinta_bien('y deja el año anterior aparte', pedir('gasto-suministros.php'), '70,10');
pinta_bien('Contratos enlaza al gasto en suministros', pedir('seccion.php', ['s' => 'contratos']), 'gasto-suministros.php');
pinta_bien('el gasto en comunidad suma la piscina con su obra', pedir('gasto-comunidad.php'), '351,82');
pinta_bien('y enseña el análisis escrito', pedir('gasto-comunidad.php', ['id' => (string)$id['comunidad']]), 'El ascensor es lo más caro');
pinta_bien('y avisa del recibo sin desglose', pedir('gasto-comunidad.php'), 'no tiene desglose');
pinta_bien('Contratos enlaza al gasto en comunidad', pedir('seccion.php', ['s' => 'contratos']), 'gasto-comunidad.php');
pinta_bien('la ficha de la comunidad enlaza a su análisis', pedir('elemento.php', ['id' => (string)$id['comunidad']]), 'Gasto por partidas');
pinta_bien('sección que no existe → página de error', pedir('seccion.php', ['s' => 'nada']), 'Esa sección no existe');
foreach (['furgo', 'casa', 'ficha_leo', 'dni', 'seguro', 'netflix', 'cole', 'cumple', 'fontanero', 'trat'] as $k) {
    pinta_bien("ficha de {$k}", pedir('elemento.php', ['id' => (string)$id[$k]]), 'Avisos');
    pinta_bien("edición de {$k}", pedir('elemento-editar.php', ['id' => (string)$id[$k]]), 'Guardar');
}
pinta_bien('la ficha del vehículo muestra lo gastado', pedir('elemento.php', ['id' => (string)$id['furgo']]), '189,90');
pinta_bien('la ficha del DNI muestra su archivo', pedir('elemento.php', ['id' => (string)$id['dni']]), 'DNI escaneado');
$pdo->prepare('UPDATE elementos SET enlace_id = ? WHERE id IN (?, ?)')->execute([$id['casa'], $id['luz'], $id['seguro']]);
pinta_bien('la ficha de la casa lista sus contratos', pedir('elemento.php', ['id' => (string)$id['casa']]), 'Contratos y seguros');
pinta_bien('y los nombra', pedir('elemento.php', ['id' => (string)$id['casa']]), 'Seguro de hogar');
pinta_bien('la ficha del contrato enlaza a su casa', pedir('elemento.php', ['id' => (string)$id['luz']]), 'Casa de prueba');
pinta_bien('el listado de Vivienda resume los contratos de la casa', pedir('seccion.php', ['s' => 'vivienda']), 'Contratos y seguros');
pinta_bien('el formulario de un suministro ofrece la vivienda', pedir('elemento-editar.php', ['s' => 'contratos', 't' => 'suministro', 'enlace' => (string)$id['casa']]), 'Casa de prueba');
pinta_bien('elemento que no existe',pedir('elemento.php', ['id' => '99999']), 'No encontrado');
pinta_bien('agenda', pedir('vencimientos.php'), 'Toca ya');
pinta_bien('agenda filtrada', pedir('vencimientos.php', ['s' => 'contratos']), 'Renovación del seguro');
$itv = agenda($pdo, 400, null, $id['furgo'])[0];
pinta_bien('editar un aviso automático explica de dónde sale', pedir('vencimientos.php', ['editar' => (string)$itv['id'], 'volver' => 'index.php']), 'sale de la ficha');
pinta_bien('personas', pedir('personas.php'), 'Leo Prueba');
pinta_bien('editar persona', pedir('personas.php', ['editar' => (string)$id['leo']]), 'Editar a Leo Prueba');
pinta_bien('ajustes (admin)', pedir('ajustes.php'), 'Accesos');
pinta_bien('mi cuenta', pedir('cuenta.php'), 'Cambiar la contraseña');
pinta_bien('un miembro no ve Ajustes', pedir('ajustes.php', [], null, $id['miembro']), 'Solo para administradores');
pinta_bien('un miembro no ve el enlace a finanzas', pedir('index.php', [], null, $id['miembro']));
comprueba('…de verdad no lo ve', !str_contains(pedir('index.php', [], null, $id['miembro'])['html'], 'finanzas-personales'));
$r = pedir('archivo.php', ['id' => (string)$id['documento']]);
comprueba('archivo.php sirve el PDF', $r['codigo'] === 0 && str_starts_with($r['html'], '%PDF'), substr($r['html'], 0, 300));

echo "\nFormularios (POST)\n";
$r = pedir('elemento-editar.php', ['s' => 'vehiculos', 't' => 'vehiculo'], [
    'nombre' => 'Coche de pruebas', 'persona_id' => (string)$id['ana'],
    'datos' => ['matricula' => '9999ZZZ', 'km' => '20.000', 'proxima_itv' => '2026-11-20', 'combustible' => 'Gasolina'],
]);
comprueba('alta de un vehículo redirige a su ficha', (bool)preg_match('#/elemento\.php\?id=(\d+)$#', (string)$r['redireccion'], $m), $r['html']);
$nuevo = (int)($m[1] ?? 0);
$el = elemento($pdo, $nuevo);
comprueba('y queda guardado con sus datos', $el && $el['datos']['km'] === 20000.0 && $el['persona_id'] === $id['ana']);
comprueba('con el aviso de la ITV', count(agenda($pdo, 400, null, $nuevo)) === 1);

$r = pedir('elemento-editar.php', ['s' => 'vehiculos', 't' => 'vehiculo'], ['nombre' => '', 'datos' => ['km' => 'mucho']]);
pinta_bien('con errores, el formulario se repinta y los explica', $r, 'tiene que ser un número');
comprueba('…sin perder lo escrito', str_contains($r['html'], 'value="mucho"'));

$r = pedir('elemento.php', ['id' => (string)$nuevo], ['accion' => 'registro', 'tipo' => 'Mantenimiento', 'fecha' => '2026-10-02',
    'titulo' => 'Revisión', 'valor' => '21.500', 'coste' => '99,90']);
comprueba('apuntar en el historial', $r['redireccion'] !== null && elemento($pdo, $nuevo)['datos']['km'] === 21500.0, $r['html']);

$v = agenda($pdo, 400, null, $nuevo)[0];
$r = pedir('vencimientos.php', [], ['accion' => 'hecho', 'vencimiento_id' => (string)$v['id'], 'volver' => 'elemento.php?id=' . $nuevo]);
comprueba('marcar hecho vuelve a la ficha', str_ends_with((string)$r['redireccion'], '/elemento.php?id=' . $nuevo), (string)$r['redireccion']);
comprueba('…y queda hecho', vencimiento($pdo, $v['id'])['estado'] === 'hecho');
$r = pedir('vencimientos.php', [], ['accion' => 'hecho', 'vencimiento_id' => '1', 'volver' => 'https://malo.example/']);
comprueba('«volver» no deja salir de la app', str_ends_with((string)$r['redireccion'], '/vencimientos.php'), (string)$r['redireccion']);

$r = pedir('vencimientos.php', [], ['accion' => 'crear', 'titulo' => 'Pintar el salón', 'fecha' => '2027-04-01', 'seccion' => 'vivienda',
    'repetir_meses' => '0', 'aviso_dias' => '15', 'volver' => 'seccion.php?s=vivienda']);
comprueba('crear recordatorio', str_contains($r['html'], 'FLASH:ok') && (int)$pdo->query("SELECT COUNT(*) FROM vencimientos WHERE titulo = 'Pintar el salón'")->fetchColumn() === 1);

$r = pedir('personas.php', [], ['accion' => 'guardar', 'nombre' => 'Abuela Prueba', 'relacion' => 'Otro familiar']);
comprueba('añadir persona', (int)$pdo->query("SELECT COUNT(*) FROM personas WHERE nombre = 'Abuela Prueba'")->fetchColumn() === 1);

$r = pedir('ajustes.php', [], ['accion' => 'crear', 'nombre' => 'Abuela', 'email' => 'abuela@ejemplo.test', 'rol' => 'miembro']);
pinta_bien('dar acceso enseña la contraseña temporal una vez', $r, 'Contraseña temporal');
$u = $pdo->query("SELECT * FROM usuarios WHERE email = 'abuela@ejemplo.test'")->fetch();
comprueba('…y la cuenta nace obligada a cambiarla', $u && (int)$u['debe_cambiar'] === 1);
$r = pedir('index.php', [], null, (int)$u['id']);
comprueba('con contraseña temporal, cualquier página lleva a «Mi cuenta»', str_ends_with((string)$r['redireccion'], '/cuenta.php'), (string)$r['redireccion']);
$r = pedir('ajustes.php', [], ['accion' => 'estado', 'usuario_id' => (string)$id['admin']]);
comprueba('un admin no puede suspenderse a sí mismo', str_contains($r['html'], 'No puedes suspender'));
$r = pedir('ajustes.php', [], ['accion' => 'crear', 'nombre' => 'Intruso', 'email' => 'x@ejemplo.test'], $id['miembro']);
comprueba('un miembro no puede dar accesos', (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE email = 'x@ejemplo.test'")->fetchColumn() === 0);

$r = pedir('elemento.php', ['id' => (string)$id['casa']], ['accion' => 'borrar'], $id['miembro']);
comprueba('un miembro no puede borrar (solo archivar)', elemento($pdo, $id['casa']) !== null && str_contains($r['html'], 'Solo un administrador'));
$r = pedir('elemento.php', ['id' => (string)$id['casa']], ['accion' => 'archivar'], $id['miembro']);
comprueba('pero sí archivar', elemento($pdo, $id['casa'])['activo'] === 0);

// Un POST con token CSRF equivocado no hace nada.
$f = tempnam(sys_get_temp_dir(), 'sc-pet');
file_put_contents($f, json_encode(['pagina' => 'personas.php', 'post' => ['accion' => 'guardar', 'nombre' => 'Sin token'], 'csrf' => 'falso']));
exec(cli_php() . ' ' . escapeshellarg(__DIR__ . '/render.php') . ' ' . escapeshellarg($f) . ' 2>&1', $salida);
@unlink($f);
comprueba('sin token CSRF válido no se guarda nada', (int)$pdo->query("SELECT COUNT(*) FROM personas WHERE nombre = 'Sin token'")->fetchColumn() === 0);

$pdo = null;   // en Windows, un archivo abierto no se puede borrar
@unlink($bd);
terminar();
