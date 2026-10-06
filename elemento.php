<?php
// Ficha de un elemento: sus datos, avisos, historial y archivos.
require_once __DIR__ . '/includes/auth.php';

$id = (int)($_GET['id'] ?? 0);
$el = elemento($pdo, $id);
if (!$el) pagina_error(404, 'No encontrado', 'Ese elemento no existe o se ha borrado.');
$sec = seccion($el['seccion']);
$def = tipo_def($el['seccion'], $el['tipo']);
$uid = (int)$usuario_actual['id'];
$aqui = 'elemento.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    $ancla = '';
    if (!csrf_ok()) {
        flash('error', 'La página llevaba demasiado tiempo abierta. Vuelve a intentarlo.');
    } else {
        try {
            switch ($accion) {
                case 'registro':
                    crear_registro($pdo, ['elemento_id' => $id] + $_POST, $uid);
                    flash('ok', 'Apuntado en el historial.');
                    $ancla = '#historial';
                    break;
                case 'borrar-registro':
                    borrar_registro($pdo, (int)($_POST['registro_id'] ?? 0), $id, $uid);
                    $ancla = '#historial';
                    break;
                case 'plan':
                    guardar_plan($pdo, $id, $_POST, $uid);
                    flash('ok', 'Plan de desarrollo guardado.');
                    $ancla = '#plan';
                    break;
                case 'borrar-plan':
                    borrar_plan($pdo, (int)($_POST['plan_id'] ?? 0), $id, $uid);
                    $ancla = '#plan';
                    break;
                case 'formacion':
                    $n = guardar_formacion_formulario($pdo, $el, $_POST, $uid);
                    flash('ok', $n > 1 ? "Curso apuntado a {$n} personas." : 'Formación guardada.');
                    $ancla = '#formacion';
                    break;
                case 'borrar-formacion':
                    borrar_formacion($pdo, (int)($_POST['formacion_id'] ?? 0), $id, $uid);
                    $ancla = '#formacion';
                    break;
                case 'documento':
                    guardar_documento_subido($pdo, $id, (string)($_POST['titulo'] ?? ''), $_FILES['archivo'] ?? null, $uid);
                    flash('ok', 'Archivo guardado.');
                    $ancla = '#archivos';
                    break;
                case 'borrar-documento':
                    borrar_documento($pdo, (int)($_POST['documento_id'] ?? 0), $id, $uid);
                    $ancla = '#archivos';
                    break;
                case 'archivar':
                    cambiar_activo_elemento($pdo, $id, false, $uid);
                    flash('ok', 'Archivado. Sus avisos dejan de salir; puedes recuperarlo cuando quieras.');
                    break;
                case 'recuperar':
                    cambiar_activo_elemento($pdo, $id, true, $uid);
                    flash('ok', 'Recuperado.');
                    break;
                case 'borrar':
                    if (!es_admin()) throw new RuntimeException('Solo un administrador puede borrar. Puedes archivarlo.');
                    $s = borrar_elemento($pdo, $id, $uid);
                    flash('ok', 'Borrado, con sus avisos, historial y archivos.');
                    redirigir('seccion.php?s=' . $s);
            }
        } catch (ErrorValidacion $ex) {
            flash('error', implode(' ', $ex->errores));
        } catch (RuntimeException $ex) {
            flash('error', $ex->getMessage());
        }
    }
    redirigir($aqui . $ancla);
}

