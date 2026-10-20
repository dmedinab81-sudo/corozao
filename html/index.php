<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_name('COROZAO_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
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
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
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
    <title>Corozao | Sistema de inventario y ventas</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            color: #1f2937;
            background: #f3f5f8;
        }
        .panel {
            width: 100%;
            max-width: 430px;
            padding: 32px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 8px 28px rgb(0 0 0 / 7%);
        }
        h1 { margin-top: 0; margin-bottom: 8px; font-size: 27px; }
        .subtitulo { margin-top: 0; margin-bottom: 26px; color: #64748b; }
        label { display: block; margin: 16px 0 7px; font-weight: 600; }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 16px;
        }
        button {
            width: 100%;
            margin-top: 22px;
            padding: 13px;
            border: 0;
            border-radius: 6px;
            background: #173b65;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { background: #102d4d; }
        .error {
            padding: 12px;
            border-radius: 6px;
            background: #fef2f2;
            color: #991b1b;
        }
        .detalle { color: #64748b; font-size: 14px; }
    </style>
</head>
<body>
<main class="panel">
    <h1>Corozao</h1>
    <p class="subtitulo">Sistema de inventario y ventas</p>

    <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= escapar($error) ?></p>
    <?php endif; ?>

    <?php if ($autenticado): ?>
        <h2>Bienvenido</h2>
        <p><?= escapar((string) $_SESSION['nombre_completo']) ?></p>
        <p class="detalle">
            Usuario: <?= escapar((string) $_SESSION['nombre_usuario']) ?><br>
            Rol: <?= escapar((string) $_SESSION['rol']) ?>
        </p>
        <p>El acceso al sistema funciona. Los módulos de inventario y ventas todavía están pendientes de implementación.</p>

        <form method="post">
            <input type="hidden" name="csrf_token"
                   value="<?= escapar($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="salir">
            <button type="submit">Cerrar sesión</button>
        </form>
    <?php else: ?>
        <form method="post" autocomplete="on">
            <input type="hidden" name="csrf_token"
                   value="<?= escapar($_SESSION['csrf_token']) ?>">

            <label for="usuario">Usuario</label>
            <input id="usuario" name="usuario" type="text"
                   maxlength="60" autocomplete="username"
                   required autofocus>

            <label for="clave">Contraseña</label>
            <input id="clave" name="clave" type="password"
                   maxlength="1024" autocomplete="current-password"
                   required>

            <button type="submit">Ingresar</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
