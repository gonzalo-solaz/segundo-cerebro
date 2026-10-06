<?php
// =====================================================================
//  Trabajo: el panel (trabajo.php), Mi puesto (la ficha del empleo), el
//  Equipo (las fichas «Persona del equipo») y las Compras del servicio. Gonzalo, 6/10/2026: «el cerebro
//  de todo lo relacionado con el trabajo»; la gestión del servicio sigue en
//  las herramientas de la empresa, aquí va lo que hay que tener controlado.
//  Las lecturas pasan por elementos_de() (el filtro de privacidad, si un día
//  hace falta, va allí).
// =====================================================================

// El empleo de quien mira (su persona) o, si no hay, el primero activo.
function mi_empleo(PDO $pdo, ?int $persona_id): ?array {
    $empleos = array_values(array_filter(elementos_de($pdo, 'trabajo'), static fn($e) => $e['tipo'] === 'empleo'));
    foreach ($empleos as $e) if ($persona_id && $e['persona_id'] === $persona_id) return $e;
    return $empleos[0] ?? null;
}

// Las personas del equipo, por nombre. Con $activos = false, las que ya no están (archivadas).
function equipo_trabajo(PDO $pdo, bool $activos = true): array {
    return array_values(array_filter(elementos_de($pdo, 'trabajo', $activos), static fn($e) => $e['tipo'] === 'miembro'));
}

// Las compras y licencias del servicio, por nombre. Con $activas = false, las canceladas (archivadas).
function compras_trabajo(PDO $pdo, bool $activas = true): array {
    return array_values(array_filter(elementos_de($pdo, 'trabajo', $activas), static fn($e) => $e['tipo'] === 'compra'));
}

// Lo que cuesta al año una compra que se repite; null si se paga una vez o falta el importe o la periodicidad.
function importe_anual(array $datos): ?float {
    $meses = periodicidades()[$datos['periodicidad'] ?? ''] ?? 0;
    if ($meses <= 0 || !isset($datos['importe']) || !is_numeric($datos['importe'])) return null;
    return round((float)$datos['importe'] * 12 / $meses, 2);
}

/**
 * Lo que dice el horario (campo en formato lista, una línea por día) del día
 * de $fecha: «Lunes: 8:00-14:00 y 15:00-17:30» → «8:00-14:00 y 15:00-17:30».
 * null si ese día no tiene línea (fin de semana, o un horario escrito de otra forma).
 */
function horario_de_hoy(string $horario, string $fecha): ?string {
    $dia = sin_tildes(DIAS_SEMANA[(int)(new DateTimeImmutable($fecha))->format('w')]);
    foreach (preg_split('/\R/u', $horario) as $linea) {
        if (preg_match('/^\s*([\p{L}]+)\s*:\s*(.+?)\s*$/u', $linea, $m) && sin_tildes($m[1]) === $dia) return $m[2];
    }
    return null;
}

// De la entrada a la salida, para una cifra: «8:30-14:00 y 15:00-17:15» → «8:30 – 17:15».
function horario_franja(?string $horario_dia): ?string {
    if ($horario_dia === null || !preg_match_all('/\b\d{1,2}[:.]\d{2}\b/', $horario_dia, $m) || count($m[0]) < 2) return $horario_dia;
    return str_replace('.', ':', $m[0][0]) . ' – ' . str_replace('.', ':', end($m[0]));
}

function sin_tildes(string $s): string {
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
}

// «21 años», «1 año y 8 meses», «8 meses», «menos de un mes». '' sin fecha o si es futura.
function tiempo_desde(?string $desde, string $hoy): string {
    if (!$desde || $desde > $hoy) return '';
    $d = (new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hoy));
    $a = $d->y === 1 ? '1 año' : $d->y . ' años';
    $m = $d->m === 1 ? '1 mes' : $d->m . ' meses';
    if ($d->y >= 5 || ($d->y > 0 && $d->m === 0)) return $a;
    if ($d->y > 0) return $a . ' y ' . $m;
    return $d->m > 0 ? $m : 'menos de un mes';
}

// Las pestañas de Trabajo: Panel · Mi puesto · Equipo · Compras. Salen en trabajo.php y en las fichas de la sección.
function pestanas_trabajo(string $activa, ?array $empleo, int $n_equipo, int $n_compras = 0): void {
    $mi_puesto = $empleo ? 'elemento.php?id=' . $empleo['id'] : 'elemento-editar.php?s=trabajo&t=empleo';
    $p = ['panel' => ['trabajo.php', 'Panel'], 'puesto' => [$mi_puesto, 'Mi puesto'],
          'equipo' => ['trabajo.php?p=equipo', 'Equipo' . ($n_equipo ? ' · ' . $n_equipo : '')],
          'compras' => ['trabajo.php?p=compras', 'Compras' . ($n_compras ? ' · ' . $n_compras : '')]];
    echo '<nav class="filtros pestanas" aria-label="Trabajo">';
    foreach ($p as $k => [$href, $txt]) {
        echo '<a class="chip chip-boton' . ($k === $activa ? ' chip-activo' : '') . '" href="' . e(url($href)) . '"'
           . ($k === $activa ? ' aria-current="page"' : '') . '>' . e($txt) . '</a>';
    }
    echo '</nav>';
}
