<?php
// =====================================================================
//  La cuenta común de la casa (8/10/2026, Gonzalo pasó el extracto de 2026).
//
//  Gonzalo y Pilar tienen cada uno su cuenta y una común en Mediolanum para
//  la casa. Cada uno manda ahí su parte de la hipoteca («Hipoteca Gonza»,
//  «Hipoteca Pilar») y lo que pone para gastos («gastos Gonza»…); de ahí
//  salen la hipoteca, la comunidad, los suministros, el colegio, el súper…
//
//  Los movimientos viven en finanzas (cuenta de ámbito «casa», fuera de las
//  cifras de Gonzalo) y se leen de su acción «casa». Aquí se hacen los
//  números, con lo acordado (Gonzalo, 8/10/2026): la hipoteca, cada uno su
//  parte (el porcentaje de la ficha de la hipoteca: 60/40); el resto, a
//  medias. Funciones puras: la página es cuenta-casa.php.
// =====================================================================

// Las categorías de finanzas con una ficha detrás: sus cargos se buscan en
// el historial de las fichas (las facturas y recibos apuntados).
const CASA_CATEGORIAS_FICHA = ['Comunidad', 'Suministros', 'Seguros', 'Impuestos'];
const CASA_PREFIJO_APORTACION = 'Aportación ';

// «A dónde va», por bloques con los nombres del menú donde encajan (Gonzalo,
// 9/10/2026): la categoría de finanzas queda dentro de su bloque. Finanzas
// no cambia (sus categorías son planas y las comparte con las cuentas de
// Gonzalo); esto solo agrupa al pintar. Lo que no está en ninguno va a Otros.
// El cajero es para pagar a la chica de la limpieza: Vivienda.
const CASA_GRUPOS = [
    'Vivienda'     => ['Hipoteca', 'Suministros', 'Comunidad', 'Impuestos', 'Seguro de hogar', 'Hogar', 'Cajero'],
    'Alimentación' => ['Supermercado', 'Restaurantes'],
    'Familia'      => ['Educación', 'Niños', 'Extraescolares', 'Ropa', 'Regalos'],
    'Vehículos'    => ['Combustible', 'Seguros de vehículos', 'Vehículos'],
    'Viajes'       => ['Viajes'],
    'Salud'        => ['Salud/Farmacia'],
    'Otros'        => [],
];
// Cómo se llama dentro del bloque lo que en finanzas tiene otro nombre.
const CASA_NOMBRES = ['Educación' => 'Colegio', 'Cajero' => 'Limpieza (efectivo)', 'Impuestos' => 'IBI e impuestos',
                      'Salud/Farmacia' => 'Farmacia y salud', 'Niños' => 'Otros de los niños'];
// Los seguros se parten por la compañía: el del T4 es de Generali; el de hogar, de Tuio.
const CASA_SEGUROS_VEHICULO = ['generali', 'qualitas', 'allianz', 'axa'];

// 'Aportación Pilar' → 'Pilar'; null si no es una aportación.
function aportante(string $categoria): ?string {
    return str_starts_with($categoria, CASA_PREFIJO_APORTACION) ? substr($categoria, strlen(CASA_PREFIJO_APORTACION)) : null;
}

function anios_cuenta_casa(array $movs): array {
    $out = [];
    foreach ($movs as $m) $out[(int)substr((string)$m['fecha'], 0, 4)] = true;
    krsort($out);
    return array_keys($out);
}

/**
 * La parte de la hipoteca de cada aportante, en %, desde la ficha de la
 * hipoteca: su «parte que paga el titular» es del titular y el resto, del
 * otro. Se casa por el nombre de pila de la persona titular. Sin ficha, a
 * medias. $aportantes: ['Gonzalo', 'Pilar'].
 */
function reparto_hipoteca(?array $hipoteca, ?string $nombre_titular, array $aportantes): array {
    $n = count($aportantes);
    if (!$n) return [];
    $igual = array_fill_keys($aportantes, 100 / $n);
    $p = $hipoteca['datos']['porcentaje_pago'] ?? null;
    if (!$hipoteca || !is_numeric($p) || $n !== 2 || $nombre_titular === null) return $igual;
    $pila = minusculas(strtok(trim($nombre_titular), ' ') ?: '');
    $titular = null;
    foreach ($aportantes as $a) if (minusculas($a) === $pila) $titular = $a;
    if ($titular === null) return $igual;
    $otro = $aportantes[0] === $titular ? $aportantes[1] : $aportantes[0];
    return [$titular => (float)$p, $otro => 100 - (float)$p];
}

