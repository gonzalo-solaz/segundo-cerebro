<?php
// La API (lo que usa Claude vía remoto.php) graba con las mismas reglas
// que los formularios.
require __DIR__ . '/arranque.php';
require __DIR__ . '/../includes/api.php';
echo "API\n";

$pdo = bd_nueva();
$id = sembrar($pdo);
$api = static fn(string $accion, array $datos = [], ?array $archivo = null) =>
    api_ejecutar($pdo, ['accion' => $accion, 'datos' => json_encode($datos)], $archivo);

$r = $api('estado');
comprueba('estado: hoy, avisos y gasto fijo', $r['hoy'] === '2026-10-03' && count($r['avisos']) > 0 && abs($r['gasto_fijo_mensual'] - 127.99) < 0.001);
$r = $api('precio', ['elemento_id' => $id['seguro'], 'desde' => '2025-03-01', 'coste' => 200, 'nota' => 'la prima de 2025']);
comprueba('precio: apunta lo que costaba y devuelve el historial', $r['precios'][0]['coste'] === 200.0 && $r['precios'][0]['nota'] === 'la prima de 2025');
$r = $api('gastos', ['persona_id' => $id['yo']]);
$seg = array_values(array_filter($r['items'], static fn($i) => $i['id'] === $id['seguro']))[0];
comprueba('gastos: con el precio de hace un año, la comparación (+20 %)', abs($seg['interanual']['pct'] - 20) < 0.001);
$r = $api('gastos');
comprueba('gastos: el total del panel, por partidas, mes a mes y qué revisar (sin los datos en bruto)', abs($r['total'] - 127.99) < 0.001
    && isset($r['partidas']['seguro']) && count($r['calendario']) === 12 && $r['revisar'] && !isset($r['items'][0]['datos']) && !isset($r['partidas']['seguro']['items'][0]['datos']));
$r = $api('esquema');
comprueba('esquema: describe campos con sus opciones y avisos', $r['secciones']['vehiculos']['tipos']['vehiculo']['campos']['proxima_itv']['vence'] === 'Pasar la ITV'
    && in_array('Diésel', $r['secciones']['vehiculos']['tipos']['vehiculo']['campos']['combustible']['opciones'], true));
$r = $api('buscar', ['seccion' => 'vehiculos', 'texto' => 'Fur']);
comprueba('buscar por sección y texto', count($r['elementos']) === 1 && $r['elementos'][0]['id'] === $id['furgo']);

// Crear desde un papel leído por Claude: los números llegan como números JSON.
$r = $api('elemento', ['seccion' => 'contratos', 'tipo' => 'seguro', 'nombre' => 'Seguro de la furgo',
    'datos' => ['ramo' => 'Coche o moto', 'compania' => 'Aseguradora', 'coste' => 412.35, 'periodicidad' => 'Anual', 'renovacion' => '2027-03-01']]);
$nuevo = $r['elemento']['id'];
comprueba('crea el seguro con el importe exacto', $r['elemento']['datos']['coste'] === 412.35, var_export($r['elemento']['datos']['coste'], true));
comprueba('y su aviso de renovación sale solo', count($r['vencimientos']) === 1 && $r['vencimientos'][0]['fecha'] === '2027-03-01');

// Actualizar SOLO un campo: el resto se conserva.
$r = $api('elemento', ['id' => $nuevo, 'datos' => ['renovacion' => '2027-03-15']]);
comprueba('actualizar un campo conserva los demás', $r['elemento']['datos']['compania'] === 'Aseguradora' && $r['elemento']['datos']['coste'] === 412.35);
comprueba('y mueve el aviso', $r['vencimientos'][0]['fecha'] === '2027-03-15');
$r = $api('elemento', ['id' => $nuevo, 'datos' => ['compania' => null]]);
comprueba('un campo a null se vacía', !isset($r['elemento']['datos']['compania']));
$r = $api('elemento', ['id' => $id['furgo'], 'datos' => ['km' => 160000]]);
comprueba('160000 (número JSON) no se lee como 160', $r['elemento']['datos']['km'] === 160000.0);
$r = $api('elemento', ['id' => $id['furgo'], 'datos' => ['km' => 12.345]]);
comprueba('12.345 (número JSON con decimales) no se lee como 12345', $r['elemento']['datos']['km'] === 12.345, var_export($r['elemento']['datos']['km'], true));

$r = $api('elemento', ['id' => $nuevo, 'enlace_id' => $id['furgo']]);
comprueba('la API enlaza un seguro a su vehículo', $r['elemento']['enlace_id'] === $id['furgo']);
$r = $api('elemento', ['id' => $nuevo, 'datos' => ['coste' => 400]]);
comprueba('y lo conserva al actualizar otro campo', $r['elemento']['enlace_id'] === $id['furgo']);
$r = $api('esquema');
comprueba('el esquema explica a qué se puede enlazar', str_contains((string)$r['secciones']['contratos']['tipos']['suministro']['enlace'], 'vivienda/inmueble'));
$e = lanza(static fn() => $api('elemento', ['seccion' => 'contratos', 'tipo' => 'seguro', 'nombre' => 'X', 'datos' => ['ramo' => 'Barco']]));
comprueba('una opción inventada se rechaza con ErrorValidacion', $e instanceof ErrorValidacion);
$e = lanza(static fn() => $api('elemento', ['seccion' => 'vehiculos', 'tipo' => 'vehiculo', 'nombre' => 'X', 'datos' => ['caballos' => 150]]));
comprueba('un campo inventado se rechaza', $e instanceof ErrorValidacion);

