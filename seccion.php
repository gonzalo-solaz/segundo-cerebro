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
// Trabajo tiene su propio panel con pestañas (Panel · Mi puesto · Equipo).
if ($clave === 'trabajo' && !$archivados && empty($_GET['lista'])) redirigir('trabajo.php');

// Lo que cuelga de otro elemento de esta misma sección (el equipamiento de una
// vivienda) se ve dentro de la ficha del padre, no suelto en el listado. Si el
// padre no está en la lista (archivado), se deja a la vista para no perderlo.
$en_lista = array_flip(array_column($elementos, 'id'));
$elementos = array_values(array_filter($elementos, static fn($el) => $el['enlace_id'] === null || !isset($en_lista[$el['enlace_id']])));
$avisos = agenda($pdo, 365, $clave);

// En Salud, tratamientos, gafas y peso viven dentro de la ficha médica de su
// persona. Solo se dejan sueltos los de quien aún no tiene ficha (para no perderlos).
if ($clave === 'salud') {
    $con_ficha = [];
    foreach ($elementos as $el) if ($el['tipo'] === 'ficha' && $el['persona_id']) $con_ficha[$el['persona_id']] = true;
    $elementos = array_values(array_filter($elementos, static fn($el) => !isset(tipos_de_la_ficha_medica()[$el['tipo']]) || !isset($con_ficha[$el['persona_id']])));
}

// «persona=ID» (desde la tarjeta de la persona): solo lo suyo y sus avisos.
$filtro_persona = !empty($_GET['persona']) ? persona($pdo, (int)$_GET['persona']) : null;
if ($filtro_persona) {
    $pid = (int)$filtro_persona['id'];
    $elementos = array_values(array_filter($elementos, static fn($el) => (int)$el['persona_id'] === $pid));
    $ids = array_flip(array_column($elementos, 'id'));
    $avisos = array_values(array_filter($avisos, static fn($v) => $v['elemento_id'] && isset($ids[$v['elemento_id']])));
}
$proximo = [];
foreach ($avisos as $v) {
    if ($v['elemento_id'] && !isset($proximo[$v['elemento_id']])) $proximo[$v['elemento_id']] = $v;
}
$enlazados = resumen_enlazados($pdo);
$por_tipo = [];
foreach ($elementos as $el) $por_tipo[$el['tipo']][] = $el;
$volver = 'seccion.php?s=' . $clave . ($filtro_persona ? '&persona=' . $filtro_persona['id'] . '&lista=1' : '');

$botones = '';
foreach ($sec['tipos'] as $t => $def) {
    if ($clave === 'salud' && isset(tipos_de_la_ficha_medica()[$t])) continue;   // se añaden desde la ficha
    $botones .='<a class="btn btn-sutil" href="' . e(url('elemento-editar.php?s=' . $clave . '&t=' . $t)) . '">'
              . icono('mas') . e($def['nombre']) . '</a>';
}

if ($clave === 'salud' && elementos_peso($pdo)) {
    $botones .= '<a class="btn btn-sutil btn-ir" href="' . e(url('peso.php')) . '">' . icono('bascula') . 'Peso y pautas</a>';
}
if ($clave === 'contratos') {
    $botones .= '<a class="btn btn-sutil btn-ir" href="' . e(url('gastos-fijos.php')) . '">' . icono('cartera') . 'Gastos fijos</a>';
    $botones .= '<a class="btn btn-sutil btn-ir" href="' . e(url('gasto-suministros.php')) . '">' . icono('historial') . 'Gasto en suministros</a>';
    if (elementos_comunidad($pdo)) {
        $botones .= '<a class="btn btn-sutil btn-ir" href="' . e(url('gasto-comunidad.php')) . '">' . icono('historial') . 'Gasto en comunidad</a>';
    }
}

cabecera($sec['nombre'], 'seccion:' . $clave);
cabecera_pagina($sec['nombre'], e($sec['descripcion']), $botones, $sec['icono'], $sec['color']);
?>
<?php if ($filtro_persona): ?>
  <div class="filtros">
    <a class="chip chip-activo" href="<?= e(url('seccion.php?s=' . $clave . '&lista=1')) ?>" title="Quitar el filtro">Solo de <?= e($filtro_persona['nombre']) ?> ×</a>
    <a class="chip" href="<?= e(url('personas.php')) ?>"><?= icono('atras', 'ico ico-mini') ?>Personas</a>
  </div>
<?php endif; ?>

