<?php
declare(strict_types=1);

function corozao_compras_instaladas(PDO $pdo): bool
{
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (\'compras\', \'compra_detalles\')'
    );
    if ((int) $stmt->fetchColumn() !== 2) {
        return false;
    }
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'movimientos_inventario\'
           AND COLUMN_NAME = \'compra_id\''
    );
    if ((int) $stmt->fetchColumn() !== 1) {
        return false;
    }
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'movimientos_inventario\'
           AND INDEX_NAME = \'uq_movimiento_compra\' AND NON_UNIQUE = 0'
    );
    if ((int) $stmt->fetchColumn() !== 1) {
        return false;
    }
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'movimientos_inventario\'
           AND CONSTRAINT_NAME = \'fk_movimiento_compra\''
    );
    if ((int) $stmt->fetchColumn() !== 1) {
        return false;
    }
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'auditoria_eventos\''
    );
    return (int) $stmt->fetchColumn() === 1;
}

function corozao_compra_entrada_decimal(string $valor, int $escala, int $enteros, bool $positivo): string
{
    $valor = trim($valor);
    $patron = '/^(?:0|[1-9][0-9]{0,' . ($enteros - 1) . '})(?:\.[0-9]{1,' . $escala . '})?$/';
    if (!preg_match($patron, $valor)) {
        throw new DomainException('Una cantidad o costo excede el formato permitido.');
    }
    if ($positivo && trim(str_replace(['0', '.'], '', $valor)) === '') {
        throw new DomainException('La cantidad debe ser mayor que cero.');
    }
    [$entero, $fraccion] = array_pad(explode('.', $valor, 2), 2, '');
    $entero = ltrim($entero, '0');
    return ($entero === '' ? '0' : $entero) . '.' . str_pad($fraccion, $escala, '0');
}

/** Convierte un resultado SQL decimal solo si no pierde precisión al guardarlo. */
function corozao_compra_decimal_exacto(string $valor, int $escalaOrigen, int $escalaDestino, int $maxEnteros): string
{
    if (!preg_match('/^(\d+)\.(\d+)$/', $valor, $coincidencias)
        || strlen($coincidencias[2]) !== $escalaOrigen) {
        throw new DomainException('La cantidad o el total queda fuera de precisión.');
    }
    $entero = ltrim($coincidencias[1], '0');
    $entero = $entero === '' ? '0' : $entero;
    $fraccion = $coincidencias[2];
    if (strlen($entero) > $maxEnteros || trim(substr($fraccion, $escalaDestino), '0') !== '') {
        throw new DomainException('La conversión excede la precisión permitida; ajusta la cantidad o el costo.');
    }
    return $entero . '.' . str_pad(substr($fraccion, 0, $escalaDestino), $escalaDestino, '0');
}

