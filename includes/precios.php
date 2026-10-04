<?php
// =====================================================================
//  Historial de precios de los contratos (tabla precios, migración 006).
//
//  La ficha guarda el coste de hoy; para comparar con hace un año hace falta
//  saber cuál regía entonces. Se apunta solo cuando cambia el coste de la
//  ficha (y, si no había historial, también el anterior, desde que se creó
//  la ficha: así no se pierde) y, hacia atrás, con la acción «precio» de la
//  API (las primas de años anteriores que vienen en las notas, la renta del
//  garaje que sale en finanzas…). Gonzalo, 4/10/2026.
// =====================================================================

/** Apunta (o sustituye, si ya hay uno ese día) el precio de un elemento desde una fecha. */
function guardar_precio(PDO $pdo, int $elemento_id, string $desde, $coste, ?string $periodicidad = null, string $nota = '', ?int $usuario_id = null): int {
    $el = elemento($pdo, $elemento_id);
    $errores = [];
    if (!$el) throw new ErrorValidacion(['Ese elemento no existe.']);
    if (!isset(tipo_def($el['seccion'], $el['tipo'])['campos']['coste'])) $errores[] = 'Ese tipo de elemento no lleva coste.';
    $desde = leer_fecha($desde);
    if (!$desde) $errores[] = '«desde» tiene que ser una fecha (AAAA-MM-DD).';
    $c = is_numeric($coste) ? (float)$coste : leer_numero($coste);
    if ($c === null || $c < 0) $errores[] = 'El coste tiene que ser un número.';
    $periodicidad ??= (string)($el['datos']['periodicidad'] ?? '');
    if (!isset(periodicidades()[$periodicidad])) $errores[] = 'La periodicidad tiene que ser una de estas: ' . implode(', ', array_keys(periodicidades())) . '.';
    if (longitud($nota) > 200) $errores[] = 'La nota es demasiado larga (200 caracteres como mucho).';
    if ($errores) throw new ErrorValidacion($errores);

    $pdo->prepare('DELETE FROM precios WHERE elemento_id = ? AND desde = ?')->execute([$elemento_id, $desde]);
    $pdo->prepare('INSERT INTO precios (elemento_id, desde, coste, periodicidad, nota, creado_en) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$elemento_id, $desde, round($c, 2), $periodicidad, trim($nota), ahora()]);
    $id = (int)$pdo->lastInsertId();
    anotar($pdo, $usuario_id, 'apuntó el precio de «' . $el['nombre'] . '» desde el ' . fecha_es($desde) . ': ' . eur($c));
    return $id;
}

/**
 * Al guardar una ficha: si el coste o la periodicidad cambian, queda el
 * precio nuevo desde hoy; si no había historial, también el de antes, desde
 * el día en que se creó la ficha (lo más que se sabe de él).
 */
function registrar_cambio_precio(PDO $pdo, int $id, ?array $antes, array $datos): void {
    $nuevo = isset($datos['coste']) && is_numeric($datos['coste']) ? round((float)$datos['coste'], 2) : null;
    $per = (string)($datos['periodicidad'] ?? '');
    if ($nuevo === null || !isset(periodicidades()[$per])) return;
    $viejo = $antes && isset($antes['datos']['coste']) && is_numeric($antes['datos']['coste']) ? round((float)$antes['datos']['coste'], 2) : null;
    $per_viejo = (string)($antes['datos']['periodicidad'] ?? '');
    if ($antes && $viejo === $nuevo && $per_viejo === $per) return;
    $hay = $pdo->prepare('SELECT COUNT(*) FROM precios WHERE elemento_id = ?');
    $hay->execute([$id]);
    $ins = $pdo->prepare('INSERT INTO precios (elemento_id, desde, coste, periodicidad, nota, creado_en) VALUES (?, ?, ?, ?, ?, ?)');
    if ($antes && $viejo !== null && isset(periodicidades()[$per_viejo]) && (int)$hay->fetchColumn() === 0) {
        $creado = substr((string)$antes['creado_en'], 0, 10);
        if ($creado < hoy()) $ins->execute([$id, $creado, $viejo, $per_viejo, 'el de la ficha al crearla', ahora()]);
    }
    $pdo->prepare('DELETE FROM precios WHERE elemento_id = ? AND desde = ?')->execute([$id, hoy()]);
    $ins->execute([$id, hoy(), $nuevo, $per, '', ahora()]);
}

/** Todo el historial: [elemento_id => [['desde', 'coste', 'periodicidad', 'nota'], …]] de más viejo a más nuevo. */
function precios_por_elemento(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT elemento_id, desde, coste, periodicidad, nota FROM precios ORDER BY desde, id') as $f) {
        $out[(int)$f['elemento_id']][] = ['desde' => $f['desde'], 'coste' => (float)$f['coste'], 'periodicidad' => $f['periodicidad'], 'nota' => $f['nota']];
    }
    return $out;
}

/** El precio que regía en una fecha (el último que empezó antes o ese día), o null si no se sabe. */
function precio_en(array $historial, string $fecha): ?array {
    $r = null;
    foreach ($historial as $p) if ($p['desde'] <= $fecha) $r = $p;
    return $r;
}
