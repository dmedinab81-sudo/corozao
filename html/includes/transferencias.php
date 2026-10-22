<?php
declare(strict_types=1);

/** Confirma que la migración incremental de transferencias ya está instalada. */
function corozao_transferencias_instaladas(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla
           AND COLUMN_NAME IN (\'clave_idempotencia\', \'despachado_por_usuario_id\',
                               \'fecha_despacho\', \'recibido_por_usuario_id\', \'fecha_recepcion\')'
    );
    $stmt->execute(['tabla' => 'movimientos_inventario']);
    if ((int) $stmt->fetchColumn() !== 5) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla AND COLUMN_NAME = \'estado\'
           AND COLUMN_TYPE LIKE \'%EN_TRANSITO%\''
    );
    $stmt->execute(['tabla' => 'movimientos_inventario']);
    if ((int) $stmt->fetchColumn() !== 1) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla
           AND INDEX_NAME = \'uq_mov_clave_idempotencia\' AND NON_UNIQUE = 0'
    );
    $stmt->execute(['tabla' => 'movimientos_inventario']);
    if ((int) $stmt->fetchColumn() !== 1) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla
           AND CONSTRAINT_NAME IN (\'fk_mov_despachador\', \'fk_mov_receptor\')'
    );
    $stmt->execute(['tabla' => 'movimientos_inventario']);
    return (int) $stmt->fetchColumn() === 2;
}

/** Normaliza una cantidad positiva de hasta tres decimales sin usar flotantes. */
function corozao_cantidad_base(string $valor): string
{
    $valor = trim($valor);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,3})?$/', $valor)
        || trim(str_replace(['0', '.'], '', $valor)) === '') {
        throw new DomainException('La cantidad debe ser positiva y tener hasta tres decimales.');
    }
    [$entero, $fraccion] = array_pad(explode('.', $valor, 2), 2, '');
    $entero = ltrim($entero, '0');
    return ($entero === '' ? '0' : $entero) . '.' . str_pad($fraccion, 3, '0');
}

