---
name: segundo-cerebro
description: "Gestiona el Segundo cerebro de Gonzalo (gonzalosolaz.tech/segundo-cerebro): el panel de mandos de la casa con vivienda, vehículos, salud, documentos, contratos y familia. Graba en la app lo que el usuario pasa (pólizas, permisos de circulación, fichas técnicas, ITV, DNI, pasaportes, informes médicos, recetas, facturas, contratos de luz o internet), cuenta qué vence, marca avisos como hechos, añade o cambia secciones y campos, y prepara los cambios de código para subirlos con FileZilla. Usa esta skill siempre que se trabaje en la carpeta segundo-cerebro. Triggers: 'te paso la póliza', 'guarda esto en el segundo cerebro', 'apunta la ITV', 'ha caducado el DNI', 'qué vence este mes', 'qué tengo pendiente', 'renové el seguro', 'he pasado la revisión del coche', 'liquidación de la comunidad', 'recibo de la comunidad', 'te paso la nómina', 'convenio', 'datos de la empresa', 'gastos de la comunidad', 'añade una sección', 'quiero controlar también X', 'nuevo campo', 'sube los cambios', 'qué archivos subo', 'integrar finanzas', 'dale acceso a mi pareja'."
---

# Segundo cerebro

Mantiene vivo el panel de mandos de la casa: graba lo que el usuario te pasa, le cuenta qué vence y hace crecer la app sin romperla.

**Regla fundamental: nunca inventes un dato.** Fecha, importe, número de póliza, matrícula, kilómetros: si el papel no lo dice con claridad, pregunta o déjalo vacío. Un aviso con una fecha inventada es peor que no tener aviso.

Antes de nada, si no lo has hecho en esta conversación, **lee `CLAUDE.md`** (decisiones, reglas, cómo se prueba y despliega).

---

## Paso 1 — Entender qué quiere

Identifica el modo por lo que dice el usuario. Si no está claro, pregunta en una línea.

| Lo que dice | Modo |
|---|---|
| «te paso…», adjunta un PDF/foto, «apunta…», «renové…», «he pasado la ITV» | **A. Grabar** |
| «qué vence», «qué tengo pendiente», «resumen» | **B. Revisar** |
| «quiero controlar también…», «añade un campo», «nueva sección» | **C. Ampliar la app** |
| un fallo, un cambio de diseño, una mejora | **D. Cambiar código** |
| «súbelo», «qué archivos subo», primera instalación | **E. Desplegar** |
| «integrar finanzas» | **F. Finanzas** (ver `CLAUDE.md` → Ideas para después; decidir con el usuario antes de tocar nada de finanzas) |

---

## Paso 2A — Grabar lo que el usuario pasa

La vía es `php remoto.php <acción>` (habla con la API del servidor; ver la cabecera de `remoto.php`).

**Si falta `acceso.json`** o `php remoto.php estado` falla: no te bloquees. Explícale el apartado 8 de `INSTRUCCIONES.md` (`API_CLAVE` + `acceso.json`) y, mientras tanto, dale los datos ya extraídos y ordenados para que los meta él por el formulario (di en qué sección y qué tipo).

1. **Lee el documento** con Read (los PDF con capa de texto y las fotos se leen bien). Si es ilegible, dilo y pide otra foto.
2. **Conoce los campos válidos**: `php remoto.php esquema` (una vez por conversación). Usa SOLO las claves, tipos y opciones que devuelve; la API rechaza lo inventado.
3. **¿Ya existe?** `php remoto.php buscar <seccion> texto="..."` y `php remoto.php personas`. Si ya existe, **actualiza** (con `id`, enviando solo lo que cambia); si no, **crea**.
4. **Decide dónde va**, con el sentido común de casa:
   - Seguro del coche → `contratos/seguro` con `ramo: "Coche o moto"` (no en vehículos).
   - Permiso de circulación / ficha técnica → `vehiculos/vehiculo` (matrícula, bastidor, fecha de matriculación).
   - Informe médico, analítica → historial (`registro`) de la ficha médica de esa persona; el PDF, como `documento` de esa ficha.
   - Receta → `salud/tratamiento` con `receta_hasta`.
   - Pesaje («peso 84,2», foto de la báscula) → `registro` del `salud/peso` de esa persona con `tipo: "Peso"` y `valor` en kg (la unidad se pone sola; `Cintura` en cm y `Grasa corporal` en %). Un apunte por tipo y día.
   - DNI/pasaporte/carnet → `documentos`, con su titular.
   - Factura de una reparación → `registro` del elemento con `coste`, y el PDF adjunto.
