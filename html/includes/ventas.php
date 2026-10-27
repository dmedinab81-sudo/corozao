<?php
declare(strict_types=1);

function corozao_ventas_instaladas(PDO $pdo): bool
{
    $tablas = ['cajas', 'ventas', 'venta_detalles', 'venta_pagos', 'facturas_electronicas'];
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\'
           AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tablas), '?')) . ')'
    );
    $stmt->execute($tablas);
    if ((int) $stmt->fetchColumn() !== count($tablas)) {
        return false;
    }
    $stmt = $pdo->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = \'movimientos_inventario\' AND COLUMN_NAME = \'venta_id\''
    );
    return (int) $stmt->fetchColumn() === 1;
}

function corozao_caja_conciliacion_instalada(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_TYPE=\'BASE TABLE\' AND TABLE_NAME=\'caja_conciliaciones\'');
    return (int) $stmt->fetchColumn() === 1;
}

function corozao_caja_fecha_operativa_instalada(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME=\'cajas\' AND COLUMN_NAME=\'fecha_operativa\' AND DATA_TYPE=\'date\'');
    return (int) $stmt->fetchColumn() === 1;
}

function corozao_fecha_operativa_local(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil')))->format('Y-m-d');
}

function corozao_fecha_caja_es_hoy(?string $fechaCaja, string $hoy): bool
{
    return $fechaCaja !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCaja) === 1
        && hash_equals($hoy, $fechaCaja);
}

function corozao_dinero_centavos(string $valor, bool $permitirCero = true): int
{
    $valor = trim($valor);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/', $valor)) {
        throw new DomainException('Ingresa un importe válido con hasta dos decimales.');
    }
    [$entero, $fraccion] = array_pad(explode('.', $valor, 2), 2, '');
    $centavos = ((int) $entero * 100) + (int) str_pad($fraccion, 2, '0');
    if (!$permitirCero && $centavos < 1) {
        throw new DomainException('Cada medio de pago debe tener un importe mayor que cero.');
    }
    return $centavos;
}

function corozao_centavos_decimal(int $centavos): string
{
    $absolutos = abs($centavos);
    return ($centavos < 0 ? '-' : '') . intdiv($absolutos, 100) . '.'
        . str_pad((string) ($absolutos % 100), 2, '0', STR_PAD_LEFT);
}

function corozao_cantidad_milesima(string $valor): int
{
    if (!preg_match('/^(\d+)\.(\d{3})$/', $valor, $m)) {
        throw new DomainException('El pedido contiene una cantidad con precisión no válida.');
    }
    return ((int) $m[1] * 1000) + (int) $m[2];
}

function corozao_caja_abrir(PDO $pdo, int $usuarioId, int $bodegaId, string $monto): int
{
    if ($usuarioId < 1 || $bodegaId < 1) {
        throw new DomainException('La sesión o bodega no son válidas.');
    }
    $centavos = corozao_dinero_centavos($monto);
    $fechaOperativaInstalada = corozao_caja_fecha_operativa_instalada($pdo);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id = :id AND activo = 1 FOR UPDATE');
        $stmt->execute(['id' => $bodegaId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('La bodega ya no está activa.');
        }
        $stmt = $pdo->prepare(
            'SELECT caja_id FROM cajas WHERE usuario_id = :usuario AND bodega_id = :bodega
             AND estado = \'ABIERTA\' FOR UPDATE'
        );
        $stmt->execute(['usuario' => $usuarioId, 'bodega' => $bodegaId]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('Ya tienes una caja abierta en esta bodega.');
        }
        if ($fechaOperativaInstalada) {
            $stmt = $pdo->prepare(
                'INSERT INTO cajas (bodega_id, usuario_id, estado, fecha_operativa, monto_apertura)
                 VALUES (:bodega, :usuario, \'ABIERTA\', :fecha, :monto)'
            );
            $stmt->execute(['bodega' => $bodegaId, 'usuario' => $usuarioId,
                'fecha' => corozao_fecha_operativa_local(), 'monto' => corozao_centavos_decimal($centavos)]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO cajas (bodega_id, usuario_id, estado, monto_apertura)
                 VALUES (:bodega, :usuario, \'ABIERTA\', :monto)'
            );
            $stmt->execute(['bodega' => $bodegaId, 'usuario' => $usuarioId,
                'monto' => corozao_centavos_decimal($centavos)]);
        }
        $cajaId = (int) $pdo->lastInsertId();
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'cajas', $cajaId, null,
            ['estado' => 'ABIERTA', 'bodega_id' => $bodegaId, 'monto_apertura' => corozao_centavos_decimal($centavos)]);
        $pdo->commit();
        return $cajaId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new DomainException('Ya existe una caja abierta en esta bodega.');
        }
        throw $e;
    }
}

