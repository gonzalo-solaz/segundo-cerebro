<?php
// =====================================================================
//  LAS SECCIONES DEL SEGUNDO CEREBRO — declaradas, no programadas.
//
//  Este archivo es el corazón de la app. Cada sección define sus tipos de
//  «elemento» (un coche, un DNI, un seguro...) y los campos de cada tipo.
//  De aquí salen SOLOS: los formularios de alta/edición, las fichas, los
//  resúmenes de las tarjetas, la validación, los avisos automáticos y lo
//  que la API le explica a Claude (acción «esquema»).
//
//  Añadir una sección o un tipo nuevo = añadir un bloque aquí. No hace
//  falta SQL: los campos se guardan como JSON en elementos.datos.
//  Lo que NO se puede hacer a la ligera es CAMBIAR la clave de un campo
//  que ya tiene datos (se quedarían huérfanos en el JSON): si hay que
//  renombrar, hace falta una migración que reescriba los datos.
//
//  Campos — claves admitidas:
//    etiqueta   Texto del formulario.
//    tipo       texto | area | numero | importe | fecha | opcion | tel | email
//    opciones   (tipo opcion) Lista cerrada de valores.
//    unidad     Se pinta detrás del valor («km», «m²»).
//    resumen    true = sale en la tarjeta del listado.
//    ayuda      Texto pequeño bajo el campo.
//    vence      (tipo fecha) Crea y mantiene SOLO un aviso con ese título
//               («Renovar el DNI» → «Renovar el DNI · DNI de Ana»). Si el
//               título lleva {nombre}, se sustituye por el nombre del elemento.
//    aviso      Días de antelación con los que avisa (por defecto 30).
//    repetir    Meses tras los que vuelve a tocar cuando se marca hecho
//               (0 = no se repite), o 'periodicidad' para sacarlo del campo
//               periodicidad del propio elemento.
//
//  Tipos — claves admitidas:
//    nombre        Cómo se llama el tipo («Vehículo»).
//    ejemplo       Placeholder del nombre («Furgo California»).
//    persona       null | 'opcional' | 'obligatoria' — a quién pertenece.
//    persona_etiqueta  Texto del selector de persona («Titular», «Conductor habitual»).
//    nombre_auto   Si se deja el nombre en blanco: «DNI de {persona}».
//    campos        Los campos (ver arriba).
//
//  Sección — además: emoji (su «icono de página», como en Notion: es la
//  marca de la sección en el menú, las fichas y la agenda), color (de la
//  paleta de bloques de Notion; el fondo suave se deriva en el CSS),
//  sugerencias de recordatorios típicos (solo rellenan el título y la repetición; la fecha SIEMPRE la pone el usuario: no se
//  inventan fechas) y la configuración del historial (registros).
// =====================================================================

function periodicidades(): array {
    return ['Mensual' => 1, 'Bimestral' => 2, 'Trimestral' => 3, 'Semestral' => 6, 'Anual' => 12];
}

function opciones_repetir(): array {
    return [0 => 'No se repite', 1 => 'Cada mes', 2 => 'Cada 2 meses', 3 => 'Cada 3 meses',
            6 => 'Cada 6 meses', 12 => 'Cada año', 24 => 'Cada 2 años', 36 => 'Cada 3 años',
            48 => 'Cada 4 años', 60 => 'Cada 5 años', 120 => 'Cada 10 años'];
}

function texto_repetir(int $meses): string {
    return opciones_repetir()[$meses] ?? "Cada $meses meses";
}

// Un contacto de confianza (fontanero, taller, médico...) se repite en
// varias secciones con distinto ejemplo.
function tipo_contacto(string $nombre, string $ejemplo, string $oficio): array {
    return [
        'nombre'  => $nombre,
        'ejemplo' => $ejemplo,
        'persona' => null,
        'campos'  => [
            'oficio'   => ['etiqueta' => $oficio, 'tipo' => 'texto', 'resumen' => true],
            'telefono' => ['etiqueta' => 'Teléfono', 'tipo' => 'tel', 'resumen' => true],
            'email'    => ['etiqueta' => 'Email', 'tipo' => 'email'],
            'direccion' => ['etiqueta' => 'Dirección o web', 'tipo' => 'texto'],
        ],
    ];
}

