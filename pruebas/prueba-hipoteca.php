<?php
// La hipoteca que se calcula sola: el Euríbor (del CSV del Banco de España o del BCE), el cuadro
// de amortización (que reproduce el del banco), las revisiones, los cargos de finanzas y la puesta
// al día sin que nadie toque nada (ficha, aviso, historial de tipos).
require __DIR__ . '/arranque.php';
require __DIR__ . '/../includes/api.php';
echo "Hipoteca y Euríbor\n";

// ---------------------------------------------------------------- Euríbor
$csv = "\"CÓDIGO DE LA SERIE\",D_DNBAA572,D_DNBAF172,D_DNBAA304\n\"NÚMERO SECUENCIAL\",1,2,3\n"
     . "\"30 SEP 2026\",2.4,3.300,4.0\n\"01 OCT 2026\",2.4,3.200,4.0\n\"02 OCT 2026\",2.4,3.250,4.0\n\"03 OCT 2026\",2.4,\"_\",4.0\n"
     . "\"29 SEP 2026\",2.4,3.194,4.0\n\"FUENTE\",\"\",\"\",\"\"\n";
$s = euribor_leer_bde($csv, '2026-10-03');
comprueba('Euríbor del Banco de España: la media de cada mes con su serie, sin los días vacíos',
    $s['2026-10']['valor'] === 3.225 && $s['2026-10']['dias'] === 2 && $s['2026-09']['valor'] === 3.247, json_encode($s));
$s5 = euribor_leer_bde($csv, '2026-10-05');
comprueba('…el mes en curso es provisional y el anterior se cierra pasados 3 días', !$s5['2026-10']['definitivo'] && $s5['2026-09']['definitivo']
    && !$s['2026-09']['definitivo']);
$json = json_encode([['serie' => 'D_DNBAF172', 'fechas' => ['2026-10-02T08:15:00Z', '2026-10-01T08:15:00Z', '2026-09-30T08:15:00Z', '2026-09-29T08:15:00Z', '2026-08-31T08:15:00Z'],
                       'valores' => [3.250, 3.200, 3.300, 3.194, 2.9]]]);
$j = euribor_leer_bde_cualquiera($json, '2026-10-05');
comprueba('Euríbor de la API REST del Banco de España (JSON): igual que el CSV', $j['2026-10']['valor'] === 3.225 && $j['2026-09']['valor'] === 3.247
    && $j['2026-09']['definitivo'], json_encode($j));
comprueba('…sin el primer mes del rango, que llega a medias', !isset($j['2026-08']));
$bce = euribor_leer_bce("KEY,FREQ,TIME_PERIOD,OBS_VALUE\nFM.M,M,2018-10,-0.1538261\nFM.M,M,2026-09,3.247\n");
comprueba('Euríbor del BCE: redondeado como el BOE (−0,154)', $bce['2018-10']['valor'] === -0.154 && $bce['2026-09']['definitivo']);

$pdo = bd_nueva();
$id = sembrar($pdo);
euribor_guardar($pdo, ['2026-08' => ['valor' => 2.954, 'dias' => 21, 'definitivo' => true], '2026-09' => ['valor' => 3.247, 'dias' => 22, 'definitivo' => true],
                       '2026-10' => ['valor' => 3.233, 'dias' => 6, 'definitivo' => false]]);
comprueba('guardar el Euríbor no repite lo que no cambia', euribor_guardar($pdo, ['2026-09' => ['valor' => 3.247, 'dias' => 22, 'definitivo' => true]]) === 0);
comprueba('…y un mes cerrado no vuelve a provisional', euribor_guardar($pdo, ['2026-09' => ['valor' => 3.1, 'dias' => 3, 'definitivo' => false]]) === 0);
$e = euribor_estado($pdo);
comprueba('estado del Euríbor: último cerrado y provisional', $e['cerrado']['mes'] === '2026-09' && $e['provisional']['valor'] === 3.233 && $e['provisional']['dias'] === 6);
comprueba('sin URL (pruebas) no sale a la red', euribor_actualizar($pdo, true)['consultado'] === false);

