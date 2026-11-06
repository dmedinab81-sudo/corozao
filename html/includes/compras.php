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

function corozao_compra_reversiones_instaladas(PDO $pdo): bool
{
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'
           AND TABLE_NAME = \'reversiones_compras\''
    );
    return (int)$stmt->fetchColumn() === 1;
}

/** Reversa completa una compra en una SALIDA compensatoria, conservando el origen. */
function corozao_compra_revertir(PDO $pdo, int $compraId, int $usuarioId, string $motivo): array
{
    if ($compraId < 1 || $usuarioId < 1) {
        throw new DomainException('La compra o el usuario responsable no son válidos.');
    }
    if (!corozao_compra_reversiones_instaladas($pdo)) {
        throw new DomainException('La reversión de compras requiere aplicar primero la migración incremental correspondiente.');
    }
    $motivo = trim($motivo);
    $prefijoMotivo = 'Reversión completa de compra #' . $compraId . ': ';
    if ($motivo === '' || mb_strlen($motivo, 'UTF-8') > 500 - mb_strlen($prefijoMotivo, 'UTF-8')) {
        throw new DomainException('Indica un motivo obligatorio de hasta ' . (500 - mb_strlen($prefijoMotivo, 'UTF-8')) . ' caracteres.');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT c.compra_id, c.estado, c.bodega_id, m.movimiento_id, m.estado AS estado_movimiento,
                    m.tipo_movimiento, m.fecha_movimiento
             FROM compras c
             INNER JOIN movimientos_inventario m ON m.compra_id = c.compra_id
             WHERE c.compra_id = :compra FOR UPDATE'
        );
        $stmt->execute(['compra' => $compraId]);
        $compra = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$compra || $compra['estado'] !== 'CONFIRMADA'
            || $compra['estado_movimiento'] !== 'CONFIRMADO' || $compra['tipo_movimiento'] !== 'COMPRA') {
            throw new DomainException('Solo se puede revertir una compra confirmada con su movimiento original íntegro.');
        }
        $stmt = $pdo->prepare('SELECT reversion_id FROM reversiones_compras WHERE compra_id = :compra FOR UPDATE');
        $stmt->execute(['compra' => $compraId]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('Esta compra ya tiene una reversión registrada.');
        }

        $movimientoId = (int)$compra['movimiento_id'];
        $stmt = $pdo->prepare(
            'SELECT detalle_movimiento_id, producto_id, presentacion_id, cantidad_base, costo_unitario, costo_total
             FROM detalle_movimiento_inventario WHERE movimiento_id = :movimiento
             ORDER BY producto_id, detalle_movimiento_id FOR UPDATE'
        );
        $stmt->execute(['movimiento' => $movimientoId]);
        $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lineas) {
            throw new DomainException('El movimiento original no tiene líneas; requiere revisión antes de corregirlo.');
        }

        $cantidades = [];
        foreach ($lineas as $linea) {
            $productoId = (int)$linea['producto_id'];
            $cantidad = (string)$linea['cantidad_base'];
            if (!preg_match('/^(\d+)\.(\d{3})$/', $cantidad, $partes)) {
                throw new DomainException('Una cantidad del movimiento original tiene precisión inválida.');
            }
            $milesimas = ((int)$partes[1] * 1000) + (int)$partes[2];
            if ($milesimas < 1) {
                throw new DomainException('Una cantidad del movimiento original no es válida.');
            }
            $cantidades[$productoId] = ($cantidades[$productoId] ?? 0) + $milesimas;
        }
        ksort($cantidades, SORT_NUMERIC);

        // No reescribir la secuencia de stock: exige revertir primero movimientos posteriores.
        $productoIds = array_keys($cantidades);
        $marcadores = implode(',', array_fill(0, count($productoIds), '?'));
        $sqlPosteriores =
            'SELECT DISTINCT m.movimiento_id, m.tipo_movimiento
             FROM movimientos_inventario m
             INNER JOIN detalle_movimiento_inventario d ON d.movimiento_id = m.movimiento_id
             WHERE ((m.estado = \'CONFIRMADO\' AND m.movimiento_id > ?)
                    OR m.estado IN (\'BORRADOR\', \'EN_TRANSITO\'))
               AND (m.bodega_origen_id = ? OR m.bodega_destino_id = ?)
               AND d.producto_id IN (' . $marcadores . ')
               AND NOT EXISTS (SELECT 1 FROM reversiones_compras rc WHERE rc.movimiento_id = m.movimiento_id)
               AND NOT (m.compra_id IS NOT NULL AND EXISTS
                   (SELECT 1 FROM reversiones_compras rc2 WHERE rc2.compra_id = m.compra_id))
             ORDER BY m.movimiento_id LIMIT 1';
        $stmt = $pdo->prepare($sqlPosteriores);
        $stmt->execute(array_merge([$movimientoId, (int)$compra['bodega_id'], (int)$compra['bodega_id']], $productoIds));
        $posterior = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($posterior) {
            throw new DomainException('Hay movimientos posteriores o pendientes (#' . (int)$posterior['movimiento_id'] . ', '
                . $posterior['tipo_movimiento'] . ') para estos productos en la bodega. Cierra el movimiento pendiente, revierte primero las compras posteriores o realiza un conteo y corrección auditada.');
        }

        $saldoAntes = [];
        $crearSaldo = $pdo->prepare(
            'INSERT INTO inventario_saldos (bodega_id, producto_id, cantidad_base)
             VALUES (:bodega, :producto, 0)
             ON DUPLICATE KEY UPDATE producto_id = VALUES(producto_id)'
        );
        $leerSaldo = $pdo->prepare(
            'SELECT cantidad_base FROM inventario_saldos
             WHERE bodega_id = :bodega AND producto_id = :producto FOR UPDATE'
        );
        foreach ($cantidades as $productoId => $cantidad) {
            $crearSaldo->execute(['bodega' => (int)$compra['bodega_id'], 'producto' => $productoId]);
            $leerSaldo->execute(['bodega' => (int)$compra['bodega_id'], 'producto' => $productoId]);
            $saldo = (string)$leerSaldo->fetchColumn();
            if (!preg_match('/^(\d+)\.(\d{3})$/', $saldo, $partes)) {
                throw new RuntimeException('El saldo de inventario tiene precisión inválida.');
            }
            $saldoMilesimas = ((int)$partes[1] * 1000) + (int)$partes[2];
            if ($saldoMilesimas < $cantidad) {
                throw new DomainException('El saldo actual no alcanza para revertir la compra sin dejar inventario negativo. Revisa ventas, transferencias y el conteo físico.');
            }
            $saldoAntes[$productoId] = $saldo;
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        $clave = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        $observaciones = $prefijoMotivo . $motivo;
        $stmt = $pdo->prepare(
            'INSERT INTO movimientos_inventario
                (tipo_movimiento, estado, bodega_origen_id, bodega_destino_id, referencia_tipo,
                 referencia_id, observaciones, usuario_id, confirmado_por_usuario_id,
                 fecha_movimiento, fecha_confirmacion, clave_idempotencia)
             VALUES (\'SALIDA\', \'CONFIRMADO\', :bodega, NULL, \'COMPRA_REVERSA\', :compra,
                 :observaciones, :usuario, :confirmador, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :clave)'
        );
        $stmt->execute(['bodega' => (int)$compra['bodega_id'], 'compra' => $compraId,
            'observaciones' => $observaciones, 'usuario' => $usuarioId,
            'confirmador' => $usuarioId, 'clave' => $clave]);
        $movimientoReversaId = (int)$pdo->lastInsertId();

        $insertarDetalle = $pdo->prepare(
            'INSERT INTO detalle_movimiento_inventario
                (movimiento_id, producto_id, presentacion_id, cantidad_base, costo_unitario, costo_total)
             SELECT :nuevo_movimiento, producto_id, presentacion_id, cantidad_base, costo_unitario, costo_total
             FROM detalle_movimiento_inventario
             WHERE movimiento_id = :movimiento_original AND detalle_movimiento_id = :detalle'
        );
        foreach ($lineas as $linea) {
            $insertarDetalle->execute(['nuevo_movimiento' => $movimientoReversaId,
                'movimiento_original' => $movimientoId, 'detalle' => (int)$linea['detalle_movimiento_id']]);
        }

        $actualizarSaldo = $pdo->prepare(
            'UPDATE inventario_saldos SET cantidad_base = cantidad_base - :cantidad
             WHERE bodega_id = :bodega AND producto_id = :producto AND cantidad_base >= :minimo'
        );
        $auditoriaSaldos = [];
        foreach ($cantidades as $productoId => $milesimas) {
            $cantidad = intdiv($milesimas, 1000) . '.' . str_pad((string)($milesimas % 1000), 3, '0', STR_PAD_LEFT);
            $actualizarSaldo->execute(['cantidad' => $cantidad, 'bodega' => (int)$compra['bodega_id'],
                'producto' => $productoId, 'minimo' => $cantidad]);
            if ($actualizarSaldo->rowCount() !== 1) {
                throw new DomainException('El saldo cambió durante la reversión; no se aplicaron cambios.');
            }
            $leerSaldo->execute(['bodega' => (int)$compra['bodega_id'], 'producto' => $productoId]);
            $auditoriaSaldos[] = ['producto_id' => $productoId, 'cantidad_base' => $cantidad,
                'saldo_anterior' => $saldoAntes[$productoId], 'saldo_posterior' => (string)$leerSaldo->fetchColumn()];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO reversiones_compras (compra_id, movimiento_id, usuario_id, motivo)
             VALUES (:compra, :movimiento, :usuario, :motivo)'
        );
        $stmt->execute(['compra' => $compraId, 'movimiento' => $movimientoReversaId,
            'usuario' => $usuarioId, 'motivo' => $motivo]);
        $reversionId = (int)$pdo->lastInsertId();
        $detalleAuditoria = array_map(static fn(array $linea): array => [
            'producto_id' => (int)$linea['producto_id'],
            'presentacion_id' => $linea['presentacion_id'] === null ? null : (int)$linea['presentacion_id'],
            'cantidad_base' => (string)$linea['cantidad_base'],
            'costo_total_neto' => $linea['costo_total'] === null ? null : (string)$linea['costo_total'],
        ], $lineas);
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'reversiones_compras', $reversionId, null, [
            'compra_id' => $compraId, 'movimiento_id' => $movimientoReversaId,
            'motivo' => $motivo, 'productos' => $auditoriaSaldos,
        ]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'movimientos_inventario', $movimientoReversaId, null, [
            'tipo_movimiento' => 'SALIDA', 'referencia_tipo' => 'COMPRA_REVERSA',
            'referencia_id' => $compraId, 'compra_movimiento_original_id' => $movimientoId,
            'detalles' => $detalleAuditoria,
        ]);
        $pdo->commit();
        return ['reversion_id' => $reversionId, 'movimiento_id' => $movimientoReversaId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function corozao_compras_iva_instalado(PDO $pdo): bool
{
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'compra_detalles\'
           AND COLUMN_NAME IN (\'tarifa_iva_compra\', \'impuesto_iva\')'
    );
    return (int)$stmt->fetchColumn() === 2;
}

function corozao_compra_tarifa_iva(string $valor): string
{
    $normalizada = corozao_compra_entrada_decimal($valor, 2, 3, false);
    [$entero, $decimales] = explode('.', $normalizada, 2);
    if (((int)$entero * 100) + (int)$decimales > 10000) {
        throw new DomainException('La tarifa de IVA debe estar entre 0 y 100 por ciento.');
    }
    return $normalizada;
}

function corozao_compra_entrada_decimal(string $valor, int $escala, int $enteros, bool $positivo): string
{
    $valor = trim($valor);
    $patron = $escala === 0
        ? '/^(?:0|[1-9][0-9]{0,' . ($enteros - 1) . '})$/'
        : '/^(?:0|[1-9][0-9]{0,' . ($enteros - 1) . '})(?:\.[0-9]{1,' . $escala . '})?$/';
    if (!preg_match($patron, $valor)) {
        throw new DomainException($escala === 0
            ? 'La cantidad debe ser un número entero positivo.'
            : 'Una cantidad o costo excede el formato permitido.');
    }
    if ($positivo && trim(str_replace(['0', '.'], '', $valor)) === '') {
        throw new DomainException('La cantidad debe ser mayor que cero.');
    }
    [$entero, $fraccion] = array_pad(explode('.', $valor, 2), 2, '');
    $entero = ltrim($entero, '0');
    return $escala === 0 ? ($entero === '' ? '0' : $entero) : ($entero === '' ? '0' : $entero) . '.' . str_pad($fraccion, $escala, '0');
}

function corozao_compra_fecha_entrada(string $valor): string
{
    $zona = new DateTimeZone('America/Guayaquil');
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $valor, $zona);
    $errores = DateTimeImmutable::getLastErrors();
    if (!$fecha || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))
        || $fecha->format('Y-m-d\\TH:i') !== $valor) {
        throw new DomainException('Indica una fecha y hora de compra válidas.');
    }
    return $fecha->format('Y-m-d H:i:s');
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

/** Carga y valida hasta cien líneas; la presentación determina el producto y la conversión base. */
function corozao_compra_validar_lineas(PDO $pdo, array $entradas): array
{
    if (count($entradas) > 100) {
        throw new DomainException('Cada compra admite hasta cien presentaciones.');
    }
    usort($entradas, static function (mixed $a, mixed $b): int {
        $idA = is_array($a) && is_string($a['presentacion_id'] ?? null)
            && ctype_digit($a['presentacion_id']) ? (int) $a['presentacion_id'] : PHP_INT_MAX;
        $idB = is_array($b) && is_string($b['presentacion_id'] ?? null)
            && ctype_digit($b['presentacion_id']) ? (int) $b['presentacion_id'] : PHP_INT_MAX;
        return $idA <=> $idB;
    });
    $lineas = [];
    $separaIva = corozao_compras_iva_instalado($pdo);
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
        $tarifaRaw = $entrada['tarifa_iva'] ?? '';
        if (!is_string($presentacionRaw) || !is_string($cantidadRaw) || !is_string($costoRaw)
            || ($separaIva && !is_string($tarifaRaw))) {
            throw new DomainException('Los campos de producto, cantidad y costo deben ser texto válido.');
        }
        if ($presentacionRaw === '' && trim($cantidadRaw) === '' && trim($costoRaw) === ''
            && (!$separaIva || trim($tarifaRaw) === '')) {
            continue;
        }
        $presentacionId = filter_var($presentacionRaw, FILTER_VALIDATE_INT);
        if (!$presentacionId || $presentacionId < 1 || trim($cantidadRaw) === '' || trim($costoRaw) === ''
            || ($separaIva && trim($tarifaRaw) === '')) {
            throw new DomainException($separaIva
                ? 'Cada presentación requiere cantidad, costo antes de IVA y tarifa de IVA.'
                : 'Cada presentación requiere cantidad y costo unitario.');
        }
        if (isset($presentacionesVistas[$presentacionId])) {
            throw new DomainException('Cada presentación debe aparecer una sola vez en la compra.');
        }
        $presentacionesVistas[$presentacionId] = true;
        $cantidad = corozao_compra_entrada_decimal($cantidadRaw, 0, 11, true);
        $costo = corozao_compra_entrada_decimal($costoRaw, 2, 8, false);
        $tarifa = $separaIva ? corozao_compra_tarifa_iva($tarifaRaw) : null;

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
        $impuestoIva = null;
        if ($separaIva) {
            $ivaStmt = $pdo->prepare(
                'SELECT CAST(ROUND(CAST(:base AS DECIMAL(14,4)) * CAST(:tarifa AS DECIMAL(5,2)) / 100, 4)
                 AS DECIMAL(14,4))'
            );
            $ivaStmt->execute(['base' => $costoTotal, 'tarifa' => $tarifa]);
            $impuestoIva = (string)$ivaStmt->fetchColumn();
        }
        $lineas[] = [
            'producto_id' => (int) $presentacion['producto_id'],
            'presentacion_id' => (int) $presentacion['presentacion_id'],
            'cantidad_presentacion' => $cantidad,
            'unidades_base' => $unidadesBase,
            'cantidad_base' => $cantidadBase,
            'costo_unitario_presentacion' => $costo,
            'costo_total' => $costoTotal,
            'tarifa_iva_compra' => $tarifa,
            'impuesto_iva' => $impuestoIva,
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
    $camposIva = corozao_compras_iva_instalado($pdo)
        ? ', cd.tarifa_iva_compra, cd.impuesto_iva' : '';
    $stmt = $pdo->prepare(
        'SELECT cd.producto_id, cd.presentacion_id, cd.cantidad_presentacion, cd.unidades_base,
                cd.cantidad_base, cd.costo_unitario_presentacion, cd.costo_total' . $camposIva . ',
                p.nombre AS producto, p.marca, p.contenido, c.nombre AS categoria,
                (SELECT GROUP_CONCAT(DISTINCT pc.codigo ORDER BY pc.codigo) FROM producto_codigos pc
                 WHERE pc.presentacion_id = cd.presentacion_id) AS codigos,
                pr.nombre AS presentacion
         FROM compra_detalles cd
         INNER JOIN productos p ON p.producto_id = cd.producto_id
         INNER JOIN presentaciones pr ON pr.presentacion_id = cd.presentacion_id
         LEFT JOIN categorias c ON c.categoria_id = p.categoria_id
         WHERE cd.compra_id = :compra ORDER BY cd.producto_id, cd.presentacion_id'
    );
    $stmt->execute(['compra' => $compraId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function corozao_compra_insertar_detalles(PDO $pdo, int $compraId, array $lineas): void
{
    $separaIva = corozao_compras_iva_instalado($pdo);
    $sql = $separaIva
        ? 'INSERT INTO compra_detalles
            (compra_id, producto_id, presentacion_id, cantidad_presentacion,
             unidades_base, cantidad_base, costo_unitario_presentacion, costo_total,
             tarifa_iva_compra, impuesto_iva)
           VALUES (:compra, :producto, :presentacion, :cantidad_presentacion,
             :unidades_base, :cantidad_base, :costo_unitario, :costo_total, :tarifa_iva, :impuesto_iva)'
        : 'INSERT INTO compra_detalles
            (compra_id, producto_id, presentacion_id, cantidad_presentacion,
             unidades_base, cantidad_base, costo_unitario_presentacion, costo_total)
           VALUES (:compra, :producto, :presentacion, :cantidad_presentacion,
             :unidades_base, :cantidad_base, :costo_unitario, :costo_total)';
    $stmt = $pdo->prepare($sql);
    foreach ($lineas as $linea) {
        $params = [
            'compra' => $compraId,
            'producto' => $linea['producto_id'],
            'presentacion' => $linea['presentacion_id'],
            'cantidad_presentacion' => $linea['cantidad_presentacion'],
            'unidades_base' => $linea['unidades_base'],
            'cantidad_base' => $linea['cantidad_base'],
            'costo_unitario' => $linea['costo_unitario_presentacion'],
            'costo_total' => $linea['costo_total'],
        ];
        if ($separaIva) {
            $params['tarifa_iva'] = $linea['tarifa_iva_compra'];
            $params['impuesto_iva'] = $linea['impuesto_iva'];
        }
        $stmt->execute($params);
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
    string $clave,
    string $fechaCompra
): int {
    if (strlen($referencia) > 100 || strlen($observaciones) > 500) {
        throw new DomainException('La referencia o las observaciones exceden su longitud máxima.');
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clave)) {
        throw new DomainException('La clave de solicitud no es válida. Recarga la página.');
    }
    $fechaCompra = corozao_compra_fecha_entrada($fechaCompra);
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
                     :clave, :usuario, :fecha_compra)'
        );
        $stmt->execute([
            'proveedor' => $proveedorId,
            'bodega' => $bodegaId,
            'referencia' => $referencia === '' ? null : $referencia,
            'observaciones' => $observaciones === '' ? null : $observaciones,
            'clave' => strtolower($clave),
            'usuario' => $usuarioId,
            'fecha_compra' => $fechaCompra,
        ]);
        $compraId = (int) $pdo->lastInsertId();
        corozao_compra_insertar_detalles($pdo, $compraId, $lineas);
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'compras', $compraId, null, [
            'estado' => 'BORRADOR', 'proveedor_id' => $proveedorId, 'bodega_id' => $bodegaId,
            'referencia_proveedor' => $referencia === '' ? null : $referencia,
            'fecha_compra' => $fechaCompra, 'detalles' => $lineas,
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
    string $observaciones,
    string $fechaCompra
): void {
    if (strlen($referencia) > 100 || strlen($observaciones) > 500) {
        throw new DomainException('La referencia o las observaciones exceden su longitud máxima.');
    }
    $fechaCompra = corozao_compra_fecha_entrada($fechaCompra);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT compra_id, estado, referencia_proveedor, observaciones, fecha_compra
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
            'fecha_compra' => $compra['fecha_compra'],
            'detalles' => corozao_compra_detalles_snapshot($pdo, $compraId),
        ];
        $lineas = corozao_compra_validar_lineas($pdo, $entradas);
        $stmt = $pdo->prepare('DELETE FROM compra_detalles WHERE compra_id = :id');
        $stmt->execute(['id' => $compraId]);
        corozao_compra_insertar_detalles($pdo, $compraId, $lineas);
        $stmt = $pdo->prepare(
            'UPDATE compras SET referencia_proveedor = :referencia, observaciones = :observaciones,
                 fecha_compra = :fecha_compra
             WHERE compra_id = :id AND estado = \'BORRADOR\''
        );
        $stmt->execute([
            'referencia' => $referencia === '' ? null : $referencia,
            'observaciones' => $observaciones === '' ? null : $observaciones,
            'fecha_compra' => $fechaCompra,
            'id' => $compraId,
        ]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'compras', $compraId, $anteriores, [
            'estado' => 'BORRADOR', 'referencia_proveedor' => $referencia === '' ? null : $referencia,
            'observaciones' => $observaciones === '' ? null : $observaciones,
            'fecha_compra' => $fechaCompra, 'detalles' => $lineas,
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
        $columnasIva = corozao_compras_iva_instalado($pdo)
            ? ', tarifa_iva_compra, impuesto_iva' : '';
        $stmt = $pdo->prepare(
            'SELECT compra_detalle_id, producto_id, presentacion_id, cantidad_presentacion,
                    unidades_base, cantidad_base, costo_unitario_presentacion, costo_total' . $columnasIva . '
             FROM compra_detalles WHERE compra_id = :id ORDER BY producto_id, presentacion_id FOR UPDATE'
        );
        $stmt->execute(['id' => $compraId]);
        $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lineas) {
            throw new DomainException('Agrega productos antes de confirmar la compra.');
        }
        if ($columnasIva !== '') {
            foreach ($lineas as $linea) {
                if ($linea['tarifa_iva_compra'] === null || $linea['impuesto_iva'] === null) {
                    throw new DomainException('Completa la tarifa de IVA de cada línea del borrador (usa 0 si no aplica) antes de confirmar.');
                }
            }
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
        $separaIva = corozao_compras_iva_instalado($pdo);
        $detallesSnapshot = array_map(static function (array $linea) use ($separaIva): array {
            $snapshot = [
                'producto_id' => (int) $linea['producto_id'],
                'presentacion_id' => (int) $linea['presentacion_id'],
                'cantidad_base' => (string) $linea['cantidad_base'],
                'costo_neto' => (string) $linea['costo_total'],
            ];
            if ($separaIva) {
                $snapshot['tarifa_iva_compra'] = (string)$linea['tarifa_iva_compra'];
                $snapshot['impuesto_iva'] = (string)$linea['impuesto_iva'];
            }
            return $snapshot;
        }, $lineas);
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