/** Crea un borrador y su evento auditado de forma indivisible. */
function corozao_transferencia_crear(
    PDO $pdo,
    int $usuarioId,
    int $origenId,
    int $destinoId,
    array $cantidades,
    string $observaciones,
    string $claveIdempotencia
): int {
    if ($origenId === $destinoId || $origenId < 1 || $destinoId < 1) {
        throw new DomainException('Selecciona bodegas distintas.');
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $claveIdempotencia)) {
        throw new DomainException('La clave de solicitud no es válida. Recarga la página.');
    }
    if (strlen($observaciones) > 500) {
        throw new DomainException('Las observaciones admiten hasta 500 caracteres.');
    }

    $lineas = [];
    foreach ($cantidades as $productoTexto => $cantidadTexto) {
        if (!(is_int($productoTexto) || (is_string($productoTexto) && ctype_digit($productoTexto)))) {
            throw new DomainException('Uno de los productos no es válido.');
        }
        $productoId = (int) $productoTexto;
        if ($productoId < 1 || !is_string($cantidadTexto) || trim($cantidadTexto) === '') {
            throw new DomainException('Cada producto seleccionado requiere una cantidad válida.');
        }
        if (isset($lineas[$productoId])) {
            throw new DomainException('Cada producto debe aparecer una sola vez en la transferencia.');
        }
        $lineas[$productoId] = corozao_cantidad_base($cantidadTexto);
    }
    if (!$lineas) {
        throw new DomainException('Agrega al menos un producto y su cantidad.');
    }
    ksort($lineas, SORT_NUMERIC);

    try {
        $pdo->beginTransaction();
        $lockBodegas = $pdo->prepare(
            'SELECT bodega_id FROM bodegas WHERE bodega_id IN (:origen, :destino) AND activo = 1
             ORDER BY bodega_id FOR UPDATE'
        );
        $lockBodegas->execute(['origen' => $origenId, 'destino' => $destinoId]);
        if (count($lockBodegas->fetchAll(PDO::FETCH_COLUMN)) !== 2) {
            throw new DomainException('Las dos bodegas deben existir y estar activas.');
        }

        $productoStmt = $pdo->prepare(
            'SELECT producto_id FROM productos WHERE producto_id = :producto AND activo = 1 FOR UPDATE'
        );
        foreach (array_keys($lineas) as $productoId) {
            $productoStmt->execute(['producto' => $productoId]);
            if (!$productoStmt->fetchColumn()) {
                throw new DomainException('Todos los productos deben existir y estar activos.');
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO movimientos_inventario
                (tipo_movimiento, estado, bodega_origen_id, bodega_destino_id,
                 observaciones, usuario_id, fecha_movimiento, clave_idempotencia)
             VALUES (\'TRANSFERENCIA\', \'BORRADOR\', :origen, :destino,
                     :observaciones, :usuario, CURRENT_TIMESTAMP, :clave)'
        );
        $stmt->execute([
            'origen' => $origenId,
            'destino' => $destinoId,
            'observaciones' => $observaciones === '' ? null : $observaciones,
            'usuario' => $usuarioId,
            'clave' => strtolower($claveIdempotencia),
        ]);
        $movimientoId = (int) $pdo->lastInsertId();

        $detalle = $pdo->prepare(
            'INSERT INTO detalle_movimiento_inventario
                (movimiento_id, producto_id, presentacion_id, cantidad_base, costo_unitario, costo_total)
             VALUES (:movimiento, :producto, NULL, :cantidad, NULL, NULL)'
        );
        foreach ($lineas as $productoId => $cantidad) {
            $detalle->execute([
                'movimiento' => $movimientoId,
                'producto' => $productoId,
                'cantidad' => $cantidad,
            ]);
        }

        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'movimientos_inventario', $movimientoId,
            null, ['tipo_movimiento' => 'TRANSFERENCIA', 'estado' => 'BORRADOR',
                'bodega_origen_id' => $origenId, 'bodega_destino_id' => $destinoId,
                'detalles' => array_map(static fn (int $id, string $cantidad): array =>
                    ['producto_id' => $id, 'cantidad_base' => $cantidad], array_keys($lineas), array_values($lineas))]);
        $pdo->commit();
        return $movimientoId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            $stmt = $pdo->prepare('SELECT movimiento_id FROM movimientos_inventario WHERE clave_idempotencia = :clave');
            $stmt->execute(['clave' => strtolower($claveIdempotencia)]);
            $existente = $stmt->fetchColumn();
            if ($existente) {
                return (int) $existente;
            }
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Despacha una transferencia descontando el origen y auditando dentro de la misma transacción. */
function corozao_transferencia_despachar(PDO $pdo, int $movimientoId, int $usuarioId): void
{
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT movimiento_id, tipo_movimiento, estado, bodega_origen_id, bodega_destino_id
             FROM movimientos_inventario WHERE movimiento_id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $movimientoId]);
        $movimiento = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$movimiento || $movimiento['tipo_movimiento'] !== 'TRANSFERENCIA' || $movimiento['estado'] !== 'BORRADOR') {
            throw new DomainException('La transferencia ya fue despachada o no está disponible.');
        }
        $lockBodegas = $pdo->prepare(
            'SELECT bodega_id FROM bodegas
             WHERE bodega_id IN (:origen, :destino) AND activo = 1
             ORDER BY bodega_id FOR UPDATE'
        );
        $lockBodegas->execute([
            'origen' => $movimiento['bodega_origen_id'],
            'destino' => $movimiento['bodega_destino_id'],
        ]);
        if (count($lockBodegas->fetchAll(PDO::FETCH_COLUMN)) !== 2) {
            throw new DomainException('Las dos bodegas deben permanecer activas para despachar.');
        }
        $detalles = $pdo->prepare(
            'SELECT producto_id, cantidad_base FROM detalle_movimiento_inventario
             WHERE movimiento_id = :id ORDER BY producto_id FOR UPDATE'
        );
        $detalles->execute(['id' => $movimientoId]);
        $lineas = $detalles->fetchAll(PDO::FETCH_ASSOC);
        if (!$lineas) {
            throw new DomainException('La transferencia no contiene productos.');
        }
        $crearSaldo = $pdo->prepare(
            'INSERT INTO inventario_saldos (bodega_id, producto_id, cantidad_base)
             VALUES (:bodega, :producto, 0)
             ON DUPLICATE KEY UPDATE producto_id = VALUES(producto_id)'
        );
        $lockSaldo = $pdo->prepare(
            'SELECT cantidad_base FROM inventario_saldos
             WHERE bodega_id = :bodega AND producto_id = :producto FOR UPDATE'
        );
        foreach ($lineas as $linea) {
            $crearSaldo->execute(['bodega' => $movimiento['bodega_origen_id'], 'producto' => $linea['producto_id']]);
            $lockSaldo->execute(['bodega' => $movimiento['bodega_origen_id'], 'producto' => $linea['producto_id']]);
            if ($lockSaldo->fetchColumn() === false) {
                throw new RuntimeException('No se pudo bloquear el saldo de origen.');
            }
        }
        $restar = $pdo->prepare(
            'UPDATE inventario_saldos SET cantidad_base = cantidad_base - :cantidad
             WHERE bodega_id = :bodega AND producto_id = :producto AND cantidad_base >= :cantidad_minima'
        );
        foreach ($lineas as $linea) {
            $restar->execute([
                'cantidad' => $linea['cantidad_base'], 'bodega' => $movimiento['bodega_origen_id'],
                'producto' => $linea['producto_id'], 'cantidad_minima' => $linea['cantidad_base'],
            ]);
            if ($restar->rowCount() !== 1) {
                throw new DomainException('Existencia insuficiente o saldo cambiado; no se despachó la transferencia.');
            }
        }
        $detalleAuditoria = array_map(static fn (array $linea): array => [
            'producto_id' => (int) $linea['producto_id'],
            'cantidad_base' => (string) $linea['cantidad_base'],
        ], $lineas);
        $antes = ['estado' => 'BORRADOR', 'bodega_origen_id' => (int) $movimiento['bodega_origen_id'],
            'detalles' => $detalleAuditoria];
        $stmt = $pdo->prepare(
            'UPDATE movimientos_inventario SET estado = \'EN_TRANSITO\',
                despachado_por_usuario_id = :usuario, fecha_despacho = CURRENT_TIMESTAMP(6)
             WHERE movimiento_id = :id AND estado = \'BORRADOR\''
        );
        $stmt->execute(['usuario' => $usuarioId, 'id' => $movimientoId]);
        $fechaStmt = $pdo->prepare('SELECT fecha_despacho FROM movimientos_inventario WHERE movimiento_id = :id');
        $fechaStmt->execute(['id' => $movimientoId]);
        $despues = ['estado' => 'EN_TRANSITO', 'despachado_por_usuario_id' => $usuarioId,
            'fecha_despacho' => $fechaStmt->fetchColumn(), 'detalles' => $detalleAuditoria];
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'movimientos_inventario', $movimientoId, $antes, $despues);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Recibe todas las cantidades de una vez; las diferencias requieren un procedimiento auditado aparte. */
function corozao_transferencia_recibir(PDO $pdo, int $movimientoId, int $usuarioId): void
{
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT movimiento_id, tipo_movimiento, estado, bodega_destino_id
             FROM movimientos_inventario WHERE movimiento_id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $movimientoId]);
        $movimiento = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$movimiento || $movimiento['tipo_movimiento'] !== 'TRANSFERENCIA' || $movimiento['estado'] !== 'EN_TRANSITO') {
            throw new DomainException('La transferencia no está pendiente de recepción.');
        }
        $detalles = $pdo->prepare(
            'SELECT producto_id, cantidad_base FROM detalle_movimiento_inventario
             WHERE movimiento_id = :id ORDER BY producto_id FOR UPDATE'
        );
        $detalles->execute(['id' => $movimientoId]);
        $lineas = $detalles->fetchAll(PDO::FETCH_ASSOC);
        if (!$lineas) {
            throw new DomainException('La transferencia no contiene productos.');
        }
        $bodegaStmt = $pdo->prepare('SELECT activo FROM bodegas WHERE bodega_id = :id FOR UPDATE');
        $bodegaStmt->execute(['id' => $movimiento['bodega_destino_id']]);
        if ((int) $bodegaStmt->fetchColumn() !== 1) {
            throw new DomainException('La bodega de destino no está activa.');
        }
        $crearSaldo = $pdo->prepare(
            'INSERT INTO inventario_saldos (bodega_id, producto_id, cantidad_base)
             VALUES (:bodega, :producto, 0)
             ON DUPLICATE KEY UPDATE producto_id = VALUES(producto_id)'
        );
        $lockSaldo = $pdo->prepare(
            'SELECT cantidad_base FROM inventario_saldos
             WHERE bodega_id = :bodega AND producto_id = :producto FOR UPDATE'
        );
        foreach ($lineas as $linea) {
            $crearSaldo->execute(['bodega' => $movimiento['bodega_destino_id'], 'producto' => $linea['producto_id']]);
            $lockSaldo->execute(['bodega' => $movimiento['bodega_destino_id'], 'producto' => $linea['producto_id']]);
            if ($lockSaldo->fetchColumn() === false) {
                throw new RuntimeException('No se pudo bloquear el saldo de destino.');
            }
        }
        $sumar = $pdo->prepare(
            'UPDATE inventario_saldos SET cantidad_base = cantidad_base + :cantidad
             WHERE bodega_id = :bodega AND producto_id = :producto'
        );
        foreach ($lineas as $linea) {
            $sumar->execute([
                'cantidad' => $linea['cantidad_base'], 'bodega' => $movimiento['bodega_destino_id'],
                'producto' => $linea['producto_id'],
            ]);
        }
        $detalleAuditoria = array_map(static fn (array $linea): array => [
            'producto_id' => (int) $linea['producto_id'],
            'cantidad_base' => (string) $linea['cantidad_base'],
        ], $lineas);
        $antes = ['estado' => 'EN_TRANSITO', 'bodega_destino_id' => (int) $movimiento['bodega_destino_id'],
            'detalles' => $detalleAuditoria];
        $stmt = $pdo->prepare(
            'UPDATE movimientos_inventario SET estado = \'CONFIRMADO\',
                confirmado_por_usuario_id = :usuario, fecha_confirmacion = CURRENT_TIMESTAMP,
                recibido_por_usuario_id = :usuario_receptor, fecha_recepcion = CURRENT_TIMESTAMP(6)
             WHERE movimiento_id = :id AND estado = \'EN_TRANSITO\''
        );
        $stmt->execute(['usuario' => $usuarioId, 'usuario_receptor' => $usuarioId, 'id' => $movimientoId]);
        $fechaStmt = $pdo->prepare('SELECT fecha_recepcion FROM movimientos_inventario WHERE movimiento_id = :id');
        $fechaStmt->execute(['id' => $movimientoId]);
        $despues = ['estado' => 'CONFIRMADO', 'recibido_por_usuario_id' => $usuarioId,
            'fecha_recepcion' => $fechaStmt->fetchColumn(), 'detalles' => $detalleAuditoria];
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'movimientos_inventario', $movimientoId, $antes, $despues);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
