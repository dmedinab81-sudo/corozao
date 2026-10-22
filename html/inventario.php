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

function inventario_h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inventario_decimal(mixed $value): string
{
    $texto = (string) $value;
    if (!preg_match('/^-?\d+(?:\.\d{1,3})?$/', $texto)) {
        return '0.000';
    }
    $negativo = str_starts_with($texto, '-');
    $texto = ltrim($texto, '-');
    [$entero, $fraccion] = array_pad(explode('.', $texto, 2), 2, '');
    return ($negativo ? '-' : '') . number_format((int) $entero, 0, '.', ',') . '.' . str_pad($fraccion, 3, '0');
}

$usuario = corozao_exigir_permiso($pdo, 'inventory.availability.view');
$esAdministrador = $usuario['rol'] === 'ADMINISTRADOR';
$bodegas = corozao_bodegas_asignadas($pdo, $usuario);
$modo = $esAdministrador && ($_GET['vista'] ?? '') === 'kardex' ? 'kardex' : 'existencias';
$bodegaRaw = $_GET['bodega'] ?? '';
$bodegaId = is_string($bodegaRaw) ? filter_var($bodegaRaw, FILTER_VALIDATE_INT) : false;
$qRaw = $_GET['q'] ?? '';
$buscar = is_string($qRaw) ? trim($qRaw) : '';
$desdeRaw = $_GET['desde'] ?? '';
$hastaRaw = $_GET['hasta'] ?? '';
$fechaValida = static function (mixed $fecha): string {
    if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return '';
    }
    $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return $objeto && $objeto->format('Y-m-d') === $fecha ? $fecha : '';
};
$desde = $fechaValida($desdeRaw);
$hasta = $fechaValida($hastaRaw);
$bodegaActualId = null;
$error = (($desdeRaw !== '' && $desde === '') || ($hastaRaw !== '' && $hasta === ''))
    ? 'Ingresa fechas válidas para el filtro.'
    : null;
$filas = [];

if ($bodegas) {
    $bodegaActualId = $bodegaId !== false && $bodegaId !== null
        ? corozao_exigir_bodega($pdo, $usuario, $bodegaId)
        : (int) $bodegas[0]['bodega_id'];
} else {
    $error = 'No tienes bodegas activas asignadas para consultar.';
}

if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
    $error = 'El rango de fechas no es válido.';
}
if (strlen($buscar) > 100) {
    $error = 'La búsqueda admite hasta 100 caracteres.';
}

