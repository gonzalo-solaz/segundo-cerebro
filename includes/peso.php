<?php
// =====================================================================
//  Control de peso (salud/peso): IMC, evolución, ritmo, objetivo y pautas
//  calculadas para cada persona. Lo pinta peso.php y lo da la API
//  (acción «peso») para que Claude ponga al día el plan.
//
//  Cada pesaje es un apunte del historial del elemento (tipo «Peso» en kg,
//  «Cintura» en cm, «Grasa corporal» en %): no hay tabla propia, así que la
//  API, el borrado y la actividad son los de siempre. Un día = una medida
//  de cada tipo: si se repite el día, manda el último apunte.
//
//  Las referencias (para no inventarlas otra vez):
//    · IMC y sus grados: OMS. En menores no vale (se usan percentiles) y en
//      mayores de 65 se tolera algo más alto (22-27, SEGG).
//    · Ritmo sano de pérdida: 0,5-1 kg por semana y no más del 1 % del peso.
//    · Gasto: Mifflin-St Jeor × factor de actividad. Déficit de ~500 kcal,
//      nunca por debajo de 1.500 (hombres) / 1.200 (mujeres) sin médico.
//    · Cintura: riesgo alto desde 102 cm (H) / 88 cm (M) (OMS); índice
//      cintura/altura < 0,5 (NICE 2022).
// =====================================================================

function tipos_medida_peso(): array {
    return ['Peso' => 'peso', 'Cintura' => 'cintura', 'Grasa corporal' => 'grasa'];
}

function elementos_peso(PDO $pdo): array {
    return array_values(array_filter(elementos_de($pdo, 'salud'), static fn($e) => $e['tipo'] === 'peso'));
}

function imc(float $kg, float $cm): float {
    $m = $cm / 100;
    return round($kg / ($m * $m), 1);
}

// [nombre, clase]: la clase colorea (ok | aviso | alto).
function categoria_imc(float $imc): array {
    if ($imc < 18.5) return ['Bajo peso', 'aviso'];
    if ($imc < 25)   return ['Peso normal', 'ok'];
    if ($imc < 30)   return ['Sobrepeso', 'aviso'];
    if ($imc < 35)   return ['Obesidad grado I', 'alto'];
    if ($imc < 40)   return ['Obesidad grado II', 'alto'];
    return ['Obesidad grado III', 'alto'];
}

// Pesos con IMC entre 18,5 y 24,9 para esa altura.
function rango_peso_sano(float $cm): array {
    $m2 = ($cm / 100) ** 2;
    return [round(18.5 * $m2, 1), round(24.9 * $m2, 1)];
}

// La altura escrita en metros («1,78») también vale.
function altura_cm($v): ?float {
    if (!is_numeric($v)) return null;
    $v = (float)$v;
    if ($v > 0 && $v < 3) $v *= 100;
    return $v >= 50 && $v <= 250 ? $v : null;
}

/**
 * Las medidas de un elemento, un día por fila, del más antiguo al más
 * reciente: [fecha => ['peso' => ?float, 'cintura' => ?float, 'grasa' => ?float,
 * 'notas' => string, 'ids' => [registro_id, ...]]].
 */
