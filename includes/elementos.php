<?php
// =====================================================================
//  Elementos: las «cosas» que se controlan (una casa, un coche, un DNI...).
//
//  TODA lectura de elementos pasa por estas funciones. Hoy toda la familia
//  ve todo; si mañana hace falta algo privado (la salud de un adulto, por
//  ejemplo), el filtro se pone AQUÍ, en un solo sitio, y no en cada página
//  (la lección de van4ever: el día que se olvida un WHERE, alguien ve lo
//  que no debía).
//
//  Guardar un elemento también mantiene sus avisos: cada campo de fecha con
//  «vence» en secciones.php tiene su vencimiento pendiente, que se crea, se
//  mueve o se borra solo al cambiar la fecha.
// =====================================================================

const SQL_ELEMENTO = 'SELECT e.*, p.nombre AS persona_nombre, p.color AS persona_color, t.nombre AS enlace_nombre
                      FROM elementos e LEFT JOIN personas p ON p.id = e.persona_id
                      LEFT JOIN elementos t ON t.id = e.enlace_id';

function decodificar_elemento(array $f): array {
    $f['id'] = (int)$f['id'];
    $f['activo'] = (int)$f['activo'];
    $f['persona_id'] = $f['persona_id'] !== null ? (int)$f['persona_id'] : null;
    $f['enlace_id'] = ($f['enlace_id'] ?? null) !== null ? (int)$f['enlace_id'] : null;
    $f['datos'] = json_array($f['datos']);
    return $f;
}

function elementos_de(PDO $pdo, string $seccion, bool $activos = true): array {
    $st = $pdo->prepare(SQL_ELEMENTO . ' WHERE e.seccion = ? AND e.activo = ? ORDER BY e.tipo, e.nombre');
    $st->execute([$seccion, $activos ? 1 : 0]);
    return array_map('decodificar_elemento', $st->fetchAll());
}

function elemento(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare(SQL_ELEMENTO . ' WHERE e.id = ?');
    $st->execute([$id]);
    $f = $st->fetch();
    return $f ? decodificar_elemento($f) : null;
}

function elementos_de_persona(PDO $pdo, int $persona_id): array {
    $st = $pdo->prepare(SQL_ELEMENTO . ' WHERE e.persona_id = ? AND e.activo = 1 ORDER BY e.seccion, e.nombre');
    $st->execute([$persona_id]);
    return array_map('decodificar_elemento', $st->fetchAll());
}

// Los elementos que pertenecen a otro (los contratos de una vivienda, el
// seguro de un vehículo), en el orden en que se enseñan en su ficha.
function elementos_enlazados(PDO $pdo, int $id): array {
    $st = $pdo->prepare(SQL_ELEMENTO . ' WHERE e.enlace_id = ? AND e.activo = 1 ORDER BY e.seccion, e.tipo, e.nombre');
    $st->execute([$id]);
    return array_map('decodificar_elemento', $st->fetchAll());
}

// Cuántos elementos cuelgan de cada uno y cuánto cuestan al mes:
// [id_padre => ['n' => 5, 'mensual' => 13.54]]. Una sola consulta para
// pintar el listado de una sección.
function resumen_enlazados(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT enlace_id, datos FROM elementos WHERE activo = 1 AND enlace_id IS NOT NULL') as $f) {
        $k = (int)$f['enlace_id'];
        $out[$k] = $out[$k] ?? ['n' => 0, 'mensual' => 0.0];
        $out[$k]['n']++;
        $out[$k]['mensual'] += coste_mensual(json_array($f['datos']));
    }
    return $out;
}

// Los tipos que pueden colgar de un elemento de esta sección y tipo:
// [[seccion, tipo, nombre del tipo], ...]. Vacío si nada puede enlazarse a él.
function tipos_que_enlazan(string $seccion, string $tipo): array {
    $out = [];
    foreach (secciones() as $clave => $s) {
        foreach ($s['tipos'] as $t => $def) {
            foreach ($def['enlace']['a'] ?? [] as [$sd, $td]) {
                if ($sd === $seccion && $td === $tipo) $out[] = [$clave, $t, $def['nombre']];
            }
        }
    }
    return $out;
}

