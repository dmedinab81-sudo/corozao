<?php
declare(strict_types=1);

function corozao_devoluciones_instaladas(PDO $pdo): bool
{
    $tables = ['devoluciones', 'devolucion_detalles', 'devolucion_pagos', 'caja_reembolsos_conciliaciones'];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_TYPE=\'BASE TABLE\' AND TABLE_NAME IN (?,?,?,?)');
    $stmt->execute($tables);
    return (int)$stmt->fetchColumn() === count($tables);
}

function corozao_devolucion_cantidad(string $valor): int
{
    $valor = trim($valor);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})$/', $valor)) {
        throw new DomainException('La cantidad debe ser un número entero positivo.');
    }
    return (int)$valor * 1000;
}

function corozao_devolucion_formato_cantidad(int $milesimas): string
{
    return intdiv($milesimas, 1000) . '.' . str_pad((string)($milesimas % 1000), 3, '0', STR_PAD_LEFT);
}

function corozao_devolucion_ratio(PDO $pdo, int $importe, int $parte, int $total, bool $redondear = true): int
{
    if ($importe < 0 || $parte < 0 || $total < 1 || $parte > $total) {
        throw new DomainException('No se pudo calcular el importe proporcional de la devolución.');
    }
    $expr = 'CAST(:importe AS DECIMAL(20,0))*CAST(:parte AS DECIMAL(20,0))/CAST(:total AS DECIMAL(20,0))';
    $expr = $redondear ? 'ROUND(' . $expr . ',0)' : 'FLOOR(' . $expr . ')';
    $stmt = $pdo->prepare('SELECT CAST(' . $expr . ' AS UNSIGNED)');
    $stmt->execute(['importe' => (string)$importe, 'parte' => (string)$parte, 'total' => (string)$total]);
    return (int)$stmt->fetchColumn();
}

