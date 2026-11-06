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
$usuario = corozao_exigir_permiso($pdo, 'commercial.prices.view');
$usuarioId = (int) $usuario['usuario_id'];
$esAdministrador = $usuario['rol'] === 'ADMINISTRADOR';
$puedeAdministrar = corozao_rol_puede((string) $usuario['rol'], 'commercial.prices.manage');
$bodegas = corozao_bodegas_asignadas($pdo, $usuario);

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$error = null;
$flash = null;
$auditoriaDisponible = false;
$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla'
);
$stmt->execute(['tabla' => 'auditoria_eventos']);
$auditoriaDisponible = (int) $stmt->fetchColumn() === 1;

$bodegaIdRaw = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['bodega_id'] ?? '')
    : ($_GET['bodega'] ?? '');
$bodegaId = is_string($bodegaIdRaw) ? filter_var($bodegaIdRaw, FILTER_VALIDATE_INT) : false;
$bodegaId = $bodegaId !== false && $bodegaId !== null && $bodegaId > 0 ? (int) $bodegaId : null;
$bodegaActivaId = null;
$bodegaActual = null;

if ($bodegaId !== null) {
    $bodegaActivaId = corozao_exigir_bodega($pdo, $usuario, $bodegaId);
    foreach ($bodegas as $bodega) {
        if ((int) $bodega['bodega_id'] === $bodegaActivaId) {
            $bodegaActual = $bodega;
            break;
        }
    }
}

$buscarRaw = $_GET['q'] ?? '';
$buscar = is_string($buscarRaw) ? mb_substr(trim($buscarRaw), 0, 100, 'UTF-8') : '';
$categoriaRaw = $_GET['categoria'] ?? '';
$categoriaId = is_string($categoriaRaw) ? filter_var($categoriaRaw, FILTER_VALIDATE_INT) : false;
$categoriaId = $categoriaId !== false && $categoriaId !== null && $categoriaId > 0 ? (int) $categoriaId : null;
$estadoRaw = $_GET['estado'] ?? 'TODOS';
$estado = is_string($estadoRaw) ? $estadoRaw : 'TODOS';
if (!in_array($estado, ['TODOS', 'HABILITADAS', 'DESHABILITADAS', 'SIN_PRECIO', 'IVA_PENDIENTE'], true)) {
    $estado = 'TODOS';
}
$paginaRaw = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
$pagina = max(1, (int) ($paginaRaw ?: 1));

function h(mixed $valor): string
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function decimalNormalizado(string $valor, int $escala): string
{
    [$entero, $fraccion] = array_pad(explode('.', $valor, 2), 2, '');
    $entero = ltrim($entero, '0');
    $entero = $entero === '' ? '0' : $entero;
    return $entero . '.' . str_pad($fraccion, $escala, '0');
}

function decimalVisual(string $valor, int $escala): string
{
    return number_format((float) $valor, $escala, '.', ',');
}

function unidadesVisual(string $valor): string
{
    return str_contains($valor, '.') ? rtrim(rtrim($valor, '0'), '.') : $valor;
}

