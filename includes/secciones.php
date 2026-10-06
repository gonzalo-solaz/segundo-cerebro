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
//    anio       (tipo numero) Es un año: sin punto de miles («2015», no «2.015»).
//    resumen    true = sale en la tarjeta del listado.
//    ayuda      Texto pequeño bajo el campo.
//    aparte     (tipo area) Va en su propia tarjeta plegada, fuera de «Datos».
//    lista      (con aparte) Se pinta como lista: una línea = un punto; una línea
//               que acaba en «:» abre un grupo; «Etiqueta: valor» pone la etiqueta
//               en negrita (ver lista_campo() en funciones.php).
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
//    enlace        ['etiqueta' => 'Vivienda', 'a' => [['vivienda', 'inmueble']]]:
//                  a qué elemento (de qué sección y tipo) puede pertenecer este.
//                  La ficha del elemento «padre» lista a sus hijos (los
//                  contratos de la casa, el seguro del coche).
//    campos        Los campos (ver arriba).
//
//  Sección — además: sugerencias de recordatorios típicos (solo rellenan el
//  título y la repetición; la fecha SIEMPRE la pone el usuario: no se
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

// Factor por el que se multiplica el metabolismo basal para el gasto diario.
function niveles_actividad(): array {
    return ['Sedentaria (casi sin ejercicio)' => 1.2, 'Ligera (1-3 días por semana)' => 1.375,
            'Moderada (3-5 días por semana)' => 1.55, 'Alta (6-7 días por semana)' => 1.725,
            'Muy alta (trabajo físico o dos sesiones al día)' => 1.9];
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
    // Lo que se paga a medias (4/10/2026, Gonzalo: «la hipoteca el 60 %; suministros, seguro de
    // hogar y comunidad, el 50 % con Pilar»). gastos-fijos.php y el panel enseñan la parte de
    // quien mira: la del titular si es él; el resto si el titular es otro; sin titular, este %.
    $parte = ['etiqueta' => 'Parte que paga el titular', 'tipo' => 'numero', 'unidad' => '%',
              'ayuda' => 'Si lo pagáis entre dos: el % que paga el titular (sin titular, el tuyo). Vacío = lo paga entero.'];
    // En las actividades, la persona es quien va (un hijo), no quien paga.
    $parte_tuya = ['etiqueta' => 'Parte que pagas tú', 'tipo' => 'numero', 'unidad' => '%',
                   'ayuda' => 'Si lo pagáis entre dos: el % que pagas tú. Vacío = lo pagas entero.'];
    $horario = ['etiqueta' => 'Horario', 'tipo' => 'area', 'aparte' => true, 'lista' => true,
                'ayuda' => 'Una línea por día: «Lunes: 8:00-14:00 y 15:00-17:30». Otra con «Desde: 17/11/2025» si quieres.'];

    $s = [
        // -------------------------------------------------------------
        'vivienda' => [
            'nombre' => 'Vivienda', 'icono' => 'casa', 'color' => '#2c6fae',
            'descripcion' => 'Casas, instalaciones, garantías y los profesionales de confianza.',
            'tipos' => [
                // Origen único (3/10/2026): lo que es de la casa vive aquí y finanzas lo
                // lee (dirección, catastro, compra, titularidad); el valor de mercado y la
                // deuda pendiente viven en finanzas. La compra es un apunte «Compra o venta».
                'inmueble' => [
                    'nombre' => 'Vivienda', 'ejemplo' => 'Casa de Valencia', 'persona' => 'opcional', 'persona_etiqueta' => 'Titular',
                    'campos' => [
                        'direccion' => ['etiqueta' => 'Dirección', 'tipo' => 'texto', 'resumen' => true],
                        'regimen' => ['etiqueta' => 'Régimen', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['Propiedad', 'Propiedad con hipoteca', 'Alquiler', 'Otro']],
                        'referencia_catastral' => ['etiqueta' => 'Referencia catastral', 'tipo' => 'texto'],
                        'superficie' => ['etiqueta' => 'Superficie construida', 'tipo' => 'numero', 'unidad' => 'm²'],
                        'anio_construccion' => ['etiqueta' => 'Año de construcción', 'tipo' => 'numero', 'anio' => true],
                        'fecha_compra' => ['etiqueta' => 'Fecha de compra o de entrada', 'tipo' => 'fecha'],
                        'porcentaje_propiedad' => ['etiqueta' => 'Parte del titular', 'tipo' => 'numero', 'unidad' => '%',
                            'ayuda' => 'La parte de la propiedad del titular de arriba. Finanzas la usa para el patrimonio.'],
                        'copropietarios' => ['etiqueta' => 'Copropietarios', 'tipo' => 'texto', 'ayuda' => 'Ej.: Pilar García Giner (40 %).'],
                        'wifi' => ['etiqueta' => 'Contraseña del WiFi', 'tipo' => 'texto'],
                        'fin_hipoteca' => ['etiqueta' => 'Fin del contrato de alquiler', 'tipo' => 'fecha',
                            'vence' => 'Fin de hipoteca o alquiler', 'aviso' => 90,
                            'ayuda' => 'Si hay hipoteca, va en su propio contrato (Contratos → Hipoteca), enlazado a la casa.'],
                        'escritura' => ['etiqueta' => 'Escritura y registro', 'tipo' => 'area', 'aparte' => true, 'lista' => true,
                            'ayuda' => 'Lo que dice la escritura: notaría y protocolo, vendedor, registro y finca, descripción, superficies, servidumbres. Una línea por punto.'],
                    ],
                ],
                'equipo' => [
                    'nombre' => 'Equipamiento o material', 'ejemplo' => 'Caldera', 'persona' => null,
                    'enlace' => ['etiqueta' => 'Vivienda', 'a' => [['vivienda', 'inmueble']]],
                    'campos' => [
                        'marca' => ['etiqueta' => 'Marca', 'tipo' => 'texto', 'resumen' => true],
                        'modelo' => ['etiqueta' => 'Modelo o referencia', 'tipo' => 'texto', 'resumen' => true],
                        'ubicacion' => ['etiqueta' => 'Dónde está', 'tipo' => 'texto'],
                        'fecha_compra' => ['etiqueta' => 'Fecha de compra', 'tipo' => 'fecha'],
                        'garantia_hasta' => ['etiqueta' => 'Garantía hasta', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Fin de la garantía', 'aviso' => 30],
                    ],
                ],
                'contacto' => tipo_contacto('Contacto de confianza', 'Fontanero de siempre', 'Oficio'),
            ],
            'sugerencias' => [
                ['Seguro de hogar', 12, 45], ['Revisión de la caldera', 12, 30],
                ['Limpieza de filtros del aire acondicionado', 12, 15], ['Inspección periódica del gas', 60, 30],
            ],
            'registros' => ['tipos' => ['Reparación', 'Mejora o reforma', 'Mantenimiento', 'Lectura de contador', 'Incidencia', 'Compra o venta'],
                            'valor' => 'Lectura', 'unidad' => ''],
        ],

        // -------------------------------------------------------------
        'vehiculos' => [
            'nombre' => 'Vehículos', 'icono' => 'coche', 'color' => '#b0761a',
            'descripcion' => 'ITV, revisiones, kilómetros y lo que se ha gastado en cada vehículo.',
            'tipos' => [
                'vehiculo' => [
                    'nombre' => 'Vehículo', 'ejemplo' => 'Furgo California', 'persona' => 'opcional',
                    'persona_etiqueta' => 'Conductor habitual',
                    'campos' => [
                        'marca' => ['etiqueta' => 'Marca', 'tipo' => 'texto'],
                        'modelo' => ['etiqueta' => 'Modelo', 'tipo' => 'texto', 'resumen' => true],
                        'matricula' => ['etiqueta' => 'Matrícula', 'tipo' => 'texto', 'resumen' => true],
                        'anio' => ['etiqueta' => 'Año', 'tipo' => 'numero', 'anio' => true],
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
                        // Plegados (3/10/2026): las notas de los coches acabaron siendo un muro de texto.
                        // Las notas quedan para lo breve (avería pendiente, baja temporal...).
                        'equipamiento' => ['etiqueta' => 'Equipamiento y componentes', 'tipo' => 'area', 'aparte' => true, 'lista' => true,
                            'ayuda' => 'Lo que lleva el coche: motor, caja, ruedas y neumáticos, batería, extras. Una línea por punto; una línea que acaba en «:» abre un grupo.'],
                        'recambios' => ['etiqueta' => 'Recambios y mantenimiento', 'tipo' => 'area', 'aparte' => true, 'lista' => true,
                            'ayuda' => 'Lo que se usa para mantenerlo: aceite, referencias de filtros y frenos, plan de mantenimiento, defectos a vigilar. Una línea por punto.'],
                        'origen' => ['etiqueta' => 'Origen e historia', 'tipo' => 'area', 'aparte' => true, 'lista' => true,
                            'ayuda' => 'Procedencia, propietarios, compra, papeles, seguros anteriores. Una línea por punto.'],
                    ],
                ],
                'contacto' => tipo_contacto('Taller o contacto', 'Taller de confianza', 'Especialidad'),
            ],
            'sugerencias' => [
                ['Seguro del vehículo', 12, 45],
                ['Cambio de aceite y filtros', 12, 21], ['Cambio de neumáticos', 0, 15],
            ],
            'registros' => ['tipos' => ['Mantenimiento', 'Reparación', 'Lectura de kilómetros', 'Repostaje', 'Multa', 'Otro'],
                            'valor' => 'Kilómetros', 'unidad' => 'km', 'actualiza' => 'km'],
        ],

        // -------------------------------------------------------------
        'salud' => [
            'nombre' => 'Salud', 'icono' => 'salud', 'color' => '#c4453c',
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
                'gafas' => [
                    'nombre' => 'Graduación de gafas', 'ejemplo' => 'Graduación 2026', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'De quién es',
                    'campos' => [
                        'fecha' => ['etiqueta' => 'Fecha de la revisión', 'tipo' => 'fecha', 'resumen' => true],
                        'centro' => ['etiqueta' => 'Óptica o clínica', 'tipo' => 'texto'],
                        'esf_od' => ['etiqueta' => 'Ojo derecho · Esfera', 'tipo' => 'numero', 'unidad' => 'D', 'resumen' => true],
                        'cil_od' => ['etiqueta' => 'Ojo derecho · Cilindro', 'tipo' => 'numero', 'unidad' => 'D'],
                        'eje_od' => ['etiqueta' => 'Ojo derecho · Eje', 'tipo' => 'numero', 'unidad' => '°'],
                        'add_od' => ['etiqueta' => 'Ojo derecho · Adición', 'tipo' => 'numero', 'unidad' => 'D'],
                        'esf_oi' => ['etiqueta' => 'Ojo izquierdo · Esfera', 'tipo' => 'numero', 'unidad' => 'D', 'resumen' => true],
                        'cil_oi' => ['etiqueta' => 'Ojo izquierdo · Cilindro', 'tipo' => 'numero', 'unidad' => 'D'],
                        'eje_oi' => ['etiqueta' => 'Ojo izquierdo · Eje', 'tipo' => 'numero', 'unidad' => '°'],
                        'add_oi' => ['etiqueta' => 'Ojo izquierdo · Adición', 'tipo' => 'numero', 'unidad' => 'D'],
                        'dip' => ['etiqueta' => 'Distancia interpupilar', 'tipo' => 'numero', 'unidad' => 'mm'],
                        'proxima_revision' => ['etiqueta' => 'Próxima revisión', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Revisar la vista', 'aviso' => 30],
                    ],
                ],
                // Los pesajes son apuntes del historial (tipos Peso, Cintura y
                // Grasa corporal); peso.php saca de ellos el IMC, la evolución y
                // las pautas. Altura, sexo y actividad son para esos cálculos.
                'peso' => [
                    'nombre' => 'Control de peso', 'ejemplo' => 'Peso', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'De quién es', 'nombre_auto' => 'Peso de {persona}',
                    'campos' => [
                        'altura' => ['etiqueta' => 'Altura', 'tipo' => 'numero', 'unidad' => 'cm', 'resumen' => true,
                            'ayuda' => 'Para el IMC. En centímetros: 178.'],
                        'sexo' => ['etiqueta' => 'Sexo', 'tipo' => 'opcion', 'opciones' => ['Hombre', 'Mujer'],
                            'ayuda' => 'Cambia el gasto de calorías y los límites sanos de la cintura.'],
                        'actividad' => ['etiqueta' => 'Actividad física habitual', 'tipo' => 'opcion',
                            'opciones' => array_keys(niveles_actividad())],
                        'peso_objetivo' => ['etiqueta' => 'Peso objetivo', 'tipo' => 'numero', 'unidad' => 'kg', 'resumen' => true],
                        'fecha_objetivo' => ['etiqueta' => 'Revisar el objetivo el', 'tipo' => 'fecha',
                            'vence' => 'Revisar el objetivo de peso', 'aviso' => 7,
                            'ayuda' => 'Un punto de control (dentro de 3 meses, por ejemplo) para ver cómo va y ajustar.'],
                        'plan' => ['etiqueta' => 'Plan y pautas personales', 'tipo' => 'area', 'aparte' => true,
                            'ayuda' => 'Lo acordado con el médico o el nutricionista, y lo que Claude ponga al día en cada revisión.'],
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
            'registros' => ['tipos' => ['Consulta', 'Analítica', 'Prueba', 'Medición', 'Peso', 'Cintura', 'Grasa corporal',
                                        'Vacuna', 'Urgencia', 'Otro'],
                            'valor' => 'Medida', 'unidad' => '',
                            'unidades' => ['Peso' => 'kg', 'Cintura' => 'cm', 'Grasa corporal' => '%']],
        ],

        // -------------------------------------------------------------
        'documentos' => [
            'nombre' => 'Documentos', 'icono' => 'documento', 'color' => '#6c56b0',
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
                'tarjeta_sanitaria' => [
                    'nombre' => 'Tarjeta sanitaria (SIP)', 'ejemplo' => 'Tarjeta sanitaria', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'Titular', 'nombre_auto' => 'Tarjeta sanitaria de {persona}',
                    'campos' => [
                        'numero' => ['etiqueta' => 'Número SIP', 'tipo' => 'texto', 'resumen' => true],
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
            'nombre' => 'Contratos', 'icono' => 'contrato', 'color' => '#1f8a64',
            'descripcion' => 'Suministros, seguros, comunidad y suscripciones: cuánto cuestan, cuándo renuevan y cuándo acaba la permanencia.',
            'tipos' => [
                'suministro' => [
                    'nombre' => 'Suministro', 'ejemplo' => 'Luz de casa', 'persona' => 'opcional', 'persona_etiqueta' => 'Titular',
                    'enlace' => ['etiqueta' => 'Vivienda', 'a' => [['vivienda', 'inmueble']]],
                    'campos' => [
                        'categoria' => ['etiqueta' => 'Qué es', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['Luz', 'Gas', 'Agua', 'Internet y fibra', 'Móvil', 'Alarma', 'Otro']],
                        'compania' => ['etiqueta' => 'Compañía', 'tipo' => 'texto', 'resumen' => true],
                        'coste' => $coste,
                        'periodicidad' => $periodicidad,
                        'porcentaje_pago' => $parte,
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
                    'enlace' => ['etiqueta' => 'Vivienda o vehículo asegurado', 'a' => [['vivienda', 'inmueble'], ['vehiculos', 'vehiculo']]],
                    'campos' => [
                        'ramo' => ['etiqueta' => 'Tipo de seguro', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['Hogar', 'Coche o moto', 'Salud', 'Vida', 'Decesos', 'Viaje', 'Otro']],
                        'compania' => ['etiqueta' => 'Compañía', 'tipo' => 'texto', 'resumen' => true],
                        'numero_poliza' => ['etiqueta' => 'Número de póliza', 'tipo' => 'texto'],
                        'coste' => $coste,
                        'periodicidad' => $periodicidad,
                        'porcentaje_pago' => $parte,
                        'renovacion' => ['etiqueta' => 'Renovación', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Renovación del seguro', 'aviso' => 45, 'repetir' => 12,
                            'ayuda' => 'Avisa con 45 días: para cambiar de compañía suele haber que avisar con un mes.'],
                        'telefono_asistencia' => ['etiqueta' => 'Teléfono de asistencia', 'tipo' => 'tel'],
                    ],
                ],
                // Los términos de la hipoteca viven aquí (y su cuota cuenta en el gasto
                // fijo); lo que se va pagando —capital pendiente, cuotas pagadas— vive
                // en finanzas, que lee estos términos (3/10/2026).
                'hipoteca' => [
                    'nombre' => 'Hipoteca o préstamo', 'ejemplo' => 'Hipoteca de casa', 'persona' => 'opcional', 'persona_etiqueta' => 'Titular',
                    'enlace' => ['etiqueta' => 'Vivienda', 'a' => [['vivienda', 'inmueble']]],
                    'campos' => [
                        'compania' => ['etiqueta' => 'Entidad', 'tipo' => 'texto', 'resumen' => true],
                        'numero_contrato' => ['etiqueta' => 'Número de contrato', 'tipo' => 'texto'],
                        'coste' => ['etiqueta' => 'Cuota', 'tipo' => 'importe', 'resumen' => true, 'ayuda' => 'La cuota entera, aunque se pague a medias.'],
                        'periodicidad' => $periodicidad,
                        'capital_inicial' => ['etiqueta' => 'Capital prestado', 'tipo' => 'importe'],
                        'interes' => ['etiqueta' => 'Tipo de interés', 'tipo' => 'texto', 'ayuda' => 'Ej.: variable, Euríbor + 0,99 (TAE 3,52 %).'],
                        'revision_interes' => ['etiqueta' => 'Revisión del interés', 'tipo' => 'opcion',
                            'opciones' => ['Fijo', 'Mensual', 'Trimestral', 'Semestral', 'Anual']],
                        'cuotas_totales' => ['etiqueta' => 'Número de cuotas', 'tipo' => 'numero'],
                        'fecha_inicio' => ['etiqueta' => 'Firma', 'tipo' => 'fecha'],
                        'fecha_fin' => ['etiqueta' => 'Última cuota', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Fin de la hipoteca', 'aviso' => 90],
                        'titulares' => ['etiqueta' => 'Titulares', 'tipo' => 'texto'],
                        'porcentaje_pago' => ['etiqueta' => 'Parte de la cuota que paga el titular', 'tipo' => 'numero', 'unidad' => '%'],
                    ],
                ],
                // Cada liquidación trimestral se apunta en el historial como «Recibo»
                // con lo que paga esta casa (no el total de la comunidad).
                'comunidad' => [
                    'nombre' => 'Comunidad de propietarios', 'ejemplo' => 'Comunidad de José Vilella 7', 'persona' => 'opcional',
                    'persona_etiqueta' => 'Propietario',
                    'enlace' => ['etiqueta' => 'Vivienda', 'a' => [['vivienda', 'inmueble']]],
                    'campos' => [
                        'administrador' => ['etiqueta' => 'Administrador de fincas', 'tipo' => 'texto', 'resumen' => true],
                        'telefono' => ['etiqueta' => 'Teléfono del administrador', 'tipo' => 'tel'],
                        'email' => ['etiqueta' => 'Email del administrador', 'tipo' => 'email'],
                        'coste' => $coste,
                        'periodicidad' => $periodicidad,
                        'porcentaje_pago' => $parte,
                        'piso' => ['etiqueta' => 'Piso y escalera', 'tipo' => 'texto', 'ayuda' => 'Como sale en la liquidación. Ej.: 2º-6ª, escalera A.'],
                        'cuota_participacion' => ['etiqueta' => 'Cuota de participación en el edificio', 'tipo' => 'numero', 'unidad' => '%'],
                        'cuota_zona' => ['etiqueta' => 'Cuota en su escalera o zona', 'tipo' => 'numero', 'unidad' => '%',
                            'ayuda' => 'La «recalculada por zonas»: la que se aplica a los gastos de la escalera (ascensor, limpieza…).'],
                        'analisis' => ['etiqueta' => 'Análisis y preguntas para la junta', 'tipo' => 'area', 'aparte' => true,
                            'ayuda' => 'Las conclusiones de «Gasto en comunidad». Claude lo pone al día con cada liquidación.'],
                    ],
                ],
                'suscripcion' => [
                    'nombre' => 'Suscripción', 'ejemplo' => 'Netflix', 'persona' => 'opcional', 'persona_etiqueta' => 'A nombre de',
                    'campos' => [
                        'coste' => $coste,
                        'periodicidad' => $periodicidad,
                        'porcentaje_pago' => $parte,
                        'renovacion' => ['etiqueta' => 'Próxima renovación', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Se renueva', 'aviso' => 7, 'repetir' => 'periodicidad'],
                        'cuenta' => ['etiqueta' => 'Cuenta o email de acceso', 'tipo' => 'texto'],
                    ],
                ],
                // Una plaza de garaje, un trastero, un local o la vivienda (4/10/2026: el garaje de
                // Gonzalo, que sale en finanzas cada mes y aquí no contaba).
                'alquiler' => [
                    'nombre' => 'Alquiler', 'ejemplo' => 'Plaza de garaje', 'persona' => 'opcional', 'persona_etiqueta' => 'Inquilino',
                    'enlace' => ['etiqueta' => 'Vivienda', 'a' => [['vivienda', 'inmueble']]],
                    'campos' => [
                        'que' => ['etiqueta' => 'Qué se alquila', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['Garaje', 'Trastero', 'Vivienda', 'Local', 'Otro']],
                        'arrendador' => ['etiqueta' => 'Arrendador', 'tipo' => 'texto', 'resumen' => true],
                        'direccion' => ['etiqueta' => 'Dirección', 'tipo' => 'texto'],
                        'coste' => ['etiqueta' => 'Renta', 'tipo' => 'importe', 'resumen' => true],
                        'periodicidad' => $periodicidad,
                        'porcentaje_pago' => $parte,
                        'fianza' => ['etiqueta' => 'Fianza', 'tipo' => 'importe'],
                        'fecha_inicio' => ['etiqueta' => 'Desde', 'tipo' => 'fecha'],
                        'fin_contrato' => ['etiqueta' => 'Fin del contrato', 'tipo' => 'fecha',
                            'vence' => 'Fin del alquiler', 'aviso' => 60],
                        'revision_precio' => ['etiqueta' => 'Próxima revisión de la renta', 'tipo' => 'fecha',
                            'vence' => 'Revisión de la renta', 'aviso' => 30, 'repetir' => 12,
                            'ayuda' => 'Suele ser anual, con el IPC: comprueba que la subida es la que toca.'],
                        'telefono' => ['etiqueta' => 'Teléfono del arrendador', 'tipo' => 'tel'],
                    ],
                ],
                // El impuesto de circulación (IVTM) de un vehículo y el IBI de una vivienda
                // (4/10/2026, petición de Gonzalo). Cada año se apunta el recibo en el historial
                // como «Recibo» y se pone la fecha del siguiente pago; «hecho» la salta un año.
                // La clave `renovacion` es la que usa el calendario del gasto fijo para saber cuándo
                // se cobra (includes/gastos.php): no renombrarla.
                'impuesto' => [
                    'nombre' => 'Impuesto o tasa', 'ejemplo' => 'IBI de la casa', 'persona' => 'opcional', 'persona_etiqueta' => 'Contribuyente',
                    'enlace' => ['etiqueta' => 'Vivienda o vehículo', 'a' => [['vivienda', 'inmueble'], ['vehiculos', 'vehiculo']]],
                    'campos' => [
                        'impuesto' => ['etiqueta' => 'Qué impuesto es', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['IBI', 'Impuesto de circulación', 'Tasa de basuras', 'Otro']],
                        'ayuntamiento' => ['etiqueta' => 'Ayuntamiento', 'tipo' => 'texto', 'resumen' => true],
                        'referencia' => ['etiqueta' => 'Referencia catastral o matrícula', 'tipo' => 'texto'],
                        'coste' => ['etiqueta' => 'Importe del recibo', 'tipo' => 'importe', 'resumen' => true],
                        'periodicidad' => $periodicidad,
                        'porcentaje_pago' => $parte,
                        'domiciliado' => ['etiqueta' => 'Domiciliado', 'tipo' => 'opcion', 'opciones' => ['Sí', 'No'],
                            'ayuda' => 'Con «Sí» se cobra solo en la cuenta; con «No» hay que pagarlo en el plazo voluntario.'],
                        'renovacion' => ['etiqueta' => 'Próximo pago', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Pagar el impuesto', 'aviso' => 15, 'repetir' => 12,
                            'ayuda' => 'El día del cargo en cuenta o el último del plazo voluntario, como diga el recibo o la carta del ayuntamiento. Si aún no ha llegado, déjalo vacío.'],
                        'bonificaciones' => ['etiqueta' => 'Bonificaciones o exenciones', 'tipo' => 'texto',
                            'ayuda' => 'Familia numerosa, vehículo histórico o eléctrico, baja temporal… lo que reduzca el recibo.'],
                    ],
                ],
            ],
            'sugerencias' => [['Comparar tarifas de luz y gas', 12, 15]],
            'registros' => ['tipos' => ['Factura', 'Recibo', 'Incidencia', 'Cambio de tarifa', 'Reclamación', 'Parte al seguro', 'Otro'],
                            'valor' => null, 'unidad' => ''],
        ],

        // -------------------------------------------------------------
        'familia' => [
            'nombre' => 'Familia', 'icono' => 'familia', 'color' => '#b8457c',
            'descripcion' => 'Colegio, actividades y fechas importantes de cada uno.',
            'tipos' => [
                'colegio' => [
                    'nombre' => 'Colegio o estudios', 'ejemplo' => 'Colegio San José', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'Alumno',
                    'campos' => [
                        'curso' => ['etiqueta' => 'Curso', 'tipo' => 'texto', 'resumen' => true,
                            'ayuda' => 'Ej.: 4.º de Primaria · grupo C. Finanzas saca de aquí el curso (4PRI) para el colegio del CEU.'],
                        'tutor' => ['etiqueta' => 'Tutor o tutora', 'tipo' => 'texto', 'resumen' => true],
                        'acogida' => ['etiqueta' => 'Va a acogida (madrugadores)', 'tipo' => 'opcion', 'opciones' => ['Sí', 'No']],
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
                        'porcentaje_pago' => $parte_tuya,
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

        // -------------------------------------------------------------
        // Los NÚMEROS de las nóminas viven en finanzas (recibo a recibo y
        // cuadrados con el banco); aquí, la empresa, el contrato, el convenio
        // y los PDF. La ficha del empleo con «nominas_finanzas» = Sí enseña
        // lo que hay en finanzas (includes/finanzas.php). Decisión de Gonzalo,
        // 3/10/2026: no duplicar los recibos.
        // Trabajo se abre en trabajo.php (panel · Mi puesto · Equipo), no en el listado
        // genérico (Gonzalo, 6/10/2026: «el cerebro de todo lo del trabajo»). El horario
        // va en formato lista, una línea por día («Lunes: 8:00-14:00 y 15:00-17:30»): de ahí
        // sale «hoy» en el panel y en el equipo (horario_de_hoy() en includes/trabajo.php).
        'trabajo' => [
            'nombre' => 'Trabajo', 'icono' => 'maletin', 'color' => '#1d8291',
            'descripcion' => 'Mi puesto, el equipo, el convenio y las nóminas.',
            'tipos' => [
                'empleo' => [
                    'nombre' => 'Empleo', 'ejemplo' => 'Universidad CEU Cardenal Herrera', 'persona' => 'obligatoria',
                    'persona_etiqueta' => 'Quién trabaja',
                    'campos' => [
                        'puesto' => ['etiqueta' => 'Puesto', 'tipo' => 'texto', 'resumen' => true],
                        'categoria' => ['etiqueta' => 'Grupo o categoría del convenio', 'tipo' => 'texto',
                            'ayuda' => 'Como sale en la nómina o en el contrato. Ej.: PAS · Titulado.'],
                        'cif' => ['etiqueta' => 'CIF de la empresa', 'tipo' => 'texto'],
                        'centro' => ['etiqueta' => 'Centro de trabajo', 'tipo' => 'texto'],
                        'fecha_alta' => ['etiqueta' => 'Fecha de alta (antigüedad)', 'tipo' => 'fecha'],
                        'contrato' => ['etiqueta' => 'Tipo de contrato', 'tipo' => 'opcion', 'resumen' => true,
                            'opciones' => ['Indefinido', 'Temporal', 'Fijo discontinuo', 'Prácticas o formación', 'Funcionario', 'Autónomo', 'Otro']],
                        'jornada' => ['etiqueta' => 'Jornada', 'tipo' => 'texto', 'ayuda' => 'Ej.: completa, 37,5 h a la semana.'],
                        'bruto_anual' => ['etiqueta' => 'Salario bruto anual', 'tipo' => 'importe'],
                        'pagas' => ['etiqueta' => 'Número de pagas', 'tipo' => 'numero'],
                        'nominas_finanzas' => ['etiqueta' => 'Sus nóminas se llevan en Finanzas', 'tipo' => 'opcion', 'opciones' => ['Sí', 'No'],
                            'ayuda' => 'Con «Sí», la ficha enseña las nóminas del año y el cuadre con el banco, leídos de la app de finanzas.'],
                        'revision_salarial' => ['etiqueta' => 'Próxima revisión salarial', 'tipo' => 'fecha',
                            'vence' => 'Revisión salarial', 'aviso' => 30, 'repetir' => 12],
                        'fin_contrato' => ['etiqueta' => 'Fin del contrato', 'tipo' => 'fecha',
                            'vence' => 'Fin del contrato', 'aviso' => 60],
                        'beneficios' => ['etiqueta' => 'Beneficios y retribución flexible', 'tipo' => 'area', 'aparte' => true,
                            'ayuda' => 'Seguro médico, ticket restaurante, transporte, colegio, guardería…'],
                        'horario' => $horario,
                        'trayectoria' => ['etiqueta' => 'Puestos, contratos y categorías', 'tipo' => 'area', 'aparte' => true, 'lista' => true,
                            'ayuda' => 'Una línea por cambio: «01/05/2010: responsable del servicio».'],
                        'condiciones' => ['etiqueta' => 'Condiciones y acuerdos', 'tipo' => 'area', 'aparte' => true,
                            'ayuda' => 'Teletrabajo, vacaciones, lo pactado con RRHH.'],
                    ],
                ],
                // Las personas del equipo que diriges: fichas de trabajo, no personas de
                // la familia (no entran en la app ni salen en Personas). Los datos de RRHH
                // los manda Workday: aquí, lo que conviene tener a mano.
                'miembro' => [
                    'nombre' => 'Persona del equipo', 'ejemplo' => 'Nombre y apellidos', 'persona' => null,
                    'campos' => [
                        'puesto' => ['etiqueta' => 'Puesto', 'tipo' => 'texto', 'resumen' => true],
                        'categoria' => ['etiqueta' => 'Categoría', 'tipo' => 'texto', 'ayuda' => 'Ej.: Titulado superior.'],
                        'relacion' => ['etiqueta' => 'Relación', 'tipo' => 'opcion',
                            'opciones' => ['Plantilla', 'Externo (ETT o empresa)', 'Becario', 'Otro']],
                        'contrato' => ['etiqueta' => 'Contrato', 'tipo' => 'opcion',
                            'opciones' => ['Indefinido', 'Temporal', 'Prácticas o formación', 'Otro']],
                        'incorporacion' => ['etiqueta' => 'En el equipo desde', 'tipo' => 'fecha'],
                        'servicio_continuo' => ['etiqueta' => 'Fecha de servicio continuo', 'tipo' => 'fecha',
                            'ayuda' => 'La de Workday (la antigüedad en la empresa).'],
                        'fin_prueba' => ['etiqueta' => 'Fin del periodo de prueba', 'tipo' => 'fecha',
                            'vence' => 'Evaluar el periodo de prueba', 'aviso' => 30],
                        'fin_contrato' => ['etiqueta' => 'Fin del contrato', 'tipo' => 'fecha',
                            'vence' => 'Fin del contrato', 'aviso' => 60],
                        'email' => ['etiqueta' => 'Email', 'tipo' => 'email'],
                        'telefono' => ['etiqueta' => 'Teléfono', 'tipo' => 'tel'],
                        'horario' => $horario,
                        'trayectoria' => ['etiqueta' => 'Contratos y categorías', 'tipo' => 'area', 'aparte' => true, 'lista' => true,
                            'ayuda' => 'Una línea por cambio: «15/11/2012: contrato indefinido».'],
                        'funciones' => ['etiqueta' => 'Funciones', 'tipo' => 'area', 'aparte' => true],
                    ],
                ],
                'convenio' => [
                    'nombre' => 'Convenio colectivo', 'ejemplo' => 'XIV Convenio de centros de educación universitaria', 'persona' => null,
                    'enlace' => ['etiqueta' => 'Empleo', 'a' => [['trabajo', 'empleo']]],
                    'campos' => [
                        'ambito' => ['etiqueta' => 'Ámbito', 'tipo' => 'texto', 'resumen' => true, 'ayuda' => 'Estatal, autonómico, de empresa…'],
                        'publicacion' => ['etiqueta' => 'Publicación', 'tipo' => 'texto', 'resumen' => true, 'ayuda' => 'Ej.: BOE-A-2024-10663.'],
                        'web' => ['etiqueta' => 'Enlace al texto', 'tipo' => 'texto'],
                        'vigente_hasta' => ['etiqueta' => 'Vigente hasta', 'tipo' => 'fecha', 'resumen' => true,
                            'vence' => 'Fin de la vigencia del convenio', 'aviso' => 60,
                            'ayuda' => 'Suele prorrogarse solo: el aviso es para mirar si hay convenio nuevo o tablas revisadas.'],
                        'tablas' => ['etiqueta' => 'Tablas salariales', 'tipo' => 'area', 'aparte' => true,
                            'ayuda' => 'Salario base de la categoría por año, pluses, trienios.'],
                        'permisos' => ['etiqueta' => 'Vacaciones, permisos y jornada', 'tipo' => 'area', 'aparte' => true],
                        'analisis' => ['etiqueta' => 'Análisis y preguntas para RRHH', 'tipo' => 'area', 'aparte' => true],
                    ],
                ],
                'contacto' => ['enlace' => ['etiqueta' => 'Empleo', 'a' => [['trabajo', 'empleo']]]]
                              + tipo_contacto('Contacto del trabajo', 'RRHH · nóminas', 'Departamento o cargo'),
            ],
            'sugerencias' => [['Pedir el certificado de retenciones', 12, 15], ['Revisar la nómina de enero (tablas nuevas)', 12, 7]],
            // El líquido de una nómina va en «Importe», NUNCA en «Coste»: el coste
            // suma como gasto en la ficha y en el panel.
            'registros' => ['tipos' => ['Nómina', 'Certificado de retenciones', 'Carta de retribución', 'Subida o cambio de sueldo',
                                        'Contrato o anexo', 'Evaluación', 'Formación', 'Otro'],
                            'valor' => 'Importe', 'unidad' => '€'],
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
