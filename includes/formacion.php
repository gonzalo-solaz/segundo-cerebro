<?php
// =====================================================================
//  Formación del equipo (tabla formacion, migración 008).
//
//  Cada curso es una ficha de Trabajo de tipo «curso» (nombre, quién lo
//  imparte, horas…, con sus archivos: diplomas, certificados). La tabla dice
//  QUIÉN lo ha hecho: una fila por curso y persona (la ficha del empleo o la de
//  una persona del equipo), con la inscripción, la finalización, el estado y el
//  resultado, como en el «Historial de aprendizaje» de Workday. Gonzalo,
//  6/10/2026: «hay cursos que los han hecho varios miembros del equipo».
//  Los nombres se leen con elementos_de() (la única vía de lectura de fichas).
// =====================================================================

function es_curso(array $el): bool {
    return $el['seccion'] === 'trabajo' && $el['tipo'] === 'curso';
}

// Quién puede hacer un curso: los mismos que llevan plan de desarrollo (tú y tu equipo).
function hace_formacion(array $el): bool {
    return lleva_plan($el);
}

function estados_formacion(): array {
    return ['Inscrito', 'En curso', 'Finalizado', 'No asistió', 'Cancelado'];
}

// Todas las fichas de Trabajo (también las archivadas: alguien que se fue sigue en sus cursos), por id.
function fichas_trabajo(PDO $pdo): array {
    $out = [];
    foreach ([true, false] as $activos) foreach (elementos_de($pdo, 'trabajo', $activos) as $e) $out[$e['id']] = $e;
    return $out;
}

// Quién puede apuntarse: tú (el empleo) y las personas del equipo en activo.
function personas_formacion(PDO $pdo): array {
    return array_values(array_filter(elementos_de($pdo, 'trabajo'), 'hace_formacion'));
}

function decodificar_formacion(array $f, array $fichas): array {
    foreach (['id', 'curso_id', 'elemento_id'] as $k) $f[$k] = (int)$f[$k];
    foreach (['inscripcion', 'finalizacion'] as $k) $f[$k] = $f[$k] ? substr((string)$f[$k], 0, 10) : null;
    $f['notas'] = (string)($f['notas'] ?? '');
    $f['curso_nombre'] = $fichas[$f['curso_id']]['nombre'] ?? '';
    $f['persona_nombre'] = $fichas[$f['elemento_id']]['nombre'] ?? '';
    $f['persona_tipo'] = $fichas[$f['elemento_id']]['tipo'] ?? '';
    return $f;
}

// La fecha que manda para ordenar: la de finalización o, si no hay, la de inscripción.
function fecha_formacion(array $f): string {
    return (string)($f['finalizacion'] ?? $f['inscripcion'] ?? '');
}

function ordenar_formacion(array $filas): array {
    usort($filas, static fn($a, $b) => [fecha_formacion($b), $a['persona_nombre'], $a['curso_nombre']]
                                   <=> [fecha_formacion($a), $b['persona_nombre'], $b['curso_nombre']]);
    return $filas;
}

// Las filas de un curso (quién lo ha hecho) o de una persona (qué cursos ha hecho); lo más reciente, primero.
function formacion_de(PDO $pdo, int $elemento_id, ?array $fichas = null): array {
    $fichas ??= fichas_trabajo($pdo);
    $el = $fichas[$elemento_id] ?? null;
    if (!$el) return [];
    $col = es_curso($el) ? 'curso_id' : 'elemento_id';
    $st = $pdo->prepare("SELECT * FROM formacion WHERE {$col} = ?");
    $st->execute([$elemento_id]);
    return ordenar_formacion(array_map(static fn($f) => decodificar_formacion($f, $fichas), $st->fetchAll()));
}

function formacion_toda(PDO $pdo, ?array $fichas = null): array {
    $fichas ??= fichas_trabajo($pdo);
    return ordenar_formacion(array_map(static fn($f) => decodificar_formacion($f, $fichas), $pdo->query('SELECT * FROM formacion')->fetchAll()));
}

/**
 * Apunta a una persona en un curso, o pone al día su fila si ya estaba. Lo que no
 * viene en $e se queda como estaba (la API cambia solo lo que dice); en blanco, se
 * borra. Sin estado y con fecha de finalización, queda «Finalizado». Devuelve el id.
 */