function mediciones_peso(PDO $pdo, int $elemento_id): array {
    $tipos = tipos_medida_peso();
    $st = $pdo->prepare("SELECT id, fecha, tipo, valor, notas FROM registros
                         WHERE elemento_id = ? AND tipo IN ('Peso', 'Cintura', 'Grasa corporal') AND valor IS NOT NULL
                         ORDER BY fecha, id");
    $st->execute([$elemento_id]);
    $out = [];
    foreach ($st as $r) {
        $d = &$out[$r['fecha']];
        $d ??= ['peso' => null, 'cintura' => null, 'grasa' => null, 'notas' => '', 'ids' => []];
        $d[$tipos[$r['tipo']]] = (float)$r['valor'];
        $d['ids'][] = (int)$r['id'];
        if (trim((string)$r['notas']) !== '') $d['notas'] = trim((string)$r['notas']);
        unset($d);
    }
    return $out;
}

/**
 * Apunta un pesaje (peso, cintura y grasa, cualquiera de ellos) como apuntes
 * del historial. Si ese día ya había una medida del mismo tipo, la sustituye:
 * pesarse otra vez el mismo día corrige, no duplica.
 */
function guardar_medicion(PDO $pdo, int $elemento_id, array $e, ?int $usuario_id = null): void {
    $el = elemento($pdo, $elemento_id);
    if (!$el || $el['seccion'] !== 'salud' || $el['tipo'] !== 'peso') throw new ErrorValidacion(['Ese control de peso no existe.']);
    $errores = [];
    $fecha = trim((string)($e['fecha'] ?? '')) === '' ? hoy() : leer_fecha($e['fecha']);
    if (!$fecha) $errores[] = 'Pon una fecha válida.';
    elseif ($fecha > hoy()) $errores[] = 'La fecha no puede ser futura.';

    $limites = ['Peso' => ['peso', 20, 350, 'El peso'], 'Cintura' => ['cintura', 40, 250, 'La cintura'],
                'Grasa corporal' => ['grasa', 2, 75, 'La grasa corporal']];
    $valores = [];
    foreach ($limites as $tipo => [$campo, $min, $max, $txt]) {
        if (trim((string)($e[$campo] ?? '')) === '') continue;
        $n = leer_numero($e[$campo]);
        if ($n === null || $n < $min || $n > $max) $errores[] = "{$txt} tiene que ser un número entre {$min} y {$max}.";
        else $valores[$tipo] = round($n, 2);
    }
    if (!$valores && !$errores) $errores[] = 'Apunta al menos el peso, la cintura o la grasa.';
    if ($errores) throw new ErrorValidacion($errores);

    $notas = trim((string)($e['notas'] ?? ''));
    $pdo->beginTransaction();
    try {
        $viejos = $pdo->prepare('SELECT id FROM registros WHERE elemento_id = ? AND fecha = ? AND tipo = ?');
        $primero = true;
        foreach ($valores as $tipo => $v) {
            $viejos->execute([$elemento_id, $fecha, $tipo]);
            foreach ($viejos->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                $pdo->prepare('DELETE FROM registros WHERE id = ?')->execute([$rid]);
            }
            crear_registro($pdo, ['elemento_id' => $elemento_id, 'fecha' => $fecha, 'tipo' => $tipo, 'titulo' => $tipo,
                                  'valor' => numero_input($v), 'notas' => $primero ? $notas : ''], $usuario_id);
            $primero = false;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

// Borra las medidas de un día (los apuntes que se pasan, si son de ese elemento).
function borrar_medicion(PDO $pdo, int $elemento_id, array $ids, ?int $usuario_id = null): void {
    foreach ($ids as $rid) borrar_registro($pdo, (int)$rid, $elemento_id, $usuario_id);
}

/**
 * Peso de tendencia: media móvil exponencial que tiene en cuenta los días
 * entre pesajes (10 % por día, como en «The Hacker's Diet»). Quita el ruido
 * del agua y la sal, que mueven la báscula 1-2 kg de un día a otro.
 */
function tendencia_peso(array $serie): array {
    $out = [];
    $t = null;
    $antes = null;
    foreach ($serie as $f => $kg) {
        if ($t === null) $t = $kg;
        else $t += (1 - 0.9 ** max(1, dias_entre($antes, $f))) * ($kg - $t);
        $out[$f] = round($t, 2);
        $antes = $f;
    }
    return $out;
}

// Pendiente (kg por semana) de la recta que mejor se ajusta a los pesajes de
// los últimos $dias. Null si hay menos de 3 o abarcan menos de 10 días.
function ritmo_semanal(array $serie, int $dias = 28): ?float {
    if (!$serie) return null;
    $fin = array_key_last($serie);
    $desde = sumar_dias($fin, -$dias);
    $xs = $ys = [];
    foreach ($serie as $f => $kg) {
        if ($f < $desde) continue;
        $xs[] = dias_entre($desde, $f);
        $ys[] = $kg;
    }
    $n = count($xs);
    if ($n < 3 || max($xs) - min($xs) < 10) return null;
    $mx = array_sum($xs) / $n;
    $my = array_sum($ys) / $n;
    $num = $den = 0.0;
    foreach ($xs as $i => $x) { $num += ($x - $mx) * ($ys[$i] - $my); $den += ($x - $mx) ** 2; }
    return $den > 0 ? round($num / $den * 7, 2) : null;
}

// El pesaje más reciente de hace $dias o más (para «cambio en 30 días»).
function pesaje_de_hace(array $serie, int $dias): ?array {
    if (!$serie) return null;
    $limite = sumar_dias(array_key_last($serie), -$dias);
    $hallado = null;
    foreach ($serie as $f => $kg) {
        if ($f > $limite) break;
        $hallado = ['fecha' => $f, 'kg' => $kg];
    }
    return $hallado;
}

/**
 * Todo lo que pinta peso.php (y devuelve la API) para un control de peso.
 */
function analisis_peso(PDO $pdo, array $el): array {
    $d = $el['datos'];
    $med = mediciones_peso($pdo, $el['id']);
    $serie = [];
    foreach ($med as $f => $m) if ($m['peso'] !== null) $serie[$f] = $m['peso'];
    $persona = $el['persona_id'] ? persona($pdo, $el['persona_id']) : null;
    $edad = edad($persona['fecha_nacimiento'] ?? null);
    $altura = altura_cm($d['altura'] ?? null);
    $sexo = $d['sexo'] ?? null;
    $factor = niveles_actividad()[$d['actividad'] ?? ''] ?? null;
    $objetivo = is_numeric($d['peso_objetivo'] ?? null) ? (float)$d['peso_objetivo'] : null;

    $a = [
        'mediciones' => $med, 'serie' => $serie, 'tendencia' => tendencia_peso($serie),
        'altura' => $altura, 'sexo' => $sexo, 'edad' => $edad, 'factor' => $factor, 'objetivo' => $objetivo,
        'menor' => $edad !== null && $edad < 18, 'mayor' => $edad !== null && $edad >= 65,
        'actual' => null, 'fecha' => null, 'inicial' => null, 'fecha_inicial' => null, 'minimo' => null, 'maximo' => null,
        'imc' => null, 'categoria' => null, 'rango_sano' => $altura ? rango_peso_sano($altura) : null,
        'cambios' => [], 'ritmo' => null, 'ritmo_dias' => null, 'ritmo_max' => null,
        'falta' => null, 'llegada' => null,
        'cintura' => null, 'fecha_cintura' => null, 'ica' => null, 'riesgo_cintura' => null, 'grasa' => null,
        'calorias' => null,
    ];
    foreach (array_reverse($med) as $f => $m) {
        if ($a['cintura'] === null && $m['cintura'] !== null) { $a['cintura'] = $m['cintura']; $a['fecha_cintura'] = $f; }
        if ($a['grasa'] === null && $m['grasa'] !== null) $a['grasa'] = ['valor' => $m['grasa'], 'fecha' => $f];
    }

    if ($serie) {
        $a['fecha'] = array_key_last($serie);
        $a['actual'] = $serie[$a['fecha']];
        $a['fecha_inicial'] = array_key_first($serie);
        $a['inicial'] = $serie[$a['fecha_inicial']];
        $a['minimo'] = min($serie);
        $a['maximo'] = max($serie);
        foreach ([7, 30, 90, 365] as $n) {
            $p = pesaje_de_hace($serie, $n);
            if ($p) $a['cambios'][$n] = ['kg' => round($a['actual'] - $p['kg'], 2), 'desde' => $p['fecha'], 'peso' => $p['kg']];
        }
        // El ritmo, de las últimas 4 semanas; si no da, de los últimos 3 meses.
        foreach ([28, 90] as $n) {
            $r = ritmo_semanal($serie, $n);
            if ($r !== null) { $a['ritmo'] = $r; $a['ritmo_dias'] = $n; break; }
        }
        $a['ritmo_max'] = round(min(1.0, $a['actual'] * 0.01), 2);
        if ($altura) {
            $a['imc'] = imc($a['actual'], $altura);
            $a['categoria'] = categoria_imc($a['imc']);
        }
        if ($objetivo !== null) {
            $a['falta'] = round($a['actual'] - $objetivo, 2);   // > 0: falta por perder
            $r = $a['ritmo'];
            if ($r !== null && abs($a['falta']) >= 0.3 && abs($r) >= 0.05 && ($r < 0) === ($a['falta'] > 0)) {
                $a['llegada'] = sumar_dias($a['fecha'], (int)round(abs($a['falta']) / abs($r) * 7));
            }
        }
        if ($altura && $sexo && $edad !== null && $edad >= 18) {
            $basal = 10 * $a['actual'] + 6.25 * $altura - 5 * $edad + ($sexo === 'Mujer' ? -161 : 5);
            $minimo = $sexo === 'Mujer' ? 1200 : 1500;
            $mantener = $factor ? $basal * $factor : null;
            // Proteína sobre un peso de referencia: el objetivo si se quiere bajar,
            // o el máximo sano si hay sobrepeso; si no, el peso de hoy.
            $ref = $a['actual'];
            if ($objetivo !== null && $objetivo < $ref) $ref = $objetivo;
            elseif ($a['imc'] !== null && $a['imc'] >= 25) $ref = $a['rango_sano'][1];
            $a['calorias'] = [
                'basal' => (int)round($basal / 10) * 10,
                'mantener' => $mantener ? (int)round($mantener / 10) * 10 : null,
                'adelgazar' => $mantener ? (int)round(max($mantener - 500, $minimo) / 10) * 10 : null,
                'minimo' => $minimo,
                'al_minimo' => $mantener !== null && $mantener - 500 < $minimo,
                'proteina' => [(int)round($ref * 1.2), (int)round($ref * 1.6)],
                'peso_ref' => round($ref, 1),
            ];
        }
    }
    if ($a['cintura'] !== null) {
        if ($altura) $a['ica'] = round($a['cintura'] / $altura, 2);
        if ($sexo) {
            [$aumentado, $alto] = $sexo === 'Mujer' ? [80, 88] : [94, 102];
            $a['riesgo_cintura'] = $a['cintura'] >= $alto ? 'alto' : ($a['cintura'] >= $aumentado ? 'aumentado' : 'bajo');
            $a['umbrales_cintura'] = [$aumentado, $alto];
        }
    }
    $a['consejos'] = consejos_peso($a);
    return $a;
}

/**
 * Lo que la app le dice a esa persona con sus números: [[clase, texto], ...]
 * (clase ok | aviso | alto | info). Frases cortas y accionables; nada de
 * diagnósticos: para eso está el médico.
 */
function consejos_peso(array $a): array {
    $c = [];
    if ($a['actual'] === null) return [['info', 'Apunta el primer pesaje para empezar a ver la evolución.']];
    if (!$a['altura']) $c[] = ['info', 'Pon la altura en la ficha para calcular el IMC y el peso sano.'];
    if ($a['menor']) {
        $c[] = ['info', 'En menores de 18 años el IMC de adultos no sirve: se mira con las tablas de percentiles del pediatra. Aquí solo se registra la evolución.'];
        return $c;
    }

    if ($a['imc'] !== null) {
        [$min, $max] = $a['rango_sano'];
        [$cat, $clase] = $a['categoria'];
        $txt = 'IMC ' . numero_es($a['imc']) . ': ' . mb_minusculas_inicial($cat) . '. Para tu altura, el peso sano va de '
             . numero_es($min) . ' a ' . numero_es($max) . ' kg.';
        if ($a['imc'] >= 25) {
            $cinco = round($a['actual'] * 0.05, 1);
            $txt .= ' Bajar solo un 5 % (' . numero_es($cinco) . ' kg) ya mejora la tensión, el azúcar y el colesterol.';
        }
        $c[] = [$clase, $txt];
        if ($a['mayor']) $c[] = ['info', 'Pasados los 65, un IMC entre 22 y 27 se considera adecuado: importa más no perder músculo que bajar de peso.'];
        if ($a['imc'] >= 30) $c[] = ['alto', 'Con un IMC de 30 o más conviene que el plan lo lleve el médico de cabecera (puede derivar a nutrición o endocrino).'];
        if ($a['imc'] < 18.5) $c[] = ['aviso', 'Estás por debajo del peso sano: consúltalo con el médico antes de seguir bajando.'];
    }

    $quiere_bajar = $a['objetivo'] !== null ? $a['falta'] > 0.3 : ($a['imc'] !== null && $a['imc'] >= 25);
    $r = $a['ritmo'];
    if ($r !== null) {
        $semanas = $a['ritmo_dias'] === 28 ? 'las últimas 4 semanas' : 'los últimos 3 meses';
        $ritmo = kg_signo($r, ' kg por semana');
        if ($quiere_bajar) {
            if ($r <= -$a['ritmo_max'] - 0.05) {
                $c[] = ['aviso', "Vas a {$ritmo} en {$semanas}: más rápido de lo recomendable (como mucho " . numero_es($a['ritmo_max'])
                     . ' kg). A ese ritmo se pierde músculo y es más fácil recuperarlo. Come algo más de proteína y no recortes más.'];
            } elseif ($r <= -0.1) {
                $c[] = ['ok', "Buen ritmo: {$ritmo} en {$semanas}. Lo sano es entre 0,5 y " . numero_es($a['ritmo_max']) . ' kg por semana; sigue así.'];
            } elseif ($r < 0.1) {
                $c[] = ['aviso', "Estancado en {$semanas} ({$ritmo}). Las mesetas son normales: revisa raciones, picoteo y bebidas (alcohol incluido) y suma pasos antes de recortar más."];
            } else {
                $c[] = ['aviso', "En {$semanas} vas a {$ritmo}: subiendo. Vuelve a lo básico: plato con media ración de verdura, sin bebidas con azúcar ni alcohol entre semana, y 30 minutos de paseo al día."];
            }
        } elseif ($a['objetivo'] !== null && $a['falta'] < -0.3) {
            $c[] = [$r > 0.05 ? 'ok' : 'info', "Quieres subir de peso y vas a {$ritmo} en {$semanas}. Mejor despacio (0,25-0,5 kg por semana) y con ejercicio de fuerza, para que sea músculo."];
        } else {
            $c[] = [abs($r) < 0.25 ? 'ok' : 'info', "En {$semanas}: {$ritmo}." . (abs($r) < 0.25 ? ' Estable: lo importante ahora es mantener los hábitos.' : '')];
        }
    } elseif (count($a['serie']) < 3) {
        $c[] = ['info', 'Con 3 pesajes en al menos 10 días se calcula el ritmo y cuándo llegarías al objetivo.'];
    }

    if ($a['objetivo'] !== null) {
        $f = $a['falta'];
        if (abs($f) < 0.3) {
            $c[] = ['ok', 'Estás en tu peso objetivo. Ahora toca mantener: sube las calorías poco a poco y sigue pesándote una vez por semana.'];
        } elseif ($a['llegada']) {
            $c[] = ['info', 'Te ' . ($f > 0 ? 'faltan ' : 'quedan por ganar ') . numero_es(abs($f)) . ' kg. Al ritmo de ahora llegarías hacia el '
                 . fecha_es($a['llegada']) . ' (' . relativo(dias_entre(hoy(), $a['llegada'])) . ').'];
        } else {
            $c[] = ['info', 'Te ' . ($f > 0 ? 'faltan ' : 'quedan por ganar ') . numero_es(abs($f)) . ' kg para el objetivo.'];
        }
        if ($a['rango_sano'] && $a['objetivo'] < $a['rango_sano'][0]) {
            $c[] = ['aviso', 'El objetivo queda por debajo del peso sano para tu altura (' . numero_es($a['rango_sano'][0]) . ' kg). Revísalo.'];
        }
    }

    if ($a['riesgo_cintura'] === 'alto') {
        $c[] = ['alto', 'Cintura de ' . numero_es($a['cintura']) . ' cm: la grasa abdominal es la que más riesgo cardiovascular da. Es la medida que más interesa bajar.'];
    } elseif ($a['riesgo_cintura'] === 'aumentado') {
        $c[] = ['aviso', 'Cintura de ' . numero_es($a['cintura']) . ' cm: riesgo algo aumentado (sano, por debajo de ' . $a['umbrales_cintura'][0] . ' cm).'];
    } elseif ($a['cintura'] === null && $a['altura']) {
        $c[] = ['info', 'Mide también la cintura (a la altura del ombligo, sin apretar) una vez al mes: dice más que el IMC sobre el riesgo.'];
    }
    if ($a['fecha'] && dias_entre($a['fecha'], hoy()) > 14) {
        $c[] = ['info', 'El último pesaje es ' . relativo(dias_entre(hoy(), $a['fecha'])) . '. Pesarse una vez por semana ayuda a no perder el hilo.'];
    }
    return $c;
}

// «−2,5 kg», «+0,3 kg»: con el signo menos de verdad, no el guion.
function kg_signo(float $kg, string $unidad = ' kg'): string {
    return ($kg > 0 ? '+' : ($kg < 0 ? '−' : '')) . numero_es(abs($kg)) . $unidad;
}

// Una línea para las tarjetas (sección Salud) y el panel: [[etiqueta, valor], ...].
function resumen_peso(PDO $pdo, array $el): array {
    $serie = [];
    foreach (mediciones_peso($pdo, $el['id']) as $f => $m) if ($m['peso'] !== null) $serie[$f] = $m['peso'];
    if (!$serie) return [];
    $f = array_key_last($serie);
    $out = [['Último peso', numero_es($serie[$f]) . ' kg · ' . fecha_corta($f)]];
    if ($alt = altura_cm($el['datos']['altura'] ?? null)) {
        $i = imc($serie[$f], $alt);
        $out[] = ['IMC', numero_es($i) . ' · ' . categoria_imc($i)[0]];
    }
    return $out;
}

/**
 * Gráfica de la evolución en SVG, pintada en el servidor (la CSP no deja
 * librerías de fuera ni hace falta JS): los pesajes como puntos, la
 * tendencia como línea, la franja del peso sano y el objetivo.
 * $desde = 'AAAA-MM-DD' o null para todo.
 */
function grafica_peso(array $a, ?string $desde = null): string {
    $serie = array_filter($a['serie'], static fn($f) => $desde === null || $f >= $desde, ARRAY_FILTER_USE_KEY);
    if (!$serie) return '';
    $tend = array_intersect_key($a['tendencia'], $serie);
    $W = 640; $H = 250; $iz = 44; $de = 14; $ar = 14; $ab = 28;

    $vals = array_merge(array_values($serie), array_values($tend));
    if ($a['objetivo'] !== null) $vals[] = $a['objetivo'];
    $lo = min($vals); $hi = max($vals);
    if ($hi - $lo < 2) { $m = ($hi + $lo) / 2; $lo = $m - 1; $hi = $m + 1; }
    $margen = ($hi - $lo) * 0.08;
    $lo -= $margen; $hi += $margen;
    $paso = 0.5;
    foreach ([0.5, 1, 2, 5, 10, 20] as $p) { $paso = $p; if (($hi - $lo) / $p <= 6) break; }

    $f0 = array_key_first($serie); $f1 = array_key_last($serie);
    $span = max(1, dias_entre($f0, $f1));
    $x = static fn(string $f) => $f0 === $f1 ? ($iz + $W - $de) / 2 : $iz + dias_entre($f0, $f) / $span * ($W - $iz - $de);
    $y = static fn(float $kg) => $ar + ($hi - $kg) / ($hi - $lo) * ($H - $ar - $ab);
    $n = static fn(float $v) => number_format($v, 1, '.', '');

    $s = '<svg class="grafica-peso" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="'
       . e('Evolución del peso: de ' . numero_es(reset($serie)) . ' a ' . numero_es(end($serie)) . ' kg') . '">';
    // Franja del peso sano (recortada al área de la gráfica).
    if ($a['rango_sano']) {
        [$smin, $smax] = $a['rango_sano'];
        $ya = $y(min($hi, $smax)); $yb = $y(max($lo, $smin));
        if ($yb > $ya) $s .= '<rect class="gp-banda" x="' . $iz . '" y="' . $n($ya) . '" width="' . ($W - $iz - $de) . '" height="' . $n($yb - $ya) . '"/>';
    }
    // Rejilla y eje de kilos.
    for ($v = ceil($lo / $paso) * $paso; $v <= $hi; $v += $paso) {
        $yy = $n($y($v));
        $s .= '<line class="gp-rejilla" x1="' . $iz . '" x2="' . ($W - $de) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text class="gp-eje" x="' . ($iz - 6) . '" y="' . $yy . '" text-anchor="end" dominant-baseline="middle">' . e(numero_es($v)) . '</text>';
    }
    // Eje de fechas: inicios de mes, sin amontonarse.
    $meses = [];
    for ($m = substr($f0, 0, 7) . '-01'; $m <= $f1; $m = sumar_meses($m, 1)) if ($m >= $f0) $meses[] = $m;
    $cada = max(1, (int)ceil(count($meses) / 6));
    $varios_anios = substr($f0, 0, 4) !== substr($f1, 0, 4);
    foreach ($meses as $i => $m) {
        if ($i % $cada) continue;
        [$an, $me] = array_map('intval', explode('-', $m));
        $etq = MESES_CORTOS[$me - 1] . ($varios_anios ? ' ' . substr((string)$an, 2) : '');
        $s .= '<text class="gp-eje" x="' . $n($x($m)) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($etq) . '</text>';
    }
    if (!$meses || $span < 20) {
        $s .= '<text class="gp-eje" x="' . $iz . '" y="' . ($H - 8) . '">' . e(fecha_corta($f0)) . '</text>';
        if ($f0 !== $f1) $s .= '<text class="gp-eje" x="' . ($W - $de) . '" y="' . ($H - 8) . '" text-anchor="end">' . e(fecha_corta($f1)) . '</text>';
    }
    // Objetivo.
    if ($a['objetivo'] !== null && $a['objetivo'] >= $lo && $a['objetivo'] <= $hi) {
        $yo = $n($y($a['objetivo']));
        $s .= '<line class="gp-objetivo" x1="' . $iz . '" x2="' . ($W - $de) . '" y1="' . $yo . '" y2="' . $yo . '"/>'
            . '<text class="gp-eje gp-objetivo-txt" x="' . ($W - $de - 2) . '" y="' . $n($y($a['objetivo']) - 5) . '" text-anchor="end">Objetivo ' . e(numero_es($a['objetivo'])) . '</text>';
    }
    // Pesajes y tendencia.
    foreach ($serie as $f => $kg) {
        $s .= '<circle class="gp-punto" cx="' . $n($x($f)) . '" cy="' . $n($y($kg)) . '" r="' . (count($serie) > 60 ? 2 : 3) . '"><title>'
            . e(fecha_es($f) . ': ' . numero_es($kg) . ' kg') . '</title></circle>';
    }
    if (count($tend) > 1) {
        $pts = [];
        foreach ($tend as $f => $kg) $pts[] = $n($x($f)) . ',' . $n($y($kg));
        $s .= '<polyline class="gp-tendencia" points="' . implode(' ', $pts) . '"/>';
    }
    return $s . '</svg>';
}