5. **Graba**: escribe el JSON en un archivo temporal del scratchpad y usa `php remoto.php elemento|vencimiento|registro <archivo.json>`. Las fechas, en `AAAA-MM-DD`; los importes, como números JSON.
6. **Adjunta el original**: `php remoto.php documento <ruta> elemento=<id> titulo="Póliza 2026"`.
7. **Las fechas de la ficha crean sus avisos solas** (ITV, caducidad, renovación…). No crees un vencimiento a mano para algo que ya tiene campo. Usa `vencimiento` solo para lo que no tiene campo («cambiar las ruedas en primavera»).
8. **«Renové el seguro / pasé la ITV»**: busca el aviso pendiente (`php remoto.php ficha <id>`) y márcalo con `php remoto.php hecho <id>` — si se repite, el siguiente se programa solo. Si el usuario te da la fecha nueva (la ITV siguiente), actualiza el campo de la ficha.

### Liquidación de la comunidad de propietarios (cada trimestre)

Cuando Gonzalo deje una liquidación nueva en `facturas/comunidad/` (o la pase por el chat), el análisis de `gasto-comunidad.php` se pone al día así. Los números se calculan solos con las partidas; lo único que se reescribe a mano es el texto del campo `analisis`.

1. **Ficha**: `php remoto.php buscar contratos texto="Comunidad"` → id. Sus coeficientes están en `cuota_participacion` (zona común) y `cuota_zona` (escalera).
2. **¿Ya está?** `php remoto.php ficha <id>`: mira en el historial que ese trimestre no tenga ya su `Recibo`.
3. **Lee el PDF entero.** Página 1: balance de caja (no sirve para el reparto). Página 2, «Balance de gastos desglosado por conceptos de distribución»: cada partida por bloque (Zona común, Escalera A, Escalera B, Garaje). Página 3, «Distribución de cargas»: la fila **GONZALO SOLAZ SOLER, 2º-6ª (A)**, con su zona común + escalera A = total a pagar.
4. **Recibo**: `registro` con `tipo: "Recibo"`, fecha = la de la liquidación, título «Comunidad 4T26», `coste` = total de Gonzalo en la página 3 y notas «Zona común X + escalera A Y», más lo raro (cobro en dos mitades, derrama…).
5. **Partidas**: `php remoto.php partidas <json>` con el `registro_id` del paso 4. Solo las líneas de **Zona común** (`zona: "comun"`) y **Escalera A** (`zona: "escalera"`) de la página 2: nunca Escalera B ni Garaje. El «Fondo de reserva 5 %» de cada bloque también va, con la categoría «Fondo de reserva». Lo del apartado «Gastos y reparaciones extraordinarias» (obras, reparaciones, limpiezas extra) lleva `extraordinaria: true`. El total de cada línea, tal cual; la parte la calcula la app. La respuesta da `diferencia` con el recibo: 1-2 céntimos es redondeo (el administrador redondea por bloque); si es más, falta o sobra una línea.
   Las categorías válidas las da `esquema` → `partidas_comunidad`. El mapa que se usó en 2026 (mantenlo igual para poder comparar):
   | Concepto en la liquidación | Categoría |
   |---|---|
   | Manto. Piscina, depuradora, obras en la piscina | Piscina |
   | Manto. Ascensor (incluye teléfono), reparaciones de ascensor | Ascensor |
   | Limpieza viviendas, limpiezas extra | Limpieza |
   | Luz eléctrica viviendas | Luz |
   | Agua | Agua |
   | Seguro comunitario | Seguro |
   | Administrador, gastos bancarios, retenciones IRPF, suplidos, certificado digital (DEH), prevención de riesgos (CAE) | Administración |
   | Extintores, BIES, detección de incendios | Contra incendios |
   | Bombillas, electricidad u otras reparaciones de la escalera | Reparaciones |
   | Fondo de reserva 5 % | Fondo de reserva |
