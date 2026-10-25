<?php
declare(strict_types=1);

function corozao_pedidos_instalados(PDO $pdo): bool
{
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (\'pedidos\', \'pedido_detalles\', \'auditoria_eventos\')'
    );
    if ((int) $stmt->fetchColumn() !== 3) {
        return false;
    }
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'pedidos\'
           AND COLUMN_NAME IN (\'clave_idempotencia\', \'actualizado_por_usuario_id\',
                               \'solicita_cierre_por_usuario_id\', \'fecha_solicitud_cierre\')'
    );
    if ((int) $stmt->fetchColumn() !== 4) {
        return false;
    }
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'pedido_detalles\'
           AND COLUMN_NAME IN (\'producto_id\', \'presentacion_id\', \'precio_bodega_id\',
                               \'cantidad_presentacion\', \'precio_unitario_presentacion\',
                               \'precio_incluye_iva\', \'tarifa_iva\', \'subtotal_linea\', \'usuario_agrega_id\')'
    );
    if ((int) $stmt->fetchColumn() !== 9) {
        return false;
    }
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'pedidos\'
           AND INDEX_NAME = \'uq_pedidos_idempotencia\' AND NON_UNIQUE = 0'
    );
    return (int) $stmt->fetchColumn() === 1;
}

function corozao_pedido_cantidad(string $valor): string
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

function corozao_pedido_decimal_exacto(string $valor, int $escalaOrigen, int $escalaDestino, int $maxEnteros): string
{
    if (!preg_match('/^(\d+)\.(\d+)$/', $valor, $m) || strlen($m[2]) !== $escalaOrigen) {
        throw new DomainException('El cálculo del pedido excede la precisión disponible.');
    }
    $entero = ltrim($m[1], '0');
    $entero = $entero === '' ? '0' : $entero;
    if (strlen($entero) > $maxEnteros || trim(substr($m[2], $escalaDestino), '0') !== '') {
        throw new DomainException('La cantidad o el subtotal excede el formato permitido.');
    }
    return $entero . '.' . str_pad(substr($m[2], 0, $escalaDestino), $escalaDestino, '0');
}

function corozao_pedido_validar_entradas(array $entradas): array
{
    if (count($entradas) > 10) {
        throw new DomainException('Cada pedido admite hasta diez presentaciones.');
    }
    $limpias = [];
    foreach ($entradas as $entrada) {
        if (!is_array($entrada)) {
            throw new DomainException('Una línea del pedido no es válida.');
        }
        $idRaw = $entrada['presentacion_id'] ?? '';
        $cantidadRaw = $entrada['cantidad'] ?? '';
        $eliminarRaw = $entrada['eliminar'] ?? '0';
        if (!is_string($idRaw) || !is_string($cantidadRaw) || !is_string($eliminarRaw)) {
            throw new DomainException('Los datos de las presentaciones no son válidos.');
        }
        if ($idRaw === '' && trim($cantidadRaw) === '') {
            continue;
        }
        if (!ctype_digit($idRaw) || (int) $idRaw < 1) {
            throw new DomainException('Selecciona una presentación válida.');
        }
        $id = (int) $idRaw;
        if (isset($limpias[$id])) {
            throw new DomainException('No repitas una presentación en el mismo pedido.');
        }
        if ($eliminarRaw === '1') {
            $limpias[$id] = ['eliminar' => true];
            continue;
        }
        $limpias[$id] = ['eliminar' => false, 'cantidad' => corozao_pedido_cantidad($cantidadRaw)];
    }
    if (!$limpias) {
        throw new DomainException('Agrega al menos una presentación al pedido.');
    }
    ksort($limpias, SORT_NUMERIC);
    return $limpias;
}

