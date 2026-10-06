-- Formación del equipo (6/10/2026, Gonzalo: «una pestaña de formación que además vaya a
-- persona/s; hay cursos que los han hecho varios miembros del equipo»). Cada curso es una
-- ficha de Trabajo (tipo «curso»); esta tabla dice quién lo ha hecho, con sus fechas y su
-- estado (como en el «Historial de aprendizaje» de Workday). Un curso, varias personas;
-- una persona, una fila por curso.
CREATE TABLE IF NOT EXISTS formacion (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  curso_id       INT UNSIGNED  NOT NULL,
  elemento_id    INT UNSIGNED  NOT NULL,
  inscripcion    DATE          NULL,
  finalizacion   DATE          NULL,
  estado         VARCHAR(30)   NOT NULL DEFAULT '',
  resultado      VARCHAR(200)  NOT NULL DEFAULT '',
  notas          TEXT          NULL,
  actualizado_en DATETIME      NOT NULL,
  UNIQUE KEY un_asistente (curso_id, elemento_id),
  KEY por_persona (elemento_id),
  FOREIGN KEY (curso_id) REFERENCES elementos (id) ON DELETE CASCADE,
  FOREIGN KEY (elemento_id) REFERENCES elementos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
