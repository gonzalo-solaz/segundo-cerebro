<?php
// =====================================================================
//  La hipoteca que se calcula sola (Gonzalo, 9/10/2026: «que segundo
//  cerebro sea dinámico: al aparecer en finanzas un recibo nuevo, que se
//  actualice la tabla de amortización con el dato real; lo que viene es una
//  estimación. Que no haya que hacer tareas manuales»).
//
//  El cuadro de amortización no se guarda: se calcula cada vez con
//    · los términos de la ficha: capital, cuotas, primera cuota, tipo y
//      meses del periodo inicial, diferencial y cada cuánto se revisa;
//    · los tipos conocidos: los precios de la ficha con su `tipo`
//      (migración 009). Hacia atrás, el cuadro del banco; en cada revisión,
//      el que calcula la app y guarda sola en cuanto el banco revisa;
//    · el Euríbor (tabla euribor) para las revisiones sin tipo guardado:
//      con la media cerrada del mes que toca es «calculado»; con la
//      provisional o la última que haya, «estimado»;
//    · las amortizaciones anticipadas, que son apuntes del historial de la
//      ficha (tipo «Amortización anticipada», el importe en coste; «cuota» en
//      el título o las notas = reducir cuota, si no, plazo);
//    · los cargos reales del banco, que da finanzas (cuenta de la casa,
//      categoría Hipoteca): cada cuota que ya ha vencido se casa con el suyo.
//  Con los tipos del banco reproduce su cuadro al céntimo (87.556,90 € tras
//  la cuota 107, 9/10/2026): intereses = pendiente × tipo / 1200 redondeado
//  (meses de 30 días) y la cuota, la de un préstamo francés con lo que queda.
//
//  hipotecas_al_dia() la mantiene sola (cron y cada visita, sin esperar):
//  guarda el tipo de cada revisión en cuanto el banco la hace (un mes antes de
//  la cuota), pone en la ficha la cuota y el tipo vigentes y avisa en la
//  agenda de la próxima subida o bajada. Finanzas lee el capital pendiente de
//  aquí (acción `fichas`, bloque `amortizacion`): ya no se apunta a mano.
// =====================================================================

// La revisión se hace un mes antes de la cuota a la que se aplica, con la media del Euríbor del
// mes anterior a la revisión: la cuota del 7/12 lleva el Euríbor de octubre (escritura de
// Mediolanum, cláusula tercera bis; es lo habitual en España).
const HIPOTECA_MESES_EURIBOR = 2;
const HIPOTECA_TIPO_AMORTIZACION = 'Amortización anticipada';
// Un cargo del banco se casa con la cuota de su fecha si cae a 12 días o menos (el 7 puede ser
// festivo y el extracto apunta el cargo uno o dos días después).
const HIPOTECA_DIAS_CARGO = 12;
// La agenda avisa de la próxima subida o bajada de la cuota con este margen.
const HIPOTECA_DIAS_AVISO = 75;
// Un cambio de cuota menor que esto no se cuenta (ni se avisa ni se marca en la gráfica).
const HIPOTECA_CAMBIO_MINIMO = 0.5;

/** Lo que hace falta para calcular el cuadro, o null si la ficha no lo tiene. */
function hipoteca_terminos(array $el): ?array {
    $d = $el['datos'] ?? [];
    $capital = is_numeric($d['capital_inicial'] ?? null) ? (float)$d['capital_inicial'] : 0.0;
    $n = (int)($d['cuotas_totales'] ?? 0);
    $primera = (string)($d['primera_cuota'] ?? '');
    if ($capital <= 0 || $n <= 0 || !fecha_valida($primera) || ($d['periodicidad'] ?? 'Mensual') !== 'Mensual') return null;
    $rev = ['Mensual' => 1, 'Trimestral' => 3, 'Semestral' => 6, 'Anual' => 12][$d['revision_interes'] ?? ''] ?? 0;
    $dif = is_numeric($d['diferencial'] ?? null) ? (float)$d['diferencial'] : null;
    $inicial = is_numeric($d['tipo_inicial'] ?? null) ? (float)$d['tipo_inicial'] : null;
    $variable = $rev > 0 && $dif !== null;
    if (!$variable && $inicial === null) return null;
    $meses = $variable ? max(0, (int)($d['meses_tipo_inicial'] ?? 0)) : $n;
    if ($variable && $meses > 0 && $inicial === null) return null;
    return ['capital' => $capital, 'n' => $n, 'primera' => $primera, 'revision' => $rev, 'diferencial' => $dif,
            'tipo_inicial' => $inicial, 'meses_inicial' => $meses, 'variable' => $variable];
}

