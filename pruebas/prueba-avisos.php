<?php
// El correo diario: avisa de lo vencido y lo próximo, una vez; se repite
// solo si cambia algo o pasa una semana.
require __DIR__ . '/arranque.php';
echo "Aviso diario\n";

$pdo = bd_nueva();
$id = sembrar($pdo);
$enviados = [];
$cartero = static function (array $para, string $asunto, string $cuerpo) use (&$enviados): bool {
    $enviados[] = compact('para', 'asunto', 'cuerpo');
    return true;
};

$avisos = avisos_activos($pdo);
$titulos = array_column($avisos, 'titulo');
comprueba('la ITV pasada está en los avisos', in_array('Pasar la ITV · Furgo', $titulos, true));
comprueba('la garantía de la caldera (en 17 días, aviso 30) está', in_array('Fin de la garantía · Caldera', $titulos, true));
comprueba('el DNI (en 104 días, aviso 90) todavía no', !in_array('Renovar el DNI · DNI de Gonzalo Prueba', $titulos, true));

$r = ejecutar_vigilancia($pdo, $cartero);
comprueba('primera vez: manda correo', $r['correo'] === true && count($enviados) === 1);
comprueba('a los dos destinatarios de EMAIL_AVISOS', $enviados[0]['para'] === ['yo@ejemplo.test', 'ana@ejemplo.test']);
comprueba('el asunto cuenta vencidos y próximos', (bool)preg_match('/1 vencido y \d+ próximos/u', $enviados[0]['asunto']), $enviados[0]['asunto']);
comprueba('el cuerpo separa YA VENCIDO y PRÓXIMAMENTE', str_contains($enviados[0]['cuerpo'], 'YA VENCIDO') && str_contains($enviados[0]['cuerpo'], 'PRÓXIMAMENTE'));
comprueba('y enlaza a la app', str_contains($enviados[0]['cuerpo'], URL_APP));

$r = ejecutar_vigilancia($pdo, $cartero);
comprueba('segunda vez el mismo día y sin cambios: no repite', $r['correo'] === false && count($enviados) === 1, $r['resumen']);

marcar_hecho($pdo, agenda($pdo, 400, null, $id['furgo'])[0]['id'], $id['admin']);
$r = ejecutar_vigilancia($pdo, $cartero);
comprueba('al marcar algo hecho cambia la lista: vuelve a mandar', $r['correo'] === true && count($enviados) === 2);

guardar_ajuste($pdo, 'vigilancia_ultimo_correo', sumar_dias(hoy(), -8) . ' 07:00:00');
$r = ejecutar_vigilancia($pdo, $cartero);
comprueba('a la semana, aunque no cambie nada, recuerda', $r['correo'] === true && count($enviados) === 3);

comprueba('el panel sabe que el cron ha corrido', estado_vigilancia($pdo)['ok'] === true);
guardar_ajuste($pdo, 'vigilancia_ultima', date('Y-m-d H:i:s', time() - 40 * 3600));
comprueba('y avisa si lleva más de 36 h sin correr', estado_vigilancia($pdo)['ok'] === false);

$fallido = static fn() => false;
guardar_ajuste($pdo, 'vigilancia_huella', 'otra');
$r = ejecutar_vigilancia($pdo, $fallido);
comprueba('si mail() falla, no se da por avisado', $r['correo'] === false && ajuste($pdo, 'vigilancia_huella') === 'otra', $r['resumen']);
terminar();
