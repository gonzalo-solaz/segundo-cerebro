<?php
// El panel de mandos: lo que vence, cómo está cada sección y qué ha pasado.
require_once __DIR__ . '/includes/auth.php';

$agenda = agenda($pdo, 60);
$grupos = agrupar_agenda($agenda);
$en_7 = count(array_filter($agenda, static fn($v) => $v['dias'] >= 0 && $v['dias'] <= 7));
$en_30 = count(array_filter($agenda, static fn($v) => $v['dias'] >= 0 && $v['dias'] <= 30));
$cuantos = contar_elementos($pdo);
$proximos = proximos_por_seccion($pdo);
// Lo que paga quien mira (la hipoteca al 60 %, los suministros a medias…), con el total de la casa en pequeño.
$gasto_fijo = analisis_gastos_fijos(elementos_con_coste($pdo), [], hoy(), (int)($usuario_actual['persona_id'] ?? 0) ?: null);
$actividad = actividad_reciente($pdo, 8);
$vigilancia = estado_vigilancia($pdo);
$vacio = array_sum($cuantos) === 0 && !$agenda;

cabecera('Panel', 'index');
cabecera_pagina(saludo() . ', ' . nombre_corto($usuario_actual['nombre']), e(ucfirst(fecha_larga(hoy()))),
    '<a class="btn btn-primario" href="' . e(url('vencimientos.php#nuevo')) . '">' . icono('mas') . 'Recordatorio</a>');
?>

<?php if ($vacio): ?>
  <section class="tarjeta bienvenida">
    <h2>Empecemos a llenar tu segundo cerebro</h2>
    <p>Cada sección guarda sus cosas y avisa sola de lo que vence. Lo más rápido para empezar:</p>
    <ol>
      <li>Da de alta a la familia en <a href="<?= e(url('personas.php')) ?>">Personas</a>.</li>
      <li>Añade los <a href="<?= e(url('seccion.php?s=documentos')) ?>">DNI y pasaportes</a> con su caducidad.</li>
      <li>Añade los <a href="<?= e(url('seccion.php?s=vehiculos')) ?>">vehículos</a> con la fecha de la próxima ITV.</li>
    </ol>
    <p class="tenue">O pásale a Claude las pólizas, el permiso de circulación o los informes y que los grabe él (ver INSTRUCCIONES.md).</p>
  </section>
<?php endif; ?>

<section class="kpis">
  <div class="kpi <?= $grupos['vencido'] ? 'kpi-rojo' : '' ?>">
    <span class="kpi-num"><?= count($grupos['vencido']) ?></span><span class="kpi-txt">vencidos</span>
  </div>
  <div class="kpi <?= $en_7 ? 'kpi-ambar' : '' ?>">
    <span class="kpi-num"><?= $en_7 ?></span><span class="kpi-txt">esta semana</span>
  </div>
  <div class="kpi">
    <span class="kpi-num"><?= $en_30 ?></span><span class="kpi-txt">en 30 días</span>
  </div>
  <a class="kpi kpi-enlace" href="<?= e(url('gastos-fijos.php')) ?>">
    <span class="kpi-num kpi-num-texto"><?= $gasto_fijo['tuyo'] > 0 ? e(eur($gasto_fijo['tuyo'])) : '—' ?></span>
    <span class="kpi-txt"><?= $gasto_fijo['a_medias'] ? 'tus gastos fijos al mes · de ' . e(eur($gasto_fijo['total'])) . ' de la casa' : 'gastos fijos al mes' ?></span>
    <span class="kpi-mas">A dónde va <?= icono('atras', 'ico ico-mini ico-girado') ?></span>
  </a>
</section>

<div class="panel-rejilla">
  <section class="tarjeta">
    <div class="tarjeta-cabecera">
      <h2><?= icono('agenda') ?>Lo que viene</h2>
      <a class="enlace-tenue" href="<?= e(url('vencimientos.php')) ?>">Ver toda la agenda</a>
    </div>
    <?php if (!$agenda): ?>
      <p class="vacio-mini">Nada en los próximos 60 días. 🎉</p>
    <?php endif; ?>
    <?php foreach (['vencido' => 'Vencido', 'pronto' => 'Toca ya', 'futuro' => 'Más adelante'] as $g => $titulo): ?>
      <?php if (!$grupos[$g]) continue; ?>
      <h3 class="grupo grupo-<?= $g ?>"><?= e($titulo) ?></h3>
      <?php foreach ($grupos[$g] as $v) fila_vencimiento($v, 'index.php'); ?>
    <?php endforeach; ?>
  </section>

  <aside class="tarjeta">
    <div class="tarjeta-cabecera"><h2><?= icono('historial') ?>Actividad</h2></div>
    <?php if (!$actividad): ?><p class="vacio-mini">Aún no ha pasado nada.</p><?php endif; ?>
    <ul class="actividad">
      <?php foreach ($actividad as $a): ?>
        <li><strong><?= e(nombre_corto($a['quien'])) ?></strong> <?= e($a['texto']) ?>
          <span class="tenue"><?= e(relativo(dias_entre(hoy(), substr($a['creado_en'], 0, 10)))) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if (es_admin() && !$vigilancia['ok']): ?>
      <p class="nota nota-aviso"><?= icono('alerta', 'ico ico-mini') ?><?= e($vigilancia['texto']) ?>
        <a href="<?= e(url('ajustes.php#sistema')) ?>">Ver cómo activarlo</a></p>
    <?php endif; ?>
  </aside>
</div>

<h2 class="titulo-bloque">Secciones</h2>
<div class="rejilla">
  <?php foreach (secciones() as $k => $s): ?>
    <?php $kpi = kpi_seccion($pdo, $k); $prox = $proximos[$k] ?? null; ?>
    <a class="tarjeta tarjeta-seccion" href="<?= e(url('seccion.php?s=' . $k)) ?>" style="--c:<?= e($s['color']) ?>">
      <div class="ts-cabecera">
        <span class="icono-grande"><?= icono($s['icono']) ?></span>
        <div>
          <h3><?= e($s['nombre']) ?></h3>
          <span class="tenue"><?= $cuantos[$k] ?> <?= $cuantos[$k] === 1 ? 'elemento' : 'elementos' ?></span>
        </div>
      </div>
      <?php if ($kpi): ?><p class="ts-kpi"><?= e($kpi) ?></p><?php endif; ?>
      <?php if ($prox): ?>
        <p class="ts-proximo venc-<?= e($prox['situacion']) ?>"><?= icono('reloj', 'ico ico-mini') ?><span><?= e($prox['titulo']) ?> · <?= e(relativo($prox['dias'])) ?></span></p>
      <?php else: ?>
        <p class="ts-proximo tenue">Sin avisos pendientes</p>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if (es_admin() && FINANZAS_URL !== ''): ?>
    <a class="tarjeta tarjeta-seccion" href="<?= e(url('finanzas.php')) ?>" style="--c:#405189">
      <div class="ts-cabecera">
        <span class="icono-grande"><?= icono('cartera') ?></span>
        <div><h3>Finanzas</h3><span class="tenue">Cuentas, gasto del mes y nóminas</span></div>
      </div>
      <p class="ts-kpi">Movimientos, nóminas y patrimonio</p>
    </a>
  <?php endif; ?>
</div>
<?php pie();
