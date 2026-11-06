<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('COROZAO_SESSID');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');

require '/etc/corozao/database.php';
require_once __DIR__ . '/includes/autorizacion.php';
require_once __DIR__ . '/includes/pedidos.php';
require_once __DIR__ . '/includes/ventas.php';
require_once __DIR__ . '/includes/devoluciones.php';

function venta_h(mixed $valor): string { return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function venta_uuid(): string
{
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128); $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

$usuario = corozao_exigir_permiso($pdo, 'sales.view_own');
corozao_exigir_permiso($pdo, 'sales.create');
$csrf = $_SESSION['csrf_token'] ?? '';
if (!is_string($csrf) || strlen($csrf) < 32) { $csrf = bin2hex(random_bytes(32)); $_SESSION['csrf_token'] = $csrf; }
$error = null;
$listo = false;
$conciliacionLista = false;
$fechaOperativaLista = false;
$devolucionesLista = false;
$ventasDirectasLista = false;
$cajaMejorasLista = false;
$mensaje = $_SESSION['ventas_flash'] ?? null;
unset($_SESSION['ventas_flash']);
$bodegas = corozao_bodegas_asignadas($pdo, $usuario);
$cajas = [];
$resumenesCaja = [];
$cajasAbiertasAdmin = [];
$revisionesPendientes = [];
$movimientosCaja = [];
$pedidos = [];
$ventasPropias = [];
$cobrarId = filter_var($_GET['cobrar'] ?? null, FILTER_VALIDATE_INT) ?: null;
$ventaId = filter_var($_GET['venta'] ?? null, FILTER_VALIDATE_INT) ?: null;

try { $listo = corozao_ventas_instaladas($pdo); }
catch (Throwable $e) { error_log('Corozao: no se pudo validar el esquema de ventas.'); http_response_code(503); $error = 'No se pudo validar el esquema de ventas.'; }
if ($listo) {
    try { $conciliacionLista = corozao_caja_conciliacion_instalada($pdo); }
    catch (Throwable $e) { error_log('Corozao: no se pudo validar el esquema de conciliación.'); }
    try { $fechaOperativaLista = corozao_caja_fecha_operativa_instalada($pdo); }
    catch (Throwable $e) { error_log('Corozao: no se pudo validar la fecha operativa de caja.'); }
    try { $devolucionesLista = corozao_devoluciones_instaladas($pdo); }
    catch (Throwable $e) { error_log('Corozao: no se pudo validar el esquema de devoluciones.'); }
    try { $ventasDirectasLista = corozao_ventas_directas_instaladas($pdo); }
    catch (Throwable $e) { error_log('Corozao: no se pudo validar la ampliación de ventas directas.'); }
    try { $cajaMejorasLista = corozao_caja_mejoras_instaladas($pdo); }
    catch (Throwable $e) { error_log('Corozao: no se pudo validar las mejoras de cobro y revisión de caja.'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $listo) {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !corozao_csrf_valido($token)) {
        http_response_code(403); $error = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
    } else {
        try {
            $accion = $_POST['accion'] ?? '';
            if ($accion === 'abrir_caja') {
                corozao_exigir_permiso($pdo, 'cash.open_own');
                $bodegaId = filter_var($_POST['bodega_id'] ?? null, FILTER_VALIDATE_INT);
                $monto = $_POST['monto_apertura'] ?? '';
                if (!$bodegaId || !is_string($monto)) throw new DomainException('Indica la bodega y el fondo inicial.');
                corozao_exigir_bodega($pdo, $usuario, $bodegaId);
                corozao_caja_abrir($pdo, (int) $usuario['usuario_id'], $bodegaId, $monto);
                $_SESSION['ventas_flash'] = 'Caja abierta.';
                header('Location: /ventas.php'); exit;
            }
            if ($accion === 'cerrar_caja') {
                corozao_exigir_permiso($pdo, 'cash.close_own');
                $cajaId = filter_var($_POST['caja_id'] ?? null, FILTER_VALIDATE_INT);
                $declarados = $_POST['declarados'] ?? null;
                $reembolsosDeclarados = $_POST['reembolsos_declarados'] ?? [];
                $observaciones = $_POST['observaciones_cierre'] ?? '';
                if (!$cajaId || !is_array($declarados) || !is_array($reembolsosDeclarados) || !is_string($observaciones)) throw new DomainException('Los datos de cierre no son válidos.');
                corozao_caja_cerrar($pdo, $cajaId, (int) $usuario['usuario_id'], $declarados, $observaciones, $reembolsosDeclarados);
                $pendienteRevision = false;
                if ($cajaMejorasLista) {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM caja_revision_diferencias WHERE caja_id=:caja AND estado=\'PENDIENTE\'');
                    $stmt->execute(['caja' => $cajaId]);
                    $pendienteRevision = (int)$stmt->fetchColumn() > 0;
                }
                $_SESSION['ventas_flash'] = $pendienteRevision
                    ? 'Caja cerrada; la diferencia quedó pendiente de revisión y autorización administrativa.'
                    : ($conciliacionLista ? 'Caja cerrada y conciliada por cada medio de pago.'
                        : 'Caja cerrada con conciliación de efectivo. La conciliación de transferencias y tarjetas queda pendiente de instalar.');
                header('Location: /ventas.php'); exit;
            }
            if ($accion === 'movimiento_caja') {
                corozao_exigir_permiso($pdo, 'cash.movements.manage');
                $cajaId = filter_var($_POST['caja_id'] ?? null, FILTER_VALIDATE_INT);
                $tipo = $_POST['tipo_movimiento_caja'] ?? '';
                $monto = $_POST['monto_movimiento_caja'] ?? '';
                $motivo = $_POST['motivo_movimiento_caja'] ?? '';
                $clave = $_POST['clave_idempotencia'] ?? '';
                if (!$cajaId || !is_string($tipo) || !is_string($monto) || !is_string($motivo) || !is_string($clave)) {
                    throw new DomainException('Completa los datos del movimiento autorizado.');
                }
                $stmt = $pdo->prepare('SELECT bodega_id FROM cajas WHERE caja_id=:caja');
                $stmt->execute(['caja' => $cajaId]);
                $bodegaMovimiento = $stmt->fetchColumn();
                if (!$bodegaMovimiento) throw new DomainException('La caja seleccionada no existe.');
                corozao_exigir_bodega($pdo, $usuario, (int)$bodegaMovimiento);
                corozao_caja_movimiento_registrar($pdo, (int)$cajaId, (int)$usuario['usuario_id'], $tipo, $monto, $motivo, $clave);
                $_SESSION['ventas_flash'] = 'Movimiento de caja registrado y autorizado por el administrador.';
                header('Location: /ventas.php'); exit;
            }
            if ($accion === 'resolver_diferencia_caja') {
                $revisionId = filter_var($_POST['caja_revision_id'] ?? null, FILTER_VALIDATE_INT);
                $decision = $_POST['decision'] ?? '';
                $observacionesRevision = $_POST['observaciones_revision'] ?? '';
                if (!$revisionId || !is_string($decision) || !is_string($observacionesRevision)) throw new DomainException('Los datos de revisión no son válidos.');
                corozao_caja_diferencia_resolver($pdo, (int)$revisionId, (int)$usuario['usuario_id'], $decision, $observacionesRevision);
                $_SESSION['ventas_flash'] = $decision === 'APROBADA' ? 'Diferencia de caja aprobada.' : 'Diferencia de caja rechazada y documentada.';
                header('Location: /ventas.php'); exit;
            }
            if ($accion === 'cobrar') {
                corozao_exigir_permiso($pdo, 'orders.convert_to_sale');
                corozao_exigir_permiso($pdo, 'sales.record_payment');
                $pedidoId = filter_var($_POST['pedido_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$pedidoId) throw new DomainException('El pedido seleccionado no es válido.');
                if (($_POST['tipo_destinatario'] ?? '') === 'FACTURA') {
                    corozao_exigir_permiso($pdo, 'sales.invoice');
                }
                $stmt = $pdo->prepare('SELECT bodega_id FROM pedidos WHERE pedido_id = :id');
                $stmt->execute(['id' => $pedidoId]);
                $bodegaId = $stmt->fetchColumn();
                if (!$bodegaId) throw new DomainException('El pedido no existe.');
                corozao_exigir_bodega($pdo, $usuario, $bodegaId);
                $id = corozao_venta_cobrar($pdo, $pedidoId, (int) $usuario['usuario_id'], $_POST);
                $_SESSION['ventas_flash'] = 'Venta #' . $id . ' registrada. La factura electrónica queda pendiente para una fase posterior; no se envió al SRI.';
                header('Location: /ventas.php?venta=' . $id); exit;
            }
            if ($accion === 'cobrar_directa') {
                if (!corozao_ventas_directas_instaladas($pdo)) throw new DomainException('La estructura de ventas directas aún no está instalada.');
                $contexto = corozao_exigir_permiso($pdo, 'sales.direct.create');
                corozao_exigir_permiso($pdo, 'sales.record_payment');
                if (($_POST['tipo_destinatario'] ?? '') === 'FACTURA') corozao_exigir_permiso($pdo, 'sales.invoice');
                $bodegaId = filter_var($_POST['bodega_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$bodegaId || $bodegaId < 1) throw new DomainException('Selecciona una bodega válida.');
                corozao_exigir_bodega($pdo, $contexto, $bodegaId);
                $id = corozao_venta_cobrar_directa($pdo, $bodegaId, (int)$contexto['usuario_id'], $_POST);
                $_SESSION['ventas_flash'] = 'Venta directa #' . $id . ' registrada. La factura electrónica queda pendiente; no se envió al SRI.';
                header('Location: /ventas.php?venta=' . $id); exit;
            }
            throw new DomainException('La acción solicitada no es válida.');
        } catch (DomainException $e) { http_response_code(422); $error = $e->getMessage(); }
        catch (Throwable $e) { error_log('Corozao: error al registrar caja o venta.'); http_response_code(500); $error = 'No se pudo completar la operación; no se aplicaron cambios parciales.'; }
    }
}

if ($listo) {
    try {
        $stmt = $pdo->prepare('SELECT c.*, b.nombre AS bodega FROM cajas c INNER JOIN bodegas b ON b.bodega_id = c.bodega_id WHERE c.usuario_id = :usuario AND c.estado = \'ABIERTA\' ORDER BY c.fecha_apertura DESC');
        $stmt->execute(['usuario' => (int) $usuario['usuario_id']]); $cajas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cajas as $cajaAbierta) $resumenesCaja[(int)$cajaAbierta['caja_id']] = corozao_caja_resumen($pdo, (int)$cajaAbierta['caja_id']);
        if ($cajaMejorasLista && corozao_rol_puede((string)$usuario['rol'], 'cash.movements.manage')) {
            $stmt = $pdo->query('SELECT c.caja_id,c.estado,c.fecha_apertura,b.nombre AS bodega,u.nombre_completo AS cajero
                FROM cajas c INNER JOIN bodegas b ON b.bodega_id=c.bodega_id INNER JOIN usuarios u ON u.usuario_id=c.usuario_id
                WHERE c.estado=\'ABIERTA\' ORDER BY c.fecha_apertura');
            $cajasAbiertasAdmin = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->query('SELECT cr.caja_revision_id,cr.caja_id,cr.detalle_diferencias,cr.fecha_creacion,
                c.usuario_id,c.monto_apertura,c.monto_esperado,c.monto_declarado,c.diferencia,c.observaciones_cierre,
                b.nombre AS bodega,u.nombre_completo AS cajero
                FROM caja_revision_diferencias cr INNER JOIN cajas c ON c.caja_id=cr.caja_id
                INNER JOIN bodegas b ON b.bodega_id=c.bodega_id INNER JOIN usuarios u ON u.usuario_id=c.usuario_id
                WHERE cr.estado=\'PENDIENTE\' ORDER BY cr.fecha_creacion');
            $revisionesPendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($cajaMejorasLista) {
            $sqlMovimientos = 'SELECT cm.*,b.nombre AS bodega,u.nombre_completo AS responsable
                FROM caja_movimientos cm INNER JOIN cajas c ON c.caja_id=cm.caja_id
                INNER JOIN bodegas b ON b.bodega_id=c.bodega_id INNER JOIN usuarios u ON u.usuario_id=cm.autorizado_por_usuario_id';
            if (!corozao_rol_puede((string)$usuario['rol'], 'cash.movements.manage')) $sqlMovimientos .= ' WHERE c.usuario_id=:usuario';
            $sqlMovimientos .= ' ORDER BY cm.fecha_creacion DESC LIMIT 100';
            $stmt = $pdo->prepare($sqlMovimientos);
            if (!corozao_rol_puede((string)$usuario['rol'], 'cash.movements.manage')) $stmt->bindValue(':usuario', (int)$usuario['usuario_id'], PDO::PARAM_INT);
            $stmt->execute();
            $movimientosCaja = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $stmt = $pdo->prepare('SELECT v.venta_id, v.fecha_venta, v.nombre_destinatario, v.total, v.descuento_valor,
            fe.estado AS estado_factura,
            (SELECT GROUP_CONCAT(CONCAT(vp.medio, \': $\', CAST(vp.monto AS CHAR)) ORDER BY vp.medio SEPARATOR \' · \')
             FROM venta_pagos vp WHERE vp.venta_id=v.venta_id) AS pagos
            FROM ventas v LEFT JOIN facturas_electronicas fe ON fe.venta_id=v.venta_id
            WHERE v.usuario_id=:usuario ORDER BY v.fecha_venta DESC, v.venta_id DESC LIMIT 100');
        $stmt->execute(['usuario' => (int) $usuario['usuario_id']]); $ventasPropias = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($bodegas) {
            $sql = 'SELECT p.pedido_id, p.bodega_id, p.referencia_cliente, p.fecha_solicitud_cierre, b.nombre AS bodega,
                    (SELECT COUNT(*) FROM pedido_detalles d WHERE d.pedido_id = p.pedido_id) AS lineas,
                    (SELECT COALESCE(SUM(d.subtotal_linea),0) FROM pedido_detalles d WHERE d.pedido_id = p.pedido_id) AS total
                    FROM pedidos p INNER JOIN bodegas b ON b.bodega_id = p.bodega_id
                    WHERE p.estado = \'PENDIENTE_FACTURACION\' AND p.bodega_id IN (' . implode(',', array_fill(0, count($bodegas), '?')) . ')
                    ORDER BY p.fecha_solicitud_cierre, p.pedido_id LIMIT 100';
            $stmt = $pdo->prepare($sql); $stmt->execute(array_map(static fn($b) => (int) $b['bodega_id'], $bodegas)); $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) { error_log('Corozao: no se pudo cargar caja y pedidos pendientes.'); http_response_code(500); $error = 'No se pudo cargar la información de ventas.'; }
}
$pedidoCobrar = null;
$lineasPedidoCobrar = [];
$totalesPedidoCobrar = null;
if ($listo && $cobrarId) {
    $stmt = $pdo->prepare('SELECT p.pedido_id,p.bodega_id,p.referencia_cliente,b.nombre AS bodega FROM pedidos p INNER JOIN bodegas b ON b.bodega_id=p.bodega_id WHERE p.pedido_id=:id AND p.estado=\'PENDIENTE_FACTURACION\'');
    $stmt->execute(['id' => $cobrarId]); $pedidoCobrar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($pedidoCobrar) {
        corozao_exigir_bodega($pdo, $usuario, $pedidoCobrar['bodega_id']);
        $lineasPedidoCobrar = corozao_pedido_snapshot_detalles($pdo, (int)$pedidoCobrar['pedido_id']);
        $totalesPedidoCobrar = corozao_venta_calcular_totales($pdo, (int)$pedidoCobrar['pedido_id']);
    }
}
$claveVentaDirecta = venta_uuid();
$venta = null;
if ($listo && $ventaId) {
    $stmt = $pdo->prepare('SELECT v.*, c.nombre AS bodega, fe.estado AS estado_factura FROM ventas v INNER JOIN bodegas c ON c.bodega_id=v.bodega_id LEFT JOIN facturas_electronicas fe ON fe.venta_id=v.venta_id WHERE v.venta_id=:id AND v.usuario_id=:usuario');
    $stmt->execute(['id' => $ventaId, 'usuario' => (int) $usuario['usuario_id']]); $venta = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><title>Ventas y caja | Corozao</title>    <link rel="icon" type="image/svg+xml" href="/assets/img/brand/corozao-mark.svg?v=2">
    <link rel="stylesheet" href="/assets/css/brand.css">
</head>
<body data-corozao-role="<?= htmlspecialchars((string)($_SESSION['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bg-body-tertiary"><nav class="navbar bg-white border-bottom"><div class="container-xxl"><a class="navbar-brand fw-semibold" href="/">Corozao</a><div><span class="badge text-bg-primary"><?= venta_h($usuario['rol']) ?></span> <a class="btn btn-sm btn-outline-primary ms-2" href="/devoluciones.php">Devoluciones</a> <a class="btn btn-sm btn-outline-secondary" href="/">Inicio</a></div></div></nav>
<main class="container-xxl py-4"><header class="mb-4"><p class="text-primary fw-semibold mb-1">Operación</p><h1 class="h2">Ventas y caja</h1><p class="text-body-secondary">Cobra pedidos pendientes, registra pagos divididos y controla tu caja.</p></header>
<?php if ($mensaje): ?><div class="alert alert-success" role="status"><?= venta_h($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= venta_h($error) ?></div><?php endif; ?>
<?php if ($listo && corozao_rol_puede((string)$usuario['rol'], 'sales.direct.create') && !$pedidoCobrar && $ventasDirectasLista): ?><p><a class="btn btn-outline-primary" href="/ventas.php?directa=1">Iniciar venta directa</a></p><?php endif; ?>
<?php if (!$listo): ?><div class="alert alert-warning">El módulo aún no está habilitado. Aplica manualmente <code>migrations/20261026_ventas_caja.sql</code>; esta página no ejecuta migraciones.</div><?php else: ?>
<?php if (!$fechaOperativaLista): ?><div class="alert alert-warning" role="status">El cierre diario por fecha local aún no está activo. Aplica manualmente <code>migrations/20261027_fecha_operativa_caja.sql</code>; las cajas actuales no se modifican.</div><?php endif; ?>
<?php if (!$devolucionesLista): ?><div class="alert alert-warning" role="status">Las devoluciones aún no están activas. Aplica manualmente <code>migrations/20261028_devoluciones.sql</code>; esta página no ejecuta migraciones.</div><?php endif; ?>
<?php if (!$ventasDirectasLista && corozao_rol_puede((string)$usuario['rol'], 'sales.direct.create')): ?><div class="alert alert-warning" role="status">La venta directa requiere las migraciones <code>20261101_ventas_directas.sql</code> y <code>20261103_referencia_cliente_ventas.sql</code>. La venta directa permanece deshabilitada hasta instalarlas; no se ejecutan migraciones desde esta página.</div><?php endif; ?>
<?php if (!$cajaMejorasLista): ?><div class="alert alert-warning" role="status">Cobro de efectivo recibido/cambio, movimientos autorizados y revisión administrativa requieren aplicar manualmente <code>migrations/20261106_caja_cobros_revision.sql</code>. Los cobros mantienen el modo anterior hasta entonces.</div><?php endif; ?>
<?php if ($venta): ?><section class="alert alert-success"><h2 class="h5">Venta #<?= (int) $venta['venta_id'] ?> registrada</h2><p class="mb-1">Total cobrado: $<?= venta_h($venta['total']) ?> · Descuento fijo: $<?= venta_h($venta['descuento_valor']) ?></p><p>Destinatario: <?= venta_h($venta['nombre_destinatario']) ?> · Estado de estructura electrónica: <?= venta_h($venta['estado_factura'] ?? 'PENDIENTE') ?>. No se ha generado ni enviado un comprobante al SRI.</p><a class="btn btn-primary" target="_blank" rel="noopener" href="/comprobante_venta.php?venta=<?= (int)$venta['venta_id'] ?>">Imprimir comprobante interno</a></section><?php endif; ?>
<?php if ($listo && $fechaOperativaLista): foreach ($cajas as $caja): $fechaCaja = $caja['fecha_operativa'] === null ? null : (string)$caja['fecha_operativa']; if (!corozao_fecha_caja_es_hoy($fechaCaja, corozao_fecha_operativa_local())): ?><div class="alert alert-warning" role="alert">La caja #<?= (int)$caja['caja_id'] ?> de <?= venta_h($caja['bodega']) ?> pertenece a <?= venta_h($fechaCaja ?? 'una fecha histórica sin etiqueta') ?>. No admite nuevos cobros; ciérrala y abre una caja para el día actual.</div><?php endif; endforeach; endif; ?>
<section class="card shadow-sm mb-4">
<div class="card-header"><h2 class="h5 mb-0">Mis cajas abiertas</h2></div><div class="card-body">
<?php if (!$cajas): ?><p class="text-body-secondary">No tienes una caja abierta.</p><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= venta_h($csrf) ?>"><input type="hidden" name="accion" value="abrir_caja"><div class="col-12 col-md-6"><label class="form-label" for="bodega">Bodega</label><select class="form-select" id="bodega" name="bodega_id" required><option value="">Selecciona</option><?php foreach ($bodegas as $b): ?><option value="<?= (int)$b['bodega_id'] ?>"><?= venta_h($b['nombre']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><label class="form-label" for="monto-apertura">Fondo inicial de efectivo</label><input class="form-control" type="number" min="0" step="0.01" id="monto-apertura" name="monto_apertura" value="0.00" required></div><div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Abrir caja</button></div></form>
<?php else: ?><div class="row g-3">
<?php foreach ($cajas as $caja): $resumenCaja = $resumenesCaja[(int)$caja['caja_id']] ?? null; ?>
<div class="col-12"><article class="border rounded p-3 bg-white"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2"><div><strong><?= venta_h($caja['bodega']) ?></strong><p class="small text-body-secondary mb-1">Abierta <?= venta_h($caja['fecha_apertura']) ?> · Fondo inicial $<?= venta_h($caja['monto_apertura']) ?></p></div><span class="badge text-bg-success">ABIERTA</span></div>
<?php if ($resumenCaja): ?><div class="table-responsive mt-3"><table class="table table-sm table-bordered align-middle mb-3"><thead><tr><th>Resumen de operaciones</th><th class="text-end">Valor</th></tr></thead><tbody>
<tr><td>Ventas confirmadas</td><td class="text-end"><?= (int)$resumenCaja['operaciones'] ?> operaciones · $<?= number_format($resumenCaja['ventas_total']/100,2,'.',',') ?></td></tr>
<tr><td>Ventas con pagos divididos</td><td class="text-end"><?= (int)$resumenCaja['operaciones_divididas'] ?> operaciones · $<?= number_format($resumenCaja['total_dividido']/100,2,'.',',') ?></td></tr>
<?php foreach (['EFECTIVO'=>'Efectivo','TRANSFERENCIA'=>'Transferencias','TARJETA_CREDITO'=>'Tarjetas'] as $medio=>$etiqueta): ?><tr><td><?= venta_h($etiqueta) ?> cobradas</td><td class="text-end">$<?= number_format($resumenCaja['pagos'][$medio]/100,2,'.',',') ?></td></tr><?php if ($resumenCaja['reembolsos'][$medio] > 0): ?><tr><td>Devoluciones pagadas por <?= venta_h(strtolower($etiqueta)) ?></td><td class="text-end">− $<?= number_format($resumenCaja['reembolsos'][$medio]/100,2,'.',',') ?></td></tr><?php endif; ?><?php endforeach; ?>
<tr><td>Ingresos adicionales autorizados</td><td class="text-end">+ $<?= number_format($resumenCaja['ingresos']/100,2,'.',',') ?></td></tr><tr><td>Retiros autorizados</td><td class="text-end">− $<?= number_format($resumenCaja['retiros']/100,2,'.',',') ?></td></tr>
<?php foreach (['EFECTIVO'=>'Efectivo esperado (físico)','TRANSFERENCIA'=>'Transferencias esperadas','TARJETA_CREDITO'=>'Tarjetas esperadas'] as $medio=>$etiqueta): ?><tr class="fw-semibold"><td><?= venta_h($etiqueta) ?></td><td class="text-end">$<?= number_format($resumenCaja['esperados'][$medio]/100,2,'.',',') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php if (!$conciliacionLista): ?><div class="alert alert-warning small py-2">La conciliación por medios electrónicos requiere la migración <code>20261026_ventas_conteos_conciliacion.sql</code>.</div><?php endif; ?>
<form method="post" class="row g-2"><input type="hidden" name="csrf_token" value="<?= venta_h($csrf) ?>"><input type="hidden" name="accion" value="cerrar_caja"><input type="hidden" name="caja_id" value="<?= (int)$caja['caja_id'] ?>">
<?php $mediosCierre = $conciliacionLista ? ['EFECTIVO'=>'Efectivo contado físicamente','TRANSFERENCIA'=>'Transferencias verificadas','TARJETA_CREDITO'=>'Tarjetas verificadas'] : ['EFECTIVO'=>'Efectivo contado físicamente']; foreach ($mediosCierre as $medio=>$etiqueta): $esperadoCierre = $resumenCaja['esperados'][$medio] ?? 0; ?><div class="col-12 col-md-4"><label class="form-label" for="declarado-<?= (int)$caja['caja_id'] ?>-<?= $medio ?>"><?= venta_h($etiqueta) ?></label><div class="small text-body-secondary mb-1">Esperado: $<?= number_format($esperadoCierre/100,2,'.',',') ?></div><input class="form-control" type="number" min="0" step="0.01" id="declarado-<?= (int)$caja['caja_id'] ?>-<?= $medio ?>" name="declarados[<?= $medio ?>]" value="0.00" required></div><?php endforeach; ?>
<?php if ($devolucionesLista): foreach (['TRANSFERENCIA'=>'Transferencias reembolsadas verificadas','TARJETA_CREDITO'=>'Tarjetas reembolsadas verificadas'] as $medio=>$etiqueta): ?><div class="col-12 col-md-4"><label class="form-label" for="reembolso-<?= (int)$caja['caja_id'] ?>-<?= $medio ?>"><?= venta_h($etiqueta) ?></label><div class="small text-body-secondary mb-1">Esperado: $<?= number_format(($resumenCaja['reembolsos'][$medio] ?? 0)/100,2,'.',',') ?></div><input class="form-control" type="number" min="0" step="0.01" id="reembolso-<?= (int)$caja['caja_id'] ?>-<?= $medio ?>" name="reembolsos_declarados[<?= $medio ?>]" value="0.00" required></div><?php endforeach; endif; ?>
<div class="col-12 col-md-8"><label class="form-label" for="obs-<?= (int)$caja['caja_id'] ?>">Observación obligatoria si hay diferencia</label><input class="form-control" id="obs-<?= (int)$caja['caja_id'] ?>" name="observaciones_cierre" maxlength="500"></div><div class="col-12 col-md-4 d-flex align-items-end"><button class="btn btn-outline-primary w-100" type="submit"><?= $conciliacionLista ? 'Cerrar y conciliar' : 'Cerrar caja' ?></button></div></form>
</article></div><?php endforeach; ?></div><?php endif; ?></div></section>
<?php if ($cajaMejorasLista && corozao_rol_puede((string)$usuario['rol'], 'cash.movements.manage')): ?>
<section class="card shadow-sm mb-4"><div class="card-header"><h2 class="h5 mb-0">Ingresos y retiros autorizados</h2></div><div class="card-body"><p class="text-body-secondary">Solo el administrador puede registrarlos. Cada movimiento queda vinculado a la caja y a su auditoría.</p>
<?php if (!$cajasAbiertasAdmin): ?><p class="mb-0">No hay cajas abiertas donde registrar movimientos.</p><?php else: ?><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= venta_h($csrf) ?>"><input type="hidden" name="accion" value="movimiento_caja"><input type="hidden" name="clave_idempotencia" value="<?= venta_h(venta_uuid()) ?>"><div class="col-12 col-md-4"><label class="form-label" for="caja-mov">Caja abierta</label><select class="form-select" id="caja-mov" name="caja_id" required><option value="">Selecciona</option><?php foreach ($cajasAbiertasAdmin as $ca): ?><option value="<?= (int)$ca['caja_id'] ?>">#<?= (int)$ca['caja_id'] ?> · <?= venta_h($ca['bodega']) ?> · <?= venta_h($ca['cajero']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3"><label class="form-label" for="tipo-mov-caja">Tipo</label><select class="form-select" id="tipo-mov-caja" name="tipo_movimiento_caja"><option value="INGRESO">Ingreso autorizado</option><option value="RETIRO">Retiro autorizado</option></select></div><div class="col-12 col-md-2"><label class="form-label" for="monto-mov-caja">Importe</label><input class="form-control" id="monto-mov-caja" name="monto_movimiento_caja" type="number" min="0.01" step="0.01" required></div><div class="col-12 col-md-3"><label class="form-label" for="motivo-mov-caja">Motivo obligatorio</label><input class="form-control" id="motivo-mov-caja" name="motivo_movimiento_caja" maxlength="500" required></div><div class="col-12"><button class="btn btn-primary" type="submit">Registrar movimiento</button></div></form><?php endif; ?></div></section>
<?php endif; ?>
<?php if ($cajaMejorasLista && corozao_rol_puede((string)$usuario['rol'], 'cash.differences.review')): ?>
<section class="card shadow-sm mb-4"><div class="card-header"><h2 class="h5 mb-0">Diferencias pendientes de autorización administrativa</h2></div><div class="card-body">
<?php if (!$revisionesPendientes): ?><p class="mb-0">No hay diferencias pendientes de revisión.</p><?php else: foreach ($revisionesPendientes as $revision): $detalleRevision = json_decode((string)$revision['detalle_diferencias'], true) ?: []; ?>
<article class="border rounded p-3 mb-3"><h3 class="h6">Caja #<?= (int)$revision['caja_id'] ?> · <?= venta_h($revision['bodega']) ?> · <?= venta_h($revision['cajero']) ?></h3><p class="small text-body-secondary">Cerrada <?= venta_h($revision['fecha_creacion']) ?> · Motivo: <?= venta_h($revision['observaciones_cierre'] ?? '—') ?></p><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Medio</th><th>Esperado</th><th>Declarado</th><th>Diferencia</th></tr></thead><tbody><?php foreach (($detalleRevision['medios'] ?? []) as $medio=>$valores): ?><tr><td><?= venta_h(str_replace('_',' ',$medio)) ?></td><td>$<?= venta_h($valores['esperado']) ?></td><td>$<?= venta_h($valores['declarado']) ?></td><td>$<?= venta_h($valores['diferencia']) ?></td></tr><?php endforeach; ?><?php foreach (($detalleRevision['reembolsos'] ?? []) as $medio=>$valores): ?><tr><td>Reembolso <?= venta_h(str_replace('_',' ',$medio)) ?></td><td>$<?= venta_h($valores['esperado']) ?></td><td>$<?= venta_h($valores['declarado']) ?></td><td>$<?= venta_h($valores['diferencia']) ?></td></tr><?php endforeach; ?></tbody></table></div><form method="post" class="row g-2"><input type="hidden" name="csrf_token" value="<?= venta_h($csrf) ?>"><input type="hidden" name="accion" value="resolver_diferencia_caja"><input type="hidden" name="caja_revision_id" value="<?= (int)$revision['caja_revision_id'] ?>"><div class="col-12 col-md-7"><label class="form-label" for="rev-obs-<?= (int)$revision['caja_revision_id'] ?>">Observaciones de revisión (obligatorias para rechazo)</label><input class="form-control" id="rev-obs-<?= (int)$revision['caja_revision_id'] ?>" name="observaciones_revision" maxlength="500"></div><div class="col-12 col-md-5 d-flex align-items-end gap-2"><button class="btn btn-primary" name="decision" value="APROBADA">Aprobar diferencia</button><button class="btn btn-outline-danger" name="decision" value="RECHAZADA">Rechazar</button></div></form></article>
<?php endforeach; endif; ?></div></section>
<?php endif; ?>
<?php if ($cajaMejorasLista && $movimientosCaja): ?><section class="card shadow-sm mb-4"><div class="card-header"><h2 class="h5 mb-0">Movimientos adicionales de caja</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Fecha</th><th>Caja / bodega</th><th>Tipo</th><th>Motivo</th><th>Autorizó</th><th class="text-end">Importe</th></tr></thead><tbody><?php foreach ($movimientosCaja as $mov): ?><tr><td><?= venta_h($mov['fecha_creacion']) ?></td><td>#<?= (int)$mov['caja_id'] ?> · <?= venta_h($mov['bodega']) ?></td><td><?= venta_h($mov['tipo']) ?></td><td><?= venta_h($mov['motivo']) ?></td><td><?= venta_h($mov['responsable']) ?></td><td class="text-end">$<?= venta_h($mov['monto']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php if ($pedidoCobrar): ?><section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Cobrar pedido #<?= (int)$pedidoCobrar['pedido_id'] ?> · <?= venta_h($pedidoCobrar['bodega']) ?></h2></div><div class="card-body"><p class="text-body-secondary">El total definitivo se recalcula y el inventario se verifica al confirmar. El descuento es un valor fijo; los pagos deben sumar exactamente el total neto.</p><div class="table-responsive mb-3"><table class="table table-sm table-bordered"><thead class="table-light"><tr><th>Producto / contenido / presentación</th><th>Cantidad</th><th>Precio unitario</th><th>IVA</th><th>Total referencial</th></tr></thead><tbody><?php foreach ($lineasPedidoCobrar as $linea): $bruto = (float)$linea['precio_unitario_presentacion'] * (float)$linea['cantidad_presentacion']; if ((int)$linea['precio_incluye_iva'] !== 1) $bruto *= 1 + (float)$linea['tarifa_iva'] / 100; ?><tr><td><?= venta_h(implode(' · ', array_filter([$linea['codigos'] ?? '', $linea['producto'] ?? '', $linea['marca'] ?? '', $linea['contenido'] ?? '', $linea['categoria'] ?? '', $linea['presentacion'] ?? ''])) ) ?></td><td><?= venta_h($linea['cantidad_presentacion']) ?></td><td>$<?= number_format((float)$linea['precio_unitario_presentacion'], 2, '.', ',') ?></td><td><?= venta_h($linea['tarifa_iva']) ?>%</td><td>$<?= number_format($bruto,2,'.','') ?></td></tr><?php endforeach; ?></tbody></table></div><form method="post" class="row g-3" id="form-cobro" data-cobro-form data-total-centavos="<?= (int)($totalesPedidoCobrar['bruto'] ?? 0) ?>"><input type="hidden" name="csrf_token" value="<?= venta_h($csrf) ?>"><input type="hidden" name="accion" value="cobrar"><input type="hidden" name="pedido_id" value="<?= (int)$pedidoCobrar['pedido_id'] ?>">
<div class="col-12"><fieldset><legend class="h6">¿Desea factura con datos del cliente?</legend><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="tipo_destinatario" id="factura-si" value="FACTURA" checked><label class="form-check-label" for="factura-si">Sí, registrar datos</label></div><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="tipo_destinatario" id="factura-no" value="CONSUMIDOR_FINAL"><label class="form-check-label" for="factura-no">No, consumidor final</label></div></fieldset></div>
<div class="col-12 col-md-4 campo-factura"><label class="form-label" for="tipo-id">Tipo identificación</label><select class="form-select" id="tipo-id" name="tipo_destinatario_detalle"><option value="RUC">RUC</option><option value="CEDULA" selected>Cédula</option><option value="PASAPORTE">Pasaporte</option></select></div>
<div class="col-12 col-md-4 campo-factura"><label class="form-label" for="identificacion">Identificación</label><input class="form-control" id="identificacion" name="identificacion_destinatario" maxlength="20" required></div><div class="col-12 col-md-4 campo-factura"><label class="form-label" for="nombre">Nombre / razón social</label><input class="form-control" id="nombre" name="nombre_destinatario" maxlength="300" required></div><div class="col-12 col-md-6 campo-factura"><label class="form-label" for="correo">Correo electrónico (opcional)</label><input class="form-control" type="email" id="correo" name="correo_destinatario" maxlength="150"></div><div class="col-12 col-md-6 campo-factura"><label class="form-label" for="direccion">Dirección (opcional)</label><input class="form-control" id="direccion" name="direccion_destinatario" maxlength="300"></div>
<div class="col-12 col-md-4"><label class="form-label" for="descuento">Descuento fijo ($)</label><input class="form-control" type="number" min="0" step="0.01" id="descuento" name="descuento_valor" value="0.00" required></div><?php foreach (['EFECTIVO'=>'Efectivo','TRANSFERENCIA'=>'Transferencia','TARJETA_CREDITO'=>'Tarjeta de crédito'] as $key=>$label): ?><div class="col-12 col-md-4"><label class="form-label" for="pago-<?= $key ?>"><?= $label ?> ($)</label><input class="form-control" type="number" min="0" step="0.01" id="pago-<?= $key ?>" name="pagos[<?= $key ?>]" value="0.00"><label class="form-label mt-2" for="ref-<?= $key ?>">Referencia (si aplica)</label><input class="form-control" id="ref-<?= $key ?>" name="referencias[<?= $key ?>]" maxlength="100"></div><?php endforeach; ?>
<?php if ($cajaMejorasLista): ?><div class="col-12 col-lg-6" data-cash-tender hidden><label class="form-label" for="efectivo-recibido">Efectivo recibido del cliente</label><input class="form-control" id="efectivo-recibido" name="efectivo_recibido" type="number" min="0" step="0.01" value="0.00"><div class="small mt-2">Cambio a entregar: <strong data-cash-change>$0.00</strong></div></div><div class="col-12 col-lg-6"><div class="small">Pendiente de pago: <strong data-amount-due>$0.00</strong></div><div class="small" data-cobro-status role="status" aria-live="polite"></div></div><?php else: ?><div class="col-12"><div class="alert alert-warning small">Para registrar efectivo recibido y cambio, aplica <code>migrations/20261106_caja_cobros_revision.sql</code>.</div></div><?php endif; ?>
<div class="col-12"><div class="alert alert-info small mb-0">El registro deja lista la estructura de factura electrónica en estado pendiente. En esta fase no se genera XML, firma ni comunicación con el SRI.</div></div><div class="col-12"><button class="btn btn-primary" data-cobro-submit type="submit">Confirmar cobro</button><a class="btn btn-outline-secondary" href="/ventas.php">Cancelar</a></div></form></div></section><?php elseif ($cobrarId): ?><div class="alert alert-warning">El pedido no está pendiente o no está asignado a tus bodegas.</div><?php endif; ?>
<?php if (!$pedidoCobrar && $ventasDirectasLista && isset($_GET['directa']) && corozao_rol_puede((string)$usuario['rol'], 'sales.direct.create')): ?>
<section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Venta directa</h2></div><div class="card-body"><p class="text-body-secondary">Los precios, impuestos y saldos se verifican nuevamente en el servidor al confirmar.</p>
<form method="post" action="/ventas.php?directa=1" class="row g-3" id="form-venta-directa" data-cobro-form data-total-centavos="0"><input type="hidden" name="csrf_token" value="<?= venta_h($csrf) ?>"><input type="hidden" name="accion" value="cobrar_directa"><input type="hidden" name="clave_idempotencia" value="<?= venta_h($claveVentaDirecta) ?>">
<div class="col-12 col-md-6"><label class="form-label" for="bodega-venta-directa">Bodega autorizada</label><select class="form-select" id="bodega-venta-directa" name="bodega_id" data-product-warehouse required><option value="">Selecciona</option><?php foreach ($bodegas as $b): ?><option value="<?= (int)$b['bodega_id'] ?>"><?= venta_h($b['nombre']) ?></option><?php endforeach; ?></select></div>
<div class="col-12 col-md-6"><label class="form-label" for="direct-referencia-cliente">Cliente o referencia</label><input class="form-control" id="direct-referencia-cliente" name="referencia_cliente" maxlength="100" placeholder="Nombre del cliente o referencia de la venta"><div class="form-text">Opcional; aparecerá en el comprobante interno.</div></div>
<div class="col-12" data-product-selector data-operation="venta_directa"><label class="form-label" for="buscar-producto-venta">Buscar producto, código o presentación</label><input class="form-control" id="buscar-producto-venta" data-product-query autocomplete="off" aria-controls="resultados-venta" placeholder="Escribe al menos dos caracteres"><div id="resultados-venta" data-product-results class="list-group mt-1" role="listbox" hidden></div>
<div class="row g-2 align-items-end mt-2"><div class="col-12 col-lg-7"><label class="form-label" for="seleccionado-venta">Presentación seleccionada</label><div class="form-control bg-body-secondary" id="seleccionado-venta">Selecciona un resultado</div></div><div class="col-6 col-lg-3"><label class="form-label" for="cantidad-venta">Cantidad</label><input class="form-control" id="cantidad-venta" type="number" min="1" step="1"></div><div class="col-6 col-lg-2"><button class="btn btn-outline-primary w-100" type="button" id="agregar-linea-venta">Agregar</button></div></div></div>
<div class="col-12"><div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead class="table-light"><tr><th>Producto / presentación</th><th>Cantidad</th><th>Precio</th><th>IVA</th><th>Subtotal</th><th>Total</th><th></th></tr></thead><tbody id="lineas-venta-directa"></tbody><tfoot><tr><th colspan="5" class="text-end">Total referencial</th><th id="total-venta-directa">$0.00</th><th></th></tr></tfoot></table></div></div>
<div class="col-12"><fieldset><legend class="h6">¿Desea factura con datos del cliente?</legend><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="tipo_destinatario" id="directa-factura-si" value="FACTURA"><label class="form-check-label" for="directa-factura-si">Sí, registrar datos</label></div><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="tipo_destinatario" id="directa-factura-no" value="CONSUMIDOR_FINAL" checked><label class="form-check-label" for="directa-factura-no">No, consumidor final</label></div></fieldset></div>
<div class="col-12 col-md-4 direct-billing" hidden><label class="form-label" for="direct-tipo-id">Tipo identificación</label><select class="form-select" id="direct-tipo-id" name="tipo_destinatario_detalle"><option value="RUC">RUC</option><option value="CEDULA" selected>Cédula</option><option value="PASAPORTE">Pasaporte</option></select></div><div class="col-12 col-md-4 direct-billing" hidden><label class="form-label" for="direct-identificacion">Identificación</label><input class="form-control" id="direct-identificacion" name="identificacion_destinatario" maxlength="20"></div><div class="col-12 col-md-4 direct-billing" hidden><label class="form-label" for="direct-nombre">Nombre / razón social</label><input class="form-control" id="direct-nombre" name="nombre_destinatario" maxlength="300"></div><div class="col-12 col-md-6 direct-billing" hidden><label class="form-label" for="direct-correo">Correo electrónico</label><input class="form-control" id="direct-correo" type="email" name="correo_destinatario" maxlength="150"></div><div class="col-12 col-md-6 direct-billing" hidden><label class="form-label" for="direct-direccion">Dirección</label><input class="form-control" id="direct-direccion" name="direccion_destinatario" maxlength="300"></div>
<div class="col-12 col-md-4"><label class="form-label" for="direct-descuento">Descuento fijo ($)</label><input class="form-control" type="number" min="0" step="0.01" id="direct-descuento" name="descuento_valor" value="0.00" required></div><?php foreach (['EFECTIVO'=>'Efectivo','TRANSFERENCIA'=>'Transferencia','TARJETA_CREDITO'=>'Tarjeta de crédito'] as $key=>$label): ?><div class="col-12 col-md-4"><label class="form-label" for="direct-pago-<?= $key ?>"><?= venta_h($label) ?> ($)</label><input class="form-control" type="number" min="0" step="0.01" id="direct-pago-<?= $key ?>" name="pagos[<?= $key ?>]" value="0.00"><label class="form-label mt-2" for="direct-ref-<?= $key ?>">Referencia (si aplica)</label><input class="form-control" id="direct-ref-<?= $key ?>" name="referencias[<?= $key ?>]" maxlength="100"></div><?php endforeach; ?>
<?php if ($cajaMejorasLista): ?><div class="col-12 col-lg-6" data-cash-tender hidden><label class="form-label" for="direct-efectivo-recibido">Efectivo recibido del cliente</label><input class="form-control" id="direct-efectivo-recibido" name="efectivo_recibido" type="number" min="0" step="0.01" value="0.00"><div class="small mt-2">Cambio a entregar: <strong data-cash-change>$0.00</strong></div></div><div class="col-12 col-lg-6"><div class="small">Pendiente de pago: <strong data-amount-due>$0.00</strong></div><div class="small" data-cobro-status role="status" aria-live="polite"></div></div><?php else: ?><div class="col-12"><div class="alert alert-warning small">Para registrar efectivo recibido y cambio, aplica <code>migrations/20261106_caja_cobros_revision.sql</code>.</div></div><?php endif; ?>
<div class="col-12"><div class="alert alert-info small mb-0">La venta y su movimiento, pagos y estructura pendiente de facturación se confirman en una transacción. La integración SRI se implementará después.</div></div><div class="col-12"><button class="btn btn-primary" data-cobro-submit type="submit">Confirmar venta</button><a class="btn btn-outline-secondary" href="/ventas.php">Cancelar</a></div></form></div></section>
<script src="/assets/product-selector.js" defer></script><script>
document.addEventListener('DOMContentLoaded',()=>{const root=document.querySelector('[data-product-selector][data-operation="venta_directa"]'),body=document.getElementById('lineas-venta-directa');let chosen=null,next=0;const selected=document.getElementById('seleccionado-venta'),qty=document.getElementById('cantidad-venta');root?.addEventListener('product:selected',e=>{chosen=e.detail;selected.textContent=[chosen.codigos,chosen.producto,chosen.marca,chosen.contenido,chosen.categoria,chosen.presentacion,`$${Number(chosen.precio_venta).toFixed(2)} · IVA ${chosen.tarifa_iva}%`].filter(Boolean).join(' · ');qty.value='1';qty.focus();});const update=()=>{let sum=0;body.querySelectorAll('tr').forEach(tr=>{const n=Number(tr.querySelector('input[name$="[cantidad]"]').value||0),price=Number(tr.dataset.price),rate=Number(tr.dataset.iva),gross=price*n*(tr.dataset.includes==='1'?1:1+rate/100),net=tr.dataset.includes==='1'?gross/(1+rate/100):price*n;tr.querySelector('[data-net]').textContent=`$${net.toFixed(2)}`;tr.querySelector('[data-gross]').textContent=`$${gross.toFixed(2)}`;sum+=gross;});document.getElementById('total-venta-directa').textContent=`$${sum.toFixed(2)}`;body.dispatchEvent(new CustomEvent('corozao:total',{bubbles:true,detail:{centavos:Math.round(sum*100)}}));};document.getElementById('agregar-linea-venta').addEventListener('click',()=>{if(!chosen||!qty.value){alert('Selecciona un producto e indica la cantidad.');return;}if([...body.querySelectorAll('input[name$="[presentacion_id]"]')].some(i=>i.value===String(chosen.presentacion_id))){alert('Esta presentación ya está agregada.');return;}const p=chosen,i=next++,tr=document.createElement('tr');tr.dataset.price=p.precio_venta;tr.dataset.iva=p.tarifa_iva;tr.dataset.includes=p.precio_incluye_iva;const desc=document.createElement('td');desc.textContent=[p.codigos,p.producto,p.marca,p.contenido,p.categoria,p.presentacion].filter(Boolean).join(' · ');const id=document.createElement('input');id.type='hidden';id.name=`lineas[${i}][presentacion_id]`;id.value=p.presentacion_id;desc.append(id);const qc=document.createElement('td'),q=document.createElement('input');q.type='number';q.min='1';q.step='1';q.required=true;q.name=`lineas[${i}][cantidad]`;q.value=qty.value;q.className='form-control form-control-sm';qc.append(q);const price=document.createElement('td');price.textContent=`$${Number(p.precio_venta).toFixed(2)}`;const tax=document.createElement('td');tax.textContent=`${p.tarifa_iva}%`;const net=document.createElement('td');net.dataset.net='';const gross=document.createElement('td');gross.dataset.gross='';const action=document.createElement('td'),remove=document.createElement('button');remove.type='button';remove.className='btn btn-sm btn-outline-danger';remove.textContent='Quitar';remove.addEventListener('click',()=>{tr.remove();update();});action.append(remove);tr.append(desc,qc,price,tax,net,gross,action);body.append(tr);chosen=null;selected.textContent='Selecciona un resultado';qty.value='';update();});body.addEventListener('input',update);document.querySelectorAll('input[name="tipo_destinatario"]').forEach(r=>r.addEventListener('change',()=>{const visible=document.getElementById('directa-factura-si').checked;document.querySelectorAll('.direct-billing').forEach(el=>{el.hidden=!visible;el.querySelectorAll('input').forEach(i=>i.required=visible);});}));});
</script>
<script>
(() => {
    const key = 'corozao_venta_directa_borrador';
    if (new URLSearchParams(location.search).has('venta')) sessionStorage.removeItem(key);
    const form = document.getElementById('form-venta-directa');
    const body = document.getElementById('lineas-venta-directa');
    if (!form || !body) return;
    const restore = () => {
        let draft;
        try { draft = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) { draft = null; }
        if (!draft || !Array.isArray(draft.rows)) return;
        Object.entries(draft.fields || {}).forEach(([name, value]) => {
            const field = form.elements.namedItem(name);
            if (!field) return;
            if (field instanceof RadioNodeList) [...field].forEach(option => { option.checked = option.value === value; });
            else field.value = value;
        });
        form.querySelector('input[name="tipo_destinatario"]:checked')?.dispatchEvent(new Event('change', {bubbles:true}));
        const factura = form.querySelector('#directa-factura-si')?.checked;
        form.querySelectorAll('.direct-billing').forEach(el => { el.hidden = !factura; el.querySelectorAll('input').forEach(input => input.required = !!factura); });
        draft.rows.forEach((item, index) => {
            if (!item || !item.presentacion || !item.descripcion) return;
            const tr = document.createElement('tr');
            tr.dataset.price = item.precio; tr.dataset.iva = item.iva; tr.dataset.includes = item.incluye;
            const desc = document.createElement('td'); desc.append(document.createTextNode(item.descripcion));
            const id = document.createElement('input'); id.type = 'hidden'; id.name = `lineas[${index}][presentacion_id]`; id.value = item.presentacion; desc.append(id);
            const qc = document.createElement('td'), q = document.createElement('input');
            q.type = 'number'; q.min = '1'; q.step = '1'; q.required = true; q.name = `lineas[${index}][cantidad]`;
            q.value = item.cantidad; q.className = 'form-control form-control-sm'; qc.append(q);
            const price = document.createElement('td'); price.textContent = `$${Number(item.precio).toFixed(2)}`;
            const tax = document.createElement('td'); tax.textContent = `${item.iva}%`;
            const net = document.createElement('td'); net.dataset.net = '';
            const gross = document.createElement('td'); gross.dataset.gross = '';
            const action = document.createElement('td'), remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'btn btn-sm btn-outline-danger'; remove.textContent = 'Quitar';
            remove.addEventListener('click', () => { tr.remove(); body.dispatchEvent(new Event('input', {bubbles:true})); });
            action.append(remove); tr.append(desc, qc, price, tax, net, gross, action); body.append(tr);
        });
        body.dispatchEvent(new Event('input', {bubbles:true}));
    };
    document.addEventListener('DOMContentLoaded', restore, {once:true});
    form.addEventListener('submit', () => {
        const rows = [...body.querySelectorAll('tr')].map(tr => ({
            presentacion: tr.querySelector('input[name$="[presentacion_id]"]')?.value,
            cantidad: tr.querySelector('input[name$="[cantidad]"]')?.value,
            precio: tr.dataset.price, iva: tr.dataset.iva, incluye: tr.dataset.includes,
            descripcion: tr.querySelector('td')?.firstChild?.textContent?.trim()
        }));
        const fields = {};
        new FormData(form).forEach((value, name) => {
            if (['csrf_token','accion','clave_idempotencia'].includes(name) || name.startsWith('lineas[') || name === 'tipo_destinatario') return;
            if (typeof value === 'string') fields[name] = value;
        });
        fields.tipo_destinatario = form.querySelector('input[name="tipo_destinatario"]:checked')?.value || 'CONSUMIDOR_FINAL';
        sessionStorage.setItem(key, JSON.stringify({rows, fields}));
    });
    form.querySelector('a[href="/ventas.php"]')?.addEventListener('click', () => sessionStorage.removeItem(key));
})();
</script>
<?php endif; ?>
<section class="card shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Pedidos pendientes de cobro</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Pedido</th><th>Bodega</th><th>Referencia</th><th>Líneas</th><th>Total referencial</th><th></th></tr></thead><tbody><?php foreach ($pedidos as $p): ?><tr><td>#<?= (int)$p['pedido_id'] ?><br><small><?= venta_h($p['fecha_solicitud_cierre']) ?></small></td><td><?= venta_h($p['bodega']) ?></td><td><?= venta_h($p['referencia_cliente'] ?? '—') ?></td><td><?= (int)$p['lineas'] ?></td><td>$<?= venta_h($p['total']) ?></td><td><a class="btn btn-sm btn-primary" href="/ventas.php?cobrar=<?= (int)$p['pedido_id'] ?>">Cobrar</a></td></tr><?php endforeach; ?><?php if (!$pedidos): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">No hay pedidos pendientes de cobro.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card shadow-sm mt-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Mis ventas recientes</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Venta / fecha</th><th>Destinatario</th><th>Pagos</th><th>Descuento fijo</th><th>Total</th><th>Factura electrónica</th><th>Comprobante</th></tr></thead><tbody><?php foreach ($ventasPropias as $v): ?><tr><td>#<?= (int)$v['venta_id'] ?><br><small><?= venta_h($v['fecha_venta']) ?></small></td><td><?= venta_h($v['nombre_destinatario']) ?></td><td><?= venta_h($v['pagos'] ?? '—') ?></td><td>$<?= venta_h($v['descuento_valor']) ?></td><td>$<?= venta_h($v['total']) ?></td><td><?= venta_h($v['estado_factura'] ?? 'PENDIENTE') ?></td><td><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="/comprobante_venta.php?venta=<?= (int)$v['venta_id'] ?>">Imprimir</a></td></tr><?php endforeach; ?><?php if (!$ventasPropias): ?><tr><td colspan="7" class="text-center text-body-secondary py-4">Todavía no has registrado ventas.</td></tr><?php endif; ?></tbody></table></div><div class="card-footer small text-body-secondary">La consulta está limitada a ventas creadas por tu usuario. Los comprobantes impresos son internos; la integración electrónica queda pendiente.</div></section>
<?php endif; ?></main><?php if ($devolucionesLista): ?><script>
document.querySelectorAll('form').forEach(form => {
  if (form.querySelector('input[name="accion"][value="cerrar_caja"]')) {
    const cajaId = form.querySelector('input[name="caja_id"]')?.value || '0';
    const boton = form.querySelector('button[type="submit"]')?.parentElement;
    ['TRANSFERENCIA', 'TARJETA_CREDITO'].forEach((medio) => {
      const col = document.createElement('div'); col.className = 'col-12 col-md-6';
      const label = document.createElement('label'); label.className = 'form-label';
      label.htmlFor = `reembolso-${cajaId}-${medio}`;
      label.textContent = medio === 'TRANSFERENCIA' ? 'Transferencias reembolsadas' : 'Tarjetas reembolsadas';
      const input = document.createElement('input'); input.className = 'form-control'; input.type = 'number';
      input.min = '0'; input.step = '0.01'; input.required = true; input.value = '0.00';
      input.id = label.htmlFor; input.name = `reembolsos_declarados[${medio}]`;
      col.append(label, input); form.insertBefore(col, boton);
    });
  }
});
</script><?php endif; ?><script>
const opts = document.querySelectorAll('input[name="tipo_destinatario"]');
const campos = document.querySelectorAll('.campo-factura');
function alternarFactura(){const factura=document.querySelector('#factura-si')?.checked; campos.forEach(c=>{c.hidden=!factura;c.querySelectorAll('input,select').forEach(i=>{i.disabled=!factura;i.required=factura&&['identificacion_destinatario','nombre_destinatario'].includes(i.name)});});}
opts.forEach(o=>o.addEventListener('change',alternarFactura)); alternarFactura();
</script><script>if(!new URLSearchParams(location.search).has('directa'))sessionStorage.removeItem('corozao_venta_directa_borrador');</script><script src="/assets/cobros.js" defer></script>
<script src="/assets/brand.js" defer></script>
</body></html>