// ---------------------------------------------------------------- el cuadro del banco
// Los primeros 21 recibos de la hipoteca de casa (cuadro de Mediolanum): tras el 21 quedan 116.573,04 €.
$hip = ['id' => 0, 'datos' => ['capital_inicial' => 124000, 'cuotas_totales' => 300, 'primera_cuota' => '2017-12-07', 'periodicidad' => 'Mensual',
        'revision_interes' => 'Trimestral', 'diferencial' => 1.05, 'tipo_inicial' => 1.6, 'meses_tipo_inicial' => 12]];
$banco = [['desde' => '2017-12-07', 'coste' => 501.77, 'tipo' => 1.6, 'nota' => 'cuadro'], ['desde' => '2018-12-07', 'coste' => 462.97, 'tipo' => 0.896, 'nota' => 'cuadro'],
          ['desde' => '2019-03-07', 'coste' => 464.99, 'tipo' => 0.934, 'nota' => 'cuadro'], ['desde' => '2019-06-07', 'coste' => 465.20, 'tipo' => 0.938, 'nota' => 'cuadro']];
$c = cuadro_hipoteca($hip, $banco, [], [], [], '2019-08-20');
comprueba('el cuadro reproduce el del banco al céntimo (116.573,04 € tras la cuota 21)', abs($c['filas'][20]['pendiente'] - 116573.04) < 0.005, (string)$c['filas'][20]['pendiente']);
comprueba('…con sus intereses (165,33 € la primera; 91,41 € la 21)', $c['filas'][0]['intereses'] === 165.33 && $c['filas'][20]['intereses'] === 91.41);
// Sin tipos guardados, con el Euríbor: la revisión de diciembre de 2018 usa el de octubre (−0,154 + 1,05 = 0,896).
$c2 = cuadro_hipoteca($hip, [], ['2018-10' => ['valor' => -0.154, 'dias' => 23, 'definitivo' => true]], [], [], '2019-01-20');
comprueba('una revisión sin tipo guardado: Euríbor de dos meses antes + diferencial', $c2['filas'][12]['tipo'] === 0.896 && $c2['filas'][12]['origen'] === 'calculado'
    && $c2['filas'][12]['cuota'] === 462.97, json_encode($c2['filas'][12]));
comprueba('…y sin Euríbor de ese mes, se estima con el último', $c2['filas'][15]['origen'] === 'estimado' && $c2['filas'][15]['estado'] === 'estimada');
comprueba('la última cuota deja el préstamo a cero', end($c2['filas'])['pendiente'] === 0.0 && count($c2['filas']) === 300);
$r = $c['resumen'];
comprueba('resumen: capital pendiente, cuotas y próxima', $r['cuotas_pagadas'] === 21 && $r['cuotas_pendientes'] === 279 && $r['proxima']['fecha'] === '2019-09-07'
    && abs($r['capital_pendiente'] - 116573.04) < 0.005);

// Cargos del banco (de finanzas): casan con su cuota aunque lleguen dos días tarde; el que falta se dice.
$cargos = [['fecha' => '2019-06-10', 'importe' => 465.20], ['fecha' => '2019-08-07', 'importe' => 465.25]];
$c3 = cuadro_hipoteca($hip, $banco, [], [], $cargos, '2019-08-20');
$est = array_column(array_slice($c3['filas'], 17, 4), 'estado', 'fecha');
comprueba('cobrada / sin cargo en el extracto / anterior a los datos de finanzas', $est['2019-06-07'] === 'cobrada' && $est['2019-07-07'] === 'sin_cargo'
    && $est['2019-08-07'] === 'cobrada' && $est['2019-05-07'] === 'pagada', json_encode($est));
comprueba('…y el cobro lleva su importe real', $c3['filas'][20]['cobro']['importe'] === 465.25);

