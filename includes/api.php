<?php
// =====================================================================
//  Lo que hace cada acción de la API (api.php es solo la puerta).
//
//  Es la vía del flujo «te paso el papel y tú lo grabas»: el usuario le da
//  a Claude la póliza, la foto de la ITV o el informe del médico, Claude
//  lo lee y lo graba con remoto.php. Todo pasa por las MISMAS funciones
//  que los formularios (guardar_elemento, crear_vencimiento...), así que
//  valida igual y crea los mismos avisos automáticos.
//
//  Lo grabado por aquí queda en la actividad como hecho por «Claude».
// =====================================================================

function api_ejecutar(PDO $pdo, array $p, ?array $archivo = null): array {
    $accion = (string)($p['accion'] ?? '');
    $datos = [];
    if (isset($p['datos']) && $p['datos'] !== '') {
        $datos = json_decode((string)$p['datos'], true);
        if (!is_array($datos)) throw new RuntimeException('«datos» no es un JSON válido.');
    }

    switch ($accion) {
        case 'estado':
            return api_estado($pdo);

        case 'esquema':
            return ['secciones' => api_esquema(), 'repetir' => opciones_repetir(), 'relaciones' => relaciones(),
                    'partidas_comunidad' => ['categorias' => categorias_comunidad(), 'zonas' => array_map(static fn($z) => $z[0] . ' (coeficiente: ' . $z[1] . ')', zonas_comunidad())]];

        case 'personas':
            return ['personas' => personas($pdo, false)];

        case 'buscar':
            $sec = isset($datos['seccion']) && $datos['seccion'] !== '' ? (string)$datos['seccion'] : null;
            if ($sec !== null && !seccion($sec)) throw new RuntimeException("No existe la sección «{$sec}».");
            $lista = buscar_elementos($pdo, $sec, trim((string)($datos['texto'] ?? '')), !empty($datos['archivados']));
            return ['elementos' => array_map('api_elemento_resumen', $lista)];

        case 'ficha':
            $el = elemento($pdo, (int)($datos['id'] ?? 0));
            if (!$el) throw new RuntimeException('Ese elemento no existe.');
            return ['elemento' => $el,
                    'vencimientos' => agenda($pdo, 3650, null, $el['id']),
                    'registros' => registros_de($pdo, $el['id'], 50),
                    'documentos' => documentos_de($pdo, $el['id'])];

        case 'elemento':
            return api_guardar_elemento($pdo, $datos);

        case 'vencimiento':
            if (!empty($datos['id'])) {
                $actual = vencimiento($pdo, (int)$datos['id']);
                if (!$actual) throw new RuntimeException('Ese aviso no existe.');
                actualizar_vencimiento($pdo, (int)$datos['id'], $datos + [
                    'titulo' => $actual['titulo'], 'fecha' => $actual['fecha'], 'aviso_dias' => $actual['aviso_dias'],
                    'repetir_meses' => $actual['repetir_meses'], 'notas' => $actual['notas']]);
                return ['vencimiento' => vencimiento($pdo, (int)$datos['id'])];
            }
            return ['vencimiento' => vencimiento($pdo, crear_vencimiento($pdo, $datos, null))];

        case 'hecho':
            $id = (int)($datos['id'] ?? 0);
            if (!vencimiento($pdo, $id)) throw new RuntimeException('Ese aviso no existe.');
            return ['siguiente' => marcar_hecho($pdo, $id, null)];

        case 'registro':
            $id = crear_registro($pdo, $datos, null);
            return ['registro_id' => $id, 'elemento' => elemento($pdo, (int)$datos['elemento_id'])];

        case 'partidas':
            // Desglose de un recibo de la comunidad. Sustituye el que hubiera.
            return guardar_partidas($pdo, (int)($datos['registro_id'] ?? 0), (array)($datos['partidas'] ?? []), null);

        case 'comunidad':
            $el = elemento($pdo, (int)($datos['id'] ?? 0));
            if (!$el || $el['seccion'] !== 'contratos' || $el['tipo'] !== 'comunidad') throw new RuntimeException('Ese elemento no es una comunidad de propietarios.');
            return ['elemento' => api_elemento_resumen($el), 'analisis' => analisis_comunidad($pdo, $el['id'])];

        case 'documento':
            if (!$archivo) throw new RuntimeException('Falta el archivo.');
            $id = guardar_documento_bytes($pdo, (int)($datos['elemento_id'] ?? 0), (string)($datos['titulo'] ?? ''),
                $archivo['nombre'], $archivo['contenido'], null);
            return ['documento' => documento($pdo, $id)];

        case 'actividad':
            return ['actividad' => actividad_reciente($pdo, 30)];
    }
    throw new RuntimeException("Acción desconocida «{$accion}». Las que hay: estado, esquema, personas, buscar, ficha, elemento, vencimiento, hecho, registro, partidas, comunidad, documento, actividad.");
}