function guardar_formacion(PDO $pdo, int $curso_id, int $elemento_id, array $e, ?int $usuario_id = null): int {
    $curso = elemento($pdo, $curso_id);
    $persona = elemento($pdo, $elemento_id);
    if (!$curso || !es_curso($curso)) throw new ErrorValidacion(['Ese curso no existe: créalo antes en Trabajo → Formación.']);
    if (!$persona || !hace_formacion($persona)) throw new ErrorValidacion(['La formación es tuya (tu empleo) o de una persona del equipo.']);

    $st = $pdo->prepare('SELECT * FROM formacion WHERE curso_id = ? AND elemento_id = ?');
    $st->execute([$curso_id, $elemento_id]);
    $antes = $st->fetch() ?: null;
    $dato = static fn(string $k) => array_key_exists($k, $e) ? $e[$k] : ($antes[$k] ?? null);

    $errores = [];
    $fechas = [];
    foreach (['inscripcion' => 'La fecha de inscripción', 'finalizacion' => 'La fecha de finalización'] as $k => $etq) {
        $v = trim((string)$dato($k));
        $fechas[$k] = $v === '' ? null : leer_fecha(substr($v, 0, 10));
        if ($v !== '' && $fechas[$k] === null) $errores[] = "{$etq} no es una fecha válida.";
    }
    if ($fechas['inscripcion'] && $fechas['finalizacion'] && $fechas['finalizacion'] < $fechas['inscripcion']) {
        $errores[] = 'La finalización no puede ser anterior a la inscripción.';
    }
    $estado = trim((string)$dato('estado'));
    if ($estado === '' && $fechas['finalizacion']) $estado = 'Finalizado';
    if ($estado !== '' && !in_array($estado, estados_formacion(), true)) {
        $errores[] = 'El estado tiene que ser uno de estos: ' . implode(', ', estados_formacion()) . '.';
    }
    $resultado = trim((string)$dato('resultado'));
    if (longitud($resultado) > 200) $errores[] = 'El resultado es demasiado largo (200 caracteres como mucho): lo largo va en las notas.';
    $notas = trim((string)$dato('notas'));
    if ($errores) throw new ErrorValidacion($errores);

    if ($antes) {
        $pdo->prepare('UPDATE formacion SET inscripcion = ?, finalizacion = ?, estado = ?, resultado = ?, notas = ?, actualizado_en = ? WHERE id = ?')
            ->execute([$fechas['inscripcion'], $fechas['finalizacion'], $estado, $resultado, $notas, ahora(), $antes['id']]);
        $id = (int)$antes['id'];
    } else {
        $pdo->prepare('INSERT INTO formacion (curso_id, elemento_id, inscripcion, finalizacion, estado, resultado, notas, actualizado_en)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$curso_id, $elemento_id, $fechas['inscripcion'], $fechas['finalizacion'], $estado, $resultado, $notas, ahora()]);
        $id = (int)$pdo->lastInsertId();
    }
    anotar($pdo, $usuario_id, "apuntó el curso «{$curso['nombre']}» a «{$persona['nombre']}»");
    return $id;
}

// Quita a una persona de un curso. $desde es la ficha desde la que se borra (el curso o la persona): no se borra lo de otra.
function borrar_formacion(PDO $pdo, int $id, int $desde, ?int $usuario_id = null): void {
    $st = $pdo->prepare('SELECT curso_id, elemento_id FROM formacion WHERE id = ? AND (curso_id = ? OR elemento_id = ?)');
    $st->execute([$id, $desde, $desde]);
    $f = $st->fetch();
    if (!$f) return;
    $pdo->prepare('DELETE FROM formacion WHERE id = ?')->execute([$id]);
    $c = elemento($pdo, (int)$f['curso_id']);
    $p = elemento($pdo, (int)$f['elemento_id']);
    anotar($pdo, $usuario_id, 'quitó el curso «' . ($c['nombre'] ?? '') . '» a «' . ($p['nombre'] ?? '') . '»');
}

// Horas de un curso (campo «horas» de su ficha) o null.
function horas_curso(array $curso): ?float {
    $h = $curso['datos']['horas'] ?? null;
    return is_numeric($h) ? (float)$h : null;
}

// ---------------------------------------------------------------------
//  Los bloques de la ficha (elemento.php)
// ---------------------------------------------------------------------
function texto_fechas_formacion(array $f): string {
    if ($f['finalizacion']) return 'terminó el ' . fecha_es($f['finalizacion']);
    if ($f['inscripcion']) return 'inscrito el ' . fecha_es($f['inscripcion']);
    return '';
}

/**
 * El formulario para apuntar o editar. $quien: en la ficha de un curso, la lista de
 * personas para elegir (casillas: varias a la vez); en la de una persona, la de cursos
 * (un desplegable). Al editar ($f con id), ni lo uno ni lo otro.
 */
function formulario_formacion(array $f, string $modo, array $opciones = []): void {
    ?>
    <form method="post" class="form-rejilla">
      <?= csrf_input() ?><input type="hidden" name="accion" value="formacion">
      <?php if (!empty($f['id'])): ?>
        <input type="hidden" name="curso_id" value="<?= (int)$f['curso_id'] ?>"><input type="hidden" name="persona_id" value="<?= (int)$f['elemento_id'] ?>">
      <?php elseif ($modo === 'curso'): ?>
        <fieldset class="campo campo-ancho casillas"><legend>Quién lo ha hecho</legend>
          <?php foreach ($opciones as $o): ?>
            <label class="casilla"><input type="checkbox" name="personas[]" value="<?= (int)$o['id'] ?>"> <?= e($o['tipo'] === 'empleo' ? 'Yo' : $o['nombre']) ?></label>
          <?php endforeach; ?>
        </fieldset>
      <?php else: ?>
        <div class="campo campo-ancho"><label>Curso</label>
          <select name="curso_id" required><option value="">— Elige el curso —</option>
            <?php foreach ($opciones as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['nombre']) ?></option><?php endforeach; ?>
          </select></div>
      <?php endif; ?>
      <div class="campo"><label>Inscripción</label><input type="date" name="inscripcion" value="<?= e($f['inscripcion'] ?? '') ?>"></div>
      <div class="campo"><label>Finalización</label><input type="date" name="finalizacion" value="<?= e($f['finalizacion'] ?? '') ?>"></div>
      <div class="campo"><label>Estado</label>
        <select name="estado"><?= opciones_html(array_combine(estados_formacion(), estados_formacion()), $f['estado'] ?? '', true, '— Finalizado si tiene fecha —') ?></select></div>
      <div class="campo"><label>Resultado <span class="tenue">(opcional)</span></label>
        <input type="text" name="resultado" maxlength="200" value="<?= e($f['resultado'] ?? '') ?>" placeholder="Completado con cuestionario, apto…"></div>
      <div class="campo campo-ancho"><label>Notas <span class="tenue">(opcional)</span></label>
        <textarea name="notas" rows="2"><?= e($f['notas'] ?? '') ?></textarea></div>
      <div class="campo-ancho"><button class="btn btn-primario"><?= icono('check') ?>Guardar</button></div>
    </form>
    <?php
}

// El bloque: en un curso, «Quién lo ha hecho»; en una persona, «Formación».
function pintar_formacion(PDO $pdo, array $el): void {
    $fichas = fichas_trabajo($pdo);
    $filas = formacion_de($pdo, $el['id'], $fichas);
    $en_curso = es_curso($el);
    $ya = array_column($filas, $en_curso ? 'elemento_id' : 'curso_id');
    $opciones = $en_curso
        ? array_values(array_filter(personas_formacion($pdo), static fn($p) => !in_array($p['id'], $ya, true)))
        : array_values(array_filter(elementos_de($pdo, 'trabajo'), static fn($c) => es_curso($c) && !in_array($c['id'], $ya, true)));
    $horas = 0.0;
    if (!$en_curso) foreach ($filas as $f) if ($f['estado'] === 'Finalizado') $horas += horas_curso($fichas[$f['curso_id']] ?? ['datos' => []]) ?? 0;
    ?>
    <section class="tarjeta" id="formacion">
      <div class="tarjeta-cabecera">
        <h2><?= icono('birrete') ?><?= $en_curso ? 'Quién lo ha hecho' : 'Formación' ?></h2>
        <span class="tenue"><?= e(implode(' · ', array_filter([
            $filas ? count($filas) . ($en_curso ? (count($filas) === 1 ? ' persona' : ' personas') : (count($filas) === 1 ? ' curso' : ' cursos')) : '',
            $horas > 0 ? numero_es($horas, $horas == floor($horas) ? 0 : 1) . ' h' : '']))) ?></span>
      </div>
      <?php if (!$filas): ?><p class="vacio-mini"><?= $en_curso ? 'Nadie apuntado todavía. Marca quién lo ha hecho: pueden ser varias personas.' : 'Sin cursos todavía.' ?></p><?php endif; ?>
      <?php foreach ($filas as $f): $otro = $en_curso ? $f['elemento_id'] : $f['curso_id']; ?>
        <article class="plan-curso">
          <div class="plan-cabecera">
            <h3><a href="<?= e(url('elemento.php?id=' . $otro . ($en_curso ? '#formacion' : ''))) ?>"><?= e($en_curso ? $f['persona_nombre'] : $f['curso_nombre']) ?></a></h3>
            <?php if ($f['estado'] !== ''): ?><span class="chip<?= $f['estado'] === 'Finalizado' ? ' chip-activo' : '' ?>"><?= e($f['estado']) ?></span><?php endif; ?>
          </div>
          <p class="tenue"><?= e(implode(' · ', array_filter([
              $f['inscripcion'] ? 'inscripción ' . fecha_es($f['inscripcion']) : '',
              $f['finalizacion'] ? 'finalización ' . fecha_es($f['finalizacion']) : '', $f['resultado']]))) ?></p>
          <?php if ($f['notas'] !== ''): ?><p class="notas"><?= nl2br(e($f['notas'])) ?></p><?php endif; ?>
          <div class="botones-tarjeta plan-acciones">
            <details class="desplegable">
              <summary class="btn btn-sutil"><?= icono('editar') ?>Editar</summary>
              <?php formulario_formacion($f, $en_curso ? 'curso' : 'persona'); ?>
            </details>
            <form method="post" data-confirmar="¿Quitar este curso a <?= e($f['persona_nombre']) ?>?">
              <?= csrf_input() ?><input type="hidden" name="accion" value="borrar-formacion"><input type="hidden" name="formacion_id" value="<?= (int)$f['id'] ?>">
              <button class="btn-icono" title="Quitar"><?= icono('papelera') ?></button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
      <div class="botones-tarjeta">
        <?php if ($opciones): ?>
          <details class="desplegable">
            <summary class="btn btn-sutil"><?= icono('mas') ?><?= $en_curso ? 'Añadir personas' : 'Apuntar un curso' ?></summary>
            <?php formulario_formacion([], $en_curso ? 'curso' : 'persona', $opciones); ?>
          </details>
        <?php endif; ?>
        <?php if (!$en_curso): ?>
          <a class="btn btn-sutil" href="<?= e(url('elemento-editar.php?s=trabajo&t=curso')) ?>"><?= icono('mas') ?>Curso nuevo</a>
        <?php endif; ?>
      </div>
    </section>
    <?php
}

// Lo que manda el formulario del bloque: una o varias personas (desde el curso) o un curso (desde la persona).
function guardar_formacion_formulario(PDO $pdo, array $el, array $post, ?int $usuario_id): int {
    $campos = array_intersect_key($post, array_flip(['inscripcion', 'finalizacion', 'estado', 'resultado', 'notas']));
    if (es_curso($el)) {
        $personas = isset($post['persona_id']) ? [(int)$post['persona_id']] : array_map('intval', (array)($post['personas'] ?? []));
        if (!$personas) throw new ErrorValidacion(['Marca al menos a una persona.']);
        foreach ($personas as $p) guardar_formacion($pdo, $el['id'], $p, $campos, $usuario_id);
        return count($personas);
    }
    guardar_formacion($pdo, (int)($post['curso_id'] ?? 0), $el['id'], $campos, $usuario_id);
    return 1;
}
