<?php
// Las migraciones se aplican una vez, en orden, y SQLite entiende el SQL
// escrito para MySQL tras sql_traducir().
require __DIR__ . '/arranque.php';
echo "Esquema\n";

$t = sql_traducir("CREATE TABLE IF NOT EXISTS x (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  a VARCHAR(10) NOT NULL,
  b INT UNSIGNED NULL,
  UNIQUE KEY a_unica (a),
  KEY por_b (b, a),
  FOREIGN KEY (b) REFERENCES y (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", true);
comprueba('AUTO_INCREMENT → INTEGER PRIMARY KEY AUTOINCREMENT', str_contains($t[0], 'INTEGER PRIMARY KEY AUTOINCREMENT'), $t[0]);
comprueba('se quitan ENGINE/CHARSET/UNSIGNED', !preg_match('/ENGINE|CHARSET|UNSIGNED|COLLATE/i', $t[0]), $t[0]);
comprueba('UNIQUE KEY → UNIQUE', str_contains($t[0], 'UNIQUE (a)'), $t[0]);
comprueba('KEY en línea → CREATE INDEX aparte, con la tabla de prefijo', ($t[1] ?? '') === 'CREATE INDEX IF NOT EXISTS x_por_b ON x (b, a)', $t[1] ?? '(nada)');
comprueba('FOREIGN KEY se respeta', str_contains($t[0], 'FOREIGN KEY (b) REFERENCES y (id) ON DELETE CASCADE'), $t[0]);
comprueba('en MySQL no se toca nada', sql_traducir('CREATE TABLE z (id INT) ENGINE=InnoDB', false) === ['CREATE TABLE z (id INT) ENGINE=InnoDB']);

$pdo = conectar_bd();
$primera = esquema_al_dia($pdo, true);
$todas = array_column(migraciones_disponibles(), 'nombre');
comprueba('la primera vez se aplican todas las migraciones', $primera === $todas, implode(', ', $primera));
comprueba('la segunda vez no se aplica ninguna', esquema_al_dia($pdo, true) === []);
foreach (['personas', 'usuarios', 'elementos', 'vencimientos', 'registros', 'documentos', 'actividad', 'ajustes'] as $tabla) {
    $existe = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '{$tabla}'")->fetchColumn();
    comprueba("existe la tabla {$tabla}", $existe === 1);
}
$fk = lanza(static fn() => $pdo->exec("INSERT INTO registros (elemento_id, fecha, titulo, creado_en) VALUES (999, '2026-01-01', 'x', '2026-01-01 00:00:00')"));
comprueba('las claves foráneas están activas (un registro sin elemento no entra)', $fk instanceof PDOException);

foreach (migraciones_disponibles() as $m) {
    comprueba("{$m['nombre']}: sin ENUM ni funciones de fecha de MySQL",
        !preg_match('/\bENUM\s*\(|\bNOW\s*\(|CURDATE|DATE_ADD|DATE_SUB/i', (string)file_get_contents($m['ruta'])));
}
// Las consultas de la app tampoco: las fechas las pone PHP. (Se mira el
// código sin comentarios: los comentarios SÍ hablan de NOW() para explicar
// por qué no se usa.)
function codigo_sin_comentarios(string $ruta): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($ruta)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
$malas = [];
foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../includes/*.php'), glob(__DIR__ . '/../cron/*.php')) as $f) {
    if (preg_match('/\b(NOW|CURDATE|CURRENT_DATE)\s*\(|DATE_ADD|DATE_SUB|ON DUPLICATE KEY/i', codigo_sin_comentarios($f))) $malas[] = basename($f);
}
comprueba('ningún PHP usa NOW()/CURDATE()/ON DUPLICATE KEY', !$malas, implode(', ', $malas));

// El patrón «$var» con comillas españolas dentro de comillas dobles: PHP se
// come «»» como parte del nombre de la variable (lección de finanzas).
$guillemet = [];
foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../includes/*.php')) as $f) {
    if (preg_match('/\$[a-zA-Z_]\w*[»«]/u', (string)file_get_contents($f))) $guillemet[] = basename($f);
}
comprueba('ningún "«$var»" sin llaves', !$guillemet, implode(', ', $guillemet));
terminar();
