<?php
declare(strict_types=1);

function corozao_cargas_iniciales_instaladas(PDO $pdo): bool
{
    $tablas = ['cargas_inventario_inicial', 'cargas_inventario_inicial_detalles'];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_TYPE=\'BASE TABLE\' AND TABLE_NAME IN (?,?)');
    $stmt->execute($tablas);
    return (int) $stmt->fetchColumn() === count($tablas);
}

function corozao_carga_inicial_milesimas(string $valor): int
{
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})\.([0-9]{3})$/', $valor, $m)) {
        throw new DomainException('El conteo contiene una cantidad con formato no válido.');
    }
    $entero = substr($valor, 0, strpos($valor, '.'));
    return ((int) $entero * 1000) + (int) $m[1];
}

function corozao_carga_inicial_formato(int $milesimas): string
{
    return intdiv($milesimas, 1000) . '.' . str_pad((string) ($milesimas % 1000), 3, '0', STR_PAD_LEFT);
}

/** Confirma una carga aprobada por administrador usando exclusivamente lecturas físicas existentes. */
function corozao_carga_inicial_confirmar(PDO $pdo, int $conteoId, int $usuarioId, array $detallesIds, string $clave): int
{
    $sesion = corozao_exigir_permiso($pdo, 'inventory.initial_load.create');
    if ((int)$sesion['usuario_id'] !== $usuarioId) throw new DomainException('La sesión responsable de la carga cambió.');
    if ($conteoId < 1 || $usuarioId < 1
        || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clave)) {
        throw new DomainException('El conteo o la clave de operación no son válidos. Recarga la página.');
    }
    if (!$detallesIds || count($detallesIds) > 500) throw new DomainException('Selecciona entre uno y 500 productos contados.');
    $ids = [];
    foreach ($detallesIds as $raw) {
        if (!(is_int($raw) || is_string($raw)) || !ctype_digit((string)$raw) || (int)$raw < 1) {
            throw new DomainException('Una línea seleccionada no es válida.');
        }
        $id = (int)$raw;
        if (isset($ids[$id])) throw new DomainException('No repitas una línea del conteo.');
        $ids[$id] = $id;
    }
    $ids = array_values($ids);
    sort($ids, SORT_NUMERIC);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT carga_inicial_id,estado FROM cargas_inventario_inicial WHERE clave_idempotencia=:clave FOR UPDATE');
        $stmt->execute(['clave' => strtolower($clave)]);
        $repetida = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($repetida && $repetida['estado'] === 'CONFIRMADA') {
            $pdo->commit();
            return (int)$repetida['carga_inicial_id'];
        }
        if ($repetida) throw new DomainException('La solicitud de carga ya está en proceso.');

        $stmt = $pdo->prepare('SELECT conteo_id,bodega_id,estado FROM conteos_inventario WHERE conteo_id=:id FOR UPDATE');
        $stmt->execute(['id' => $conteoId]);
        $conteo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conteo || $conteo['estado'] !== 'CERRADO') throw new DomainException('La carga inicial requiere un conteo físico cerrado.');
        $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id=:id AND activo=1 FOR UPDATE');
        $stmt->execute(['id' => $conteo['bodega_id']]);
        if (!$stmt->fetchColumn()) throw new DomainException('La bodega del conteo está inactiva y no admite una carga inicial.');

        $placeholders = [];
        $params = ['conteo' => $conteoId];
        foreach ($ids as $i => $id) { $key = 'linea' . $i; $placeholders[] = ':' . $key; $params[$key] = $id; }
        $stmt = $pdo->prepare('SELECT d.conteo_detalle_id,d.producto_id,d.cantidad_teorica,d.cantidad_fisica,p.nombre AS producto
            FROM conteo_inventario_detalles d INNER JOIN productos p ON p.producto_id=d.producto_id
            WHERE d.conteo_id=:conteo AND d.conteo_detalle_id IN (' . implode(',', $placeholders) . ')
            ORDER BY d.producto_id FOR UPDATE');
        $stmt->execute($params);
        $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($lineas) !== count($ids)) throw new DomainException('Una o más líneas no pertenecen al conteo seleccionado.');

        $items = [];
        $saldoStmt = $pdo->prepare('SELECT cantidad_base FROM inventario_saldos WHERE bodega_id=:bodega AND producto_id=:producto FOR UPDATE');
        $historialStmt = $pdo->prepare('SELECT COUNT(*) FROM detalle_movimiento_inventario d
            INNER JOIN movimientos_inventario m ON m.movimiento_id=d.movimiento_id
            WHERE d.producto_id=:producto AND (m.bodega_origen_id=:origen OR m.bodega_destino_id=:destino)');
        $productoStmt = $pdo->prepare('SELECT producto_id FROM productos WHERE producto_id=:producto AND activo=1 FOR UPDATE');
        foreach ($lineas as $linea) {
            $productoId = (int)$linea['producto_id'];
            $teorico = corozao_carga_inicial_milesimas((string)$linea['cantidad_teorica']);
            $fisico = corozao_carga_inicial_milesimas((string)$linea['cantidad_fisica']);
            if ($teorico !== 0 || $fisico < 1) {
                throw new DomainException('Solo se cargan cantidades físicas positivas cuyo saldo teórico era cero: ' . $linea['producto'] . '.');
            }
            $productoStmt->execute(['producto' => $productoId]);
            if (!$productoStmt->fetchColumn()) throw new DomainException('El producto ' . $linea['producto'] . ' está inactivo o no existe.');
            $historialStmt->execute(['producto' => $productoId, 'origen' => $conteo['bodega_id'], 'destino' => $conteo['bodega_id']]);
            if ((int)$historialStmt->fetchColumn() !== 0) {
                throw new DomainException($linea['producto'] . ' ya tiene movimientos en esta bodega y no admite carga inicial.');
            }
            $saldoStmt->execute(['bodega' => $conteo['bodega_id'], 'producto' => $productoId]);
            $saldoRaw = $saldoStmt->fetchColumn();
            $saldo = $saldoRaw === false ? 0 : corozao_carga_inicial_milesimas((string)$saldoRaw);
            if ($saldo !== 0) throw new DomainException($linea['producto'] . ' ya tiene saldo en esta bodega.');
            $items[] = ['conteo_detalle_id' => (int)$linea['conteo_detalle_id'], 'producto_id' => $productoId,
                'producto' => (string)$linea['producto'], 'cantidad' => $fisico];
        }

        $stmt = $pdo->prepare('INSERT INTO cargas_inventario_inicial
            (bodega_id,conteo_id,estado,clave_idempotencia,usuario_id)
            VALUES (:bodega,:conteo,\'BORRADOR\',:clave,:usuario)');
        $stmt->execute(['bodega' => $conteo['bodega_id'], 'conteo' => $conteoId,
            'clave' => strtolower($clave), 'usuario' => $usuarioId]);
        $cargaId = (int)$pdo->lastInsertId();
        $observaciones = 'Carga inicial #' . $cargaId . ' aprobada desde conteo cerrado #' . $conteoId;
        $stmt = $pdo->prepare('INSERT INTO movimientos_inventario
            (tipo_movimiento,estado,bodega_origen_id,bodega_destino_id,referencia_tipo,referencia_id,
             observaciones,usuario_id,fecha_movimiento,clave_idempotencia)
            VALUES (\'AJUSTE_POSITIVO\',\'BORRADOR\',NULL,:bodega,\'CARGA_INICIAL\',:referencia,
             :observaciones,:usuario,CURRENT_TIMESTAMP,:clave)');
        $stmt->execute(['bodega' => $conteo['bodega_id'], 'referencia' => $cargaId,
            'observaciones' => $observaciones, 'usuario' => $usuarioId, 'clave' => strtolower($clave)]);
        $movimientoId = (int)$pdo->lastInsertId();

        $lineaCarga = $pdo->prepare('INSERT INTO cargas_inventario_inicial_detalles
            (carga_inicial_id,bodega_id,producto_id,conteo_detalle_id,cantidad_base,saldo_anterior,saldo_posterior)
            VALUES (:carga,:bodega,:producto,:conteo_detalle,:cantidad,0,:posterior)');
        $movimientoDetalle = $pdo->prepare('INSERT INTO detalle_movimiento_inventario
            (movimiento_id,producto_id,presentacion_id,cantidad_base,costo_unitario,costo_total)
            VALUES (:movimiento,:producto,NULL,:cantidad,NULL,NULL)');
        $crearSaldo = $pdo->prepare('INSERT INTO inventario_saldos (bodega_id,producto_id,cantidad_base)
            VALUES (:bodega,:producto,:cantidad)');
        $actualizarSaldo = $pdo->prepare('UPDATE inventario_saldos SET cantidad_base=:cantidad
            WHERE bodega_id=:bodega AND producto_id=:producto AND cantidad_base=0');
        $auditoriaItems = [];
        foreach ($items as $item) {
            $cantidad = corozao_carga_inicial_formato($item['cantidad']);
            $lineaCarga->execute(['carga' => $cargaId, 'bodega' => $conteo['bodega_id'], 'producto' => $item['producto_id'],
                'conteo_detalle' => $item['conteo_detalle_id'], 'cantidad' => $cantidad, 'posterior' => $cantidad]);
            $saldoStmt->execute(['bodega' => $conteo['bodega_id'], 'producto' => $item['producto_id']]);
            $saldoActual = $saldoStmt->fetchColumn();
            if ($saldoActual === false) {
                $crearSaldo->execute(['bodega' => $conteo['bodega_id'], 'producto' => $item['producto_id'], 'cantidad' => $cantidad]);
            } else {
                if (corozao_carga_inicial_milesimas((string)$saldoActual) !== 0) {
                    throw new DomainException($item['producto'] . ' recibió saldo durante la carga; se revirtió toda la operación.');
                }
                $actualizarSaldo->execute(['cantidad' => $cantidad, 'bodega' => $conteo['bodega_id'], 'producto' => $item['producto_id']]);
                if ($actualizarSaldo->rowCount() !== 1) throw new DomainException('El saldo de ' . $item['producto'] . ' cambió durante la carga.');
            }
            $movimientoDetalle->execute(['movimiento' => $movimientoId, 'producto' => $item['producto_id'], 'cantidad' => $cantidad]);
            $auditoriaItems[] = ['producto_id' => $item['producto_id'], 'cantidad_base' => $cantidad,
                'saldo_anterior' => '0.000', 'saldo_posterior' => $cantidad, 'conteo_detalle_id' => $item['conteo_detalle_id']];
        }
        $stmt = $pdo->prepare('UPDATE movimientos_inventario SET estado=\'CONFIRMADO\',confirmado_por_usuario_id=:usuario,
            fecha_confirmacion=CURRENT_TIMESTAMP WHERE movimiento_id=:id AND estado=\'BORRADOR\'');
        $stmt->execute(['usuario' => $usuarioId, 'id' => $movimientoId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('No se pudo confirmar el movimiento inicial.');
        $stmt = $pdo->prepare('UPDATE cargas_inventario_inicial SET estado=\'CONFIRMADA\',movimiento_id=:movimiento,
            confirmada_por_usuario_id=:usuario,fecha_confirmacion=CURRENT_TIMESTAMP(6)
            WHERE carga_inicial_id=:id AND estado=\'BORRADOR\'');
        $stmt->execute(['movimiento' => $movimientoId, 'usuario' => $usuarioId, 'id' => $cargaId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('No se pudo confirmar la carga inicial.');
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'cargas_inventario_inicial', $cargaId, null,
            ['estado' => 'CONFIRMADA', 'bodega_id' => (int)$conteo['bodega_id'], 'conteo_id' => $conteoId,
                'movimiento_id' => $movimientoId, 'productos' => $auditoriaItems]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'movimientos_inventario', $movimientoId,
            ['estado' => 'BORRADOR'], ['estado' => 'CONFIRMADO', 'tipo_movimiento' => 'AJUSTE_POSITIVO',
                'bodega_id' => (int)$conteo['bodega_id'], 'referencia_tipo' => 'CARGA_INICIAL',
                'referencia_id' => $cargaId, 'productos' => $auditoriaItems]);
        $pdo->commit();
        return $cargaId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') throw new DomainException('Una línea de conteo o producto ya fue cargado; no se duplicó inventario.');
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
