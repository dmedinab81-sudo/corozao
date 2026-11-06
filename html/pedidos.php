<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('COROZAO_SESSID');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
]);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');

require '/etc/corozao/database.php';
require_once __DIR__ . '/includes/autorizacion.php';
require_once __DIR__ . '/includes/pedidos.php';

function pedido_h(mixed $valor): string
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pedido_cantidad_vista(mixed $valor): string
{
    $texto = (string) ($valor ?? '');
    if (preg_match('/^([0-9]+)(?:\.0{1,3})$/', $texto, $coincidencia)) {
        $entero = ltrim($coincidencia[1], '0');
        return $entero === '' ? '0' : $entero;
    }
    return $texto;
}

function pedido_opcion(array $item): string
{
    $tratamiento = (int) $item['precio_incluye_iva'] === 1 ? 'incluye IVA' : 'más IVA';
    return $item['producto'] . ' · ' . $item['presentacion'] . ' — $' . number_format((float) $item['precio_venta'], 2, '.', ',')
        . ' (' . $tratamiento . ' ' . $item['tarifa_iva'] . '%)';
}

function pedido_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

$usuario = corozao_usuario_sesion($pdo);
if ($usuario === null) {
    $usuario = corozao_exigir_permiso($pdo, 'orders.own.view');
}
$permisoVista = $usuario['rol'] === 'CAJERO' ? 'orders.pending_billing.view' : 'orders.own.view';
$usuario = corozao_exigir_permiso($pdo, $permisoVista);
$rol = (string) $usuario['rol'];
$esAdmin = $rol === 'ADMINISTRADOR';
$esCajero = $rol === 'CAJERO';
if (!$esCajero) {
    corozao_exigir_permiso($pdo, 'orders.catalog.view');
}
$csrf = $_SESSION['csrf_token'] ?? null;
if (!is_string($csrf) || strlen($csrf) < 32) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf_token'];
}
$error = null;
$mensaje = $_SESSION['pedidos_flash'] ?? null;
unset($_SESSION['pedidos_flash']);
$listo = false;
$pedidos = [];
$pedidoEditar = null;
$lineasEditar = [];
$bodegas = corozao_bodegas_asignadas($pdo, $usuario);

try {
    $listo = corozao_pedidos_instalados($pdo);
} catch (Throwable $e) {
    error_log('Corozao: no se pudo validar el esquema de pedidos.');
    http_response_code(503);
    $error = 'No se pudo validar el esquema. Inténtalo más tarde.';
}

