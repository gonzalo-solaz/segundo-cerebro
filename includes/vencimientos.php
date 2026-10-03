<?php
// =====================================================================
//  Vencimientos: lo que caduca, renueva o toca hacer.
//
//  Dos orígenes:
//   · Automáticos (origen 'campo:<clave>'): los crea la ficha de un
//     elemento a partir de un campo de fecha (caducidad del DNI, ITV...).
//     Se cambian desde la ficha, no se borran sueltos.
//   · A mano (origen ''): recordatorios que pone cualquiera.
//
//  «Hecho» con repetición programa el siguiente: el objetivo de diseño es
//  el de finanzas, piloto automático. Si algo obliga a acordarse de volver
//  a apuntarlo cada año, está mal hecho.
// =====================================================================

const SQL_VENCIMIENTO = 'SELECT v.*, e.nombre AS elemento_nombre, e.tipo AS elemento_tipo,
                                p.nombre AS persona_nombre, p.color AS persona_color
                         FROM vencimientos v
                         LEFT JOIN elementos e ON e.id = v.elemento_id
                         LEFT JOIN personas p ON p.id = e.persona_id';

function completar_vencimiento(array $v): array {
    foreach (['id', 'aviso_dias', 'repetir_meses'] as $k) $v[$k] = (int)$v[$k];
    $v['elemento_id'] = $v['elemento_id'] !== null ? (int)$v['elemento_id'] : null;
    $v['dias'] = dias_entre(hoy(), $v['fecha']);
    $v['situacion'] = situacion_vencimiento($v);
    $v['automatico'] = str_starts_with((string)$v['origen'], 'campo:');
    return $v;
}

// vencido | pronto (dentro de su ventana de aviso) | futuro | hecho
function situacion_vencimiento(array $v): string {
    if (($v['estado'] ?? '') === 'hecho') return 'hecho';
    $dias = $v['dias'] ?? dias_entre(hoy(), $v['fecha']);
    if ($dias < 0) return 'vencido';
    if ($dias <= (int)$v['aviso_dias']) return 'pronto';
    return 'futuro';
}

function vencimiento(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare(SQL_VENCIMIENTO . ' WHERE v.id = ?');
    $st->execute([$id]);
    $f = $st->fetch();
    return $f ? completar_vencimiento($f) : null;
}

/**
 * Pendientes hasta dentro de $dias días (los vencidos, siempre).
 * Los de elementos archivados no salen.
 */
function agenda(PDO $pdo, int $dias = 90, ?string $seccion = null, ?int $elemento_id = null): array {
    $sql = SQL_VENCIMIENTO . " WHERE v.estado = 'pendiente' AND v.fecha <= ? AND (v.elemento_id IS NULL OR e.activo = 1)";
    $p = [sumar_dias(hoy(), $dias)];
    if ($seccion !== null)     { $sql .= ' AND v.seccion = ?';     $p[] = $seccion; }
    if ($elemento_id !== null) { $sql .= ' AND v.elemento_id = ?'; $p[] = $elemento_id; }
    $st = $pdo->prepare($sql . ' ORDER BY v.fecha, v.id');
    $st->execute($p);
    return array_map('completar_vencimiento', $st->fetchAll());
}

function hechos_recientes(PDO $pdo, int $n = 30, ?string $seccion = null, ?int $elemento_id = null): array {
    $sql = SQL_VENCIMIENTO . " WHERE v.estado = 'hecho'";
    $p = [];
    if ($seccion !== null)     { $sql .= ' AND v.seccion = ?';     $p[] = $seccion; }
    if ($elemento_id !== null) { $sql .= ' AND v.elemento_id = ?'; $p[] = $elemento_id; }
    $st = $pdo->prepare($sql . ' ORDER BY v.hecho_en DESC, v.id DESC LIMIT ' . max(1, $n));
    $st->execute($p);
    return array_map('completar_vencimiento', $st->fetchAll());
}

function agrupar_agenda(array $lista): array {
    $g = ['vencido' => [], 'pronto' => [], 'futuro' => []];
    foreach ($lista as $v) $g[$v['situacion']][] = $v;
    return $g;
}

// Lo que ya está dentro de su ventana de aviso (o vencido): lo que el cron
// manda por correo y lo que el panel pinta en rojo/ámbar.
function avisos_activos(PDO $pdo): array {
    return array_values(array_filter(agenda($pdo, 400), static fn($v) => $v['situacion'] !== 'futuro'));
}

// Próximo pendiente de cada sección (para las tarjetas del panel).
function proximos_por_seccion(PDO $pdo): array {
    $out = [];
    foreach (agenda($pdo, 3650) as $v) {
        if (!isset($out[$v['seccion']])) $out[$v['seccion']] = $v;
    }
    return $out;
}

/** Valida lo que llega del formulario o la API. Devuelve [fila, errores]. */
function validar_vencimiento(PDO $pdo, array $v): array {
    $errores = [];
    $titulo = trim((string)($v['titulo'] ?? ''));
    if ($titulo === '') $errores[] = 'Ponle un título.';
    elseif (longitud($titulo) > 150) $errores[] = 'El título es demasiado largo.';

    $fecha = leer_fecha($v['fecha'] ?? '');
    if (!$fecha) $errores[] = 'Pon una fecha válida.';

    $elemento_id = (int)($v['elemento_id'] ?? 0) ?: null;
    $seccion = (string)($v['seccion'] ?? '');
    if ($elemento_id) {
        $el = elemento($pdo, $elemento_id);
        if (!$el) { $errores[] = 'Ese elemento no existe.'; $elemento_id = null; }
        else $seccion = $el['seccion'];   // manda la sección del elemento
    }
    if (!seccion($seccion)) $errores[] = 'Elige una sección.';

    $aviso = (int)($v['aviso_dias'] ?? 30);
    if ($aviso < 0 || $aviso > 365) $errores[] = 'El aviso tiene que estar entre 0 y 365 días.';
    $repetir = (int)($v['repetir_meses'] ?? 0);
    if ($repetir < 0 || $repetir > 120) $errores[] = 'La repetición tiene que estar entre 0 y 120 meses.';

    $notas = trim((string)($v['notas'] ?? ''));
    return [compact('titulo', 'fecha', 'elemento_id', 'seccion', 'aviso', 'repetir', 'notas'), $errores];
}