/**
 * Los números de un año. $movs: los de la acción «casa» de finanzas (fecha,
 * concepto, importe, categoria). $pct_hipoteca: [aportante => %].
 *
 * Devuelve:
 *  - personas: [nombre => [hipoteca, gastos, total (lo puesto), toca, dif]]
 *    «toca» = su parte de las cuotas pagadas + la mitad del resto del gasto.
 *  - desfase: lo que uno lleva puesto de más frente al otro con lo acordado
 *    (la mitad de la diferencia entre sus «dif»), y quién.
 *  - cuotas: lo pagado de hipoteca; resto: el gasto neto del resto (las
 *    devoluciones y otras entradas, como la renta, ya restan).
 *  - categorias: [nombre, gasto neto, media al mes, %] de más a menos, y
 *    entradas: las categorías que en neto ingresan (la renta…).
 *  - meses: [AAAA-MM => [gasto, puesto => [nombre => €]]].
 *  - sin_categorizar: cuántos y cuánto dinero (en valor absoluto).
 */
function analisis_cuenta_casa(array $movs, int $anio, string $hoy, array $pct_hipoteca): array {
    $personas = [];
    foreach (array_keys($pct_hipoteca) as $a) $personas[$a] = ['hipoteca' => 0.0, 'gastos' => 0.0];
    $cuotas = 0.0; $neto = []; $meses = []; $sin = ['n' => 0, 'importe' => 0.0];
    foreach ($movs as $m) {
        if ((int)substr((string)$m['fecha'], 0, 4) !== $anio) continue;
        $mes = substr((string)$m['fecha'], 0, 7);
        $imp = (float)$m['importe'];
        $cat = (string)($m['categoria'] ?? 'Sin categorizar');
        $meses[$mes] ??= ['gasto' => 0.0, 'puesto' => []];
        if (($quien = aportante($cat)) !== null) {
            $personas[$quien] ??= ['hipoteca' => 0.0, 'gastos' => 0.0];
            $personas[$quien][str_contains(minusculas((string)$m['concepto']), 'hipoteca') ? 'hipoteca' : 'gastos'] += $imp;
            $meses[$mes]['puesto'][$quien] = ($meses[$mes]['puesto'][$quien] ?? 0.0) + $imp;
            continue;
        }
        // Lo que entra y sale (Reembolso) es neutro; si no suma cero es porque su
        // entrada está dentro de una venta (Ingresos extra), y ahí se descuenta.
        if ($cat === 'Reembolso') $cat = 'Ingresos extra';
        if ($cat === 'Seguros') {
            $c = minusculas((string)$m['concepto']);
            $cat = array_filter(CASA_SEGUROS_VEHICULO, static fn($k) => str_contains($c, $k)) ? 'Seguros de vehículos' : 'Seguro de hogar';
        }
        $meses[$mes]['gasto'] -= $imp;
        if ($cat === 'Hipoteca') { $cuotas -= $imp; continue; }
        $neto[$cat] = ($neto[$cat] ?? 0.0) - $imp;
        if ($cat === 'Sin categorizar') { $sin['n']++; $sin['importe'] += abs($imp); }
    }
    ksort($meses);
    $resto = array_sum($neto);

    $n = max(1, count($personas));
    $puesto_hipoteca = array_sum(array_column($personas, 'hipoteca'));
    foreach ($personas as $quien => &$p) {
        $p['pct_puesto_hipoteca'] = $puesto_hipoteca > 0 ? $p['hipoteca'] / $puesto_hipoteca * 100 : null;
        $p['total'] = $p['hipoteca'] + $p['gastos'];
        $p['toca'] = $cuotas * ($pct_hipoteca[$quien] ?? 0) / 100 + $resto / $n;
        $p['dif'] = $p['total'] - $p['toca'];
        $p['pct_hipoteca'] = $pct_hipoteca[$quien] ?? null;
    }
    unset($p);
    $desfase = null;
    if (count($personas) === 2) {
        [$a, $b] = array_keys($personas);
        $d = ($personas[$a]['dif'] - $personas[$b]['dif']) / 2;
        if (abs($d) >= 0.005) $desfase = ['de_mas' => $d > 0 ? $a : $b, 'otro' => $d > 0 ? $b : $a, 'importe' => abs($d)];
    }

    // Meses para la media: los enteros que han pasado y el actual a medias.
    $fin = $anio < (int)substr($hoy, 0, 4) ? $anio . '-12-31' : $hoy;
    $meses_trans = (int)substr($fin, 5, 2) - 1 + (int)substr($fin, 8, 2) / (int)date('t', strtotime($fin));
    $meses_trans = max($meses_trans, 1 / 31);
    $gasto_total = $cuotas + array_sum(array_filter($neto, static fn($v) => $v > 0));
    $categorias = []; $entradas = [];
    foreach ($neto as $cat => $v) {
        if ($v > 0.005) $categorias[] = ['nombre' => $cat, 'gasto' => $v, 'mes' => $v / $meses_trans, 'pct' => $gasto_total > 0 ? $v / $gasto_total * 100 : 0];
        elseif ($v < -0.005) $entradas[] = ['nombre' => $cat, 'importe' => -$v];
    }
    if ($cuotas > 0) $categorias[] = ['nombre' => 'Hipoteca', 'gasto' => $cuotas, 'mes' => $cuotas / $meses_trans, 'pct' => $gasto_total > 0 ? $cuotas / $gasto_total * 100 : 0];
    usort($categorias, static fn($x, $y) => $y['gasto'] <=> $x['gasto']);
    usort($entradas, static fn($x, $y) => $y['importe'] <=> $x['importe']);

    // Los bloques: cada categoría a su bloque (o a Otros), de más a menos.
    $de_bloque = [];
    foreach (CASA_GRUPOS as $g => $cats) foreach ($cats as $c) $de_bloque[$c] = $g;
    $grupos = [];
    foreach ($categorias as $c) {
        $g = $de_bloque[$c['nombre']] ?? 'Otros';
        $grupos[$g] ??= ['nombre' => $g, 'gasto' => 0.0, 'mes' => 0.0, 'pct' => 0.0, 'subs' => []];
        $grupos[$g]['gasto'] += $c['gasto'];
        $grupos[$g]['mes'] += $c['mes'];
        $grupos[$g]['pct'] += $c['pct'];
        $grupos[$g]['subs'][] = ['nombre' => CASA_NOMBRES[$c['nombre']] ?? $c['nombre']] + $c;
    }
    $grupos = array_values($grupos);
    usort($grupos, static fn($x, $y) => $y['gasto'] <=> $x['gasto']);

    return ['anio' => $anio, 'personas' => $personas, 'desfase' => $desfase, 'cuotas' => $cuotas, 'resto' => $resto,
            'gasto_total' => $gasto_total, 'meses_transcurridos' => $meses_trans, 'gasto_mes' => $gasto_total / $meses_trans,
            'categorias' => $categorias, 'grupos' => $grupos, 'entradas' => $entradas, 'meses' => $meses, 'sin_categorizar' => $sin];
}

