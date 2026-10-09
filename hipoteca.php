<?php
// El cuadro de amortización de una hipoteca, calculado solo (includes/hipoteca.php): lo pagado,
// lo que queda, la próxima revisión con el Euríbor del día y, cuota a cuota, si el banco la ha
// cobrado (los cargos de la cuenta de la casa, de finanzas; solo administradores).
require_once __DIR__ . '/includes/auth.php';

$sec = seccion('contratos');
$el = elemento($pdo, (int)($_GET['id'] ?? 0));
if (!$el) {
    foreach (elementos_de($pdo, 'contratos') as $x) if ($x['tipo'] === 'hipoteca') { $el = $x; break; }
}
if (!$el || $el['tipo'] !== 'hipoteca') pagina_error(404, 'No encontrada', 'No hay ninguna hipoteca en Contratos.');
$hoy = hoy();

// «Actualizar»: el Euríbor ya, y los cargos de finanzas sin la copia de una hora.
if (!empty($_GET['actualizar']) && es_admin()) {
    $m = mantenimiento($pdo, true);
    if ($m['euribor']['error']) flash('error', $m['euribor']['error']);
    else flash('ok', 'Euríbor consultado' . ($m['euribor']['fuente'] ? ' (' . $m['euribor']['fuente'] . ')' : '') . ($m['hipotecas'] ? '. ' . implode('. ', $m['hipotecas']) : '') . '.');
    redirigir('hipoteca.php?id=' . $el['id'] . '&cargos=1');
}
$el = elemento($pdo, (int)$el['id']);
$cargos = es_admin() ? cargos_hipoteca(!empty($_GET['cargos'])) : [];
$cuadro = cuadro_de($pdo, $el, $cargos, $hoy);
$eur = euribor_estado($pdo);
$t = hipoteca_terminos($el);

$acciones = '<a class="btn btn-sutil" href="' . e(url('elemento.php?id=' . $el['id'])) . '">' . icono('atras') . 'Ficha</a>';
if (es_admin()) $acciones .= '<a class="btn btn-sutil" href="' . e(url('hipoteca.php?id=' . $el['id'] . '&actualizar=1')) . '">' . icono('repetir') . 'Actualizar</a>';
cabecera('Cuadro de amortización', 'seccion:contratos');
cabecera_pagina('Cuadro de amortización',
    '<a href="' . e(url('seccion.php?s=contratos')) . '">' . e($sec['nombre']) . '</a> · <a href="' . e(url('elemento.php?id=' . $el['id'])) . '">' . e($el['nombre']) . '</a>',
    $acciones, 'contrato', $sec['color']);

if (!$cuadro): ?>
  <div class="tarjeta vacio">
    <p>Para calcular el cuadro solo, a la ficha le faltan términos: capital prestado, número de cuotas, primera cuota y, si es variable,
      el diferencial, la revisión y el tipo del periodo inicial.
      <a href="<?= e(url('elemento-editar.php?id=' . $el['id'])) ?>">Complétalos</a>.</p>
  </div>
<?php pie(); exit; endif;

$r = $cuadro['resumen'];
$filas = $cuadro['filas'];
$cambio = $r['cambio'];
$ultima = $r['ultima'];
$vigente = tramo_vigente($filas, $hoy);
$cobradas = array_filter($filas, static fn($f) => $f['estado'] === 'cobrada');
$avisos_cobro = array_filter($filas, static fn($f) => in_array($f['estado'], ['sin_cargo', 'pendiente_extracto'], true));
$difieren = array_filter($cobradas, static fn($f) => abs($f['cobro']['importe'] - $f['cuota']) > 0.10);
// La próxima revisión (la que aún no ha llegado a su cuota).
$proxima_rev = null;
foreach ($filas as $f) if ($f['revision'] && $f['fecha'] > $hoy) { $proxima_rev = $f; break; }
$num = 'class="num"';
?>

