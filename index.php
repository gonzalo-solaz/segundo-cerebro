<?php
// El panel de mandos: lo que vence, cómo está cada sección y qué ha pasado.
require_once __DIR__ . '/includes/auth.php';

$agenda = agenda($pdo, 60);
$grupos = agrupar_agenda($agenda);
$en_7 = count(array_filter($agenda, static fn($v) => $v['dias'] >= 0 && $v['dias'] <= 7));
$en_30 = count(array_filter($agenda, static fn($v) => $v['dias'] >= 0 && $v['dias'] <= 30));
$cuantos = contar_elementos($pdo);
$proximos = proximos_por_seccion($pdo);
$gasto_fijo = coste_mensual_total($pdo);
$actividad = actividad_reciente($pdo, 8);
$vigilancia = estado_vigilancia($pdo);
$vacio = array_sum($cuantos) === 0 && !$agenda;
$n_vencidos = count($grupos['vencido']);

cabecera('Panel', 'index');
cabecera_pagina(saludo() . ', ' . nombre_corto($usuario_actual['nombre']), e(ucfirst(fecha_larga(hoy()))),
    '<a class="btn btn-primario" href="' . e(url('vencimientos.php#nuevo')) . '">' . icono('mas') . 'Recordatorio</a>', '🧠');
?>

<?php if ($vacio): ?>
  <section class="callout callout-info">
    <span class="callout-emoji" aria-hidden="true">👋</span>
    <div>
      <p><strong>Empecemos a llenar tu segundo cerebro.</strong> Cada sección guarda sus cosas y avisa sola de lo que vence.</p>
      <ol>
        <li>Da de alta a la familia en <a href="<?= e(url('personas.php')) ?>">Personas</a>.</li>
        <li>Añade los <a href="<?= e(url('seccion.php?s=documentos')) ?>">DNI y pasaportes</a> con su caducidad.</li>
        <li>Añade los <a href="<?= e(url('seccion.php?s=vehiculos')) ?>">vehículos</a> con la fecha de la próxima ITV.</li>
      </ol>
      <p class="tenue">O pásale a Claude las pólizas, el permiso de circulación o los informes y que los grabe él.</p>
    </div>
  </section>
<?php else: ?>
  <section class="callout <?= $n_vencidos ? 'callout-alerta' : '' ?>" aria-label="Resumen">
    <span class="callout-emoji" aria-hidden="true"><?= $n_vencidos ? '⚠️' : '📌' ?></span>
    <p class="cifras">
      <span class="<?= $n_vencidos ? 'cifra-roja' : '' ?>"><strong><?= $n_vencidos ?></strong> <?= $n_vencidos === 1 ? 'vencido' : 'vencidos' ?></span>
      <span class="<?= $en_7 ? 'cifra-ambar' : '' ?>"><strong><?= $en_7 ?></strong> esta semana</span>
      <span><strong><?= $en_30 ?></strong> en 30 días</span>
      <?php if ($gasto_fijo > 0): ?><span><strong><?= e(eur($gasto_fijo)) ?></strong> de gastos fijos al mes</span><?php endif; ?>
    </p>
  </section>
<?php endif; ?>

<div class="panel-rejilla">
  <section class="bloque">
    <div class="bloque-cabecera">
      <h2>Lo que viene</h2>
      <a class="enlace-tenue" href="<?= e(url('vencimientos.php')) ?>">Toda la agenda →</a>
    </div>
    <?php if (!$agenda): ?>
      <p class="vacio-mini">Nada en los próximos 60 días.</p>
    <?php endif; ?>
    <?php foreach (['vencido' => 'Vencido', 'pronto' => 'Toca ya', 'futuro' => 'Más adelante'] as $g => $titulo): ?>
      <?php if (!$grupos[$g]) continue; ?>
      <h3 class="grupo grupo-<?= $g ?>"><?= e($titulo) ?></h3>
      <?php foreach ($grupos[$g] as $v) fila_vencimiento($v, 'index.php'); ?>
    <?php endforeach; ?>
  </section>

  <aside class="bloque bloque-lateral">
    <div class="bloque-cabecera"><h2>Actividad</h2></div>
    <?php if (!$actividad): ?><p class="vacio-mini">Aún no ha pasado nada.</p><?php endif; ?>
    <ul class="actividad">
      <?php foreach ($actividad as $a): ?>
        <li><strong><?= e(nombre_corto($a['quien'])) ?></strong> <?= e($a['texto']) ?>
          <span class="tenue">· <?= e(relativo(dias_entre(hoy(), substr($a['creado_en'], 0, 10)))) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if (es_admin() && !$vigilancia['ok']): ?>
      <p class="nota nota-aviso"><?= e($vigilancia['texto']) ?>
        <a href="<?= e(url('ajustes.php#sistema')) ?>">Cómo activarlo</a></p>
    <?php endif; ?>
  </aside>
</div>

<h2 class="titulo-bloque">Secciones</h2>
<div class="galeria">
  <?php foreach (secciones() as $k => $s): ?>
    <?php $kpi = kpi_seccion($pdo, $k); $prox = $proximos[$k] ?? null; ?>
    <a class="tarjeta-seccion" href="<?= e(url('seccion.php?s=' . $k)) ?>" style="--c:<?= e($s['color']) ?>">
      <div class="ts-portada"><?= emoji($s['emoji'], 'emoji ts-emoji') ?></div>
      <div class="ts-cuerpo">
        <h3><?= e($s['nombre']) ?></h3>
        <p class="ts-meta"><?= $cuantos[$k] ?> <?= $cuantos[$k] === 1 ? 'elemento' : 'elementos' ?><?= $kpi ? ' · ' . e($kpi) : '' ?></p>
        <?php if ($prox): ?>
          <p class="ts-proximo venc-<?= e($prox['situacion']) ?>"><span class="punto"></span><?= e($prox['titulo']) ?> · <?= e(relativo($prox['dias'])) ?></p>
        <?php else: ?>
          <p class="ts-proximo tenue">Sin avisos pendientes</p>
        <?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>
  <?php if (es_admin() && FINANZAS_URL !== ''): ?>
    <a class="tarjeta-seccion" href="<?= e(FINANZAS_URL) ?>" style="--c:#9F6B53">
      <div class="ts-portada"><?= emoji('💶', 'emoji ts-emoji') ?></div>
      <div class="ts-cuerpo">
        <h3>Finanzas <?= icono('externo', 'ico ico-mini') ?></h3>
        <p class="ts-meta">App aparte, con su propio acceso</p>
        <p class="ts-proximo tenue">Movimientos, nóminas y patrimonio</p>
      </div>
    </a>
  <?php endif; ?>
</div>
<?php pie();
