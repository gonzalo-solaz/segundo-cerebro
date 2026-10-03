<?php
// Mi cuenta: cambiar la contraseña. Con contraseña temporal, la app trae
// aquí sí o sí hasta que se cambie.
require_once __DIR__ . '/includes/auth.php';

$errores = [];
$temporal = (int)$usuario_actual['debe_cambiar'] === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');
    $repite = (string)($_POST['repite'] ?? '');
    $st = $pdo->prepare('SELECT password FROM usuarios WHERE id = ?');
    $st->execute([$usuario_actual['id']]);
    $hash = (string)$st->fetchColumn();

    if (!csrf_ok()) $errores[] = 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.';
    elseif (!password_verify($actual, $hash)) $errores[] = 'La contraseña actual no es correcta.';
    elseif ($nueva !== $repite) $errores[] = 'Las dos contraseñas nuevas no coinciden.';
    elseif ($nueva === $actual) $errores[] = 'La nueva tiene que ser distinta de la actual.';
    else {
        try {
            cambiar_password($pdo, (int)$usuario_actual['id'], $nueva);
            // Sesión nueva tras cambiar la contraseña.
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            anotar($pdo, (int)$usuario_actual['id'], 'cambió su contraseña');
            flash('ok', 'Contraseña cambiada.');
            redirigir('index.php');
        } catch (ErrorValidacion $ex) {
            $errores = $ex->errores;
        }
    }
}

cabecera('Mi cuenta', 'cuenta');
cabecera_pagina('Mi cuenta', e($usuario_actual['email']) . ' · ' . e(roles()[$usuario_actual['rol']] ?? ''), '', 'persona', $usuario_actual['persona_color'] ?? '#405189');
?>
<?php if ($temporal): ?>
  <div class="flash flash-aviso">Estás entrando con una contraseña temporal. Elige la tuya para seguir.</div>
<?php endif; ?>
<?php if ($errores): ?>
  <div class="flash flash-error" role="alert"><?php foreach ($errores as $err): ?><?= e($err) ?> <?php endforeach; ?></div>
<?php endif; ?>

<section class="tarjeta tarjeta-estrecha">
  <h2><?= icono('llave') ?>Cambiar la contraseña</h2>
  <form method="post" class="form-rejilla">
    <?= csrf_input() ?>
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