6. **PDF**: `php remoto.php documento <pdf> elemento=<id> titulo="Liquidación 4T26"`.
7. **Coste de la ficha**: si el trimestre fue normal (sin obras) y el recibo cambió, actualiza `coste` (cuenta en el gasto fijo mensual).
8. **Análisis**: `php remoto.php comunidad <id>` da los números. Reescribe `datos.analisis` de la ficha (`elemento` con `id`) en 10-15 líneas: lo pagado en el año y lo que se estima para el año entero; las 3 partidas que más cuestan; qué ha cambiado respecto al trimestre anterior (la tabla recibo a recibo); y las preguntas abiertas para la junta. Conserva lo que siga valiendo, quita lo resuelto y no inventes precios de mercado: si citas uno, que venga de una fuente abierta en esa misma sesión.
9. **Cuéntaselo a Gonzalo** en 3-4 líneas: cuánto paga este trimestre, qué ha subido o bajado, y si hay algo que preguntar al administrador.

### Revisión del control de peso

Cuando Gonzalo pida «cómo voy con el peso» o pautas personales:

1. `php remoto.php buscar salud texto="Peso"` → id; `php remoto.php peso <id>` da IMC, ritmo (kg/semana), cambios a 7/30/90/365 días, llegada estimada al objetivo, calorías, cintura y los `consejos` que ya enseña la página.
2. Cuéntaselo en 4-6 líneas con esos números (no los recalcules a ojo). Si faltan altura, sexo, actividad o la fecha de nacimiento (en Personas), dilo: sin ellos no hay IMC ni calorías.
3. Si te lo pide, reescribe `datos.plan` de la ficha (`elemento` con `id`): lo acordado con el médico, metas de la semana (pasos, días de fuerza, qué recortar) y lo que le esté funcionando. Corto y concreto, nada de dietas milagro ni cifras por debajo de 1.500 kcal (hombres) / 1.200 (mujeres).
4. Es salud: no diagnostiques. Con IMC ≥ 30, pérdida sin buscarla o cualquier medicación, remite al médico.

### Nóminas y papeles del trabajo (sección Trabajo)

Los **números** de la nómina viven en **finanzas**, no aquí (decisión de Gonzalo, 3/10/2026): un recibo se graba UNA vez, en finanzas, y la ficha del empleo de aquí lo enseña leyéndolo de su API. Con una nómina nueva:

1. **Números → finanzas**: en `C:\Users\Gonza\projects\personal\finanzas-personales`, el procedimiento de su `CLAUDE.md` (mapa recibo → campos, `php remoto.php nomina recibo.json`, comprobar que la diferencia con el banco no cambia).
2. **PDF → aquí**: en la ficha del empleo (`buscar trabajo`), un `registro` tipo `Nómina`, título «Nómina septiembre 2026», fecha = la del recibo, el **líquido en `valor`, nunca en `coste`** (el coste suma como gasto), y el PDF con `documento`. Nada más: ni conceptos ni cuadre, que ya están en finanzas.
3. Cartas de retribución, certificados de retenciones, contratos y anexos: igual, con su tipo de apunte. Si cambian el puesto, el bruto o la categoría, actualiza la ficha del empleo.
4. **Convenio**: ficha tipo `convenio` enlazada al empleo. Las tablas salariales van en `tablas`, y las dudas para RRHH, en `analisis`. La tabla que usa finanzas para comparar sigue en `CEU_CONVENIO` de su `dashboard-pie.html`: si cambian las tablas, actualiza las dos (que finanzas la lea de aquí está pendiente).

### Compras y licencias del servicio (Trabajo → Compras)

