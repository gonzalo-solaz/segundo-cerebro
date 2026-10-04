<?php
// Cuánto cuestan al año los suministros (luz, gas, internet…), sumando las
// facturas apuntadas en el historial de cada contrato.
require_once __DIR__ . '/includes/auth.php';

$sec = seccion('contratos');
$anios = gasto_suministros($pdo);

$volver = '<a class="btn btn-sutil" href="' . e(url('seccion.php?s=contratos')) . '">' . icono('atras') . 'Contratos</a>';
cabecera('Gasto en suministros', 'seccion:contratos');
cabecera_pagina('Gasto en suministros',
    '<a href="' . e(url('seccion.php?s=contratos')) . '">' . e($sec['nombre']) . '</a>',
    $volver, 'historial', $sec['color']);
?>

<?php if (!$anios): ?>
  <div class="tarjeta vacio">
    <p>Aún no hay facturas. Apúntalas en el historial de cada suministro con el tipo «Factura» y su coste; aquí se suman solas.</p>
  </div>
<?php else: ?>
  <p class="tenue">Suma de las facturas apuntadas, por la fecha de la factura (no la del consumo: la del gas de julio llega en agosto).
    Solo cuentan los meses que tienen factura apuntada, así que un año a medias sale más bajo de lo que será.</p>

  <?php foreach ($anios as $anio => $a): ?>
    <section class="tarjeta" id="anio-<?= (int)$anio ?>">
      <div class="tarjeta-cabecera">
        <h2><?= (int)$anio ?></h2>
        <span class="tenue"><strong><?= e(eur($a['total'])) ?></strong> · <?= (int)$a['n'] ?> <?= $a['n'] === 1 ? 'factura' : 'facturas' ?></span>
      </div>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>Suministro</th><th class="num">Facturas</th><th class="num">Media</th><th class="num">Total</th></tr></thead>
          <tbody>
            <?php foreach ($a['suministros'] as $id => $s): ?>
              <tr>
                <td><a href="<?= e(url('elemento.php?id=' . $id . '#historial')) ?>"><?= e($s['nombre']) ?></a>
                  <?php if ($s['categoria'] !== ''): ?><span class="chip chip-categoria"><?= e($s['categoria']) ?></span><?php endif; ?></td>
                <td class="num"><?= (int)$s['n'] ?></td>
                <td class="num"><?= e(eur($s['total'] / $s['n'])) ?></td>
                <td class="num"><strong><?= e(eur($s['total'])) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <h3 class="subtitulo">Por meses</h3>
      <div class="tabla-scroll">
        <table class="tabla">
          <thead><tr><th>Mes</th><th class="num">Total</th></tr></thead>
          <tbody>
            <?php foreach ($a['meses'] as $m => $importe): ?>
              <tr><td><?= e(ucfirst(MESES[$m - 1])) ?></td><td class="num"><?= e(eur($importe)) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach; ?>

<?php endif; ?>
<?php pie();
