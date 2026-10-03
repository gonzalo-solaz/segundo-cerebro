<?php
// Una sección: sus elementos agrupados por tipo y sus avisos.
require_once __DIR__ . '/includes/auth.php';

$clave = (string)($_GET['s'] ?? '');
$sec = seccion($clave);
if (!$sec) pagina_error(404, 'No encontrado', 'Esa sección no existe.');

$archivados = !empty($_GET['archivados']);
$elementos = elementos_de($pdo, $clave, !$archivados);

// Con una sola vivienda, entrar en Vivienda es entrar en su ficha. En cuanto
// haya otra, sale el listado. «lista=1» fuerza el listado (el contacto de
// confianza o una vivienda nueva se añaden desde ahí).
if ($clave === 'vivienda' && !$archivados && empty($_GET['lista'])) {
    $casas = array_values(array_filter($elementos, static fn($el) => $el['tipo'] === 'inmueble'));
    if (count($casas) === 1) redirigir('elemento.php?id=' . $casas[0]['id']);
}
$avisos = agenda($pdo, 365, $clave);
$proximo = [];
foreach ($avisos as $v) {
    if ($v['elemento_id'] && !isset($proximo[$v['elemento_id']])) $proximo[$v['elemento_id']] = $v;
}
$enlazados = resumen_enlazados($pdo);
$por_tipo = [];
foreach ($elementos as $el) $por_tipo[$el['tipo']][] = $el;
$volver = 'seccion.php?s=' . $clave;

$botones = '';
foreach ($sec['tipos'] as $t => $def) {
    $botones .= '<a class="btn btn-sutil" href="' . e(url('elemento-editar.php?s=' . $clave . '&t=' . $t)) . '">'
              . icono('mas') . e($def['nombre']) . '</a>';
}

if ($clave === 'contratos') {
    $botones .= '<a class="btn btn-sutil" href="' . e(url('gasto-suministros.php')) . '">' . icono('historial') . 'Gasto en suministros</a>';
    if (elementos_comunidad($pdo)) {
        $botones .= '<a class="btn btn-sutil" href="' . e(url('gasto-comunidad.php')) . '">' . icono('historial') . 'Gasto en comunidad</a>';
    }
}

cabecera($sec['nombre'], 'seccion:' . $clave);
cabecera_pagina($sec['nombre'], e($sec['descripcion']), $botones, $sec['icono'], $sec['color']);
?>

<?php if ($clave === 'familia'): ?>
  <?php $gente = personas($pdo); ?>
  <section class="personas-tira">
    <?php foreach ($gente as $p): ?>
      <a class="persona-mini" href="<?= e(url('personas.php?editar=' . $p['id'])) ?>">
        <?= avatar($p['nombre'], $p['color']) ?>
        <span><strong><?= e($p['nombre']) ?></strong><small class="tenue"><?= e($p['relacion']) ?><?= ($ed = edad($p['fecha_nacimiento'])) !== null ? ' · ' . $ed . ' años' : '' ?></small></span>
      </a>
    <?php endforeach; ?>
    <a class="persona-mini persona-nueva" href="<?= e(url('personas.php')) ?>"><?= icono('mas') ?><span>Gestionar personas</span></a>
  </section>
<?php endif; ?>

<div class="seccion-rejilla">
  <div>
    <?php if (!$elementos): ?>
      <div class="tarjeta vacio">
        <?php if ($archivados): ?>
          <p>No hay nada archivado en <?= e($sec['nombre']) ?>.</p>
        <?php else: ?>
          <p>Aún no hay nada en <?= e($sec['nombre']) ?>. Empieza con uno de los botones de arriba.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php foreach ($sec['tipos'] as $t => $def): ?>
      <?php if (empty($por_tipo[$t])) continue; ?>
      <h2 class="titulo-bloque"><?= e($def['nombre']) ?> <span class="tenue">(<?= count($por_tipo[$t]) ?>)</span></h2>
      <div class="rejilla rejilla-elementos">
        <?php foreach ($por_tipo[$t] as $el): ?>
          <?php $prox = $proximo[$el['id']] ?? null; ?>
          <a class="tarjeta tarjeta-elemento" href="<?= e(url('elemento.php?id=' . $el['id'])) ?>" style="--c:<?= e($sec['color']) ?>">
            <div class="te-cabecera">
              <h3><?= e($el['nombre']) ?></h3>
              <?= chip_persona($el['persona_nombre'], $el['persona_color']) ?>
            </div>
            <dl class="resumen">
              <?php foreach (resumen_elemento($el) as [$etq, $val]): ?>
                <div><dt><?= e($etq) ?></dt><dd><?= e(recortar($val, 80)) ?></dd></div>
              <?php endforeach; ?>
              <?php if (!empty($el['enlace_nombre'])): ?>
                <div><dt><?= e(tipo_def($el['seccion'], $el['tipo'])['enlace']['etiqueta']) ?></dt><dd><?= e(recortar($el['enlace_nombre'], 80)) ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($enlazados[$el['id']])): $en = $enlazados[$el['id']]; ?>
                <div><dt>Contratos y seguros</dt><dd><?= (int)$en['n'] ?><?= $en['mensual'] > 0 ? ' · ' . e(eur($en['mensual'])) . ' al mes' : '' ?></dd></div>
              <?php endif; ?>
            </dl>
            <?php if ($prox): ?>
              <p class="ts-proximo venc-<?= e($prox['situacion']) ?>"><?= icono('reloj', 'ico ico-mini') ?><?= e(fecha_corta($prox['fecha'])) ?> · <?= e(relativo($prox['dias'])) ?></p>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <p class="pie-seccion">
      <?php if ($archivados): ?>
        <a class="enlace-tenue" href="<?= e(url('seccion.php?s=' . $clave)) ?>"><?= icono('atras', 'ico ico-mini') ?>Volver a lo activo</a>
      <?php else: ?>
        <a class="enlace-tenue" href="<?= e(url('seccion.php?s=' . $clave . '&archivados=1')) ?>"><?= icono('archivar', 'ico ico-mini') ?>Ver archivados</a>
      <?php endif; ?>
    </p>
  </div>

  <aside class="tarjeta">
    <div class="tarjeta-cabecera"><h2><?= icono('agenda') ?>Avisos de <?= e($sec['nombre']) ?></h2></div>
    <?php if (!$avisos): ?><p class="vacio-mini">Nada en los próximos 12 meses.</p><?php endif; ?>
    <?php foreach ($avisos as $v) fila_vencimiento($v, $volver, false); ?>
    <?php formulario_vencimiento($clave, null, $volver); ?>
  </aside>
</div>
<?php pie();
