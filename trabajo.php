<?php
// Trabajo: el panel (avisos, equipo de hoy, mi puesto, historial), el Equipo y
// las Compras. «Mi puesto» es la ficha del empleo (elemento.php), con estas mismas
// pestañas. Gonzalo, 6/10/2026: «no entrar directamente en Mi puesto: un panel
// principal con avisos, historial, datos en cards», pestañas Mi puesto y Equipo y,
// después, «una pestaña Compras con el listado de software que pido, con el CECO» y
// «una pestaña de formación que además vaya a persona/s».
require_once __DIR__ . '/includes/auth.php';

$sec = seccion('trabajo');
$p = in_array($_GET['p'] ?? '', ['equipo', 'formacion', 'compras'], true) ? $_GET['p'] : 'panel';
$antiguos = in_array($p, ['equipo', 'compras'], true) && !empty($_GET['antiguos']);
$hoy = hoy();

$empleo = mi_empleo($pdo, (int)($usuario_actual['persona_id'] ?? 0) ?: null);
$equipo = equipo_trabajo($pdo);
$compras = compras_trabajo($pdo);
$cursos = cursos_trabajo($pdo);
$avisos = agenda($pdo, 365, 'trabajo');
$proximo = [];
foreach ($avisos as $v) if ($v['elemento_id'] && !isset($proximo[$v['elemento_id']])) $proximo[$v['elemento_id']] = $v;

$boton = [
    'compras' => '<a class="btn btn-sutil" href="' . e(url('elemento-editar.php?s=trabajo&t=compra')) . '">' . icono('mas') . 'Compra o licencia</a>',
    'formacion' => '<a class="btn btn-sutil" href="' . e(url('elemento-editar.php?s=trabajo&t=curso')) . '">' . icono('mas') . 'Curso</a>',
][$p] ?? '<a class="btn btn-sutil" href="' . e(url('elemento-editar.php?s=trabajo&t=miembro')) . '">' . icono('mas') . 'Persona del equipo</a>';
$a_trabajo = '<a href="' . e(url('trabajo.php')) . '">Trabajo</a> · ';
[$titulo, $migas, $icono_p] = [
    'panel' => [$sec['nombre'], e($sec['descripcion']), $sec['icono']],
    'equipo' => ['Equipo', $a_trabajo . ($antiguos ? 'Los que ya no están' : 'Las personas que diriges'), 'familia'],
    'formacion' => ['Formación', $a_trabajo . 'Los cursos que has hecho tú y tu equipo', 'birrete'],
    'compras' => ['Compras', $a_trabajo . ($antiguos ? 'Canceladas' : 'Licencias y compras del servicio'), $sec['icono']],
][$p];
cabecera($sec['nombre'], 'seccion:trabajo');
cabecera_pagina($titulo, $migas, $boton, $icono_p, $sec['color']);
pestanas_trabajo($p, $empleo, count($equipo), count($compras), count($cursos));

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

