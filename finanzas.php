<?php
// =====================================================================
//  Finanzas, dentro del segundo cerebro (3/10/2026, decisión de Gonzalo:
//  «esperaba que finanzas viviera dentro y tener el mismo panel»). Solo
//  administradores.
//
//  Es EL MISMO panel de finanzas, no una copia: el marcado y los datos llegan
//  de su API (acción «panel») y los estilos y el JS se cargan de su carpeta
//  (assets/panel.css y panel.js, mismo dominio). Un solo código del panel
//  para las dos apps: se cambia en finanzas-personales. Los botones de acción
//  (importar, revisar, nómina…) abren aún sus pantallas, sin contraseña, por
//  el pase; se irán pasando aquí una a una.
//
//  No se pinta «Qué mirar» (los avisos de salud que da la API): Gonzalo lo
//  quitó el 3/10/2026, ya sabe que tiene movimientos por clasificar. Las
//  comprobaciones siguen en la pantalla de Salud de finanzas.
//
//  El marcado se imprime sin escapar: viene de nuestro propio servidor
//  (finanzas), con clave, nunca de un usuario.
// =====================================================================
require_once __DIR__ . '/includes/auth.php';

if (!es_admin()) pagina_error(403, 'Solo administradores', 'Finanzas solo la ven los administradores.');

$r = finanzas_panel();
$d = $r['datos'];
$est = finanzas_ruta_estaticos();
$v = rawurlencode((string)($d['version'] ?? '1'));

cabecera('Finanzas', 'finanzas');
cabecera_pagina('Finanzas', 'Cuentas, inversiones, patrimonio y nóminas.',
    '<a class="btn btn-sutil" href="' . e(url('cuenta-casa.php')) . '">' . icono('casa') . 'Cuenta de la casa</a>', 'cartera', '#405189');
?>
<?php if ($r['error']): ?>
  <div class="flash flash-aviso"><?= e($r['error']) ?><?= $d ? ' Enseño la última copia (' . e(fecha_corta(substr((string)$r['leido_en'], 0, 10)) . ' ' . substr((string)$r['leido_en'], 11, 5)) . ').' : '' ?></div>
<?php endif; ?>

<?php if (!empty($d['html'])): ?>
  <link rel="stylesheet" href="<?= e($est . 'panel.css?v=' . $v) ?>">
  <?php // Después del suyo: lo viste con el diseño de esta app (assets/finanzas.css). ?>
  <link rel="stylesheet" href="<?= e(asset('finanzas.css')) ?>">
  <div class="panel-fin en-cerebro"><?= $d['html'] ?></div>
  <script id="data-finanzas" type="application/json"><?= json_encode($d['datos'] ?? new stdClass(),
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="<?= e($est . 'chart.umd.min.js?v=' . $v) ?>"></script>
  <script src="<?= e($est . 'panel.js?v=' . $v) ?>"></script>
<?php elseif (!$r['error']): ?>
  <div class="tarjeta vacio"><p>Finanzas no ha devuelto el panel.</p></div>
<?php endif; ?>
<?php pie();