function cuota_francesa(float $capital, float $tipo, int $n): float {
    if ($n <= 0) return round($capital, 2);
    if ($tipo <= 0) return round($capital / $n, 2);
    $i = $tipo / 1200;
    return round($capital * $i / (1 - (1 + $i) ** -$n), 2);
}

// Cuántas cuotas hacen falta para pagar $capital con esa cuota (al reducir plazo).
function cuotas_que_faltan(float $capital, float $tipo, float $cuota): int {
    if ($capital <= 0) return 0;
    if ($tipo <= 0) return (int)ceil($capital / $cuota);
    $i = $tipo / 1200;
    if ($cuota <= $capital * $i) return 9999;
    return (int)ceil(-log(1 - $capital * $i / $cuota) / log(1 + $i));
}

/** Los precios de la ficha que llevan tipo: [['desde', 'coste', 'tipo', 'nota'], …] de más viejo a más nuevo. */
function tipos_de_hipoteca(PDO $pdo, int $elemento_id): array {
    $st = $pdo->prepare('SELECT desde, coste, tipo, nota FROM precios WHERE elemento_id = ? AND tipo IS NOT NULL ORDER BY desde, id');
    $st->execute([$elemento_id]);
    return array_map(static fn($f) => ['desde' => $f['desde'], 'coste' => (float)$f['coste'], 'tipo' => round((float)$f['tipo'], 3),
        'nota' => (string)$f['nota']], $st->fetchAll());
}

/** Las amortizaciones anticipadas apuntadas en su historial: [['fecha', 'importe', 'modo' => plazo|cuota]]. */
function amortizaciones_de(PDO $pdo, int $elemento_id): array {
    $st = $pdo->prepare('SELECT fecha, titulo, notas, coste FROM registros WHERE elemento_id = ? AND tipo = ? AND coste > 0 ORDER BY fecha, id');
    $st->execute([$elemento_id, HIPOTECA_TIPO_AMORTIZACION]);
    return array_map(static fn($f) => ['fecha' => $f['fecha'], 'importe' => (float)$f['coste'],
        'modo' => str_contains(minusculas($f['titulo'] . ' ' . $f['notas']), 'cuota') ? 'cuota' : 'plazo'], $st->fetchAll());
}

/**
 * Los cargos de la hipoteca que ha cobrado el banco, de finanzas (cuenta de la casa, categoría
 * Hipoteca): [['fecha', 'importe' (positivo)]]. Vacío si finanzas no está conectada.
 */
function cargos_hipoteca(bool $forzar = false): array {
    if (!finanzas_configurada()) return [];
    $out = [];
    foreach ((array)(finanzas_leer('casa', $forzar)['datos']['movimientos'] ?? []) as $m) {
        if (($m['categoria'] ?? '') === 'Hipoteca' && (float)($m['importe'] ?? 0) < 0 && fecha_valida((string)($m['fecha'] ?? ''))) {
            $out[] = ['fecha' => (string)$m['fecha'], 'importe' => round(-(float)$m['importe'], 2)];
        }
    }
    usort($out, static fn($a, $b) => strcmp($a['fecha'], $b['fecha']));
    return $out;
}

/**
 * El cuadro de amortización completo. Pura: no lee la base.
 *  $tipos: tipos_de_hipoteca() · $euribor: euribor_serie() · $amortizaciones: amortizaciones_de()
 *  $cargos: cargos_hipoteca() · $hoy: 'AAAA-MM-DD'.
 * Devuelve null si la ficha no tiene los términos; si no:
 *  ['filas' => [[n, fecha, revision (fecha en que el banco fija el tipo, o null), tipo, origen
 *               (escritura|banco|calculado|estimado), euribor (['mes', 'valor', 'definitivo'] o null), cuota,
 *               intereses, capital, pendiente, amortizado (anticipado ese mes), vencida, cobro (['fecha', 'importe'] o null),
 *               estado (cobrada|pagada|sin_cargo|pendiente_extracto|prevista|estimada)]],
 *   'resumen' => […] (ver hipoteca_resumen()), 'cargos_sin_cuota' => [...] ].
 */
