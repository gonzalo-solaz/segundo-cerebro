-- Hipoteca que se calcula sola (9/10/2026, Gonzalo: «quiero un segundo cerebro dinámico:
-- que al entrar consulte el Euríbor y que la tabla de amortización se actualice sola»).
--
-- 1) El tipo de interés de cada cuota, junto a su precio: así el historial de precios de
--    la hipoteca es también su historial de tipos (el cuadro del banco hacia atrás y, en
--    cada revisión, el que calcula la app con el Euríbor). Vacío en el resto de contratos.
ALTER TABLE precios ADD COLUMN tipo DECIMAL(6,3) NULL;

-- 2) El Euríbor a un año, media de cada mes (la referencia oficial de las hipotecas). Se
--    calcula con los valores diarios del Banco de España; el mes en curso es provisional.
CREATE TABLE IF NOT EXISTS euribor (
  mes            VARCHAR(7)   NOT NULL PRIMARY KEY,
  valor          DECIMAL(7,3) NOT NULL,
  dias           INT          NOT NULL DEFAULT 0,
  definitivo     TINYINT      NOT NULL DEFAULT 0,
  actualizado_en DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
