<?php
// Trabajo: el panel (avisos, equipo de hoy, mi puesto, historial) y el Equipo.
// «Mi puesto» es la ficha del empleo (elemento.php), con estas mismas pestañas.
// Gonzalo, 6/10/2026: «no entrar directamente en Mi puesto: un panel principal
// con avisos, historial, datos en cards», y pestañas Mi puesto y Equipo.
require_once __DIR__ . '/includes/auth.php';

$sec = seccion('trabajo');
$p = ($_GET['p'] ?? '') === 'equipo' ? 'equipo' : 'panel';
$antiguos = $p === 'equipo' && !empty($_GET['antiguos']);
$hoy = hoy();

$empleo = mi_empleo($pdo, (int)($usuario_actual['persona_id'] ?? 0) ?: null);
$equipo = equipo_trabajo($pdo);
$avisos = agenda($pdo, 365, 'trabajo');
$proximo = [];
foreach ($avisos as $v) if ($v['elemento_id'] && !isset($proximo[$v['elemento_id']])) $proximo[$v['elemento_id']] = $v;

$boton_miembro = '<a class="btn btn-sutil" href="' . e(url('elemento-editar.php?s=trabajo&t=miembro')) . '">' . icono('mas') . 'Persona del equipo</a>';
cabecera($sec['nombre'], 'seccion:trabajo');
cabecera_pagina($p === 'equipo' ? 'Equipo' : $sec['nombre'],
    $p === 'equipo' ? '<a href="' . e(url('trabajo.php')) . '">Trabajo</a> · ' . ($antiguos ? 'Los que ya no están' : 'Las personas que diriges')
                    : e($sec['descripcion']),
    $boton_miembro, $p === 'equipo' ? 'familia' : $sec['icono'], $sec['color']);
pestanas_trabajo($p, $empleo, count($equipo));

// Una persona del equipo en tarjeta: puesto, cuánto lleva, su horario de hoy y su próximo aviso.
$tarjeta_miembro = static function (array $m) use ($sec, $hoy, $proximo): void {
    $d = $m['datos'];
    $hoy_h = horario_de_hoy((string)($d['horario'] ?? ''), $hoy);
    $en_equipo = tiempo_desde($d['incorporacion'] ?? ($d['servicio_continuo'] ?? null), $hoy);
    $prox = $proximo[$m['id']] ?? null;
    ?>
    <a class="tarjeta tarjeta-elemento tarjeta-miembro" href="<?= e(url('elemento.php?id=' . $m['id'])) ?>" style="--c:<?= e($sec['color']) ?>">
      <div class="te-cabecera">
        <?= avatar($m['nombre'], $sec['color']) ?>
        <h3><?= e($m['nombre']) ?></h3>
      </div>
      <dl class="resumen">
        <?php if (!empty($d['puesto'])): ?><div><dt>Puesto</dt><dd><?= e($d['puesto']) ?></dd></div><?php endif; ?>
        <?php if ($en_equipo !== ''): ?><div><dt>En el equipo</dt><dd><?= e($en_equipo) ?></dd></div><?php endif; ?>
        <?php if (!empty($d['relacion']) && $d['relacion'] !== 'Plantilla'): ?><div><dt>Relación</dt><dd><?= e($d['relacion']) ?></dd></div><?php endif; ?>
        <?php if (!empty($d['horario'])): ?><div><dt>Hoy</dt><dd><?= e($hoy_h ?? 'No trabaja') ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($prox): ?>
        <p class="ts-proximo venc-<?= e($prox['situacion']) ?>"><?= icono('reloj', 'ico ico-mini') ?><span><?= e(titulo_sin_elemento($prox['titulo'], $m['nombre'])) ?> · <?= e(fecha_corta($prox['fecha'])) ?> · <?= e(relativo($prox['dias'])) ?></span></p>
      <?php endif; ?>
    </a>
    <?php
};

