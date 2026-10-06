-- Plan de desarrollo del equipo (6/10/2026, Gonzalo: «no lo quiero en el historial,
-- quiero un bloque del plan de desarrollo»). Un registro por persona y curso con el
-- objetivo, sus niveles de 0 a 4, la autoevaluación y la nota final. Vive en la ficha del
-- empleo y en la de cada persona del equipo (sección Trabajo). Antes se había grabado
-- como apuntes del historial (tipo Objetivo y Evaluación con nota «sobre 10»): se quitan
-- de allí y se vuelven a grabar en esta tabla.
CREATE TABLE IF NOT EXISTS plan_desarrollo (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  elemento_id    INT UNSIGNED  NOT NULL,
  curso          VARCHAR(7)    NOT NULL,
  objetivo       VARCHAR(300)  NOT NULL DEFAULT '',
  descripcion    TEXT          NULL,
  niveles        TEXT          NULL,
  autoevaluacion DECIMAL(4,2)  NULL,
  nota           DECIMAL(4,2)  NULL,
  notas          TEXT          NULL,
  actualizado_en DATETIME      NOT NULL,
  UNIQUE KEY un_curso (elemento_id, curso),
  FOREIGN KEY (elemento_id) REFERENCES elementos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM registros WHERE tipo = 'Objetivo';

DELETE FROM registros WHERE tipo = 'Evaluación' AND unidad = 'sobre 10';
