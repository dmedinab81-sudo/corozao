-- Trazabilidad de cargas iniciales basadas en conteos físicos cerrados.
-- Tablas aditivas; no insertan ni actualizan saldos o movimientos existentes.
-- Aplicar manualmente tras respaldo y revisión. No ejecutar en producción desde la aplicación.
CREATE TABLE `cargas_inventario_inicial` (
    `carga_inicial_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bodega_id` INT UNSIGNED NOT NULL,
    `conteo_id` BIGINT UNSIGNED NOT NULL,
    `movimiento_id` BIGINT UNSIGNED NULL,
    `estado` ENUM('BORRADOR', 'CONFIRMADA') NOT NULL DEFAULT 'BORRADOR',
    `clave_idempotencia` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `confirmada_por_usuario_id` BIGINT UNSIGNED NULL,
    `fecha_creacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_confirmacion` DATETIME(6) NULL,
    PRIMARY KEY (`carga_inicial_id`),
    UNIQUE KEY `uq_carga_inicial_movimiento` (`movimiento_id`),
    UNIQUE KEY `uq_carga_inicial_idempotencia` (`clave_idempotencia`),
    KEY `idx_cargas_iniciales_bodega_fecha` (`bodega_id`, `fecha_creacion`),
    KEY `idx_cargas_iniciales_conteo` (`conteo_id`),
    CONSTRAINT `fk_cargas_iniciales_bodega`
        FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_cargas_iniciales_conteo`
        FOREIGN KEY (`conteo_id`) REFERENCES `conteos_inventario` (`conteo_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_cargas_iniciales_movimiento`
        FOREIGN KEY (`movimiento_id`) REFERENCES `movimientos_inventario` (`movimiento_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_cargas_iniciales_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_cargas_iniciales_confirmador`
        FOREIGN KEY (`confirmada_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_cargas_iniciales_estado` CHECK
        ((`estado` = 'BORRADOR' AND `movimiento_id` IS NULL AND
          `confirmada_por_usuario_id` IS NULL AND `fecha_confirmacion` IS NULL) OR
         (`estado` = 'CONFIRMADA' AND `movimiento_id` IS NOT NULL AND
          `confirmada_por_usuario_id` IS NOT NULL AND `fecha_confirmacion` IS NOT NULL))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cargas_inventario_inicial_detalles` (
    `carga_inicial_detalle_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `carga_inicial_id` BIGINT UNSIGNED NOT NULL,
    `bodega_id` INT UNSIGNED NOT NULL,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `conteo_detalle_id` BIGINT UNSIGNED NOT NULL,
    `cantidad_base` DECIMAL(14,3) NOT NULL,
    `saldo_anterior` DECIMAL(14,3) NOT NULL,
    `saldo_posterior` DECIMAL(14,3) NOT NULL,
    PRIMARY KEY (`carga_inicial_detalle_id`),
    UNIQUE KEY `uq_carga_inicial_bodega_producto` (`bodega_id`, `producto_id`),
    UNIQUE KEY `uq_carga_inicial_conteo_detalle` (`conteo_detalle_id`),
    KEY `idx_carga_inicial_detalles_carga` (`carga_inicial_id`),
    CONSTRAINT `fk_carga_inicial_detalles_carga`
        FOREIGN KEY (`carga_inicial_id`) REFERENCES `cargas_inventario_inicial` (`carga_inicial_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_carga_inicial_detalles_bodega`
        FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_carga_inicial_detalles_producto`
        FOREIGN KEY (`producto_id`) REFERENCES `productos` (`producto_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_carga_inicial_detalles_conteo`
        FOREIGN KEY (`conteo_detalle_id`) REFERENCES `conteo_inventario_detalles` (`conteo_detalle_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_carga_inicial_detalles_cantidades` CHECK
        (`cantidad_base` > 0 AND `saldo_anterior` >= 0 AND `saldo_posterior` >= 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
