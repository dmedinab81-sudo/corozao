-- Registra el IVA de compra por separado del costo que alimenta el Kardex.
-- Migración aditiva: las compras existentes conservan sus valores y quedan con
-- tarifa e impuesto NULL porque el desglose histórico no está disponible.
-- Aplicar manualmente tras respaldo; no ejecutar en producción sin autorización.
ALTER TABLE `compra_detalles`
    ADD COLUMN `tarifa_iva_compra` DECIMAL(5,2) NULL AFTER `costo_total`,
    ADD COLUMN `impuesto_iva` DECIMAL(14,4) NULL AFTER `tarifa_iva_compra`,
    ADD CONSTRAINT `chk_compra_iva_tarifa` CHECK
        (`tarifa_iva_compra` IS NULL OR `tarifa_iva_compra` BETWEEN 0 AND 100),
    ADD CONSTRAINT `chk_compra_iva_importe` CHECK
        (`impuesto_iva` IS NULL OR `impuesto_iva` >= 0),
    ADD CONSTRAINT `chk_compra_iva_pareja` CHECK
        ((`tarifa_iva_compra` IS NULL AND `impuesto_iva` IS NULL) OR
         (`tarifa_iva_compra` IS NOT NULL AND `impuesto_iva` IS NOT NULL));
