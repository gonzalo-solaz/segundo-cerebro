<?php
// =====================================================================
//  Gastos fijos: a dónde va lo que se paga sí o sí (gastos-fijos.php).
//
//  Sale de los mismos datos que la cifra del panel («gastos fijos al mes»):
//  los elementos con «coste» y «periodicidad». Aquí se desglosa por partida,
//  por cosa (la casa, cada vehículo) y por mes de cargo, y se añade lo que
//  miraría un asesor: renovaciones con su plazo para no renovar, importes
//  que faltan o no cuadran con las facturas, el peso de la hipoteca y, con
//  finanzas (solo admin), ahorro, colchón y gastos del banco que aquí no
//  están. Petición de Gonzalo, 4/10/2026: «saber a dónde se va el gasto».
//
//  Funciones puras (los datos entran, el análisis sale) para probarlas sin
//  base de datos. Las lecturas son elementos_con_coste() (elementos.php) e
//  historial_de_gastos() (registros.php).
// =====================================================================

// Las partidas, en orden FIJO: el de la barra apilada y el de sus colores
// (--serie-N en assets/app.css, validados para que dos vecinas se distingan
// también con daltonismo). El color sigue a la partida, no a su tamaño.
// Lo que no encaja va a «otros», en gris.
function partidas_gasto(): array {
    return [
        'hipoteca'    => ['nombre' => 'Hipoteca', 'serie' => 1],
        'suministro'  => ['nombre' => 'Suministros', 'serie' => 2],
        'seguro'      => ['nombre' => 'Seguros', 'serie' => 3],
        'comunidad'   => ['nombre' => 'Comunidad', 'serie' => 4],
        'suscripcion' => ['nombre' => 'Suscripciones', 'serie' => 5],
        'otros'       => ['nombre' => 'Actividades y otros', 'serie' => 0],
    ];
}

function partida_de(array $el): string {
    return $el['seccion'] === 'contratos' && $el['tipo'] !== 'otros' && isset(partidas_gasto()[$el['tipo']]) ? $el['tipo'] : 'otros';
}

/**
 * Cuándo se cobra algo que no es mensual, dentro de [$desde, $hasta].
 * El ancla es la renovación de la ficha (seguros, suscripciones) o, si no
 * hay, el último recibo o factura + su periodicidad (la comunidad: entonces
 * la fecha es aproximada). Una renovación vieja que no se actualizó se lleva
 * hacia delante. Sin ancla no se sabe: null (y se reparte por meses).
 */
function fechas_de_cargo(array $datos, int $meses, ?string $ultimo, string $desde, string $hasta): ?array {
    $ren = (string)($datos['renovacion'] ?? '');
    if (fecha_valida($ren)) {
        [$ancla, $k, $aprox] = [$ren, 0, false];
    } elseif ($ultimo !== null && fecha_valida($ultimo)) {
        [$ancla, $k, $aprox] = [$ultimo, 1, true];
    } else {
        return null;
    }
    // Siempre desde el ancla (k × periodo): sumar de uno en uno arrastraría
    // el recorte de fin de mes (31 ene → 30 abr → 30 jul…).
    while (sumar_meses($ancla, $k * $meses) < $desde) $k++;
    $fechas = [];
    for (; ($f = sumar_meses($ancla, $k * $meses)) <= $hasta; $k++) $fechas[] = $f;
    return ['fechas' => $fechas, 'aprox' => $aprox];
}

// «16 años y 1 mes», «8 meses».
function tiempo_hasta(string $desde, string $hasta): string {
    $d = (new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta));
    $partes = [];
    if ($d->y) $partes[] = $d->y . ($d->y === 1 ? ' año' : ' años');
    if ($d->m || !$partes) $partes[] = $d->m . ($d->m === 1 ? ' mes' : ' meses');
    return implode(' y ', $partes);
}

