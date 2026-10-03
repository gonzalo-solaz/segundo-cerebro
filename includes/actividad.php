<?php
// =====================================================================
//  Registro de actividad («Ana marcó como hecho “ITV · Furgo”») y ajustes
//  clave-valor del sistema (última ejecución del cron, etc.).
// =====================================================================

function anotar(PDO $pdo, ?int $usuario_id, string $texto): void {
    $pdo->prepare('INSERT INTO actividad (usuario_id, texto, creado_en) VALUES (?, ?, ?)')
        ->execute([$usuario_id, recortar($texto, 255), ahora()]);
}

// usuario_id NULL = lo hizo Claude por la API (o el cron).
function actividad_reciente(PDO $pdo, int $n = 10): array {
    return $pdo->query('SELECT a.*, COALESCE(u.nombre, \'Claude\') AS quien FROM actividad a
                        LEFT JOIN usuarios u ON u.id = a.usuario_id
                        ORDER BY a.creado_en DESC, a.id DESC LIMIT ' . max(1, $n))->fetchAll();
}

function ajuste(PDO $pdo, string $clave, ?string $defecto = null): ?string {
    $st = $pdo->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $st->execute([$clave]);
    $v = $st->fetchColumn();
    return $v === false ? $defecto : (string)$v;
}

// Sin INSERT ... ON DUPLICATE KEY (es solo de MySQL y las pruebas van con
// SQLite): se mira primero si existe.
function guardar_ajuste(PDO $pdo, string $clave, string $valor): void {
    $st = $pdo->prepare('SELECT COUNT(*) FROM ajustes WHERE clave = ?');
    $st->execute([$clave]);
    if ((int)$st->fetchColumn() > 0) {
        $pdo->prepare('UPDATE ajustes SET valor = ? WHERE clave = ?')->execute([$valor, $clave]);
    } else {
        $pdo->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?)')->execute([$clave, $valor]);
    }
}
