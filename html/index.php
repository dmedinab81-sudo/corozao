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
require_once __DIR__ . '/includes/autorizacion.php';

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
        !corozao_csrf_valido($csrfRecibido)
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

$usuarioActual = null;
if (isset($_SESSION['usuario_id'])) {
    try {
        require '/etc/corozao/database.php';
        $usuarioActual = corozao_usuario_sesion($pdo);
        if ($usuarioActual === null) {
            unset($_SESSION['usuario_id'], $_SESSION['rol'], $_SESSION['nombre_completo'], $_SESSION['nombre_usuario']);
        }
    } catch (Throwable $e) {
        error_log('Corozao: no se pudo validar la sesión activa.');
        http_response_code(503);
        $error = 'No se pudo validar la sesión. Inténtalo más tarde.';
    }
}
$autenticado = $usuarioActual !== null;
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
<?php if ($autenticado): ?>
<nav class="navbar bg-white border-bottom">
  <div class="container-xxl">
    <a class="navbar-brand fw-semibold" href="/">Corozao</a>
    <div class="d-flex align-items-center gap-2">
      <span class="badge text-bg-primary"><?= escapar((string) $_SESSION['rol']) ?></span>
      <form method="post" class="m-0">
        <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="salir">
        <button class="btn btn-sm btn-outline-secondary" type="submit">Cerrar sesión</button>
      </form>
    </div>
  </div>