// Elementos a los que se puede enlazar uno de este tipo: [id => nombre].
function candidatos_enlace(PDO $pdo, array $def): array {
    $out = [];
    foreach ($def['enlace']['a'] ?? [] as [$seccion, $tipo]) {
        foreach (elementos_de($pdo, $seccion) as $el) {
            if ($el['tipo'] === $tipo) $out[$el['id']] = $el['nombre'];
        }
    }
    return $out;
}

// Búsqueda para la API (y, si un día hace falta, para un buscador).
function buscar_elementos(PDO $pdo, ?string $seccion = null, string $texto = '', bool $archivados = false): array {
    $sql = SQL_ELEMENTO . ' WHERE e.activo = ?';
    $p = [$archivados ? 0 : 1];
    if ($seccion) { $sql .= ' AND e.seccion = ?'; $p[] = $seccion; }
    if ($texto !== '') { $sql .= ' AND e.nombre LIKE ?'; $p[] = '%' . $texto . '%'; }
    $st = $pdo->prepare($sql . ' ORDER BY e.seccion, e.nombre');
    $st->execute($p);
    return array_map('decodificar_elemento', $st->fetchAll());
}

/**
 * Valida y normaliza lo que llega del formulario o de la API.
 * $entrada = ['nombre', 'persona_id', 'notas', 'datos' => [clave => valor]].
 * Devuelve [fila limpia, errores].
 */
function validar_elemento(PDO $pdo, string $seccion, string $tipo, array $entrada): array {
    $def = tipo_def($seccion, $tipo);
    if (!$def) return [null, ["No existe el tipo «{$tipo}» en la sección «{$seccion}»."]];
    $errores = [];

    // ---- Persona ----
    $persona_id = (int)($entrada['persona_id'] ?? 0) ?: null;
    $persona_nombre = null;
    if (empty($def['persona'])) {
        $persona_id = null;
    } elseif ($persona_id) {
        $p = persona($pdo, $persona_id);
        if (!$p) { $errores[] = 'Esa persona no existe.'; $persona_id = null; }
        else $persona_nombre = $p['nombre'];
    }
    if (($def['persona'] ?? null) === 'obligatoria' && !$persona_id && !$errores) {
        $errores[] = 'Elige ' . mb_minusculas_inicial($def['persona_etiqueta'] ?? 'la persona') . '.';
    }

    // ---- Enlace (a qué vivienda o vehículo pertenece) ----
    $enlace_id = (int)($entrada['enlace_id'] ?? 0) ?: null;
    if (empty($def['enlace'])) {
        $enlace_id = null;
    } elseif ($enlace_id) {
        $destino = elemento($pdo, $enlace_id);
        $valido = false;
        foreach ($def['enlace']['a'] as [$seccion_destino, $tipo_destino]) {
            if ($destino && $destino['seccion'] === $seccion_destino && $destino['tipo'] === $tipo_destino) $valido = true;
        }
        if (!$valido) {
            $errores[] = '«' . $def['enlace']['etiqueta'] . '» tiene que ser uno de los que ya existen en la app.';
            $enlace_id = null;
        }
    }

    // ---- Nombre ----
    $nombre = trim((string)($entrada['nombre'] ?? ''));
    if ($nombre === '' && !empty($def['nombre_auto']) && $persona_nombre) {
        $nombre = str_replace('{persona}', $persona_nombre, $def['nombre_auto']);
    }
    if ($nombre === '') $errores[] = 'Ponle un nombre.';
    elseif (longitud($nombre) > 150) $errores[] = 'El nombre es demasiado largo (150 caracteres como mucho).';

    // ---- Campos ----
    $datos = [];
    $entrada_datos = $entrada['datos'] ?? [];
    if (!is_array($entrada_datos)) $entrada_datos = [];
    foreach (array_keys($entrada_datos) as $clave) {
        if (!isset($def['campos'][$clave])) {
            $errores[] = "Campo desconocido «{$clave}» (los de «{$def['nombre']}» son: " . implode(', ', array_keys($def['campos'])) . ').';
        }
    }
    foreach ($def['campos'] as $clave => $c) {
        $crudo = $entrada_datos[$clave] ?? null;
        if ($crudo === null || is_array($crudo)) continue;
        $crudo = trim((string)$crudo);
        if ($crudo === '') continue;
        $etq = $c['etiqueta'];
        switch ($c['tipo']) {
            case 'fecha':
                $f = leer_fecha($crudo);
                if ($f === null) $errores[] = "«{$etq}» no es una fecha válida.";
                else $datos[$clave] = $f;
                break;
            case 'numero':
            case 'importe':
                $n = leer_numero($crudo);
                if ($n === null) $errores[] = "«{$etq}» tiene que ser un número.";
                else $datos[$clave] = $c['tipo'] === 'importe' ? round($n, 2) : $n;
                break;
            case 'opcion':
                if (!in_array($crudo, $c['opciones'], true)) {
                    $errores[] = "«{$etq}» tiene que ser una de estas: " . implode(', ', $c['opciones']) . '.';
                } else {
                    $datos[$clave] = $crudo;
                }
                break;
            case 'email':
                if (!filter_var($crudo, FILTER_VALIDATE_EMAIL)) $errores[] = "«{$etq}» no es un email válido.";
                else $datos[$clave] = $crudo;
                break;
            case 'area':
                if (longitud($crudo) > 5000) $errores[] = "«{$etq}» es demasiado largo.";
                else $datos[$clave] = $crudo;
                break;
            default:
                if (longitud($crudo) > 255) $errores[] = "«{$etq}» es demasiado largo (255 caracteres como mucho).";
                else $datos[$clave] = $crudo;
        }
    }

    $notas = trim((string)($entrada['notas'] ?? ''));
    if (longitud($notas) > 10000) $errores[] = 'Las notas son demasiado largas.';

    return [[
        'seccion' => $seccion, 'tipo' => $tipo, 'nombre' => $nombre,
        'persona_id' => $persona_id, 'enlace_id' => $enlace_id, 'datos' => $datos, 'notas' => $notas,
    ], $errores];
}

