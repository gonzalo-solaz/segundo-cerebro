<?php
// =====================================================================
//  Archivos adjuntos (PDF y fotos): la póliza, el escaneo del DNI, el
//  informe del médico...
//
//  · Viven en DIR_ARCHIVOS (private/archivos/AAAA/), que .htaccess cierra
//    a cualquier acceso por URL. Solo se sirven a través de archivo.php,
//    que exige sesión.
//  · El nombre en disco es aleatorio: el original solo se guarda en la
//    base de datos para ofrecerlo al descargar.
//  · El tipo se decide por los PRIMEROS BYTES del archivo, no por la
//    extensión que diga el nombre: un .pdf que no empieza por %PDF no
//    entra. Así no hace falta la extensión fileinfo.
// =====================================================================

const DOC_MIME = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'heic' => 'image/heic',
];
const DOC_MAX_BYTES = 15 * 1024 * 1024;

function tipo_real_archivo(string $bytes): ?string {
    if (strncmp($bytes, '%PDF', 4) === 0) return 'pdf';
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) return 'png';
    if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') return 'webp';
    if (substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'mif1', 'msf1', 'hevc'], true)) return 'heic';
    return null;
}

function tamano_legible(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return numero_es(round($bytes / 1024)) . ' KB';
    return str_replace('.', ',', (string)round($bytes / 1048576, 1)) . ' MB';
}

/** Guarda unos bytes como documento del elemento. Lo usan el formulario y la API. */
function guardar_documento_bytes(PDO $pdo, int $elemento_id, string $titulo, string $nombre_original, string $bytes, ?int $usuario_id = null): int {
    $el = elemento($pdo, $elemento_id);
    if (!$el) throw new ErrorValidacion(['Ese elemento no existe.']);
    $errores = [];
    $titulo = trim($titulo);
    $nombre_original = trim(basename(str_replace('\\', '/', $nombre_original))) ?: 'documento';
    if ($titulo === '') $titulo = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $nombre_original);
    if (longitud($titulo) > 150) $errores[] = 'El título es demasiado largo.';
    if ($bytes === '') $errores[] = 'El archivo está vacío.';
    elseif (strlen($bytes) > DOC_MAX_BYTES) $errores[] = 'El archivo pasa de 15 MB.';
    $ext = tipo_real_archivo($bytes);
    if ($bytes !== '' && !$ext) $errores[] = 'Solo se admiten PDF y fotos (JPG, PNG, WEBP, HEIC).';
    if ($errores) throw new ErrorValidacion($errores);

    $sub = date('Y');
    $dir = rtrim(DIR_ARCHIVOS, '/\\') . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('No se puede crear la carpeta de archivos.');
    $archivo = $sub . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    if (@file_put_contents(rtrim(DIR_ARCHIVOS, '/\\') . '/' . $archivo, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('No se ha podido guardar el archivo en el servidor.');
    }
    $pdo->prepare('INSERT INTO documentos (elemento_id, titulo, archivo, nombre_original, mime, bytes, subido_por, creado_en)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$elemento_id, $titulo, $archivo, recortar($nombre_original, 255), DOC_MIME[$ext], strlen($bytes), $usuario_id, ahora()]);
    $id = (int)$pdo->lastInsertId();
    anotar($pdo, $usuario_id, "adjuntó «{$titulo}» a «{$el['nombre']}»");
    return $id;
}

/** Desde un <input type="file">. $archivo es la entrada de $_FILES. */
function guardar_documento_subido(PDO $pdo, int $elemento_id, string $titulo, ?array $archivo, ?int $usuario_id = null): int {
    if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new ErrorValidacion(['Elige un archivo.']);
    }
    if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new ErrorValidacion(['El archivo es demasiado grande (15 MB como mucho).']);
    }
    if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
        throw new ErrorValidacion(['No se ha podido subir el archivo. Prueba otra vez.']);
    }
    return guardar_documento_bytes($pdo, $elemento_id, $titulo, (string)$archivo['name'],
        (string)file_get_contents($archivo['tmp_name']), $usuario_id);
}

function documentos_de(PDO $pdo, int $elemento_id): array {
    $st = $pdo->prepare('SELECT * FROM documentos WHERE elemento_id = ? ORDER BY creado_en DESC, id DESC');
    $st->execute([$elemento_id]);
    return $st->fetchAll();
}

function documento(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM documentos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// Ruta en disco, comprobando que no se sale de DIR_ARCHIVOS.
function ruta_documento(array $doc): ?string {
    if (!preg_match('#^\d{4}/[a-f0-9]{32}\.(pdf|jpg|png|webp|heic)$#', $doc['archivo'])) return null;
    $ruta = rtrim(DIR_ARCHIVOS, '/\\') . '/' . $doc['archivo'];
    return is_file($ruta) ? $ruta : null;
}

function borrar_archivo_documento(array $doc): void {
    $ruta = ruta_documento($doc);
    if ($ruta) @unlink($ruta);
}

function borrar_documento(PDO $pdo, int $id, int $elemento_id, ?int $usuario_id = null): void {
    $doc = documento($pdo, $id);
    if (!$doc || (int)$doc['elemento_id'] !== $elemento_id) return;
    borrar_archivo_documento($doc);
    $pdo->prepare('DELETE FROM documentos WHERE id = ?')->execute([$id]);
    anotar($pdo, $usuario_id, "borró el archivo «{$doc['titulo']}»");
}

function espacio_documentos(PDO $pdo): array {
    $f = $pdo->query('SELECT COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS bytes FROM documentos')->fetch();
    return ['n' => (int)$f['n'], 'bytes' => (int)$f['bytes']];
}