$bodegaRaw = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['bodega_id'] ?? '') : ($_GET['bodega'] ?? '');
$bodegaId = is_string($bodegaRaw) ? filter_var($bodegaRaw, FILTER_VALIDATE_INT) : false;
$bodegaId = $bodegaId && $bodegaId > 0 ? (int) $bodegaId : null;
if ($bodegaId !== null) {
    corozao_exigir_bodega($pdo, $usuario, $bodegaId);
} elseif ($bodegas && !$esCajero) {
    $bodegaId = (int) $bodegas[0]['bodega_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $listo) {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !corozao_csrf_valido($token)) {
        http_response_code(403);
        $error = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
    } else {
        try {
            $accion = $_POST['accion'] ?? '';
            if (!is_string($accion)) {
                throw new DomainException('Acción no válida.');
            }
            if ($accion === 'crear') {
                $contexto = corozao_exigir_permiso($pdo, 'orders.open.create');
                $bodegaPost = $_POST['bodega_id'] ?? '';
                $bodegaCrear = is_string($bodegaPost) ? filter_var($bodegaPost, FILTER_VALIDATE_INT) : false;
                if (!$bodegaCrear || $bodegaCrear < 1) {
                    throw new DomainException('Selecciona una bodega autorizada.');
                }
                corozao_exigir_bodega($pdo, $contexto, $bodegaCrear);
                $entradas = $_POST['lineas'] ?? null;
                if (!is_array($entradas)) {
                    throw new DomainException('Agrega productos al pedido.');
                }
                $referencia = $_POST['referencia_cliente'] ?? '';
                $observaciones = $_POST['observaciones'] ?? '';
                $clave = $_POST['clave_idempotencia'] ?? '';
                if (!is_string($referencia) || !is_string($observaciones) || !is_string($clave)) {
                    throw new DomainException('Los datos del pedido no son válidos.');
                }
                $id = corozao_pedido_crear(
                    $pdo, (int) $contexto['usuario_id'], (int) $bodegaCrear, $entradas,
                    trim($referencia), trim($observaciones), $clave
                );
                $_SESSION['pedidos_flash'] = 'Pedido #' . $id . ' creado. El inventario no se reservó.';
                header('Location: /pedidos.php', true, 303);
                exit;
            }

            $pedidoRaw = $_POST['pedido_id'] ?? '';
            $pedidoId = is_string($pedidoRaw) ? filter_var($pedidoRaw, FILTER_VALIDATE_INT) : false;
            if (!$pedidoId || $pedidoId < 1) {
                throw new DomainException('El pedido seleccionado no es válido.');
            }
            $stmt = $pdo->prepare('SELECT pedido_id, usuario_id, bodega_id FROM pedidos WHERE pedido_id = :id');
            $stmt->execute(['id' => $pedidoId]);
            $pedidoPost = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pedidoPost) {
                throw new DomainException('El pedido no existe.');
            }
            corozao_exigir_bodega($pdo, $usuario, $pedidoPost['bodega_id']);
            if (!$esAdmin && (int) $pedidoPost['usuario_id'] !== (int) $usuario['usuario_id']) {
                throw new DomainException('Solo puedes cambiar los pedidos que creaste.');
            }

            if ($accion === 'actualizar') {
                $contexto = corozao_exigir_permiso($pdo, 'orders.open.modify_own');
                $entradas = $_POST['lineas'] ?? null;
                $referencia = $_POST['referencia_cliente'] ?? '';
                $observaciones = $_POST['observaciones'] ?? '';
                if (!is_array($entradas) || !is_string($referencia) || !is_string($observaciones)) {
                    throw new DomainException('Los datos del pedido no son válidos.');
                }
                corozao_pedido_actualizar(
                    $pdo, (int) $pedidoId, (int) $contexto['usuario_id'], $esAdmin,
                    $entradas, trim($referencia), trim($observaciones)
                );
                $_SESSION['pedidos_flash'] = 'Pedido #' . $pedidoId . ' actualizado.';
            } elseif ($accion === 'enviar' || $accion === 'solicitar_cierre') {
                $permiso = $accion === 'enviar' ? 'orders.send' : 'orders.finalization.request';
                $contexto = corozao_exigir_permiso($pdo, $permiso);
                corozao_pedido_transicionar($pdo, (int) $pedidoId, (int) $contexto['usuario_id'], $accion);
                $_SESSION['pedidos_flash'] = $accion === 'enviar'
                    ? 'Pedido #' . $pedidoId . ' enviado a preparación.'
                    : 'Pedido #' . $pedidoId . ' enviado a la cola de facturación.';
            } else {
                http_response_code(400);
                throw new DomainException('Acción no válida.');
            }
            header('Location: /pedidos.php', true, 303);
            exit;
        } catch (DomainException $e) {
            http_response_code(422);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Corozao: error al guardar un pedido.');
            http_response_code(500);
            $error = 'No se pudo guardar el pedido; no se confirmaron cambios parciales.';
        }
    }
}

$editarRaw = $_GET['editar'] ?? '';
$editarId = is_string($editarRaw) ? filter_var($editarRaw, FILTER_VALIDATE_INT) : false;
if ($listo && !$esCajero && $editarId && $editarId > 0) {
    try {
        corozao_exigir_permiso($pdo, 'orders.open.modify_own');
        $stmt = $pdo->prepare('SELECT * FROM pedidos WHERE pedido_id = :id');
        $stmt->execute(['id' => $editarId]);
        $pedidoEditar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$pedidoEditar || (!$esAdmin && (int) $pedidoEditar['usuario_id'] !== (int) $usuario['usuario_id'])
            || !in_array($pedidoEditar['estado'], ['ABIERTO', 'EN_PREPARACION'], true)) {
            $pedidoEditar = null;
            throw new DomainException('El pedido no está disponible para edición.');
        }
        corozao_exigir_bodega($pdo, $usuario, $pedidoEditar['bodega_id']);
        $bodegaId = (int) $pedidoEditar['bodega_id'];
        $lineasEditar = corozao_pedido_snapshot_detalles($pdo, (int) $editarId);
    } catch (DomainException $e) {
        http_response_code(404);
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Corozao: no se pudo cargar el pedido para edición.');
        http_response_code(500);
        $error = 'No se pudo cargar el pedido.';
    }
}

