<?php
// Las personas de la casa (tengan acceso o no).
require_once __DIR__ . '/includes/auth.php';

$uid = (int)$usuario_actual['id'];
$errores = [];
$editar = !empty($_GET['editar']) ? persona($pdo, (int)$_GET['editar']) : null;
$valores = $editar ?: ['nombre' => '', 'relacion' => '', 'fecha_nacimiento' => '', 'color' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)($_POST['persona_id'] ?? 0);
    if (!csrf_ok()) {
        $errores[] = 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.';
    } else {
        try {
            switch ((string)($_POST['accion'] ?? '')) {
                case 'guardar':
                    guardar_persona($pdo, $_POST, $pid ?: null, $uid);
                    flash('ok', $pid ? 'Ficha actualizada.' : 'Persona añadida.');
                    redirigir('personas.php');
                case 'archivar':
                case 'recuperar':
                    cambiar_activa_persona($pdo, $pid, $_POST['accion'] === 'recuperar', $uid);
                    redirigir('personas.php');
            }
        } catch (ErrorValidacion $ex) {
            $errores = $ex->errores;
            $valores = array_merge($valores, array_intersect_key($_POST, $valores));
        }
    }
}

$todas = personas($pdo, false);
cabecera('Personas', 'personas');
cabecera_pagina('Personas', 'La familia. Cada uno tiene sus documentos, su salud y sus cosas; no todos necesitan acceso a la app.', '', 'familia', '#b8457c');
?>
<?php if ($errores): ?>
  <div class="flash flash-error" role="alert"><ul><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="panel-rejilla">
  <section>
    <?php if (!$todas): ?><div class="tarjeta vacio"><p>Empieza por ti y luego añade al resto.</p></div><?php endif; ?>
    <div class="rejilla rejilla-elementos">
      <?php foreach ($todas as $p): ?>
        <div class="tarjeta tarjeta-persona<?= $p['activa'] ? '' : ' archivada' ?>" style="--c:<?= e($p['color']) ?>">
          <div class="te-cabecera">
            <?= avatar($p['nombre'], $p['color'], 'avatar avatar-grande') ?>
            <div>
              <h3><?= e($p['nombre']) ?></h3>
              <span class="tenue"><?= e($p['relacion'] ?: '—') ?><?= ($ed = edad($p['fecha_nacimiento'])) !== null ? ' · ' . $ed . ' años' : '' ?></span>
            </div>
          </div>
          <?php
            // Un resumen por sección, no la lista entera: con 19 cosas a su
            // nombre la tarjeta se volvía ilegible. Cada fila lleva a la
            // sección filtrada por la persona.
            $suyas = elementos_de_persona($pdo, (int)$p['id']);
            $tiene = [];
            $por_seccion = [];
            foreach ($suyas as $el) {
                $tiene[$el['seccion'] . '/' . $el['tipo']] = true;
                $por_seccion[$el['seccion']] = ($por_seccion[$el['seccion']] ?? 0) + 1;
            }
          ?>
          <p class="tenue"><?= $suyas ? count($suyas) . ' cosas a su nombre' : 'Nada a su nombre todavía' ?><?= $p['usuario_nombre'] ? ' · tiene acceso' : '' ?></p>
          <?php if ($por_seccion): ?>
            <ul class="persona-secciones">
              <?php foreach (secciones() as $s => $def): ?>
                <?php if (empty($por_seccion[$s])) continue; ?>
                <li><a href="<?= e(url('seccion.php?s=' . $s . '&persona=' . $p['id'] . '&lista=1')) ?>" style="--c:<?= e($def['color']) ?>">
                  <?= icono($def['icono']) ?><span><?= e($def['nombre']) ?></span><strong><?= (int)$por_seccion[$s] ?></strong>
                </a></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($p['activa']): ?>
            <div class="enlaces-persona">
              <?php foreach (['documentos' => 'dni', 'salud' => 'ficha'] as $s => $t): ?>
                <?php if (empty($tiene[$s . '/' . $t])): ?>
                  <a class="enlace-tenue" href="<?= e(url('elemento-editar.php?s=' . $s . '&t=' . $t . '&persona=' . $p['id'])) ?>"><?= icono('mas', 'ico ico-mini') ?><?= e(tipo_def($s, $t)['nombre']) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="acciones-tarjeta">
            <a class="btn btn-sutil" href="<?= e(url('personas.php?editar=' . $p['id'])) ?>"><?= icono('editar') ?>Editar</a>
            <form method="post"<?= $p['activa'] ? ' data-confirmar="¿Archivar a ' . e($p['nombre']) . '? Sus cosas se conservan."' : '' ?>>
              <?= csrf_input() ?><input type="hidden" name="persona_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="accion" value="<?= $p['activa'] ? 'archivar' : 'recuperar' ?>">
              <button class="btn-icono" title="<?= $p['activa'] ? 'Archivar' : 'Recuperar' ?>"><?= icono('archivar') ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <aside class="tarjeta" id="formulario">
    <div class="tarjeta-cabecera"><h2><?= $editar ? 'Editar a ' . e($editar['nombre']) : 'Añadir persona' ?></h2></div>
    <form method="post" class="form-rejilla">
      <?= csrf_input() ?>
      <input type="hidden" name="accion" value="guardar">
      <?php if ($editar): ?><input type="hidden" name="persona_id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
      <div class="campo campo-ancho"><label>Nombre</label><input type="text" name="nombre" value="<?= e($valores['nombre']) ?>" required maxlength="80"></div>
      <div class="campo"><label>Relación</label><select name="relacion"><?= opciones_html(array_combine(relaciones(), relaciones()), $valores['relacion']) ?></select></div>
      <div class="campo"><label>Nacimiento <span class="tenue">(opcional)</span></label><input type="date" name="fecha_nacimiento" value="<?= e((string)$valores['fecha_nacimiento']) ?>"></div>
      <div class="campo campo-ancho"><span class="etiqueta">Color</span>
        <div class="colores">
          <?php foreach (colores_persona() as $c): ?>
            <label class="color" style="--c:<?= e($c) ?>"><input type="radio" name="color" value="<?= e($c) ?>"<?= $valores['color'] === $c ? ' checked' : '' ?>><span></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="campo-ancho acciones-form">
        <button class="btn btn-primario"><?= icono('check') ?>Guardar</button>
        <?php if ($editar): ?><a class="btn btn-sutil" href="<?= e(url('personas.php')) ?>">Cancelar</a><?php endif; ?>
      </div>
    </form>
  </aside>
</div>
<?php pie();
