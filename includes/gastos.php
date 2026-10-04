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
//  Lo que se paga a medias (la hipoteca al 60 %, los suministros al 50 %…)
//  se ve por la parte de quien mira (parte_que_pagas): la cifra grande es
//  lo suyo y el total de la casa va en pequeño (Gonzalo, 4/10/2026).
//
//  Funciones puras (los datos entran, el análisis sale) para probarlas sin
//  base de datos. Las lecturas son elementos_con_coste() (elementos.php) e
//  historial_de_gastos() (registros.php).
// =====================================================================

// Las partidas, en orden FIJO: el de la barra apilada y el de sus colores
// (--serie-N en assets/app.css, validados para que dos vecinas se distingan
// también con daltonismo; si se añade una, revalidar). El color sigue a la
// partida, no a su tamaño. Lo que no encaja va a «otros», en gris.
function partidas_gasto(): array {
    return [
        'hipoteca'    => ['nombre' => 'Hipoteca', 'serie' => 1],
        'alquiler'    => ['nombre' => 'Alquileres', 'serie' => 2],
        'suministro'  => ['nombre' => 'Suministros', 'serie' => 3],
        'seguro'      => ['nombre' => 'Seguros', 'serie' => 4],
        'comunidad'   => ['nombre' => 'Comunidad', 'serie' => 5],
        'suscripcion' => ['nombre' => 'Suscripciones', 'serie' => 6],
        'otros'       => ['nombre' => 'Actividades y otros', 'serie' => 0],
    ];
}

function partida_de(array $el): string {
    return $el['seccion'] === 'contratos' && $el['tipo'] !== 'otros' && isset(partidas_gasto()[$el['tipo']]) ? $el['tipo'] : 'otros';
}

/**
 * El % de un gasto que paga quien mira (la persona de su usuario):
 *  - sin persona (o sin decir quién mira): 100, el gasto entero;
 *  - en Contratos, la persona es el titular y «porcentaje_pago» es SU parte:
 *    si el titular es quien mira, ese %; si es otro, el resto (lo pagáis a
 *    medias) o nada (si el otro lo paga entero); sin titular, ese %;
 *  - fuera de Contratos (las actividades: la persona es quien va), el % es
 *    el de quien mira.
 * Vacío = 100. Ej.: hipoteca de Gonzalo al 60 % → 60 para él, 40 para Pilar.
 */
function parte_que_pagas(array $el, ?int $persona): float {
    $p = $el['datos']['porcentaje_pago'] ?? null;
    $pct = is_numeric($p) && $p >= 0 && $p <= 100 ? (float)$p : 100.0;
    if ($persona === null) return 100.0;
    $titular = $el['persona_id'] ?? null;
    if ($el['seccion'] !== 'contratos' || $titular === null || (int)$titular === $persona) return $pct;
    return $pct < 100 ? 100 - $pct : 0.0;
}

/**
 * Cada cuántos meses llegan las facturas (1, 2, 3, 6 o 12), por el hueco MÁS
 * CORTO entre dos seguidas: una que falte por apuntar alarga los huecos, no
 * los acorta. null con menos de dos fechas. (El agua: 3 recibos de unos 116 €
 * cada 3 meses son 38,74 € al mes, no 116.)
 */
