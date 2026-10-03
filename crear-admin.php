<?php
// =====================================================================
//  Alta del PRIMER administrador. Solo funciona mientras no exista ningún
//  usuario; después no hace nada. Aun así, bórralo del servidor cuando
//  hayas entrado: el resto de accesos se dan desde Ajustes.
// =====================================================================
require_once __DIR__ . '/includes/sesion.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/app.php';

enviar_cabeceras_seguridad();
esquema_al_dia($pdo);

$ya_existe = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
$errores = [];
$v = ['nombre' => '', 'email' => ''];

if (!$ya_existe && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = ['nombre' => trim((string)($_POST['nombre'] ?? '')), 'email' => trim((string)($_POST['email'] ?? ''))];
    $pass = (string)($_POST['password'] ?? '');
    if (!csrf_ok()) {
        $errores[] = 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.';
    } elseif ($pass === '') {
        $errores[] = 'Elige una contraseña.';
    } else {
        try {
            $pdo->beginTransaction();
            // Su ficha en Personas («Yo»): así sus documentos y su salud ya
            // tienen a quién pertenecer.
            $persona_id = guardar_persona($pdo, ['nombre' => $v['nombre'], 'relacion' => 'Yo']);
            crear_usuario($pdo, $v + ['password' => $pass, 'rol' => 'admin', 'persona_id' => $persona_id]);
            $pdo->commit();
            redirigir('login.php?creada=1');
        } catch (ErrorValidacion $ex) {
            $pdo->rollBack();
            $errores = $ex->errores;
        }
    }
}

cabecera_publica('Primer acceso');
?>
    <?php if ($ya_existe): ?>
      <div class="flash flash-ok">La cuenta de administrador ya está creada.</div>
      <p class="tenue">Borra <code>crear-admin.php</code> del servidor y entra por <a href="<?= e(url('login.php')) ?>">la página de acceso</a>.</p>
    <?php else: ?>
      <p class="tenue">Crea tu cuenta de administrador. Los demás accesos de la familia se dan después, desde Ajustes.</p>
      <?php if ($errores): ?><div class="flash flash-error"><?= e(implode(' ', $errores)) ?></div><?php endif; ?>
      <form method="post" class="form-acceso">
        <?= csrf_input() ?>
        <label for="nombre">Tu nombre</label>
        <input type="text" id="nombre" name="nombre" value="<?= e($v['nombre']) ?>" required autofocus>
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($v['email']) ?>" required autocomplete="username">
        <label for="password">Contraseña (mínimo <?= PASSWORD_MINIMO ?> caracteres)</label>
        <input type="password" id="password" name="password" required minlength="<?= PASSWORD_MINIMO ?>" autocomplete="new-password">
        <button class="btn btn-primario btn-bloque">Crear cuenta</button>
      </form>
    <?php endif; ?>
<?php pie_publico();