/** Carga y valida hasta cinco líneas; la presentación determina el producto y la conversión base. */
function corozao_compra_validar_lineas(PDO $pdo, array $entradas): array
{
    if (count($entradas) > 5) {
        throw new DomainException('Cada compra admite hasta cinco presentaciones por formulario.');
    }
    usort($entradas, static function (mixed $a, mixed $b): int {
        $idA = is_array($a) && is_string($a['presentacion_id'] ?? null)
            && ctype_digit($a['presentacion_id']) ? (int) $a['presentacion_id'] : PHP_INT_MAX;
        $idB = is_array($b) && is_string($b['presentacion_id'] ?? null)
            && ctype_digit($b['presentacion_id']) ? (int) $b['presentacion_id'] : PHP_INT_MAX;
        return $idA <=> $idB;
    });
    $lineas = [];
    $presentacionesVistas = [];
    $presentacionStmt = $pdo->prepare(
        'SELECT pr.presentacion_id, pr.producto_id, pr.unidades_base
         FROM presentaciones pr
         INNER JOIN productos p ON p.producto_id = pr.producto_id
         WHERE pr.presentacion_id = :presentacion AND pr.activo = 1 AND p.activo = 1
         FOR UPDATE'
    );
    $calculoStmt = $pdo->prepare(
        'SELECT CAST(CAST(:cantidad_base AS DECIMAL(14,3)) * CAST(:unidades_base AS DECIMAL(12,3)) AS DECIMAL(26,6)) AS base_calculada,
                CAST(CAST(:cantidad_total AS DECIMAL(14,3)) * CAST(:costo_unitario AS DECIMAL(12,4)) AS DECIMAL(26,7)) AS total_calculado'
    );

    foreach ($entradas as $entrada) {
        if (!is_array($entrada)) {
            throw new DomainException('Una línea de compra no es válida.');
        }
        $presentacionRaw = $entrada['presentacion_id'] ?? '';
        $cantidadRaw = $entrada['cantidad'] ?? '';
        $costoRaw = $entrada['costo'] ?? '';
        if (!is_string($presentacionRaw) || !is_string($cantidadRaw) || !is_string($costoRaw)) {
            throw new DomainException('Los campos de producto, cantidad y costo deben ser texto válido.');
        }
        if ($presentacionRaw === '' && trim($cantidadRaw) === '' && trim($costoRaw) === '') {
            continue;
        }
        $presentacionId = filter_var($presentacionRaw, FILTER_VALIDATE_INT);
        if (!$presentacionId || $presentacionId < 1 || trim($cantidadRaw) === '' || trim($costoRaw) === '') {
            throw new DomainException('Cada presentación requiere cantidad y costo unitario.');
        }
        if (isset($presentacionesVistas[$presentacionId])) {
            throw new DomainException('Cada presentación debe aparecer una sola vez en la compra.');
        }
        $presentacionesVistas[$presentacionId] = true;
        $cantidad = corozao_compra_entrada_decimal($cantidadRaw, 3, 11, true);
        $costo = corozao_compra_entrada_decimal($costoRaw, 4, 8, false);

        $presentacionStmt->execute(['presentacion' => $presentacionId]);
        $presentacion = $presentacionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$presentacion) {
            throw new DomainException('La presentación o su producto ya no está activo.');
        }
        $unidadesBase = (string) $presentacion['unidades_base'];
        $calculoStmt->execute([
            'cantidad_base' => $cantidad,
            'unidades_base' => $unidadesBase,
            'cantidad_total' => $cantidad,
            'costo_unitario' => $costo,
        ]);
        $calculo = $calculoStmt->fetch(PDO::FETCH_ASSOC);
        $cantidadBase = corozao_compra_decimal_exacto((string) $calculo['base_calculada'], 6, 3, 11);
        $costoTotal = corozao_compra_decimal_exacto((string) $calculo['total_calculado'], 7, 4, 10);
        $lineas[] = [
            'producto_id' => (int) $presentacion['producto_id'],
            'presentacion_id' => (int) $presentacion['presentacion_id'],
            'cantidad_presentacion' => $cantidad,
            'unidades_base' => $unidadesBase,
            'cantidad_base' => $cantidadBase,
            'costo_unitario_presentacion' => $costo,
            'costo_total' => $costoTotal,
        ];
    }
    if (!$lineas) {
        throw new DomainException('Agrega al menos una presentación a la compra.');
    }
    usort($lineas, static fn (array $a, array $b): int =>
        [$a['producto_id'], $a['presentacion_id']] <=> [$b['producto_id'], $b['presentacion_id']]);
    return $lineas;
}