function secciones(): array {
    static $s = null;
    if ($s !== null) return $s;

    $periodicidad = ['etiqueta' => 'Periodicidad del pago', 'tipo' => 'opcion', 'opciones' => array_keys(periodicidades())];
    $coste = ['etiqueta' => 'Coste por pago', 'tipo' => 'importe', 'resumen' => true];

    $s = [
        // -------------------------------------------------------------
        'vivienda' => [
            'nombre' => 'Vivienda', 'icono' => 'casa', 'emoji' => '🏠', 'color' => '#337EA9',
            'descripcion' => 'Casas, instalaciones, garantías y los profesionales de confianza.',
            'tipos' => [
                'inmueble' => [
                    'nombre' => 'Vivienda', 'ejemplo' => 'Casa de Valencia', 'persona' => null,
                    'campos' => [
                        'direccion' => ['etiqueta' => 'Dirección', 'tipo' => 'texto', 'resumen' => true],
                        'regimen' => ['etiqueta' => 'Régimen', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['Propiedad', 'Propiedad con hipoteca', 'Alquiler', 'Otro']],
                        'referencia_catastral' => ['etiqueta' => 'Referencia catastral', 'tipo' => 'texto'],
                        'superficie' => ['etiqueta' => 'Superficie', 'tipo' => 'numero', 'unidad' => 'm²'],
                        'fecha_compra' => ['etiqueta' => 'Fecha de compra o de entrada', 'tipo' => 'fecha'],
                        'fin_hipoteca' => ['etiqueta' => 'Fin de la hipoteca o del contrato de alquiler', 'tipo' => 'fecha',
                            'vence' => 'Fin de hipoteca o alquiler', 'aviso' => 90],
                    ],
                ],
                'equipo' => [
                    'nombre' => 'Electrodoméstico o instalación', 'ejemplo' => 'Caldera', 'persona' => null,
                    'campos' => [
                        'marca' => ['etiqueta' => 'Marca', 'tipo' => 'texto', 'resumen' => true],
                        'modelo' => ['etiqueta' => 'Modelo', 'tipo' => 'texto'],
                        'ubicacion' => ['etiqueta' => 'Dónde está', 'tipo' => 'texto'],
                        'fecha_compra' => ['etiqueta' => 'Fecha de compra', 'tipo' => 'fecha'],
                        'garantia_hasta' => ['etiqueta' => 'Garantía hasta', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Fin de la garantía', 'aviso' => 30],
                    ],
                ],
                'contacto' => tipo_contacto('Contacto de confianza', 'Fontanero de siempre', 'Oficio'),
            ],
            'sugerencias' => [
                ['IBI', 12, 30], ['Seguro de hogar', 12, 45], ['Revisión de la caldera', 12, 30],
                ['Limpieza de filtros del aire acondicionado', 12, 15], ['Inspección periódica del gas', 60, 30],
            ],
            'registros' => ['tipos' => ['Reparación', 'Mejora o reforma', 'Mantenimiento', 'Lectura de contador', 'Incidencia'],
                            'valor' => 'Lectura', 'unidad' => ''],
        ],

        // -------------------------------------------------------------
        'vehiculos' => [
            'nombre' => 'Vehículos', 'icono' => 'coche', 'emoji' => '🚗', 'color' => '#D9730D',
            'descripcion' => 'ITV, revisiones, kilómetros y lo que se ha gastado en cada vehículo.',
            'tipos' => [
                'vehiculo' => [
                    'nombre' => 'Vehículo', 'ejemplo' => 'Furgo California', 'persona' => 'opcional',
                    'persona_etiqueta' => 'Conductor habitual',
                    'campos' => [
                        'marca' => ['etiqueta' => 'Marca', 'tipo' => 'texto'],
                        'modelo' => ['etiqueta' => 'Modelo', 'tipo' => 'texto', 'resumen' => true],
                        'matricula' => ['etiqueta' => 'Matrícula', 'tipo' => 'texto', 'resumen' => true],
                        'anio' => ['etiqueta' => 'Año', 'tipo' => 'numero'],
                        'combustible' => ['etiqueta' => 'Combustible', 'tipo' => 'opcion',
                            'opciones' => ['Gasolina', 'Diésel', 'Híbrido', 'Híbrido enchufable', 'Eléctrico', 'GLP', 'Otro']],
                        'bastidor' => ['etiqueta' => 'Número de bastidor', 'tipo' => 'texto'],
                        'fecha_matriculacion' => ['etiqueta' => 'Fecha de matriculación', 'tipo' => 'fecha'],
                        'km' => ['etiqueta' => 'Kilómetros', 'tipo' => 'numero', 'unidad' => 'km', 'resumen' => true,
                            'ayuda' => 'Se actualiza solo al apuntar un mantenimiento o una lectura con más kilómetros.'],
                        'proxima_itv' => ['etiqueta' => 'Próxima ITV', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Pasar la ITV', 'aviso' => 30],
                        'proxima_revision' => ['etiqueta' => 'Próxima revisión del taller', 'tipo' => 'fecha',
                            'vence' => 'Revisión en el taller', 'aviso' => 21],
                    ],
                ],
                'contacto' => tipo_contacto('Taller o contacto', 'Taller de confianza', 'Especialidad'),
            ],
            'sugerencias' => [
                ['Impuesto de circulación', 12, 30], ['Seguro del vehículo', 12, 45],
                ['Cambio de aceite y filtros', 12, 21], ['Cambio de neumáticos', 0, 15],
            ],
            'registros' => ['tipos' => ['Mantenimiento', 'Reparación', 'Lectura de kilómetros', 'Repostaje', 'Multa', 'Otro'],
                            'valor' => 'Kilómetros', 'unidad' => 'km', 'actualiza' => 'km'],
        ],

        // -------------------------------------------------------------
        'salud' => [
            'nombre' => 'Salud', 'icono' => 'salud', 'emoji' => '🩺', 'color' => '#D44C47',
            'descripcion' => 'Fichas médicas, tratamientos, especialistas e historial de cada persona.',
            'tipos' => [
                'ficha' => [
                    'nombre' => 'Ficha médica', 'ejemplo' => 'Ficha médica', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'De quién es', 'nombre_auto' => 'Ficha médica de {persona}',
                    'campos' => [
                        'grupo_sanguineo' => ['etiqueta' => 'Grupo sanguíneo', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['A+', 'A−', 'B+', 'B−', 'AB+', 'AB−', '0+', '0−']],
                        'alergias' => ['etiqueta' => 'Alergias', 'tipo' => 'area', 'resumen' => true],
                        'medicacion_habitual' => ['etiqueta' => 'Medicación habitual', 'tipo' => 'area'],
                        'condiciones' => ['etiqueta' => 'Enfermedades o condiciones', 'tipo' => 'area'],
                        'tarjeta_sanitaria' => ['etiqueta' => 'Número de tarjeta sanitaria (SIP)', 'tipo' => 'texto'],
                        'centro_salud' => ['etiqueta' => 'Centro de salud', 'tipo' => 'texto'],
                        'medico_cabecera' => ['etiqueta' => 'Médico de cabecera', 'tipo' => 'texto'],
                        'seguro_medico' => ['etiqueta' => 'Seguro médico privado', 'tipo' => 'texto'],
                    ],
                ],
                'tratamiento' => [
                    'nombre' => 'Tratamiento o medicación', 'ejemplo' => 'Ibuprofeno 600', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'Para quién',
                    'campos' => [
                        'pauta' => ['etiqueta' => 'Pauta', 'tipo' => 'texto', 'resumen' => true, 'ayuda' => 'Ej.: 1 cada 8 horas con comida.'],
                        'recetado_por' => ['etiqueta' => 'Recetado por', 'tipo' => 'texto'],
                        'desde' => ['etiqueta' => 'Desde', 'tipo' => 'fecha'],
                        'receta_hasta' => ['etiqueta' => 'La receta caduca', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Renovar la receta', 'aviso' => 10],
                        'hasta' => ['etiqueta' => 'Fin del tratamiento', 'tipo' => 'fecha',
                            'vence' => 'Fin del tratamiento', 'aviso' => 3],
                    ],
                ],
                'profesional' => [
                    'nombre' => 'Médico o especialista', 'ejemplo' => 'Dra. García (dermatóloga)', 'persona' => 'opcional',
                    'persona_etiqueta' => 'Paciente habitual',
                    'campos' => [
                        'especialidad' => ['etiqueta' => 'Especialidad', 'tipo' => 'texto', 'resumen' => true],
                        'centro' => ['etiqueta' => 'Centro o clínica', 'tipo' => 'texto'],
                        'telefono' => ['etiqueta' => 'Teléfono', 'tipo' => 'tel', 'resumen' => true],
                        'email' => ['etiqueta' => 'Email', 'tipo' => 'email'],
                    ],
                ],
            ],
            'sugerencias' => [
                ['Revisión médica anual', 12, 30], ['Dentista', 12, 21], ['Oftalmólogo', 24, 30],
                ['Analítica', 12, 21], ['Vacuna de la gripe', 12, 21],
            ],
            'registros' => ['tipos' => ['Consulta', 'Analítica', 'Prueba', 'Medición', 'Vacuna', 'Urgencia', 'Otro'],
                            'valor' => 'Medida', 'unidad' => ''],
        ],

        // -------------------------------------------------------------
        'documentos' => [
            'nombre' => 'Documentos', 'icono' => 'documento', 'emoji' => '🪪', 'color' => '#9065B0',
            'descripcion' => 'DNI, pasaportes, carnets y tarjetas de cada uno, con su caducidad y su copia.',
            'tipos' => [
                'dni' => [
                    'nombre' => 'DNI', 'ejemplo' => 'DNI', 'persona' => 'obligatoria', 'persona_etiqueta' => 'Titular',
                    'nombre_auto' => 'DNI de {persona}',
                    'campos' => [
                        'numero' => ['etiqueta' => 'Número', 'tipo' => 'texto', 'resumen' => true],
                        'expedicion' => ['etiqueta' => 'Fecha de expedición', 'tipo' => 'fecha'],
                        'caducidad' => ['etiqueta' => 'Caduca', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Renovar el DNI', 'aviso' => 90],
                    ],
                ],
                'pasaporte' => [
                    'nombre' => 'Pasaporte', 'ejemplo' => 'Pasaporte', 'persona' => 'obligatoria', 'persona_etiqueta' => 'Titular',
                    'nombre_auto' => 'Pasaporte de {persona}',
                    'campos' => [
                        'numero' => ['etiqueta' => 'Número', 'tipo' => 'texto', 'resumen' => true],
                        'expedicion' => ['etiqueta' => 'Fecha de expedición', 'tipo' => 'fecha'],
                        'caducidad' => ['etiqueta' => 'Caduca', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Renovar el pasaporte', 'aviso' => 120,
                            'ayuda' => 'Muchos países piden 6 meses de validez: por eso avisa con 4 meses.'],
                    ],
                ],
                'carnet' => [
                    'nombre' => 'Carnet de conducir', 'ejemplo' => 'Carnet de conducir', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'Titular', 'nombre_auto' => 'Carnet de conducir de {persona}',
                    'campos' => [
                        'permisos' => ['etiqueta' => 'Permisos', 'tipo' => 'texto', 'resumen' => true, 'ayuda' => 'Ej.: B, A2'],
                        'numero' => ['etiqueta' => 'Número', 'tipo' => 'texto'],
                        'caducidad' => ['etiqueta' => 'Caduca', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Renovar el carnet de conducir', 'aviso' => 90],
                    ],
                ],
                'otro' => [
                    'nombre' => 'Otro documento o tarjeta', 'ejemplo' => 'Tarjeta sanitaria europea', 'persona' => 'opcional',
                    'persona_etiqueta' => 'Titular',
                    'campos' => [
                        'numero' => ['etiqueta' => 'Número', 'tipo' => 'texto', 'resumen' => true],
                        'caducidad' => ['etiqueta' => 'Caduca', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Renovar', 'aviso' => 60],
                    ],
                ],
            ],
            'sugerencias' => [],
            'registros' => null,
        ],

        // -------------------------------------------------------------
        'contratos' => [
            'nombre' => 'Contratos', 'icono' => 'contrato', 'emoji' => '📑', 'color' => '#448361',
            'descripcion' => 'Suministros, seguros y suscripciones: cuánto cuestan, cuándo renuevan y cuándo acaba la permanencia.',
            'tipos' => [
                'suministro' => [
                    'nombre' => 'Suministro', 'ejemplo' => 'Luz de casa', 'persona' => 'opcional', 'persona_etiqueta' => 'Titular',
                    'campos' => [
                        'categoria' => ['etiqueta' => 'Qué es', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['Luz', 'Gas', 'Agua', 'Internet y fibra', 'Móvil', 'Alarma', 'Otro']],
                        'compania' => ['etiqueta' => 'Compañía', 'tipo' => 'texto', 'resumen' => true],
                        'coste' => $coste,
                        'periodicidad' => $periodicidad,
                        'numero_contrato' => ['etiqueta' => 'Número de contrato o CUPS', 'tipo' => 'texto'],
                        'telefono' => ['etiqueta' => 'Teléfono de atención', 'tipo' => 'tel'],
                        'permanencia_hasta' => ['etiqueta' => 'Permanencia hasta', 'tipo' => 'fecha',
                            'vence' => 'Fin de la permanencia', 'aviso' => 30],
                        'revision_precio' => ['etiqueta' => 'Próxima revisión de precio', 'tipo' => 'fecha',
                            'vence' => 'Revisar precio', 'aviso' => 30, 'repetir' => 12],
                    ],
                ],
                'seguro' => [
                    'nombre' => 'Seguro', 'ejemplo' => 'Seguro de hogar', 'persona' => 'opcional', 'persona_etiqueta' => 'Tomador',
                    'campos' => [
                        'ramo' => ['etiqueta' => 'Tipo de seguro', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['Hogar', 'Coche o moto', 'Salud', 'Vida', 'Decesos', 'Viaje', 'Otro']],
                        'compania' => ['etiqueta' => 'Compañía', 'tipo' => 'texto', 'resumen' => true],
                        'numero_poliza' => ['etiqueta' => 'Número de póliza', 'tipo' => 'texto'],
                        'coste' => $coste,
                        'periodicidad' => $periodicidad,
                        'renovacion' => ['etiqueta' => 'Renovación', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Renovación del seguro', 'aviso' => 45, 'repetir' => 12,
                            'ayuda' => 'Avisa con 45 días: para cambiar de compañía suele haber que avisar con un mes.'],
                        'telefono_asistencia' => ['etiqueta' => 'Teléfono de asistencia', 'tipo' => 'tel'],
                    ],
                ],
                'suscripcion' => [
                    'nombre' => 'Suscripción', 'ejemplo' => 'Netflix', 'persona' => 'opcional', 'persona_etiqueta' => 'A nombre de',
                    'campos' => [
                        'coste' => $coste,
                        'periodicidad' => $periodicidad,
                        'renovacion' => ['etiqueta' => 'Próxima renovación', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Se renueva', 'aviso' => 7, 'repetir' => 'periodicidad'],
                        'cuenta' => ['etiqueta' => 'Cuenta o email de acceso', 'tipo' => 'texto'],
                    ],
                ],
            ],
            'sugerencias' => [['Comparar tarifas de luz y gas', 12, 15]],
            'registros' => ['tipos' => ['Incidencia', 'Cambio de tarifa', 'Reclamación', 'Parte al seguro', 'Otro'],
                            'valor' => null, 'unidad' => ''],
        ],

        // -------------------------------------------------------------
        'familia' => [
            'nombre' => 'Familia', 'icono' => 'familia', 'emoji' => '👨‍👩‍👧', 'color' => '#C14C8A',
            'descripcion' => 'Colegio, actividades y fechas importantes de cada uno.',
            'tipos' => [
                'colegio' => [
                    'nombre' => 'Colegio o estudios', 'ejemplo' => 'Colegio San José', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'Alumno',
                    'campos' => [
                        'curso' => ['etiqueta' => 'Curso', 'tipo' => 'texto', 'resumen' => true],
                        'tutor' => ['etiqueta' => 'Tutor o tutora', 'tipo' => 'texto', 'resumen' => true],
                        'horario' => ['etiqueta' => 'Horario', 'tipo' => 'texto'],
                        'telefono' => ['etiqueta' => 'Teléfono', 'tipo' => 'tel'],
                        'email' => ['etiqueta' => 'Email', 'tipo' => 'email'],
                    ],
                ],
                'actividad' => [
                    'nombre' => 'Actividad extraescolar', 'ejemplo' => 'Natación', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'Quién va',
                    'campos' => [
                        'horario' => ['etiqueta' => 'Días y horario', 'tipo' => 'texto', 'resumen' => true],
                        'lugar' => ['etiqueta' => 'Dónde', 'tipo' => 'texto'],
                        'coste' => $coste,
                        'periodicidad' => $periodicidad,
                        'contacto' => ['etiqueta' => 'Contacto (monitor, club...)', 'tipo' => 'texto'],
                    ],
                ],
                'fecha' => [
                    'nombre' => 'Fecha importante', 'ejemplo' => 'Cumpleaños de la abuela', 'persona' => 'opcional',
                    'persona_etiqueta' => 'De quién',
                    'campos' => [
                        'fecha' => ['etiqueta' => 'Próxima vez', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => '{nombre}', 'aviso' => 14, 'repetir' => 12],
                    ],
                ],
            ],
            'sugerencias' => [
                ['Matrícula del colegio', 12, 30], ['Libros y material escolar', 12, 30], ['Revisión del pediatra', 12, 21],
            ],
            'registros' => ['tipos' => ['Tutoría', 'Notas', 'Incidencia', 'Logro', 'Otro'],
                            'valor' => 'Nota', 'unidad' => ''],
        ],
    ];
    return $s;
}

function seccion(string $clave): ?array {
    return secciones()[$clave] ?? null;
}

function tipo_def(string $seccion, string $tipo): ?array {
    return secciones()[$seccion]['tipos'][$tipo] ?? null;
}