<section class="kpis">
  <div class="kpi">
    <span class="kpi-num kpi-num-texto"><?= e(eur($r['capital_pendiente'])) ?></span>
    <span class="kpi-txt">pendiente de <?= e(eur($t['capital'])) ?> · amortizado el <?= e(numero_es($r['porcentaje_amortizado'])) ?>&nbsp;%</span>
  </div>
  <div class="kpi">
    <span class="kpi-num kpi-num-texto"><?= e(eur((float)$r['cuota_actual'])) ?></span>
    <span class="kpi-txt">cuota al <?= e(tipo_es((float)$r['tipo_actual'])) ?><?= $vigente ? ' desde ' . e(fecha_corta($vigente['fecha'])) . ' ' . substr($vigente['fecha'], 0, 4) : '' ?></span>
  </div>
  <div class="kpi <?= $cambio ? ($cambio['diferencia'] > 0 ? 'kpi-ambar' : 'kpi-verde') : '' ?>">
    <?php if ($cambio): $cf = $cambio['fila']; ?>
      <span class="kpi-num kpi-num-texto"><?= $cf['estado'] === 'estimada' ? '≈' : '' ?><?= e(eur($cf['cuota'])) ?></span>
      <span class="kpi-txt">desde el <?= e(fecha_es($cf['fecha'])) ?> · <?= $cambio['diferencia'] > 0 ? 'sube' : 'baja' ?> <?= e(eur(abs($cambio['diferencia']))) ?><?= $cf['estado'] === 'estimada' ? ' (estimada)' : '' ?></span>
    <?php elseif ($r['proxima']): ?>
      <span class="kpi-num kpi-num-texto"><?= e(eur($r['proxima']['cuota'])) ?></span>
      <span class="kpi-txt">próxima cuota, el <?= e(fecha_es($r['proxima']['fecha'])) ?></span>
    <?php else: ?>
      <span class="kpi-num kpi-num-texto">—</span><span class="kpi-txt">sin cuotas pendientes</span>
    <?php endif; ?>
  </div>
  <div class="kpi">
    <?php $ep = $eur['provisional']; $ec = $eur['cerrado']; ?>
    <span class="kpi-num kpi-num-texto"><?= $ep ? e(tipo_es($ep['valor'])) : ($ec ? e(tipo_es($ec['valor'])) : '—') ?></span>
    <span class="kpi-txt">Euríbor <?= $ep ? e(MESES[(int)substr($ep['mes'], 5, 2) - 1]) . ', provisional (' . (int)$ep['dias'] . ' días)' . ($ec ? ' · ' . e(MESES[(int)substr($ec['mes'], 5, 2) - 1]) . ' ' . e(tipo_es($ec['valor'])) : '')
      : ($ec ? e(mes_largo($ec['mes'])) : 'sin consultar') ?></span>
  </div>
</section>

<?php if ($t['variable'] && $proxima_rev): $pe = $proxima_rev['euribor']; ?>
  <section class="tarjeta">
    <div class="tarjeta-cabecera"><h2><?= icono('reloj') ?>Próxima revisión</h2><span class="tenue"><?= e(relativo(dias_entre($hoy, $proxima_rev['revision']))) ?></span></div>
    <p>
      El <strong><?= e(fecha_es($proxima_rev['revision'])) ?></strong> el banco revisa el tipo con la media del Euríbor de
      <?= e($pe ? mes_largo($pe['mes']) : 'el mes anterior') ?>, que se aplica desde la cuota del <?= e(fecha_es($proxima_rev['fecha'])) ?>.
      <?php if ($proxima_rev['origen'] === 'calculado'): ?>
        <?= e(ucfirst(MESES[(int)substr($pe['mes'], 5, 2) - 1])) ?> ya ha cerrado en <strong><?= e(tipo_es($pe['valor'])) ?></strong>: el tipo será el
        <strong><?= e(tipo_es($proxima_rev['tipo'])) ?></strong> y la cuota, <strong><?= e(eur($proxima_rev['cuota'])) ?></strong>.
      <?php elseif ($pe): ?>
        <?php if ($pe['mes'] === substr(sumar_meses($proxima_rev['fecha'], -HIPOTECA_MESES_EURIBOR), 0, 7)): ?>
          Va en el <strong><?= e(tipo_es($pe['valor'])) ?></strong> (media provisional de <?= (int)$pe['dias'] ?> días):
        <?php else: ?>
          Aún no hay datos de ese mes; con el último Euríbor (<?= e(mes_largo($pe['mes'])) ?>, <?= e(tipo_es($pe['valor'])) ?>):
        <?php endif; ?>
        el tipo pasaría al <strong><?= e(tipo_es($proxima_rev['tipo'])) ?></strong> y la cuota, a <strong><?= e(eur($proxima_rev['cuota'])) ?></strong>
        (<?= ($proxima_rev['cuota'] - (float)$r['cuota_actual']) >= 0 ? '+' : '−' ?><?= e(eur(abs($proxima_rev['cuota'] - (float)$r['cuota_actual']))) ?>).
        Se confirma sola cuando cierre el mes.
      <?php else: ?>
        Aún no hay Euríbor guardado: la app lo consulta sola cada pocas horas.
      <?php endif; ?>
    </p>
    <p class="tenue">Euríbor + <?= e(numero_es($t['diferencial'], 3)) ?> de diferencial, revisión <?= e(minusculas((string)$el['datos']['revision_interes'])) ?>. La ficha, el gasto fijo, la cuenta de la casa y finanzas se ponen al día solos el día de la cuota.</p>
  </section>
<?php endif; ?>

