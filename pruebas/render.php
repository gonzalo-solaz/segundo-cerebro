<?php
// =====================================================================
//  Renderiza UNA página como si llegara una petición con sesión. Lo usa
//  prueba-paginas.php, cada vez en un proceso nuevo (las páginas llaman a
//  exit al redirigir).
//
//      php pruebas/render.php peticion.json
//      {"pagina": "index.php", "get": {...}, "post": {...} | null, "usuario": 1}
//
//  Si la página redirige, al final sale «[[REDIRECCION:<url>]]».
// =====================================================================
require __DIR__ . '/arranque.php';

$pet = json_decode((string)file_get_contents($argv[1] ?? ''), true);
if (!is_array($pet) || empty($pet['pagina'])) { fwrite(STDERR, "Petición no válida\n"); exit(2); }

$_GET = $pet['get'] ?? [];
$_POST = $pet['post'] ?? [];
$_FILES = [];
$_SERVER['REQUEST_METHOD'] = isset($pet['post']) ? 'POST' : 'GET';
$_SERVER['SCRIPT_NAME'] = BASE_URL . '/' . $pet['pagina'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'ejemplo.test';
$_SESSION = [
    'usuario_id' => (int)($pet['usuario'] ?? 1),
    'csrf' => 'csrf-de-pruebas',
    'inicio_sesion' => time(),
    'ultima_actividad' => time(),
];
if (isset($pet['post'])) $_POST['csrf'] = $pet['csrf'] ?? 'csrf-de-pruebas';

register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['SC_REDIRECCION'])) echo "\n[[REDIRECCION:{$GLOBALS['SC_REDIRECCION']}]]\n";
    foreach ($_SESSION['flash'] ?? [] as $f) echo "\n[[FLASH:{$f['tipo']}:{$f['texto']}]]\n";
});

chdir(dirname(__DIR__));
require dirname(__DIR__) . '/' . basename($pet['pagina']);
