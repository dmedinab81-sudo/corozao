-- Conserva en la venta la referencia del cliente, también en ventas directas.
-- En ventas cobradas desde pedidos se copia referencia_cliente del pedido.
-- Migración aditiva; no cambia ni elimina filas existentes.
-- Aplicar manualmente después de revisar respaldo; no ejecutar automáticamente.
ALTER TABLE `ventas`
    ADD COLUMN `referencia_cliente` VARCHAR(100) NULL AFTER `pedido_id`;