// Amortización anticipada: reduciendo plazo, acaba antes con la misma cuota; reduciendo cuota, baja la cuota.
$plazo = cuadro_hipoteca($hip, $banco, [], [['fecha' => '2019-07-01', 'importe' => 10000, 'modo' => 'plazo']], [], '2019-08-20');
$cuota = cuadro_hipoteca($hip, $banco, [], [['fecha' => '2019-07-01', 'importe' => 10000, 'modo' => 'cuota']], [], '2019-08-20');
comprueba('amortizar reduciendo plazo: misma cuota y menos cuotas', count($plazo['filas']) < 300 && $plazo['filas'][19]['cuota'] === 465.20
    && $plazo['filas'][19]['amortizado'] === 10000.0);
comprueba('amortizar reduciendo cuota: mismas cuotas y cuota más baja', count($cuota['filas']) === 300 && $cuota['filas'][19]['cuota'] < 430);

// ---------------------------------------------------------------- se pone al día sola
$hid = guardar_elemento($pdo, 'contratos', 'hipoteca', ['nombre' => 'Hipoteca de prueba', 'persona_id' => $id['yo'] ?? null, 'datos' => [
    'compania' => 'Banco', 'coste' => '580', 'periodicidad' => 'Mensual', 'capital_inicial' => '100000', 'cuotas_totales' => '300',
    'revision_interes' => 'Trimestral', 'diferencial' => '1,05', 'tipo_inicial' => '1,6', 'meses_tipo_inicial' => '12', 'primera_cuota' => '2017-12-07']], null, $id['admin']);
// Hacia atrás no hay tipos: los pone el Euríbor de la tabla; para ir rápido, un tipo «del banco» en junio de 2026.
guardar_precio($pdo, $hid, '2026-09-07', 520.00, null, 'cuadro del banco', null, 3.905);
$hecho = hipotecas_al_dia($pdo, '2026-10-03');
$el = elemento($pdo, $hid);
comprueba('al día: la ficha lleva la cuota vigente y el tipo en texto', (float)$el['datos']['coste'] === 520.0
    && $el['datos']['interes'] === 'Variable: Euríbor + 1,05 (3,905' . NBSP . '% desde el 7/9/2026)', json_encode($el['datos']));
$avisos = array_values(array_filter(agenda($pdo, 120, null, $hid), static fn($v) => $v['origen'] === 'auto:hipoteca'));
comprueba('…y avisa de la próxima subida, estimada con el Euríbor provisional', count($avisos) === 1 && $avisos[0]['fecha'] === '2026-12-07'
    && str_contains($avisos[0]['titulo'], 'sube a ≈') && str_contains((string)$avisos[0]['notas'], 'provisional con 6 días'), json_encode($avisos));
comprueba('…es un aviso informativo: no se borra y hecho no va al historial', $avisos[0]['informativo']
    && lanza(static fn() => borrar_vencimiento($pdo, $avisos[0]['id'])) instanceof RuntimeException);
hipotecas_al_dia($pdo, '2026-10-03');
comprueba('ponerla al día dos veces no repite nada', count(array_filter(agenda($pdo, 120, null, $hid), static fn($v) => $v['origen'] === 'auto:hipoteca')) === 1
    && count(tipos_de_hipoteca($pdo, $hid)) === 1);
// Cierra octubre y llega la revisión (7/11): el tipo se guarda solo, con su nota.
euribor_guardar($pdo, ['2026-10' => ['valor' => 3.233, 'dias' => 22, 'definitivo' => true]]);
$hecho = hipotecas_al_dia($pdo, '2026-11-08');
$tipos = tipos_de_hipoteca($pdo, $hid);
$dic = end($tipos);
comprueba('en la revisión se guarda el tipo nuevo (Euríbor cerrado + diferencial)', $dic['desde'] === '2026-12-07' && $dic['tipo'] === 4.283
    && str_contains($dic['nota'], 'calculado') && $hecho && str_contains($hecho[0], '4,283'), json_encode($dic) . json_encode($hecho));
