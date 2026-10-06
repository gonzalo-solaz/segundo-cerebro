<?php
// =====================================================================
//  Plan de desarrollo del equipo (tabla plan_desarrollo, migración 007).
//
//  Un registro por persona y curso (septiembre a agosto): el objetivo, la
//  descripción, los niveles de 0 a 4 (cómo se mide), la autoevaluación, la
//  nota final y notas sueltas. Vive en las fichas de Trabajo de tipo «empleo»
//  (el responsable) y «miembro» (cada persona del equipo). Las notas son sobre
//  10, como en la evaluación de competencias de la empresa. Gonzalo, 6/10/2026:
//  «quiero un bloque del plan de desarrollo», no apuntes sueltos del historial.
// =====================================================================

// Los tipos de ficha que llevan plan de desarrollo.
function lleva_plan(array $el): bool {
    return $el['seccion'] === 'trabajo' && in_array($el['tipo'], ['empleo', 'miembro'], true);
}

// «2025-26». Septiembre empieza el curso nuevo.
function curso_de(string $fecha): string {
    [$a, $m] = array_map('intval', explode('-', $fecha));
    $ini = $m >= 9 ? $a : $a - 1;
    return $ini . '-' . substr((string)($ini + 1), -2);
}

// Un curso válido es «AAAA-AA» con el segundo año justo detrás del primero («2025-26»).
function curso_valido(string $curso): bool {
    return preg_match('/^(\d{4})-(\d{2})$/', $curso, $m) === 1 && ((int)$m[1] + 1) % 100 === (int)$m[2];
}

function plan_de(PDO $pdo, int $elemento_id): array {
    $st = $pdo->prepare('SELECT * FROM plan_desarrollo WHERE elemento_id = ? ORDER BY curso DESC');
    $st->execute([$elemento_id]);
    return array_map('decodificar_plan', $st->fetchAll());
}

function decodificar_plan(array $f): array {
    $f['id'] = (int)$f['id'];
    $f['elemento_id'] = (int)$f['elemento_id'];
    foreach (['autoevaluacion', 'nota'] as $k) $f[$k] = $f[$k] !== null ? (float)$f[$k] : null;
    foreach (['descripcion', 'niveles', 'notas'] as $k) $f[$k] = (string)($f[$k] ?? '');
    return $f;
}

/**
 * Crea o actualiza el plan de un curso. Lo que no viene en $e se queda como estaba (la
 * API cambia solo lo que dice); lo que viene en blanco, se borra. Devuelve el id.
 */