function crear_vencimiento(PDO $pdo, array $v, ?int $usuario_id = null): int {
    [$f, $errores] = validar_vencimiento($pdo, $v);
    if ($errores) throw new ErrorValidacion($errores);
    $pdo->prepare('INSERT INTO vencimientos (seccion, elemento_id, titulo, fecha, aviso_dias, repetir_meses, origen, estado, notas, creado_en)
                   VALUES (?, ?, ?, ?, ?, ?, \'\', \'pendiente\', ?, ?)')
        ->execute([$f['seccion'], $f['elemento_id'], $f['titulo'], $f['fecha'], $f['aviso'], $f['repetir'], $f['notas'], ahora()]);
    $id = (int)$pdo->lastInsertId();
    anotar($pdo, $usuario_id, "programó «{$f['titulo']}» para el " . fecha_es($f['fecha']));
    return $id;
}

function actualizar_vencimiento(PDO $pdo, int $id, array $v, ?int $usuario_id = null): void {
    $actual = vencimiento($pdo, $id);
    if (!$actual) throw new RuntimeException('Ese aviso no existe.');
    $v['elemento_id'] = $actual['elemento_id'];
    $v['seccion'] = $actual['seccion'];
    [$f, $errores] = validar_vencimiento($pdo, $v);
    if ($errores) throw new ErrorValidacion($errores);
    $pdo->prepare('UPDATE vencimientos SET titulo = ?, fecha = ?, aviso_dias = ?, repetir_meses = ?, notas = ? WHERE id = ?')
        ->execute([$f['titulo'], $f['fecha'], $f['aviso'], $f['repetir'], $f['notas'], $id]);
    // Si sale de un campo de la ficha, la ficha se queda con la fecha nueva.
    if ($actual['automatico'] && $actual['estado'] === 'pendiente' && $actual['elemento_id']) {
        cambiar_dato_elemento($pdo, $actual['elemento_id'], substr($actual['origen'], 6), $f['fecha']);
    }
    anotar($pdo, $usuario_id, "cambió «{$f['titulo']}» al " . fecha_es($f['fecha']));
}

/**
 * Marca como hecho. Si se repite, crea el siguiente y devuelve su fecha.
 *
 * El siguiente se cuenta desde la fecha PREVISTA, no desde hoy: un seguro
 * renueva el mismo día cada año aunque lo marques tarde. Si aun así la
 * fecha siguiente ya ha pasado (lo marcaste con muchísimo retraso), se
 * sigue sumando hasta caer en el futuro.
 */
function marcar_hecho(PDO $pdo, int $id, ?int $usuario_id = null): ?string {
    $v = vencimiento($pdo, $id);
    if (!$v || $v['estado'] !== 'pendiente') return null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE vencimientos SET estado = 'hecho', hecho_en = ?, hecho_por = ? WHERE id = ?")
            ->execute([hoy(), $usuario_id, $id]);
        $siguiente = null;
        if ($v['repetir_meses'] > 0) {
            $siguiente = sumar_meses($v['fecha'], $v['repetir_meses']);
            while ($siguiente <= hoy()) $siguiente = sumar_meses($siguiente, $v['repetir_meses']);
            $pdo->prepare('INSERT INTO vencimientos (seccion, elemento_id, titulo, fecha, aviso_dias, repetir_meses, origen, estado, notas, creado_en)
                           VALUES (?, ?, ?, ?, ?, ?, ?, \'pendiente\', ?, ?)')
                ->execute([$v['seccion'], $v['elemento_id'], $v['titulo'], $siguiente, $v['aviso_dias'],
                           $v['repetir_meses'], $v['origen'], $v['notas'], ahora()]);
            if ($v['automatico'] && $v['elemento_id']) {
                cambiar_dato_elemento($pdo, $v['elemento_id'], substr($v['origen'], 6), $siguiente);
            }
        }
        anotar($pdo, $usuario_id, "marcó como hecho «{$v['titulo']}»" . ($siguiente ? ' (el siguiente, el ' . fecha_es($siguiente) . ')' : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $siguiente;
}

function borrar_vencimiento(PDO $pdo, int $id, ?int $usuario_id = null): void {
    $v = vencimiento($pdo, $id);
    if (!$v) return;
    if ($v['automatico'] && $v['estado'] === 'pendiente') {
        // Si se borrara, volvería a salir al guardar la ficha. Lo honrado es
        // decir dónde se cambia.
        throw new RuntimeException('Este aviso sale de la ficha de «' . $v['elemento_nombre'] . '»: cambia o vacía allí la fecha.');
    }
    $pdo->prepare('DELETE FROM vencimientos WHERE id = ?')->execute([$id]);
    anotar($pdo, $usuario_id, "borró el aviso «{$v['titulo']}»");
}