$r = $api('vencimiento', ['titulo' => 'Revisión caldera', 'fecha' => '05/11/2026', 'seccion' => 'vivienda', 'elemento_id' => $id['caldera'], 'repetir_meses' => 12]);
comprueba('vencimiento con fecha española', $r['vencimiento']['fecha'] === '2026-11-05' && $r['vencimiento']['elemento_id'] === $id['caldera']);
$r2 = $api('vencimiento', ['id' => $r['vencimiento']['id'], 'fecha' => '2026-11-20']);
comprueba('cambiar solo la fecha de un vencimiento', $r2['vencimiento']['fecha'] === '2026-11-20' && $r2['vencimiento']['titulo'] === 'Revisión caldera');
$r = $api('hecho', ['id' => $r['vencimiento']['id']]);
comprueba('hecho devuelve el siguiente', $r['siguiente'] === '2027-11-20');

$r = $api('registro', ['elemento_id' => $id['furgo'], 'fecha' => '2026-10-02', 'tipo' => 'Mantenimiento', 'titulo' => 'Pastillas de freno', 'valor' => 161000, 'coste' => 120.5]);
comprueba('registro actualiza km', $r['elemento']['datos']['km'] === 161000.0);

$r = $api('documento', ['elemento_id' => $nuevo, 'titulo' => 'Póliza'], ['nombre' => 'poliza.pdf', 'contenido' => "%PDF-1.7\nx"]);
comprueba('documento por la API', $r['documento']['titulo'] === 'Póliza' && $r['documento']['mime'] === 'application/pdf');
$e = lanza(static fn() => $api('documento', ['elemento_id' => $nuevo]));
comprueba('documento sin archivo → error claro', $e instanceof RuntimeException && str_contains($e->getMessage(), 'archivo'));

$r = $api('ficha', ['id' => $nuevo]);
comprueba('ficha trae avisos y documentos', count($r['vencimientos']) === 1 && count($r['documentos']) === 1);
$r = $api('actividad');
comprueba('lo hecho por la API queda como de «Claude»', $r['actividad'][0]['quien'] === 'Claude');
$r = $api('esquema');
comprueba('el esquema explica las categorías de las partidas', in_array('Piscina', $r['partidas_comunidad']['categorias'], true));
$r = $api('partidas', ['registro_id' => $id['recibo_2t'], 'partidas' => [
    ['concepto' => 'Mantenimiento piscina', 'categoria' => 'Piscina', 'zona' => 'escalera', 'total' => 290.40],
    ['concepto' => 'Obra fuga de la piscina', 'categoria' => 'Piscina', 'zona' => 'comun', 'total' => 2735.10, 'extraordinaria' => true]]]);
comprueba('la API graba el desglose (números JSON) y cuadra con el recibo', $r['n'] === 2 && abs($r['diferencia']) < 0.001, json_encode($r));
$r = $api('comunidad', ['id' => $id['comunidad']]);
comprueba('y devuelve el análisis', abs($r['analisis']['anios'][2026]['categorias']['Piscina']['extra'] - 228.52) < 0.001);
$e = lanza(static fn() => $api('comunidad', ['id' => $id['luz']]));
comprueba('el análisis solo es de comunidades', $e instanceof RuntimeException);
$r = $api('registro', ['elemento_id' => $id['peso'], 'fecha' => '2026-10-03', 'tipo' => 'Peso', 'valor' => 85.1]);
comprueba('un pesaje por la API entra como apunte con kg', $r['registro_id'] > 0);
$r = $api('peso', ['id' => $id['peso']]);
comprueba('la API da el control de peso con el último pesaje', $r['analisis']['actual'] === 85.1 && $r['analisis']['imc'] === 26.3, json_encode([$r['analisis']['actual'], $r['analisis']['imc']]));
comprueba('…y sus consejos', count($r['analisis']['consejos']) > 0);
$e = lanza(static fn() => $api('peso', ['id' => $id['luz']]));
comprueba('el análisis de peso solo es de controles de peso', $e instanceof RuntimeException);
$e = lanza(static fn() => api_ejecutar($pdo, ['accion' => 'borrar-todo']));
comprueba('acción desconocida → lista las que hay', $e instanceof RuntimeException && str_contains($e->getMessage(), 'estado'));
$f = api_ejecutar($pdo, ['accion' => 'fichas', 'datos' => json_encode(['ids' => [$id['furgo'], $id['cole'], 99999]])]);
comprueba('fichas: varias de una vez, sin las que no existen', count($f['fichas']) === 2 && isset($f['fichas'][(string)$id['furgo']]['registros']));
comprueba('fichas: con la fecha de nacimiento del titular', $f['fichas'][(string)$id['cole']]['persona_nacimiento'] === '2018-05-10');
$c = api_ejecutar($pdo, ['accion' => 'conexiones']);
comprueba('conexiones: sin clave de finanzas lo dice y no llama', $c['finanzas_api_clave'] === 'FALTA' && $c['pase_clave'] === 'configurada');
$e = lanza(static fn() => api_ejecutar($pdo, ['accion' => 'elemento', 'datos' => '{roto']));
comprueba('JSON roto → error claro', $e instanceof RuntimeException && str_contains($e->getMessage(), 'JSON'));
terminar();
