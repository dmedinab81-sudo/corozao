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
$administrador = corozao_exigir_permiso($pdo, 'users.warehouses.assign');
$usuarioAdminId = (int) $administrador['usuario_id'];

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$error = null;
$mensaje = null;
$usuarioSeleccionado = '';
$bodegaSeleccionada = '';

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla'
);
$stmt->execute(['tabla' => 'usuario_bodegas']);
$tablaDisponible = (int) $stmt->fetchColumn() === 1;
$stmt->execute(['tabla' => 'auditoria_eventos']);
$auditoriaDisponible = (int) $stmt->fetchColumn() === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $accion = $_POST['accion'] ?? '';
    $usuarioSeleccionado = is_string($_POST['usuario_id'] ?? null) ? $_POST['usuario_id'] : '';
    $bodegaSeleccionada = is_string($_POST['bodega_id'] ?? null) ? $_POST['bodega_id'] : '';

    if (!is_string($token) || !corozao_csrf_valido($token)) {
        http_response_code(403);
        $error = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
    } elseif (!$tablaDisponible || !$auditoriaDisponible) {
        http_response_code(503);
        $error = 'La asignación requiere las tablas de relación y auditoría. No se guardaron cambios.';
    } elseif (!is_string($accion) || !in_array($accion, ['asignar', 'desactivar'], true)) {
        http_response_code(400);
        $error = 'Acción no válida.';
    } else {
        $usuarioId = filter_var($usuarioSeleccionado, FILTER_VALIDATE_INT);
        $bodegaId = filter_var($bodegaSeleccionada, FILTER_VALIDATE_INT);
        if (!$usuarioId || $usuarioId < 1 || !$bodegaId || $bodegaId < 1) {
            $error = 'Selecciona un usuario y una bodega válidos.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'SELECT usuario_id, nombre_completo, rol
                     FROM usuarios
                     WHERE usuario_id = :usuario AND activo = 1
                       AND rol IN (\'CAJERO\', \'MESERO\')
                     FOR UPDATE'
                );
                $stmt->execute(['usuario' => $usuarioId]);
                $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$usuario) {
                    throw new DomainException('Solo se pueden asignar cuentas activas de cajero o mesero.');
                }

                $stmt = $pdo->prepare(
                    'SELECT bodega_id, nombre FROM bodegas
                     WHERE bodega_id = :bodega AND activo = 1 FOR UPDATE'
                );
                $stmt->execute(['bodega' => $bodegaId]);
                $bodega = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$bodega) {
                    throw new DomainException('La bodega no existe o está inactiva.');
                }

                $stmt = $pdo->prepare(
                    'SELECT usuario_bodega_id, usuario_id, bodega_id, activo,
                            asignado_por_usuario_id, actualizado_por_usuario_id
                     FROM usuario_bodegas
                     WHERE usuario_id = :usuario AND bodega_id = :bodega
                     FOR UPDATE'
                );
                $stmt->execute(['usuario' => $usuarioId, 'bodega' => $bodegaId]);
                $anterior = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($accion === 'asignar') {
                    if ($anterior && (int) $anterior['activo'] === 1) {
                        throw new DomainException('Esta bodega ya está asignada a ese usuario.');
                    }

                    if ($anterior) {
                        $stmt = $pdo->prepare(
                            'UPDATE usuario_bodegas
                             SET activo = 1, actualizado_por_usuario_id = :admin
                             WHERE usuario_bodega_id = :id'
                        );
                        $stmt->execute(['admin' => $usuarioAdminId, 'id' => $anterior['usuario_bodega_id']]);
                        $registroId = (int) $anterior['usuario_bodega_id'];
                        $accionAuditoria = 'MODIFICAR';
                        $valoresAnteriores = [
                            'usuario_id' => $usuarioId,
                            'bodega_id' => $bodegaId,
                            'activo' => (int) $anterior['activo'],
                        ];
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO usuario_bodegas
                                (usuario_id, bodega_id, asignado_por_usuario_id)
                             VALUES (:usuario, :bodega, :admin)'
                        );
                        $stmt->execute([
                            'usuario' => $usuarioId,
                            'bodega' => $bodegaId,
                            'admin' => $usuarioAdminId,
                        ]);
                        $registroId = (int) $pdo->lastInsertId();
                        $accionAuditoria = 'CREAR';
                        $valoresAnteriores = null;
                    }
                    $valoresPosteriores = [
                        'usuario_id' => $usuarioId,
                        'rol' => $usuario['rol'],
                        'bodega_id' => $bodegaId,
                        'bodega' => $bodega['nombre'],
                        'activo' => 1,
                    ];
                    corozao_registrar_auditoria(
                        $pdo,
                        $usuarioAdminId,
                        $accionAuditoria,
                        'usuario_bodegas',
                        $registroId,
                        $valoresAnteriores,
                        $valoresPosteriores
                    );
                    $mensaje = 'Asignación guardada.';
                } else {
                    if (!$anterior || (int) $anterior['activo'] !== 1) {
                        throw new DomainException('La asignación ya está inactiva o no existe.');
                    }
                    $stmt = $pdo->prepare(
                        'UPDATE usuario_bodegas
                         SET activo = 0, actualizado_por_usuario_id = :admin
                         WHERE usuario_bodega_id = :id'
                    );
                    $stmt->execute(['admin' => $usuarioAdminId, 'id' => $anterior['usuario_bodega_id']]);
                    $registroId = (int) $anterior['usuario_bodega_id'];
                    corozao_registrar_auditoria(
                        $pdo,
                        $usuarioAdminId,
                        'DESACTIVAR',
                        'usuario_bodegas',
                        $registroId,
                        ['usuario_id' => $usuarioId, 'bodega_id' => $bodegaId, 'activo' => 1],
                        ['usuario_id' => $usuarioId, 'bodega_id' => $bodegaId, 'activo' => 0]
                    );
                    $mensaje = 'Asignación desactivada.';
                }

                $pdo->commit();
                $_SESSION['asignaciones_bodega_flash'] = $mensaje;
                header('Location: /asignaciones_bodega.php', true, 303);
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
                error_log('Corozao: error al guardar asignación de bodega.');
                http_response_code(500);
                $error = 'No se pudo guardar la asignación. No se conservaron cambios parciales.';
            }
        }
    }
}

