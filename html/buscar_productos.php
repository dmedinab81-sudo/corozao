<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('COROZAO_SESSID');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require '/etc/corozao/database.php';
require_once __DIR__ . '/includes/autorizacion.php';

function buscar_productos_responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    buscar_productos_responder(405, ['error' => 'Método no permitido.']);
}

$operacion = $_GET['operacion'] ?? '';
$bodegaRaw = $_GET['bodega_id'] ?? '';
$consultaRaw = $_GET['q'] ?? '';
if (!is_string($operacion) || !is_string($bodegaRaw) || !is_string($consultaRaw)) {
    buscar_productos_responder(400, ['error' => 'Parámetros inválidos.']);
}
$permisos = [
    'pedido' => 'orders.catalog.view',
    'compra' => 'purchases.create',
    'compra_editar' => 'purchases.update_draft',
    'venta_pedido' => 'orders.convert_to_sale',
    'venta_directa' => 'sales.direct.create',
];
if (!isset($permisos[$operacion])) {
    buscar_productos_responder(400, ['error' => 'Operación no válida.']);
}
$usuario = corozao_exigir_permiso($pdo, $permisos[$operacion]);
$bodegaId = filter_var($bodegaRaw, FILTER_VALIDATE_INT);
if (!$bodegaId || $bodegaId < 1) {
    buscar_productos_responder(400, ['error' => 'Selecciona una bodega válida.']);
}
corozao_exigir_bodega($pdo, $usuario, $bodegaId);
$consulta = trim($consultaRaw);
if (mb_strlen($consulta, 'UTF-8') < 2) {
    buscar_productos_responder(200, ['resultados' => []]);
}
if (mb_strlen($consulta, 'UTF-8') > 100) {
    buscar_productos_responder(400, ['error' => 'La búsqueda es demasiado larga.']);
}

$esCompra = in_array($operacion, ['compra', 'compra_editar'], true);
$comercial = $esCompra
    ? ''
    : ' AND p.venta_habilitada = 1 AND p.estado_comercial = \'VALIDADO\'
       AND bp.bodega_id = :bodega AND bp.activo = 1
       AND pb.vigente_hasta IS NULL AND pb.vigente_desde <= CURRENT_TIMESTAMP(6)
       AND pb.tarifa_iva IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM precios_bodega px
          WHERE px.bodega_presentacion_id = bp.bodega_presentacion_id
            AND px.vigente_hasta IS NULL AND px.precio_bodega_id <> pb.precio_bodega_id)';
$joinPrecio = $esCompra ? '' : ' INNER JOIN bodega_presentaciones bp ON bp.presentacion_id = pr.presentacion_id
    INNER JOIN precios_bodega pb ON pb.bodega_presentacion_id = bp.bodega_presentacion_id';
$camposComerciales = $esCompra ? '' : ', pb.precio_bodega_id, pb.precio_venta, pb.precio_incluye_iva, pb.tarifa_iva';
$like = '%' . strtr($consulta, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
$sql = 'SELECT DISTINCT p.producto_id, pr.presentacion_id, p.nombre AS producto,
               p.marca, p.contenido, p.unidad_base, c.nombre AS categoria,
               pr.nombre AS presentacion, pr.unidades_base,
               (SELECT GROUP_CONCAT(DISTINCT pc.codigo ORDER BY pc.codigo)
                FROM producto_codigos pc WHERE pc.presentacion_id = pr.presentacion_id) AS codigos'
       . $camposComerciales . '
        FROM productos p
        INNER JOIN presentaciones pr ON pr.producto_id = p.producto_id
        LEFT JOIN categorias c ON c.categoria_id = p.categoria_id'
       . $joinPrecio . '
        WHERE p.activo = 1 AND pr.activo = 1' . $comercial . '
          AND (p.nombre LIKE :nombre ESCAPE \'!\' OR COALESCE(p.marca, \'\') LIKE :marca ESCAPE \'!\'
            OR COALESCE(p.contenido, \'\') LIKE :contenido ESCAPE \'!\'
            OR pr.nombre LIKE :presentacion ESCAPE \'!\'
            OR COALESCE(c.nombre, \'\') LIKE :categoria ESCAPE \'!\'
            OR EXISTS (SELECT 1 FROM producto_codigos cx
                       WHERE cx.presentacion_id = pr.presentacion_id AND cx.codigo LIKE :codigo ESCAPE \'!\'))
        ORDER BY p.nombre, p.marca, p.contenido, pr.nombre LIMIT 30';
try {
    $stmt = $pdo->prepare($sql);
    $params = ['nombre' => $like, 'marca' => $like, 'contenido' => $like,
        'presentacion' => $like, 'categoria' => $like, 'codigo' => $like];
    if (!$esCompra) $params['bodega'] = $bodegaId;
    $stmt->execute($params);
    buscar_productos_responder(200, ['resultados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('Corozao: fallo la búsqueda AJAX de productos.');
    buscar_productos_responder(500, ['error' => 'No se pudo buscar el catálogo.']);
}