function cuadro_hipoteca(array $el, array $tipos, array $euribor, array $amortizaciones, array $cargos, string $hoy): ?array {
    $t = hipoteca_terminos($el);
    if (!$t) return null;
    $fechas = [];
    for ($k = 0; $k < $t['n'] + 1; $k++) $fechas[$k] = sumar_meses($t['primera'], $k);

    // Cada tipo guardado vale desde la primera cuota que cae en su «desde» o después.
    $conocidos = [];
    foreach ($tipos as $p) {
        for ($k = 0; $k < $t['n']; $k++) {
            if ($fechas[$k] >= $p['desde']) { $conocidos[$k] = $p; break; }
        }
    }
    // El último Euríbor que haya hasta un mes (para estimar lo que aún no se sabe).
    $ultimo_hasta = static function (string $mes) use ($euribor): ?array {
        $r = null;
        foreach ($euribor as $m => $f) if ($m <= $mes) $r = ['mes' => $m] + $f;
        return $r;
    };
    $ultimo_euribor = $euribor ? ['mes' => array_key_last($euribor)] + end($euribor) : null;

    $C = round($t['capital'], 2);
    $fin = $t['n'] - 1;
    $tipo = null;
    $cuota = null;
    $origen = null;
    $eur = null;
    $filas = [];
    $usadas = [];
    for ($k = 0; $k <= $fin && $C > 0.004; $k++) {
        $fecha = $fechas[$k];
        $anterior = $k ? $fechas[$k - 1] : '0000-00-00';
        $amortizado = 0.0;
        $recalcular = false;
        foreach ($amortizaciones as $ai => $a) {
            if (isset($usadas[$ai]) || $a['fecha'] > $fecha || $a['fecha'] <= $anterior) continue;
            $usadas[$ai] = true;
            $quita = min($C, $a['importe']);
            $C = round($C - $quita, 2);
            $amortizado += $quita;
            if ($C <= 0.004) break;
            if ($a['modo'] === 'cuota' || $tipo === null) $recalcular = true;
            else $fin = $k + cuotas_que_faltan($C, $tipo, $cuota) - 1;
        }
        if ($C <= 0.004) {
            $filas[] = hipoteca_fila($k, $fecha, null, $tipo, $origen, $eur, 0.0, 0.0, 0.0, 0.0, $amortizado);
            break;
        }

        $es_revision = $t['variable'] && $k >= $t['meses_inicial'] && ($k - $t['meses_inicial']) % $t['revision'] === 0;
        $revision = $es_revision ? sumar_meses($fecha, -1) : null;
        $nuevo = null;
        $cuota_dada = null;
        if (isset($conocidos[$k])) {
            $nuevo = $conocidos[$k]['tipo'];
            // La cuota del banco tal cual, salvo que una amortización la haya cambiado este mes.
            $cuota_dada = $amortizado > 0 ? null : $conocidos[$k]['coste'];
            $origen = str_contains($conocidos[$k]['nota'], 'calculad') ? 'calculado' : 'banco';
            $eur = null;
        } elseif ($k === 0 && $t['tipo_inicial'] !== null) {
            $nuevo = $t['tipo_inicial'];
            $origen = 'escritura';
            $eur = null;
        } elseif ($es_revision || ($k === 0 && $t['variable'])) {
            $mes = substr(sumar_meses($fecha, -HIPOTECA_MESES_EURIBOR), 0, 7);
            $e = isset($euribor[$mes]) ? ['mes' => $mes] + $euribor[$mes] : ($ultimo_hasta($mes) ?? $ultimo_euribor);
            if ($e) {
                $nuevo = max(0.0, round($e['valor'] + $t['diferencial'], 3));
                $origen = $e['mes'] === $mes && $e['definitivo'] ? 'calculado' : 'estimado';
                $eur = $e;
            } elseif ($tipo !== null) {
                $origen = 'estimado';
            }
        }
        if ($nuevo === null && $tipo === null) return null;   // sin tipo de partida no hay cuadro
        $restantes = $fin - $k + 1;
        if ($nuevo !== null) {
            // Si el tipo no cambia, la cuota tampoco (el banco la recalcula y sale la misma; así no hay derivas de céntimos).
            $igual = $tipo !== null && abs($nuevo - $tipo) < 0.0005 && $cuota_dada === null && !$recalcular;
            $tipo = $nuevo;
            if (!$igual) $cuota = $cuota_dada ?? cuota_francesa($C, $tipo, $restantes);
        } elseif ($recalcular) {
            $cuota = cuota_francesa($C, $tipo, $restantes);
        }
        $intereses = round($C * $tipo / 1200, 2);
        $capital = round($cuota - $intereses, 2);
        $cuota_k = $cuota;
        if ($k === $fin || $capital >= $C) {
            $capital = $C;
            $cuota_k = round($capital + $intereses, 2);
        }
        $C = round($C - $capital, 2);
        $filas[] = hipoteca_fila($k, $fecha, $revision, $tipo, $origen, $eur, $cuota_k, $intereses, $capital, $C, $amortizado);
    }

    // Cada cuota vencida, con su cargo (el más cercano, sin repetir).
    $usados = [];
    $ultimo_cargo = $cargos ? end($cargos)['fecha'] : null;
    // Lo de antes del primer cargo que tiene finanzas no se puede casar: se da por pagado (como el cuadro del banco).
    $desde_cargos = $cargos ? date('Y-m-d', strtotime($cargos[0]['fecha'] . ' -' . HIPOTECA_DIAS_CARGO . ' days')) : null;
    foreach ($filas as $i => $f) {
        $vencida = $f['fecha'] <= $hoy;
        $filas[$i]['vencida'] = $vencida;
        $mejor = null;
        foreach ($cargos as $ci => $c) {
            if (isset($usados[$ci])) continue;
            $d = abs(dias_entre($f['fecha'], $c['fecha']));
            if ($d <= HIPOTECA_DIAS_CARGO && ($mejor === null || $d < $mejor[1])) $mejor = [$ci, $d];
        }
        if ($mejor && $vencida) {
            $usados[$mejor[0]] = true;
            $filas[$i]['cobro'] = $cargos[$mejor[0]];
            $filas[$i]['estado'] = 'cobrada';
        } elseif ($vencida) {
            $filas[$i]['estado'] = $desde_cargos === null || $f['fecha'] < $desde_cargos ? 'pagada'
                : ($f['fecha'] < $ultimo_cargo ? 'sin_cargo' : 'pendiente_extracto');
        } else {
            $filas[$i]['estado'] = $f['origen'] === 'estimado' ? 'estimada' : 'prevista';
        }
    }
    // Una vez estimado un tipo, todo lo que viene detrás también lo es.
    $estimando = false;
    foreach ($filas as $i => $f) {
        if ($f['vencida']) continue;
        if ($f['origen'] === 'estimado') $estimando = true;
        if ($estimando) $filas[$i]['estado'] = 'estimada';
    }
    $sueltos = array_values(array_filter($cargos, static fn($c, $ci) => !isset($usados[$ci]), ARRAY_FILTER_USE_BOTH));
    return ['filas' => $filas, 'resumen' => hipoteca_resumen($filas, $t, $hoy), 'cargos_sin_cuota' => $sueltos];
}

