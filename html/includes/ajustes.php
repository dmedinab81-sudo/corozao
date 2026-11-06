<?php
declare(strict_types=1);

/** Confirma que el esquema actual permite ajustes auditados e idempotentes. */
function corozao_ajustes_instalados(PDO $pdo): bool
{
    $columnas = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'movimientos_inventario\'
           AND COLUMN_NAME IN (\'clave_idempotencia\', \'estado\')'
    );
    if ((int) $columnas->fetchColumn() !== 2) {
        return false;
    }
    $indice = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'movimientos_inventario\'
           AND INDEX_NAME = \'uq_mov_clave_idempotencia\' AND NON_UNIQUE = 0'
    );
    if ((int) $indice->fetchColumn() !== 1) {
        return false;
    }
    $checks = $pdo->query(
        'SELECT COUNT(DISTINCT CONSTRAINT_NAME) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND ((TABLE_NAME = \'movimientos_inventario\' AND CONSTRAINT_NAME = \'chk_mov_bodegas\')
             OR (TABLE_NAME = \'inventario_saldos\' AND CONSTRAINT_NAME = \'chk_saldo_no_negativo\'))'
    );
    if ((int) $checks->fetchColumn() !== 2) {
        return false;
    }
    $auditoria = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'auditoria_eventos\''
    );
    return (int) $auditoria->fetchColumn() === 1;
}

function corozao_ajuste_cantidad(string $valor): string
{
    $valor = trim($valor);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})$/', $valor)
        || trim(str_replace(['0', '.'], '', $valor)) === '') {
        throw new DomainException('La cantidad debe ser un número entero positivo.');
    }
    [$entero, $fraccion] = array_pad(explode('.', $valor, 2), 2, '');
    $entero = ltrim($entero, '0');
    return ($entero === '' ? '0' : $entero) . '.' . str_pad($fraccion, 3, '0');
}

