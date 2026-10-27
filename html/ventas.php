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
require_once __DIR__ . '/includes/ventas.php';

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
$mensaje = $_SESSION['ventas_flash'] ?? null;
unset($_SESSION['ventas_flash']);
$bodegas = corozao_bodegas_asignadas($pdo, $usuario);
$cajas = [];
$pedidos = [];
$ventasPropias = [];
$cobrarId = filter_var($_GET['cobrar'] ?? null, FILTER_VALIDATE_INT) ?: null;
$ventaId = filter_var($_GET['venta'] ?? null, FILTER_VALIDATE_INT) ?: null;

try { $listo = corozao_ventas_instaladas($pdo); }
catch (Throwable $e) { error_log('Corozao: no se pudo validar el esquema de ventas.'); http_response_code(503); $error = 'No se pudo validar el esquema de ventas.'; }

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
                $declarado = $_POST['monto_declarado'] ?? '';
                $observaciones = $_POST['observaciones_cierre'] ?? '';
                if (!$cajaId || !is_string($declarado) || !is_string($observaciones)) throw new DomainException('Los datos de cierre no son válidos.');
                corozao_caja_cerrar($pdo, $cajaId, (int) $usuario['usuario_id'], $declarado, $observaciones);
                $_SESSION['ventas_flash'] = 'Caja cerrada y diferencia registrada.';
                header('Location: /ventas.php'); exit;
            }
            if ($accion === 'cobrar') {
                corozao_exigir_permiso($pdo, 'orders.convert_to_sale');
                corozao_exigir_permiso($pdo, 'sales.record_payment');
                $pedidoId = filter_var($_POST['pedido_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$pedidoId) throw new DomainException('El pedido seleccionado no es válido.');
                $stmt = $pdo->prepare('SELECT bodega_id FROM pedidos WHERE pedido_id = :id');
                $stmt->execute(['id' => $pedidoId]);
                $bodegaId = $stmt->fetchColumn();
                if (!$bodegaId) throw new DomainException('El pedido no existe.');
                corozao_exigir_bodega($pdo, $usuario, $bodegaId);
                $id = corozao_venta_cobrar($pdo, $pedidoId, (int) $usuario['usuario_id'], $_POST);
                $_SESSION['ventas_flash'] = 'Venta #' . $id . ' registrada. La factura electrónica queda pendiente para una fase posterior; no se envió al SRI.';
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
if ($listo && $cobrarId) {
    $stmt = $pdo->prepare('SELECT p.pedido_id,p.bodega_id,p.referencia_cliente,b.nombre AS bodega FROM pedidos p INNER JOIN bodegas b ON b.bodega_id=p.bodega_id WHERE p.pedido_id=:id AND p.estado=\'PENDIENTE_FACTURACION\'');
    $stmt->execute(['id' => $cobrarId]); $pedidoCobrar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($pedidoCobrar) corozao_exigir_bodega($pdo, $usuario, $pedidoCobrar['bodega_id']);
}
$venta = null;
if ($listo && $ventaId) {
    $stmt = $pdo->prepare('SELECT v.*, c.nombre AS bodega, fe.estado AS estado_factura FROM ventas v INNER JOIN bodegas c ON c.bodega_id=v.bodega_id LEFT JOIN facturas_electronicas fe ON fe.venta_id=v.venta_id WHERE v.venta_id=:id AND v.usuario_id=:usuario');
    $stmt->execute(['id' => $ventaId, 'usuario' => (int) $usuario['usuario_id']]); $venta = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><title>Ventas y caja | Corozao</title></head>
<body class="bg-body-tertiary"><nav class="navbar bg-white border-bottom"><div class="container-xxl"><a class="navbar-brand fw-semibold" href="/">Corozao</a><div><span class="badge text-bg-primary"><?= venta_h($usuario['rol']) ?></span> <a class="btn btn-sm btn-outline-secondary ms-2" href="/">Inicio</a></div></div></nav>
<main class="container-xxl py-4"><header class="mb-4"><p class="text-primary fw-semibold mb-1">Operación</p><h1 class="h2">Ventas y caja</h1><p class="text-body-secondary">Cobra pedidos pendientes, registra pagos divididos y controla tu caja.</p></header>
<?php if ($mensaje): ?><div class="alert alert-success" role="status"><?= venta_h($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= venta_h($error) ?></div><?php endif; ?>
<?php if (!$listo): ?><div class="alert alert-warning">El módulo aún no está habilitado. Aplica manualmente <code>migrations/20261026_ventas_caja.sql</code>; esta página no ejecuta migraciones.</div><?php else: ?>
<?php if ($venta): ?><section class="alert alert-success"><h2 class="h5">Venta #<?= (int) $venta['venta_id'] ?> registrada</h2><p class="mb-1">Total cobrado: $<?= venta_h($venta['total']) ?> · Descuento fijo: $<?= venta_h($venta['descuento_valor']) ?></p><p class="mb-0">Destinatario: <?= venta_h($venta['nombre_destinatario']) ?> · Estado de estructura electrónica: <?= venta_h($venta['estado_factura'] ?? 'PENDIENTE') ?>. No se ha generado ni enviado un comprobante al SRI.</p></section><?php endif; ?>
<section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Mis cajas abiertas</h2></div><div class="card-body">
<?php if (!$cajas): ?><p class="text-body-secondary">No tienes una caja abierta.</p><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= venta_h($csrf) ?>"><input type="hidden" name="accion" value="abrir_caja"><div class="col-12 col-md-6"><label class="form-label" for="bodega">Bodega</label><select class="form-select" id="bodega" name="bodega_id" required><option value="">Selecciona</option><?php foreach ($bodegas as $b): ?><option value="<?= (int)$b['bodega_id'] ?>"><?= venta_h($b['nombre']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><label class="form-label" for="monto-apertura">Fondo inicial</label><input class="form-control" type="number" min="0" step="0.01" id="monto-apertura" name="monto_apertura" value="0.00" required></div><div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Abrir caja</button></div></form><?php else: ?><div class="row g-3"><?php foreach ($cajas as $caja): ?><div class="col-12 col-lg-6"><article class="border rounded p-3"><div class="d-flex justify-content-between"><strong><?= venta_h($caja['bodega']) ?></strong><span class="badge text-bg-success">ABIERTA</span></div><p class="small text-body-secondary">Abierta <?= venta_h($caja['fecha_apertura']) ?> · Fondo $<?= venta_h($caja['monto_apertura']) ?></p><form method="post" class="row g-2"><input type="hidden" name="csrf_token" value="<?= venta_h($csrf) ?>"><input type="hidden" name="accion" value="cerrar_caja"><input type="hidden" name="caja_id" value="<?= (int)$caja['caja_id'] ?>"><div class="col-12 col-md-5"><label class="form-label" for="declarado-<?= (int)$caja['caja_id'] ?>">Efectivo contado</label><input class="form-control" type="number" min="0" step="0.01" id="declarado-<?= (int)$caja['caja_id'] ?>" name="monto_declarado" required></div><div class="col-12 col-md-5"><label class="form-label" for="obs-<?= (int)$caja['caja_id'] ?>">Motivo diferencia</label><input class="form-control" id="obs-<?= (int)$caja['caja_id'] ?>" name="observaciones_cierre" maxlength="500"></div><div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-outline-danger w-100" type="submit">Cerrar</button></div></form></article></div><?php endforeach; ?></div><?php endif; ?></div></section>
<?php if ($pedidoCobrar): ?><section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Cobrar pedido #<?= (int)$pedidoCobrar['pedido_id'] ?> · <?= venta_h($pedidoCobrar['bodega']) ?></h2></div><div class="card-body"><p class="text-body-secondary">El total definitivo se calcula con el detalle guardado. El importe del descuento es un valor monetario fijo; los pagos deben sumar exactamente el total neto.</p><form method="post" class="row g-3" id="form-cobro"><input type="hidden" name="csrf_token" value="<?= venta_h($csrf) ?>"><input type="hidden" name="accion" value="cobrar"><input type="hidden" name="pedido_id" value="<?= (int)$pedidoCobrar['pedido_id'] ?>">
<div class="col-12"><fieldset><legend class="h6">¿Desea factura con datos del cliente?</legend><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="tipo_destinatario" id="factura-si" value="FACTURA" checked><label class="form-check-label" for="factura-si">Sí, registrar datos</label></div><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="tipo_destinatario" id="factura-no" value="CONSUMIDOR_FINAL"><label class="form-check-label" for="factura-no">No, consumidor final</label></div></fieldset></div>
<div class="col-12 col-md-4 campo-factura"><label class="form-label" for="tipo-id">Tipo identificación</label><select class="form-select" id="tipo-id" name="tipo_destinatario_detalle"><option value="RUC">RUC</option><option value="CEDULA" selected>Cédula</option><option value="PASAPORTE">Pasaporte</option></select></div>
<div class="col-12 col-md-4 campo-factura"><label class="form-label" for="identificacion">Identificación</label><input class="form-control" id="identificacion" name="identificacion_destinatario" maxlength="20" required></div><div class="col-12 col-md-4 campo-factura"><label class="form-label" for="nombre">Nombre / razón social</label><input class="form-control" id="nombre" name="nombre_destinatario" maxlength="300" required></div><div class="col-12 col-md-6 campo-factura"><label class="form-label" for="correo">Correo electrónico (opcional)</label><input class="form-control" type="email" id="correo" name="correo_destinatario" maxlength="150"></div><div class="col-12 col-md-6 campo-factura"><label class="form-label" for="direccion">Dirección (opcional)</label><input class="form-control" id="direccion" name="direccion_destinatario" maxlength="300"></div>
<div class="col-12 col-md-4"><label class="form-label" for="descuento">Descuento fijo ($)</label><input class="form-control" type="number" min="0" step="0.01" id="descuento" name="descuento_valor" value="0.00" required></div><?php foreach (['EFECTIVO'=>'Efectivo','TRANSFERENCIA'=>'Transferencia','TARJETA_CREDITO'=>'Tarjeta de crédito'] as $key=>$label): ?><div class="col-12 col-md-4"><label class="form-label" for="pago-<?= $key ?>"><?= $label ?> ($)</label><input class="form-control" type="number" min="0" step="0.01" id="pago-<?= $key ?>" name="pagos[<?= $key ?>]" value="0.00"><label class="form-label mt-2" for="ref-<?= $key ?>">Referencia (si aplica)</label><input class="form-control" id="ref-<?= $key ?>" name="referencias[<?= $key ?>]" maxlength="100"></div><?php endforeach; ?>
<div class="col-12"><div class="alert alert-info small mb-0">El registro deja lista la estructura de factura electrónica en estado pendiente. En esta fase no se genera XML, firma ni comunicación con el SRI.</div></div><div class="col-12"><button class="btn btn-primary" type="submit">Confirmar cobro</button><a class="btn btn-outline-secondary" href="/ventas.php">Cancelar</a></div></form></div></section><?php elseif ($cobrarId): ?><div class="alert alert-warning">El pedido no está pendiente o no está asignado a tus bodegas.</div><?php endif; ?>
<section class="card shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Pedidos pendientes de cobro</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Pedido</th><th>Bodega</th><th>Referencia</th><th>Líneas</th><th>Total referencial</th><th></th></tr></thead><tbody><?php foreach ($pedidos as $p): ?><tr><td>#<?= (int)$p['pedido_id'] ?><br><small><?= venta_h($p['fecha_solicitud_cierre']) ?></small></td><td><?= venta_h($p['bodega']) ?></td><td><?= venta_h($p['referencia_cliente'] ?? '—') ?></td><td><?= (int)$p['lineas'] ?></td><td>$<?= venta_h($p['total']) ?></td><td><a class="btn btn-sm btn-primary" href="/ventas.php?cobrar=<?= (int)$p['pedido_id'] ?>">Cobrar</a></td></tr><?php endforeach; ?><?php if (!$pedidos): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">No hay pedidos pendientes de cobro.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="card shadow-sm mt-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Mis ventas recientes</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Venta / fecha</th><th>Destinatario</th><th>Pagos</th><th>Descuento fijo</th><th>Total</th><th>Factura electrónica</th></tr></thead><tbody><?php foreach ($ventasPropias as $v): ?><tr><td>#<?= (int)$v['venta_id'] ?><br><small><?= venta_h($v['fecha_venta']) ?></small></td><td><?= venta_h($v['nombre_destinatario']) ?></td><td><?= venta_h($v['pagos'] ?? '—') ?></td><td>$<?= venta_h($v['descuento_valor']) ?></td><td>$<?= venta_h($v['total']) ?></td><td><?= venta_h($v['estado_factura'] ?? 'PENDIENTE') ?></td></tr><?php endforeach; ?><?php if (!$ventasPropias): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">Todavía no has registrado ventas.</td></tr><?php endif; ?></tbody></table></div><div class="card-footer small text-body-secondary">La consulta está limitada a ventas creadas por tu usuario. Los comprobantes electrónicos quedan pendientes para integrar después.</div></section>
<?php endif; ?></main><script>
const opts = document.querySelectorAll('input[name="tipo_destinatario"]');
const campos = document.querySelectorAll('.campo-factura');
function alternarFactura(){const factura=document.querySelector('#factura-si')?.checked; campos.forEach(c=>{c.hidden=!factura;c.querySelectorAll('input,select').forEach(i=>{i.disabled=!factura;i.required=factura&&['identificacion_destinatario','nombre_destinatario'].includes(i.name)});});}
opts.forEach(o=>o.addEventListener('change',alternarFactura)); alternarFactura();
</script></body></html>
