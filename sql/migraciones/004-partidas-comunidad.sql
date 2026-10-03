-- Desglose de cada recibo de la comunidad de propietarios (3/10/2026): qué
-- partidas trae la liquidación (piscina, ascensor, limpieza…), cuánto paga la
-- comunidad o la escalera por cada una y cuánto le toca a esta casa. De aquí
-- sale la página «Gasto en comunidad». El recibo es un apunte del historial
-- (registros, tipo «Recibo») de un elemento contratos/comunidad.
CREATE TABLE IF NOT EXISTS partidas (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  registro_id    INT UNSIGNED  NOT NULL,
  concepto       VARCHAR(150)  NOT NULL,
  categoria      VARCHAR(40)   NOT NULL,
  zona           VARCHAR(20)   NOT NULL DEFAULT '',
  total          DECIMAL(10,2) NOT NULL,
  parte          DECIMAL(10,2) NOT NULL,
  extraordinaria TINYINT       NOT NULL DEFAULT 0,
  KEY por_registro (registro_id),
  FOREIGN KEY (registro_id) REFERENCES registros (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
