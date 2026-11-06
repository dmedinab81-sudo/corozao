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

function comprobante_h(mixed $valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$usuario = corozao_exigir_permiso($pdo, 'sales.view_own');
$ventaId = filter_var($_GET['venta'] ?? null, FILTER_VALIDATE_INT);
if (!$ventaId || $ventaId < 1) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}
if (!corozao_ventas_instaladas($pdo)) {
    http_response_code(503);
    exit('El módulo de ventas no está disponible.');
}

$stmt = $pdo->prepare(
    'SELECT v.*, b.nombre AS bodega, u.nombre_completo AS cajero
     FROM ventas v
     INNER JOIN bodegas b ON b.bodega_id = v.bodega_id
     INNER JOIN usuarios u ON u.usuario_id = v.usuario_id
     WHERE v.venta_id = :venta AND v.usuario_id = :usuario
     LIMIT 1'
);
$stmt->execute(['venta' => $ventaId, 'usuario' => (int)$usuario['usuario_id']]);
$venta = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$venta) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}

$tieneSnapshots = corozao_ventas_directas_instaladas($pdo);
if ($tieneSnapshots) {
    $nombre = 'COALESCE(vd.producto_nombre_snapshot, p.nombre)';
    $marca = 'COALESCE(vd.marca_snapshot, p.marca)';
    $contenido = 'COALESCE(vd.contenido_snapshot, p.contenido)';
    $categoria = 'COALESCE(vd.categoria_snapshot, c.nombre)';
    $presentacion = 'COALESCE(vd.presentacion_snapshot, pr.nombre)';
    $codigo = 'COALESCE(vd.codigo_snapshot, (SELECT GROUP_CONCAT(DISTINCT pc.codigo ORDER BY pc.codigo) FROM producto_codigos pc WHERE pc.presentacion_id = vd.presentacion_id))';
} else {
    $nombre = 'p.nombre';
    $marca = 'p.marca';
    $contenido = 'p.contenido';
    $categoria = 'c.nombre';
    $presentacion = 'pr.nombre';
    $codigo = '(SELECT GROUP_CONCAT(DISTINCT pc.codigo ORDER BY pc.codigo) FROM producto_codigos pc WHERE pc.presentacion_id = vd.presentacion_id)';
}
$stmt = $pdo->prepare(
    'SELECT vd.cantidad_presentacion, vd.precio_unitario_presentacion, vd.tarifa_iva,
            vd.subtotal, vd.impuesto, vd.total_bruto,
            ' . $nombre . ' AS producto, ' . $marca . ' AS marca,
            ' . $contenido . ' AS contenido, ' . $categoria . ' AS categoria,
            ' . $presentacion . ' AS presentacion, ' . $codigo . ' AS codigo
     FROM venta_detalles vd
     INNER JOIN productos p ON p.producto_id = vd.producto_id
     INNER JOIN presentaciones pr ON pr.presentacion_id = vd.presentacion_id
     LEFT JOIN categorias c ON c.categoria_id = p.categoria_id
     WHERE vd.venta_id = :venta
     ORDER BY vd.venta_detalle_id'
);
$stmt->execute(['venta' => $ventaId]);
$detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT medio, monto, referencia FROM venta_pagos WHERE venta_id = :venta ORDER BY venta_pago_id');
$stmt->execute(['venta' => $ventaId]);
$pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$detalles) {
    http_response_code(500);
    exit('El detalle de esta venta no está disponible.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante interno #<?= (int)$venta['venta_id'] ?> | Corozao</title>
    <style>
        :root{font-family:Arial,Helvetica,sans-serif;color:#17202a}*{box-sizing:border-box}body{margin:0;background:#eef1f4;font-size:14px;line-height:1.45}.toolbar{max-width:900px;margin:20px auto 12px;display:flex;gap:8px}.toolbar a,.toolbar button{border:1px solid #52606d;border-radius:5px;padding:9px 14px;background:white;color:#17202a;text-decoration:none;font-size:14px;cursor:pointer}.sheet{max-width:900px;margin:0 auto 30px;padding:42px;background:white;box-shadow:0 2px 14px #17202a1c}.top{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #153d5b;padding-bottom:18px}.brand{font-size:26px;font-weight:700;color:#153d5b}.muted{color:#566573}.notice{margin:20px 0;padding:12px;border:1px solid #bdc3c7;background:#f8f9f9;text-align:center;font-weight:700;letter-spacing:.02em}.parties{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:20px 0}.box{border:1px solid #d5d8dc;border-radius:4px;padding:14px}.box h2{margin:0 0 8px;font-size:14px;text-transform:uppercase;color:#34495e}.box p{margin:3px 0}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:9px 7px;border-bottom:1px solid #d5d8dc;text-align:left;vertical-align:top}th{background:#f2f4f4;font-size:12px;text-transform:uppercase}.num{text-align:right;white-space:nowrap}.description{min-width:190px}.totals{width:min(100%,360px);margin:18px 0 0 auto}.totals td{border:0;padding:5px 7px}.grand td{border-top:2px solid #153d5b;font-size:18px;font-weight:700;padding-top:10px}.payments{margin-top:24px}.payments h2{font-size:15px}.footer{margin-top:32px;padding-top:14px;border-top:1px solid #d5d8dc;text-align:center;font-size:12px;color:#566573}.footer strong{color:#922b21}@media(max-width:600px){.sheet{padding:22px 15px;margin:0 auto}.top{display:block}.top>div+div{margin-top:12px}.parties{grid-template-columns:1fr}.toolbar{margin:12px}.hide-mobile{display:none}}@media print{@page{size:A4;margin:12mm}body{background:white;font-size:11px}.toolbar{display:none}.sheet{max-width:none;margin:0;padding:0;box-shadow:none}.top{break-inside:avoid}.box{break-inside:avoid}tr{break-inside:avoid}th{background:#eee!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}.notice{background:white}}
    </style>
    <link rel="icon" type="image/svg+xml" href="/assets/img/brand/corozao-mark.svg?v=2">
    <link rel="stylesheet" href="/assets/css/brand.css">
    <style>
        .print-brand{display:flex;align-items:center;gap:14px}.print-brand img{width:68px;height:68px;object-fit:contain;background:#090E19;border-radius:5px}.print-brand .brand{font-size:20px;color:#090E19}
        @media print{.print-brand img{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
    </style>
    <link rel="stylesheet" href="/assets/css/print-thermal.css">
</head>
<body>
<nav class="toolbar" aria-label="Acciones del comprobante"><button type="button" onclick="window.print()">Imprimir / Guardar PDF</button><a href="/ventas.php">Volver a ventas</a></nav>
<main class="sheet">
    <header class="top">
        <div class="print-brand"><img src="/assets/img/brand/corozao-logo.jpeg" alt="Corozao by Mi Sub"><div><div class="brand">Corozao by Mi Sub</div><div><?= comprobante_h($venta['bodega']) ?></div><div class="muted">Comprobante interno de venta</div></div></div>
        <div><strong>Comprobante interno / venta #<?= (int)$venta['venta_id'] ?></strong><br><span><?= comprobante_h($venta['fecha_venta']) ?></span><br><span class="muted">Atendido por: <?= comprobante_h($venta['cajero']) ?></span></div>
    </header>
    <div class="notice">COMPROBANTE INTERNO — NO ES FACTURA ELECTRÓNICA NI DOCUMENTO TRIBUTARIO</div>
    <section class="parties" aria-label="Datos de la venta">
        <div class="box"><h2>Cliente</h2><p><strong><?= comprobante_h($venta['nombre_destinatario']) ?></strong></p>
            <?php if ($venta['tipo_destinatario'] !== 'CONSUMIDOR_FINAL'): ?><p><?= comprobante_h($venta['tipo_destinatario']) ?>: <?= comprobante_h($venta['identificacion_destinatario']) ?></p><?php endif; ?>
            <?php if (!empty($venta['direccion_destinatario'])): ?><p><?= comprobante_h($venta['direccion_destinatario']) ?></p><?php endif; ?>
            <?php if (!empty($venta['correo_destinatario'])): ?><p><?= comprobante_h($venta['correo_destinatario']) ?></p><?php endif; ?>
        </div>
        <div class="box"><h2>Referencia</h2><p>Venta: #<?= (int)$venta['venta_id'] ?></p><p>Bodega: <?= comprobante_h($venta['bodega']) ?></p><p>Origen: <?= $venta['pedido_id'] === null ? 'Venta directa' : 'Pedido #' . (int)$venta['pedido_id'] ?></p><?php if (!empty($venta['referencia_cliente'])): ?><p>Cliente / referencia: <?= comprobante_h($venta['referencia_cliente']) ?></p><?php endif; ?></div>
    </section>
    <div class="table-wrap"><table><thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">P. unitario</th><th class="num">Neto / IVA</th><th class="num">Total línea</th></tr></thead><tbody>
        <?php foreach ($detalles as $d): $descripcion = implode(' · ', array_filter([$d['codigo'], $d['producto'], $d['marca'], $d['contenido'], $d['categoria'], $d['presentacion']])); ?>
            <tr><td class="description"><?= comprobante_h($descripcion) ?></td><td class="num"><?= comprobante_h($d['cantidad_presentacion']) ?></td><td class="num">$<?= number_format((float)$d['precio_unitario_presentacion'], 2, '.', ',') ?></td><td class="num">$<?= comprobante_h($d['subtotal']) ?><br><small>IVA $<?= comprobante_h($d['impuesto']) ?> (<?= comprobante_h($d['tarifa_iva']) ?>%)</small></td><td class="num">$<?= comprobante_h($d['total_bruto']) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <table class="totals" aria-label="Totales"><tbody>
        <tr><td>Subtotal neto</td><td class="num">$<?= comprobante_h($venta['subtotal']) ?></td></tr>
        <tr><td>IVA</td><td class="num">$<?= comprobante_h($venta['impuestos']) ?></td></tr>
        <tr><td>Descuento</td><td class="num">− $<?= comprobante_h($venta['descuento_valor']) ?></td></tr>
        <tr class="grand"><td>TOTAL PAGADO</td><td class="num">$<?= comprobante_h($venta['total']) ?></td></tr>
    </tbody></table>
    <section class="payments"><h2>Medios de pago</h2><div class="table-wrap"><table><thead><tr><th>Medio</th><th>Referencia</th><th class="num">Importe</th></tr></thead><tbody>
        <?php foreach ($pagos as $p): ?><tr><td><?= comprobante_h(str_replace('_', ' ', $p['medio'])) ?></td><td><?= comprobante_h($p['referencia'] ?: '—') ?></td><td class="num">$<?= comprobante_h($p['monto']) ?></td></tr><?php endforeach; ?>
        <?php if (isset($venta['efectivo_recibido']) && $venta['efectivo_recibido'] !== null): ?><tr><td colspan="2">Efectivo recibido</td><td class="num">$<?= comprobante_h($venta['efectivo_recibido']) ?></td></tr><tr><td colspan="2">Cambio entregado</td><td class="num">$<?= comprobante_h($venta['cambio_entregado']) ?></td></tr><?php endif; ?>
    </tbody></table></div></section>
    <footer class="footer"><strong>Documento de control interno.</strong> No reemplaza una factura electrónica ni otro comprobante tributario. No se ha generado ni enviado información al SRI.</footer>
</main>
</body>
</html>
