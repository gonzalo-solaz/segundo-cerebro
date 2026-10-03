-- Un elemento puede pertenecer a otro: el suministro o el seguro de una
-- vivienda, el seguro de un vehículo. Solo guarda el id del elemento «padre»;
-- sin clave foránea (ALTER ... ADD COLUMN no la lleva en SQLite) y por eso
-- borrar_elemento() deja a null a los que apuntaban al borrado.
ALTER TABLE elementos ADD COLUMN enlace_id INT UNSIGNED NULL;
