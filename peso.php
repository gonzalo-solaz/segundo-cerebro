<?php
// Control de peso de una persona: IMC, evolución, ritmo, objetivo, calorías
// y pautas. Los números salen solos de los pesajes (apuntes del historial);
// el plan personal es el campo «Plan y pautas personales» de la ficha.
require_once __DIR__ . '/includes/auth.php';

$sec = seccion('salud');
$controles = elementos_peso($pdo);
$el = null;
if (isset($_GET['id'])) {
    $el = elemento($pdo, (int)$_GET['id']);
    if (!$el || $el['seccion'] !== 'salud' || $el['tipo'] !== 'peso') pagina_error(404, 'No encontrado', 'Ese control de peso no existe.');
}
$el ??= $controles[0] ?? null;
$uid = (int)$usuario_actual['id'];

if ($el && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $aqui = 'peso.php?id=' . $el['id'];
    if (!csrf_ok()) {
        flash('error', 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.');
    } else {
        try {
            switch ((string)($_POST['accion'] ?? '')) {
                case 'medicion':
                    guardar_medicion($pdo, $el['id'], $_POST, $uid);
                    flash('ok', 'Apuntado.');
                    break;
                case 'borrar-medicion':
                    borrar_medicion($pdo, $el['id'], array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))), $uid);
                    $aqui .= '#registro';
                    break;
            }
        } catch (ErrorValidacion $ex) {
            flash('error', implode(' ', $ex->errores));
        }
    }
    redirigir($aqui);
}

$rangos = ['3m' => ['3 meses', -3], '6m' => ['6 meses', -6], '1a' => ['1 año', -12], 'todo' => ['Todo', null]];
$rango = isset($rangos[$_GET['r'] ?? '']) ? $_GET['r'] : '1a';
$a = $el ? analisis_peso($pdo, $el) : null;
$desde = $a && $a['fecha'] && $rangos[$rango][1] !== null ? sumar_meses($a['fecha'], $rangos[$rango][1]) : null;
$todas = !empty($_GET['todas']);

$acciones = '';
if ($el) {
    $acciones = '<a class="btn btn-sutil" href="' . e(url('elemento.php?id=' . $el['id'])) . '">' . icono('salud') . 'Ficha</a>'
              . '<a class="btn btn-sutil" href="' . e(url('elemento-editar.php?id=' . $el['id'])) . '">' . icono('editar') . 'Altura y objetivo</a>';
}
cabecera($el ? $el['nombre'] : 'Control de peso', 'seccion:salud');
cabecera_pagina($el ? $el['nombre'] : 'Control de peso',
    '<a href="' . e(url('seccion.php?s=salud')) . '">' . e($sec['nombre']) . '</a> · Control de peso',
    $acciones, 'bascula', $sec['color']);
?>

<?php if (!$el): ?>
  <div class="tarjeta vacio">
    <p>Todavía nadie lleva un control de peso.</p>
    <p><a class="btn btn-primario" href="<?= e(url('elemento-editar.php?s=salud&t=peso')) ?>"><?= icono('mas') ?>Empezar uno</a></p>
    <p class="tenue">Con la altura y el primer pesaje ya salen el IMC y el peso sano; con la edad (en Personas), el sexo y la actividad, también las calorías.</p>
  </div>
<?php pie(); return; endif; ?>

<?php if (count($controles) > 1): ?>
  <p class="filtros">
    <?php foreach ($controles as $c): ?>
      <a class="chip chip-boton<?= $c['id'] === $el['id'] ? ' chip-activo' : '' ?>" href="<?= e(url('peso.php?id=' . $c['id'])) ?>"><?= e($c['persona_nombre'] ?? $c['nombre']) ?></a>
    <?php endforeach; ?>
  </p>
<?php endif; ?>

