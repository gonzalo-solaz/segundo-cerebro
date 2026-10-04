<?php
// =====================================================================
//  IPC: la variación anual del índice general nacional, del INE (serie
//  IPC251856, API pública wstempus, sin clave). Para comparar con él lo que
//  sube cada gasto y el sueldo (Gonzalo, 4/10/2026: «el garaje me lo suben
//  con el IPC; la nómina también»).
//
//  Copia de un día en DIR_CACHE/ipc.json (el INE publica una vez al mes);
//  si el INE no contesta, se usa la última copia, y sin copia no se vuelve a
//  intentar hasta pasada una hora (para no esperar en cada visita). Con
//  IPC_URL vacía (las pruebas) no se sale a la red: solo la copia.
// =====================================================================

const IPC_COPIA_SEGUNDOS = 24 * 3600;
const IPC_REINTENTO_SEGUNDOS = 3600;

/** Lee la respuesta del INE: ['AAAA-MM' => variación anual en %], de más vieja a más nueva. */
function ipc_leer_ine(string $json): array {
    $d = json_decode($json, true);
    $out = [];
    foreach ($d['Data'] ?? [] as $x) {
        $anio = (int)($x['Anyo'] ?? 0);
        $mes = (int)($x['FK_Periodo'] ?? 0);
        if ($anio < 2000 || $mes < 1 || $mes > 12 || !is_numeric($x['Valor'] ?? null)) continue;
        $out[sprintf('%04d-%02d', $anio, $mes)] = (float)$x['Valor'];
    }
    ksort($out);
    return $out;
}

/** ['serie' => ['AAAA-MM' => %], 'ultimo' => 'AAAA-MM'|null, 'error' => ?string]. */
function ipc_serie(): array {
    $ruta = DIR_CACHE . '/ipc.json';
    $copia = is_file($ruta) ? json_array((string)@file_get_contents($ruta)) : [];
    $edad = time() - (int)($copia['t'] ?? 0);
    $serie = $copia['serie'] ?? [];
    $error = null;
    $toca = $serie ? $edad > IPC_COPIA_SEGUNDOS : $edad > IPC_REINTENTO_SEGUNDOS;
    if (IPC_URL !== '' && $toca) {
        $txt = false;
        if (function_exists('curl_init')) {
            $c = curl_init(IPC_URL);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6]);
            $txt = curl_exec($c);
            if ((int)curl_getinfo($c, CURLINFO_HTTP_CODE) !== 200) $txt = false;
            curl_close($c);
        } else {
            $txt = @file_get_contents(IPC_URL, false, stream_context_create(['http' => ['timeout' => 6]]));
        }
        $nueva = $txt !== false ? ipc_leer_ine((string)$txt) : [];
        if ($nueva) $serie = $nueva;
        else $error = 'El INE no ha contestado; ' . ($serie ? 'uso el último IPC guardado.' : 'sin IPC por ahora.');
        if (!is_dir(dirname($ruta))) @mkdir(dirname($ruta), 0750, true);
        @file_put_contents($ruta, json_texto(['t' => time(), 'serie' => $serie]), LOCK_EX);
    }
    return ['serie' => $serie, 'ultimo' => $serie ? array_key_last($serie) : null, 'error' => $error];
}

/** La variación anual del último mes publicado que no pase de $mes ('AAAA-MM'): ['mes', 'valor'] o null. */
function ipc_hasta(array $serie, string $mes): ?array {
    $r = null;
    foreach ($serie as $m => $v) if ($m <= $mes) $r = ['mes' => $m, 'valor' => $v];
    return $r;
}

// «diciembre de 2025».
function mes_largo(string $mes): string {
    return MESES[(int)substr($mes, 5, 2) - 1] . ' de ' . substr($mes, 0, 4);
}