if ($p === 'equipo'):
    $lista = $antiguos ? equipo_trabajo($pdo, false) : $equipo;
    ?>
    <?php if (!$lista): ?>
      <div class="tarjeta vacio">
        <p><?= $antiguos ? 'No hay nadie archivado.' : 'Aún no hay nadie en el equipo. Añade a cada persona con el botón de arriba: puesto, desde cuándo está, su horario…' ?></p>
      </div>
    <?php endif; ?>
    <div class="rejilla rejilla-elementos">
      <?php foreach ($lista as $m) $tarjeta_miembro($m); ?>
    </div>
    <p class="pie-seccion">
      <?php if ($antiguos): ?>
        <a class="enlace-tenue" href="<?= e(url('trabajo.php?p=equipo')) ?>"><?= icono('atras', 'ico ico-mini') ?>Volver al equipo</a>
      <?php else: ?>
        <a class="enlace-tenue" href="<?= e(url('trabajo.php?p=equipo&antiguos=1')) ?>"><?= icono('archivar', 'ico ico-mini') ?>Los que ya no están (archivados)</a>
      <?php endif; ?>
    </p>
<?php pie(); return; endif;

// ---------------------------- Panel ----------------------------
$grupos = agrupar_agenda($avisos);
$en_30 = count(array_filter($avisos, static fn($v) => $v['dias'] >= 0 && $v['dias'] <= 30));
$ed = $empleo['datos'] ?? [];
$mi_hoy = horario_de_hoy((string)($ed['horario'] ?? ''), $hoy);
$en_empresa = tiempo_desde($ed['fecha_alta'] ?? null, $hoy);
$historial = registros_de_seccion($pdo, 'trabajo', 8);
$colgados = $empleo ? elementos_enlazados($pdo, $empleo['id']) : [];
$datos_puesto = [];
if ($empleo) {
    foreach (['puesto', 'categoria', 'contrato', 'jornada', 'fecha_alta', 'revision_salarial', 'fin_contrato'] as $k) {
        $c = tipo_def('trabajo', 'empleo')['campos'][$k];
        if (($ed[$k] ?? '') !== '') $datos_puesto[] = [$c['etiqueta'], valor_campo($c, $ed[$k])];
    }
}
?>
<section class="kpis">
  <div class="kpi <?= $grupos['vencido'] ? 'kpi-rojo' : ($en_30 ? 'kpi-ambar' : '') ?>">
    <span class="kpi-num"><?= $grupos['vencido'] ? count($grupos['vencido']) : $en_30 ?></span>
    <span class="kpi-txt"><?= $grupos['vencido'] ? 'avisos vencidos' : 'avisos en 30 días' ?></span>
  </div>
  <a class="kpi kpi-enlace" href="<?= e(url('trabajo.php?p=equipo')) ?>">
    <span class="kpi-num"><?= count($equipo) ?></span>
    <span class="kpi-txt"><?= count($equipo) === 1 ? 'persona en tu equipo' : 'personas en tu equipo' ?></span>
  </a>
  <div class="kpi">
    <span class="kpi-num kpi-num-texto"><?= $en_empresa !== '' ? e($en_empresa) : '—' ?></span>
    <span class="kpi-txt"><?= $empleo ? 'en ' . e(recortar($empleo['nombre'], 40)) : 'en la empresa' ?></span>
  </div>
  <div class="kpi">
    <span class="kpi-num kpi-num-texto"><?= $mi_hoy !== null ? e(horario_franja($mi_hoy)) : '—' ?></span>
    <span class="kpi-txt">tu horario hoy<?= $mi_hoy !== null && $mi_hoy !== horario_franja($mi_hoy) ? ' · ' . e($mi_hoy) : '' ?></span>
  </div>
</section>