function hipoteca_fila(int $k, string $fecha, ?string $revision, ?float $tipo, ?string $origen, ?array $eur, float $cuota,
                       float $intereses, float $capital, float $pendiente, float $amortizado): array {
    return ['n' => $k + 1, 'fecha' => $fecha, 'revision' => $revision, 'tipo' => $tipo, 'origen' => $origen,
            'euribor' => $eur ? ['mes' => $eur['mes'], 'valor' => $eur['valor'], 'definitivo' => (bool)$eur['definitivo'],
                                 'dias' => (int)($eur['dias'] ?? 0)] : null,
            'cuota' => $cuota, 'intereses' => $intereses, 'capital' => $capital, 'pendiente' => $pendiente,
            'amortizado' => round($amortizado, 2), 'vencida' => false, 'cobro' => null, 'estado' => ''];
}

/**
 * Lo que se enseña arriba y lo que lee finanzas:
 *  capital_pendiente, capital_amortizado, porcentaje_amortizado, cuotas_pagadas, cuotas_pendientes,
 *  intereses_pagados, intereses_pendientes, pagado, tipo_actual, cuota_actual, ultima (fila), proxima (fila),
 *  cambio (la próxima cuota distinta: ['fila', 'antes', 'diferencia'] o null), fin (fecha de la última cuota).
 */
