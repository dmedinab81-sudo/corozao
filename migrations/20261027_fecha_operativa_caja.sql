-- Marca el día comercial local de cada caja nueva para exigir cierre diario.
-- Columna aditiva y nullable: no asigna fechas a cajas históricas.
-- Aplicar manualmente tras revisar respaldo; no ejecuta cierres ni cambia ventas.
ALTER TABLE `cajas`
    ADD COLUMN `fecha_operativa` DATE NULL,
    ADD KEY `idx_cajas_fecha_operativa` (`bodega_id`, `fecha_operativa`, `estado`);
