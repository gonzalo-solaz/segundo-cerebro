<?php
// =====================================================================
//  Carga config.php una sola vez y pone valor por defecto a lo que falte.
//
//  Por qué los valores por defecto: en finanzas, cada constante nueva
//  obligaba a acordarse de subir config.php a mano, y si no se hacía, la
//  página reventaba con «Undefined constant». Aquí una versión nueva de la
//  app funciona con el config.php viejo.
//
//  En local (pruebas y servidor-local.php) se puede usar OTRA configuración
//  con la variable de entorno SC_CONFIG. Solo se atiende en línea de
//  comandos o en el servidor de desarrollo de PHP, nunca en Hostinger.
// =====================================================================

if (!defined('SC_CONFIG_CARGADA')) {
    $sc_ruta_config = dirname(__DIR__) . '/config.php';
    $sc_otra = getenv('SC_CONFIG');
    if ($sc_otra && in_array(PHP_SAPI, ['cli', 'cli-server'], true)) $sc_ruta_config = $sc_otra;

    if (!is_file($sc_ruta_config)) {
        http_response_code(500);
        exit('Falta config.php: copia config.example.php como config.php y rellena tus datos.');
    }
    require $sc_ruta_config;
    define('SC_CONFIG_CARGADA', $sc_ruta_config);

    $sc_defectos = [
        'DB_DRIVER'          => 'mysql',
        'DB_SQLITE'          => '',
        'BASE_URL'           => '',
        'URL_APP'            => '',
        'NOMBRE_APP'         => 'Segundo cerebro',
        'ASSETS_VERSION'     => '1',
        'SESION_INACTIVIDAD' => 2 * 24 * 3600,
        'SESION_MAXIMA'      => 14 * 24 * 3600,
        'EMAIL_AVISOS'       => '',
        'CRON_CLAVE'         => '',
        'API_CLAVE'          => '',
        'FINANZAS_URL'       => '',
        'FINANZAS_API_CLAVE' => '',
        'PASE_CLAVE'         => '',
        // La variación anual del IPC (INE, serie IPC251856, últimos 36 meses). Vacía = sin red (pruebas).
        'IPC_URL'            => 'https://servicios.ine.es/wstempus/js/ES/DATOS_SERIE/IPC251856?nult=36',
        // El Euríbor a un año: diario del Banco de España (se calcula la media de cada mes) y, si no
        // contesta, la media mensual del BCE. Vacías = sin red (pruebas). Ver includes/euribor.php.
        'EURIBOR_URL'        => 'https://www.bde.es/webbe/es/estadisticas/compartido/datos/csv/ti_1_7.csv',
        'EURIBOR_BCE_URL'    => 'https://data-api.ecb.europa.eu/service/data/FM/M.U2.EUR.RT.MM.EURIBOR1YD_.HSTA?format=csvdata&detail=dataonly&startPeriod=2015-01',
        'DIR_ARCHIVOS'       => dirname(__DIR__) . '/private/archivos',
        'DIR_CACHE'          => dirname(__DIR__) . '/private/cache',
    ];
    foreach ($sc_defectos as $sc_k => $sc_v) {
        if (!defined($sc_k)) define($sc_k, $sc_v);
    }
    unset($sc_ruta_config, $sc_otra, $sc_defectos, $sc_k, $sc_v);
}
