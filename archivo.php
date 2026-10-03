<?php
// Sirve un archivo adjunto. Es la ÚNICA puerta a private/archivos/, y
// exige sesión (el guardián). ?descargar=1 lo baja en vez de abrirlo.
require_once __DIR__ . '/includes/auth.php';

$doc = documento($pdo, (int)($_GET['id'] ?? 0));
$ruta = $doc ? ruta_documento($doc) : null;
if (!$doc || !$ruta) pagina_error(404, 'No encontrado', 'Ese archivo no existe o ya no está en el servidor.');

// La CSP general bloquea el visor de PDF del navegador; aquí no hace falta:
// solo se sirven PDF e imágenes cuyo tipo se comprobó por sus bytes al
// subirlos, y nosniff impide que el navegador los interprete como otra cosa.
header_remove('Content-Security-Policy');
$nombre = preg_replace('/[^\w.\- ]+/u', '_', $doc['nombre_original']) ?: 'documento';
$modo = !empty($_GET['descargar']) ? 'attachment' : 'inline';
header('Content-Type: ' . $doc['mime']);
header('Content-Length: ' . filesize($ruta));
header("Content-Disposition: {$modo}; filename=\"" . str_replace('"', '', $nombre) . "\"; filename*=UTF-8''" . rawurlencode($doc['nombre_original']));
readfile($ruta);
