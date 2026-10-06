# Segundo cerebro — panel de mandos de la casa (PHP + MySQL)

Memoria viva del proyecto. Se actualiza **a la vez que el código**, con el
*porqué* de cada decisión no obvia (fecha y caso que la motivó). Lo que ya se
deduce leyendo el código no se repite aquí.

Última revisión: **6/10/2026**. Estado: **app base escrita y probada en local
(5/5 pruebas), sin desplegar todavía** (ver «Estado»).

## Comportamiento al iniciar

Cuando el usuario abra esta carpeta y escriba cualquier cosa sin un encargo
concreto, responde:

> **Segundo cerebro** 🧠
>
> Tu panel de mandos de la casa: vivienda, vehículos, salud, documentos,
> contratos, familia y trabajo, con avisos de todo lo que vence.
>
> Puedo:
> - **Grabar lo que me pases**: una póliza, el permiso de circulación, un
>   informe médico, una foto del DNI… lo leo y lo guardo en su sitio, con sus avisos.
> - **Contarte qué vence** y qué hay pendiente.
> - **Añadir o cambiar secciones** y campos.
> - **Preparar cambios para subir** a gonzalosolaz.tech/segundo-cerebro.
>
> **¿Qué hacemos?**

Después usa la skill `segundo-cerebro` (`.claude/skills/segundo-cerebro.md`).
Si el usuario ya trae un encargo concreto, ve directo a él con la skill.

---

## Quién es el usuario y cómo trabaja

Gonzalo Solaz. Escríbele en **español de España**; espera análisis directo y
honesto, con las limitaciones dichas claramente. **No tiene SSH en Hostinger:
solo hPanel (y FileZilla, que ya no se usa para desplegar: se despliega por GitHub).** Usa también Codex (de ahí `AGENTS.md`).

Sus otros proyectos están en `C:\Users\Gonza\projects\` y son la cantera de
patrones; antes de inventar algo, mirar si ya está resuelto allí:

| Proyecto | Qué se ha traído de allí |
|---|---|
| **finanzas-personales** (`gonzalosolaz.tech/finanzas-personales`) | Casi todo el esqueleto: PHP+MySQL sin build, migraciones que se aplican solas, sesión con carpeta propia, freno de fuerza bruta, `.htaccess` dentro de cada carpeta, API + `remoto.php` para que Claude grabe, cron con correo que no se repite, despliegue archivo a archivo. Es la referencia principal y la app que se integrará aquí. |
| **van4ever** (antes registro-exclusives) | SameSite=Lax (enlaces desde WhatsApp/correo), «filtrar en UN sitio para no olvidar un WHERE», cero recursos externos, mobile first. |
| **suite-inmobiliaria** | Formularios generados desde un **esquema declarativo** (allí, la configuración fiscal; aquí, `includes/secciones.php`). |

## Qué es

Una intranet privada, para la familia, en `https://gonzalosolaz.tech/segundo-cerebro/`
(Hostinger, en SUBCARPETA: `BASE_URL = '/segundo-cerebro'`). Siete secciones:
**Vivienda, Vehículos, Salud, Documentos, Contratos, Familia y Trabajo**. Cada una guarda
«cosas» (una casa, un coche, un DNI, un seguro, la ficha médica de un hijo…),
con sus **avisos** de lo que vence, su **historial** y sus **archivos** (PDF y
fotos). El panel junta lo que vence de todas.

**Objetivo de diseño (heredado de finanzas): piloto automático.** Si algo obliga
a acordarse de volver a apuntarlo cada año, está mal hecho. Por eso las fechas
de la ficha crean sus avisos solas, «hecho» programa el siguiente y el cron
avisa por correo.

## Decisiones (y por qué)

- **Familia con acceso propio (3/10/2026, decisión del usuario).** Roles `admin`
  y `miembro`. Hoy **todo lo ve toda la familia con acceso**. Si un día hace
  falta algo privado (la salud de un adulto), el filtro va en
  `includes/elementos.php` y `includes/vencimientos.php`, que son la ÚNICA vía de
  lectura de elementos y avisos: no hay consultas a `elementos` desde las páginas.
  Mantenerlo así.
- **Personas ≠ usuarios.** Los niños tienen DNI, ficha médica y colegio, pero no
  entran. `personas` son fichas; `usuarios.persona_id` dice quién es quién.
- **Seis secciones, pocas tablas.** Lo que distingue un coche de un DNI son sus
  campos, y viven en `includes/secciones.php` (esquema declarativo) y en
  `elementos.datos` (JSON). De ese archivo salen formularios, fichas,
  validación, avisos y lo que la API le explica a Claude. **Añadir una sección o
  un tipo = editar ese archivo; no hay SQL.**
- **Base de datos propia**, no la de finanzas (lección de van4ever: una base por
  app, nunca compartir tablas con un discriminador). Convenciones idénticas a
  finanzas para que integrarla sea mover código, no reescribirlo.
- **Sin www.** El `.htaccess` manda `www.gonzalosolaz.tech` → `gonzalosolaz.tech`.
  La raíz del dominio (hoy la demo de Suite inmobiliaria) redirige al revés para
  sus rutas, y una cookie es de UN host: entrando a veces con www y a veces sin
  él «se pierde» la sesión. finanzas vive sin www y el día que compartan acceso
  tienen que estar en el mismo host. Comprobado el 3/10/2026 que finanzas, con
  su propio `RewriteEngine On`, no hereda las reglas de la raíz (no hay bucle).
- **Sesión: 2 días de inactividad / 14 máximo** (finanzas: 1 h / 12 h). La usa la
  familia desde el móvil; pedir contraseña cada hora haría que nadie la usara.
  Se compensa con: contraseña de 12+, freno de fuerza bruta, temporal
  obligatoria de cambiar, suspensión que expulsa al instante. Configurable en
  `config.php` (`SESION_INACTIVIDAD`, `SESION_MAXIMA`).
- **Cookie `SameSite=Lax`, no Strict** (registro-exclusives, julio 2026: con
  Strict, los enlaces desde WhatsApp/correo llegaban sin cookie). Todos los POST
  llevan CSRF. Cookie con ruta `/segundo-cerebro/` y nombre propio.
- **CSP estricta** (`script-src 'self'`): nada de JS en línea ni CDN. El JS va
  en `assets/app.js` con atributos `data-accion`. `archivo.php` quita la CSP
  (el visor de PDF del navegador no funciona con ella) y se apoya en que solo
  sirve PDF/imágenes validados por sus primeros bytes + `nosniff`.
- **Archivos en `private/archivos/AAAA/`** con nombre aleatorio; solo se sirven
  por `archivo.php` (con sesión). El tipo se decide por los bytes, no por la
  extensión, y así no hace falta la extensión fileinfo.
- **Diseño: lenguaje visual de Wise con los azules de la casa (3/10/2026).** Gonzalo
  pasó como referencia el sistema de Wise (styles.refero.design) y pidió todas las
  secciones homogéneas; al verlo, «me gusta pero no uses los dos verdes, usa azules
  como los que tenemos». Se conserva la disposición (barra lateral con iconos y color
  de cada sección, barra del móvil); cambian tokens y componentes: marino `#1b2559`
  (el `#405189` de siempre, en profundo) para la barra, las cifras y el login, celeste
  `#a3b8ff` para la acción principal y lo activo, `#405189` para enlaces y foco.
  **Inter** variable en `assets/fuentes/inter-var.woff2` (local: cero recursos
  externos), titulares muy pesados con tracking apretado, botones y etiquetas en
  píldora, tarjetas planas de 24 px sin sombras, y las cifras (`.kpis`) en una banda
  marino. El verde queda solo como color semántico (bien, conseguido), nunca de marca.
  Los colores de sección de `secciones.php` tienen la misma luminosidad; los de persona
  se guardan con la paleta antigua y `app.css` los remapea al pintarlos (no se tocan
  los datos). Finanzas (`finanzas.php`) carga su `panel.css` y DESPUÉS `assets/finanzas.css`,
  que viste el panel con este diseño (cabecera de página, años en píldoras, patrimonio en
  la banda marino, tarjetas, etiquetas en tipo frase; Gonzalo, 3/10/2026: «finanzas no ha
  quedado como las otras secciones»). Eligió esto frente a la alternativa de pasar toda la
  app al estilo de finanzas. Si finanzas renombra clases o variables del panel, revisar
  `finanzas.css`. Antes (hasta 3/10/2026) se copiaban los tokens de
  finanzas con Poppins.
- **Probar:** `php pruebas/todas.php` → tiene que dar **5/5** antes de decir que
  algo está listo para subir. Van contra **SQLite** con el esquema real (no hay
  base simulada a mano como en finanzas) y no necesitan `private/` ni MySQL, así
  que dan 5/5 en cualquier ordenador. El PHP de este equipo (winget, sin
  php.ini) no carga pdo_sqlite: `includes/cli.php` relanza el script con
  `-d extension=pdo_sqlite` solo. No tocar la instalación de PHP.
- **PWA instalable (3/10/2026, petición de Gonzalo, copiada de van4ever).**
  `manifest.json` (con excepción en `.htaccess`: el filtro `*.json` lo bloquea),
  `sw.js` SIN caché y SIN listener de `fetch` (hay datos de salud y todo va
  `no-store`; solo sirve para que sea instalable), iconos PNG en `assets/`
  (192/512, maskable y Apple 180, sacados de `icono.svg`) y etiquetas en
  `cabeza_html()`. En la app instalada, `app.js` abre en la propia app los
  `target="_blank"` del mismo origen (iOS los abriría con otro almacén de cookies
  y sin sesión: afecta a `archivo.php`). Si cambia el icono o el nombre, subir el
  `?v=cerebro-N` del manifest y de `layout.php`. Sin avisos push (van4ever los
  tiene; aquí los avisos van por correo).
