<?php
// Todo el dominio de la app, en el orden en que se necesita. Lo cargan el
// guardián (auth.php), la API, el cron y las pruebas.
require_once __DIR__ . '/config-carga.php';
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/secciones.php';
require_once __DIR__ . '/actividad.php';
require_once __DIR__ . '/personas.php';
require_once __DIR__ . '/usuarios.php';
require_once __DIR__ . '/elementos.php';
require_once __DIR__ . '/vencimientos.php';
require_once __DIR__ . '/registros.php';
require_once __DIR__ . '/comunidad.php';
require_once __DIR__ . '/finanzas.php';
require_once __DIR__ . '/peso.php';
require_once __DIR__ . '/documentos.php';
require_once __DIR__ . '/avisos.php';
require_once __DIR__ . '/iconos.php';
require_once __DIR__ . '/layout.php';
