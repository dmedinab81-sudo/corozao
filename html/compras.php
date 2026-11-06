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

function compras_decimal(mixed $value, int $escala = 2): string
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
$ivaComprasLista = false;
$reversionesComprasLista = false;
$compras = [];
$proveedores = [];
$bodegasDisponibles = [];
$compraEditar = null;
$lineasEditar = [];

try {
    $migracionLista = corozao_compras_instaladas($pdo);
    if ($migracionLista) {
        $ivaComprasLista = corozao_compras_iva_instalado($pdo);
        $reversionesComprasLista = corozao_compra_reversiones_instaladas($pdo);
    }
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
                    $camposLinea = ['presentacion_id', 'cantidad', 'costo'];
                    if ($ivaComprasLista) $camposLinea[] = 'tarifa_iva';
                    foreach ($camposLinea as $campo) {
                        if (!isset($lineaRaw[$campo]) || !is_string($lineaRaw[$campo])) {
                            throw new DomainException('Los campos de las líneas de compra no son válidos.');
                        }
                    }
                    $lineas[] = $lineaRaw;
                }
                $referencia = $_POST['referencia_proveedor'] ?? '';
                $observaciones = $_POST['observaciones'] ?? '';
                $fechaCompra = $_POST['fecha_compra'] ?? '';
                if (!is_string($referencia) || !is_string($observaciones) || !is_string($fechaCompra)) {
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
                        $clave,
                        $fechaCompra
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
                        $lineas, trim($referencia), trim($observaciones), $fechaCompra
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

            if ($accion === 'revertir') {
                $contexto = corozao_exigir_permiso($pdo, 'purchases.reverse');
                $idRaw = $_POST['compra_id'] ?? '';
                $motivo = $_POST['motivo_reversion'] ?? '';
                $compraId = is_string($idRaw) ? filter_var($idRaw, FILTER_VALIDATE_INT) : false;
                if (!$reversionesComprasLista) {
                    throw new DomainException('La reversión requiere aplicar la migración incremental indicada en pantalla.');
                }
                if (!$compraId || $compraId < 1 || !is_string($motivo)) {
                    throw new DomainException('La compra o el motivo de reversión no son válidos.');
                }
                $stmt = $pdo->prepare('SELECT bodega_id FROM compras WHERE compra_id = :id');
                $stmt->execute(['id' => $compraId]);
                $bodegaId = $stmt->fetchColumn();
                if (!$bodegaId) throw new DomainException('La compra no existe.');
                corozao_exigir_bodega($pdo, $contexto, $bodegaId);
                $resultado = corozao_compra_revertir($pdo, (int)$compraId,
                    (int)$contexto['usuario_id'], $motivo);
                $_SESSION['compras_flash'] = 'Compra #' . $compraId . ' revertida con el movimiento de salida #'
                    . $resultado['movimiento_id'] . '. La compra original permanece en el historial.';
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
        'SELECT c.compra_id, c.proveedor_id, c.bodega_id, c.estado, c.fecha_compra, c.referencia_proveedor,
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
        $camposTotalesIva = $ivaComprasLista
            ? ', (SELECT COALESCE(SUM(cd.impuesto_iva), 0) FROM compra_detalles cd WHERE cd.compra_id = c.compra_id) AS iva_total,
                 (SELECT COUNT(*) FROM compra_detalles cd WHERE cd.compra_id = c.compra_id AND cd.impuesto_iva IS NULL) AS lineas_iva_pendiente'
            : ', 0 AS iva_total, 0 AS lineas_iva_pendiente';
        $totalCompraSql = $ivaComprasLista
            ? 'SUM(cd.costo_total + COALESCE(cd.impuesto_iva, 0))'
            : 'SUM(cd.costo_total)';
        $camposReversion = $reversionesComprasLista
            ? ', rc.reversion_id, rc.movimiento_id AS movimiento_reversion_id, rc.usuario_id AS reversion_por, rc.fecha_hora AS fecha_reversion'
            : ', NULL AS reversion_id, NULL AS movimiento_reversion_id, NULL AS reversion_por, NULL AS fecha_reversion';
        $joinReversion = $reversionesComprasLista
            ? ' LEFT JOIN reversiones_compras rc ON rc.compra_id = c.compra_id' : '';
        $compras = $pdo->query(
            'SELECT c.compra_id, c.estado, c.fecha_compra, c.fecha_confirmacion,
                    c.referencia_proveedor, c.observaciones,
                    pr.razon_social AS proveedor, b.nombre AS bodega,
                    u.nombre_completo AS creador, uc.nombre_completo AS confirmador,
                    (SELECT COUNT(*) FROM compra_detalles cd WHERE cd.compra_id = c.compra_id) AS lineas,
                    (SELECT COALESCE(' . $totalCompraSql . ', 0) FROM compra_detalles cd WHERE cd.compra_id = c.compra_id) AS total'
                    . $camposTotalesIva . $camposReversion . '
             FROM compras c
             INNER JOIN proveedores pr ON pr.proveedor_id = c.proveedor_id
             INNER JOIN bodegas b ON b.bodega_id = c.bodega_id
             INNER JOIN usuarios u ON u.usuario_id = c.usuario_id
             LEFT JOIN usuarios uc ON uc.usuario_id = c.confirmado_por_usuario_id
             ' . $joinReversion . '
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
$fechaCompraValor = $esEdicion
    ? str_replace(' ', 'T', substr((string)$compraEditar['fecha_compra'], 0, 16))
    : (new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil')))->format('Y-m-d\\TH:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Corozao | Compras</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/brand/corozao-mark.svg?v=2">
    <link rel="stylesheet" href="/assets/css/brand.css">
</head>
<body data-corozao-role="<?= htmlspecialchars((string)($_SESSION['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bg-body-tertiary">
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
    <?php if ($migracionLista && !$reversionesComprasLista): ?><div class="alert alert-warning" role="status">La reversión completa de compras aún no está habilitada. Para conservar compras y Kardex originales, aplica manualmente <code>migrations/20261101_reversiones_compras.sql</code>. Esta página no ejecuta migraciones.</div><?php endif; ?>
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
                        <div class="col-12 col-md-6"><label class="form-label" for="bodega">Bodega de ingreso</label><select class="form-select" id="bodega" name="bodega_id" data-product-warehouse required><option value="">Selecciona</option><?php foreach ($bodegasDisponibles as $bodega): ?><option value="<?= (int) $bodega['bodega_id'] ?>"><?= compras_h($bodega['nombre']) ?></option><?php endforeach; ?></select></div>
                    <?php else: ?>
                        <div class="col-12"><p class="text-body-secondary mb-0"><?= compras_h($compraEditar['proveedor']) ?> · <?= compras_h($compraEditar['bodega']) ?></p></div>
                    <?php endif; ?>
                    <?php if (!$ivaComprasLista): ?><div class="col-12"><div class="alert alert-warning mb-0">El desglose de IVA requiere aplicar <code>migrations/20261102_compras_iva_separado.sql</code>. Mientras tanto, los borradores conservan el comportamiento actual.</div></div><?php endif; ?>
                    <div class="col-12 col-md-6"><label class="form-label" for="fecha-compra">Fecha y hora del documento</label><input class="form-control" id="fecha-compra" type="datetime-local" name="fecha_compra" value="<?= compras_h($fechaCompraValor) ?>" required></div>
                    <div class="col-12" data-product-selector data-operation="<?= $esEdicion ? 'compra_editar' : 'compra' ?>" data-purchase-vat-enabled="<?= $ivaComprasLista ? '1' : '0' ?>" <?= $esEdicion ? 'data-warehouse="' . (int)$compraEditar['bodega_id'] . '"' : '' ?>>
                        <label class="form-label" for="buscar-producto-compra">Buscar producto, código o presentación</label>
                        <input class="form-control" id="buscar-producto-compra" data-product-query autocomplete="off" aria-controls="resultados-compra" placeholder="Escribe al menos dos caracteres">
                        <div id="resultados-compra" data-product-results class="list-group mt-1" role="listbox" hidden></div>
                        <div class="row g-2 align-items-end mt-2"><div class="col-12 col-lg-6"><label class="form-label" for="producto-seleccionado-compra">Presentación seleccionada</label><div id="producto-seleccionado-compra" class="form-control bg-body-secondary">Selecciona un resultado de búsqueda</div></div><div class="col-6 col-lg-2"><label class="form-label" for="cantidad-producto-compra">Cantidad</label><input class="form-control" type="number" min="1" step="1" id="cantidad-producto-compra"></div><div class="col-6 col-lg-2"><label class="form-label" for="costo-producto-compra">Costo unitario sin IVA</label><input class="form-control" type="number" min="0" step="0.01" id="costo-producto-compra"></div><?php if ($ivaComprasLista): ?><div class="col-6 col-lg-1"><label class="form-label" for="tarifa-iva-producto-compra">IVA %</label><input class="form-control" type="number" min="0" max="100" step="0.01" id="tarifa-iva-producto-compra"></div><div class="col-6 col-lg-1"><button class="btn btn-outline-primary w-100" id="agregar-producto-compra" type="button">Agregar</button></div><?php else: ?><div class="col-12 col-lg-2"><button class="btn btn-outline-primary w-100" id="agregar-producto-compra" type="button">Agregar</button></div><?php endif; ?></div>
                    </div>
                    <div class="col-12"><div class="table-responsive"><table class="table table-sm table-bordered align-middle"><thead class="table-light"><tr><th>Producto / presentación</th><th>Cantidad</th><th>Costo unitario sin IVA</th><th>IVA %</th><th>IVA</th><th>Subtotal neto</th><th>Total línea</th><th></th></tr></thead><tbody id="detalle-compra">
                    <?php foreach ($lineasFormulario as $i => $linea): ?><tr data-detail-row><td><?= compras_h(implode(' · ', array_filter([$linea['codigos'] ?? '', $linea['producto'] ?? 'Producto', $linea['marca'] ?? '', $linea['contenido'] ?? '', $linea['categoria'] ?? '', $linea['presentacion'] ?? 'Presentación']))) ?><input type="hidden" name="lineas[<?= $i ?>][presentacion_id]" value="<?= (int)$linea['presentacion_id'] ?>"></td><td><input class="form-control form-control-sm" type="number" min="1" step="1" name="lineas[<?= $i ?>][cantidad]" value="<?= compras_h($linea['cantidad_presentacion']) ?>" required></td><td><input class="form-control form-control-sm" type="number" min="0" step="0.01" name="lineas[<?= $i ?>][costo]" value="<?= number_format((float)$linea['costo_unitario_presentacion'], 2, '.', '') ?>" required></td><td><?php if ($ivaComprasLista): ?><input class="form-control form-control-sm" type="number" min="0" max="100" step="0.01" name="lineas[<?= $i ?>][tarifa_iva]" value="<?= compras_h($linea['tarifa_iva_compra'] ?? '') ?>" required aria-label="Tarifa IVA en porcentaje"><?php else: ?>—<?php endif; ?></td><td class="text-end" data-line-tax>—</td><td class="text-end" data-line-net>—</td><td class="text-end" data-line-total>—</td><td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-line>Quitar</button></td></tr><?php endforeach; ?>
                    </tbody><tfoot><tr><th colspan="5" class="text-end">Subtotal neto</th><th id="subtotal-compra" class="text-end">$0.0000</th><th id="total-compra" class="text-end">$0.0000</th><th></th></tr></tfoot></table></div><p class="small text-body-secondary mb-0"><?php if ($ivaComprasLista): ?>El costo unitario se ingresa antes de IVA. El costo neto alimenta la valoración del Kardex y el IVA queda separado.<?php else: ?>El costo neto alimenta la valoración del Kardex. Aplica la migración indicada para capturar el IVA por separado.<?php endif; ?></p></div>
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
                    <td><span class="badge text-bg-<?= $compra['estado'] === 'CONFIRMADA' ? 'success' : 'secondary' ?>"><?= compras_h($compra['estado']) ?></span><?php if ($compra['reversion_id'] !== null): ?><br><small class="text-danger-emphasis">Revertida · movimiento #<?= (int)$compra['movimiento_reversion_id'] ?></small><?php endif; ?></td>
                    <td><?= compras_h($compra['proveedor']) ?><?php if ($compra['referencia_proveedor']): ?><br><small>Ref. <?= compras_h($compra['referencia_proveedor']) ?></small><?php endif; ?></td>
                    <td><?= compras_h($compra['bodega']) ?></td><td><?= (int) $compra['lineas'] ?></td><td class="text-end">$<?= compras_decimal($compra['total']) ?><?php if ($ivaComprasLista): ?><br><small>IVA separado: $<?= compras_decimal($compra['iva_total']) ?></small><?php if ((int)$compra['lineas_iva_pendiente'] > 0): ?><br><small class="text-warning-emphasis">IVA no desglosado en <?= (int)$compra['lineas_iva_pendiente'] ?> línea(s) históricas</small><?php endif; ?><?php endif; ?></td><td><?= compras_h($compra['creador']) ?></td>
                    <td><?php if ($compra['estado'] === 'BORRADOR'): ?><div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-secondary" href="?editar=<?= (int) $compra['compra_id'] ?>">Editar</a><form method="post" class="m-0"><input type="hidden" name="csrf_token" value="<?= compras_h($csrfToken) ?>"><input type="hidden" name="accion" value="confirmar"><input type="hidden" name="compra_id" value="<?= (int) $compra['compra_id'] ?>"><button class="btn btn-sm btn-primary" type="submit">Confirmar e ingresar</button></form></div><?php elseif ($compra['reversion_id'] !== null): ?><span class="text-body-secondary">Historial conservado</span><?php elseif ($reversionesComprasLista && corozao_rol_puede((string)$usuario['rol'], 'purchases.reverse')): ?><details><summary class="btn btn-sm btn-outline-danger">Revertir</summary><form method="post" class="mt-2" onsubmit="return confirm('Se creará una salida compensatoria y se actualizará el saldo. La compra original permanecerá en el historial. ¿Continuar?')"><input type="hidden" name="csrf_token" value="<?= compras_h($csrfToken) ?>"><input type="hidden" name="accion" value="revertir"><input type="hidden" name="compra_id" value="<?= (int)$compra['compra_id'] ?>"><label class="form-label small" for="motivo-reversion-<?= (int)$compra['compra_id'] ?>">Motivo obligatorio</label><textarea class="form-control form-control-sm mb-2" id="motivo-reversion-<?= (int)$compra['compra_id'] ?>" name="motivo_reversion" rows="2" maxlength="450" required></textarea><button class="btn btn-sm btn-danger" type="submit">Confirmar reversión</button></form></details><?php else: ?><span class="text-body-secondary">Cerrada</span><?php endif; ?></td>
                </tr><?php endforeach; ?>
                <?php if (!$compras): ?><tr><td colspan="8" class="text-center text-body-secondary py-4">Todavía no hay compras.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <div class="card-footer bg-white small text-body-secondary">Las compras confirmadas no se editan ni eliminan. Una reversión crea una salida compensatoria y conserva todos los movimientos originales.</div>
        </section>
    <?php endif; ?>
</main>
<script src="/assets/product-selector.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
 const root=document.querySelector('[data-product-selector]'), body=document.getElementById('detalle-compra');
 const selected=document.getElementById('producto-seleccionado-compra'), qty=document.getElementById('cantidad-producto-compra'), cost=document.getElementById('costo-producto-compra'), rate=document.getElementById('tarifa-iva-producto-compra'), vatEnabled=root?.dataset.purchaseVatEnabled==='1';
 let chosen=null, next=body.querySelectorAll('[data-detail-row]').length;
 root?.addEventListener('product:selected',e=>{chosen=e.detail; selected.textContent=[chosen.producto,chosen.marca,chosen.contenido,chosen.categoria,chosen.presentacion].filter(Boolean).join(' · '); qty.value='1'; cost.value=''; if(rate)rate.value=''; cost.focus();});
 document.getElementById('agregar-producto-compra')?.addEventListener('click',()=>{
   if(!chosen||!qty.value||cost.value===''||(vatEnabled&&(!rate||rate.value===''))){alert(vatEnabled?'Selecciona una presentación e ingresa cantidad, costo neto y tarifa de IVA.':'Selecciona una presentación e ingresa cantidad y costo.');return;}
   if([...body.querySelectorAll('input[name$="[presentacion_id]"]')].some(i=>i.value===String(chosen.presentacion_id))){alert('Esta presentación ya está en el detalle.');return;}
   const i=next++, tr=document.createElement('tr'); tr.dataset.detailRow='';
   const label=[chosen.codigos,chosen.producto,chosen.marca,chosen.contenido,chosen.categoria,chosen.presentacion].filter(Boolean).join(' · ');
   const cell=document.createElement('td');cell.textContent=label;const id=document.createElement('input');id.type='hidden';id.name=`lineas[${i}][presentacion_id]`;id.value=chosen.presentacion_id;cell.append(id);
   const qcell=document.createElement('td'), q=document.createElement('input');q.className='form-control form-control-sm';q.type='number';q.min='1';q.step='1';q.required=true;q.name=`lineas[${i}][cantidad]`;q.value=qty.value;qcell.append(q);
   const ccell=document.createElement('td'), c=document.createElement('input');c.className='form-control form-control-sm';c.type='number';c.min='0';c.step='0.01';c.required=true;c.name=`lineas[${i}][costo]`;c.value=cost.value;ccell.append(c);
   const rateCell=document.createElement('td');if(vatEnabled){const r=document.createElement('input');r.className='form-control form-control-sm';r.type='number';r.min='0';r.max='100';r.step='0.01';r.required=true;r.name=`lineas[${i}][tarifa_iva]`;r.value=rate.value;rateCell.append(r);}else rateCell.textContent='IVA sin desglose';
   const tax=document.createElement('td');tax.className='text-end';tax.dataset.lineTax='';const net=document.createElement('td');net.className='text-end';net.dataset.lineNet='';const total=document.createElement('td');total.className='text-end';total.dataset.lineTotal='';const action=document.createElement('td'), remove=document.createElement('button');remove.type='button';remove.className='btn btn-sm btn-outline-danger';remove.textContent='Quitar';remove.dataset.removeLine='';action.append(remove);tr.append(cell,qcell,ccell,rateCell,tax,net,total,action);body.append(tr); chosen=null;selected.textContent='Selecciona un resultado de búsqueda';qty.value='';cost.value='';if(rate)rate.value='';recalc();
 });
 const recalc=()=>{let netTotal=0,grossTotal=0;body.querySelectorAll('[data-detail-row]').forEach(tr=>{const q=Number(tr.querySelector('input[name$="[cantidad]"]').value||0),c=Number(tr.querySelector('input[name$="[costo]"]').value||0),rateInput=tr.querySelector('input[name$="[tarifa_iva]"]'),rateValue=rateInput?Number(rateInput.value||0):0,net=q*c,tax=net*rateValue/100,gross=net+tax;tr.querySelector('[data-line-net]').textContent=`$${net.toFixed(2)}`;tr.querySelector('[data-line-tax]').textContent=`$${tax.toFixed(2)}`;tr.querySelector('[data-line-total]').textContent=`$${gross.toFixed(2)}`;netTotal+=net;grossTotal+=gross;});document.getElementById('subtotal-compra').textContent=`$${netTotal.toFixed(2)}`;document.getElementById('total-compra').textContent=`$${grossTotal.toFixed(2)}`;};
 body.addEventListener('click',e=>{if(e.target.closest('[data-remove-line]')){e.target.closest('tr').remove();recalc();}});body.addEventListener('input',recalc);recalc();
});
</script>
<script src="/assets/brand.js" defer></script>
</body>
</html>
