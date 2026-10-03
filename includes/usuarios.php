<?php
// =====================================================================
//  Accesos de la familia. Dos roles:
//    admin   → todo, más gestionar accesos y ver Ajustes (y el enlace a
//              finanzas).
//    miembro → ve y edita todo lo de la familia; no gestiona accesos ni
//              borra elementos (puede archivarlos, que se deshace).
//  No hay registro público: las cuentas las crea un administrador, con una
//  contraseña temporal que hay que cambiar en el primer acceso.
// =====================================================================

const PASSWORD_MINIMO = 12;

function roles(): array {
    return ['admin' => 'Administrador', 'miembro' => 'Miembro'];
}

function usuario(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT u.*, p.color AS persona_color FROM usuarios u LEFT JOIN personas p ON p.id = u.persona_id WHERE u.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function usuarios_todos(PDO $pdo): array {
    return $pdo->query('SELECT u.*, p.nombre AS persona_nombre FROM usuarios u LEFT JOIN personas p ON p.id = u.persona_id
                        ORDER BY u.estado, u.id')->fetchAll();
}

function es_admin(): bool {
    return ($GLOBALS['usuario_actual']['rol'] ?? '') === 'admin';
}

// 12 y no 8: es la única puerta de una web con la salud y los papeles de
// la familia, publicada en internet y sin segundo factor.
function problema_password(string $pass): ?string {
    if (strlen($pass) < PASSWORD_MINIMO) return 'La contraseña debe tener al menos ' . PASSWORD_MINIMO . ' caracteres.';
    return null;
}

/** Crea un acceso. Devuelve [id, contraseña en claro] (la temporal, si no se dio). */
function crear_usuario(PDO $pdo, array $u, ?int $usuario_id = null): array {
    $errores = [];
    $nombre = trim((string)($u['nombre'] ?? ''));
    $email = strtolower(trim((string)($u['email'] ?? '')));
    $rol = (string)($u['rol'] ?? 'miembro');
    $persona_id = (int)($u['persona_id'] ?? 0) ?: null;
    $pass = (string)($u['password'] ?? '');
    $temporal = $pass === '';
    if ($temporal) $pass = contrasena_temporal();

    if ($nombre === '') $errores[] = 'Escribe el nombre.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Ese email no es válido.';
    if (!isset(roles()[$rol])) $errores[] = 'Rol no válido.';
    if ($persona_id && !persona($pdo, $persona_id)) $errores[] = 'Esa persona no existe.';
    if (!$temporal && ($p = problema_password($pass))) $errores[] = $p;
    if (!$errores) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        if ((int)$st->fetchColumn() > 0) $errores[] = 'Ya hay un acceso con ese email.';
    }
    if ($errores) throw new ErrorValidacion($errores);

    $pdo->prepare('INSERT INTO usuarios (email, nombre, password, rol, estado, debe_cambiar, persona_id, creado_en)
                   VALUES (?, ?, ?, ?, \'activo\', ?, ?, ?)')
        ->execute([$email, $nombre, password_hash($pass, PASSWORD_DEFAULT), $rol, $temporal ? 1 : 0, $persona_id, ahora()]);
    $id = (int)$pdo->lastInsertId();
    anotar($pdo, $usuario_id, "dio acceso a {$nombre}");
    return [$id, $pass];
}

function cambiar_password(PDO $pdo, int $id, string $nueva, bool $temporal = false): void {
    if (!$temporal && ($p = problema_password($nueva))) throw new ErrorValidacion([$p]);
    $pdo->prepare('UPDATE usuarios SET password = ?, debe_cambiar = ? WHERE id = ?')
        ->execute([password_hash($nueva, PASSWORD_DEFAULT), $temporal ? 1 : 0, $id]);
}