function snapshotPrecio(?array $precio): ?array
{
    if ($precio === null) {
        return null;
    }
    return [
        'precio_bodega_id' => (int) $precio['precio_bodega_id'],
        'precio_venta' => (string) $precio['precio_venta'],
        'precio_incluye_iva' => (int) $precio['precio_incluye_iva'],
        'tarifa_iva' => $precio['tarifa_iva'] === null ? null : (string) $precio['tarifa_iva'],
        'vigente_desde' => (string) $precio['vigente_desde'],
        'vigente_hasta' => $precio['vigente_hasta'] === null ? null : (string) $precio['vigente_hasta'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $accionRaw = $_POST['accion'] ?? '';
    $accion = is_string($accionRaw) ? $accionRaw : '';
    if (!is_string($token) || !corozao_csrf_valido($token)) {
        http_response_code(403);
        $error = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
    } elseif (!$bodegaActivaId) {
        http_response_code(403);
        $error = 'Selecciona una bodega autorizada.';
    } elseif (!$auditoriaDisponible) {
        http_response_code(503);
        $error = 'La auditoría no está disponible. No se guardaron cambios.';
    } elseif (!in_array($accion, ['activar', 'desactivar', 'precio'], true)) {
        http_response_code(400);
        $error = 'Acción no válida.';
    } else {
        $permisoAccion = $accion === 'precio'
            ? 'commercial.prices.manage'
            : 'commercial.presentations.manage';
        $contexto = corozao_exigir_permiso_en_bodega($pdo, $permisoAccion, $bodegaActivaId);
        $usuarioId = (int) $contexto['usuario']['usuario_id'];
        $presentacionRaw = $_POST['presentacion_id'] ?? '';
        $presentacionId = is_string($presentacionRaw)
            ? filter_var($presentacionRaw, FILTER_VALIDATE_INT)
            : false;
        $presentacionId = $presentacionId !== false && $presentacionId !== null && $presentacionId > 0
            ? (int) $presentacionId
            : null;

        if ($presentacionId === null) {
            $error = 'La presentación seleccionada no es válida.';
        } else {
            $precioTexto = $_POST['precio_venta'] ?? '';
            $incluyeTexto = $_POST['precio_incluye_iva'] ?? '';
            $tarifaTexto = $_POST['tarifa_iva'] ?? '';
            $precioTexto = is_string($precioTexto) ? trim($precioTexto) : '';
            $incluyeTexto = is_string($incluyeTexto) ? $incluyeTexto : '';
            $tarifaTexto = is_string($tarifaTexto) ? trim($tarifaTexto) : '';
            $precioNormalizado = null;
            $tarifaNormalizada = null;

            if ($accion === 'precio') {
                if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/', $precioTexto)
                    || trim(str_replace(['0', '.'], '', $precioTexto)) === '') {
                    $error = 'Ingresa un precio mayor que cero, con hasta dos decimales.';
                } else {
                    $precioNormalizado = decimalNormalizado($precioTexto, 2);
                }
                if ($error === null && !in_array($incluyeTexto, ['0', '1'], true)) {
                    $error = 'Indica explícitamente si el precio incluye IVA.';
                }
                if ($error === null && $tarifaTexto !== '') {
                    if (!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/', $tarifaTexto)) {
                        $error = 'La tarifa de IVA debe tener hasta tres enteros y dos decimales.';
                    } else {
                        $tarifaNormalizada = decimalNormalizado($tarifaTexto, 2);
                    }
                }
            }

            if ($error === null) {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'SELECT bodega_id, nombre FROM bodegas
                         WHERE bodega_id = :bodega AND activo = 1 FOR UPDATE'
                    );
                    $stmt->execute(['bodega' => $bodegaActivaId]);
                    $bodegaLock = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$bodegaLock) {
                        throw new DomainException('La bodega dejó de estar activa.');
                    }

                    $stmt = $pdo->prepare(
                        'SELECT pr.presentacion_id, pr.producto_id, pr.activo AS presentacion_activa,
                                p.nombre AS producto_nombre, p.activo AS producto_activo,
                                p.estado_comercial, p.venta_habilitada
                         FROM presentaciones pr
                         INNER JOIN productos p ON p.producto_id = pr.producto_id
                         WHERE pr.presentacion_id = :presentacion
                         FOR UPDATE'
                    );
                    $stmt->execute(['presentacion' => $presentacionId]);
                    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$producto) {
                        throw new DomainException('La presentación no existe.');
                    }

                    $stmt = $pdo->prepare(
                        'SELECT bodega_presentacion_id, activo
                         FROM bodega_presentaciones
                         WHERE bodega_id = :bodega AND presentacion_id = :presentacion
                         FOR UPDATE'
                    );
                    $stmt->execute([
                        'bodega' => $bodegaActivaId,
                        'presentacion' => $presentacionId,
                    ]);
                    $bodegaPresentacion = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($accion === 'activar' || $accion === 'desactivar') {
                        if ($accion === 'activar') {
                            if ((int) $producto['presentacion_activa'] !== 1
                                || (int) $producto['producto_activo'] !== 1
                                || $producto['estado_comercial'] !== 'VALIDADO'
                                || (int) $producto['venta_habilitada'] !== 1) {
                                throw new DomainException('El producto y la presentación deben estar activos y validados globalmente para habilitarse en esta bodega.');
                            }
                            if ($bodegaPresentacion && (int) $bodegaPresentacion['activo'] === 1) {
                                throw new DomainException('La presentación ya está habilitada en esta bodega.');
                            }
                            if ($bodegaPresentacion) {
                                $stmt = $pdo->prepare(
                                    'UPDATE bodega_presentaciones
                                     SET activo = 1, actualizado_por_usuario_id = :usuario
                                     WHERE bodega_presentacion_id = :id'
                                );
                                $stmt->execute([
                                    'usuario' => $usuarioId,
                                    'id' => $bodegaPresentacion['bodega_presentacion_id'],
                                ]);
                                $bodegaPresentacionId = (int) $bodegaPresentacion['bodega_presentacion_id'];
                                $accionAuditoria = 'MODIFICAR';
                                $anterior = [
                                    'bodega_id' => $bodegaActivaId,
                                    'presentacion_id' => $presentacionId,
                                    'producto_id' => (int) $producto['producto_id'],
                                    'activo' => (int) $bodegaPresentacion['activo'],
                                ];
                            } else {
                                $stmt = $pdo->prepare(
                                    'INSERT INTO bodega_presentaciones
                                        (bodega_id, presentacion_id, activo, actualizado_por_usuario_id)
                                     VALUES (:bodega, :presentacion, 1, :usuario)'
                                );
                                $stmt->execute([
                                    'bodega' => $bodegaActivaId,
                                    'presentacion' => $presentacionId,
                                    'usuario' => $usuarioId,
                                ]);
                                $bodegaPresentacionId = (int) $pdo->lastInsertId();
                                $accionAuditoria = 'CREAR';
                                $anterior = null;
                            }
                            $posterior = [
                                'bodega_id' => $bodegaActivaId,
                                'bodega' => $bodegaLock['nombre'],
                                'presentacion_id' => $presentacionId,
                                'producto_id' => (int) $producto['producto_id'],
                                'activo' => 1,
                            ];
                            $texto = 'Presentación habilitada solo para esta bodega.';
                        } else {
                            if (!$bodegaPresentacion || (int) $bodegaPresentacion['activo'] !== 1) {
                                throw new DomainException('La presentación no está habilitada en esta bodega.');
                            }
                            $bodegaPresentacionId = (int) $bodegaPresentacion['bodega_presentacion_id'];
                            $stmt = $pdo->prepare(
                                'UPDATE bodega_presentaciones
                                 SET activo = 0, actualizado_por_usuario_id = :usuario
                                 WHERE bodega_presentacion_id = :id'
                            );
                            $stmt->execute(['usuario' => $usuarioId, 'id' => $bodegaPresentacionId]);
                            $accionAuditoria = 'DESACTIVAR';
                            $anterior = [
                                'bodega_id' => $bodegaActivaId,
                                'presentacion_id' => $presentacionId,
                                'producto_id' => (int) $producto['producto_id'],
                                'activo' => 1,
                            ];
                            $posterior = $anterior;
                            $posterior['activo'] = 0;
                            $texto = 'Presentación deshabilitada en esta bodega; se conserva su historial.';
                        }
                        corozao_registrar_auditoria(
                            $pdo,
                            $usuarioId,
                            $accionAuditoria,
                            'bodega_presentaciones',
                            $bodegaPresentacionId,
                            $anterior,
                            $posterior
                        );
                    } else {
                        if (!$bodegaPresentacion || (int) $bodegaPresentacion['activo'] !== 1) {
                            throw new DomainException('Habilita primero la presentación para esta bodega.');
                        }
                        $bodegaPresentacionId = (int) $bodegaPresentacion['bodega_presentacion_id'];
                        $stmt = $pdo->prepare(
                            'SELECT precio_bodega_id, precio_venta, precio_incluye_iva,
                                    tarifa_iva, vigente_desde, vigente_hasta
                             FROM precios_bodega
                             WHERE bodega_presentacion_id = :relacion AND vigente_hasta IS NULL
                             ORDER BY vigente_desde DESC, precio_bodega_id DESC
                             FOR UPDATE'
                        );
                        $stmt->execute(['relacion' => $bodegaPresentacionId]);
                        $preciosAbiertos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        if (count($preciosAbiertos) > 1) {
                            throw new DomainException('Hay varios precios abiertos para esta presentación. Se requiere revisar el historial antes de cambiarlo.');
                        }
                        $precioAnterior = $preciosAbiertos[0] ?? null;
                        $normalizadoAnterior = $precioAnterior === null
                            ? null
                            : decimalNormalizado((string) $precioAnterior['precio_venta'], 4);
                        $tarifaAnterior = $precioAnterior === null || $precioAnterior['tarifa_iva'] === null
                            ? null
                            : decimalNormalizado((string) $precioAnterior['tarifa_iva'], 2);
                        if ($precioAnterior !== null
                            && $normalizadoAnterior === $precioNormalizado
                            && (int) $precioAnterior['precio_incluye_iva'] === (int) $incluyeTexto
                            && $tarifaAnterior === $tarifaNormalizada) {
                            throw new DomainException('El precio y su tratamiento tributario no cambiaron.');
                        }

                        $ahora = (string) $pdo->query('SELECT CURRENT_TIMESTAMP AS ahora')->fetchColumn();
                        $fechaCambio = new DateTimeImmutable($ahora, new DateTimeZone('UTC'));
                        if ($precioAnterior !== null) {
                            $fechaAnterior = new DateTimeImmutable(
                                (string) $precioAnterior['vigente_desde'],
                                new DateTimeZone('UTC')
                            );
                            if ($fechaAnterior > $fechaCambio) {
                                throw new DomainException('El precio vigente inicia en el futuro; no se reemplazó.');
                            }
                            $fechaMinima = $fechaAnterior->modify('+1 second');
                            if ($fechaCambio < $fechaMinima) {
                                $fechaCambio = $fechaMinima;
                            }
                            $stmt = $pdo->prepare(
                                'UPDATE precios_bodega
                                 SET vigente_hasta = :fin
                                 WHERE precio_bodega_id = :id AND vigente_hasta IS NULL'
                            );
                            $stmt->execute([
                                'fin' => $fechaCambio->format('Y-m-d H:i:s'),
                                'id' => $precioAnterior['precio_bodega_id'],
                            ]);
                            if ($stmt->rowCount() !== 1) {
                                throw new RuntimeException('El precio cambió simultáneamente; vuelve a intentarlo.');
                            }
                        }

                        $stmt = $pdo->prepare(
                            'INSERT INTO precios_bodega
                                (bodega_presentacion_id, precio_venta, precio_incluye_iva,
                                 tarifa_iva, vigente_desde, usuario_id)
                             VALUES (:relacion, :precio, :incluye, :tarifa, :desde, :usuario)'
                        );
                        $stmt->execute([
                            'relacion' => $bodegaPresentacionId,
                            'precio' => $precioNormalizado,
                            'incluye' => (int) $incluyeTexto,
                            'tarifa' => $tarifaNormalizada,
                            'desde' => $fechaCambio->format('Y-m-d H:i:s'),
                            'usuario' => $usuarioId,
                        ]);
                        $nuevoPrecioId = (int) $pdo->lastInsertId();
                        $stmt = $pdo->prepare(
                            'SELECT precio_bodega_id, precio_venta, precio_incluye_iva,
                                    tarifa_iva, vigente_desde, vigente_hasta
                             FROM precios_bodega WHERE precio_bodega_id = :id'
                        );
                        $stmt->execute(['id' => $nuevoPrecioId]);
                        $precioPosterior = $stmt->fetch(PDO::FETCH_ASSOC);
                        corozao_registrar_auditoria(
                            $pdo,
                            $usuarioId,
                            $precioAnterior === null ? 'CREAR' : 'MODIFICAR',
                            'precios_bodega',
                            $nuevoPrecioId,
                            snapshotPrecio($precioAnterior),
                            snapshotPrecio($precioPosterior)
                        );
                        $texto = $tarifaNormalizada === null
                            ? 'Precio guardado. La tarifa de IVA queda pendiente y no se tratará como cero.'
                            : 'Precio guardado con su tratamiento de IVA.';
                    }

                    $pdo->commit();
                    $_SESSION['comercial_bodegas_flash'] = $texto;
                    header('Location: /comercial_bodegas.php?' . http_build_query([
                        'bodega' => $bodegaActivaId, 'q' => $buscar, 'categoria' => $categoriaId ?? '',
                        'estado' => $estado, 'pagina' => $pagina,
                    ]), true, 303);
                    exit;
                } catch (DomainException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Corozao: error al actualizar catálogo comercial por bodega.');
                    http_response_code(500);
                    $error = 'No se pudo guardar el cambio. No se conservaron cambios parciales.';
                }
            }
        }
    }
}

