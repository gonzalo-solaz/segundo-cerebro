<?php
require_once __DIR__ . '/includes/sesion.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/antiabuso.php';

enviar_cabeceras_seguridad();
esquema_al_dia($pdo);

// Adónde volver después de entrar (lo pone auth.php: «finanzas-entrar.php?a=nomina.php»).
$volver = volver_seguro($_GET['volver'] ?? ($_POST['volver'] ?? ''));
if (!empty($_SESSION['usuario_id'])) redirigir($volver);

// Sin ningún usuario todavía, lo único que tiene sentido es crear el primero.
if ((int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0) redirigir('crear-admin.php');

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave_limite = 'login:' . ip_visitante();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!csrf_ok()) {
        $error = 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.';
    } elseif (!limite_ok($clave_limite, 10, 900)) {
        // 10 intentos cada 15 minutos: holgado para quien se equivoca de
        // tecla, muro para un script que prueba contraseñas.
        $error = 'Demasiados intentos desde tu conexión. Espera unos minutos.';
    } else {
        $st = $pdo->prepare('SELECT * FROM usuarios WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password'])) {
            if ($u['estado'] !== 'activo') {
                $error = 'Este acceso está suspendido. Habla con quien administra la app.';
            } else {
                limite_limpiar($clave_limite);
                if (dos_pasos_activa($u)) {
                    // Contraseña buena, pero aún NO hay sesión: falta el código de la app.
                    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
                    $_SESSION['pendiente_2p'] = (int)$u['id'];
                    $_SESSION['pendiente_2p_desde'] = time();
                    redirigir('verificar.php?volver=' . rawurlencode($volver));
                }
                abrir_sesion($pdo, $u);
                redirigir((int)$u['debe_cambiar'] === 1 ? 'cuenta.php' : $volver);
            }
        } else {
            // Mismo mensaje falle el email o la contraseña: decir cuál
            // confirmaría a un desconocido qué cuentas existen.
            $error = 'Email o contraseña incorrectos.';
        }
    }
}

$motivo = (string)($_GET['motivo'] ?? '');
if ($motivo === 'expirada')    $error = $error ?: 'Tu sesión se cerró por seguridad. Vuelve a entrar.';
if ($motivo === 'desactivada') $error = $error ?: 'Este acceso ya no está activo.';
$ok = ($_GET['creada'] ?? '') === '1' ? 'Cuenta creada. Ya puedes entrar.' : '';
if ($motivo === 'salida') $ok = 'Has salido. Hasta pronto.';

cabecera_publica('Entrar');
?>
    <?php if ($ok): ?><div class="flash flash-ok"><?= e($ok) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="form-acceso">
      <?= csrf_input() ?>
      <input type="hidden" name="volver" value="<?= e($volver) ?>">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
      <button class="btn btn-primario btn-bloque">Entrar</button>
    </form>
<?php pie_publico();
