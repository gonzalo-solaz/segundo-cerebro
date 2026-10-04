<?php
// A dónde va el gasto fijo: el desglose de la cifra del panel, lo que
// conviene revisar y, para los admin con finanzas, cómo queda frente a los
// ingresos. Lo que se paga a medias se ve por la parte de quien mira (cifra
// grande) con el total de la casa en pequeño. La lógica, en includes/gastos.php.
require_once __DIR__ . '/includes/auth.php';

$hoy = hoy();
$persona = (int)($usuario_actual['persona_id'] ?? 0) ?: null;
$an = analisis_gastos_fijos(elementos_con_coste($pdo), historial_de_gastos($pdo, $hoy), $hoy, $persona, precios_por_elemento($pdo));
// El IPC del INE (copia de un día): la vara con la que se mide lo que sube cada cosa.
$ipc = ipc_serie();
$ipc_ref = $ipc['ultimo'] ? ['mes' => $ipc['ultimo'], 'valor' => $ipc['serie'][$ipc['ultimo']]] : null;

// Finanzas: solo admin (como su sección), con la copia de una hora de finanzas_leer().
$fin = null;
$fin_leido = null;
$fin_error = null;
if (es_admin() && finanzas_configurada() && $an['tuyo'] > 0) {
    $r = finanzas_leer('resumen');
    $fin = $r['datos'] ? salud_finanzas($r['datos'], $an, $hoy, finanzas_nominas()['anios'], $ipc['serie']) : null;
    [$fin_leido, $fin_error] = [$r['leido_en'], $r['error']];
    if ($fin) $an['revisar'] = ordenar_revisar(array_merge($an['revisar'], $fin['revisar']));
}

$iconos_nivel = ['aviso' => 'alerta', 'idea' => 'bombilla', 'bien' => 'check', 'dato' => 'cartera'];
$estado_nivel = ['aviso' => 'Atención', 'idea' => 'Mejorable', 'bien' => 'Bien'];
$mes_nombre = static function (string $mes, int $i): string {
    $m = (int)substr($mes, 5, 2);
    return MESES_CORTOS[$m - 1] . ($i === 0 || $m === 1 ? ' ' . substr($mes, 0, 4) : '');
};
// «de 75,69 €»: el total, en pequeño, cuando lo tuyo es solo una parte.
$de_total = static fn(float $tuyo, float $total): string => abs($total - $tuyo) >= 0.005 ? 'de ' . eur($total) : '';
$pico = $an['pico'] !== null ? $an['calendario'][$an['pico']] : null;
// ▲ ▼ frente a hace un año: en ámbar lo que sube más que el IPC; en verde lo que baja.
$chip = static function (?array $v) use ($ipc_ref): string {
    if (!$v) return '';
    $p = round($v['pct'], 1);
    [$clase, $flecha, $que] = $p < 0 ? ['var-baja', '▼', 'baja'] : ($p == 0.0 ? ['var-igual', '=', 'igual']
        : ($ipc_ref && $p > $ipc_ref['valor'] ? ['var-sube', '▲', 'sube más que el IPC'] : ['var-ipc', '▲', 'sube, no más que el IPC']));
    return '<span class="var ' . $clase . '" title="' . e('Frente a hace un año: ' . $que) . '">' . $flecha . ' '
         . e(number_format(abs($p), 1, ',', '.')) . ' %</span>';
};
$medias = $an['a_medias'];

cabecera('Gastos fijos', 'index');
cabecera_pagina('Gastos fijos', '<a href="' . e(url('index.php')) . '">Panel</a>',
    '<a class="btn btn-sutil" href="' . e(url('index.php')) . '">' . icono('atras') . 'Panel</a>', 'cartera', '#405189');
?>

<?php if ($an['total'] <= 0): ?>
  <div class="tarjeta vacio">
    <p>Aún no hay gastos fijos. Ponles coste y periodicidad a tus contratos (luz, seguros, hipoteca, comunidad…) y aquí verás a dónde va cada euro.</p>
    <a class="btn" href="<?= e(url('seccion.php?s=contratos')) ?>"><?= icono('contrato') ?>Ir a Contratos</a>
  </div>
<?php pie(); exit; endif; ?>