</nav>
<main class="container-xxl py-4 py-lg-5">
  <header class="mb-4">
    <p class="text-primary fw-semibold mb-1">Panel de trabajo</p>
    <h1 class="h2 mb-2">Bienvenido, <?= escapar((string) $_SESSION['nombre_completo']) ?></h1>
    <p class="text-body-secondary mb-0">Sesión de <?= escapar((string) $_SESSION['nombre_usuario']) ?> · <?= escapar((string) $_SESSION['rol']) ?></p>
  </header>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= escapar($error) ?></div>
  <?php endif; ?>

  <div class="row g-3 g-lg-4">
    <div class="col-12 col-md-6 col-xl-4">
      <section class="card h-100 shadow-sm" aria-labelledby="modulo-productos">
        <div class="card-body d-flex flex-column p-4">
          <span class="badge text-bg-success align-self-start mb-3">Disponible</span>
          <h2 id="modulo-productos" class="h5">Catálogo de productos</h2>
          <p class="text-body-secondary flex-grow-1">Consulta y busca los productos registrados.</p>
          <a class="btn btn-primary" href="/productos.php">Abrir catálogo</a>
        </div>
      </section>
    </div>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'commercial.prices.view')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-comercial-bodega">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-<?= $_SESSION['rol'] === 'ADMINISTRADOR' ? 'primary' : 'success' ?> align-self-start mb-3">
              <?= $_SESSION['rol'] === 'ADMINISTRADOR' ? 'Administración' : 'Consulta por bodega' ?>
            </span>
            <h2 id="modulo-comercial-bodega" class="h5">Catálogo comercial</h2>
            <p class="text-body-secondary flex-grow-1">
              <?= $_SESSION['rol'] === 'ADMINISTRADOR'
                  ? 'Configura habilitación y precios por presentación para cada bodega.'
                  : 'Consulta precios y presentaciones habilitadas en tus bodegas asignadas.' ?>
            </p>
            <a class="btn btn-outline-primary" href="/comercial_bodegas.php">
              <?= $_SESSION['rol'] === 'ADMINISTRADOR' ? 'Gestionar bodegas' : 'Consultar catálogo' ?>
            </a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'suppliers.view')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-proveedores">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-success align-self-start mb-3">Disponible</span>
            <h2 id="modulo-proveedores" class="h5">Proveedores</h2>
            <p class="text-body-secondary flex-grow-1">Busca, consulta y administra proveedores y sus productos.</p>
            <a class="btn btn-outline-primary" href="/proveedores.php">Abrir proveedores</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'users.warehouses.assign')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-asignaciones">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-primary align-self-start mb-3">Administración</span>
            <h2 id="modulo-asignaciones" class="h5">Acceso por bodega</h2>
            <p class="text-body-secondary flex-grow-1">Asigna o revoca el acceso de cajeros y meseros a bodegas.</p>
            <a class="btn btn-outline-primary" href="/asignaciones_bodega.php">Gestionar asignaciones</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'inventory.availability.view')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-inventario">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-<?= $_SESSION['rol'] === 'ADMINISTRADOR' ? 'primary' : 'success' ?> align-self-start mb-3">
              <?= $_SESSION['rol'] === 'ADMINISTRADOR' ? 'Administración' : 'Consulta asignada' ?>
            </span>
            <h2 id="modulo-inventario" class="h5">Inventario y Kardex</h2>
            <p class="text-body-secondary flex-grow-1">
              <?= $_SESSION['rol'] === 'ADMINISTRADOR'
                  ? 'Consulta existencias y movimientos confirmados por bodega.'
                  : 'Consulta disponibilidad de productos en tus bodegas asignadas.' ?>
            </p>
            <a class="btn btn-outline-primary" href="/inventario.php">Consultar inventario</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'inventory.transfer.review')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-transferencias">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-primary align-self-start mb-3">Administración</span>
            <h2 id="modulo-transferencias" class="h5">Transferencias</h2>
            <p class="text-body-secondary flex-grow-1">Crea borradores, despacha desde origen y registra la recepción en destino.</p>
            <a class="btn btn-outline-primary" href="/transferencias.php">Gestionar transferencias</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'purchases.view')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-compras">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-primary align-self-start mb-3">Administración</span>
            <h2 id="modulo-compras" class="h5">Compras e ingresos</h2>
            <p class="text-body-secondary flex-grow-1">Registra borradores y confirma el ingreso de mercadería a una bodega.</p>
            <a class="btn btn-outline-primary" href="/compras.php">Gestionar compras</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'inventory.adjustment.view')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-ajustes">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-warning align-self-start mb-3">Inventario</span>
            <h2 id="modulo-ajustes" class="h5">Ajustes de inventario</h2>
            <p class="text-body-secondary flex-grow-1">Registra ajustes positivos o negativos con motivo obligatorio y auditoría.</p>
            <a class="btn btn-outline-primary" href="/ajustes.php">Gestionar ajustes</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'orders.own.view')
        || corozao_rol_puede((string) $_SESSION['rol'], 'orders.pending_billing.view')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-pedidos">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-info align-self-start mb-3">Atención</span>
            <h2 id="modulo-pedidos" class="h5"><?= $_SESSION['rol'] === 'CAJERO' ? 'Pedidos por facturar' : 'Pedidos' ?></h2>
            <p class="text-body-secondary flex-grow-1"><?= $_SESSION['rol'] === 'CAJERO'
                ? 'Consulta pedidos que solicitaron facturación.'
                : 'Registra pedidos, agrega presentaciones y envíalos a preparación o facturación.' ?></p>
            <a class="btn btn-outline-primary" href="/pedidos.php">Gestionar pedidos</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'sales.create')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-ventas">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-success align-self-start mb-3">Operación de caja</span>
            <h2 id="modulo-ventas" class="h5">Ventas y caja</h2>
            <p class="text-body-secondary flex-grow-1">Abre tu caja, cobra pedidos pendientes y registra pagos divididos y cierres.</p>
            <a class="btn btn-outline-primary" href="/ventas.php">Abrir ventas y caja</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'reports.view')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-reportes">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-primary align-self-start mb-3">Administración</span>
            <h2 id="modulo-reportes" class="h5">Reportes y conteos</h2>
            <p class="text-body-secondary flex-grow-1">Consulta ventas y cierres; registra conteos físicos con diferencias auditadas.</p>
            <a class="btn btn-outline-primary" href="/reportes.php">Abrir reportes</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
    <?php if (corozao_rol_puede((string) $_SESSION['rol'], 'inventory.initial_load.create')): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <section class="card h-100 shadow-sm" aria-labelledby="modulo-carga-inicial">
          <div class="card-body d-flex flex-column p-4">
            <span class="badge text-bg-warning align-self-start mb-3">Administración</span>
            <h2 id="modulo-carga-inicial" class="h5">Inventario inicial</h2>
            <p class="text-body-secondary flex-grow-1">Autoriza cargas desde conteos cerrados cuando no exista historial en la bodega.</p>
            <a class="btn btn-outline-primary" href="/cargas_iniciales.php">Revisar conteos elegibles</a>
          </div>
        </section>
      </div>
    <?php endif; ?>
  </div>

</main>
<?php else: ?>
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
      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= escapar($_SESSION['csrf_token']) ?>">

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
    </div>
  </section>
</main>
<?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