if (isset($_SESSION['comercial_bodegas_flash']) && is_string($_SESSION['comercial_bodegas_flash'])) {
    $flash = $_SESSION['comercial_bodegas_flash'];
    unset($_SESSION['comercial_bodegas_flash']);
}

$porPagina = 50;
$total = 0;
$filas = [];
$categorias = $pdo->query('SELECT categoria_id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);

if ($bodegaActivaId !== null) {
    $where = [];
    $params = ['bodega' => $bodegaActivaId];
    if ($buscar !== '') {
        $where[] = '(p.nombre LIKE :nombre OR p.marca LIKE :marca OR EXISTS (
            SELECT 1 FROM producto_codigos pc
            INNER JOIN presentaciones prc ON prc.presentacion_id = pc.presentacion_id
            WHERE prc.producto_id = p.producto_id AND pc.codigo LIKE :codigo
        ))';
        $termino = '%' . $buscar . '%';
        $params['nombre'] = $termino;
        $params['marca'] = $termino;
        $params['codigo'] = $termino;
    }
    if ($categoriaId !== null) {
        $where[] = 'p.categoria_id = :categoria';
        $params['categoria'] = $categoriaId;
    }
    if ($estado === 'HABILITADAS') {
        $where[] = 'bp.activo = 1';
    } elseif ($estado === 'DESHABILITADAS') {
        $where[] = '(bp.bodega_presentacion_id IS NULL OR bp.activo = 0)';
    } elseif ($estado === 'SIN_PRECIO') {
        $where[] = 'bp.activo = 1 AND NOT EXISTS (
            SELECT 1 FROM precios_bodega pbf
            WHERE pbf.bodega_presentacion_id = bp.bodega_presentacion_id
              AND pbf.vigente_hasta IS NULL
        )';
    } elseif ($estado === 'IVA_PENDIENTE') {
        $where[] = 'bp.activo = 1 AND EXISTS (
            SELECT 1 FROM precios_bodega pbi
            WHERE pbi.bodega_presentacion_id = bp.bodega_presentacion_id
              AND pbi.vigente_hasta IS NULL AND pbi.tarifa_iva IS NULL
        )';
    }
    $whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM presentaciones pr
         INNER JOIN productos p ON p.producto_id = pr.producto_id
         LEFT JOIN bodega_presentaciones bp
           ON bp.presentacion_id = pr.presentacion_id AND bp.bodega_id = :bodega
         WHERE 1=1' . $whereSql
    );
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $paginas);
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $pdo->prepare(
        'SELECT p.producto_id, p.nombre AS producto_nombre, p.marca, p.contenido,
                p.estado_comercial, p.venta_habilitada, p.activo AS producto_activo,
                c.nombre AS categoria_nombre,
                pr.presentacion_id, pr.nombre AS presentacion_nombre,
                pr.unidades_base, pr.activo AS presentacion_activa,
                bp.bodega_presentacion_id, bp.activo AS bodega_activa,
                (SELECT COUNT(*) FROM precios_bodega pbc
                 WHERE pbc.bodega_presentacion_id = bp.bodega_presentacion_id
                   AND pbc.vigente_hasta IS NULL) AS precios_abiertos,
                pb.precio_bodega_id, pb.precio_venta, pb.precio_incluye_iva,
                pb.tarifa_iva, pb.vigente_desde
         FROM presentaciones pr
         INNER JOIN productos p ON p.producto_id = pr.producto_id
         LEFT JOIN categorias c ON c.categoria_id = p.categoria_id
         LEFT JOIN bodega_presentaciones bp
           ON bp.presentacion_id = pr.presentacion_id AND bp.bodega_id = :bodega
         LEFT JOIN precios_bodega pb ON pb.precio_bodega_id = (
             SELECT pbl.precio_bodega_id FROM precios_bodega pbl
             WHERE pbl.bodega_presentacion_id = bp.bodega_presentacion_id
               AND pbl.vigente_hasta IS NULL
             ORDER BY pbl.vigente_desde DESC, pbl.precio_bodega_id DESC LIMIT 1
         )
         WHERE 1=1' . $whereSql . '
         ORDER BY p.nombre, p.producto_id, pr.nombre, pr.presentacion_id
         LIMIT ' . $porPagina . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$historialPresentacion = null;
$historial = [];
$historialRaw = $_GET['historial'] ?? '';
$historialId = is_string($historialRaw) ? filter_var($historialRaw, FILTER_VALIDATE_INT) : false;
if ($bodegaActivaId !== null && $historialId !== false && $historialId !== null && $historialId > 0) {
    $stmt = $pdo->prepare(
        'SELECT bp.bodega_presentacion_id, pr.nombre AS presentacion_nombre,
                p.nombre AS producto_nombre
         FROM bodega_presentaciones bp
         INNER JOIN presentaciones pr ON pr.presentacion_id = bp.presentacion_id
         INNER JOIN productos p ON p.producto_id = pr.producto_id
         WHERE bp.bodega_id = :bodega AND bp.presentacion_id = :presentacion LIMIT 1'
    );
    $stmt->execute(['bodega' => $bodegaActivaId, 'presentacion' => (int) $historialId]);
    $historialPresentacion = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($historialPresentacion) {
        $stmt = $pdo->prepare(
            'SELECT pb.precio_bodega_id, pb.precio_venta, pb.precio_incluye_iva,
                    pb.tarifa_iva, pb.vigente_desde, pb.vigente_hasta,
                    pb.fecha_creacion, u.nombre_completo AS usuario_nombre
             FROM precios_bodega pb
             INNER JOIN usuarios u ON u.usuario_id = pb.usuario_id
             WHERE pb.bodega_presentacion_id = :relacion
             ORDER BY pb.vigente_desde DESC, pb.precio_bodega_id DESC LIMIT 100'
        );
        $stmt->execute(['relacion' => $historialPresentacion['bodega_presentacion_id']]);
        $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestión comercial por bodega | Corozao</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="/assets/img/brand/corozao-mark.svg?v=2">
    <link rel="stylesheet" href="/assets/css/brand.css">
</head>
<body data-corozao-role="<?= htmlspecialchars((string)($_SESSION['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bg-body-tertiary">
<header class="navbar navbar-dark shadow-sm" style="background-color: #173b65">
    <div class="container-xxl">
        <a class="navbar-brand fw-semibold" href="/">Corozao</a>
        <span class="navbar-text text-white">Gestión comercial por bodega</span>
    </div>
</header>
<main class="container-xxl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h2 mb-1">Catálogo comercial por bodega</h1>
            <p class="text-body-secondary mb-0">Habilitación de presentaciones y precios independientes por sucursal.</p>
        </div>
        <a class="btn btn-outline-secondary" href="/">Volver al panel</a>
    </div>

    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($flash !== null): ?><div class="alert alert-success" role="status"><?= h($flash) ?></div><?php endif; ?>
    <?php if (!$bodegas): ?>
        <div class="alert alert-warning" role="alert">No tienes bodegas activas asignadas. Solicita una asignación al administrador.</div>
    <?php else: ?>
        <section class="card shadow-sm mb-4">
            <div class="card-body p-3 p-md-4">
                <form method="get" action="/comercial_bodegas.php" class="row g-3 align-items-end">
                    <div class="col-12 col-lg-4">
                        <label class="form-label" for="bodega">Bodega</label>
                        <select class="form-select" id="bodega" name="bodega" required>
                            <option value="">Selecciona</option>
                            <?php foreach ($bodegas as $opcionBodega): ?>
                                <option value="<?= h($opcionBodega['bodega_id']) ?>" <?= $bodegaActivaId === (int) $opcionBodega['bodega_id'] ? 'selected' : '' ?>>
                                    <?= h($opcionBodega['nombre']) ?> · <?= h($opcionBodega['tipo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($bodegaActivaId !== null): ?>
                        <div class="col-12 col-lg-8">
                            <p class="mb-0 text-body-secondary">Los cambios afectan solo a <strong><?= h($bodegaActual['nombre'] ?? '') ?></strong>. La habilitación global del producto y el historial se conservan por separado.</p>
                        </div>
                    <?php else: ?>
                        <div class="col-12 col-lg-8"><button class="btn btn-primary" type="submit">Abrir bodega</button></div>
                    <?php endif; ?>
                    <?php if ($bodegaActivaId !== null): ?>
                        <input type="hidden" name="q" value="<?= h($buscar) ?>">
                        <input type="hidden" name="categoria" value="<?= h($categoriaId ?? '') ?>">
                        <input type="hidden" name="estado" value="<?= h($estado) ?>">
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <?php if ($bodegaActivaId !== null): ?>
            <section class="card shadow-sm mb-4">
                <div class="card-body p-3 p-md-4">
                    <form method="get" action="/comercial_bodegas.php" class="row g-3 align-items-end">
                        <input type="hidden" name="bodega" value="<?= h($bodegaActivaId) ?>">
                        <div class="col-12 col-md-6 col-xl-5">
                            <label class="form-label" for="q">Nombre, marca o código</label>
                            <input class="form-control" id="q" name="q" maxlength="100" value="<?= h($buscar) ?>">
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="form-label" for="categoria">Categoría</label>
                            <select class="form-select" id="categoria" name="categoria">
                                <option value="">Todas</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= h($categoria['categoria_id']) ?>" <?= $categoriaId === (int) $categoria['categoria_id'] ? 'selected' : '' ?>><?= h($categoria['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="form-label" for="estado">Estado en bodega</label>
                            <select class="form-select" id="estado" name="estado">
                                <option value="TODOS" <?= $estado === 'TODOS' ? 'selected' : '' ?>>Todos</option>
                                <option value="HABILITADAS" <?= $estado === 'HABILITADAS' ? 'selected' : '' ?>>Habilitadas</option>
                                <option value="DESHABILITADAS" <?= $estado === 'DESHABILITADAS' ? 'selected' : '' ?>>Deshabilitadas</option>
                                <option value="SIN_PRECIO" <?= $estado === 'SIN_PRECIO' ? 'selected' : '' ?>>Sin precio vigente</option>
                                <option value="IVA_PENDIENTE" <?= $estado === 'IVA_PENDIENTE' ? 'selected' : '' ?>>IVA pendiente</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-xl-2"><button class="btn btn-primary w-100" type="submit">Buscar</button></div>
                    </form>
                </div>
            </section>

            <section class="card shadow-sm mb-4">
                <div class="card-body p-3 p-md-4">
                    <p><strong><?= h($total) ?></strong> presentación(es) · hasta <?= h($porPagina) ?> por página</p>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle">
                            <thead><tr><th>Producto / presentación</th><th>Estado global</th><th>Estado en bodega</th><th>Precio de bodega</th><th>Acciones</th></tr></thead>
                            <tbody>
                            <?php foreach ($filas as $fila): ?>
                                <?php
                                $globalElegible = (int) $fila['producto_activo'] === 1
                                    && (int) $fila['presentacion_activa'] === 1
                                    && $fila['estado_comercial'] === 'VALIDADO'
                                    && (int) $fila['venta_habilitada'] === 1;
                                $bodegaHabilitada = (int) ($fila['bodega_activa'] ?? 0) === 1;
                                $precioAbiertoDuplicado = (int) $fila['precios_abiertos'] > 1;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= h($fila['producto_nombre']) ?></strong><?= $fila['marca'] ? ' · ' . h($fila['marca']) : '' ?><br>
                                        <small class="text-body-secondary">Contenido: <?= h($fila['contenido'] ?: 'Sin especificar') ?></small><br>
                                        <span><?= h($fila['presentacion_nombre']) ?> · <?= h(unidadesVisual((string) $fila['unidades_base'])) ?> unidades base</span><br>
                                        <small class="text-body-secondary"><?= h($fila['categoria_nombre'] ?? 'Sin categoría') ?> · Producto #<?= h($fila['producto_id']) ?></small>
                                    </td>
                                    <td>
                                        <?= (int) $fila['producto_activo'] === 1 ? 'Producto activo' : 'Producto inactivo' ?><br>
                                        <?= h($fila['estado_comercial']) ?> · <?= (int) $fila['venta_habilitada'] === 1 ? 'Venta global habilitada' : 'Venta global deshabilitada' ?><br>
                                        <?= (int) $fila['presentacion_activa'] === 1 ? 'Presentación activa' : 'Presentación inactiva' ?>
                                    </td>
                                    <td><span class="badge text-bg-<?= $bodegaHabilitada ? 'success' : 'secondary' ?>"><?= $bodegaHabilitada ? 'Habilitada' : 'Deshabilitada' ?></span></td>
                                    <td>
                                        <?php if ($precioAbiertoDuplicado): ?>
                                            <span class="text-danger">Varios precios sin fecha de cierre; requiere revisión</span>
                                        <?php elseif ($fila['precio_bodega_id'] === null): ?>
                                            <span class="text-body-secondary">Sin precio vigente</span>
                                        <?php else: ?>
                                            <strong>$<?= h(decimalVisual((string) $fila['precio_venta'], 2)) ?></strong><br>
                                            <?php if ($fila['tarifa_iva'] === null): ?>
                                                <span class="badge text-bg-warning">IVA pendiente</span>
                                            <?php else: ?>
                                                <small>IVA <?= h(decimalVisual((string) $fila['tarifa_iva'], 2)) ?>%</small>
                                            <?php endif; ?><br>
                                            <small><?= (int) $fila['precio_incluye_iva'] === 1 ? 'Incluye IVA' : 'No incluye IVA' ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-2">
                                            <?php if ($puedeAdministrar): ?>
                                                <form method="post" action="/comercial_bodegas.php?<?= h(http_build_query(['bodega' => $bodegaActivaId, 'q' => $buscar, 'categoria' => $categoriaId ?? '', 'estado' => $estado, 'pagina' => $pagina])) ?>" onsubmit="return confirm('¿Cambiar la habilitación solo en esta bodega?');">
                                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                    <input type="hidden" name="bodega_id" value="<?= h($bodegaActivaId) ?>">
                                                    <input type="hidden" name="presentacion_id" value="<?= h($fila['presentacion_id']) ?>">
                                                    <?php if ($bodegaHabilitada): ?>
                                                        <input type="hidden" name="accion" value="desactivar">
                                                        <button class="btn btn-sm btn-outline-danger" type="submit">Desactivar aquí</button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="accion" value="activar">
                                                        <button class="btn btn-sm btn-outline-success" type="submit" <?= !$globalElegible ? 'disabled title="Requiere estado global activo y validado"' : '' ?>>Activar aquí</button>
                                                    <?php endif; ?>
                                                </form>
                                                <?php if ($bodegaHabilitada): ?>
                                                    <?php if ($precioAbiertoDuplicado): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" type="button" disabled>Resolver precios duplicados</button>
                                                    <?php else: ?>
                                                        <form method="post" action="/comercial_bodegas.php?<?= h(http_build_query(['bodega' => $bodegaActivaId, 'q' => $buscar, 'categoria' => $categoriaId ?? '', 'estado' => $estado, 'pagina' => $pagina])) ?>" onsubmit="return confirm('Se cerrará la vigencia del precio actual y se creará un nuevo registro histórico. ¿Continuar?');">
                                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                            <input type="hidden" name="accion" value="precio">
                                                            <input type="hidden" name="bodega_id" value="<?= h($bodegaActivaId) ?>">
                                                            <input type="hidden" name="presentacion_id" value="<?= h($fila['presentacion_id']) ?>">
                                                            <label class="form-label small mb-1" for="precio-<?= h($fila['presentacion_id']) ?>">Nuevo precio</label>
                                                            <input class="form-control form-control-sm mb-2" id="precio-<?= h($fila['presentacion_id']) ?>" name="precio_venta" type="number" min="0.01" max="99999999.99" step="0.01" required>
                                                            <label class="form-label small mb-1" for="incluye-<?= h($fila['presentacion_id']) ?>">Tratamiento IVA</label>
                                                            <select class="form-select form-select-sm mb-2" id="incluye-<?= h($fila['presentacion_id']) ?>" name="precio_incluye_iva" required>
                                                                <option value="">Selecciona</option>
                                                                <option value="1">Precio incluye IVA</option>
                                                                <option value="0">Precio no incluye IVA</option>
                                                            </select>
                                                            <label class="form-label small mb-1" for="iva-<?= h($fila['presentacion_id']) ?>">Tarifa IVA (%)</label>
                                                            <input class="form-control form-control-sm mb-1" id="iva-<?= h($fila['presentacion_id']) ?>" name="tarifa_iva" type="number" min="0" max="999.99" step="0.01" placeholder="Vacío = pendiente">
                                                            <small class="form-text d-block mb-2">Déjala vacía solo si la tarifa es desconocida; no se convertirá en cero.</small>
                                                            <button class="btn btn-sm btn-primary w-100" type="submit">Guardar nuevo precio</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($fila['bodega_presentacion_id'] !== null): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="/comercial_bodegas.php?<?= h(http_build_query(['bodega' => $bodegaActivaId, 'historial' => $fila['presentacion_id']])) ?>">Historial de precios</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$filas): ?><tr><td colspan="5" class="text-body-secondary">No hay presentaciones para estos filtros.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php $queryPagina = ['bodega' => $bodegaActivaId, 'q' => $buscar, 'categoria' => $categoriaId, 'estado' => $estado]; ?>
                    <?php if ($paginas > 1): ?>
                        <nav class="d-flex flex-wrap align-items-center gap-3" aria-label="Paginación">
                            <?php if ($pagina > 1): ?><a class="btn btn-sm btn-outline-primary" href="?<?= h(http_build_query($queryPagina + ['pagina' => $pagina - 1])) ?>">← Anterior</a><?php endif; ?>
                            <span>Página <?= h($pagina) ?> de <?= h($paginas) ?></span>
                            <?php if ($pagina < $paginas): ?><a class="btn btn-sm btn-outline-primary" href="?<?= h(http_build_query($queryPagina + ['pagina' => $pagina + 1])) ?>">Siguiente →</a><?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($historialPresentacion): ?>
                <section class="card shadow-sm">
                    <div class="card-body p-3 p-md-4">
                        <h2 class="h5">Historial: <?= h($historialPresentacion['producto_nombre']) ?> · <?= h($historialPresentacion['presentacion_nombre']) ?></h2>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Precio</th><th>IVA</th><th>Vigencia</th><th>Usuario</th></tr></thead>
                                <tbody>
                                <?php foreach ($historial as $registro): ?>
                                    <tr>
                                        <td>$<?= h(decimalVisual((string) $registro['precio_venta'], 2)) ?></td>
                                        <td>
                                            <?php if ($registro['tarifa_iva'] === null): ?>IVA pendiente<?php else: ?><?= h(decimalVisual((string) $registro['tarifa_iva'], 2)) ?>%<?php endif; ?>
                                            · <?= (int) $registro['precio_incluye_iva'] === 1 ? 'incluye IVA' : 'sin IVA' ?>
                                        </td>
                                        <td><?= h($registro['vigente_desde']) ?> – <?= $registro['vigente_hasta'] === null ? 'Vigente' : h($registro['vigente_hasta']) ?></td>
                                        <td><?= h($registro['usuario_nombre']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$historial): ?><tr><td colspan="4" class="text-body-secondary">No hay cambios de precio registrados.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script src="/assets/brand.js" defer></script>
</body>
</html>