function corozao_pedido_snapshot_detalles(PDO $pdo, int $pedidoId): array
{
    $stmt = $pdo->prepare(
        'SELECT d.producto_id, p.nombre AS producto, d.presentacion_id, pr.nombre AS presentacion,
                d.precio_bodega_id, d.cantidad_presentacion, d.unidades_base, d.cantidad_base,
                d.precio_unitario_presentacion, d.precio_incluye_iva, d.tarifa_iva, d.subtotal_linea,
                d.usuario_agrega_id
         FROM pedido_detalles d
         INNER JOIN productos p ON p.producto_id = d.producto_id
         INNER JOIN presentaciones pr ON pr.presentacion_id = d.presentacion_id
         WHERE d.pedido_id = :pedido ORDER BY d.producto_id, d.presentacion_id'
    );
    $stmt->execute(['pedido' => $pedidoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Lee y bloquea el precio vigente junto con la habilitación comercial de una presentación. */
function corozao_pedido_precio_vigente(PDO $pdo, int $bodegaId, int $presentacionId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.producto_id, pr.presentacion_id, pr.unidades_base,
                pb.precio_bodega_id, pb.precio_venta, pb.precio_incluye_iva, pb.tarifa_iva
         FROM presentaciones pr
         INNER JOIN productos p ON p.producto_id = pr.producto_id
         INNER JOIN bodega_presentaciones bp ON bp.presentacion_id = pr.presentacion_id
         INNER JOIN precios_bodega pb ON pb.bodega_presentacion_id = bp.bodega_presentacion_id
         WHERE pr.presentacion_id = :presentacion AND pr.activo = 1
           AND p.activo = 1 AND p.venta_habilitada = 1 AND p.estado_comercial = \'VALIDADO\'
           AND bp.bodega_id = :bodega AND bp.activo = 1 AND pb.vigente_hasta IS NULL
           AND pb.vigente_desde <= CURRENT_TIMESTAMP(6)
         ORDER BY pb.vigente_desde DESC, pb.precio_bodega_id DESC FOR UPDATE'
    );
    $stmt->execute(['presentacion' => $presentacionId, 'bodega' => $bodegaId]);
    $precios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($precios) !== 1) {
        throw new DomainException(count($precios) > 1
            ? 'La presentación tiene más de un precio vigente; requiere revisión administrativa.'
            : 'La presentación no está habilitada o no tiene un precio vigente en esta bodega.');
    }
    if ($precios[0]['tarifa_iva'] === null) {
        throw new DomainException('La tarifa de IVA de la presentación está pendiente; no se agregó al pedido.');
    }
    return $precios[0];
}

function corozao_pedido_calcular_linea(PDO $pdo, string $cantidad, string $unidades, string $precio): array
{
    $stmt = $pdo->prepare(
        'SELECT CAST(CAST(:cantidad_base AS DECIMAL(14,3)) * CAST(:unidades AS DECIMAL(12,3)) AS DECIMAL(26,6)) AS cantidad_base,
                CAST(CAST(:cantidad_total AS DECIMAL(14,3)) * CAST(:precio AS DECIMAL(12,4)) AS DECIMAL(26,7)) AS subtotal'
    );
    $stmt->execute(['cantidad_base' => $cantidad, 'unidades' => $unidades,
        'cantidad_total' => $cantidad, 'precio' => $precio]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'cantidad_base' => corozao_pedido_decimal_exacto((string) $resultado['cantidad_base'], 6, 3, 11),
        'subtotal_linea' => corozao_pedido_decimal_exacto((string) $resultado['subtotal'], 7, 7, 19),
    ];
}

function corozao_pedido_insertar_linea(PDO $pdo, int $pedidoId, int $usuarioId, array $linea): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO pedido_detalles
            (pedido_id, producto_id, presentacion_id, precio_bodega_id,
             cantidad_presentacion, unidades_base, cantidad_base, precio_unitario_presentacion,
             precio_incluye_iva, tarifa_iva, subtotal_linea, usuario_agrega_id)
         VALUES (:pedido, :producto, :presentacion, :precio_id,
                 :cantidad_presentacion, :unidades_base, :cantidad_base, :precio,
                 :incluye_iva, :tarifa_iva, :subtotal, :usuario)'
    );
    $stmt->execute([
        'pedido' => $pedidoId, 'producto' => $linea['producto_id'],
        'presentacion' => $linea['presentacion_id'], 'precio_id' => $linea['precio_bodega_id'],
        'cantidad_presentacion' => $linea['cantidad_presentacion'], 'unidades_base' => $linea['unidades_base'],
        'cantidad_base' => $linea['cantidad_base'], 'precio' => $linea['precio_unitario_presentacion'],
        'incluye_iva' => $linea['precio_incluye_iva'], 'tarifa_iva' => $linea['tarifa_iva'],
        'subtotal' => $linea['subtotal_linea'], 'usuario' => $usuarioId,
    ]);
}

