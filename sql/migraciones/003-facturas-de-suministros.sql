-- Las primeras facturas de los suministros (3/10/2026) se grabaron antes de que
-- existiera el tipo «Factura» en el historial de Contratos, con el tipo vacío y
-- un título que empieza por «Factura». Se pasan al tipo nuevo para que entren en
-- la vista de gasto anual de suministros. Idempotente: solo toca los que siguen
-- sin tipo.
UPDATE registros SET tipo = 'Factura' WHERE tipo = '' AND titulo LIKE 'Factura %' AND elemento_id IN (SELECT id FROM elementos WHERE seccion = 'contratos' AND tipo = 'suministro');
