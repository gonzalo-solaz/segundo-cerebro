<?php
// =====================================================================
//  Euríbor a un año: la media de cada mes, que es lo que usan los bancos
//  para revisar las hipotecas variables (Gonzalo, 9/10/2026: «que al entrar
//  en cerebro se consulte el Euríbor y se actualice solo»).
//
//  Fuente: el CSV del Banco de España con los valores DIARIOS (tabla
//  ti_1_7, serie D_DNBAF172; pública y sin clave). Con él sale la media de
//  cada mes redondeada a 3 decimales, como la publica el BOE (cuadra con las
//  25 revisiones del cuadro de Mediolanum desde 2018), y la del mes en curso,
//  provisional. Si el Banco de España no contesta, la media mensual del BCE
//  (solo meses cerrados).
//
//  Se guarda en la tabla euribor (migración 009) y se consulta como mucho
//  cada 6 horas: lo lanza el cron diario y, sin hacer esperar a nadie, cada
//  visita a la app (includes/mantenimiento.php). Con las dos URL vacías (las
//  pruebas) no sale a la red.
// =====================================================================

const EURIBOR_COPIA_SEGUNDOS = 6 * 3600;
const EURIBOR_REINTENTO_SEGUNDOS = 3600;
const EURIBOR_DESDE = '2015-01';          // más atrás no lo necesita ninguna hipoteca de casa
const EURIBOR_SERIE_BDE = 'D_DNBAF172';  // Euríbor a 12 meses, diario
const EURIBOR_MESES_BDE = ['ENE' => 1, 'FEB' => 2, 'MAR' => 3, 'ABR' => 4, 'MAY' => 5, 'JUN' => 6,
                           'JUL' => 7, 'AGO' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DIC' => 12];

// Un mes está cerrado cuando ya ha acabado y el Banco de España ha tenido 3 días para
// publicar sus últimos valores (los da con uno o dos días de retraso).
function euribor_mes_cerrado(string $mes, string $hoy): bool {
    return $hoy >= date('Y-m-d', strtotime(sumar_meses($mes . '-01', 1) . ' +3 days'));
}

/** El CSV del Banco de España → ['AAAA-MM' => ['valor', 'dias', 'definitivo']], de más viejo a más nuevo. */
function euribor_leer_bde(string $csv, string $hoy): array {
    $col = null;
    $dias = [];
    foreach (preg_split('/\r\n|\n|\r/', $csv) as $linea) {
        if ($linea === '') continue;
        $c = str_getcsv($linea, ',', '"', '');
        if ($col === null) {
            $i = array_search(EURIBOR_SERIE_BDE, $c, true);
            if ($i !== false) $col = $i;
            continue;
        }
        if (!preg_match('/^(\d{2}) ([A-Z]{3}) (\d{4})$/', trim((string)$c[0]), $m) || !isset(EURIBOR_MESES_BDE[$m[2]])) continue;
        $v = trim((string)($c[$col] ?? ''));
        if (!is_numeric($v)) continue;
        $mes = sprintf('%04d-%02d', (int)$m[3], EURIBOR_MESES_BDE[$m[2]]);
        if ($mes < EURIBOR_DESDE) continue;
        $dias[$mes][] = (float)$v;
    }
    $out = [];
    foreach ($dias as $mes => $vs) {
        $out[$mes] = ['valor' => round(array_sum($vs) / count($vs), 3), 'dias' => count($vs), 'definitivo' => euribor_mes_cerrado($mes, $hoy)];
    }
    ksort($out);
    return $out;
}

/** El CSV mensual del BCE (format=csvdata) → lo mismo, todos cerrados. */
function euribor_leer_bce(string $csv): array {
    $cab = null;
    $out = [];
    foreach (preg_split('/\r\n|\n|\r/', $csv) as $linea) {
        if ($linea === '') continue;
        $c = str_getcsv($linea, ',', '"', '');
        if ($cab === null) { $cab = array_flip($c); continue; }
        $mes = (string)($c[$cab['TIME_PERIOD'] ?? -1] ?? '');
        $v = $c[$cab['OBS_VALUE'] ?? -1] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $mes) || !is_numeric($v) || $mes < EURIBOR_DESDE) continue;
        $out[$mes] = ['valor' => round((float)$v, 3), 'dias' => 0, 'definitivo' => true];
    }
    ksort($out);
    return $out;
}

function euribor_descargar(string $url, int $segundos): ?string {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 5,
                               CURLOPT_TIMEOUT => $segundos, CURLOPT_USERAGENT => 'segundo-cerebro (gonzalosolaz.tech)']);
        $txt = curl_exec($c);
        $ok = (int)curl_getinfo($c, CURLINFO_HTTP_CODE) === 200;
    } else {
        $txt = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $segundos]]));
        $ok = $txt !== false;
    }
    return $ok && is_string($txt) && $txt !== '' ? $txt : null;
}

