<?php
// La lógica de la app: fechas, números, validación por esquema, avisos
// automáticos de los campos, repeticiones, historial y borrados.
require __DIR__ . '/arranque.php';

echo "Fechas y números\n";
comprueba('31 ene + 1 mes = 28 feb', sumar_meses('2026-01-31', 1) === '2026-02-28');
comprueba('29 feb 2028 + 12 meses = 28 feb 2029', sumar_meses('2028-02-29', 12) === '2029-02-28');
comprueba('15 nov + 3 meses cruza de año', sumar_meses('2026-11-15', 3) === '2027-02-15');
comprueba('restar meses', sumar_meses('2026-10-03', -12) === '2025-10-03');
comprueba('dias_entre a través del cambio de hora', dias_entre('2026-10-24', '2026-10-26') === 2);
comprueba('dias_entre negativo', dias_entre('2026-10-03', '2026-09-20') === -13);
comprueba('leer_fecha 3/10/2026', leer_fecha('3/10/2026') === '2026-10-03');
comprueba('leer_fecha rechaza 31/02/2026', leer_fecha('31/02/2026') === null);
comprueba('leer_numero «154.300» son miles', leer_numero('154.300') === 154300.0);
comprueba('leer_numero «1.234,56»', leer_numero('1.234,56') === 1234.56);
comprueba('leer_numero «12,5»', leer_numero('12,5') === 12.5);
comprueba('leer_numero «12.5»', leer_numero('12.5') === 12.5);
comprueba('leer_numero «abc» = null', leer_numero('abc') === null);
comprueba('numero_es sin decimales inútiles', numero_es(154300) === '154.300' && numero_es(85.5) === '85,5');
comprueba('relativo', relativo(0) === 'hoy' && relativo(1) === 'mañana' && relativo(-3) === 'hace 3 días' && relativo(60) === 'en 2 meses');
comprueba('longitud sin mbstring cuenta letras, no bytes', longitud('cañón') === 5);
comprueba('recortar respeta los acentos', recortar('Ñandú corredor', 6) === 'Ñandú…');
comprueba('contraseña temporal de 14 caracteres legibles', (bool)preg_match('/^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/', contrasena_temporal()));
comprueba('volver_seguro admite páginas propias', volver_seguro('elemento.php?id=3#historial') === 'elemento.php?id=3#historial');
comprueba('volver_seguro rechaza URL de fuera', volver_seguro('https://malo.example/') === 'index.php' && volver_seguro('//malo.example') === 'index.php');

echo "\nElementos y avisos automáticos\n";
$pdo = bd_nueva();
$id = sembrar($pdo);

$furgo = elemento($pdo, $id['furgo']);
comprueba('los km «154.300» se guardan como 154300', $furgo['datos']['km'] === 154300.0, var_export($furgo['datos']['km'], true));
$itv = agenda($pdo, 400, null, $id['furgo']);
comprueba('la ITV de la ficha crea su aviso', count($itv) === 1 && $itv[0]['titulo'] === 'Pasar la ITV · Furgo' && $itv[0]['origen'] === 'campo:proxima_itv');
comprueba('una ITV pasada sale como vencida', $itv[0]['situacion'] === 'vencido');
$ficha = elemento($pdo, $id['ficha_leo']);
comprueba('nombre automático «Ficha médica de Leo Prueba»', $ficha['nombre'] === 'Ficha médica de Leo Prueba');

$e = lanza(static fn() => guardar_elemento($pdo, 'documentos', 'dni', ['datos' => ['caducidad' => '2030-01-01']]));
comprueba('un DNI sin titular no se guarda', $e instanceof ErrorValidacion && str_contains($e->getMessage(), 'Elige titular'), $e ? $e->getMessage() : 'no lanzó');
$e = lanza(static fn() => guardar_elemento($pdo, 'vehiculos', 'vehiculo', ['nombre' => 'X', 'datos' => ['proxima_itv' => 'mañana', 'km' => 'muchos', 'combustible' => 'Leña']]));
comprueba('fecha, número y opción no válidos: los tres errores a la vez', $e instanceof ErrorValidacion && count($e->errores) === 3, $e ? implode(' | ', $e->errores) : 'no lanzó');
$e = lanza(static fn() => guardar_elemento($pdo, 'vehiculos', 'vehiculo', ['nombre' => 'X', 'datos' => ['color' => 'rojo']]));
comprueba('un campo que no existe se rechaza diciendo cuáles hay', $e instanceof ErrorValidacion && str_contains($e->getMessage(), 'matricula'));
$e = lanza(static fn() => guardar_elemento($pdo, 'vehiculos', 'avion', ['nombre' => 'X']));
comprueba('un tipo que no existe se rechaza', $e instanceof ErrorValidacion);

// Cambiar la fecha en la ficha mueve el aviso; vaciarla lo borra.
guardar_elemento($pdo, 'vehiculos', 'vehiculo', ['nombre' => 'Furgo', 'persona_id' => $id['yo'],
    'datos' => ['matricula' => '1234ABC', 'km' => '154300', 'proxima_itv' => '2027-03-14']], $id['furgo'], $id['admin']);
$itv = agenda($pdo, 4000, null, $id['furgo']);
comprueba('cambiar la ITV en la ficha mueve el aviso (no crea otro)', count($itv) === 1 && $itv[0]['fecha'] === '2027-03-14');
guardar_elemento($pdo, 'vehiculos', 'vehiculo', ['nombre' => 'Furgo', 'datos' => ['matricula' => '1234ABC', 'km' => '154300']], $id['furgo'], $id['admin']);
comprueba('vaciar la fecha borra el aviso', agenda($pdo, 4000, null, $id['furgo']) === []);

echo "\nHecho y repeticiones\n";
// Seguro con renovación anual: «hecho» programa el del año que viene y la ficha se pone al día.
$seg = agenda($pdo, 400, null, $id['seguro'])[0];
comprueba('el seguro avisa con 45 días (renueva el 1/11)', $seg['situacion'] === 'pronto' && $seg['aviso_dias'] === 45 && $seg['repetir_meses'] === 12);
$sig = marcar_hecho($pdo, $seg['id'], $id['admin']);
comprueba('hecho → el siguiente queda para el 1/11/2027', $sig === '2027-11-01', (string)$sig);
comprueba('la ficha del seguro se queda con la renovación nueva', elemento($pdo, $id['seguro'])['datos']['renovacion'] === '2027-11-01');
comprueba('marcar hecho dos veces no duplica', marcar_hecho($pdo, $seg['id'], $id['admin']) === null && count(agenda($pdo, 4000, null, $id['seguro'])) === 1);
// La suscripción repite según su periodicidad (mensual).
$net = agenda($pdo, 400, null, $id['netflix'])[0];
comprueba('la suscripción mensual repite cada mes', $net['repetir_meses'] === 1);
comprueba('y al hacerla pasa al mes siguiente', marcar_hecho($pdo, $net['id'], $id['admin']) === '2026-11-15');
// Recordatorio a mano marcado con mucho retraso: el siguiente cae en el futuro.
$viejo = crear_vencimiento($pdo, ['titulo' => 'Revisión vieja', 'fecha' => '2024-05-01', 'seccion' => 'vivienda', 'repetir_meses' => 12], null);
comprueba('marcado con años de retraso, el siguiente cae en el futuro', marcar_hecho($pdo, $viejo, null) === '2027-05-01');
// Un DNI renovado: al marcar hecho NO se repite solo (la fecha nueva la pone quien lo renueva).
$dni = agenda($pdo, 400, null, $id['dni'])[0];
comprueba('el DNI avisa con 90 días y no se repite', $dni['aviso_dias'] === 90 && $dni['repetir_meses'] === 0);
marcar_hecho($pdo, $dni['id'], $id['admin']);
guardar_elemento($pdo, 'documentos', 'dni', ['persona_id' => $id['yo'], 'datos' => ['numero' => '00000000T', 'caducidad' => '2027-01-15']], $id['dni'], $id['admin']);
comprueba('re-guardar la ficha con la MISMA fecha ya hecha no resucita el aviso', agenda($pdo, 400, null, $id['dni']) === []);
guardar_elemento($pdo, 'documentos', 'dni', ['persona_id' => $id['yo'], 'datos' => ['numero' => '00000000T', 'caducidad' => '2037-01-15']], $id['dni'], $id['admin']);
comprueba('con la caducidad nueva, aviso nuevo', count(agenda($pdo, 4000, null, $id['dni'])) === 1);