Tipo `trabajo/compra`, una ficha por producto. El importe va en **`importe`, nunca en `coste`** (el coste suma al gasto fijo de casa). `periodicidad` (Anual, Mensual… o «Una vez» para hardware) y `renovacion` crean el aviso «Renovar la licencia», que se repite solo al marcar «hecho». `gestion` = «A través de FUSP» cuando la compra la tramita la fundación. El CECO del servicio está en la ficha del empleo (`ceco`); el `ceco` de la compra solo si va contra otro. Cada renovación pagada: `registro` tipo `Compra o renovación` con el importe en `valor` y la factura con `documento`; si cambia el precio, cambia `importe`. Una licencia que se deja de pagar se archiva: `elemento` con `id` y `"activo": false`.

### Casas, hipoteca, vehículos e hijos: un solo origen (con finanzas)

Desde el 3/10/2026, la ficha de aquí manda en la identidad (nombre, dirección, catastro, compra, titular y su %, términos de la hipoteca, marca, modelo, año, curso del colegio, acogida) y finanzas la lee; el dinero (valor de mercado, saldos, movimientos) vive en finanzas. **La hipoteca se calcula sola desde el 9/10/2026**: con `primera_cuota`, `diferencial`, `tipo_inicial`, `meses_tipo_inicial` y `revision_interes`, la app saca el cuadro de amortización, consulta el Euríbor sola (Banco de España), guarda el tipo de cada revisión y cambia `coste` e `interes` de la ficha el día de la cuota; finanzas lee de aquí el capital pendiente (bloque `amortizacion` de `fichas`). **No cambies a mano la cuota ni el capital.** Para mirarla: `php remoto.php hipoteca <id>` (con `--filas`, cuota a cuota) y `php remoto.php euribor` (consulta ya). Si te pasan el cuadro del banco, sus tipos van con `precio` y `tipo` (`{"elemento_id":33,"desde":"2026-12-07","coste":628.41,"tipo":4.283}`); una amortización anticipada, `registro` tipo `Amortización anticipada` con el importe en `coste` (y «cuota» en el título si reduce la cuota). Un vehículo o una casa nuevos que cuenten como patrimonio: la ficha aquí y, en finanzas, `patrimonio_nuevo` con su valor y `elemento_id`. Si cambia el curso de un hijo, solo su ficha de colegio.

### La cuenta común de la casa (extracto de Mediolanum de la común)

Si Gonzalo pasa el extracto de la cuenta común (la que comparte con Pilar), NO se graba aquí: se importa en finanzas (`php remoto.php importar <csv>` en finanzas-personales; el número de cuenta de la cabecera decide que va a la común, y la categorizan sus propias reglas). Si llega en `.xls`, conviértelo antes al CSV de Mediolanum (ver el CLAUDE.md de finanzas). Luego: `php remoto.php pendientes ambito=casa` en finanzas y propón categorías antes de aplicarlas; y revisa en `cuenta-casa.php` los «cargos sin apuntar en su ficha» (cada factura o recibo que falte, con `registro` aquí) y la última cuota de la hipoteca frente a su ficha (ya no hace falta tocarla: `hipoteca.php` casa cada cargo con su cuota y avisa si falta o no cuadra).

Sobre el contexto de la casa:
- Personas: usa las que devuelve `personas`. Si el papel es de alguien que no está, pregunta antes de crearlo.
- Si dudas de a qué vehículo o persona se refiere un papel, pregunta: no lo deduzcas por el nombre.
- Lo que aprendas que se repite (cómo se llama «la furgo» en la app, qué compañía es cuál) anótalo en `CLAUDE.md` para la próxima vez.

---

## Paso 2B — Revisar qué vence

1. `php remoto.php estado`.
2. Cuéntalo agrupado y en lenguaje de casa:
   - **Ya vencido** (lo primero, sin dramatizar);
   - **Toca ya** (dentro de su ventana de aviso);
   - **Próximas semanas**.