function guardar_plan(PDO $pdo, int $elemento_id, array $e, ?int $usuario_id = null): int {
    $el = elemento($pdo, $elemento_id);
    if (!$el || !lleva_plan($el)) throw new ErrorValidacion(['El plan de desarrollo es de la ficha de un empleo o de una persona del equipo.']);
    $errores = [];

    $curso = trim((string)($e['curso'] ?? ''));
    if (!curso_valido($curso)) $errores[] = 'El curso tiene que ser como «2025-26».';

    $st = $pdo->prepare('SELECT * FROM plan_desarrollo WHERE elemento_id = ? AND curso = ?');
    $st->execute([$elemento_id, $curso]);
    $antes = ($f = $st->fetch()) ? decodificar_plan($f) : null;
    $dato = static fn(string $k, $defecto) => array_key_exists($k, $e) ? $e[$k] : ($antes[$k] ?? $defecto);

    $objetivo = trim((string)$dato('objetivo', ''));
    if (longitud($objetivo) > 300) $errores[] = 'El objetivo es demasiado largo (300 caracteres como mucho): lo largo va en la descripción.';
    $nota = [];
    foreach (['autoevaluacion' => 'La autoevaluación', 'nota' => 'La nota final'] as $k => $etq) {
        $v = $dato($k, null);
        if ($v === null || trim((string)$v) === '') { $nota[$k] = null; continue; }
        $n = is_numeric($v) ? (float)$v : leer_numero((string)$v);
        if ($n === null || $n < 0 || $n > 10) { $errores[] = "{$etq} tiene que ser un número de 0 a 10."; continue; }
        $nota[$k] = round($n, 2);
    }
    $textos = [];
    foreach (['descripcion', 'niveles', 'notas'] as $k) $textos[$k] = trim((string)$dato($k, ''));
    if ($objetivo === '' && ($nota['autoevaluacion'] ?? null) === null && ($nota['nota'] ?? null) === null) {
        $errores[] = 'Apunta al menos el objetivo o una nota.';
    }
    if ($errores) throw new ErrorValidacion($errores);

    if ($antes) {
        $pdo->prepare('UPDATE plan_desarrollo SET objetivo = ?, descripcion = ?, niveles = ?, autoevaluacion = ?, nota = ?, notas = ?, actualizado_en = ?
                       WHERE id = ?')
            ->execute([$objetivo, $textos['descripcion'], $textos['niveles'], $nota['autoevaluacion'], $nota['nota'], $textos['notas'], ahora(), $antes['id']]);
        $id = $antes['id'];
    } else {
        $pdo->prepare('INSERT INTO plan_desarrollo (elemento_id, curso, objetivo, descripcion, niveles, autoevaluacion, nota, notas, actualizado_en)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$elemento_id, $curso, $objetivo, $textos['descripcion'], $textos['niveles'], $nota['autoevaluacion'], $nota['nota'], $textos['notas'], ahora()]);
        $id = (int)$pdo->lastInsertId();
    }
    anotar($pdo, $usuario_id, "puso al día el plan de desarrollo {$curso} de «{$el['nombre']}»");
    return $id;
}

function borrar_plan(PDO $pdo, int $id, int $elemento_id, ?int $usuario_id = null): void {
    $st = $pdo->prepare('SELECT curso FROM plan_desarrollo WHERE id = ? AND elemento_id = ?');
    $st->execute([$id, $elemento_id]);
    $curso = $st->fetchColumn();
    if ($curso === false) return;
    $pdo->prepare('DELETE FROM plan_desarrollo WHERE id = ?')->execute([$id]);
    anotar($pdo, $usuario_id, "borró el plan de desarrollo {$curso}");
}

// Las notas finales de más antigua a más reciente: [['curso' => '2022-23', 'nota' => 8.38], …].
function evolucion_notas(array $plan): array {
    $out = [];
    foreach (array_reverse($plan) as $p) if ($p['nota'] !== null) $out[] = ['curso' => $p['curso'], 'nota' => $p['nota']];
    return $out;
}

// El plan de un curso de cada persona de una lista de elementos: [elemento_id => fila], para el panel.
function plan_del_curso(PDO $pdo, string $curso): array {
    $st = $pdo->prepare('SELECT * FROM plan_desarrollo WHERE curso = ?');
    $st->execute([$curso]);
    $out = [];
    foreach ($st->fetchAll() as $f) $out[(int)$f['elemento_id']] = decodificar_plan($f);
    return $out;
}

// Todos los planes, del curso más reciente al más antiguo: la pestaña «Plan de desarrollo» de Trabajo.
function plan_todo(PDO $pdo): array {
    return array_map('decodificar_plan', $pdo->query('SELECT * FROM plan_desarrollo ORDER BY curso DESC, id')->fetchAll());
}

// La nota final más reciente de cada ficha: [elemento_id => ['curso', 'nota']].
function ultima_nota_por_elemento(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT elemento_id, curso, nota FROM plan_desarrollo WHERE nota IS NOT NULL ORDER BY curso') as $f) {
        $out[(int)$f['elemento_id']] = ['curso' => $f['curso'], 'nota' => (float)$f['nota']];
    }
    return $out;
}

// ---------------------------------------------------------------------
//  El bloque «Plan de desarrollo» de la ficha (elemento.php)
// ---------------------------------------------------------------------
function nota_es($n): string {
    return numero_es($n, 2);
}

function formulario_plan(int $elemento_id, array $p, bool $nuevo): void {
    ?>
    <form method="post" class="form-rejilla">
      <?= csrf_input() ?><input type="hidden" name="accion" value="plan">
      <div class="campo"><label>Curso</label>
        <input type="text" name="curso" value="<?= e($p['curso']) ?>" required pattern="\d{4}-\d{2}" maxlength="7" placeholder="2026-27"<?= $nuevo ? '' : ' readonly' ?>></div>
      <div class="campo"><label>Autoevaluación <span class="tenue">(sobre 10)</span></label>
        <input type="text" inputmode="decimal" name="autoevaluacion" value="<?= e($p['autoevaluacion'] !== null ? nota_es($p['autoevaluacion']) : '') ?>"></div>
      <div class="campo"><label>Nota final <span class="tenue">(sobre 10)</span></label>
        <input type="text" inputmode="decimal" name="nota" value="<?= e($p['nota'] !== null ? nota_es($p['nota']) : '') ?>"></div>
      <div class="campo campo-ancho"><label>Objetivo</label>
        <input type="text" name="objetivo" maxlength="300" value="<?= e($p['objetivo']) ?>" placeholder="Qué hay que conseguir y para cuándo"></div>
      <div class="campo campo-ancho"><label>Descripción <span class="tenue">(opcional)</span></label>
        <textarea name="descripcion" rows="3"><?= e($p['descripcion']) ?></textarea></div>
      <div class="campo campo-ancho"><label>Niveles de 0 a 4 <span class="tenue">(una línea por nivel)</span></label>
        <textarea name="niveles" rows="5" placeholder="Nivel 0 = …"><?= e($p['niveles']) ?></textarea></div>
      <div class="campo campo-ancho"><label>Notas <span class="tenue">(opcional)</span></label>
        <textarea name="notas" rows="2"><?= e($p['notas']) ?></textarea></div>
      <div class="campo-ancho"><button class="btn btn-primario"><?= icono('check') ?>Guardar</button></div>
    </form>
    <?php
}

function pintar_plan(array $el, array $plan, string $hoy): void {
    $evol = evolucion_notas($plan);
    $vacio = ['curso' => '', 'objetivo' => '', 'descripcion' => '', 'niveles' => '', 'autoevaluacion' => null, 'nota' => null, 'notas' => ''];
    $nuevo = ['curso' => curso_de($hoy)] + $vacio;
    foreach ($plan as $p) if ($p['curso'] === $nuevo['curso']) { $nuevo['curso'] = ''; break; }
    ?>
    <?php // Plegado (Gonzalo, 6/10/2026); al volver de guardar, «#plan» lo abre (app.js). ?>
    <details class="tarjeta tarjeta-plegable" id="plan">
      <summary>
        <div class="titulo-plegable">
          <h2><?= icono('bombilla') ?>Plan de desarrollo</h2>
          <?php if (count($evol) > 1): ?>
            <span class="tenue" title="Nota final de cada curso">Notas: <?= e(implode(' → ', array_map(static fn($x) => nota_es($x['nota']), $evol))) ?></span>
          <?php elseif ($plan): ?>
            <span class="tenue"><?= count($plan) ?> curso<?= count($plan) === 1 ? '' : 's' ?></span>
          <?php endif; ?>
        </div>
      </summary>
      <?php if (!$plan): ?><p class="vacio-mini">Sin objetivos ni notas todavía. Se apunta un curso (septiembre a agosto) con su objetivo, sus niveles de 0 a 4 y la nota.</p><?php endif; ?>
      <?php foreach ($plan as $p): ?>
        <article class="plan-curso">
          <div class="plan-cabecera">
            <h3>Curso <?= e($p['curso']) ?></h3>
            <?php if ($p['autoevaluacion'] !== null): ?><span class="chip">Autoevaluación <?= e(nota_es($p['autoevaluacion'])) ?></span><?php endif; ?>
            <?php if ($p['nota'] !== null): ?><span class="chip chip-activo">Nota <?= e(nota_es($p['nota'])) ?></span><?php endif; ?>
            <?php if ($p['nota'] === null && $p['curso'] < curso_de($hoy)): ?><span class="chip">Sin nota</span><?php endif; ?>
          </div>
          <?php if ($p['objetivo'] !== ''): ?><p class="plan-objetivo"><?= e($p['objetivo']) ?></p><?php endif; ?>
          <?php if ($p['notas'] !== ''): ?><p class="notas"><?= nl2br(e($p['notas'])) ?></p><?php endif; ?>
          <?php if ($p['descripcion'] !== '' || $p['niveles'] !== ''): ?>
            <details class="desplegable plan-detalle">
              <summary class="enlace-tenue">Descripción y niveles</summary>
              <?php if ($p['descripcion'] !== ''): ?><p class="notas"><?= nl2br(e($p['descripcion'])) ?></p><?php endif; ?>
              <?php if ($p['niveles'] !== ''): ?><?= lista_campo($p['niveles']) ?><?php endif; ?>
            </details>
          <?php endif; ?>
          <div class="botones-tarjeta plan-acciones">
            <details class="desplegable">
              <summary class="btn btn-sutil"><?= icono('editar') ?>Editar</summary>
              <?php formulario_plan($el['id'], $p, false); ?>
            </details>
            <form method="post" data-confirmar="¿Borrar el plan del curso <?= e($p['curso']) ?>? No se puede deshacer.">
              <?= csrf_input() ?><input type="hidden" name="accion" value="borrar-plan"><input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
              <button class="btn-icono" title="Borrar este curso"><?= icono('papelera') ?></button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
      <details class="desplegable">
        <summary class="btn btn-sutil"><?= icono('mas') ?>Añadir un curso</summary>
        <?php formulario_plan($el['id'], $nuevo, true); ?>
      </details>
    </details>
    <?php
}
