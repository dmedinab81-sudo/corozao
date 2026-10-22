-- Flujo de transferencias: despacho y recepción en dos transacciones atómicas.
-- Preserva las filas, saldos y estados existentes; aplicar manualmente después
-- de revisar respaldo y coordinar una ventana de mantenimiento.
ALTER TABLE `movimientos_inventario`
    MODIFY COLUMN `estado`
        ENUM('BORRADOR', 'EN_TRANSITO', 'CONFIRMADO') NOT NULL DEFAULT 'BORRADOR',
    ADD COLUMN `clave_idempotencia` CHAR(36)
        CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN `despachado_por_usuario_id` BIGINT UNSIGNED NULL,
    ADD COLUMN `fecha_despacho` DATETIME(6) NULL,
    ADD COLUMN `recibido_por_usuario_id` BIGINT UNSIGNED NULL,
    ADD COLUMN `fecha_recepcion` DATETIME(6) NULL,
    ADD UNIQUE KEY `uq_mov_clave_idempotencia` (`clave_idempotencia`),
    ADD KEY `idx_mov_estado_destino_fecha`
        (`estado`, `bodega_destino_id`, `fecha_movimiento`),
    ADD CONSTRAINT `fk_mov_despachador`
        FOREIGN KEY (`despachado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT `fk_mov_receptor`
        FOREIGN KEY (`recibido_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    ADD CONSTRAINT `chk_mov_en_transito_transferencia`
        CHECK (`estado` <> 'EN_TRANSITO' OR `tipo_movimiento` = 'TRANSFERENCIA');
