-- Ventas, pagos divididos, sesiones de caja y estructura para factura electrónica.
-- Migración incremental: conserva filas, precios, pedidos y movimientos existentes.
-- No emite comprobantes ni contiene claves/certificados del SRI.
-- Aplicar manualmente después de revisar respaldo y coordinar mantenimiento.
CREATE TABLE `cajas` (
    `caja_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bodega_id` INT UNSIGNED NOT NULL,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `estado` ENUM('ABIERTA', 'CERRADA') NOT NULL DEFAULT 'ABIERTA',
    `caja_abierta_unica` TINYINT GENERATED ALWAYS AS
        (CASE WHEN `estado` = 'ABIERTA' THEN 1 ELSE NULL END) PERSISTENT,
    `monto_apertura` DECIMAL(14,2) NOT NULL,
    `monto_esperado` DECIMAL(14,2) NULL,
    `monto_declarado` DECIMAL(14,2) NULL,
    `diferencia` DECIMAL(14,2) NULL,
    `observaciones_cierre` VARCHAR(500) NULL,
    `cerrado_por_usuario_id` BIGINT UNSIGNED NULL,
    `fecha_apertura` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_cierre` DATETIME(6) NULL,
    PRIMARY KEY (`caja_id`),
    UNIQUE KEY `uq_caja_abierta_usuario_bodega` (`usuario_id`, `bodega_id`, `caja_abierta_unica`),
    KEY `idx_cajas_usuario_estado_fecha` (`usuario_id`, `estado`, `fecha_apertura`),
    KEY `idx_cajas_bodega_estado_fecha` (`bodega_id`, `estado`, `fecha_apertura`),
    CONSTRAINT `fk_cajas_bodega`
        FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_cajas_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_cajas_cerrador`
        FOREIGN KEY (`cerrado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_cajas_apertura` CHECK (`monto_apertura` >= 0),
    CONSTRAINT `chk_cajas_estado_cierre` CHECK
        ((`estado` = 'ABIERTA' AND `fecha_cierre` IS NULL) OR
         (`estado` = 'CERRADA' AND `fecha_cierre` IS NOT NULL AND
          `monto_esperado` IS NOT NULL AND `monto_declarado` IS NOT NULL AND `diferencia` IS NOT NULL))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ventas` (
    `venta_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id` BIGINT UNSIGNED NOT NULL,
    `caja_id` BIGINT UNSIGNED NOT NULL,
    `bodega_id` INT UNSIGNED NOT NULL,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `estado` ENUM('CONFIRMADA') NOT NULL DEFAULT 'CONFIRMADA',
    `subtotal` DECIMAL(14,2) NOT NULL,
    `impuestos` DECIMAL(14,2) NOT NULL,
    `descuento_valor` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total` DECIMAL(14,2) NOT NULL,
    `tipo_destinatario` ENUM('RUC', 'CEDULA', 'PASAPORTE', 'CONSUMIDOR_FINAL') NOT NULL,
    `identificacion_destinatario` VARCHAR(20) NULL,
    `nombre_destinatario` VARCHAR(300) NOT NULL,
    `direccion_destinatario` VARCHAR(300) NULL,
    `correo_destinatario` VARCHAR(150) NULL,
    `clave_idempotencia` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `fecha_venta` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`venta_id`),
    UNIQUE KEY `uq_ventas_pedido` (`pedido_id`),
    UNIQUE KEY `uq_ventas_idempotencia` (`clave_idempotencia`),
    KEY `idx_ventas_caja_fecha` (`caja_id`, `fecha_venta`),
    KEY `idx_ventas_bodega_fecha` (`bodega_id`, `fecha_venta`),
    KEY `idx_ventas_usuario_fecha` (`usuario_id`, `fecha_venta`),
    CONSTRAINT `fk_ventas_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`pedido_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ventas_caja`
        FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`caja_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ventas_bodega`
        FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_ventas_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_ventas_importes` CHECK
        (`subtotal` >= 0 AND `impuestos` >= 0 AND `descuento_valor` >= 0 AND `total` >= 0),
    CONSTRAINT `chk_ventas_destinatario` CHECK
        ((`tipo_destinatario` = 'CONSUMIDOR_FINAL' AND `identificacion_destinatario` IS NULL) OR
         (`tipo_destinatario` <> 'CONSUMIDOR_FINAL' AND `identificacion_destinatario` IS NOT NULL))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `venta_detalles` (
    `venta_detalle_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `venta_id` BIGINT UNSIGNED NOT NULL,
    `pedido_detalle_id` BIGINT UNSIGNED NOT NULL,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `presentacion_id` BIGINT UNSIGNED NOT NULL,
    `precio_bodega_id` BIGINT UNSIGNED NOT NULL,
    `cantidad_presentacion` DECIMAL(14,3) NOT NULL,
    `unidades_base` DECIMAL(12,3) NOT NULL,
    `cantidad_base` DECIMAL(14,3) NOT NULL,
    `precio_unitario_presentacion` DECIMAL(12,4) NOT NULL,
    `precio_incluye_iva` TINYINT(1) NOT NULL,
    `tarifa_iva` DECIMAL(5,2) NOT NULL,
    `subtotal` DECIMAL(14,2) NOT NULL,
    `impuesto` DECIMAL(14,2) NOT NULL,
    `total_bruto` DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (`venta_detalle_id`),
    UNIQUE KEY `uq_venta_detalle_pedido_detalle` (`pedido_detalle_id`),
    KEY `idx_venta_detalles_venta` (`venta_id`),
    KEY `idx_venta_detalles_producto` (`producto_id`),
    KEY `idx_venta_detalles_presentacion` (`presentacion_id`),
    CONSTRAINT `fk_venta_detalles_venta`
        FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`venta_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_venta_detalles_pedido_detalle`
        FOREIGN KEY (`pedido_detalle_id`) REFERENCES `pedido_detalles` (`pedido_detalle_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_venta_detalles_producto`
        FOREIGN KEY (`producto_id`) REFERENCES `productos` (`producto_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_venta_detalles_presentacion`
        FOREIGN KEY (`presentacion_id`) REFERENCES `presentaciones` (`presentacion_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_venta_detalles_precio`
        FOREIGN KEY (`precio_bodega_id`) REFERENCES `precios_bodega` (`precio_bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_venta_detalles_cantidad` CHECK
        (`cantidad_presentacion` > 0 AND `unidades_base` > 0 AND `cantidad_base` > 0),
    CONSTRAINT `chk_venta_detalles_importes` CHECK
        (`precio_unitario_presentacion` >= 0 AND `tarifa_iva` >= 0 AND
         `subtotal` >= 0 AND `impuesto` >= 0 AND `total_bruto` >= 0),
    CONSTRAINT `chk_venta_detalles_incluye_iva` CHECK (`precio_incluye_iva` IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `venta_pagos` (
    `venta_pago_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `venta_id` BIGINT UNSIGNED NOT NULL,
    `medio` ENUM('EFECTIVO', 'TRANSFERENCIA', 'TARJETA_CREDITO') NOT NULL,
    `monto` DECIMAL(14,2) NOT NULL,
    `referencia` VARCHAR(100) NULL,
    `fecha_pago` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`venta_pago_id`),
    KEY `idx_venta_pagos_venta_medio` (`venta_id`, `medio`),
    CONSTRAINT `fk_venta_pagos_venta`
        FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`venta_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_venta_pago_monto` CHECK (`monto` > 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `facturas_electronicas` (
    `factura_electronica_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `venta_id` BIGINT UNSIGNED NOT NULL,
    `estado` ENUM('PENDIENTE', 'GENERADA', 'ENVIADA', 'AUTORIZADA', 'RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
    `ambiente` ENUM('PRUEBAS', 'PRODUCCION') NULL,
    `establecimiento` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `punto_emision` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `secuencial` CHAR(9) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `clave_acceso` CHAR(49) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `numero_autorizacion` VARCHAR(64) NULL,
    `fecha_autorizacion` DATETIME(6) NULL,
    `xml_firmado` MEDIUMTEXT NULL,
    `respuesta_sri` MEDIUMTEXT NULL,
    `fecha_creacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_actualizacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`factura_electronica_id`),
    UNIQUE KEY `uq_facturas_electronicas_venta` (`venta_id`),
    UNIQUE KEY `uq_facturas_electronicas_clave` (`clave_acceso`),
    UNIQUE KEY `uq_facturas_electronicas_secuencial` (`establecimiento`, `punto_emision`, `secuencial`),
    CONSTRAINT `fk_facturas_electronicas_venta`
        FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`venta_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_factura_autorizada` CHECK
        (`estado` <> 'AUTORIZADA' OR
         (`clave_acceso` IS NOT NULL AND `numero_autorizacion` IS NOT NULL AND `fecha_autorizacion` IS NOT NULL))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `movimientos_inventario`
    ADD COLUMN `venta_id` BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY `uq_movimiento_venta` (`venta_id`),
    ADD CONSTRAINT `fk_movimiento_venta`
        FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`venta_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE `pedidos`
    MODIFY COLUMN `estado`
        ENUM('ABIERTO', 'EN_PREPARACION', 'PENDIENTE_FACTURACION', 'FACTURADO') NOT NULL DEFAULT 'ABIERTO';