// Los avisos automáticos no se borran sueltos; los manuales sí.
$auto = agenda($pdo, 4000, null, $id['dni'])[0];
$e = lanza(static fn() => borrar_vencimiento($pdo, $auto['id'], null));
comprueba('un aviso que sale de la ficha no se borra suelto (y dice dónde cambiarlo)', $e instanceof RuntimeException && str_contains($e->getMessage(), 'ficha'));
borrar_vencimiento($pdo, $id['ibi'], null);
comprueba('un recordatorio puesto a mano sí se borra', vencimiento($pdo, $id['ibi']) === null);
// Editar la fecha de un aviso automático cambia también la ficha.
actualizar_vencimiento($pdo, $auto['id'], ['titulo' => $auto['titulo'], 'fecha' => '2036-12-01', 'aviso_dias' => 90, 'repetir_meses' => 0], null);
comprueba('cambiar la fecha del aviso cambia la caducidad de la ficha', elemento($pdo, $id['dni'])['datos']['caducidad'] === '2036-12-01');

echo "\nHistorial, costes y borrados\n";
crear_registro($pdo, ['elemento_id' => $id['furgo'], 'fecha' => '2026-10-01', 'tipo' => 'Lectura de kilómetros', 'valor' => '155.120'], $id['admin']);
comprueba('un apunte con más km actualiza los km del vehículo', elemento($pdo, $id['furgo'])['datos']['km'] === 155120.0);
crear_registro($pdo, ['elemento_id' => $id['furgo'], 'fecha' => '2025-01-01', 'titulo' => 'Apunte viejo', 'valor' => '90000'], $id['admin']);
comprueba('un apunte viejo con menos km no los baja', elemento($pdo, $id['furgo'])['datos']['km'] === 155120.0);
comprueba('lo gastado en 12 meses suma los costes', abs(gasto_ultimo_ano($pdo, $id['furgo']) - 189.90) < 0.001);
$gs = gasto_suministros($pdo);
comprueba('el gasto de suministros se agrupa por año, el más reciente primero', array_keys($gs) === [2026, 2025], implode(',', array_keys($gs)));
comprueba('suma las facturas del año y no la incidencia con coste', abs($gs[2026]['total'] - 181.02) < 0.001 && $gs[2026]['n'] === 2, var_export($gs[2026]['total'], true));
comprueba('lo suma por suministro y por mes', abs($gs[2026]['suministros'][$id['luz']]['total'] - 181.02) < 0.001 && $gs[2026]['meses'] === [8 => 80.5, 9 => 100.52]);
comprueba('el año anterior sale aparte', abs($gs[2025]['total'] - 70.10) < 0.001);
comprueba('los vehículos no entran en el gasto de suministros', !isset($gs[2026]['suministros'][$id['furgo']]));
$e = lanza(static fn() => crear_registro($pdo, ['elemento_id' => $id['dni'], 'titulo' => 'x'], null));
comprueba('documentos no lleva historial', $e instanceof ErrorValidacion);
$e = lanza(static fn() => crear_registro($pdo, ['elemento_id' => $id['furgo'], 'tipo' => 'Despegue'], null));
comprueba('un tipo de apunte que no está en la lista se rechaza', $e instanceof ErrorValidacion);
// Luz 60/mes + seguro 240/año (20) + Netflix 12,99 + natación 35 = 127,99
comprueba('gasto fijo mensual = 127,99 €', abs(coste_mensual_total($pdo) - 127.99) < 0.001, (string)coste_mensual_total($pdo));
comprueba('kpi de contratos', kpi_seccion($pdo, 'contratos') === '92,99 € al mes', (string)kpi_seccion($pdo, 'contratos'));

$e = lanza(static fn() => guardar_documento_bytes($pdo, $id['casa'], 'Falso', 'virus.pdf', "MZ\x90\x00 ejecutable", null));
comprueba('un «.pdf» que no es PDF no entra', $e instanceof ErrorValidacion);
$doc = documento($pdo, $id['documento']);
$ruta = ruta_documento($doc);
comprueba('el archivo se guarda con nombre aleatorio', $ruta !== null && is_file($ruta) && !str_contains($doc['archivo'], 'dni'));
comprueba('ruta_documento no se deja engañar con ../', ruta_documento(['archivo' => '../config.php']) === null);

cambiar_activo_elemento($pdo, $id['caldera'], false, null);
comprueba('archivar quita sus avisos de la agenda', agenda($pdo, 400, null, $id['caldera']) === []);
cambiar_activo_elemento($pdo, $id['caldera'], true, null);
comprueba('recuperarlo los devuelve', count(agenda($pdo, 400, null, $id['caldera'])) === 1);

$sec = borrar_elemento($pdo, $id['dni'], $id['admin']);
comprueba('borrar devuelve la sección', $sec === 'documentos');
comprueba('borrar quita sus avisos y documentos', (int)$pdo->query('SELECT COUNT(*) FROM vencimientos WHERE elemento_id = ' . (int)$id['dni'])->fetchColumn() === 0
    && documentos_de($pdo, $id['dni']) === []);
comprueba('y el archivo del disco', !is_file($ruta));

