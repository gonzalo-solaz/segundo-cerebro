<?php
// =====================================================================
//  Lo común a todas las pruebas: extensiones, avisos de PHP convertidos en
//  errores, configuración de pruebas, base SQLite nueva y datos de
//  ejemplo creados con las MISMAS funciones que usa la app.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo por línea de comandos.'); }

require_once __DIR__ . '/../includes/cli.php';
// Solo pdo_sqlite: mbstring se deja SIN cargar a propósito, para que las
// pruebas cacen cualquier dependencia de ella (Hostinger la trae, pero el
// código no debe necesitarla).
cli_asegurar_extensiones(['pdo_sqlite']);

error_reporting(E_ALL);
ini_set('display_errors', '1');
// Un aviso o un «undefined index» es un fallo, no ruido.
// (Salvo lo silenciado a propósito con @, como los @unlink de limpieza.)
set_error_handler(static function (int $n, string $m, string $f, int $l): bool {
    if (!(error_reporting() & $n)) return false;
    throw new ErrorException($m, 0, $n, $f, $l);
});
putenv('SC_CONFIG=' . __DIR__ . '/config-pruebas.php');

require_once __DIR__ . '/../includes/config-carga.php';
require_once __DIR__ . '/../includes/esquema.php';
require_once __DIR__ . '/../includes/app.php';

$GLOBALS['sc_fallos'] = 0;
$GLOBALS['sc_pruebas'] = 0;

function comprueba(string $que, bool $ok, string $detalle = ''): void {
    $GLOBALS['sc_pruebas']++;
    if ($ok) {
        echo "  ✓ {$que}\n";
    } else {
        $GLOBALS['sc_fallos']++;
        echo "  ✗ {$que}" . ($detalle !== '' ? "\n      → {$detalle}" : '') . "\n";
    }
}

function lanza(callable $fn): ?Throwable {
    try { $fn(); } catch (Throwable $e) { return $e; }
    return null;
}

function terminar(): void {
    $f = $GLOBALS['sc_fallos'];
    echo "\n  " . ($f ? "{$f} fallo(s)" : 'Todo bien') . " de {$GLOBALS['sc_pruebas']} comprobaciones.\n";
    exit($f ? 1 : 0);
}

function bd_nueva(): PDO {
    $pdo = conectar_bd();
    esquema_al_dia($pdo, true);
    return $pdo;
}

/** Una familia y una casa de ejemplo (fechas relativas a SC_HOY = 3/10/2026). */
// Lo que devuelve la acción nomina_estado de la API de finanzas (recortado):
// 2026 con julio, su extra y agosto; en agosto cambia la diferencia con el banco.
function nominas_de_ejemplo(): array {
    $fila = static fn($mes, $tipo, $liq, $banco, $dif, $cambia) => ['mes' => $mes, 'tipo' => $tipo, 'salario_base' => 1941.45,
        'liquido' => $liq, 'banco' => $banco, 'dif' => $dif, 'cambia' => $cambia];
    return [
        ['anio' => 2026, 'pagas_totales' => 15, 'n_meses' => 3,
         'meses' => [['mes' => '2026-08', 'tipo' => 'mensual', 'dias' => 30, 'campos' => ['salario_base' => 1941.45, 'liquido' => 2100]]],
         'cuadre' => [$fila('2026-07', 'mensual', 2100, null, null, false), $fila('2026-07', 'extra', 1500, 3706.96, 106.96, false),
                      $fila('2026-08', 'mensual', 2100, 2210, 110, true)]],
        ['anio' => 2025, 'pagas_totales' => 15, 'n_meses' => 1, 'meses' => [],
         'cuadre' => [$fila('2025-12', 'mensual', 2000, 2082.78, 82.78, false)]],
    ];
}

