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

function escapar(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfRecibido = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($_SESSION['csrf_token'], $csrfRecibido)
    ) {
        http_response_code(403);
        $error = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
    } elseif (($_POST['accion'] ?? '') === 'salir') {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
        }

        session_destroy();
        header('Location: /');
        exit;
    } else {
        $usuarioRecibido = $_POST['usuario'] ?? '';
        $usuario = is_string($usuarioRecibido) ? trim($usuarioRecibido) : '';
        $clave = $_POST['clave'] ?? '';

        if (
            $usuario === '' ||
            !is_string($clave) ||
            $clave === '' ||
            strlen($usuario) > 60 ||
            strlen($clave) > 1024
        ) {
            $error = 'Usuario o contraseña incorrectos.';
        } else {
            try {
                require '/etc/corozao/database.php';

                $consulta = $pdo->prepare(
                    'SELECT usuario_id, nombre_completo, nombre_usuario,
                            password_hash, rol, activo
                     FROM usuarios
                     WHERE nombre_usuario = :usuario
                     LIMIT 1'
                );

                $consulta->execute(['usuario' => $usuario]);
                $registro = $consulta->fetch();

                if (
                    $registro &&
                    (int) $registro['activo'] === 1 &&
                    password_verify($clave, $registro['password_hash'])
                ) {
                    session_regenerate_id(true);

                    $_SESSION['usuario_id'] = (int) $registro['usuario_id'];
                    $_SESSION['nombre_completo'] = $registro['nombre_completo'];
                    $_SESSION['nombre_usuario'] = $registro['nombre_usuario'];
                    $_SESSION['rol'] = $registro['rol'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: /');
                    exit;
                }

                $error = 'Usuario o contraseña incorrectos.';
            } catch (Throwable $e) {
                error_log('Corozao: error interno durante el inicio de sesión.');
                http_response_code(500);
                $error = 'No se pudo procesar el acceso. Inténtalo más tarde.';
            }
        }
    }
}

$autenticado = isset($_SESSION['usuario_id'], $_SESSION['rol']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Corozao | Sistema de inventario y ventas</title>
</head>
<body class="bg-body-tertiary">
<main class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
  <section class="card shadow-sm w-100" style="max-width: 32rem" aria-labelledby="titulo-principal">
    <div class="card-body p-4 p-md-5">
    <div class="mb-4">
        <span class="badge text-bg-primary mb-3">Corozao</span>
        <h1 id="titulo-principal" class="h3 mb-2">Sistema de inventario y ventas</h1>
        <p class="text-body-secondary mb-0">Acceso seguro al sistema</p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= escapar($error) ?></div>
    <?php endif; ?>

    <?php if ($autenticado): ?>
        <h2 class="h5">Bienvenido, <?= escapar((string) $_SESSION['nombre_completo']) ?></h2>
        <dl class="row small text-body-secondary mb-4">
            <dt class="col-4">Usuario</dt>
            <dd class="col-8"><?= escapar((string) $_SESSION['nombre_usuario']) ?></dd>
            <dt class="col-4">Rol</dt>
            <dd class="col-8"><?= escapar((string) $_SESSION['rol']) ?></dd>
        </dl>
        <div class="alert alert-info" role="status">El catálogo está disponible. Los módulos de inventario y ventas aún están pendientes.</div>
        <a class="btn btn-primary w-100 mb-3" href="/productos.php">Abrir catálogo de productos</a>
        <?php if ($_SESSION['rol'] === 'ADMINISTRADOR'): ?>
            <a class="btn btn-outline-primary w-100 mb-3" href="/proveedores.php">Administrar proveedores</a>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token"
                   value="<?= escapar($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="salir">
            <button class="btn btn-outline-secondary w-100" type="submit">Cerrar sesión</button>
        </form>
    <?php else: ?>
        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf_token"
                   value="<?= escapar($_SESSION['csrf_token']) ?>">

            <div class="mb-3">
                <label class="form-label" for="usuario">Usuario</label>
                <input class="form-control form-control-lg" id="usuario" name="usuario" type="text"
                       maxlength="60" autocomplete="username" required autofocus>
            </div>

            <div class="mb-4">
                <label class="form-label" for="clave">Contraseña</label>
                <input class="form-control form-control-lg" id="clave" name="clave" type="password"
                       maxlength="1024" autocomplete="current-password" required>
            </div>

            <button class="btn btn-primary btn-lg w-100" type="submit">Ingresar</button>
        </form>
    <?php endif; ?>
    </div>
  </section>
</main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