- **Ver la app en local:** `php servidor-local.php` → http://127.0.0.1:8090
  (SQLite en `private/local.sqlite`, datos de juguete). Para mirar el diseño sin
  sesión en el navegador de Claude: capturas con Edge headless
  (`msedge --headless=new --screenshot`) de HTML bajado con curl; para el móvil,
  dentro de un `<iframe width=390>` (la ventana headless no baja de ~500 px y
  parece que desborda cuando no lo hace). Mejor, para revisar el móvil: **Playwright
  (Python) está instalado** y lanza el Edge del equipo (`channel='msedge'`) emulando un
  móvil de verdad (`viewport` 360/390, `is_mobile`, `has_touch`), y con los **datos reales**:
  se clonan de producción por la API (solo lectura: `personas`, `buscar`, `ficha <id>`) a un
  SQLite del scratchpad y se sirven con otro `config` vía `SC_CONFIG` (ver «Revisión del móvil»).
  Con datos de juguete no salen los fallos (notas largas, números de factura, dos teléfonos).
- **Desplegar — lo haces tú, por GitHub (corrección de Gonzalo, 3/10/2026).** No se
  sube nada con FileZilla: tras editar y pasar las pruebas (5/5), haces commit y
  `git push` a `main`; GitHub Actions pasa las pruebas y sube por FTP a Hostinger
  (ver «Despliegue real»). No empujes sin pruebas verdes ni cambios ajenos
  (revisa `git status`). El workflow decide qué se sube, no hace falta dar listas
  de archivos. `config.php` NO viaja: si cambia, avisa a Gonzalo. Si cambias
  `assets/app.css` o `app.js`, sube tú `ASSETS_VERSION` en `config.php` y avísale
  de que `config.php` se sube a mano (si no, los móviles siguen con la vieja).
- **Cambiar el esquema:** `sql/migraciones/NNN-nombre.sql`, idempotente, se aplica
  solo al cargar. Escrito para MySQL pero dentro de lo que traduce
  `sql_traducir()` a SQLite: `CREATE TABLE IF NOT EXISTS` con `KEY` en línea,
  `ALTER TABLE ... ADD COLUMN` de una en una, sin ENUM ni funciones de fecha.
  Nunca pedir al usuario que pegue SQL en phpMyAdmin.
- **En las consultas, las fechas las pone PHP** (`hoy()`, `ahora()`); nada de
  `NOW()`, `CURDATE()` ni `ON DUPLICATE KEY` (la prueba de esquema lo vigila).
- **Añadir una página privada:** `require_once includes/auth.php`,
  `cabecera()`/`pie()` de `includes/layout.php`, y añadirla a
  `pruebas/prueba-paginas.php`.
- **Reglas de código:** sentencias preparadas siempre; todo formulario con
  `csrf_input()`/`csrf_ok()`; todo lo impreso con `e()`; nada de
  `"«$var»"` (PHP se come el `»`: siempre `"«{$var}»"`, la prueba lo caza).
  Cualquier cambio de datos deja rastro con `anotar()`.

## El usuario te pasa papeles y tú los grabas

Es el flujo que más valor da (y el preferido en finanzas). Lo detalla la skill:
`remoto.php` → `api.php`, con la clave en `acceso.json` (local, gitignored; la
misma que `API_CLAVE` del `config.php` del servidor). Lo grabado por la API pasa
por las MISMAS funciones que los formularios y queda en la actividad como
«Claude». **Nunca inventes una fecha, un importe ni un número de póliza**: si el
papel no lo dice, pregunta o déjalo vacío.

## Estado (3/10/2026)

**Hecho y probado en local:** login con alta del primer admin, accesos de la
familia con contraseña temporal, personas, las seis secciones con sus tipos,
fichas con datos/avisos/historial/archivos, agenda con «hecho» que repite,
avisos automáticos desde los campos de fecha, gasto fijo mensual, actividad,
correo diario (`cron/diario.php`), API para Claude, tema claro/oscuro.

**Pendiente de la primera instalación** (pasos en `INSTRUCCIONES.md`):
crear la base de datos en hPanel, `config.php`, subir, crear el admin, borrar
`crear-admin.php`, programar el cron (la ruta exacta la enseña Ajustes →
Sistema) y, si se quiere el flujo de papeles, `API_CLAVE` + `acceso.json`.

**Ideas para después (no hechas):**
- **Integrar finanzas.** Paso 1, ya hecho: tarjeta-enlace en el panel (solo
  admin) vía `FINANZAS_URL`. Paso 1 bis, hecho: las nóminas en la ficha del
  empleo (sección Trabajo, ver abajo). Paso 2: que los contratos y el historial con coste
  se crucen con los movimientos reales de finanzas (p. ej. «el seguro de hogar
  se cobró el 2/11 por 241,30 €»). Paso 3: un solo acceso (mover finanzas a
  `/segundo-cerebro/finanzas` como sección «externa», o compartir sesión;
  requisito para ambos: mismo host sin www, que ya se cumple). Decidirlo con el
  usuario antes de tocar nada de finanzas.
- Privacidad por elemento («solo yo») — ver la primera decisión.
- Cumpleaños automáticos desde `personas.fecha_nacimiento`.
- PWA (icono en la pantalla de inicio). Ojo con la lección de
  registro-exclusives: en la PWA de iOS, los `target="_blank"` abren un visor
  con OTRO almacén de cookies y llegan sin sesión (afecta a `archivo.php`).
- Segundo factor (2FA) para los admin.
- **Copia de seguridad:** hoy no hay. Los archivos viven solo en
  `private/archivos/` del servidor y la base en MySQL de Hostinger (que hace
  copias, pero no versionadas por nosotros). Si el usuario habla de respaldos,
  es lo primero que hay que decirle.

## Despliegue real y migración desde Notion (3/10/2026)

**La app ya está desplegada en `https://gonzalosolaz.tech/admin/`** (no en
`/segundo-cerebro/`: ese nombre es solo la carpeta del repo). Cada push a `main`
pasa las pruebas y sube por FTP vía GitHub Actions (secretos en el repo
`gonzalo-solaz/segundo-cerebro`). Los textos de `remoto.php`/INSTRUCCIONES que
dicen `/segundo-cerebro` están desactualizados.

**La API aún no está activa** (comprobado: `/admin/api.php` da 404 = falta el
secreto `API_CLAVE`). Para que Claude grabe: Gonzalo crea el secreto `API_CLAVE`
en GitHub, relanza el despliegue y crea `acceso.json` con
`{"url":"https://gonzalosolaz.tech/admin","clave":"..."}`. Claude nunca debe
pedir ni leer la clave por el chat.

**Traer los datos de Notion (Inmuebles > Casa - C/ Doctor José Vilella).** Claude
tiene el MCP de Notion (espacio privado completo: Personal, Vehículos, Médico,
Inmuebles, Viajes...). Gonzalo decidió qué traer:

- Preparado en `private/importar-notion/*.json` (gitignored; llevan CUPS):
  vivienda, internet, gas, agua, luz, seguro de hogar (Tuio) y ventana Velux.
  Se graban con `php remoto.php elemento <archivo>` cuando la API esté activa.
  Hay que comprobar antes con `buscar` que no se dupliquen.
- **Decidido NO traer:** la página «Pared comedor».
- **El seguro está con Tuio** (no Liberty). Liberty/CoverGrup solo aparece en las
  notas como histórico; su nº de póliza, teléfono y PDF NO se han traído.
- **Pendiente de preguntar a Gonzalo:** nº de póliza de Tuio, coste y periodicidad
  (162,42 € ¿anual?), renovación, teléfono de asistencia; coste/periodicidad/
  permanencia de cada suministro; régimen, m² y fin de hipoteca de la casa;
  WiFi (¿se guarda? lo ve toda la familia); material «homestone gris 42HO-38»
  (¿tipo nuevo «Material» o equipo?); si Pilar García existe como persona.
- **Campos que la app no tiene** (ahora van en `notas`): titularidad 60/40,
  fecha de alta del suministro («Desde»), potencia contratada. Si se repiten,
  añadirlos a `includes/secciones.php`.
- **Aún sin revisar en Notion:** Casa madre (Massarrojos), Compra local/nave, Obra
  baño feb 2023, Iluminación, Aparadores, Alfombras; y las páginas Vehículos y
  Médico (datos médicos: pedir permiso antes de copiar nada).

**Actualización 3/10/2026:** API activa y `acceso.json` creado. Grabados en
producción los 7 elementos de `private/importar-notion/` (ids 2-8: vivienda, internet,
gas, agua, luz, seguro Tuio, Velux). No repetir. Sigue pendiente lo de la lista
anterior. Pilar García NO existe como persona (solo está Gonzalo, id 1).

**Seguro Tuio completado (3/10/2026):** ficha id 7 con póliza 187006, 162,42 €/año,
renovación 12/03/2027 (aviso creado), tomador Gonzalo. Falta: teléfono de
asistencia (los papeles solo dan contacto@tuio.com) y adjuntar los PDF (recibo,
IPID, condiciones particulares), que se pasaron en el chat y no están en disco.