function intervalo_facturas(array $fechas): ?int {
    sort($fechas);
    $min = null;
    for ($i = 1; $i < count($fechas); $i++) {
        $d = dias_entre($fechas[$i - 1], $fechas[$i]);
        if ($d > 0) $min = $min === null ? $d : min($min, $d);
    }
    if ($min === null) return null;
    $meses = $min / 30.44;
    $mejor = 1;
    foreach ([1, 2, 3, 6, 12] as $m) if (abs(log($meses / $m)) < abs(log($meses / $mejor))) $mejor = $m;
    return $mejor;
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
 * historial_de_gastos(); $persona = la de quien mira (null = la casa entera).
 * Cada importe va dos veces: el de la casa ('mensual', 'total') y el de
 * quien mira ('tuyo'). Las partidas, las cosas, el calendario y lo que hay
 * que apartar van por lo tuyo; lo que pagan otros entero, aparte.
 */
function analisis_gastos_fijos(array $elementos, array $historial, string $hoy, ?int $persona = null): array {
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
        $parte = parte_que_pagas($el, $persona);
        $it = [
            'id' => $el['id'], 'nombre' => $el['nombre'], 'seccion' => $el['seccion'], 'tipo' => $el['tipo'],
            'tipo_nombre' => tipo_def($el['seccion'], $el['tipo'])['nombre'] ?? $el['tipo'],
            'partida' => partida_de($el), 'coste' => $coste, 'periodicidad' => (string)$d['periodicidad'], 'meses' => $n,
            'mensual' => $coste / $n, 'parte' => $parte, 'tuyo' => $coste / $n * $parte / 100, 'coste_tuyo' => $coste * $parte / 100,
            'enlace_id' => $el['enlace_id'] ?? null, 'enlace_nombre' => $el['enlace_nombre'] ?? null,
            'persona_id' => $el['persona_id'] ?? null, 'persona_nombre' => $el['persona_nombre'] ?? null, 'datos' => $d,
            'proximo' => null, 'aprox' => false, 'real' => null,
        ];

        if ($n === 1) {
            // Lo mensual, todos los meses (la hipoteca, hasta su última cuota).
            $hasta = (string)($d['fecha_fin'] ?? '');
            foreach ($meses as $m => &$mm) {
                if (fecha_valida($hasta) && $m . '-01' > $hasta) continue;
                $mm['base'] += $it['coste_tuyo'];
            }
            unset($mm);
        } else {
            $c = fechas_de_cargo($d, $n, $h['ultimo'] ?? null, $inicio, $fin);
            if ($c === null) {
                $sin_fecha[] = $el['nombre'];
                foreach ($meses as &$mm) $mm['extra'] += $it['tuyo'];
                unset($mm);
            } else {
                foreach ($c['fechas'] as $f) {
                    $m = substr($f, 0, 7);
                    if ($it['coste_tuyo'] > 0) {
                        $meses[$m]['extra'] += $it['coste_tuyo'];
                        $meses[$m]['cargos'][] = ['fecha' => $f, 'id' => $el['id'], 'nombre' => $el['nombre'],
                            'importe' => $it['coste_tuyo'], 'total' => $coste, 'aprox' => $c['aprox']];
                    }
                    if ($it['proximo'] === null && $f >= $hoy) $it['proximo'] = $f;
                }
                $it['aprox'] = $c['aprox'];
            }
        }
        // Con 3 facturas o más en 12 meses, lo que sale de verdad al mes, al
        // ritmo al que llegan (que puede no ser el de la ficha).
        if ($el['tipo'] === 'suministro' && $h && $h['n'] >= 3) {
            $cada = intervalo_facturas($h['fechas'] ?? []) ?? $n;
            $it['real'] = ['mensual' => $h['total'] / ($h['n'] * $cada), 'n' => $h['n'], 'cada' => $cada,
                           'media' => $h['total'] / $h['n'], 'max' => $h['max'], 'min' => $h['min']];
        }
        $items[] = $it;
    }

    $total = array_sum(array_column($items, 'mensual'));
    $tuyo = array_sum(array_column($items, 'tuyo'));
    $pct = static fn(float $x): float => $tuyo > 0 ? $x / $tuyo * 100 : 0.0;
    $mios = array_values(array_filter($items, static fn($i) => $i['tuyo'] > 0));

    $partidas = [];
    foreach (partidas_gasto() as $k => $p) {
        $suyos = array_values(array_filter($mios, static fn($i) => $i['partida'] === $k));
        if (!$suyos) continue;
        usort($suyos, static fn($a, $b) => $b['tuyo'] <=> $a['tuyo']);
        $t = array_sum(array_column($suyos, 'tuyo'));
        $partidas[$k] = $p + ['mensual' => array_sum(array_column($suyos, 'mensual')), 'tuyo' => $t, 'pct' => $pct($t), 'items' => $suyos];
    }

    // Por cosa: la casa o el vehículo al que pertenece; una actividad, por
    // quien va (la natación de Leo); un contrato suelto (la plaza de garaje),
    // él mismo.
    $cosas = [];
    foreach ($mios as $it) {
        if ($it['enlace_id']) [$k, $nombre, $id] = ['e' . $it['enlace_id'], (string)$it['enlace_nombre'], (int)$it['enlace_id']];
        elseif ($it['seccion'] !== 'contratos' && $it['persona_nombre']) [$k, $nombre, $id] = ['p' . $it['persona_nombre'], (string)$it['persona_nombre'], null];
        else [$k, $nombre, $id] = ['e' . $it['id'], $it['nombre'], $it['id']];
        $c = &$cosas[$k];
        $c ??= ['id' => $id, 'nombre' => $nombre, 'mensual' => 0.0, 'tuyo' => 0.0, 'pct' => 0.0, 'items' => [],
                'otros_12m' => $id ? round((float)($historial[$id]['otros'] ?? 0), 2) : 0.0];
        $c['mensual'] += $it['mensual'];
        $c['tuyo'] += $it['tuyo'];
        $c['items'][] = $it['nombre'];
        unset($c);
    }
    foreach ($cosas as &$c) $c['pct'] = $pct($c['tuyo']);
    unset($c);
    uasort($cosas, static fn($a, $b) => $b['tuyo'] <=> $a['tuyo']);

    foreach ($meses as &$mm) {
        $mm['total'] = $mm['base'] + $mm['extra'];
        usort($mm['cargos'], static fn($a, $b) => $a['fecha'] <=> $b['fecha']);
    }
    unset($mm);
    $calendario = array_values($meses);
    $max = $calendario ? max(array_column($calendario, 'total')) : 0.0;
    $pico = null;
    foreach ($calendario as $i => $mm) if ($mm['extra'] > 0 && $mm['total'] === $max) { $pico = $i; break; }

    $provision = array_sum(array_map(static fn($i) => $i['meses'] > 1 ? $i['tuyo'] : 0.0, $items));

    $an = [
        'total' => round($total, 2), 'anual' => round($total * 12, 2),
        'tuyo' => round($tuyo, 2), 'anual_tuyo' => round($tuyo * 12, 2), 'a_medias' => abs($total - $tuyo) >= 0.005,
        'items' => $items, 'partidas' => $partidas, 'cosas' => array_values($cosas),
        'de_otros' => array_values(array_filter($items, static fn($i) => $i['tuyo'] <= 0)),
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
    $nombre_periodo = [1 => 'Mensual', 2 => 'Bimestral', 3 => 'Trimestral', 6 => 'Semestral', 12 => 'Anual'];

    // Importes que faltan: el total se queda corto sin ellos.
    if ($an['sin_importe']) {
        $nombres = array_column($an['sin_importe'], 'nombre');
        $uno = count($nombres) === 1;
        $out[] = ['nivel' => 'aviso',
            'titulo' => $uno ? 'Falta el importe de «' . $nombres[0] . '»' : 'Faltan los importes de ' . count($nombres) . ' gastos',
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
        if ($i['real']) {
            $r = $i['real'];
            if ($r['cada'] !== $i['meses']) {
                // Las facturas llegan a otro ritmo que el de la ficha: el importe al mes puede
                // estar bien, pero el calendario no pone cada cargo en su mes.
                $out[] = ['nivel' => 'idea', 'titulo' => '«' . $i['nombre'] . '» se cobra ' . ($r['cada'] === 1 ? 'cada mes' : 'cada ' . $r['cada'] . ' meses'),
                    'texto' => 'Sus facturas llegan ' . ($r['cada'] === 1 ? 'cada mes' : 'cada ' . $r['cada'] . ' meses') . ' (unos ' . eur($r['media'])
                        . ' cada una) y la ficha dice «' . $i['periodicidad'] . '». Si pones ' . eur($r['media']) . ' y «' . ($nombre_periodo[$r['cada']] ?? '')
                        . '», «Mes a mes» pondrá cada cargo en su mes.',
                    'enlaces' => [$ficha($i)]];
            }
            // La ficha no cuadra con lo que dicen las facturas.
            $dif = abs($r['mensual'] - $i['mensual']);
            if ($dif >= 3 && $dif / $i['mensual'] >= 0.10) {
                $out[] = ['nivel' => 'idea', 'titulo' => '«' . $i['nombre'] . '» no cuadra con sus facturas',
                    'texto' => 'La ficha cuenta ' . eur($i['mensual']) . ' al mes y las ' . $r['n'] . ' facturas de los últimos 12 meses salen a '
                        . eur($r['mensual']) . '. Pon ese coste en la ficha para que el total sea real.',
                    'enlaces' => [$ficha($i)]];
            }
        }
    }

    // Luz y gas cambian con la estación: con menos de un año de facturas, su media es orientativa.
    $estacionales = array_values(array_filter($an['items'], static fn($i) => $i['real'] && $i['real']['cada'] === 1 && $i['real']['n'] < 11
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
        if ($i['parte'] < 100) {
            $partes[] = 'Pagas el ' . numero_es($i['parte']) . ' %: ' . eur($i['tuyo']) . ' de los ' . eur($i['mensual']) . ' de la cuota.';
        }
        $finh = (string)($d['fecha_fin'] ?? '');
        if (fecha_valida($finh) && $finh > $hoy) {
            $partes[] = 'Quedan ' . tiempo_hasta($hoy, $finh) . ' (la última cuota, en ' . MESES[(int)substr($finh, 5, 2) - 1] . ' de ' . substr($finh, 0, 4) . ').';
        }
        $rev = ['Mensual' => 'cada mes', 'Trimestral' => 'cada trimestre', 'Semestral' => 'cada seis meses', 'Anual' => 'cada año'][$d['revision_interes'] ?? ''] ?? null;
        if ($rev) $partes[] = 'El interés se revisa ' . $rev . ': cuando cambie la cuota, cámbiala en su ficha y todo se recalcula.';
        $partes[] = 'Si un día amortizas, reducir plazo ahorra más intereses que reducir cuota.';
        $out[] = ['nivel' => 'dato', 'titulo' => 'La hipoteca es el ' . pct_es($i['tuyo'] / max($an['tuyo'], 0.01) * 100) . ' de tu gasto fijo',
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
 * gastos fijos. Finanzas lleva las cuentas de Gonzalo, no las de toda la casa
 * (allí la hipoteca sale por su 60 %), así que todo se compara con SU parte
 * (el análisis hecho con su persona): la cifra «tuyo», no el total de la casa.
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

    $hipotecas = $an['partidas']['hipoteca']['items'] ?? [];
    $hipoteca = array_sum(array_column($hipotecas, 'tuyo'));
    $tuya = (bool)array_filter($hipotecas, static fn($i) => $i['parte'] < 100);

    $ahorro = ($ingreso - $gasto) / $ingreso * 100;
    $colchon = $saldo / $gasto;
    $fijos = $an['tuyo'] / $ingreso * 100;
    $r = [
        'meses' => $n, 'ingreso' => round($ingreso, 2), 'gasto' => round($gasto, 2), 'ahorro_pct' => $ahorro,
        'saldo' => round($saldo, 2), 'colchon_meses' => $colchon, 'fijos_pct' => $fijos,
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
    $r['cifras'][] = ['nivel' => $fijos <= 35 ? 'bien' : ($fijos <= 50 ? 'idea' : 'aviso'), 'valor' => pct_es($fijos),
        'titulo' => 'de tus ingresos se va en gastos fijos',
        'texto' => 'Tu parte son ' . eur($an['tuyo']) . ' al mes y entran ' . eur($ingreso) . '. La regla 50/30/20 deja el 50 % para lo necesario '
            . '(esto, más la comida, el transporte…)' . ($fijos < 50 ? ': de ese 50 %, te quedan ' . eur($ingreso * 0.5 - $an['tuyo']) . ' para lo demás.' : ' y ya lo pasas solo con esto.')];
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
    $recoge = ['hipoteca' => ['hipoteca', 'prestamo'], 'alquiler' => ['alquiler', 'garaje', 'parking', 'aparcamiento', 'trastero'],
               'suministro' => ['luz', 'gas', 'agua', 'internet', 'fibra', 'telef', 'movil', 'electric', 'suministro'],
               'seguro' => ['seguro'], 'comunidad' => ['comunidad'], 'suscripcion' => ['suscrip'], 'otros' => ['extraescolar', 'actividad']];
    $fijos = ['gimnas', 'colegio', 'guarder'];
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
