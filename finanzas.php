<?php
// =====================================================================
//  Finanzas, como sección del segundo cerebro (fase 3, 3/10/2026). Solo
//  administradores. Los datos NO viven aquí: se leen de la API de finanzas
//  (acción «resumen», con copia de una hora; includes/finanzas.php). Lo
//  pesado —el dashboard, importar extractos, revisar, la nómina— sigue en
//  finanzas, a un clic y sin contraseña (finanzas-entrar.php).
// =====================================================================
require_once __DIR__ . '/includes/auth.php';

if (!es_admin()) pagina_error(403, 'Solo administradores', 'Finanzas solo la ven los administradores.');

$r = finanzas_resumen(isset($_GET['recargar']));
$d = $r['datos'];
$nom = resumen_nominas(finanzas_nominas()['anios'], hoy());
$empleo = null;
foreach (elementos_de($pdo, 'trabajo') as $x) {
    if ($x['tipo'] === 'empleo' && ($x['datos']['nominas_finanzas'] ?? '') === 'Sí') { $empleo = $x; break; }
}

$liquidez = 0.0;
foreach ($d['saldos'] ?? [] as $s) if (!empty($s['activa'])) $liquidez += (float)$s['saldo'];
$meses = $d['gasto']['meses'] ?? [];
$ultimo = $d['gasto']['ultimo_completo'] ?? null;
$fila_ultimo = null;
$previos = [];
foreach ($meses as $m) {
    if ($m['mes'] === $ultimo) $fila_ultimo = $m;
    elseif ($ultimo !== null && $m['mes'] < $ultimo) $previos[] = (float)$m['gasto'];
}
$media = $previos ? array_sum($previos) / count($previos) : null;
$avisos = array_filter($d['comprobaciones'] ?? [], static fn($c) => ($c['estado'] ?? 'ok') !== 'ok');
$pendientes = (int)($d['pendientes'] ?? 0);

$boton = static fn(string $pagina, string $texto, string $ico, string $clase = 'btn-sutil') =>
    '<a class="btn ' . $clase . '" href="' . e(url_finanzas($pagina)) . '">' . icono($ico) . e($texto) . '</a>';
$acciones = $boton('index.php', 'Abrir el dashboard', 'externo', 'btn-primario');

cabecera('Finanzas', 'finanzas');
cabecera_pagina('Finanzas', 'Datos de la app de finanzas' . ($r['leido_en'] ? ' · ' . e(fecha_corta(substr($r['leido_en'], 0, 10)) . ' ' . substr($r['leido_en'], 11, 5)) : ''),
    $acciones, 'cartera', '#405189');
?>
<?php if ($r['error']): ?><div class="flash flash-aviso"><?= e($r['error']) ?></div><?php endif; ?>

<?php if ($d): ?>
<section class="kpis">
  <div class="kpi">
    <span class="kpi-num kpi-num-texto"><?= e(eur($liquidez)) ?></span><span class="kpi-txt">en las cuentas</span>
  </div>
  <div class="kpi <?= $media !== null && $fila_ultimo && $fila_ultimo['gasto'] > $media * 1.15 ? 'kpi-ambar' : '' ?>">
    <span class="kpi-num kpi-num-texto"><?= $fila_ultimo ? e(eur($fila_ultimo['gasto'])) : '—' ?></span>
    <span class="kpi-txt">gastado en <?= $ultimo ? e(mes_es($ultimo)) : '—' ?><?= $media !== null ? ' · media ' . e(eur($media)) : '' ?></span>
  </div>
  <div class="kpi <?= $pendientes ? 'kpi-ambar' : '' ?>">
    <span class="kpi-num"><?= $pendientes ?></span><span class="kpi-txt">movimientos sin categorizar</span>
  </div>
  <div class="kpi <?= $avisos ? 'kpi-rojo' : 'kpi-verde' ?>">
    <span class="kpi-num"><?= count($avisos) ?></span><span class="kpi-txt"><?= !$avisos ? 'todo en orden' : (count($avisos) === 1 ? 'cosa que mirar' : 'cosas que mirar') ?></span>
  </div>
</section>

<div class="acciones-fila-pagina">
  <?= $boton('importador.php', 'Importar extractos', 'clip') ?>
  <?= $boton('revisar.php', 'Revisar' . ($pendientes ? " ({$pendientes})" : ''), 'check') ?>
  <?= $boton('movimiento.php', 'Movimiento a mano', 'mas') ?>
  <?= $boton('nomina.php', 'Nómina', 'documento') ?>
  <?= $boton('salud.php', 'Salud de los datos', 'salud') ?>
  <a class="btn btn-sutil" href="<?= e(url('finanzas.php?recargar=1')) ?>"><?= icono('repetir') ?>Actualizar</a>