<section class="tarjeta">
  <div class="tarjeta-cabecera"><h2><?= icono('historial') ?>La cuota, revisión a revisión</h2></div>
  <?= grafica_hipoteca($filas, $hoy) ?>
  <p class="tenue">Trazo lleno, lo pagado; discontinuo, lo que viene si el Euríbor se queda como está.</p>
</section>

<div class="ficha-rejilla">
  <section class="tarjeta">
    <div class="tarjeta-cabecera"><h2><?= icono('cartera') ?>Lo pagado y lo que queda</h2></div>
    <div class="progreso" aria-hidden="true"><span style="width:<?= e(number_format(min(100, $r['porcentaje_amortizado']), 1, '.', '')) ?>%"></span></div>
    <p class="tenue">Amortizado el <?= e(numero_es($r['porcentaje_amortizado'])) ?>&nbsp;% del capital · <?= (int)$r['cuotas_pagadas'] ?> cuotas pagadas y <?= (int)$r['cuotas_pendientes'] ?> por pagar</p>
    <dl class="datos datos-compactos">
      <div><dt>Pagado en cuotas</dt><dd><?= e(eur($r['pagado'])) ?></dd></div>
      <div><dt>De ello, intereses</dt><dd><?= e(eur($r['intereses_pagados'])) ?></dd></div>
      <div><dt>Capital pendiente</dt><dd><?= e(eur($r['capital_pendiente'])) ?></dd></div>
      <div><dt>Intereses que quedan</dt><dd>≈<?= e(eur($r['intereses_pendientes'])) ?></dd></div>
      <div><dt>Última cuota</dt><dd><?= e(fecha_es((string)$r['fin'])) ?></dd></div>
      <div><dt>Primera cuota</dt><dd><?= e(fecha_es($filas[0]['fecha'])) ?></dd></div>
    </dl>
    <p class="tenue">Lo que queda se calcula con <?= $cambio && $cambio['fila']['estado'] === 'estimada' ? 'el Euríbor de hoy' : 'el tipo de hoy' ?> para todas las revisiones que faltan: cambiará con cada una.</p>
  </section>

  <?php if (es_admin()): ?>
    <section class="tarjeta">
      <div class="tarjeta-cabecera"><h2><?= icono('check') ?>Lo que ha cobrado el banco</h2></div>
      <?php if (!finanzas_configurada()): ?>
        <p class="vacio-mini">Sin conexión con finanzas: no se pueden casar las cuotas con los cargos del banco.</p>
      <?php elseif (!$cargos): ?>
        <p class="vacio-mini">Finanzas aún no tiene cargos de la hipoteca (categoría «Hipoteca» de la cuenta de la casa).</p>
      <?php else: ?>
        <p>
          <?= count($cobradas) ?> cuota<?= count($cobradas) === 1 ? '' : 's' ?> casan con su cargo en la cuenta de la casa
          (desde <?= e(fecha_es($cargos[0]['fecha'])) ?>)<?= $difieren ? '' : ', todas por el importe del cuadro' ?>.
          <?php foreach ($avisos_cobro as $f): ?>
            <br><span class="estado-cuota estado-<?= e($f['estado']) ?>"><?= e(estado_cuota($f)) ?></span> Cuota del <?= e(fecha_es($f['fecha'])) ?> (<?= e(eur($f['cuota'])) ?>)<?= $f['estado'] === 'pendiente_extracto' ? ': llegará con el próximo extracto que se importe en finanzas' : '' ?>.
          <?php endforeach; ?>
          <?php foreach ($difieren as $f): ?>
            <br>El <?= e(fecha_es($f['cobro']['fecha'])) ?> cobró <?= e(eur($f['cobro']['importe'])) ?> y el cuadro dice <?= e(eur($f['cuota'])) ?>.
          <?php endforeach; ?>
          <?php foreach ($cuadro['cargos_sin_cuota'] as $c): ?>
            <br>Hay un cargo de <?= e(eur($c['importe'])) ?> el <?= e(fecha_es($c['fecha'])) ?> que no casa con ninguna cuota.
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>

