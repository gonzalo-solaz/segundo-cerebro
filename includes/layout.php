<?php
// =====================================================================
//  Maqueta y piezas de interfaz compartidas.
//  El diseño vive en assets/app.css (tokens en :root, tema claro/oscuro).
//  Nada externo: ni fuentes de Google ni CDN (regla de van4ever, y aquí
//  hay datos de salud): la tipografía es la del sistema.
// =====================================================================

function menu_principal(): array {
    $m = [['clave' => 'index', 'url' => 'index.php', 'texto' => 'Panel', 'icono' => 'panel']];
    foreach (secciones() as $k => $s) {
        $m[] = ['clave' => 'seccion:' . $k, 'url' => 'seccion.php?s=' . $k, 'texto' => $s['nombre'],
                'icono' => $s['icono'], 'color' => $s['color']];
    }
    $m[] = ['clave' => 'agenda', 'url' => 'vencimientos.php', 'texto' => 'Agenda', 'icono' => 'agenda'];
    $m[] = ['clave' => 'personas', 'url' => 'personas.php', 'texto' => 'Personas', 'icono' => 'persona'];
    return $m;
}

function cabeza_html(string $titulo): void {
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= e($titulo) ?> · <?= e(NOMBRE_APP) ?></title>
<script src="<?= e(asset('tema.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<link rel="icon" href="<?= e(asset('icono.svg')) ?>" type="image/svg+xml">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<?php
}

