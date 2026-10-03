<?php
// =====================================================================
//  Vigilancia diaria (la lanza cron/diario.php): mira qué está vencido o
//  dentro de su ventana de aviso y lo manda por correo.
//
//  Lección de finanzas: un correo IGUAL todos los días se acaba ignorando.
//  Solo se repite si cambia QUÉ hay que hacer (algo nuevo entra en su
//  ventana, algo pasa a vencido, algo se marca hecho) o si ha pasado una
//  semana desde el último.
// =====================================================================

function destinatarios_avisos(): array {
    $out = [];
    foreach (explode(',', (string)EMAIL_AVISOS) as $d) {
        $d = trim($d);
        if (filter_var($d, FILTER_VALIDATE_EMAIL)) $out[] = $d;
    }
    return $out;
}

function huella_avisos(array $avisos): string {
    return sha1(implode('|', array_map(static fn($v) => $v['id'] . ':' . $v['fecha'] . ':' . $v['situacion'], $avisos)));
}

function correo_avisos(array $avisos): array {
    $vencidos = array_values(array_filter($avisos, static fn($v) => $v['situacion'] === 'vencido'));
    $pronto = array_values(array_filter($avisos, static fn($v) => $v['situacion'] === 'pronto'));
    $partes = [];
    if ($vencidos) $partes[] = count($vencidos) . (count($vencidos) === 1 ? ' vencido' : ' vencidos');
    if ($pronto) $partes[] = count($pronto) . (count($pronto) === 1 ? ' próximo' : ' próximos');
    $asunto = NOMBRE_APP . ': ' . implode(' y ', $partes);

    $linea = static function (array $v): string {
        $sec = seccion($v['seccion'])['nombre'] ?? $v['seccion'];
        return '  · ' . fecha_es($v['fecha']) . ' (' . relativo($v['dias']) . ') — ' . $v['titulo'] . "  [{$sec}]";
    };
    $cuerpo = "Hola,\n\nEsto es lo que tienes pendiente en " . NOMBRE_APP . ":\n";
    if ($vencidos) $cuerpo .= "\nYA VENCIDO\n" . implode("\n", array_map($linea, $vencidos)) . "\n";
    if ($pronto)   $cuerpo .= "\nPRÓXIMAMENTE\n" . implode("\n", array_map($linea, $pronto)) . "\n";
    $cuerpo .= "\nMárcalos como hechos en " . (URL_APP ?: 'la app') . "\n"
             . "(Este correo solo se repite si cambia algo o pasa una semana.)\n";
    return [$asunto, $cuerpo];
}

function enviar_correo(array $para, string $asunto, string $cuerpo): bool {
    $host = parse_url((string)URL_APP, PHP_URL_HOST) ?: 'localhost';
    $cabeceras = 'From: ' . '=?UTF-8?B?' . base64_encode(NOMBRE_APP) . '?=' . " <avisos@{$host}>\r\n"
               . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    return @mail(implode(', ', $para), '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $cabeceras);
}

/**
 * Devuelve un informe. $enviar permite a las pruebas sustituir mail().
 */
function ejecutar_vigilancia(PDO $pdo, ?callable $enviar = null): array {
    $avisos = avisos_activos($pdo);
    guardar_ajuste($pdo, 'vigilancia_ultima', ahora());

    $informe = ['avisos' => count($avisos), 'correo' => false, 'motivo' => ''];
    if (!$avisos) {
        guardar_ajuste($pdo, 'vigilancia_huella', '');
        $informe['resumen'] = 'Nada pendiente.';
        return $informe;
    }
    $para = destinatarios_avisos();
    $huella = huella_avisos($avisos);
    $ultimo = ajuste($pdo, 'vigilancia_ultimo_correo');
    $cambia = $huella !== ajuste($pdo, 'vigilancia_huella');
    $semana = !$ultimo || dias_entre(substr($ultimo, 0, 10), hoy()) >= 7;

    if (!$para) {
        $informe['motivo'] = 'sin EMAIL_AVISOS en config.php';
    } elseif (!$cambia && !$semana) {
        $informe['motivo'] = 'nada nuevo desde el último correo';
    } else {
        [$asunto, $cuerpo] = correo_avisos($avisos);
        $ok = ($enviar ?? 'enviar_correo')($para, $asunto, $cuerpo);
        if ($ok) {
            guardar_ajuste($pdo, 'vigilancia_huella', $huella);
            guardar_ajuste($pdo, 'vigilancia_ultimo_correo', ahora());
            $informe['correo'] = true;
        } else {
            $informe['motivo'] = 'mail() ha fallado';
        }
    }
    $informe['resumen'] = $informe['avisos'] . ' avisos; ' . ($informe['correo'] ? 'correo enviado' : 'sin correo (' . $informe['motivo'] . ')');
    return $informe;
}

// Para el panel y Ajustes: ¿el cron está corriendo de verdad? (En finanzas
// estuvo un día entero sin ejecutarse por una ruta mal puesta en hPanel y
// nadie lo notó: por eso se avisa si pasan 36 h sin noticias.)
function estado_vigilancia(PDO $pdo): array {
    $ultima = ajuste($pdo, 'vigilancia_ultima');
    if (!$ultima) return ['ok' => false, 'texto' => 'El aviso diario por correo no se ha ejecutado nunca.'];
    $horas = (time() - strtotime($ultima)) / 3600;
    if ($horas > 36) return ['ok' => false, 'texto' => 'El aviso diario lleva ' . (int)$horas . ' h sin ejecutarse (último: ' . fecha_es(substr($ultima, 0, 10)) . ').'];
    return ['ok' => true, 'texto' => 'Aviso diario en marcha (último: ' . substr($ultima, 11, 5) . ' del ' . fecha_es(substr($ultima, 0, 10)) . ').'];
}