if (isset($_SESSION['asignaciones_bodega_flash']) && is_string($_SESSION['asignaciones_bodega_flash'])) {
    $mensaje = $_SESSION['asignaciones_bodega_flash'];
    unset($_SESSION['asignaciones_bodega_flash']);
}

$usuarios = $pdo->query(
    "SELECT usuario_id, nombre_completo, nombre_usuario, rol
     FROM usuarios WHERE activo = 1 AND rol IN ('CAJERO', 'MESERO')
     ORDER BY rol, nombre_completo, usuario_id"
)->fetchAll(PDO::FETCH_ASSOC);
$bodegas = $pdo->query(
    'SELECT bodega_id, nombre, tipo FROM bodegas WHERE activo = 1 ORDER BY nombre, bodega_id'
)->fetchAll(PDO::FETCH_ASSOC);
$asignaciones = [];
if ($tablaDisponible) {
    $asignaciones = $pdo->query(
        'SELECT ub.usuario_bodega_id, ub.usuario_id, ub.bodega_id, ub.activo,
                ub.fecha_asignacion, ub.fecha_actualizacion,
                u.nombre_completo, u.nombre_usuario, u.rol,
                b.nombre AS bodega_nombre, b.tipo AS bodega_tipo
         FROM usuario_bodegas ub
         INNER JOIN usuarios u ON u.usuario_id = ub.usuario_id
         INNER JOIN bodegas b ON b.bodega_id = ub.bodega_id
         ORDER BY ub.activo DESC, u.nombre_completo, b.nombre, ub.usuario_bodega_id'
    )->fetchAll(PDO::FETCH_ASSOC);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asignaciones por bodega | Corozao</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<header class="navbar navbar-dark shadow-sm" style="background-color: #173b65">
    <div class="container-xxl">
        <a class="navbar-brand fw-semibold" href="/">Corozao</a>
        <span class="navbar-text text-white">Asignaciones por bodega</span>
    </div>
</header>
<main class="container-xxl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h2 mb-1">Asignaciones por bodega</h1>
            <p class="text-body-secondary mb-0">Define las bodegas que puede consultar cada cajero o mesero.</p>
        </div>
        <a class="btn btn-outline-secondary" href="/">Volver al panel</a>
    </div>

    <?php if (!$tablaDisponible): ?>
        <div class="alert alert-warning" role="alert">
            Aún no está instalada la migración incremental de asignaciones. No se asigna ninguna bodega automáticamente.
        </div>
    <?php endif; ?>
    <?php if (!$auditoriaDisponible): ?>
        <div class="alert alert-warning" role="alert">La auditoría no está disponible; los cambios permanecerán bloqueados.</div>
    <?php endif; ?>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($mensaje !== null): ?><div class="alert alert-success" role="status"><?= h($mensaje) ?></div><?php endif; ?>

    <section class="card shadow-sm mb-4">
        <div class="card-body p-3 p-md-4">
            <h2 class="h5">Asignar una bodega</h2>
            <form method="post" action="/asignaciones_bodega.php" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="accion" value="asignar">
                <div class="col-12 col-lg-5">
                    <label class="form-label" for="usuario_id">Usuario operativo</label>
                    <select class="form-select" id="usuario_id" name="usuario_id" required>
                        <option value="">Selecciona un cajero o mesero</option>
                        <?php foreach ($usuarios as $usuario): ?>
                            <option value="<?= h($usuario['usuario_id']) ?>" <?= (string) $usuarioSeleccionado === (string) $usuario['usuario_id'] ? 'selected' : '' ?>>
                                <?= h($usuario['nombre_completo']) ?> · <?= h($usuario['rol']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-5">
                    <label class="form-label" for="bodega_id">Bodega activa</label>
                    <select class="form-select" id="bodega_id" name="bodega_id" required>
                        <option value="">Selecciona una bodega</option>
                        <?php foreach ($bodegas as $bodega): ?>
                            <option value="<?= h($bodega['bodega_id']) ?>" <?= (string) $bodegaSeleccionada === (string) $bodega['bodega_id'] ? 'selected' : '' ?>>
                                <?= h($bodega['nombre']) ?> · <?= h($bodega['tipo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-2">
                    <button class="btn btn-primary w-100" type="submit" <?= !$tablaDisponible || !$auditoriaDisponible || !$usuarios || !$bodegas ? 'disabled' : '' ?>>Asignar</button>
                </div>
            </form>
            <p class="small text-body-secondary mt-3 mb-0">Una asignación inactiva puede reactivarse. Las asignaciones del administrador no limitan su acceso a las bodegas.</p>
        </div>
    </section>

    <section class="card shadow-sm">
        <div class="card-body p-3 p-md-4">
            <h2 class="h5">Asignaciones registradas</h2>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead><tr><th>Usuario</th><th>Rol</th><th>Bodega</th><th>Estado</th><th>Asignada</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($asignaciones as $asignacion): ?>
                        <tr>
                            <td><?= h($asignacion['nombre_completo']) ?><br><small class="text-body-secondary"><?= h($asignacion['nombre_usuario']) ?></small></td>
                            <td><?= h($asignacion['rol']) ?></td>
                            <td><?= h($asignacion['bodega_nombre']) ?> · <?= h($asignacion['bodega_tipo']) ?></td>
                            <td><span class="badge text-bg-<?= (int) $asignacion['activo'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $asignacion['activo'] === 1 ? 'Activa' : 'Inactiva' ?></span></td>
                            <td><?= h($asignacion['fecha_asignacion']) ?></td>
                            <td>
                                <?php if ((int) $asignacion['activo'] === 1): ?>
                                    <form method="post" action="/asignaciones_bodega.php" onsubmit="return confirm('¿Desactivar esta asignación?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="accion" value="desactivar">
                                        <input type="hidden" name="usuario_id" value="<?= h($asignacion['usuario_id']) ?>">
                                        <input type="hidden" name="bodega_id" value="<?= h($asignacion['bodega_id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" <?= !$auditoriaDisponible || !$tablaDisponible ? 'disabled' : '' ?>>Desactivar</button>
                                    </form>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$asignaciones): ?><tr><td colspan="6" class="text-body-secondary">No hay asignaciones registradas.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
</body>
</html>
