-- Reversiones completas y auditables de compras confirmadas.
-- La compra y el movimiento originales se conservan; el sistema crea una
-- salida compensatoria vinculada y actualiza el saldo en una transacción.
-- Una compra solo admite una reversión completa. No modifica filas existentes.
-- Aplicar manualmente después de respaldo; no ejecutar en producción sin autorización.
CREATE TABLE `reversiones_compras` (
    `reversion_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `compra_id` BIGINT UNSIGNED NOT NULL,
    `movimiento_id` BIGINT UNSIGNED NOT NULL,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `motivo` VARCHAR(500) NOT NULL,
    `fecha_hora` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`reversion_id`),
    UNIQUE KEY `uq_reversion_compra` (`compra_id`),
    UNIQUE KEY `uq_reversion_movimiento` (`movimiento_id`),
    KEY `idx_reversion_usuario_fecha` (`usuario_id`, `fecha_hora`),
    CONSTRAINT `fk_reversion_compra`
        FOREIGN KEY (`compra_id`) REFERENCES `compras` (`compra_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_reversion_movimiento`
        FOREIGN KEY (`movimiento_id`) REFERENCES `movimientos_inventario` (`movimiento_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_reversion_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_reversion_motivo` CHECK (CHAR_LENGTH(TRIM(`motivo`)) > 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