<section class="tarjeta" id="cuadro">
  <div class="tarjeta-cabecera"><h2><?= icono('contrato') ?>Cuota a cuota</h2><span class="tenue"><?= count($filas) ?> cuotas</span></div>
  <?php
    $por_anio = [];
    foreach ($filas as $f) $por_anio[substr($f['fecha'], 0, 4)][] = $f;
    $anio_hoy = substr($hoy, 0, 4);
  ?>
  <?php foreach ($por_anio as $anio => $fs):
      $suma = array_sum(array_column($fs, 'cuota'));
      $int = array_sum(array_column($fs, 'intereses'));
      $final = end($fs)['pendiente'];
      $hoy_aqui = array_filter($fs, static fn($f) => $f['fecha'] <= $hoy);
      $mi_fila = $hoy_aqui ? end($hoy_aqui)['n'] : null; ?>
    <details class="cuadro-anio"<?= (string)$anio === $anio_hoy ? ' open' : '' ?>>
      <summary><strong><?= e((string)$anio) ?></strong>
        <span class="tenue"><?= count($fs) ?> cuota<?= count($fs) === 1 ? '' : 's' ?> · <?= e(eur($suma)) ?>, de ellos <?= e(eur($int)) ?> de intereses · queda <?= e(eur($final)) ?></span></summary>
      <div class="tabla-scroll"><table class="tabla">
        <thead><tr><th>Nº</th><th>Fecha</th><th <?= $num ?>>Tipo</th><th <?= $num ?>>Cuota</th><th <?= $num ?>>Intereses</th><th <?= $num ?>>Capital</th><th <?= $num ?>>Pendiente</th><th>Estado</th></tr></thead>
        <tbody>
          <?php foreach ($fs as $f): ?>
            <tr<?= $f['n'] === $mi_fila && (string)$anio === $anio_hoy ? ' class="fila-hoy"' : '' ?>>
              <td><?= (int)$f['n'] ?></td>
              <td><?= e(fecha_es($f['fecha'])) ?></td>
              <td <?= $num ?> title="<?= e(['escritura' => 'Tipo inicial de la escritura', 'banco' => 'Del cuadro del banco', 'calculado' => 'Calculado con el Euríbor cerrado', 'estimado' => 'Estimado con el último Euríbor'][$f['origen']] ?? '') ?>"><?= $f['origen'] === 'estimado' ? '≈' : '' ?><?= e(numero_es((float)$f['tipo'], 3)) ?>&nbsp;%</td>
              <td <?= $num ?>><?= e(eur($f['cuota'])) ?>
                <?php if ($f['cobro'] && abs($f['cobro']['importe'] - $f['cuota']) > 0.004): ?><span class="cobro-dif">cobrado <?= e(eur($f['cobro']['importe'])) ?></span><?php endif; ?>
                <?php if ($f['amortizado'] > 0): ?><span class="cobro-dif">+ <?= e(eur($f['amortizado'])) ?> anticipados</span><?php endif; ?></td>
              <td <?= $num ?>><?= e(eur($f['intereses'])) ?></td>
              <td <?= $num ?>><?= e(eur($f['capital'])) ?></td>
              <td <?= $num ?>><?= e(eur($f['pendiente'])) ?></td>
              <td><span class="estado-cuota estado-<?= e($f['estado']) ?>"<?= $f['cobro'] ? ' title="' . e('Cargo del ' . fecha_es($f['cobro']['fecha']) . ': ' . eur($f['cobro']['importe'])) . '"' : '' ?>><?= e(estado_cuota($f)) ?><?= $f['cobro'] ? ' ' . e(fecha_corta($f['cobro']['fecha'])) : '' ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </details>
  <?php endforeach; ?>
</section>

<section class="tarjeta">
  <div class="tarjeta-cabecera"><h2><?= icono('bombilla') ?>Cómo se calcula</h2></div>
  <ul class="lista-explica">
    <li>Con los términos de la ficha y, hacia atrás, los tipos del cuadro del banco: reproduce su cuadro al céntimo. Intereses = pendiente × tipo ÷ 1.200 (meses de 30 días); la cuota, la de un préstamo francés con lo que queda.</li>
    <?php if ($t['variable']): ?>
      <li>Cada revisión: media del Euríbor del mes anterior a la revisión + <?= e(numero_es($t['diferencial'], 3)) ?>. En cuanto el banco revisa y el mes ha cerrado, la app guarda el tipo y la cuota y, el día de la cuota, los pone en la ficha. Nadie tiene que tocar nada.</li>
      <li>Euríbor: <?= e($eur['fuente'] ?: 'Banco de España') ?><?= $eur['consultado'] ? ', consultado el ' . e(fecha_es(substr($eur['consultado'], 0, 10))) . ' a las ' . e(substr($eur['consultado'], 11, 5)) : '' ?>. Se consulta solo cada pocas horas.<?= $eur['error'] ? ' ' . e($eur['error']) : '' ?></li>
    <?php endif; ?>
    <li>Una amortización anticipada se apunta en el historial de la ficha (tipo «<?= e(HIPOTECA_TIPO_AMORTIZACION) ?>», con el importe; escribe «cuota» en el título si reduce la cuota; si no, reduce el plazo) y el cuadro se rehace solo.</li>
    <?php if (es_admin()): ?><li>Los cobros salen de los movimientos de la cuenta de la casa en finanzas (categoría «Hipoteca»), que se leen cada hora.</li><?php endif; ?>
  </ul>
</section>
<?php pie();