function corozao_compra_detalles_snapshot(PDO $pdo, int $compraId): array
{
    $stmt = $pdo->prepare(
        'SELECT cd.producto_id, cd.presentacion_id, cd.cantidad_presentacion, cd.unidades_base,
                cd.cantidad_base, cd.costo_unitario_presentacion, cd.costo_total,
                p.nombre AS producto, pr.nombre AS presentacion
         FROM compra_detalles cd
         INNER JOIN productos p ON p.producto_id = cd.producto_id
         INNER JOIN presentaciones pr ON pr.presentacion_id = cd.presentacion_id
         WHERE cd.compra_id = :compra ORDER BY cd.producto_id, cd.presentacion_id'
    );
    $stmt->execute(['compra' => $compraId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function corozao_compra_insertar_detalles(PDO $pdo, int $compraId, array $lineas): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO compra_detalles
            (compra_id, producto_id, presentacion_id, cantidad_presentacion,
             unidades_base, cantidad_base, costo_unitario_presentacion, costo_total)
         VALUES (:compra, :producto, :presentacion, :cantidad_presentacion,
                 :unidades_base, :cantidad_base, :costo_unitario, :costo_total)'
    );
    foreach ($lineas as $linea) {
        $stmt->execute([
            'compra' => $compraId,
            'producto' => $linea['producto_id'],
            'presentacion' => $linea['presentacion_id'],
            'cantidad_presentacion' => $linea['cantidad_presentacion'],
            'unidades_base' => $linea['unidades_base'],
            'cantidad_base' => $linea['cantidad_base'],
            'costo_unitario' => $linea['costo_unitario_presentacion'],
            'costo_total' => $linea['costo_total'],
        ]);
    }
}

function corozao_compra_crear(
    PDO $pdo,
    int $usuarioId,
    int $proveedorId,
    int $bodegaId,
    array $entradas,
    string $referencia,
    string $observaciones,
    string $clave
): int {
    if (strlen($referencia) > 100 || strlen($observaciones) > 500) {
        throw new DomainException('La referencia o las observaciones exceden su longitud máxima.');
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clave)) {
        throw new DomainException('La clave de solicitud no es válida. Recarga la página.');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT proveedor_id FROM proveedores WHERE proveedor_id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $proveedorId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('Selecciona un proveedor activo.');
        }
        $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $bodegaId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('Selecciona una bodega activa.');
        }
        $lineas = corozao_compra_validar_lineas($pdo, $entradas);
        $stmt = $pdo->prepare(
            'INSERT INTO compras
                (proveedor_id, bodega_id, estado, referencia_proveedor, observaciones,
                 clave_idempotencia, usuario_id, fecha_compra)
             VALUES (:proveedor, :bodega, \'BORRADOR\', :referencia, :observaciones,
                     :clave, :usuario, CURRENT_TIMESTAMP(6))'
        );
        $stmt->execute([
            'proveedor' => $proveedorId,
            'bodega' => $bodegaId,
            'referencia' => $referencia === '' ? null : $referencia,
            'observaciones' => $observaciones === '' ? null : $observaciones,
            'clave' => strtolower($clave),
            'usuario' => $usuarioId,
        ]);
        $compraId = (int) $pdo->lastInsertId();
        corozao_compra_insertar_detalles($pdo, $compraId, $lineas);
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'compras', $compraId, null, [
            'estado' => 'BORRADOR', 'proveedor_id' => $proveedorId, 'bodega_id' => $bodegaId,
            'referencia_proveedor' => $referencia === '' ? null : $referencia, 'detalles' => $lineas,
        ]);
        $pdo->commit();
        return $compraId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            $stmt = $pdo->prepare('SELECT compra_id FROM compras WHERE clave_idempotencia = :clave');
            $stmt->execute(['clave' => strtolower($clave)]);
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