function sembrar(PDO $pdo): array {
    $id = [];
    $id['yo']   = guardar_persona($pdo, ['nombre' => 'Gonzalo Prueba', 'relacion' => 'Yo']);
    $id['ana']  = guardar_persona($pdo, ['nombre' => 'Ana Prueba', 'relacion' => 'Pareja']);
    $id['leo']  = guardar_persona($pdo, ['nombre' => 'Leo Prueba', 'relacion' => 'Hijo', 'fecha_nacimiento' => '2018-05-10']);

    [$id['admin']]   = crear_usuario($pdo, ['nombre' => 'Gonzalo', 'email' => 'admin@ejemplo.test', 'password' => 'una-contraseña-larga',
                                            'rol' => 'admin', 'persona_id' => $id['yo']]);
    [$id['miembro']] = crear_usuario($pdo, ['nombre' => 'Ana', 'email' => 'ana@ejemplo.test', 'password' => 'otra-contraseña-larga',
                                            'rol' => 'miembro', 'persona_id' => $id['ana']]);

    $g = static fn(string $s, string $t, array $e) => guardar_elemento($pdo, $s, $t, $e, null, $id['admin']);
    $id['casa']      = $g('vivienda', 'inmueble', ['nombre' => 'Casa de prueba', 'datos' => ['direccion' => 'Calle Mayor 1', 'regimen' => 'Propiedad', 'superficie' => '95,5']]);
    $id['caldera']   = $g('vivienda', 'equipo', ['nombre' => 'Caldera', 'datos' => ['marca' => 'Junkers', 'garantia_hasta' => '2026-10-20']]);
    $id['fontanero'] = $g('vivienda', 'contacto', ['nombre' => 'Fontanero', 'datos' => ['oficio' => 'Fontanería', 'telefono' => '600 000 000']]);
    $id['furgo']     = $g('vehiculos', 'vehiculo', ['nombre' => 'Furgo', 'persona_id' => $id['yo'],
                          'datos' => ['matricula' => '1234ABC', 'km' => '154.300', 'proxima_itv' => '2026-09-20', 'combustible' => 'Diésel']]);
    $id['ficha_leo'] = $g('salud', 'ficha', ['persona_id' => $id['leo'], 'datos' => ['grupo_sanguineo' => '0+', 'alergias' => 'Ninguna conocida']]);
    $id['trat']      = $g('salud', 'tratamiento', ['nombre' => 'Antihistamínico', 'persona_id' => $id['ana'], 'datos' => ['receta_hasta' => '2026-10-10']]);
    $id['medico']    = $g('salud', 'profesional', ['nombre' => 'Dra. Prueba', 'datos' => ['especialidad' => 'Pediatría']]);
    $id['dni']       = $g('documentos', 'dni', ['persona_id' => $id['yo'], 'datos' => ['numero' => '00000000T', 'caducidad' => '2027-01-15']]);
    $id['pasaporte'] = $g('documentos', 'pasaporte', ['persona_id' => $id['ana'], 'datos' => ['caducidad' => '2026-12-01']]);
    $id['carnet']    = $g('documentos', 'carnet', ['persona_id' => $id['yo'], 'datos' => ['permisos' => 'B']]);
    $id['tse']       = $g('documentos', 'otro', ['nombre' => 'Tarjeta sanitaria europea', 'persona_id' => $id['leo'], 'datos' => []]);
    $id['seguro']    = $g('contratos', 'seguro', ['nombre' => 'Seguro de hogar', 'datos' => ['ramo' => 'Hogar', 'coste' => '240', 'periodicidad' => 'Anual', 'renovacion' => '2026-11-01']]);
    $id['luz']       = $g('contratos', 'suministro', ['nombre' => 'Luz', 'datos' => ['categoria' => 'Luz', 'coste' => '60', 'periodicidad' => 'Mensual']]);
    $id['netflix']   = $g('contratos', 'suscripcion', ['nombre' => 'Netflix', 'datos' => ['coste' => '12,99', 'periodicidad' => 'Mensual', 'renovacion' => '2026-10-15']]);
    $id['cole']      = $g('familia', 'colegio', ['nombre' => 'Colegio de prueba', 'persona_id' => $id['leo'], 'datos' => ['curso' => '3º Primaria']]);
    $id['natacion']  = $g('familia', 'actividad', ['nombre' => 'Natación', 'persona_id' => $id['leo'], 'datos' => ['coste' => '35', 'periodicidad' => 'Mensual']]);
    $id['cumple']    = $g('familia', 'fecha', ['nombre' => 'Cumpleaños de la abuela', 'datos' => ['fecha' => '2026-12-01']]);

    $id['ibi'] = crear_vencimiento($pdo, ['titulo' => 'IBI', 'fecha' => '2026-11-05', 'seccion' => 'vivienda',
                                          'repetir_meses' => 12, 'aviso_dias' => 30], $id['admin']);
    $id['registro'] = crear_registro($pdo, ['elemento_id' => $id['furgo'], 'fecha' => '2026-09-01', 'tipo' => 'Mantenimiento',
                                            'titulo' => 'Aceite y filtros', 'valor' => '150.000', 'coste' => '189,90'], $id['admin']);
    // Facturas del suministro de luz: dos meses de 2026 y uno de 2025; y una
    // incidencia con coste que NO es consumo y no debe entrar en la suma.
    foreach ([['2025-12-09', '70,10'], ['2026-08-09', '80,50'], ['2026-09-09', '100,52']] as [$f, $c]) {
        crear_registro($pdo, ['elemento_id' => $id['luz'], 'fecha' => $f, 'tipo' => 'Factura', 'titulo' => 'Factura luz', 'coste' => $c], $id['admin']);
    }
    crear_registro($pdo, ['elemento_id' => $id['luz'], 'fecha' => '2026-09-10', 'tipo' => 'Incidencia', 'titulo' => 'Cambio de contador', 'coste' => '25'], $id['admin']);
    // Comunidad de propietarios con dos recibos desglosados en 2026 (uno con
    // obra extraordinaria) y uno de 2025 sin desglose. Sin coste en la ficha
    // para no mover el gasto fijo mensual que comprueban otras pruebas.
    $id['comunidad'] = $g('contratos', 'comunidad', ['nombre' => 'Comunidad de prueba', 'enlace_id' => $id['casa'],
        'datos' => ['cuota_participacion' => '8,355', 'cuota_zona' => '21,23', 'analisis' => 'El ascensor es lo más caro en un trimestre normal.']]);
    $recibo = static fn(string $f, string $t, string $c) => crear_registro($pdo, ['elemento_id' => $id['comunidad'], 'fecha' => $f,
        'tipo' => 'Recibo', 'titulo' => $t, 'coste' => $c], $id['admin']);
    $id['recibo_1t'] = $recibo('2026-03-26', 'Comunidad 1T26', '84,78');
    $id['recibo_2t'] = $recibo('2026-06-30', 'Comunidad 2T26', '290,17');
    $id['recibo_4t25'] = $recibo('2025-12-20', 'Comunidad 4T25', '80');
    guardar_partidas($pdo, $id['recibo_1t'], [
        ['concepto' => 'Mantenimiento piscina', 'categoria' => 'Piscina', 'zona' => 'escalera', 'total' => '290,40'],
        ['concepto' => 'Administrador', 'categoria' => 'Administración', 'zona' => 'comun', 'total' => '276,86'],
    ], $id['admin']);
    guardar_partidas($pdo, $id['recibo_2t'], [
        ['concepto' => 'Mantenimiento piscina', 'categoria' => 'Piscina', 'zona' => 'escalera', 'total' => '290,40'],
        ['concepto' => 'Obra fuga de la piscina', 'categoria' => 'Piscina', 'zona' => 'comun', 'total' => '2.735,10', 'extraordinaria' => true],
    ], $id['admin']);
    $id['documento'] = guardar_documento_bytes($pdo, $id['dni'], 'DNI escaneado', 'dni.pdf', "%PDF-1.4\n% prueba\n", $id['admin']);
    return $id;
}
