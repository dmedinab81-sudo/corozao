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
require_once __DIR__ . '/includes/ajustes.php';

function ajuste_h(mixed $valor): string
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ajuste_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

$usuario = corozao_exigir_permiso($pdo, 'inventory.adjustment.view');
$csrf = $_SESSION['csrf_token'] ?? null;
if (!is_string($csrf) || strlen($csrf) < 32) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf_token'];
}
$error = null;
$mensaje = $_SESSION['ajustes_flash'] ?? null;
unset($_SESSION['ajustes_flash']);
$listo = false;
$ajustes = [];
$productos = [];
$bodegas = corozao_bodegas_asignadas($pdo, $usuario);

try {
    $listo = corozao_ajustes_instalados($pdo);
} catch (Throwable $e) {
    error_log('Corozao: no se pudo validar el esquema de ajustes.');
    http_response_code(503);
    $error = 'No se pudo validar el esquema. Inténtalo más tarde.';
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
                throw new DomainException('La acción solicitada no es válida.');
            }
            if ($accion === 'crear') {
                $contexto = corozao_exigir_permiso($pdo, 'inventory.adjustment.create');
                $bodegaRaw = $_POST['bodega_id'] ?? '';
                $productoRaw = $_POST['producto_id'] ?? '';
                $bodegaId = is_string($bodegaRaw) ? filter_var($bodegaRaw, FILTER_VALIDATE_INT) : false;
                $productoId = is_string($productoRaw) ? filter_var($productoRaw, FILTER_VALIDATE_INT) : false;
                if (!$bodegaId || !$productoId) {
                    throw new DomainException('Selecciona la bodega y el producto.');
                }
                corozao_exigir_bodega($pdo, $contexto, $bodegaId);
                $tipo = $_POST['tipo_movimiento'] ?? '';
                $cantidad = $_POST['cantidad'] ?? '';
                $motivo = $_POST['motivo'] ?? '';
                $clave = $_POST['clave_idempotencia'] ?? '';
                if (!is_string($tipo) || !is_string($cantidad) || !is_string($motivo) || !is_string($clave)) {
                    throw new DomainException('Los datos enviados no son válidos.');
                }
                $id = corozao_ajuste_crear(
                    $pdo, (int) $contexto['usuario_id'], (int) $bodegaId, (int) $productoId,
                    $tipo, $cantidad, $motivo, $clave
                );
                $_SESSION['ajustes_flash'] = 'Borrador de ajuste #' . $id . ' creado; el saldo aún no cambia.';
                header('Location: /ajustes.php');
                exit;
            }
            if ($accion === 'confirmar') {
                $contexto = corozao_exigir_permiso($pdo, 'inventory.adjustment.confirm');
                $idRaw = $_POST['movimiento_id'] ?? '';
                $id = is_string($idRaw) ? filter_var($idRaw, FILTER_VALIDATE_INT) : false;
                if (!$id || $id < 1) {
                    throw new DomainException('El ajuste seleccionado no es válido.');
                }
                $stmt = $pdo->prepare(
                    'SELECT bodega_origen_id, bodega_destino_id FROM movimientos_inventario
                     WHERE movimiento_id = :id AND tipo_movimiento IN (\'AJUSTE_POSITIVO\', \'AJUSTE_NEGATIVO\')'
                );
                $stmt->execute(['id' => $id]);
                $fila = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$fila) {
                    throw new DomainException('El ajuste no existe.');
                }
                corozao_exigir_bodega($pdo, $contexto, $fila['bodega_destino_id'] ?? $fila['bodega_origen_id']);
                corozao_ajuste_confirmar($pdo, (int) $id, (int) $contexto['usuario_id']);
                $_SESSION['ajustes_flash'] = 'Ajuste #' . $id . ' confirmado y aplicado al saldo.';
                header('Location: /ajustes.php');
                exit;
            }
            http_response_code(400);
            throw new DomainException('Acción no válida.');
        } catch (DomainException $e) {
            http_response_code(422);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Corozao: error al procesar un ajuste de inventario.');
            http_response_code(500);
            $error = 'No se pudo guardar el ajuste; el saldo no recibió cambios parciales.';
        }
    }
}