<section class="kpis">
  <div class="kpi"><span class="kpi-num kpi-num-texto"><?= e(eur($an['tuyo'])) ?></span>
    <span class="kpi-txt"><?= $medias ? 'pagas tú al mes · de ' . e(eur($an['total'])) . ' de la casa' : 'al mes' ?></span></div>
  <div class="kpi"><span class="kpi-num kpi-num-texto"><?= e(eur($an['anual_tuyo'])) ?></span>
    <span class="kpi-txt"><?= $medias ? 'al año · de ' . e(eur($an['anual'])) : 'al año' ?></span></div>
  <div class="kpi"><span class="kpi-num kpi-num-texto"><?= e(eur($an['provision'])) ?></span><span class="kpi-txt">al mes para apartar: lo que no se paga cada mes</span></div>
  <?php if ($pico): ?>
    <div class="kpi"><span class="kpi-num kpi-num-texto"><?= e(eur($pico['total'])) ?></span>
      <span class="kpi-txt">el mes más caro: <?= e(MESES[(int)substr($pico['mes'], 5, 2) - 1] . ' de ' . substr($pico['mes'], 0, 4)) ?></span></div>
  <?php else: ?>
    <div class="kpi"><span class="kpi-num kpi-num-texto"><?= count($an['items']) ?></span><span class="kpi-txt">gastos con importe</span></div>
  <?php endif; ?>
</section>

<div class="panel-rejilla">
  <section class="tarjeta" id="reparto">
    <div class="tarjeta-cabecera"><h2><?= icono('cartera') ?>A dónde va</h2><span class="tenue"><?= $medias ? 'lo que pagas tú, al mes' : 'al mes' ?></span></div>
    <div class="reparto" role="img" aria-label="<?= e(implode(', ', array_map(static fn($p) => $p['nombre'] . ' ' . pct_es($p['pct']), $an['partidas']))) ?>">
      <?php foreach ($an['partidas'] as $p): ?>
        <span class="reparto-tramo serie-<?= (int)$p['serie'] ?>" style="flex-grow:<?= e(number_format($p['tuyo'], 2, '.', '')) ?>"
              title="<?= e($p['nombre'] . ' · ' . eur($p['tuyo']) . ' al mes · ' . pct_es($p['pct'])) ?>"></span>
      <?php endforeach; ?>
    </div>
    <p class="comparativa">
      <?php if ($an['interanual']): ?>
        Frente a hace un año <?= $chip($an['interanual']) ?>: lo que se puede comparar (<?= (int)$an['interanual']['n'] ?> de <?= (int)$an['interanual']['de'] ?> gastos)
        te cuesta <?= e(eur($an['interanual']['ahora'])) ?> al mes; hace un año, <?= e(eur($an['interanual']['antes'])) ?>.
      <?php else: ?>
        Aún no hay datos de hace un año para comparar.
      <?php endif; ?>
      <?php if ($ipc_ref): ?><span class="tenue">IPC: <?= e(variacion_es($ipc_ref['valor'])) ?> (<?= e(mes_largo($ipc_ref['mes'])) ?>, INE).</span><?php endif; ?>
    </p>
    <?php foreach ($an['partidas'] as $p): ?>
      <div class="partida">
        <div class="partida-cabecera">
          <span class="punto serie-<?= (int)$p['serie'] ?>"></span>
          <h3><?= e($p['nombre']) ?></h3>
          <span class="tenue"><?= e(pct_es($p['pct'])) ?></span>
          <?php if ($p['interanual']): ?><?= $chip($p['interanual']) ?><?php if ($p['interanual']['n'] < $p['interanual']['de']): ?><span class="tenue nota-pequena"><?= (int)$p['interanual']['n'] ?> de <?= (int)$p['interanual']['de'] ?></span><?php endif; ?><?php endif; ?>
          <span class="partida-importe"><strong><?= e(eur($p['tuyo'])) ?></strong>
            <?php if ($t = $de_total($p['tuyo'], $p['mensual'])): ?><span class="partida-meta"><?= e($t) ?></span><?php endif; ?></span>
        </div>
        <ul class="partida-items">
          <?php foreach ($p['items'] as $i): ?>
            <li>
              <div>
                <a href="<?= e(url('elemento.php?id=' . $i['id'])) ?>"><?= e($i['nombre']) ?></a> <?= $chip($i['interanual']) ?>
                <span class="partida-meta">
                  <?php if ($i['parte'] < 100): ?>pagas el <?= e(numero_es($i['parte'])) ?> % · <?php endif; ?>
                  <?php if ($i['meses'] > 1): ?>
                    <?= e(coste_y_periodo($i['coste'], $i['periodicidad'])) ?><?php if ($i['proximo']): ?> · próximo cargo <?= $i['aprox'] ? '≈ ' : '' ?><?= e(fecha_corta($i['proximo'])) ?><?php endif; ?>
                  <?php elseif ($i['real']): ?>
                    según <?= (int)$i['real']['n'] ?> facturas<?= $i['real']['cada'] > 1 ? ' (cada ' . (int)$i['real']['cada'] . ' meses)' : '' ?>: <?= e(eur($i['real']['mensual'])) ?> al mes de media
                  <?php else: ?>
                    <?= e($i['tipo_nombre']) ?>, cada mes
                  <?php endif; ?>
                </span>
                <?php if ($i['interanual']): ?><span class="partida-meta"><?= e(ucfirst($i['interanual']['detalle'])) ?></span><?php endif; ?>
              </div>
              <span class="num"><?= e(eur($i['tuyo'])) ?>
                <?php if ($t = $de_total($i['tuyo'], $i['mensual'])): ?><span class="partida-meta"><?= e($t) ?></span><?php endif; ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
    <?php if ($an['sin_comparar']): ?>
      <p class="tenue nota-pequena">Para compararlo con hace un año falta:
        <?php foreach ($an['sin_comparar'] as $n => $s): ?><?= $n ? '; ' : '' ?><a href="<?= e(url('elemento.php?id=' . $s['id'])) ?>"><?= e($s['nombre']) ?></a>, <?= e($s['falta']) ?><?php endforeach; ?>.</p>
    <?php endif; ?>
    <?php if ($an['de_otros']): ?>
      <p class="tenue nota-pequena">Lo pagan otros, no cuenta en lo tuyo:
        <?php foreach ($an['de_otros'] as $n => $o): ?><?= $n ? ', ' : '' ?><a href="<?= e(url('elemento.php?id=' . $o['id'])) ?>"><?= e($o['nombre']) ?></a><?= $o['persona_nombre'] ? ' (' . e(nombre_corto($o['persona_nombre'])) . ')' : '' ?><?php endforeach; ?>.</p>
    <?php endif; ?>
    <?php if ($an['sin_importe']): ?>
      <p class="tenue nota-pequena">Sin importe, no cuentan:
        <?php foreach ($an['sin_importe'] as $n => $s): ?><?= $n ? ', ' : '' ?><a href="<?= e(url('elemento.php?id=' . $s['id'])) ?>"><?= e($s['nombre']) ?></a><?php endforeach; ?>.</p>
    <?php endif; ?>
  </section>

  <aside class="tarjeta" id="revisar">
    <div class="tarjeta-cabecera"><h2><?= icono('bombilla') ?>Qué revisar</h2></div>
    <?php if (!$an['revisar']): ?><p class="vacio-mini">Nada que revisar ahora mismo.</p><?php endif; ?>
    <ul class="revisar">
      <?php foreach ($an['revisar'] as $r): ?>
        <li class="revisar-<?= e($r['nivel']) ?>">
          <span class="revisar-icono"><?= icono($iconos_nivel[$r['nivel']]) ?></span>
          <div>
            <h3><?= e($r['titulo']) ?></h3>
            <p><?= e($r['texto']) ?></p>
            <?php if ($r['enlaces']): ?>
              <p class="revisar-enlaces">
                <?php foreach ($r['enlaces'] as [$texto, $ruta]): ?><a href="<?= e(url($ruta)) ?>"><?= e($texto) ?></a><?php endforeach; ?>
              </p>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </aside>
