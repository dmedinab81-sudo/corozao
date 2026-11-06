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

function corozao_ventas_directas_instaladas(PDO $pdo): bool
{
    $stmt = $pdo->query(
        'SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND
           ((TABLE_NAME = \'ventas\' AND COLUMN_NAME IN (\'pedido_id\', \'referencia_cliente\')) OR
            (TABLE_NAME = \'venta_detalles\' AND COLUMN_NAME IN (\'pedido_detalle_id\',
             \'producto_nombre_snapshot\', \'marca_snapshot\', \'contenido_snapshot\',
             \'categoria_snapshot\', \'presentacion_snapshot\', \'codigo_snapshot\')))'
    );
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row['IS_NULLABLE'];
    return ($columns['ventas']['pedido_id'] ?? 'NO') === 'YES'
        && isset($columns['ventas']['referencia_cliente'])
        && ($columns['venta_detalles']['pedido_detalle_id'] ?? 'NO') === 'YES'
        && count(array_intersect(['producto_nombre_snapshot', 'marca_snapshot', 'contenido_snapshot',
            'categoria_snapshot', 'presentacion_snapshot', 'codigo_snapshot'], array_keys($columns['venta_detalles'] ?? []))) === 6;
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

function corozao_caja_mejoras_instaladas(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME=\'ventas\' AND COLUMN_NAME IN (\'efectivo_recibido\',\'cambio_entregado\')');
    if ((int)$stmt->fetchColumn() !== 2) return false;
    $stmt = $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_TYPE=\'BASE TABLE\' AND TABLE_NAME IN (\'caja_movimientos\',\'caja_revision_diferencias\')');
    return (int)$stmt->fetchColumn() === 2;
}

/** Resumen de caja recalculado desde los registros confirmados; los importes se devuelven en centavos. */
function corozao_caja_resumen(PDO $pdo, int $cajaId): array
{
    $stmt = $pdo->prepare('SELECT caja_id,monto_apertura FROM cajas WHERE caja_id=:caja');
    $stmt->execute(['caja' => $cajaId]);
    $caja = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$caja) throw new DomainException('La caja seleccionada no existe.');

    $medios = ['EFECTIVO', 'TRANSFERENCIA', 'TARJETA_CREDITO'];
    $pagos = array_fill_keys($medios, 0);
    $reembolsos = array_fill_keys($medios, 0);
    $stmt = $pdo->prepare('SELECT vp.medio,CAST(SUM(vp.monto) AS DECIMAL(14,2)) AS monto
        FROM ventas v INNER JOIN venta_pagos vp ON vp.venta_id=v.venta_id
        WHERE v.caja_id=:caja AND v.estado=\'CONFIRMADA\' GROUP BY vp.medio');
    $stmt->execute(['caja' => $cajaId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($pagos[$row['medio']])) $pagos[$row['medio']] = corozao_dinero_centavos((string)$row['monto']);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) AS operaciones,
        CAST(COALESCE(SUM(v.total),0) AS DECIMAL(14,2)) AS ventas_total,
        SUM(CASE WHEN COALESCE(p.medios,0)>1 THEN 1 ELSE 0 END) AS operaciones_divididas,
        CAST(COALESCE(SUM(CASE WHEN COALESCE(p.medios,0)>1 THEN v.total ELSE 0 END),0) AS DECIMAL(14,2)) AS total_dividido
        FROM ventas v LEFT JOIN (SELECT venta_id,COUNT(DISTINCT medio) AS medios FROM venta_pagos GROUP BY venta_id) p
          ON p.venta_id=v.venta_id
        WHERE v.caja_id=:caja AND v.estado=\'CONFIRMADA\'');
    $stmt->execute(['caja' => $cajaId]);
    $ventas = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $usaDevoluciones = function_exists('corozao_devoluciones_instaladas') && corozao_devoluciones_instaladas($pdo);
    if ($usaDevoluciones) {
        $stmt = $pdo->prepare('SELECT dp.medio,CAST(SUM(dp.monto) AS DECIMAL(14,2)) AS monto
            FROM devolucion_pagos dp INNER JOIN devoluciones d ON d.devolucion_id=dp.devolucion_id
            WHERE d.caja_id=:caja AND d.estado=\'APROBADA\' GROUP BY dp.medio');
        $stmt->execute(['caja' => $cajaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($reembolsos[$row['medio']])) $reembolsos[$row['medio']] = corozao_dinero_centavos((string)$row['monto']);
        }
    }

    $ingresos = 0;
    $retiros = 0;
    if (corozao_caja_mejoras_instaladas($pdo)) {
        $stmt = $pdo->prepare('SELECT tipo,CAST(SUM(monto) AS DECIMAL(14,2)) AS monto FROM caja_movimientos
            WHERE caja_id=:caja GROUP BY tipo');
        $stmt->execute(['caja' => $cajaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['tipo'] === 'INGRESO') $ingresos = corozao_dinero_centavos((string)$row['monto']);
            if ($row['tipo'] === 'RETIRO') $retiros = corozao_dinero_centavos((string)$row['monto']);
        }
    }

    $esperados = $pagos;
    $esperados['EFECTIVO'] += corozao_dinero_centavos((string)$caja['monto_apertura']) + $ingresos - $retiros - $reembolsos['EFECTIVO'];
    $esperados['TRANSFERENCIA'] -= $reembolsos['TRANSFERENCIA'];
    $esperados['TARJETA_CREDITO'] -= $reembolsos['TARJETA_CREDITO'];
    if (min($esperados) < 0) throw new DomainException('Los retiros y reembolsos superan los ingresos registrados de la caja.');

    return [
        'operaciones' => (int)($ventas['operaciones'] ?? 0),
        'ventas_total' => corozao_dinero_centavos((string)($ventas['ventas_total'] ?? '0.00')),
        'operaciones_divididas' => (int)($ventas['operaciones_divididas'] ?? 0),
        'total_dividido' => corozao_dinero_centavos((string)($ventas['total_dividido'] ?? '0.00')),
        'fondo_inicial' => corozao_dinero_centavos((string)$caja['monto_apertura']),
        'pagos' => $pagos, 'reembolsos' => $reembolsos,
        'ingresos' => $ingresos, 'retiros' => $retiros, 'esperados' => $esperados,
    ];
}

function corozao_caja_movimiento_registrar(PDO $pdo, int $cajaId, int $usuarioId, string $tipo, string $monto, string $motivo, string $clave): int
{
    corozao_exigir_permiso($pdo, 'cash.movements.manage');
    if (!corozao_caja_mejoras_instaladas($pdo)) throw new DomainException('La migración de movimientos de caja aún no está instalada.');
    if ($cajaId < 1 || $usuarioId < 1 || !in_array($tipo, ['INGRESO', 'RETIRO'], true)) throw new DomainException('El movimiento de caja no es válido.');
    $centavos = corozao_dinero_centavos($monto, false);
    $motivo = trim($motivo);
    if ($motivo === '' || mb_strlen($motivo, 'UTF-8') > 500) throw new DomainException('El motivo es obligatorio y admite hasta 500 caracteres.');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clave)) throw new DomainException('La clave del movimiento no es válida. Recarga la página.');
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT caja_id,estado FROM cajas WHERE caja_id=:caja FOR UPDATE');
        $stmt->execute(['caja' => $cajaId]);
        $caja = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$caja || $caja['estado'] !== 'ABIERTA') throw new DomainException('Solo se permiten movimientos en una caja abierta.');
        $stmt = $pdo->prepare('SELECT caja_movimiento_id FROM caja_movimientos WHERE clave_idempotencia=:clave FOR UPDATE');
        $stmt->execute(['clave' => $clave]);
        $existente = $stmt->fetchColumn();
        if ($existente) { $pdo->commit(); return (int)$existente; }
        if ($tipo === 'RETIRO') {
            $resumen = corozao_caja_resumen($pdo, $cajaId);
            if ($centavos > $resumen['esperados']['EFECTIVO']) throw new DomainException('El retiro supera el efectivo esperado disponible en caja.');
        }
        $stmt = $pdo->prepare('INSERT INTO caja_movimientos
            (caja_id,tipo,monto,motivo,clave_idempotencia,registrado_por_usuario_id,autorizado_por_usuario_id)
            VALUES (:caja,:tipo,:monto,:motivo,:clave,:usuario,:autorizador)');
        $stmt->execute(['caja' => $cajaId, 'tipo' => $tipo, 'monto' => corozao_centavos_decimal($centavos),
            'motivo' => $motivo, 'clave' => $clave, 'usuario' => $usuarioId, 'autorizador' => $usuarioId]);
        $id = (int)$pdo->lastInsertId();
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'caja_movimientos', $id, null,
            ['caja_id' => $cajaId, 'tipo' => $tipo, 'monto' => corozao_centavos_decimal($centavos), 'motivo' => $motivo]);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function corozao_caja_diferencia_resolver(PDO $pdo, int $revisionId, int $usuarioId, string $decision, string $observaciones): void
{
    corozao_exigir_permiso($pdo, 'cash.differences.review');
    if (!corozao_caja_mejoras_instaladas($pdo)) throw new DomainException('La revisión de diferencias aún no está instalada.');
    if ($revisionId < 1 || !in_array($decision, ['APROBADA', 'RECHAZADA'], true)) throw new DomainException('La resolución no es válida.');
    $observaciones = trim($observaciones);
    if (mb_strlen($observaciones, 'UTF-8') > 500 || ($decision === 'RECHAZADA' && $observaciones === '')) {
        throw new DomainException('Indica un motivo para rechazar la diferencia (hasta 500 caracteres).');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT caja_revision_id,caja_id,estado,detalle_diferencias FROM caja_revision_diferencias
            WHERE caja_revision_id=:id FOR UPDATE');
        $stmt->execute(['id' => $revisionId]);
        $revision = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$revision || $revision['estado'] !== 'PENDIENTE') throw new DomainException('La diferencia ya fue revisada o no existe.');
        $stmt = $pdo->prepare('UPDATE caja_revision_diferencias SET estado=:estado,revisado_por_usuario_id=:usuario,
            observaciones_revision=:observaciones,fecha_revision=CURRENT_TIMESTAMP(6)
            WHERE caja_revision_id=:id AND estado=\'PENDIENTE\'');
        $stmt->execute(['estado' => $decision, 'usuario' => $usuarioId,
            'observaciones' => $observaciones ?: null, 'id' => $revisionId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('La diferencia fue revisada por otra sesión.');
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'caja_revision_diferencias', $revisionId,
            ['estado' => 'PENDIENTE'], ['estado' => $decision, 'caja_id' => (int)$revision['caja_id'],
                'detalle_diferencias' => json_decode((string)$revision['detalle_diferencias'], true),
                'observaciones_revision' => $observaciones ?: null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
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

/** Recalcula líneas directas desde el precio y los datos comerciales vigentes de la bodega. */
function corozao_venta_preparar_lineas_directas(PDO $pdo, int $bodegaId, array $entradas): array
{
    if (count($entradas) < 1 || count($entradas) > 100) {
        throw new DomainException('La venta requiere entre uno y cien productos.');
    }
    $lineas = [];
    $vistos = [];
    $metaStmt = $pdo->prepare(
        'SELECT p.nombre, p.marca, p.contenido, p.unidad_base, c.nombre AS categoria,
                pr.nombre AS presentacion,
                (SELECT GROUP_CONCAT(DISTINCT pc.codigo ORDER BY pc.codigo) FROM producto_codigos pc
                 WHERE pc.presentacion_id = pr.presentacion_id) AS codigo
         FROM presentaciones pr INNER JOIN productos p ON p.producto_id = pr.producto_id
         LEFT JOIN categorias c ON c.categoria_id = p.categoria_id
         WHERE pr.presentacion_id = :presentacion'
    );
    $importesStmt = $pdo->prepare(
        'SELECT CAST(ROUND(CASE WHEN :incluye_neto = 1 THEN :subtotal_neto / (1 + :iva_neto / 100)
                    ELSE :subtotal_neto2 END, 2) AS DECIMAL(14,2)) AS neto,
                CAST(ROUND(CASE WHEN :incluye_impuesto = 1
                    THEN :subtotal_impuesto - ROUND(:subtotal_impuesto2 / (1 + :iva_impuesto / 100), 2)
                    ELSE :subtotal_impuesto3 * :iva_impuesto2 / 100 END, 2) AS DECIMAL(14,2)) AS impuesto,
                CAST(ROUND(CASE WHEN :incluye_total = 1 THEN :subtotal_total
                    ELSE ROUND(:subtotal_total2, 2) + ROUND(:subtotal_total3 * :iva_total / 100, 2) END, 2) AS DECIMAL(14,2)) AS bruto'
    );
    foreach ($entradas as $entrada) {
        if (!is_array($entrada) || !is_string($entrada['presentacion_id'] ?? null)
            || !is_string($entrada['cantidad'] ?? null)) {
            throw new DomainException('Una línea de la venta no es válida.');
        }
        $presentacionId = filter_var($entrada['presentacion_id'], FILTER_VALIDATE_INT);
        if (!$presentacionId || $presentacionId < 1 || isset($vistos[$presentacionId])) {
            throw new DomainException('Selecciona presentaciones válidas sin repetirlas.');
        }
        $vistos[$presentacionId] = true;
        $cantidad = corozao_pedido_cantidad($entrada['cantidad']);
        $comercial = corozao_pedido_precio_vigente($pdo, $bodegaId, $presentacionId);
        $calculo = corozao_pedido_calcular_linea($pdo, $cantidad, (string)$comercial['unidades_base'], (string)$comercial['precio_venta']);
        $metaStmt->execute(['presentacion' => $presentacionId]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC);
        if (!$meta) throw new DomainException('No se pudo cargar el producto seleccionado.');
        $importesStmt->execute([
            'incluye_neto' => $comercial['precio_incluye_iva'], 'subtotal_neto' => $calculo['subtotal_linea'],
            'subtotal_neto2' => $calculo['subtotal_linea'], 'iva_neto' => $comercial['tarifa_iva'],
            'incluye_impuesto' => $comercial['precio_incluye_iva'], 'subtotal_impuesto' => $calculo['subtotal_linea'],
            'subtotal_impuesto2' => $calculo['subtotal_linea'], 'subtotal_impuesto3' => $calculo['subtotal_linea'],
            'iva_impuesto' => $comercial['tarifa_iva'],
            'iva_impuesto2' => $comercial['tarifa_iva'], 'incluye_total' => $comercial['precio_incluye_iva'],
            'subtotal_total' => $calculo['subtotal_linea'], 'subtotal_total2' => $calculo['subtotal_linea'],
            'subtotal_total3' => $calculo['subtotal_linea'],
            'iva_total' => $comercial['tarifa_iva'],
        ]);
        $importes = $importesStmt->fetch(PDO::FETCH_ASSOC);
        $lineas[] = array_merge($comercial, $meta, [
            'pedido_detalle_id' => null,
            'cantidad_presentacion' => $cantidad,
            'cantidad_base' => $calculo['cantidad_base'],
            'subtotal_linea' => $calculo['subtotal_linea'],
            'importe_subtotal' => $importes['neto'],
            'importe_impuesto' => $importes['impuesto'],
            'importe_bruto' => $importes['bruto'],
            'nombre_producto' => $meta['nombre'],
            'marca_producto' => $meta['marca'],
            'contenido_producto' => $meta['contenido'],
            'categoria_producto' => $meta['categoria'],
            'nombre_presentacion' => $meta['presentacion'],
            'codigo_producto' => $meta['codigo'],
        ]);
    }
    usort($lineas, static fn(array $a, array $b): int =>
        [$a['producto_id'], $a['presentacion_id']] <=> [$b['producto_id'], $b['presentacion_id']]);
    return $lineas;
}

function corozao_venta_totales_lineas(array $lineas): array
{
    $totales = ['subtotal' => 0, 'impuestos' => 0, 'bruto' => 0];
    foreach ($lineas as $linea) {
        $totales['subtotal'] += corozao_dinero_centavos((string)$linea['importe_subtotal']);
        $totales['impuestos'] += corozao_dinero_centavos((string)$linea['importe_impuesto']);
        $totales['bruto'] += corozao_dinero_centavos((string)$linea['importe_bruto']);
    }
    if (!$lineas) throw new DomainException('La venta no contiene productos.');
    return $totales;
}

function corozao_venta_cobrar(PDO $pdo, int $pedidoId, int $usuarioId, array $entrada): int
{
    return corozao_venta_confirmar_fuente($pdo, $pedidoId, 0, $usuarioId, $entrada);
}

function corozao_venta_cobrar_directa(PDO $pdo, int $bodegaId, int $usuarioId, array $entrada): int
{
    return corozao_venta_confirmar_fuente($pdo, null, $bodegaId, $usuarioId, $entrada);
}

/** Confirma por un mismo camino ventas procedentes de pedidos y ventas directas. */
function corozao_venta_confirmar_fuente(PDO $pdo, ?int $pedidoId, int $bodegaId, int $usuarioId, array $entrada): int
{
    if (($pedidoId !== null && $pedidoId < 1) || $usuarioId < 1 || ($pedidoId === null && $bodegaId < 1)) {
        throw new DomainException('El pedido, bodega o usuario no son válidos.');
    }
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
    $referenciaCliente = $entrada['referencia_cliente'] ?? '';
    if (!is_string($referenciaCliente)) throw new DomainException('La referencia del cliente no es válida.');
    $referenciaCliente = trim($referenciaCliente);
    if (mb_strlen($referenciaCliente, 'UTF-8') > 100) throw new DomainException('La referencia del cliente no puede superar 100 caracteres.');
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
    $cashTraceInstalled = corozao_caja_mejoras_instaladas($pdo);
    $efectivoAplicado = $pagosLimpios['EFECTIVO'] ?? 0;
    $efectivoInput = $entrada['efectivo_recibido'] ?? null;
    if ($cashTraceInstalled) {
        if (!is_string($efectivoInput)) throw new DomainException('Indica el efectivo recibido del cliente.');
        $efectivoRecibido = corozao_dinero_centavos($efectivoInput);
        if ($efectivoAplicado === 0 && $efectivoRecibido !== 0) {
            throw new DomainException('Registra el efectivo recibido solo cuando la venta incluya un pago en efectivo.');
        }
        if ($efectivoRecibido < $efectivoAplicado) {
            throw new DomainException('El efectivo recibido no puede ser inferior al importe aplicado en efectivo.');
        }
    } else {
        // Mantiene el comportamiento anterior hasta que se aplique la migración incremental.
        $efectivoRecibido = $efectivoAplicado;
    }
    $cambioEntregado = $efectivoRecibido - $efectivoAplicado;
    $referencias = $entrada['referencias'] ?? [];
    if (!is_array($referencias)) $referencias = [];
    $lineasDirectasEntrada = $entrada['lineas'] ?? [];
    if (!is_array($lineasDirectasEntrada)) throw new DomainException('El detalle de la venta no es válido.');
    if ($pedidoId === null && (count($lineasDirectasEntrada) < 1 || count($lineasDirectasEntrada) > 100)) {
        throw new DomainException('Agrega entre uno y cien productos a la venta.');
    }
    $claveIdempotencia = $entrada['clave_idempotencia'] ?? '';
    if ($pedidoId === null && (!is_string($claveIdempotencia)
        || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $claveIdempotencia))) {
        throw new DomainException('La clave de venta no es válida. Recarga la página.');
    }

    try {
        $pdo->beginTransaction();
        $pedido = null;
        if ($pedidoId !== null) {
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
            $referenciaCliente = (string)($pedido['referencia_cliente'] ?? '');
            $bodegaId = (int) $pedido['bodega_id'];
        } else {
            $stmt = $pdo->prepare('SELECT venta_id FROM ventas WHERE clave_idempotencia = :clave FOR UPDATE');
            $stmt->execute(['clave' => $claveIdempotencia]);
            $existente = $stmt->fetchColumn();
            if ($existente) { $pdo->commit(); return (int) $existente; }
            $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id = :bodega AND activo = 1 FOR UPDATE');
            $stmt->execute(['bodega' => $bodegaId]);
            if (!$stmt->fetchColumn()) throw new DomainException('La bodega seleccionada ya no está activa.');
        }
        $fechaCajaSelect = corozao_caja_fecha_operativa_instalada($pdo)
            ? 'fecha_operativa' : 'NULL AS fecha_operativa';
        $stmt = $pdo->prepare(
            'SELECT caja_id, bodega_id, ' . $fechaCajaSelect . ' FROM cajas
             WHERE usuario_id = :usuario AND estado = \'ABIERTA\' AND bodega_id = :bodega FOR UPDATE'
        );
        $stmt->execute(['usuario' => $usuarioId, 'bodega' => $bodegaId]);
        $caja = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$caja) throw new DomainException('Abre una caja para la bodega del pedido antes de cobrar.');
        if (corozao_caja_fecha_operativa_instalada($pdo)
            && !corozao_fecha_caja_es_hoy($caja['fecha_operativa'] === null ? null : (string)$caja['fecha_operativa'], corozao_fecha_operativa_local())) {
            throw new DomainException('La caja pertenece a una fecha operativa anterior. Ciérrala y abre una nueva para continuar cobrando.');
        }
        $lineas = [];
        if ($pedidoId !== null) {
            $totales = corozao_venta_calcular_totales($pdo, $pedidoId);
            $stmt = $pdo->prepare(
                'SELECT d.*, p.nombre AS nombre_producto, p.marca AS marca_producto,
                        p.contenido AS contenido_producto, p.unidad_base,
                        c.nombre AS categoria_producto, pr.nombre AS nombre_presentacion,
                        (SELECT GROUP_CONCAT(DISTINCT pc.codigo ORDER BY pc.codigo) FROM producto_codigos pc
                         WHERE pc.presentacion_id = d.presentacion_id) AS codigo_producto,
                 CAST(ROUND(CASE WHEN d.precio_incluye_iva = 1 THEN d.subtotal_linea / (1 + d.tarifa_iva / 100)
                       ELSE d.subtotal_linea END, 2) AS DECIMAL(14,2)) AS importe_subtotal,
                 CAST(ROUND(CASE WHEN d.precio_incluye_iva = 1
                       THEN d.subtotal_linea - ROUND(d.subtotal_linea / (1 + d.tarifa_iva / 100), 2)
                       ELSE d.subtotal_linea * d.tarifa_iva / 100 END, 2) AS DECIMAL(14,2)) AS importe_impuesto,
                 CAST(ROUND(CASE WHEN d.precio_incluye_iva = 1 THEN d.subtotal_linea
                       ELSE ROUND(d.subtotal_linea, 2) + ROUND(d.subtotal_linea * d.tarifa_iva / 100, 2) END, 2) AS DECIMAL(14,2)) AS importe_bruto
                 FROM pedido_detalles d INNER JOIN productos p ON p.producto_id = d.producto_id
                 INNER JOIN presentaciones pr ON pr.presentacion_id = d.presentacion_id
                 LEFT JOIN categorias c ON c.categoria_id = p.categoria_id
                 WHERE d.pedido_id = :pedido ORDER BY d.producto_id, d.presentacion_id FOR UPDATE'
            );
            $stmt->execute(['pedido' => $pedidoId]);
            $lineas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $lineas = corozao_venta_preparar_lineas_directas($pdo, $bodegaId, $lineasDirectasEntrada);
            $totales = corozao_venta_totales_lineas($lineas);
        }
        $total = $totales['bruto'] - $descuento;
        if ($descuento > $totales['bruto'] || $total < 0) throw new DomainException('El descuento fijo no puede superar el total de la venta.');
        if ($total > 0 && !$pagosLimpios) throw new DomainException('Registra al menos un medio de pago.');
        if (array_sum($pagosLimpios) !== $total) throw new DomainException('La suma de pagos debe ser igual al total después del descuento.');

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
            $stmt->execute(['bodega' => $bodegaId, 'producto' => $productoId]);
            $stmt = $pdo->prepare(
                'SELECT cantidad_base FROM inventario_saldos WHERE bodega_id = :bodega AND producto_id = :producto FOR UPDATE'
            );
            $stmt->execute(['bodega' => $bodegaId, 'producto' => $productoId]);
            if (corozao_cantidad_milesima((string) $stmt->fetchColumn()) < $cantidad) {
                throw new DomainException('Existencias insuficientes para el producto #' . $productoId . '.');
            }
        }
        $uuid = static function (): string {
            $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
            $h = bin2hex($b); return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
        };
        $ventaColumns = 'pedido_id, referencia_cliente, caja_id, bodega_id, usuario_id, subtotal, impuestos,
             descuento_valor, total, tipo_destinatario, identificacion_destinatario,
             nombre_destinatario, direccion_destinatario, correo_destinatario, clave_idempotencia';
        $ventaValues = ':pedido, :referencia_cliente, :caja, :bodega, :usuario, :subtotal, :impuestos, :descuento, :total,
                     :tipo, :identificacion, :nombre, :direccion, :correo, :clave';
        if ($cashTraceInstalled) {
            $ventaColumns = str_replace('descuento_valor, total', 'descuento_valor, efectivo_recibido, cambio_entregado, total', $ventaColumns);
            $ventaValues = str_replace(':descuento, :total', ':descuento, :efectivo_recibido, :cambio_entregado, :total', $ventaValues);
        }
        $stmt = $pdo->prepare('INSERT INTO ventas (' . $ventaColumns . ') VALUES (' . $ventaValues . ')');
        $ventaParams = [
            'pedido' => $pedidoId, 'referencia_cliente' => $referenciaCliente === '' ? null : $referenciaCliente,
            'caja' => $caja['caja_id'], 'bodega' => $bodegaId, 'usuario' => $usuarioId,
            'subtotal' => corozao_centavos_decimal($totales['subtotal']), 'impuestos' => corozao_centavos_decimal($totales['impuestos']),
            'descuento' => corozao_centavos_decimal($descuento), 'total' => corozao_centavos_decimal($total), 'tipo' => $tipo,
            'identificacion' => $identificacion === '' ? null : $identificacion, 'nombre' => $nombre,
            'direccion' => $direccion === '' ? null : $direccion, 'correo' => $correo === '' ? null : $correo,
            'clave' => $pedidoId === null ? $claveIdempotencia : $uuid(),
        ];
        if ($cashTraceInstalled) $ventaParams += [
            'efectivo_recibido' => corozao_centavos_decimal($efectivoRecibido),
            'cambio_entregado' => corozao_centavos_decimal($cambioEntregado),
        ];
        $stmt->execute($ventaParams);
        $ventaId = (int) $pdo->lastInsertId();
        $tieneSnapshots = corozao_ventas_directas_instaladas($pdo);
        $sqlDetalle = $tieneSnapshots
            ? 'INSERT INTO venta_detalles (venta_id, producto_nombre_snapshot, marca_snapshot, contenido_snapshot,
                categoria_snapshot, presentacion_snapshot, codigo_snapshot, pedido_detalle_id, producto_id, presentacion_id, precio_bodega_id,
                cantidad_presentacion, unidades_base, cantidad_base, precio_unitario_presentacion, precio_incluye_iva,
                tarifa_iva, subtotal, impuesto, total_bruto)
               VALUES (:venta, :nombre_producto, :marca, :contenido, :categoria, :nombre_presentacion, :codigo,
                :pedido_detalle, :producto, :presentacion, :precio_id, :cantidad, :unidades, :base,
                :precio, :incluye, :tarifa, :subtotal, :impuesto, :bruto)'
            : 'INSERT INTO venta_detalles (venta_id, pedido_detalle_id, producto_id, presentacion_id, precio_bodega_id,
                cantidad_presentacion, unidades_base, cantidad_base, precio_unitario_presentacion, precio_incluye_iva,
                tarifa_iva, subtotal, impuesto, total_bruto)
               VALUES (:venta, :pedido_detalle, :producto, :presentacion, :precio_id, :cantidad, :unidades, :base,
                :precio, :incluye, :tarifa, :subtotal, :impuesto, :bruto)';
        $detalleStmt = $pdo->prepare($sqlDetalle);
        foreach ($lineas as $linea) {
            $neto = corozao_dinero_centavos((string) $linea['importe_subtotal']);
            $iva = corozao_dinero_centavos((string) $linea['importe_impuesto']);
            $bruto = corozao_dinero_centavos((string) $linea['importe_bruto']);
            $detalleParams = [
                'venta' => $ventaId, 'pedido_detalle' => $linea['pedido_detalle_id'], 'producto' => $linea['producto_id'],
                'presentacion' => $linea['presentacion_id'], 'precio_id' => $linea['precio_bodega_id'],
                'cantidad' => $linea['cantidad_presentacion'], 'unidades' => $linea['unidades_base'], 'base' => $linea['cantidad_base'],
                'precio' => $linea['precio_unitario_presentacion'] ?? $linea['precio_venta'], 'incluye' => $linea['precio_incluye_iva'], 'tarifa' => $linea['tarifa_iva'],
                'subtotal' => corozao_centavos_decimal($neto), 'impuesto' => corozao_centavos_decimal($iva), 'bruto' => corozao_centavos_decimal($bruto),
            ];
            if ($tieneSnapshots) $detalleParams += [
                'nombre_producto' => $linea['nombre_producto'], 'marca' => $linea['marca_producto'] ?? null,
                'contenido' => $linea['contenido_producto'] ?? null, 'categoria' => $linea['categoria_producto'] ?? null,
                'nombre_presentacion' => $linea['nombre_presentacion'] ?? null, 'codigo' => $linea['codigo_producto'] ?? null,
            ];
            $detalleStmt->execute($detalleParams);
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
            $stmt->execute(['cantidad' => $unidades, 'bodega' => $bodegaId,
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
        $stmt->execute(['bodega' => $bodegaId, 'observaciones' => 'Venta #' . $ventaId,
            'usuario' => $usuarioId, 'clave' => $uuid(), 'confirmador' => $usuarioId, 'venta' => $ventaId]);
        $movimientoId = (int) $pdo->lastInsertId();
        $movDet = $pdo->prepare(
            'INSERT INTO detalle_movimiento_inventario
             (movimiento_id, producto_id, presentacion_id, cantidad_base, costo_unitario, costo_total)
             VALUES (:movimiento, :producto, :presentacion, :cantidad, NULL, NULL)'
        );
        foreach ($lineas as $linea) $movDet->execute(['movimiento' => $movimientoId, 'producto' => $linea['producto_id'],
            'presentacion' => $linea['presentacion_id'], 'cantidad' => $linea['cantidad_base']]);
        if ($pedidoId !== null) {
            $stmt = $pdo->prepare('UPDATE pedidos SET estado = \'FACTURADO\', actualizado_por_usuario_id = :usuario, fecha_actualizacion = CURRENT_TIMESTAMP(6) WHERE pedido_id = :pedido AND estado = \'PENDIENTE_FACTURACION\'');
            $stmt->execute(['usuario' => $usuarioId, 'pedido' => $pedidoId]);
            if ($stmt->rowCount() !== 1) throw new DomainException('El estado del pedido cambió durante el cobro.');
        }
        $stmt = $pdo->prepare('INSERT INTO facturas_electronicas (venta_id, estado) VALUES (:venta, \'PENDIENTE\')');
        $stmt->execute(['venta' => $ventaId]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'ventas', $ventaId, null, [
            'pedido_id' => $pedidoId, 'origen' => $pedidoId === null ? 'DIRECTA' : 'PEDIDO',
            'bodega_id' => $bodegaId, 'subtotal' => corozao_centavos_decimal($totales['subtotal']),
            'impuestos' => corozao_centavos_decimal($totales['impuestos']), 'descuento_valor' => corozao_centavos_decimal($descuento),
            'total' => corozao_centavos_decimal($total), 'tipo_destinatario' => $tipo,
            'efectivo_recibido' => corozao_centavos_decimal($efectivoRecibido),
            'cambio_entregado' => corozao_centavos_decimal($cambioEntregado),
            'referencia_cliente' => $referenciaCliente === '' ? null : $referenciaCliente,
            'identificacion_destinatario' => $identificacion === '' ? null : $identificacion, 'nombre_destinatario' => $nombre,
            'movimiento_id' => $movimientoId, 'estado_factura_sri' => 'PENDIENTE',
        ]);
        if ($pedidoId !== null) {
            corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'pedidos', $pedidoId,
                ['estado' => 'PENDIENTE_FACTURACION'], ['estado' => 'FACTURADO', 'venta_id' => $ventaId]);
        }
        $pdo->commit();
        return $ventaId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function corozao_caja_cerrar(PDO $pdo, int $cajaId, int $usuarioId, array $declarados, string $observaciones, array $reembolsosDeclarados = []): void
{
    $usaConciliacionCompleta = corozao_caja_conciliacion_instalada($pdo);
    $usaDevoluciones = function_exists('corozao_devoluciones_instaladas') && corozao_devoluciones_instaladas($pdo);
    $medios = $usaConciliacionCompleta ? ['EFECTIVO', 'TRANSFERENCIA', 'TARJETA_CREDITO'] : ['EFECTIVO'];
    $mediosReembolso = $usaDevoluciones ? ['TRANSFERENCIA', 'TARJETA_CREDITO'] : [];
    if (array_diff(array_keys($declarados), $medios) || array_diff($medios, array_keys($declarados))) {
        throw new DomainException('Declara el total contado para cada medio de pago.');
    }
    $declaradoCentavos = [];
    foreach ($medios as $medio) {
        if (!is_string($declarados[$medio])) throw new DomainException('Uno de los importes declarados no es válido.');
        $declaradoCentavos[$medio] = corozao_dinero_centavos($declarados[$medio]);
    }
    if (array_diff(array_keys($reembolsosDeclarados), $mediosReembolso)
        || array_diff($mediosReembolso, array_keys($reembolsosDeclarados))) {
        throw new DomainException('Declara los reembolsos por transferencia y tarjeta para el cierre.');
    }
    $reembolsosDeclaradosCentavos = [];
    foreach ($mediosReembolso as $medio) {
        if (!is_string($reembolsosDeclarados[$medio])) throw new DomainException('Uno de los importes reembolsados no es válido.');
        $reembolsosDeclaradosCentavos[$medio] = corozao_dinero_centavos($reembolsosDeclarados[$medio]);
    }
    $observaciones = trim($observaciones);
    if (mb_strlen($observaciones, 'UTF-8') > 500) throw new DomainException('Las observaciones admiten hasta 500 caracteres.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM cajas WHERE caja_id = :id AND usuario_id = :usuario AND estado = \'ABIERTA\' FOR UPDATE');
        $stmt->execute(['id' => $cajaId, 'usuario' => $usuarioId]);
        $caja = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$caja) throw new DomainException('La caja ya no está abierta o no te pertenece.');
        $resumen = corozao_caja_resumen($pdo, $cajaId);
        $esperados = array_fill_keys($medios, 0);
        foreach ($medios as $medio) $esperados[$medio] = $resumen['esperados'][$medio];
        $reembolsosEsperados = array_fill_keys($mediosReembolso, 0);
        foreach ($mediosReembolso as $medio) $reembolsosEsperados[$medio] = $resumen['reembolsos'][$medio];
        $diferencias = [];
        foreach ($medios as $medio) $diferencias[$medio] = $declaradoCentavos[$medio] - $esperados[$medio];
        $diferenciasReembolso = [];
        foreach ($mediosReembolso as $medio) $diferenciasReembolso[$medio] = $reembolsosDeclaradosCentavos[$medio] - $reembolsosEsperados[$medio];
        $hayDiferencias = in_array(true, array_map(static fn(int $d): bool => $d !== 0, $diferencias), true)
            || in_array(true, array_map(static fn(int $d): bool => $d !== 0, $diferenciasReembolso), true);
        if ($hayDiferencias) {
            corozao_exigir_permiso($pdo, 'cash.differences.record');
        }
        if ($hayDiferencias && $observaciones === '') {
            throw new DomainException('Explica el motivo de las diferencias de caja.');
        }
        if ($hayDiferencias && corozao_caja_mejoras_instaladas($pdo)) {
            $detalleRevision = ['medios' => [], 'reembolsos' => []];
            foreach ($medios as $medio) $detalleRevision['medios'][$medio] = [
                'esperado' => corozao_centavos_decimal($esperados[$medio]),
                'declarado' => corozao_centavos_decimal($declaradoCentavos[$medio]),
                'diferencia' => corozao_centavos_decimal($diferencias[$medio]),
            ];
            foreach ($mediosReembolso as $medio) $detalleRevision['reembolsos'][$medio] = [
                'esperado' => corozao_centavos_decimal($reembolsosEsperados[$medio]),
                'declarado' => corozao_centavos_decimal($reembolsosDeclaradosCentavos[$medio]),
                'diferencia' => corozao_centavos_decimal($diferenciasReembolso[$medio]),
            ];
            $stmt = $pdo->prepare('INSERT INTO caja_revision_diferencias
                (caja_id,estado,detalle_diferencias,cerrado_por_usuario_id)
                VALUES (:caja,\'PENDIENTE\',:detalle,:usuario)');
            $stmt->execute(['caja' => $cajaId,
                'detalle' => json_encode($detalleRevision, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'usuario' => $usuarioId]);
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
        if ($usaDevoluciones) {
            $insertarReembolso = $pdo->prepare('INSERT INTO caja_reembolsos_conciliaciones
                (caja_id,medio,monto_esperado,monto_declarado,diferencia)
                VALUES (:caja,:medio,:esperado,:declarado,:diferencia)');
            foreach ($mediosReembolso as $medio) {
                $datos = ['caja' => $cajaId, 'medio' => $medio,
                    'esperado' => corozao_centavos_decimal($reembolsosEsperados[$medio]),
                    'declarado' => corozao_centavos_decimal($reembolsosDeclaradosCentavos[$medio]),
                    'diferencia' => corozao_centavos_decimal($diferenciasReembolso[$medio])];
                $insertarReembolso->execute($datos);
                $conciliacionesAuditoria['REEMBOLSO_' . $medio] = ['esperado' => $datos['esperado'],
                    'declarado' => $datos['declarado'], 'diferencia' => $datos['diferencia']];
            }
        }
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'cajas', $cajaId,
            ['estado' => 'ABIERTA'], ['estado' => 'CERRADA', 'conciliacion_completa' => $usaConciliacionCompleta,
                'conciliaciones' => $conciliacionesAuditoria,
                'revision_admin' => $hayDiferencias && corozao_caja_mejoras_instaladas($pdo) ? 'PENDIENTE' : null,
                'observaciones_cierre' => $observaciones ?: null]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