function corozao_pedido_crear(
    PDO $pdo, int $usuarioId, int $bodegaId, array $entradas,
    string $referenciaCliente, string $observaciones, string $clave
): int {
    if ($usuarioId < 1 || $bodegaId < 1
        || mb_strlen($referenciaCliente, 'UTF-8') > 100 || mb_strlen($observaciones, 'UTF-8') > 500
        || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clave)) {
        throw new DomainException('La referencia, las observaciones o la clave del pedido no son válidas.');
    }
    $entradas = corozao_pedido_validar_entradas($entradas);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $bodegaId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('La bodega seleccionada no está activa.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO pedidos
                (bodega_id, estado, referencia_cliente, observaciones, clave_idempotencia,
                 usuario_id, actualizado_por_usuario_id)
             VALUES (:bodega, \'ABIERTO\', :referencia, :observaciones, :clave, :usuario, :actualizador)'
        );
        $stmt->execute([
            'bodega' => $bodegaId, 'referencia' => $referenciaCliente === '' ? null : $referenciaCliente,
            'observaciones' => $observaciones === '' ? null : $observaciones, 'clave' => strtolower($clave),
            'usuario' => $usuarioId, 'actualizador' => $usuarioId,
        ]);
        $pedidoId = (int) $pdo->lastInsertId();
        foreach ($entradas as $presentacionId => $entrada) {
            if ($entrada['eliminar']) {
                throw new DomainException('No se puede quitar una línea al crear un pedido.');
            }
            $precio = corozao_pedido_precio_vigente($pdo, $bodegaId, (int) $presentacionId);
            $calculo = corozao_pedido_calcular_linea(
                $pdo, $entrada['cantidad'], (string) $precio['unidades_base'], (string) $precio['precio_venta']
            );
            corozao_pedido_insertar_linea($pdo, $pedidoId, $usuarioId, [
                'producto_id' => (int) $precio['producto_id'], 'presentacion_id' => (int) $presentacionId,
                'precio_bodega_id' => (int) $precio['precio_bodega_id'],
                'cantidad_presentacion' => $entrada['cantidad'], 'unidades_base' => (string) $precio['unidades_base'],
                'cantidad_base' => $calculo['cantidad_base'], 'precio_unitario_presentacion' => (string) $precio['precio_venta'],
                'precio_incluye_iva' => (int) $precio['precio_incluye_iva'], 'tarifa_iva' => (string) $precio['tarifa_iva'],
                'subtotal_linea' => $calculo['subtotal_linea'],
            ]);
        }
        $detalles = corozao_pedido_snapshot_detalles($pdo, $pedidoId);
        if (!$detalles) {
            throw new DomainException('Agrega al menos una presentación al pedido.');
        }
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'pedidos', $pedidoId, null, [
            'estado' => 'ABIERTO', 'bodega_id' => $bodegaId,
            'referencia_cliente' => $referenciaCliente === '' ? null : $referenciaCliente,
            'observaciones' => $observaciones === '' ? null : $observaciones, 'detalles' => $detalles,
        ]);
        $pdo->commit();
        return $pedidoId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            $stmt = $pdo->prepare(
                'SELECT pedido_id FROM pedidos WHERE clave_idempotencia = :clave AND usuario_id = :usuario'
            );
            $stmt->execute(['clave' => strtolower($clave), 'usuario' => $usuarioId]);
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