if ($listo) {
    try {
        $productos = $pdo->query(
            'SELECT p.producto_id,p.nombre,p.contenido,c.nombre AS categoria FROM productos p
             LEFT JOIN categorias c ON c.categoria_id=p.categoria_id
             WHERE p.activo = 1 ORDER BY p.nombre, p.producto_id LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);
        $ajustes = $pdo->query(
            'SELECT m.movimiento_id, m.tipo_movimiento, m.estado, m.fecha_movimiento, m.fecha_confirmacion,
                    m.observaciones, b.nombre AS bodega, p.nombre AS producto, p.contenido, c.nombre AS categoria, d.cantidad_base,
                    u.nombre_completo AS creador, uc.nombre_completo AS confirmador
             FROM movimientos_inventario m
             INNER JOIN detalle_movimiento_inventario d ON d.movimiento_id = m.movimiento_id
             INNER JOIN productos p ON p.producto_id = d.producto_id
             LEFT JOIN categorias c ON c.categoria_id = p.categoria_id
             INNER JOIN bodegas b ON b.bodega_id = COALESCE(m.bodega_destino_id, m.bodega_origen_id)
             INNER JOIN usuarios u ON u.usuario_id = m.usuario_id
             LEFT JOIN usuarios uc ON uc.usuario_id = m.confirmado_por_usuario_id
             WHERE m.tipo_movimiento IN (\'AJUSTE_POSITIVO\', \'AJUSTE_NEGATIVO\')
             ORDER BY m.fecha_movimiento DESC, m.movimiento_id DESC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Corozao: no se pudo cargar el historial de ajustes.');
        http_response_code(500);
        $error = 'No se pudo cargar el historial de ajustes.';
    }
}
$claveNueva = ajuste_uuid();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Corozao | Ajustes de inventario</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/brand/corozao-mark.svg?v=2">
    <link rel="stylesheet" href="/assets/css/brand.css">
</head>
<body data-corozao-role="<?= htmlspecialchars((string)($_SESSION['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bg-body-tertiary">
<nav class="navbar bg-white border-bottom"><div class="container-xxl">
    <a class="navbar-brand fw-semibold" href="/">Corozao</a>
    <span class="badge text-bg-primary"><?= ajuste_h($usuario['rol']) ?></span>
</div></nav>
<main class="container-xxl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><p class="text-primary fw-semibold mb-1">Inventario</p><h1 class="h2 mb-0">Ajustes auditados</h1></div>
        <a class="btn btn-outline-secondary" href="/">Volver al panel</a>
    </div>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= ajuste_h($error) ?></div><?php endif; ?>
    <?php if ($mensaje !== null): ?><div class="alert alert-success" role="status"><?= ajuste_h($mensaje) ?></div><?php endif; ?>
    <?php if (!$listo): ?>
        <div class="alert alert-warning" role="status">El esquema instalado no reúne las restricciones necesarias para ajustes auditados e idempotentes. No se guardaron cambios.</div>
    <?php else: ?>
        <div class="alert alert-info">Crear un borrador no cambia existencias. El saldo se modifica únicamente al confirmar. El motivo queda registrado en auditoría.</div>
        <section class="card shadow-sm mb-4" aria-labelledby="crear-ajuste">
            <div class="card-header bg-white"><h2 id="crear-ajuste" class="h5 mb-0">Nuevo ajuste</h2></div>
            <div class="card-body"><form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= ajuste_h($csrf) ?>">
                <input type="hidden" name="accion" value="crear">
                <input type="hidden" name="clave_idempotencia" value="<?= ajuste_h($claveNueva) ?>">
                <div class="col-12 col-md-6"><label class="form-label" for="tipo">Tipo</label>
                    <select class="form-select" id="tipo" name="tipo_movimiento" required>
                        <option value="">Selecciona</option><option value="AJUSTE_POSITIVO">Ajuste positivo</option>
                        <option value="AJUSTE_NEGATIVO">Ajuste negativo</option>
                    </select>
                </div>
                <div class="col-12 col-md-6"><label class="form-label" for="bodega">Bodega</label>
                    <select class="form-select" id="bodega" name="bodega_id" required><option value="">Selecciona</option>
                        <?php foreach ($bodegas as $bodega): ?><option value="<?= (int) $bodega['bodega_id'] ?>"><?= ajuste_h($bodega['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6"><label class="form-label" for="producto">Producto</label>
                    <select class="form-select" id="producto" name="producto_id" required><option value="">Selecciona</option>
                        <?php foreach ($productos as $producto): ?><option value="<?= (int) $producto['producto_id'] ?>"><?= ajuste_h($producto['nombre']) ?> · Contenido: <?= ajuste_h($producto['contenido'] ?: 'Sin especificar') ?> · Tipo/categoría: <?= ajuste_h($producto['categoria'] ?: 'Sin categoría') ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6"><label class="form-label" for="cantidad">Cantidad base</label>
                    <input class="form-control" type="number" id="cantidad" name="cantidad" min="1" step="1" required>
                </div>
                <div class="col-12"><label class="form-label" for="motivo">Motivo obligatorio</label>
                    <textarea class="form-control" id="motivo" name="motivo" maxlength="500" rows="2" required></textarea>
                </div>
                <div class="col-12"><button class="btn btn-primary" type="submit">Crear borrador</button></div>
            </form></div>
        </section>
        <section class="card shadow-sm" aria-labelledby="historial-ajustes">
            <div class="card-header bg-white"><h2 id="historial-ajustes" class="h5 mb-0">Últimos ajustes</h2></div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>ID / fecha</th><th>Estado</th><th>Tipo</th><th>Bodega</th><th>Producto / cantidad</th><th>Motivo</th><th>Usuarios</th><th>Acción</th></tr></thead>
                <tbody><?php foreach ($ajustes as $ajuste): ?><tr>
                    <td>#<?= (int) $ajuste['movimiento_id'] ?><br><small><?= ajuste_h($ajuste['fecha_movimiento']) ?></small></td>
                    <td><span class="badge text-bg-<?= $ajuste['estado'] === 'CONFIRMADO' ? 'success' : 'secondary' ?>"><?= ajuste_h($ajuste['estado']) ?></span></td>
                    <td><?= $ajuste['tipo_movimiento'] === 'AJUSTE_POSITIVO' ? 'Positivo' : 'Negativo' ?></td>
                    <td><?= ajuste_h($ajuste['bodega']) ?></td>
                    <td><?= ajuste_h($ajuste['producto']) ?><br><small class="text-body-secondary">Contenido: <?= ajuste_h($ajuste['contenido'] ?: 'Sin especificar') ?> · Tipo/categoría: <?= ajuste_h($ajuste['categoria'] ?: 'Sin categoría') ?></small><br><?= ajuste_h($ajuste['cantidad_base']) ?></td>
                    <td><?= ajuste_h($ajuste['observaciones']) ?></td>
                    <td><?= ajuste_h($ajuste['creador']) ?><?php if ($ajuste['confirmador']): ?><br><small>Confirmó: <?= ajuste_h($ajuste['confirmador']) ?></small><?php endif; ?></td>
                    <td><?php if ($ajuste['estado'] === 'BORRADOR'): ?><form method="post">
                        <input type="hidden" name="csrf_token" value="<?= ajuste_h($csrf) ?>">
                        <input type="hidden" name="accion" value="confirmar">
                        <input type="hidden" name="movimiento_id" value="<?= (int) $ajuste['movimiento_id'] ?>">
                        <button class="btn btn-sm btn-outline-success" type="submit">Confirmar y aplicar</button>
                    </form><?php else: ?>Aplicado<?php endif; ?></td>
                </tr><?php endforeach; ?>
                <?php if (!$ajustes): ?><tr><td colspan="8" class="text-center text-body-secondary py-4">Todavía no hay ajustes.</td></tr><?php endif; ?></tbody>
            </table></div>
        </section>
    <?php endif; ?>
</main>
<script src="/assets/brand.js" defer></script>
</body>
</html>
