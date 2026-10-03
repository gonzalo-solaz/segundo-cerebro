<?php
// Cuánto le cuesta a esta casa cada partida de la comunidad de propietarios
// (piscina, ascensor, limpieza…), sumando el desglose de los recibos. Los
// números salen solos de las partidas; las conclusiones son el campo
// «Análisis» de la ficha, que Claude pone al día con cada liquidación.
require_once __DIR__ . '/includes/auth.php';

$sec = seccion('contratos');
$comunidades = elementos_comunidad($pdo);
$el = null;
if (isset($_GET['id'])) {
    $el = elemento($pdo, (int)$_GET['id']);
    if (!$el || $el['seccion'] !== 'contratos' || $el['tipo'] !== 'comunidad') $el = null;
}
$el ??= $comunidades[0] ?? null;
$an = $el ? analisis_comunidad($pdo, $el['id']) : null;

// Barra de dos tramos (ordinario + extraordinario) en proporción a la partida más cara.
function barra_partida(float $ord, float $extra, float $max, string $color): string {
    if ($max <= 0) return '';
    $a = round($ord / $max * 100, 1);
    $b = round($extra / $max * 100, 1);
    return '<div style="display:flex;height:6px;margin-top:5px;max-width:160px" aria-hidden="true">'
         . '<span style="width:' . $a . '%;background:' . e($color) . '"></span>'
         . ($b > 0 ? '<span style="width:' . $b . '%;background:' . e($color) . ';opacity:.35"></span>' : '')
         . '</div>';
}

function porcentaje_partida(float $parte, float $total): string {
    $p = $parte / $total * 100;
    return ($p > 0 && $p < 1 ? '<1' : numero_es(round($p))) . ' %';
}

$num = 'class="num" style="text-align:right;white-space:nowrap"';
$volver = $el
    ? '<a class="btn btn-sutil" href="' . e(url('elemento.php?id=' . $el['id'])) . '">' . icono('atras') . 'Ficha</a>'
    : '<a class="btn btn-sutil" href="' . e(url('seccion.php?s=contratos')) . '">' . icono('atras') . 'Contratos</a>';
cabecera('Gasto en comunidad', 'seccion:contratos');
cabecera_pagina('Gasto en comunidad',
    '<a href="' . e(url('seccion.php?s=contratos')) . '">' . e($sec['nombre']) . '</a>'
    . ($el ? ' · <a href="' . e(url('elemento.php?id=' . $el['id'])) . '">' . e($el['nombre']) . '</a>' : ''),
    $volver, 'historial', $sec['color']);
?>

<?php if (!$el): ?>
  <div class="tarjeta vacio">
    <p>Aún no hay ninguna comunidad de propietarios.
      <a href="<?= e(url('elemento-editar.php?s=contratos&t=comunidad')) ?>">Añádela</a> y apunta cada liquidación en su historial como «Recibo».</p>
  </div>
