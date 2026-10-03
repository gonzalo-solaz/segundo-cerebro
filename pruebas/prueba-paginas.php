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
$cache = sys_get_temp_dir() . '/sc-cache-' . getmypid();
@mkdir($cache, 0777, true);
file_put_contents($cache . '/finanzas-nomina_estado.json', json_encode(['t' => time(), 'leido_en' => '2026-10-03 08:00:00', 'datos' => ['anios' => nominas_de_ejemplo()]]));
putenv('SC_CACHE=' . $cache);
putenv('SC_FINANZAS_CLAVE=clave-de-finanzas');
$r = pedir('elemento.php', ['id' => (string)$id['empleo']]);
pinta_bien('con clave, enseña el líquido del año leído de finanzas', $r, '5.700,00');
pinta_bien('y avisa de la nómina que falta', $r, 'Falta grabar la nómina de septiembre 2026');
pinta_bien('y del mes en que cambia el cuadre con el banco', $r, 'La diferencia con el banco cambia en agosto 2026');
file_put_contents($cache . '/finanzas-resumen.json', json_encode(['t' => time(), 'leido_en' => '2026-10-03 08:00:00', 'datos' => [
    'pendientes' => 7, 'actualizado' => '2026-10-01',
    'saldos' => [['cuenta' => 'Mediolanum', 'saldo' => 1234.5, 'banco' => 'Mediolanum', 'cuadra' => true, 'activa' => true, 'ultimo_movimiento' => '2026-09-30'],
                 ['cuenta' => 'Vieja', 'saldo' => 10, 'banco' => 'X', 'cuadra' => null, 'activa' => false, 'ultimo_movimiento' => '']],
    'comprobaciones' => ['atraso' => ['estado' => 'aviso', 'titulo' => 'Revolut lleva 20 días sin importar', 'detalle' => 'Último: 13/09']],
    'gasto' => ['ultimo_completo' => '2026-09', 'meses' => [['mes' => '2026-08', 'gasto' => 1000, 'ingreso' => 2500],
        ['mes' => '2026-09', 'gasto' => 1500, 'ingreso' => 2600], ['mes' => '2026-10', 'gasto' => 100, 'ingreso' => 0]],
        'categorias' => [['categoria' => 'Supermercado', 'mes' => 420.3, 'media' => 380]]]]]));
$r = pedir('finanzas.php');
pinta_bien('Finanzas: la liquidez suma solo las cuentas activas', $r, '1.234,50');
pinta_bien('y el gasto del último mes completo frente a la media', $r, 'media 1.000,00');
pinta_bien('y lo que hay que mirar', $r, 'Revolut lleva 20 días sin importar');
pinta_bien('y las categorías', $r, 'Supermercado');
pinta_bien('y los botones a finanzas van por el pase', $r, 'finanzas-entrar.php?a=importador.php');
@unlink($cache . '/finanzas-resumen.json');
putenv('SC_CACHE');
putenv('SC_FINANZAS_CLAVE');
pinta_bien('sin clave de finanzas, la página lo explica', pedir('finanzas.php'), 'Falta la clave de finanzas');
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
