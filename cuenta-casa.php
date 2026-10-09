<?php
// La cuenta común de la casa (8/10/2026): quién pone qué frente a lo acordado
// (la hipoteca, cada uno su parte; el resto, a medias), a dónde va el dinero y
// qué cargos del banco no están apuntados en su ficha. Los movimientos los da
// finanzas (acción «casa», copia de una hora); los números, includes/cuenta-casa.php.
// Solo administradores, como Finanzas: son movimientos del banco.
require_once __DIR__ . '/includes/auth.php';

if (!es_admin()) pagina_error(403, 'Solo administradores', 'La cuenta de la casa solo la ven los administradores.');

$hoy = hoy();
$r = finanzas_leer('casa', !empty($_GET['actualizar']));
$movs = (array)($r['datos']['movimientos'] ?? []);
$anios = anios_cuenta_casa($movs);
$anio = in_array((int)($_GET['anio'] ?? 0), $anios, true) ? (int)$_GET['anio'] : ($anios[0] ?? (int)substr($hoy, 0, 4));

// La parte de la hipoteca de cada uno: la de su ficha (Gonzalo, 60 %).
$hipoteca = null;
foreach (elementos_con_coste($pdo) as $el) if ($el['seccion'] === 'contratos' && $el['tipo'] === 'hipoteca') { $hipoteca = $el; break; }
$titular = $hipoteca && $hipoteca['persona_id'] ? (persona($pdo, (int)$hipoteca['persona_id'])['nombre'] ?? null) : null;
$aportantes = [];
foreach ($movs as $m) if (($a = aportante((string)$m['categoria'])) !== null) $aportantes[$a] = true;
$aportantes = array_keys($aportantes);
sort($aportantes);
$an = analisis_cuenta_casa($movs, $anio, $hoy, reparto_hipoteca($hipoteca, $titular, $aportantes));
$sin_apuntar = cargos_sin_apuntar($movs, cargos_apuntados($pdo, ($anio - 1) . '-11-01', ($anio + 1) . '-02-28'), $anio);
$cuota = ultima_cuota($movs);
$cuenta = $r['datos']['cuentas'][0] ?? null;

$botones = '<a class="btn btn-sutil" href="' . e(url('gastos-fijos.php')) . '">' . icono('cartera') . 'Gastos fijos</a>'
         . '<a class="btn btn-sutil" href="' . e(url('cuenta-casa.php?anio=' . $anio . '&actualizar=1')) . '">' . icono('repetir') . 'Actualizar</a>';
cabecera('Cuenta de la casa', 'finanzas');
cabecera_pagina('Cuenta de la casa', 'La común con lo que pone cada uno · de finanzas', $botones, 'casa', '#405189');
?>
<?php if ($r['error']): ?>
  <div class="flash flash-aviso"><?= e($r['error']) ?><?= $movs ? ' Enseño la última copia (' . e(fecha_corta(substr((string)$r['leido_en'], 0, 10)) . ' ' . substr((string)$r['leido_en'], 11, 5)) . ').' : '' ?></div>
<?php endif; ?>

<?php if (!$movs): ?>
  <div class="tarjeta vacio">
    <p>Aún no hay movimientos de la cuenta de la casa. Se importan en finanzas como cualquier extracto de Mediolanum: el número de cuenta decide que van a la común.</p>
  </div>
<?php pie(); exit; endif; ?>

<?php if (count($anios) > 1): ?>
  <p><?php foreach ($anios as $a): ?>
    <a class="btn <?= $a === $anio ? '' : 'btn-sutil' ?>" href="<?= e(url('cuenta-casa.php?anio=' . $a)) ?>"><?= (int)$a ?></a>
  <?php endforeach; ?></p>
<?php endif; ?>

<section class="kpis">
  <div class="kpi"><span class="kpi-num kpi-num-texto"><?= e(eur($an['gasto_mes'])) ?></span><span class="kpi-txt">sale al mes de media en <?= (int)$anio ?></span></div>
  <div class="kpi"><span class="kpi-num kpi-num-texto"><?= e(eur($an['gasto_total'])) ?></span><span class="kpi-txt">en lo que va de <?= (int)$anio ?></span></div>
  <?php foreach ($an['personas'] as $quien => $p): ?>
    <div class="kpi"><span class="kpi-num kpi-num-texto"><?= e(eur($p['total'])) ?></span><span class="kpi-txt">ha puesto <?= e($quien) ?></span></div>
  <?php endforeach; ?>
</section>

