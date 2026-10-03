<?php
// =====================================================================
//  Comunidad de propietarios: el desglose de cada recibo por partidas y el
//  análisis de lo que le cuesta a esta casa cada una (página
//  gasto-comunidad.php).
//
//  Cada liquidación trimestral es un apunte «Recibo» en el historial del
//  elemento contratos/comunidad, con coste = lo que paga esta casa. Sus
//  partidas (tabla `partidas`) dicen de dónde sale ese importe: la piscina,
//  el ascensor, la limpieza… con el total de la comunidad o de la escalera y
//  la parte de esta casa. La parte se calcula con los coeficientes de la
//  ficha (zona «comun» → cuota de participación; «escalera» → cuota en su
//  escalera o zona), salvo que venga dada.
//
//  Se separa lo ordinario de lo extraordinario (obras, reparaciones de una
//  vez) porque si no, un año con una avería parece un año caro para siempre.
// =====================================================================

function categorias_comunidad(): array {
    return ['Piscina', 'Ascensor', 'Limpieza', 'Luz', 'Agua', 'Seguro', 'Administración',
            'Contra incendios', 'Garaje', 'Reparaciones', 'Fondo de reserva', 'Otros'];
}

// zona => [etiqueta, campo de la ficha con el coeficiente]
function zonas_comunidad(): array {
    return ['comun' => ['Zona común', 'cuota_participacion'], 'escalera' => ['Escalera o zona', 'cuota_zona']];
}

/**
 * Sustituye TODAS las partidas de un recibo por las que llegan (así repetir
 * la grabación no duplica nada). $lista = [['concepto', 'categoria', 'zona',
 * 'total', 'parte'?, 'extraordinaria'?], ...]. Devuelve lo que cuadra con el
 * recibo: ['n', 'suma_parte', 'coste_recibo', 'diferencia'].
 */
