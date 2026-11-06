-- Habilita ventas sin pedido previo y conserva snapshots comerciales por línea.
-- Aditiva respecto de filas: los registros existentes quedan intactos y los
-- nuevos campos de snapshot quedan NULL para ventas históricas.
-- Requiere revisión de respaldo y aplicación manual; no ejecutar en producción
-- sin autorización expresa.
ALTER TABLE `ventas`
    MODIFY COLUMN `pedido_id` BIGINT UNSIGNED NULL;

ALTER TABLE `venta_detalles`
    MODIFY COLUMN `pedido_detalle_id` BIGINT UNSIGNED NULL,
    ADD COLUMN `producto_nombre_snapshot` VARCHAR(200) NULL AFTER `venta_id`,
    ADD COLUMN `marca_snapshot` VARCHAR(100) NULL AFTER `producto_nombre_snapshot`,
    ADD COLUMN `contenido_snapshot` VARCHAR(100) NULL AFTER `marca_snapshot`,
    ADD COLUMN `categoria_snapshot` VARCHAR(150) NULL AFTER `contenido_snapshot`,
    ADD COLUMN `presentacion_snapshot` VARCHAR(150) NULL AFTER `categoria_snapshot`,
    ADD COLUMN `codigo_snapshot` VARCHAR(100) NULL AFTER `presentacion_snapshot`;
