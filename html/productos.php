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
require_once __DIR__ . '/includes/productos.php';
$usuarioActual = corozao_exigir_permiso($pdo, 'catalog.products.view', true);
$administrador = corozao_rol_puede((string)$usuarioActual['rol'], 'catalog.products.manage');
$puedeVerPrecios = corozao_rol_puede((string)$usuarioActual['rol'], 'commercial.prices.view');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function productos_h(mixed $valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function productos_unidades_visual(mixed $valor): string
{
    $unidades = (string) ($valor ?? '');
    return str_contains($unidades, '.') ? rtrim(rtrim($unidades, '0'), '.') : $unidades;
}

function productos_id(mixed $valor): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        throw new InvalidArgumentException('Selecciona un producto válido.');
    }
    return $id;
}

$error = null;
$mensaje = $_SESSION['flash_producto'] ?? null;
unset($_SESSION['flash_producto']);
$presentaciones = [];
$preciosBodega = [];
$producto = null;
$productos = [];
$categorias = [];
$total = 0;
$pagina = max(1, (int)(filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$porPagina = 50;
$paginaMaxima = 1;
$buscarRecibido = $_GET['q'] ?? '';
$buscar = is_string($buscarRecibido) ? trim($buscarRecibido) : '';
$estado = is_string($_GET['estado'] ?? '') ? $_GET['estado'] : 'TODOS';
$categoriaFiltro = filter_var($_GET['categoria'] ?? '', FILTER_VALIDATE_INT);
$idDetalle = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$idEdicion = filter_var($_GET['editar'] ?? '', FILTER_VALIDATE_INT);
$crear = isset($_GET['crear']) && $administrador;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !corozao_csrf_valido($token)) {
            http_response_code(403);
            throw new RuntimeException('La solicitud expiró. Recarga la página e inténtalo nuevamente.');
        }
        $accion = $_POST['accion'] ?? '';
        if (!is_string($accion)) {
            throw new InvalidArgumentException('La operación no es válida.');
        }
        if (in_array($accion, ['crear', 'actualizar', 'estado_producto'], true)) {
            corozao_exigir_permiso($pdo, 'catalog.products.manage');
        } elseif ($accion === 'agregar_presentacion' || $accion === 'estado_presentacion') {
            corozao_exigir_permiso($pdo, 'catalog.presentations.manage');
        } elseif ($accion === 'agregar_codigo') {
            corozao_exigir_permiso($pdo, 'catalog.codes.manage');
        } else {
            throw new InvalidArgumentException('La operación solicitada no está permitida.');
        }

        $productoId = isset($_POST['producto_id']) ? productos_id($_POST['producto_id']) : 0;
        switch ($accion) {
            case 'crear':
                $productoId = corozao_producto_crear($pdo, (int)$usuarioActual['usuario_id'], $_POST);
                $_SESSION['flash_producto'] = 'Producto creado correctamente.';
                header('Location: /productos.php?id=' . $productoId);
                exit;
            case 'actualizar':
                corozao_producto_actualizar($pdo, (int)$usuarioActual['usuario_id'], $productoId, $_POST);
                $_SESSION['flash_producto'] = 'Producto actualizado correctamente.';
                header('Location: /productos.php?id=' . $productoId);
                exit;
            case 'estado_producto':
                if (!in_array($_POST['activo'] ?? null, ['0', '1'], true)) {
                    throw new InvalidArgumentException('Estado de producto no válido.');
                }
                $activo = $_POST['activo'] === '1';
                corozao_producto_cambiar_estado($pdo, (int)$usuarioActual['usuario_id'], $productoId, $activo);
                $_SESSION['flash_producto'] = $activo ? 'Producto activado.' : 'Producto desactivado sin eliminar su historial.';
                header('Location: /productos.php?id=' . $productoId);
                exit;
            case 'agregar_presentacion':
                corozao_producto_agregar_presentacion($pdo, (int)$usuarioActual['usuario_id'], $productoId, $_POST);
                $_SESSION['flash_producto'] = 'Presentación agregada.';
                header('Location: /productos.php?id=' . $productoId);
                exit;
            case 'agregar_codigo':
                $presentacionId = productos_id($_POST['presentacion_id'] ?? null);
                corozao_producto_agregar_codigo($pdo, (int)$usuarioActual['usuario_id'], $productoId, $presentacionId, $_POST);
                $_SESSION['flash_producto'] = 'Código agregado.';
                header('Location: /productos.php?id=' . $productoId);
                exit;
            case 'estado_presentacion':
                $presentacionId = productos_id($_POST['presentacion_id'] ?? null);
                if (!in_array($_POST['activo'] ?? null, ['0', '1'], true)) {
                    throw new InvalidArgumentException('Estado de presentación no válido.');
                }
                $activo = $_POST['activo'] === '1';
                corozao_producto_cambiar_estado_presentacion($pdo, (int)$usuarioActual['usuario_id'], $productoId, $presentacionId, $activo);
                $_SESSION['flash_producto'] = $activo ? 'Presentación activada.' : 'Presentación desactivada; se conservan sus relaciones.';
                header('Location: /productos.php?id=' . $productoId);
                exit;
        }
    } catch (Throwable $e) {
        if (http_response_code() < 400) {
            http_response_code(400);
        }
        $error = $e->getMessage();
        if ($e instanceof PDOException) {
            error_log('Corozao productos: error de persistencia.');
            $error = $e->getCode() === '23000'
                ? 'El nombre de presentación o el código ya existe.'
                : 'No se pudo guardar el producto. Revisa los datos e inténtalo nuevamente.';
        }
        if (($_POST['accion'] ?? '') === 'crear') {
            $crear = true;
        } elseif (($_POST['accion'] ?? '') === 'actualizar') {
            $idEdicion = filter_var($_POST['producto_id'] ?? '', FILTER_VALIDATE_INT);
        } elseif (isset($_POST['producto_id'])) {
            $idDetalle = filter_var($_POST['producto_id'], FILTER_VALIDATE_INT);
        }
    }
}

