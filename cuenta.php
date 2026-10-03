<?php
// Mi cuenta: cambiar la contraseña y la verificación en dos pasos. Con
// contraseña temporal, o siendo administrador sin dos pasos, la app trae
// aquí sí o sí hasta resolverlo (includes/auth.php).
require_once __DIR__ . '/includes/auth.php';

$errores = [];
$temporal = (int)$usuario_actual['debe_cambiar'] === 1;
$uid = (int)$usuario_actual['id'];

// Comprueba la contraseña actual (para lo que no debe poder hacer quien
// encuentre el móvil desbloqueado con la sesión abierta).
$password_ok = static function (string $pass) use ($pdo, $uid): bool {
    $st = $pdo->prepare('SELECT password FROM usuarios WHERE id = ?');
    $st->execute([$uid]);
    return password_verify($pass, (string)$st->fetchColumn());
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? 'password');
    if (!csrf_ok()) {
        $errores[] = 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.';
    } elseif ($accion === 'password') {
        $actual = (string)($_POST['actual'] ?? '');
        $nueva = (string)($_POST['nueva'] ?? '');
        $repite = (string)($_POST['repite'] ?? '');
        if (!$password_ok($actual)) $errores[] = 'La contraseña actual no es correcta.';
        elseif ($nueva !== $repite) $errores[] = 'Las dos contraseñas nuevas no coinciden.';
        elseif ($nueva === $actual) $errores[] = 'La nueva tiene que ser distinta de la actual.';
        else {
            try {
                cambiar_password($pdo, $uid, $nueva);
                // Sesión nueva tras cambiar la contraseña.
                if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
                anotar($pdo, $uid, 'cambió su contraseña');
                flash('ok', 'Contraseña cambiada.');
                redirigir(dos_pasos_obligatoria($usuario_actual) && !dos_pasos_activa($usuario_actual) ? 'cuenta.php#dos-pasos' : 'index.php');
            } catch (ErrorValidacion $ex) {
                $errores = $ex->errores;
            }
        }
    } elseif ($accion === 'activar-2p') {
        try {
            $secreto = (string)($_SESSION['totp_nuevo'] ?? '');
            if ($secreto === '') throw new ErrorValidacion(['La clave ha caducado: vuelve a empezar.']);
            $_SESSION['codigos_mostrar'] = activar_dos_pasos($pdo, $uid, $secreto, (string)($_POST['codigo'] ?? ''));
            unset($_SESSION['totp_nuevo']);
            flash('ok', 'Verificación en dos pasos activada.');
            redirigir('cuenta.php#dos-pasos');
        } catch (ErrorValidacion $ex) {
            $errores = $ex->errores;
        }
    } elseif ($accion === 'nuevos-codigos') {
        // Solo el de la app (no uno de recuperación): demuestra que se tiene el móvil.
        $paso = dos_pasos_activa($usuario_actual) ? totp_paso_valido((string)$usuario_actual['totp_secreto'], (string)($_POST['codigo'] ?? ''),
            isset($usuario_actual['totp_ultimo_paso']) ? (int)$usuario_actual['totp_ultimo_paso'] : null) : null;
        if ($paso === null) {
            $errores[] = 'Para generar códigos nuevos, escribe el código que da ahora la app.';
        } else {
            $pdo->prepare('UPDATE usuarios SET totp_ultimo_paso = ? WHERE id = ?')->execute([$paso, $uid]);
            $_SESSION['codigos_mostrar'] = nuevos_codigos_recuperacion($pdo, $uid);
            anotar($pdo, $uid, 'generó códigos de recuperación nuevos');
            redirigir('cuenta.php#dos-pasos');
        }
    } elseif ($accion === 'cambiar-movil') {
        // Quita la actual y empieza otra: para un móvil nuevo o si se perdió el viejo.
        if (!$password_ok((string)($_POST['actual'] ?? ''))) {
            $errores[] = 'La contraseña no es correcta.';
        } else {
            quitar_dos_pasos($pdo, $uid);
            unset($_SESSION['totp_nuevo']);
            flash('aviso', 'Verificación quitada. Configúrala con el móvil nuevo.');
            redirigir('cuenta.php#dos-pasos');
        }
    }
}

// Releer: lo de arriba puede haber cambiado los dos pasos.
$usuario_actual = usuario($pdo, $uid);
$activa = dos_pasos_activa($usuario_actual);
if (!$activa && empty($_SESSION['totp_nuevo'])) $_SESSION['totp_nuevo'] = totp_nuevo_secreto();
$codigos = $_SESSION['codigos_mostrar'] ?? null;
unset($_SESSION['codigos_mostrar']);   // se enseñan UNA vez

cabecera('Mi cuenta', 'cuenta');
cabecera_pagina('Mi cuenta', e($usuario_actual['email']) . ' · ' . e(roles()[$usuario_actual['rol']] ?? ''), '', 'persona', $usuario_actual['persona_color'] ?? '#163300');
?>
<?php if ($temporal): ?>
  <div class="flash flash-aviso">Estás entrando con una contraseña temporal. Elige la tuya para seguir.</div>