**Facturas de suministros y gasto anual (3/10/2026, petición de Gonzalo):** los
PDF que Gonzalo deja en `facturas/` (gitignored y excluida del despliegue: llevan
CUPS e IBAN parcial) se graban en la ficha del suministro con `registro` (tipo
`Factura`, `coste`, fecha = la de la factura) + `documento`. Grabadas en
producción: gas julio (14,62 €, ficha 4), luz agosto (100,52 €, ficha 6) y
Pepephone septiembre (44,90 €, ficha 3); no repetir. La página
`gasto-suministros.php` (botón en Contratos) suma por año, suministro y mes
SOLO los apuntes tipo `Factura` de suministros (`gasto_suministros()` en
`includes/elementos.php`): una incidencia con coste no es consumo. La migración
003 pasa a `Factura` esas tres, que se grabaron antes sin tipo (la API no tiene
editar/borrar registro). El año a medias sale bajo, no se proyecta nada. Pendiente:
el campo `coste` de los suministros sigue vacío, así que el gasto fijo mensual no
cuenta luz, gas ni internet.

**Enlaces entre elementos (3/10/2026, petición de Gonzalo):** los suministros y
seguros pueden pertenecer a una vivienda (los seguros también a un vehículo).
Columna `elementos.enlace_id` (migración 002), clave `enlace` en el tipo de
`secciones.php`. La ficha de la vivienda/vehículo lista sus contratos con el coste
mensual, y el listado de Vivienda los resume. Los contratos siguen viviendo en
Contratos (gasto fijo, avisos): no se duplican. Por la API: `enlace_id` en
`elemento`. Borrar el padre deja a los hijos sin enlace.

**Comunidad de propietarios (3/10/2026, petición de Gonzalo):** tipo
`contratos/comunidad` (enlazado a la vivienda, con coeficientes y un campo
`analisis`) y tipo de apunte `Recibo` en Contratos. Cada liquidación trimestral =
un `Recibo` con lo que paga Gonzalo (no el total de la comunidad) + el PDF + su
**desglose por partidas** (tabla `partidas`, migración 004, `includes/comunidad.php`,
acción `partidas` de la API). La página `gasto-comunidad.php` (botón en Contratos y
en la ficha) saca sola, por año, lo que le cuesta cada categoría separando lo
ordinario de las obras, y la tabla recibo a recibo; las conclusiones escritas
viven en el campo `analisis` de la ficha. **Por qué así:** Gonzalo quería que el
análisis viviera en la app y se actualizara con cada factura; los números se
calculan (no se reescriben) y solo el texto lo pone al día Claude. El procedimiento
de cada trimestre está en la skill («Liquidación de la comunidad»). Sus
coeficientes: 8,355 % zona común y 21,230 % escalera A (2º-6ª, José Vilella 7); no
tiene garaje ni trastero; ascensor, limpieza, luz, agua y piscina se reparten
50/50 entre escaleras; el garaje no paga piscina. Los PDF están en
`facturas/comunidad/` y los JSON grabados, en `private/comunidad/`. Informe
inicial (foto fija de 1T-3T 2026, con fuentes de precios de mercado) en Claude Docs:
https://claude.ai/code/artifact/bed5a1b2-46db-404b-bbcf-79e190dcb31d.
**Grabado en producción (3/10/2026):** comunidad = ficha 9 (enlazada a la casa, id 2),
recibos 1T26/2T26/3T26 = registros 4, 5 y 6 con sus partidas y sus PDF, y el
campo `analisis` escrito. No repetir. El siguiente es el 4T26.

**Facturas de suministros 2026 completas (3/10/2026, petición de Gonzalo):** grabadas
en producción las 21 que faltaban de `facturas/` (con su PDF): gas ene-jun (ficha 4,
registros 7-12), luz ene-jul (ficha 6, registros 13-19) y Pepephone ene-ago (ficha 3,
registros 20-27). Con las 3 anteriores, el año 2026 queda entero (ene-sep). No repetir.
La fecha del apunte es la de emisión de la factura. Detalles que conviene saber: el gas
de marzo (130,12 €) incluye 44,23 € de la inspección periódica de la instalación (IRI) de
Nedgia; la luz se disparó en julio (611 kWh, 158,85 €); Pepephone subió la tarifa de
50,90 a 52,90 € en la factura de julio. Para las de octubre en adelante: mismo
procedimiento (`registro` tipo `Factura` + `documento`).

**Coste de los suministros rellenado (3/10/2026):** `coste` + `Mensual` en producción:
internet 44,90 € (tarifa actual), gas 66,58 € (media de 7 facturas ene-jul 2026) y luz
75,69 € (media de 8, ene-ago). Son estimaciones: los de gas y luz hay que recalcularlos
cuando haya más facturas (el gas de marzo incluye 44,23 € de inspección puntual). El gas
de enero (160,55 €) está bien: IVA 21 % sobre 132,69 € = 27,86 €.

**Vivienda: equipamiento dentro de la casa (3/10/2026, petición de Gonzalo):** el tipo
`vivienda/equipo` pasa a llamarse «Equipamiento o material» y se enlaza a la vivienda (como
los contratos); la ficha de la casa los lista en su tarjeta «Equipamiento y materiales»,
aparte de «Contratos y seguros», y `resumen_enlazados()` ya solo cuenta contratos. Con UNA
sola vivienda activa, `seccion.php?s=vivienda` redirige a su ficha; con dos o más sale el
listado (`&lista=1` lo fuerza; el migajas de la ficha lo usa). Los contactos de confianza y
«Otra vivienda» se añaden desde la tarjeta de contactos de la ficha. Grabado en producción:
Velux (id 8) enlazado a la casa y «Homestone gris» (id 10, modelo 42HO-38 (07-60), la única
fila de «Materiales utilizados en casa» en Notion, con la página en blanco). No repetir.

**Audi A6 Allroad C7 traído de Notion (3/10/2026, petición de Gonzalo):** grabado en
producción el coche (vehículos, id 11, a nombre de Gonzalo, persona 1) con las
especificaciones, referencias de recambios, la avería pendiente de la cámara ADAS y
los consejos de suspensión/mapas en `notas`; su seguro (contratos, id 12, Qualitas
2025/5417233, 262,02 €/año, renovación 11/02/2027, con el histórico de Axa en notas) y
sus 23 apuntes de historial (registros 28-50: revisiones desde 2016, compra por 27.200 €,
ITV, neumáticos, refrigerante, batería, AdBlue). No repetir. El historial de Notion no
trae kilómetros en 2025-12 ni 2026-09, y `km` queda en 219.263 (mayo 2025). Es un coche importado de Alemania (1.ª matriculación 02/11/2015; España 11/02/2020). ITV del 11/06/2026 apuntada (registro 51) y aviso de la siguiente el 11/06/2027 (deducido: anual por tener más de 10 años; confirmar con la pegatina). Adjuntos a la ficha 11: permiso+ficha técnica (escaneo), factura de compra, Norauto 17/12/2021, Manirapid 13/03/2023 y el plan de mantenimiento Audi; los PDF originales están en `facturas/audi/`. **Pendiente:** teléfono de asistencia del seguro y los PDF de las pólizas (no se han bajado). Ojo: el plan de mantenimiento marca la correa de distribución a 210.000 km y el historial no la muestra claramente. Los otros vehículos de Notion (Allroad C5 4.2 0913CMM, VW T4 California 2781KHZ, Opel Astra 2647HJR) siguen sin traer.

**Hanway Scrambler 125 traída de Notion (3/10/2026, petición de Gonzalo):** grabada en producción (vehículos, id 13, matrícula 1116KPX, bastidor SJVHS1211HU000814, matriculada el 15/10/2018) con neumáticos y el seguro antiguo de Axa en `notas`, y 2 apuntes de historial (registros 52-53: compra 14/10/2018 por 2.090 €; 21/01/2023 a 6.620 km por 33,07 €, sin descripción en Notion). No repetir. Titular Gonzalo (persona 1), gasolina. **Seguro Allianz Moto** grabado (contratos, id 14, póliza 057742866, 120,43 €/año, renovación 01/11/2026 con aviso, asistencia 900 117 120, PDF adjunto de `facturas/hanway/`), enlazado a la moto. **Sin rellenar:** ITV (no consta) y el PDF del manual del propietario, que sigue solo en Notion. Faltan por traer: Allroad C5 4.2 (0913CMM), VW T4 California (2781KHZ) y Opel Astra (2647HJR).

**T4 California, T3 Syncro y Suzuki Vitara traídos de Notion (3/10/2026, petición de Gonzalo, todos a su nombre, persona 1):** grabados en producción: VW T4 California 2781KHZ (vehículos, id 15, 34 apuntes de historial; su seguro Generali UV-G-177.005.600, 356,08 €/año, renovación 11/03/2027, es contratos id 16, enlazado), VW T3 Syncro Caravelle B-4890-TS (id 17, 32 apuntes) y Suzuki Vitara Z-6541-AM (id 18, 19 apuntes). No repetir. El script está en `private/importar-notion/vehiculos-notion.py` (gitignored). **Sin rellenar a propósito:** próxima ITV de los tres (Notion no la trae; las últimas: T4 27/11/2025, Vitara 24/05/2023, T3 sin ITV reciente) y seguro vigente del T3 y del Vitara (no constan). Los km del T3 están casi congelados desde 2009 (175.070 a 176.081 hasta 2017: el cuentakilómetros no debía contar) y la ficha marca 176.081. **No traídos:** la lista de compras de piezas del T4 y los viajes en camper, los PDF de Notion (documentación del T3, ficha técnica del Vitara y justificantes de la transferencia, el manual de la Hanway), y el Opel Astra 2647HJR, el Allroad C5 0913CMM, el Mini Cooper S JCW, el Polo y otros 'coches de interés'.

