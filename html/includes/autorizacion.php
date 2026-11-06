<?php
declare(strict_types=1);

/** Permisos por operación. Lo que no aparece aquí queda denegado. */
function corozao_permisos(): array
{
    return [
        'catalog.products.view' => ['ADMINISTRADOR', 'CAJERO', 'MESERO'],
        'catalog.products.manage' => ['ADMINISTRADOR'],
        'catalog.categories.manage' => ['ADMINISTRADOR'],
        'catalog.presentations.manage' => ['ADMINISTRADOR'],
        'catalog.codes.manage' => ['ADMINISTRADOR'],
        'suppliers.view' => ['ADMINISTRADOR'],
        'suppliers.create' => ['ADMINISTRADOR'],
        'suppliers.update' => ['ADMINISTRADOR'],
        'suppliers.deactivate' => ['ADMINISTRADOR'],
        'suppliers.link_products' => ['ADMINISTRADOR'],
        'purchases.view' => ['ADMINISTRADOR'],
        'purchases.create' => ['ADMINISTRADOR'],
        'purchases.update_draft' => ['ADMINISTRADOR'],
        'purchases.confirm' => ['ADMINISTRADOR'],
        'purchases.reverse' => ['ADMINISTRADOR'],
        'warehouses.manage' => ['ADMINISTRADOR'],
        'inventory.stock.view' => ['ADMINISTRADOR'],
        'inventory.kardex.view' => ['ADMINISTRADOR'],
        'inventory.transfer.create' => ['ADMINISTRADOR'],
        'inventory.transfer.review' => ['ADMINISTRADOR'],
        'inventory.transfer.confirm' => ['ADMINISTRADOR'],
        'inventory.transfer.receive' => ['ADMINISTRADOR'],
        'inventory.adjustment.create' => ['ADMINISTRADOR'],
        'inventory.adjustment.confirm' => ['ADMINISTRADOR'],
        'inventory.adjustment.view' => ['ADMINISTRADOR'],
        'inventory.availability.view' => ['ADMINISTRADOR', 'CAJERO'],
        'commercial.prices.view' => ['ADMINISTRADOR', 'CAJERO', 'MESERO'],
        'commercial.prices.manage' => ['ADMINISTRADOR'],
        'commercial.presentations.manage' => ['ADMINISTRADOR'],
        'orders.catalog.view' => ['ADMINISTRADOR', 'MESERO'],
        'orders.open.create' => ['ADMINISTRADOR', 'MESERO'],
        'orders.open.modify_own' => ['ADMINISTRADOR', 'MESERO'],
        'orders.send' => ['ADMINISTRADOR', 'MESERO'],
        'orders.finalization.request' => ['ADMINISTRADOR', 'MESERO'],
        'orders.own.view' => ['ADMINISTRADOR', 'MESERO'],
        'orders.pending_billing.view' => ['ADMINISTRADOR', 'CAJERO'],
        'orders.convert_to_sale' => ['ADMINISTRADOR', 'CAJERO'],
        'sales.direct.create' => ['ADMINISTRADOR', 'CAJERO'],
        'sales.view_own' => ['ADMINISTRADOR', 'CAJERO'],
        'sales.create' => ['ADMINISTRADOR', 'CAJERO'],
        'sales.record_payment' => ['ADMINISTRADOR', 'CAJERO'],
        'sales.invoice' => ['ADMINISTRADOR', 'CAJERO'],
        'sales.return.request' => ['ADMINISTRADOR', 'CAJERO'],
        'sales.return.approve' => ['ADMINISTRADOR'],
        'cash.open_own' => ['ADMINISTRADOR', 'CAJERO'],
        'cash.close_own' => ['ADMINISTRADOR', 'CAJERO'],
        'cash.differences.record' => ['ADMINISTRADOR', 'CAJERO'],
        'cash.movements.manage' => ['ADMINISTRADOR'],
        'cash.differences.review' => ['ADMINISTRADOR'],
        'inventory.physical_count.create' => ['ADMINISTRADOR'],
        'inventory.physical_count.close' => ['ADMINISTRADOR'],
        'inventory.physical_count.modify' => ['ADMINISTRADOR'],
        'inventory.physical_count.remove' => ['ADMINISTRADOR'],
        'inventory.initial_load.create' => ['ADMINISTRADOR'],
        'reports.view' => ['ADMINISTRADOR'],
        'users.manage' => ['ADMINISTRADOR'],
        'users.warehouses.assign' => ['ADMINISTRADOR'],
        'audit.view' => ['ADMINISTRADOR'],
        // La anulación y edición de ventas confirmadas siguen denegadas por defecto.
        'sales.void' => [],
        'sales.refund' => [],
        'sales.confirmed.modify' => [],
        'inventory.confirmed.delete' => [],
        'inventory.history.modify' => [],
    ];
}