function corozao_ajuste_crear(
    PDO $pdo,
    int $usuarioId,
    int $bodegaId,
    int $productoId,
    string $tipo,
    string $cantidadEntrada,
    string $motivo,
    string $clave
): int {
    if (!in_array($tipo, ['AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO'], true)) {
        throw new DomainException('El tipo de ajuste no es válido.');
    }
    $cantidad = corozao_ajuste_cantidad($cantidadEntrada);
    $motivo = trim($motivo);
    if ($motivo === '' || mb_strlen($motivo, 'UTF-8') > 500) {
        throw new DomainException('El motivo es obligatorio y admite hasta 500 caracteres.');
    }
    if ($usuarioId < 1 || $bodegaId < 1 || $productoId < 1
        || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clave)) {
        throw new DomainException('Los datos del ajuste no son válidos. Recarga la página.');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $bodegaId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('Selecciona una bodega activa.');
        }
        $stmt = $pdo->prepare('SELECT producto_id FROM productos WHERE producto_id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $productoId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('Selecciona un producto activo.');
        }
        $origenId = $tipo === 'AJUSTE_NEGATIVO' ? $bodegaId : null;
        $destinoId = $tipo === 'AJUSTE_POSITIVO' ? $bodegaId : null;
        $stmt = $pdo->prepare(
            'INSERT INTO movimientos_inventario
                (tipo_movimiento, estado, bodega_origen_id, bodega_destino_id,
                 observaciones, usuario_id, fecha_movimiento, clave_idempotencia)
             VALUES (:tipo, \'BORRADOR\', :origen, :destino, :motivo, :usuario, CURRENT_TIMESTAMP, :clave)'
        );
        $stmt->execute([
            'tipo' => $tipo, 'origen' => $origenId, 'destino' => $destinoId,
            'motivo' => $motivo, 'usuario' => $usuarioId, 'clave' => strtolower($clave),
        ]);
        $movimientoId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare(
            'INSERT INTO detalle_movimiento_inventario
                (movimiento_id, producto_id, presentacion_id, cantidad_base, costo_unitario, costo_total)
             VALUES (:movimiento, :producto, NULL, :cantidad, NULL, NULL)'
        );
        $stmt->execute([
            'movimiento' => $movimientoId, 'producto' => $productoId, 'cantidad' => $cantidad,
        ]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'movimientos_inventario', $movimientoId, null, [
            'tipo_movimiento' => $tipo, 'estado' => 'BORRADOR', 'bodega_id' => $bodegaId,
            'producto_id' => $productoId, 'cantidad_base' => $cantidad, 'motivo' => $motivo,
        ]);
        $pdo->commit();
        return $movimientoId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            $stmt = $pdo->prepare(
                'SELECT movimiento_id FROM movimientos_inventario
                 WHERE clave_idempotencia = :clave AND usuario_id = :usuario AND tipo_movimiento = :tipo'
            );
            $stmt->execute(['clave' => strtolower($clave), 'usuario' => $usuarioId, 'tipo' => $tipo]);
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

/** Aplica el saldo y cierra el movimiento, en la misma transacción que su auditoría. */
function corozao_ajuste_confirmar(PDO $pdo, int $movimientoId, int $usuarioId): void
{
    if ($movimientoId < 1 || $usuarioId < 1) {
        throw new DomainException('El ajuste seleccionado no es válido.');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT movimiento_id, tipo_movimiento, estado, bodega_origen_id, bodega_destino_id, observaciones
             FROM movimientos_inventario WHERE movimiento_id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $movimientoId]);
        $movimiento = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$movimiento || !in_array($movimiento['tipo_movimiento'], ['AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO'], true)
            || $movimiento['estado'] !== 'BORRADOR') {
            throw new DomainException('El ajuste ya fue confirmado o no está disponible.');
        }
        $bodegaId = (int) ($movimiento['bodega_destino_id'] ?? $movimiento['bodega_origen_id']);
        $stmt = $pdo->prepare('SELECT activo FROM bodegas WHERE bodega_id = :id FOR UPDATE');
        $stmt->execute(['id' => $bodegaId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new DomainException('La bodega del ajuste ya no está activa.');
        }
        $stmt = $pdo->prepare(
            'SELECT d.producto_id, d.cantidad_base, p.nombre AS producto
             FROM detalle_movimiento_inventario d
             INNER JOIN productos p ON p.producto_id = d.producto_id
             WHERE d.movimiento_id = :id ORDER BY d.producto_id FOR UPDATE'
        );
        $stmt->execute(['id' => $movimientoId]);
        $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($lineas) !== 1 || trim((string) $movimiento['observaciones']) === '') {
            throw new DomainException('El ajuste debe conservar un producto y un motivo.');
        }
        $linea = $lineas[0];
        $stmt = $pdo->prepare('SELECT activo FROM productos WHERE producto_id = :id FOR UPDATE');
        $stmt->execute(['id' => $linea['producto_id']]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new DomainException('El producto del ajuste ya no está activo.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO inventario_saldos (bodega_id, producto_id, cantidad_base)
             VALUES (:bodega, :producto, 0)
             ON DUPLICATE KEY UPDATE producto_id = VALUES(producto_id)'
        );
        $stmt->execute(['bodega' => $bodegaId, 'producto' => $linea['producto_id']]);
        $stmt = $pdo->prepare(
            'SELECT cantidad_base FROM inventario_saldos WHERE bodega_id = :bodega AND producto_id = :producto FOR UPDATE'
        );
        $stmt->execute(['bodega' => $bodegaId, 'producto' => $linea['producto_id']]);
        $saldoAntes = (string) $stmt->fetchColumn();
        if ($movimiento['tipo_movimiento'] === 'AJUSTE_POSITIVO') {
            $stmt = $pdo->prepare(
                'UPDATE inventario_saldos SET cantidad_base = cantidad_base + :cantidad
                 WHERE bodega_id = :bodega AND producto_id = :producto'
            );
        } else {
            $stmt = $pdo->prepare(
                'UPDATE inventario_saldos SET cantidad_base = cantidad_base - :cantidad
                 WHERE bodega_id = :bodega AND producto_id = :producto AND cantidad_base >= :minimo'
            );
        }
        $parametros = ['cantidad' => $linea['cantidad_base'], 'bodega' => $bodegaId, 'producto' => $linea['producto_id']];
        if ($movimiento['tipo_movimiento'] === 'AJUSTE_NEGATIVO') {
            $parametros['minimo'] = $linea['cantidad_base'];
        }
        $stmt->execute($parametros);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('El saldo disponible no alcanza para aplicar este ajuste negativo.');
        }
        $stmt = $pdo->prepare(
            'UPDATE movimientos_inventario SET estado = \'CONFIRMADO\', confirmado_por_usuario_id = :usuario,
                fecha_confirmacion = CURRENT_TIMESTAMP
             WHERE movimiento_id = :id AND estado = \'BORRADOR\''
        );
        $stmt->execute(['usuario' => $usuarioId, 'id' => $movimientoId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('El ajuste cambió mientras se procesaba.');
        }
        $stmt = $pdo->prepare(
            'SELECT cantidad_base FROM inventario_saldos WHERE bodega_id = :bodega AND producto_id = :producto'
        );
        $stmt->execute(['bodega' => $bodegaId, 'producto' => $linea['producto_id']]);
        $saldoDespues = (string) $stmt->fetchColumn();
        corozao_registrar_auditoria(
            $pdo,
            $usuarioId,
            'MODIFICAR',
            'movimientos_inventario',
            $movimientoId,
            ['estado' => 'BORRADOR', 'cantidad_base' => (string) $linea['cantidad_base'], 'saldo' => $saldoAntes],
            ['estado' => 'CONFIRMADO', 'confirmado_por_usuario_id' => $usuarioId,
                'tipo_movimiento' => $movimiento['tipo_movimiento'], 'bodega_id' => $bodegaId,
                'producto_id' => (int) $linea['producto_id'], 'saldo' => $saldoDespues,
                'motivo' => $movimiento['observaciones']]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
