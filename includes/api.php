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
            $ficha = ['elemento' => $el,
                      'vencimientos' => agenda($pdo, 3650, null, $el['id']),
                      'registros' => registros_de($pdo, $el['id'], 50),
                      'documentos' => documentos_de($pdo, $el['id'])];
            if (lleva_plan($el)) $ficha['plan_desarrollo'] = plan_de($pdo, $el['id']);
            return $ficha;

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

        case 'gastos':
            // A dónde va el gasto fijo (lo de gastos-fijos.php, sin finanzas):
            // partidas, cosas, calendario de 12 meses y «Qué revisar». Con
            // persona_id, por lo que paga esa persona («tuyo»); sin él, la casa.
            $an = analisis_gastos_fijos(elementos_con_coste($pdo), historial_de_gastos($pdo, hoy()), hoy(), (int)($datos['persona_id'] ?? 0) ?: null, precios_por_elemento($pdo));
            $quitar = static fn(array $i): array => array_diff_key($i, ['datos' => 0]);
            $an['items'] = array_map($quitar, $an['items']);
            foreach ($an['partidas'] as &$p) $p['items'] = array_map($quitar, $p['items']);
            unset($p);
            return $an;

        case 'plan':
            // Plan de desarrollo de una persona del equipo (o del empleo): un curso, con su objetivo,
            // descripcion, niveles (de 0 a 4), autoevaluacion y nota (sobre 10). Si el curso ya existe,
            // cambia solo lo que viene; blanco = borrar ese campo.
            $eid = (int)($datos['elemento_id'] ?? 0);
            $id = guardar_plan($pdo, $eid, $datos, null);
            return ['plan_id' => $id, 'plan_desarrollo' => plan_de($pdo, $eid)];

        case 'precio':
            // Lo que costaba un contrato desde una fecha (para comparar con hace un año):
            // las primas de años anteriores, la renta del garaje que sale en finanzas…
            $id = guardar_precio($pdo, (int)($datos['elemento_id'] ?? 0), (string)($datos['desde'] ?? ''), $datos['coste'] ?? null,
                isset($datos['periodicidad']) ? (string)$datos['periodicidad'] : null, (string)($datos['nota'] ?? ''), null);
            return ['precio_id' => $id, 'precios' => precios_por_elemento($pdo)[(int)($datos['elemento_id'] ?? 0)] ?? []];

        case 'peso':
            // Números del control de peso (para poner al día el plan). Los
            // pesajes se graban con «registro» (tipo Peso, Cintura o Grasa corporal).
            $el = elemento($pdo, (int)($datos['id'] ?? 0));
            if (!$el || $el['seccion'] !== 'salud' || $el['tipo'] !== 'peso') throw new RuntimeException('Ese elemento no es un control de peso.');
            $an = analisis_peso($pdo, $el);
            unset($an['tendencia']);
            $an['mediciones'] = array_slice($an['mediciones'], -60, null, true);
            return ['elemento' => api_elemento_resumen($el), 'analisis' => $an];

        case 'documento':
            if (!$archivo) throw new RuntimeException('Falta el archivo.');
            $id = guardar_documento_bytes($pdo, (int)($datos['elemento_id'] ?? 0), (string)($datos['titulo'] ?? ''),
                $archivo['nombre'], $archivo['contenido'], null);
            return ['documento' => documento($pdo, $id)];

        case 'actividad':
            return ['actividad' => actividad_reciente($pdo, 30)];

        case 'conexiones':
            return api_conexiones();

        case 'fichas':
            // Para finanzas (origen único de los datos): varias fichas de una vez,
            // con su historial y, si tienen titular, su fecha de nacimiento.
            $out = [];
            foreach (array_slice(array_map('intval', (array)($datos['ids'] ?? [])), 0, 50) as $fid) {
                $el = elemento($pdo, $fid);
                if (!$el) continue;
                $p = $el['persona_id'] ? persona($pdo, (int)$el['persona_id']) : null;
                $out[(string)$fid] = $el + ['registros' => registros_de($pdo, $fid, 50),
                                            'persona_nacimiento' => $p['fecha_nacimiento'] ?? null];
            }
            return ['fichas' => $out];
    }
    throw new RuntimeException("Acción desconocida «{$accion}». Las que hay: estado, esquema, personas, buscar, ficha, elemento, vencimiento, hecho, registro, partidas, comunidad, peso, documento, actividad, conexiones, fichas.");
}

/**
 * Comprueba de una vez todas las claves entre las dos apps, sin enseñar
 * ninguna: aquí → finanzas con FINANZAS_API_CLAVE, llevando un pase firmado
 * con PASE_CLAVE que finanzas verifica con la suya; y finanzas → aquí con
 * su CEREBRO_API_CLAVE. Solo dice si cada una vale.
 */
function api_conexiones(): array {
    $out = ['pase_clave' => PASE_CLAVE !== '' ? 'configurada' : 'FALTA', 'finanzas_api_clave' => null, 'finanzas' => null];
    if (!finanzas_configurada()) {
        $out['finanzas_api_clave'] = 'FALTA';
        return $out;
    }
    try {
        $r = finanzas_pedir(['accion' => 'conexiones', 'pase' => PASE_CLAVE !== '' ? pase_crear(PASE_CLAVE, ['t' => 'prueba']) : '']);
        unset($r['ok']);
        $out['finanzas_api_clave'] = 'ok';
        $out['finanzas'] = $r;
    } catch (RuntimeException $ex) {
        $out['finanzas_api_clave'] = $ex->getMessage();
    }
    return $out;
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