echo "\nGastos fijos\n";
// Base nueva: arriba se marcó hecha la renovación del seguro (ya es de 2027).
$pg = bd_nueva();
$ig = sembrar($pg);
$hist = historial_de_gastos($pg, hoy());
comprueba('historial: lo gastado en la furgo en 12 meses y el último cargo de la luz', abs($hist[$ig['furgo']]['otros'] - 189.90) < 0.001 && $hist[$ig['luz']]['ultimo'] === '2026-09-09');
$gf = analisis_gastos_fijos(elementos_con_coste($pg), $hist, hoy());
comprueba('el total es el mismo que el del panel', abs($gf['total'] - coste_mensual_total($pg)) < 0.001, (string)$gf['total']);
comprueba('por partidas, en su orden fijo', array_keys($gf['partidas']) === ['suministro', 'seguro', 'suscripcion', 'otros'], implode(',', array_keys($gf['partidas'])));
comprueba('un contrato sin coste no cuenta, pero se dice', array_column($gf['sin_importe'], 'nombre') === ['Comunidad de prueba']);
comprueba('lo mensual cuenta todos los meses', count($gf['calendario']) === 12 && abs($gf['calendario'][0]['total'] - 107.99) < 0.001);
comprueba('el seguro anual se cobra en el mes de su renovación (el más caro)', $gf['calendario'][1]['mes'] === '2026-11' && abs($gf['calendario'][1]['extra'] - 240) < 0.001 && $gf['pico'] === 1);
comprueba('para apartar: lo no mensual, al mes', abs($gf['provision'] - 20) < 0.001);
$luz = array_values(array_filter($gf['items'], static fn($i) => $i['id'] === $ig['luz']))[0];
comprueba('lo real de la luz sale de sus facturas, sin la incidencia', $luz['real']['n'] === 3 && abs($luz['real']['mensual'] - 251.12 / 3) < 0.001);
$cosas = array_column($gf['cosas'], 'mensual', 'nombre');
comprueba('por cosa: la natación es de Leo', abs(($cosas['Leo Prueba'] ?? 0) - 35) < 0.001, implode(',', array_keys($cosas)));
comprueba('revisar: lo primero, el importe que falta', $gf['revisar'][0]['nivel'] === 'aviso' && str_contains($gf['revisar'][0]['titulo'], 'Comunidad de prueba'));
comprueba('revisar: la luz no cuadra con sus facturas', (bool)array_filter($gf['revisar'], static fn($r) => $r['titulo'] === '«Luz» no cuadra con sus facturas'));
comprueba('revisar: el seguro se renueva y ya pasó el mes para no renovar', (bool)array_filter($gf['revisar'],
    static fn($r) => str_contains($r['titulo'], 'Seguro de hogar') && str_contains($r['texto'], 'Ya no da tiempo')));
$c = fechas_de_cargo(['renovacion' => '2025-03-12'], 12, null, '2026-10-01', '2027-09-30');
comprueba('una renovación vieja se lleva hacia delante', $c['fechas'] === ['2027-03-12'] && !$c['aprox']);
$c = fechas_de_cargo([], 3, '2026-09-30', '2026-10-01', '2027-09-30');
comprueba('sin renovación: el último recibo + su periodicidad (aproximado)', $c['fechas'] === ['2026-12-30', '2027-03-30', '2027-06-30', '2027-09-30'] && $c['aprox']);
$c = fechas_de_cargo([], 3, '2026-01-31', '2026-01-01', '2026-12-31');
comprueba('sin arrastrar el fin de mes', $c['fechas'] === ['2026-04-30', '2026-07-31', '2026-10-31'], implode(',', $c['fechas']));
comprueba('sin ancla no se inventa la fecha', fechas_de_cargo([], 12, null, '2026-10-01', '2027-09-30') === null);
$hip = ['id' => 90, 'seccion' => 'contratos', 'tipo' => 'hipoteca', 'nombre' => 'Hipoteca', 'enlace_id' => 2, 'enlace_nombre' => 'Casa', 'persona_id' => 1, 'persona_nombre' => 'Gonzalo',
        'datos' => ['coste' => 600, 'periodicidad' => 'Mensual', 'porcentaje_pago' => 60, 'fecha_fin' => '2042-11-07', 'revision_interes' => 'Trimestral']];
$an = analisis_gastos_fijos([$hip], [], '2026-10-04', 1);
$r = $an['revisar'][0];
comprueba('la hipoteca: su peso, lo que queda y tu parte', $r['titulo'] === 'La hipoteca es el 100 % de tu gasto fijo'
    && str_contains($r['texto'], 'Quedan 16 años y 1 mes') && str_contains($r['texto'], 'Pagas el 60 %: 360,00 € de los 600,00 €'), $r['titulo'] . ' / ' . $r['texto']);
comprueba('lo tuyo y lo de la casa', abs($an['tuyo'] - 360) < 0.001 && abs($an['total'] - 600) < 0.001 && $an['a_medias']);
$casa = analisis_gastos_fijos([$hip], [], '2026-10-04');
comprueba('sin decir quién mira, la casa entera (como la API y los usuarios sin persona)', abs($casa['tuyo'] - 600) < 0.001 && !$casa['a_medias']);
$corta = analisis_gastos_fijos([array_replace_recursive($hip, ['datos' => ['fecha_fin' => '2027-03-07', 'porcentaje_pago' => 100]])], [], '2026-10-04', 1);
comprueba('tras la última cuota, la hipoteca deja de contar', $corta['calendario'][5]['total'] == 600 && $corta['calendario'][6]['total'] == 0);
$meses_fin = [];
for ($i = 0; $i < 12; $i++) $meses_fin[] = ['mes' => substr(sumar_meses('2025-10-01', $i), 0, 7), 'gasto' => 2500, 'ingreso' => 3000];
$meses_fin[] = ['mes' => '2026-10', 'gasto' => 0, 'ingreso' => 0];
$res = ['saldos' => [['saldo' => 5000, 'activa' => true], ['saldo' => 900, 'activa' => false]],
        'gasto' => ['meses' => $meses_fin, 'categorias' => [
            ['categoria' => 'Garaje', 'mes' => 113.63, 'media' => 112.24], ['categoria' => 'Hipoteca', 'mes' => 360, 'media' => 377.09],
            ['categoria' => 'Suscripciones', 'mes' => 49.78, 'media' => 41.07], ['categoria' => 'Vehículos', 'mes' => 434.07, 'media' => 392.32],
            ['categoria' => 'Sin categorizar', 'mes' => 286.64, 'media' => 30.14]]]];
$sf = salud_finanzas($res, $an, '2026-10-04');
comprueba('finanzas: la media de los 12 meses completos, sin el actual', $sf['meses'] === 12 && abs($sf['ingreso'] - 3000) < 0.001 && abs($sf['gasto'] - 2500) < 0.001);
comprueba('ahorro del 16,7 % y colchón de 2 meses (solo las cuentas activas)', abs($sf['ahorro_pct'] - 50 / 3) < 0.001 && abs($sf['colchon_meses'] - 2) < 0.001);
comprueba('la hipoteca, por tu parte: 360 de 3.000 = 12 %, holgado', $sf['hipoteca_tuya'] && abs($sf['hipoteca_pct'] - 12) < 0.001 && $sf['cifras'][3]['nivel'] === 'bien');
comprueba('tus gastos fijos frente a tus ingresos: 360 de 3.000 = 12 %, y lo que queda del 50 %', abs($sf['fijos_pct'] - 12) < 0.001
    && $sf['cifras'][0]['nivel'] === 'bien' && str_contains($sf['cifras'][0]['texto'], '1.140,00 €'));
comprueba('lo que se repite en el banco y aquí no está: garaje y suscripciones, no la hipoteca',
    array_column($sf['repetidos'], 'categoria') === ['Garaje', 'Suscripciones'], implode(',', array_column($sf['repetidos'], 'categoria')));