<div class="panel-rejilla">
  <section class="tarjeta" id="quien">
    <div class="tarjeta-cabecera"><h2><?= icono('familia') ?>Quién pone qué</h2><span class="tenue"><?= (int)$anio ?></span></div>
    <?php if ($an['desfase']): ?>
      <p class="comparativa">Con lo acordado, <strong><?= e($an['desfase']['de_mas']) ?></strong> lleva puestos
        <strong><?= e(eur($an['desfase']['importe'])) ?></strong> más que <?= e($an['desfase']['otro']) ?>.</p>
    <?php elseif (count($an['personas']) === 2): ?>
      <p class="comparativa">Con lo acordado, los dos vais a la par.</p>
    <?php endif; ?>
    <div class="tabla-scroll">
      <table class="tabla">
        <thead><tr><th></th><?php foreach ($an['personas'] as $quien => $p): ?><th class="num"><?= e($quien) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <tr><td>Para la hipoteca</td><?php foreach ($an['personas'] as $p): ?><td class="num"><?= e(eur($p['hipoteca'])) ?>
              <?php if ($p['pct_puesto_hipoteca'] !== null): ?><span class="partida-meta"><?= e(numero_es($p['pct_puesto_hipoteca'], 1) . NBSP . '%') ?> del total puesto</span><?php endif; ?></td><?php endforeach; ?></tr>
          <tr><td>Para gastos</td><?php foreach ($an['personas'] as $p): ?><td class="num"><?= e(eur($p['gastos'])) ?></td><?php endforeach; ?></tr>
          <tr><td><strong>Ha puesto</strong></td><?php foreach ($an['personas'] as $p): ?><td class="num"><strong><?= e(eur($p['total'])) ?></strong></td><?php endforeach; ?></tr>
          <tr><td>Le toca<span class="partida-meta">su parte de las cuotas y la mitad del resto</span></td>
            <?php foreach ($an['personas'] as $p): ?><td class="num"><?= e(eur($p['toca'])) ?>
              <?php if ($p['pct_hipoteca'] !== null): ?><span class="partida-meta">hipoteca al <?= e(numero_es($p['pct_hipoteca'], 0) . NBSP . '%') ?></span><?php endif; ?></td><?php endforeach; ?></tr>
        </tbody>
        <tfoot><tr><td>Diferencia</td><?php foreach ($an['personas'] as $p): ?><td class="num"><?= e(($p['dif'] >= 0 ? '+' : '−') . eur(abs($p['dif']))) ?></td><?php endforeach; ?></tr></tfoot>
      </table>
    </div>
    <p class="tenue nota-pequena">Cuotas de la hipoteca: <?= e(eur($an['cuotas'])) ?>. Resto del gasto, a medias: <?= e(eur($an['resto'])) ?>
      (ya restadas las devoluciones y otras entradas<?= $an['entradas'] ? ', como ' . e(minusculas($an['entradas'][0]['nombre'])) . ' (' . e(eur($an['entradas'][0]['importe'])) . ')' : '' ?>).
      Lo que sobra de las dos diferencias es lo que queda en la cuenta.</p>
  </section>

  <aside class="tarjeta" id="cuenta">
    <div class="tarjeta-cabecera"><h2><?= icono('cartera') ?>La cuenta</h2></div>
    <?php if ($cuenta): ?>
      <p><strong><?= e(eur($cuenta['saldo'])) ?></strong> de saldo
        <?= $cuenta['cuadra'] === true ? '· cuadra con el banco' : ($cuenta['cuadra'] === false ? '· <strong>no cuadra</strong> con el banco (' . e(eur($cuenta['banco'])) . ')' : '') ?>.</p>
      <p class="tenue nota-pequena">Último movimiento importado: <?= e(fecha_es((string)($r['datos']['ultimo_movimiento'] ?? ''))) ?>.
        Leído de finanzas <?= e(fecha_corta(substr((string)$r['leido_en'], 0, 10)) . ' ' . substr((string)$r['leido_en'], 11, 5)) ?>.</p>
    <?php endif; ?>
    <?php if ($cuota && $hipoteca): ?>
      <?php $coste = (float)($hipoteca['datos']['coste'] ?? 0); ?>
      <p>Última cuota de la hipoteca: <strong><?= e(eur($cuota['importe'])) ?></strong> (<?= e(fecha_corta($cuota['fecha'])) ?>).
        <?php if (abs($coste - $cuota['importe']) < 0.005): ?>
          <span class="tenue">Igual que en <a href="<?= e(url('elemento.php?id=' . $hipoteca['id'])) ?>">su ficha</a>.</span>
        <?php else: ?>
          <strong>Su ficha dice <?= e(eur($coste)) ?></strong>: <a href="<?= e(url('elemento-editar.php?id=' . $hipoteca['id'])) ?>">ponla al día</a>.
        <?php endif; ?></p>
    <?php endif; ?>
    <?php if ($an['sin_categorizar']['n']): ?>
      <p class="tenue nota-pequena"><?= (int)$an['sin_categorizar']['n'] ?> movimientos sin categoría en <?= (int)$anio ?>
        (<?= e(eur($an['sin_categorizar']['importe'])) ?>): salen como «Sin categorizar». Se categorizan en finanzas.</p>
    <?php endif; ?>
  </aside>