**T3 de baja temporal y Opel Astra (3/10/2026, petición de Gonzalo):** el T3 Syncro (id 17) está de baja temporal: sin seguro ni ITV a propósito (queda dicho en sus notas; no esperar avisos). Opel Astra J 2647HJR traído de Notion y asignado a **Pilar** (persona 2): vehículos, id 19, con 23 apuntes de historial (2012-2026, último 232.829 km). No repetir. Sin rellenar: próxima ITV y seguro (Notion no los trae). Script en `private/importar-notion/astra-notion.py`. Quedan sin traer: Allroad C5 0913CMM, Mini Cooper S JCW, Polo y los 'coches de interés'.

**ITV vencida a propósito (4/10/2026, Gonzalo pasó capturas de las alertas de la DGT):** la
Hanway (id 13) tiene `proxima_itv` = 21/01/2025 y el T3 (id 17) = 22/12/2016, así que salen como
**vencidas** en la agenda. Es deliberado: la «fecha de alerta» de la DGT es cuándo tocaba pasarla,
y sirve para saber que está caducada. El apunte 53 de la Hanway (21/01/2023, 33,07 €) SÍ fue su
ITV (la pasó ese día; la API no deja editar su tipo, queda como «Otro»). Cuando pase la ITV de
verdad: apuntar el `registro` tipo ITV y poner la nueva `proxima_itv` (marcar «hecho» el aviso).
El T3 sigue de baja temporal: el aviso vencido no significa que deba circular.

**Mini Cooper S JCW (2005) traído de Notion (3/10/2026, petición de Gonzalo):** vehículos, id 24, a nombre de Gonzalo (persona 1), 23 apuntes de historial (compra 1.800 € el 29/12/2025, transporte 1.100 € y las compras de piezas hasta el 27/05/2026). **Importado de Alemania y SIN matricular en España**: la ficha no tiene matrícula ni fecha de matriculación a propósito (el campo `matricula` no es obligatorio); cuando se matricule, rellenarlos y añadir la ITV. Notion dice que todo funciona excepto el motor. No repetir. Script en `private/importar-notion/mini-notion.py`. Sin traer: Allroad C5 0913CMM, Polo y los 'coches de interés'.

**Control de peso (3/10/2026, petición de Gonzalo: «peso con IMC, registro, evolución y pautas»):** tipo
`salud/peso` (uno por persona: altura, sexo, actividad, peso objetivo, «revisar el objetivo el» con aviso, y
`plan` plegado) y página `peso.php` (botón «Peso y pautas» en Salud y «Evolución y pautas» en la ficha). **Los
pesajes son apuntes del historial** (tipos `Peso` kg, `Cintura` cm, `Grasa corporal` %; la unidad la pone
`registros.unidades` de la sección): sin tabla nueva, así la API, el borrado y la actividad son los de siempre.
Desde la página, repetir el día SUSTITUYE la medida (por la API no: `mediciones_peso()` se queda con la última).
Todo el cálculo vive en `includes/peso.php` (IMC OMS, tendencia con media exponencial por días, ritmo = regresión
de 4 semanas, llegada al objetivo, Mifflin-St Jeor con suelo de 1.500/1.200 kcal, cintura OMS y cintura/altura
NICE); las referencias están en su cabecera. La gráfica es SVG pintado en PHP (la CSP no deja librerías). En
menores no se juzga el IMC ni se dan calorías (percentiles del pediatra). Las pautas generales están escritas en
`peso.php`; las personales, en `plan`. API: acción `peso` (`php remoto.php peso <id>`). La edad sale de
`personas.fecha_nacimiento`: si falta, no hay calorías. **Aún sin datos en producción**: no se ha creado el
control de nadie (no inventar altura ni peso; preguntar).

**Graduación de gafas (3/10/2026, petición de Gonzalo):** tipo `salud/gafas` (una ficha por graduación: esfera, cilindro, eje y adición de cada ojo, DIP, fecha y «próxima revisión» con aviso) para comparar graduaciones y saber si toca cambiar de gafas. Origen: Notion, Personal > Gafas (2024 y 2026, sin día exacto). No inventar fechas ni valores que Notion no da.

**Notas cortas, detalle en campos plegados (3/10/2026, queja de Gonzalo: «tanta información amontonada no la veo útil»).** Las notas de los coches (hasta 3.400 caracteres) mezclaban origen, equipamiento, recambios y papeles. Ahora `vehiculo` tiene tres campos `aparte` con `lista` (tarjetas plegadas pintadas como lista por `lista_campo()`: una línea = un punto, «Grupo:» abre un grupo, «Etiqueta: valor» pone la etiqueta en negrita): `equipamiento` (lo que lleva: motor, caja, ruedas y neumáticos, batería, extras), `recambios` (mantenimiento: aceite, filtros, frenos, plan de mantenimiento, defectos a vigilar; **no** componentes) y `origen` (procedencia, compra, papeles, seguros anteriores). `notas` queda para lo breve y accionable (avería pendiente, baja temporal, importado sin matricular); si pasan de 500 caracteres, la ficha las pliega sola. **Al traer un vehículo de Notion, repartir así, no volcarlo todo en `notas`.** Repartidos en producción los 7 vehículos (ids 11, 13, 15, 17, 18, 19, 24), ya en formato lista; no repetir. Copia de las notas originales en `private/importar-notion/copia-notas/` y script en `repartir-notas-vehiculos.py` (gitignored). Las compras que ya constan en el historial se quitaron de las notas del Mini; el resto del texto se movió tal cual.

**Sección Trabajo y nóminas desde finanzas (3/10/2026, petición de Gonzalo).** Tipos
`trabajo/empleo` (empresa, puesto, categoría, contrato, bruto, revisión salarial y fin de
contrato con aviso; beneficios y condiciones plegados), `trabajo/convenio` (enlazado al
empleo: publicación, vigencia con aviso, tablas, permisos, análisis) y contactos del trabajo
enlazados al empleo. **Decisiones de Gonzalo:** (1) los números de las nóminas siguen
viviendo SOLO en finanzas (recibo a recibo y cuadrados con el banco); aquí no se copian.
La ficha del empleo con `nominas_finanzas` = Sí los **lee** de la API de finanzas
(`nomina_estado`, `includes/finanzas.php`), con copia de una hora en `private/cache/`, y
avisa si falta la nómina del mes anterior o si la diferencia con el banco cambia. Hace
falta el secreto `FINANZAS_API_CLAVE` en GitHub (= la `API_CLAVE` de finanzas); sin él, la
tarjeta lo dice y no llama. Aquí van los PDF (apunte `Nómina` con el líquido en `valor`,
nunca en `coste`). (2) Lo ve toda la familia con acceso, como el resto. (3) Que la tabla del
convenio viva aquí y finanzas la lea (hoy está escrita a mano en `CEU_CONVENIO` de su
`dashboard-pie.html`) queda para más adelante: si cambian las tablas, se actualizan las dos.

**Un solo acceso con finanzas y verificación en dos pasos (3/10/2026, decisión de
Gonzalo: «que finanzas sea una sección; no quiero validarme en otra app; cada dato con
un solo origen»).** Plan por fases: (1) finanzas se despliega por GitHub; (2) acceso
único + 2FA; (3) página «Finanzas» aquí con su resumen, leído de su API; (4) unificar
datos: la cosa y sus papeles (casa, vehículo, empleo, convenio, hijos, colegio, términos
de la hipoteca) viven AQUÍ; el dinero que se mueve o se valora (movimientos, nóminas,
valor de mercado, capital pendiente) vive en finanzas, que lee estas fichas con su
`CEREBRO_API_CLAVE`; (5) cruzar contratos con movimientos. **Fase 2 hecha:**
- **2FA (TOTP, `includes/dos-pasos.php`, migración 005)** obligatoria para los admin
  (`auth.php` los manda a «Mi cuenta» hasta activarla), opcional para miembros. Sin QR
  ni librerías: clave en base32 + enlace `otpauth://`. 8 códigos de recuperación de un
  uso; otro admin puede quitarla desde Ajustes. Login: contraseña → `verificar.php`
  (5 min, 5 intentos por cuenta y cuarto de hora, un código no vale dos veces).
- **Pase a finanzas (`includes/pase.php`, idéntico en las dos apps; las pruebas de
  ambas comprueban el mismo pase de ejemplo):** `finanzas-entrar.php` (solo admin) firma
  con `PASE_CLAVE` un pase de 1 minuto y un solo uso; finanzas abre su sesión por email.
  Un solo «Salir» encadenado en los dos sentidos (`logout.php` ↔ `salir.php` de finanzas).
  Sin `PASE_CLAVE`, todo sigue como antes (enlace externo, login propio de finanzas).