// Quién paga qué (Gonzalo = 1, Pilar = 2): la parte del titular, el resto si el titular es el otro.
$el = static fn(?int $titular, $pct, string $s = 'contratos') => ['seccion' => $s, 'persona_id' => $titular, 'datos' => $pct === null ? [] : ['porcentaje_pago' => $pct]];
comprueba('el titular paga su %', parte_que_pagas($el(1, 60), 1) === 60.0);
comprueba('si el titular es otro, el resto', parte_que_pagas($el(1, 60), 2) === 40.0);
comprueba('si otro lo paga entero, nada', parte_que_pagas($el(2, null), 1) === 0.0 && parte_que_pagas($el(2, 100), 1) === 0.0);
comprueba('sin titular, el % es de quien mira', parte_que_pagas($el(null, 50), 1) === 50.0 && parte_que_pagas($el(null, null), 1) === 100.0);
comprueba('en una actividad, la persona es quien va: el % es de quien mira', parte_que_pagas($el(4, 50, 'familia'), 1) === 50.0);
comprueba('sin saber quién mira, todo', parte_que_pagas($el(2, 30), null) === 100.0);
// El agua: recibos cada 3 meses en una ficha «Mensual» (lo que pasó en producción el 4/10/2026).
comprueba('las facturas llegan cada 3 meses', intervalo_facturas(['2026-02-06', '2026-05-07', '2026-08-06']) === 3);
comprueba('una factura sin apuntar no alarga el ritmo', intervalo_facturas(['2025-12-09', '2026-08-09', '2026-09-09']) === 1);
comprueba('dos facturas en un mes siguen siendo mensuales', intervalo_facturas(['2026-06-08', '2026-06-29', '2026-07-20', '2026-08-19']) === 1);
$agua = ['id' => 91, 'seccion' => 'contratos', 'tipo' => 'suministro', 'nombre' => 'Agua', 'persona_id' => null, 'persona_nombre' => null,
         'datos' => ['coste' => 38.74, 'periodicidad' => 'Mensual', 'porcentaje_pago' => 50]];
$ha = [91 => ['ultimo' => '2026-08-06', 'n' => 3, 'total' => 348.63, 'max' => 118.97, 'min' => 113.07, 'fechas' => ['2026-02-06', '2026-05-07', '2026-08-06'], 'otros' => 0.0]];
$aa = analisis_gastos_fijos([$agua], $ha, '2026-10-04', 1);
comprueba('el agua real: 348,63 € en 3 recibos trimestrales = 38,74 € al mes, no 116', abs($aa['items'][0]['real']['mensual'] - 348.63 / 9) < 0.001 && $aa['items'][0]['real']['cada'] === 3);
comprueba('y no dice que no cuadra; dice que se cobra cada 3 meses', !array_filter($aa['revisar'], static fn($r) => str_contains($r['titulo'], 'no cuadra'))
    && (bool)array_filter($aa['revisar'], static fn($r) => $r['titulo'] === '«Agua» se cobra cada 3 meses' && str_contains($r['texto'], '«Trimestral»')));
comprueba('el agua a medias: 19,37 € de 38,74 €', abs($aa['tuyo'] - 19.37) < 0.001 && abs($aa['total'] - 38.74) < 0.001);
$garaje = ['id' => 92, 'seccion' => 'contratos', 'tipo' => 'alquiler', 'nombre' => 'Plaza de garaje', 'persona_id' => 1, 'persona_nombre' => 'Gonzalo',
           'datos' => ['que' => 'Garaje', 'coste' => 113.63, 'periodicidad' => 'Mensual']];
$ag = analisis_gastos_fijos([$hip, $garaje], [], '2026-10-04', 1);
comprueba('el alquiler va en su partida, junto a la hipoteca', array_keys($ag['partidas']) === ['hipoteca', 'alquiler']);
comprueba('un contrato suelto es su propia cosa: la plaza de garaje', in_array('Plaza de garaje', array_column($ag['cosas'], 'nombre'), true));
comprueba('y con él, el garaje del banco ya está recogido', array_column(salud_finanzas($res, $ag, '2026-10-04')['repetidos'], 'categoria') === ['Suscripciones']);
comprueba('con menos de 6 meses de finanzas no se opina', salud_finanzas(['gasto' => ['meses' => [['mes' => '2026-09', 'gasto' => 1, 'ingreso' => 1]]]], $an, '2026-10-04') === null);

echo "\nFrente a hace un año (precios, facturas e IPC)\n";
$ine = '{"COD":"IPC251856","Data":[{"Anyo":2025,"FK_Periodo":11,"Valor":3.0},{"Anyo":2025,"FK_Periodo":12,"Valor":2.9},{"Anyo":2025,"FK_Periodo":10,"Valor":3.1}]}';
$serie = ipc_leer_ine($ine);
comprueba('el IPC del INE, por meses y en orden', $serie === ['2025-10' => 3.1, '2025-11' => 3.0, '2025-12' => 2.9]);
comprueba('el IPC de un mes que aún no ha salido es el último publicado', ipc_hasta($serie, '2026-03') === ['mes' => '2025-12', 'valor' => 2.9] && ipc_hasta($serie, '2025-09') === null);
// Un cambio de coste en la ficha deja su precio; y hacia atrás, guardar_precio.
guardar_elemento($pg, 'contratos', 'seguro', ['nombre' => 'Seguro de hogar', 'datos' => ['ramo' => 'Hogar', 'coste' => '250', 'periodicidad' => 'Anual', 'renovacion' => '2026-11-01']], $ig['seguro'], null);
$pr = precios_por_elemento($pg)[$ig['seguro']] ?? [];
comprueba('cambiar el coste en la ficha apunta el precio nuevo desde hoy', count($pr) >= 1 && end($pr)['desde'] === hoy() && end($pr)['coste'] === 250.0);
guardar_precio($pg, $ig['seguro'], '2025-11-01', 200, null, 'Liberty');
guardar_precio($pg, $ig['seguro'], '2025-11-01', 210, null, 'Liberty');
$pr = precios_por_elemento($pg)[$ig['seguro']];
comprueba('el mismo día se sustituye, no se duplica', count(array_filter($pr, static fn($p) => $p['desde'] === '2025-11-01')) === 1);
comprueba('el precio que regía en una fecha', precio_en($pr, '2026-01-01')['coste'] === 210.0 && precio_en($pr, '2025-10-01') === null);
$e = lanza(static fn() => guardar_precio($pg, $ig['dni'], '2025-01-01', 10));
comprueba('un DNI no tiene precio', $e instanceof ErrorValidacion);
$e = lanza(static fn() => guardar_precio($pg, $ig['seguro'], 'ayer', 10));
comprueba('ni un precio sin fecha', $e instanceof ErrorValidacion);
// Por precio: el seguro, 200 € hace un año (desde el 1/3/2025) y 250 € hoy: +25 %.
guardar_precio($pg, $ig['seguro'], '2025-03-01', 200);
$gp = analisis_gastos_fijos(elementos_con_coste($pg), historial_de_gastos($pg, hoy()), hoy(), $ig['yo'], precios_por_elemento($pg));
$seg = array_values(array_filter($gp['items'], static fn($i) => $i['id'] === $ig['seguro']))[0];
comprueba('por precio: 200 € hace un año, 250 € hoy = +25 %', $seg['interanual']['como'] === 'precio' && abs($seg['interanual']['pct'] - 25) < 0.001
    && abs($seg['interanual']['antes'] - 200 / 12) < 0.001, json_encode($seg['interanual']));
