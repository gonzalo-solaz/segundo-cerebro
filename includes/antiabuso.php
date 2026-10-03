<?php
// =====================================================================
//  Freno de fuerza bruta para el login y la API (copiado de finanzas).
//  Contador por IP en archivos dentro de private/ (bloqueada por
//  .htaccess). Para una familia no hace falta más: cero tablas.
// =====================================================================

function ip_visitante(): string {
    // REMOTE_ADDR y no X-Forwarded-For: esa cabecera la puede falsificar
    // cualquiera para saltarse el freno.
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'desconocida');
}

function ruta_contador(string $clave): string {
    $dir = dirname(__DIR__) . '/private/intentos';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . sha1($clave) . '.json';
}

// Devuelve true si la acción PUEDE ejecutarse (y apunta el intento).
function limite_ok(string $clave, int $max = 10, int $ventana = 900): bool {
    $ruta = ruta_contador($clave);
    $ahora = time();
    $intentos = is_readable($ruta) ? (json_decode((string)file_get_contents($ruta), true) ?: []) : [];
    $intentos = array_values(array_filter($intentos, static fn($t) => $ahora - $t < $ventana));
    if (count($intentos) >= $max) return false;
    $intentos[] = $ahora;
    @file_put_contents($ruta, json_encode($intentos), LOCK_EX);
    return true;
}

// ¿Está agotado el cupo? Solo mira, no apunta nada.
function limite_superado(string $clave, int $max = 10, int $ventana = 900): bool {
    $ruta = ruta_contador($clave);
    if (!is_readable($ruta)) return false;
    $ahora = time();
    $intentos = json_decode((string)file_get_contents($ruta), true) ?: [];
    return count(array_filter($intentos, static fn($t) => $ahora - $t < $ventana)) >= $max;
}

function limite_limpiar(string $clave): void {
    @unlink(ruta_contador($clave));
}
