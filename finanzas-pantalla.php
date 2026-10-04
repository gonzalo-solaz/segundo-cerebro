<?php
// =====================================================================
//  Las pantallas de acción de finanzas (importar, revisar, movimiento,
//  nómina, cotizaciones, salud) DENTRO del segundo cerebro (4/10/2026, queja
//  de Gonzalo: «las subsecciones de finanzas no están adaptadas, son
//  diferentes y pierdo el menú lateral»). Solo administradores.
//
//  Cada una sigue viviendo en finanzas (sus formularios, sus subidas de
//  extractos, su lógica), pero se monta aquí en un iframe: el menú lateral
//  y la cabecera son de esta app, y dentro finanzas pinta SOLO el contenido,
//  con los estilos de aquí (assets/finanzas-pantallas.css). El iframe entra
//  siempre por el pase (finanzas-entrar.php), así que no hay sesión de
//  finanzas que caduque a medias. Cuando una pantalla se pase a esta app
//  sobre su API, solo cambia el cuerpo de este archivo.
//
//  ?p = la pantalla, ?q = su query (p. ej. vista=sueltos), que se reenvía.
// =====================================================================
require_once __DIR__ . '/includes/auth.php';

if (!es_admin()) pagina_error(403, 'Solo administradores', 'Finanzas solo la ven los administradores.');

// clave => [título, qué hace, icono]. El orden es el de la fila de pestañas.
$pantallas = [
    'importador'   => ['Importar extracto', 'Sube los extractos del banco: solo se guarda lo nuevo.'],
    'revisar'      => ['Revisar sin categorizar', 'Clasifica lo pendiente: unas pocas decisiones se llevan la mayor parte.'],
    'movimiento'   => ['Añadir movimiento', 'Apunta a mano algo que el banco no trae.'],
    'nomina'       => ['Cargar nómina', 'Graba el recibo del mes y cuádralo con el banco.'],
    'cotizaciones' => ['Actualizar cotizaciones', 'Pon al día el valor de acciones, fondos y criptomonedas.'],
    'salud'        => ['Salud de los datos', 'Comprueba que la importación ha ido bien.'],
];
$p = (string)($_GET['p'] ?? '');
if (!isset($pantallas[$p])) redirigir('finanzas.php');

$q = (string)($_GET['q'] ?? '');
if (!preg_match('/^[A-Za-z0-9_=&%.-]*$/', $q)) $q = '';
[$titulo, $sub] = $pantallas[$p];

// Sin el pase configurado no se puede montar dentro: se ofrece abrirla en finanzas.
$posible = PASE_CLAVE !== '' && FINANZAS_URL !== '';
$destino = $p . '.php?' . ($q !== '' ? $q . '&' : '') . 'embed=1';

cabecera($titulo, 'finanzas');
cabecera_pagina($titulo, '<a href="' . e(url('finanzas.php')) . '">Finanzas</a> · ' . e($sub), '', 'cartera', '#405189');
?>
<p class="filtros">
  <a class="chip chip-boton" href="<?= e(url('finanzas.php')) ?>">Panel</a>
  <?php foreach ($pantallas as $k => [$t]): ?>
    <a class="chip chip-boton<?= $k === $p ? ' chip-activo' : '' ?>" href="<?= e(url('finanzas-pantalla.php?p=' . $k)) ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
</p>
<?php if ($posible): ?>
  <?php // El tamaño del marco (.fin-marco) vive en el mismo CSS que viste lo de dentro. ?>
  <link rel="stylesheet" href="<?= e(asset('finanzas-pantallas.css')) ?>">
  <iframe class="fin-marco" title="<?= e($titulo) ?>"
          src="<?= e(url('finanzas-entrar.php?a=' . rawurlencode(pase_destino_valido($destino)))) ?>"></iframe>
<?php else: ?>
  <div class="tarjeta">
    <p>Para ver esta pantalla dentro del segundo cerebro falta el secreto <code>PASE_CLAVE</code> (el mismo en los repositorios de las dos apps).</p>
    <p><a class="btn btn-primario" href="<?= e(rtrim(FINANZAS_URL, '/') . '/' . $p . '.php') ?>">Abrirla en finanzas</a></p>
  </div>
<?php endif; ?>
<?php pie();