$avisos = agenda($pdo, 36500, null, $id);
$hechos = hechos_recientes($pdo, 10, null, $id);
$conf_reg = $sec['registros'];
$registros = $conf_reg ? registros_de($pdo, $id) : [];
$gasto = $conf_reg ? gasto_ultimo_ano($pdo, $id) : 0.0;
$docs = documentos_de($pdo, $id);
$hijos = elementos_enlazados($pdo, $id);
$tipos_hijos = tipos_que_enlazan($el['seccion'], $el['tipo']);
$coste_hijos = 0.0;
foreach ($hijos as $h) $coste_hijos += coste_mensual($h['datos']);
// Lo enlazado se enseña en una tarjeta por sección: los contratos de la casa
// no se mezclan con su equipamiento.
$contactos_casa = [];
if ($el['seccion'] === 'vivienda' && $el['tipo'] === 'inmueble') {
    $contactos_casa = array_values(array_filter(elementos_de($pdo, 'vivienda'), static fn($x) => $x['tipo'] === 'contacto'));
}
$titulos_hijos = ['contratos' => ['Contratos y seguros', 'contrato'], 'vivienda' => ['Equipamiento y materiales', 'casa'],
                  'trabajo' => ['Convenio y contactos', 'maletin']];
// En una persona del equipo, lo que tiene enlazado son sus equipos (su ordenador…).
if ($el['seccion'] === 'trabajo' && $el['tipo'] === 'miembro') $titulos_hijos['trabajo'] = ['Equipos y material', 'maletin'];
// Las nóminas del empleo se leen de finanzas (no se copian aquí): ver includes/finanzas.php.
$nominas = null;
if ($el['seccion'] === 'trabajo' && $el['tipo'] === 'empleo' && ($el['datos']['nominas_finanzas'] ?? '') === 'Sí') {
    $nominas = finanzas_nominas();
    $nominas['resumen'] = resumen_nominas($nominas['anios'], hoy());
}
$grupos_hijos = [];
foreach ($titulos_hijos as $gs => [$titulo, $icono_g]) {
    $gh = array_values(array_filter($hijos, static fn($h) => $h['seccion'] === $gs));
    $gt = array_values(array_filter($tipos_hijos, static fn($t) => $t[0] === $gs));
    if ($gh || $gt) $grupos_hijos[$gs] = ['titulo' => $titulo, 'icono' => $icono_g, 'hijos' => $gh, 'tipos' => $gt];
}

$acciones = '<a class="btn btn-sutil" href="' . e(url('elemento-editar.php?id=' . $id)) . '">' . icono('editar') . 'Editar</a>';
if ($el['seccion'] === 'salud' && $el['tipo'] === 'peso') {
    $acciones = '<a class="btn btn-primario" href="' . e(url('peso.php?id=' . $id)) . '">' . icono('bascula') . 'Evolución y pautas</a>' . $acciones;
}
if ($el['seccion'] === 'contratos' && $el['tipo'] === 'comunidad') {
    $acciones = '<a class="btn btn-sutil" href="' . e(url('gasto-comunidad.php?id=' . $id)) . '">' . icono('historial') . 'Gasto por partidas</a>' . $acciones;
}
// Trabajo vive en trabajo.php: las migas y las pestañas llevan allí (la persona del equipo, al Equipo).
$migas = '<a href="' . e(url('seccion.php?s=' . $el['seccion'] . '&lista=1')) . '">' . e($sec['nombre']) . '</a> · ' . e($def['nombre']);
if ($el['seccion'] === 'trabajo') {
    $migas = '<a href="' . e(url('trabajo.php')) . '">' . e($sec['nombre']) . '</a> · '
           . ($el['tipo'] === 'miembro' ? '<a href="' . e(url('trabajo.php?p=equipo')) . '">Equipo</a>'
              : ($el['tipo'] === 'compra' ? '<a href="' . e(url('trabajo.php?p=compras')) . '">Compras</a>'
              : ($el['tipo'] === 'curso' ? '<a href="' . e(url('trabajo.php?p=formacion')) . '">Formación</a>' : e($def['nombre']))));
}
cabecera($el['nombre'], 'seccion:' . $el['seccion']);
cabecera_pagina($el['nombre'], $migas, $acciones, $sec['icono'], $sec['color']);
if ($el['seccion'] === 'trabajo') {
    $mi = mi_empleo($pdo, (int)($usuario_actual['persona_id'] ?? 0) ?: null);
    $activa = ['miembro' => 'equipo', 'compra' => 'compras', 'curso' => 'formacion'][$el['tipo']] ?? ($mi && $mi['id'] === $id ? 'puesto' : '');
    pestanas_trabajo($activa, $mi, count(equipo_trabajo($pdo)), count(compras_trabajo($pdo)), count(cursos_trabajo($pdo)));
}
?>
<?php if (!$el['activo']): ?>
  <div class="flash flash-aviso">Este elemento está archivado: sus avisos no salen en la agenda.</div>
