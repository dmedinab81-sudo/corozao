<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('COROZAO_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');

require '/etc/corozao/database.php';
require_once __DIR__ . '/includes/autorizacion.php';
require_once __DIR__ . '/includes/transferencias.php';

function transferencia_h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function transferencia_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

$usuario = corozao_exigir_permiso($pdo, 'inventory.transfer.review');
$csrfToken = $_SESSION['csrf_token'] ?? null;
if (!is_string($csrfToken) || strlen($csrfToken) < 32) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['csrf_token'];
}
$bodegas = corozao_bodegas_asignadas($pdo, $usuario);
$error = null;
$mensaje = null;
$movimientos = [];
$productos = [];
$migracionLista = false;

try {
    $migracionLista = corozao_transferencias_instaladas($pdo);
    if ($migracionLista) {
        $stmt = $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'auditoria_eventos\''
        );
        $migracionLista = (int) $stmt->fetchColumn() === 1;
    }
} catch (Throwable $e) {
    error_log('Corozao: no se pudo validar el esquema de transferencias.');
    http_response_code(503);
    $error = 'No se pudo validar el esquema. Inténtalo más tarde.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $migracionLista) {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !corozao_csrf_valido($token)) {
        http_response_code(403);
        $error = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
    } else {
        $accionRaw = $_POST['accion'] ?? '';
        $accion = is_string($accionRaw) ? $accionRaw : '';
        try {
            if ($accion === 'crear') {
                $contexto = corozao_exigir_permiso($pdo, 'inventory.transfer.create');
                $origenRaw = $_POST['bodega_origen_id'] ?? '';
                $destinoRaw = $_POST['bodega_destino_id'] ?? '';
                $origenId = is_string($origenRaw) ? filter_var($origenRaw, FILTER_VALIDATE_INT) : false;
                $destinoId = is_string($destinoRaw) ? filter_var($destinoRaw, FILTER_VALIDATE_INT) : false;
                if (!$origenId || !$destinoId) {
                    throw new DomainException('Selecciona las bodegas de origen y destino.');
                }
                $origenId = corozao_exigir_bodega($pdo, $contexto, $origenId);
                $destinoId = corozao_exigir_bodega($pdo, $contexto, $destinoId);
                $productosRecibidos = $_POST['productos'] ?? null;
                $cantidadesRecibidas = $_POST['cantidades'] ?? null;
                if (!is_array($productosRecibidos) || !is_array($cantidadesRecibidas)) {
                    throw new DomainException('Incluye al menos un producto.');
                }
                $cantidades = [];
                foreach ($productosRecibidos as $indice => $productoTexto) {
                    $cantidadTexto = $cantidadesRecibidas[$indice] ?? '';
                    if (!is_string($productoTexto) || !is_string($cantidadTexto)) {
                        throw new DomainException('Los productos y cantidades recibidos no son válidos.');
                    }
                    if ($productoTexto === '' && trim($cantidadTexto) === '') {
                        continue;
                    }
                    if (!ctype_digit($productoTexto) || (int) $productoTexto < 1 || trim($cantidadTexto) === '') {
                        throw new DomainException('Cada producto seleccionado requiere una cantidad.');
                    }
                    if (array_key_exists((int) $productoTexto, $cantidades)) {
                        throw new DomainException('Cada producto debe aparecer una sola vez.');
                    }
                    $cantidades[(int) $productoTexto] = $cantidadTexto;
                }
                $observaciones = $_POST['observaciones'] ?? '';
                $clave = $_POST['clave_idempotencia'] ?? '';
                if (!is_string($observaciones) || !is_string($clave)) {
                    throw new DomainException('Los datos recibidos no son válidos.');
                }
                $id = corozao_transferencia_crear(
                    $pdo,
                    (int) $contexto['usuario_id'],
                    $origenId,
                    $destinoId,
                    $cantidades,
                    trim($observaciones),
                    $clave
                );
                $_SESSION['transferencia_flash'] = 'Borrador de transferencia #' . $id . ' guardado.';
                header('Location: /transferencias.php');
                exit;
            }

            $movimientoRaw = $_POST['movimiento_id'] ?? '';
            $movimientoId = is_string($movimientoRaw) ? filter_var($movimientoRaw, FILTER_VALIDATE_INT) : false;
            if (!$movimientoId || $movimientoId < 1) {
                throw new DomainException('La transferencia seleccionada no es válida.');
            }
            $stmt = $pdo->prepare(
                'SELECT bodega_origen_id, bodega_destino_id
                 FROM movimientos_inventario WHERE movimiento_id = :id AND tipo_movimiento = \'TRANSFERENCIA\''
            );
            $stmt->execute(['id' => $movimientoId]);
            $bodegasTransferencia = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bodegasTransferencia) {
                throw new DomainException('La transferencia no existe.');
            }

            if ($accion === 'despachar') {
                $contexto = corozao_exigir_permiso($pdo, 'inventory.transfer.confirm');
                corozao_exigir_bodega($pdo, $contexto, $bodegasTransferencia['bodega_origen_id']);
                corozao_transferencia_despachar($pdo, $movimientoId, (int) $contexto['usuario_id']);
                $_SESSION['transferencia_flash'] = 'Transferencia #' . $movimientoId . ' despachada; el origen fue descontado.';
            } elseif ($accion === 'recibir') {
                $contexto = corozao_exigir_permiso($pdo, 'inventory.transfer.receive');
                corozao_exigir_bodega($pdo, $contexto, $bodegasTransferencia['bodega_destino_id']);
                corozao_transferencia_recibir($pdo, $movimientoId, (int) $contexto['usuario_id']);
                $_SESSION['transferencia_flash'] = 'Transferencia #' . $movimientoId . ' recibida; el destino fue actualizado.';
            } else {
                http_response_code(400);
                throw new DomainException('Acción no válida.');
            }
            header('Location: /transferencias.php');
            exit;
        } catch (DomainException $e) {
            http_response_code(422);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Corozao: error en operación de transferencia.');
            http_response_code(500);
            $error = 'No se pudo guardar la operación; no se confirmaron cambios parciales.';
        }
    }
}