function corozao_venta_calcular_totales(PDO $pdo, int $pedidoId): array
{
    $stmt = $pdo->prepare(
        'SELECT CAST(SUM(ROUND(CASE WHEN precio_incluye_iva = 1
                    THEN subtotal_linea / (1 + tarifa_iva / 100) ELSE subtotal_linea END, 2)) AS DECIMAL(14,2)) AS subtotal,
                CAST(SUM(ROUND(CASE WHEN precio_incluye_iva = 1
                    THEN subtotal_linea - ROUND(subtotal_linea / (1 + tarifa_iva / 100), 2)
                    ELSE subtotal_linea * tarifa_iva / 100 END, 2)) AS DECIMAL(14,2)) AS impuestos,
                CAST(SUM(ROUND(CASE WHEN precio_incluye_iva = 1
                    THEN subtotal_linea ELSE ROUND(subtotal_linea, 2) + ROUND(subtotal_linea * tarifa_iva / 100, 2) END, 2)) AS DECIMAL(14,2)) AS bruto
         FROM pedido_detalles WHERE pedido_id = :pedido'
    );
    $stmt->execute(['pedido' => $pedidoId]);
    $totales = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$totales || $totales['bruto'] === null) throw new DomainException('El pedido no contiene productos.');
    return ['subtotal' => corozao_dinero_centavos((string) $totales['subtotal']),
        'impuestos' => corozao_dinero_centavos((string) $totales['impuestos']),
        'bruto' => corozao_dinero_centavos((string) $totales['bruto'])];
}