</div>

<section class="tarjeta" id="reparto">
  <div class="tarjeta-cabecera"><h2><?= icono('historial') ?>A dónde va</h2><span class="tenue"><?= (int)$anio ?> · gasto neto: las devoluciones restan</span></div>
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Bloque</th><th class="num">Al mes</th><th class="num">En el año</th><th class="num">%</th></tr></thead>
      <?php foreach ($an['grupos'] as $g): ?>
        <tbody class="grupo-gasto">
          <tr><td><strong><?= e($g['nombre']) ?></strong></td><td class="num"><strong><?= e(eur($g['mes'])) ?></strong></td>
            <td class="num"><strong><?= e(eur($g['gasto'])) ?></strong></td><td class="num"><strong><?= e(pct_es($g['pct'])) ?></strong></td></tr>
          <?php if (count($g['subs']) > 1 || $g['subs'][0]['nombre'] !== $g['nombre']): ?>
            <?php foreach ($g['subs'] as $c): ?>
              <tr class="sub-gasto"><td><?= e($c['nombre']) ?></td><td class="num"><?= e(eur($c['mes'])) ?></td><td class="num"><?= e(eur($c['gasto'])) ?></td><td class="num"><?= e(pct_es($c['pct'])) ?></td></tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      <?php endforeach; ?>
      <tfoot><tr><td>Total</td><td class="num"><?= e(eur($an['gasto_mes'])) ?></td><td class="num"><?= e(eur($an['gasto_total'])) ?></td><td></td></tr></tfoot>
    </table>
  </div>
  <?php if ($an['entradas']): ?>
    <p class="tenue nota-pequena">Además entraron, sin contar lo que ponéis:
      <?php foreach ($an['entradas'] as $i => $x): ?><?= $i ? '; ' : '' ?><?= e($x['nombre']) ?> <?= e(eur($x['importe'])) ?><?php endforeach; ?>.</p>
  <?php endif; ?>
  <p class="tenue nota-pequena">La media sale de <?= e(numero_es($an['meses_transcurridos'], 1)) ?> meses<?= $anio === (int)substr($hoy, 0, 4) ? ' (el actual, a medias)' : '' ?>.</p>
</section>

<div class="panel-rejilla">
  <section class="tarjeta" id="meses">
    <div class="tarjeta-cabecera"><h2><?= icono('agenda') ?>Mes a mes</h2></div>
    <div class="tabla-scroll">
      <table class="tabla">
        <thead><tr><th>Mes</th><th class="num">Sale</th><?php foreach ($an['personas'] as $quien => $p): ?><th class="num"><?= e($quien) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($an['meses'] as $mes => $x): ?>
            <tr><td><?= e(ucfirst(MESES[(int)substr($mes, 5, 2) - 1])) ?></td><td class="num"><?= e(eur($x['gasto'])) ?></td>
              <?php foreach ($an['personas'] as $quien => $p): ?><td class="num"><?= e(eur($x['puesto'][$quien] ?? 0)) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <aside class="tarjeta" id="sin-apuntar">
    <div class="tarjeta-cabecera"><h2><?= icono('alerta') ?>Cargos sin apuntar en su ficha</h2></div>
    <?php if (!$sin_apuntar['sin_ficha']): ?>
      <p class="tenue">Todos los cargos de comunidad, suministros, seguros e impuestos de <?= (int)$anio ?> están apuntados en el historial de su ficha (<?= (int)$sin_apuntar['casados'] ?>).</p>
    <?php else: ?>
      <p class="tenue nota-pequena">Cargos de comunidad, suministros, seguros e impuestos sin una factura o recibo del mismo importe en ninguna ficha. <?= (int)$sin_apuntar['casados'] ?> sí lo están.</p>
      <ul class="lista-campo">
        <?php foreach ($sin_apuntar['sin_ficha'] as $m): ?>
          <li><span class="tenue"><?= e(fecha_corta((string)$m['fecha'])) ?></span> · <?= e((string)$m['concepto']) ?> · <strong><?= e(eur(-(float)$m['importe'])) ?></strong>
            <span class="partida-meta"><?= e((string)$m['categoria']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>
</div>
<?php pie();
