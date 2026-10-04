<?php
// =====================================================================
//  Historial de cada elemento: mantenimientos, reparaciones, consultas,
//  mediciones... Con coste opcional, para saber cuánto se lleva cada cosa
//  (y, el día que finanzas viva dentro, cruzarlo con los movimientos).
// =====================================================================

function validar_registro(PDO $pdo, array $r): array {
    $errores = [];
    $el = elemento($pdo, (int)($r['elemento_id'] ?? 0));
    if (!$el) return [null, ['Ese elemento no existe.']];
    $conf = seccion($el['seccion'])['registros'] ?? null;
    if (!$conf) return [null, ['Esta sección no lleva historial.']];

    $fecha = leer_fecha($r['fecha'] ?? '') ?? (trim((string)($r['fecha'] ?? '')) === '' ? hoy() : null);
    if (!$fecha) $errores[] = 'Pon una fecha válida.';

    $tipo = trim((string)($r['tipo'] ?? ''));
    if ($tipo !== '' && !in_array($tipo, $conf['tipos'], true)) {
        $errores[] = 'El tipo tiene que ser uno de estos: ' . implode(', ', $conf['tipos']) . '.';
    }
    $titulo = trim((string)($r['titulo'] ?? ''));
    if ($titulo === '') $titulo = $tipo;
    if ($titulo === '') $errores[] = 'Escribe qué se hizo.';
    elseif (longitud($titulo) > 150) $errores[] = 'El título es demasiado largo.';

    $valor = null;
    if (trim((string)($r['valor'] ?? '')) !== '') {
        $valor = leer_numero($r['valor']);
        if ($valor === null) $errores[] = '«' . ($conf['valor'] ?? 'Valor') . '» tiene que ser un número.';
    }
    $unidad = trim((string)($r['unidad'] ?? ''));
    if ($unidad === '') $unidad = (string)($conf['unidades'][$tipo] ?? $conf['unidad'] ?? '');
    if (longitud($unidad) > 15) $errores[] = 'La unidad es demasiado larga.';

    $coste = null;
    if (trim((string)($r['coste'] ?? '')) !== '') {
        $coste = leer_numero($r['coste']);
        if ($coste === null) $errores[] = 'El coste tiene que ser un número.';
    }
    $notas = trim((string)($r['notas'] ?? ''));
    return [['elemento' => $el, 'conf' => $conf, 'fecha' => $fecha, 'tipo' => $tipo, 'titulo' => $titulo,
             'valor' => $valor, 'unidad' => $valor === null ? '' : $unidad, 'coste' => $coste, 'notas' => $notas], $errores];
}

function crear_registro(PDO $pdo, array $r, ?int $usuario_id = null): int {
    [$f, $errores] = validar_registro($pdo, $r);
    if ($errores) throw new ErrorValidacion($errores);
    $el = $f['elemento'];
    $pdo->prepare('INSERT INTO registros (elemento_id, fecha, tipo, titulo, valor, unidad, coste, notas, creado_por, creado_en)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$el['id'], $f['fecha'], $f['tipo'], $f['titulo'], $f['valor'], $f['unidad'], $f['coste'],
                   $f['notas'], $usuario_id, ahora()]);
    $id = (int)$pdo->lastInsertId();

    // Los kilómetros del coche se ponen al día solos con cada apunte que
    // traiga más de los que había (nunca hacia atrás: un apunte viejo
    // metido tarde no debe «quitar» kilómetros).
    $campo = $f['conf']['actualiza'] ?? null;
    if ($campo && $f['valor'] !== null && $f['valor'] > (float)($el['datos'][$campo] ?? 0)) {
        cambiar_dato_elemento($pdo, $el['id'], $campo, $f['valor']);
    }
    anotar($pdo, $usuario_id, "apuntó «{$f['titulo']}» en «{$el['nombre']}»");
    return $id;
}

function registros_de(PDO $pdo, int $elemento_id, int $n = 100): array {
    $st = $pdo->prepare('SELECT r.*, u.nombre AS autor FROM registros r LEFT JOIN usuarios u ON u.id = r.creado_por
                         WHERE r.elemento_id = ? ORDER BY r.fecha DESC, r.id DESC LIMIT ' . max(1, $n));
    $st->execute([$elemento_id]);
    return $st->fetchAll();
}

function borrar_registro(PDO $pdo, int $id, int $elemento_id, ?int $usuario_id = null): void {
    $st = $pdo->prepare('SELECT titulo FROM registros WHERE id = ? AND elemento_id = ?');
    $st->execute([$id, $elemento_id]);
    $titulo = $st->fetchColumn();
    if ($titulo === false) return;
    $pdo->prepare('DELETE FROM partidas WHERE registro_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM registros WHERE id = ?')->execute([$id]);
    anotar($pdo, $usuario_id, "borró el apunte «{$titulo}»");
}

/**
 * Lo que dice el historial de cada elemento para los gastos fijos, en una
 * sola consulta: [elemento_id => ['ultimo' => fecha del último cargo
 * («Factura» o «Recibo» con coste: de ahí sale cuándo toca el siguiente),
 * 'n', 'total', 'max', 'min', 'fechas' => los cargos de los últimos 12 meses,
 * 'otros' => lo demás gastado en 12 meses (taller, ITV, reparaciones…; sin
 * «Compra o venta», que no es mantener), 'cargos' =>
 * [[fecha, coste], …] de los últimos 24 meses (para comparar con el año anterior)]].
 */
function historial_de_gastos(PDO $pdo, string $hoy): array {
    $desde = sumar_meses($hoy, -12);
    $desde24 = substr(sumar_meses($hoy, -23), 0, 7) . '-01';   // 24 meses enteros, con el actual
    $out = [];
    foreach ($pdo->query('SELECT elemento_id, fecha, tipo, coste FROM registros WHERE coste IS NOT NULL ORDER BY fecha, id') as $f) {
        $o = &$out[(int)$f['elemento_id']];
        $o ??= ['ultimo' => null, 'n' => 0, 'total' => 0.0, 'max' => null, 'min' => null, 'fechas' => [], 'otros' => 0.0, 'cargos' => []];
        $c = (float)$f['coste'];
        $cargo = in_array($f['tipo'], ['Factura', 'Recibo'], true);
        if ($cargo) $o['ultimo'] = $f['fecha'];
        if ($cargo && $f['fecha'] >= $desde24 && $f['fecha'] <= $hoy) $o['cargos'][] = [$f['fecha'], $c];
        if ($f['fecha'] > $desde && $f['fecha'] <= $hoy) {
            if ($cargo) {
                $o['n']++;
                $o['total'] += $c;
                $o['max'] = max($o['max'] ?? $c, $c);
                $o['min'] = min($o['min'] ?? $c, $c);
                $o['fechas'][] = $f['fecha'];
            } elseif ($f['tipo'] !== 'Compra o venta') {
                $o['otros'] += $c;
            }
        }
        unset($o);
    }
    return $out;
}

// Lo gastado en los últimos 12 meses (suma de costes del historial).
function gasto_ultimo_ano(PDO $pdo, int $elemento_id): float {
    $st = $pdo->prepare('SELECT COALESCE(SUM(coste), 0) FROM registros WHERE elemento_id = ? AND fecha > ?');
    $st->execute([$elemento_id, sumar_meses(hoy(), -12)]);
    return (float)$st->fetchColumn();
}