<?php if ($clave === 'familia' && !$archivados): ?>
  <?php
  $gente = personas($pdo);
  if ($filtro_persona) $gente = array_values(array_filter($gente, static fn($p) => (int)$p['id'] === (int)$filtro_persona['id']));
  $de_persona = [];
  foreach ($elementos as $el) $de_persona[(int)$el['persona_id']][] = $el;
  ?>
  <div class="familia-rejilla">
    <?php foreach ($gente as $p): ?>
      <?php $suyos = $de_persona[$p['id']] ?? []; ?>
      <article class="tarjeta familia-persona">
        <header class="fp-cabecera">
          <?= avatar($p['nombre'], $p['color'], 'avatar avatar-grande') ?>
          <div>
            <h2><a href="<?= e(url('personas.php?editar=' . $p['id'])) ?>"><?= e($p['nombre']) ?></a></h2>
            <p class="tenue"><?= e($p['relacion']) ?><?= ($ed = edad($p['fecha_nacimiento'])) !== null ? ' · ' . $ed . ' años' : '' ?></p>
          </div>
        </header>
        <?php foreach ($suyos as $el): ?>
          <?php $prox = $proximo[$el['id']] ?? null; ?>
          <a class="fp-elemento" href="<?= e(url('elemento.php?id=' . $el['id'])) ?>">
            <span class="fp-tipo"><?= e($sec['tipos'][$el['tipo']]['nombre'] ?? $el['tipo']) ?></span>
            <strong><?= e($el['nombre']) ?></strong>
            <dl class="resumen">
              <?php foreach (resumen_elemento($el) as [$etq, $val]): ?>
                <div><dt><?= e($etq) ?></dt><dd><?= e(recortar($val, 80)) ?></dd></div>
              <?php endforeach; ?>
            </dl>
            <?php if ($prox): ?>
              <span class="ts-proximo venc-<?= e($prox['situacion']) ?>"><?= icono('reloj', 'ico ico-mini') ?><span><?= e(titulo_sin_elemento($prox['titulo'], $el['nombre'])) ?> · <?= e(fecha_corta($prox['fecha'])) ?> · <?= e(relativo($prox['dias'])) ?></span></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$suyos): ?><p class="vacio-mini"><?= ($ed ?? null) !== null && $ed >= 18 ? 'Nada apuntado.' : 'Todavía sin colegio ni actividades.' ?></p><?php endif; ?>
        <footer class="fp-pie">
          <?php foreach (['colegio' => 'Colegio', 'actividad' => 'Actividad', 'fecha' => 'Fecha'] as $t => $txt): ?>
            <a class="chip chip-boton" href="<?= e(url('elemento-editar.php?s=familia&t=' . $t . '&persona=' . $p['id'])) ?>"><?= icono('mas', 'ico ico-mini') ?><?= e($txt) ?></a>
          <?php endforeach; ?>
        </footer>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="pie-seccion"><a class="enlace-tenue" href="<?= e(url('personas.php')) ?>"><?= icono('mas', 'ico ico-mini') ?>Gestionar personas</a></p>
<?php endif; ?>

<?php if ($clave === 'familia' && !$archivados): ?>
<div class="seccion-rejilla">
  <div>
    <?php $sin_persona = array_filter($elementos, static fn($el) => !$el['persona_id']); ?>
    <?php if ($sin_persona): ?>
      <h2 class="titulo-bloque">Sin persona asignada</h2>
      <div class="rejilla rejilla-elementos">
        <?php foreach ($sin_persona as $el): ?>
          <a class="tarjeta tarjeta-elemento" href="<?= e(url('elemento.php?id=' . $el['id'])) ?>"><div class="te-cabecera"><h3><?= e($el['nombre']) ?></h3></div>
            <dl class="resumen"><?php foreach (resumen_elemento($el) as [$etq, $val]): ?><div><dt><?= e($etq) ?></dt><dd><?= e(recortar($val, 80)) ?></dd></div><?php endforeach; ?></dl></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="pie-seccion"><a class="enlace-tenue" href="<?= e(url('seccion.php?s=familia&archivados=1')) ?>"><?= icono('archivar', 'ico ico-mini') ?>Ver archivados</a></p>
  </div>
  <aside class="tarjeta">
    <div class="tarjeta-cabecera"><h2><?= icono('agenda') ?>Avisos de <?= e($sec['nombre']) ?></h2></div>
    <?php if (!$avisos): ?><p class="vacio-mini">Nada en los próximos 12 meses.</p><?php endif; ?>
    <?php foreach ($avisos as $v) fila_vencimiento($v, $volver, false); ?>
    <?php formulario_vencimiento($clave, null, $volver); ?>
  </aside>
</div>
<?php pie(); return; endif; ?>

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
              <?php if ($el['tipo'] === 'peso' && $clave === 'salud'): ?>
                <?php foreach (resumen_peso($pdo, $el) as [$etq, $val]): ?><div><dt><?= e($etq) ?></dt><dd><?= e($val) ?></dd></div><?php endforeach; ?>
              <?php endif; ?>
              <?php if (!empty($el['enlace_nombre'])): ?>
                <div><dt><?= e(tipo_def($el['seccion'], $el['tipo'])['enlace']['etiqueta']) ?></dt><dd><?= e(recortar($el['enlace_nombre'], 80)) ?></dd></div>
              <?php endif; ?>
              <?php if (!empty($enlazados[$el['id']])): $en = $enlazados[$el['id']]; ?>
                <div><dt>Contratos y seguros</dt><dd><?= (int)$en['n'] ?><?= $en['mensual'] > 0 ? ' · ' . e(eur($en['mensual'])) . ' al mes' : '' ?></dd></div>
              <?php endif; ?>
            </dl>
            <?php if ($prox): ?>
              <p class="ts-proximo venc-<?= e($prox['situacion']) ?>"><?= icono('reloj', 'ico ico-mini') ?><span><?= e(titulo_sin_elemento($prox['titulo'], $el['nombre'])) ?> · <?= e(fecha_corta($prox['fecha'])) ?> · <?= e(relativo($prox['dias'])) ?></span></p>
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