function hipoteca_resumen(array $filas, array $t, string $hoy): array {
    $vencidas = array_values(array_filter($filas, static fn($f) => $f['fecha'] <= $hoy));
    $futuras = array_values(array_filter($filas, static fn($f) => $f['fecha'] > $hoy));
    $ultima = $vencidas ? end($vencidas) : null;
    $pendiente = $ultima ? $ultima['pendiente'] : $t['capital'];
    // La próxima cuota distinta de la anterior (la última del préstamo no cuenta: solo ajusta céntimos).
    $cambio = null;
    $antes = $ultima;
    foreach ($futuras as $i => $f) {
        if ($i === count($futuras) - 1) break;
        if ($antes && abs($f['cuota'] - $antes['cuota']) >= HIPOTECA_CAMBIO_MINIMO) {
            $cambio = ['fila' => $f, 'antes' => $antes['cuota'], 'diferencia' => round($f['cuota'] - $antes['cuota'], 2)];
            break;
        }
        $antes = $f;
    }
    $pagado = array_sum(array_column($vencidas, 'cuota')) + array_sum(array_column($vencidas, 'amortizado'));
    return [
        'capital_pendiente' => round($pendiente, 2),
        'capital_amortizado' => round($t['capital'] - $pendiente, 2),
        'porcentaje_amortizado' => round(($t['capital'] - $pendiente) / $t['capital'] * 100, 2),
        'cuotas_pagadas' => count($vencidas),
        'cuotas_pendientes' => count($futuras),
        'intereses_pagados' => round(array_sum(array_column($vencidas, 'intereses')), 2),
        'intereses_pendientes' => round(array_sum(array_column($futuras, 'intereses')), 2),
        'pagado' => round($pagado, 2),
        'tipo_actual' => $ultima['tipo'] ?? ($futuras[0]['tipo'] ?? null),
        'cuota_actual' => $ultima['cuota'] ?? ($futuras[0]['cuota'] ?? null),
        'ultima' => $ultima,
        'proxima' => $futuras[0] ?? null,
        'cambio' => $cambio,
        'fin' => $filas ? end($filas)['fecha'] : null,
    ];
}

/** El cuadro de una ficha, leyendo lo que haga falta. Con $cargos = null no se piden a finanzas. */
function cuadro_de(PDO $pdo, array $el, ?array $cargos = null, ?string $hoy = null): ?array {
    return cuadro_hipoteca($el, tipos_de_hipoteca($pdo, (int)$el['id']), euribor_serie($pdo),
        amortizaciones_de($pdo, (int)$el['id']), $cargos ?? [], $hoy ?? hoy());
}

/** Para finanzas (acción `fichas`): lo pagado y lo que queda, con los nombres que usa allí. */
function amortizacion_para_finanzas(array $cuadro): array {
    $r = $cuadro['resumen'];
    return [
        'capital_pendiente' => $r['capital_pendiente'], 'capital_amortizado' => $r['capital_amortizado'],
        'porcentaje_amortizado' => $r['porcentaje_amortizado'], 'cuotas_pagadas' => $r['cuotas_pagadas'],
        'cuotas_pendientes' => $r['cuotas_pendientes'], 'cuota_ultima_pagada' => $r['ultima']['cuota'] ?? null,
        'fecha_ultima_cuota' => $r['ultima']['fecha'] ?? null, 'fecha_proxima_cuota' => $r['proxima']['fecha'] ?? null,
        'cuota_proxima' => $r['proxima']['cuota'] ?? null, 'tipo_actual' => $r['tipo_actual'],
        'intereses_pagados' => $r['intereses_pagados'], 'intereses_pendientes' => $r['intereses_pendientes'],
        'fecha_consulta_capital' => hoy(), 'origen' => 'segundo cerebro (cuadro calculado)',
    ];
}

// «Variable: Euríbor + 1,05 (3,905 % desde el 7/9/2026)».
function texto_interes(array $t, ?array $vigente): string {
    if (!$t['variable']) return 'Fijo: ' . tipo_es((float)$t['tipo_inicial']);
    $base = 'Variable: Euríbor + ' . numero_es($t['diferencial'], 3);
    if (!$vigente || $vigente['tipo'] === null) return $base;
    [$a, $m, $d] = array_map('intval', explode('-', $vigente['fecha']));
    return $base . ' (' . tipo_es($vigente['tipo']) . " desde el {$d}/{$m}/{$a})";
}