/** Guarda los meses que cambian. Devuelve cuántos. Un mes cerrado no vuelve a provisional. */
function euribor_guardar(PDO $pdo, array $serie): int {
    $antes = euribor_serie($pdo);
    $n = 0;
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM euribor WHERE mes = ?');
        $ins = $pdo->prepare('INSERT INTO euribor (mes, valor, dias, definitivo, actualizado_en) VALUES (?, ?, ?, ?, ?)');
        foreach ($serie as $mes => $f) {
            $a = $antes[$mes] ?? null;
            if ($a && $a['definitivo'] && !$f['definitivo']) continue;
            if ($a && abs($a['valor'] - $f['valor']) < 0.0005 && $a['dias'] === $f['dias'] && $a['definitivo'] === $f['definitivo']) continue;
            $del->execute([$mes]);
            $ins->execute([$mes, $f['valor'], $f['dias'], $f['definitivo'] ? 1 : 0, ahora()]);
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $n;
}

/**
 * Consulta el Euríbor si toca (o si se fuerza) y lo guarda:
 * ['consultado' => bool, 'cambiados' => int, 'fuente' => ?string, 'error' => ?string].
 */
function euribor_actualizar(PDO $pdo, bool $forzar = false): array {
    $r = ['consultado' => false, 'cambiados' => 0, 'fuente' => null, 'error' => null];
    if (EURIBOR_URL === '' && EURIBOR_BCE_URL === '') return $r;
    $ahora = strtotime(ahora());
    if (!$forzar) {
        $ultima = ajuste($pdo, 'euribor_consultado');
        $intento = ajuste($pdo, 'euribor_intento');
        if ($ultima && $ahora - strtotime($ultima) < EURIBOR_COPIA_SEGUNDOS) return $r;
        if ($intento && $ahora - strtotime($intento) < EURIBOR_REINTENTO_SEGUNDOS) return $r;
    }
    guardar_ajuste($pdo, 'euribor_intento', ahora());
    $serie = [];
    if (EURIBOR_URL !== '' && ($txt = euribor_descargar(EURIBOR_URL, 25)) !== null) {
        $serie = euribor_leer_bde($txt, hoy());
        $r['fuente'] = 'Banco de España';
    }
    if (!$serie && EURIBOR_BCE_URL !== '' && ($txt = euribor_descargar(EURIBOR_BCE_URL, 15)) !== null) {
        $serie = euribor_leer_bce($txt);
        $r['fuente'] = 'BCE';
    }
    $r['consultado'] = true;
    if (!$serie) {
        $r['fuente'] = null;
        $r['error'] = 'Ni el Banco de España ni el BCE han contestado; sigue el último Euríbor guardado.';
        guardar_ajuste($pdo, 'euribor_error', $r['error']);
        return $r;
    }
    $r['cambiados'] = euribor_guardar($pdo, $serie);
    guardar_ajuste($pdo, 'euribor_consultado', ahora());
    guardar_ajuste($pdo, 'euribor_fuente', (string)$r['fuente']);
    guardar_ajuste($pdo, 'euribor_error', '');
    return $r;
}

/** ['AAAA-MM' => ['valor' => float, 'dias' => int, 'definitivo' => bool]], de más viejo a más nuevo. */
function euribor_serie(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT mes, valor, dias, definitivo FROM euribor ORDER BY mes') as $f) {
        $out[$f['mes']] = ['valor' => round((float)$f['valor'], 3), 'dias' => (int)$f['dias'], 'definitivo' => (int)$f['definitivo'] === 1];
    }
    return $out;
}

/**
 * Lo que se enseña: el último mes cerrado, el mes en curso (provisional) y de cuándo es la consulta.
 * ['cerrado' => ['mes', 'valor'] | null, 'provisional' => ['mes', 'valor', 'dias'] | null,
 *  'consultado' => ?string, 'fuente' => ?string, 'error' => ?string, 'serie' => [...]].
 */
function euribor_estado(PDO $pdo): array {
    $serie = euribor_serie($pdo);
    $cerrado = $provisional = null;
    foreach ($serie as $mes => $f) {
        if ($f['definitivo']) $cerrado = ['mes' => $mes, 'valor' => $f['valor']];
        else $provisional = ['mes' => $mes, 'valor' => $f['valor'], 'dias' => $f['dias']];
    }
    if ($provisional && $cerrado && $provisional['mes'] < $cerrado['mes']) $provisional = null;
    return ['cerrado' => $cerrado, 'provisional' => $provisional, 'consultado' => ajuste($pdo, 'euribor_consultado'),
            'fuente' => ajuste($pdo, 'euribor_fuente'), 'error' => ajuste($pdo, 'euribor_error') ?: null, 'serie' => $serie];
}

// «3,233 %» (los tipos y el Euríbor, con sus decimales tal cual: 1,6 · 5,21 · 3,905).
function tipo_es(float $t): string {
    return numero_es($t, 3) . NBSP . '%';
}