<div class="panel-rejilla">
  <div>
    <section class="tarjeta" id="avisos">
      <div class="tarjeta-cabecera"><h2><?= icono('agenda') ?>Avisos</h2></div>
      <?php if (!$avisos): ?><p class="vacio-mini">Nada en los próximos 12 meses.</p><?php endif; ?>
      <?php foreach ($avisos as $v) fila_vencimiento($v, 'trabajo.php', false); ?>
      <?php formulario_vencimiento('trabajo', null, 'trabajo.php'); ?>
    </section>

    <section class="tarjeta" id="equipo-hoy">
      <div class="tarjeta-cabecera">
        <h2><?= icono('familia') ?>El equipo hoy</h2>
        <a class="enlace-tenue" href="<?= e(url('trabajo.php?p=equipo')) ?>">Ver el equipo</a>
      </div>
      <?php if (!$equipo): ?><p class="vacio-mini">Aún no hay nadie. <a href="<?= e(url('elemento-editar.php?s=trabajo&t=miembro')) ?>">Añade a la primera persona</a>.</p><?php endif; ?>
      <ul class="lista-docs">
        <?php foreach ($equipo as $m): ?>
          <?php $h = horario_de_hoy((string)($m['datos']['horario'] ?? ''), $hoy); ?>
          <li>
            <a href="<?= e(url('elemento.php?id=' . $m['id'])) ?>"><?= e($m['nombre']) ?></a>
            <span class="tenue"><?= e(implode(' · ', array_filter([$m['datos']['puesto'] ?? '',
                !empty($m['datos']['horario']) ? 'hoy ' . ($h ?? 'no trabaja') : '']))) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="tarjeta" id="historial">
      <div class="tarjeta-cabecera"><h2><?= icono('historial') ?>Historial reciente</h2></div>
      <?php if (!$historial): ?><p class="vacio-mini">Sin apuntes todavía. Se apuntan desde cada ficha (evaluaciones, formación, nóminas…).</p><?php endif; ?>
      <ul class="historial">
        <?php foreach ($historial as $r): ?>
          <li>
            <div class="h-fecha"><?= e(fecha_es($r['fecha'])) ?></div>
            <div class="h-cuerpo">
              <strong><?= e($r['titulo']) ?></strong>
              <?php if ($r['tipo'] !== '' && $r['tipo'] !== $r['titulo']): ?><span class="chip"><?= e($r['tipo']) ?></span><?php endif; ?>
              <div class="tenue"><a href="<?= e(url('elemento.php?id=' . $r['elemento_id'] . '#historial')) ?>"><?= e($r['elemento_nombre']) ?></a></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>

  <div>
    <section class="tarjeta" id="mi-puesto">
      <div class="tarjeta-cabecera">
        <h2><?= icono('maletin') ?>Mi puesto</h2>
        <?php if ($empleo): ?><a class="enlace-tenue" href="<?= e(url('elemento.php?id=' . $empleo['id'])) ?>">Abrir la ficha</a><?php endif; ?>
      </div>
      <?php if (!$empleo): ?>
        <p class="vacio-mini">Todavía no hay empleo. <a href="<?= e(url('elemento-editar.php?s=trabajo&t=empleo')) ?>">Créalo</a> con la empresa, el puesto y el contrato.</p>
      <?php else: ?>
        <p><strong><?= e($empleo['nombre']) ?></strong></p>
        <dl class="datos datos-compactos">
          <?php foreach ($datos_puesto as [$etq, $val]): ?>
            <div><dt><?= e($etq) ?></dt><dd><?= e($val) ?></dd></div>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>
    </section>

    <?php if ($empleo): ?>
      <section class="tarjeta" id="convenio">
        <div class="tarjeta-cabecera"><h2><?= icono('contrato') ?>Convenio y contactos</h2></div>
        <?php if (!$colgados): ?><p class="vacio-mini">Ni convenio ni contactos todavía.</p><?php endif; ?>
        <ul class="lista-docs">
          <?php foreach ($colgados as $h): ?>
            <li>
              <a href="<?= e(url('elemento.php?id=' . $h['id'])) ?>"><?= e($h['nombre']) ?></a>
              <span class="tenue"><?= e(implode(' · ', array_filter([tipo_def($h['seccion'], $h['tipo'])['nombre'] ?? '',
                  $h['datos']['oficio'] ?? '', $h['datos']['telefono'] ?? '', $h['datos']['publicacion'] ?? '']))) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </div>
</div>
<?php pie();
