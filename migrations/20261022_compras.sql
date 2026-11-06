-- Tablas incrementales de compras y enlace único con el movimiento de ingreso.
-- No reconstruye ni modifica filas existentes; las columnas nuevas quedan NULL.
-- Aplicar manualmente después de revisar respaldo y coordinar mantenimiento.
CREATE TABLE `compras` (
    `compra_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `proveedor_id` BIGINT UNSIGNED NOT NULL,
    `bodega_id` INT UNSIGNED NOT NULL,
    `estado` ENUM('BORRADOR', 'CONFIRMADA') NOT NULL DEFAULT 'BORRADOR',
    `referencia_proveedor` VARCHAR(100) NULL,
    `observaciones` VARCHAR(500) NULL,
    `clave_idempotencia` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `confirmado_por_usuario_id` BIGINT UNSIGNED NULL,
    `fecha_compra` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_confirmacion` DATETIME(6) NULL,
    `fecha_creacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`compra_id`),
    UNIQUE KEY `uq_compras_idempotencia` (`clave_idempotencia`),
    KEY `idx_compras_estado_fecha` (`estado`, `fecha_compra`),
    KEY `idx_compras_proveedor_fecha` (`proveedor_id`, `fecha_compra`),
    CONSTRAINT `fk_compras_proveedor`
        FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`proveedor_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_compras_bodega`
        FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_compras_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_compras_confirmador`
        FOREIGN KEY (`confirmado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `compra_detalles` (
    `compra_detalle_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `compra_id` BIGINT UNSIGNED NOT NULL,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `presentacion_id` BIGINT UNSIGNED NOT NULL,
    `cantidad_presentacion` DECIMAL(14,3) NOT NULL,
    `unidades_base` DECIMAL(12,3) NOT NULL,
    `cantidad_base` DECIMAL(14,3) NOT NULL,
    `costo_unitario_presentacion` DECIMAL(12,4) NOT NULL,
    `costo_total` DECIMAL(14,4) NOT NULL,
    PRIMARY KEY (`compra_detalle_id`),
    UNIQUE KEY `uq_compra_detalle_presentacion` (`compra_id`, `presentacion_id`),
    KEY `idx_compra_detalles_producto` (`producto_id`),
    KEY `idx_compra_detalles_presentacion` (`presentacion_id`),
    CONSTRAINT `fk_compra_detalles_compra`
        FOREIGN KEY (`compra_id`) REFERENCES `compras` (`compra_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_compra_detalles_producto`
        FOREIGN KEY (`producto_id`) REFERENCES `productos` (`producto_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_compra_detalles_presentacion`
        FOREIGN KEY (`presentacion_id`) REFERENCES `presentaciones` (`presentacion_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_compra_cantidad_presentacion` CHECK (`cantidad_presentacion` > 0),
    CONSTRAINT `chk_compra_unidades_base` CHECK (`unidades_base` > 0),
    CONSTRAINT `chk_compra_cantidad_base` CHECK (`cantidad_base` > 0),
    CONSTRAINT `chk_compra_costo_unitario` CHECK (`costo_unitario_presentacion` >= 0),
    CONSTRAINT `chk_compra_costo_total` CHECK (`costo_total` >= 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `movimientos_inventario`
    ADD COLUMN `compra_id` BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY `uq_movimiento_compra` (`compra_id`),
    ADD CONSTRAINT `fk_movimiento_compra`
        FOREIGN KEY (`compra_id`) REFERENCES `compras` (`compra_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT;