comprueba('la partida junta lo comparable y dice cuántos', $gp['partidas']['seguro']['interanual']['n'] === 1 && $gp['partidas']['seguro']['interanual']['de'] === 1);
comprueba('y lo que no se puede comparar dice qué falta', in_array('Netflix', array_column($gp['sin_comparar'], 'nombre'), true)
    && array_column($gp['sin_comparar'], 'falta', 'nombre')['Luz'] === 'las facturas de hace un año');
// Por facturas: los mismos meses de los dos años (agosto y septiembre).
$itl = ['tipo' => 'suministro', 'meses' => 1, 'parte' => 50, 'tuyo' => 30, 'mensual' => 60, 'coste' => 60, 'periodicidad' => 'Mensual', 'real' => null];
$hl = ['cargos' => [['2024-12-01', 50], ['2025-08-10', 70], ['2025-09-10', 80], ['2025-12-09', 70.10], ['2026-08-09', 80.50], ['2026-09-09', 100.52]]];
$v = interanual_item($itl, [], $hl, '2026-10-03');
comprueba('por facturas: diciembre, agosto y septiembre de los dos años, 200 € → 251,12 €', $v['como'] === 'facturas' && abs($v['pct'] - (251.12 / 200 - 1) * 100) < 0.001
    && abs($v['antes'] - 200 / 3 * 0.5) < 0.001, json_encode($v));
comprueba('un mes suelto no basta para lo mensual', interanual_item($itl, [], ['cargos' => [['2025-09-10', 80], ['2026-09-09', 100]]], '2026-10-03') === null);
comprueba('las facturas mandan sobre el precio', interanual_item($itl, [['desde' => '2020-01-01', 'coste' => 1, 'periodicidad' => 'Mensual', 'nota' => '']], $hl, '2026-10-03')['como'] === 'facturas');
// Con obras extraordinarias: se compara lo normal con lo normal y las obras van aparte.
$itc = ['tipo' => 'comunidad', 'meses' => 3, 'parte' => 50, 'tuyo' => 150, 'mensual' => 100, 'coste' => 300, 'periodicidad' => 'Trimestral', 'real' => null];
$hc = ['cargos' => [['2025-03-31', 300], ['2025-06-30', 300], ['2026-03-31', 330], ['2026-06-30', 900, 540]]];
$v = interanual_item($itc, [], $hc, '2026-10-03');
comprueba('con obras: normal 600 € → 690 € (+15 %), no 600 → 1.230 €', $v['como'] === 'facturas' && abs($v['pct'] - 15) < 0.001
    && abs($v['pct_con_extra'] - 105) < 0.001 && abs($v['extra_ahora'] - 540 / 6 * 0.5) < 0.001 && $v['extra_antes'] == 0, json_encode($v));
comprueba('lo extraordinario no se mezcla con lo normal en la partida', sumar_interanual([['interanual' => $v]])['extra'] > 0);
// Un año a medias no se compara con uno entero: 4 recibos contra 4.
$rc = [];
foreach ([300, 300, 300, 300, 310, 900, 320, 330] as $i => $coste) {
    $rc[$i + 1] = ['coste' => (float)$coste, 'extra' => $coste === 900 ? 560.0 : 0.0, 'n_partidas' => $i > 3 ? 5 : 0,
                   'etiqueta' => 'r' . $i, 'titulo' => 'Recibo ' . $i];
}
$cr = comparar_recibos($rc);
comprueba('comparar recibos: 4 contra 4, normal 1.200 € → 1.300 € (+8,3 %), con obras +55 %', $cr && $cr['antes']['normal'] === 1200.0 && $cr['ahora']['normal'] === 1300.0
    && abs($cr['pct_normal'] - 8.333) < 0.01 && abs($cr['pct_total'] - 55) < 0.01 && count($cr['extras']) === 1 && $cr['antes']['sin_desglose'] === 4, json_encode($cr));
comprueba('con menos de 8 recibos no se compara', comparar_recibos(array_slice($rc, 0, 7, true)) === null);
// El sueldo base frente al IPC del mes anterior a la subida.
$anios = [['anio' => 2025, 'meses' => [['mes' => '2025-11', 'tipo' => 'mensual', 'campos' => ['salario_base' => 1903.38]],
                                        ['mes' => '2025-12', 'tipo' => 'mensual', 'campos' => ['salario_base' => 1903.38]],
                                        ['mes' => '2025-12', 'tipo' => 'extra', 'campos' => ['salario_base' => 5000]]]],
          ['anio' => 2026, 'meses' => [['mes' => '2026-01', 'tipo' => 'mensual', 'campos' => ['salario_base' => 1941.45]],
                                        ['mes' => '2026-02', 'tipo' => 'mensual', 'campos' => ['salario_base' => 1941.45]]]]];
$sub = subida_salarial($anios, $serie);
comprueba('el sueldo base subió un 2,0 % en enero (la paga extra no cuenta)', $sub['mes'] === '2026-01' && abs($sub['pct'] - 2.0001) < 0.01 && $sub['ipc']['mes'] === '2025-12');
$sf2 = salud_finanzas($res, $an, '2026-10-04', $anios, $serie);
comprueba('y frente al IPC del 2,9 %, pierde 0,9 puntos', str_contains(end($sf2['cifras'])['texto'], 'pierdes 0,9 puntos') && end($sf2['cifras'])['nivel'] === 'idea');
comprueba('sin cambios de sueldo en los datos, no se dice nada', subida_salarial([$anios[1]], $serie) === null || subida_salarial([['meses' => [$anios[1]['meses'][0]]]], $serie) === null);
comprueba('variación con signo', variacion_es(2.04) === '+2,0 %' && variacion_es(-35.83) === '−35,8 %');

echo "\nControl de peso\n";
comprueba('IMC de 85,5 kg y 180 cm = 26,4 (sobrepeso)', imc(85.5, 180) === 26.4 && categoria_imc(26.4)[0] === 'Sobrepeso');
comprueba('los cortes del IMC son los de la OMS', categoria_imc(18.4)[0] === 'Bajo peso' && categoria_imc(24.9)[0] === 'Peso normal'
    && categoria_imc(30)[0] === 'Obesidad grado I' && categoria_imc(40)[0] === 'Obesidad grado III');
