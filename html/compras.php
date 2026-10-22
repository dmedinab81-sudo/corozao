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
require_once __DIR__ . '/includes/compras.php';

function compras_h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function compras_decimal(mixed $value, int $escala = 4): string
{
    $texto = (string) $value;
    if (!preg_match('/^(\d+)\.(\d+)$/', $texto, $partes)) {
        return '0.' . str_repeat('0', $escala);
    }
    return number_format((int) $partes[1], 0, '.', ',') . '.' . str_pad(substr($partes[2], 0, $escala), $escala, '0');
}

function compras_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

$usuario = corozao_exigir_permiso($pdo, 'purchases.view');
$csrfToken = $_SESSION['csrf_token'] ?? null;
if (!is_string($csrfToken) || strlen($csrfToken) < 32) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['csrf_token'];
}
$bodegas = corozao_bodegas_asignadas($pdo, $usuario);
$error = null;
$mensaje = null;
$migracionLista = false;
$compras = [];
$proveedores = [];
$bodegasDisponibles = [];
$presentaciones = [];
$compraEditar = null;
$lineasEditar = [];

try {
    $migracionLista = corozao_compras_instaladas($pdo);
} catch (Throwable $e) {
    error_log('Corozao: no se pudo validar el esquema de compras.');
    http_response_code(503);
    $error = 'No se pudo validar el esquema de compras. Inténtalo más tarde.';
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
            if ($accion === 'crear' || $accion === 'actualizar') {
                $permiso = $accion === 'crear' ? 'purchases.create' : 'purchases.update_draft';
                $contexto = corozao_exigir_permiso($pdo, $permiso);
                $lineasRaw = $_POST['lineas'] ?? null;
                if (!is_array($lineasRaw)) {
                    throw new DomainException('Agrega al menos una presentación.');
                }
                $lineas = [];
                foreach ($lineasRaw as $lineaRaw) {
                    if (!is_array($lineaRaw)) {
                        throw new DomainException('Una línea de compra no es válida.');
                    }
                    foreach (['presentacion_id', 'cantidad', 'costo'] as $campo) {
                        if (!isset($lineaRaw[$campo]) || !is_string($lineaRaw[$campo])) {
                            throw new DomainException('Los campos de las líneas de compra no son válidos.');
                        }
                    }
                    $lineas[] = $lineaRaw;
                }
                $referencia = $_POST['referencia_proveedor'] ?? '';
                $observaciones = $_POST['observaciones'] ?? '';
                if (!is_string($referencia) || !is_string($observaciones)) {
                    throw new DomainException('La referencia o las observaciones no son válidas.');
                }
                if ($accion === 'crear') {
                    $proveedorRaw = $_POST['proveedor_id'] ?? '';
                    $bodegaRaw = $_POST['bodega_id'] ?? '';
                    $proveedorId = is_string($proveedorRaw) ? filter_var($proveedorRaw, FILTER_VALIDATE_INT) : false;
                    $bodegaId = is_string($bodegaRaw) ? filter_var($bodegaRaw, FILTER_VALIDATE_INT) : false;
                    if (!$proveedorId || !$bodegaId) {
                        throw new DomainException('Selecciona el proveedor y la bodega.');
                    }
                    corozao_exigir_bodega($pdo, $contexto, $bodegaId);
                    $clave = $_POST['clave_idempotencia'] ?? '';
                    if (!is_string($clave)) {
                        throw new DomainException('La clave de solicitud no es válida.');
                    }
                    $compraId = corozao_compra_crear(
                        $pdo,
                        (int) $contexto['usuario_id'],
                        (int) $proveedorId,
                        (int) $bodegaId,
                        $lineas,
                        trim($referencia),
                        trim($observaciones),
                        $clave
                    );
                    $_SESSION['compras_flash'] = 'Borrador de compra #' . $compraId . ' guardado.';
                } else {
                    $idRaw = $_POST['compra_id'] ?? '';
                    $compraId = is_string($idRaw) ? filter_var($idRaw, FILTER_VALIDATE_INT) : false;
                    if (!$compraId || $compraId < 1) {
                        throw new DomainException('La compra seleccionada no es válida.');
                    }
                    corozao_compra_actualizar_borrador(
                        $pdo, (int) $compraId, (int) $contexto['usuario_id'],
                        $lineas, trim($referencia), trim($observaciones)
                    );
                    $_SESSION['compras_flash'] = 'Borrador de compra #' . $compraId . ' actualizado.';
                }
                header('Location: /compras.php');
                exit;
            }

            if ($accion === 'confirmar') {
                $contexto = corozao_exigir_permiso($pdo, 'purchases.confirm');
                $idRaw = $_POST['compra_id'] ?? '';
                $compraId = is_string($idRaw) ? filter_var($idRaw, FILTER_VALIDATE_INT) : false;
                if (!$compraId || $compraId < 1) {
                    throw new DomainException('La compra seleccionada no es válida.');
                }
                $stmt = $pdo->prepare('SELECT bodega_id FROM compras WHERE compra_id = :id');
                $stmt->execute(['id' => $compraId]);
                $bodegaId = $stmt->fetchColumn();
                if (!$bodegaId) {
                    throw new DomainException('La compra no existe.');
                }
                corozao_exigir_bodega($pdo, $contexto, $bodegaId);
                corozao_compra_confirmar($pdo, (int) $compraId, (int) $contexto['usuario_id']);
                $_SESSION['compras_flash'] = 'Compra #' . $compraId . ' confirmada y recibida en inventario.';
                header('Location: /compras.php');
                exit;
            }

            http_response_code(400);
            throw new DomainException('Acción no válida.');
        } catch (DomainException $e) {
            http_response_code(422);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Corozao: error en operación de compra.');
            http_response_code(500);
            $error = 'No se pudo guardar la operación; no se confirmaron cambios parciales.';
        }
    }
}

