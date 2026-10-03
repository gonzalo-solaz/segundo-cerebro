<?php
// =====================================================================
//  Lo que se lee de la app de finanzas: las nóminas.
//
//  Los recibos viven SOLO en finanzas (recibo a recibo, cuadrados con el
//  ingreso del banco): aquí no se copian, se leen de su API (acción
//  nomina_estado) con la clave FINANZAS_API_CLAVE. Decisión de Gonzalo,
//  3/10/2026: finanzas manda en los números; segundo cerebro guarda la
//  empresa, el contrato, el convenio y los PDF.
//
//  Se guarda una copia una hora en DIR_CACHE (private/cache/) para que la ficha no
//  espere a finanzas en cada visita; si finanzas no contesta, se enseña la
//  última copia diciendo de cuándo es.
// =====================================================================

const FINANZAS_CACHE_SEGUNDOS = 3600;

function finanzas_configurada(): bool {
    return FINANZAS_URL !== '' && FINANZAS_API_CLAVE !== '';
}

function finanzas_ruta_cache(string $accion): string {
    return DIR_CACHE . '/finanzas-' . $accion . '.json';
}

// POST a la API de finanzas. Devuelve el JSON decodificado o lanza
// RuntimeException con un texto que se puede enseñar (nunca la clave).
function finanzas_pedir(array $campos, int $segundos = 8): array {
    $url = rtrim(FINANZAS_URL, '/') . '/api.php';
    $cuerpo = http_build_query(['clave' => FINANZAS_API_CLAVE] + $campos);
    $codigo = 0;
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true,
                               CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => $segundos]);
        $txt = curl_exec($c);
        $codigo = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => $segundos, 'ignore_errors' => true,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $cuerpo]]);
        $txt = @file_get_contents($url, false, $ctx);
        if (preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m)) $codigo = (int)$m[1];
    }
    if ($txt === false || $codigo === 0) throw new RuntimeException('Finanzas no contesta.');
    // 404 sin cuerpo = la clave no coincide con la API_CLAVE de finanzas (o allí no hay ninguna).
    if ($codigo === 404) throw new RuntimeException('Finanzas no acepta la clave (revisa el secreto FINANZAS_API_CLAVE).');
    $datos = json_decode((string)$txt, true);
    if (!is_array($datos)) throw new RuntimeException("Finanzas ha contestado algo que no se entiende (código {$codigo}).");
    if (empty($datos['ok'])) throw new RuntimeException('Finanzas dice: ' . (string)($datos['error'] ?? 'error sin detalle'));
    return $datos;
}

/**
 * Lee una acción de la API de finanzas con copia de una hora:
 * ['datos' => array, 'leido_en' => 'AAAA-MM-DD HH:MM:SS', 'error' => ?string].
 * Sin configurar, o sin copia y con finanzas caído, 'datos' viene vacío y
 * 'error' lo explica; con copia vieja, se enseña la copia y el error.
 */
function finanzas_leer(string $accion, bool $forzar = false): array {
    if (!finanzas_configurada()) {
        return ['datos' => [], 'leido_en' => null, 'error' => 'Falta la clave de finanzas (secreto FINANZAS_API_CLAVE).'];
    }
    $ruta = finanzas_ruta_cache($accion);
    $copia = is_file($ruta) ? json_array((string)@file_get_contents($ruta)) : [];
    if (!$forzar && $copia && time() - (int)($copia['t'] ?? 0) < FINANZAS_CACHE_SEGUNDOS) {
        return ['datos' => $copia['datos'] ?? [], 'leido_en' => $copia['leido_en'] ?? null, 'error' => null];
    }
    try {
        $datos = finanzas_pedir(['accion' => $accion]);
        unset($datos['ok']);
        $nueva = ['t' => time(), 'leido_en' => ahora(), 'datos' => $datos];
        if (!is_dir(dirname($ruta))) @mkdir(dirname($ruta), 0750, true);
        @file_put_contents($ruta, json_texto($nueva), LOCK_EX);
        return ['datos' => $datos, 'leido_en' => $nueva['leido_en'], 'error' => null];
    } catch (RuntimeException $ex) {
        return ['datos' => $copia['datos'] ?? [], 'leido_en' => $copia['leido_en'] ?? null, 'error' => $ex->getMessage()];
    }
}