- `ASSETS_VERSION` la pone ahora el workflow (el commit): ya no hay que subirla a mano.
- **Fase 3 hecha:** `finanzas.php` (menú «Finanzas», solo admin) enseña lo que da la
  acción `resumen` de la API de finanzas (saldos, salud, pendientes, gasto e ingreso de
  13 meses y categorías del último mes completo frente a su media, con las MISMAS reglas
  que el dashboard: gasto = |suma de los de tipo gasto|, los reembolsos restan, las
  transferencias no cuentan) y las nóminas. Copia de una hora por acción en
  `private/cache/finanzas-<acción>.json` («Actualizar» la fuerza). Los botones llevan a
  cada pantalla de finanzas por el pase.
- **Fase 4 hecha (origen único, en producción):** la casa (2) tiene titular Gonzalo, 60 %
  (copropietaria Pilar, 40 %), 123 m², 2008 y el apunte «Compra o venta» de 155.000 €
  (7/11/2017); nueva **Hipoteca Freedom (Mediolanum)**, contratos id 33, enlazada a la casa,
  cuota 611,65 €/mes (la entera: decisión de Gonzalo; ya cuenta en el gasto fijo), fin
  7/11/2042; **Casa madre (Massarrojos)**, vivienda id 34 (12,5 % de Gonzalo); **empleo**
  «Universidad CEU Cardenal Herrera» id 35 (nóminas en finanzas) y su **convenio** id 36
  (tablas 2023-2027; siguen también en `CEU_CONVENIO` de finanzas, hay que cambiar las dos);
  acogida «Sí» en los colegios 22 y 23. Finanzas lee todo esto por la acción `fichas` y ya no
  lo guarda (ver su CLAUDE.md, «Origen único»). **Si cambia la cuota de la hipoteca, se cambia
  aquí (ficha 33)**; el capital pendiente, en finanzas. No repetir.

**Finanzas vive dentro: el mismo panel (3/10/2026, petición de Gonzalo: «esperaba que
finanzas ya viviera dentro y tener el mismo panel»).** «Finanzas» va en el menú lateral
entre Trabajo y Agenda (solo admin). `finanzas.php` monta EL panel de finanzas, no una
copia: pide a su API la acción `panel` (marcado, datos, versión, avisos; siempre fresco,
con la última copia en `private/cache/finanzas-panel.json` si finanzas no contesta) y
carga sus estáticos de `/finanzas-personales/assets/` (`panel.css`, `panel.js`,
`chart.umd.min.js`; mismo dominio, así que la CSP `'self'` los admite). El panel se
cambia en finanzas-personales y se ve igual en los dos sitios. Aquí NO se pinta la tarjeta
«Qué mirar» con los avisos de salud (la quitó Gonzalo, 3/10/2026: ya sabe que tiene movimientos
sin clasificar); siguen en la pantalla Salud de finanzas. El tema lo pone el botón
de aquí (el panel repinta los gráficos al cambiar `data-theme`). Decidido con Gonzalo:
un solo código del panel (no fusionar repos: las funciones chocan); las pantallas de
acción (importar, revisar, movimiento, nómina…) siguen en finanzas, abiertas por el pase,
y se irán pasando aquí una a una sobre su API; la dirección vieja de finanzas redirige
aquí (su login `?local=1` es el plan B). La página resumen anterior se ha retirado.

**Las pantallas de acción de finanzas, dentro del menú lateral (4/10/2026, queja de
Gonzalo: «las subsecciones de finanzas no están adaptadas, son diferentes y pierdo el menú
lateral; quiero que toda la app respire el mismo estilo»).** Importar, revisar, movimiento,
nómina, cotizaciones y salud ya no se abren sueltas en finanzas con su maqueta oscura:
`finanzas-pantalla.php?p=<pantalla>` (solo admin) pinta el menú lateral, la cabecera y una
fila de pestañas, y monta la pantalla en un **iframe** que entra por el pase
(`finanzas-entrar.php?a=revisar.php?…&embed=1`: siempre pase nuevo, así no hay sesión de
finanzas caducada a medias). **Por qué iframe y no pasarlas ya a la API:** cada una tiene
formularios, subida de extractos y lógica propia; pasarlas es el plan largo, esto da la
navegación y el estilo ya. Dentro, finanzas detecta que está embebida (`embebido()` en su
`includes/cerebro.php`: cabecera `Sec-Fetch-Dest: iframe` o `?embed=1`) y pinta SOLO el
contenido, cargando de aquí `app.css` + **`assets/finanzas-pantallas.css`** (viste su marcado:
`.caja`, tablas, `.num`, `.etiqueta`, botones, `.pildora`) y **`finanzas-pantallas.js`** (ajusta
la altura del iframe, sigue el tema claro/oscuro de fuera y sube arriba tras cada envío). El
diseño sigue siendo de aquí: si finanzas renombra clases de esas pantallas, revisar ese CSS.
Una visita directa (no en iframe) a una pantalla de finanzas rebota sola a este marco si se
entró por el pase. Los botones del panel y «Abrir en finanzas» de la ficha del empleo apuntan
al marco. Finanzas pasó `X-Frame-Options` de DENY a SAMEORIGIN (solo ellas, mismo dominio).
Al pasar una pantalla a esta app sobre la API, se borra de la lista `$pantallas` y se le hace
página propia; el resto no cambia. Límite conocido: si caduca la sesión de ESTA app (2 días)
con el marco abierto, el login no se puede mostrar dentro del iframe (CSP `frame-ancestors`)
y sale en blanco: basta recargar la página entera.

**Gastos fijos: a dónde va (4/10/2026, petición de Gonzalo: «saber a dónde se va el gasto…
actúa como experto en finanzas»).** La cifra del panel («gastos fijos al mes») lleva a
`gastos-fijos.php` (página y no popover: la CSP no deja JS en línea y en el móvil se lee mejor;
también hay botón en Contratos). Mismo total que el panel. Lógica pura en `includes/gastos.php`;
lecturas por `elementos_con_coste()` (elementos.php) e `historial_de_gastos()` (registros.php).
Enseña: reparto por partida (orden y colores FIJOS, `--serie-N` en `app.css`, validados con la
skill dataviz para daltonismo y tema oscuro: si se añade una partida, revalidar), lo que cuesta
cada cosa (la casa, cada vehículo; más lo apuntado en su historial en 12 meses, sin compras),
mes a mes 12 meses (fecha de cargo = renovación de la ficha o, si no hay, último Recibo/Factura +
periodicidad, marcado ≈; sin ninguna, se reparte) con lo que hay que apartar al mes para lo no
mensual, y «Qué revisar»: importes que faltan, seguros que renuevan con el plazo para no renovar
(un mes antes, art. 22 de la Ley de Contrato de Seguro), permanencias, ficha que no cuadra con
sus facturas (≥10 % y ≥3 €/mes), suministros estacionales y la hipoteca. **Con finanzas (solo
admin, acción `resumen`, copia de 1 h):** finanzas lleva las cuentas de Gonzalo, no las de la casa
(allí la «Hipoteca» es su 60 %, ~367 €/mes), así que NO se divide el gasto fijo de la casa entre
sus ingresos: se compara la hipoteca por su parte (`porcentaje_pago`) con el límite bancario del
30-35 %, el ahorro (12 meses completos, referencia 20 %) y el colchón (saldo de las cuentas activas
/ gasto medio, referencia 3-6 meses); y avisa de categorías del banco que parecen fijas y aquí no
están. API: acción `gastos` (`php remoto.php gastos persona=1` = lo de Gonzalo; sin persona, la casa).

**Quién paga qué (4/10/2026, Gonzalo: «de la hipoteca pago el 60 %; de los suministros de casa,
seguro de hogar y comunidad, el 50 %, y Pilar el otro 50 %; los seguros de los coches, yo. Pon mi
gasto en la cifra y el total en pequeño»).** Campo `porcentaje_pago` («Parte que paga el titular»)
en todos los tipos con coste (en actividades, «Parte que pagas tú»: allí la persona es quien va).
`parte_que_pagas()` lo ve desde quien mira: titular = él → ese %; titular otro → el resto (o nada
si lo paga entero); sin titular → ese %. El panel y `gastos-fijos.php` enseñan lo tuyo en grande y
el total de la casa en pequeño; la API, sin `persona_id`, la casa. **`coste_mensual_total()` y las
fichas siguen siendo de la casa** (el coste de un contrato es lo que cobra la compañía). Finanzas
(cuentas de Gonzalo) se compara con lo suyo, y ahora también «gastos fijos / ingresos» (regla
50/30/20). Trade Republic es su cuenta remunerada al 3 % y su fondo de emergencia: el colchón la
cuenta bien. Tipo nuevo `contratos/alquiler` (partida «Alquileres», junto a la hipoteca): la plaza
de garaje de Gonzalo, 113,63 €/mes por transferencia desde Mediolanum el día 1, sube cada abril
(106,76 → 108,90 → 111,08 → 113,63 €). El agua tiene recibos trimestrales (~116 €) con la ficha en
«Mensual» 38,74 €: el «real» de los suministros sale del ritmo de las facturas
(`intervalo_facturas()`, el hueco más corto), no de la periodicidad de la ficha. Pendiente: importes
de Tenis (id 29) y Voleibol (id 28) y quién los paga; «Suscripciones» (~41 €/mes en finanzas) sin
ficha aquí.