$mensaje = $_SESSION['compras_flash'] ?? null;
unset($_SESSION['compras_flash']);
$claveNueva = compras_uuid();
$editarRaw = $_GET['editar'] ?? '';
$editarId = is_string($editarRaw) ? filter_var($editarRaw, FILTER_VALIDATE_INT) : false;
if ($editarId !== false && $editarId !== null && $editarId > 0) {
    $stmt = $pdo->prepare(
        'SELECT c.compra_id, c.proveedor_id, c.bodega_id, c.estado, c.referencia_proveedor,
                c.observaciones, p.razon_social AS proveedor, b.nombre AS bodega
         FROM compras c INNER JOIN proveedores p ON p.proveedor_id = c.proveedor_id
         INNER JOIN bodegas b ON b.bodega_id = c.bodega_id WHERE c.compra_id = :id'
    );
    $stmt->execute(['id' => $editarId]);
    $compraEditar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($compraEditar && $compraEditar['estado'] === 'BORRADOR') {
        $lineasEditar = corozao_compra_detalles_snapshot($pdo, (int) $editarId);
    } else {
        $error = 'Solo se pueden editar borradores existentes.';
        $compraEditar = null;
    }
}

if ($migracionLista) {
    try {
        $proveedores = $pdo->query(
            'SELECT proveedor_id, razon_social FROM proveedores WHERE activo = 1 ORDER BY razon_social, proveedor_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $bodegasDisponibles = $bodegas;
        $presentaciones = $pdo->query(
            'SELECT pr.presentacion_id, pr.producto_id, pr.nombre AS presentacion,
                    pr.unidades_base, p.nombre AS producto
             FROM presentaciones pr INNER JOIN productos p ON p.producto_id = pr.producto_id
             WHERE pr.activo = 1 AND p.activo = 1 ORDER BY p.nombre, pr.nombre LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);
        $compras = $pdo->query(
            'SELECT c.compra_id, c.estado, c.fecha_compra, c.fecha_confirmacion,
                    c.referencia_proveedor, c.observaciones,
                    pr.razon_social AS proveedor, b.nombre AS bodega,
                    u.nombre_completo AS creador, uc.nombre_completo AS confirmador,
                    (SELECT COUNT(*) FROM compra_detalles cd WHERE cd.compra_id = c.compra_id) AS lineas,
                    (SELECT COALESCE(SUM(cd.costo_total), 0) FROM compra_detalles cd WHERE cd.compra_id = c.compra_id) AS total
             FROM compras c
             INNER JOIN proveedores pr ON pr.proveedor_id = c.proveedor_id
             INNER JOIN bodegas b ON b.bodega_id = c.bodega_id
             INNER JOIN usuarios u ON u.usuario_id = c.usuario_id
             LEFT JOIN usuarios uc ON uc.usuario_id = c.confirmado_por_usuario_id
             ORDER BY c.fecha_compra DESC, c.compra_id DESC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Corozao: no se pudo cargar la pantalla de compras.');
        http_response_code(500);
        $error = 'No se pudieron cargar las compras.';
    }
}

$esEdicion = $compraEditar !== null;
$lineasFormulario = $lineasEditar;
while (count($lineasFormulario) < 5) {
    $lineasFormulario[] = ['presentacion_id' => '', 'cantidad_presentacion' => '', 'costo_unitario_presentacion' => ''];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Corozao | Compras</title>
</head>
<body class="bg-body-tertiary">
<nav class="navbar bg-white border-bottom"><div class="container-xxl">
    <a class="navbar-brand fw-semibold" href="/">Corozao</a>
    <span class="badge text-bg-primary"><?= compras_h($usuario['rol']) ?></span>
</div></nav>
<main class="container-xxl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><p class="text-primary fw-semibold mb-1">Inventario</p><h1 class="h2 mb-0">Compras</h1></div>
        <a class="btn btn-outline-secondary" href="/">Volver al panel</a>
    </div>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= compras_h($error) ?></div><?php endif; ?>
    <?php if ($mensaje !== null): ?><div class="alert alert-success" role="status"><?= compras_h($mensaje) ?></div><?php endif; ?>
    <?php if (!$migracionLista): ?>
        <div class="alert alert-warning" role="status">Módulo pendiente de habilitación: aplica manualmente <code>migrations/20261022_compras.sql</code> después de revisar un respaldo. No se han creado compras ni movimientos.</div>
    <?php else: ?>
        <section class="card shadow-sm mb-4" aria-labelledby="formulario-compra">
            <div class="card-header bg-white"><h2 id="formulario-compra" class="h5 mb-0"><?= $esEdicion ? 'Editar borrador #' . (int) $compraEditar['compra_id'] : 'Nuevo borrador' ?></h2></div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= compras_h($csrfToken) ?>">
                    <input type="hidden" name="accion" value="<?= $esEdicion ? 'actualizar' : 'crear' ?>">
                    <?php if ($esEdicion): ?><input type="hidden" name="compra_id" value="<?= (int) $compraEditar['compra_id'] ?>">
                    <?php else: ?><input type="hidden" name="clave_idempotencia" value="<?= compras_h($claveNueva) ?>"><?php endif; ?>
                    <?php if (!$esEdicion): ?>
                        <div class="col-12 col-md-6"><label class="form-label" for="proveedor">Proveedor</label><select class="form-select" id="proveedor" name="proveedor_id" required><option value="">Selecciona</option><?php foreach ($proveedores as $proveedor): ?><option value="<?= (int) $proveedor['proveedor_id'] ?>"><?= compras_h($proveedor['razon_social']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-12 col-md-6"><label class="form-label" for="bodega">Bodega de ingreso</label><select class="form-select" id="bodega" name="bodega_id" required><option value="">Selecciona</option><?php foreach ($bodegasDisponibles as $bodega): ?><option value="<?= (int) $bodega['bodega_id'] ?>"><?= compras_h($bodega['nombre']) ?></option><?php endforeach; ?></select></div>
                    <?php else: ?>
                        <div class="col-12"><p class="text-body-secondary mb-0"><?= compras_h($compraEditar['proveedor']) ?> · <?= compras_h($compraEditar['bodega']) ?></p></div>
                    <?php endif; ?>
                    <div class="col-12"><p class="fw-semibold mb-1">Presentaciones</p><p class="small text-body-secondary">Ingresa unidades de presentación y costo por presentación. La conversión a unidades base y el total deben caber exactamente en el inventario.</p></div>
                    <?php foreach (array_slice($lineasFormulario, 0, 5) as $i => $linea): ?>
                        <div class="col-12 col-lg-6"><label class="visually-hidden" for="presentacion-<?= $i ?>">Presentación <?= $i + 1 ?></label><select class="form-select" id="presentacion-<?= $i ?>" name="lineas[<?= $i ?>][presentacion_id]"><option value="">Presentación <?= $i + 1 ?> (opcional)</option><?php foreach ($presentaciones as $presentacion): ?><option value="<?= (int) $presentacion['presentacion_id'] ?>" <?= (int) ($linea['presentacion_id'] ?? 0) === (int) $presentacion['presentacion_id'] ? 'selected' : '' ?>><?= compras_h($presentacion['producto'] . ' · ' . $presentacion['presentacion'] . ' (' . $presentacion['unidades_base'] . ' base)') ?></option><?php endforeach; ?></select></div>
                        <div class="col-6 col-lg-3"><label class="visually-hidden" for="cantidad-<?= $i ?>">Cantidad <?= $i + 1 ?></label><input class="form-control" id="cantidad-<?= $i ?>" name="lineas[<?= $i ?>][cantidad]" type="number" min="0.001" step="0.001" value="<?= compras_h($linea['cantidad_presentacion'] ?? '') ?>" placeholder="Unidades presentación"></div>
                        <div class="col-6 col-lg-3"><label class="visually-hidden" for="costo-<?= $i ?>">Costo <?= $i + 1 ?></label><input class="form-control" id="costo-<?= $i ?>" name="lineas[<?= $i ?>][costo]" type="number" min="0" step="0.0001" value="<?= compras_h($linea['costo_unitario_presentacion'] ?? '') ?>" placeholder="Costo unitario"></div>
                    <?php endforeach; ?>
                    <div class="col-12 col-md-6"><label class="form-label" for="referencia">Referencia del proveedor</label><input class="form-control" id="referencia" name="referencia_proveedor" maxlength="100" value="<?= compras_h($compraEditar['referencia_proveedor'] ?? '') ?>"></div>
                    <div class="col-12 col-md-6"><label class="form-label" for="observaciones">Observaciones</label><input class="form-control" id="observaciones" name="observaciones" maxlength="500" value="<?= compras_h($compraEditar['observaciones'] ?? '') ?>"></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><?= $esEdicion ? 'Guardar borrador' : 'Crear borrador' ?></button><?php if ($esEdicion): ?> <a class="btn btn-outline-secondary" href="/compras.php">Cancelar edición</a><?php endif; ?></div>
                </form>
            </div>
        </section>
        <section class="card shadow-sm" aria-labelledby="lista-compras">
            <div class="card-header bg-white"><h2 id="lista-compras" class="h5 mb-0">Últimas compras</h2></div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>ID / fecha</th><th>Estado</th><th>Proveedor</th><th>Bodega</th><th>Líneas</th><th class="text-end">Total</th><th>Creada por</th><th>Acción</th></tr></thead><tbody>
                <?php foreach ($compras as $compra): ?><tr>
                    <td>#<?= (int) $compra['compra_id'] ?><br><small><?= compras_h($compra['fecha_compra']) ?></small><?php if ($compra['fecha_confirmacion']): ?><br><small>Confirmada: <?= compras_h($compra['fecha_confirmacion']) ?></small><?php endif; ?></td>
                    <td><span class="badge text-bg-<?= $compra['estado'] === 'CONFIRMADA' ? 'success' : 'secondary' ?>"><?= compras_h($compra['estado']) ?></span></td>
                    <td><?= compras_h($compra['proveedor']) ?><?php if ($compra['referencia_proveedor']): ?><br><small>Ref. <?= compras_h($compra['referencia_proveedor']) ?></small><?php endif; ?></td>
                    <td><?= compras_h($compra['bodega']) ?></td><td><?= (int) $compra['lineas'] ?></td><td class="text-end">$<?= compras_decimal($compra['total']) ?></td><td><?= compras_h($compra['creador']) ?></td>
                    <td><?php if ($compra['estado'] === 'BORRADOR'): ?><div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-secondary" href="?editar=<?= (int) $compra['compra_id'] ?>">Editar</a><form method="post" class="m-0"><input type="hidden" name="csrf_token" value="<?= compras_h($csrfToken) ?>"><input type="hidden" name="accion" value="confirmar"><input type="hidden" name="compra_id" value="<?= (int) $compra['compra_id'] ?>"><button class="btn btn-sm btn-primary" type="submit">Confirmar e ingresar</button></form></div><?php else: ?><span class="text-body-secondary">Cerrada</span><?php endif; ?></td>
                </tr><?php endforeach; ?>
                <?php if (!$compras): ?><tr><td colspan="8" class="text-center text-body-secondary py-4">Todavía no hay compras.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <div class="card-footer bg-white small text-body-secondary">Las compras confirmadas generan un movimiento COMPRA y no se editan ni anulan desde esta pantalla.</div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