try {
    $categorias = $pdo->query(
        'SELECT categoria_id, nombre, activo FROM categorias ORDER BY activo DESC, nombre'
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($idDetalle !== false && $idDetalle !== null) {
        $stmt = $pdo->prepare(
            'SELECT p.*, c.nombre AS categoria_nombre
             FROM productos p LEFT JOIN categorias c ON c.categoria_id = p.categoria_id
             WHERE p.producto_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int)$idDetalle]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($producto) {
            $stmt = $pdo->prepare(
                'SELECT pr.presentacion_id, pr.nombre AS presentacion, pr.unidades_base,
                        pr.activo AS presentacion_activa,
                        (SELECT GROUP_CONCAT(CONCAT(pc.codigo, CHAR(32), pc.tipo_codigo)
                            ORDER BY pc.codigo SEPARATOR ", ")
                         FROM producto_codigos pc WHERE pc.presentacion_id = pr.presentacion_id) AS codigos,
                        (SELECT pp.precio_venta FROM precios_producto pp
                         WHERE pp.presentacion_id = pr.presentacion_id AND pp.vigente_hasta IS NULL
                         ORDER BY pp.vigente_desde DESC, pp.precio_id DESC LIMIT 1) AS precio_referencia
                 FROM presentaciones pr WHERE pr.producto_id = :id
                 ORDER BY pr.activo DESC, pr.nombre, pr.presentacion_id'
            );
            $stmt->execute(['id' => (int)$idDetalle]);
            $presentaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($puedeVerPrecios) {
                $bodegaIds = $administrador
                    ? []
                    : array_map('intval', array_column(corozao_bodegas_asignadas($pdo, $usuarioActual), 'bodega_id'));
                if ($administrador || $bodegaIds) {
                    $sqlPrecio = 'SELECT pr.nombre AS presentacion, b.nombre AS bodega, pb.precio_venta,
                                         pb.precio_incluye_iva, pb.tarifa_iva, pb.vigente_desde
                                  FROM presentaciones pr
                                  INNER JOIN bodega_presentaciones bp ON bp.presentacion_id = pr.presentacion_id
                                  INNER JOIN bodegas b ON b.bodega_id = bp.bodega_id
                                  INNER JOIN precios_bodega pb ON pb.bodega_presentacion_id = bp.bodega_presentacion_id
                                  WHERE pr.producto_id = :id AND pb.vigente_hasta IS NULL';
                    $paramsPrecio = ['id' => (int)$idDetalle];
                    if (!$administrador) {
                        $marcadores = [];
                        foreach ($bodegaIds as $i => $bodegaId) {
                            $marcador = ':bodega' . $i;
                            $marcadores[] = $marcador;
                            $paramsPrecio['bodega' . $i] = $bodegaId;
                        }
                        $sqlPrecio .= ' AND bp.bodega_id IN (' . implode(', ', $marcadores) . ')';
                    }
                    $sqlPrecio .= ' ORDER BY pr.nombre, b.nombre, pb.vigente_desde DESC';
                    $stmt = $pdo->prepare($sqlPrecio);
                    $stmt->execute($paramsPrecio);
                    $preciosBodega = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    } elseif ($idEdicion !== false && $idEdicion !== null && $administrador) {
        $stmt = $pdo->prepare('SELECT * FROM productos WHERE producto_id = :id LIMIT 1');
        $stmt->execute(['id' => (int)$idEdicion]);
        $producto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$producto) {
            throw new RuntimeException('No se encontró el producto solicitado.');
        }
    } elseif (!$crear) {
        $where = [];
        $params = [];
        if ($buscar !== '') {
            $where[] = '(
                p.nombre LIKE :nombre OR p.marca LIKE :marca OR EXISTS (
                    SELECT 1 FROM presentaciones px
                    INNER JOIN producto_codigos cx ON cx.presentacion_id = px.presentacion_id
                    WHERE px.producto_id = p.producto_id AND cx.codigo LIKE :codigo
                )
            )';
            $like = '%' . $buscar . '%';
            $params += ['nombre' => $like, 'marca' => $like, 'codigo' => $like];
        }
        if ($categoriaFiltro !== false && $categoriaFiltro !== null && (int)$categoriaFiltro > 0) {
            $where[] = 'p.categoria_id = :categoria';
            $params['categoria'] = (int)$categoriaFiltro;
        }
        $estados = [
            'VALIDADO' => "p.estado_comercial = 'VALIDADO'",
            'PENDIENTE' => "p.estado_comercial = 'PENDIENTE_REVISION'",
            'NO_VENTA' => 'p.venta_habilitada = 0',
            'INACTIVO' => 'p.activo = 0',
            'ACTIVO' => 'p.activo = 1',
        ];
        if (isset($estados[$estado])) {
            $where[] = $estados[$estado];
        } else {
            $estado = 'TODOS';
        }
        $condicion = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM productos p {$condicion}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        $paginaMaxima = max(1, (int)ceil($total / $porPagina));
        $pagina = min($pagina, $paginaMaxima);
        $offset = ($pagina - 1) * $porPagina;
        $sql = "SELECT p.producto_id, p.nombre, p.marca, p.contenido, p.estado_comercial,
                       p.venta_habilitada, p.activo, c.nombre AS categoria_nombre,
                       (SELECT GROUP_CONCAT(DISTINCT pc.codigo ORDER BY pc.codigo SEPARATOR ', ')
                        FROM presentaciones pr INNER JOIN producto_codigos pc ON pc.presentacion_id = pr.presentacion_id
                        WHERE pr.producto_id = p.producto_id) AS codigos,
                       (SELECT COUNT(*) FROM presentaciones pr WHERE pr.producto_id = p.producto_id) AS total_presentaciones,
                       0 AS total_precios
                FROM productos p LEFT JOIN categorias c ON c.categoria_id=p.categoria_id
                {$condicion} ORDER BY p.nombre, p.producto_id LIMIT {$porPagina} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Corozao productos: error al consultar el catálogo.');
    if ($error === null) {
        http_response_code(500);
        $error = 'No se pudo consultar el catálogo. Inténtalo más tarde.';
    }
}

$formularioEdicion = $idEdicion !== false && $idEdicion !== null && $administrador && $producto !== null;
$formularioNuevo = $crear;
$detalleVisible = $producto !== null && !$formularioEdicion;
$base = '/productos.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Productos | Corozao</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/brand/corozao-mark.svg?v=2">
    <link rel="stylesheet" href="/assets/css/brand.css">
</head>
<body data-corozao-role="<?= htmlspecialchars((string)($_SESSION['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bg-body-tertiary">
<nav class="navbar navbar-dark shadow-sm" style="background-color:#173b65">
    <div class="container-xxl"><a class="navbar-brand fw-semibold" href="/">Corozao</a><span class="navbar-text text-white">Productos</span></div>
</nav>
<main class="container-xxl py-4 py-lg-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div><h1 class="h2 mb-1">Catálogo de productos</h1><p class="text-secondary mb-0"><?= productos_h($usuarioActual['nombre_completo']) ?> · <?= productos_h($usuarioActual['rol']) ?></p></div>
        <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="/">Volver</a><?php if ($administrador): ?><a class="btn btn-primary" href="/productos.php?crear=1">Crear producto</a><?php endif; ?></div>
    </div>
    <?php if ($mensaje): ?><div class="alert alert-success" role="status"><?= productos_h($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= productos_h($error) ?></div><?php endif; ?>

    <?php if ($formularioNuevo || $formularioEdicion):
        $valores = $formularioEdicion ? $producto : [
            'nombre' => '', 'marca' => '', 'contenido' => '', 'unidad_base' => 'unidad',
            'proveedor_referencial' => '', 'categoria_id' => '', 'estado_comercial' => 'PENDIENTE_REVISION', 'venta_habilitada' => 0,
        ];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach (['nombre', 'marca', 'contenido', 'unidad_base', 'proveedor_referencial', 'categoria_id', 'estado_comercial', 'venta_habilitada', 'presentacion_nombre', 'unidades_base', 'codigo'] as $campo) {
                if (isset($_POST[$campo]) && is_string($_POST[$campo])) {
                    $valores[$campo] = $_POST[$campo];
                }
            }
        }
    ?>
    <section class="card shadow-sm">
        <div class="card-body p-3 p-lg-4">
            <h2 class="h4"><?= $formularioEdicion ? 'Editar producto' : 'Crear producto' ?></h2>
            <form method="post" action="/productos.php" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= productos_h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="<?= $formularioEdicion ? 'actualizar' : 'crear' ?>">
                <?php if ($formularioEdicion): ?><input type="hidden" name="producto_id" value="<?= (int)$producto['producto_id'] ?>"><?php endif; ?>
                <div class="col-12 col-md-6"><label class="form-label" for="nombre">Nombre o descripción *</label><input class="form-control" id="nombre" name="nombre" maxlength="200" required value="<?= productos_h($valores['nombre']) ?>"></div>
                <div class="col-12 col-md-6"><label class="form-label" for="marca">Marca</label><input class="form-control" id="marca" name="marca" maxlength="150" value="<?= productos_h($valores['marca']) ?>"></div>
                <div class="col-12 col-md-6"><label class="form-label" for="proveedor_referencial">Proveedor de referencia</label><input class="form-control" id="proveedor_referencial" name="proveedor_referencial" maxlength="200" value="<?= productos_h($valores['proveedor_referencial'] ?? '') ?>"></div>
                <div class="col-12 col-md-6"><label class="form-label" for="categoria_id">Categoría</label><select class="form-select" id="categoria_id" name="categoria_id"><option value="">Sin categoría</option><?php foreach ($categorias as $cat): ?><option value="<?= (int)$cat['categoria_id'] ?>" <?= (string)($valores['categoria_id'] ?? '') === (string)$cat['categoria_id'] ? 'selected' : '' ?> <?= (int)$cat['activo'] === 0 && (string)($valores['categoria_id'] ?? '') !== (string)$cat['categoria_id'] ? 'disabled' : '' ?>><?= productos_h($cat['nombre']) ?><?= (int)$cat['activo'] === 0 ? ' (inactiva)' : '' ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label" for="contenido">Contenido</label><input class="form-control" id="contenido" name="contenido" maxlength="100" value="<?= productos_h($valores['contenido']) ?>"></div>
                <div class="col-12 col-md-6"><label class="form-label" for="unidad_base">Unidad base *</label><input class="form-control" id="unidad_base" name="unidad_base" maxlength="30" required value="<?= productos_h($valores['unidad_base']) ?>"></div>
                <div class="col-12 col-md-3"><label class="form-label" for="estado_comercial">Estado comercial</label><select class="form-select" id="estado_comercial" name="estado_comercial"><option value="PENDIENTE_REVISION" <?= $valores['estado_comercial'] === 'PENDIENTE_REVISION' ? 'selected' : '' ?>>Pendiente de revisión</option><option value="VALIDADO" <?= $valores['estado_comercial'] === 'VALIDADO' ? 'selected' : '' ?>>Validado</option></select></div>
                <div class="col-12 col-md-3"><label class="form-label" for="venta_habilitada">Habilitado para venta</label><select class="form-select" id="venta_habilitada" name="venta_habilitada"><option value="0" <?= (int)$valores['venta_habilitada'] === 0 ? 'selected' : '' ?>>No</option><option value="1" <?= (int)$valores['venta_habilitada'] === 1 ? 'selected' : '' ?>>Sí</option></select></div>
                <?php if ($formularioNuevo): ?>
                    <div class="col-12"><hr><h3 class="h5">Primera presentación</h3><p class="small text-secondary">El código es opcional. Los códigos se asocian a una presentación.</p></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="presentacion_nombre">Presentación *</label><input class="form-control" id="presentacion_nombre" name="presentacion_nombre" maxlength="100" required placeholder="Ej. Unidad"></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="unidades_base">Unidades base *</label><input class="form-control" id="unidades_base" name="unidades_base" type="number" min="1" step="1" required value="1"></div>
                    <div class="col-12 col-md-4"><label class="form-label" for="codigo">Código (opcional)</label><input class="form-control" id="codigo" name="codigo" maxlength="100"></div>
                    <input type="hidden" name="tipo_codigo" value="VENTA">
                <?php endif; ?>
                <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit"><?= $formularioEdicion ? 'Guardar cambios' : 'Crear producto' ?></button><a class="btn btn-outline-secondary" href="<?= $formularioEdicion ? '/productos.php?id='.(int)$producto['producto_id'] : '/productos.php' ?>">Cancelar</a></div>
            </form>
        </div>
    </section>

    <?php elseif ($detalleVisible): ?>
    <section class="card shadow-sm mb-4"><div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between gap-2"><div><a href="/productos.php" class="btn btn-sm btn-outline-secondary mb-3">← Volver al catálogo</a><h2 class="h3"><?= productos_h($producto['nombre']) ?></h2></div>
        <?php if ($administrador): ?><div class="d-flex align-items-start gap-2"><a class="btn btn-outline-primary" href="/productos.php?editar=<?= (int)$producto['producto_id'] ?>">Editar</a><form method="post" action="/productos.php" onsubmit="return confirm('¿Confirmas el cambio de estado? No se borrará el historial.');"><input type="hidden" name="csrf_token" value="<?= productos_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="estado_producto"><input type="hidden" name="producto_id" value="<?= (int)$producto['producto_id'] ?>"><input type="hidden" name="activo" value="<?= (int)$producto['activo'] === 1 ? '0' : '1' ?>"><button class="btn <?= (int)$producto['activo'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= (int)$producto['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button></form></div><?php endif; ?></div>
        <div class="row g-3 mb-4"><div class="col-12 col-md-6"><dl class="row mb-0"><dt class="col-sm-4">ID</dt><dd class="col-sm-8"><?= (int)$producto['producto_id'] ?></dd><dt class="col-sm-4">Categoría</dt><dd class="col-sm-8"><?= productos_h($producto['categoria_nombre'] ?? 'Sin categoría') ?></dd><dt class="col-sm-4">Marca</dt><dd class="col-sm-8"><?= productos_h($producto['marca'] ?: '—') ?></dd><dt class="col-sm-4">Contenido</dt><dd class="col-sm-8"><?= productos_h($producto['contenido'] ?: '—') ?></dd><dt class="col-sm-4">Proveedor ref.</dt><dd class="col-sm-8"><?= productos_h($producto['proveedor_referencial'] ?: '—') ?></dd></dl></div><div class="col-12 col-md-6"><dl class="row mb-0"><dt class="col-sm-5">Unidad base</dt><dd class="col-sm-7"><?= productos_h($producto['unidad_base']) ?></dd><dt class="col-sm-5">Estado comercial</dt><dd class="col-sm-7"><?= productos_h($producto['estado_comercial']) ?></dd><dt class="col-sm-5">Habilitado para venta</dt><dd class="col-sm-7"><?= (int)$producto['venta_habilitada'] === 1 ? 'Sí' : 'No' ?></dd><dt class="col-sm-5">Producto</dt><dd class="col-sm-7"><span class="badge text-bg-<?= (int)$producto['activo'] === 1 ? 'success' : 'secondary' ?>"><?= (int)$producto['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span></dd></dl></div></div>
        <h3 class="h5">Presentaciones y códigos</h3>
        <div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Presentación</th><th>Unidades base</th><th>Códigos asociados</th><?php if ($administrador): ?><th>Precio general de referencia</th><?php endif; ?><th>Estado</th><?php if ($administrador): ?><th>Acciones</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($presentaciones as $pr): ?><tr><td><?= productos_h($pr['presentacion']) ?></td><td><?= productos_h(productos_unidades_visual($pr['unidades_base'])) ?></td><td><?= productos_h($pr['codigos'] ?? 'Sin código') ?></td><?php if ($administrador): ?><td><?= $pr['precio_referencia'] === null ? 'Sin precio' : '$'.number_format((float)$pr['precio_referencia'], 2, '.', ',') ?></td><?php endif; ?><td><?= (int)$pr['presentacion_activa'] === 1 ? 'Activa' : 'Inactiva' ?></td><?php if ($administrador): ?><td><div class="d-flex flex-wrap gap-2"><form method="post" action="/productos.php" onsubmit="return confirm('¿Cambiar el estado de esta presentación?');"><input type="hidden" name="csrf_token" value="<?= productos_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="estado_presentacion"><input type="hidden" name="producto_id" value="<?= (int)$producto['producto_id'] ?>"><input type="hidden" name="presentacion_id" value="<?= (int)$pr['presentacion_id'] ?>"><input type="hidden" name="activo" value="<?= (int)$pr['presentacion_activa'] === 1 ? '0' : '1' ?>"><button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int)$pr['presentacion_activa'] === 1 ? 'Desactivar' : 'Activar' ?></button></form></div></td><?php endif; ?></tr><?php endforeach; ?>
        <?php if (!$presentaciones): ?><tr><td colspan="<?= $administrador ? 6 : 4 ?>" class="text-secondary">Sin presentaciones.</td></tr><?php endif; ?></tbody></table></div>
        <?php if ($puedeVerPrecios): ?><h3 class="h5 mt-4">Precios vigentes por bodega</h3><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Presentación</th><th>Bodega</th><th>Precio</th><th>IVA</th><th>Desde</th></tr></thead><tbody><?php foreach ($preciosBodega as $precio): ?><tr><td><?= productos_h($precio['presentacion']) ?></td><td><?= productos_h($precio['bodega']) ?></td><td>$<?= number_format((float)$precio['precio_venta'], 2, '.', ',') ?></td><td><?= $precio['tarifa_iva'] === null ? 'Sin tarifa' : productos_h($precio['tarifa_iva']).'%' ?> · <?= (int)$precio['precio_incluye_iva'] === 1 ? 'incluye' : 'no incluye' ?></td><td><?= productos_h($precio['vigente_desde']) ?></td></tr><?php endforeach; ?><?php if (!$preciosBodega): ?><tr><td colspan="5" class="text-secondary">No hay precios vigentes disponibles.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?>
    </div></section>
    <?php if ($administrador): ?><div class="row g-3 mb-4">
        <div class="col-12 col-xl-6"><section class="card h-100"><div class="card-body"><h3 class="h5">Agregar presentación</h3><form method="post" action="/productos.php" class="row g-2"><input type="hidden" name="csrf_token" value="<?= productos_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="agregar_presentacion"><input type="hidden" name="producto_id" value="<?= (int)$producto['producto_id'] ?>"><div class="col-12 col-md-5"><label class="form-label">Nombre</label><input class="form-control" name="presentacion_nombre" maxlength="100" required></div><div class="col-12 col-md-3"><label class="form-label">Unidades base</label><input class="form-control" name="unidades_base" type="number" min="1" step="1" required></div><div class="col-12 col-md-4"><label class="form-label">Código opcional</label><input class="form-control" name="codigo" maxlength="100"></div><input type="hidden" name="tipo_codigo" value="VENTA"><div class="col-12"><button class="btn btn-primary" type="submit">Agregar</button></div></form></div></section></div>
        <div class="col-12 col-xl-6"><section class="card h-100"><div class="card-body"><h3 class="h5">Agregar código a una presentación</h3><form method="post" action="/productos.php" class="row g-2"><input type="hidden" name="csrf_token" value="<?= productos_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="agregar_codigo"><input type="hidden" name="producto_id" value="<?= (int)$producto['producto_id'] ?>"><div class="col-12 col-md-5"><label class="form-label">Presentación</label><select class="form-select" name="presentacion_id" required><?php foreach ($presentaciones as $pr): ?><option value="<?= (int)$pr['presentacion_id'] ?>"><?= productos_h($pr['presentacion']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><label class="form-label">Código</label><input class="form-control" name="codigo" maxlength="100" required></div><div class="col-12 col-md-3"><label class="form-label">Tipo</label><input class="form-control" name="tipo_codigo" maxlength="30" value="VENTA" required></div><div class="col-12"><button class="btn btn-primary" type="submit">Agregar código</button></div></form></div></section></div>
    </div><?php endif; ?>

    <?php else: ?>
    <section class="card shadow-sm mb-4"><div class="card-body"><form method="get" action="/productos.php" class="row g-3 align-items-end">
        <div class="col-12 col-md-6 col-xl-5"><label class="form-label" for="q">Buscar por código, nombre o marca</label><input class="form-control" id="q" name="q" maxlength="100" value="<?= productos_h($buscar) ?>"></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="categoria">Categoría</label><select class="form-select" id="categoria" name="categoria"><option value="">Todas</option><?php foreach ($categorias as $cat): ?><option value="<?= (int)$cat['categoria_id'] ?>" <?= $categoriaFiltro !== false && $categoriaFiltro !== null && (int)$categoriaFiltro === (int)$cat['categoria_id'] ? 'selected' : '' ?>><?= productos_h($cat['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-6 col-xl-2"><label class="form-label" for="estado">Estado</label><select class="form-select" id="estado" name="estado"><?php foreach (['TODOS'=>'Todos','ACTIVO'=>'Activos','INACTIVO'=>'Inactivos','VALIDADO'=>'Validados','PENDIENTE'=>'Pendientes de revisión','NO_VENTA'=>'Venta no habilitada'] as $v=>$label): ?><option value="<?= productos_h($v) ?>" <?= $estado === $v ? 'selected' : '' ?>><?= productos_h($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-6 col-xl-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit">Buscar</button><a class="btn btn-outline-secondary" href="/productos.php">Limpiar</a></div>
    </form></div></section>
    <section class="card shadow-sm"><div class="card-body"><p><strong><?= $total ?></strong> producto(s) · Página <?= $pagina ?> de <?= $paginaMaxima ?></p><div class="table-responsive"><table class="table table-striped table-hover align-middle"><thead><tr><th>Código</th><th>Producto</th><th>Categoría</th><th>Estado</th><th>Venta</th><th>Presentaciones</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach ($productos as $p): ?><tr><td><?= productos_h($p['codigos'] ?: '—') ?></td><td><strong><?= productos_h($p['nombre']) ?></strong><?php if ($p['marca']): ?><br><span class="small text-secondary"><?= productos_h($p['marca']) ?></span><?php endif; ?><?php if ($p['contenido']): ?><br><span class="small text-secondary"><?= productos_h($p['contenido']) ?></span><?php endif; ?></td><td><?= productos_h($p['categoria_nombre'] ?? 'Sin categoría') ?></td><td><span class="badge text-bg-<?= (int)$p['activo'] === 1 ? 'success' : 'secondary' ?>"><?= (int)$p['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span><br><small><?= productos_h($p['estado_comercial']) ?></small></td><td><?= (int)$p['venta_habilitada'] === 1 ? 'Habilitada' : 'No habilitada' ?></td><td><?= (int)$p['total_presentaciones'] ?></td><td><div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-primary" href="/productos.php?id=<?= (int)$p['producto_id'] ?>">Detalle</a><?php if ($administrador): ?><a class="btn btn-sm btn-outline-secondary" href="/productos.php?editar=<?= (int)$p['producto_id'] ?>">Editar</a><?php endif; ?></div></td></tr><?php endforeach; ?>
    <?php if (!$productos): ?><tr><td colspan="7" class="text-secondary">No se encontraron productos.</td></tr><?php endif; ?></tbody></table></div>
    <?php if ($paginaMaxima > 1): ?><nav class="d-flex justify-content-between align-items-center" aria-label="Paginación"><span>Página <?= $pagina ?> de <?= $paginaMaxima ?></span><div class="d-flex gap-2"><?php if ($pagina > 1): ?><a class="btn btn-sm btn-outline-primary" href="?<?= productos_h(http_build_query(['q'=>$buscar,'categoria'=>$categoriaFiltro ?: '', 'estado'=>$estado,'pagina'=>$pagina-1])) ?>">Anterior</a><?php endif; ?><?php if ($pagina < $paginaMaxima): ?><a class="btn btn-sm btn-outline-primary" href="?<?= productos_h(http_build_query(['q'=>$buscar,'categoria'=>$categoriaFiltro ?: '', 'estado'=>$estado,'pagina'=>$pagina+1])) ?>">Siguiente</a><?php endif; ?></div></nav><?php endif; ?>
    </div></section>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/brand.js" defer></script>
</body>
</html>