try {
    if ($bodegaActualId !== null) {
        $permisoBodega = $modo === 'kardex' ? 'inventory.kardex.view' : 'inventory.availability.view';
        corozao_exigir_permiso_en_bodega($pdo, $permisoBodega, $bodegaActualId);

        if ($error === null && $modo === 'existencias') {
            $sql = 'SELECT p.producto_id, p.nombre AS producto, s.cantidad_base
                    FROM inventario_saldos s
                    INNER JOIN productos p ON p.producto_id = s.producto_id
                    WHERE s.bodega_id = :bodega AND p.nombre LIKE :buscar';
            $params = ['bodega' => $bodegaActualId, 'buscar' => '%' . $buscar . '%'];
            $sql .= ' ORDER BY p.nombre, p.producto_id LIMIT 500';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($error === null) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla
                   AND COLUMN_NAME IN (\'fecha_despacho\', \'despachado_por_usuario_id\',
                                       \'fecha_recepcion\', \'recibido_por_usuario_id\')'
            );
            $stmt->execute(['tabla' => 'movimientos_inventario']);
            $dosEtapas = (int) $stmt->fetchColumn() === 4;
            $fechaEfectiva = $dosEtapas
                ? 'CASE WHEN m.tipo_movimiento = \'TRANSFERENCIA\' AND m.bodega_origen_id = :fecha_origen
                         THEN COALESCE(m.fecha_despacho, m.fecha_movimiento)
                         WHEN m.tipo_movimiento = \'TRANSFERENCIA\' AND m.bodega_destino_id = :fecha_destino
                         THEN COALESCE(m.fecha_recepcion, m.fecha_confirmacion, m.fecha_movimiento)
                         ELSE m.fecha_movimiento END'
                : 'm.fecha_movimiento';
            $usuarioMovimiento = $dosEtapas
                ? 'CASE WHEN m.tipo_movimiento = \'TRANSFERENCIA\' AND m.bodega_origen_id = :usuario_origen
                         THEN ud.nombre_completo ELSE uf.nombre_completo END'
                : 'uf.nombre_completo';
            $fechaFiltro = static fn (string $sufijo): string => $dosEtapas
                ? 'CASE WHEN m.tipo_movimiento = \'TRANSFERENCIA\' AND m.bodega_origen_id = :filtro_fecha_origen_' . $sufijo . '
                         THEN COALESCE(m.fecha_despacho, m.fecha_movimiento)
                         WHEN m.tipo_movimiento = \'TRANSFERENCIA\' AND m.bodega_destino_id = :filtro_fecha_destino_' . $sufijo . '
                         THEN COALESCE(m.fecha_recepcion, m.fecha_confirmacion, m.fecha_movimiento)
                         ELSE m.fecha_movimiento END'
                : 'm.fecha_movimiento';
            $sql = 'SELECT m.movimiento_id, m.tipo_movimiento, m.fecha_movimiento,
                           m.observaciones, p.producto_id, p.nombre AS producto,
                           d.cantidad_base, d.costo_unitario,
                           CASE WHEN m.bodega_destino_id = :bodega_signo
                                THEN d.cantidad_base ELSE -d.cantidad_base END AS variacion,
                           ' . $fechaEfectiva . ' AS fecha_efectiva,
                           m.estado AS estado_movimiento,
                           bo.nombre AS bodega_origen, bd.nombre AS bodega_destino,
                           uc.nombre_completo AS usuario_creador,
                           ' . $usuarioMovimiento . ' AS usuario_confirmador
                    FROM movimientos_inventario m
                    INNER JOIN detalle_movimiento_inventario d ON d.movimiento_id = m.movimiento_id
                    INNER JOIN productos p ON p.producto_id = d.producto_id
                    LEFT JOIN bodegas bo ON bo.bodega_id = m.bodega_origen_id
                    LEFT JOIN bodegas bd ON bd.bodega_id = m.bodega_destino_id
                    LEFT JOIN usuarios uc ON uc.usuario_id = m.usuario_id
                    LEFT JOIN usuarios uf ON uf.usuario_id = m.confirmado_por_usuario_id
                    ' . ($dosEtapas ? 'LEFT JOIN usuarios ud ON ud.usuario_id = m.despachado_por_usuario_id ' : '') . '
                    WHERE ((m.estado = \'CONFIRMADO\'
                      AND (m.bodega_origen_id = :bodega_origen OR m.bodega_destino_id = :bodega_destino))'
                    . ($dosEtapas
                        ? ' OR (m.tipo_movimiento = \'TRANSFERENCIA\' AND m.estado = \'EN_TRANSITO\'
                             AND m.bodega_origen_id = :bodega_transito)'
                        : '') . ')
                      AND p.nombre LIKE :buscar';
            $params = [
                'bodega_signo' => $bodegaActualId,
                'bodega_origen' => $bodegaActualId,
                'bodega_destino' => $bodegaActualId,
                'buscar' => '%' . $buscar . '%',
            ];
            if ($dosEtapas) {
                $params['fecha_origen'] = $bodegaActualId;
                $params['fecha_destino'] = $bodegaActualId;
                $params['usuario_origen'] = $bodegaActualId;
                $params['bodega_transito'] = $bodegaActualId;
            }
            if ($desde !== '') {
                $sql .= ' AND ' . $fechaFiltro('desde') . ' >= :desde';
                $params['desde'] = $desde . ' 00:00:00';
                if ($dosEtapas) {
                    $params['filtro_fecha_origen_desde'] = $bodegaActualId;
                    $params['filtro_fecha_destino_desde'] = $bodegaActualId;
                }
            }
            if ($hasta !== '') {
                $sql .= ' AND ' . $fechaFiltro('hasta') . ' < :hasta';
                $params['hasta'] = (new DateTimeImmutable($hasta))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
                if ($dosEtapas) {
                    $params['filtro_fecha_origen_hasta'] = $bodegaActualId;
                    $params['filtro_fecha_destino_hasta'] = $bodegaActualId;
                }
            }
            $sql .= ' ORDER BY fecha_efectiva DESC, m.movimiento_id DESC, d.detalle_movimiento_id LIMIT 500';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    error_log('Corozao: no se pudo consultar inventario/Kardex.');
    http_response_code(500);
    $error = 'No se pudo cargar la consulta. Inténtalo más tarde.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Corozao | Inventario</title>
</head>
<body class="bg-body-tertiary">
<nav class="navbar bg-white border-bottom"><div class="container-xxl">
    <a class="navbar-brand fw-semibold" href="/">Corozao</a>
    <span class="badge text-bg-primary"><?= inventario_h($usuario['rol']) ?></span>
</div></nav>
<main class="container-xxl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><p class="text-primary fw-semibold mb-1">Inventario</p><h1 class="h2 mb-0"><?= $modo === 'kardex' ? 'Kardex' : 'Existencias' ?></h1></div>
        <?php if ($esAdministrador): ?>
            <div class="btn-group" role="group" aria-label="Vista de inventario">
                <a class="btn <?= $modo === 'existencias' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?vista=existencias&bodega=<?= (int) ($bodegaActualId ?? 0) ?>">Existencias</a>
                <a class="btn <?= $modo === 'kardex' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?vista=kardex&bodega=<?= (int) ($bodegaActualId ?? 0) ?>">Kardex</a>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= inventario_h($error) ?></div><?php endif; ?>
    <form class="card card-body shadow-sm mb-3" method="get">
        <?php if ($esAdministrador): ?><input type="hidden" name="vista" value="<?= inventario_h($modo) ?>"><?php endif; ?>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label" for="bodega">Bodega</label>
                <select class="form-select" id="bodega" name="bodega" required>
                    <?php foreach ($bodegas as $bodega): ?><option value="<?= (int) $bodega['bodega_id'] ?>" <?= (int) $bodega['bodega_id'] === $bodegaActualId ? 'selected' : '' ?>><?= inventario_h($bodega['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4"><label class="form-label" for="q">Producto</label><input class="form-control" id="q" name="q" maxlength="100" value="<?= inventario_h($buscar) ?>" placeholder="Buscar por nombre"></div>
            <?php if ($modo === 'kardex'): ?>
                <div class="col-6 col-md-2"><label class="form-label" for="desde">Desde</label><input class="form-control" type="date" id="desde" name="desde" value="<?= inventario_h($desde) ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="hasta">Hasta</label><input class="form-control" type="date" id="hasta" name="hasta" value="<?= inventario_h($hasta) ?>"></div>
            <?php endif; ?>
            <div class="col-12"><button class="btn btn-primary" type="submit">Buscar</button> <a class="btn btn-outline-secondary" href="/inventario.php">Limpiar</a></div>
        </div>
    </form>
    <?php if ($error === null): ?>
        <section class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <?php if ($modo === 'kardex'): ?><th>Fecha</th><th>Movimiento</th><th>Producto</th><th class="text-end">Variación</th><th>Origen</th><th>Destino</th><th>Registrado por</th><th>Confirmado por</th><th>Observaciones</th>
                <?php else: ?><th>Producto</th><th class="text-end">Existencia base</th><?php endif; ?>
            </tr></thead><tbody>
            <?php foreach ($filas as $fila): ?><tr>
                <?php if ($modo === 'kardex'): ?>
                    <td><?= inventario_h($fila['fecha_efectiva']) ?></td><td><?= inventario_h($fila['tipo_movimiento']) ?><?= $fila['estado_movimiento'] === 'EN_TRANSITO' ? ' (EN TRÁNSITO)' : '' ?> #<?= (int) $fila['movimiento_id'] ?></td><td><?= inventario_h($fila['producto']) ?></td>
                    <td class="text-end <?= str_starts_with((string) $fila['variacion'], '-') ? 'text-danger' : 'text-success' ?>"><?= inventario_decimal($fila['variacion']) ?></td>
                    <td><?= inventario_h($fila['bodega_origen'] ?? '—') ?></td><td><?= inventario_h($fila['bodega_destino'] ?? '—') ?></td>
                    <td><?= inventario_h($fila['usuario_creador'] ?? '—') ?></td><td><?= inventario_h($fila['usuario_confirmador'] ?? '—') ?></td><td><?= inventario_h($fila['observaciones'] ?? '—') ?></td>
                <?php else: ?><td><?= inventario_h($fila['producto']) ?></td><td class="text-end fw-semibold"><?= inventario_decimal($fila['cantidad_base']) ?></td><?php endif; ?>
            </tr><?php endforeach; ?>
            <?php if (!$filas): ?><tr><td colspan="9" class="text-center text-body-secondary py-4">No hay resultados para estos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table></div><div class="card-footer bg-white text-body-secondary">Máximo 500 filas por consulta. Las existencias y cantidades se expresan en unidades base.</div></section>
    <?php endif; ?>
    <div class="mt-3"><a href="/" class="btn btn-link px-0">Volver al panel</a></div>
</main>
</body>
</html>
