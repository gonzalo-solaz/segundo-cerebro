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
comprueba('la vivienda ofrece añadir suministros, seguros y su comunidad', array_column(tipos_que_enlazan('vivienda', 'inmueble'), 1) === ['equipo', 'suministro', 'seguro', 'comunidad']);
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