function api_estado(PDO $pdo): array {
    $simple = static fn(array $v) => [
        'id' => $v['id'], 'fecha' => $v['fecha'], 'dias' => $v['dias'], 'situacion' => $v['situacion'],
        'titulo' => $v['titulo'], 'seccion' => $v['seccion'], 'elemento_id' => $v['elemento_id'],
        'repetir_meses' => $v['repetir_meses'], 'automatico' => $v['automatico'],
    ];
    return [
        'hoy' => hoy(),
        'avisos' => array_map($simple, avisos_activos($pdo)),
        'proximos_90_dias' => array_map($simple, array_values(array_filter(agenda($pdo, 90), static fn($v) => $v['situacion'] === 'futuro'))),
        'elementos_por_seccion' => contar_elementos($pdo),
        'gasto_fijo_mensual' => coste_mensual_total($pdo),
        'vigilancia' => estado_vigilancia($pdo)['texto'],
    ];
}

// Lo que Claude necesita para no inventarse campos: claves, tipos, opciones.
function api_esquema(): array {
    $out = [];
    foreach (secciones() as $k => $s) {
        $tipos = [];
        foreach ($s['tipos'] as $t => $def) {
            $campos = [];
            foreach ($def['campos'] as $c => $cd) {
                $campos[$c] = array_intersect_key($cd, array_flip(['etiqueta', 'tipo', 'opciones', 'unidad', 'vence', 'aviso', 'repetir']));
            }
            $tipos[$t] = ['nombre' => $def['nombre'], 'persona' => $def['persona'] ?? null,
                          'enlace' => isset($def['enlace']) ? $def['enlace']['etiqueta'] . ' (enlace_id de un elemento de: '
                              . implode(', ', array_map(static fn($a) => $a[0] . '/' . $a[1], $def['enlace']['a'])) . ')' : null,
                          'nombre_auto' => $def['nombre_auto'] ?? null, 'campos' => $campos];
        }
        $out[$k] = ['nombre' => $s['nombre'], 'tipos' => $tipos, 'registros' => $s['registros'],
                    'sugerencias' => $s['sugerencias']];
    }
    return $out;
}

function api_elemento_resumen(array $e): array {
    return ['id' => $e['id'], 'seccion' => $e['seccion'], 'tipo' => $e['tipo'], 'nombre' => $e['nombre'],
            'persona' => $e['persona_nombre'], 'persona_id' => $e['persona_id'],
            'enlace_id' => $e['enlace_id'], 'enlace' => $e['enlace_nombre'], 'datos' => $e['datos'], 'activo' => $e['activo']];
}

// Los números del JSON pasan a texto «a la española» (12.5 → "12,5") para
// que leer_numero() no confunda 12.345 con doce mil trescientos.
function api_valor_a_texto($v) {
    if (is_int($v)) return (string)$v;
    if (is_float($v)) return str_replace('.', ',', (string)$v);
    return $v;
}

/**
 * Crea o actualiza. Al actualizar, FUSIONA: los campos que no vienen se
 * conservan y un campo a null se vacía. Así Claude puede decir «solo
 * cambia la caducidad» sin reenviar la ficha entera.
 */
function api_guardar_elemento(PDO $pdo, array $d): array {
    $id = (int)($d['id'] ?? 0) ?: null;
    if ($id) {
        $actual = elemento($pdo, $id);
        if (!$actual) throw new RuntimeException('Ese elemento no existe.');
        $datos = $actual['datos'];
        foreach ((array)($d['datos'] ?? []) as $k => $v) {
            if ($v === null) unset($datos[$k]); else $datos[$k] = $v;
        }
        $entrada = [
            'nombre' => array_key_exists('nombre', $d) ? $d['nombre'] : $actual['nombre'],
            'persona_id' => array_key_exists('persona_id', $d) ? $d['persona_id'] : $actual['persona_id'],
            'enlace_id' => array_key_exists('enlace_id', $d) ? $d['enlace_id'] : $actual['enlace_id'],
            'notas' => array_key_exists('notas', $d) ? $d['notas'] : $actual['notas'],
            'datos' => array_map('api_valor_a_texto', $datos),
        ];
        $id = guardar_elemento($pdo, $actual['seccion'], $actual['tipo'], $entrada, $id, null);
    } else {
        $entrada = $d;
        $entrada['datos'] = array_map('api_valor_a_texto', (array)($d['datos'] ?? []));
        $id = guardar_elemento($pdo, (string)($d['seccion'] ?? ''), (string)($d['tipo'] ?? ''), $entrada, null, null);
    }
    return ['elemento' => elemento($pdo, $id), 'vencimientos' => agenda($pdo, 3650, null, $id)];
}
