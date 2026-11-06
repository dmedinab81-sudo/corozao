-- Migration incremental para trazabilidad de cambios de proveedores.
-- No altera ni reconstruye proveedores, proveedor_productos ni otras tablas.
-- Aplicar manualmente después de revisar un respaldo de la base de datos.
CREATE TABLE `auditoria_eventos` (
    `evento_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `fecha_hora` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `accion` ENUM('CREAR', 'MODIFICAR', 'DESACTIVAR') NOT NULL,
    `entidad` VARCHAR(80) NOT NULL,
    `registro_id` BIGINT UNSIGNED NOT NULL,
    `valores_anteriores` JSON NULL,
    `valores_posteriores` JSON NULL,
    PRIMARY KEY (`evento_id`),
    KEY `idx_auditoria_entidad_registro_fecha`
        (`entidad`, `registro_id`, `fecha_hora`),
    KEY `idx_auditoria_usuario_fecha` (`usuario_id`, `fecha_hora`),
    CONSTRAINT `fk_auditoria_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`usuario_id`)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