// ---------------------------- Formación ----------------------------
// Un curso por fila, agrupados por curso académico (septiembre a agosto) de su última
// finalización, con quién lo ha hecho. Arriba, un filtro por persona: con él, cada fila
// enseña las fechas de esa persona.
if ($p === 'formacion'):
    $fichas = fichas_trabajo($pdo);
    $filas = formacion_toda($pdo, $fichas);
    $personas = personas_formacion($pdo);
    $quien = (int)($_GET['persona'] ?? 0);
    if ($quien && !isset($fichas[$quien])) $quien = 0;
    $vistas = $quien ? array_values(array_filter($filas, static fn($f) => $f['elemento_id'] === $quien)) : $filas;
    $por_curso = [];
    foreach ($vistas as $f) $por_curso[$f['curso_id']][] = $f;
    $grupos_f = [];
    foreach ($cursos as $c) {
        $fs = $por_curso[$c['id']] ?? [];
        if ($quien && !$fs) continue;
        $fecha = $fs ? max(array_map('fecha_formacion', $fs)) : '';
        // Lo que está a medias (inscrito o en curso, sin fecha de fin) va arriba en «En curso»: con solo la
        // inscripción caería en el curso anterior (Premiere avanzado: inscripción el 29/07/2026, se da en 2026-27).
        $a_medias = array_filter($fs, static fn($f) => !$f['finalizacion'] && in_array($f['estado'], ['Inscrito', 'En curso'], true));
        $g = $a_medias ? 'En curso' : ($fecha !== '' ? curso_de($fecha) : ($fs ? 'Sin fecha' : 'Sin nadie apuntado'));
        $grupos_f[$g][] = ['curso' => $c, 'filas' => $fs, 'fecha' => $fecha];
    }
    $rango = static fn(string $g): int => $g === 'En curso' ? 0 : (curso_valido($g) ? 1 : 2);
    uksort($grupos_f, static fn($a, $b) => [$rango((string)$a), (string)$b] <=> [$rango((string)$b), (string)$a]);
    foreach ($grupos_f as &$g) usort($g, static fn($x, $y) => [$y['fecha'], $x['curso']['nombre']] <=> [$x['fecha'], $y['curso']['nombre']]);
    unset($g);
    $este = curso_de($hoy);
    $este_curso = array_filter($vistas, static fn($f) => $f['estado'] === 'Finalizado' && $f['finalizacion'] && curso_de($f['finalizacion']) === $este);
    $horas_curso = 0.0;
    foreach ($este_curso as $f) $horas_curso += horas_curso($fichas[$f['curso_id']]) ?? 0;
    $en_marcha = array_filter($vistas, static fn($f) => in_array($f['estado'], ['Inscrito', 'En curso'], true) && !$f['finalizacion']);
    $ultima = $vistas[0] ?? null;
    $nombre_p = static fn(array $f): string => $f['persona_tipo'] === 'empleo' ? 'Yo' : nombre_corto($f['persona_nombre']);
    $con_formacion = array_unique(array_column($filas, 'elemento_id'));
    ?>
    <section class="kpis">
      <div class="kpi">
        <span class="kpi-num"><?= $quien ? count($vistas) : count($cursos) ?></span>
        <span class="kpi-txt"><?= $quien ? 'cursos de ' . e(nombre_corto($fichas[$quien]['nombre'])) : (count($cursos) === 1 ? 'curso' : 'cursos') ?></span>
      </div>
      <div class="kpi">
        <span class="kpi-num"><?= count($este_curso) ?></span>
        <span class="kpi-txt">terminados en el curso <?= e($este) ?><?= $horas_curso > 0 ? ' · ' . e(numero_es($horas_curso, 0)) . ' h' : '' ?></span>
      </div>
      <div class="kpi">
        <span class="kpi-num"><?= count($en_marcha) ?></span>
        <span class="kpi-txt">inscritos o en curso</span>
      </div>
      <div class="kpi">
        <span class="kpi-num kpi-num-texto"><?= $ultima ? e(fecha_es(fecha_formacion($ultima))) : '—' ?></span>
        <span class="kpi-txt"><?= $ultima ? 'el último · ' . e(recortar($ultima['curso_nombre'], 40)) : 'sin formación apuntada' ?></span>
      </div>
    </section>

    <?php if ($filas): ?>
      <nav class="filtros" aria-label="Por persona">
        <a class="chip chip-boton<?= $quien ? '' : ' chip-activo' ?>" href="<?= e(url('trabajo.php?p=formacion')) ?>">Todos</a>
        <?php foreach ($personas as $pe): if (!in_array($pe['id'], $con_formacion, true)) continue; ?>
          <a class="chip chip-boton<?= $quien === $pe['id'] ? ' chip-activo' : '' ?>" href="<?= e(url('trabajo.php?p=formacion&persona=' . $pe['id'])) ?>"<?= $quien === $pe['id'] ? ' aria-current="page"' : '' ?>><?= e($pe['tipo'] === 'empleo' ? 'Yo' : nombre_corto($pe['nombre'])) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if (!$cursos): ?>
      <section class="tarjeta">
        <p class="vacio-mini">Aún no hay cursos. Crea cada uno con el botón de arriba y, en su ficha, marca quién lo ha hecho: pueden ser varias personas.</p>
      </section>
    <?php endif; ?>
    <?php foreach ($grupos_f as $g_nombre => $items): ?>
      <section class="tarjeta">
        <div class="tarjeta-cabecera">
          <h2><?= icono('birrete') ?><?= e(curso_valido((string)$g_nombre) ? 'Curso ' . $g_nombre : (string)$g_nombre) ?></h2>
          <span class="tenue"><?= count($items) ?></span>
        </div>
        <div class="tabla-scroll">
          <table class="tabla">
            <thead><tr><th>Curso</th><th><?= $quien ? 'Estado' : 'Quién' ?></th><th>Finalización</th></tr></thead>
            <tbody>
              <?php foreach ($items as $it): $c = $it['curso']; $d = $c['datos']; ?>
                <tr>
                  <td><a href="<?= e(url('elemento.php?id=' . $c['id'])) ?>"><strong><?= e($c['nombre']) ?></strong></a>
                    <?php $sub = array_filter([$d['organiza'] ?? '', $d['modalidad'] ?? '', horas_curso($c) !== null ? numero_es(horas_curso($c), 0) . ' h' : '']);
                    if ($sub): ?><div class="tenue"><?= e(implode(' · ', $sub)) ?></div><?php endif; ?></td>
                  <td><?php if ($quien): $f = $it['filas'][0]; ?>
                      <?= e($f['estado'] !== '' ? $f['estado'] : '—') ?><?php if ($f['resultado'] !== ''): ?><div class="tenue"><?= e($f['resultado']) ?></div><?php endif; ?>
                    <?php elseif (!$it['filas']): ?>
                      <a class="enlace-tenue" href="<?= e(url('elemento.php?id=' . $c['id'] . '#formacion')) ?>">Marcar quién</a>
                    <?php else: ?>
                      <?= implode(', ', array_map(static fn($f) => '<a href="' . e(url('elemento.php?id=' . $f['elemento_id'])) . '">' . e($nombre_p($f)) . '</a>'
                          . (!in_array($f['estado'], ['Finalizado', ''], true) ? ' <span class="tenue">(' . e(minusculas($f['estado'])) . ')</span>' : ''), $it['filas'])) ?>
                    <?php endif; ?></td>
                  <td><?php if ($g_nombre === 'En curso'): $t = (string)($d['termina'] ?? ''); ?>
                      <?= $t !== '' ? 'termina el ' . e(fecha_es($t)) : '—' ?>
                    <?php else: ?><?= $it['fecha'] !== '' ? e(fecha_es($it['fecha'])) : '—' ?><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>
