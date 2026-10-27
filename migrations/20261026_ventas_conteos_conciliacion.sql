-- Conciliación por medio de pago y conteos físicos auditables.
-- Migración aditiva: no actualiza ni corrige saldos o movimientos existentes.
-- Aplicar manualmente después de revisar respaldo y coordinar mantenimiento.
CREATE TABLE `caja_conciliaciones` (
    `caja_conciliacion_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `caja_id` BIGINT UNSIGNED NOT NULL,
    `medio` ENUM('EFECTIVO', 'TRANSFERENCIA', 'TARJETA_CREDITO') NOT NULL,
    `monto_esperado` DECIMAL(14,2) NOT NULL,
    `monto_declarado` DECIMAL(14,2) NOT NULL,
    `diferencia` DECIMAL(14,2) NOT NULL,
    `fecha_creacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`caja_conciliacion_id`),
    UNIQUE KEY `uq_caja_conciliacion_medio` (`caja_id`, `medio`),
    CONSTRAINT `fk_caja_conciliaciones_caja`
        FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`caja_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_caja_conciliacion_importes` CHECK
        (`monto_esperado` >= 0 AND `monto_declarado` >= 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `conteos_inventario` (
    `conteo_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bodega_id` INT UNSIGNED NOT NULL,
    `estado` ENUM('ABIERTO', 'CERRADO') NOT NULL DEFAULT 'ABIERTO',
    `conteo_abierto_unico` TINYINT GENERATED ALWAYS AS
        (CASE WHEN `estado` = 'ABIERTO' THEN 1 ELSE NULL END) PERSISTENT,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `cerrado_por_usuario_id` BIGINT UNSIGNED NULL,
    `fecha_apertura` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_cierre` DATETIME(6) NULL,
    PRIMARY KEY (`conteo_id`),
    UNIQUE KEY `uq_conteo_abierto_bodega` (`bodega_id`, `conteo_abierto_unico`),
    KEY `idx_conteos_bodega_fecha` (`bodega_id`, `fecha_apertura`),
    KEY `idx_conteos_usuario_fecha` (`usuario_id`, `fecha_apertura`),
    CONSTRAINT `fk_conteos_bodega`
        FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_conteos_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_conteos_cerrador`
        FOREIGN KEY (`cerrado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_conteos_estado_fecha` CHECK
        ((`estado` = 'ABIERTO' AND `fecha_cierre` IS NULL) OR
         (`estado` = 'CERRADO' AND `fecha_cierre` IS NOT NULL))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `conteo_inventario_detalles` (
    `conteo_detalle_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conteo_id` BIGINT UNSIGNED NOT NULL,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `cantidad_teorica` DECIMAL(14,3) NOT NULL,
    `cantidad_fisica` DECIMAL(14,3) NOT NULL,
    `diferencia` DECIMAL(14,3) NOT NULL,
    `fecha_conteo` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`conteo_detalle_id`),
    UNIQUE KEY `uq_conteo_producto` (`conteo_id`, `producto_id`),
    KEY `idx_conteo_detalles_producto_fecha` (`producto_id`, `fecha_conteo`),
    CONSTRAINT `fk_conteo_detalles_conteo`
        FOREIGN KEY (`conteo_id`) REFERENCES `conteos_inventario` (`conteo_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_conteo_detalles_producto`
        FOREIGN KEY (`producto_id`) REFERENCES `productos` (`producto_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_conteo_detalles_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_conteo_cantidades` CHECK
        (`cantidad_teorica` >= 0 AND `cantidad_fisica` >= 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