<?php endif; ?>

<div class="ficha-rejilla">
  <div class="ficha-columna">
    <section class="tarjeta">
      <div class="tarjeta-cabecera"><h2>Datos</h2><?= chip_persona($el['persona_nombre'], $el['persona_color']) ?></div>
      <dl class="datos">
        <?php $alguno = false; ?>
        <?php foreach ($def['campos'] as $clave => $c): ?>
          <?php if (!isset($el['datos'][$clave]) || $el['datos'][$clave] === '' || !empty($c['aparte']) || !empty($c['oculto'])) continue; $alguno = true; ?>
          <div class="<?= $c['tipo'] === 'area' ? 'dato-ancho' : '' ?>">
            <dt><?= e($c['etiqueta']) ?></dt>
            <dd><?php
              $val = valor_campo($c, $el['datos'][$clave]);
              if ($c['tipo'] === 'tel') echo enlaces_tel($val);
              elseif ($c['tipo'] === 'email') echo '<a href="mailto:' . e($val) . '">' . e($val) . '</a>';
              else echo nl2br(e($val));
            ?></dd>
          </div>
        <?php endforeach; ?>
        <?php if (!empty($def['enlace']) && $el['enlace_id']): $alguno = true; ?>
          <div>
            <dt><?= e($def['enlace']['etiqueta']) ?></dt>
            <dd><a href="<?= e(url('elemento.php?id=' . $el['enlace_id'])) ?>"><?= e($el['enlace_nombre']) ?></a></dd>
          </div>
        <?php endif; ?>
        <?php if (($cm = coste_mensual($el['datos'])) > 0 && ($el['datos']['periodicidad'] ?? '') !== 'Mensual'): ?>
          <div><dt>Equivale a</dt><dd><?= e(eur($cm)) ?> al mes</dd></div>
        <?php endif; ?>
      </dl>
      <?php if (!$alguno): ?><p class="vacio-mini">Sin datos todavía. <a href="<?= e(url('elemento-editar.php?id=' . $id)) ?>">Complétalos</a>.</p><?php endif; ?>
      <?php $notas_largas = longitud(trim((string)$el['notas'])) > 500; ?>
      <?php if (trim((string)$el['notas']) !== '' && !$notas_largas): ?>
        <h3 class="subtitulo">Notas</h3>
        <p class="notas"><?= nl2br(e($el['notas'])) ?></p>
      <?php endif; ?>
    </section>

    <?php if (es_curso($el)) pintar_formacion($pdo, $el); ?>
    <?php if (lleva_plan($el)) pintar_plan($el, plan_de($pdo, $id), hoy()); ?>
    <?php if (hace_formacion($el)) pintar_formacion($pdo, $el); ?>

    <?php // Notas largas: plegadas, para que no tapen los datos (3/10/2026: la ficha del Mini ocupaba dos pantallas de texto). ?>
    <?php if ($notas_largas): ?>
      <details class="tarjeta tarjeta-plegable">
        <summary><h2>Notas</h2></summary>
        <p class="notas"><?= nl2br(e($el['notas'])) ?></p>
      </details>
    <?php endif; ?>

    <?php foreach ($def['campos'] as $clave => $c): ?>
      <?php if (empty($c['aparte']) || !empty($c['destacado']) || trim((string)($el['datos'][$clave] ?? '')) === '') continue; ?>
      <details class="tarjeta tarjeta-plegable">
        <summary><h2><?= e($c['etiqueta']) ?></h2></summary>
        <?php if (!empty($c['lista'])): ?>
          <?= lista_campo((string)$el['datos'][$clave]) ?>
        <?php else: ?>
          <p class="notas"><?= nl2br(e($el['datos'][$clave])) ?></p>
        <?php endif; ?>
      </details>
    <?php endforeach; ?>

    <?php if ($nominas !== null): $rn = $nominas['resumen']; ?>
      <section class="tarjeta" id="nominas">
        <div class="tarjeta-cabecera">
          <h2><?= icono('cartera') ?>Nóminas<?= $rn ? ' ' . (int)$rn['anio'] : '' ?></h2>
          <span class="tenue">De la app de finanzas<?= $nominas['leido_en'] ? ' · ' . e(fecha_corta(substr($nominas['leido_en'], 0, 10)) . ' ' . substr($nominas['leido_en'], 11, 5)) : '' ?></span>
        </div>
        <?php if ($nominas['error']): ?><div class="flash flash-aviso"><?= e($nominas['error']) ?></div><?php endif; ?>
        <?php if ($rn): ?>
          <p>Líquido cobrado: <strong><?= e(eur($rn['liquido'])) ?></strong> en <?= (int)$rn['n'] ?> recibo<?= $rn['n'] === 1 ? '' : 's' ?><?= $rn['pagas'] ? ' de ' . (int)$rn['pagas'] . ' pagas' : '' ?><?php if ($rn['salario_base']): ?> · salario base <?= e(eur($rn['salario_base'])) ?><?php endif; ?>.</p>
          <?php if ($rn['falta']): ?><div class="flash flash-aviso">Falta grabar la nómina de <?= e(mes_es($rn['falta'])) ?>.</div><?php endif; ?>
          <?php if ($rn['cambia']): ?><div class="flash flash-aviso">La diferencia con el banco cambia en <?= e(implode(', ', array_map('mes_es', $rn['cambia']))) ?>: mira ese recibo en finanzas.</div><?php endif; ?>
          <div class="tabla-scroll"><table class="tabla">
            <thead><tr><th>Mes</th><th class="num">Líquido</th><th class="num">Banco</th><th class="num">Diferencia</th></tr></thead>
            <tbody>
              <?php foreach (array_reverse($rn['filas']) as $f): ?>
                <tr>
                  <td><?= e(ucfirst(mes_es($f['mes']))) ?><?= ($f['tipo'] ?? '') === 'extra' ? ' <span class="chip">extra</span>' : '' ?></td>
                  <td class="num"><?= e(eur($f['liquido'])) ?></td>
                  <td class="num"><?= $f['banco'] !== null ? e(eur($f['banco'])) : '' ?></td>
                  <td class="num"><?= $f['dif'] !== null ? ($f['cambia'] ? '<strong>' . e(eur($f['dif'])) . '</strong>' : e(eur($f['dif']))) : '' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php elseif (!$nominas['error']): ?>
          <p class="vacio-mini">Finanzas aún no tiene ninguna nómina grabada.</p>
        <?php endif; ?>
        <?php if (es_admin() && FINANZAS_URL !== ''): ?>
          <a class="btn btn-sutil" href="<?= e(PASE_CLAVE !== '' ? url('finanzas-pantalla.php?p=nomina') : rtrim(FINANZAS_URL, '/') . '/nomina.php') ?>"><?= icono('externo') ?>Abrir en finanzas</a>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php foreach ($grupos_hijos as $gs => $g): ?>
      <section class="tarjeta" id="enlazados-<?= e($gs) ?>">
        <div class="tarjeta-cabecera">
          <h2><?= icono($g['icono']) ?><?= e($g['titulo']) ?></h2>
          <?php if ($gs === 'contratos' && $coste_hijos > 0): ?><span class="tenue">Suman <strong><?= e(eur($coste_hijos)) ?></strong> al mes</span><?php endif; ?>
        </div>
        <?php if (!$g['hijos']): ?><p class="vacio-mini">Aún no hay nada enlazado a esta ficha.</p><?php endif; ?>
        <ul class="lista-docs">
          <?php foreach ($g['hijos'] as $h): ?>
            <?php
              $hd = tipo_def($h['seccion'], $h['tipo']);
              $linea = [];
              if (!empty($h['datos']['compania'])) $linea[] = $h['datos']['compania'];
              if (!empty($h['datos']['marca'])) $linea[] = $h['datos']['marca'];
              if (!empty($h['datos']['modelo'])) $linea[] = $h['datos']['modelo'];
              if (is_numeric($h['datos']['importe'] ?? null) && $h['tipo'] === 'compra') $linea[] = eur($h['datos']['importe']);
              if (!empty($h['datos']['primera_compra']) && $h['tipo'] === 'compra') $linea[] = 'comprado el ' . fecha_es($h['datos']['primera_compra']);
              if (isset($h['datos']['coste'])) $linea[] = eur($h['datos']['coste']) . (!empty($h['datos']['periodicidad']) ? ' (' . $h['datos']['periodicidad'] . ')' : '');
              $prox = agenda($pdo, 36500, null, $h['id'])[0] ?? null;
              if ($prox) $linea[] = fecha_corta($prox['fecha']) . ' (' . relativo($prox['dias']) . ')';
            ?>
            <li>
              <a href="<?= e(url('elemento.php?id=' . $h['id'])) ?>"><?= e($h['nombre']) ?></a>
              <span class="tenue"><?= e($hd['nombre'] . ($linea ? ' · ' . implode(' · ', $linea) : '')) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="botones-tarjeta">
          <?php foreach ($g['tipos'] as [$ts, $tt, $tn]): ?>
            <a class="btn btn-sutil" href="<?= e(url('elemento-editar.php?s=' . $ts . '&t=' . $tt . '&enlace=' . $id)) ?>"><?= icono('mas') ?><?= e($tn) ?></a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <?php if ($el['seccion'] === 'vivienda' && $el['tipo'] === 'inmueble'): ?>
      <section class="tarjeta" id="contactos">
        <div class="tarjeta-cabecera"><h2><?= icono('persona') ?>Contactos de confianza</h2></div>
        <?php if (!$contactos_casa): ?><p class="vacio-mini">Fontanero, electricista, administrador de fincas…</p><?php endif; ?>
        <ul class="lista-docs">
          <?php foreach ($contactos_casa as $c): ?>
            <li>
              <a href="<?= e(url('elemento.php?id=' . $c['id'])) ?>"><?= e($c['nombre']) ?></a>
              <span class="tenue"><?= e(implode(' · ', array_filter([$c['datos']['oficio'] ?? '', $c['datos']['telefono'] ?? '']))) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="botones-tarjeta">
          <a class="btn btn-sutil" href="<?= e(url('elemento-editar.php?s=vivienda&t=contacto')) ?>"><?= icono('mas') ?>Contacto de confianza</a>
          <a class="btn btn-sutil" href="<?= e(url('elemento-editar.php?s=vivienda&t=inmueble')) ?>"><?= icono('mas') ?>Otra vivienda</a>
        </div>
      </section>
    <?php endif; ?>

    <section class="tarjeta" id="archivos">
      <div class="tarjeta-cabecera"><h2><?= icono('clip') ?>Archivos</h2></div>
      <?php if (!$docs): ?><p class="vacio-mini">Sube aquí la copia escaneada, la póliza, la factura…</p><?php endif; ?>
      <ul class="lista-docs">
        <?php foreach ($docs as $d): ?>
          <li>
            <a href="<?= e(url('archivo.php?id=' . $d['id'])) ?>" target="_blank" rel="noopener"><?= icono('clip', 'ico ico-mini') ?><?= e($d['titulo']) ?></a>
            <span class="tenue"><?= e(strtoupper(pathinfo($d['archivo'], PATHINFO_EXTENSION))) ?> · <?= e(tamano_legible((int)$d['bytes'])) ?> · <?= e(fecha_es(substr($d['creado_en'], 0, 10))) ?></span>
            <a class="btn-icono" href="<?= e(url('archivo.php?id=' . $d['id'] . '&descargar=1')) ?>" title="Descargar"><?= icono('descarga') ?></a>
            <form method="post" data-confirmar="¿Borrar «<?= e($d['titulo']) ?>»? No se puede deshacer.">
              <?= csrf_input() ?><input type="hidden" name="accion" value="borrar-documento"><input type="hidden" name="documento_id" value="<?= (int)$d['id'] ?>">
              <button class="btn-icono" title="Borrar"><?= icono('papelera') ?></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
      <details class="desplegable">
        <summary class="btn btn-sutil"><?= icono('mas') ?>Subir archivo</summary>
        <form method="post" enctype="multipart/form-data" class="form-rejilla">
          <?= csrf_input() ?><input type="hidden" name="accion" value="documento">
          <div class="campo"><label>Título <span class="tenue">(opcional)</span></label><input type="text" name="titulo" maxlength="150" placeholder="Ej.: Póliza 2026"></div>
          <div class="campo"><label>Archivo (PDF o foto, hasta 15 MB)</label><input type="file" name="archivo" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,application/pdf,image/*"></div>
          <div class="campo-ancho"><button class="btn btn-primario"><?= icono('check') ?>Subir</button></div>
        </form>
      </details>
    </section>
  </div>

  <div class="ficha-columna">
    <?php // Los campos «destacado» (el horario) van desplegados, lo primero de esta columna. ?>
    <?php foreach ($def['campos'] as $clave => $c): ?>
      <?php if (empty($c['destacado']) || trim((string)($el['datos'][$clave] ?? '')) === '') continue; ?>
      <section class="tarjeta" id="<?= e($clave) ?>">
        <div class="tarjeta-cabecera"><h2><?= icono('reloj') ?><?= e($c['etiqueta']) ?></h2></div>
        <?php if (!empty($c['lista'])): ?>
          <?= lista_campo((string)$el['datos'][$clave]) ?>
        <?php else: ?>
          <p class="notas"><?= nl2br(e($el['datos'][$clave])) ?></p>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
    <section class="tarjeta" id="avisos">
      <div class="tarjeta-cabecera"><h2><?= icono('agenda') ?>Avisos</h2></div>
      <?php if (!$avisos): ?><p class="vacio-mini">Nada pendiente.</p><?php endif; ?>
      <?php foreach ($avisos as $v) fila_vencimiento($v, $aqui, false, false); ?>
      <?php formulario_vencimiento($el['seccion'], $id, $aqui); ?>
      <?php if ($hechos): ?>
        <h3 class="subtitulo">Hechos</h3>
        <ul class="lista-hechos">
          <?php foreach ($hechos as $h): ?>
            <li><?= icono('check', 'ico ico-mini') ?><?= e($h['titulo']) ?> <span class="tenue">· <?= e(fecha_es($h['hecho_en'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if ($conf_reg): ?>
      <section class="tarjeta" id="historial">
        <div class="tarjeta-cabecera">
          <h2><?= icono('historial') ?>Historial</h2>
          <?php if ($gasto > 0): ?><span class="tenue">Gastado en 12 meses: <strong><?= e(eur($gasto)) ?></strong></span><?php endif; ?>
        </div>
        <details class="desplegable">
          <summary class="btn btn-sutil"><?= icono('mas') ?>Apuntar</summary>
          <form method="post" class="form-rejilla">
            <?= csrf_input() ?><input type="hidden" name="accion" value="registro">
            <div class="campo"><label>Tipo</label><select name="tipo"><?= opciones_html(array_combine($conf_reg['tipos'], $conf_reg['tipos']), '') ?></select></div>
            <div class="campo"><label>Fecha</label><input type="date" name="fecha" value="<?= e(hoy()) ?>" required></div>
            <div class="campo campo-ancho"><label>Qué se hizo</label><input type="text" name="titulo" maxlength="150" placeholder="Ej.: Cambio de aceite y filtros"></div>
            <?php if ($conf_reg['valor']): ?>
              <div class="campo"><label><?= e($conf_reg['valor']) ?> <span class="tenue">(opcional)</span></label>
                <div class="con-sufijo"><input type="text" inputmode="decimal" name="valor"><?php if ($conf_reg['unidad'] !== ''): ?><span><?= e($conf_reg['unidad']) ?></span><?php else: ?><input type="text" name="unidad" placeholder="unidad" class="input-unidad"><?php endif; ?></div>
              </div>
            <?php endif; ?>
            <div class="campo"><label>Coste <span class="tenue">(opcional)</span></label><div class="con-sufijo"><input type="text" inputmode="decimal" name="coste" placeholder="0,00"><span>€</span></div></div>
            <div class="campo campo-ancho"><label>Notas <span class="tenue">(opcional)</span></label><textarea name="notas" rows="2"></textarea></div>
            <div class="campo-ancho"><button class="btn btn-primario"><?= icono('check') ?>Apuntar</button></div>
          </form>
        </details>
        <?php if (!$registros): ?><p class="vacio-mini">Sin apuntes todavía.</p><?php endif; ?>
        <ul class="historial">
          <?php foreach ($registros as $r): ?>
            <li>
              <div class="h-fecha"><?= e(fecha_es($r['fecha'])) ?></div>
              <div class="h-cuerpo">
                <strong><?= e($r['titulo']) ?></strong>
                <?php if ($r['tipo'] !== '' && $r['tipo'] !== $r['titulo']): ?><span class="chip"><?= e($r['tipo']) ?></span><?php endif; ?>
                <?php // Sin autor = lo grabó Claude por la API: no se rotula (3/10/2026, sobraba en cada apunte). ?>
                <?php $meta = array_filter([
                    $r['valor'] !== null ? numero_es($r['valor']) . ($r['unidad'] !== '' ? ' ' . $r['unidad'] : '') : null,
                    $r['coste'] !== null ? eur($r['coste']) : null,
                    $r['autor'] ? nombre_corto($r['autor']) : null,
                ], fn($x) => $x !== null); ?>
                <?php if ($meta): ?><div class="tenue"><?= e(implode(' · ', $meta)) ?></div><?php endif; ?>
                <?php if (trim((string)$r['notas']) !== ''): ?><p class="notas"><?= nl2br(e($r['notas'])) ?></p><?php endif; ?>
              </div>
              <form method="post" data-confirmar="¿Borrar este apunte?">
                <?= csrf_input() ?><input type="hidden" name="accion" value="borrar-registro"><input type="hidden" name="registro_id" value="<?= (int)$r['id'] ?>">
                <button class="btn-icono" title="Borrar"><?= icono('papelera') ?></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
  </div>
</div>

<section class="tarjeta zona-peligro">
  <?php if ($el['activo']): ?>
    <form method="post" data-confirmar="¿Archivar «<?= e($el['nombre']) ?>»? Dejará de salir, con sus avisos. Se puede recuperar.">
      <?= csrf_input() ?><input type="hidden" name="accion" value="archivar">
      <button class="btn btn-sutil"><?= icono('archivar') ?>Archivar</button>
    </form>
  <?php else: ?>
    <form method="post"><?= csrf_input() ?><input type="hidden" name="accion" value="recuperar">
      <button class="btn btn-sutil"><?= icono('archivar') ?>Recuperar</button></form>
  <?php endif; ?>
  <?php if (es_admin()): ?>
    <form method="post" data-confirmar="¿Borrar «<?= e($el['nombre']) ?>» con TODOS sus avisos, historial y archivos? No se puede deshacer.">
      <?= csrf_input() ?><input type="hidden" name="accion" value="borrar">
      <button class="btn btn-peligro"><?= icono('papelera') ?>Borrar del todo</button>
    </form>
  <?php endif; ?>
</section>
<?php pie();