**Frente a hace un año y el IPC (4/10/2026, Gonzalo: «saber si cada partida sale más cara o más
barata que el año anterior, y el IPC; el garaje y la nómina suben con él»).** En «A dónde va», cada
gasto y partida lleva ▲/▼ con su % frente a hace un año (ámbar si sube más que el IPC, verde si
baja), y debajo, qué falta para comparar el resto. `interanual_item()`: con facturas/recibos de los
mismos meses en los dos años (los 12 hasta el actual frente a los 12 anteriores), lo pagado; si no,
el precio que regía hace un año. **Historial de precios:** tabla `precios` (migración 006,
`includes/precios.php`): se apunta sola al cambiar el coste de la ficha (y, si no había historial,
el anterior desde la creación de la ficha) y hacia atrás con la acción `precio` de la API
(`php remoto.php precio '{"elemento_id":38,"desde":"2025-04-01","coste":111.08}'`). **IPC:** INE,
serie IPC251856 (variación anual, nacional), API pública sin clave, `includes/ipc.php`, copia de un
día en `private/cache/ipc.json` (con `IPC_URL` vacía, las pruebas, no sale a la red). El INE va con
retraso: se dice siempre de qué mes es. **Sueldo:** en «Frente a tus ingresos», la última subida del
salario base (nomina_estado de finanzas) frente al IPC del mes anterior a la subida: enero de 2026,
1.903,38 → 1.941,45 € (+2,0 %) frente al 2,9 % de diciembre de 2025. Precios cargados en producción
el 4/10/2026 (de las notas de las fichas y de finanzas): garaje (38), Tuio/Liberty (7), Audi (12),
T4 (16), Hanway (14, solo desde el 18/10/2025). **Sin comparar por falta de datos:** luz, gas, agua e
internet (facturas de 2025), comunidad (recibos de 2025), hipoteca (la cuota de hace un año: lo de
finanzas son transferencias redondas, 407 € hasta enero y 360 € desde febrero, no la cuota) y la
prima anterior de la Hanway.

**Recibos de la comunidad de 2025 e historial de la hipoteca (4/10/2026, Gonzalo pasó capturas del banco):**
comunidad = `Recibo` de la ficha 9 (registros 218-222), SIN desglose ni PDF (solo el total): 4T24 319,06 €, 1T25 319,06 €,
2T25 291,27 €, 3T25 369,73 €, 4T25 318,49 €. Los cargos son del 3/1, 10/4, 7/7 y 6/10/2025 y el 7/1/2026; el trimestre se
**dedujo** del patrón (1T26 se domicilió el 2/4/2026) y la fecha del apunte es el fin de trimestre, como en 2026, para que
`interanual_item()` case mes a mes; el cargo real va en las notas. Comparativa de los 12 últimos meses frente a los
anteriores: 1.299,12 € → 1.764,66 € (+35,8 %, casi todo las obras de la piscina del 2T26).
**Corregido el mismo día (Gonzalo: «no es igualitario» y «explica el extra»):** la comparativa ya era de
4 recibos contra 4 (12 meses móviles), pero la vista por años enfrentaba 2025 entero con 3 recibos de 2026 y
las obras inflaban la subida. Ahora `interanual_item()` compara lo NORMAL con lo normal (las partidas
`extraordinaria` salen de `historial_de_gastos()`, tercer valor de cada cargo) y enseña las obras aparte
(`extra_ahora`); `gasto-comunidad.php` tiene la tarjeta «Frente a hace un año» (`comparar_recibos()`: últimos
4 recibos contra los 4 anteriores) y avisa del año a medias. Normal: 1.299,12 € → 1.389,85 € (+7,0 %), más
374,81 € de obras (tu parte). Límite: los recibos de 2025 no tienen desglose, se cuentan enteros como normales
(2T25 fue el más barato, así que probablemente no llevaban obras). **Hipoteca (ficha 33):** cuotas
de 2025 en `precios` (cuota entera, la del cuadro del banco: 605,71 € desde 7/1/2025, 597,78 desde 7/3, 579,98 desde 7/6,
577,07 desde 7/9, 581,93 desde 7/12; el tipo, en la nota de cada precio), así que sale 577,07 → 611,65 € (+6,0 %).
**Hueco:** no hay cuotas de enero a agosto de 2026 (la ficha dice 611,65 € «desde septiembre de 2026») ni de antes de
2025. No repetir.

**Suministros de 2025 para comparar con 2026 (4/10/2026, Gonzalo pasó el extracto del banco y las capturas de
Pepeenergy, Aguas de Valencia y Naturgy).** Grabados en producción 37 apuntes `Factura` SIN PDF (registros 223-259):
luz 12 meses (ficha 6), Pepephone 13 (ficha 3: 12 cargos del extracto, con dos cargos sumados hasta julio, y la factura
de diciembre de 2025, 49,16 €), agua 4 recibos (ficha 5) y gas 8 (ficha 4: 7 cargos de **Naturgy**, la compañía anterior,
y la primera factura de Pepeenergy de diciembre, 108,91 €). No repetir. **Fechas:** la del cargo o recibo cuando consta;
si no (luz de abril a diciembre, gas de Pepeenergy, Pepephone de diciembre) es aproximada y la nota lo dice, siempre en el
MES de emisión (el de la factura, no el del consumo) para que `interanual_item()` empareje con 2026. Lo que dicen: luz
ene-ago +11,5 % (julio y agosto, +88 €: aire acondicionado), agua +2,6 %, Pepephone +2,7 % (el descuento de 3 € del gas
tapa la subida de tarifa a 52,90 €). Gonzalo tiene radiadores de gas (invierno) y aire acondicionado (verano: la luz).
**Gas: no se compara por facturas** (Naturgy factura cada 2 meses; Pepeenergy, cada mes): `interanual_item()` lo salta
cuando el ritmo de los dos años no coincide (salía −24 % falso). Con los PDF de Naturgy (adjuntos a la ficha 4,
documentos 46-52; la captura de Pepeenergy de diciembre, el 53) se ve que **el gas sube por consumo, no por precio**:
11/12-31/03 son 3.975 kWh y 439 € (sin la inspección) frente a ≈2.326 kWh y ≈258 € del mismo tramo de 2024/25 (Naturgy
prorrateado por días): +71 % de kWh y +70 % de euros, con el mismo coste por kWh (≈0,110 €, fijo incluido; Pepeenergy
cobra 0,088 €/kWh variable frente a 0,0808 de Naturgy, pero su término fijo es más bajo y lleva el descuento de
Pepephone). Abril-julio: 639 kWh y 91,54 € frente a 353 kWh y 61,34 € (4/4-6/8/2025). De enero a julio de 2026 lleva
3.650 kWh, el 96 % de los 3.782 de todo 2025 (lecturas reales: 5.376 m³ el 29/11/2024, 5.706 el 6/12/2025). No hay
solapamiento en diciembre: Naturgy llegó hasta el 10/12 y Pepeenergy empieza el 11/12; su factura de diciembre se emitió
el 11/02/2026, así que el apunte (fechado 31/12/2025) tiene la fecha aproximada. **Para que el gas muestre variación** (sin comparar por facturas) se apuntó en `precios` (id 18, ficha 4) la media
mensual de ene-jul 2025, 40,56 € (Naturgy repartido por días; ficha de hoy: 66,58 €, ene-jul 2026): +64 %. No repetir; cuando
haya un año entero de Pepeenergy, la media de la ficha y esta se pueden rehacer con el año completo. Ojo con el balance por años: la
fecha es la de emisión, no la del consumo.

**Impuestos: IBI e impuesto de circulación (4/10/2026, petición de Gonzalo: «en vehículos habrá que
añadir los impuestos de circulación; también el IBI para la vivienda»).** Tipo `contratos/impuesto`
(«Impuesto o tasa»), enlazable a una vivienda o a un vehículo, así que sale solo con su botón «Impuesto o
tasa» en la tarjeta «Contratos y seguros» de cada ficha. Campos: qué impuesto (IBI, Impuesto de
circulación, Tasa de basuras, Otro), ayuntamiento, referencia, importe, periodicidad, parte que paga el
titular, domiciliado, bonificaciones y **«Próximo pago»** (clave `renovacion`, NO renombrarla: es la que usa
el calendario del gasto fijo; aviso 15 días, se repite cada año al marcar «hecho»). Cada año se apunta el
recibo en el historial como `Recibo` (así la comparativa frente a hace un año funciona sola). Entra en el
gasto fijo como partida «Impuestos» (`serie-7`, azul claro `#2a9fd0` / `#2f9ad0` en oscuro, validado con la skill
dataviz tras «Suscripciones»; si se reordenan las partidas, revalidar). Se quitaron las sugerencias de
recordatorio «IBI» e «Impuesto de circulación» (la ficha crea su propio aviso). **Aún sin fichas
en producción:** no se ha creado ninguno; Gonzalo los apuntará cuando lleguen los recibos. No inventar
importes ni fechas de pago del ayuntamiento: preguntar o dejar vacío. Ojo: el T3 está de baja temporal y el Mini
sin matricular, no llevan impuesto de circulación.

**Tarjeta sanitaria (SIP) también en Documentos (4/10/2026, Gonzalo: «¿no debería salir también en documentos?»).** Tipo `documentos/tarjeta_sanitaria` (solo el número SIP; no caduca, así que sin aviso). Grabada la de Gonzalo: id 40, 7304580087 (con el «73» delante, confirmado por él). El mismo número sigue en el campo `tarjeta_sanitaria` de su ficha médica (id 20): está en dos sitios a propósito (cartera y ficha de urgencias); si cambia, tocar los dos. Pilar y los niños, sin grabar. No repetir.

