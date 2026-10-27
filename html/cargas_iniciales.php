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
require_once __DIR__ . '/includes/cargas_iniciales.php';

function carga_h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function carga_uuid(): string
{
    $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

$usuario = corozao_exigir_permiso($pdo, 'inventory.initial_load.create');
$csrf = $_SESSION['csrf_token'] ?? '';
if (!is_string($csrf) || strlen($csrf) < 32) { $csrf=bin2hex(random_bytes(32)); $_SESSION['csrf_token']=$csrf; }
$error = null;
$mensaje = $_SESSION['cargas_flash'] ?? null;
unset($_SESSION['cargas_flash']);
$listo = false;
$conteoId = filter_var($_GET['conteo'] ?? $_POST['conteo_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

try { $listo = corozao_cargas_iniciales_instaladas($pdo); }
catch (Throwable $e) { error_log('Corozao: no se pudo validar esquema de cargas iniciales.'); http_response_code(503); $error='No se pudo validar el esquema de cargas iniciales.'; }

if ($_SERVER['REQUEST_METHOD']==='POST' && $listo) {
    $token=$_POST['csrf_token'] ?? '';
    if (!is_string($token) || !corozao_csrf_valido($token)) { http_response_code(403); $error='La solicitud expiró. Recarga la página.'; }
    else {
        try {
            corozao_exigir_permiso($pdo, 'inventory.initial_load.create');
            $accion=$_POST['accion'] ?? '';
            if ($accion!=='confirmar') throw new DomainException('Acción no válida.');
            $ids=$_POST['detalle_ids'] ?? null;
            $clave=$_POST['clave_idempotencia'] ?? '';
            if (!$conteoId || !is_array($ids) || !is_string($clave)) throw new DomainException('Selecciona un conteo y sus productos físicos.');
            $cargaId=corozao_carga_inicial_confirmar($pdo,$conteoId,(int)$usuario['usuario_id'],$ids,$clave);
            $_SESSION['cargas_flash']='Carga inicial #'.$cargaId.' confirmada. Se registró su movimiento de ingreso y auditoría.';
            header('Location: /cargas_iniciales.php?conteo='.$conteoId); exit;
        } catch (DomainException $e) { http_response_code(422); $error=$e->getMessage(); }
        catch (Throwable $e) { error_log('Corozao: no se pudo confirmar una carga inicial.'); http_response_code(500); $error='No se confirmó la carga. La transacción revirtió sus cambios.'; }
    }
}

$conteos=[]; $conteo=null; $lineas=[]; $cargas=[];
if ($listo) {
    try {
        $conteos=$pdo->query('SELECT ci.conteo_id,ci.bodega_id,b.nombre AS bodega,ci.fecha_cierre,u.nombre_completo AS responsable,
            COUNT(d.conteo_detalle_id) AS productos_contados
            FROM conteos_inventario ci INNER JOIN bodegas b ON b.bodega_id=ci.bodega_id
            INNER JOIN usuarios u ON u.usuario_id=ci.usuario_id
            LEFT JOIN conteo_inventario_detalles d ON d.conteo_id=ci.conteo_id
            WHERE ci.estado=\'CERRADO\' GROUP BY ci.conteo_id
            ORDER BY ci.fecha_cierre DESC,ci.conteo_id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
        if ($conteoId) {
                $stmt=$pdo->prepare('SELECT ci.conteo_id,ci.bodega_id,b.nombre AS bodega,b.activo AS bodega_activa,ci.estado,ci.fecha_cierre
                FROM conteos_inventario ci INNER JOIN bodegas b ON b.bodega_id=ci.bodega_id WHERE ci.conteo_id=:id');
            $stmt->execute(['id'=>$conteoId]); $conteo=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($conteo && $conteo['estado']==='CERRADO') {
                $stmt=$pdo->prepare('SELECT d.conteo_detalle_id,d.producto_id,p.nombre AS producto,p.activo AS producto_activo,
                    d.cantidad_teorica,d.cantidad_fisica,d.fecha_conteo,s.cantidad_base AS saldo_actual,
                    EXISTS(SELECT 1 FROM detalle_movimiento_inventario md INNER JOIN movimientos_inventario m ON m.movimiento_id=md.movimiento_id
                        WHERE md.producto_id=d.producto_id AND (m.bodega_origen_id=:bodega_origen OR m.bodega_destino_id=:bodega_destino)) AS tiene_movimientos,
                    EXISTS(SELECT 1 FROM cargas_inventario_inicial_detalles cid WHERE cid.bodega_id=:bodega_carga AND cid.producto_id=d.producto_id) AS ya_cargado
                    FROM conteo_inventario_detalles d INNER JOIN productos p ON p.producto_id=d.producto_id
                    LEFT JOIN inventario_saldos s ON s.bodega_id=:bodega_saldo AND s.producto_id=d.producto_id
                    WHERE d.conteo_id=:conteo ORDER BY p.nombre LIMIT 1000');
                $stmt->execute(['bodega_origen'=>$conteo['bodega_id'],'bodega_destino'=>$conteo['bodega_id'],
                    'bodega_carga'=>$conteo['bodega_id'],'bodega_saldo'=>$conteo['bodega_id'],'conteo'=>$conteoId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linea) {
                    $razon=null;
                    if ((int)$conteo['bodega_activa']!==1) $razon='La bodega está inactiva';
                    elseif ((int)$linea['producto_activo']!==1) $razon='El producto está inactivo';
                    elseif ((int)$linea['ya_cargado']===1) $razon='Ya se cargó en esta bodega';
                    elseif ((int)$linea['tiene_movimientos']===1) $razon='Tiene movimientos previos en la bodega';
                    elseif ($linea['saldo_actual']!==null && corozao_carga_inicial_milesimas((string)$linea['saldo_actual'])!==0) $razon='Ya tiene saldo';
                    elseif (corozao_carga_inicial_milesimas((string)$linea['cantidad_teorica'])!==0) $razon='El saldo teórico al contar no era cero';
                    elseif (corozao_carga_inicial_milesimas((string)$linea['cantidad_fisica'])<1) $razon='La cantidad física debe ser positiva';
                    $linea['razon_no_elegible']=$razon;
                    $lineas[]=$linea;
                }
                $stmt=$pdo->prepare('SELECT c.carga_inicial_id,c.estado,c.movimiento_id,c.fecha_confirmacion,u.nombre_completo AS responsable,
                    COUNT(d.carga_inicial_detalle_id) AS productos
                    FROM cargas_inventario_inicial c INNER JOIN usuarios u ON u.usuario_id=c.confirmada_por_usuario_id
                    LEFT JOIN cargas_inventario_inicial_detalles d ON d.carga_inicial_id=c.carga_inicial_id
                    WHERE c.conteo_id=:conteo GROUP BY c.carga_inicial_id ORDER BY c.carga_inicial_id DESC');
                $stmt->execute(['conteo'=>$conteoId]); $cargas=$stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($conteo) { $error='La carga inicial requiere un conteo cerrado.'; $conteo=null; }
        }
    } catch (Throwable $e) { error_log('Corozao: no se pudo consultar conteos para carga inicial.'); http_response_code(500); $error='No se pudo consultar la información de conteos.'; }
}
$clave=carga_uuid();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><title>Cargas iniciales | Corozao</title></head>
<body class="bg-body-tertiary"><nav class="navbar bg-white border-bottom"><div class="container-xxl"><a class="navbar-brand fw-semibold" href="/">Corozao</a><span><span class="badge text-bg-primary">ADMINISTRADOR</span> <a class="btn btn-sm btn-outline-secondary ms-2" href="/reportes.php">Reportes</a> <a class="btn btn-sm btn-outline-secondary" href="/">Inicio</a></span></div></nav>
<main class="container-xxl py-4"><header class="mb-4"><p class="text-primary fw-semibold mb-1">Inventario inicial</p><h1 class="h2">Cargas iniciales desde conteos</h1><p class="text-body-secondary">Solo se cargan cantidades físicas de un conteo cerrado cuando no existe saldo ni historial de movimientos en esa bodega. Cada carga genera un ingreso positivo auditado.</p></header>
<?php if($mensaje):?><div class="alert alert-success" role="status"><?=carga_h($mensaje)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger" role="alert"><?=carga_h($error)?></div><?php endif;?>
<?php if(!$listo):?><div class="alert alert-warning">Esquema pendiente. Aplica manualmente <code>migrations/20261027_cargas_inventario_inicial.sql</code>. Esta página no ejecuta migraciones.</div><?php else:?>
<section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Seleccionar conteo cerrado</h2></div><div class="card-body"><form method="get" class="row g-2 align-items-end"><div class="col-12 col-md-9"><label class="form-label" for="conteo">Conteos recientes</label><select class="form-select" id="conteo" name="conteo" required><option value="">Selecciona</option><?php foreach($conteos as $c):?><option value="<?=(int)$c['conteo_id']?>" <?=(int)$c['conteo_id']===$conteoId?'selected':''?>>#<?=(int)$c['conteo_id']?> · <?=carga_h($c['bodega'])?> · <?=carga_h($c['fecha_cierre'])?> · <?=(int)$c['productos_contados']?> productos</option><?php endforeach;?></select></div><div class="col-12 col-md-3"><button class="btn btn-outline-primary w-100" type="submit">Consultar conteo</button></div></form></div></section>
<?php if($conteo):?><section class="card shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Conteo #<?=(int)$conteo['conteo_id']?> · <?=carga_h($conteo['bodega'])?></h2><small class="text-body-secondary">Cerrado <?=carga_h($conteo['fecha_cierre'])?></small></div><div class="card-body"><div class="alert alert-warning">Confirmar modifica existencias. Solo son elegibles productos con saldo teórico cero, cantidad física positiva y sin saldos ni movimientos previos en esta bodega. No se cargan cantidades escritas en este formulario: se usan las lecturas guardadas en el conteo.</div>
<form method="post" onsubmit="return confirm('¿Autorizar y registrar estas cantidades como inventario inicial? Se actualizarán los saldos y se confirmará un movimiento auditado.')"><input type="hidden" name="csrf_token" value="<?=carga_h($csrf)?>"><input type="hidden" name="accion" value="confirmar"><input type="hidden" name="conteo_id" value="<?=(int)$conteo['conteo_id']?>"><input type="hidden" name="clave_idempotencia" value="<?=carga_h($clave)?>"><div class="table-responsive"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>Seleccionar</th><th>Producto</th><th>Teórico del conteo</th><th>Físico contado</th><th>Elegibilidad</th></tr></thead><tbody><?php foreach($lineas as $l):?><tr><td><?php if($l['razon_no_elegible']===null):?><input class="form-check-input" type="checkbox" name="detalle_ids[]" value="<?=(int)$l['conteo_detalle_id']?>" aria-label="Seleccionar <?=carga_h($l['producto'])?>"><?php else:?><span class="text-body-secondary">—</span><?php endif;?></td><td><?=carga_h($l['producto'])?></td><td><?=carga_h($l['cantidad_teorica'])?></td><td><?=carga_h($l['cantidad_fisica'])?></td><td><?=$l['razon_no_elegible']===null?'<span class="badge text-bg-success">Elegible</span>':'<span class="badge text-bg-secondary">'.carga_h($l['razon_no_elegible']).'</span>'?></td></tr><?php endforeach;?><?php if(!$lineas):?><tr><td colspan="5" class="text-center text-body-secondary">Este conteo no tiene líneas.</td></tr><?php endif;?></tbody></table></div><button class="btn btn-primary" type="submit" <?=!array_filter($lineas,static fn($l)=>$l['razon_no_elegible']===null)?'disabled':''?>>Confirmar carga seleccionada</button></form></div></section><?php endif;?>
<?php if($cargas):?><section class="card shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Cargas ya confirmadas desde este conteo</h2></div><div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Carga</th><th>Movimiento</th><th>Productos</th><th>Confirmada por</th><th>Fecha</th></tr></thead><tbody><?php foreach($cargas as $c):?><tr><td>#<?=(int)$c['carga_inicial_id']?></td><td>#<?=(int)$c['movimiento_id']?></td><td><?=(int)$c['productos']?></td><td><?=carga_h($c['responsable'])?></td><td><?=carga_h($c['fecha_confirmacion'])?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
<?php endif;?></main></body></html>
