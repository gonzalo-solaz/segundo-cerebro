# Segundo cerebro — instalación y uso

Panel de mandos de la casa en `https://gonzalosolaz.tech/segundo-cerebro/`.

## Requisitos

- Hostinger con PHP 8 y MySQL (lo que ya tienes para finanzas).
- FileZilla conectado a tu Hostinger.
- En este ordenador: PHP (ya lo tienes) para las pruebas y para verla en local.

---

## 1. Verla primero en tu ordenador (opcional, 1 minuto)

```
cd C:\Users\Gonza\projects\segundo-cerebro
php servidor-local.php
```

Abre <http://127.0.0.1:8090>. La primera vez te pide crear el administrador.
Lo que metas aquí es de juguete (se guarda en `private/local.sqlite`) y no
se mezcla con el servidor. `Ctrl+C` para pararlo.

## 2. Crear la base de datos (hPanel)

1. hPanel → **Bases de datos** → **Bases de datos MySQL**.
2. Crea una nueva: nombre `cerebro`, usuario `cerebro` y una contraseña
   larga (guárdala). Hostinger les pondrá delante tu prefijo, por ejemplo
   `u123456789_cerebro`.
3. **No hace falta tocar phpMyAdmin**: las tablas se crean solas la primera
   vez que se abre la app.

## 3. Preparar `config.php`

1. En la carpeta del proyecto, copia `config.example.php` como `config.php`.
2. Rellena `DB_NAME`, `DB_USER` y `DB_PASS` con lo del paso 2.
3. Pon tu correo en `EMAIL_AVISOS` (puedes poner varios separados por comas,
   por ejemplo el tuyo y el de tu pareja).
4. Deja `BASE_URL` y `URL_APP` como vienen.

## 4. Subir con FileZilla

En el servidor, dentro de `domains/gonzalosolaz.tech/public_html/`, crea la
carpeta **`segundo-cerebro`** y sube a ella:

| Sube | No subas |
|---|---|
| Todos los `.php` de la raíz **excepto** `remoto.php` y `servidor-local.php` | `remoto.php`, `servidor-local.php` |
| `config.php` (el que has rellenado) | `config.example.php` (no pasa nada, pero sobra) |
| `.htaccess` y `.user.ini` | `acceso.json` |
| Las carpetas `includes/`, `assets/`, `cron/`, `sql/` enteras | `pruebas/`, `.claude/`, `*.md`, `.gitignore` |
| `private/.htaccess` (solo ese archivo, dentro de una carpeta `private`) | El resto de `private/` |

> Los archivos que empiezan por punto (`.htaccess`, `.user.ini`) son los que
> protegen la app. Comprueba que han subido: en FileZilla, menú **Servidor →
> Forzar mostrar archivos ocultos**.

## 5. Crear tu cuenta

1. Abre <https://gonzalosolaz.tech/segundo-cerebro/> → te lleva a crear el
   administrador (tu nombre, email y una contraseña de 12+ caracteres).
2. Entra con ella.
3. **Borra `crear-admin.php` del servidor** (ya no hace nada, pero sobra).

## 6. Activar el aviso diario por correo

1. En la app: **Ajustes → Sistema**. Ahí aparece el comando exacto, con la
   ruta de tu servidor. Cópialo.
2. hPanel → **Avanzado → Trabajos Cron** → tipo «Personalizado» → pega el
   comando → una vez al día (por ejemplo, todos los días a las 7:00).
3. Al día siguiente, en Ajustes → Sistema tiene que poner «Aviso diario en
   marcha». Si en 36 horas no ha corrido, el panel te avisa.

> Ojo con la ruta: tiene que llevar `domains/gonzalosolaz.tech/` en medio.
> Sin eso el cron no corre nunca y hPanel no te lo dice (pasó en finanzas).

## 7. Dar acceso a la familia

1. **Personas** → añade a cada uno (también a los niños: no tendrán acceso,
   pero sí sus documentos, salud y colegio).
2. **Ajustes → Dar acceso a alguien** → nombre, email, rol y quién es.
3. La app te enseña **una sola vez** una contraseña temporal: pásasela. Al
   entrar, se le obliga a elegir la suya.

Roles: **Administrador** (todo, más accesos y Ajustes) y **Miembro** (ve y
edita todo; no da accesos ni borra, aunque sí archiva).

## 8. Que Claude grabe por ti (opcional, muy recomendable)

Para pasarle a Claude una póliza, el permiso de circulación o un informe y que
lo guarde él con sus avisos:

1. Inventa una clave larga y aleatoria (o pídesela a Claude).
2. Ponla en `API_CLAVE` del `config.php` del servidor y vuelve a subir ese archivo.
3. En la carpeta del proyecto (en tu ordenador, **no** en el servidor) crea
   `acceso.json`:
   ```json
   {"url": "https://gonzalosolaz.tech/segundo-cerebro", "clave": "LA-MISMA-CLAVE"}
   ```
4. Prueba: `php remoto.php estado`.

A partir de ahí, en esta carpeta, dile a Claude cosas como *«te paso la póliza
del seguro de la furgo»* o *«¿qué vence este mes?»*.

---

## Uso diario

- **Panel**: lo vencido, lo que toca ya y lo que viene, más un resumen por sección.
- **Hecho** ✓: marca un aviso como hecho. Si se repite (seguro, ITV, IBI…),
  programa solo el siguiente.
- **Fechas de la ficha**: al poner la caducidad del DNI o la próxima ITV, el
  aviso se crea solo. Para cambiarlo, cambia la fecha en la ficha.
- **Historial**: en vehículos, apuntar un mantenimiento con más kilómetros
  actualiza los kilómetros del coche.
- **Archivos**: en cada ficha, sube el PDF o la foto (hasta 15 MB).

## Cuando Claude cambie algo

Claude corre las pruebas (`php pruebas/todas.php`, tienen que salir 5/5) y te
dice **qué archivos subir**. Arrástralos a la misma ruta dentro de
`segundo-cerebro/` en FileZilla. Si toca `config.php` o los estilos, te lo dirá
aparte.

## Estructura

```
segundo-cerebro/
├── CLAUDE.md / AGENTS.md      ← memoria del proyecto (Claude / Codex)
├── INSTRUCCIONES.md           ← este archivo
├── .claude/skills/segundo-cerebro.md   ← la skill
├── config.example.php         ← plantilla de config.php
├── index.php                  ← panel
├── seccion.php                ← una sección
├── elemento.php / elemento-editar.php  ← ficha y su formulario
├── vencimientos.php           ← agenda
├── personas.php / ajustes.php / cuenta.php
├── login.php / logout.php / crear-admin.php
├── archivo.php                ← sirve los PDF y fotos (con sesión)
├── api.php                    ← puerta para Claude
├── remoto.php                 ← (local) cliente de la API
├── servidor-local.php         ← (local) verla en tu ordenador
├── includes/                  ← el código; secciones.php define las secciones
├── sql/migraciones/           ← el esquema (se aplica solo)
├── cron/diario.php            ← aviso diario por correo
├── assets/                    ← CSS, JS, icono
├── private/                   ← sesiones y archivos (nunca accesible por URL)
└── pruebas/                   ← (local) php pruebas/todas.php
```