function corozao_venta_cobrar(PDO $pdo, int $pedidoId, int $usuarioId, array $entrada): int
{
    if ($pedidoId < 1 || $usuarioId < 1) throw new DomainException('El pedido o usuario no son válidos.');
    $tipo = $entrada['tipo_destinatario'] ?? '';
    if ($tipo === 'FACTURA') {
        $tipo = $entrada['tipo_destinatario_detalle'] ?? '';
    }
    if (!is_string($tipo) || !in_array($tipo, ['RUC', 'CEDULA', 'PASAPORTE', 'CONSUMIDOR_FINAL'], true)) {
        throw new DomainException('Selecciona factura con datos del cliente o consumidor final.');
    }
    $nombre = $entrada['nombre_destinatario'] ?? '';
    $identificacion = $entrada['identificacion_destinatario'] ?? '';
    $direccion = $entrada['direccion_destinatario'] ?? '';
    $correo = $entrada['correo_destinatario'] ?? '';
    foreach ([$nombre, $identificacion, $direccion, $correo] as $valor) {
        if (!is_string($valor)) throw new DomainException('Los datos del destinatario no son válidos.');
    }
    $nombre = trim($nombre);
    $identificacion = trim($identificacion);
    $direccion = trim($direccion);
    $correo = trim($correo);
    if ($tipo === 'CONSUMIDOR_FINAL') {
        $nombre = 'CONSUMIDOR FINAL';
        $identificacion = '';
        $direccion = '';
        $correo = '';
    } elseif ($nombre === '' || $identificacion === '' || mb_strlen($nombre, 'UTF-8') > 300
        || mb_strlen($identificacion, 'UTF-8') > 20 || mb_strlen($direccion, 'UTF-8') > 300
        || mb_strlen($correo, 'UTF-8') > 150 || ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL))) {
        throw new DomainException('Completa un nombre, identificación y correo válido para la factura.');
    }
    if (($tipo === 'RUC' && !preg_match('/^[0-9]{13}$/', $identificacion))
        || ($tipo === 'CEDULA' && !preg_match('/^[0-9]{10}$/', $identificacion))
        || ($tipo === 'PASAPORTE' && !preg_match('/^[A-Za-z0-9-]{1,20}$/', $identificacion))) {
        throw new DomainException('La identificación no corresponde al tipo seleccionado.');
    }
    $descuentoInput = $entrada['descuento_valor'] ?? '0';
    if (!is_string($descuentoInput)) throw new DomainException('El descuento debe ser un importe fijo.');
    $descuento = corozao_dinero_centavos($descuentoInput);
    $pagos = $entrada['pagos'] ?? null;
    if (!is_array($pagos)) throw new DomainException('Registra al menos un medio de pago.');
    $medios = ['EFECTIVO', 'TRANSFERENCIA', 'TARJETA_CREDITO'];
    $pagosLimpios = [];
    foreach ($pagos as $medio => $importe) {
        if (!is_string($medio) || !in_array($medio, $medios, true) || !is_string($importe)) {
            throw new DomainException('Uno de los medios de pago no es válido.');
        }
        $centavos = corozao_dinero_centavos($importe);
        if ($centavos > 0) $pagosLimpios[$medio] = $centavos;
    }
    $referencias = $entrada['referencias'] ?? [];
    if (!is_array($referencias)) $referencias = [];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM pedidos WHERE pedido_id = :id FOR UPDATE');
        $stmt->execute(['id' => $pedidoId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) throw new DomainException('El pedido no existe.');
        if ($pedido['estado'] === 'FACTURADO') {
            $stmt = $pdo->prepare('SELECT venta_id FROM ventas WHERE pedido_id = :pedido');
            $stmt->execute(['pedido' => $pedidoId]);
            $existente = $stmt->fetchColumn();
            if ($existente) { $pdo->commit(); return (int) $existente; }
            throw new DomainException('El pedido figura facturado sin venta asociada; requiere revisión.');
        }
        if ($pedido['estado'] !== 'PENDIENTE_FACTURACION') {
            throw new DomainException('Solo se pueden cobrar pedidos pendientes de facturación.');
        }
        $fechaCajaSelect = corozao_caja_fecha_operativa_instalada($pdo)
            ? 'fecha_operativa' : 'NULL AS fecha_operativa';
        $stmt = $pdo->prepare(
            'SELECT caja_id, bodega_id, ' . $fechaCajaSelect . ' FROM cajas
             WHERE usuario_id = :usuario AND estado = \'ABIERTA\' AND bodega_id = :bodega FOR UPDATE'
        );
        $stmt->execute(['usuario' => $usuarioId, 'bodega' => $pedido['bodega_id']]);
        $caja = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$caja) throw new DomainException('Abre una caja para la bodega del pedido antes de cobrar.');
        if (corozao_caja_fecha_operativa_instalada($pdo)
            && !corozao_fecha_caja_es_hoy($caja['fecha_operativa'] === null ? null : (string)$caja['fecha_operativa'], corozao_fecha_operativa_local())) {
            throw new DomainException('La caja pertenece a una fecha operativa anterior. Ciérrala y abre una nueva para continuar cobrando.');
        }
        $totales = corozao_venta_calcular_totales($pdo, $pedidoId);
        $total = $totales['bruto'] - $descuento;
        if ($descuento > $totales['bruto'] || $total < 0) throw new DomainException('El descuento fijo no puede superar el total del pedido.');
        if ($total > 0 && !$pagosLimpios) throw new DomainException('Registra al menos un medio de pago.');
        if (array_sum($pagosLimpios) !== $total) throw new DomainException('La suma de pagos debe ser igual al total después del descuento.');

        $stmt = $pdo->prepare(
            'SELECT d.*, p.nombre AS nombre_producto,
             CAST(ROUND(CASE WHEN d.precio_incluye_iva = 1 THEN d.subtotal_linea / (1 + d.tarifa_iva / 100)
                   ELSE d.subtotal_linea END, 2) AS DECIMAL(14,2)) AS importe_subtotal,
             CAST(ROUND(CASE WHEN d.precio_incluye_iva = 1
                   THEN d.subtotal_linea - ROUND(d.subtotal_linea / (1 + d.tarifa_iva / 100), 2)
                   ELSE d.subtotal_linea * d.tarifa_iva / 100 END, 2) AS DECIMAL(14,2)) AS importe_impuesto,
             CAST(ROUND(CASE WHEN d.precio_incluye_iva = 1 THEN d.subtotal_linea
                   ELSE ROUND(d.subtotal_linea, 2) + ROUND(d.subtotal_linea * d.tarifa_iva / 100, 2) END, 2) AS DECIMAL(14,2)) AS importe_bruto
             FROM pedido_detalles d
             INNER JOIN productos p ON p.producto_id = d.producto_id
             WHERE d.pedido_id = :pedido ORDER BY d.producto_id, d.presentacion_id FOR UPDATE'
        );
        $stmt->execute(['pedido' => $pedidoId]);
        $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $porProducto = [];
        foreach ($lineas as $linea) {
            $productoId = (int) $linea['producto_id'];
            $porProducto[$productoId] = ($porProducto[$productoId] ?? 0) + corozao_cantidad_milesima((string) $linea['cantidad_base']);
        }
        ksort($porProducto, SORT_NUMERIC);
        foreach ($porProducto as $productoId => $cantidad) {
            $stmt = $pdo->prepare(
                'INSERT INTO inventario_saldos (bodega_id, producto_id, cantidad_base)
                 VALUES (:bodega, :producto, 0)
                 ON DUPLICATE KEY UPDATE producto_id = VALUES(producto_id)'
            );
            $stmt->execute(['bodega' => $pedido['bodega_id'], 'producto' => $productoId]);
            $stmt = $pdo->prepare(
                'SELECT cantidad_base FROM inventario_saldos WHERE bodega_id = :bodega AND producto_id = :producto FOR UPDATE'
            );
            $stmt->execute(['bodega' => $pedido['bodega_id'], 'producto' => $productoId]);
            if (corozao_cantidad_milesima((string) $stmt->fetchColumn()) < $cantidad) {
                throw new DomainException('Existencias insuficientes para el producto #' . $productoId . '.');
            }
        }
        $uuid = static function (): string {
            $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
            $h = bin2hex($b); return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
        };
        $stmt = $pdo->prepare(
            'INSERT INTO ventas (pedido_id, caja_id, bodega_id, usuario_id, subtotal, impuestos,
             descuento_valor, total, tipo_destinatario, identificacion_destinatario,
             nombre_destinatario, direccion_destinatario, correo_destinatario, clave_idempotencia)
             VALUES (:pedido, :caja, :bodega, :usuario, :subtotal, :impuestos, :descuento, :total,
                     :tipo, :identificacion, :nombre, :direccion, :correo, :clave)'
        );
        $stmt->execute([
            'pedido' => $pedidoId, 'caja' => $caja['caja_id'], 'bodega' => $pedido['bodega_id'], 'usuario' => $usuarioId,
            'subtotal' => corozao_centavos_decimal($totales['subtotal']), 'impuestos' => corozao_centavos_decimal($totales['impuestos']),
            'descuento' => corozao_centavos_decimal($descuento), 'total' => corozao_centavos_decimal($total), 'tipo' => $tipo,
            'identificacion' => $identificacion === '' ? null : $identificacion, 'nombre' => $nombre,
            'direccion' => $direccion === '' ? null : $direccion, 'correo' => $correo === '' ? null : $correo, 'clave' => $uuid(),
        ]);
        $ventaId = (int) $pdo->lastInsertId();
        $detalleStmt = $pdo->prepare(
            'INSERT INTO venta_detalles (venta_id, pedido_detalle_id, producto_id, presentacion_id, precio_bodega_id,
             cantidad_presentacion, unidades_base, cantidad_base, precio_unitario_presentacion, precio_incluye_iva,
             tarifa_iva, subtotal, impuesto, total_bruto)
             VALUES (:venta, :pedido_detalle, :producto, :presentacion, :precio_id, :cantidad, :unidades, :base,
             :precio, :incluye, :tarifa, :subtotal, :impuesto, :bruto)'
        );
        foreach ($lineas as $linea) {
            $neto = corozao_dinero_centavos((string) $linea['importe_subtotal']);
            $iva = corozao_dinero_centavos((string) $linea['importe_impuesto']);
            $bruto = corozao_dinero_centavos((string) $linea['importe_bruto']);
            $detalleStmt->execute([
                'venta' => $ventaId, 'pedido_detalle' => $linea['pedido_detalle_id'], 'producto' => $linea['producto_id'],
                'presentacion' => $linea['presentacion_id'], 'precio_id' => $linea['precio_bodega_id'],
                'cantidad' => $linea['cantidad_presentacion'], 'unidades' => $linea['unidades_base'], 'base' => $linea['cantidad_base'],
                'precio' => $linea['precio_unitario_presentacion'], 'incluye' => $linea['precio_incluye_iva'], 'tarifa' => $linea['tarifa_iva'],
                'subtotal' => corozao_centavos_decimal($neto), 'impuesto' => corozao_centavos_decimal($iva), 'bruto' => corozao_centavos_decimal($bruto),
            ]);
        }
        $pagoStmt = $pdo->prepare('INSERT INTO venta_pagos (venta_id, medio, monto, referencia) VALUES (:venta, :medio, :monto, :referencia)');
        foreach ($pagosLimpios as $medio => $importe) {
            $referencia = $referencias[$medio] ?? '';
            if (!is_string($referencia) || mb_strlen($referencia, 'UTF-8') > 100) throw new DomainException('La referencia de pago excede 100 caracteres.');
            $pagoStmt->execute(['venta' => $ventaId, 'medio' => $medio, 'monto' => corozao_centavos_decimal($importe), 'referencia' => trim($referencia) ?: null]);
        }
        foreach ($porProducto as $productoId => $cantidad) {
            $stmt = $pdo->prepare(
                'UPDATE inventario_saldos SET cantidad_base = cantidad_base - :cantidad
                 WHERE bodega_id = :bodega AND producto_id = :producto AND cantidad_base >= :minimo'
            );
            $unidades = intdiv($cantidad, 1000) . '.' . str_pad((string) ($cantidad % 1000), 3, '0', STR_PAD_LEFT);
            $stmt->execute(['cantidad' => $unidades, 'bodega' => $pedido['bodega_id'],
                'producto' => $productoId, 'minimo' => $unidades]);
            if ($stmt->rowCount() !== 1) throw new DomainException('El saldo cambió durante el cobro; vuelve a intentarlo.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO movimientos_inventario (tipo_movimiento, estado, bodega_origen_id, bodega_destino_id,
             observaciones, usuario_id, fecha_movimiento, clave_idempotencia, confirmado_por_usuario_id,
             fecha_confirmacion, venta_id)
             VALUES (\'VENTA\', \'CONFIRMADO\', :bodega, NULL, :observaciones, :usuario,
             CURRENT_TIMESTAMP(6), :clave, :confirmador, CURRENT_TIMESTAMP(6), :venta)'
        );
        $stmt->execute(['bodega' => $pedido['bodega_id'], 'observaciones' => 'Venta #' . $ventaId,
            'usuario' => $usuarioId, 'clave' => $uuid(), 'confirmador' => $usuarioId, 'venta' => $ventaId]);
        $movimientoId = (int) $pdo->lastInsertId();
        $movDet = $pdo->prepare(
            'INSERT INTO detalle_movimiento_inventario
             (movimiento_id, producto_id, presentacion_id, cantidad_base, costo_unitario, costo_total)
             VALUES (:movimiento, :producto, :presentacion, :cantidad, NULL, NULL)'
        );
        foreach ($lineas as $linea) $movDet->execute(['movimiento' => $movimientoId, 'producto' => $linea['producto_id'],
            'presentacion' => $linea['presentacion_id'], 'cantidad' => $linea['cantidad_base']]);
        $stmt = $pdo->prepare('UPDATE pedidos SET estado = \'FACTURADO\', actualizado_por_usuario_id = :usuario, fecha_actualizacion = CURRENT_TIMESTAMP(6) WHERE pedido_id = :pedido AND estado = \'PENDIENTE_FACTURACION\'');
        $stmt->execute(['usuario' => $usuarioId, 'pedido' => $pedidoId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('El estado del pedido cambió durante el cobro.');
        $stmt = $pdo->prepare('INSERT INTO facturas_electronicas (venta_id, estado) VALUES (:venta, \'PENDIENTE\')');
        $stmt->execute(['venta' => $ventaId]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'ventas', $ventaId, null, [
            'pedido_id' => $pedidoId, 'bodega_id' => (int) $pedido['bodega_id'], 'subtotal' => corozao_centavos_decimal($totales['subtotal']),
            'impuestos' => corozao_centavos_decimal($totales['impuestos']), 'descuento_valor' => corozao_centavos_decimal($descuento),
            'total' => corozao_centavos_decimal($total), 'tipo_destinatario' => $tipo,
            'identificacion_destinatario' => $identificacion === '' ? null : $identificacion, 'nombre_destinatario' => $nombre,
            'movimiento_id' => $movimientoId, 'estado_factura_sri' => 'PENDIENTE',
        ]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'pedidos', $pedidoId,
            ['estado' => 'PENDIENTE_FACTURACION'], ['estado' => 'FACTURADO', 'venta_id' => $ventaId]);
        $pdo->commit();
        return $ventaId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function corozao_caja_cerrar(PDO $pdo, int $cajaId, int $usuarioId, array $declarados, string $observaciones): void
{
    $usaConciliacionCompleta = corozao_caja_conciliacion_instalada($pdo);
    $medios = $usaConciliacionCompleta ? ['EFECTIVO', 'TRANSFERENCIA', 'TARJETA_CREDITO'] : ['EFECTIVO'];
    if (array_diff(array_keys($declarados), $medios) || array_diff($medios, array_keys($declarados))) {
        throw new DomainException('Declara el total contado para cada medio de pago.');
    }
    $declaradoCentavos = [];
    foreach ($medios as $medio) {
        if (!is_string($declarados[$medio])) throw new DomainException('Uno de los importes declarados no es válido.');
        $declaradoCentavos[$medio] = corozao_dinero_centavos($declarados[$medio]);
    }
    $observaciones = trim($observaciones);
    if (mb_strlen($observaciones, 'UTF-8') > 500) throw new DomainException('Las observaciones admiten hasta 500 caracteres.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM cajas WHERE caja_id = :id AND usuario_id = :usuario AND estado = \'ABIERTA\' FOR UPDATE');
        $stmt->execute(['id' => $cajaId, 'usuario' => $usuarioId]);
        $caja = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$caja) throw new DomainException('La caja ya no está abierta o no te pertenece.');
        $stmt = $pdo->prepare('SELECT vp.medio, CAST(SUM(vp.monto) AS DECIMAL(14,2)) AS monto FROM ventas v
            INNER JOIN venta_pagos vp ON vp.venta_id = v.venta_id WHERE v.caja_id = :caja GROUP BY vp.medio');
        $stmt->execute(['caja' => $cajaId]);
        $esperados = array_fill_keys($medios, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pago) {
            $esperados[$pago['medio']] = corozao_dinero_centavos((string) $pago['monto']);
        }
        $esperados['EFECTIVO'] += corozao_dinero_centavos((string) $caja['monto_apertura']);
        $diferencias = [];
        foreach ($medios as $medio) $diferencias[$medio] = $declaradoCentavos[$medio] - $esperados[$medio];
        $hayDiferencias = in_array(true, array_map(static fn(int $d): bool => $d !== 0, $diferencias), true);
        if ($hayDiferencias) {
            corozao_exigir_permiso($pdo, 'cash.differences.record');
        }
        if ($hayDiferencias && $observaciones === '') {
            throw new DomainException('Explica el motivo de las diferencias de caja.');
        }
        $stmt = $pdo->prepare('UPDATE cajas SET estado = \'CERRADA\', monto_esperado = :esperado, monto_declarado = :declarado,
            diferencia = :diferencia, observaciones_cierre = :observaciones, cerrado_por_usuario_id = :usuario, fecha_cierre = CURRENT_TIMESTAMP(6)
            WHERE caja_id = :id AND estado = \'ABIERTA\'');
        $stmt->execute(['esperado' => corozao_centavos_decimal($esperados['EFECTIVO']),
            'declarado' => corozao_centavos_decimal($declaradoCentavos['EFECTIVO']),
            'diferencia' => corozao_centavos_decimal($diferencias['EFECTIVO']), 'observaciones' => $observaciones ?: null,
            'usuario' => $usuarioId, 'id' => $cajaId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('No se pudo cerrar la caja.');
        $conciliacionesAuditoria = [];
        if ($usaConciliacionCompleta) {
            $insertarConciliacion = $pdo->prepare('INSERT INTO caja_conciliaciones
                (caja_id, medio, monto_esperado, monto_declarado, diferencia)
                VALUES (:caja, :medio, :esperado, :declarado, :diferencia)');
        }
        foreach ($medios as $medio) {
            $datos = ['caja' => $cajaId, 'medio' => $medio,
                'esperado' => corozao_centavos_decimal($esperados[$medio]),
                'declarado' => corozao_centavos_decimal($declaradoCentavos[$medio]),
                'diferencia' => corozao_centavos_decimal($diferencias[$medio])];
            if ($usaConciliacionCompleta) $insertarConciliacion->execute($datos);
            $conciliacionesAuditoria[$medio] = ['esperado' => $datos['esperado'], 'declarado' => $datos['declarado'], 'diferencia' => $datos['diferencia']];
        }
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'cajas', $cajaId,
            ['estado' => 'ABIERTA'], ['estado' => 'CERRADA', 'conciliacion_completa' => $usaConciliacionCompleta,
                'conciliaciones' => $conciliacionesAuditoria,
                'observaciones_cierre' => $observaciones ?: null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