/** La cuota vigente: la primera de su tramo (desde cuándo rige el tipo de la última cuota vencida). */
function tramo_vigente(array $filas, string $hoy): ?array {
    $r = null;
    foreach ($filas as $f) {
        if ($f['fecha'] > $hoy) break;
        if ($r === null || abs($f['tipo'] - $r['tipo']) > 0.0004) $r = $f;
    }
    return $r;
}

/**
 * Pone al día las hipotecas variables sin que nadie haga nada. Para cada una:
 *  1) en cuanto el banco revisa (un mes antes de la cuota) y el Euríbor de ese mes está cerrado,
 *     guarda el tipo y la cuota nuevos en su historial de precios;
 *  2) desde el día de la cuota, la ficha lleva la cuota y el tipo vigentes (y con ellos el gasto
 *     fijo, el panel, la cuenta de la casa y finanzas);
 *  3) avisa en la agenda de la próxima subida o bajada (y lo quita cuando pasa).
 * Devuelve lo que ha cambiado, en frases.
 */
function hipotecas_al_dia(PDO $pdo, ?string $hoy = null): array {
    $hoy ??= hoy();
    $hecho = [];
    $euribor = euribor_serie($pdo);
    foreach (elementos_de($pdo, 'contratos') as $el) {
        if ($el['tipo'] !== 'hipoteca' || !$el['activo']) continue;
        $t = hipoteca_terminos($el);
        if (!$t) continue;
        $id = (int)$el['id'];
        $cuadro = cuadro_hipoteca($el, tipos_de_hipoteca($pdo, $id), $euribor, amortizaciones_de($pdo, $id), [], $hoy);
        if (!$cuadro) continue;

        // 1) Revisiones ya hechas por el banco con el Euríbor cerrado: se guardan.
        // Solo las que cambian el tipo: una revisión que lo deja igual no aporta nada al historial.
        $guardadas = array_column(tipos_de_hipoteca($pdo, $id), 'desde');
        foreach ($cuadro['filas'] as $i => $f) {
            if ($f['origen'] !== 'calculado' || !$f['revision'] || $f['revision'] > $hoy || in_array($f['fecha'], $guardadas, true)) continue;
            if ($f['amortizado'] > 0 || ($i > 0 && abs($f['tipo'] - $cuadro['filas'][$i - 1]['tipo']) < 0.0005)) continue;
            $e = $f['euribor'];
            $nota = 'calculado: Euríbor de ' . mes_largo($e['mes']) . ' (' . tipo_es($e['valor']) . ') + ' . numero_es($t['diferencial'], 3);
            $pdo->prepare('DELETE FROM precios WHERE elemento_id = ? AND desde = ?')->execute([$id, $f['fecha']]);
            $pdo->prepare('INSERT INTO precios (elemento_id, desde, coste, periodicidad, nota, tipo, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $f['fecha'], $f['cuota'], 'Mensual', recortar($nota, 200), $f['tipo'], ahora()]);
            $txt = "revisó «{$el['nombre']}»: " . tipo_es($f['tipo']) . ' y ' . eur($f['cuota']) . ' de cuota desde el ' . fecha_es($f['fecha'])
                 . ' (Euríbor de ' . mes_largo($e['mes']) . ', ' . tipo_es($e['valor']) . ')';
            anotar($pdo, null, $txt);
            $hecho[] = $txt;
        }

        // 2) La ficha, con la cuota y el tipo vigentes.
        $vigente = tramo_vigente($cuadro['filas'], $hoy);
        $ultima = $cuadro['resumen']['ultima'];
        $el = elemento($pdo, $id);
        if ($ultima && $vigente) {
            $cuota = $ultima['cuota'];
            $coste = is_numeric($el['datos']['coste'] ?? null) ? round((float)$el['datos']['coste'], 2) : null;
            if ($coste === null || abs($coste - $cuota) > 0.004) {
                cambiar_dato_elemento($pdo, $id, 'coste', $cuota);
                $txt = "puso la cuota de «{$el['nombre']}» en " . eur($cuota) . ($coste !== null ? ' (antes, ' . eur($coste) . ')' : '');
                anotar($pdo, null, $txt);
                $hecho[] = $txt;
            }
            $interes = texto_interes($t, $vigente);
            if (($el['datos']['interes'] ?? '') !== $interes) cambiar_dato_elemento($pdo, $id, 'interes', $interes);
        }

        // 3) El aviso de la próxima subida o bajada.
        hipoteca_aviso($pdo, $el, $cuadro['resumen']['cambio'], $hoy);
    }
    return $hecho;
}

/** Un solo aviso (origen «auto:hipoteca») con la próxima cuota distinta; se cierra solo cuando llega. */
function hipoteca_aviso(PDO $pdo, array $el, ?array $cambio, string $hoy): void {
    $id = (int)$el['id'];
    $origen = 'auto:hipoteca';
    // Los de cuotas que ya han llegado se dan por hechos.
    $pdo->prepare("UPDATE vencimientos SET estado = 'hecho', hecho_en = ? WHERE elemento_id = ? AND origen = ? AND estado = 'pendiente' AND fecha <= ?")
        ->execute([$hoy, $id, $origen, $hoy]);
    $st = $pdo->prepare("SELECT id, fecha, titulo, notas FROM vencimientos WHERE elemento_id = ? AND origen = ? AND estado = 'pendiente'");
    $st->execute([$id, $origen]);
    $pendiente = $st->fetch() ?: null;
    if (!$cambio || dias_entre($hoy, $cambio['fila']['fecha']) > HIPOTECA_DIAS_AVISO) {
        if ($pendiente) $pdo->prepare('DELETE FROM vencimientos WHERE id = ?')->execute([$pendiente['id']]);
        return;
    }
    $f = $cambio['fila'];
    $estimada = $f['estado'] === 'estimada';
    $sube = $cambio['diferencia'] > 0;
    $titulo = 'La cuota de la hipoteca ' . ($sube ? 'sube' : 'baja') . ' a ' . ($estimada ? '≈' : '') . eur($f['cuota'])
            . ' (' . ($sube ? '+' : '−') . eur(abs($cambio['diferencia'])) . ')';
    $e = $f['euribor'];
    $notas = 'Tipo del ' . tipo_es($f['tipo']) . ($e ? ': Euríbor de ' . mes_largo($e['mes']) . ' (' . tipo_es($e['valor'])
           . ($e['definitivo'] ? '' : ', provisional con ' . $e['dias'] . ' días') . ') + diferencial.' : '.')
           . ' Lo calcula la app sola; no hay que hacer nada.';
    // Si ya se marcó hecho para esa fecha, no se vuelve a poner.
    $st = $pdo->prepare("SELECT COUNT(*) FROM vencimientos WHERE elemento_id = ? AND origen = ? AND estado = 'hecho' AND fecha = ?");
    $st->execute([$id, $origen, $f['fecha']]);
    if ((int)$st->fetchColumn() > 0) return;
    if ($pendiente) {
        if ($pendiente['fecha'] !== $f['fecha'] || $pendiente['titulo'] !== $titulo || (string)$pendiente['notas'] !== $notas) {
            $pdo->prepare('UPDATE vencimientos SET titulo = ?, fecha = ?, notas = ? WHERE id = ?')->execute([$titulo, $f['fecha'], $notas, $pendiente['id']]);
        }
        return;
    }
    $pdo->prepare("INSERT INTO vencimientos (seccion, elemento_id, titulo, fecha, aviso_dias, repetir_meses, origen, estado, notas, creado_en)
                   VALUES (?, ?, ?, ?, 30, 0, ?, 'pendiente', ?, ?)")
        ->execute([$el['seccion'], $id, $titulo, $f['fecha'], $origen, $notas, ahora()]);
}

// Textos del estado de cada cuota en el cuadro.
function estado_cuota(array $f): string {
    return ['cobrada' => 'Cobrada', 'pagada' => 'Pagada', 'sin_cargo' => 'Sin cargo en el extracto',
            'pendiente_extracto' => 'Falta el extracto', 'prevista' => 'Prevista', 'estimada' => 'Estimada'][$f['estado']] ?? '';
}

/**
 * La cuota mes a mes, de la primera a la última: lo pagado en trazo lleno y lo que viene en
 * discontinuo (con el tipo de hoy), con «hoy» marcado. SVG pintado aquí (la CSP no deja librerías).
 */
function grafica_hipoteca(array $filas, string $hoy): string {
    if (count($filas) < 2) return '';
    $W = 640; $H = 280; $iz = 46; $de = 12; $ar = 16; $ab = 26;
    $cuotas = array_column(array_slice($filas, 0, -1), 'cuota');   // la última solo ajusta céntimos
    $lo = min($cuotas); $hi = max($cuotas);
    $paso = 50;
    foreach ([25, 50, 100, 200, 500] as $p) { $paso = $p; if (($hi - $lo) / $p <= 5) break; }
    $lo = floor(($lo - $paso * 0.3) / $paso) * $paso; $hi = ceil(($hi + $paso * 0.3) / $paso) * $paso;
    $n = count($filas);
    $x = static fn(int $i) => $iz + $i / ($n - 1) * ($W - $iz - $de);
    $y = static fn(float $v) => $ar + ($hi - $v) / ($hi - $lo) * ($H - $ar - $ab);
    $f = static fn(float $v) => number_format($v, 1, '.', '');
    $s = '<svg class="grafica-hipoteca" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="'
       . e('Cuota mensual de la hipoteca, de ' . eur($filas[0]['cuota']) . ' a ' . eur($cuotas[count($cuotas) - 1])) . '">';
    for ($v = $lo; $v <= $hi + 0.01; $v += $paso) {
        $yy = $f($y($v));
        $s .= '<line class="gh-rejilla" x1="' . $iz . '" x2="' . ($W - $de) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text class="gh-eje" x="' . ($iz - 6) . '" y="' . $yy . '" text-anchor="end" dominant-baseline="middle">' . e(numero_es($v)) . '</text>';
    }
    $anio0 = (int)substr($filas[0]['fecha'], 0, 4); $anio1 = (int)substr($filas[$n - 1]['fecha'], 0, 4);
    $cada = max(1, (int)ceil(($anio1 - $anio0) / 6));
    foreach ($filas as $i => $r) {
        $a = (int)substr($r['fecha'], 0, 4);
        if (substr($r['fecha'], 5, 2) === '01' && ($a - $anio0) % $cada === 0) {
            $s .= '<text class="gh-eje" x="' . $f($x($i)) . '" y="' . ($H - 6) . '" text-anchor="middle">' . $a . '</text>';
        }
    }
    // Hoy.
    $ih = null;
    foreach ($filas as $i => $r) if ($r['fecha'] <= $hoy) $ih = $i;
    if ($ih !== null) {
        $s .= '<line class="gh-hoy" x1="' . $f($x($ih)) . '" x2="' . $f($x($ih)) . '" y1="' . $ar . '" y2="' . ($H - $ab) . '"/>'
            . '<text class="gh-eje gh-hoy-txt" x="' . $f($x($ih) + 5) . '" y="' . ($ar + 10) . '">hoy</text>';
    }
    // La cuota en escalones: lo vencido lleno, lo que viene discontinuo.
    $tramo = static function (array $idx) use ($filas, $x, $y, $f): string {
        $d = '';
        foreach ($idx as $j => $i) {
            $d .= ($j ? ' H ' . $f($x($i)) . ' V ' : 'M ' . $f($x($i)) . ' ') . $f($y($filas[$i]['cuota']));
        }
        return $d;
    };
    $pasado = $futuro = [];
    foreach (array_keys(array_slice($filas, 0, -1, true)) as $i) {
        if ($filas[$i]['fecha'] <= $hoy) $pasado[] = $i; else $futuro[] = $i;
    }
    if ($pasado && $futuro) array_unshift($futuro, end($pasado));
    if (count($pasado) > 1) $s .= '<path class="gh-linea" d="' . $tramo($pasado) . '"/>';
    if (count($futuro) > 1) $s .= '<path class="gh-linea gh-estimada" d="' . $tramo($futuro) . '"/>';
    // Cada cambio de cuota, con su tipo (para el dedo o el ratón).
    $antes = null;
    foreach ($filas as $i => $r) {
        if ($i === $n - 1) break;
        if ($antes === null || abs($r['cuota'] - $antes) >= HIPOTECA_CAMBIO_MINIMO) {
            $s .= '<circle class="gh-punto' . ($r['fecha'] > $hoy ? ' gh-punto-futuro' : '') . '" cx="' . $f($x($i)) . '" cy="' . $f($y($r['cuota'])) . '" r="3"><title>'
                . e(fecha_es($r['fecha']) . ': ' . eur($r['cuota']) . ' al ' . tipo_es((float)$r['tipo']) . ($r['fecha'] > $hoy ? ' (' . minusculas(estado_cuota($r)) . ')' : ''))
                . '</title></circle>';
        }
        if ($antes === null || abs($r['cuota'] - $antes) >= HIPOTECA_CAMBIO_MINIMO) $antes = $r['cuota'];
    }
    return $s . '</svg>';
}