</div>

<?php if ($avisos): ?>
  <section class="tarjeta">
    <div class="tarjeta-cabecera"><h2><?= icono('alerta') ?>Qué mirar</h2></div>
    <ul class="lista-docs">
      <?php foreach ($avisos as $c): ?>
        <li><strong><?= e($c['titulo']) ?></strong> <span class="tenue"><?= e(is_array($c['detalle']) ? implode(' · ', $c['detalle']) : (string)$c['detalle']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<div class="ficha-rejilla">
  <div class="ficha-columna">
    <section class="tarjeta">
      <div class="tarjeta-cabecera"><h2><?= icono('cartera') ?>Cuentas</h2></div>
      <div class="tabla-scroll"><table class="tabla">
        <thead><tr><th>Cuenta</th><th class="num">Saldo</th><th>Último movimiento</th></tr></thead>
        <tbody>
          <?php foreach ($d['saldos'] ?? [] as $s): ?>
            <tr class="<?= empty($s['activa']) ? 'apagado' : '' ?>">
              <td><?= e($s['cuenta']) ?><?php if ($s['cuadra'] === false): ?> <span class="chip" title="El saldo calculado no coincide con el del banco">no cuadra</span><?php endif; ?></td>
              <td class="num"><?= e(eur($s['saldo'])) ?></td>
              <td class="tenue"><?= $s['ultimo_movimiento'] ? e(fecha_es((string)$s['ultimo_movimiento'])) : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>

    <section class="tarjeta">
      <div class="tarjeta-cabecera"><h2><?= icono('historial') ?>Gasto e ingreso por mes</h2></div>
      <div class="tabla-scroll"><table class="tabla">
        <thead><tr><th>Mes</th><th class="num">Gasto</th><th class="num">Ingreso</th><th class="num">Ahorro</th></tr></thead>
        <tbody>
          <?php foreach (array_reverse($meses) as $m): ?>
            <tr>
              <td><?= e(ucfirst(mes_es($m['mes']))) ?><?= $m['mes'] > (string)$ultimo ? ' <span class="chip">en curso</span>' : '' ?></td>
              <td class="num"><?= e(eur($m['gasto'])) ?></td>
              <td class="num"><?= e(eur($m['ingreso'])) ?></td>
              <td class="num <?= $m['ingreso'] - $m['gasto'] < 0 ? 'texto-alto' : '' ?>"><?= e(eur($m['ingreso'] - $m['gasto'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
  </div>

  <div class="ficha-columna">
    <section class="tarjeta">
      <div class="tarjeta-cabecera"><h2><?= icono('contrato') ?>En qué se fue <?= $ultimo ? e(mes_es($ultimo)) : '' ?></h2><span class="tenue">frente a su media</span></div>
      <div class="tabla-scroll"><table class="tabla">
        <thead><tr><th>Categoría</th><th class="num">Mes</th><th class="num">Media</th></tr></thead>
        <tbody>
          <?php foreach ($d['gasto']['categorias'] ?? [] as $c): ?>
            <tr>
              <td><?= e($c['categoria']) ?></td>
              <td class="num <?= $c['media'] > 0 && $c['mes'] > $c['media'] * 1.5 ? 'texto-alto' : '' ?>"><?= e(eur($c['mes'])) ?></td>
              <td class="num tenue"><?= e(eur($c['media'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>

    <section class="tarjeta">
      <div class="tarjeta-cabecera"><h2><?= icono('maletin') ?>Nóminas<?= $nom ? ' ' . (int)$nom['anio'] : '' ?></h2></div>
      <?php if ($nom): ?>
        <p>Líquido cobrado: <strong><?= e(eur($nom['liquido'])) ?></strong> en <?= (int)$nom['n'] ?> recibos<?= $nom['pagas'] ? ' de ' . (int)$nom['pagas'] : '' ?>.</p>
        <?php if ($nom['falta']): ?><div class="flash flash-aviso">Falta grabar la nómina de <?= e(mes_es($nom['falta'])) ?>.</div><?php endif; ?>
      <?php else: ?>
        <p class="vacio-mini">Sin nóminas que enseñar.</p>
      <?php endif; ?>
      <?php if ($empleo): ?><a class="btn btn-sutil" href="<?= e(url('elemento.php?id=' . $empleo['id'] . '#nominas')) ?>"><?= icono('maletin') ?><?= e($empleo['nombre']) ?></a><?php endif; ?>
    </section>
  </div>
</div>
<?php endif; ?>
<?php pie();
