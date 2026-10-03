# Segundo cerebro — panel de mandos de la casa (PHP + MySQL)

Memoria viva del proyecto. Se actualiza **a la vez que el código**, con el
*porqué* de cada decisión no obvia (fecha y caso que la motivó). Lo que ya se
deduce leyendo el código no se repite aquí.

Última revisión: **3/10/2026**. Estado: **app base escrita y probada en local
(5/5 pruebas), sin desplegar todavía** (ver «Estado»).

## Comportamiento al iniciar

Cuando el usuario abra esta carpeta y escriba cualquier cosa sin un encargo
concreto, responde:

> **Segundo cerebro** 🧠
>
> Tu panel de mandos de la casa: vivienda, vehículos, salud, documentos,
> contratos y familia, con avisos de todo lo que vence.
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
(Hostinger, en SUBCARPETA: `BASE_URL = '/segundo-cerebro'`). Seis secciones:
**Vivienda, Vehículos, Salud, Documentos, Contratos y Familia**. Cada una guarda
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
- **Diseño**: paleta Velzon, la misma familia que el dashboard de finanzas, tema
  claro/oscuro, mobile first, tipografía del sistema (cero recursos externos:
  hay datos de salud).

## Cómo se trabaja aquí

- **Probar:** `php pruebas/todas.php` → tiene que dar **5/5** antes de decir que
  algo está listo para subir. Van contra **SQLite** con el esquema real (no hay
  base simulada a mano como en finanzas) y no necesitan `private/` ni MySQL, así
  que dan 5/5 en cualquier ordenador. El PHP de este equipo (winget, sin
  php.ini) no carga pdo_sqlite: `includes/cli.php` relanza el script con
  `-d extension=pdo_sqlite` solo. No tocar la instalación de PHP.
- **Ver la app en local:** `php servidor-local.php` → http://127.0.0.1:8090
  (SQLite en `private/local.sqlite`, datos de juguete). Para mirar el diseño sin
  sesión en el navegador de Claude: capturas con Edge headless
  (`msedge --headless=new --screenshot`) de HTML bajado con curl; para el móvil,
  dentro de un `<iframe width=390>` (la ventana headless no baja de ~500 px y
  parece que desborda cuando no lo hace).
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
  admin) vía `FINANZAS_URL`. Paso 2: que los contratos y el historial con coste
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
