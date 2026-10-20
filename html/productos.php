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

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function precio(mixed $value): string
{
    return $value === null
        ? 'Sin precio'
        : '$' . number_format((float) $value, 2, '.', ',');
}

require '/etc/corozao/database.php';
require_once __DIR__ . '/includes/autorizacion.php';
$usuarioActual = corozao_exigir_permiso($pdo, 'catalog.products.view', true);
$puedeVerPrecios = $usuarioActual['rol'] === 'ADMINISTRADOR'
    && corozao_rol_puede((string) $usuarioActual['rol'], 'commercial.prices.view');

$error = null;
$producto = null;
$presentaciones = [];
$productos = [];
$categorias = [];
$total = 0;

$buscarRecibido = $_GET['q'] ?? '';
$buscar = is_string($buscarRecibido) ? trim($buscarRecibido) : '';
$estadoRecibido = $_GET['estado'] ?? 'TODOS';
$estado = is_string($estadoRecibido) ? $estadoRecibido : 'TODOS';
$categoriaId = filter_var(
    $_GET['categoria'] ?? '',
    FILTER_VALIDATE_INT
);
$pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);

$pagina = max(1, (int) ($pagina ?: 1));
$porPagina = 50;
$paginaMaxima = 1;

try {
    $categorias = $pdo->query(
        'SELECT categoria_id, nombre
         FROM categorias
         WHERE activo = 1
         ORDER BY nombre'
    )->fetchAll();

    $idDetalle = filter_var(
        $_GET['id'] ?? '',
        FILTER_VALIDATE_INT
    );

    if ($idDetalle !== false && $idDetalle !== null) {
        $stmt = $pdo->prepare(
            'SELECT p.*, c.nombre AS categoria_nombre
             FROM productos p
             LEFT JOIN categorias c ON c.categoria_id = p.categoria_id
             WHERE p.producto_id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $idDetalle]);
        $producto = $stmt->fetch();

        if ($producto) {
            $columnasPrecio = $puedeVerPrecios ? '
                    (SELECT pp.precio_venta FROM precios_producto pp
                     WHERE pp.presentacion_id = pr.presentacion_id AND pp.vigente_hasta IS NULL
                     ORDER BY pp.vigente_desde DESC, pp.precio_id DESC LIMIT 1) AS precio_venta,
                    (SELECT pp.precio_incluye_iva FROM precios_producto pp
                     WHERE pp.presentacion_id = pr.presentacion_id AND pp.vigente_hasta IS NULL
                     ORDER BY pp.vigente_desde DESC, pp.precio_id DESC LIMIT 1) AS precio_incluye_iva,
                    (SELECT pp.tarifa_iva FROM precios_producto pp
                     WHERE pp.presentacion_id = pr.presentacion_id AND pp.vigente_hasta IS NULL
                     ORDER BY pp.vigente_desde DESC, pp.precio_id DESC LIMIT 1) AS tarifa_iva,' :
                    'NULL AS precio_venta, NULL AS precio_incluye_iva, NULL AS tarifa_iva,';
            $stmt = $pdo->prepare(
                "SELECT
                    pr.presentacion_id,
                    pr.nombre AS presentacion,
                    pr.unidades_base,
                    pr.activo AS presentacion_activa,
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(pc.codigo, ' (', pc.tipo_codigo, ')')
                            ORDER BY pc.codigo SEPARATOR ', '
                        )
                        FROM producto_codigos pc
                        WHERE pc.presentacion_id = pr.presentacion_id
                    ) AS codigos,
                    $columnasPrecio
                 FROM presentaciones pr
                 WHERE pr.producto_id = :id
                 ORDER BY pr.activo DESC, pr.nombre"
            );
            $stmt->execute(['id' => $idDetalle]);
            $presentaciones = $stmt->fetchAll();
        }
    } else {
        $where = [];
        $params = [];

        if ($buscar !== '') {
            $where[] = '(
                p.nombre LIKE :nombre
                OR p.marca LIKE :marca
                OR EXISTS (
                    SELECT 1
                    FROM presentaciones prq
                    INNER JOIN producto_codigos pcq
                        ON pcq.presentacion_id = prq.presentacion_id
                    WHERE prq.producto_id = p.producto_id
                      AND pcq.codigo LIKE :codigo
                )
            )';

            $termino = '%' . $buscar . '%';
            $params['nombre'] = $termino;
            $params['marca'] = $termino;
            $params['codigo'] = $termino;
        }

        if ($categoriaId !== false && $categoriaId !== null) {
            $where[] = 'p.categoria_id = :categoria';
            $params['categoria'] = (int) $categoriaId;
        }

        if ($estado === 'PENDIENTE') {
            $where[] = "p.estado_comercial = 'PENDIENTE_REVISION'";
        } elseif ($estado === 'VALIDADO') {
            $where[] = "p.estado_comercial = 'VALIDADO'";
        } elseif ($estado === 'NO_VENTA') {
            $where[] = 'p.venta_habilitada = 0';
        } elseif ($estado === 'INACTIVO') {
            $where[] = 'p.activo = 0';
        } elseif ($estado === 'TODOS') {
            // No se aplica filtro comercial.
        } else {
            $estado = 'TODOS';
        }

        $condicion = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM productos p $condicion"
        );
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $paginaMaxima = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $paginaMaxima);
        $offset = ($pagina - 1) * $porPagina;

        $columnasPrecioLista = $puedeVerPrecios ? '
                (SELECT COUNT(*) FROM presentaciones pr
                 INNER JOIN precios_producto pp ON pp.presentacion_id = pr.presentacion_id
                 WHERE pr.producto_id = p.producto_id AND pp.vigente_hasta IS NULL) AS total_precios,
                (SELECT COUNT(*) FROM presentaciones pr
                 INNER JOIN precios_producto pp ON pp.presentacion_id = pr.presentacion_id
                 WHERE pr.producto_id = p.producto_id AND pp.vigente_hasta IS NULL
                   AND pp.tarifa_iva IS NULL) AS precios_sin_iva' :
                '0 AS total_precios, 0 AS precios_sin_iva';
        $sql = "
            SELECT
                p.producto_id,
                p.nombre,
                p.marca,
                p.contenido,
                p.estado_comercial,
                p.venta_habilitada,
                p.activo,
                c.nombre AS categoria_nombre,
                (
                    SELECT COUNT(*)
                    FROM presentaciones pr
                    WHERE pr.producto_id = p.producto_id
                ) AS total_presentaciones,
                $columnasPrecioLista
            FROM productos p
            LEFT JOIN categorias c ON c.categoria_id = p.categoria_id
            $condicion
            ORDER BY p.nombre, p.producto_id
            LIMIT $porPagina OFFSET $offset
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $productos = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    error_log('Corozao productos: error al consultar el catálogo.');
    http_response_code(500);
    $error = 'No se pudo consultar el catálogo. Inténtalo más tarde.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Productos | Corozao</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f3f5f8;
            color: #1f2937;
            font-family: Arial, sans-serif;
        }
        header {
            padding: 18px 24px;
            color: white;
            background: #173b65;
        }
        header a { color: white; text-decoration: none; }
        main { max-width: 1400px; margin: 24px auto; padding: 0 18px; }
        .panel {
            padding: 22px;
            margin-bottom: 18px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        .filtros {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            align-items: end;
        }
        label { display: block; margin-bottom: 6px; font-weight: bold; }
        input, select, button {
            width: 100%;
            min-height: 40px;
            padding: 9px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: white;
            font-size: 15px;
        }
        button, .boton {
            border: 0;
            color: white;
            background: #173b65;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            display: inline-block;
            padding: 11px 14px;
            border-radius: 6px;
        }
        .tabla { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; background: white; }
        th, td {
            padding: 11px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }
        th { background: #f8fafc; }
        .estado { font-weight: bold; }
        .pendiente, .alerta { color: #92400e; }
        .validado { color: #166534; }
        .apagado { color: #64748b; }
        .muted { color: #64748b; }
        .aviso {
            padding: 12px;
            margin: 12px 0;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 6px;
            color: #9a3412;
        }
        .error {
            padding: 12px;
            background: #fef2f2;
            color: #991b1b;
            border-radius: 6px;
        }
        .paginacion { display: flex; gap: 12px; align-items: center; margin-top: 18px; }
        .paginacion a { color: #173b65; }
        @media (max-width: 650px) {
            th, td { padding: 8px; }
            .panel { padding: 14px; }
        }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<header class="navbar navbar-dark shadow-sm" style="background-color: #173b65">
    <div class="container-xxl">
        <a class="navbar-brand fw-semibold" href="/">Corozao</a>
        <span class="navbar-text text-white">Catálogo de productos</span>
    </div>
</header>

<main class="container-xxl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
      <div>
        <h1 class="h2 mb-1">Productos</h1>
        <p class="text-body-secondary mb-0">
        Usuario: <?= h($_SESSION['nombre_completo'] ?? $_SESSION['nombre_usuario'] ?? '') ?>
        </p>
      </div>
      <a class="btn btn-outline-primary" href="/">Volver al inicio</a>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
    <?php elseif ($producto !== null): ?>
        <section class="panel card shadow-sm">
            <p><a class="btn btn-sm btn-outline-secondary" href="/productos.php">← Volver al catálogo</a></p>
            <h2 class="h3"><?= h($producto['nombre']) ?></h2>
            <p><strong>ID:</strong> <?= h($producto['producto_id']) ?></p>
            <p><strong>Categoría:</strong> <?= h($producto['categoria_nombre'] ?? 'Sin categoría') ?></p>
            <p><strong>Marca:</strong> <?= h($producto['marca'] ?? '—') ?></p>
            <p><strong>Contenido:</strong> <?= h($producto['contenido'] ?? '—') ?></p>
            <p><strong>Unidad base:</strong> <?= h($producto['unidad_base']) ?></p>
            <p><strong>Estado comercial:</strong> <?= h($producto['estado_comercial']) ?></p>
            <p><strong>Venta habilitada:</strong> <?= (int) $producto['venta_habilitada'] === 1 ? 'Sí' : 'No' ?></p>
            <p><strong>Producto activo:</strong> <?= (int) $producto['activo'] === 1 ? 'Sí' : 'No' ?></p>

            <?php if ($producto['estado_comercial'] !== 'VALIDADO' || !(int) $producto['venta_habilitada']): ?>
                <div class="aviso alert alert-warning">Este producto requiere revisión antes de habilitar su venta.</div>
            <?php endif; ?>

            <h3 class="h5">Presentaciones y códigos<?= $puedeVerPrecios ? ', precios vigentes' : '' ?></h3>
            <?php if (!$puedeVerPrecios): ?>
                <p class="alert alert-info">Los precios comerciales se consultan en contexto de bodega desde el módulo correspondiente.</p>
            <?php endif; ?>
            <div class="tabla table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead>
                    <tr>
                        <th>Presentación</th>
                        <th>Unidades base</th>
                        <th>Códigos</th>
                        <?php if ($puedeVerPrecios): ?><th>Precio</th><th>IVA</th><?php endif; ?>
                        <th>Estado</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($presentaciones as $pr): ?>
                        <tr>
                            <td><?= h($pr['presentacion']) ?></td>
                            <td><?= h($pr['unidades_base']) ?></td>
                            <td><?= h($pr['codigos'] ?? 'Sin código') ?></td>
                            <?php if ($puedeVerPrecios): ?>
                                <td><?= h(precio($pr['precio_venta'])) ?></td>
                                <td>
                                    <?php if ($pr['tarifa_iva'] === null): ?>
                                        <span class="alerta text-warning-emphasis">Tarifa pendiente</span>
                                    <?php else: ?>
                                        <?= h(number_format((float) $pr['tarifa_iva'], 2)) ?>%
                                    <?php endif; ?>
                                    <?php if ($pr['precio_incluye_iva'] !== null): ?>
                                        <br><small><?= (int) $pr['precio_incluye_iva'] === 1 ? 'Precio incluye IVA' : 'Precio sin IVA' ?></small>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><?= (int) $pr['presentacion_activa'] === 1 ? 'Activa' : 'Inactiva' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$presentaciones): ?>
                        <tr><td colspan="<?= $puedeVerPrecios ? 6 : 4 ?>">Este producto no tiene presentaciones.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>
        <section class="panel card shadow-sm">
            <form method="get" action="/productos.php">
                <div class="filtros row g-3 align-items-end">
                    <div class="col-12 col-md-6 col-xl-5">
                        <label class="form-label" for="q">Nombre, marca o código</label>
                        <input class="form-control" id="q" name="q" value="<?= h($buscar) ?>" maxlength="100">
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="categoria">Categoría</label>
                        <select class="form-select" id="categoria" name="categoria">
                            <option value="">Todas</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= h($cat['categoria_id']) ?>"
                                    <?= $categoriaId !== false && $categoriaId !== null && (int) $categoriaId === (int) $cat['categoria_id'] ? 'selected' : '' ?>>
                                    <?= h($cat['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="estado">Estado</label>
                        <select class="form-select" id="estado" name="estado">
                            <?php
                            $estados = [
                                'TODOS' => 'Todos',
                                'VALIDADO' => 'Validados',
                                'PENDIENTE' => 'Pendientes de revisión',
                                'NO_VENTA' => 'Venta no habilitada',
                                'INACTIVO' => 'Inactivos',
                            ];
                            foreach ($estados as $valor => $etiqueta):
                            ?>
                                <option value="<?= h($valor) ?>" <?= $estado === $valor ? 'selected' : '' ?>>
                                    <?= h($etiqueta) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2"><button class="btn btn-primary w-100" type="submit">Buscar</button></div>
                </div>
            </form>
        </section>

        <section class="panel card shadow-sm">
            <p>
                <strong><?= h($total) ?></strong> producto(s) encontrados.
                <span class="muted">Mostrando hasta 50 por página.</span>
            </p>

            <?php if ($total > 0): ?>
                <div class="tabla table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Producto</th>
                            <th>Categoría</th>
                            <th>Estado comercial</th>
                            <th>Venta</th>
                            <th>Presentaciones</th>
                            <?php if ($puedeVerPrecios): ?><th>Precios</th><th>IVA pendiente</th><?php endif; ?>
                            <th>Detalle</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($productos as $p): ?>
                            <tr>
                                <td><?= h($p['producto_id']) ?></td>
                                <td>
                                    <strong><?= h($p['nombre']) ?></strong>
                                    <?php if ($p['marca']): ?>
                                        <br><small><?= h($p['marca']) ?></small>
                                    <?php endif; ?>
                                    <?php if ($p['contenido']): ?>
                                        <br><small><?= h($p['contenido']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($p['categoria_nombre'] ?? 'Sin categoría') ?></td>
                                <td class="estado <?= $p['estado_comercial'] === 'VALIDADO' ? 'validado text-success' : 'pendiente text-warning-emphasis' ?>">
                                    <?= h($p['estado_comercial']) ?>
                                </td>
                                <td><?= (int) $p['venta_habilitada'] === 1 ? 'Habilitada' : 'No habilitada' ?></td>
                                <td><?= h($p['total_presentaciones']) ?></td>
                                <?php if ($puedeVerPrecios): ?>
                                    <td><?= h($p['total_precios']) ?></td>
                                    <td>
                                        <?php if ((int) $p['precios_sin_iva'] > 0): ?>
                                            <span class="alerta text-warning-emphasis"><?= h($p['precios_sin_iva']) ?> pendiente(s)</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td><a class="btn btn-sm btn-outline-primary" href="/productos.php?id=<?= h($p['producto_id']) ?>">Ver detalle</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No se encontraron productos con esos filtros.</p>
            <?php endif; ?>

            <?php if ($paginaMaxima > 1): ?>
                <nav class="paginacion" aria-label="Paginación">
                    <?php if ($pagina > 1): ?>
                        <a class="btn btn-sm btn-outline-primary" href="?<?= h(http_build_query([
                            'q' => $buscar,
                            'categoria' => $categoriaId ?: '',
                            'estado' => $estado,
                            'pagina' => $pagina - 1,
                        ])) ?>">← Anterior</a>
                    <?php endif; ?>
                    <span>Página <?= h($pagina) ?> de <?= h($paginaMaxima) ?></span>
                    <?php if ($pagina < $paginaMaxima): ?>
                        <a class="btn btn-sm btn-outline-primary" href="?<?= h(http_build_query([
                            'q' => $buscar,
                            'categoria' => $categoriaId ?: '',
                            'estado' => $estado,
                            'pagina' => $pagina + 1,
                        ])) ?>">Siguiente →</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
