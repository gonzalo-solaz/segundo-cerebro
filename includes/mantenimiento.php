<?php
// =====================================================================
//  Lo que la app hace sola para estar al día, sin que nadie pulse nada
//  (Gonzalo, 9/10/2026: «todo lo que se pueda para que esté lo más
//  actualizado posible y no haya que hacer tareas manuales»):
//    · consultar el Euríbor (includes/euribor.php, como mucho cada 6 h);
//    · poner al día las hipotecas (includes/hipoteca.php): tipo de cada
//      revisión, cuota de la ficha y aviso de la próxima subida o bajada.
//
//  Se lanza desde el cron diario y, como el cron puede no estar programado,
//  también al entrar en la app: cada visita mira si hace más de una hora de
//  la última vez y, si es así, lo hace DESPUÉS de mandar la página
//  (litespeed_finish_request / fastcgi_finish_request), así que nadie espera.
//  Si el servidor no sabe cerrar la respuesta antes, en la visita solo se
//  hace lo que no sale a la red; el Euríbor queda para el cron o la API.
// =====================================================================

const MANTENIMIENTO_CADA_SEGUNDOS = 3600;

/** Hace todo ahora. ['euribor' => euribor_actualizar(), 'hipotecas' => [frases]]. */
function mantenimiento(PDO $pdo, bool $forzar = false, bool $con_red = true): array {
    guardar_ajuste($pdo, 'mantenimiento_ultimo', ahora());
    $r = ['euribor' => $con_red ? euribor_actualizar($pdo, $forzar) : ['consultado' => false, 'cambiados' => 0, 'fuente' => null, 'error' => null]];
    $r['hipotecas'] = hipotecas_al_dia($pdo);
    return $r;
}

/** Si toca, deja el mantenimiento para cuando la página ya se haya mandado. */
function mantenimiento_en_segundo_plano(PDO $pdo): void {
    if (PHP_SAPI === 'cli') return;   // las pruebas pintan las páginas por línea de comandos
    $ultimo = ajuste($pdo, 'mantenimiento_ultimo');
    if ($ultimo && strtotime(ahora()) - strtotime($ultimo) < MANTENIMIENTO_CADA_SEGUNDOS) return;
    guardar_ajuste($pdo, 'mantenimiento_ultimo', ahora());   // para que dos visitas a la vez no lo hagan dos veces
    $cerrar = function_exists('litespeed_finish_request') ? 'litespeed_finish_request'
            : (function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : null);
    register_shutdown_function(static function () use ($pdo, $cerrar): void {
        if ($cerrar) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $cerrar();
        }
        ignore_user_abort(true);
        @set_time_limit(90);
        try {
            mantenimiento($pdo, false, $cerrar !== null);
        } catch (Throwable $e) {
            error_log('Segundo cerebro · mantenimiento: ' . $e->getMessage());
        }
    });
}