function mb_minusculas_inicial(string $s): string {
    if (!preg_match('/^(.)(.*)$/us', $s, $m)) return $s;
    $primera = function_exists('mb_strtolower') ? mb_strtolower($m[1], 'UTF-8') : strtolower($m[1]);
    return $primera . $m[2];
}

/**
 * Crea ($id = null) o actualiza un elemento y sincroniza sus avisos.
 * Lanza ErrorValidacion con la lista de problemas. Devuelve el id.
 */
function guardar_elemento(PDO $pdo, string $seccion, string $tipo, array $entrada, ?int $id = null, ?int $usuario_id = null): int {
    if ($id) {
        $actual = elemento($pdo, $id);
        if (!$actual) throw new RuntimeException('Ese elemento no existe.');
        if ($actual['seccion'] !== $seccion || $actual['tipo'] !== $tipo) {
            throw new RuntimeException('No se puede cambiar la sección o el tipo de un elemento ya creado.');
        }
    }
    [$f, $errores] = validar_elemento($pdo, $seccion, $tipo, $entrada);
    if ($errores) throw new ErrorValidacion($errores);

    $pdo->beginTransaction();
    try {
        if ($id) {
            $pdo->prepare('UPDATE elementos SET nombre = ?, persona_id = ?, enlace_id = ?, datos = ?, notas = ?, actualizado_en = ? WHERE id = ?')
                ->execute([$f['nombre'], $f['persona_id'], $f['enlace_id'], json_texto($f['datos']), $f['notas'], ahora(), $id]);
            $verbo = 'actualizó';
        } else {
            $pdo->prepare('INSERT INTO elementos (seccion, tipo, nombre, persona_id, enlace_id, datos, notas, activo, creado_en, actualizado_en)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')
                ->execute([$seccion, $tipo, $f['nombre'], $f['persona_id'], $f['enlace_id'], json_texto($f['datos']), $f['notas'], ahora(), ahora()]);
            $id = (int)$pdo->lastInsertId();
            $verbo = 'añadió';
        }
        sincronizar_vencimientos_campos($pdo, $id, $seccion, $tipo, $f['nombre'], $f['datos']);
        anotar($pdo, $usuario_id, "{$verbo} «{$f['nombre']}» en " . seccion($seccion)['nombre']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

function titulo_vencimiento(string $plantilla, string $nombre): string {
    return str_contains($plantilla, '{nombre}') ? str_replace('{nombre}', $nombre, $plantilla) : $plantilla . ' · ' . $nombre;
}

function repetir_de_campo(array $campo, array $datos): int {
    $r = $campo['repetir'] ?? 0;
    if ($r === 'periodicidad') return periodicidades()[$datos['periodicidad'] ?? ''] ?? 0;
    return (int)$r;
}

/**
 * Cada campo de fecha con «vence» tiene, como mucho, UN aviso pendiente
 * (origen = 'campo:<clave>'). Si la fecha cambia, se mueve; si se vacía, se
 * borra; si ya se marcó hecho para esa misma fecha, no se vuelve a crear.
 */
function sincronizar_vencimientos_campos(PDO $pdo, int $id, string $seccion, string $tipo, string $nombre, array $datos): void {
    $def = tipo_def($seccion, $tipo);
    foreach ($def['campos'] as $clave => $c) {
        if ($c['tipo'] !== 'fecha' || empty($c['vence'])) continue;
        $origen = 'campo:' . $clave;
        $fecha = $datos[$clave] ?? null;
        $titulo = recortar(titulo_vencimiento($c['vence'], $nombre), 150);
        $aviso = (int)($c['aviso'] ?? 30);
        $repetir = repetir_de_campo($c, $datos);

        $st = $pdo->prepare("SELECT id FROM vencimientos WHERE elemento_id = ? AND origen = ? AND estado = 'pendiente'");
        $st->execute([$id, $origen]);
        $pendiente = $st->fetchColumn();

        if (!$fecha) {
            if ($pendiente) $pdo->prepare('DELETE FROM vencimientos WHERE id = ?')->execute([$pendiente]);
            continue;
        }
        if ($pendiente) {
            $pdo->prepare('UPDATE vencimientos SET titulo = ?, fecha = ?, aviso_dias = ?, repetir_meses = ? WHERE id = ?')
                ->execute([$titulo, $fecha, $aviso, $repetir, $pendiente]);
            continue;
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM vencimientos WHERE elemento_id = ? AND origen = ? AND estado = 'hecho' AND fecha = ?");
        $st->execute([$id, $origen, $fecha]);
        if ((int)$st->fetchColumn() > 0) continue;

        $pdo->prepare('INSERT INTO vencimientos (seccion, elemento_id, titulo, fecha, aviso_dias, repetir_meses, origen, estado, creado_en)
                       VALUES (?, ?, ?, ?, ?, ?, ?, \'pendiente\', ?)')
            ->execute([$seccion, $id, $titulo, $fecha, $aviso, $repetir, $origen, ahora()]);
    }
}

// Cambia UN campo del JSON de datos (lo usan los avisos y el historial).
function cambiar_dato_elemento(PDO $pdo, int $id, string $clave, $valor): void {
    $el = elemento($pdo, $id);
    if (!$el) return;
    $datos = $el['datos'];
    if ($valor === null) unset($datos[$clave]); else $datos[$clave] = $valor;
    $pdo->prepare('UPDATE elementos SET datos = ?, actualizado_en = ? WHERE id = ?')
        ->execute([json_texto($datos), ahora(), $id]);
}

function cambiar_activo_elemento(PDO $pdo, int $id, bool $activo, ?int $usuario_id = null): void {
    $el = elemento($pdo, $id);
    if (!$el) return;
    $pdo->prepare('UPDATE elementos SET activo = ?, actualizado_en = ? WHERE id = ?')->execute([$activo ? 1 : 0, ahora(), $id]);
    anotar($pdo, $usuario_id, ($activo ? 'recuperó' : 'archivó') . " «{$el['nombre']}»");
}

// Borra el elemento con TODO lo suyo, archivos incluidos. Devuelve su sección.
function borrar_elemento(PDO $pdo, int $id, ?int $usuario_id = null): ?string {
    $el = elemento($pdo, $id);
    if (!$el) return null;
    foreach (documentos_de($pdo, $id) as $d) borrar_archivo_documento($d);
    // Se borran los hijos a mano además del ON DELETE CASCADE: si un día las
    // claves foráneas no están activas, no quedan huérfanos.
    foreach (['vencimientos', 'registros', 'documentos'] as $tabla) {
        $pdo->prepare("DELETE FROM {$tabla} WHERE elemento_id = ?")->execute([$id]);
    }
    $pdo->prepare('UPDATE elementos SET enlace_id = NULL WHERE enlace_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM elementos WHERE id = ?')->execute([$id]);
    anotar($pdo, $usuario_id, "borró «{$el['nombre']}» de " . seccion($el['seccion'])['nombre']);
    return $el['seccion'];
}

// ---------------------------------------------------------------------
//  Para pintar
// ---------------------------------------------------------------------
function valor_campo(array $campo, $valor): string {
    if ($valor === null || $valor === '') return '';
    switch ($campo['tipo']) {
        case 'fecha':   return fecha_es((string)$valor);
        case 'importe': return eur($valor);
        case 'numero':  return numero_es($valor) . (!empty($campo['unidad']) ? ' ' . $campo['unidad'] : '');
        default:        return (string)$valor;
    }
}

// Pares [etiqueta, valor] de los campos marcados como «resumen» que tienen dato.
function resumen_elemento(array $el): array {
    $def = tipo_def($el['seccion'], $el['tipo']);
    if (!$def) return [];
    $out = [];
    foreach ($def['campos'] as $clave => $c) {
        if (empty($c['resumen']) || !isset($el['datos'][$clave]) || $el['datos'][$clave] === '') continue;
        $out[] = [$c['etiqueta'], valor_campo($c, $el['datos'][$clave])];
    }
    return $out;
}

// Coste mensual de un elemento con coste + periodicidad (contratos,
// actividades). Sin periodicidad no se supone ninguna: no cuenta.
function coste_mensual(array $datos): float {
    if (!isset($datos['coste']) || !is_numeric($datos['coste'])) return 0.0;
    $meses = periodicidades()[$datos['periodicidad'] ?? ''] ?? 0;
    return $meses > 0 ? (float)$datos['coste'] / $meses : 0.0;
}

function coste_mensual_total(PDO $pdo): float {
    $total = 0.0;
    foreach ($pdo->query("SELECT datos FROM elementos WHERE activo = 1 AND datos LIKE '%coste%'") as $f) {
        $total += coste_mensual(json_array($f['datos']));
    }
    return round($total, 2);
}

function contar_elementos(PDO $pdo): array {
    $out = array_fill_keys(array_keys(secciones()), 0);
    foreach ($pdo->query('SELECT seccion, COUNT(*) AS n FROM elementos WHERE activo = 1 GROUP BY seccion') as $f) {
        $out[$f['seccion']] = (int)$f['n'];
    }
    return $out;
}

// Una línea de dato útil para la tarjeta de cada sección en el panel.
function kpi_seccion(PDO $pdo, string $clave): ?string {
    switch ($clave) {
        case 'vehiculos':
            $partes = [];
            foreach (elementos_de($pdo, 'vehiculos') as $v) {
                if ($v['tipo'] === 'vehiculo' && isset($v['datos']['km'])) $partes[] = $v['nombre'] . ': ' . numero_es($v['datos']['km']) . ' km';
            }
            return $partes ? implode(' · ', array_slice($partes, 0, 2)) : null;
        case 'contratos':
            $t = 0.0;
            foreach (elementos_de($pdo, 'contratos') as $c) $t += coste_mensual($c['datos']);
            return $t > 0 ? eur($t) . ' al mes' : null;
        case 'documentos':
            $st = $pdo->prepare("SELECT COUNT(*) FROM vencimientos v JOIN elementos e ON e.id = v.elemento_id
                                 WHERE v.seccion = 'documentos' AND v.estado = 'pendiente' AND e.activo = 1 AND v.fecha <= ?");
            $st->execute([sumar_meses(hoy(), 6)]);
            $n = (int)$st->fetchColumn();
            return $n ? $n . ($n === 1 ? ' caduca' : ' caducan') . ' en los próximos 6 meses' : 'Nada caduca en 6 meses';
        case 'familia':
            $n = count(personas($pdo));
            return $n . ($n === 1 ? ' persona' : ' personas');
    }
    return null;
}