function cabecera(string $titulo, string $activa = ''): void {
    global $usuario_actual;
    $flashes = flashes();
    cabeza_html($titulo);
    ?>
<body>
<div class="app">
  <aside class="lateral" id="lateral">
    <a class="marca" href="<?= e(url('index.php')) ?>">
      <span class="marca-icono"><?= icono('cerebro') ?></span><span class="marca-texto"><?= e(NOMBRE_APP) ?></span>
    </a>
    <nav class="menu" aria-label="Secciones">
      <?php foreach (menu_principal() as $i): ?>
        <a href="<?= e(url($i['url'])) ?>" class="<?= $activa === $i['clave'] ? 'activo' : '' ?>"<?= isset($i['color']) ? ' style="--c:' . e($i['color']) . '"' : '' ?>>
          <?= icono($i['icono']) ?><span><?= e($i['texto']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (es_admin()): ?>
        <div class="menu-separador"></div>
        <?php if (FINANZAS_URL !== ''): ?>
          <a href="<?= e(FINANZAS_URL) ?>" style="--c:#405189"><?= icono('cartera') ?><span>Finanzas</span><?= icono('externo', 'ico ico-mini') ?></a>
        <?php endif; ?>
        <a href="<?= e(url('ajustes.php')) ?>" class="<?= $activa === 'ajustes' ? 'activo' : '' ?>"><?= icono('ajustes') ?><span>Ajustes</span></a>
      <?php endif; ?>
    </nav>
    <div class="lateral-pie">
      <a class="usuario <?= $activa === 'cuenta' ? 'activo' : '' ?>" href="<?= e(url('cuenta.php')) ?>" title="Mi cuenta">
        <?= avatar($usuario_actual['nombre'], $usuario_actual['persona_color'] ?? null) ?>
        <span><?= e(nombre_corto($usuario_actual['nombre'])) ?></span>
      </a>
      <button type="button" class="btn-icono" data-accion="tema" title="Cambiar entre tema claro y oscuro">
        <?= icono('luna', 'ico solo-claro') ?><?= icono('sol', 'ico solo-oscuro') ?>
      </button>
      <form method="post" action="<?= e(url('logout.php')) ?>">
        <?= csrf_input() ?>
        <button class="btn-icono" title="Salir"><?= icono('salir') ?></button>
      </form>
    </div>
  </aside>
  <div class="velo" data-accion="menu"></div>
  <div class="principal">
    <header class="barra-movil">
      <button type="button" class="btn-icono" data-accion="menu" aria-label="Abrir el menú"><?= icono('menu') ?></button>
      <a class="marca" href="<?= e(url('index.php')) ?>"><span class="marca-icono"><?= icono('cerebro') ?></span><span class="marca-texto"><?= e(NOMBRE_APP) ?></span></a>
    </header>
    <main class="contenido">
      <?php foreach ($flashes as $f): ?>
        <div class="flash flash-<?= e($f['tipo']) ?>" role="status"><?= e($f['texto']) ?></div>
      <?php endforeach; ?>
<?php
}

function pie(): void {
    ?>
    </main>
  </div>
</div>
</body>
</html>
<?php
}

// Login y alta del primer administrador: sin menú, sin nada privado.
function cabecera_publica(string $titulo): void {
    cabeza_html($titulo);
    ?>
<body class="publica">
  <main class="tarjeta-acceso">
    <div class="acceso-marca"><span class="marca-icono"><?= icono('cerebro') ?></span><span><?= e(NOMBRE_APP) ?></span></div>
    <h1><?= e($titulo) ?></h1>
<?php
}

function pie_publico(): void {
    ?>
  </main>
</body>
</html>
<?php
}

function pagina_error(int $codigo, string $titulo, string $texto): void {
    http_response_code($codigo);
    cabecera($titulo);
    ?>
    <div class="vacio">
      <h1><?= e($titulo) ?></h1>
      <p><?= e($texto) ?></p>
      <a class="btn" href="<?= e(url('index.php')) ?>"><?= icono('atras') ?>Volver al panel</a>
    </div>
    <?php
    pie();
    exit;
}

function exigir_admin(): void {
    if (!es_admin()) pagina_error(403, 'Solo para administradores', 'Esta parte la gestiona quien administra la app.');
}

// ---------------------------------------------------------------------
//  Piezas
// ---------------------------------------------------------------------
function avatar(string $nombre, ?string $color = null, string $clase = 'avatar'): string {
    return '<span class="' . e($clase) . '" style="--c:' . e($color ?: '#405189') . '">' . e(iniciales($nombre)) . '</span>';
}

function chip_seccion(string $clave): string {
    $s = seccion($clave);
    if (!$s) return '';
    return '<a class="chip" href="' . e(url('seccion.php?s=' . $clave)) . '" style="--c:' . e($s['color']) . '">'
         . icono($s['icono'], 'ico ico-mini') . e($s['nombre']) . '</a>';
}

function chip_persona(?string $nombre, ?string $color): string {
    if (!$nombre) return '';
    return '<span class="chip chip-persona" style="--c:' . e($color ?: '#405189') . '">' . e($nombre) . '</span>';
}

function cabecera_pagina(string $titulo, string $antetitulo = '', string $acciones = '', ?string $icono = null, ?string $color = null): void {
    ?>
    <div class="cabecera-pagina">
      <div class="cabecera-titulo">
        <?php if ($icono): ?><span class="icono-grande" style="--c:<?= e($color ?: '#405189') ?>"><?= icono($icono) ?></span><?php endif; ?>
        <div>
          <?php if ($antetitulo !== ''): ?><p class="antetitulo"><?= $antetitulo ?></p><?php endif; ?>
          <h1><?= e($titulo) ?></h1>
        </div>
      </div>
      <?php if ($acciones !== ''): ?><div class="acciones"><?= $acciones ?></div><?php endif; ?>
    </div>
    <?php
}

/**
 * Una fila de la agenda: fecha grande, qué es, cuándo, y el botón «Hecho».
 * $volver = la página a la que regresar después de marcarlo.
 */
function fila_vencimiento(array $v, string $volver, bool $con_seccion = true, bool $con_elemento = true): void {
    [, $m, $d] = array_map('intval', explode('-', $v['fecha']));
    ?>
    <div class="venc venc-<?= e($v['situacion']) ?>">
      <div class="venc-fecha"><strong><?= $d ?></strong><span><?= e(MESES_CORTOS[$m - 1]) ?></span></div>
      <div class="venc-cuerpo">
        <div class="venc-titulo"><?= e($v['titulo']) ?></div>
        <div class="venc-meta">
          <span class="venc-cuando"><?= e(relativo($v['dias'])) ?><?= substr($v['fecha'], 0, 4) !== substr(hoy(), 0, 4) ? ' · ' . e(substr($v['fecha'], 0, 4)) : '' ?></span>
          <?php if ($con_seccion): ?><?= chip_seccion($v['seccion']) ?><?php endif; ?>
          <?php if ($con_elemento && $v['elemento_id']): ?>
            <a class="enlace-tenue" href="<?= e(url('elemento.php?id=' . $v['elemento_id'])) ?>"><?= e($v['elemento_nombre']) ?></a>
          <?php endif; ?>
          <?php if ($v['repetir_meses'] > 0): ?><span class="tenue" title="Se repite"><?= icono('repetir', 'ico ico-mini') ?><?= e(texto_repetir($v['repetir_meses'])) ?></span><?php endif; ?>
          <?= chip_persona($v['persona_nombre'] ?? null, $v['persona_color'] ?? null) ?>
        </div>
      </div>
      <div class="venc-acciones">
        <a class="btn-icono" href="<?= e(url('vencimientos.php?editar=' . $v['id'] . '&volver=' . rawurlencode($volver))) ?>" title="Cambiar fecha o detalles"><?= icono('editar') ?></a>
        <form method="post" action="<?= e(url('vencimientos.php')) ?>">
          <?= csrf_input() ?>
          <input type="hidden" name="accion" value="hecho">
          <input type="hidden" name="vencimiento_id" value="<?= (int)$v['id'] ?>">
          <input type="hidden" name="volver" value="<?= e($volver) ?>">
          <button class="btn btn-hecho" title="Marcar como hecho"><?= icono('check') ?><span>Hecho</span></button>
        </form>
      </div>
    </div>
    <?php
}

function opciones_html(array $opciones, $seleccionada, bool $con_vacia = true, string $texto_vacia = '—'): string {
    $h = $con_vacia ? '<option value="">' . e($texto_vacia) . '</option>' : '';
    foreach ($opciones as $valor => $texto) {
        $sel = (string)$valor === (string)$seleccionada ? ' selected' : '';
        $h .= '<option value="' . e($valor) . '"' . $sel . '>' . e($texto) . '</option>';
    }
    return $h;
}

// Un campo del formulario de un elemento, a partir de su definición.
function campo_formulario(string $clave, array $c, $valor): string {
    $nombre = 'datos[' . $clave . ']';
    $id = 'campo-' . $clave;
    $v = is_array($valor) ? '' : (string)($valor ?? '');
    $etiqueta = e($c['etiqueta']) . (!empty($c['unidad']) ? ' <span class="tenue">(' . e($c['unidad']) . ')</span>' : '');
    switch ($c['tipo']) {
        case 'area':
            $input = '<textarea name="' . e($nombre) . '" id="' . $id . '" rows="3">' . e($v) . '</textarea>';
            break;
        case 'opcion':
            $input = '<select name="' . e($nombre) . '" id="' . $id . '">'
                   . opciones_html(array_combine($c['opciones'], $c['opciones']), $v) . '</select>';
            break;
        case 'fecha':
            $input = '<input type="date" name="' . e($nombre) . '" id="' . $id . '" value="' . e($v) . '">';
            break;
        case 'numero':
        case 'importe':
            $input = '<input type="text" inputmode="decimal" name="' . e($nombre) . '" id="' . $id . '" value="'
                   . e(numero_input($valor, $c['tipo'] === 'importe')) . '"' . ($c['tipo'] === 'importe' ? ' placeholder="0,00"' : '') . '>';
            break;
        case 'tel':
            $input = '<input type="tel" name="' . e($nombre) . '" id="' . $id . '" value="' . e($v) . '">';
            break;
        case 'email':
            $input = '<input type="email" name="' . e($nombre) . '" id="' . $id . '" value="' . e($v) . '">';
            break;
        default:
            $input = '<input type="text" name="' . e($nombre) . '" id="' . $id . '" value="' . e($v) . '">';
    }
    $ayuda = '';
    if (!empty($c['vence'])) {
        $ayuda .= '<small class="ayuda ayuda-aviso">' . icono('reloj', 'ico ico-mini') . 'Avisa ' . (int)($c['aviso'] ?? 30) . ' días antes'
               . (!empty($c['repetir']) ? ' y se repite' : '') . '.</small>';
    }
    if (!empty($c['ayuda'])) $ayuda .= '<small class="ayuda">' . e($c['ayuda']) . '</small>';
    $ancho = $c['tipo'] === 'area' ? ' campo-ancho' : '';
    return '<div class="campo' . $ancho . '"><label for="' . $id . '">' . $etiqueta . '</label>' . $input . $ayuda . '</div>';
}

/** Formulario de «nuevo recordatorio», con las sugerencias de la sección. */
function formulario_vencimiento(string $seccion_fija = '', ?int $elemento_id = null, string $volver = 'vencimientos.php', bool $abierto = false): void {
    $sugerencias = $seccion_fija !== '' ? (seccion($seccion_fija)['sugerencias'] ?? []) : [];
    $form_id = 'form-venc-' . ($elemento_id ?: ($seccion_fija ?: 'libre'));
    ?>
    <details class="desplegable"<?= $abierto ? ' open' : '' ?> id="nuevo">
      <summary class="btn btn-sutil"><?= icono('mas') ?>Nuevo recordatorio</summary>
      <form method="post" action="<?= e(url('vencimientos.php')) ?>" class="form-rejilla" id="<?= e($form_id) ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="accion" value="crear">
        <input type="hidden" name="volver" value="<?= e($volver) ?>">
        <?php if ($elemento_id): ?><input type="hidden" name="elemento_id" value="<?= (int)$elemento_id ?>"><?php endif; ?>
        <?php if ($sugerencias): ?>
          <div class="campo campo-ancho">
            <span class="etiqueta">Ideas habituales</span>
            <div class="sugerencias">
              <?php foreach ($sugerencias as [$t, $meses, $aviso]): ?>
                <button type="button" class="chip chip-boton" data-accion="sugerencia" data-form="<?= e($form_id) ?>"
                        data-titulo="<?= e($t) ?>" data-repetir="<?= (int)$meses ?>" data-aviso="<?= (int)$aviso ?>"><?= e($t) ?></button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="campo"><label>Qué hay que hacer</label><input type="text" name="titulo" required maxlength="150"></div>
        <div class="campo"><label>Fecha</label><input type="date" name="fecha" required></div>
        <?php if (!$seccion_fija): ?>
          <div class="campo"><label>Sección</label>
            <select name="seccion" required><?= opciones_html(array_map(static fn($s) => $s['nombre'], secciones()), '', true, 'Elige…') ?></select>
          </div>
        <?php else: ?>
          <input type="hidden" name="seccion" value="<?= e($seccion_fija) ?>">
        <?php endif; ?>
        <div class="campo"><label>Se repite</label><select name="repetir_meses"><?= opciones_html(opciones_repetir(), 0, false) ?></select></div>
        <div class="campo"><label>Avisar con</label>
          <div class="con-sufijo"><input type="number" name="aviso_dias" value="30" min="0" max="365"><span>días</span></div>
        </div>
        <div class="campo campo-ancho"><label>Notas <span class="tenue">(opcional)</span></label><input type="text" name="notas"></div>
        <div class="campo-ancho"><button class="btn btn-primario"><?= icono('check') ?>Guardar recordatorio</button></div>
      </form>
    </details>
    <?php
}