3. Para cada cosa, di qué hay que hacer en concreto si es obvio (pedir cita ITV, comparar el seguro antes de que renueve).
4. **No marques nada como hecho sin que el usuario lo confirme.**
5. Si pregunta **a dónde va el dinero o por los gastos fijos**: `php remoto.php gastos persona=1` (lo que paga Gonzalo, «tuyo», y el total de la casa; partidas, cosas, mes a mes y «revisar», lo mismo que `gastos-fijos.php`). Al grabar un contrato que se paga a medias, pon `porcentaje_pago` (la parte del titular). Si el papel trae lo que costaba antes (la prima del año pasado, la renta anterior), apúntalo con `php remoto.php precio` (`desde` = la fecha desde la que regía): de ahí sale la comparación con hace un año. Y las facturas antiguas de un suministro, como `registro` tipo `Factura` con su fecha real. Cuenta el total, las 2-3 partidas que más pesan, el mes más caro y lo que hay que apartar al mes; luego lo de «revisar». No recalcules a ojo.
6. Si `vigilancia` dice que el cron no corre, avísalo al final con el paso 6 de `INSTRUCCIONES.md`.

---

## Paso 2C — Ampliar la app (secciones, tipos, campos)

Todo se declara en **`includes/secciones.php`** (la cabecera del archivo explica cada clave). No hace falta SQL.

1. Propón el diseño al usuario en 3-5 líneas: tipo, campos, cuál sale en la tarjeta (`resumen`), qué fechas avisan (`vence`, `aviso`, `repetir`). Pregunta solo lo que cambie el diseño.
2. Edita `includes/secciones.php`:
   - Una sección nueva necesita además un icono en `includes/iconos.php` (trazo SVG 24×24, estilo Lucide) y un color que no repita los existentes.
   - **No cambies la clave de un campo que ya tiene datos**: los datos se quedarían huérfanos en el JSON. Si hay que renombrar, hace falta una migración que reescriba `elementos.datos`.
   - Las opciones de un campo `opcion` se pueden ampliar; quitar una que esté en uso deja datos que ya no validan al editar.
3. Si añades una sección, añade su caso a `kpi_seccion()` (`includes/elementos.php`) solo si hay un dato que de verdad aporte.
4. Corre las pruebas (Paso 3) — `prueba-paginas` pinta el alta de cada tipo, así que caza un campo mal declarado.

---

## Paso 2D — Cambiar código

Sigue las reglas de `CLAUDE.md` → «Cómo se trabaja aquí». Lo que más se olvida:
- Página nueva → añadirla a `pruebas/prueba-paginas.php`.
- Esquema → `sql/migraciones/NNN-*.sql`, dentro de lo que traduce `sql_traducir()`.
- Fechas en consultas → desde PHP (`hoy()`), nunca `NOW()`.
- JS → en `assets/app.js` con `data-accion`; la CSP prohíbe JS en línea.
- Diseño → tokens de `:root` en `assets/app.css`; probar a 390 px y en oscuro.
- Lecturas de elementos/avisos → por las funciones de `includes/elementos.php` y `vencimientos.php`, nunca SQL directo en las páginas.

Libertad creativa en el diseño, dentro de la paleta y del tono de la app (sobria, clara, sin ruido).

---

## Paso 3 — Probar

```
php pruebas/todas.php
```

Tiene que dar **5/5**. Si una prueba falla, arréglalo antes de seguir; no la «ajustes» para que pase sin entender por qué fallaba. Para un cambio visual, además: `php servidor-local.php` y captura con Edge headless (cómo, en `CLAUDE.md`).

---

## Paso 4 — Desplegar y cerrar

Al terminar, presenta siempre:

1. **Qué se ha hecho**, en 2-4 líneas.
2. **Si se grabaron datos**: qué elementos, qué avisos se crearon (con fecha) y qué quedó sin rellenar porque el papel no lo decía.
3. **Si se tocó código**: resultado de las pruebas y, con pruebas verdes, **commit y `git push` a `main` tú mismo** (GitHub Actions lo sube a Hostinger; Gonzalo no usa FileZilla). Revisa `git status` para no empujar cambios ajenos. Aparte, avisa si cambió `config.php` (no viaja) o `ASSETS_VERSION`.
4. **Nunca en la lista**: `pruebas/`, `remoto.php`, `servidor-local.php`, `acceso.json`, `*.md`, `.claude/`, `private/` (salvo `private/.htaccess` en la primera instalación).
5. Si cambió algo que `CLAUDE.md` debería saber (una decisión, una lección), **actualízalo** con fecha y porqué.
6. Pregunta si quiere ajustar algo.