$av = array_values(array_filter(agenda($pdo, 120, null, $hid), static fn($v) => $v['origen'] === 'auto:hipoteca'));
comprueba('…el aviso deja de ser una estimación', count($av) === 1 && !str_contains($av[0]['titulo'], '≈'), json_encode($av));
comprueba('…y la ficha sigue con la cuota de noviembre hasta que llega la de diciembre', (float)elemento($pdo, $hid)['datos']['coste'] === 520.0);
hipotecas_al_dia($pdo, '2026-12-08');
$el = elemento($pdo, $hid);
comprueba('el día de la cuota, la ficha pasa a la cuota nueva', abs((float)$el['datos']['coste'] - $dic['coste']) < 0.001 && str_contains($el['datos']['interes'], '4,283'));
$st = $pdo->prepare("SELECT estado FROM vencimientos WHERE elemento_id = ? AND origen = 'auto:hipoteca' AND fecha = '2026-12-07'");
$st->execute([$hid]);
comprueba('…y el aviso se da por hecho solo', $st->fetchColumn() === 'hecho');

// Una amortización apuntada en el historial rehace el cuadro y no cuenta como gasto.
$antes = cuadro_de($pdo, elemento($pdo, $hid), null, '2026-12-08')['resumen']['fin'];
crear_registro($pdo, ['elemento_id' => $hid, 'tipo' => HIPOTECA_TIPO_AMORTIZACION, 'fecha' => '2026-12-20', 'titulo' => 'Reducir plazo', 'coste' => '5000'], $id['admin']);
$despues = cuadro_de($pdo, elemento($pdo, $hid), null, '2026-12-28')['resumen']['fin'];
comprueba('una amortización anticipada en el historial acorta el préstamo', $despues < $antes, "{$antes} → {$despues}");
comprueba('…y no es gasto del año', gasto_ultimo_ano($pdo, $hid) === 0.0);

// ---------------------------------------------------------------- la API
$api = static fn(string $accion, array $datos = []) => api_ejecutar($pdo, ['accion' => $accion, 'datos' => json_encode($datos)]);
$r = $api('hipoteca', ['id' => $hid, 'filas' => true]);
comprueba('API hipoteca: resumen, Euríbor y cuota a cuota', isset($r['resumen']['capital_pendiente'], $r['euribor']['cerrado']) && count($r['filas']) > 100);
$f = $api('fichas', ['ids' => [$hid]])['fichas'][(string)$hid];
comprueba('API fichas (lo que lee finanzas): la amortización calculada', isset($f['amortizacion']['capital_pendiente'], $f['amortizacion']['cuotas_pagadas'])
    && $f['amortizacion']['origen'] === 'segundo cerebro (cuadro calculado)');
$p = $api('precio', ['elemento_id' => $hid, 'desde' => '2017-12-07', 'coste' => 400, 'tipo' => '1,6']);
comprueba('API precio con el tipo de la cuota', end($p['precios'])['tipo'] !== null && $p['precios'][0]['tipo'] === 1.6);
comprueba('API precio rechaza un tipo que no es un porcentaje', lanza(static fn() => $api('precio', ['elemento_id' => $hid, 'desde' => '2018-01-07', 'coste' => 1, 'tipo' => 'mucho'])) instanceof Throwable);
comprueba('API euribor: sin red en las pruebas, dice que no ha consultado', $api('euribor')['consulta']['consultado'] === false);
$rb = api_ejecutar($pdo, ['accion' => 'euribor', 'bde' => $json]);
comprueba('API euribor con el JSON del Banco de España (lo que manda la tarea de GitHub): lo guarda y dice de dónde viene',
    $rb['consulta']['fuente'] === 'Banco de España (vía GitHub)' && $rb['euribor']['fuente'] === 'Banco de España (vía GitHub)'
    && $rb['euribor']['cerrado']['mes'] === '2026-10', json_encode($rb['euribor']['cerrado']));
comprueba('…y algo que no es el Euríbor se rechaza', lanza(static fn() => api_ejecutar($pdo, ['accion' => 'euribor', 'bde' => '{"x":1}'])) instanceof RuntimeException);
comprueba('API hipoteca: un elemento que no es hipoteca se rechaza', lanza(static fn() => $api('hipoteca', ['id' => $id['luz']])) instanceof RuntimeException);
comprueba('API estado lleva el Euríbor', isset($api('estado')['euribor']['cerrado']));
terminar();
