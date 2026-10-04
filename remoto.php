<?php
// =====================================================================
//  Herramienta LOCAL para que Claude grabe en el servidor sin pasar por
//  los formularios. Habla con api.php por HTTPS usando curl.exe (el que
//  trae Windows). No se sube al servidor.
//
//      php remoto.php estado                       Lo que vence y cómo está cada sección
//      php remoto.php esquema                      Secciones, tipos y campos válidos
//      php remoto.php personas
//      php remoto.php buscar [seccion] [texto=...] [--archivados]
//      php remoto.php ficha <id>                   Un elemento con avisos, historial y archivos
//      php remoto.php elemento <archivo.json | JSON>
//            {"seccion":"vehiculos","tipo":"vehiculo","nombre":"Furgo","datos":{"matricula":"1234ABC","proxima_itv":"2027-03-14"}}
//            {"id":12,"datos":{"caducidad":"2031-05-02"}}      ← al actualizar, solo lo que cambia
//            {"seccion":"contratos","tipo":"suministro","nombre":"Luz","enlace_id":2,"datos":{...}}  ← enlace_id: la vivienda/vehículo a la que pertenece (ver «esquema»)
//      php remoto.php vencimiento <archivo.json | JSON>
//            {"titulo":"IBI","fecha":"2026-11-05","seccion":"vivienda","repetir_meses":12,"aviso_dias":30}
//      php remoto.php hecho <id>
//      php remoto.php registro <archivo.json | JSON>
//            {"elemento_id":12,"fecha":"2026-09-30","tipo":"Mantenimiento","titulo":"Aceite y filtros","valor":154300,"coste":189.9}
//      php remoto.php partidas <archivo.json | JSON>      Desglose de un recibo de la comunidad (sustituye el anterior)
//            {"registro_id":40,"partidas":[{"concepto":"Mantenimiento piscina","categoria":"Piscina","zona":"escalera","total":290.40},
//                                          {"concepto":"Obra fuga","categoria":"Piscina","zona":"comun","total":2735.10,"extraordinaria":true}]}
//            zona comun|escalera → la parte se calcula con los coeficientes de la ficha; o "parte" a mano
//      php remoto.php comunidad <id>                Números del análisis (por año, categoría y recibo)
//      php remoto.php gastos                       A dónde va el gasto fijo: partidas, cosas, mes a mes y qué revisar
//      php remoto.php peso <id>                     Control de peso: IMC, ritmo, objetivo, calorías y consejos
//            Un pesaje: registro {"elemento_id":30,"fecha":"2026-10-03","tipo":"Peso","valor":82.4}  (Cintura en cm, Grasa corporal en %)
//      php remoto.php documento <archivo.pdf> elemento=<id> [titulo="..."]
//      php remoto.php actividad
//      php remoto.php conexiones                   ¿Valen todas las claves entre esta app y finanzas? (no las enseña)
//
//  Conexión en acceso.json (NO va al repositorio ni al servidor):
//      {"url": "https://gonzalosolaz.tech/admin", "clave": "la API_CLAVE de config.php"}
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }
date_default_timezone_set('Europe/Madrid');

// SC_ACCESO permite apuntar a otro acceso.json (p. ej. el servidor local).
$conf = @json_decode((string)@file_get_contents(getenv('SC_ACCESO') ?: __DIR__ . '/acceso.json'), true);
if (!is_array($conf) || empty($conf['url']) || empty($conf['clave'])) {
    fwrite(STDERR, "Falta acceso.json con {\"url\": \"https://gonzalosolaz.tech/segundo-cerebro\", \"clave\": \"API_CLAVE\"}\n");
    exit(1);
}
$URL = rtrim($conf['url'], '/') . '/api.php';
$CURL = 'curl';
foreach (['C:\\Windows\\System32\\curl.exe', '/usr/bin/curl'] as $c) if (is_file($c)) { $CURL = $c; break; }

$args = array_slice($argv, 1);
$accion = (string)array_shift($args);

function partir_args(array $args): array {
    $kv = []; $pos = [];
    foreach ($args as $a) {
        if (preg_match('/^-{0,2}([a-z_]+)=(.*)$/s', $a, $m)) $kv[$m[1]] = $m[2];
        elseif (str_starts_with($a, '--')) $kv[substr($a, 2)] = true;
        else $pos[] = $a;
    }
    return [$kv, $pos];
}

// Un argumento que es un archivo .json o el JSON en línea.
function leer_json_arg(?string $arg): array {
    if ($arg === null) { fwrite(STDERR, "Falta el JSON (archivo o en línea).\n"); exit(1); }
    $txt = is_file($arg) ? (string)file_get_contents($arg) : $arg;
    $d = json_decode($txt, true);
    if (!is_array($d)) { fwrite(STDERR, "No es un JSON válido: " . substr($txt, 0, 200) . "\n"); exit(1); }
    return $d;
}