/** Reemplaza solo las líneas enviadas, conservando los precios de las líneas ya agregadas. */
function corozao_pedido_actualizar(
    PDO $pdo, int $pedidoId, int $usuarioId, bool $esAdministrador,
    array $entradas, string $referenciaCliente, string $observaciones
): void {
    if ($pedidoId < 1 || $usuarioId < 1
        || mb_strlen($referenciaCliente, 'UTF-8') > 100 || mb_strlen($observaciones, 'UTF-8') > 500) {
        throw new DomainException('La referencia o las observaciones exceden su longitud máxima.');
    }
    $entradas = corozao_pedido_validar_entradas($entradas);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM pedidos WHERE pedido_id = :id FOR UPDATE');
        $stmt->execute(['id' => $pedidoId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pedido || !in_array($pedido['estado'], ['ABIERTO', 'EN_PREPARACION'], true)
            || (!$esAdministrador && (int) $pedido['usuario_id'] !== $usuarioId)) {
            throw new DomainException('El pedido ya no está abierto o no te pertenece.');
        }
        $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $pedido['bodega_id']]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('La bodega del pedido ya no está activa.');
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM pedido_detalles WHERE pedido_id = :pedido ORDER BY presentacion_id FOR UPDATE'
        );
        $stmt->execute(['pedido' => $pedidoId]);
        $anterioresLineas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linea) {
            $anterioresLineas[(int) $linea['presentacion_id']] = $linea;
        }
        $detallesAntes = corozao_pedido_snapshot_detalles($pdo, $pedidoId);
        $nuevasVistas = [];
        foreach ($entradas as $presentacionId => $entrada) {
            $existente = $anterioresLineas[$presentacionId] ?? null;
            if ($entrada['eliminar']) {
                if ($existente !== null) {
                    $stmt = $pdo->prepare('DELETE FROM pedido_detalles WHERE pedido_detalle_id = :id');
                    $stmt->execute(['id' => $existente['pedido_detalle_id']]);
                    unset($anterioresLineas[$presentacionId]);
                }
                continue;
            }
            if ($existente !== null) {
                $nuevasVistas[$presentacionId] = true;
                if ((string) $existente['cantidad_presentacion'] !== $entrada['cantidad']) {
                    $calculo = corozao_pedido_calcular_linea(
                        $pdo, $entrada['cantidad'], (string) $existente['unidades_base'],
                        (string) $existente['precio_unitario_presentacion']
                    );
                    $stmt = $pdo->prepare(
                        'UPDATE pedido_detalles SET cantidad_presentacion = :cantidad,
                            cantidad_base = :base, subtotal_linea = :subtotal
                         WHERE pedido_detalle_id = :id'
                    );
                    $stmt->execute([
                        'cantidad' => $entrada['cantidad'], 'base' => $calculo['cantidad_base'],
                        'subtotal' => $calculo['subtotal_linea'],
                        'id' => $existente['pedido_detalle_id'],
                    ]);
                }
                continue;
            }
            $nuevasVistas[$presentacionId] = true;
            $precio = corozao_pedido_precio_vigente($pdo, (int) $pedido['bodega_id'], (int) $presentacionId);
            $calculo = corozao_pedido_calcular_linea(
                $pdo, $entrada['cantidad'], (string) $precio['unidades_base'], (string) $precio['precio_venta']
            );
            corozao_pedido_insertar_linea($pdo, $pedidoId, $usuarioId, [
                'producto_id' => (int) $precio['producto_id'], 'presentacion_id' => (int) $presentacionId,
                'precio_bodega_id' => (int) $precio['precio_bodega_id'],
                'cantidad_presentacion' => $entrada['cantidad'], 'unidades_base' => (string) $precio['unidades_base'],
                'cantidad_base' => $calculo['cantidad_base'], 'precio_unitario_presentacion' => (string) $precio['precio_venta'],
                'precio_incluye_iva' => (int) $precio['precio_incluye_iva'], 'tarifa_iva' => (string) $precio['tarifa_iva'],
                'subtotal_linea' => $calculo['subtotal_linea'],
            ]);
        }
        foreach (array_keys($anterioresLineas) as $presentacionId) {
            if (!isset($nuevasVistas[$presentacionId])) {
                $stmt = $pdo->prepare('DELETE FROM pedido_detalles WHERE pedido_detalle_id = :id');
                $stmt->execute(['id' => $anterioresLineas[$presentacionId]['pedido_detalle_id']]);
            }
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM pedido_detalles WHERE pedido_id = :pedido'
        );
        $stmt->execute(['pedido' => $pedidoId]);
        if ((int) $stmt->fetchColumn() < 1) {
            throw new DomainException('El pedido debe conservar al menos una presentación.');
        }
        $detallesDespues = corozao_pedido_snapshot_detalles($pdo, $pedidoId);
        $stmt = $pdo->prepare(
            'UPDATE pedidos SET referencia_cliente = :referencia, observaciones = :observaciones,
                actualizado_por_usuario_id = :usuario, fecha_actualizacion = CURRENT_TIMESTAMP(6)
             WHERE pedido_id = :pedido'
        );
        $stmt->execute([
            'referencia' => $referenciaCliente === '' ? null : $referenciaCliente,
            'observaciones' => $observaciones === '' ? null : $observaciones,
            'usuario' => $usuarioId, 'pedido' => $pedidoId,
        ]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'pedidos', $pedidoId,
            ['estado' => $pedido['estado'], 'actualizado_por_usuario_id' => (int) $pedido['actualizado_por_usuario_id'],
                'detalles' => $detallesAntes,
                'referencia_cliente' => $pedido['referencia_cliente'], 'observaciones' => $pedido['observaciones']],
            ['estado' => $pedido['estado'], 'actualizado_por_usuario_id' => $usuarioId, 'detalles' => $detallesDespues,
                'referencia_cliente' => $referenciaCliente === '' ? null : $referenciaCliente,
                'observaciones' => $observaciones === '' ? null : $observaciones]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function corozao_pedido_transicionar(PDO $pdo, int $pedidoId, int $usuarioId, string $accion): void
{
    if ($pedidoId < 1 || $usuarioId < 1 || !in_array($accion, ['enviar', 'solicitar_cierre'], true)) {
        throw new DomainException('La acción de pedido no es válida.');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM pedidos WHERE pedido_id = :id FOR UPDATE');
        $stmt->execute(['id' => $pedidoId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pedido || !in_array($pedido['estado'], ['ABIERTO', 'EN_PREPARACION'], true)) {
            throw new DomainException('El pedido ya no admite este cambio de estado.');
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pedido_detalles WHERE pedido_id = :id');
        $stmt->execute(['id' => $pedidoId]);
        if ((int) $stmt->fetchColumn() < 1) {
            throw new DomainException('Agrega productos antes de enviar o cerrar el pedido.');
        }
        if ($accion === 'enviar') {
            if ($pedido['estado'] === 'EN_PREPARACION') {
                throw new DomainException('El pedido ya fue enviado a preparación.');
            }
            $estadoNuevo = 'EN_PREPARACION';
            $stmt = $pdo->prepare(
                'UPDATE pedidos SET estado = :estado, fecha_envio_preparacion = CURRENT_TIMESTAMP(6),
                    actualizado_por_usuario_id = :usuario, fecha_actualizacion = CURRENT_TIMESTAMP(6)
                 WHERE pedido_id = :id AND estado = \'ABIERTO\''
            );
            $stmt->execute(['estado' => $estadoNuevo, 'usuario' => $usuarioId, 'id' => $pedidoId]);
        } else {
            $estadoNuevo = 'PENDIENTE_FACTURACION';
            $stmt = $pdo->prepare(
                'UPDATE pedidos SET estado = :estado, solicita_cierre_por_usuario_id = :usuario,
                    fecha_solicitud_cierre = CURRENT_TIMESTAMP(6), actualizado_por_usuario_id = :actualizador,
                    fecha_actualizacion = CURRENT_TIMESTAMP(6)
                 WHERE pedido_id = :id AND estado IN (\'ABIERTO\', \'EN_PREPARACION\')'
            );
            $stmt->execute(['estado' => $estadoNuevo, 'usuario' => $usuarioId,
                'actualizador' => $usuarioId, 'id' => $pedidoId]);
        }
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('El estado del pedido cambió mientras se procesaba.');
        }
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'pedidos', $pedidoId,
            ['estado' => $pedido['estado']], ['estado' => $estadoNuevo,
                'usuario_id' => $usuarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
