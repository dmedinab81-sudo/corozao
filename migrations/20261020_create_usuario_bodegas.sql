-- Relación incremental de usuarios operativos con bodegas asignadas.
-- No modifica usuarios, bodegas ni asigna bodegas automáticamente.
-- Aplicar manualmente después de revisar un respaldo de la base de datos.
CREATE TABLE `usuario_bodegas` (
    `usuario_bodega_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `bodega_id` INT UNSIGNED NOT NULL,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `asignado_por_usuario_id` BIGINT UNSIGNED NOT NULL,
    `actualizado_por_usuario_id` BIGINT UNSIGNED NULL,
    `fecha_asignacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `fecha_actualizacion` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`usuario_bodega_id`),
    UNIQUE KEY `uq_usuario_bodega` (`usuario_id`, `bodega_id`),
    KEY `idx_usuario_bodegas_bodega_activo` (`bodega_id`, `activo`),
    KEY `idx_usuario_bodegas_usuario_activo` (`usuario_id`, `activo`),
    CONSTRAINT `fk_usuario_bodegas_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_usuario_bodegas_bodega`
        FOREIGN KEY (`bodega_id`) REFERENCES `bodegas` (`bodega_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_usuario_bodegas_asignador`
        FOREIGN KEY (`asignado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `fk_usuario_bodegas_actualizador`
        FOREIGN KEY (`actualizado_por_usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT `chk_usuario_bodegas_activo` CHECK (`activo` IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