<?php elseif (!$activa && dos_pasos_obligatoria($usuario_actual)): ?>
  <div class="flash flash-aviso">Como administrador, necesitas la verificación en dos pasos para seguir: con tu acceso se entra también en finanzas.</div>
<?php endif; ?>
<?php if ($errores): ?>
  <div class="flash flash-error" role="alert"><?php foreach ($errores as $err): ?><?= e($err) ?> <?php endforeach; ?></div>
<?php endif; ?>

<?php if ($codigos): ?>
  <section class="tarjeta tarjeta-estrecha" id="codigos">
    <h2><?= icono('llave') ?>Tus códigos de recuperación</h2>
    <p>Si pierdes el móvil, cada uno de estos códigos te deja entrar <strong>una vez</strong> en lugar del de la app. <strong>Guárdalos ahora</strong> fuera del móvil (en papel, en tu gestor de contraseñas): no se vuelven a enseñar.</p>
    <ul class="codigos-recuperacion">
      <?php foreach ($codigos as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php if (!$temporal): ?>
<section class="tarjeta tarjeta-estrecha" id="dos-pasos">
  <h2><?= icono('llave') ?>Verificación en dos pasos</h2>
  <?php if ($activa): ?>
    <p>Activada. Al entrar, después de la contraseña se pide el código de tu app. Te quedan <strong><?= codigos_recuperacion_restantes($usuario_actual) ?></strong> códigos de recuperación.</p>
    <details class="desplegable">
      <summary class="btn btn-sutil">Generar códigos de recuperación nuevos</summary>
      <form method="post" class="form-rejilla">
        <?= csrf_input() ?><input type="hidden" name="accion" value="nuevos-codigos">
        <div class="campo"><label for="codigo-nuevos">Código de la app</label>
          <input type="text" name="codigo" id="codigo-nuevos" required inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
        <div class="campo-ancho"><button class="btn btn-primario"><?= icono('check') ?>Generar (los viejos dejan de valer)</button></div>
      </form>
    </details>
    <details class="desplegable">
      <summary class="btn btn-sutil">He cambiado de móvil</summary>
      <form method="post" class="form-rejilla">
        <?= csrf_input() ?><input type="hidden" name="accion" value="cambiar-movil">
        <div class="campo"><label for="actual-movil">Tu contraseña</label>
          <input type="password" name="actual" id="actual-movil" required autocomplete="current-password"></div>
        <div class="campo-ancho"><button class="btn btn-sutil">Quitar y configurar de nuevo</button></div>
      </form>
    </details>
  <?php else: ?>
    <?php $secreto = (string)$_SESSION['totp_nuevo']; ?>
    <ol class="pasos">
      <li>Instala una app de autenticación en el móvil: Google Authenticator, Microsoft Authenticator o la de tu gestor de contraseñas.</li>
      <li>Desde el móvil, <a href="<?= e(totp_enlace($secreto, (string)$usuario_actual['email'])) ?>">pulsa aquí para añadir la cuenta</a>. O, en la app, «Introducir clave de configuración» y escribe:
        <p><code class="clave-totp"><?= e(totp_clave_legible($secreto)) ?></code></p></li>
      <li>Escribe el código de 6 cifras que te enseña la app:</li>
    </ol>
    <form method="post" class="form-rejilla">
      <?= csrf_input() ?><input type="hidden" name="accion" value="activar-2p">
      <div class="campo"><label for="codigo">Código</label>
        <input type="text" name="codigo" id="codigo" required inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
      <div class="campo-ancho"><button class="btn btn-primario"><?= icono('check') ?>Activar</button></div>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="tarjeta tarjeta-estrecha">
  <h2><?= icono('llave') ?>Cambiar la contraseña</h2>
  <form method="post" class="form-rejilla">
    <?= csrf_input() ?><input type="hidden" name="accion" value="password">
    <div class="campo campo-ancho"><label for="actual"><?= $temporal ? 'Contraseña temporal' : 'Contraseña actual' ?></label>
      <input type="password" name="actual" id="actual" required autocomplete="current-password"></div>
    <div class="campo"><label for="nueva">Nueva (mínimo <?= PASSWORD_MINIMO ?> caracteres)</label>
      <input type="password" name="nueva" id="nueva" required minlength="<?= PASSWORD_MINIMO ?>" autocomplete="new-password"></div>
    <div class="campo"><label for="repite">Repítela</label>
      <input type="password" name="repite" id="repite" required minlength="<?= PASSWORD_MINIMO ?>" autocomplete="new-password"></div>
    <div class="campo-ancho"><button class="btn btn-primario"><?= icono('check') ?>Guardar</button></div>
  </form>
  <p class="tenue">Consejo: tres o cuatro palabras que solo tú relaciones son más fáciles de recordar y más difíciles de adivinar que «P4ssw0rd!».</p>
</section>
<?php pie();