<?php pie(); return; endif;

// ---------------------------- Compras ----------------------------
// Una tabla (se compara mejor de un vistazo que en tarjetas): qué es, cuánto, cuándo se renueva y
// por dónde se compra. Arriba, el CECO del servicio (ficha del empleo), que es lo que se pide al comprar.
if ($p === 'compras'):
    $lista = $antiguos ? compras_trabajo($pdo, false) : $compras;
    // Ordenar por las cabeceras (enlaces, sin JS: la CSP no deja scripts en línea). Lo que no
    // tiene fecha o importe va al final; a igualdad, por nombre.
    $col_fecha = $antiguos ? 'primera_compra' : 'renovacion';
    // Por defecto, por renovación: lo primero que caduca, arriba (Gonzalo, 6/10/2026).
    $orden = in_array($_GET['orden'] ?? '', ['nombre', 'importe'], true) ? $_GET['orden'] : 'fecha';
    if ($orden === 'fecha') {
        usort($lista, static fn($a, $b) => [($a['datos'][$col_fecha] ?? '') === '', $a['datos'][$col_fecha] ?? '', $a['nombre']]
                                       <=> [($b['datos'][$col_fecha] ?? '') === '', $b['datos'][$col_fecha] ?? '', $b['nombre']]);
    } elseif ($orden === 'importe') {
        $clave = static fn($c) => [!is_numeric($c['datos']['importe'] ?? null), -(float)($c['datos']['importe'] ?? 0), $c['nombre']];
        usort($lista, static fn($a, $b) => $clave($a) <=> $clave($b));
    }
    $th = static function (string $clave, string $texto, string $clase = '') use ($orden, $antiguos): string {
        $href = url('trabajo.php?p=compras' . ($antiguos ? '&antiguos=1' : '') . ($clave === 'fecha' ? '' : '&orden=' . $clave));
        $activa = $orden === $clave;
        return '<th' . ($clase ? ' class="' . $clase . '"' : '') . ($activa ? ' aria-sort="' . ($clave === 'importe' ? 'descending' : 'ascending') . '"' : '') . '>'
             . '<a class="th-orden' . ($activa ? ' th-activa' : '') . '" href="' . e($href) . '">' . e($texto) . ($activa ? ($clave === 'importe' ? ' ↓' : ' ↑') : '') . '</a></th>';
    };
    $ceco = trim((string)($empleo['datos']['ceco'] ?? ''));
    $al_anio = 0.0; $sin_importe = 0;
    foreach ($compras as $c) {
        $a = importe_anual($c['datos']);
        if ($a !== null) $al_anio += $a;
        elseif (($c['datos']['periodicidad'] ?? '') !== 'Una vez') $sin_importe++;
    }
    $renovaciones = array_values(array_filter($compras, static fn($c) => ($c['datos']['renovacion'] ?? '') >= $hoy));
    usort($renovaciones, static fn($a, $b) => strcmp($a['datos']['renovacion'], $b['datos']['renovacion']));
    $siguiente = $renovaciones[0] ?? null;
    ?>
    <?php if (!$antiguos): ?>
    <section class="kpis">
      <div class="kpi">
        <span class="kpi-num kpi-num-texto"><?= $ceco !== '' ? e($ceco) : '—' ?></span>
        <span class="kpi-txt">CECO del servicio<?php if ($ceco === '' && $empleo): ?> · <a href="<?= e(url('elemento-editar.php?id=' . $empleo['id'])) ?>">apúntalo en tu puesto</a><?php endif; ?></span>
      </div>
      <div class="kpi">
        <span class="kpi-num"><?= e(eur($al_anio)) ?></span>
        <span class="kpi-txt">al año en renovaciones<?= $sin_importe ? ' · ' . $sin_importe . ' sin importe o periodicidad' : '' ?></span>
      </div>
      <div class="kpi">
        <span class="kpi-num"><?= count($compras) ?></span>
        <span class="kpi-txt"><?= count($compras) === 1 ? 'compra activa' : 'compras activas' ?></span>
      </div>
      <div class="kpi">
        <span class="kpi-num kpi-num-texto"><?= $siguiente ? e(fecha_es($siguiente['datos']['renovacion'])) : '—' ?></span>
        <span class="kpi-txt"><?= $siguiente ? 'próxima renovación · ' . e($siguiente['nombre']) : 'sin renovaciones apuntadas' ?></span>
      </div>
    </section>
    <?php endif; ?>

    <?php
    // Software y servicios por un lado; hardware y material por otro (Gonzalo, 6/10/2026). En el
    // hardware importa para quién es y cuándo se compró (¿toca renovarlo?), no cómo se tramita.
    $grupos_compras = [
        'software' => ['Software y servicios', 'bombilla', array_values(array_filter($lista, static fn($c) => !es_hardware($c)))],
        'hardware' => ['Hardware y material', 'maletin', array_values(array_filter($lista, 'es_hardware'))],
    ];
    if (!$lista): ?>
      <section class="tarjeta">
        <p class="vacio-mini"><?= $antiguos ? 'No hay ninguna cancelada.' : 'Aún no hay compras. Añade cada licencia con el botón de arriba: importe, cuándo se renueva y cómo se compra.' ?></p>
      </section>
    <?php endif; ?>
    <?php foreach ($grupos_compras as $gk => [$g_titulo, $g_icono, $filas]): if (!$filas) continue; $hw = $gk === 'hardware'; ?>
      <section class="tarjeta" id="compras-<?= e($gk) ?>">
        <div class="tarjeta-cabecera">
          <h2><?= icono($g_icono) ?><?= e($g_titulo) ?></h2>
          <span class="tenue"><?= count($filas) ?></span>
        </div>
        <div class="tabla-scroll">
          <table class="tabla">
            <thead><tr><?= $th('nombre', 'Compra') ?><?= $th('importe', 'Importe', 'num') ?>
              <?= $hw ? '<th>Comprado</th>' : '' ?><?= $th('fecha', $antiguos ? 'Primera compra' : 'Renovación') ?>
              <?= $hw ? '' : '<th>Cómo se compra</th>' ?></tr></thead>
            <tbody>
              <?php foreach ($filas as $c): $d = $c['datos']; $f = (string)($antiguos ? ($d['primera_compra'] ?? '') : ($d['renovacion'] ?? '')); $fc = (string)($d['primera_compra'] ?? ''); ?>
                <tr>
                  <td><a href="<?= e(url('elemento.php?id=' . $c['id'])) ?>"><strong><?= e($c['nombre']) ?></strong></a>
                    <?php $sub = array_filter([$d['uso'] ?? '', $d['plazas'] ?? '']); if ($sub): ?><div class="tenue"><?= e(implode(' · ', $sub)) ?></div><?php endif; ?>
                    <?php if (!empty($d['estado'])): ?><span class="chip chip-estado-<?= e(minusculas($d['estado'])) ?>"><?= e($d['estado']) ?></span><?php endif; ?>
                    <?php if (!empty($d['comentario'])): ?><div class="compra-comentario"><?= nl2br(e($d['comentario'])) ?></div><?php endif; ?>
                    <?php if ($c['enlace_id']): ?><div class="tenue">Para <a href="<?= e(url('elemento.php?id=' . $c['enlace_id'])) ?>"><?= e($c['enlace_nombre']) ?></a></div><?php endif; ?></td>
                  <td class="num"><?= is_numeric($d['importe'] ?? null) ? e(eur($d['importe'])) : '—' ?>
                    <?php if (!empty($d['periodicidad'])): ?><div class="tenue"><?= e(minusculas($d['periodicidad'])) ?></div><?php endif; ?></td>
                  <?php if ($hw): ?>
                    <td><?= $fc !== '' ? e(fecha_es($fc)) : '—' ?>
                      <?php if ($fc !== ''): ?><div class="tenue"><?= e(relativo(dias_entre($hoy, $fc))) ?></div><?php endif; ?></td>
                  <?php endif; ?>
                  <td><?= $f !== '' ? e(fecha_es($f)) : '—' ?>
                    <?php if (!$antiguos && $f !== ''): ?><div class="tenue"><?= e(relativo(dias_entre($hoy, $f))) ?></div><?php endif; ?></td>
                  <?php if (!$hw): ?>
                    <td><?= e($d['gestion'] ?? '—') ?>
                      <?php if (($d['ceco'] ?? '') !== ''): ?><div class="tenue">CECO <?= e($d['ceco']) ?></div><?php endif; ?></td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>
    <p class="pie-seccion">
      <?php if ($antiguos): ?>
        <a class="enlace-tenue" href="<?= e(url('trabajo.php?p=compras')) ?>"><?= icono('atras', 'ico ico-mini') ?>Volver a las compras</a>
      <?php else: ?>
        <a class="enlace-tenue" href="<?= e(url('trabajo.php?p=compras&antiguos=1')) ?>"><?= icono('archivar', 'ico ico-mini') ?>Canceladas (archivadas)</a>
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
$curso = curso_de($hoy);
$plan_curso = plan_del_curso($pdo, $curso);
$ultima_nota = ultima_nota_por_elemento($pdo);
$con_plan = array_merge($empleo ? [$empleo] : [], $equipo);
$colgados = $empleo ? elementos_enlazados($pdo, $empleo['id']) : [];
$docs_equipo = documentos_trabajo($pdo);
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

    <section class="tarjeta" id="plan-desarrollo">
      <div class="tarjeta-cabecera"><h2><?= icono('bombilla') ?>Plan de desarrollo <?= e($curso) ?></h2></div>
      <?php if (!$con_plan): ?><p class="vacio-mini">Aparece cuando haya equipo.</p><?php endif; ?>
      <ul class="lista-docs">
        <?php foreach ($con_plan as $m): $pl = $plan_curso[$m['id']] ?? null; $un = $ultima_nota[$m['id']] ?? null; ?>
          <li>
            <a href="<?= e(url('elemento.php?id=' . $m['id'] . '#plan')) ?>"><?= e($m['tipo'] === 'empleo' ? 'Yo' : nombre_corto($m['nombre'])) ?></a>
            <span class="tenue"><?= e(implode(' · ', array_filter([
                $pl && $pl['objetivo'] !== '' ? recortar($pl['objetivo'], 90) : 'Sin objetivo para este curso',
                $un ? 'última nota ' . nota_es($un['nota']) . ' (' . $un['curso'] . ')' : '']))) ?></span>
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

    <section class="tarjeta" id="documentos-equipo">
      <div class="tarjeta-cabecera">
        <h2><?= icono('documento') ?>Documentos del equipo</h2>
        <a class="enlace-tenue" href="<?= e(url('elemento-editar.php?s=trabajo&t=documento')) ?>">Añadir</a>
      </div>
      <?php if (!$docs_equipo): ?><p class="vacio-mini">Manuales, normas y protocolos que das al equipo.</p><?php endif; ?>
      <ul class="lista-docs">
        <?php foreach ($docs_equipo as $d): $pdf = documentos_de($pdo, $d['id'])[0] ?? null; ?>
          <li>
            <a href="<?= e(url('elemento.php?id=' . $d['id'])) ?>"><?= e($d['nombre']) ?></a>
            <span class="tenue"><?= e(implode(' · ', array_filter([$d['datos']['categoria'] ?? '',
                !empty($d['datos']['enviado']) ? 'enviado el ' . fecha_es($d['datos']['enviado']) : '']))) ?></span>
            <?php if ($pdf): ?>
              <a class="enlace-tenue" href="<?= e(url('archivo.php?id=' . $pdf['id'])) ?>" target="_blank" rel="noopener"><?= icono('clip', 'ico ico-mini') ?>Abrir el PDF</a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>
</div>
<?php pie();
