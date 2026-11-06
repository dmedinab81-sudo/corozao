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
require_once __DIR__ . '/includes/usuarios.php';
$actor = corozao_exigir_permiso($pdo, 'users.manage', true);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function usuarios_h(mixed $valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function usuarios_id(mixed $valor): int
{
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        throw new InvalidArgumentException('Selecciona un usuario válido.');
    }
    return $id;
}

$error = null;
$mensaje = $_SESSION['flash_usuarios'] ?? null;
unset($_SESSION['flash_usuarios']);
$usuario = null;
$usuarios = [];
$total = 0;
$porPagina = 50;
$pagina = max(1, (int)(filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$paginaMaxima = 1;
$buscarRecibido = $_GET['q'] ?? '';
$buscar = is_string($buscarRecibido) ? trim($buscarRecibido) : '';
$rolFiltro = is_string($_GET['rol'] ?? '') ? $_GET['rol'] : 'TODOS';
$estadoFiltro = is_string($_GET['estado'] ?? '') ? $_GET['estado'] : 'TODOS';
$editarId = filter_var($_GET['editar'] ?? '', FILTER_VALIDATE_INT);
$crear = isset($_GET['crear']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? '';
        if (!is_string($token) || !corozao_csrf_valido($token)) {
            http_response_code(403);
            throw new RuntimeException('La solicitud expiró. Recarga la página e inténtalo nuevamente.');
        }
        corozao_exigir_permiso($pdo, 'users.manage');
        $accion = $_POST['accion'] ?? '';
        if (!is_string($accion)) {
            throw new InvalidArgumentException('La operación solicitada no es válida.');
        }
        if ($accion === 'crear') {
            $id = corozao_usuario_crear($pdo, (int)$actor['usuario_id'], $_POST);
            $_SESSION['flash_usuarios'] = 'Usuario creado correctamente.';
            header('Location: /usuarios.php?editar=' . $id);
            exit;
        }
        $id = usuarios_id($_POST['usuario_id'] ?? null);
        if ($accion === 'actualizar') {
            corozao_usuario_actualizar($pdo, (int)$actor['usuario_id'], $id, $_POST);
            $_SESSION['flash_usuarios'] = 'Datos del usuario actualizados.';
            header('Location: /usuarios.php?editar=' . $id);
            exit;
        }
        if ($accion === 'cambiar_password') {
            corozao_usuario_cambiar_password($pdo, (int)$actor['usuario_id'], $id, $_POST);
            $_SESSION['flash_usuarios'] = 'Contraseña actualizada. La contraseña anterior no se puede recuperar.';
            header('Location: /usuarios.php?editar=' . $id);
            exit;
        }
        throw new InvalidArgumentException('La operación solicitada no está permitida.');
    } catch (Throwable $e) {
        if (http_response_code() < 400) {
            http_response_code(400);
        }
        $error = $e->getMessage();
        if ($e instanceof PDOException) {
            error_log('Corozao usuarios: error de persistencia.');
            $error = $e->getCode() === '23000'
                ? 'Ese nombre de usuario ya está registrado.'
                : 'No se pudo guardar el usuario. Revisa los datos e inténtalo nuevamente.';
        }
        if (($_POST['accion'] ?? '') === 'crear') {
            $crear = true;
        } elseif (in_array($_POST['accion'] ?? '', ['actualizar', 'cambiar_password'], true)) {
            $editarId = filter_var($_POST['usuario_id'] ?? '', FILTER_VALIDATE_INT);
        }
    }
}

try {
    if ($editarId !== false && $editarId !== null) {
        $usuario = corozao_usuario_obtener_para_editar($pdo, (int)$editarId);
    } elseif (!$crear) {
        $where = [];
        $params = [];
        if ($buscar !== '') {
            $where[] = '(nombre_completo LIKE :nombre OR nombre_usuario LIKE :usuario)';
            $like = '%' . $buscar . '%';
            $params['nombre'] = $like;
            $params['usuario'] = $like;
        }
        if (in_array($rolFiltro, ['ADMINISTRADOR', 'CAJERO', 'MESERO'], true)) {
            $where[] = 'rol = :rol';
            $params['rol'] = $rolFiltro;
        } else {
            $rolFiltro = 'TODOS';
        }
        if ($estadoFiltro === 'ACTIVO') {
            $where[] = 'activo = 1';
        } elseif ($estadoFiltro === 'INACTIVO') {
            $where[] = 'activo = 0';
        } else {
            $estadoFiltro = 'TODOS';
        }
        $condicion = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios {$condicion}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        $paginaMaxima = max(1, (int)ceil($total / $porPagina));
        $pagina = min($pagina, $paginaMaxima);
        $offset = ($pagina - 1) * $porPagina;
        $stmt = $pdo->prepare(
            "SELECT usuario_id, nombre_completo, nombre_usuario, rol, activo, fecha_creacion
             FROM usuarios {$condicion} ORDER BY nombre_completo, usuario_id LIMIT {$porPagina} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Corozao usuarios: error al consultar usuarios.');
    if ($error === null) {
        http_response_code(500);
        $error = 'No se pudo consultar la administración de usuarios.';
    }
}

$mostrarFormulario = $crear || $usuario !== null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Usuarios | Corozao</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/brand/corozao-mark.svg?v=2">
    <link rel="stylesheet" href="/assets/css/brand.css">
</head>
<body data-corozao-role="<?= htmlspecialchars((string)($_SESSION['rol'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="bg-body-tertiary">
<nav class="navbar navbar-dark shadow-sm" style="background-color:#173b65"><div class="container-xxl"><a class="navbar-brand fw-semibold" href="/">Corozao</a><span class="navbar-text text-white">Administración de usuarios</span></div></nav>
<main class="container-xxl py-4 py-lg-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div><h1 class="h2 mb-1">Usuarios</h1><p class="text-secondary mb-0">Administración restringida a ADMINISTRADOR</p></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="/">Volver</a><a class="btn btn-primary" href="/usuarios.php?crear=1">Crear usuario</a></div></div>
    <?php if ($mensaje): ?><div class="alert alert-success" role="status"><?= usuarios_h($mensaje) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= usuarios_h($error) ?></div><?php endif; ?>

    <?php if ($mostrarFormulario):
        $valores = $usuario ?? ['nombre_completo'=>'','nombre_usuario'=>'','rol'=>'MESERO','activo'=>1];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach (['nombre_completo', 'nombre_usuario', 'rol', 'activo'] as $campo) {
                if (isset($_POST[$campo]) && is_string($_POST[$campo])) {
                    $valores[$campo] = $_POST[$campo];
                }
            }
        }
    ?>
    <section class="card shadow-sm mb-4"><div class="card-body p-3 p-lg-4">
        <h2 class="h4"><?= $usuario ? 'Editar usuario' : 'Crear usuario' ?></h2>
        <form method="post" action="/usuarios.php" class="row g-3" <?= $usuario ? 'onsubmit="return this.elements.activo.value !== \'0\' || confirm(\'¿Confirmas desactivar esta cuenta? No se eliminará su historial.\');"' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= usuarios_h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="<?= $usuario ? 'actualizar' : 'crear' ?>">
            <?php if ($usuario): ?><input type="hidden" name="usuario_id" value="<?= (int)$usuario['usuario_id'] ?>"><?php endif; ?>
            <div class="col-12 col-md-6"><label class="form-label" for="nombre_completo">Nombres y apellidos *</label><input class="form-control" id="nombre_completo" name="nombre_completo" maxlength="150" autocomplete="name" required value="<?= usuarios_h($valores['nombre_completo']) ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label" for="nombre_usuario">Usuario de inicio de sesión *</label><input class="form-control" id="nombre_usuario" name="nombre_usuario" maxlength="60" autocomplete="username" required value="<?= usuarios_h($valores['nombre_usuario']) ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label" for="rol">Rol *</label><select class="form-select" id="rol" name="rol" required><?php foreach (['ADMINISTRADOR','CAJERO','MESERO'] as $rol): ?><option value="<?= usuarios_h($rol) ?>" <?= $valores['rol'] === $rol ? 'selected' : '' ?>><?= usuarios_h($rol) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-6"><label class="form-label" for="activo">Estado</label><select class="form-select" id="activo" name="activo"><option value="1" <?= (int)$valores['activo'] === 1 ? 'selected' : '' ?>>Activo</option><option value="0" <?= (int)$valores['activo'] === 0 ? 'selected' : '' ?>>Inactivo</option></select></div>
            <?php if (!$usuario): ?><div class="col-12 col-md-6"><label class="form-label" for="password">Contraseña *</label><input class="form-control" id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><div class="form-text">Mínimo 12 caracteres y máximo 72 bytes. La contraseña no se mostrará ni podrá recuperarse.</div></div><div class="col-12 col-md-6"><label class="form-label" for="password_confirmacion">Confirmar contraseña *</label><input class="form-control" id="password_confirmacion" name="password_confirmacion" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div><?php endif; ?>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $usuario ? 'Guardar cambios' : 'Crear usuario' ?></button><a class="btn btn-outline-secondary" href="<?= $usuario ? '/usuarios.php' : '/usuarios.php' ?>">Cancelar</a></div>
        </form>
        <?php if ($usuario): ?><hr class="my-4"><h3 class="h5">Cambiar contraseña</h3><p class="text-secondary">Formulario independiente; la contraseña actual nunca se consulta ni se muestra.</p><form method="post" action="/usuarios.php" class="row g-3"><input type="hidden" name="csrf_token" value="<?= usuarios_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="accion" value="cambiar_password"><input type="hidden" name="usuario_id" value="<?= (int)$usuario['usuario_id'] ?>"><div class="col-12 col-md-6"><label class="form-label" for="nueva_password">Nueva contraseña *</label><input class="form-control" id="nueva_password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><div class="form-text">Mínimo 12 caracteres y máximo 72 bytes.</div></div><div class="col-12 col-md-6"><label class="form-label" for="confirmar_password">Confirmar nueva contraseña *</label><input class="form-control" id="confirmar_password" name="password_confirmacion" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div><div class="col-12"><button class="btn btn-outline-primary" type="submit">Actualizar contraseña</button></div></form><?php endif; ?>
    </div></section>
    <?php endif; ?>

    <?php if (!$mostrarFormulario): ?><section class="card shadow-sm mb-4"><div class="card-body"><form method="get" action="/usuarios.php" class="row g-3 align-items-end"><div class="col-12 col-md-6 col-xl-5"><label class="form-label" for="q">Buscar por nombre o usuario</label><input class="form-control" id="q" name="q" maxlength="100" value="<?= usuarios_h($buscar) ?>"></div><div class="col-12 col-md-3"><label class="form-label" for="rol_filtro">Rol</label><select class="form-select" name="rol" id="rol_filtro"><option value="TODOS">Todos</option><?php foreach (['ADMINISTRADOR','CAJERO','MESERO'] as $rol): ?><option value="<?= usuarios_h($rol) ?>" <?= $rolFiltro === $rol ? 'selected' : '' ?>><?= usuarios_h($rol) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3"><label class="form-label" for="estado_filtro">Estado</label><select class="form-select" name="estado" id="estado_filtro"><?php foreach (['TODOS'=>'Todos','ACTIVO'=>'Activos','INACTIVO'=>'Inactivos'] as $v=>$label): ?><option value="<?= usuarios_h($v) ?>" <?= $estadoFiltro === $v ? 'selected' : '' ?>><?= usuarios_h($label) ?></option><?php endforeach; ?></select></div><div class="col-12"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn btn-outline-secondary" href="/usuarios.php">Limpiar</a></div></form></div></section>
    <section class="card shadow-sm"><div class="card-body"><p><strong><?= $total ?></strong> usuario(s) · Página <?= $pagina ?> de <?= $paginaMaxima ?></p><div class="table-responsive"><table class="table table-striped table-hover align-middle"><thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Estado</th><th>Creado</th><th>Acciones</th></tr></thead><tbody><?php foreach ($usuarios as $fila): ?><tr><td><?= usuarios_h($fila['nombre_completo']) ?></td><td><?= usuarios_h($fila['nombre_usuario']) ?></td><td><span class="badge text-bg-primary"><?= usuarios_h($fila['rol']) ?></span></td><td><span class="badge text-bg-<?= (int)$fila['activo'] === 1 ? 'success' : 'secondary' ?>"><?= (int)$fila['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span></td><td><?= usuarios_h($fila['fecha_creacion']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="/usuarios.php?editar=<?= (int)$fila['usuario_id'] ?>">Administrar</a></td></tr><?php endforeach; ?><?php if (!$usuarios): ?><tr><td colspan="6" class="text-secondary">No se encontraron usuarios.</td></tr><?php endif; ?></tbody></table></div><?php if ($paginaMaxima > 1): ?><nav class="d-flex justify-content-between" aria-label="Paginación"><span>Página <?= $pagina ?> de <?= $paginaMaxima ?></span><div class="d-flex gap-2"><?php if ($pagina > 1): ?><a class="btn btn-sm btn-outline-primary" href="?<?= usuarios_h(http_build_query(['q'=>$buscar,'rol'=>$rolFiltro,'estado'=>$estadoFiltro,'pagina'=>$pagina-1])) ?>">Anterior</a><?php endif; ?><?php if ($pagina < $paginaMaxima): ?><a class="btn btn-sm btn-outline-primary" href="?<?= usuarios_h(http_build_query(['q'=>$buscar,'rol'=>$rolFiltro,'estado'=>$estadoFiltro,'pagina'=>$pagina+1])) ?>">Siguiente</a><?php endif; ?></div></nav><?php endif; ?></div></section><?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/brand.js" defer></script>
</body>
</html>