comprueba('peso sano para 180 cm: 59,9-80,7 kg', rango_peso_sano(180) === [59.9, 80.7]);
comprueba('la altura en metros también vale', altura_cm('1.78') === 178.0 && altura_cm(178) === 178.0 && altura_cm(5) === null);
$an = analisis_peso($pdo, elemento($pdo, $id['peso']));
comprueba('último peso e IMC', $an['actual'] === 85.5 && $an['imc'] === 26.4, var_export([$an['actual'], $an['imc']], true));
comprueba('ritmo de las últimas 4 semanas: −0,66 kg/semana', $an['ritmo'] === -0.66 && $an['ritmo_dias'] === 28, var_export($an['ritmo'], true));
comprueba('cambio en 30 días contra el pesaje del 1/9: −2,5 kg', $an['cambios'][30]['kg'] === -2.5 && $an['cambios'][30]['desde'] === '2026-09-01');
comprueba('le faltan 5,5 kg y llegaría el 29/11', $an['falta'] === 5.5 && $an['llegada'] === '2026-11-29', var_export([$an['falta'], $an['llegada']], true));
comprueba('calorías (Mifflin-St Jeor, 46 años, actividad ligera)', $an['calorias']['basal'] === 1760 && $an['calorias']['mantener'] === 2410
    && $an['calorias']['adelgazar'] === 1910 && $an['calorias']['proteina'] === [96, 128], json_encode($an['calorias']));
comprueba('cintura de 101 cm en hombre: riesgo aumentado; cintura/altura 0,56', $an['riesgo_cintura'] === 'aumentado' && $an['ica'] === 0.56);
comprueba('el consejo del ritmo dice que va bien', (bool)array_filter($an['consejos'], static fn($c) => $c[0] === 'ok' && str_contains($c[1], 'Buen ritmo')));
comprueba('la tendencia suaviza: queda por encima del último pesaje', end($an['tendencia']) > 85.5);
guardar_medicion($pdo, $id['peso'], ['fecha' => '2026-10-02', 'peso' => '85,3'], null);
$med = mediciones_peso($pdo, $id['peso']);
comprueba('repetir el día corrige el peso sin tocar la cintura', $med['2026-10-02']['peso'] === 85.3 && $med['2026-10-02']['cintura'] === 101.0
    && (int)$pdo->query("SELECT COUNT(*) FROM registros WHERE tipo = 'Peso' AND fecha = '2026-10-02'")->fetchColumn() === 1);
$e = lanza(static fn() => guardar_medicion($pdo, $id['peso'], ['peso' => '8'], null));
comprueba('un peso imposible se rechaza', $e instanceof ErrorValidacion);
$e = lanza(static fn() => guardar_medicion($pdo, $id['peso'], ['fecha' => '2026-12-01', 'peso' => '80'], null));
comprueba('y una fecha futura', $e instanceof ErrorValidacion);
$e = lanza(static fn() => guardar_medicion($pdo, $id['furgo'], ['peso' => '80'], null));
comprueba('solo se apunta en un control de peso', $e instanceof ErrorValidacion);
comprueba('el apunte «Peso» lleva kg aunque no se diga', (string)$pdo->query("SELECT unidad FROM registros WHERE tipo = 'Peso' LIMIT 1")->fetchColumn() === 'kg');
comprueba('kpi de salud: el último peso', kpi_seccion($pdo, 'salud') === 'Gonzalo: 85,3 kg', (string)kpi_seccion($pdo, 'salud'));
$hijo = guardar_elemento($pdo, 'salud', 'peso', ['persona_id' => $id['leo'], 'datos' => ['altura' => '130', 'sexo' => 'Hombre']], null, null);
guardar_medicion($pdo, $hijo, ['fecha' => '2026-10-01', 'peso' => '30'], null);
$an = analisis_peso($pdo, elemento($pdo, $hijo));
comprueba('en un menor no se dan calorías ni se juzga el IMC', $an['menor'] && $an['calorias'] === null && count($an['consejos']) === 1 && str_contains($an['consejos'][0][1], 'percentiles'));

echo "\nContratos enlazados a una vivienda\n";
$sum = static fn(int $enlace) => ['nombre' => 'Luz', 'enlace_id' => $enlace, 'datos' => ['categoria' => 'Luz', 'coste' => '60', 'periodicidad' => 'Mensual']];
guardar_elemento($pdo, 'contratos', 'suministro', $sum($id['casa']), $id['luz'], $id['admin']);
guardar_elemento($pdo, 'contratos', 'seguro', ['nombre' => 'Seguro de hogar', 'enlace_id' => $id['casa'],
    'datos' => ['ramo' => 'Hogar', 'coste' => '240', 'periodicidad' => 'Anual']], $id['seguro'], $id['admin']);
$hijos = array_column(elementos_enlazados($pdo, $id['casa']), 'id');
sort($hijos);
$esperados = [$id['seguro'], $id['luz'], $id['comunidad']];
sort($esperados);
comprueba('la casa lista sus contratos y su comunidad', $hijos === $esperados);
comprueba('el contrato sabe a qué casa pertenece', elemento($pdo, $id['luz'])['enlace_nombre'] === 'Casa de prueba');
$res = resumen_enlazados($pdo);
comprueba('el resumen cuenta 3 y suma 80 €/mes (60 + 240/12; la comunidad de prueba no lleva coste)', $res[$id['casa']]['n'] === 3 && abs($res[$id['casa']]['mensual'] - 80.0) < 0.001, json_encode($res));
comprueba('la vivienda ofrece añadir suministros, seguros, su hipoteca, su comunidad, alquileres e impuestos', array_column(tipos_que_enlazan('vivienda', 'inmueble'), 1) === ['equipo', 'suministro', 'seguro', 'hipoteca', 'comunidad', 'alquiler', 'impuesto']);
comprueba('el vehículo ofrece su seguro y su impuesto', array_column(tipos_que_enlazan('vehiculos', 'vehiculo'), 1) === ['seguro', 'impuesto']);
comprueba('un DNI no cuelga de nada', tipos_que_enlazan('documentos', 'dni') === []);
$e = lanza(static fn() => guardar_elemento($pdo, 'contratos', 'suministro', $sum($id['furgo']), $id['luz'], $id['admin']));
comprueba('un suministro no se puede enlazar a un vehículo', $e instanceof ErrorValidacion);
guardar_elemento($pdo, 'contratos', 'seguro', ['nombre' => 'Seguro de la furgo', 'enlace_id' => $id['furgo'], 'datos' => ['ramo' => 'Coche o moto']], null, $id['admin']);
comprueba('un seguro sí se puede enlazar a un vehículo', count(elementos_enlazados($pdo, $id['furgo'])) === 1);
cambiar_activo_elemento($pdo, $id['seguro'], false, null);
$quedan = array_column(elementos_enlazados($pdo, $id['casa']), 'id');
comprueba('archivar un contrato lo quita de la ficha de la casa', !in_array($id['seguro'], $quedan, true) && in_array($id['luz'], $quedan, true));
cambiar_activo_elemento($pdo, $id['seguro'], true, null);
guardar_elemento($pdo, 'vivienda', 'equipo', ['nombre' => 'Caldera', 'enlace_id' => $id['casa'], 'datos' => ['marca' => 'Junkers']], $id['caldera'], $id['admin']);
comprueba('el equipamiento sí se enlaza a su vivienda', elemento($pdo, $id['caldera'])['enlace_id'] === $id['casa']);
$agenda_c = guardar_elemento($pdo, 'vivienda', 'contacto', ['nombre' => 'Fontanero', 'enlace_id' => $id['casa'], 'datos' => ['oficio' => 'Fontanería']], null, $id['admin']);
comprueba('un tipo sin «enlace» ignora el enlace que le llegue', elemento($pdo, (int)$agenda_c)['enlace_id'] === null);
borrar_elemento($pdo, $id['casa'], $id['admin']);
comprueba('borrar la casa deja sus contratos sin enlace (no huérfanos)', elemento($pdo, $id['luz'])['enlace_id'] === null);

