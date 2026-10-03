<?php
// Solo administradores: accesos de la familia y estado del sistema.
require_once __DIR__ . '/includes/auth.php';
exigir_admin();

$uid = (int)$usuario_actual['id'];
$clave_nueva = null;   // contraseña temporal recién generada: se enseña UNA vez

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $obj = (int)($_POST['usuario_id'] ?? 0);
    if (!csrf_ok()) {
        flash('error', 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.');
        redirigir('ajustes.php');
    }
    try {
        switch ((string)($_POST['accion'] ?? '')) {
            case 'crear':
                [$nuevo, $pass] = crear_usuario($pdo, $_POST, $uid);
                $clave_nueva = ['nombre' => trim((string)$_POST['nombre']), 'email' => strtolower(trim((string)$_POST['email'])), 'pass' => $pass];
                break;
            case 'temporal':
                $u = usuario($pdo, $obj);
                if (!$u) throw new RuntimeException('Ese acceso no existe.');
                $pass = contrasena_temporal();
                cambiar_password($pdo, $obj, $pass, true);
                anotar($pdo, $uid, "generó una contraseña temporal para {$u['nombre']}");
                $clave_nueva = ['nombre' => $u['nombre'], 'email' => $u['email'], 'pass' => $pass];
                break;
            case 'estado':
                if ($obj === $uid) throw new RuntimeException('No puedes suspender tu propio acceso.');
                $u = usuario($pdo, $obj);
                if (!$u) throw new RuntimeException('Ese acceso no existe.');
                $nuevo_estado = $u['estado'] === 'activo' ? 'suspendido' : 'activo';
                $pdo->prepare('UPDATE usuarios SET estado = ? WHERE id = ?')->execute([$nuevo_estado, $obj]);
                anotar($pdo, $uid, ($nuevo_estado === 'activo' ? 'reactivó' : 'suspendió') . " el acceso de {$u['nombre']}");
                flash('ok', $nuevo_estado === 'activo' ? 'Acceso reactivado.' : 'Acceso suspendido: se le cierra la sesión al instante.');
                redirigir('ajustes.php');
            case 'rol':
                if ($obj === $uid) throw new RuntimeException('No puedes cambiar tu propio rol.');
                $rol = (string)($_POST['rol'] ?? '');
                if (!isset(roles()[$rol])) throw new RuntimeException('Rol no válido.');
                $pdo->prepare('UPDATE usuarios SET rol = ? WHERE id = ?')->execute([$rol, $obj]);
                flash('ok', 'Rol cambiado.');
                redirigir('ajustes.php');
            case 'quitar-2p':
                // Para quien ha perdido el móvil y los códigos: al volver a entrar
                // (con la contraseña) se le pide configurarla de nuevo si es admin.
                if ($obj === $uid) throw new RuntimeException('La tuya se cambia en «Mi cuenta».');
                quitar_dos_pasos($pdo, $obj, $uid);
                flash('ok', 'Verificación en dos pasos quitada.');
                redirigir('ajustes.php');
            case 'persona':
                $pid = (int)($_POST['persona_id'] ?? 0) ?: null;
                if ($pid && !persona($pdo, $pid)) throw new RuntimeException('Esa persona no existe.');
                $pdo->prepare('UPDATE usuarios SET persona_id = ? WHERE id = ?')->execute([$pid, $obj]);
                flash('ok', 'Vinculado.');
                redirigir('ajustes.php');
        }
    } catch (ErrorValidacion $ex) {
        flash('error', implode(' ', $ex->errores));
        redirigir('ajustes.php');
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
        redirigir('ajustes.php');
    }
}

$usuarios = usuarios_todos($pdo);
$gente = personas($pdo);
$vigilancia = estado_vigilancia($pdo);
$espacio = espacio_documentos($pdo);
$migraciones = migraciones_aplicadas($pdo);
$ruta_cron = str_replace('\\', '/', (string)realpath(__DIR__ . '/cron/diario.php'));

cabecera('Ajustes', 'ajustes');
cabecera_pagina('Ajustes', 'Accesos de la familia y estado del sistema.', '', 'ajustes', '#163300');
?>

<?php if ($clave_nueva): ?>
  <section class="tarjeta tarjeta-destacada">
    <h2><?= icono('llave') ?>Acceso para <?= e($clave_nueva['nombre']) ?></h2>
    <p>Pásale estos datos (por WhatsApp, en persona…). <strong>No se volverán a mostrar.</strong> Al entrar, la app le pedirá que elija su propia contraseña.</p>
    <dl class="datos">
      <div><dt>Dirección</dt><dd><?= e(URL_APP ?: url('login.php')) ?></dd></div>
      <div><dt>Email</dt><dd><?= e($clave_nueva['email']) ?></dd></div>
      <div><dt>Contraseña temporal</dt><dd><code class="clave"><?= e($clave_nueva['pass']) ?></code></dd></div>
    </dl>
  </section>
<?php endif; ?>

