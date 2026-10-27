-- Solicitudes y aprobaciones auditables de devoluciones de ventas.
-- El reembolso se registra contra la caja abierta del administrador y vuelve
-- proporcionalmente a los medios originales. El stock se repone solo si el
-- administrador marca cada producto como vendible.
-- Migración aditiva: no modifica ventas, pagos, movimientos ni saldos existentes.
-- Aplicar manualmente después de revisar respaldo y coordinar mantenimiento.
CREATE TABLE `devoluciones` (
    `devolucion_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `venta_id` BIGINT UNSIGNED NOT NULL,
    `caja_id` BIGINT UNSIGNED NULL,
    `movimiento_id` BIGINT UNSIGNED NULL,
    `estado` ENUM('PENDIENTE', 'APROBADA', 'RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
    `motivo` VARCHAR(500) NOT NULL,
    `motivo_resolucion` VARCHAR(500) NULL,
    `reembolso_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `clave_idempotencia` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `solicitada_por_usuario_id` BIGINT UNSIGNED NOT NULL,
    `resuelta_por_usuario_id` BIGINT UNSIGNED NULL,
    `fecha_solicitud` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_resolucion` DATETIME(6) NULL,
    PRIMARY KEY (`devolucion_id`),
    UNIQUE KEY `uq_devoluciones_idempotencia` (`clave_idempotencia`),
    UNIQUE KEY `uq_devoluciones_movimiento` (`movimiento_id`),
    KEY `idx_devoluciones_venta_estado_fecha` (`venta_id`, `estado`, `fecha_solicitud`),
    KEY `idx_devoluciones_caja_fecha` (`caja_id`, `fecha_resolucion`),
    KEY `idx_devoluciones_solicitante_fecha` (`solicitada_por_usuario_id`, `fecha_solicitud`),
    CONSTRAINT `fk_devoluciones_venta`
        FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`venta_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_devoluciones_caja`
        FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`caja_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_devoluciones_movimiento`
        FOREIGN KEY (`movimiento_id`) REFERENCES `movimientos_inventario` (`movimiento_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_devoluciones_solicitante`
        FOREIGN KEY (`solicitada_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_devoluciones_resolutor`
        FOREIGN KEY (`resuelta_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_devoluciones_importe` CHECK (`reembolso_total` >= 0),
    CONSTRAINT `chk_devoluciones_estado` CHECK
        ((`estado` = 'PENDIENTE' AND `resuelta_por_usuario_id` IS NULL AND
          `fecha_resolucion` IS NULL AND `caja_id` IS NULL AND `movimiento_id` IS NULL AND
          `reembolso_total` = 0) OR
         (`estado` = 'APROBADA' AND `resuelta_por_usuario_id` IS NOT NULL AND
          `fecha_resolucion` IS NOT NULL AND
          ((`reembolso_total` = 0 AND `caja_id` IS NULL) OR
           (`reembolso_total` > 0 AND `caja_id` IS NOT NULL))) OR
         (`estado` = 'RECHAZADA' AND `resuelta_por_usuario_id` IS NOT NULL AND
          `fecha_resolucion` IS NOT NULL AND `caja_id` IS NULL AND
          `movimiento_id` IS NULL AND `reembolso_total` = 0))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `devolucion_detalles` (
    `devolucion_detalle_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `devolucion_id` BIGINT UNSIGNED NOT NULL,
    `venta_detalle_id` BIGINT UNSIGNED NOT NULL,
    `cantidad_base` DECIMAL(14,3) NOT NULL,
    `importe_bruto_devuelto` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `producto_vendible` TINYINT(1) NULL,
    PRIMARY KEY (`devolucion_detalle_id`),
    UNIQUE KEY `uq_devolucion_detalle_venta_linea` (`devolucion_id`, `venta_detalle_id`),
    KEY `idx_devolucion_detalles_venta_linea` (`venta_detalle_id`),
    CONSTRAINT `fk_devolucion_detalles_devolucion`
        FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones` (`devolucion_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_devolucion_detalles_venta_linea`
        FOREIGN KEY (`venta_detalle_id`) REFERENCES `venta_detalles` (`venta_detalle_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_devolucion_detalles_importes` CHECK
        (`cantidad_base` > 0 AND `importe_bruto_devuelto` >= 0 AND
         (`producto_vendible` IS NULL OR `producto_vendible` IN (0,1)))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `devolucion_pagos` (
    `devolucion_pago_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `devolucion_id` BIGINT UNSIGNED NOT NULL,
    `medio` ENUM('EFECTIVO', 'TRANSFERENCIA', 'TARJETA_CREDITO') NOT NULL,
    `monto` DECIMAL(14,2) NOT NULL,
    `referencia` VARCHAR(100) NULL,
    `fecha_pago` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`devolucion_pago_id`),
    KEY `idx_devolucion_pagos_devolucion_medio` (`devolucion_id`, `medio`),
    CONSTRAINT `fk_devolucion_pagos_devolucion`
        FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones` (`devolucion_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_devolucion_pagos_monto` CHECK (`monto` > 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Conciliación separada de salidas por transferencia y tarjeta.
-- El efectivo se concilia con el saldo físico neto de caja.
CREATE TABLE `caja_reembolsos_conciliaciones` (
    `caja_reembolso_conciliacion_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `caja_id` BIGINT UNSIGNED NOT NULL,
    `medio` ENUM('TRANSFERENCIA', 'TARJETA_CREDITO') NOT NULL,
    `monto_esperado` DECIMAL(14,2) NOT NULL,
    `monto_declarado` DECIMAL(14,2) NOT NULL,
    `diferencia` DECIMAL(14,2) NOT NULL,
    `fecha_creacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`caja_reembolso_conciliacion_id`),
    UNIQUE KEY `uq_caja_reembolso_medio` (`caja_id`, `medio`),
    CONSTRAINT `fk_caja_reembolso_conciliaciones_caja`
        FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`caja_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_caja_reembolso_importes` CHECK
        (`monto_esperado` >= 0 AND `monto_declarado` >= 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
