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

function pedir_con_sesion(string $pagina, array $get, ?array $post, array $sesion): array {
    $f = tempnam(sys_get_temp_dir(), 'sc-pet');
    file_put_contents($f, json_encode(['pagina' => $pagina, 'get' => $get, 'post' => $post, 'sesion' => (object)$sesion]));
    $salida = [];
    exec(cli_php() . ' ' . escapeshellarg(__DIR__ . '/render.php') . ' ' . escapeshellarg($f) . ' 2>&1', $salida, $codigo);
    @unlink($f);
    $txt = implode("\n", $salida);
    preg_match('/\[\[REDIRECCION:([^\]]*)\]\]/', $txt, $m);
    return ['codigo' => $codigo, 'html' => $txt, 'redireccion' => $m[1] ?? null];
}

function pinta_bien(string $que, array $r, string $debe_contener = ''): void {
    // «127,99 €» con espacio duro (NBSP) cuenta como «127,99 €»: aquí importa el contenido.
    $r['html'] = str_replace(NBSP, ' ', $r['html']);
    $limpio = $r['codigo'] === 0
        && str_contains($r['html'], '</html>')
        && !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|ErrorException)/', $r['html']);
    $contiene = $debe_contener === '' || str_contains($r['html'], $debe_contener);
    comprueba($que, $limpio && $contiene, $limpio ? "no aparece «{$debe_contener}»" : substr($r['html'], 0, 1500));
}