if ($listo) {
    try {
        $sql = 'SELECT p.pedido_id, p.bodega_id, p.estado, p.referencia_cliente, p.observaciones,
                       p.fecha_creacion, p.fecha_envio_preparacion, p.fecha_solicitud_cierre,
                       b.nombre AS bodega, u.nombre_completo AS creador, ua.nombre_completo AS actualizador,
                       uc.nombre_completo AS solicita_cierre,
                       (SELECT COUNT(*) FROM pedido_detalles d WHERE d.pedido_id = p.pedido_id) AS lineas,
                       (SELECT COALESCE(SUM(d.subtotal_linea), 0) FROM pedido_detalles d WHERE d.pedido_id = p.pedido_id) AS total,
                       (SELECT GROUP_CONCAT(CONCAT(dp.nombre, \' · \' , pr.nombre, \' × \' ,
                               TRIM(TRAILING \'.\' FROM TRIM(TRAILING \'0\' FROM CAST(d.cantidad_presentacion AS CHAR))))
                           ORDER BY pr.nombre SEPARATOR \'; \')
                        FROM pedido_detalles d INNER JOIN presentaciones pr ON pr.presentacion_id = d.presentacion_id
                        INNER JOIN productos dp ON dp.producto_id = d.producto_id
                        WHERE d.pedido_id = p.pedido_id) AS resumen
                FROM pedidos p INNER JOIN bodegas b ON b.bodega_id = p.bodega_id
                INNER JOIN usuarios u ON u.usuario_id = p.usuario_id
                INNER JOIN usuarios ua ON ua.usuario_id = p.actualizado_por_usuario_id
                LEFT JOIN usuarios uc ON uc.usuario_id = p.solicita_cierre_por_usuario_id
                WHERE p.bodega_id IN (' . implode(',', array_fill(0, count($bodegas), '?')) . ')';
        $params = array_map(static fn (array $b): int => (int) $b['bodega_id'], $bodegas);
        if ($esCajero) {
            $sql .= ' AND p.estado = \'PENDIENTE_FACTURACION\'';
        } elseif (!$esAdmin) {
            $sql .= ' AND p.usuario_id = ?';
            $params[] = (int) $usuario['usuario_id'];
        }
        if ($bodegaId !== null) {
            $sql .= ' AND p.bodega_id = ?';
            $params[] = $bodegaId;
        }
        $sql .= ' ORDER BY FIELD(p.estado, \'PENDIENTE_FACTURACION\', \'EN_PREPARACION\', \'ABIERTO\'),
                          p.fecha_actualizacion DESC, p.pedido_id DESC LIMIT 200';
        if ($bodegas) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('Corozao: no se pudo cargar el catálogo o la lista de pedidos.');
        http_response_code(500);
        $error = 'No se pudo cargar la información de pedidos.';
    }
}

$claveNueva = pedido_uuid();
$formLineas = $lineasEditar;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Corozao | Pedidos</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/brand/corozao-mark.svg?v=2">
    <link rel="stylesheet" href="/assets/css/brand.css">
</head>
<body data-corozao-role="<?= htmlspecialchars((string)($_SESSION['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bg-body-tertiary">
<nav class="navbar bg-white border-bottom"><div class="container-xxl">
    <a class="navbar-brand fw-semibold" href="/">Corozao</a><span class="badge text-bg-primary"><?= pedido_h($rol) ?></span>
</div></nav>
<main class="container-xxl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><p class="text-primary fw-semibold mb-1">Atención al cliente</p><h1 class="h2 mb-0"><?= $esCajero ? 'Pedidos pendientes de facturación' : 'Pedidos' ?></h1></div>
        <a class="btn btn-outline-secondary" href="/">Volver al panel</a>
    </div>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= pedido_h($error) ?></div><?php endif; ?>
    <?php if ($mensaje !== null): ?><div class="alert alert-success" role="status"><?= pedido_h($mensaje) ?></div><?php endif; ?>
    <?php if (!$bodegas): ?><div class="alert alert-warning" role="status">No tienes bodegas activas asignadas.</div><?php endif; ?>
    <?php if (!$listo): ?>
        <div class="alert alert-warning" role="status">Módulo pendiente de habilitación: aplica manualmente <code>migrations/20261025_pedidos.sql</code>. No se han guardado pedidos.</div>
    <?php endif; ?>
    <?php if ($listo && !$esCajero && !$pedidoEditar && count($bodegas) > 1): ?>
        <form method="get" class="card card-body mb-3"><label class="form-label" for="filtro-bodega">Bodega para nuevo pedido y lista</label>
            <div class="d-flex gap-2"><select class="form-select" id="filtro-bodega" name="bodega"><?php foreach ($bodegas as $bodega): ?><option value="<?= (int) $bodega['bodega_id'] ?>" <?= (int) $bodega['bodega_id'] === $bodegaId ? 'selected' : '' ?>><?= pedido_h($bodega['nombre']) ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary" type="submit">Ver</button></div>
        </form>
    <?php endif; ?>
    <?php if ($listo && !$esCajero && $bodegaId !== null && (!$editarId || $pedidoEditar)): ?>
        <section class="card shadow-sm mb-4" aria-labelledby="form-pedido">
            <div class="card-header bg-white"><h2 id="form-pedido" class="h5 mb-0"><?= $pedidoEditar ? 'Editar pedido #' . (int) $pedidoEditar['pedido_id'] : 'Nuevo pedido' ?></h2></div>
            <div class="card-body">
                <p class="small text-body-secondary">El pedido conserva los precios e impuestos vigentes al agregar cada presentación. No reserva existencias; estas se validarán al facturar.</p>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= pedido_h($csrf) ?>">
                    <input type="hidden" name="accion" value="<?= $pedidoEditar ? 'actualizar' : 'crear' ?>">
                    <input type="hidden" name="bodega_id" value="<?= (int) $bodegaId ?>">
                    <?php if ($pedidoEditar): ?><input type="hidden" name="pedido_id" value="<?= (int) $pedidoEditar['pedido_id'] ?>">
                    <?php else: ?><input type="hidden" name="clave_idempotencia" value="<?= pedido_h($claveNueva) ?>"><?php endif; ?>
                    <div class="col-12 col-md-6"><label class="form-label" for="bodega-visible">Bodega</label>
                        <select class="form-select" id="bodega-visible" disabled><?php foreach ($bodegas as $bodega): ?><option value="<?= (int) $bodega['bodega_id'] ?>" <?= (int) $bodega['bodega_id'] === $bodegaId ? 'selected' : '' ?>><?= pedido_h($bodega['nombre']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="col-12 col-md-6"><label class="form-label" for="referencia">Cliente o referencia</label>
                        <input class="form-control" id="referencia" name="referencia_cliente" maxlength="100" value="<?= pedido_h($pedidoEditar['referencia_cliente'] ?? '') ?>" placeholder="Opcional">
                    </div>
                    <div class="col-12" data-product-selector data-operation="pedido" data-warehouse="<?= (int)$bodegaId ?>">
                        <label class="form-label" for="buscar-producto-pedido">Buscar producto, código o presentación</label>
                        <input class="form-control" id="buscar-producto-pedido" data-product-query autocomplete="off" aria-controls="resultados-pedido" placeholder="Escribe al menos dos caracteres">
                        <div id="resultados-pedido" data-product-results class="list-group mt-1" role="listbox" hidden></div>
                    </div>
                    <div class="col-12"><div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead class="table-light"><tr><th>Producto / identificación</th><th>Cantidad</th><th>Precio unitario</th><th>IVA</th><th>Total referencial</th><th></th></tr></thead><tbody id="detalle-pedido">
                    <?php foreach ($formLineas as $i => $linea): ?><tr data-detail-row data-existing-line="1" data-price="<?= number_format((float)$linea['precio_unitario_presentacion'], 2, '.', ',') ?>" data-iva="<?= pedido_h($linea['tarifa_iva']) ?>" data-includes-iva="<?= (int)$linea['precio_incluye_iva'] ?>"><td><?= pedido_h(implode(' · ', array_filter([$linea['codigos'] ?? '', $linea['producto'] ?? '', $linea['marca'] ?? '', $linea['contenido'] ?? '', $linea['categoria'] ?? '', $linea['presentacion'] ?? '']))) ?><input type="hidden" name="lineas[<?= $i ?>][presentacion_id]" value="<?= (int)$linea['presentacion_id'] ?>"></td><td><input class="form-control form-control-sm" type="number" min="1" step="1" name="lineas[<?= $i ?>][cantidad]" value="<?= pedido_h(pedido_cantidad_vista($linea['cantidad_presentacion'])) ?>" required></td><td>$<?= number_format((float)$linea['precio_unitario_presentacion'], 2, '.', ',') ?></td><td><?= pedido_h($linea['tarifa_iva']) ?>%</td><td data-line-total>—</td><td><input type="hidden" name="lineas[<?= $i ?>][eliminar]" value="0"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-line>Quitar</button></td></tr><?php endforeach; ?>
                    </tbody><tfoot><tr><th colspan="4" class="text-end">Total referencial</th><th id="total-referencial">$0.00</th><th></th></tr></tfoot></table></div></div>
                    <div class="col-12"><label class="form-label" for="observaciones">Observaciones</label>
                        <textarea class="form-control" id="observaciones" name="observaciones" maxlength="500" rows="2"><?= pedido_h($pedidoEditar['observaciones'] ?? '') ?></textarea>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><?= $pedidoEditar ? 'Guardar cambios' : 'Crear pedido' ?></button>
                        <?php if ($pedidoEditar): ?><a class="btn btn-outline-secondary" href="/pedidos.php">Cancelar edición</a><?php endif; ?></div>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($listo): ?>
        <section class="card shadow-sm" aria-labelledby="lista-pedidos">
            <div class="card-header bg-white"><h2 id="lista-pedidos" class="h5 mb-0"><?= $esCajero ? 'Cola de facturación' : 'Pedidos recientes' ?></h2></div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>ID / fecha</th><th>Estado</th><th>Bodega</th><th>Referencia</th><th>Productos</th><th>Total referencial</th><th>Creado / actualizado por</th><th>Acciones</th></tr></thead>
                <tbody><?php foreach ($pedidos as $pedido): ?><tr>
                    <td>#<?= (int) $pedido['pedido_id'] ?><br><small><?= pedido_h($pedido['fecha_creacion']) ?></small></td>
                    <td><span class="badge text-bg-<?= $pedido['estado'] === 'PENDIENTE_FACTURACION' ? 'warning' : ($pedido['estado'] === 'EN_PREPARACION' ? 'info' : 'secondary') ?>"><?= pedido_h($pedido['estado']) ?></span></td>
                    <td><?= pedido_h($pedido['bodega']) ?></td><td><?= pedido_h($pedido['referencia_cliente'] ?? '—') ?></td>
                    <td><?= pedido_h($pedido['resumen'] ?? '—') ?></td><td>$<?= pedido_h($pedido['total']) ?></td>
                    <td><?= pedido_h($pedido['creador']) ?><br><small>Último cambio: <?= pedido_h($pedido['actualizador']) ?></small><?php if ($pedido['solicita_cierre']): ?><br><small>Solicitó cierre: <?= pedido_h($pedido['solicita_cierre']) ?></small><?php endif; ?></td>
                    <td>
                        <?php if (!$esCajero && in_array($pedido['estado'], ['ABIERTO', 'EN_PREPARACION'], true)): ?>
                            <a class="btn btn-sm btn-outline-primary mb-1" href="/pedidos.php?<?= http_build_query(['bodega' => $pedido['bodega_id'], 'editar' => $pedido['pedido_id']]) ?>">Editar</a>
                            <?php if ($pedido['estado'] === 'ABIERTO'): ?>
                                <form method="post" class="mb-1"><input type="hidden" name="csrf_token" value="<?= pedido_h($csrf) ?>"><input type="hidden" name="accion" value="enviar"><input type="hidden" name="pedido_id" value="<?= (int) $pedido['pedido_id'] ?>"><button class="btn btn-sm btn-outline-info" type="submit">Enviar a preparación</button></form>
                            <?php endif; ?>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= pedido_h($csrf) ?>"><input type="hidden" name="accion" value="solicitar_cierre"><input type="hidden" name="pedido_id" value="<?= (int) $pedido['pedido_id'] ?>"><button class="btn btn-sm btn-outline-success" type="submit">Solicitar facturación</button></form>
                        <?php elseif ($pedido['estado'] === 'PENDIENTE_FACTURACION'): ?>
                            <span class="text-body-secondary d-block mb-1">Pendiente de facturar</span>
                            <?php if (corozao_rol_puede((string) $usuario['rol'], 'orders.convert_to_sale')): ?>
                                <a class="btn btn-sm btn-primary" href="/ventas.php?cobrar=<?= (int) $pedido['pedido_id'] ?>">Continuar al cobro</a>
                            <?php endif; ?>
                        <?php else: ?><span class="text-body-secondary">En preparación</span><?php endif; ?>
                    </td>
                </tr><?php endforeach; ?>
                <?php if (!$pedidos): ?><tr><td colspan="8" class="text-center text-body-secondary py-4">No hay pedidos para mostrar.</td></tr><?php endif; ?></tbody>
            </table></div>
            <div class="card-footer bg-white small text-body-secondary">Los pedidos no reservan inventario. El cajero verificará disponibilidad al facturar; el precio capturado al agregar el producto se conserva como referencia del pedido.</div>
        </section>
    <?php endif; ?>
</main>
<script src="/assets/product-selector.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const root=document.querySelector('[data-product-selector]'),body=document.getElementById('detalle-pedido');let next=body.querySelectorAll('[data-detail-row]').length;
 const recalc=()=>{let total=0;body.querySelectorAll('[data-detail-row]').forEach(tr=>{if(tr.querySelector('input[name$="[eliminar]"]')?.value==='1'){tr.querySelector('[data-line-total]').textContent='$0.00';return;}const q=Number(tr.querySelector('input[name$="[cantidad]"]').value||0),price=Number(tr.dataset.price||0),iva=Number(tr.dataset.iva||0),gross=price*q*(tr.dataset.includesIva==='1'?1:1+iva/100);tr.querySelector('[data-line-total]').textContent=`$${gross.toFixed(2)}`;total+=gross;});document.getElementById('total-referencial').textContent=`$${total.toFixed(2)}`;};
 root?.addEventListener('product:selected',e=>{const p=e.detail,existing=[...body.querySelectorAll('input[name$="[presentacion_id]"]')].find(i=>i.value===String(p.presentacion_id));if(existing){const tr=existing.closest('tr'),removed=tr.querySelector('input[name$="[eliminar]"]');if(removed?.value==='1'){removed.value='0';tr.classList.remove('opacity-50');tr.querySelector('[data-remove-line]').disabled=false;tr.querySelector('input[name$="[cantidad]"]').value='1';recalc();return;}alert('Esta presentación ya está en el pedido.');return;}const i=next++,tr=document.createElement('tr');tr.dataset.detailRow='';tr.dataset.price=p.precio_venta;tr.dataset.iva=p.tarifa_iva;tr.dataset.includesIva=p.precio_incluye_iva;const desc=document.createElement('td');desc.textContent=[p.codigos,p.producto,p.marca,p.contenido,p.categoria,p.presentacion].filter(Boolean).join(' · ');const id=document.createElement('input');id.type='hidden';id.name=`lineas[${i}][presentacion_id]`;id.value=p.presentacion_id;desc.append(id);const qc=document.createElement('td'),q=document.createElement('input');q.type='number';q.min='1';q.step='1';q.required=true;q.name=`lineas[${i}][cantidad]`;q.value='1';q.className='form-control form-control-sm';qc.append(q);const price=document.createElement('td');price.textContent=`$${Number(p.precio_venta).toFixed(2)}`;const iva=document.createElement('td');iva.textContent=`${p.tarifa_iva}%`;const total=document.createElement('td');total.dataset.lineTotal='';const action=document.createElement('td'),hidden=document.createElement('input');hidden.type='hidden';hidden.name=`lineas[${i}][eliminar]`;hidden.value='0';const remove=document.createElement('button');remove.type='button';remove.className='btn btn-sm btn-outline-danger';remove.textContent='Quitar';remove.dataset.removeLine='';action.append(hidden,remove);tr.append(desc,qc,price,iva,total,action);body.append(tr);recalc();q.focus();});
 body.addEventListener('input',recalc);body.addEventListener('click',e=>{const b=e.target.closest('[data-remove-line]');if(!b)return;const tr=b.closest('tr');if(tr.dataset.existingLine){tr.querySelector('input[name$="[eliminar]"]').value='1';tr.classList.add('opacity-50');b.disabled=true;}else tr.remove();recalc();});recalc();
});
</script>
<script src="/assets/product-selector.js" defer></script>
<script src="/assets/brand.js" defer></script>
</body>
</html>
