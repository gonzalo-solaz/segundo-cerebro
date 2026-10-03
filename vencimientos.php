<?php
// La agenda completa, y el único sitio que procesa los avisos: crear,
// marcar hecho, editar y borrar (los formularios de las demás páginas
// envían aquí, con «volver» para regresar a donde estaban).
require_once __DIR__ . '/includes/auth.php';

$uid = (int)$usuario_actual['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $volver = volver_seguro($_POST['volver'] ?? '', 'vencimientos.php');
    $vid = (int)($_POST['vencimiento_id'] ?? 0);
    if (!csrf_ok()) {
        flash('error', 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.');
        redirigir($volver);
    }
    try {
        switch ((string)($_POST['accion'] ?? '')) {
            case 'crear':
                crear_vencimiento($pdo, $_POST, $uid);
                flash('ok', 'Recordatorio guardado.');
                break;
            case 'hecho':
                $v = vencimiento($pdo, $vid);
                $siguiente = marcar_hecho($pdo, $vid, $uid);
                if ($v) flash('ok', '«' . $v['titulo'] . '» hecho.' . ($siguiente ? ' El siguiente queda para el ' . fecha_es($siguiente) . '.' : ''));
                break;
            case 'editar':
                actualizar_vencimiento($pdo, $vid, $_POST, $uid);
                flash('ok', 'Aviso actualizado.');
                break;
            case 'borrar':
                borrar_vencimiento($pdo, $vid, $uid);
                flash('ok', 'Aviso borrado.');
                break;
        }
    } catch (ErrorValidacion $ex) {
        flash('error', implode(' ', $ex->errores));
    } catch (RuntimeException $ex) {
        flash('error', $ex->getMessage());
    }
    redirigir($volver);
}

$filtro = (string)($_GET['s'] ?? '');
if ($filtro !== '' && !seccion($filtro)) $filtro = '';
$editar = !empty($_GET['editar']) ? vencimiento($pdo, (int)$_GET['editar']) : null;
$volver_editar = volver_seguro($_GET['volver'] ?? '', 'vencimientos.php');
$pendientes = agenda($pdo, 36500, $filtro ?: null);
$grupos = agrupar_agenda($pendientes);
$hechos = hechos_recientes($pdo, 20, $filtro ?: null);
$aqui = 'vencimientos.php' . ($filtro ? '?s=' . $filtro : '');

cabecera('Agenda', 'agenda');
cabecera_pagina('Agenda', 'Todo lo que vence o toca hacer, de todas las secciones.', '', 'agenda', '#405189');
?>

<?php if ($editar): ?>
  <section class="tarjeta tarjeta-destacada">
    <div class="tarjeta-cabecera"><h2><?= icono('editar') ?>Cambiar «<?= e($editar['titulo']) ?>»</h2></div>
    <?php if ($editar['automatico']): ?>
      <p class="nota">Este aviso sale de la ficha de <a href="<?= e(url('elemento.php?id=' . $editar['elemento_id'])) ?>"><?= e($editar['elemento_nombre']) ?></a>: si cambias la fecha aquí, también se cambia allí.</p>
    <?php endif; ?>
    <form method="post" class="form-rejilla">
      <?= csrf_input() ?>
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="vencimiento_id" value="<?= (int)$editar['id'] ?>">
      <input type="hidden" name="volver" value="<?= e($volver_editar) ?>">
      <div class="campo"><label>Qué hay que hacer</label><input type="text" name="titulo" value="<?= e($editar['titulo']) ?>" required maxlength="150"></div>
      <div class="campo"><label>Fecha</label><input type="date" name="fecha" value="<?= e($editar['fecha']) ?>" required></div>
      <div class="campo"><label>Se repite</label><select name="repetir_meses"><?= opciones_html(opciones_repetir() + [$editar['repetir_meses'] => texto_repetir($editar['repetir_meses'])], $editar['repetir_meses'], false) ?></select></div>
      <div class="campo"><label>Avisar con</label><div class="con-sufijo"><input type="number" name="aviso_dias" value="<?= (int)$editar['aviso_dias'] ?>" min="0" max="365"><span>días</span></div></div>
      <div class="campo campo-ancho"><label>Notas</label><input type="text" name="notas" value="<?= e((string)$editar['notas']) ?>"></div>
      <div class="campo-ancho acciones-form">
        <button class="btn btn-primario"><?= icono('check') ?>Guardar</button>
        <a class="btn btn-sutil" href="<?= e(url($volver_editar)) ?>">Cancelar</a>
      </div>
    </form>
    <?php if (!$editar['automatico'] || $editar['estado'] === 'hecho'): ?>
      <form method="post" data-confirmar="¿Borrar este aviso?" class="borrar-aparte">
        <?= csrf_input() ?>
        <input type="hidden" name="accion" value="borrar">
        <input type="hidden" name="vencimiento_id" value="<?= (int)$editar['id'] ?>">
        <input type="hidden" name="volver" value="vencimientos.php">
        <button class="btn btn-peligro"><?= icono('papelera') ?>Borrar el aviso</button>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>

<nav class="filtros">
  <a class="chip <?= $filtro === '' ? 'chip-activo' : '' ?>" href="<?= e(url('vencimientos.php')) ?>">Todo</a>
  <?php foreach (secciones() as $k => $s): ?>
    <a class="chip <?= $filtro === $k ? 'chip-activo' : '' ?>" style="--c:<?= e($s['color']) ?>" href="<?= e(url('vencimientos.php?s=' . $k)) ?>"><?= icono($s['icono'], 'ico ico-mini') ?><?= e($s['nombre']) ?></a>
  <?php endforeach; ?>
</nav>

<div class="panel-rejilla">
  <section class="tarjeta">
    <?php if (!$pendientes): ?><p class="vacio-mini">No hay nada pendiente<?= $filtro ? ' en ' . e(seccion($filtro)['nombre']) : '' ?>.</p><?php endif; ?>
    <?php foreach (['vencido' => 'Vencido', 'pronto' => 'Toca ya', 'futuro' => 'Más adelante'] as $g => $titulo): ?>
      <?php if (!$grupos[$g]) continue; ?>
      <h3 class="grupo grupo-<?= $g ?>"><?= e($titulo) ?> <span class="tenue">(<?= count($grupos[$g]) ?>)</span></h3>
      <?php foreach ($grupos[$g] as $v) fila_vencimiento($v, $aqui, $filtro === ''); ?>
    <?php endforeach; ?>
  </section>

  <aside>
    <section class="tarjeta">
      <?php formulario_vencimiento($filtro, null, $aqui, $editar === null && isset($_GET['nuevo'])); ?>
    </section>
    <section class="tarjeta">
      <div class="tarjeta-cabecera"><h2><?= icono('check') ?>Hechos hace poco</h2></div>
      <?php if (!$hechos): ?><p class="vacio-mini">Todavía nada.</p><?php endif; ?>
      <ul class="lista-hechos">
        <?php foreach ($hechos as $h): ?>
          <li><?= icono('check', 'ico ico-mini') ?><?= e($h['titulo']) ?> <span class="tenue">· <?= e(fecha_es($h['hecho_en'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
  </aside>
</div>
<?php pie();