/**
 * POST multipart a la API. Cada campo va desde un archivo temporal
 * (-F "campo=<archivo"): así ningún valor con comillas, tildes o saltos de
 * línea tiene que sobrevivir a la línea de comandos de Windows.
 */
function llamar(string $accion, array $datos = [], ?string $archivo = null): array {
    global $URL, $CURL, $conf;
    $tmp = [];
    $cmd = escapeshellarg($CURL) . ' -sS -X POST --max-time 120 ' . escapeshellarg($URL);
    foreach (['clave' => $conf['clave'], 'accion' => $accion, 'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE)] as $k => $v) {
        $f = tempnam(sys_get_temp_dir(), 'sc');
        file_put_contents($f, $v);
        $tmp[] = $f;
        $cmd .= ' -F ' . escapeshellarg($k . '=<' . $f);
    }
    if ($archivo !== null) {
        if (!is_file($archivo)) { fwrite(STDERR, "No existe el archivo $archivo\n"); exit(1); }
        // curl -F parte por comas y punto y coma («LIQUIDACION,CUOTAS 1T26.pdf»
        // del administrador, 3/10/2026): se sube una copia con el nombre limpio.
        $nombre = preg_replace('/[,;"]+/', ' ', basename($archivo));
        if ($nombre !== basename($archivo) || preg_match('/[,;"]/', $archivo)) {
            $copia = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sc-' . getmypid() . '-' . $nombre;
            copy($archivo, $copia);
            $tmp[] = $copia;
            $archivo = $copia;
        }
        $cmd .= ' -F ' . escapeshellarg('archivo=@' . $archivo . ';filename=' . $nombre);
    }
    $salida = shell_exec($cmd . ' 2>&1');
    foreach ($tmp as $f) @unlink($f);
    $d = json_decode((string)$salida, true);
    if (!is_array($d)) {
        fwrite(STDERR, "Respuesta no válida del servidor (¿URL o clave mal en acceso.json?):\n" . substr((string)$salida, 0, 800) . "\n");
        exit(1);
    }
    return $d;
}

function mostrar(array $r): void {
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(empty($r['ok']) ? 1 : 0);
}

[$kv, $pos] = partir_args($args);
switch ($accion) {
    case 'estado':
        $r = llamar('estado');
        if (empty($r['ok']) || isset($kv['json'])) mostrar($r);
        echo "Hoy: {$r['hoy']}\n\nAVISOS (vencidos o dentro de su ventana)\n";
        foreach ($r['avisos'] ?: [] as $v) printf("  #%-4d %s  %-8s %s  [%s]\n", $v['id'], $v['fecha'], $v['situacion'], $v['titulo'], $v['seccion']);
        if (!$r['avisos']) echo "  (ninguno)\n";
        echo "\nPRÓXIMOS 90 DÍAS\n";
        foreach ($r['proximos_90_dias'] ?: [] as $v) printf("  #%-4d %s  %s  [%s]\n", $v['id'], $v['fecha'], $v['titulo'], $v['seccion']);
        if (!$r['proximos_90_dias']) echo "  (ninguno)\n";
        echo "\nElementos: " . json_encode($r['elementos_por_seccion'], JSON_UNESCAPED_UNICODE) . "\n";
        echo "Gasto fijo mensual: " . number_format((float)$r['gasto_fijo_mensual'], 2, ',', '.') . " €\n";
        echo "Vigilancia: {$r['vigilancia']}\n";
        exit(0);
    case 'esquema':
    case 'gastos':
    case 'personas':
    case 'actividad':
    case 'conexiones':
        mostrar(llamar($accion));
    case 'buscar':
        mostrar(llamar('buscar', ['seccion' => $pos[0] ?? '', 'texto' => $kv['texto'] ?? '', 'archivados' => !empty($kv['archivados'])]));
    case 'ficha':
        mostrar(llamar('ficha', ['id' => (int)($pos[0] ?? 0)]));
    case 'comunidad':
    case 'peso':
        mostrar(llamar($accion, ['id' => (int)($pos[0] ?? 0)]));
    case 'hecho':
        mostrar(llamar('hecho', ['id' => (int)($pos[0] ?? 0)]));
    case 'elemento':
    case 'vencimiento':
    case 'registro':
    case 'partidas':
        mostrar(llamar($accion, leer_json_arg($pos[0] ?? null)));
    case 'documento':
        if (empty($pos[0]) || empty($kv['elemento'])) { fwrite(STDERR, "Uso: php remoto.php documento <archivo> elemento=<id> [titulo=\"...\"]\n"); exit(1); }
        mostrar(llamar('documento', ['elemento_id' => (int)$kv['elemento'], 'titulo' => (string)($kv['titulo'] ?? '')], $pos[0]));
}
fwrite(STDERR, "Acción desconocida. Mira la cabecera de remoto.php para ver las que hay.\n");
exit(1);