echo "\nComunidad de propietarios\n";
$an = analisis_comunidad($pdo, $id['comunidad']);
comprueba('los recibos se agrupan por año, el más reciente primero', array_keys($an['anios']) === [2026, 2025], implode(',', array_keys($an['anios'])));
$p = partidas_de_registro($pdo, $id['recibo_1t']);
comprueba('la parte sale de los coeficientes de la ficha (escalera 21,23 %, zona común 8,355 %)',
    (float)$p[0]['parte'] === 61.65 && (float)$p[1]['parte'] === 23.13, json_encode(array_column($p, 'parte')));
$pis = $an['anios'][2026]['categorias']['Piscina'];
comprueba('separa lo ordinario de las obras', abs($pis['ord'] - 123.30) < 0.001 && abs($pis['extra'] - 228.52) < 0.001 && abs($pis['total'] - 351.82) < 0.001, json_encode($pis));
comprueba('la partida más cara va primero', array_key_first($an['anios'][2026]['categorias']) === 'Piscina');
comprueba('el año suma lo pagado en los recibos', abs($an['anios'][2026]['pagado'] - 374.95) < 0.001);
comprueba('el recibo medio sin obras no cuenta la obra', abs($an['anios'][2026]['media_ordinaria'] - round((84.78 + 61.65) / 2, 2)) < 0.001, (string)$an['anios'][2026]['media_ordinaria']);
comprueba('un recibo sin desglose se cuenta como tal', $an['anios'][2025]['sin_desglose'] === 1 && $an['anios'][2025]['categorias'] === []);
comprueba('cada recibo lleva su etiqueta de mes', $an['recibos'][$id['recibo_2t']]['etiqueta'] === 'jun 26');
$r = guardar_partidas($pdo, $id['recibo_1t'], [
    ['concepto' => 'Mantenimiento piscina', 'categoria' => 'Piscina', 'zona' => 'escalera', 'total' => 290.40],
    ['concepto' => 'Administrador', 'categoria' => 'Administración', 'zona' => 'comun', 'total' => 276.86],
], null);
comprueba('regrabar el desglose lo sustituye (no duplica) y dice si cuadra con el recibo',
    count(partidas_de_registro($pdo, $id['recibo_1t'])) === 2 && abs($r['diferencia']) < 0.001, json_encode($r));
$r = guardar_partidas($pdo, $id['recibo_4t25'], [['concepto' => 'Ajuste a mano', 'categoria' => 'Otros', 'total' => 500, 'parte' => 80]], null);
comprueba('la parte también se puede dar a mano', abs($r['suma_parte'] - 80) < 0.001 && abs($r['diferencia']) < 0.001, json_encode($r));
$e = lanza(static fn() => guardar_partidas($pdo, $id['registro'], [['concepto' => 'x', 'categoria' => 'Otros', 'total' => 1, 'parte' => 1]], null));
comprueba('las partidas solo van en recibos de una comunidad', $e instanceof ErrorValidacion);
$e = lanza(static fn() => guardar_partidas($pdo, $id['recibo_1t'], [['concepto' => 'Jardín', 'categoria' => 'Jardinería', 'zona' => 'comun', 'total' => 10]], null));
comprueba('una categoría que no está en la lista se rechaza', $e instanceof ErrorValidacion);
comprueba('y no se pierde lo que había', count(partidas_de_registro($pdo, $id['recibo_1t'])) === 2);
borrar_registro($pdo, $id['recibo_2t'], $id['comunidad'], null);
comprueba('borrar un recibo borra sus partidas', partidas_de_registro($pdo, $id['recibo_2t']) === []);

echo "\nNóminas leídas de finanzas\n";
$anios_fin = nominas_de_ejemplo();
$rn = resumen_nominas($anios_fin, '2026-10-03');
comprueba('el resumen es del año más reciente', $rn['anio'] === 2026 && $rn['n'] === 3 && $rn['pagas'] === 15);
comprueba('suma el líquido del año, extra incluida', abs($rn['liquido'] - 5700.0) < 0.01, (string)$rn['liquido']);
comprueba('a 3 de octubre falta la de septiembre', $rn['falta'] === '2026-09', (string)$rn['falta']);
comprueba('a mediados de septiembre no falta ninguna', resumen_nominas($anios_fin, '2026-09-15')['falta'] === null);
comprueba('marca el mes en que cambia la diferencia con el banco', $rn['cambia'] === ['2026-08']);
comprueba('el salario base es el del último recibo', $rn['salario_base'] === 1941.45);
comprueba('sin nóminas no hay resumen', resumen_nominas([], '2026-10-03') === null);
comprueba('mes_es', mes_es('2026-09') === 'septiembre 2026');
comprueba('sin clave no se llama a finanzas', !finanzas_configurada() && finanzas_nominas()['error'] !== null);