**Certificado de vacunación COVID de Gonzalo (4/10/2026, petición suya; PDF en `Dropbox\personal\documentos`).** Grabado en producción en los dos sitios, con el PDF adjunto en ambos: Documentos = tipo `otro` «Certificado COVID digital de la UE (vacunación)», id 41, número = el identificador del certificado, sin caducidad (el papel no la da); Salud = apunte `Vacuna` en su ficha médica (id 20), 15/02/2022, Spikevax (Moderna) dosis 3/3 (documentos 56 y 57). Las dosis 1 y 2 no constan en el certificado: no inventarlas. No repetir. En la misma carpeta están los de Pilar y Candela, sin grabar.

**Escritura de la casa (4/10/2026, Gonzalo pasó las escrituras de `Dropbox\personal\vivienda\Doctor-Jose-Vilella\compra`).** Campo plegado `escritura` («Escritura y registro», formato lista) en el tipo `vivienda/inmueble`: notaría y protocolos (1055 compraventa, 1056 préstamo), vendedor, registro (finca 4.944, Moncada 1), descripción, superficies, cuotas, servidumbres. **Superficie: manda la escritura** (155,95 m² construidos, 101,54 útiles), no el catastro (123 m² = 78 + 37 + 8 de comunes); Gonzalo: «es lo que manda de todas formas». La ficha 2 la tiene ya con `superficie` 155,95 y las notas corregidas (antes decían «78 + 37» y «parcela de 501 m²»: lo segundo era del edificio entero, 495,88 m²). La ficha 33 (hipoteca) lleva en `notas` las condiciones de la escritura (tipo, margen y penalizaciones, desistimiento 0,25 % hoy, tasación 183.654,06 €, responsabilidad 161.200 €). Adjuntos: compraventa y escaneo a la ficha 2 (documentos 58 y 60), préstamo a la 33 (59). La escritura dice 21,229 % de cuota de escalera y la comunidad usa 21,230 %: no afecta. **Duda abierta:** `fecha_inicio` de la hipoteca es 6/11/2017 y la escritura es del 7/11/2017 (puede ser la fecha valor del banco); no se tocó. El escaneo `escritura.pdf` (9 MB) no se pudo leer (sin texto ni pdftoppm): se tituló «escaneo, noviembre de 2017» sin verificar qué contiene. No repetir.

**Revisión del móvil (4/10/2026, Gonzalo: «revisa bien que en móvil se visualice todo de forma
correcta; que no haya errores ni datos poco claros, amontonados o que desborden»).** Hecha con los
datos reales clonados de producción y un recorrido automático de las 289 páginas a 320, 360 y 390 px
que mide qué se sale de la pantalla, más capturas. Lo que se corrigió y por qué, para no deshacerlo:
- **Fallos de datos:** el año salía «2.015» (clave `anio` en el campo de `secciones.php`: sin punto de
  miles); la cuota 8,355 % salía 8,36 % y, peor, **el formulario la guardaba redondeada** al editar
  cualquier otra cosa de la ficha (`numero_input()` y `valor_campo()` conservan hasta 4 decimales en
  los campos `numero`; los importes siguen a 2); el teléfono «962 683 350 / 656 967 618 (móvil)» era UN
  enlace `tel:` con 18 dígitos (`enlaces_tel()` en `layout.php`: un enlace por número); «1 días» en Peso.
- **Espacio duro (`NBSP`, en `funciones.php`) entre la cifra y su «€» o «%»** en `eur()`, `pct_es()`,
  `variacion_es()` y los «pagas el X %»: en el móvil se partía «543,02 / €». `leer_numero()` ya lo
  ignora. Las pruebas de páginas comparan sin él (`pinta_bien()` lo cambia por un espacio).
- **Avisos sin el nombre repetido:** los automáticos se titulan «Pasar la ITV · Hanway Scrambler 125» y
  debajo salía otra vez el nombre; `titulo_sin_elemento()` lo quita del título donde la cosa ya se ve
  (enlazada debajo o en su propia ficha). La tarjeta de cada cosa en su sección enseña ahora QUÉ vence
  («Pasar la ITV · 11 jun 2027 · en 8 meses»), no solo la fecha.
- **CSS (bloque «Móvil: revisión del 4/10/2026» de `app.css`):** historial con la fecha encima (al lado
  dejaba 134 px al texto); archivos y cosas enlazadas con el nombre en su línea y los detalles debajo;
  cabeceras con 4+ botones (Contratos tiene 9, Salud 6) en una fila que se desliza de lado, con los
  enlaces a análisis (`btn-ir`: Gastos fijos, Peso y pautas…) delante; botones de añadir de la ficha en
  `.botones-tarjeta` (antes pegados sin separación); el nombre de una tarjeta ya no se aplasta contra la
  persona (la persona baja); a 360 px o menos, `.tabla-scroll` usa el relleno de 16 px de la tarjeta (se
  salía 4 px); menú lateral compacto en pantallas bajas (en un iPhone SE no se veía «Ajustes»).
- **Sin arreglar a propósito:** la página Finanzas y sus pantallas no se pudieron revisar en local (el
  panel lo da la API de finanzas, que necesita `FINANZAS_API_CLAVE`). Las tablas anchas que quedan
  («Recibo a recibo» de la comunidad, que crece una columna por trimestre) se deslizan dentro de su tarjeta.

**Trabajo: panel propio con pestañas y el Equipo (6/10/2026, Gonzalo: «ampliar esta parte como cerebro
de todo lo relacionado con el trabajo»; tiene un equipo de 6 y entra una 7.ª persona).** La gestión del
servicio sigue en las herramientas de la empresa (Asana, Workday…); aquí, lo que hay que tener controlado.
Gonzalo primero pidió entrar directo a la ficha del CEU y luego lo cambió: «un panel principal con avisos,
historial, datos en cards», con pestañas **Mi puesto** y **Equipo**. Así: `seccion.php?s=trabajo` redirige a
`trabajo.php` (el listado genérico sigue con `&lista=1`); pestañas Panel · Mi puesto (= la ficha del
empleo, `elemento.php`) · Equipo (`trabajo.php?p=equipo`), pintadas por `pestanas_trabajo()` también en
las fichas de la sección. Panel: cifras (avisos, personas, antigüedad, tu horario de hoy), avisos, el equipo
hoy, historial reciente de toda la sección (`registros_de_seccion()`), mi puesto y convenio/contactos.
Tipo nuevo `trabajo/miembro` («Persona del equipo»: ficha de trabajo, NO una persona de la familia; sin
enlace al empleo para que no salga en «Convenio y contactos»), con fin del periodo de prueba y fin de
contrato con aviso. **Horario** (en el empleo y en cada persona) en formato lista, una línea por día
(«Martes: 8:00-14:00 y 15:00-17:30»): de ahí sale «hoy» (`horario_de_hoy()`, `includes/trabajo.php`);
una línea «Desde: …» no cuenta como día. En la ficha (la tuya y la de cada persona) el horario va **desplegado y lo primero de la segunda columna**, encima de Avisos (Gonzalo, 6/10/2026): clave `destacado` del campo en `secciones.php`. Los datos de RRHH los manda **Workday** (Gonzalo tiene acceso):
aquí se copian los que conviene tener a mano. Quién lo ve: de momento solo entra Gonzalo (su decisión:
«no me preocupa de momento»); si da de alta a Pilar, revisar si Trabajo debe ser solo suyo (datos de
terceros: evaluaciones, incidencias). **Grabado en producción (6/10/2026):** las 6 personas = elementos 43-48 (Teresa, Javier, Borja, Alejandro, Patricia, Maite; Javier con puesto «Técnico de Marketing Digital» y servicio continuo 13/06/2022 según Workday) y, en la ficha 35, el horario y la trayectoria. No repetir. Sin rellenar a propósito: «relación» y contrato de casi todos (Notion no lo dice), la incorporación de Borja y el puesto/contrato de la ficha 35. Los JSON están en `private/importar-notion/equipo/`; en este equipo (`gsolaz`) `acceso.json` ya existe. **Siguientes pasos hablados, sin hacer:** plan de desarrollo (objetivos por curso con
niveles 0-4 y notas desde 20-21), formación, saldo de horas/días debidos (falta decidir cuánto vale un día),
evaluaciones del periodo de prueba (PDF en Notion), licencias del servicio con aviso de renovación (su
importe NO en `coste`: sumaría al gasto fijo de casa), documentos (puestos, normas, protocolos) y la
acogida de la 7.ª persona. Sin decidir: si traer el diario de incidencias (Javier; Emilio ya no está:
propuesto no traerlo) y a los que se fueron. Notion: página «Equipo» (una por persona) y «Compras del
servicio de Com. Digital».

**PHP 8.5 en el equipo de la oficina (6/10/2026):** marca `curl_close()` como obsoleta y las pruebas de
finanzas fallaban; se quitó (no hace nada desde PHP 8.0) de `includes/finanzas.php` e `includes/ipc.php`.