/** Las nóminas (acción nomina_estado): como finanzas_leer, con 'anios' en vez de 'datos'. */
function finanzas_nominas(bool $forzar = false): array {
    $r = finanzas_leer('nomina_estado', $forzar);
    return ['anios' => $r['datos']['anios'] ?? [], 'leido_en' => $r['leido_en'], 'error' => $r['error']];
}

/**
 * El panel de finanzas entero (acción «panel»), para la sección Finanzas:
 * SIEMPRE fresco (acabas de importar un extracto y vuelves), con la última
 * copia solo si finanzas no contesta. Más plazo: lleva todos los movimientos.
 */
function finanzas_panel(): array {
    if (!finanzas_configurada()) {
        return ['datos' => [], 'leido_en' => null, 'error' => 'Falta la clave de finanzas (secreto FINANZAS_API_CLAVE).'];
    }
    $ruta = finanzas_ruta_cache('panel');
    try {
        $datos = finanzas_pedir(['accion' => 'panel'], 25);
        unset($datos['ok']);
        if (!is_dir(dirname($ruta))) @mkdir(dirname($ruta), 0750, true);
        @file_put_contents($ruta, json_encode(['t' => time(), 'leido_en' => ahora(), 'datos' => $datos], JSON_UNESCAPED_UNICODE), LOCK_EX);
        return ['datos' => $datos, 'leido_en' => ahora(), 'error' => null];
    } catch (RuntimeException $ex) {
        $copia = is_file($ruta) ? json_array((string)@file_get_contents($ruta)) : [];
        return ['datos' => $copia['datos'] ?? [], 'leido_en' => $copia['leido_en'] ?? null, 'error' => $ex->getMessage()];
    }
}

// Ruta (sin dominio) de los estáticos de finanzas: mismo dominio, así que la
// política de seguridad de aquí ('self') los admite. «/finanzas-personales/».
function finanzas_ruta_estaticos(): string {
    return rtrim((string)parse_url(FINANZAS_URL, PHP_URL_PATH), '/') . '/assets/';
}

function mes_es(string $mes): string {
    return MESES[(int)substr($mes, 5, 2) - 1] . ' ' . substr($mes, 0, 4);
}

/**
 * Lo que enseña la ficha del empleo, del año más reciente: los recibos con
 * su cuadre, el líquido acumulado, qué nómina falta por grabar y si la
 * diferencia con el banco ha cambiado (lo que importa en finanzas es que NO
 * cambie de un mes a otro). Función pura, para poder probarla sin red.
 */
function resumen_nominas(array $anios, string $hoy): ?array {
    if (!$anios) return null;
    usort($anios, static fn($a, $b) => (int)$a['anio'] <=> (int)$b['anio']);
    $a = end($anios);
    $filas = [];
    $liquido = 0.0;
    $ultimo_mensual = null;
    foreach ($a['cuadre'] ?? [] as $f) {
        $filas[] = $f;
        $liquido += (float)$f['liquido'];
        if (($f['tipo'] ?? 'mensual') === 'mensual') $ultimo_mensual = $f['mes'];
    }
    // La nómina de un mes llega a final de ese mes: hoy se espera la del mes anterior.
    $esperado = substr(sumar_meses(substr($hoy, 0, 7) . '-01', -1), 0, 7);
    $falta = null;
    $ultimo_global = null;
    foreach ($anios as $x) foreach ($x['cuadre'] ?? [] as $f) {
        if (($f['tipo'] ?? 'mensual') === 'mensual' && ($ultimo_global === null || $f['mes'] > $ultimo_global)) $ultimo_global = $f['mes'];
    }
    if ($ultimo_global !== null && $ultimo_global < $esperado) $falta = $esperado;
    $cambia = array_values(array_filter($filas, static fn($f) => !empty($f['cambia'])));
    $meses = $a['meses'] ?? [];
    $ult = $meses ? end($meses) : null;
    return [
        'anio' => (int)$a['anio'], 'filas' => $filas, 'liquido' => round($liquido, 2),
        'n' => count($filas), 'pagas' => (int)($a['pagas_totales'] ?? 0),
        'ultimo_mensual' => $ultimo_mensual, 'falta' => $falta,
        'cambia' => array_column($cambia, 'mes'),
        'salario_base' => $ult ? (float)($ult['campos']['salario_base'] ?? 0) : null,
    ];
}
