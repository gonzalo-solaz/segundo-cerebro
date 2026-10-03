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
solo FileZilla y hPanel.** Usa también Codex (de ahí `AGENTS.md`).

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
- **Desplegar — archivo a archivo, con FileZilla.** Tras editar y pasar las
  pruebas, di la ruta exacta de cada archivo tocado; el usuario lo arrastra a
  `segundo-cerebro/` en el servidor. **Nunca se suben**: `pruebas/`,
  `remoto.php`, `servidor-local.php`, `acceso.json`, `*.md`, `.claude/`,
  `private/` (salvo `private/.htaccess`). `config.php` se sube a mano y solo si
  cambia; las constantes nuevas tienen valor por defecto en
  `includes/config-carga.php`, así que una versión nueva funciona con el
  `config.php` viejo. Si cambias `assets/app.css` o `app.js`, dile que suba
  `ASSETS_VERSION` en `config.php` (si no, los móviles siguen con la vieja).
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