echo "\nVerificación en dos pasos\n";
$rfc = base32_codificar('12345678901234567890');   // la semilla del RFC 6238
comprueba('base32 de ida y vuelta', $rfc === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' && base32_decodificar($rfc) === '12345678901234567890');
comprueba('TOTP = vectores del RFC 6238', totp_codigo($rfc, intdiv(59, 30), 8) === '94287082'
    && totp_codigo($rfc, intdiv(1111111109, 30), 8) === '07081804' && totp_codigo($rfc, intdiv(2000000000, 30), 8) === '69279037');
comprueba('6 cifras con ceros a la izquierda', totp_codigo($rfc, intdiv(1234567890, 30)) === '005924');
$t = 1900000000;
comprueba('vale el código de ahora', totp_paso_valido($rfc, totp_codigo($rfc, intdiv($t, 30)), null, $t) === intdiv($t, 30));
comprueba('y el de hace 30 s (reloj desfasado)', totp_paso_valido($rfc, totp_codigo($rfc, intdiv($t, 30) - 1), null, $t) !== null);
comprueba('pero no el de hace un minuto y medio', totp_paso_valido($rfc, totp_codigo($rfc, intdiv($t, 30) - 3), null, $t) === null);
comprueba('ni uno ya usado', totp_paso_valido($rfc, totp_codigo($rfc, intdiv($t, 30)), intdiv($t, 30), $t) === null);
$adm = usuario($pdo, $id['admin']);
comprueba('el admin de pruebas la tiene; un miembro no está obligado', dos_pasos_activa($adm) && dos_pasos_obligatoria($adm)
    && !dos_pasos_obligatoria(usuario($pdo, $id['miembro'])));
$e = lanza(static fn() => activar_dos_pasos($pdo, $id['miembro'], $rfc, '000000'));
comprueba('activar con un código malo no activa', $e instanceof ErrorValidacion && !dos_pasos_activa(usuario($pdo, $id['miembro'])));
$cods = activar_dos_pasos($pdo, $id['miembro'], $rfc, totp_codigo($rfc, intdiv(time(), 30)));
comprueba('activar da 8 códigos de recuperación', count($cods) === 8 && dos_pasos_activa(usuario($pdo, $id['miembro'])));
comprueba('el mismo código de la app no vale dos veces', comprobar_segundo_paso($pdo, usuario($pdo, $id['miembro']), totp_codigo($rfc, intdiv(time(), 30))) === null);
comprueba('un código de recuperación entra', comprobar_segundo_paso($pdo, usuario($pdo, $id['miembro']), strtoupper($cods[0])) === 'recuperacion');
comprueba('y se gasta', comprobar_segundo_paso($pdo, usuario($pdo, $id['miembro']), $cods[0]) === null
    && codigos_recuperacion_restantes(usuario($pdo, $id['miembro'])) === 7);
quitar_dos_pasos($pdo, $id['miembro'], $id['admin']);
comprueba('quitarla la deja sin semilla ni códigos', !dos_pasos_activa(usuario($pdo, $id['miembro'])) && codigos_recuperacion_restantes(usuario($pdo, $id['miembro'])) === 0);

echo "\nPase entre el segundo cerebro y finanzas\n";
// El mismo pase de ejemplo está en las pruebas de finanzas: si los dos pase.php
// dejan de coincidir, falla una de las dos.
comprueba('pase de ejemplo compartido', pase_crear('clave-compartida', ['t' => 'entrar', 'e' => 'yo@ejemplo.test', 'a' => 'nomina.php', 'x' => 1900000000, 'n' => 'abc123'])
    === 'eyJ0IjoiZW50cmFyIiwiZSI6InlvQGVqZW1wbG8udGVzdCIsImEiOiJub21pbmEucGhwIiwieCI6MTkwMDAwMDAwMCwibiI6ImFiYzEyMyJ9.KuBBKoNzEXd0-IhDTOo9ZXH2JvMW-xSpT9D38prqGII');
$p = pase_crear('k', ['t' => 'entrar', 'e' => 'yo@ejemplo.test']);
comprueba('se lee con la misma clave', (pase_leer('k', $p, 'entrar')['e'] ?? '') === 'yo@ejemplo.test');
comprueba('no con otra clave', pase_leer('otra', $p, 'entrar') === null);
comprueba('ni como otro tipo', pase_leer('k', $p, 'salir') === null);
comprueba('ni tocado', pase_leer('k', 'x' . $p, 'entrar') === null && pase_leer('k', $p . 'x', 'entrar') === null);
comprueba('ni caducado', pase_leer('k', $p, 'entrar', time() + 61) === null);
comprueba('ni con clave vacía', pase_leer('', $p, 'entrar') === null);
comprueba('destino: solo páginas de la app', pase_destino_valido('nomina.php') === 'nomina.php'
    && pase_destino_valido('revisar.php?mes=2026-09') === 'revisar.php?mes=2026-09'
    && pase_destino_valido('https://malo.example/') === 'index.php' && pase_destino_valido('../config.php') === 'index.php');

echo "\nImpuestos (IBI e impuesto de circulación)\n";
$pi = bd_nueva();
$ii = sembrar($pi);
$ibi = guardar_elemento($pi, 'contratos', 'impuesto', ['nombre' => 'IBI de la casa', 'enlace_id' => $ii['casa'],
    'datos' => ['impuesto' => 'IBI', 'coste' => '412,50', 'periodicidad' => 'Anual', 'renovacion' => '2026-11-20', 'domiciliado' => 'Sí']], null, $ii['admin']);
$ivtm = guardar_elemento($pi, 'contratos', 'impuesto', ['nombre' => 'Impuesto de circulación de la furgo', 'enlace_id' => $ii['furgo'],
    'datos' => ['impuesto' => 'Impuesto de circulación', 'coste' => '96,40', 'periodicidad' => 'Anual']], null, $ii['admin']);
comprueba('el IBI cuelga de la casa y el impuesto de circulación, del vehículo', elemento($pi, (int)$ibi)['enlace_id'] === $ii['casa'] && elemento($pi, (int)$ivtm)['enlace_id'] === $ii['furgo']);
$av = agenda($pi, 400, null, (int)$ibi);
comprueba('el próximo pago crea su aviso, y sin fecha no hay aviso',
    count($av) === 1 && $av[0]['titulo'] === 'Pagar el impuesto · IBI de la casa' && $av[0]['fecha'] === '2026-11-20' && agenda($pi, 4000, null, (int)$ivtm) === []);
$e = lanza(static fn() => guardar_elemento($pi, 'contratos', 'impuesto', ['nombre' => 'IBI', 'enlace_id' => $ii['casa'], 'datos' => ['impuesto' => 'Plusvalía']], null, $ii['admin']));
comprueba('solo vale un impuesto de la lista', $e instanceof ErrorValidacion);
$gi = analisis_gastos_fijos(elementos_con_coste($pi), historial_de_gastos($pi, hoy()), hoy());
$imp = $gi['partidas']['impuesto'] ?? null;
comprueba('tiene su partida, con su color, tras las suscripciones y antes de «otros»',
    $imp !== null && $imp['nombre'] === 'Impuestos' && $imp['serie'] === 7 && abs($imp['mensual'] - (412.50 + 96.40) / 12) < 0.001
    && array_search('impuesto', array_keys(partidas_gasto())) === array_search('suscripcion', array_keys(partidas_gasto())) + 1);
$cal = array_column($gi['calendario'], 'extra', 'mes');
comprueba('el IBI se cobra en el mes de su próximo pago (con el seguro de prueba y el reparto del que no tiene fecha)', abs(($cal['2026-11'] ?? 0) - (240 + 412.50 + 96.40 / 12)) < 0.001, json_encode($cal));
comprueba('el impuesto sin fecha se reparte por meses', in_array('Impuesto de circulación de la furgo', $gi['sin_fecha'], true));

echo "\nPersonas y accesos\n";
$e = lanza(static fn() => crear_usuario($pdo, ['nombre' => 'Otro', 'email' => 'ADMIN@ejemplo.test']));
comprueba('no se repite email (sin distinguir mayúsculas)', $e instanceof ErrorValidacion);
[$nuevo, $pass] = crear_usuario($pdo, ['nombre' => 'Abuela', 'email' => 'abuela@ejemplo.test']);
comprueba('sin contraseña → temporal y debe cambiarla', (int)usuario($pdo, $nuevo)['debe_cambiar'] === 1 && password_verify($pass, usuario($pdo, $nuevo)['password']));
$e = lanza(static fn() => cambiar_password($pdo, $nuevo, 'corta'));
comprueba('contraseña de menos de 12 no vale', $e instanceof ErrorValidacion);
cambiar_password($pdo, $nuevo, 'una-de-verdad-larga');
comprueba('al cambiarla deja de ser temporal', (int)usuario($pdo, $nuevo)['debe_cambiar'] === 0);
$e = lanza(static fn() => guardar_persona($pdo, ['nombre' => 'Futuro', 'fecha_nacimiento' => '2099-01-01']));
comprueba('no se nace en el futuro', $e instanceof ErrorValidacion);
comprueba('la actividad apunta quién hizo qué', count(actividad_reciente($pdo, 100)) > 20);
terminar();