/**
 * Los cargos de la cuenta con ficha detrás (comunidad, suministros, seguros,
 * impuestos) que no están apuntados en el historial de ninguna ficha. Un
 * cargo casa con un apunte del mismo importe (al céntimo) a 60 días o menos (la
 * factura del gas de diciembre de 2025 está fechada el 31/12 y se cobró el 16/2);
 * o dos cargos del mismo concepto que juntos suman un apunte (la comunidad
 * cobró el recibo del 2T26 en dos veces). $apuntes: [[id, fecha, coste,
 * elemento_id, nombre], ...] de cargos_apuntados().
 * Devuelve ['sin_ficha' => [mov...], 'casados' => n].
 */
function cargos_sin_apuntar(array $movs, array $apuntes, int $anio): array {
    $cent = static fn($v): int => (int)round(abs((float)$v) * 100);
    $cargos = array_values(array_filter($movs, static fn($m) => (int)substr((string)$m['fecha'], 0, 4) === $anio
        && (float)$m['importe'] < 0 && in_array($m['categoria'] ?? '', CASA_CATEGORIAS_FICHA, true)));
    $libres = $apuntes;
    $casado = array_fill(0, count($cargos), false);
    $cerca = static fn(string $a, string $b): bool => abs(dias_entre($a, $b)) <= 60;
    // 1) Uno a uno.
    foreach ($cargos as $i => $c) {
        foreach ($libres as $k => $a) {
            if ($cent($a['coste']) === $cent($c['importe']) && $cerca($a['fecha'], $c['fecha'])) {
                $casado[$i] = true; unset($libres[$k]); break;
            }
        }
    }
    // 2) Dos cargos del mismo concepto que juntos son un apunte.
    foreach ($cargos as $i => $c) {
        if ($casado[$i]) continue;
        foreach ($cargos as $j => $d) {
            if ($j <= $i || $casado[$j] || $d['concepto'] !== $c['concepto']) continue;
            foreach ($libres as $k => $a) {
                if ($cent($a['coste']) === $cent($c['importe']) + $cent($d['importe'])
                    && $cerca($a['fecha'], $c['fecha']) && $cerca($a['fecha'], $d['fecha'])) {
                    $casado[$i] = $casado[$j] = true; unset($libres[$k]); break 2;
                }
            }
        }
    }
    $sin = [];
    foreach ($cargos as $i => $c) if (!$casado[$i]) $sin[] = $c;
    return ['sin_ficha' => $sin, 'casados' => count(array_filter($casado))];
}

// La última cuota de la hipoteca que ha cobrado el banco: [fecha, importe] o null.
function ultima_cuota(array $movs): ?array {
    $u = null;
    foreach ($movs as $m) {
        if (($m['categoria'] ?? '') === 'Hipoteca' && (float)$m['importe'] < 0 && ($u === null || $m['fecha'] >= $u['fecha'])) {
            $u = ['fecha' => (string)$m['fecha'], 'importe' => -(float)$m['importe']];
        }
    }
    return $u;
}
