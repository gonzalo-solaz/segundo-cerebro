<?php
// Alta y edición de un elemento. El formulario sale entero de
// includes/secciones.php: aquí no hay ningún campo escrito a mano.
require_once __DIR__ . '/includes/auth.php';

$id = (int)($_GET['id'] ?? 0);
$el = null;
if ($id) {
    $el = elemento($pdo, $id);
    if (!$el) pagina_error(404, 'No encontrado', 'Ese elemento no existe o se ha borrado.');
    $s = $el['seccion'];
    $t = $el['tipo'];
} else {
    $s = (string)($_GET['s'] ?? '');
    $t = (string)($_GET['t'] ?? '');
}
$sec = seccion($s);
$def = tipo_def($s, $t);
if (!$sec || !$def) pagina_error(404, 'No encontrado', 'Ese tipo de elemento no existe.');

$valores = $el
    ? ['nombre' => $el['nombre'], 'persona_id' => $el['persona_id'], 'enlace_id' => $el['enlace_id'], 'notas' => $el['notas'], 'datos' => $el['datos']]
    : ['nombre' => '', 'persona_id' => (int)($_GET['persona'] ?? 0) ?: null, 'enlace_id' => (int)($_GET['enlace'] ?? 0) ?: null, 'notas' => '', 'datos' => []];
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entrada = [
        'nombre'     => (string)($_POST['nombre'] ?? ''),
        'persona_id' => $_POST['persona_id'] ?? null,
        'enlace_id'  => $_POST['enlace_id'] ?? null,
        'notas'      => (string)($_POST['notas'] ?? ''),
        'datos'      => is_array($_POST['datos'] ?? null) ? $_POST['datos'] : [],
    ];
    if (!csrf_ok()) {
        $errores[] = 'La página llevaba demasiado tiempo abierta. Vuelve a guardar.';
    } else {
        try {
            $nuevo = guardar_elemento($pdo, $s, $t, $entrada, $id ?: null, (int)$usuario_actual['id']);
            flash('ok', $id ? 'Cambios guardados.' : 'Añadido a ' . $sec['nombre'] . '.');
            redirigir('elemento.php?id=' . $nuevo);
        } catch (ErrorValidacion $ex) {
            $errores = $ex->errores;
        }
    }
    $valores = $entrada;
}

$gente = $def['persona'] ? personas($pdo) : [];
$destinos = !empty($def['enlace']) ? candidatos_enlace($pdo, $def) : [];
$titulo = $el ? 'Editar «' . $el['nombre'] . '»' : 'Nuevo: ' . mb_minusculas_inicial($def['nombre']);
$volver_a = $el ? 'elemento.php?id=' . $el['id'] : 'seccion.php?s=' . $s;

cabecera($titulo, 'seccion:' . $s);
cabecera_pagina($titulo, '<a href="' . e(url('seccion.php?s=' . $s)) . '">' . e($sec['nombre']) . '</a>', '', $sec['icono'], $sec['color']);
?>
<?php if ($errores): ?>
  <div class="flash flash-error" role="alert">
    <strong>Revisa esto:</strong>
    <ul><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" class="tarjeta form-rejilla form-elemento">
  <?= csrf_input() ?>
  <div class="campo<?= $def['persona'] ? '' : ' campo-ancho' ?>">
    <label for="nombre">Nombre<?= !empty($def['nombre_auto']) ? ' <span class="tenue">(si lo dejas vacío: «' . e(str_replace('{persona}', '…', $def['nombre_auto'])) . '»)</span>' : '' ?></label>
    <input type="text" name="nombre" id="nombre" value="<?= e($valores['nombre']) ?>" maxlength="150"
           placeholder="<?= e('Ej.: ' . $def['ejemplo']) ?>"<?= empty($def['nombre_auto']) ? ' required' : '' ?> autofocus>
  </div>
  <?php if ($def['persona']): ?>
    <div class="campo">
      <label for="persona_id"><?= e($def['persona_etiqueta'] ?? 'Persona') ?><?= $def['persona'] === 'opcional' ? ' <span class="tenue">(opcional)</span>' : '' ?></label>
      <select name="persona_id" id="persona_id"<?= $def['persona'] === 'obligatoria' ? ' required' : '' ?>>
        <?= opciones_html(array_column($gente, 'nombre', 'id'), $valores['persona_id'], true, $def['persona'] === 'obligatoria' ? 'Elige…' : '—') ?>
      </select>
      <?php if (!$gente): ?><small class="ayuda">Aún no hay personas: <a href="<?= e(url('personas.php')) ?>">añádelas primero</a>.</small><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($def['enlace'])): ?>
    <div class="campo">
      <label for="enlace_id"><?= e($def['enlace']['etiqueta']) ?> <span class="tenue">(opcional)</span></label>
      <select name="enlace_id" id="enlace_id">
        <?= opciones_html($destinos, $valores['enlace_id'] ?? null, true, '—') ?>
      </select>
      <?php if (!$destinos): ?><small class="ayuda">Aún no hay ninguno: añádelo primero en su sección.</small><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php foreach ($def['campos'] as $clave => $c): ?>
    <?= campo_formulario($clave, $c, $valores['datos'][$clave] ?? null) ?>
  <?php endforeach; ?>

  <div class="campo campo-ancho">
    <label for="notas">Notas <span class="tenue">(opcional)</span></label>
    <textarea name="notas" id="notas" rows="3"><?= e($valores['notas']) ?></textarea>
  </div>
  <div class="campo-ancho acciones-form">
    <button class="btn btn-primario"><?= icono('check') ?>Guardar</button>
    <a class="btn btn-sutil" href="<?= e(url($volver_a)) ?>">Cancelar</a>
  </div>
</form>
<?php pie();