**Plan de desarrollo: bloque propio en la ficha (6/10/2026, Gonzalo: «no lo quiero en el historial, quiero un bloque del plan de desarrollo»).** Tabla `plan_desarrollo` (migración 007, `includes/plan.php`): un registro por ficha y curso (septiembre a agosto, «2025-26») con objetivo, descripción, niveles 0-4, autoevaluación y nota final sobre 10. Bloque «Plan de desarrollo» en la ficha del empleo y de cada persona del equipo (`lleva_plan()`: añadir/editar/borrar cursos, evolución de notas arriba) y tarjeta-resumen en el panel de Trabajo (objetivo del curso actual y última nota; con el curso 2026-27 recién empezado sale «Sin objetivo para este curso»). Una vez por curso: repetirlo actualiza solo lo que se manda (la API cambia lo que dice; en blanco = borrar). API: acción `plan` (`php remoto.php plan <json>`) y `plan_desarrollo` en `ficha`. **Por qué no en `registros`:** primero se grabó allí (tipos Objetivo/Evaluación) y a Gonzalo no le gustó; la migración 007 borra esos apuntes y «Objetivo» ya no es un tipo de historial (una «Evaluación» suelta, p. ej. de un periodo de prueba, sí puede llevar nota sobre 10 por `unidades`). **Grabado en producción:** 21 cursos en las fichas 35 (tú), 43-48: los 7 objetivos de 2025-26 con descripción y niveles y las notas de 2020-21 a 2023-24 (Gonzalo, Teresa, Javier, Borja, Alejandro). Script idempotente: `private/importar-notion/equipo/plan-desarrollo.py`. No repetir. **Sin traer:** objetivos de cursos anteriores (Notion, «Plan de desarrollo» > Curso 2024-25 … 2020-21), notas de los que ya no están (José Antonio, Ana, Mayra), la nota 2025-26 (vencido el 31/07, sin apuntar) y los PDF de competencias/autoevaluación. **Ojo al editar con scripts:** en este equipo, una barra invertida seguida de «n» dentro de un script de Python por heredoc llega como salto de línea real (rompió una línea de comentario de `remoto.php`): usa la herramienta de edición o `chr(92)`, y pasa `php -l`.

**Trabajo: pestaña Compras (6/10/2026, Gonzalo: «una pestaña compras con el listado de software que pido; apunta el CECO; la información está en Notion»).** Tipo `trabajo/compra` («Compra o licencia») y `trabajo.php?p=compras`: arriba el CECO del servicio (campo `ceco` de la ficha del empleo, **V010800**), lo que suman al año las renovaciones (`importe_anual()`), cuántas hay y la próxima; debajo, una tabla. **El importe va en `importe`, no en `coste`**: el coste sumaría al gasto fijo de casa. `renovacion` crea el aviso «Renovar la licencia», que se repite con la periodicidad (o no, con «Una vez»). Las canceladas se archivan; la API ya archiva con `"activo": false`. Sin enlace al empleo, para que no salgan en «Convenio y contactos». **Grabado en producción:** 16 licencias actuales de Notion + la DJI Osmo Pocket 3 (Hardware 2024, 700 €) = elementos 49-65, y 6 canceladas archivadas (Evernote, Issuu, Artgrid, Artlist, Prezi, WP Optimize) = 66-71. No repetir. Script: `private/importar-notion/compras/compras-notion.py`. **Fechas de renovación deducidas:** Notion tenía el «próximo recibo» sin actualizar (desde 2023 en algunas); se puso el siguiente aniversario después de hoy suponiendo renovación anual (la página dice «licencias anuales que hay que renovar»), y cada ficha lo dice en sus notas. Sin fecha en Notion: Semrush, Cookiebot, Freepik, Answer the public e iStock (iStock, además, sin periodicidad: son paquetes de créditos). Importes tal cual en Notion (no dice si llevan IVA). **Sin traer:** las plantillas de inversiones (xlsx: la app solo guarda PDF e imágenes) y la tabla del presupuesto 22-23 («Importe positivo»).
**Hoja de presupuestos (6/10/2026, captura de Gonzalo, «la última que envié», sin curso a la vista):** añadidas en producción sus tres líneas nuevas: «Software de edición de vídeo» (id 72, 500 € presupuestados, sin periodicidad), y la renovación de los dos ordenadores de edición de vídeo de los becarios (73, sin importe). La línea de los Apple de Patricia y Borja se partió en una ficha por persona, enlazada a su ficha del equipo: 74 (Patricia, 47) y 75 (Borja, 45), 3.000 € cada una (Gonzalo: «la renovación de los ordenadores de los diseñadores es 6.000 €»; el reparto a medias es supuesto). No repetir. En la hoja NO están Cookiebot, WPML, JetSmartFilters ni la DJI (compra suelta). Importes de la hoja distintos de los de Notion (sin tocar, a la espera de Gonzalo): Siteimprove 4.209,69 (Notion 8.500), Supermetrics 1.449,10 (1.608,81), Semrush 1.200 (2.900), Flickr 72 (71,99), Soundcloud 100 (99).
**Hardware aparte y enlazado a la persona (6/10/2026, Gonzalo: «separar el hardware y material del software» y «que los ordenadores estén enlazados con Borja y Patricia para saber cuándo se renovaron»).** Compras pinta dos tablas: «Software y servicios» y «Hardware y material» (`es_hardware()`: categoría Hardware o Material). Una compra puede llevar `enlace_id` a una persona del equipo («Para quién»); su ficha la lista en «Equipos y material» (en un `miembro`, la tarjeta de lo enlazado de Trabajo se llama así, no «Convenio y contactos») con el importe y la fecha de compra. **Modelo: una ficha por equipo y persona, que se conserva al renovarlo:** cada renovación es un apunte `Compra o renovación` en su historial y se cambia `primera_compra` («Fecha de compra»). No crear una ficha nueva por cada ordenador.
**Estado y acuerdo de lo pedido (6/10/2026, Gonzalo: «un campo donde dejar comentario; me han rechazado la renovación de los ordenadores de los becarios»).** Campos `estado` (Pedida, Aprobada, Rechazada, Comprada; vacío en las licencias que ya se pagan) y `comentario` en `trabajo/compra`, pintados en la tabla de Compras debajo del nombre (las `notas` no salen allí). Grabado: 73 (becarios) = Rechazada, con el acuerdo: los ordenadores actuales de Borja y Patricia pasan a los becarios cuando lleguen los nuevos; 74 y 75 = Pedida, con esa misma condición en su comentario. **Cuando lleguen los nuevos:** en 74/75, apunte `Compra o renovación`, nueva `primera_compra` y estado Comprada; los viejos pasan a los becarios (73: estado Comprada no, sino actualizar su comentario y, si se quiere, la fecha de compra de los heredados).

**Documentos del equipo (6/10/2026, Gonzalo: «un manual de procedimientos que envié a mi equipo, debajo de Convenio y contactos»).** Tipo `trabajo/documento` («Documento del equipo»: qué es, enviado el, revisarlo el con aviso, «Qué recoge» en lista) con el PDF adjunto; el panel de Trabajo los lista en la tarjeta «Documentos del equipo», debajo de «Convenio y contactos», con «Abrir el PDF» (el primer adjunto). Sin enlace al empleo. Grabado en producción: «Procedimientos y buenas prácticas del equipo» = elemento 76, PDF = documento 61 (los 9 apartados resumidos en «Qué recoge»). No repetir. Sin rellenar: la fecha de envío (el PDF no la dice; el archivo es del 13/03/2026).

**Trabajo: pestaña Formación (6/10/2026, Gonzalo: «una pestaña de formación que además vaya a persona/s; hay cursos que los han hecho varios miembros del equipo»).** Cada curso es una ficha `trabajo/curso` («Curso de formación»: tipo de contenido, quién lo imparte, modalidad, horas, tema; los diplomas, como archivos). **Quién lo ha hecho va en la tabla `formacion`** (migración 008, `includes/formacion.php`): una fila por curso y persona (la ficha del empleo o de una persona del equipo) con inscripción, finalización, estado (Inscrito, En curso, Finalizado, No asistió, Cancelado; con fecha de fin y sin estado = Finalizado), resultado y notas. **Por qué tabla y no `enlace_id` ni historial:** un curso lo hacen varias personas, cada una con sus fechas; el enlace es de uno solo y apuntes repetidos por persona duplicarían el curso. `trabajo.php?p=formacion`: cursos agrupados por curso académico (septiembre-agosto) de su última finalización, con quién y filtro por persona. En la ficha del curso, bloque «Quién lo ha hecho» (casillas: varias personas a la vez); en la de cada persona, bloque «Formación» (con las horas de lo terminado). API: acción `formacion` (`curso_id` + `elemento_id` o `elementos_ids`; repetir cambia solo lo que viene) y `formacion` en `ficha`. Origen: el «Historial de aprendizaje» de Workday (Estado de inscripción/finalización/asistencia, «Versión» = resultado).
**Grabado en producción (6/10/2026):** 8 cursos de la captura de Workday de Teresa (43) = elementos 77-84 (de «Adobe Premiere. Avanzado», 2024, a «Fotografía avanzada… Practicando», 16/07/2026), todos «Oferta de curso», Finalizado y «Asistió» en el resultado. No repetir. Faltan 31 de sus 39 cursos (la captura solo enseñaba 8) y asignar estos a las demás personas que los hicieron: con `formacion` y `elementos_ids`, sin crear el curso otra vez. Sin rellenar: quién lo imparte y horas (Workday no lo enseñaba). Script: `private/importar-notion/equipo/formacion-teresa.py`. **En curso:** «Adobe Premiere. Nivel avanzado (1)» = elemento 85 (Teresa, inscrita el 29/07/2026, En curso; 8 sesiones de 3 h los martes del 22/09 al 24/11/2026, 24 h; anuladas el 27/10 y el 01/12). Lo que está a medias va en el grupo «En curso» de la pestaña (si no, caería en el curso de la inscripción) con su «Última sesión» (`termina`). Cuando acabe: `formacion` con `finalizacion` y estado Finalizado.