$mensaje = $_SESSION['transferencia_flash'] ?? null;
unset($_SESSION['transferencia_flash']);
$claveNueva = transferencia_uuid();

if ($migracionLista) {
    try {
        $productos = $pdo->query(
            'SELECT producto_id, nombre FROM productos WHERE activo = 1 ORDER BY nombre, producto_id LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);
        $movimientos = $pdo->query(
            'SELECT m.movimiento_id, m.estado, m.fecha_movimiento, m.fecha_despacho, m.fecha_recepcion,
                    m.observaciones, bo.nombre AS bodega_origen, bd.nombre AS bodega_destino,
                    u.nombre_completo AS creador,
                    (SELECT GROUP_CONCAT(CONCAT(p.nombre, \' × \', d.cantidad_base)
                         ORDER BY p.nombre SEPARATOR \'; \')
                     FROM detalle_movimiento_inventario d
                     INNER JOIN productos p ON p.producto_id = d.producto_id
                     WHERE d.movimiento_id = m.movimiento_id) AS productos
             FROM movimientos_inventario m
             INNER JOIN bodegas bo ON bo.bodega_id = m.bodega_origen_id
             INNER JOIN bodegas bd ON bd.bodega_id = m.bodega_destino_id
             INNER JOIN usuarios u ON u.usuario_id = m.usuario_id
             WHERE m.tipo_movimiento = \'TRANSFERENCIA\'
             ORDER BY FIELD(m.estado, \'EN_TRANSITO\', \'BORRADOR\', \'CONFIRMADO\'),
                      m.fecha_movimiento DESC, m.movimiento_id DESC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Corozao: no se pudo cargar la pantalla de transferencias.');
        http_response_code(500);
        $error = 'No se pudieron cargar las transferencias.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Corozao | Transferencias</title>
</head>
<body class="bg-body-tertiary">
<nav class="navbar bg-white border-bottom"><div class="container-xxl">
    <a class="navbar-brand fw-semibold" href="/">Corozao</a>
    <span class="badge text-bg-primary"><?= transferencia_h($usuario['rol']) ?></span>
</div></nav>
<main class="container-xxl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><p class="text-primary fw-semibold mb-1">Inventario</p><h1 class="h2 mb-0">Transferencias entre bodegas</h1></div>
        <a class="btn btn-outline-secondary" href="/">Volver al panel</a>
    </div>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= transferencia_h($error) ?></div><?php endif; ?>
    <?php if ($mensaje !== null): ?><div class="alert alert-success" role="status"><?= transferencia_h($mensaje) ?></div><?php endif; ?>
    <?php if (!$migracionLista): ?>
        <div class="alert alert-warning" role="status">Módulo pendiente de habilitación: aplica manualmente <code>migrations/20261022_transferencias_dos_etapas.sql</code> después de revisar un respaldo. No se han guardado movimientos.</div>
    <?php else: ?>
        <section class="card shadow-sm mb-4" aria-labelledby="crear-transferencia">
            <div class="card-header bg-white"><h2 id="crear-transferencia" class="h5 mb-0">Nuevo borrador</h2></div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= transferencia_h($csrfToken) ?>">
                    <input type="hidden" name="accion" value="crear">
                    <input type="hidden" name="clave_idempotencia" value="<?= transferencia_h($claveNueva) ?>">
                    <div class="col-12 col-md-6"><label class="form-label" for="origen">Bodega de origen</label>
                        <select class="form-select" id="origen" name="bodega_origen_id" required>
                            <option value="">Selecciona</option><?php foreach ($bodegas as $bodega): ?><option value="<?= (int) $bodega['bodega_id'] ?>"><?= transferencia_h($bodega['nombre']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6"><label class="form-label" for="destino">Bodega de destino</label>
                        <select class="form-select" id="destino" name="bodega_destino_id" required>
                            <option value="">Selecciona</option><?php foreach ($bodegas as $bodega): ?><option value="<?= (int) $bodega['bodega_id'] ?>"><?= transferencia_h($bodega['nombre']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><p class="fw-semibold mb-1">Productos (unidades base)</p><p class="small text-body-secondary">No repitas productos. El despacho valida y descuenta el saldo disponible.</p></div>
                    <?php for ($i = 0; $i < 5; $i++): ?>
                        <div class="col-12 col-md-8"><label class="visually-hidden" for="producto-<?= $i ?>">Producto <?= $i + 1 ?></label>
                            <select class="form-select" id="producto-<?= $i ?>" name="productos[<?= $i ?>]" aria-label="Producto <?= $i + 1 ?>">
                                <option value="">Producto <?= $i + 1 ?> (opcional)</option>
                                <?php foreach ($productos as $producto): ?><option value="<?= (int) $producto['producto_id'] ?>"><?= transferencia_h($producto['nombre']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4"><label class="visually-hidden" for="cantidad-<?= $i ?>">Cantidad base <?= $i + 1 ?></label>
                            <input class="form-control" type="number" min="0.001" step="0.001" name="cantidades[<?= $i ?>]" id="cantidad-<?= $i ?>" placeholder="Cantidad base" aria-label="Cantidad <?= $i + 1 ?>">
                        </div>
                    <?php endfor; ?>
                    <div class="col-12"><label class="form-label" for="observaciones">Observaciones</label><textarea class="form-control" id="observaciones" name="observaciones" maxlength="500" rows="2"></textarea></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Guardar borrador</button></div>
                </form>
            </div>
        </section>
        <section class="card shadow-sm" aria-labelledby="lista-transferencias">
            <div class="card-header bg-white"><h2 id="lista-transferencias" class="h5 mb-0">Últimas transferencias</h2></div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>ID / fecha</th><th>Estado</th><th>Ruta</th><th>Productos</th><th>Creada por</th><th>Observaciones</th><th>Acción</th></tr></thead><tbody>
                <?php foreach ($movimientos as $movimiento): ?>
                    <tr>
                        <td>#<?= (int) $movimiento['movimiento_id'] ?><br><small><?= transferencia_h($movimiento['fecha_movimiento']) ?></small></td>
                        <td><span class="badge text-bg-<?= $movimiento['estado'] === 'CONFIRMADO' ? 'success' : ($movimiento['estado'] === 'EN_TRANSITO' ? 'warning' : 'secondary') ?>"><?= transferencia_h($movimiento['estado']) ?></span>
                            <?php if ($movimiento['fecha_despacho']): ?><br><small>Despacho: <?= transferencia_h($movimiento['fecha_despacho']) ?></small><?php endif; ?>
                            <?php if ($movimiento['fecha_recepcion']): ?><br><small>Recepción: <?= transferencia_h($movimiento['fecha_recepcion']) ?></small><?php endif; ?>
                        </td>
                        <td><?= transferencia_h($movimiento['bodega_origen']) ?><br><span aria-hidden="true">↓</span><br><?= transferencia_h($movimiento['bodega_destino']) ?></td>
                        <td><?= transferencia_h($movimiento['productos'] ?? '—') ?></td><td><?= transferencia_h($movimiento['creador']) ?></td><td><?= transferencia_h($movimiento['observaciones'] ?? '—') ?></td>
                        <td>
                            <?php if ($movimiento['estado'] === 'BORRADOR' || $movimiento['estado'] === 'EN_TRANSITO'): ?>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= transferencia_h($csrfToken) ?>">
                                    <input type="hidden" name="movimiento_id" value="<?= (int) $movimiento['movimiento_id'] ?>">
                                    <?php if ($movimiento['estado'] === 'BORRADOR'): ?><input type="hidden" name="accion" value="despachar"><button class="btn btn-sm btn-outline-primary" type="submit">Despachar y descontar origen</button>
                                    <?php else: ?><input type="hidden" name="accion" value="recibir"><button class="btn btn-sm btn-outline-success" type="submit">Recibir y sumar destino</button><?php endif; ?>
                                </form>
                            <?php else: ?><span class="text-body-secondary">Cerrada</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$movimientos): ?><tr><td colspan="7" class="text-center text-body-secondary py-4">Todavía no hay transferencias.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <div class="card-footer bg-white small text-body-secondary">La recepción registra la cantidad despachada completa. Diferencias quedan pendientes de un procedimiento de ajuste auditado.</div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
