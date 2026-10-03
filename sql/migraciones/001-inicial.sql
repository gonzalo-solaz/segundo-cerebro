-- =====================================================================
--  Segundo cerebro · esquema inicial
--  Escrito para MySQL/MariaDB. Las pruebas lo traducen a SQLite
--  (includes/esquema.php → sql_traducir): no uses ENUM, triggers ni
--  funciones de fecha de MySQL, y deja los índices en línea (KEY ...).
--
--  Por qué tan pocas tablas: las seis secciones (vivienda, vehículos,
--  salud, documentos, contratos, familia) comparten las mismas piezas
--  —cosas, fechas que vencen, historial y archivos—. Lo que distingue a
--  un coche de un DNI son sus campos, y esos viven en includes/secciones.php
--  y se guardan como JSON en elementos.datos. Añadir una sección nueva no
--  necesita tocar SQL.
-- =====================================================================

-- Las personas de la casa. Pueden tener acceso (usuarios.persona_id) o ser
-- solo una ficha (los niños, por ejemplo).
CREATE TABLE IF NOT EXISTS personas (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre           VARCHAR(80)  NOT NULL,
  relacion         VARCHAR(30)  NOT NULL DEFAULT '',
  fecha_nacimiento DATE         NULL,
  color            VARCHAR(7)   NOT NULL DEFAULT '#405189',
  activa           TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en        DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  nombre        VARCHAR(80)  NOT NULL,
  password      VARCHAR(255) NOT NULL,
  rol           VARCHAR(10)  NOT NULL DEFAULT 'miembro',
  estado        VARCHAR(12)  NOT NULL DEFAULT 'activo',
  debe_cambiar  TINYINT(1)   NOT NULL DEFAULT 0,
  persona_id    INT UNSIGNED NULL,
  ultimo_acceso DATETIME     NULL,
  creado_en     DATETIME     NOT NULL,
  UNIQUE KEY email_unico (email),
  FOREIGN KEY (persona_id) REFERENCES personas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cualquier cosa que se controla: una casa, un coche, un DNI, un seguro,
-- una ficha médica, el colegio de un hijo...
CREATE TABLE IF NOT EXISTS elementos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seccion        VARCHAR(20)  NOT NULL,
  tipo           VARCHAR(30)  NOT NULL,
  nombre         VARCHAR(150) NOT NULL,
  persona_id     INT UNSIGNED NULL,
  datos          TEXT         NOT NULL,
  notas          TEXT         NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en      DATETIME     NOT NULL,
  actualizado_en DATETIME     NOT NULL,
  KEY por_seccion (seccion, activo),
  FOREIGN KEY (persona_id) REFERENCES personas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lo que vence o toca hacer. origen = 'campo:<clave>' cuando lo crea solo
-- un campo de fecha de la ficha (la caducidad del DNI, la ITV...); vacío
-- cuando es un recordatorio puesto a mano.
CREATE TABLE IF NOT EXISTS vencimientos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seccion       VARCHAR(20)  NOT NULL,
  elemento_id   INT UNSIGNED NULL,
  titulo        VARCHAR(150) NOT NULL,
  fecha         DATE         NOT NULL,
  aviso_dias    INT          NOT NULL DEFAULT 30,
  repetir_meses INT          NOT NULL DEFAULT 0,
  origen        VARCHAR(40)  NOT NULL DEFAULT '',
  estado        VARCHAR(10)  NOT NULL DEFAULT 'pendiente',
  hecho_en      DATE         NULL,
  hecho_por     INT UNSIGNED NULL,
  notas         TEXT         NULL,
  creado_en     DATETIME     NOT NULL,
  KEY agenda (estado, fecha),
  KEY por_elemento (elemento_id),
  FOREIGN KEY (elemento_id) REFERENCES elementos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial: mantenimientos, reparaciones, consultas, mediciones...
CREATE TABLE IF NOT EXISTS registros (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  elemento_id INT UNSIGNED  NOT NULL,
  fecha       DATE          NOT NULL,
  tipo        VARCHAR(40)   NOT NULL DEFAULT '',
  titulo      VARCHAR(150)  NOT NULL,
  valor       DECIMAL(12,2) NULL,
  unidad      VARCHAR(15)   NOT NULL DEFAULT '',
  coste       DECIMAL(10,2) NULL,
  notas       TEXT          NULL,
  creado_por  INT UNSIGNED  NULL,
  creado_en   DATETIME      NOT NULL,
  KEY por_elemento (elemento_id, fecha),
  FOREIGN KEY (elemento_id) REFERENCES elementos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archivos (PDF y fotos). El archivo vive en private/archivos/AAAA/, con
-- nombre aleatorio; aquí solo la referencia.
CREATE TABLE IF NOT EXISTS documentos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  elemento_id     INT UNSIGNED NOT NULL,
  titulo          VARCHAR(150) NOT NULL,
  archivo         VARCHAR(255) NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  mime            VARCHAR(100) NOT NULL,
  bytes           INT UNSIGNED NOT NULL,
  subido_por      INT UNSIGNED NULL,
  creado_en       DATETIME     NOT NULL,
  KEY por_elemento (elemento_id),
  FOREIGN KEY (elemento_id) REFERENCES elementos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quién hizo qué. Con varias personas entrando, «¿quién ha cambiado
-- esto?» es la primera pregunta. usuario_id NULL = Claude (API) o el cron.
CREATE TABLE IF NOT EXISTS actividad (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  texto      VARCHAR(255) NOT NULL,
  creado_en  DATETIME     NOT NULL,
  KEY por_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ajustes (
  clave VARCHAR(60) NOT NULL PRIMARY KEY,
  valor TEXT        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
