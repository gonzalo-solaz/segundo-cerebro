<?php
// =====================================================================
//  Las personas de la casa. Una persona puede tener acceso a la app
//  (usuarios.persona_id) o ser solo una ficha: los niños no entran, pero
//  tienen su DNI, su ficha médica y su colegio.
// =====================================================================

function relaciones(): array {
    return ['Yo', 'Pareja', 'Hijo', 'Hija', 'Madre', 'Padre', 'Otro familiar', 'Otra persona'];
}

function colores_persona(): array {
    return ['#405189', '#0ab39c', '#f06548', '#e0991a', '#6559cc', '#e83e8c', '#3577f1', '#299cdb'];
}

function personas(PDO $pdo, bool $solo_activas = true): array {
    $sql = 'SELECT p.*, (SELECT COUNT(*) FROM elementos e WHERE e.persona_id = p.id AND e.activo = 1) AS n_elementos,
                   (SELECT u.nombre FROM usuarios u WHERE u.persona_id = p.id LIMIT 1) AS usuario_nombre
            FROM personas p' . ($solo_activas ? ' WHERE p.activa = 1' : '') . ' ORDER BY p.activa DESC, p.id';
    return $pdo->query($sql)->fetchAll();
}

function persona(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM personas WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function guardar_persona(PDO $pdo, array $p, ?int $id = null, ?int $usuario_id = null): int {
    $errores = [];
    $nombre = trim((string)($p['nombre'] ?? ''));
    if ($nombre === '') $errores[] = 'Escribe el nombre.';
    elseif (longitud($nombre) > 80) $errores[] = 'El nombre es demasiado largo.';
    $relacion = trim((string)($p['relacion'] ?? ''));
    if ($relacion !== '' && !in_array($relacion, relaciones(), true)) $errores[] = 'Esa relación no está en la lista.';
    $nacimiento = null;
    if (trim((string)($p['fecha_nacimiento'] ?? '')) !== '') {
        $nacimiento = leer_fecha($p['fecha_nacimiento']);
        if (!$nacimiento) $errores[] = 'La fecha de nacimiento no es válida.';
        elseif ($nacimiento > hoy()) $errores[] = 'La fecha de nacimiento está en el futuro.';
    }
    $color = (string)($p['color'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $usados = count($pdo->query('SELECT id FROM personas')->fetchAll());
        $color = colores_persona()[$usados % count(colores_persona())];
    }
    if ($errores) throw new ErrorValidacion($errores);

    if ($id) {
        if (!persona($pdo, $id)) throw new RuntimeException('Esa persona no existe.');
        $pdo->prepare('UPDATE personas SET nombre = ?, relacion = ?, fecha_nacimiento = ?, color = ? WHERE id = ?')
            ->execute([$nombre, $relacion, $nacimiento, $color, $id]);
        anotar($pdo, $usuario_id, "actualizó la ficha de {$nombre}");
        return $id;
    }
    $pdo->prepare('INSERT INTO personas (nombre, relacion, fecha_nacimiento, color, activa, creado_en) VALUES (?, ?, ?, ?, 1, ?)')
        ->execute([$nombre, $relacion, $nacimiento, $color, ahora()]);
    $nuevo = (int)$pdo->lastInsertId();
    anotar($pdo, $usuario_id, "añadió a {$nombre} a la familia");
    return $nuevo;
}

function cambiar_activa_persona(PDO $pdo, int $id, bool $activa, ?int $usuario_id = null): void {
    $p = persona($pdo, $id);
    if (!$p) return;
    $pdo->prepare('UPDATE personas SET activa = ? WHERE id = ?')->execute([$activa ? 1 : 0, $id]);
    anotar($pdo, $usuario_id, ($activa ? 'recuperó' : 'archivó') . " la ficha de {$p['nombre']}");
}