function pct_es(float $p): string {
    return ($p > 0 && $p < 1 ? '<1' : numero_es(round($p, $p < 10 ? 1 : 0))) . ' %';
}

// «a, b y c».
function lista_y(array $trozos): string {
    $ultimo = array_pop($trozos);
    return $trozos ? implode(', ', $trozos) . ' y ' . $ultimo : (string)$ultimo;
}

// Para comparar nombres de categoría sin depender de mbstring.
function texto_comparable(string $s): string {
    return strtr(strtolower($s), ['Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n',
                                  'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
}

/**
 * El análisis entero. $elementos = elementos_con_coste(); $historial =
 * historial_de_gastos(). Devuelve los totales, las partidas (en su orden
 * fijo), las cosas (de mayor a menor), el calendario de 12 meses desde el
 * actual, lo que falta por apuntar y la lista «Qué revisar».
 */
function analisis_gastos_fijos(array $elementos, array $historial, string $hoy): array {
    $per = periodicidades();
    $inicio = substr($hoy, 0, 7) . '-01';
    $fin = sumar_dias(sumar_meses($inicio, 12), -1);
    $meses = [];
    for ($i = 0; $i < 12; $i++) {
        $m = substr(sumar_meses($inicio, $i), 0, 7);
        $meses[$m] = ['mes' => $m, 'base' => 0.0, 'extra' => 0.0, 'total' => 0.0, 'cargos' => []];
    }

    $items = [];
    $sin_importe = [];
    $sin_fecha = [];
    foreach ($elementos as $el) {
        $d = $el['datos'];
        $n = $per[$d['periodicidad'] ?? ''] ?? 0;
        $coste = isset($d['coste']) && is_numeric($d['coste']) ? (float)$d['coste'] : 0.0;
        if ($coste <= 0 || $n === 0) {
            $sin_importe[] = ['id' => $el['id'], 'nombre' => $el['nombre'], 'falta' => $coste <= 0 ? 'coste' : 'periodicidad'];
            continue;
        }
        $h = $historial[$el['id']] ?? null;
        $it = [
            'id' => $el['id'], 'nombre' => $el['nombre'], 'seccion' => $el['seccion'], 'tipo' => $el['tipo'],
            'tipo_nombre' => tipo_def($el['seccion'], $el['tipo'])['nombre'] ?? $el['tipo'],
            'partida' => partida_de($el), 'coste' => $coste, 'periodicidad' => (string)$d['periodicidad'], 'meses' => $n,
            'mensual' => $coste / $n, 'enlace_id' => $el['enlace_id'] ?? null, 'enlace_nombre' => $el['enlace_nombre'] ?? null,
            'persona_nombre' => $el['persona_nombre'] ?? null, 'datos' => $d,
            'proximo' => null, 'aprox' => false, 'real' => null,
        ];

        if ($n === 1) {
            // Lo mensual, todos los meses (la hipoteca, hasta su última cuota).
            $hasta = (string)($d['fecha_fin'] ?? '');
            foreach ($meses as $m => &$mm) {
                if (fecha_valida($hasta) && $m . '-01' > $hasta) continue;
                $mm['base'] += $coste;
            }
            unset($mm);
        } else {
            $c = fechas_de_cargo($d, $n, $h['ultimo'] ?? null, $inicio, $fin);
            if ($c === null) {
                $sin_fecha[] = $el['nombre'];
                foreach ($meses as &$mm) $mm['extra'] += $it['mensual'];
                unset($mm);
            } else {
                foreach ($c['fechas'] as $f) {
                    $m = substr($f, 0, 7);
                    $meses[$m]['extra'] += $coste;
                    $meses[$m]['cargos'][] = ['fecha' => $f, 'id' => $el['id'], 'nombre' => $el['nombre'], 'importe' => $coste, 'aprox' => $c['aprox']];
                    if ($it['proximo'] === null && $f >= $hoy) $it['proximo'] = $f;
                }
                $it['aprox'] = $c['aprox'];
            }
        }
        // Con 3 facturas o más en 12 meses, lo que sale de verdad al mes.
        if ($el['tipo'] === 'suministro' && $h && $h['n'] >= 3) {
            $it['real'] = ['mensual' => $h['total'] / ($h['n'] * $n), 'n' => $h['n'], 'max' => $h['max'], 'min' => $h['min']];
        }
        $items[] = $it;
    }

    $total = array_sum(array_column($items, 'mensual'));
    $pct = static fn(float $x): float => $total > 0 ? $x / $total * 100 : 0.0;

    $partidas = [];
    foreach (partidas_gasto() as $k => $p) {
        $suyos = array_values(array_filter($items, static fn($i) => $i['partida'] === $k));
        if (!$suyos) continue;
        usort($suyos, static fn($a, $b) => $b['mensual'] <=> $a['mensual']);
        $m = array_sum(array_column($suyos, 'mensual'));
        $partidas[$k] = $p + ['mensual' => $m, 'pct' => $pct($m), 'items' => $suyos];
    }

    // Por cosa: la casa o el vehículo al que pertenece; si no pertenece a
    // nada, la persona (la natación de Leo); si tampoco, «Sin casa ni vehículo».
    $cosas = [];
    foreach ($items as $it) {
        if ($it['enlace_id']) [$k, $nombre, $id] = ['e' . $it['enlace_id'], (string)$it['enlace_nombre'], (int)$it['enlace_id']];
        elseif ($it['persona_nombre']) [$k, $nombre, $id] = ['p' . $it['persona_nombre'], (string)$it['persona_nombre'], null];
        else [$k, $nombre, $id] = ['g', 'Sin casa ni vehículo', null];
        $c = &$cosas[$k];
        $c ??= ['id' => $id, 'nombre' => $nombre, 'mensual' => 0.0, 'pct' => 0.0, 'items' => [],
                'otros_12m' => $id ? round((float)($historial[$id]['otros'] ?? 0), 2) : 0.0];
        $c['mensual'] += $it['mensual'];
        $c['items'][] = $it['nombre'];
        unset($c);
    }
    foreach ($cosas as &$c) $c['pct'] = $pct($c['mensual']);
    unset($c);
    uasort($cosas, static fn($a, $b) => $b['mensual'] <=> $a['mensual']);

    foreach ($meses as &$mm) {
        $mm['total'] = $mm['base'] + $mm['extra'];
        usort($mm['cargos'], static fn($a, $b) => $a['fecha'] <=> $b['fecha']);
    }
    unset($mm);
    $calendario = array_values($meses);
    $max = $calendario ? max(array_column($calendario, 'total')) : 0.0;
    $pico = null;
    foreach ($calendario as $i => $mm) if ($mm['extra'] > 0 && $mm['total'] === $max) { $pico = $i; break; }

    $provision = array_sum(array_map(static fn($i) => $i['meses'] > 1 ? $i['mensual'] : 0.0, $items));

    $an = [
        'total' => round($total, 2), 'anual' => round($total * 12, 2),
        'items' => $items, 'partidas' => $partidas, 'cosas' => array_values($cosas),
        'calendario' => $calendario, 'max_mes' => $max, 'pico' => $pico,
        'provision' => round($provision, 2), 'sin_importe' => $sin_importe, 'sin_fecha' => $sin_fecha,
    ];
    $an['revisar'] = revisar_gastos_fijos($an, $hoy);
    return $an;
}

/**
 * Lo que miraría un asesor, de más urgente a más tranquilo: [['nivel' =>
 * aviso|idea|dato|bien, 'titulo', 'texto', 'enlaces' => [[texto, ruta]]]].
 */
function revisar_gastos_fijos(array $an, string $hoy): array {
    $out = [];
    $ficha = static fn(array $i): array => [$i['nombre'], 'elemento.php?id=' . $i['id']];

    // Importes que faltan: el total se queda corto sin ellos.
    if ($an['sin_importe']) {
        $nombres = array_column($an['sin_importe'], 'nombre');
        $uno = count($nombres) === 1;
        $out[] = ['nivel' => 'aviso',
            'titulo' => $uno ? 'Falta el importe de «' . $nombres[0] . '»' : 'Faltan los importes de ' . count($nombres) . ' contratos',
            'texto' => ($uno ? 'No tiene' : 'No tienen') . ' coste o periodicidad, así que no ' . ($uno ? 'cuenta' : 'cuentan')
                . ' y el total se queda corto. Apúntalo en ' . ($uno ? 'su ficha' : 'cada ficha') . ' o pásale a Claude una factura.',
            'enlaces' => array_map($ficha, $an['sin_importe'])];
    }

    foreach ($an['items'] as $i) {
        $d = $i['datos'];
        // Seguros que renuevan en los próximos 90 días: el plazo para no renovar
        // es de un mes antes del vencimiento (art. 22 de la Ley de Contrato de Seguro).
        if ($i['tipo'] === 'seguro' && $i['proximo'] && !$i['aprox'] && dias_entre($hoy, $i['proximo']) <= 90) {
            $plazo = sumar_meses($i['proximo'], -1);
            $quedan = dias_entre($hoy, $plazo);
            $out[] = $quedan >= 0
                ? ['nivel' => $quedan <= 30 ? 'aviso' : 'idea', 'titulo' => 'Se renueva «' . $i['nombre'] . '»',
                   'texto' => 'El ' . fecha_es($i['proximo']) . ', por ' . eur($i['coste']) . '. Si quieres cambiar, pide precio a otras dos o tres compañías: '
                       . 'para no renovar hay que avisar por escrito antes del ' . fecha_es($plazo) . ' (un mes antes, art. 22 de la Ley de Contrato de Seguro).',
                   'enlaces' => [$ficha($i)]]
                : ['nivel' => 'idea', 'titulo' => 'Se renueva «' . $i['nombre'] . '»',
                   'texto' => 'El ' . fecha_es($i['proximo']) . ', por ' . eur($i['coste']) . '. Ya no da tiempo a avisar para no renovar (hacía falta un mes antes): '
                       . 'cuando llegue el recibo, si sube, apunta el precio nuevo y compáralo con calma antes de la próxima renovación.',
                   'enlaces' => [$ficha($i)]];
        }
        // Una suscripción anual que se renueva pronto: ¿se usa?
        if ($i['tipo'] === 'suscripcion' && $i['meses'] >= 12 && $i['proximo'] && dias_entre($hoy, $i['proximo']) <= 30) {
            $out[] = ['nivel' => 'idea', 'titulo' => 'Se renueva «' . $i['nombre'] . '»',
                'texto' => 'El ' . fecha_es($i['proximo']) . ', por ' . eur($i['coste']) . '. ¿La sigues usando? Si no, date de baja antes.',
                'enlaces' => [$ficha($i)]];
        }
        // Sin permanencia (o a punto): se puede cambiar o negociar sin penalización.
        $perm = (string)($d['permanencia_hasta'] ?? '');
        if (fecha_valida($perm) && dias_entre($hoy, $perm) <= 60 && dias_entre($perm, $hoy) <= 365) {
            $out[] = ['nivel' => 'idea', 'titulo' => '«' . $i['nombre'] . '» ' . ($perm <= $hoy ? 'ya no tiene permanencia' : 'acaba la permanencia'),
                'texto' => ($perm <= $hoy ? 'Desde el ' : 'El ') . fecha_es($perm) . ' puedes cambiar de compañía sin penalización: '
                    . 'compara tarifas o pide a la tuya que te iguale la oferta.',
                'enlaces' => [$ficha($i)]];
        }
        // La ficha no cuadra con lo que dicen las facturas.
        if ($i['real'] && abs($i['real']['mensual'] - $i['mensual']) >= 3 && abs($i['real']['mensual'] - $i['mensual']) / $i['mensual'] >= 0.10) {
            $out[] = ['nivel' => 'idea', 'titulo' => '«' . $i['nombre'] . '» no cuadra con sus facturas',
                'texto' => 'La ficha cuenta ' . eur($i['mensual']) . ' al mes y las ' . $i['real']['n'] . ' facturas de los últimos 12 meses salen a '
                    . eur($i['real']['mensual']) . '. Pon ese coste en la ficha para que el total sea real.',
                'enlaces' => [$ficha($i)]];
        }
    }

    // Luz y gas cambian con la estación: con menos de un año de facturas, su media es orientativa.
    $estacionales = array_values(array_filter($an['items'], static fn($i) => $i['real'] && $i['real']['n'] < 11
        && $i['real']['min'] > 0 && $i['real']['max'] / $i['real']['min'] >= 2));
    if ($estacionales) {
        $trozos = array_map(static fn($i) => '«' . $i['nombre'] . '», de ' . eur($i['real']['min']) . ' a ' . eur($i['real']['max']), $estacionales);
        $out[] = ['nivel' => 'dato', 'titulo' => 'Hay suministros que cambian mucho con la estación',
            'texto' => 'Por factura, en los últimos 12 meses: ' . implode('; ', $trozos) . '. Con menos de un año de facturas, '
                . 'su importe aquí es una media orientativa: revísalo cuando tengas el año entero.',
            'enlaces' => array_map($ficha, $estacionales)];
    }

    // La hipoteca: su peso, lo que queda y qué hacer cuando se revisa.
    foreach ($an['partidas']['hipoteca']['items'] ?? [] as $i) {
        $d = $i['datos'];
        $partes = [];
        $finh = (string)($d['fecha_fin'] ?? '');
        if (fecha_valida($finh) && $finh > $hoy) {
            $partes[] = 'Quedan ' . tiempo_hasta($hoy, $finh) . ' (la última cuota, en ' . MESES[(int)substr($finh, 5, 2) - 1] . ' de ' . substr($finh, 0, 4) . ').';
        }
        $pago = $d['porcentaje_pago'] ?? null;
        if (is_numeric($pago) && $pago > 0 && $pago < 100) {
            $partes[] = 'Aquí cuenta la cuota entera; tu parte (' . numero_es($pago) . ' %) son ' . eur($i['mensual'] * $pago / 100) . ' al mes.';
        }
        $rev = ['Mensual' => 'cada mes', 'Trimestral' => 'cada trimestre', 'Semestral' => 'cada seis meses', 'Anual' => 'cada año'][$d['revision_interes'] ?? ''] ?? null;
        if ($rev) $partes[] = 'El interés se revisa ' . $rev . ': cuando cambie la cuota, cámbiala en su ficha y todo se recalcula.';
        $partes[] = 'Si un día amortizas, reducir plazo ahorra más intereses que reducir cuota.';
        $out[] = ['nivel' => 'dato', 'titulo' => 'La hipoteca es el ' . pct_es($i['mensual'] / max($an['total'], 0.01) * 100) . ' del gasto fijo',
            'texto' => implode(' ', $partes), 'enlaces' => [$ficha($i)]];
    }

    return ordenar_revisar($out);
}

// De más urgente a más tranquilo (usort es estable: dentro de cada nivel, el orden de llegada).
function ordenar_revisar(array $lista): array {
    $orden = ['aviso' => 0, 'idea' => 1, 'bien' => 2, 'dato' => 3];
    usort($lista, static fn($a, $b) => $orden[$a['nivel']] <=> $orden[$b['nivel']]);
    return $lista;
}

// «162,42 € al año», «367,85 € cada trimestre».
function coste_y_periodo(float $coste, string $periodicidad): string {
    return eur($coste) . ' ' . (['Mensual' => 'al mes', 'Bimestral' => 'cada dos meses', 'Trimestral' => 'cada trimestre',
        'Semestral' => 'cada seis meses', 'Anual' => 'al año'][$periodicidad] ?? '');
}

/**
 * Lo que dice finanzas (acción «resumen» de su API) puesto al lado de los
 * gastos fijos. OJO: finanzas lleva las cuentas de Gonzalo, no las de toda
 * la casa (allí la hipoteca sale por su 60 %), así que la hipoteca se compara
 * por la parte que paga él (porcentaje_pago) y no se calcula «gasto fijo de
 * la casa / sus ingresos», que mezclaría dos cosas distintas.
 * Devuelve null si finanzas no tiene al menos 6 meses completos.
 */
function salud_finanzas(array $resumen, array $an, string $hoy): ?array {
    $actual = substr($hoy, 0, 7);
    $meses = array_values(array_filter($resumen['gasto']['meses'] ?? [], static fn($m) => (string)($m['mes'] ?? '') < $actual
        && ((float)($m['gasto'] ?? 0) > 0 || (float)($m['ingreso'] ?? 0) > 0)));
    $meses = array_slice($meses, -12);
    $n = count($meses);
    if ($n < 6) return null;
    $ingreso = array_sum(array_map(static fn($m) => (float)$m['ingreso'], $meses)) / $n;
    $gasto = array_sum(array_map(static fn($m) => (float)$m['gasto'], $meses)) / $n;
    if ($ingreso <= 0 || $gasto <= 0) return null;

    $saldo = 0.0;
    foreach ($resumen['saldos'] ?? [] as $s) if (!empty($s['activa'])) $saldo += (float)$s['saldo'];

    $hipoteca = 0.0;
    $tuya = false;
    foreach ($an['partidas']['hipoteca']['items'] ?? [] as $i) {
        $p = $i['datos']['porcentaje_pago'] ?? null;
        if (is_numeric($p) && $p > 0 && $p <= 100) { $hipoteca += $i['mensual'] * $p / 100; $tuya = true; }
        else $hipoteca += $i['mensual'];
    }

    $ahorro = ($ingreso - $gasto) / $ingreso * 100;
    $colchon = $saldo / $gasto;
    $r = [
        'meses' => $n, 'ingreso' => round($ingreso, 2), 'gasto' => round($gasto, 2), 'ahorro_pct' => $ahorro,
        'saldo' => round($saldo, 2), 'colchon_meses' => $colchon,
        'hipoteca' => round($hipoteca, 2), 'hipoteca_tuya' => $tuya, 'hipoteca_pct' => $hipoteca > 0 ? $hipoteca / $ingreso * 100 : null,
        'repetidos' => gastos_repetidos_sin_recoger($resumen['gasto']['categorias'] ?? [], $an),
        'cifras' => [], 'revisar' => [],
    ];
    if ($r['repetidos']) {
        $trozos = array_map(static fn($x) => '«' . $x['categoria'] . '» (' . eur($x['media']) . ' al mes de media)', $r['repetidos']);
        $r['revisar'][] = ['nivel' => 'idea', 'titulo' => 'En el banco se repiten gastos que aquí no están',
            'texto' => 'Según finanzas: ' . lista_y($trozos) . '. Si son fijos, dalos de alta en Contratos y el total de aquí será el real.',
            'enlaces' => [['Contratos', 'seccion.php?s=contratos']]];
    }

    // Las cifras, cada una con su referencia y su veredicto.
    $r['cifras'][] = $ahorro < 0
        ? ['nivel' => 'aviso', 'valor' => pct_es($ahorro), 'titulo' => 'de lo que ingresas, ahorras',
           'texto' => 'En los últimos ' . $n . ' meses salió más de lo que entró: ' . eur($gasto) . ' al mes frente a ' . eur($ingreso) . '.']
        : ['nivel' => $ahorro >= 20 ? 'bien' : ($ahorro >= 10 ? 'idea' : 'aviso'), 'valor' => pct_es($ahorro), 'titulo' => 'de lo que ingresas, ahorras',
           'texto' => 'Entran ' . eur($ingreso) . ' al mes y salen ' . eur($gasto) . ' (media de ' . $n . ' meses). La referencia es apartar el 20 % (regla 50/30/20)'
               . ($ahorro >= 20 ? ': lo cumples.' : '; para llegar, ' . eur($ingreso * 0.2 - ($ingreso - $gasto)) . ' más al mes.')];
    $r['cifras'][] = ['nivel' => $colchon >= 3 ? ($colchon > 12 ? 'idea' : 'bien') : ($colchon >= 1.5 ? 'idea' : 'aviso'),
        'valor' => numero_es(round($colchon, 1)) . ' meses', 'titulo' => 'de colchón',
        'texto' => 'Tus cuentas suman ' . eur($saldo) . ' y gastas ' . eur($gasto) . ' al mes. Lo prudente es tener de 3 a 6 meses a mano ('
            . eur($gasto * 3) . ' a ' . eur($gasto * 6) . ')'
            . ($colchon > 12 ? '; lo que pase de un año parado pierde contra la inflación.' : ($colchon >= 3 ? ': lo tienes.' : '.'))];
    if ($hipoteca > 0) {
        $hp = $hipoteca / $ingreso * 100;
        $r['cifras'][] = ['nivel' => $hp <= 30 ? 'bien' : ($hp <= 35 ? 'idea' : 'aviso'), 'valor' => pct_es($hp),
            'titulo' => $tuya ? 'de tus ingresos va a tu parte de la hipoteca' : 'de tus ingresos va a la hipoteca',
            'texto' => ($tuya ? 'Tu parte' : 'La cuota') . ' son ' . eur($hipoteca) . ' al mes. Los bancos ponen el límite en el 30-35 % de los ingresos netos'
                . ($hp <= 30 ? ': vas holgado.' : ($hp <= 35 ? ': estás en el límite.' : ': lo pasas.'))];
    }
    return $r;
}

/**
 * Categorías de finanzas que parecen gasto fijo y que aquí no cuentan:
 * estables (el último mes, a ±10 % de su media) o fijas por naturaleza
 * (garaje, alquiler, gimnasio…), de 15 € al mes o más, y sin una partida de
 * aquí que las recoja (la «Hipoteca» de finanzas ya está en la ficha).
 */
function gastos_repetidos_sin_recoger(array $categorias, array $an): array {
    $recoge = ['hipoteca' => ['hipoteca', 'prestamo'], 'suministro' => ['luz', 'gas', 'agua', 'internet', 'fibra', 'telef', 'movil', 'electric', 'suministro'],
               'seguro' => ['seguro'], 'comunidad' => ['comunidad'], 'suscripcion' => ['suscrip'], 'otros' => ['extraescolar', 'actividad']];
    $fijos = ['garaje', 'parking', 'aparcamiento', 'alquiler', 'gimnas', 'colegio', 'guarder'];
    $out = [];
    foreach ($categorias as $c) {
        $nombre = (string)($c['categoria'] ?? '');
        $t = texto_comparable($nombre);
        $media = (float)($c['media'] ?? 0);
        $mes = (float)($c['mes'] ?? 0);
        if ($media < 15 || in_array($t, ['sin categorizar', 'varios', 'otros'], true)) continue;
        $partida = null;
        foreach ($recoge as $p => $claves) foreach ($claves as $k) if (str_contains($t, $k)) $partida = $p;
        $por_naturaleza = $partida !== null || (bool)array_filter($fijos, static fn($k) => str_contains($t, $k));
        $estable = $mes > 0 && abs($mes - $media) / $media <= 0.10;
        if (!$estable && !$por_naturaleza) continue;
        if ($partida !== null && isset($an['partidas'][$partida])) continue;
        $out[] = ['categoria' => $nombre, 'media' => round($media, 2)];
    }
    return $out;
}
