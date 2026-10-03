<?php
// Segundo paso del login: el código de 6 cifras de la app (o uno de
// recuperación). Solo se llega con la contraseña ya comprobada en login.php,
// que deja $_SESSION['pendiente_2p']; hasta aquí NO hay sesión abierta.
require_once __DIR__ . '/includes/sesion.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/antiabuso.php';

enviar_cabeceras_seguridad();

$volver = volver_seguro($_GET['volver'] ?? ($_POST['volver'] ?? ''));
$uid = (int)($_SESSION['pendiente_2p'] ?? 0);
// Cinco minutos para teclear el código; después, otra vez la contraseña.
if (!$uid || time() - (int)($_SESSION['pendiente_2p_desde'] ?? 0) > 300) {
    unset($_SESSION['pendiente_2p'], $_SESSION['pendiente_2p_desde']);
    redirigir('login.php?motivo=expirada&volver=' . rawurlencode($volver));
}
$u = usuario($pdo, $uid);
if (!$u || $u['estado'] !== 'activo' || !dos_pasos_activa($u)) {
    unset($_SESSION['pendiente_2p'], $_SESSION['pendiente_2p_desde']);
    redirigir('login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 6 cifras son un millón de combinaciones: 5 intentos por cuarto de hora
    // y por cuenta (no por IP) lo hacen inviable a ciegas.
    $clave_limite = 'dos-pasos:' . $uid;
    if (!csrf_ok()) {
        $error = 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.';
    } elseif (!limite_ok($clave_limite, 5, 900)) {
        $error = 'Demasiados códigos equivocados. Espera un cuarto de hora.';
    } elseif ($como = comprobar_segundo_paso($pdo, $u, (string)($_POST['codigo'] ?? ''))) {
        limite_limpiar($clave_limite);
        abrir_sesion($pdo, $u);
        if ($como === 'recuperacion') {
            $quedan = codigos_recuperacion_restantes(usuario($pdo, $uid));
            flash('aviso', "Has entrado con un código de recuperación (te quedan {$quedan}). Si has perdido el móvil, configura la app de nuevo en «Mi cuenta».");
        }
        redirigir((int)$u['debe_cambiar'] === 1 ? 'cuenta.php' : $volver);
    } else {
        $error = 'Ese código no vale. Mira que sea el de ahora (cambia cada 30 segundos).';
    }
}

cabecera_publica('Código de verificación');
?>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-acceso">
      <?= csrf_input() ?>
      <input type="hidden" name="volver" value="<?= e($volver) ?>">
      <label for="codigo">El código de 6 cifras de tu app de autenticación</label>
      <input type="text" id="codigo" name="codigo" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="12">
      <button class="btn btn-primario btn-bloque">Entrar</button>
      <p class="tenue">¿Sin el móvil? Escribe uno de tus códigos de recuperación (como «abcd-efgh»).</p>
    </form>
<?php pie_publico();