function guardar_partidas(PDO $pdo, int $registro_id, array $lista, ?int $usuario_id = null): array {
    $st = $pdo->prepare('SELECT r.*, e.seccion, e.tipo AS tipo_elemento, e.nombre AS elemento_nombre, e.datos
                         FROM registros r JOIN elementos e ON e.id = r.elemento_id WHERE r.id = ?');
    $st->execute([$registro_id]);
    $r = $st->fetch();
    if (!$r) throw new ErrorValidacion(['Ese apunte no existe.']);
    if ($r['seccion'] !== 'contratos' || $r['tipo_elemento'] !== 'comunidad') {
        throw new ErrorValidacion(['Las partidas solo van en los recibos de una comunidad de propietarios.']);
    }
    $datos = json_array($r['datos']);

    $filas = [];
    $errores = [];
    foreach (array_values($lista) as $i => $p) {
        $n = $i + 1;
        if (!is_array($p)) { $errores[] = "La partida {$n} no es válida."; continue; }
        $concepto = trim((string)($p['concepto'] ?? ''));
        if ($concepto === '' || longitud($concepto) > 150) $errores[] = "La partida {$n} necesita un concepto (150 caracteres como mucho).";
        $categoria = trim((string)($p['categoria'] ?? ''));
        if (!in_array($categoria, categorias_comunidad(), true)) {
            $errores[] = "Partida {$n}: la categoría tiene que ser una de estas: " . implode(', ', categorias_comunidad()) . '.';
        }
        $total = partida_numero($p['total'] ?? null);
        if ($total === null) $errores[] = "Partida {$n}: el total tiene que ser un número.";
        $zona = trim((string)($p['zona'] ?? ''));
        $parte = partida_numero($p['parte'] ?? null);
        if ($zona !== '' && !isset(zonas_comunidad()[$zona])) {
            $errores[] = "Partida {$n}: la zona tiene que ser «comun» o «escalera».";
        } elseif ($parte === null && $total !== null) {
            $campo = zonas_comunidad()[$zona][1] ?? null;
            $coef = $campo ? ($datos[$campo] ?? null) : null;
            if (!is_numeric($coef)) {
                $errores[] = "Partida {$n}: falta la parte de esta casa, o la zona y su coeficiente en la ficha de la comunidad.";
            } else {
                $parte = round($total * (float)$coef / 100, 2);
            }
        }
        $filas[] = [$concepto, $categoria, $zona, $total, $parte, !empty($p['extraordinaria']) ? 1 : 0];
    }
    if ($errores) throw new ErrorValidacion($errores);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM partidas WHERE registro_id = ?')->execute([$registro_id]);
        $ins = $pdo->prepare('INSERT INTO partidas (registro_id, concepto, categoria, zona, total, parte, extraordinaria) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($filas as $f) $ins->execute(array_merge([$registro_id], $f));
        anotar($pdo, $usuario_id, 'desglosó «' . $r['titulo'] . '» en ' . count($filas) . ' partidas');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $suma = round(array_sum(array_column($filas, 4)), 2);
    $coste = $r['coste'] !== null ? (float)$r['coste'] : null;
    return ['n' => count($filas), 'suma_parte' => $suma, 'coste_recibo' => $coste,
            'diferencia' => $coste !== null ? round($coste - $suma, 2) : null];
}

// Los números de la API llegan como números JSON; los del formulario, como texto español.
function partida_numero($v): ?float {
    if (is_int($v) || is_float($v)) return round((float)$v, 2);
    if ($v === null || trim((string)$v) === '') return null;
    $n = leer_numero((string)$v);
    return $n === null ? null : round($n, 2);
}

function partidas_de_registro(PDO $pdo, int $registro_id): array {
    $st = $pdo->prepare('SELECT * FROM partidas WHERE registro_id = ? ORDER BY id');
    $st->execute([$registro_id]);
    return $st->fetchAll();
}

function elementos_comunidad(PDO $pdo): array {
    return array_values(array_filter(elementos_de($pdo, 'contratos'), static fn($e) => $e['tipo'] === 'comunidad'));
}

/**
 * Todo lo que pinta gasto-comunidad.php para una comunidad:
 *   recibos  [registro_id => ['fecha', 'titulo', 'coste', 'etiqueta' («sep 26»),
 *             'ord', 'extra', 'n_partidas', 'categorias' => [cat => importe]]]  (del más antiguo al más reciente)
 *   anios    [año => ['pagado' (suma de los recibos), 'n', 'sin_desglose', 'ord', 'extra',
 *             'media_ordinaria' (por recibo desglosado), 'categorias' => [cat => ['ord', 'extra', 'total']]]]
 *             (el año más reciente primero; categorías de la más cara a la más barata)
 *   categorias  las que aparecen alguna vez, de la más cara a la más barata en total
 * El año es el de la fecha del recibo (la de la liquidación).
 */
function analisis_comunidad(PDO $pdo, int $elemento_id): array {
    $st = $pdo->prepare("SELECT id, fecha, titulo, coste FROM registros WHERE elemento_id = ? AND tipo = 'Recibo' ORDER BY fecha, id");
    $st->execute([$elemento_id]);
    $recibos = [];
    foreach ($st as $r) {
        $mes = (int)substr($r['fecha'], 5, 2);
        $recibos[(int)$r['id']] = [
            'fecha' => $r['fecha'], 'titulo' => $r['titulo'], 'coste' => $r['coste'] !== null ? (float)$r['coste'] : 0.0,
            'etiqueta' => MESES_CORTOS[$mes - 1] . ' ' . substr($r['fecha'], 2, 2),
            'ord' => 0.0, 'extra' => 0.0, 'n_partidas' => 0, 'categorias' => [],
        ];
    }

    $st = $pdo->prepare("SELECT p.registro_id, p.categoria, p.parte, p.extraordinaria FROM partidas p
                         JOIN registros r ON r.id = p.registro_id WHERE r.elemento_id = ? AND r.tipo = 'Recibo'");
    $st->execute([$elemento_id]);
    $anios = [];
    $total_cat = [];
    foreach ($st as $p) {
        $rid = (int)$p['registro_id'];
        $parte = (float)$p['parte'];
        $clase = (int)$p['extraordinaria'] ? 'extra' : 'ord';
        $rec = &$recibos[$rid];
        $rec[$clase] += $parte;
        $rec['n_partidas']++;
        $rec['categorias'][$p['categoria']] = ($rec['categorias'][$p['categoria']] ?? 0.0) + $parte;
        $c = &$anios[(int)substr($rec['fecha'], 0, 4)]['categorias'][$p['categoria']];
        $c ??= ['ord' => 0.0, 'extra' => 0.0, 'total' => 0.0];
        $c[$clase] += $parte;
        $c['total'] += $parte;
        $total_cat[$p['categoria']] = ($total_cat[$p['categoria']] ?? 0.0) + $parte;
        unset($rec, $c);
    }

    foreach ($recibos as $rec) {
        $a = &$anios[(int)substr($rec['fecha'], 0, 4)];
        $a['categorias'] ??= [];
        $a['pagado'] = ($a['pagado'] ?? 0.0) + $rec['coste'];
        $a['n'] = ($a['n'] ?? 0) + 1;
        $a['desglosados'] = ($a['desglosados'] ?? 0) + ($rec['n_partidas'] > 0 ? 1 : 0);
        $a['ord'] = ($a['ord'] ?? 0.0) + $rec['ord'];
        $a['extra'] = ($a['extra'] ?? 0.0) + $rec['extra'];
        unset($a);
    }
    foreach ($anios as &$a) {
        $a['sin_desglose'] = $a['n'] - $a['desglosados'];
        $a['media_ordinaria'] = $a['desglosados'] ? round($a['ord'] / $a['desglosados'], 2) : 0.0;
        foreach (['pagado', 'ord', 'extra'] as $k) $a[$k] = round($a[$k], 2);
        foreach ($a['categorias'] as &$c) foreach ($c as &$v) $v = round($v, 2);
        unset($c, $v);
        uasort($a['categorias'], static fn($x, $y) => $y['total'] <=> $x['total']);
    }
    unset($a);
    krsort($anios);
    arsort($total_cat);
    foreach ($recibos as &$rec) { $rec['ord'] = round($rec['ord'], 2); $rec['extra'] = round($rec['extra'], 2); }
    unset($rec);
    return ['recibos' => $recibos, 'anios' => $anios, 'categorias' => array_keys($total_cat)];
}