<?php else: ?>

  <?php if (count($comunidades) > 1): ?>
    <p class="filtros">
      <?php foreach ($comunidades as $c): ?>
        <a class="chip chip-boton<?= $c['id'] === $el['id'] ? ' chip-activo' : '' ?>" href="<?= e(url('gasto-comunidad.php?id=' . $c['id'])) ?>"><?= e($c['nombre']) ?></a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <section class="tarjeta" id="analisis">
    <div class="tarjeta-cabecera"><h2>Análisis</h2></div>
    <?php if (trim((string)($el['datos']['analisis'] ?? '')) !== ''): ?>
      <p class="notas"><?= nl2br(e($el['datos']['analisis'])) ?></p>
    <?php else: ?>
      <p class="vacio-mini">Todavía sin conclusiones. Se escriben en el campo «Análisis» de la ficha.</p>
    <?php endif; ?>
  </section>

  <?php if (!$an['recibos']): ?>
    <div class="tarjeta vacio"><p>Aún no hay recibos. Apunta cada liquidación en el historial de la comunidad con el tipo «Recibo» y lo que te toca pagar.</p></div>
  <?php endif; ?>

  <?php foreach ($an['anios'] as $anio => $a): ?>
    <?php
      $suma = $a['ord'] + $a['extra'];
      $max = $a['categorias'] ? max(array_column($a['categorias'], 'total')) : 0.0;
    ?>
    <section class="tarjeta" id="anio-<?= (int)$anio ?>">
      <div class="tarjeta-cabecera">
        <h2><?= (int)$anio ?></h2>
        <span class="tenue"><strong><?= e(eur($a['pagado'])) ?></strong> · <?= (int)$a['n'] ?> <?= $a['n'] === 1 ? 'recibo' : 'recibos' ?></span>
      </div>
      <?php if ($a['desglosados']): ?>
        <p class="tenue">Sin obras ni extras, un recibo medio sale por <strong><?= e(eur($a['media_ordinaria'])) ?></strong>.
          <?php if ($a['extra'] > 0): ?>Las obras y gastos extraordinarios suman <strong><?= e(eur($a['extra'])) ?></strong>.<?php endif; ?></p>
      <?php endif; ?>
      <?php if ($a['sin_desglose']): ?>
        <p class="tenue"><?= (int)$a['sin_desglose'] ?> <?= $a['sin_desglose'] === 1 ? 'recibo no tiene' : 'recibos no tienen' ?> desglose por partidas: no entran en la tabla.</p>
      <?php endif; ?>
      <?php if ($a['categorias']): ?>
        <div class="tabla-scroll">
          <table class="tabla">
            <thead><tr><th>Partida</th><th <?= $num ?>>Te cuesta</th></tr></thead>
            <tbody>
              <?php foreach ($a['categorias'] as $cat => $c): ?>
                <tr>
                  <td><?= e($cat) ?><?php if ($suma > 0): ?> <span class="tenue" style="white-space:nowrap">· <?= e(porcentaje_partida($c['total'], $suma)) ?></span><?php endif; ?><?= barra_partida($c['ord'], $c['extra'], $max, $sec['color']) ?></td>
                  <td <?= $num ?>><strong><?= e(eur($c['total'])) ?></strong>
                    <?php if ($c['extra'] > 0): ?><br><span class="tenue" style="font-size:.8em"><?= e(eur($c['ord'])) ?> + <?= e(eur($c['extra'])) ?> extra</span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php $desglosados = array_filter($an['recibos'], static fn($r) => $r['n_partidas'] > 0); ?>
  <?php if ($desglosados): ?>
    <section class="tarjeta" id="recibos">
      <div class="tarjeta-cabecera"><h2>Recibo a recibo</h2></div>
      <p class="tenue">Lo que te ha tocado de cada partida en cada liquidación: sirve para ver qué sube.</p>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>Partida</th>
            <?php foreach ($desglosados as $rid => $r): ?>
              <th <?= $num ?>><a href="<?= e(url('elemento.php?id=' . $el['id'] . '#historial')) ?>" title="<?= e($r['titulo']) ?>"><?= e($r['etiqueta']) ?></a></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php foreach ($an['categorias'] as $cat): ?>
              <tr><td><?= e($cat) ?></td>
                <?php foreach ($desglosados as $r): ?>
                  <td <?= $num ?>><?= isset($r['categorias'][$cat]) ? e(eur($r['categorias'][$cat])) : '<span class="tenue">—</span>' ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            <tr><td><strong>Recibo</strong></td>
              <?php foreach ($desglosados as $r): ?><td <?= $num ?>><strong><?= e(eur($r['coste'])) ?></strong></td><?php endforeach; ?>
            </tr>
            <tr class="apagado"><td>de ello, obras y extras</td>
              <?php foreach ($desglosados as $r): ?><td <?= $num ?>><?= $r['extra'] > 0 ? e(eur($r['extra'])) : '—' ?></td><?php endforeach; ?>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

<?php endif; ?>
<?php pie();