<section class="tarjeta">
  <div class="tarjeta-cabecera"><h2><?= icono('persona') ?>Accesos</h2></div>
  <div class="tabla-scroll">
    <table class="tabla tabla-apilada">
      <thead><tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Es…</th><th>Último acceso</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($usuarios as $u): ?>
          <?php $yo = (int)$u['id'] === $uid; ?>
          <tr class="<?= $u['estado'] !== 'activo' ? 'apagado' : '' ?>">
            <td class="celda-titulo"><strong><?= e($u['nombre']) ?></strong><?= $yo ? ' <span class="tenue">(tú)</span>' : '' ?>
              <?php if ($u['estado'] !== 'activo'): ?><span class="chip">suspendido</span><?php endif; ?>
              <?php if ((int)$u['debe_cambiar'] === 1): ?><span class="chip" title="Aún no ha cambiado la contraseña temporal">temporal</span><?php endif; ?>
              <?php if (dos_pasos_activa($u)): ?><span class="chip" title="Verificación en dos pasos activada">2 pasos</span><?php endif; ?></td>
            <td data-label="Email"><?= e($u['email']) ?></td>
            <td data-label="Rol">
              <?php if ($yo): ?><?= e(roles()[$u['rol']] ?? $u['rol']) ?><?php else: ?>
                <form method="post" class="en-linea"><?= csrf_input() ?><input type="hidden" name="accion" value="rol"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                  <select name="rol" data-auto-enviar><?= opciones_html(roles(), $u['rol'], false) ?></select><noscript><button class="btn btn-sutil">Cambiar</button></noscript></form>
              <?php endif; ?>
            </td>
            <td data-label="Persona">
              <form method="post" class="en-linea"><?= csrf_input() ?><input type="hidden" name="accion" value="persona"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                <select name="persona_id" data-auto-enviar><?= opciones_html(array_column($gente, 'nombre', 'id'), $u['persona_id']) ?></select><noscript><button class="btn btn-sutil">Vincular</button></noscript></form>
            </td>
            <td class="tenue" data-label="Último acceso"><?= $u['ultimo_acceso'] ? e(fecha_es(substr($u['ultimo_acceso'], 0, 10))) : 'nunca' ?></td>
            <td class="acciones-fila">
              <form method="post" class="en-linea" data-confirmar="¿Generar una contraseña temporal nueva para <?= e($u['nombre']) ?>? La actual dejará de valer.">
                <?= csrf_input() ?><input type="hidden" name="accion" value="temporal"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                <button class="btn-icono" title="Nueva contraseña temporal"><?= icono('llave') ?></button></form>
              <?php if (!$yo && dos_pasos_activa($u)): ?>
                <form method="post" class="en-linea" data-confirmar="¿Quitar la verificación en dos pasos de <?= e($u['nombre']) ?>? Úsalo si ha perdido el móvil.">
                  <?= csrf_input() ?><input type="hidden" name="accion" value="quitar-2p"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sutil">Quitar 2 pasos</button></form>
              <?php endif; ?>
              <?php if (!$yo): ?>
                <form method="post" class="en-linea"<?= $u['estado'] === 'activo' ? ' data-confirmar="¿Suspender el acceso de ' . e($u['nombre']) . '?"' : '' ?>>
                  <?= csrf_input() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sutil"><?= $u['estado'] === 'activo' ? 'Suspender' : 'Reactivar' ?></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <details class="desplegable">
    <summary class="btn btn-sutil"><?= icono('mas') ?>Dar acceso a alguien</summary>
    <form method="post" class="form-rejilla">
      <?= csrf_input() ?><input type="hidden" name="accion" value="crear">
      <div class="campo"><label>Nombre</label><input type="text" name="nombre" required maxlength="80"></div>
      <div class="campo"><label>Email</label><input type="email" name="email" required></div>
      <div class="campo"><label>Rol</label><select name="rol"><?= opciones_html(roles(), 'miembro', false) ?></select>
        <small class="ayuda">Miembro: ve y edita todo, pero no gestiona accesos ni borra.</small></div>
      <div class="campo"><label>Es… <span class="tenue">(su ficha en Personas)</span></label><select name="persona_id"><?= opciones_html(array_column($gente, 'nombre', 'id'), '') ?></select></div>
      <div class="campo-ancho"><button class="btn btn-primario"><?= icono('llave') ?>Crear acceso con contraseña temporal</button></div>
    </form>
  </details>
</section>

<section class="tarjeta" id="sistema">
  <div class="tarjeta-cabecera"><h2><?= icono('ajustes') ?>Sistema</h2></div>
  <dl class="datos">
    <div class="dato-ancho"><dt>Aviso diario por correo</dt>
      <dd class="<?= $vigilancia['ok'] ? 'texto-ok' : 'texto-aviso' ?>"><?= e($vigilancia['texto']) ?></dd></div>
    <div><dt>Destinatarios</dt><dd><?= e(implode(', ', destinatarios_avisos()) ?: 'ninguno (EMAIL_AVISOS vacío en config.php)') ?></dd></div>
    <div><dt>API para Claude</dt><dd><?= API_CLAVE !== '' ? 'Activa' : 'Apagada (API_CLAVE vacía)' ?></dd></div>
    <div><dt>Archivos guardados</dt><dd><?= $espacio['n'] ?> · <?= e(tamano_legible($espacio['bytes'])) ?></dd></div>
    <div><dt>Esquema</dt><dd><?= count($migraciones) ?> migraciones (última: <?= e(end($migraciones)['nombre'] ?? '—') ?>)</dd></div>
    <div><dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd></div>
  </dl>
  <?php if (!$vigilancia['ok']): ?>
    <div class="nota">
      <p><strong>Para activar el aviso diario</strong>: hPanel → Avanzado → Trabajos Cron → «Personalizado», una vez al día (por ejemplo a las 7:00), con este comando:</p>
      <p><code class="copiable">/usr/bin/php <?= e($ruta_cron) ?></code></p>
      <p class="tenue">Copia la ruta tal cual. En finanzas el cron estuvo sin ejecutarse por una ruta sin <code>domains/gonzalosolaz.tech/</code>: hPanel no avisa, solo dice «Could not open input file» en el registro.</p>
    </div>
  <?php endif; ?>
</section>
<?php pie();