echo "Páginas (GET)\n";
pinta_bien('panel', pedir('index.php'), 'Lo que viene');
$r = pedir('index.php');
pinta_bien('el panel enseña el aviso vencido de la ITV', $r, '<div class="venc-titulo">Pasar la ITV</div>');
pinta_bien('…con la cosa enlazada debajo, sin repetirla en el título', $r, '>Furgo</a>');
pinta_bien('el panel enseña el gasto fijo', pedir('index.php'), '127,99');
foreach (array_keys(secciones()) as $s) {
    pinta_bien("sección {$s}", pedir('seccion.php', ['s' => $s, 'lista' => '1']), e(seccion($s)['nombre']));
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
pinta_bien('el control de peso calcula el IMC', pedir('peso.php'), '26,4');
pinta_bien('…con su categoría', pedir('peso.php', ['id' => (string)$id['peso']]), 'Sobrepeso');
pinta_bien('…pinta la gráfica', pedir('peso.php', ['id' => (string)$id['peso'], 'r' => 'todo']), 'class="gp-tendencia"');
pinta_bien('…y da las pautas', pedir('peso.php'), 'Cuándo ir al médico');
pinta_bien('…y las calorías', pedir('peso.php'), '1.910 kcal');
pinta_bien('un id que no es un control de peso → no encontrado', pedir('peso.php', ['id' => (string)$id['furgo']]), 'No encontrado');
pinta_bien('Salud enlaza al control de peso', pedir('seccion.php', ['s' => 'salud']), 'peso.php');
pinta_bien('la tarjeta del control de peso enseña el último peso', pedir('seccion.php', ['s' => 'salud']), '85,5 kg');
pinta_bien('la ficha del control de peso lleva a la evolución', pedir('elemento.php', ['id' => (string)$id['peso']]), 'Evolución y pautas');
pinta_bien('el panel enseña el último peso', pedir('index.php'), 'Gonzalo: 85,5 kg');
pinta_bien('el panel lleva al desglose del gasto fijo', pedir('index.php'), 'gastos-fijos.php');
$r = pedir('gastos-fijos.php');
pinta_bien('gastos fijos: el mismo total que el panel', $r, '127,99 €');
pinta_bien('…por partidas, con cada contrato', $r, 'Natación');
pinta_bien('…con el mes del seguro anual', $r, 'el mes más caro: noviembre de 2026');
pinta_bien('…y lo que hay que revisar', $r, e('Falta el importe de «Comunidad de prueba»'));
pinta_bien('…y lo que cuesta cada cosa', $r, 'Lo que cuesta cada cosa');
comprueba('…sin finanzas configurada no lo intenta', !str_contains($r['html'], 'Frente a tus ingresos'));
pinta_bien('un miembro también ve los gastos fijos', pedir('gastos-fijos.php', [], null, $id['miembro']), 'A dónde va');
pinta_bien('Contratos enlaza a los gastos fijos', pedir('seccion.php', ['s' => 'contratos']), 'gastos-fijos.php');
// La luz a medias (sin titular, el 50 % es de quien mira): 30 de 60 € → 97,99 € de 127,99 €.
cambiar_dato_elemento($pdo, $id['luz'], 'porcentaje_pago', 50);
pinta_bien('a medias, el panel enseña tu parte', pedir('index.php'), '97,99 €');
pinta_bien('…con el total de la casa en pequeño', pedir('index.php'), 'de 127,99 € de la casa');
$r = pedir('gastos-fijos.php');
pinta_bien('y el desglose, cuánto pagas de cada cosa', $r, 'pagas el 50 %');
pinta_bien('…con el total en pequeño', $r, 'de 60,00 €');
cambiar_dato_elemento($pdo, $id['luz'], 'porcentaje_pago', null);
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
$r = pedir('seccion.php', ['s' => 'vivienda']);
comprueba('con una sola vivienda, la sección lleva directa a su ficha', $r['redireccion'] === '/segundo-cerebro/elemento.php?id=' . $id['casa'], (string)$r['redireccion']);
pinta_bien('el listado de Vivienda (lista=1) resume los contratos de la casa', pedir('seccion.php', ['s' => 'vivienda', 'lista' => '1']), 'Contratos y seguros');
pinta_bien('la ficha de la casa ofrece equipamiento y contactos', pedir('elemento.php', ['id' => (string)$id['casa']]), 'Equipamiento y materiales');
pinta_bien('el formulario de un suministro ofrece la vivienda', pedir('elemento-editar.php', ['s' => 'contratos', 't' => 'suministro', 'enlace' => (string)$id['casa']]), 'Casa de prueba');
pinta_bien('elemento que no existe',pedir('elemento.php', ['id' => '99999']), 'No encontrado');
pinta_bien('agenda', pedir('vencimientos.php'), 'Toca ya');
pinta_bien('agenda filtrada', pedir('vencimientos.php', ['s' => 'contratos']), 'Renovación del seguro');
$itv = agenda($pdo, 400, null, $id['furgo'])[0];
pinta_bien('editar un aviso automático explica de dónde sale', pedir('vencimientos.php', ['editar' => (string)$itv['id'], 'volver' => 'index.php']), 'sale de la ficha');
pinta_bien('personas', pedir('personas.php'), 'Leo Prueba');
pinta_bien('la tarjeta de la persona resume por sección y enlaza al filtro', pedir('personas.php'), 'seccion.php?s=documentos&amp;persona=' . $id['leo']);
$r = pedir('seccion.php', ['s' => 'documentos', 'persona' => (string)$id['leo'], 'lista' => '1']);
pinta_bien('la sección filtrada por persona lo dice', $r, 'Solo de Leo Prueba');
comprueba('…y solo enseña lo suyo', substr_count($r['html'], 'tarjeta tarjeta-elemento') === 1 && str_contains($r['html'], 'Tarjeta sanitaria europea'));
pinta_bien('editar persona',pedir('personas.php', ['editar' => (string)$id['leo']]), 'Editar a Leo Prueba');
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

// Trabajo: el empleo con «nóminas en finanzas» lee la copia de DIR_CACHE (sin red).
$id['empleo'] = guardar_elemento($pdo, 'trabajo', 'empleo', ['nombre' => 'Universidad de prueba', 'persona_id' => $id['yo'],
    'datos' => ['puesto' => 'Técnico', 'nominas_finanzas' => 'Sí', 'revision_salarial' => '2027-01-01']]);
$id['convenio'] = guardar_elemento($pdo, 'trabajo', 'convenio', ['nombre' => 'Convenio de prueba', 'enlace_id' => $id['empleo'],
    'datos' => ['publicacion' => 'BOE-A-2024-10663']]);
pinta_bien('sin clave de finanzas, la ficha del empleo lo explica', pedir('elemento.php', ['id' => (string)$id['empleo']]), 'Falta la clave de finanzas');
pinta_bien('la ficha del empleo lista su convenio', pedir('elemento.php', ['id' => (string)$id['empleo']]), 'Convenio de prueba');
// Trabajo: panel propio con pestañas (Panel · Mi puesto · Equipo).
$r = pedir('seccion.php', ['s' => 'trabajo']);
comprueba('Trabajo abre su panel, no el listado', $r['redireccion'] === '/segundo-cerebro/trabajo.php', (string)$r['redireccion']);
pinta_bien('el panel de Trabajo sin equipo invita a añadirlo', pedir('trabajo.php'), 'Añade a la primera persona');
$id['companera'] = guardar_elemento($pdo, 'trabajo', 'miembro', ['nombre' => 'Ana Prueba Equipo',
    'datos' => ['puesto' => 'Diseñadora web', 'incorporacion' => '2012-09-03', 'fin_prueba' => '2026-12-01',
                'horario' => "Lunes: 8:00-15:00\nMartes: 8:00-14:00 y 15:00-17:30\nMiércoles: 8:00-15:00\nJueves: 8:00-14:00 y 15:00-17:30\nViernes: 8:00-15:00"]]);
$id['antiguo'] = guardar_elemento($pdo, 'trabajo', 'miembro', ['nombre' => 'Persona Que Se Fue', 'datos' => ['puesto' => 'Community manager']]);
cambiar_activo_elemento($pdo, $id['antiguo'], false);
$id['manual'] = guardar_elemento($pdo, 'trabajo', 'documento', ['nombre' => 'Manual de prueba del equipo',
    'datos' => ['categoria' => 'Procedimientos y normas', 'enviado' => '2026-03-13']]);
$r = pedir('trabajo.php');
pinta_bien('el panel lista los documentos del equipo', $r, 'Manual de prueba del equipo');
pinta_bien('el panel enseña el equipo de hoy', $r, 'Ana Prueba Equipo');
pinta_bien('…su mi puesto', $r, 'Universidad de prueba');
pinta_bien('…el convenio colgado del empleo', $r, 'Convenio de prueba');
pinta_bien('…y los avisos de la sección (fin del periodo de prueba)', $r, 'Evaluar el periodo de prueba');
pinta_bien('…con la pestaña Mi puesto llevando a la ficha del empleo', $r, 'elemento.php?id=' . $id['empleo']);
$r = pedir('trabajo.php', ['p' => 'equipo']);
pinta_bien('la pestaña Equipo lista a las personas', $r, 'Diseñadora web');
comprueba('…sin las archivadas', !str_contains($r['html'], 'Persona Que Se Fue'));
pinta_bien('…que salen en «los que ya no están»', pedir('trabajo.php', ['p' => 'equipo', 'antiguos' => '1']), 'Persona Que Se Fue');
// Trabajo: la pestaña Compras (licencias del servicio, con el CECO del empleo).
pinta_bien('sin compras, la pestaña Compras invita a añadirlas', pedir('trabajo.php', ['p' => 'compras']), 'Aún no hay compras');
guardar_elemento($pdo, 'trabajo', 'empleo', ['nombre' => 'Universidad de prueba', 'persona_id' => $id['yo'],
    'datos' => ['puesto' => 'Técnico', 'nominas_finanzas' => 'Sí', 'revision_salarial' => '2027-01-01', 'ceco' => 'V010800']], $id['empleo']);
$id['licencia'] = guardar_elemento($pdo, 'trabajo', 'compra', ['nombre' => 'Asana de prueba',
    'datos' => ['uso' => 'Gestión de proyectos', 'importe' => '2000', 'periodicidad' => 'Anual', 'renovacion' => '2027-09-26', 'gestion' => 'A través de FUSP']]);
$id['cancelada'] = guardar_elemento($pdo, 'trabajo', 'compra', ['nombre' => 'Evernote de prueba', 'datos' => ['importe' => '240', 'primera_compra' => '2023-02-26']]);
cambiar_activo_elemento($pdo, $id['cancelada'], false);
$r = pedir('trabajo.php', ['p' => 'compras']);
pinta_bien('la pestaña Compras enseña el CECO del servicio', $r, 'V010800');
pinta_bien('…la licencia con su importe', $r, '2.000,00 €');
pinta_bien('…y el total al año', $r, 'al año en renovaciones');
comprueba('…sin las canceladas', !str_contains($r['html'], 'Evernote de prueba'));
pinta_bien('…que salen en «Canceladas»', pedir('trabajo.php', ['p' => 'compras', 'antiguos' => '1']), 'Evernote de prueba');
pinta_bien('el panel avisa de la renovación', pedir('trabajo.php'), 'Renovar la licencia');
$id['licencia2'] = guardar_elemento($pdo, 'trabajo', 'compra', ['nombre' => 'Aaa sin fecha', 'datos' => ['importe' => '10', 'periodicidad' => 'Anual']]);
$id['licencia3'] = guardar_elemento($pdo, 'trabajo', 'compra', ['nombre' => 'Zzz pronto', 'datos' => ['importe' => '5', 'periodicidad' => 'Anual',
    'renovacion' => substr(hoy(), 0, 4) . '-12-31']]);
$h = strstr(pedir('trabajo.php', ['p' => 'compras'])['html'], '<tbody>');
comprueba('por defecto, por renovación: la más cercana primero y las sin fecha al final',
    strpos($h, 'Zzz pronto') < strpos($h, 'Asana de prueba') && strpos($h, 'Asana de prueba') < strpos($h, 'Aaa sin fecha'));
comprueba('…y la tabla pone siempre el año, también el del año en curso', str_contains($h, '31 dic ' . substr(hoy(), 0, 4)));
$h = strstr(pedir('trabajo.php', ['p' => 'compras', 'orden' => 'nombre'])['html'], '<tbody>');
comprueba('ordenar por nombre sigue disponible', strpos($h, 'Aaa sin fecha') < strpos($h, 'Asana de prueba') && strpos($h, 'Asana de prueba') < strpos($h, 'Zzz pronto'));
$h = strstr(pedir('trabajo.php', ['p' => 'compras', 'orden' => 'importe'])['html'], '<tbody>');
comprueba('ordenar por importe: de mayor a menor', strpos($h, 'Asana de prueba') < strpos($h, 'Aaa sin fecha') && strpos($h, 'Aaa sin fecha') < strpos($h, 'Zzz pronto'));
$id['ordenador'] = guardar_elemento($pdo, 'trabajo', 'compra', ['nombre' => 'Ordenador de Ana', 'enlace_id' => $id['companera'],
    'datos' => ['categoria' => 'Hardware', 'importe' => '3000', 'periodicidad' => 'Una vez', 'primera_compra' => '2021-09-15']]);
guardar_elemento($pdo, 'trabajo', 'compra', ['nombre' => 'Ordenadores de becarios', 'datos' => ['categoria' => 'Hardware',
    'estado' => 'Rechazada', 'comentario' => "Rechazada.\nHeredan los de diseño."]]);
$r = pedir('trabajo.php', ['p' => 'compras']);
pinta_bien('la tabla enseña el estado de la petición', $r, 'chip-estado-rechazada');
pinta_bien('…y el comentario, con sus saltos de línea', $r, 'Rechazada.<br />');
$h = pedir('trabajo.php', ['p' => 'compras'])['html'];
comprueba('el hardware va en su propia tabla, después del software',
    strpos($h, 'Software y servicios') < strpos($h, 'Asana de prueba') && strpos($h, 'Hardware y material') < strpos($h, 'Ordenador de Ana')
    && strpos($h, 'Asana de prueba') < strpos($h, 'Hardware y material'));
pinta_bien('…con para quién es', pedir('trabajo.php', ['p' => 'compras']), 'Para <a href="/segundo-cerebro/elemento.php?id=' . $id['companera'] . '">Ana Prueba Equipo</a>');
$r = pedir('elemento.php', ['id' => (string)$id['companera']]);
pinta_bien('la ficha de la persona lista su ordenador en «Equipos y material»', $r, 'Equipos y material');
pinta_bien('…con la fecha de compra', $r, 'comprado el 15 sep 2021');
comprueba('…y no lo llama «Convenio y contactos»', !str_contains($r['html'], 'Convenio y contactos'));
$r = pedir('elemento.php', ['id' => (string)$id['licencia']]);
pinta_bien('la ficha de una licencia lleva la pestaña Compras activa', $r, 'aria-current="page">Compras');
comprueba('…y no lleva plan de desarrollo', !str_contains($r['html'], 'Plan de desarrollo'));
$r = pedir('elemento.php', ['id' => (string)$id['companera']]);
pinta_bien('la ficha de una persona del equipo lleva las pestañas de Trabajo', $r, 'trabajo.php?p=equipo');
pinta_bien('…y su bloque de plan de desarrollo', $r, 'Plan de desarrollo');
$r = pedir('elemento.php', ['id' => (string)$id['companera']], ['accion' => 'plan', 'curso' => '2025-26', 'objetivo' => 'Manual de Dynamics',
    'niveles' => "Nivel 0 = nada\nNivel 4 = todo", 'autoevaluacion' => '9,25', 'nota' => '8,5', 'descripcion' => '', 'notas' => '']);
comprueba('guardar un curso del plan vuelve a su bloque', str_ends_with((string)$r['redireccion'], '#plan'), (string)$r['redireccion']);
$r = pedir('elemento.php', ['id' => (string)$id['companera']]);
pinta_bien('el curso sale en el bloque con su objetivo', $r, 'Manual de Dynamics');
pinta_bien('…con la nota final', $r, 'Nota 8,5');
pinta_bien('…y sus niveles', $r, 'Nivel 4 = todo');
$r = pedir('elemento.php', ['id' => (string)$id['companera']], ['accion' => 'plan', 'curso' => '2025-27', 'objetivo' => 'x']);
comprueba('un curso mal escrito no se guarda', str_contains($r['html'], 'FLASH:error'), substr($r['html'], -300));
pinta_bien('el panel de Trabajo resume el plan del equipo', pedir('trabajo.php'), 'Plan de desarrollo 2026-27');
pinta_bien('…con la última nota', pedir('trabajo.php'), 'última nota 8,5 (2025-26)');
pinta_bien('la ficha del empleo también lleva el bloque', pedir('elemento.php', ['id' => (string)$id['empleo']]), 'Plan de desarrollo');
pinta_bien('sin cursos, la pestaña Formación invita a crearlos', pedir('trabajo.php', ['p' => 'formacion']), 'Aún no hay cursos');
$id['curso'] = guardar_elemento($pdo, 'trabajo', 'curso', ['nombre' => 'Figma: Marketing y Contenido', 'datos' => ['organiza' => 'Formación CEU', 'horas' => '10']]);
$r = pedir('elemento.php', ['id' => (string)$id['curso']], ['accion' => 'formacion', 'personas' => [(string)$id['companera'], (string)$id['empleo']],
    'inscripcion' => '2025-05-14', 'finalizacion' => '2025-06-25', 'estado' => '', 'resultado' => '', 'notas' => '']);
comprueba('apuntar a dos personas a la vez vuelve al bloque', str_ends_with((string)$r['redireccion'], '#formacion'), (string)$r['redireccion']);
$r = pedir('elemento.php', ['id' => (string)$id['curso']]);
pinta_bien('la ficha del curso dice quién lo ha hecho', $r, 'Quién lo ha hecho');
pinta_bien('…con las dos personas', $r, '2 personas');
pinta_bien('…y lleva la pestaña Formación activa', $r, 'aria-current="page">Formación');
pinta_bien('la ficha de la persona lista su formación', pedir('elemento.php', ['id' => (string)$id['companera']]), 'Figma: Marketing y Contenido');
$r = pedir('trabajo.php', ['p' => 'formacion']);
pinta_bien('la pestaña agrupa por curso académico', $r, 'Curso 2024-25');
pinta_bien('…y dice quién lo hizo', $r, '>Ana</a>');
pinta_bien('…con filtro por persona', $r, 'trabajo.php?p=formacion&amp;persona=' . $id['companera']);
pinta_bien('filtrada, enseña el estado de esa persona', pedir('trabajo.php', ['p' => 'formacion', 'persona' => (string)$id['companera']]), 'Finalizado');
$id['curso2'] = guardar_elemento($pdo, 'trabajo', 'curso', ['nombre' => 'Premiere a medias', 'datos' => ['termina' => '2026-11-24']]);
guardar_formacion($pdo, $id['curso2'], $id['companera'], ['inscripcion' => '2026-07-29', 'estado' => 'En curso']);
$h = pedir('trabajo.php', ['p' => 'formacion'])['html'];
$ec = (int)strpos($h, '>En curso</h2>');
comprueba('lo que está en curso va arriba, en su grupo, no en el curso de la inscripción',
    $ec > 0 && strpos($h, 'Premiere a medias', $ec) < strpos($h, 'Curso 2024-25'));
comprueba('…con el día en que termina', str_contains($h, 'termina el 24 nov 2026'));
$r = pedir('elemento.php', ['id' => (string)$id['curso']], ['accion' => 'formacion', 'personas' => [], 'inscripcion' => '']);
comprueba('sin marcar a nadie no se guarda', str_contains($r['html'], 'FLASH:error'), substr($r['html'], -300));
$ficha_casa = pedir('elemento.php', ['id' => (string)$id['casa']]);
comprueba('una casa no lleva plan de desarrollo', !str_contains($ficha_casa['html'], 'Plan de desarrollo'));
pinta_bien('…y su horario como lista', pedir('elemento.php', ['id' => (string)$id['companera']]), '<strong>Martes:</strong>');
pinta_bien('la ficha del empleo también lleva las pestañas', pedir('elemento.php', ['id' => (string)$id['empleo']]), 'aria-current="page">Mi puesto');
$cache = sys_get_temp_dir() . '/sc-cache-' . getmypid();
@mkdir($cache, 0777, true);
file_put_contents($cache . '/finanzas-nomina_estado.json', json_encode(['t' => time(), 'leido_en' => '2026-10-03 08:00:00', 'datos' => ['anios' => nominas_de_ejemplo()]]));
putenv('SC_CACHE=' . $cache);
putenv('SC_FINANZAS_CLAVE=clave-de-finanzas');
$r = pedir('elemento.php', ['id' => (string)$id['empleo']]);
pinta_bien('con clave, enseña el líquido del año leído de finanzas', $r, '5.700,00');
pinta_bien('y avisa de la nómina que falta', $r, 'Falta grabar la nómina de septiembre 2026');
pinta_bien('y del mes en que cambia el cuadre con el banco', $r, 'La diferencia con el banco cambia en agosto 2026');
// Finanzas: el panel de finanzas montado dentro. En las pruebas no hay red: se
// comprueba que, si finanzas no contesta, se monta la última copia y se dice.
file_put_contents($cache . '/finanzas-panel.json', json_encode(['t' => time(), 'leido_en' => '2026-10-03 08:00:00', 'datos' => [
    'version' => 'abc123', 'pendientes' => 7,
    'html' => '<div class="wrap"><div class="topbar"><nav class="tabs" id="year-tabs"></nav></div>'
            . '<div id="acciones-finanzas"><a href="https://ejemplo.test/admin/finanzas-entrar.php?a=importador.php">Importar</a></div></div>',
    'datos' => ['movimientos' => [['concepto' => 'Cierra </script> aquí']]],
    'avisos' => [['estado' => 'aviso', 'titulo' => 'Revolut lleva 20 días sin importar', 'detalle' => 'Último: 13/09']]]]));
$r = pedir('finanzas.php');
pinta_bien('Finanzas: monta el panel de finanzas (sus pestañas)', $r, 'id="year-tabs"');
pinta_bien('con los estilos y el JS de la carpeta de finanzas (mismo dominio)', $r, '/finanzas-personales/assets/panel.js?v=abc123');
comprueba('y sus datos, sin que un concepto pueda cerrar el script', str_contains($r['html'], '"concepto":"Cierra ') && !str_contains($r['html'], 'Cierra </script>'));
comprueba('y NO pinta «Qué mirar» (lo quitó Gonzalo)', !str_contains($r['html'], 'Qué mirar') && !str_contains($r['html'], 'Revolut lleva 20 días sin importar'));
pinta_bien('y los botones a finanzas van por el pase', $r, 'finanzas-entrar.php?a=importador.php');
pinta_bien('si finanzas no contesta, lo dice y enseña la última copia', $r, 'Enseño la última copia');
// Gastos fijos frente a los ingresos: la acción «resumen» de finanzas, de la copia (sin red).
$meses_fin = [];
for ($i = 0; $i < 12; $i++) $meses_fin[] = ['mes' => substr(sumar_meses('2025-10-01', $i), 0, 7), 'gasto' => 2500, 'ingreso' => 3000];
$meses_fin[] = ['mes' => '2026-10', 'gasto' => 0, 'ingreso' => 0];
file_put_contents($cache . '/finanzas-resumen.json', json_encode(['t' => time(), 'leido_en' => '2026-10-03 08:00:00', 'datos' => [
    'saldos' => [['cuenta' => 'Banco', 'saldo' => 9000, 'activa' => true]],
    'gasto' => ['meses' => $meses_fin, 'categorias' => [['categoria' => 'Garaje', 'mes' => 113.63, 'media' => 112.24]]]]]));
$r = pedir('gastos-fijos.php');
pinta_bien('gastos fijos: con finanzas, frente a los ingresos', $r, 'Frente a tus ingresos');
pinta_bien('…con el ahorro de los 12 meses completos', $r, '<span class="cifra-valor">17 %</span>');
pinta_bien('…y lo que se repite en el banco y aquí no está', $r, e('«Garaje» (112,24 € al mes de media)'));
comprueba('…pero un miembro no ve nada de finanzas', !str_contains(pedir('gastos-fijos.php', [], null, $id['miembro'])['html'], 'Frente a tus ingresos'));
// Frente a hace un año: el seguro costaba 200 € (precio desde el 1/3/2025) y hoy 240 €; el IPC, de la copia.
file_put_contents($cache . '/ipc.json', json_encode(['t' => time(), 'serie' => ['2025-11' => 3.0, '2025-12' => 2.9]]));
guardar_precio($pdo, $id['seguro'], '2025-03-01', 200);
$r = pedir('gastos-fijos.php');
pinta_bien('frente a hace un año: el seguro sube un 20 %, más que el IPC', $r, 'var-sube" title="Frente a hace un año: sube más que el IPC">▲ 20,0 %');
pinta_bien('…con el IPC del INE y su mes', $r, 'IPC: +2,9 % (diciembre de 2025, INE)');
pinta_bien('…y lo que falta para comparar el resto', $r, 'Para compararlo con hace un año falta');
@unlink($cache . '/ipc.json');
@unlink($cache . '/finanzas-resumen.json');
@unlink($cache . '/finanzas-panel.json');
putenv('SC_CACHE');
putenv('SC_FINANZAS_CLAVE');
pinta_bien('sin clave de finanzas, la página lo explica', pedir('finanzas.php'), 'Falta la clave de finanzas');
// Las pantallas de acción de finanzas, dentro del menú lateral (iframe por el pase).
$r = pedir('finanzas-pantalla.php', ['p' => 'revisar', 'q' => 'vista=sueltos']);
pinta_bien('Finanzas: una pantalla de acción lleva el menú lateral y su marco', $r, 'class="fin-marco"');
pinta_bien('el marco entra por el pase y reenvía su query', $r, 'finanzas-entrar.php?a=revisar.php%3Fvista%3Dsueltos%26embed%3D1');
pinta_bien('«Finanzas» sigue marcada en el menú lateral', $r, 'class="activo"');
pinta_bien('y hay una pestaña por cada pantalla', $r, 'finanzas-pantalla.php?p=salud');
pinta_bien('sin query, el marco pide solo embed=1', pedir('finanzas-pantalla.php', ['p' => 'nomina']), 'finanzas-entrar.php?a=nomina.php%3Fembed%3D1');
comprueba('una pantalla que no existe vuelve al panel de finanzas', ($r2 = pedir('finanzas-pantalla.php', ['p' => 'inventada']))['redireccion'] !== null && str_contains((string)$r2['redireccion'], 'finanzas.php'), (string)$r2['redireccion']);
pinta_bien('un miembro no ve las pantallas de finanzas', pedir('finanzas-pantalla.php', ['p' => 'revisar'], null, $id['miembro']), 'Solo administradores');
pinta_bien('un miembro no ve Finanzas', pedir('finanzas.php', [], null, $id['miembro']), 'Solo administradores');
@unlink($cache . '/finanzas-nomina_estado.json');
@rmdir($cache);

// Dos pasos y un solo acceso con finanzas.
$r = pedir('login.php', [], ['email' => 'admin@ejemplo.test', 'password' => 'una-contraseña-larga'], 0);
comprueba('con dos pasos, la contraseña lleva al código, no dentro', str_contains((string)$r['redireccion'], '/verificar.php'), (string)$r['redireccion'] . $r['html']);
$pend = ['pendiente_2p' => $id['admin'], 'pendiente_2p_desde' => time()];
$r = pedir_con_sesion('verificar.php', [], ['codigo' => '000000'], $pend);
pinta_bien('un código malo no entra', $r, 'Ese código no vale');
$r = pedir_con_sesion('verificar.php', ['volver' => 'vencimientos.php'], ['codigo' => totp_codigo(TOTP_PRUEBAS, intdiv(time(), 30))], $pend);
comprueba('el código bueno entra y vuelve adonde iba', str_ends_with((string)$r['redireccion'], '/vencimientos.php'), (string)$r['redireccion'] . $r['html']);
$r = pedir_con_sesion('verificar.php', [], null, ['pendiente_2p' => $id['admin'], 'pendiente_2p_desde' => time() - 600]);
comprueba('pasados 5 minutos, otra vez la contraseña', str_contains((string)$r['redireccion'], '/login.php'), (string)$r['redireccion']);
$r = pedir_con_sesion('vencimientos.php', ['dias' => '30'], null, []);
comprueba('sin sesión, al login recordando la página', str_contains((string)$r['redireccion'], 'login.php?volver=vencimientos.php'), (string)$r['redireccion']);
[$id['admin2']] = crear_usuario($pdo, ['nombre' => 'Otra admin', 'email' => 'admin2@ejemplo.test', 'password' => 'una-contraseña-larga', 'rol' => 'admin']);
$r = pedir('index.php', [], null, $id['admin2']);
comprueba('un admin sin dos pasos va a activarlos', str_ends_with((string)$r['redireccion'], '/cuenta.php#dos-pasos'), (string)$r['redireccion']);
pinta_bien('«Mi cuenta» le da la clave para la app', pedir('cuenta.php', [], null, $id['admin2']), 'clave-totp');
pinta_bien('un miembro sin dos pasos entra normal', pedir('index.php', [], null, $id['miembro']), 'Lo que viene');
pinta_bien('Ajustes deja quitar los dos pasos a otro admin', pedir('ajustes.php'), '2 pasos');
$r = pedir('finanzas-entrar.php', ['a' => 'nomina.php']);
preg_match('#/finanzas-personales/entrar\.php\?pase=([^&\s]+)#', (string)$r['redireccion'], $m);
$datos = isset($m[1]) ? pase_leer(PASE_CLAVE, rawurldecode($m[1]), 'entrar') : null;
comprueba('Finanzas: el admin sale con un pase firmado a su página', ($datos['e'] ?? '') === 'admin@ejemplo.test' && ($datos['a'] ?? '') === 'nomina.php', (string)$r['redireccion']);
pinta_bien('un miembro no entra en finanzas', pedir('finanzas-entrar.php', [], null, $id['miembro']), 'Solo administradores');
pinta_bien('el menú del admin lleva a la sección Finanzas', pedir('index.php'), 'finanzas.php');
$r = pedir('logout.php', [], []);
comprueba('Salir pasa por finanzas para cerrar también aquella', str_contains((string)$r['redireccion'], '/finanzas-personales/salir.php?pase='), (string)$r['redireccion']);
$r = pedir('logout.php', ['pase' => pase_crear(PASE_CLAVE, ['t' => 'salir'])]);
comprueba('el «Salir» de finanzas cierra esta y acaba en el login', str_ends_with((string)$r['redireccion'], '/login.php?motivo=salida'), (string)$r['redireccion']);
$r = pedir('logout.php', ['pase' => pase_crear('otra-clave', ['t' => 'salir'])]);
comprueba('con un pase falso no se sale', str_ends_with((string)$r['redireccion'], '/index.php'), (string)$r['redireccion']);

// Un POST con token CSRF equivocado no hace nada.
$f = tempnam(sys_get_temp_dir(), 'sc-pet');
file_put_contents($f, json_encode(['pagina' => 'personas.php', 'post' => ['accion' => 'guardar', 'nombre' => 'Sin token'], 'csrf' => 'falso']));
exec(cli_php() . ' ' . escapeshellarg(__DIR__ . '/render.php') . ' ' . escapeshellarg($f) . ' 2>&1', $salida);
@unlink($f);
comprueba('sin token CSRF válido no se guarda nada', (int)$pdo->query("SELECT COUNT(*) FROM personas WHERE nombre = 'Sin token'")->fetchColumn() === 0);

$r = pedir('peso.php', ['id' => (string)$id['peso']], ['accion' => 'medicion', 'fecha' => '2026-10-03', 'peso' => '85,2', 'cintura' => '100']);
comprueba('apuntar un pesaje desde la página', str_ends_with((string)$r['redireccion'], '/peso.php?id=' . $id['peso'])
    && mediciones_peso($pdo, $id['peso'])['2026-10-03']['peso'] === 85.2, $r['html']);
$r = pedir('peso.php', ['id' => (string)$id['peso']], ['accion' => 'medicion', 'peso' => 'mucho']);
comprueba('un peso que no es número no se apunta y se explica', str_contains($r['html'], 'FLASH:error'), $r['html']);
$ids = implode(',', mediciones_peso($pdo, $id['peso'])['2026-10-03']['ids']);
pedir('peso.php', ['id' => (string)$id['peso']], ['accion' => 'borrar-medicion', 'ids' => $ids]);
comprueba('borrar lo de un día', !isset(mediciones_peso($pdo, $id['peso'])['2026-10-03']));

$pdo = null;   // en Windows, un archivo abierto no se puede borrar
@unlink($bd);
terminar();
