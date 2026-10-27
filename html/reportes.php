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
require_once __DIR__ . '/includes/devoluciones.php';
require_once __DIR__ . '/includes/reportes.php';

function reporte_h(mixed $v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$usuario = corozao_exigir_permiso($pdo, 'reports.view');
$csrf = $_SESSION['csrf_token'] ?? '';
if (!is_string($csrf) || strlen($csrf) < 32) { $csrf = bin2hex(random_bytes(32)); $_SESSION['csrf_token'] = $csrf; }
$error = null;
$mensaje = $_SESSION['reportes_flash'] ?? null;
unset($_SESSION['reportes_flash']);
$listo = false;
$devolucionesLista = false;
$bodegas = corozao_bodegas_asignadas($pdo, $usuario);
$conteoId = filter_var($_GET['conteo'] ?? null, FILTER_VALIDATE_INT) ?: null;
$bodegaConteoId = filter_var($_GET['bodega_conteo'] ?? null, FILTER_VALIDATE_INT) ?: null;

try { $listo = corozao_ventas_instaladas($pdo) && corozao_reportes_instalados($pdo); }
catch (Throwable $e) { error_log('Corozao: no se pudo validar esquema de reportes.'); http_response_code(503); $error = 'No se pudo validar el esquema de reportes.'; }
if ($listo) { try { $devolucionesLista = corozao_devoluciones_instaladas($pdo); } catch (Throwable $e) { error_log('Corozao: no se pudo validar conciliación de reembolsos.'); } }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $listo) {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !corozao_csrf_valido($token)) { http_response_code(403); $error = 'La solicitud expiró. Recarga la página.'; }
    else {
        try {
            $accion = $_POST['accion'] ?? '';
            if ($accion === 'abrir_conteo') {
                corozao_exigir_permiso($pdo, 'inventory.physical_count.create');
                $bodegaId = filter_var($_POST['bodega_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$bodegaId || !in_array($bodegaId, array_map(static fn($b)=>(int)$b['bodega_id'], $bodegas), true)) throw new DomainException('Selecciona una bodega válida.');
                $id = corozao_conteo_abrir($pdo, $bodegaId, (int)$usuario['usuario_id']);
                $_SESSION['reportes_flash'] = 'Sesión de conteo #' . $id . ' iniciada. Cada lectura guardará el saldo teórico en ese instante.';
                header('Location: /reportes.php?bodega_conteo=' . $bodegaId . '&conteo=' . $id); exit;
            }
            if ($accion === 'contar_producto') {
                corozao_exigir_permiso($pdo, 'inventory.physical_count.create');
                $id = filter_var($_POST['conteo_id'] ?? null, FILTER_VALIDATE_INT);
                $productoId = filter_var($_POST['producto_id'] ?? null, FILTER_VALIDATE_INT);
                $fisico = $_POST['cantidad_fisica'] ?? '';
                if (!$id || !$productoId || !is_string($fisico)) throw new DomainException('Selecciona un producto e ingresa su conteo físico.');
                corozao_conteo_registrar($pdo, $id, $productoId, (int)$usuario['usuario_id'], $fisico);
                $_SESSION['reportes_flash'] = 'Lectura de inventario guardada con saldo teórico y diferencia. No se modificaron existencias.';
                header('Location: /reportes.php?conteo=' . $id); exit;
            }
            if ($accion === 'cerrar_conteo') {
                corozao_exigir_permiso($pdo, 'inventory.physical_count.close');
                $id = filter_var($_POST['conteo_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id) throw new DomainException('El conteo no es válido.');
                corozao_conteo_cerrar($pdo, $id, (int)$usuario['usuario_id']);
                $_SESSION['reportes_flash'] = 'Conteo cerrado. Las diferencias quedan en el reporte y no ajustan inventario.';
                header('Location: /reportes.php?conteo=' . $id); exit;
            }
            throw new DomainException('Acción no válida.');
        } catch (DomainException $e) { http_response_code(422); $error = $e->getMessage(); }
        catch (Throwable $e) { error_log('Corozao: no se pudo guardar la acción de reportes/conteo.'); http_response_code(500); $error = 'No se guardó la operación. No se aplicaron cambios parciales.'; }
    }
}

$hoy = new DateTimeImmutable('today', new DateTimeZone('America/Guayaquil'));
$desdeRaw = $_GET['desde'] ?? $hoy->modify('first day of this month')->format('Y-m-d');
$hastaRaw = $_GET['hasta'] ?? $hoy->format('Y-m-d');
$desde = is_string($desdeRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeRaw) ? DateTimeImmutable::createFromFormat('!Y-m-d', $desdeRaw) : false;
$hasta = is_string($hastaRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hastaRaw) ? DateTimeImmutable::createFromFormat('!Y-m-d', $hastaRaw) : false;
if (!$desde || $desde->format('Y-m-d') !== $desdeRaw || !$hasta || $hasta->format('Y-m-d') !== $hastaRaw || $hasta < $desde || $hasta->diff($desde)->days > 366) {
    $error = $error ?? 'El rango debe ser válido y no superar 366 días.';
    $desde = $hoy->modify('first day of this month'); $hasta = $hoy;
}
$bodegaFiltro = filter_var($_GET['bodega'] ?? null, FILTER_VALIDATE_INT) ?: null;
$usuarioFiltro = filter_var($_GET['usuario'] ?? null, FILTER_VALIDATE_INT) ?: null;
$productoFiltro = filter_var($_GET['producto'] ?? null, FILTER_VALIDATE_INT) ?: null;
$medioFiltroRaw = $_GET['medio'] ?? '';
$medioFiltro = is_string($medioFiltroRaw) && in_array($medioFiltroRaw, ['', 'EFECTIVO', 'TRANSFERENCIA', 'TARJETA_CREDITO'], true) ? $medioFiltroRaw : '';
if ($bodegaFiltro && !in_array($bodegaFiltro, array_map(static fn($b)=>(int)$b['bodega_id'], $bodegas), true)) { http_response_code(403); exit('Bodega no autorizada.'); }

$resumenMedios = $resumenProductos = $resumenBodegas = $cierres = $listaUsuarios = $catalogo = $conteos = $detallesConteo = [];
$conteoActivo = null;
if ($listo) {
    try {
        $listaUsuarios = $pdo->query("SELECT usuario_id,nombre_completo,rol FROM usuarios WHERE activo=1 ORDER BY nombre_completo LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        $catalogo = $pdo->query("SELECT producto_id,nombre FROM productos WHERE activo=1 ORDER BY nombre LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
        $where = ['v.fecha_venta >= :inicio', 'v.fecha_venta < :fin'];
        $params = ['inicio' => $desde->format('Y-m-d') . ' 00:00:00', 'fin' => $hasta->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
        if ($bodegaFiltro) { $where[] = 'v.bodega_id = :bodega'; $params['bodega'] = $bodegaFiltro; }
        if ($usuarioFiltro) { $where[] = 'v.usuario_id = :usuario'; $params['usuario'] = $usuarioFiltro; }
        if ($productoFiltro) { $where[] = 'EXISTS (SELECT 1 FROM venta_detalles fvd WHERE fvd.venta_id=v.venta_id AND fvd.producto_id=:producto)'; $params['producto'] = $productoFiltro; }
        if ($medioFiltro !== '') { $where[] = 'EXISTS (SELECT 1 FROM venta_pagos fvp WHERE fvp.venta_id=v.venta_id AND fvp.medio=:medio_filtro)'; $params['medio_filtro'] = $medioFiltro; }
        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare('SELECT COUNT(*) AS ventas, CAST(COALESCE(SUM(v.subtotal),0) AS DECIMAL(14,2)) AS subtotal,
            CAST(COALESCE(SUM(v.impuestos),0) AS DECIMAL(14,2)) AS impuestos,
            CAST(COALESCE(SUM(v.descuento_valor),0) AS DECIMAL(14,2)) AS descuentos,
            CAST(COALESCE(SUM(v.total),0) AS DECIMAL(14,2)) AS total FROM ventas v WHERE ' . $whereSql);
        $stmt->execute($params); $resumenBodegas = $stmt->fetch(PDO::FETCH_ASSOC);

        $pagoWhere = ['v.fecha_venta >= :inicio', 'v.fecha_venta < :fin'];
        $pagoParams = ['inicio' => $params['inicio'], 'fin' => $params['fin']];
        if ($bodegaFiltro) { $pagoWhere[] = 'v.bodega_id = :bodega_pago'; $pagoParams['bodega_pago'] = $bodegaFiltro; }
        if ($usuarioFiltro) { $pagoWhere[] = 'v.usuario_id = :usuario_pago'; $pagoParams['usuario_pago'] = $usuarioFiltro; }
        if ($productoFiltro) { $pagoWhere[] = 'EXISTS (SELECT 1 FROM venta_detalles fvd WHERE fvd.venta_id=v.venta_id AND fvd.producto_id=:producto_pago)'; $pagoParams['producto_pago'] = $productoFiltro; }
        if ($medioFiltro !== '') { $pagoWhere[] = 'vp.medio = :medio_pago'; $pagoParams['medio_pago'] = $medioFiltro; }
        $stmt = $pdo->prepare('SELECT vp.medio,COUNT(DISTINCT v.venta_id) AS ventas,CAST(SUM(vp.monto) AS DECIMAL(14,2)) AS total
            FROM venta_pagos vp INNER JOIN ventas v ON v.venta_id=vp.venta_id WHERE ' . implode(' AND ', $pagoWhere) . ' GROUP BY vp.medio ORDER BY vp.medio');
        $stmt->execute($pagoParams); $resumenMedios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $detailWhere = $where;
        $detailParams = $params;
        if ($productoFiltro) { $detailWhere[] = 'vd.producto_id=:producto_detalle'; $detailParams['producto_detalle'] = $productoFiltro; }
        $stmt = $pdo->prepare('SELECT p.producto_id,p.nombre AS producto,b.nombre AS bodega,u.nombre_completo AS usuario,
            CAST(SUM(vd.cantidad_base) AS DECIMAL(14,3)) AS cantidad_base,
            CAST(SUM(vd.total_bruto) AS DECIMAL(14,2)) AS total_bruto
            FROM venta_detalles vd INNER JOIN ventas v ON v.venta_id=vd.venta_id
            INNER JOIN productos p ON p.producto_id=vd.producto_id INNER JOIN bodegas b ON b.bodega_id=v.bodega_id
            INNER JOIN usuarios u ON u.usuario_id=v.usuario_id WHERE ' . implode(' AND ', $detailWhere) . '
            GROUP BY p.producto_id,p.nombre,b.bodega_id,b.nombre,u.usuario_id,u.nombre_completo ORDER BY p.nombre,b.nombre,u.nombre_completo LIMIT 1000');
        $stmt->execute($detailParams); $resumenProductos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cashWhere = ['c.estado=\'CERRADA\'', 'c.fecha_cierre >= :inicio', 'c.fecha_cierre < :fin'];
        $cashParams = ['inicio' => $params['inicio'], 'fin' => $params['fin']];
        if ($bodegaFiltro) { $cashWhere[] = 'c.bodega_id=:bodega'; $cashParams['bodega'] = $bodegaFiltro; }
        if ($usuarioFiltro) { $cashWhere[] = 'c.usuario_id=:usuario'; $cashParams['usuario'] = $usuarioFiltro; }
        $detalleReembolsosSql = $devolucionesLista
            ? '(SELECT GROUP_CONCAT(CONCAT(\'Reembolso \' , cr.medio, \': esp $\',cr.monto_esperado,\', decl $\',cr.monto_declarado,\', dif $\',cr.diferencia) ORDER BY cr.medio SEPARATOR \' · \') FROM caja_reembolsos_conciliaciones cr WHERE cr.caja_id=c.caja_id)'
            : 'NULL';
        $stmt = $pdo->prepare('SELECT c.caja_id,c.fecha_cierre,c.monto_esperado AS efectivo_esperado,
            c.monto_declarado AS efectivo_declarado,c.diferencia AS diferencia_efectivo,
            b.nombre AS bodega,u.nombre_completo AS usuario,
            (SELECT COUNT(*) FROM caja_conciliaciones cc0 WHERE cc0.caja_id=c.caja_id) AS medios_conciliados,
            CONCAT_WS(\' · \',(SELECT GROUP_CONCAT(CONCAT(cc.medio, \': esp $\',cc.monto_esperado,\', decl $\',cc.monto_declarado,\', dif $\',cc.diferencia)
                ORDER BY cc.medio SEPARATOR \' · \') FROM caja_conciliaciones cc WHERE cc.caja_id=c.caja_id),
                ' . $detalleReembolsosSql . ') AS conciliacion
            FROM cajas c INNER JOIN bodegas b ON b.bodega_id=c.bodega_id INNER JOIN usuarios u ON u.usuario_id=c.usuario_id
            WHERE ' . implode(' AND ', $cashWhere) . ' ORDER BY c.fecha_cierre DESC LIMIT 500');
        $stmt->execute($cashParams); $cierres = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countWhere = ['ci.fecha_apertura >= :inicio', 'ci.fecha_apertura < :fin'];
        $countParams = ['inicio' => $params['inicio'], 'fin' => $params['fin']];
        if ($bodegaFiltro) { $countWhere[] = 'ci.bodega_id=:bodega'; $countParams['bodega'] = $bodegaFiltro; }
        $stmt = $pdo->prepare('SELECT ci.conteo_id,ci.estado,ci.fecha_apertura,ci.fecha_cierre,b.nombre AS bodega,
            u.nombre_completo AS usuario,COALESCE(d.productos_contados,0) AS productos_contados,
            COALESCE(d.diferencias,0) AS diferencias
            FROM conteos_inventario ci INNER JOIN bodegas b ON b.bodega_id=ci.bodega_id
            INNER JOIN usuarios u ON u.usuario_id=ci.usuario_id
            LEFT JOIN (SELECT conteo_id,COUNT(*) AS productos_contados,
                SUM(CASE WHEN diferencia <> 0 THEN 1 ELSE 0 END) AS diferencias
                FROM conteo_inventario_detalles GROUP BY conteo_id) d ON d.conteo_id=ci.conteo_id
            WHERE ' . implode(' AND ', $countWhere) . ' ORDER BY ci.fecha_apertura DESC LIMIT 300');
        $stmt->execute($countParams); $conteos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($bodegaConteoId && !in_array($bodegaConteoId, array_map(static fn($b)=>(int)$b['bodega_id'], $bodegas), true)) { http_response_code(403); exit('Bodega no autorizada.'); }
        if ($conteoId) {
            $stmt = $pdo->prepare('SELECT ci.*,b.nombre AS bodega FROM conteos_inventario ci INNER JOIN bodegas b ON b.bodega_id=ci.bodega_id WHERE ci.conteo_id=:id');
            $stmt->execute(['id'=>$conteoId]); $conteoActivo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($conteoActivo) {
                $bodegaConteoId = (int)$conteoActivo['bodega_id'];
                $stmt = $pdo->prepare('SELECT d.*,p.nombre AS producto,u.nombre_completo AS contado_por FROM conteo_inventario_detalles d
                    INNER JOIN productos p ON p.producto_id=d.producto_id INNER JOIN usuarios u ON u.usuario_id=d.usuario_id
                    WHERE d.conteo_id=:id ORDER BY p.nombre LIMIT 1000');
                $stmt->execute(['id'=>$conteoId]); $detallesConteo = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } elseif ($bodegaConteoId) {
            $stmt = $pdo->prepare('SELECT conteo_id FROM conteos_inventario WHERE bodega_id=:bodega AND estado=\'ABIERTO\'');
            $stmt->execute(['bodega'=>$bodegaConteoId]); $id = $stmt->fetchColumn();
            if ($id) { header('Location: /reportes.php?bodega_conteo=' . $bodegaConteoId . '&conteo=' . (int)$id); exit; }
        }
    } catch (Throwable $e) { error_log('Corozao: no se pudieron consultar reportes o conteos.'); http_response_code(500); $error = 'No se pudieron consultar los reportes.'; }
}

$productosConteo = [];
if ($listo && $conteoActivo && $conteoActivo['estado'] === 'ABIERTO') {
    $stmt = $pdo->prepare('SELECT p.producto_id,p.nombre FROM productos p
        WHERE p.activo=1 AND NOT EXISTS (SELECT 1 FROM conteo_inventario_detalles d WHERE d.conteo_id=:conteo AND d.producto_id=p.producto_id)
        ORDER BY p.nombre LIMIT 1000');
    $stmt->execute(['conteo'=>$conteoActivo['conteo_id']]); $productosConteo=$stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><title>Reportes y conteos | Corozao</title></head><body class="bg-body-tertiary">
<nav class="navbar bg-white border-bottom"><div class="container-xxl"><a class="navbar-brand fw-semibold" href="/">Corozao</a><span><span class="badge text-bg-primary">ADMINISTRADOR</span> <a class="btn btn-sm btn-outline-secondary ms-2" href="/">Inicio</a></span></div></nav>
<main class="container-xxl py-4"><header class="mb-4"><p class="text-primary fw-semibold mb-1">Control operativo</p><h1 class="h2">Reportes, conciliación y conteos</h1><p class="text-body-secondary">Filtra ventas por periodo, usuario, producto, bodega y medio. Las diferencias físicas se informan sin corregir saldos.</p></header>
<?php if ($mensaje): ?><div class="alert alert-success" role="status"><?=reporte_h($mensaje)?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger" role="alert"><?=reporte_h($error)?></div><?php endif; ?>
<?php if (!$listo): ?><div class="alert alert-warning">El módulo requiere las migraciones de ventas/caja y conteos/conciliación instaladas. No se ejecuta ningún SQL desde esta pantalla.</div><?php else: ?>
<section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Filtros de consulta</h2></div><div class="card-body"><form method="get" class="row g-3"><div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="desde">Desde</label><input class="form-control" type="date" id="desde" name="desde" value="<?=$desde->format('Y-m-d')?>" required></div><div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="hasta">Hasta</label><input class="form-control" type="date" id="hasta" name="hasta" value="<?=$hasta->format('Y-m-d')?>" required></div><div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="bodega">Bodega</label><select class="form-select" id="bodega" name="bodega"><option value="">Todas</option><?php foreach($bodegas as $b):?><option value="<?=(int)$b['bodega_id']?>" <?=(int)$b['bodega_id']===$bodegaFiltro?'selected':''?>><?=reporte_h($b['nombre'])?></option><?php endforeach;?></select></div><div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="usuario">Usuario</label><select class="form-select" id="usuario" name="usuario"><option value="">Todos</option><?php foreach($listaUsuarios as $u):?><option value="<?=(int)$u['usuario_id']?>" <?=(int)$u['usuario_id']===$usuarioFiltro?'selected':''?>><?=reporte_h($u['nombre_completo'])?> (<?=reporte_h($u['rol'])?>)</option><?php endforeach;?></select></div><div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="producto">Producto</label><select class="form-select" id="producto" name="producto"><option value="">Todos</option><?php foreach($catalogo as $p):?><option value="<?=(int)$p['producto_id']?>" <?=(int)$p['producto_id']===$productoFiltro?'selected':''?>><?=reporte_h($p['nombre'])?></option><?php endforeach;?></select></div><div class="col-12 col-sm-6 col-lg-2"><label class="form-label" for="medio">Medio de pago</label><select class="form-select" id="medio" name="medio"><option value="">Todos</option><?php foreach(['EFECTIVO','TRANSFERENCIA','TARJETA_CREDITO'] as $m):?><option value="<?=$m?>" <?=$medioFiltro===$m?'selected':''?>><?=reporte_h(str_replace('_',' ',$m))?></option><?php endforeach;?></select></div><div class="col-12"><button class="btn btn-primary" type="submit">Consultar</button></div></form></div></section>
<div class="row g-3 mb-4"><div class="col-6 col-lg"><div class="card h-100"><div class="card-body"><div class="small text-body-secondary">Ventas</div><div class="h4 mb-0"><?=(int)$resumenBodegas['ventas']?></div></div></div></div><div class="col-6 col-lg"><div class="card h-100"><div class="card-body"><div class="small text-body-secondary">Subtotal</div><div class="h4 mb-0">$<?=reporte_h($resumenBodegas['subtotal'])?></div></div></div></div><div class="col-6 col-lg"><div class="card h-100"><div class="card-body"><div class="small text-body-secondary">Impuestos</div><div class="h4 mb-0">$<?=reporte_h($resumenBodegas['impuestos'])?></div></div></div></div><div class="col-6 col-lg"><div class="card h-100"><div class="card-body"><div class="small text-body-secondary">Descuentos</div><div class="h4 mb-0">$<?=reporte_h($resumenBodegas['descuentos'])?></div></div></div></div><div class="col-12 col-lg"><div class="card h-100"><div class="card-body"><div class="small text-body-secondary">Total</div><div class="h4 mb-0">$<?=reporte_h($resumenBodegas['total'])?></div></div></div></div></div>
<section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Ventas por medio de pago</h2></div><div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Medio</th><th>Ventas con este medio</th><th>Total aplicado</th></tr></thead><tbody><?php foreach($resumenMedios as $r):?><tr><td><?=reporte_h(str_replace('_',' ',$r['medio']))?></td><td><?=(int)$r['ventas']?></td><td>$<?=reporte_h($r['total'])?></td></tr><?php endforeach;?><?php if(!$resumenMedios):?><tr><td colspan="3" class="text-center text-body-secondary">Sin pagos en el rango.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Ventas por producto</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Producto</th><th>Bodega</th><th>Usuario</th><th>Unidades base</th><th>Total bruto</th></tr></thead><tbody><?php foreach($resumenProductos as $r):?><tr><td><?=reporte_h($r['producto'])?></td><td><?=reporte_h($r['bodega'])?></td><td><?=reporte_h($r['usuario'])?></td><td><?=reporte_h($r['cantidad_base'])?></td><td>$<?=reporte_h($r['total_bruto'])?></td></tr><?php endforeach;?><?php if(!$resumenProductos):?><tr><td colspan="5" class="text-center text-body-secondary">Sin ventas en el rango.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Cierres y conciliación de caja</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Caja / cierre</th><th>Bodega</th><th>Usuario</th><th>Efectivo esperado</th><th>Declarado</th><th>Diferencia</th><th>Medios conciliados</th><th>Detalle por medio</th></tr></thead><tbody><?php foreach($cierres as $c):?><tr><td>#<?=(int)$c['caja_id']?><br><small><?=reporte_h($c['fecha_cierre'])?></small></td><td><?=reporte_h($c['bodega'])?></td><td><?=reporte_h($c['usuario'])?></td><td>$<?=reporte_h($c['efectivo_esperado'])?></td><td>$<?=reporte_h($c['efectivo_declarado'])?></td><td>$<?=reporte_h($c['diferencia_efectivo'])?></td><td><?=(int)$c['medios_conciliados']?> / 3</td><td class="small"><?=reporte_h($c['conciliacion'] ?? 'Sin conciliación histórica')?></td></tr><?php endforeach;?><?php if(!$cierres):?><tr><td colspan="8" class="text-center text-body-secondary">No hay cierres en el rango.</td></tr><?php endif;?></tbody></table></div><div class="card-footer small text-body-secondary">Los cierres antiguos pueden no tener conciliación histórica por medio; no se reconstruyen.</div></section>
<section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Conteos físicos</h2></div><div class="card-body"><form method="get" class="row g-2 align-items-end"><input type="hidden" name="desde" value="<?=$desde->format('Y-m-d')?>"><input type="hidden" name="hasta" value="<?=$hasta->format('Y-m-d')?>"><label class="col-12 col-md-4" for="bodega-conteo">Iniciar o continuar conteo en bodega</label><div class="col-12 col-md-5"><select class="form-select" id="bodega-conteo" name="bodega_conteo" required><option value="">Selecciona</option><?php foreach($bodegas as $b):?><option value="<?=(int)$b['bodega_id']?>"><?=reporte_h($b['nombre'])?></option><?php endforeach;?></select></div><div class="col-12 col-md-3"><button class="btn btn-outline-primary w-100" type="submit">Abrir conteo</button></div></form><p class="small text-body-secondary mt-3 mb-0">Cada producto se registra una sola vez por sesión. El sistema captura el saldo teórico al guardar la lectura. Los movimientos operativos continúan y ninguna diferencia cambia existencias.</p></div>
<?php if($bodegaConteoId && !$conteoActivo):?><div class="border-top p-3"><p class="mb-2">No hay un conteo abierto en esta bodega.</p><form method="post" onsubmit="return confirm('¿Iniciar una sesión de conteo para esta bodega?')"><input type="hidden" name="csrf_token" value="<?=reporte_h($csrf)?>"><input type="hidden" name="accion" value="abrir_conteo"><input type="hidden" name="bodega_id" value="<?=(int)$bodegaConteoId?>"><button class="btn btn-primary" type="submit">Iniciar conteo</button></form></div><?php endif;?>
<?php if($conteoActivo):?><div class="border-top p-3"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><h3 class="h6 mb-0">Conteo #<?=(int)$conteoActivo['conteo_id']?> · <?=reporte_h($conteoActivo['bodega'])?> · <?=reporte_h($conteoActivo['estado'])?></h3><?php if($conteoActivo['estado']==='ABIERTO'):?><form method="post" onsubmit="return confirm('¿Cerrar este conteo? No se podrá agregar más lecturas. No se cambiarán existencias.')"><input type="hidden" name="csrf_token" value="<?=reporte_h($csrf)?>"><input type="hidden" name="accion" value="cerrar_conteo"><input type="hidden" name="conteo_id" value="<?=(int)$conteoActivo['conteo_id']?>"><button class="btn btn-sm btn-outline-danger" type="submit">Cerrar conteo</button></form><?php endif;?></div>
<?php if($conteoActivo['estado']==='ABIERTO'):?><form method="post" class="row g-2 mt-2 align-items-end"><input type="hidden" name="csrf_token" value="<?=reporte_h($csrf)?>"><input type="hidden" name="accion" value="contar_producto"><input type="hidden" name="conteo_id" value="<?=(int)$conteoActivo['conteo_id']?>"><div class="col-12 col-md-7"><label class="form-label" for="producto-conteo">Producto sin contar</label><select class="form-select" id="producto-conteo" name="producto_id" required><option value="">Selecciona</option><?php foreach($productosConteo as $p):?><option value="<?=(int)$p['producto_id']?>"><?=reporte_h($p['nombre'])?> (#<?=(int)$p['producto_id']?>)</option><?php endforeach;?></select></div><div class="col-8 col-md-3"><label class="form-label" for="cantidad-fisica">Cantidad física</label><input class="form-control" type="number" min="0" step="0.001" id="cantidad-fisica" name="cantidad_fisica" required></div><div class="col-4 col-md-2"><button class="btn btn-primary w-100" type="submit">Guardar</button></div></form><?php endif;?>
<div class="table-responsive mt-3"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Producto</th><th>Teórico al contar</th><th>Físico</th><th>Diferencia</th><th>Contado por / fecha</th></tr></thead><tbody><?php foreach($detallesConteo as $d):?><tr><td><?=reporte_h($d['producto'])?></td><td><?=reporte_h($d['cantidad_teorica'])?></td><td><?=reporte_h($d['cantidad_fisica'])?></td><td class="<?=((float)$d['diferencia']===0.0?'':'text-danger fw-semibold')?>"><?=reporte_h($d['diferencia'])?></td><td><?=reporte_h($d['contado_por'])?><br><small><?=reporte_h($d['fecha_conteo'])?></small></td></tr><?php endforeach;?><?php if(!$detallesConteo):?><tr><td colspan="5" class="text-center text-body-secondary">Todavía no hay lecturas en este conteo.</td></tr><?php endif;?></tbody></table></div></div><?php endif;?></section>
<section class="card shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Sesiones de conteo recientes</h2></div><div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Conteo</th><th>Bodega</th><th>Estado</th><th>Productos</th><th>Con diferencia</th><th></th></tr></thead><tbody><?php foreach($conteos as $c):?><tr><td>#<?=(int)$c['conteo_id']?><br><small><?=reporte_h($c['fecha_apertura'])?></small></td><td><?=reporte_h($c['bodega'])?></td><td><?=reporte_h($c['estado'])?></td><td><?=(int)$c['productos_contados']?></td><td><?=(int)$c['diferencias']?></td><td><a class="btn btn-sm btn-outline-primary" href="/reportes.php?conteo=<?=(int)$c['conteo_id']?>">Ver detalle</a></td></tr><?php endforeach;?><?php if(!$conteos):?><tr><td colspan="6" class="text-center text-body-secondary">No hay conteos en el rango.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?></main></body></html>