function corozao_devolucion_distribuir(PDO $pdo, int $monto, array $original, array $reembolsado): array
{
    $restantes = [];
    foreach ($original as $medio => $importe) {
        $saldo = $importe - ($reembolsado[$medio] ?? 0);
        if ($saldo < 0) throw new DomainException('El historial de reembolsos supera el pago original.');
        if ($saldo > 0) $restantes[$medio] = $saldo;
    }
    $total = array_sum($restantes);
    if ($monto < 0 || $monto > $total) throw new DomainException('El reembolso supera el saldo pagado pendiente.');
    if ($monto === 0) return [];
    if ($monto === $total) return $restantes;

    $stmt = $pdo->prepare('SELECT CAST(FLOOR(CAST(:monto1 AS DECIMAL(20,0))*CAST(:saldo1 AS DECIMAL(20,0))/CAST(:total1 AS DECIMAL(20,0))) AS UNSIGNED) AS base,
        CAST(MOD(CAST(:monto2 AS DECIMAL(20,0))*CAST(:saldo2 AS DECIMAL(20,0)),CAST(:total2 AS DECIMAL(20,0))) AS UNSIGNED) AS resto');
    $asignado = array_fill_keys(array_keys($original), 0);
    $pendiente = $monto;
    while ($pendiente > 0) {
        $activos = array_filter($restantes, static fn(int $saldo): bool => $saldo > 0);
        if (!$activos) throw new DomainException('No se pudo distribuir el reembolso entre los medios originales.');
        $pesoTotal = 0;
        foreach ($activos as $medio => $_saldo) $pesoTotal += $original[$medio];
        $bases = $restos = [];
        foreach ($activos as $medio => $_saldo) {
            $params = ['monto1' => (string)$pendiente, 'saldo1' => (string)$original[$medio], 'total1' => (string)$pesoTotal,
                'monto2' => (string)$pendiente, 'saldo2' => (string)$original[$medio], 'total2' => (string)$pesoTotal];
            $stmt->execute($params); $fila = $stmt->fetch(PDO::FETCH_ASSOC);
            $bases[$medio] = (int)$fila['base']; $restos[$medio] = (int)$fila['resto'];
        }
        $sobranteCentavos = $pendiente - array_sum($bases);
        $orden = array_keys($activos);
        usort($orden, static fn(string $a, string $b): int => ($restos[$b] <=> $restos[$a]) ?: strcmp($a, $b));
        foreach ($orden as $medio) if ($sobranteCentavos > 0) { $bases[$medio]++; $sobranteCentavos--; }

        $asignadoEnRonda = 0;
        foreach ($bases as $medio => $propuesto) {
            $aplicar = min($propuesto, $restantes[$medio]);
            $asignado[$medio] += $aplicar; $restantes[$medio] -= $aplicar; $asignadoEnRonda += $aplicar;
        }
        if ($asignadoEnRonda < 1) throw new DomainException('No se pudo distribuir el reembolso entre los medios originales.');
        $pendiente -= $asignadoEnRonda;
    }
    return array_filter($asignado, static fn(int $v): bool => $v > 0);
}

/** CAJERO o ADMINISTRADOR solicita; la aprobación y los efectos económicos son siempre administrativos. */
function corozao_devolucion_solicitar(PDO $pdo, array $usuario, int $ventaId, array $cantidades, string $motivo, string $clave): int
{
    $sesion = corozao_exigir_permiso($pdo, 'sales.return.request');
    $usuarioId = (int)$sesion['usuario_id'];
    if ((int)($usuario['usuario_id'] ?? 0) !== $usuarioId) throw new DomainException('La sesión responsable cambió.');
    $motivo = trim($motivo);
    if ($motivo === '' || mb_strlen($motivo, 'UTF-8') > 500) throw new DomainException('El motivo es obligatorio y admite hasta 500 caracteres.');
    if ($ventaId < 1 || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clave)) {
        throw new DomainException('La venta o clave de operación no son válidas. Recarga la página.');
    }
    if (!$cantidades || count($cantidades) > 100) throw new DomainException('Selecciona entre uno y 100 productos devueltos.');
    $solicitadas = [];
    foreach ($cantidades as $rawId => $rawCantidad) {
        if (!(is_int($rawId) || is_string($rawId)) || !ctype_digit((string)$rawId) || (int)$rawId < 1 || !is_string($rawCantidad)) {
            throw new DomainException('Una línea o cantidad de devolución no es válida.');
        }
        $lineaId = (int)$rawId;
        if (isset($solicitadas[$lineaId])) throw new DomainException('No repitas una línea de la venta.');
        $cantidad = corozao_devolucion_cantidad($rawCantidad);
        if ($cantidad > 0) $solicitadas[$lineaId] = $cantidad;
    }
    if (!$solicitadas) throw new DomainException('Indica una cantidad mayor que cero en al menos un producto.');

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT devolucion_id,venta_id,solicitada_por_usuario_id FROM devoluciones WHERE clave_idempotencia=:clave FOR UPDATE');
        $stmt->execute(['clave' => strtolower($clave)]);
        $previa = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($previa) {
            if ((int)$previa['venta_id'] !== $ventaId || (int)$previa['solicitada_por_usuario_id'] !== $usuarioId) {
                throw new DomainException('La clave ya pertenece a otra solicitud. Recarga la página.');
            }
            $pdo->commit();
            return (int)$previa['devolucion_id'];
        }

        $stmt = $pdo->prepare('SELECT venta_id,bodega_id,estado FROM ventas WHERE venta_id=:id FOR UPDATE');
        $stmt->execute(['id' => $ventaId]);
        $venta = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$venta || $venta['estado'] !== 'CONFIRMADA') throw new DomainException('Solo se admiten devoluciones de ventas confirmadas.');
        if ($sesion['rol'] === 'ADMINISTRADOR') {
            $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id=:id AND activo=1');
            $stmt->execute(['id' => $venta['bodega_id']]);
            if (!$stmt->fetchColumn()) throw new DomainException('La bodega de la venta está inactiva.');
        } else {
            corozao_exigir_bodega($pdo, $sesion, (int)$venta['bodega_id']);
        }

        $stmt = $pdo->prepare('SELECT vd.venta_detalle_id,vd.cantidad_base,vd.producto_id,p.nombre AS producto,
            COALESCE((SELECT SUM(dd.cantidad_base) FROM devolucion_detalles dd
                INNER JOIN devoluciones d ON d.devolucion_id=dd.devolucion_id
                WHERE d.venta_id=vd.venta_id AND d.estado IN (\'PENDIENTE\',\'APROBADA\')
                  AND dd.venta_detalle_id=vd.venta_detalle_id),0) AS reservada
            FROM venta_detalles vd INNER JOIN productos p ON p.producto_id=vd.producto_id
            WHERE vd.venta_id=:venta ORDER BY vd.venta_detalle_id FOR UPDATE');
        $stmt->execute(['venta' => $ventaId]);
        $lineas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linea) $lineas[(int)$linea['venta_detalle_id']] = $linea;
        foreach ($solicitadas as $lineaId => $cantidad) {
            if (!isset($lineas[$lineaId])) throw new DomainException('Una línea seleccionada no pertenece a esta venta.');
            $vendida = corozao_cantidad_milesima((string)$lineas[$lineaId]['cantidad_base']);
            $reservada = corozao_cantidad_milesima((string)$lineas[$lineaId]['reservada']);
            if ($cantidad + $reservada > $vendida) throw new DomainException('La cantidad de ' . $lineas[$lineaId]['producto'] . ' supera lo vendido disponible para devolución.');
        }

        $stmt = $pdo->prepare('INSERT INTO devoluciones (venta_id,estado,motivo,clave_idempotencia,solicitada_por_usuario_id)
            VALUES (:venta,\'PENDIENTE\',:motivo,:clave,:usuario)');
        $stmt->execute(['venta' => $ventaId, 'motivo' => $motivo, 'clave' => strtolower($clave), 'usuario' => $usuarioId]);
        $devolucionId = (int)$pdo->lastInsertId();
        $insertar = $pdo->prepare('INSERT INTO devolucion_detalles (devolucion_id,venta_detalle_id,cantidad_base)
            VALUES (:devolucion,:linea,:cantidad)');
        foreach ($solicitadas as $lineaId => $cantidad) {
            $insertar->execute(['devolucion' => $devolucionId, 'linea' => $lineaId,
                'cantidad' => corozao_devolucion_formato_cantidad($cantidad)]);
        }
        $detalleAudit = [];
        foreach ($solicitadas as $lineaId => $cantidad) $detalleAudit[] = ['venta_detalle_id' => $lineaId,
            'producto_id' => (int)$lineas[$lineaId]['producto_id'], 'cantidad_base' => corozao_devolucion_formato_cantidad($cantidad)];
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'devoluciones', $devolucionId, null,
            ['venta_id' => $ventaId, 'estado' => 'PENDIENTE', 'motivo' => $motivo, 'productos' => $detalleAudit]);
        $pdo->commit();
        return $devolucionId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') {
            $stmt=$pdo->prepare('SELECT devolucion_id,venta_id,solicitada_por_usuario_id FROM devoluciones WHERE clave_idempotencia=:clave');
            $stmt->execute(['clave'=>strtolower($clave)]); $existente=$stmt->fetch(PDO::FETCH_ASSOC);
            if ($existente && (int)$existente['venta_id']===$ventaId && (int)$existente['solicitada_por_usuario_id']===$usuarioId) {
                return (int)$existente['devolucion_id'];
            }
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Aprueba devolución, registra pagos originales, caja, stock vendible, movimiento y auditoría en una sola transacción. */
function corozao_devolucion_aprobar(PDO $pdo, int $devolucionId, int $usuarioId, array $vendibles, array $referencias): int
{
    $sesion = corozao_exigir_permiso($pdo, 'sales.return.approve');
    if ((int)$sesion['usuario_id'] !== $usuarioId || $devolucionId < 1) throw new DomainException('La sesión o devolución no son válidas.');
    if (!is_array($referencias)) throw new DomainException('Las referencias de reembolso no son válidas.');
    foreach ($referencias as $medio => $ref) {
        if (!in_array($medio, ['TRANSFERENCIA','TARJETA_CREDITO'], true) || !is_string($ref) || mb_strlen($ref, 'UTF-8') > 100) {
            throw new DomainException('Una referencia de reembolso no es válida.');
        }
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM devoluciones WHERE devolucion_id=:id FOR UPDATE');
        $stmt->execute(['id' => $devolucionId]);
        $devolucion = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$devolucion) throw new DomainException('La devolución no existe.');
        if ($devolucion['estado'] === 'APROBADA') { $pdo->commit(); return $devolucionId; }
        if ($devolucion['estado'] !== 'PENDIENTE') throw new DomainException('La solicitud ya fue resuelta.');

        $stmt = $pdo->prepare('SELECT venta_id,bodega_id,total,estado FROM ventas WHERE venta_id=:id FOR UPDATE');
        $stmt->execute(['id' => $devolucion['venta_id']]);
        $venta = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$venta || $venta['estado'] !== 'CONFIRMADA') throw new DomainException('La venta ya no admite devoluciones.');
        $bodegaId = (int)$venta['bodega_id'];
        $stmt = $pdo->prepare('SELECT activo FROM bodegas WHERE bodega_id=:id FOR UPDATE');
        $stmt->execute(['id' => $bodegaId]);
        if ((int)$stmt->fetchColumn() !== 1) throw new DomainException('La bodega de la venta está inactiva.');

        $stmt = $pdo->prepare('SELECT dd.devolucion_detalle_id,dd.venta_detalle_id,dd.cantidad_base,
                vd.producto_id,vd.cantidad_base AS cantidad_vendida,vd.total_bruto,p.nombre AS producto,p.activo
            FROM devolucion_detalles dd INNER JOIN venta_detalles vd ON vd.venta_detalle_id=dd.venta_detalle_id
            INNER JOIN productos p ON p.producto_id=vd.producto_id
            WHERE dd.devolucion_id=:id ORDER BY vd.producto_id,vd.venta_detalle_id FOR UPDATE');
        $stmt->execute(['id' => $devolucionId]);
        $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$lineas) throw new DomainException('La solicitud no tiene productos.');
        if (count($vendibles) !== count($lineas)) throw new DomainException('Confirma por separado si cada producto devuelto está vendible.');

        $stmt = $pdo->prepare('SELECT CAST(COALESCE(SUM(total_bruto),0) AS DECIMAL(14,2)) FROM venta_detalles WHERE venta_id=:venta');
        $stmt->execute(['venta' => $venta['venta_id']]);
        $brutoTotal = corozao_dinero_centavos((string)$stmt->fetchColumn());
        $stmt = $pdo->prepare('SELECT CAST(COALESCE(SUM(dd.importe_bruto_devuelto),0) AS DECIMAL(14,2))
            FROM devolucion_detalles dd INNER JOIN devoluciones d ON d.devolucion_id=dd.devolucion_id
            WHERE d.venta_id=:venta AND d.estado=\'APROBADA\'');
        $stmt->execute(['venta' => $venta['venta_id']]);
        $brutoAnterior = corozao_dinero_centavos((string)$stmt->fetchColumn());
        $stmt = $pdo->prepare('SELECT CAST(COALESCE(SUM(reembolso_total),0) AS DECIMAL(14,2)) FROM devoluciones
            WHERE venta_id=:venta AND estado=\'APROBADA\'');
        $stmt->execute(['venta' => $venta['venta_id']]);
        $reembolsoAnterior = corozao_dinero_centavos((string)$stmt->fetchColumn());

        $updateDetalle = $pdo->prepare('UPDATE devolucion_detalles SET importe_bruto_devuelto=:importe,producto_vendible=:vendible
            WHERE devolucion_detalle_id=:id');
        $cantidadesPorProducto = [];
        $auditoriaLineas = [];
        $brutoActual = 0;
        foreach ($lineas as $linea) {
            $detalleId = (int)$linea['devolucion_detalle_id'];
            if (!array_key_exists($detalleId, $vendibles) || !in_array((string)$vendibles[$detalleId], ['0','1'], true)) {
                throw new DomainException('El estado de vendibilidad de cada producto es obligatorio.');
            }
            $esVendible = (int)$vendibles[$detalleId];
            if ($esVendible === 1 && (int)$linea['activo'] !== 1) throw new DomainException('El producto ' . $linea['producto'] . ' está inactivo; no puede volver a existencias vendibles.');
            $cantidad = corozao_cantidad_milesima((string)$linea['cantidad_base']);
            $vendida = corozao_cantidad_milesima((string)$linea['cantidad_vendida']);
            $stmt = $pdo->prepare('SELECT CAST(COALESCE(SUM(dd.cantidad_base),0) AS DECIMAL(14,3)) AS cantidad,
                    CAST(COALESCE(SUM(dd.importe_bruto_devuelto),0) AS DECIMAL(14,2)) AS importe
                FROM devolucion_detalles dd INNER JOIN devoluciones d ON d.devolucion_id=dd.devolucion_id
                WHERE d.venta_id=:venta AND d.estado=\'APROBADA\' AND dd.venta_detalle_id=:linea');
            $stmt->execute(['venta' => $venta['venta_id'], 'linea' => $linea['venta_detalle_id']]);
            $previo = $stmt->fetch(PDO::FETCH_ASSOC);
            $cantidadAnterior = corozao_cantidad_milesima((string)$previo['cantidad']);
            $importeAnterior = corozao_dinero_centavos((string)$previo['importe']);
            $importeObjetivo = corozao_devolucion_ratio($pdo, corozao_dinero_centavos((string)$linea['total_bruto']),
                $cantidadAnterior + $cantidad, $vendida);
            $importeLinea = $importeObjetivo - $importeAnterior;
            if ($importeLinea < 0) throw new DomainException('El cálculo de importes de una devolución no es válido.');
            $brutoActual += $importeLinea;
            $updateDetalle->execute(['importe' => corozao_centavos_decimal($importeLinea), 'vendible' => $esVendible, 'id' => $detalleId]);
            if ($esVendible === 1) $cantidadesPorProducto[(int)$linea['producto_id']] =
                ($cantidadesPorProducto[(int)$linea['producto_id']] ?? 0) + $cantidad;
            $auditoriaLineas[] = ['producto_id' => (int)$linea['producto_id'], 'producto' => (string)$linea['producto'],
                'venta_detalle_id' => (int)$linea['venta_detalle_id'], 'cantidad_base' => (string)$linea['cantidad_base'],
                'importe_bruto_devuelto' => corozao_centavos_decimal($importeLinea), 'producto_vendible' => (bool)$esVendible];
        }

        $totalVenta = corozao_dinero_centavos((string)$venta['total']);
        $brutoAcumulado = $brutoAnterior + $brutoActual;
        $reembolsoObjetivo = $brutoTotal > 0 ? corozao_devolucion_ratio($pdo, $totalVenta, $brutoAcumulado, $brutoTotal) : 0;
        $reembolsoActual = $reembolsoObjetivo - $reembolsoAnterior;
        if ($reembolsoActual < 0) throw new DomainException('El cálculo acumulado de reembolso no es válido.');

        $pagosOriginales = [];
        $stmt = $pdo->prepare('SELECT medio,CAST(SUM(monto) AS DECIMAL(14,2)) AS monto FROM venta_pagos
            WHERE venta_id=:venta GROUP BY medio ORDER BY medio');
        $stmt->execute(['venta' => $venta['venta_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pago) $pagosOriginales[$pago['medio']] = corozao_dinero_centavos((string)$pago['monto']);
        if (array_sum($pagosOriginales) !== $totalVenta) throw new DomainException('Los pagos originales no concilian con el total de la venta.');
        $pagosPrevios = [];
        $stmt = $pdo->prepare('SELECT dp.medio,CAST(SUM(dp.monto) AS DECIMAL(14,2)) AS monto FROM devolucion_pagos dp
            INNER JOIN devoluciones d ON d.devolucion_id=dp.devolucion_id
            WHERE d.venta_id=:venta AND d.estado=\'APROBADA\' GROUP BY dp.medio');
        $stmt->execute(['venta' => $venta['venta_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pago) $pagosPrevios[$pago['medio']] = corozao_dinero_centavos((string)$pago['monto']);
        $pagosActuales = corozao_devolucion_distribuir($pdo, $reembolsoActual, $pagosOriginales, $pagosPrevios);

        $cajaId = null;
        if ($reembolsoActual > 0) {
            $fechaCajaSelect = corozao_caja_fecha_operativa_instalada($pdo) ? 'fecha_operativa' : 'NULL AS fecha_operativa';
            $stmt = $pdo->prepare('SELECT caja_id,bodega_id,' . $fechaCajaSelect . ' FROM cajas
                WHERE usuario_id=:usuario AND bodega_id=:bodega AND estado=\'ABIERTA\' FOR UPDATE');
            $stmt->execute(['usuario' => $usuarioId, 'bodega' => $bodegaId]);
            $caja = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$caja) throw new DomainException('Abre una caja en la bodega de la venta para registrar el reembolso.');
            if (corozao_caja_fecha_operativa_instalada($pdo)
                && !corozao_fecha_caja_es_hoy($caja['fecha_operativa'] === null ? null : (string)$caja['fecha_operativa'], corozao_fecha_operativa_local())) {
                throw new DomainException('La caja es de una fecha operativa anterior; abre una caja del día para registrar el reembolso.');
            }
            $cajaId = (int)$caja['caja_id'];
            $efectivoReembolso = $pagosActuales['EFECTIVO'] ?? 0;
            if ($efectivoReembolso > 0) {
                $stmt = $pdo->prepare('SELECT CAST(c.monto_apertura AS DECIMAL(14,2)) +
                    COALESCE((SELECT SUM(vp.monto) FROM ventas v INNER JOIN venta_pagos vp ON vp.venta_id=v.venta_id
                        WHERE v.caja_id=c.caja_id AND vp.medio=\'EFECTIVO\'),0) -
                    COALESCE((SELECT SUM(dp.monto) FROM devolucion_pagos dp INNER JOIN devoluciones d ON d.devolucion_id=dp.devolucion_id
                        WHERE d.caja_id=c.caja_id AND d.estado=\'APROBADA\' AND dp.medio=\'EFECTIVO\'),0) AS disponible
                    FROM cajas c WHERE c.caja_id=:caja FOR UPDATE');
                $stmt->execute(['caja' => $cajaId]);
                if (corozao_dinero_centavos((string)$stmt->fetchColumn()) < $efectivoReembolso) {
                    throw new DomainException('La caja no tiene efectivo disponible suficiente para entregar el reembolso.');
                }
            }
        }

        $claveMovimiento = null;
        $movimientoId = null;
        if ($cantidadesPorProducto) {
            $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
            $hex = bin2hex($bytes); $claveMovimiento = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
            $motivoMovimiento = 'Reintegro de productos vendibles por devolución #' . $devolucionId . ': ' . trim((string)$devolucion['motivo']);
            if (mb_strlen($motivoMovimiento, 'UTF-8') > 500) $motivoMovimiento = mb_substr($motivoMovimiento, 0, 500, 'UTF-8');
            $stmt = $pdo->prepare('INSERT INTO movimientos_inventario
                (tipo_movimiento,estado,bodega_origen_id,bodega_destino_id,referencia_tipo,referencia_id,
                 observaciones,usuario_id,fecha_movimiento,clave_idempotencia)
                VALUES (\'AJUSTE_POSITIVO\',\'BORRADOR\',NULL,:bodega,\'DEVOLUCION\',:referencia,:observaciones,:usuario,CURRENT_TIMESTAMP,:clave)');
            $stmt->execute(['bodega' => $bodegaId, 'referencia' => $devolucionId, 'observaciones' => $motivoMovimiento,
                'usuario' => $usuarioId, 'clave' => $claveMovimiento]);
            $movimientoId = (int)$pdo->lastInsertId();
            $detalleMovimiento = $pdo->prepare('INSERT INTO detalle_movimiento_inventario
                (movimiento_id,producto_id,presentacion_id,cantidad_base,costo_unitario,costo_total)
                VALUES (:movimiento,:producto,NULL,:cantidad,NULL,NULL)');
            $crearSaldo = $pdo->prepare('INSERT INTO inventario_saldos (bodega_id,producto_id,cantidad_base)
                VALUES (:bodega,:producto,0) ON DUPLICATE KEY UPDATE producto_id=VALUES(producto_id)');
            $leerSaldo = $pdo->prepare('SELECT cantidad_base FROM inventario_saldos WHERE bodega_id=:bodega AND producto_id=:producto FOR UPDATE');
            $sumarSaldo = $pdo->prepare('UPDATE inventario_saldos SET cantidad_base=cantidad_base+:cantidad WHERE bodega_id=:bodega AND producto_id=:producto');
            $auditoriaSaldos = [];
            ksort($cantidadesPorProducto, SORT_NUMERIC);
            foreach ($cantidadesPorProducto as $productoId => $cantidad) {
                $crearSaldo->execute(['bodega' => $bodegaId, 'producto' => $productoId]);
                $leerSaldo->execute(['bodega' => $bodegaId, 'producto' => $productoId]);
                $antes = (string)$leerSaldo->fetchColumn();
                $cantidadDecimal = corozao_devolucion_formato_cantidad($cantidad);
                $sumarSaldo->execute(['cantidad' => $cantidadDecimal, 'bodega' => $bodegaId, 'producto' => $productoId]);
                if ($sumarSaldo->rowCount() !== 1) throw new DomainException('No se pudo actualizar una existencia devuelta.');
                $leerSaldo->execute(['bodega' => $bodegaId, 'producto' => $productoId]);
                $despues = (string)$leerSaldo->fetchColumn();
                $detalleMovimiento->execute(['movimiento' => $movimientoId, 'producto' => $productoId, 'cantidad' => $cantidadDecimal]);
                $auditoriaSaldos[] = ['producto_id' => $productoId, 'cantidad_base' => $cantidadDecimal,
                    'saldo_anterior' => $antes, 'saldo_posterior' => $despues];
            }
            $stmt = $pdo->prepare('UPDATE movimientos_inventario SET estado=\'CONFIRMADO\',confirmado_por_usuario_id=:usuario,
                fecha_confirmacion=CURRENT_TIMESTAMP WHERE movimiento_id=:id AND estado=\'BORRADOR\'');
            $stmt->execute(['usuario' => $usuarioId, 'id' => $movimientoId]);
            if ($stmt->rowCount() !== 1) throw new DomainException('No se pudo confirmar el movimiento de devolución.');
            corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'movimientos_inventario', $movimientoId,
                ['estado' => 'BORRADOR'], ['estado' => 'CONFIRMADO', 'tipo_movimiento' => 'AJUSTE_POSITIVO',
                    'referencia_tipo' => 'DEVOLUCION', 'referencia_id' => $devolucionId, 'bodega_id' => $bodegaId,
                    'productos' => $auditoriaSaldos]);
        }

        $pagoStmt = $pdo->prepare('INSERT INTO devolucion_pagos (devolucion_id,medio,monto,referencia)
            VALUES (:devolucion,:medio,:monto,:referencia)');
        $pagosAuditoria = [];
        foreach ($pagosActuales as $medio => $monto) {
            $referencia = trim((string)($referencias[$medio] ?? ''));
            if ($medio !== 'EFECTIVO' && ($referencia === '' || mb_strlen($referencia, 'UTF-8') > 100)) {
                throw new DomainException('Registra la referencia del reembolso por ' . $medio . '.');
            }
            $pagoStmt->execute(['devolucion' => $devolucionId, 'medio' => $medio,
                'monto' => corozao_centavos_decimal($monto), 'referencia' => $referencia ?: null]);
            $pagosAuditoria[$medio] = ['monto' => corozao_centavos_decimal($monto)];
        }

        $stmt = $pdo->prepare('UPDATE devoluciones SET estado=\'APROBADA\',caja_id=:caja,movimiento_id=:movimiento,
            reembolso_total=:reembolso,resuelta_por_usuario_id=:usuario,fecha_resolucion=CURRENT_TIMESTAMP(6)
            WHERE devolucion_id=:id AND estado=\'PENDIENTE\'');
        $stmt->execute(['caja' => $cajaId, 'movimiento' => $movimientoId, 'reembolso' => corozao_centavos_decimal($reembolsoActual),
            'usuario' => $usuarioId, 'id' => $devolucionId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('La solicitud cambió mientras se aprobaba.');
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'devoluciones', $devolucionId,
            ['estado' => 'PENDIENTE', 'venta_id' => (int)$venta['venta_id']],
            ['estado' => 'APROBADA', 'venta_id' => (int)$venta['venta_id'], 'bodega_id' => $bodegaId,
                'caja_id' => $cajaId, 'movimiento_id' => $movimientoId,
                'reembolso_total' => corozao_centavos_decimal($reembolsoActual), 'pagos' => $pagosAuditoria,
                'productos' => $auditoriaLineas]);
        $pdo->commit();
        return $devolucionId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function corozao_devolucion_rechazar(PDO $pdo, int $devolucionId, int $usuarioId, string $motivo): void
{
    $sesion = corozao_exigir_permiso($pdo, 'sales.return.approve');
    if ((int)$sesion['usuario_id'] !== $usuarioId || $devolucionId < 1) throw new DomainException('La sesión o devolución no son válidas.');
    $motivo = trim($motivo);
    if ($motivo === '' || mb_strlen($motivo, 'UTF-8') > 500) throw new DomainException('Explica el motivo del rechazo.');
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT estado,venta_id,motivo FROM devoluciones WHERE devolucion_id=:id FOR UPDATE');
        $stmt->execute(['id' => $devolucionId]);
        $devolucion = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$devolucion) throw new DomainException('La devolución no existe.');
        if ($devolucion['estado'] !== 'PENDIENTE') throw new DomainException('La solicitud ya fue resuelta.');
        $stmt = $pdo->prepare('UPDATE devoluciones SET estado=\'RECHAZADA\',motivo_resolucion=:motivo,
            resuelta_por_usuario_id=:usuario,fecha_resolucion=CURRENT_TIMESTAMP(6)
            WHERE devolucion_id=:id AND estado=\'PENDIENTE\'');
        $stmt->execute(['motivo' => $motivo, 'usuario' => $usuarioId, 'id' => $devolucionId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('La solicitud cambió mientras se rechazaba.');
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'devoluciones', $devolucionId,
            ['estado' => 'PENDIENTE', 'motivo' => (string)$devolucion['motivo']],
            ['estado' => 'RECHAZADA', 'venta_id' => (int)$devolucion['venta_id'], 'motivo_rechazo' => $motivo]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
