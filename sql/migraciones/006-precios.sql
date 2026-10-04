-- Lo que costaba cada contrato a lo largo del tiempo (4/10/2026, Gonzalo: «comparar
-- cada partida con el año anterior y con el IPC»). La ficha guarda solo el precio
-- de hoy; aquí queda cada precio con la fecha desde la que rige. Se apunta solo al
-- cambiar el coste en la ficha (con el anterior, si no estaba) y, para atrás, por
-- la API (acción «precio»). Las facturas y recibos siguen en registros.
CREATE TABLE IF NOT EXISTS precios (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  elemento_id  INT UNSIGNED  NOT NULL,
  desde        DATE          NOT NULL,
  coste        DECIMAL(10,2) NOT NULL,
  periodicidad VARCHAR(20)   NOT NULL,
  nota         VARCHAR(200)  NOT NULL DEFAULT '',
  creado_en    DATETIME      NOT NULL,
  KEY por_elemento (elemento_id, desde),
  FOREIGN KEY (elemento_id) REFERENCES elementos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