function corozao_compra_actualizar_borrador(
    PDO $pdo,
    int $compraId,
    int $usuarioId,
    array $entradas,
    string $referencia,
    string $observaciones
): void {
    if (strlen($referencia) > 100 || strlen($observaciones) > 500) {
        throw new DomainException('La referencia o las observaciones exceden su longitud máxima.');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT compra_id, estado, referencia_proveedor, observaciones
             FROM compras WHERE compra_id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $compraId]);
        $compra = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$compra || $compra['estado'] !== 'BORRADOR') {
            throw new DomainException('Solo se pueden modificar compras en borrador.');
        }
        $anteriores = [
            'estado' => 'BORRADOR',
            'referencia_proveedor' => $compra['referencia_proveedor'],
            'observaciones' => $compra['observaciones'],
            'detalles' => corozao_compra_detalles_snapshot($pdo, $compraId),
        ];
        $lineas = corozao_compra_validar_lineas($pdo, $entradas);
        $stmt = $pdo->prepare('DELETE FROM compra_detalles WHERE compra_id = :id');
        $stmt->execute(['id' => $compraId]);
        corozao_compra_insertar_detalles($pdo, $compraId, $lineas);
        $stmt = $pdo->prepare(
            'UPDATE compras SET referencia_proveedor = :referencia, observaciones = :observaciones
             WHERE compra_id = :id AND estado = \'BORRADOR\''
        );
        $stmt->execute([
            'referencia' => $referencia === '' ? null : $referencia,
            'observaciones' => $observaciones === '' ? null : $observaciones,
            'id' => $compraId,
        ]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'compras', $compraId, $anteriores, [
            'estado' => 'BORRADOR', 'referencia_proveedor' => $referencia === '' ? null : $referencia,
            'observaciones' => $observaciones === '' ? null : $observaciones, 'detalles' => $lineas,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function corozao_compra_confirmar(PDO $pdo, int $compraId, int $usuarioId): void
{
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT compra_id, proveedor_id, bodega_id, estado, usuario_id, fecha_compra, observaciones
             FROM compras WHERE compra_id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $compraId]);
        $compra = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$compra || $compra['estado'] !== 'BORRADOR') {
            throw new DomainException('La compra ya fue confirmada o no está disponible.');
        }
        $stmt = $pdo->prepare('SELECT proveedor_id FROM proveedores WHERE proveedor_id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $compra['proveedor_id']]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('El proveedor debe permanecer activo para confirmar la compra.');
        }
        $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $compra['bodega_id']]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('La bodega de destino ya no está activa.');
        }
        $stmt = $pdo->prepare(
            'SELECT compra_detalle_id, producto_id, presentacion_id, cantidad_presentacion,
                    unidades_base, cantidad_base, costo_unitario_presentacion, costo_total
             FROM compra_detalles WHERE compra_id = :id ORDER BY producto_id, presentacion_id FOR UPDATE'
        );
        $stmt->execute(['id' => $compraId]);
        $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lineas) {
            throw new DomainException('Agrega productos antes de confirmar la compra.');
        }

        $presentacionStmt = $pdo->prepare(
            'SELECT pr.producto_id, pr.unidades_base, pr.activo, p.activo AS producto_activo
             FROM presentaciones pr INNER JOIN productos p ON p.producto_id = pr.producto_id
             WHERE pr.presentacion_id = :presentacion FOR UPDATE'
        );
        $productos = [];
        foreach ($lineas as $linea) {
            $presentacionStmt->execute(['presentacion' => $linea['presentacion_id']]);
            $presentacion = $presentacionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$presentacion || (int) $presentacion['activo'] !== 1 || (int) $presentacion['producto_activo'] !== 1
                || (int) $presentacion['producto_id'] !== (int) $linea['producto_id']
                || (string) $presentacion['unidades_base'] !== (string) $linea['unidades_base']) {
                throw new DomainException('Una presentación cambió o dejó de estar activa; revisa el borrador antes de confirmar.');
            }
            $productos[(int) $linea['producto_id']] = true;
        }
        $productoIds = array_keys($productos);
        sort($productoIds, SORT_NUMERIC);
        $crearSaldo = $pdo->prepare(
            'INSERT INTO inventario_saldos (bodega_id, producto_id, cantidad_base)
             VALUES (:bodega, :producto, 0)
             ON DUPLICATE KEY UPDATE producto_id = VALUES(producto_id)'
        );
        $lockSaldo = $pdo->prepare(
            'SELECT cantidad_base FROM inventario_saldos
             WHERE bodega_id = :bodega AND producto_id = :producto FOR UPDATE'
        );
        foreach ($productoIds as $productoId) {
            $crearSaldo->execute(['bodega' => $compra['bodega_id'], 'producto' => $productoId]);
            $lockSaldo->execute(['bodega' => $compra['bodega_id'], 'producto' => $productoId]);
            if ($lockSaldo->fetchColumn() === false) {
                throw new RuntimeException('No se pudo bloquear un saldo de inventario.');
            }
        }

        $movimiento = $pdo->prepare(
            'INSERT INTO movimientos_inventario
                (tipo_movimiento, estado, bodega_origen_id, bodega_destino_id,
                 referencia_tipo, referencia_id, observaciones, usuario_id,
                 confirmado_por_usuario_id, fecha_movimiento, fecha_confirmacion, compra_id)
             VALUES (\'COMPRA\', \'CONFIRMADO\', NULL, :bodega,
                     \'COMPRA\', :compra, :observaciones, :creador,
                     :confirmador, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :compra_fk)'
        );
        $movimiento->execute([
            'bodega' => $compra['bodega_id'], 'compra' => $compraId,
            'observaciones' => $compra['observaciones'], 'creador' => $compra['usuario_id'],
            'confirmador' => $usuarioId, 'compra_fk' => $compraId,
        ]);
        $movimientoId = (int) $pdo->lastInsertId();
        $detalleMov = $pdo->prepare(
            'INSERT INTO detalle_movimiento_inventario
                (movimiento_id, producto_id, presentacion_id, cantidad_base, costo_unitario, costo_total)
             VALUES (:movimiento, :producto, :presentacion, :cantidad, NULL, :costo_total)'
        );
        $sumarSaldo = $pdo->prepare(
            'UPDATE inventario_saldos SET cantidad_base = cantidad_base + :cantidad
             WHERE bodega_id = :bodega AND producto_id = :producto'
        );
        foreach ($lineas as $linea) {
            $detalleMov->execute([
                'movimiento' => $movimientoId, 'producto' => $linea['producto_id'],
                'presentacion' => $linea['presentacion_id'], 'cantidad' => $linea['cantidad_base'],
                'costo_total' => $linea['costo_total'],
            ]);
            $sumarSaldo->execute([
                'cantidad' => $linea['cantidad_base'], 'bodega' => $compra['bodega_id'],
                'producto' => $linea['producto_id'],
            ]);
            if ($sumarSaldo->rowCount() !== 1) {
                throw new RuntimeException('No se pudo actualizar un saldo de inventario.');
            }
        }
        $stmt = $pdo->prepare(
            'UPDATE compras SET estado = \'CONFIRMADA\', confirmado_por_usuario_id = :usuario,
                fecha_confirmacion = CURRENT_TIMESTAMP(6)
             WHERE compra_id = :id AND estado = \'BORRADOR\''
        );
        $stmt->execute(['usuario' => $usuarioId, 'id' => $compraId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('La compra cambió mientras se confirmaba.');
        }
        $detallesSnapshot = array_map(static fn (array $linea): array => [
            'producto_id' => (int) $linea['producto_id'],
            'presentacion_id' => (int) $linea['presentacion_id'],
            'cantidad_base' => (string) $linea['cantidad_base'],
            'costo_total' => (string) $linea['costo_total'],
        ], $lineas);
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'compras', $compraId,
            ['estado' => 'BORRADOR'], ['estado' => 'CONFIRMADA',
                'confirmado_por_usuario_id' => $usuarioId, 'movimiento_id' => $movimientoId,
                'detalles' => $detallesSnapshot]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'movimientos_inventario', $movimientoId,
            null, ['tipo_movimiento' => 'COMPRA', 'estado' => 'CONFIRMADO',
                'compra_id' => $compraId, 'bodega_destino_id' => (int) $compra['bodega_id'],
                'detalles' => $detallesSnapshot]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
