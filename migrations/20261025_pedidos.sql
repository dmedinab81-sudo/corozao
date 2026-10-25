-- Pedidos abiertos y sus líneas. Migración incremental: solo crea tablas nuevas.
-- No modifica catálogo, precios, inventario, usuarios ni filas existentes.
-- Aplicar manualmente después de revisar respaldo y coordinar mantenimiento.
CREATE TABLE `pedidos` (
    `pedido_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bodega_id` INT UNSIGNED NOT NULL,
    `estado` ENUM('ABIERTO', 'EN_PREPARACION', 'PENDIENTE_FACTURACION') NOT NULL DEFAULT 'ABIERTO',
    `referencia_cliente` VARCHAR(100) NULL,
    `observaciones` VARCHAR(500) NULL,
    `clave_idempotencia` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `actualizado_por_usuario_id` BIGINT UNSIGNED NOT NULL,
    `solicita_cierre_por_usuario_id` BIGINT UNSIGNED NULL,
    `fecha_creacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_actualizacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_envio_preparacion` DATETIME(6) NULL,
    `fecha_solicitud_cierre` DATETIME(6) NULL,
    PRIMARY KEY (`pedido_id`),
    UNIQUE KEY `uq_pedidos_idempotencia` (`clave_idempotencia`),
    KEY `idx_pedidos_bodega_estado_fecha` (`bodega_id`, `estado`, `fecha_creacion`),
    KEY `idx_pedidos_usuario_estado_fecha` (`usuario_id`, `estado`, `fecha_creacion`),
    CONSTRAINT `fk_pedidos_bodega`
        FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pedidos_creador`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pedidos_actualizador`
        FOREIGN KEY (`actualizado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pedidos_solicitante_cierre`
        FOREIGN KEY (`solicita_cierre_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_pedidos_envio_preparacion`
        CHECK (`estado` <> 'EN_PREPARACION' OR `fecha_envio_preparacion` IS NOT NULL),
    CONSTRAINT `chk_pedidos_solicitud_cierre`
        CHECK (`estado` <> 'PENDIENTE_FACTURACION' OR `fecha_solicitud_cierre` IS NOT NULL)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pedido_detalles` (
    `pedido_detalle_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id` BIGINT UNSIGNED NOT NULL,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `presentacion_id` BIGINT UNSIGNED NOT NULL,
    `precio_bodega_id` BIGINT UNSIGNED NOT NULL,
    `cantidad_presentacion` DECIMAL(14,3) NOT NULL,
    `unidades_base` DECIMAL(12,3) NOT NULL,
    `cantidad_base` DECIMAL(14,3) NOT NULL,
    `precio_unitario_presentacion` DECIMAL(12,4) NOT NULL,
    `precio_incluye_iva` TINYINT(1) NOT NULL,
    `tarifa_iva` DECIMAL(5,2) NOT NULL,
    `subtotal_linea` DECIMAL(26,7) NOT NULL,
    `usuario_agrega_id` BIGINT UNSIGNED NOT NULL,
    `fecha_creacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`pedido_detalle_id`),
    UNIQUE KEY `uq_pedido_detalle_presentacion` (`pedido_id`, `presentacion_id`),
    KEY `idx_pedido_detalles_producto` (`producto_id`),
    KEY `idx_pedido_detalles_presentacion` (`presentacion_id`),
    KEY `idx_pedido_detalles_precio` (`precio_bodega_id`),
    CONSTRAINT `fk_pedido_detalles_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`pedido_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pedido_detalles_producto`
        FOREIGN KEY (`producto_id`) REFERENCES `productos` (`producto_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pedido_detalles_presentacion`
        FOREIGN KEY (`presentacion_id`) REFERENCES `presentaciones` (`presentacion_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pedido_detalles_precio`
        FOREIGN KEY (`precio_bodega_id`) REFERENCES `precios_bodega` (`precio_bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_pedido_detalles_usuario`
        FOREIGN KEY (`usuario_agrega_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_pedido_cantidad_presentacion` CHECK (`cantidad_presentacion` > 0),
    CONSTRAINT `chk_pedido_unidades_base` CHECK (`unidades_base` > 0),
    CONSTRAINT `chk_pedido_cantidad_base` CHECK (`cantidad_base` > 0),
    CONSTRAINT `chk_pedido_precio` CHECK (`precio_unitario_presentacion` >= 0),
    CONSTRAINT `chk_pedido_incluye_iva` CHECK (`precio_incluye_iva` IN (0, 1)),
    CONSTRAINT `chk_pedido_tarifa_iva` CHECK (`tarifa_iva` >= 0),
    CONSTRAINT `chk_pedido_subtotal` CHECK (`subtotal_linea` >= 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
