<?php
// =====================================================================
//  Base de datos: conexión y esquema que se actualiza solo.
//
//  Patrón heredado de finanzas-personales: cada cambio de esquema es un
//  archivo sql/migraciones/NNN-descripcion.sql y se aplica la primera vez
//  que se carga la app después de subirlo. Subir archivos ES el despliegue
//  completo; nunca hay que pegar SQL en phpMyAdmin.
//
//  Los SQL se escriben para MySQL/MariaDB (Hostinger). Las pruebas y el
//  servidor local usan SQLite, y sql_traducir() adapta lo poco que cambia.
//  Para que esa traducción baste, las migraciones se limitan a:
//    · CREATE TABLE IF NOT EXISTS con columnas, UNIQUE KEY / KEY en línea
//      y FOREIGN KEY;
//    · ALTER TABLE ... ADD COLUMN, una columna por sentencia;
//    · nada de ENUM, triggers ni funciones de fecha de MySQL.
//  Y en las consultas de la app, lo mismo: las fechas las calcula PHP
//  (hoy(), ahora()) y se pasan como parámetro. Nada de NOW() ni CURDATE().
// =====================================================================

const DIR_MIGRACIONES = __DIR__ . '/../sql/migraciones';

function conectar_bd(): PDO {
    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    try {
        if (DB_DRIVER === 'sqlite') {
            $pdo = new PDO('sqlite:' . DB_SQLITE, null, null, $opciones);
            // SQLite trae las claves foráneas apagadas de serie.
            $pdo->exec('PRAGMA foreign_keys = ON');
            return $pdo;
        }
        // Sentencias preparadas REALES (no emuladas): los valores nunca se
        // concatenan al SQL, que es lo que corta la inyección de raíz.
        $opciones[PDO::ATTR_EMULATE_PREPARES] = false;
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS, $opciones
        );
    } catch (PDOException $e) {
        error_log('Segundo cerebro · BD: ' . $e->getMessage());
        if (PHP_SAPI === 'cli') throw $e;
        http_response_code(500);
        exit('No se puede conectar a la base de datos. Revisa los datos de config.php.');
    }
}

function bd_es_sqlite(PDO $pdo): bool {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/**
 * Adapta UNA sentencia de MySQL a SQLite. Devuelve una lista porque los
 * índices declarados dentro del CREATE TABLE (KEY nombre (cols)) en SQLite
 * tienen que ir aparte, como CREATE INDEX.
 */
function sql_traducir(string $sentencia, bool $sqlite): array {
    if (!$sqlite) return [$sentencia];
    $s = $sentencia;
    $s = preg_replace('/\b(?:BIG|SMALL|TINY)?INT(?:\(\d+\))?\s+UNSIGNED\s+NOT\s+NULL\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i',
        'INTEGER PRIMARY KEY AUTOINCREMENT', $s);
    $s = preg_replace('/\)\s*ENGINE\s*=.*$/is', ')', $s);
    $s = preg_replace('/\bUNSIGNED\b/i', '', $s);
    $s = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $s);
    $s = preg_replace('/\s+COLLATE\s+\w+/i', '', $s);

    $extra = [];
    if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $s, $m)) {
        $tabla = $m[1];
        $s = preg_replace('/,\s*UNIQUE\s+(?:KEY|INDEX)\s+`?\w+`?\s*\(/i', ', UNIQUE (', $s);
        $s = preg_replace_callback('/,\s*(?:KEY|INDEX)\s+`?(\w+)`?\s*\(([^)]*)\)/i',
            static function (array $k) use ($tabla, &$extra): string {
                // En SQLite el nombre del índice es global: se prefija con la tabla.
                $extra[] = "CREATE INDEX IF NOT EXISTS {$tabla}_{$k[1]} ON {$tabla} ({$k[2]})";
                return '';
            }, $s);
    }
    return array_merge([$s], $extra);
}

// Lista ordenada de [numero, nombre, ruta] de los archivos de migración.
function migraciones_disponibles(string $dir = DIR_MIGRACIONES): array {
    $out = [];
    foreach (glob($dir . '/*.sql') ?: [] as $ruta) {
        $nombre = basename($ruta);
        if (!preg_match('/^(\d{3})-/', $nombre, $m)) continue;
        $out[] = ['numero' => (int)$m[1], 'nombre' => $nombre, 'ruta' => $ruta];
    }
    usort($out, static fn($a, $b) => $a['numero'] <=> $b['numero']);
    return $out;
}

// Parte un archivo SQL en sentencias. Los comentarios «-- ...» se quitan
// antes, para que un «;» dentro de un comentario no corte nada.
function sentencias_sql(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $out = [];
    foreach (explode(';', $sql) as $s) {
        $s = trim($s);
        if ($s !== '') $out[] = $s;
    }
    return $out;
}

/**
 * Aplica las migraciones pendientes. Devuelve los nombres aplicados.
 *
 * En una página normal nunca lanza: un fallo se registra en el log y la app
 * sigue con el esquema que tenga (mejor que un 500 en todas las páginas).
 * Con $estricto = true (pruebas y cron) sí lanza.
 */
function esquema_al_dia(PDO $pdo, bool $estricto = false, string $dir = DIR_MIGRACIONES): array {
    $aplicadas = [];
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS esquema (
            nombre      VARCHAR(120) NOT NULL PRIMARY KEY,
            aplicado_en VARCHAR(19)  NOT NULL
        )');
        $hechas = [];
        foreach ($pdo->query('SELECT nombre FROM esquema') as $f) $hechas[$f['nombre']] = true;

        $sqlite = bd_es_sqlite($pdo);
        $ins = $pdo->prepare('INSERT INTO esquema (nombre, aplicado_en) VALUES (?, ?)');
        foreach (migraciones_disponibles($dir) as $m) {
            if (isset($hechas[$m['nombre']])) continue;
            foreach (sentencias_sql((string)file_get_contents($m['ruta'])) as $s) {
                foreach (sql_traducir($s, $sqlite) as $t) $pdo->exec($t);
            }
            $ins->execute([$m['nombre'], date('Y-m-d H:i:s')]);
            $aplicadas[] = $m['nombre'];
        }
    } catch (Throwable $e) {
        error_log('Segundo cerebro · esquema: ' . $e->getMessage());
        if ($estricto) throw $e;
    }
    return $aplicadas;
}

function migraciones_aplicadas(PDO $pdo): array {
    try {
        return $pdo->query('SELECT nombre, aplicado_en FROM esquema ORDER BY nombre')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