<?php if ($a['actual'] !== null): ?>
  <div class="kpis">
    <div class="kpi">
      <span class="kpi-txt">Peso</span>
      <span class="kpi-num"><?= e(numero_es($a['actual'])) ?> <small>kg</small></span>
      <span class="kpi-txt"><?= e(fecha_es($a['fecha'])) ?></span>
    </div>
    <div class="kpi<?= $a['categoria'] && $a['categoria'][1] === 'alto' ? ' kpi-rojo' : ($a['categoria'] && $a['categoria'][1] === 'aviso' ? ' kpi-ambar' : ' kpi-verde') ?>">
      <span class="kpi-txt">IMC</span>
      <?php if ($a['imc'] !== null): ?>
        <span class="kpi-num"><?= e(numero_es($a['imc'])) ?></span>
        <span class="kpi-txt"><?= e($a['menor'] ? 'Menor: ver percentiles' : $a['categoria'][0]) ?></span>
      <?php else: ?>
        <span class="kpi-num kpi-num-texto">—</span>
        <span class="kpi-txt"><a href="<?= e(url('elemento-editar.php?id=' . $el['id'])) ?>">Falta la altura</a></span>
      <?php endif; ?>
    </div>
    <div class="kpi">
      <?php $c30 = $a['cambios'][30] ?? null; $ini = round($a['actual'] - $a['inicial'], 2); ?>
      <span class="kpi-txt"><?= $c30 ? 'En 30 días' : 'Desde el inicio' ?></span>
      <span class="kpi-num"><?= e(kg_signo($c30 ? $c30['kg'] : $ini)) ?></span>
      <span class="kpi-txt"><?= $c30 ? 'Desde el inicio: ' . e(kg_signo($ini)) : 'Desde el ' . e(fecha_es($a['fecha_inicial'])) ?></span>
    </div>
    <div class="kpi">
      <span class="kpi-txt">Objetivo</span>
      <?php if ($a['objetivo'] !== null): ?>
        <span class="kpi-num"><?= abs($a['falta']) < 0.3 ? '✓' : e(numero_es(abs($a['falta'])) . ' kg') ?></span>
        <span class="kpi-txt"><?= abs($a['falta']) < 0.3 ? 'Conseguido: ' . e(numero_es($a['objetivo'])) . ' kg'
            : ($a['falta'] > 0 ? 'por perder' : 'por ganar') . ' hasta ' . e(numero_es($a['objetivo'])) . ' kg'
              . ($a['llegada'] ? ' · ' . e(fecha_corta($a['llegada'])) : '') ?></span>
      <?php else: ?>
        <span class="kpi-num kpi-num-texto">—</span>
        <span class="kpi-txt"><a href="<?= e(url('elemento-editar.php?id=' . $el['id'])) ?>">Ponte uno</a></span>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="ficha-rejilla peso-rejilla">
  <div class="peso-apuntar">
    <section class="tarjeta" id="apuntar">
      <div class="tarjeta-cabecera"><h2><?= icono('mas') ?>Apuntar</h2></div>
      <form method="post" class="form-rejilla">
        <?= csrf_input() ?><input type="hidden" name="accion" value="medicion">
        <div class="campo"><label>Fecha</label><input type="date" name="fecha" value="<?= e(hoy()) ?>" max="<?= e(hoy()) ?>" required></div>
        <div class="campo"><label>Peso</label><div class="con-sufijo"><input type="text" inputmode="decimal" name="peso" placeholder="<?= e($a['actual'] !== null ? numero_es($a['actual']) : '80,5') ?>"><span>kg</span></div></div>
        <div class="campo"><label>Cintura <span class="tenue">(opcional)</span></label><div class="con-sufijo"><input type="text" inputmode="decimal" name="cintura"><span>cm</span></div></div>
        <div class="campo"><label>Grasa corporal <span class="tenue">(opcional)</span></label><div class="con-sufijo"><input type="text" inputmode="decimal" name="grasa"><span>%</span></div></div>
        <div class="campo campo-ancho"><label>Notas <span class="tenue">(opcional)</span></label><input type="text" name="notas" maxlength="255" placeholder="Ej.: después de vacaciones"></div>
        <div class="campo-ancho"><button class="btn btn-primario"><?= icono('check') ?>Apuntar</button>
          <span class="ayuda">Si ese día ya había una medida, se corrige.</span></div>
      </form>
    </section>
  </div>

  <div class="ficha-columna peso-izq">
    <?php if ($a['actual'] !== null): ?>
      <section class="tarjeta" id="evolucion">
        <div class="tarjeta-cabecera">
          <h2><?= icono('historial') ?>Evolución</h2>
          <span class="filtros filtros-mini">
            <?php foreach ($rangos as $k => [$txt]): ?>
              <a class="chip chip-boton<?= $k === $rango ? ' chip-activo' : '' ?>" href="<?= e(url('peso.php?id=' . $el['id'] . '&r=' . $k . '#evolucion')) ?>"><?= e($txt) ?></a>
            <?php endforeach; ?>
          </span>
        </div>
        <?= grafica_peso($a, $desde) ?>
        <p class="gp-leyenda tenue">
          <span><i class="gp-l-punto"></i>Pesaje</span>
          <span><i class="gp-l-linea"></i>Tendencia (sin el vaivén del agua)</span>
          <?php if ($a['rango_sano']): ?><span><i class="gp-l-banda"></i>Peso sano (IMC 18,5-24,9)</span><?php endif; ?>
          <?php if ($a['objetivo'] !== null): ?><span><i class="gp-l-objetivo"></i>Objetivo</span><?php endif; ?>
        </p>
        <?php if ($a['cambios']): ?>
          <dl class="datos datos-compactos">
            <?php foreach ([7 => 'En 7 días', 30 => 'En 30 días', 90 => 'En 3 meses', 365 => 'En un año'] as $n => $txt): ?>
              <?php if (!isset($a['cambios'][$n])) continue; ?>
              <div><dt><?= e($txt) ?></dt><dd><?= e(kg_signo($a['cambios'][$n]['kg'])) ?></dd></div>
            <?php endforeach; ?>
            <?php if ($a['ritmo'] !== null): ?>
              <div><dt>Ritmo</dt><dd><?= e(kg_signo($a['ritmo'], ' kg/semana')) ?></dd></div>
            <?php endif; ?>
            <div><dt>Mínimo · máximo</dt><dd><?= e(numero_es($a['minimo']) . ' · ' . numero_es($a['maximo'])) ?> kg</dd></div>
          </dl>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="tarjeta" id="situacion">
      <div class="tarjeta-cabecera"><h2><?= icono('salud') ?>Cómo vas</h2></div>
      <?php if ($a['imc'] !== null && !$a['menor']): ?>
        <?php $pos = max(0, min(100, ($a['imc'] - 15) / 25 * 100)); ?>
        <div class="imc-escala" aria-hidden="true">
          <span class="imc-bajo" style="width:14%"></span><span class="imc-normal" style="width:26%"></span><span class="imc-sobre" style="width:20%"></span><span class="imc-obeso" style="width:40%"></span>
          <i style="left:<?= e(number_format($pos, 1, '.', '')) ?>%"></i>
        </div>
        <div class="imc-marcas tenue" aria-hidden="true"><span style="left:14%">18,5</span><span style="left:40%">25</span><span style="left:60%">30</span><span style="left:80%">35</span></div>
      <?php endif; ?>
      <ul class="consejos">
        <?php foreach ($a['consejos'] as [$clase, $txt]): ?>
          <li class="consejo-<?= e($clase) ?>"><?= e($txt) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($a['cintura'] !== null || $a['grasa']): ?>
        <dl class="datos datos-compactos">
          <?php if ($a['cintura'] !== null): ?>
            <div><dt>Cintura</dt><dd><?= e(numero_es($a['cintura'])) ?> cm <span class="tenue">· <?= e(fecha_corta($a['fecha_cintura'])) ?></span></dd></div>
          <?php endif; ?>
          <?php if ($a['ica'] !== null): ?>
            <div><dt>Cintura / altura</dt><dd class="<?= $a['ica'] >= 0.6 ? 'texto-alto' : ($a['ica'] >= 0.5 ? 'texto-aviso' : 'texto-ok') ?>"><?= e(number_format($a['ica'], 2, ',', '')) ?> <span class="tenue">(sano &lt; 0,5)</span></dd></div>
          <?php endif; ?>
          <?php if ($a['grasa']): ?>
            <div><dt>Grasa corporal</dt><dd><?= e(numero_es($a['grasa']['valor'])) ?> % <span class="tenue">· <?= e(fecha_corta($a['grasa']['fecha'])) ?></span></dd></div>
          <?php endif; ?>
        </dl>
      <?php endif; ?>
    </section>

    <?php if (!$a['menor']): ?>
      <section class="tarjeta" id="calorias">
        <div class="tarjeta-cabecera"><h2><?= icono('llama') ?>Calorías y proteína</h2></div>
        <?php if ($k = $a['calorias']): ?>
          <dl class="datos">
            <?php if ($k['adelgazar']): ?>
              <div><dt>Para bajar ~0,5 kg/semana</dt><dd><strong><?= e(numero_es($k['adelgazar'])) ?> kcal</strong> al día</dd></div>
              <div><dt>Para mantenerte</dt><dd><?= e(numero_es($k['mantener'])) ?> kcal al día</dd></div>
            <?php endif; ?>
            <div><dt>En reposo (basal)</dt><dd><?= e(numero_es($k['basal'])) ?> kcal al día</dd></div>
            <div><dt>Proteína</dt><dd><?= (int)$k['proteina'][0] ?>-<?= (int)$k['proteina'][1] ?> g al día</dd></div>
          </dl>
          <?php if (!$k['mantener']): ?>
            <p class="nota">Elige la actividad física habitual en la <a href="<?= e(url('elemento-editar.php?id=' . $el['id'])) ?>">ficha</a> para calcular cuánto comer para mantener y para bajar.</p>
          <?php endif; ?>
          <p class="tenue nota-pequena">Estimación con la fórmula de Mifflin-St Jeor (peso, altura, edad y sexo) por tu actividad; el error real es de ±10 %: si en 3-4 semanas la tendencia no baja, quita 150-200 kcal.
            <?php if ($k['al_minimo']): ?>No conviene bajar de <?= e(numero_es($k['minimo'])) ?> kcal sin control médico, así que el déficit queda más corto.<?php endif; ?>
            Proteína: 1,2-1,6 g por kg de <?= e(numero_es($k['peso_ref'])) ?> kg (tu peso de referencia), repartida en las comidas: sacia y protege el músculo.</p>
        <?php else: ?>
          <?php
            $falta = [];
            if (!$a['altura']) $falta[] = 'la altura';
            if (!$a['sexo']) $falta[] = 'el sexo';
            if ($a['edad'] === null) $falta[] = 'la fecha de nacimiento (en Personas)';
            if ($a['actual'] === null) $falta[] = 'un pesaje';
          ?>
          <p class="vacio-mini">Para estimarlas falta <?= e(implode(', ', $falta)) ?>.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="tarjeta" id="pautas">
      <div class="tarjeta-cabecera"><h2><?= icono('check') ?>Pautas para adelgazar y vivir sano</h2></div>
      <?php
      $pautas = [
          'Cómo pesarte' => [
              'Misma báscula, misma hora: por la mañana, en ayunas, después de ir al baño y con poca ropa.',
              'Una vez por semana basta; a diario también vale si no te agobia. Mira la línea de tendencia, no el número del día: el agua y la sal mueven la báscula 1-2 kg.',
              'Una vez al mes, la cintura: cinta a la altura del ombligo, de pie, al final de una espiración normal.',
          ],
          'Qué comer' => [
              'El plato: la mitad verdura u hortaliza, un cuarto proteína (legumbre, pescado, huevo, carne blanca) y un cuarto hidratos integrales (pan, arroz o pasta integral, patata).',
              'Aceite de oliva virgen extra como grasa, pero medido: una cucharada son 90 kcal.',
              'Fruta entera (2-3 piezas), no en zumo. Legumbres 3-4 veces por semana y pescado otras 3-4.',
              'Proteína en cada comida: es lo que más sacia y lo que evita perder músculo al adelgazar.',
              'Fuera de casa lo menos posible ultraprocesados: bollería, embutidos grasos, precocinados, snacks.',
          ],
          'Qué beber' => [
              'Agua como bebida habitual. Café e infusiones sin azúcar.',
              'Fuera los refrescos y zumos azucarados: son calorías que no quitan el hambre.',
              'Alcohol, cuanto menos mejor: una caña o una copa de vino son ~100-150 kcal y abren el apetito. No hay cantidad «saludable».',
          ],
          'Cómo comer' => [
              'Despacio, sentado y sin pantallas: el cerebro tarda unos 20 minutos en notar que estás lleno.',
              'Platos más pequeños y sin repetir. Sirve en la cocina, no dejes la fuente en la mesa.',
              'Planifica la compra y el menú de la semana; no compres lo que no quieres comer.',
              'El ayuno intermitente funciona si te ayuda a comer menos, no por sí mismo: elige el horario que puedas sostener.',
          ],
          'Moverte' => [
              'Mínimo 150-300 minutos a la semana de actividad moderada (paseo rápido, bici) o 75-150 de intensa (OMS).',
              'Fuerza 2-3 días por semana (pesas, máquinas, ejercicios con el propio peso): al adelgazar, es lo que conserva el músculo y mantiene el gasto.',
              'Muévete durante el día: 7.000-10.000 pasos y levántate cada 30-60 minutos si trabajas sentado.',
          ],
          'Dormir y el estrés' => [
              'Duerme 7-9 horas: dormir poco aumenta el hambre y las ganas de dulce al día siguiente.',
              'El estrés y el aburrimiento empujan a picar: identifica los momentos y ten un plan (paseo, infusión, llamar a alguien).',
          ],
          'Paciencia y mantenimiento' => [
              'Objetivos cortos: el primer 5-10 % del peso es el que más salud da.',
              'Las mesetas de 2-3 semanas son normales. Revisa raciones y picoteo antes de recortar más.',
              'Al llegar al objetivo, sube la comida poco a poco y sigue pesándote cada semana: el primer año es cuando más se recupera.',
          ],
          'Cuándo ir al médico' => [
              'IMC de 30 o más, o de 27 con tensión alta, diabetes, colesterol o apnea del sueño: que el plan lo lleve el médico de cabecera.',
              'Si pierdes peso sin buscarlo (más del 5 % en 6-12 meses).',
              'Antes de empezar ejercicio intenso si tienes problemas de corazón, tensión o articulaciones.',
              'Los fármacos para adelgazar, solo con receta y seguimiento médico.',
              'Si la comida te genera culpa, atracones o compensas con vómitos o ejercicio: pide ayuda profesional.',
          ],
      ];
      ?>
      <?php foreach ($pautas as $tema => $lista): ?>
        <details class="pauta"<?= $tema === 'Qué comer' ? ' open' : '' ?>>
          <summary><?= e($tema) ?></summary>
          <ul><?php foreach ($lista as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
        </details>
      <?php endforeach; ?>
      <p class="tenue nota-pequena">Pautas generales (OMS, Ministerio de Sanidad, sociedades de nutrición y obesidad). No sustituyen al médico ni al dietista-nutricionista, sobre todo si hay alguna enfermedad o medicación.</p>
    </section>
  </div>

  <div class="ficha-columna">

    <?php if (trim((string)($el['datos']['plan'] ?? '')) !== ''): ?>
      <section class="tarjeta" id="plan">
        <div class="tarjeta-cabecera"><h2>Plan y pautas personales</h2><a class="enlace-tenue" href="<?= e(url('elemento-editar.php?id=' . $el['id'])) ?>"><?= icono('editar', 'ico ico-mini') ?>Editar</a></div>
        <p class="notas"><?= nl2br(e($el['datos']['plan'])) ?></p>
      </section>
    <?php endif; ?>

    <section class="tarjeta" id="registro">
      <div class="tarjeta-cabecera"><h2><?= icono('agenda') ?>Registro</h2><span class="tenue"><?= count($a['mediciones']) ?> días</span></div>
      <?php if (!$a['mediciones']): ?><p class="vacio-mini">Sin pesajes todavía.</p><?php else: ?>
        <?php
          $filas = array_reverse($a['mediciones'], true);
          $anterior = [];
          $previo = null;
          foreach ($a['mediciones'] as $f => $m) { if ($m['peso'] !== null) { $anterior[$f] = $previo; $previo = $m['peso']; } }
          if (!$todas) $filas = array_slice($filas, 0, 20, true);
        ?>
        <div class="tabla-scroll">
          <table class="tabla">
            <thead><tr><th>Fecha</th><th class="num">Peso</th><th class="num">Cambio</th><?php if ($a['altura']): ?><th class="num">IMC</th><?php endif; ?><th class="num">Cintura</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($filas as $f => $m): ?>
                <tr>
                  <td class="fecha-celda"><?= e(fecha_es($f)) ?><?php if ($m['notas'] !== ''): ?><br><span class="tenue nota-pequena"><?= e($m['notas']) ?></span><?php endif; ?></td>
                  <td class="num"><?= $m['peso'] !== null ? e(numero_es($m['peso'])) : '<span class="tenue">—</span>' ?></td>
                  <td class="num tenue"><?= $m['peso'] !== null && ($anterior[$f] ?? null) !== null ? e(kg_signo(round($m['peso'] - $anterior[$f], 2))) : '' ?></td>
                  <?php if ($a['altura']): ?><td class="num"><?= $m['peso'] !== null ? e(numero_es(imc($m['peso'], $a['altura']))) : '' ?></td><?php endif; ?>
                  <td class="num"><?= $m['cintura'] !== null ? e(numero_es($m['cintura'])) : '' ?></td>
                  <td class="acciones-fila">
                    <form method="post" data-confirmar="¿Borrar lo apuntado el <?= e(fecha_es($f)) ?>?">
                      <?= csrf_input() ?><input type="hidden" name="accion" value="borrar-medicion"><input type="hidden" name="ids" value="<?= e(implode(',', $m['ids'])) ?>">
                      <button class="btn-icono" title="Borrar"><?= icono('papelera') ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (!$todas && count($a['mediciones']) > 20): ?>
          <p class="pie-seccion"><a class="enlace-tenue" href="<?= e(url('peso.php?id=' . $el['id'] . '&r=' . $rango . '&todas=1#registro')) ?>">Ver los <?= count($a['mediciones']) ?> días</a></p>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php pie();
