<?php
// Entrar en finanzas sin otra contraseña (3/10/2026: un solo acceso). Solo
// administradores, que llevan la verificación en dos pasos sí o sí. Da un
// pase firmado de un minuto y un solo uso (includes/pase.php) y manda a
// finanzas/entrar.php, que abre allí su propia sesión.
//
// «a» = la página de finanzas a la que se iba (la pone finanzas cuando su
// sesión caduca: vuelve aquí, y de aquí, con pase nuevo, a donde estaba).
require_once __DIR__ . '/includes/auth.php';

if (!es_admin()) pagina_error(403, 'Solo administradores', 'Finanzas solo la ven los administradores.');
if (PASE_CLAVE === '' || FINANZAS_URL === '') {
    pagina_error(503, 'Falta configurar', 'Para entrar en finanzas desde aquí falta el secreto PASE_CLAVE (el mismo en los repositorios de las dos apps).');
}
$pase = pase_crear(PASE_CLAVE, ['t' => 'entrar', 'e' => (string)$usuario_actual['email'],
                                'a' => pase_destino_valido((string)($_GET['a'] ?? 'index.php'))]);
redirigir(rtrim(FINANZAS_URL, '/') . '/entrar.php?pase=' . rawurlencode($pase));
