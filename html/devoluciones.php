<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('COROZAO_SESSID');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');

require '/etc/corozao/database.php';
require_once __DIR__ . '/includes/autorizacion.php';
require_once __DIR__ . '/includes/ventas.php';
require_once __DIR__ . '/includes/devoluciones.php';

function devolucion_h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function devolucion_uuid(): string
{
    $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

$usuario = corozao_exigir_permiso($pdo, 'sales.return.request');
$esAdmin = $usuario['rol'] === 'ADMINISTRADOR';
$csrf = $_SESSION['csrf_token'] ?? '';
if (!is_string($csrf) || strlen($csrf)<32) { $csrf=bin2hex(random_bytes(32)); $_SESSION['csrf_token']=$csrf; }
$error=null; $mensaje=$_SESSION['devoluciones_flash']??null; unset($_SESSION['devoluciones_flash']);
$lista=corozao_devoluciones_instaladas($pdo);
$ventaId=filter_var($_GET['venta']??$_POST['venta_id']??null,FILTER_VALIDATE_INT)?:null;
$bodegas=$esAdmin
    ? $pdo->query('SELECT bodega_id,nombre FROM bodegas WHERE activo=1 ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC)
    : corozao_bodegas_asignadas($pdo,$usuario);
$venta=null; $lineasVenta=[]; $pendientes=[]; $historial=[];

if ($_SERVER['REQUEST_METHOD']==='POST' && $lista) {
    $token=$_POST['csrf_token']??'';
    if (!is_string($token)||!corozao_csrf_valido($token)) { http_response_code(403); $error='La solicitud expiró. Recarga la página.'; }
    else {
        try {
            $accion=$_POST['accion']??'';
            if ($accion==='solicitar') {
                $cantidades=$_POST['cantidades']??null; $motivo=$_POST['motivo']??''; $clave=$_POST['clave_idempotencia']??'';
                if (!$ventaId||!is_array($cantidades)||!is_string($motivo)||!is_string($clave)) throw new DomainException('Completa la venta, productos, cantidades y motivo.');
                $id=corozao_devolucion_solicitar($pdo,$usuario,$ventaId,$cantidades,$motivo,$clave);
                $_SESSION['devoluciones_flash']='Solicitud de devolución #'.$id.' registrada. Queda pendiente de aprobación del administrador.';
                header('Location: /devoluciones.php?venta='.$ventaId); exit;
            }
            if ($accion==='aprobar') {
                corozao_exigir_permiso($pdo,'sales.return.approve');
                $id=filter_var($_POST['devolucion_id']??null,FILTER_VALIDATE_INT); $vendibles=$_POST['vendibles']??null; $referencias=$_POST['referencias']??[];
                if (!$id||!is_array($vendibles)||!is_array($referencias)) throw new DomainException('Los datos de aprobación no son válidos.');
                $id=corozao_devolucion_aprobar($pdo,$id,(int)$usuario['usuario_id'],$vendibles,$referencias);
                $_SESSION['devoluciones_flash']='Devolución #'.$id.' aprobada. Se registraron los reembolsos y el inventario vendible.';
                header('Location: /devoluciones.php'); exit;
            }
            if ($accion==='rechazar') {
                corozao_exigir_permiso($pdo,'sales.return.approve');
                $id=filter_var($_POST['devolucion_id']??null,FILTER_VALIDATE_INT); $motivo=$_POST['motivo_rechazo']??'';
                if (!$id||!is_string($motivo)) throw new DomainException('Indica el motivo del rechazo.');
                corozao_devolucion_rechazar($pdo,$id,(int)$usuario['usuario_id'],$motivo);
                $_SESSION['devoluciones_flash']='Solicitud de devolución #'.$id.' rechazada con motivo registrado.';
                header('Location: /devoluciones.php'); exit;
            }
            throw new DomainException('La acción solicitada no es válida.');
        } catch (DomainException $e) { http_response_code(422); $error=$e->getMessage(); }
        catch (Throwable $e) { error_log('Corozao: no se pudo tramitar devolución.'); http_response_code(500); $error='No se completó la devolución; los cambios transaccionales se revirtieron.'; }
    }
}

if ($lista) {
    try {
        if ($ventaId) {
            $stmt=$pdo->prepare('SELECT v.venta_id,v.bodega_id,v.fecha_venta,v.total,v.descuento_valor,b.nombre AS bodega
                FROM ventas v INNER JOIN bodegas b ON b.bodega_id=v.bodega_id WHERE v.venta_id=:id AND v.estado=\'CONFIRMADA\'');
            $stmt->execute(['id'=>$ventaId]); $venta=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if ($venta) {
                if (!$esAdmin) corozao_exigir_bodega($pdo,$usuario,$venta['bodega_id']);
                $stmt=$pdo->prepare('SELECT vd.venta_detalle_id,vd.producto_id,p.nombre AS producto,vd.cantidad_base,vd.total_bruto,
                    COALESCE((SELECT SUM(dd.cantidad_base) FROM devolucion_detalles dd INNER JOIN devoluciones d ON d.devolucion_id=dd.devolucion_id
                        WHERE d.venta_id=vd.venta_id AND d.estado IN (\'PENDIENTE\',\'APROBADA\') AND dd.venta_detalle_id=vd.venta_detalle_id),0) AS reservado
                    FROM venta_detalles vd INNER JOIN productos p ON p.producto_id=vd.producto_id WHERE vd.venta_id=:venta ORDER BY p.nombre,vd.venta_detalle_id');
                $stmt->execute(['venta'=>$ventaId]); $lineasVenta=$stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        if ($esAdmin && $bodegas) {
            $ids=array_map(static fn($b)=>(int)$b['bodega_id'],$bodegas); $marks=implode(',',array_fill(0,count($ids),'?'));
            $stmt=$pdo->prepare('SELECT d.devolucion_id,d.venta_id,d.motivo,d.fecha_solicitud,u.nombre_completo AS solicitante,
                    b.nombre AS bodega,v.total
                FROM devoluciones d INNER JOIN ventas v ON v.venta_id=d.venta_id INNER JOIN bodegas b ON b.bodega_id=v.bodega_id
                INNER JOIN usuarios u ON u.usuario_id=d.solicitada_por_usuario_id
                WHERE d.estado=\'PENDIENTE\' AND v.bodega_id IN ('.$marks.') ORDER BY d.fecha_solicitud,d.devolucion_id LIMIT 100');
            $stmt->execute($ids); $pendientes=$stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $sqlHistorial='SELECT d.devolucion_id,d.venta_id,d.estado,d.motivo,d.reembolso_total,d.fecha_solicitud,d.fecha_resolucion,
                u.nombre_completo AS solicitante,ru.nombre_completo AS resolutor,b.nombre AS bodega,d.motivo_resolucion
            FROM devoluciones d INNER JOIN ventas v ON v.venta_id=d.venta_id INNER JOIN bodegas b ON b.bodega_id=v.bodega_id
            INNER JOIN usuarios u ON u.usuario_id=d.solicitada_por_usuario_id
            LEFT JOIN usuarios ru ON ru.usuario_id=d.resuelta_por_usuario_id WHERE ';
        if ($esAdmin && $bodegas) {
            $ids=array_map(static fn($b)=>(int)$b['bodega_id'],$bodegas);
            $sqlHistorial.='v.bodega_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
            $stmt=$pdo->prepare($sqlHistorial.' ORDER BY d.fecha_solicitud DESC LIMIT 100'); $stmt->execute($ids);
        } else {
            $stmt=$pdo->prepare($sqlHistorial.'d.solicitada_por_usuario_id=:usuario ORDER BY d.fecha_solicitud DESC LIMIT 100');
            $stmt->execute(['usuario'=>$usuario['usuario_id']]);
        }
        $historial=$stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('Corozao: no se pudo consultar devoluciones.'); http_response_code(500); $error='No se pudo consultar las devoluciones.'; }
}
$clave=devolucion_uuid();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><title>Devoluciones | Corozao</title></head>
<body class="bg-body-tertiary"><nav class="navbar bg-white border-bottom"><div class="container-xxl"><a class="navbar-brand fw-semibold" href="/">Corozao</a><div><span class="badge text-bg-primary"><?=devolucion_h($usuario['rol'])?></span> <a class="btn btn-sm btn-outline-secondary ms-2" href="/ventas.php">Ventas y caja</a> <a class="btn btn-sm btn-outline-secondary" href="/">Inicio</a></div></div></nav>
<main class="container-xxl py-4"><header class="mb-4"><p class="text-primary fw-semibold mb-1">Operación auditada</p><h1 class="h2">Devoluciones</h1><p class="text-body-secondary">El administrador autoriza cada devolución. Los reembolsos vuelven proporcionalmente a los medios originales; solo se reintegra a existencias lo que el administrador confirme como vendible.</p></header>
<?php if($mensaje):?><div class="alert alert-success" role="status"><?=devolucion_h($mensaje)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger" role="alert"><?=devolucion_h($error)?></div><?php endif;?>
<?php if(!$lista):?><div class="alert alert-warning">Módulo pendiente de activar. Aplica manualmente <code>migrations/20261028_devoluciones.sql</code>. Esta página no ejecuta migraciones.</div><?php else:?>
<?php if(!$esAdmin):?><section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Solicitar devolución de venta</h2></div><div class="card-body"><form method="get" class="row g-2 align-items-end"><div class="col-12 col-md-9"><label class="form-label" for="venta">Número de venta</label><input class="form-control" type="number" min="1" id="venta" name="venta" value="<?=devolucion_h($ventaId??'')?>" required></div><div class="col-12 col-md-3"><button class="btn btn-outline-primary w-100">Consultar venta</button></div></form></div></section><?php elseif($ventaId):?><section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Venta #<?= (int)$ventaId ?></h2></div><div class="card-body"><form method="get" class="row g-2 align-items-end"><div class="col-12 col-md-9"><label class="form-label" for="venta">Número de venta</label><input class="form-control" type="number" min="1" id="venta" name="venta" value="<?=devolucion_h($ventaId)?>" required></div><div class="col-12 col-md-3"><button class="btn btn-outline-primary w-100">Consultar</button></div></form></div></section><?php else:?><section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Buscar venta para solicitar devolución</h2></div><div class="card-body"><form method="get" class="row g-2 align-items-end"><div class="col-12 col-md-9"><label class="form-label" for="venta">Número de venta</label><input class="form-control" type="number" min="1" id="venta" name="venta" required></div><div class="col-12 col-md-3"><button class="btn btn-outline-primary w-100">Consultar</button></div></form></div></section><?php endif;?>
<?php if($venta&&$lineasVenta):?><section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Venta #<?=(int)$venta['venta_id']?> · <?=devolucion_h($venta['bodega'])?></h2><small class="text-body-secondary"><?=devolucion_h($venta['fecha_venta'])?> · Total $<?=devolucion_h($venta['total'])?> · descuento fijo $<?=devolucion_h($venta['descuento_valor'])?></small></div><div class="card-body"><form method="post" onsubmit="return confirm('¿Enviar esta solicitud al administrador? No se modificarán caja ni inventario hasta su aprobación.')"><input type="hidden" name="csrf_token" value="<?=devolucion_h($csrf)?>"><input type="hidden" name="accion" value="solicitar"><input type="hidden" name="venta_id" value="<?=(int)$venta['venta_id']?>"><input type="hidden" name="clave_idempotencia" value="<?=devolucion_h($clave)?>"><div class="table-responsive"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>Producto</th><th>Vendido</th><th>Ya solicitado o devuelto</th><th>Disponible</th><th>Cantidad a devolver</th></tr></thead><tbody><?php foreach($lineasVenta as $l):$vendido=corozao_cantidad_milesima((string)$l['cantidad_base']);$reservado=corozao_cantidad_milesima((string)$l['reservado']);$disponible=max(0,$vendido-$reservado);?><tr><td><?=devolucion_h($l['producto'])?></td><td><?=devolucion_h($l['cantidad_base'])?></td><td><?=devolucion_h($l['reservado'])?></td><td><?=devolucion_h(intdiv($disponible,1000).'.'.str_pad((string)($disponible%1000),3,'0',STR_PAD_LEFT))?></td><td><input class="form-control" type="number" min="0" max="<?=$disponible/1000?>" step="0.001" name="cantidades[<?=(int)$l['venta_detalle_id']?>]" value="0.000" aria-label="Cantidad devuelta de <?=devolucion_h($l['producto'])?>"></td></tr><?php endforeach;?></tbody></table></div><div class="mb-3"><label class="form-label" for="motivo">Motivo de la devolución</label><textarea class="form-control" id="motivo" name="motivo" maxlength="500" rows="2" required></textarea></div><button class="btn btn-primary" type="submit">Enviar solicitud</button></form></div></section><?php elseif($ventaId&&!$venta):?><div class="alert alert-warning">No se encontró una venta confirmada visible para tus bodegas.</div><?php endif;?>
<?php if($esAdmin):?><section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Solicitudes pendientes de aprobación</h2></div><?php if(!$pendientes):?><div class="card-body text-body-secondary">No hay solicitudes pendientes.</div><?php else:?><div class="accordion accordion-flush" id="devoluciones-pendientes"><?php foreach($pendientes as $i=>$d):$stmt=$pdo->prepare('SELECT dd.devolucion_detalle_id,dd.cantidad_base,vd.producto_id,p.nombre AS producto,p.activo,vd.cantidad_base AS vendido FROM devolucion_detalles dd INNER JOIN venta_detalles vd ON vd.venta_detalle_id=dd.venta_detalle_id INNER JOIN productos p ON p.producto_id=vd.producto_id WHERE dd.devolucion_id=:id ORDER BY p.nombre');$stmt->execute(['id'=>$d['devolucion_id']]);$items=$stmt->fetchAll(PDO::FETCH_ASSOC);$stmt=$pdo->prepare('SELECT medio,CAST(SUM(monto) AS DECIMAL(14,2)) AS monto FROM venta_pagos WHERE venta_id=(SELECT venta_id FROM devoluciones WHERE devolucion_id=:id) GROUP BY medio ORDER BY medio');$stmt->execute(['id'=>$d['devolucion_id']]);$originales=$stmt->fetchAll(PDO::FETCH_ASSOC);?><article class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#devolucion-<?=$i?>">Devolución #<?=(int)$d['devolucion_id']?> · Venta #<?=(int)$d['venta_id']?> · <?=devolucion_h($d['bodega'])?> · $<?=devolucion_h($d['total'])?></button></h3><div id="devolucion-<?=$i?>" class="accordion-collapse collapse" data-bs-parent="#devoluciones-pendientes"><div class="accordion-body"><p><strong>Solicitó:</strong> <?=devolucion_h($d['solicitante'])?> · <?=devolucion_h($d['fecha_solicitud'])?><br><strong>Motivo:</strong> <?=devolucion_h($d['motivo'])?><br><strong>Pagos originales:</strong> <?php foreach($originales as $p):?><?=devolucion_h($p['medio'])?> $<?=devolucion_h($p['monto'])?> · <?php endforeach;?></p><form method="post" onsubmit="return confirm('¿Aprobar la devolución? Se reembolsará desde tu caja abierta en esta bodega; el stock solo se reintegra para productos marcados vendibles.')"><input type="hidden" name="csrf_token" value="<?=devolucion_h($csrf)?>"><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="devolucion_id" value="<?=(int)$d['devolucion_id']?>"><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Producto</th><th>Cantidad</th><th>Estado comercial</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><?=devolucion_h($item['producto'])?><?=((int)$item['activo']!==1)?' <span class="badge text-bg-secondary">Inactivo</span>':''?></td><td><?=devolucion_h($item['cantidad_base'])?></td><td><select class="form-select form-select-sm" name="vendibles[<?=(int)$item['devolucion_detalle_id']?>]" required><option value="">Selecciona</option><option value="1" <?=((int)$item['activo']!==1)?'disabled':''?>>Vendible, reintegrar a stock</option><option value="0">No vendible, no reintegrar</option></select></td></tr><?php endforeach;?></tbody></table></div><div class="row g-2 mb-3"><?php foreach(['TRANSFERENCIA'=>'Referencia transferencia','TARJETA_CREDITO'=>'Referencia tarjeta'] as $medio=>$label):?><div class="col-12 col-md-6"><label class="form-label" for="ref-<?=$i?>-<?=$medio?>"><?=devolucion_h($label)?></label><input class="form-control" id="ref-<?=$i?>-<?=$medio?>" name="referencias[<?=$medio?>]" maxlength="100" placeholder="Se exige si se reembolsa por este medio"></div><?php endforeach;?></div><button class="btn btn-success" type="submit">Aprobar y registrar reembolso</button></form><form method="post" class="mt-3" onsubmit="return confirm('¿Rechazar esta solicitud?')"><input type="hidden" name="csrf_token" value="<?=devolucion_h($csrf)?>"><input type="hidden" name="accion" value="rechazar"><input type="hidden" name="devolucion_id" value="<?=(int)$d['devolucion_id']?>"><div class="input-group"><input class="form-control" name="motivo_rechazo" maxlength="500" placeholder="Motivo obligatorio del rechazo" required><button class="btn btn-outline-danger">Rechazar</button></div></form></div></div></article><?php endforeach;?></div><?php endif;?></section><?php endif;?>
<section class="card shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Mis solicitudes recientes</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Solicitud / venta</th><th>Bodega</th><th>Estado</th><th>Reembolso</th><th>Solicitante / resolutor</th><th>Fechas</th></tr></thead><tbody><?php foreach($historial as $d):?><tr><td>#<?=(int)$d['devolucion_id']?> / #<?=(int)$d['venta_id']?></td><td><?=devolucion_h($d['bodega'])?></td><td><span class="badge <?=$d['estado']==='APROBADA'?'text-bg-success':($d['estado']==='RECHAZADA'?'text-bg-danger':'text-bg-warning')?>"><?=devolucion_h($d['estado'])?></span><br><small><?=devolucion_h($d['motivo'])?></small><?php if($d['motivo_resolucion']):?><br><small class="text-body-secondary">Resolución: <?=devolucion_h($d['motivo_resolucion'])?></small><?php endif;?></td><td>$<?=devolucion_h($d['reembolso_total'])?></td><td><?=devolucion_h($d['solicitante'])?><?= $d['resolutor']?' / '.devolucion_h($d['resolutor']):''?></td><td><?=devolucion_h($d['fecha_solicitud'])?><br><?=devolucion_h($d['fecha_resolucion']??'Pendiente')?></td></tr><?php endforeach;?><?php if(!$historial):?><tr><td colspan="6" class="text-center text-body-secondary py-4">No tienes solicitudes recientes.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script></body></html>
