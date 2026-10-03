<?php
// =====================================================================
//  Puerta de la API, para que Claude grabe lo que le pasas (pólizas,
//  fichas técnicas, informes...) sin pasar por los formularios. Ver
//  includes/api.php (lo que hace cada acción) y remoto.php (la
//  herramienta local que la usa).
//
//  Seguridad (igual que finanzas): solo POST; la clave viaja en el CUERPO
//  (nunca en la URL, para que no quede en logs) y por HTTPS; se compara
//  con hash_equals; diez fallos desde una IP la bloquean un cuarto de
//  hora. Sin API_CLAVE en config.php, la puerta no existe (404).
// =====================================================================
require_once __DIR__ . '/includes/config-carga.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/antiabuso.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function api_responder(array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

$clave = (string)API_CLAVE;
$ip = ip_visitante();
if ($clave === '') { http_response_code(404); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_responder(['ok' => false, 'error' => 'Solo POST.'], 405);
if (limite_superado('api-fallos:' . $ip, 10, 900)) api_responder(['ok' => false, 'error' => 'Demasiados intentos. Espera unos minutos.'], 429);
if (!hash_equals($clave, (string)($_POST['clave'] ?? ''))) {
    limite_ok('api-fallos:' . $ip, 10, 900);
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/api.php';
esquema_al_dia($pdo);

$archivo = null;
if (!empty($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['archivo']['tmp_name'])) {
        api_responder(['ok' => false, 'error' => 'No se ha podido subir el archivo (código ' . (int)$_FILES['archivo']['error'] . ').'], 400);
    }
    $archivo = ['nombre' => (string)$_FILES['archivo']['name'], 'contenido' => (string)file_get_contents($_FILES['archivo']['tmp_name'])];
}

try {
    api_responder(['ok' => true] + api_ejecutar($pdo, $_POST, $archivo));
} catch (ErrorValidacion $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    api_responder(['ok' => false, 'error' => $e->getMessage(), 'errores' => $e->errores], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Segundo cerebro · api: ' . $e->getMessage());
    api_responder(['ok' => false, 'error' => $e->getMessage()], $e instanceof RuntimeException ? 400 : 500);
}