</div>

<?php if ($fin): ?>
  <section class="tarjeta" id="ingresos">
    <div class="tarjeta-cabecera">
      <h2><?= icono('cartera') ?>Frente a tus ingresos</h2>
      <span class="tenue">según finanzas, media de <?= (int)$fin['meses'] ?> meses</span>
    </div>
    <div class="cifras">
      <?php foreach ($fin['cifras'] as $c): ?>
        <div class="cifra cifra-<?= e($c['nivel']) ?>">
          <span class="cifra-estado"><?= icono($iconos_nivel[$c['nivel']], 'ico ico-mini') ?><?= e($estado_nivel[$c['nivel']] ?? '') ?></span>
          <span class="cifra-valor"><?= e($c['valor']) ?></span>
          <span class="cifra-titulo"><?= e($c['titulo']) ?></span>
          <p><?= e($c['texto']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="tenue nota-pequena">Finanzas lleva tus cuentas, así que todo se compara con lo que pagas tú, no con el total de la casa.
      <?php if ($fin_leido): ?>Leído el <?= e(fecha_es($fin_leido)) ?> a las <?= e(substr($fin_leido, 11, 5)) ?>.<?php endif; ?></p>
  </section>
<?php elseif ($fin_error && es_admin()): ?>
  <p class="nota nota-pequena"><?= icono('alerta', 'ico ico-mini') ?> Sin la comparación con tus ingresos: <?= e($fin_error) ?></p>
<?php endif; ?>

<section class="tarjeta" id="cosas">
  <div class="tarjeta-cabecera"><h2><?= icono('casa') ?>Lo que cuesta cada cosa</h2><?php if ($medias): ?><span class="tenue">lo que pagas tú</span><?php endif; ?></div>
  <ul class="partida-items cosas">
    <?php foreach ($an['cosas'] as $c): ?>
      <li>
        <div>
          <?php if ($c['id']): ?><a href="<?= e(url('elemento.php?id=' . $c['id'])) ?>"><?= e($c['nombre']) ?></a><?php else: ?><strong><?= e($c['nombre']) ?></strong><?php endif; ?>
          <?php if ($c['items'] !== [$c['nombre']]): ?><span class="partida-meta"><?= e(implode(', ', $c['items'])) ?></span><?php endif; ?>
          <span class="partida-meta"><?= e(eur($c['tuyo'] * 12)) ?> al año<?php if ($c['otros_12m'] > 0): ?> · y <?= e(eur($c['otros_12m'])) ?> más en su historial del último año<?php endif; ?></span>
        </div>
        <span class="num"><strong><?= e(eur($c['tuyo'])) ?></strong>
          <span class="partida-meta"><?= e(implode(' · ', array_filter([$de_total($c['tuyo'], $c['mensual']), pct_es($c['pct'])]))) ?></span></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="tenue nota-pequena">Lo del historial es lo apuntado en la ficha de la cosa (taller, ITV, reparaciones, reformas), sin compras: no es fijo, pero también es lo que cuesta tenerla.</p>
</section>

<section class="tarjeta" id="meses">
  <div class="tarjeta-cabecera">
    <h2><?= icono('agenda') ?>Mes a mes</h2>
    <span class="leyenda"><span><span class="punto cal-punto-base"></span>Cada mes</span><span><span class="punto cal-punto-extra"></span>Lo que no es mensual</span></span>
  </div>
  <?php if ($an['provision'] > 0): ?>
    <p>Lo que no se paga cada mes (seguros, comunidad…) te supone <strong><?= e(eur($an['provision'] * 12)) ?></strong> al año.
      Si apartas <strong><?= e(eur($an['provision'])) ?></strong> cada mes en una cuenta aparte, <?= $pico ? 'el mes más caro (' . e(MESES[(int)substr($pico['mes'], 5, 2) - 1]) . ', ' . e(eur($pico['total'])) . ')' : 'ningún mes' ?> no te pillará por sorpresa.</p>
  <?php endif; ?>
  <ol class="calendario">
    <?php foreach ($an['calendario'] as $n => $m): ?>
      <li class="<?= $n === $an['pico'] ? 'cal-pico' : '' ?>">
        <span class="cal-nombre"><?= e($mes_nombre($m['mes'], $n)) ?></span>
        <span class="cal-barra" title="<?= e(eur($m['base']) . ' cada mes + ' . eur($m['extra']) . ' no mensual') ?>">
          <?php if ($an['max_mes'] > 0): ?>
            <span class="cal-base" style="width:<?= e(number_format($m['base'] / $an['max_mes'] * 100, 2, '.', '')) ?>%"></span><?php if ($m['extra'] > 0): ?><span class="cal-extra" style="width:<?= e(number_format($m['extra'] / $an['max_mes'] * 100, 2, '.', '')) ?>%"></span><?php endif; ?>
          <?php endif; ?>
        </span>
        <span class="cal-total"><?= e(eur($m['total'])) ?></span>
        <?php if ($m['cargos']): ?>
          <span class="cal-cargos">
            <?php foreach ($m['cargos'] as $k => $c): ?><?= $k ? ' · ' : '' ?><a href="<?= e(url('elemento.php?id=' . $c['id'])) ?>"><?= e($c['nombre']) ?></a> <?= $c['aprox'] ? '≈ ' : '' ?><?= e((int)substr($c['fecha'], 8, 2) . ' ' . MESES_CORTOS[(int)substr($c['fecha'], 5, 2) - 1]) ?>, <?= e(eur($c['importe'])) ?><?= abs($c['total'] - $c['importe']) >= 0.005 ? ' ' . e('(de ' . eur($c['total']) . ')') : '' ?><?php endforeach; ?>
          </span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($an['sin_fecha']): ?>
    <p class="tenue nota-pequena">Sin fecha de cargo, repartidos por igual entre los meses: <?= e(implode(', ', $an['sin_fecha'])) ?>. Pon la renovación en su ficha (o apunta su último recibo) y saldrán en su mes.</p>
  <?php endif; ?>
  <p class="tenue nota-pequena">Las fechas salen de la renovación de cada ficha o, si no la tiene, del último recibo más su periodicidad (≈: aproximada). Luz y gas cuentan su importe medio todos los meses.</p>
</section>

<p class="tenue nota-pequena">Cómo se calcula: cada gasto cuenta su coste entre los meses de su periodicidad (un seguro de 240 € al año son 20 € al mes).
  <?php if ($medias): ?>Lo que pagáis a medias cuenta por tu parte: la del campo «Parte que paga el titular» de cada ficha (si el titular es otro, el resto). El panel enseña lo mismo.<?php else: ?>El total es el mismo que el del panel.<?php endif; ?></p>
<?php pie();
