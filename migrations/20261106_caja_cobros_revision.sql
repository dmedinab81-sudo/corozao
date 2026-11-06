-- Datos adicionales de cobro en efectivo, movimientos autorizados de caja y
-- revisión administrativa de diferencias. Migración aditiva: no reescribe
-- ventas ni cierres existentes. Aplicación manual; no ejecutar en producción
-- sin autorización y respaldo.

ALTER TABLE `ventas`
    ADD COLUMN `efectivo_recibido` DECIMAL(14,2) NULL AFTER `descuento_valor`,
    ADD COLUMN `cambio_entregado` DECIMAL(14,2) NULL AFTER `efectivo_recibido`;

CREATE TABLE `caja_movimientos` (
    `caja_movimiento_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `caja_id` BIGINT UNSIGNED NOT NULL,
    `tipo` ENUM('INGRESO', 'RETIRO') NOT NULL,
    `monto` DECIMAL(14,2) NOT NULL,
    `motivo` VARCHAR(500) NOT NULL,
    `clave_idempotencia` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `registrado_por_usuario_id` BIGINT UNSIGNED NOT NULL,
    `autorizado_por_usuario_id` BIGINT UNSIGNED NOT NULL,
    `fecha_creacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`caja_movimiento_id`),
    UNIQUE KEY `uq_caja_movimientos_idempotencia` (`clave_idempotencia`),
    KEY `idx_caja_movimientos_caja_fecha` (`caja_id`, `fecha_creacion`),
    CONSTRAINT `fk_caja_movimientos_caja`
        FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`caja_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_movimientos_registrador`
        FOREIGN KEY (`registrado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_movimientos_autorizador`
        FOREIGN KEY (`autorizado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_caja_movimientos_monto` CHECK (`monto` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `caja_revision_diferencias` (
    `caja_revision_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `caja_id` BIGINT UNSIGNED NOT NULL,
    `estado` ENUM('PENDIENTE', 'APROBADA', 'RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
    `detalle_diferencias` JSON NOT NULL,
    `cerrado_por_usuario_id` BIGINT UNSIGNED NOT NULL,
    `revisado_por_usuario_id` BIGINT UNSIGNED NULL,
    `observaciones_revision` VARCHAR(500) NULL,
    `fecha_creacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_revision` DATETIME(6) NULL,
    PRIMARY KEY (`caja_revision_id`),
    UNIQUE KEY `uq_caja_revision_diferencias_caja` (`caja_id`),
    KEY `idx_caja_revision_diferencias_estado_fecha` (`estado`, `fecha_creacion`),
    CONSTRAINT `fk_caja_revision_diferencias_caja`
        FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`caja_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_revision_cerrador`
        FOREIGN KEY (`cerrado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_revision_revisor`
        FOREIGN KEY (`revisado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_caja_revision_estado_fecha` CHECK
        ((`estado` = 'PENDIENTE' AND `revisado_por_usuario_id` IS NULL AND `fecha_revision` IS NULL)
         OR (`estado` IN ('APROBADA', 'RECHAZADA') AND `revisado_por_usuario_id` IS NOT NULL AND `fecha_revision` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