function corozao_rol_puede(string $rol, string $permiso): bool
{
    $permisos = corozao_permisos();
    return in_array($rol, $permisos[$permiso] ?? [], true);
}

/** Confirma en cada solicitud que la cuenta siga activa y usa su rol vigente. */
function corozao_usuario_sesion(PDO $pdo): ?array
{
    $usuarioId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$usuarioId || $usuarioId < 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT usuario_id, nombre_completo, nombre_usuario, rol, activo
         FROM usuarios WHERE usuario_id = :usuario LIMIT 1'
    );
    $stmt->execute(['usuario' => $usuarioId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    $roles = ['ADMINISTRADOR', 'CAJERO', 'MESERO'];
    if (!$usuario || (int) $usuario['activo'] !== 1 || !in_array($usuario['rol'], $roles, true)) {
        return null;
    }

    // Actualiza el rol en sesión si se modificó desde la administración de usuarios.
    $_SESSION['rol'] = $usuario['rol'];
    $_SESSION['nombre_completo'] = $usuario['nombre_completo'];
    $_SESSION['nombre_usuario'] = $usuario['nombre_usuario'];
    return $usuario;
}

function corozao_exigir_permiso(PDO $pdo, string $permiso, bool $redirigir = false): array
{
    try {
        $usuario = corozao_usuario_sesion($pdo);
    } catch (Throwable $e) {
        error_log('Corozao: no se pudo validar la cuenta y sus permisos.');
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudieron validar los permisos. Inténtalo más tarde.';
        exit;
    }
    if ($usuario === null) {
        if ($redirigir) {
            header('Location: /');
        } else {
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Se requiere una sesión activa.';
        }
        exit;
    }

    if (!corozao_rol_puede((string) $usuario['rol'], $permiso)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No tienes permiso para realizar esta operación.';
        exit;
    }
    return $usuario;
}

/** Devuelve bodegas activas visibles para el usuario según su asignación vigente. */
function corozao_bodegas_asignadas(PDO $pdo, array $usuario): array
{
    if (($usuario['rol'] ?? '') === 'ADMINISTRADOR') {
        return $pdo->query(
            'SELECT bodega_id, nombre, tipo FROM bodegas WHERE activo = 1 ORDER BY nombre, bodega_id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!in_array($usuario['rol'] ?? '', ['CAJERO', 'MESERO'], true)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT b.bodega_id, b.nombre, b.tipo
             FROM usuario_bodegas ub
             INNER JOIN bodegas b ON b.bodega_id = ub.bodega_id
             WHERE ub.usuario_id = :usuario AND ub.activo = 1 AND b.activo = 1
             ORDER BY b.nombre, b.bodega_id'
        );
        $stmt->execute(['usuario' => (int) $usuario['usuario_id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Si la migración aún no está instalada, no se concede acceso por bodega.
        return [];
    }
}

/** Falla de forma cerrada si la bodega no está asignada o dejó de estar activa. */
function corozao_exigir_bodega(PDO $pdo, array $usuario, mixed $bodegaId): int
{
    $id = filter_var($bodegaId, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        http_response_code(403);
        exit('Bodega no autorizada.');
    }

    foreach (corozao_bodegas_asignadas($pdo, $usuario) as $bodega) {
        if ((int) $bodega['bodega_id'] === $id) {
            return $id;
        }
    }

    http_response_code(403);
    exit('Bodega no autorizada.');
}

/** Comprobación combinada para endpoints que consultan o cambian datos de una bodega. */
function corozao_exigir_permiso_en_bodega(PDO $pdo, string $permiso, mixed $bodegaId): array
{
    $usuario = corozao_exigir_permiso($pdo, $permiso);
    $bodegaAutorizadaId = corozao_exigir_bodega($pdo, $usuario, $bodegaId);
    return ['usuario' => $usuario, 'bodega_id' => $bodegaAutorizadaId];
}

function corozao_registrar_auditoria(
    PDO $pdo,
    int $usuarioId,
    string $accion,
    string $entidad,
    int $registroId,
    ?array $anteriores,
    ?array $posteriores
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO auditoria_eventos
            (usuario_id, accion, entidad, registro_id, valores_anteriores, valores_posteriores)
         VALUES (:usuario, :accion, :entidad, :registro, :anteriores, :posteriores)'
    );
    $antes = $anteriores === null
        ? null
        : json_encode($anteriores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $despues = $posteriores === null
        ? null
        : json_encode($posteriores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt->bindValue(':usuario', $usuarioId, PDO::PARAM_INT);
    $stmt->bindValue(':accion', $accion, PDO::PARAM_STR);
    $stmt->bindValue(':entidad', $entidad, PDO::PARAM_STR);
    $stmt->bindValue(':registro', $registroId, PDO::PARAM_INT);
    $stmt->bindValue(':anteriores', $antes, $antes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':posteriores', $despues, $despues === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->execute();
}

function corozao_csrf_valido(string $token): bool
{
    return isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
