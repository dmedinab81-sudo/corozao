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

$usuarioId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT);
if (!$usuarioId || ($_SESSION['rol'] ?? '') !== 'ADMINISTRADOR') {
    http_response_code(isset($_SESSION['usuario_id']) ? 403 : 401);
    exit('Se requiere una sesión de administrador activa.');
}

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function longitudTexto(string $value): int
{
    $count = preg_match_all('/./us', $value);
    return $count === false ? strlen($value) : $count;
}

function instantaneaProveedor(array $row): array
{
    $campos = [
        'tipo_documento', 'numero_documento', 'razon_social', 'nombre_comercial',
        'contacto', 'telefono', 'telefono_alternativo', 'correo', 'direccion',
        'activo',
    ];
    $snapshot = [];
    foreach ($campos as $campo) {
        if (array_key_exists($campo, $row)) {
            $snapshot[$campo] = $row[$campo];
        }
    }
    if (array_key_exists('observaciones', $row)) {
        $snapshot['observaciones_omitidas_por_confidencialidad'] = trim((string) ($row['observaciones'] ?? '')) !== '';
    }
    return $snapshot;
}

function registrarAuditoria(
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
    $jsonAnteriores = $anteriores === null
        ? null
        : json_encode($anteriores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $jsonPosteriores = $posteriores === null
        ? null
        : json_encode($posteriores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt->bindValue(':usuario', $usuarioId, PDO::PARAM_INT);
    $stmt->bindValue(':accion', $accion, PDO::PARAM_STR);
    $stmt->bindValue(':entidad', $entidad, PDO::PARAM_STR);
    $stmt->bindValue(':registro', $registroId, PDO::PARAM_INT);
    $stmt->bindValue(':anteriores', $jsonAnteriores, $jsonAnteriores === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':posteriores', $jsonPosteriores, $jsonPosteriores === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->execute();
}

require '/etc/corozao/database.php';

// La sesión debe pertenecer a una cuenta que siga activa y con rol administrador.
$stmt = $pdo->prepare(
    'SELECT rol, activo FROM usuarios WHERE usuario_id = :usuario LIMIT 1'
);
$stmt->execute(['usuario' => $usuarioId]);
$usuarioActual = $stmt->fetch();
if (!$usuarioActual || (int) $usuarioActual['activo'] !== 1 || $usuarioActual['rol'] !== 'ADMINISTRADOR') {
    http_response_code(403);
    exit('La cuenta no tiene permisos para administrar proveedores.');
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla'
);
$stmt->execute(['tabla' => 'auditoria_eventos']);
$auditoriaDisponible = (int) $stmt->fetchColumn() === 1;

if (empty($_SESSION['proveedores_flash']) || !is_array($_SESSION['proveedores_flash'])) {
    $flash = null;
} else {
    $flash = $_SESSION['proveedores_flash'];
    unset($_SESSION['proveedores_flash']);
}

$errores = [];
$errorGeneral = null;
$valores = [
    'tipo_documento' => 'RUC',
    'numero_documento' => '',
    'razon_social' => '',
    'nombre_comercial' => '',
    'contacto' => '',
    'telefono' => '',
    'telefono_alternativo' => '',
    'correo' => '',
    'direccion' => '',
    'observaciones' => '',
];
$accionGet = $_GET['accion'] ?? 'listar';
$accionGet = is_string($accionGet) ? $accionGet : 'listar';
$proveedorId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$proveedorId = $proveedorId !== false && $proveedorId !== null && $proveedorId > 0
    ? (int) $proveedorId
    : null;
$modo = in_array($accionGet, ['crear', 'editar', 'ver'], true) ? $accionGet : 'listar';
$busquedaRecibida = $_GET['q'] ?? '';
$busqueda = is_string($busquedaRecibida) ? trim($busquedaRecibida) : '';
$estadoRecibido = $_GET['estado'] ?? 'ACTIVOS';
$estado = is_string($estadoRecibido) ? $estadoRecibido : 'ACTIVOS';
if (!in_array($estado, ['ACTIVOS', 'INACTIVOS', 'TODOS'], true)) {
    $estado = 'ACTIVOS';
}
if (in_array($modo, ['editar', 'ver'], true) && $proveedorId === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $modo = 'listar';
    $errorGeneral = 'El identificador del proveedor no es válido.';
}
$pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
$pagina = max(1, (int) ($pagina ?: 1));
$porPagina = 50;
$paginaMaxima = 1;
$total = 0;
$proveedores = [];
$proveedor = null;
$productosRelacionados = [];
$productosDisponibles = [];
$historial = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenRecibido = $_POST['csrf_token'] ?? '';
    if (!is_string($tokenRecibido) || !hash_equals($csrfToken, $tokenRecibido)) {
        http_response_code(403);
        $errorGeneral = 'La solicitud expiró. Recarga la página e inténtalo nuevamente.';
    } elseif (!$auditoriaDisponible) {
        http_response_code(503);
        $errorGeneral = 'Las escrituras están pausadas hasta aplicar la migración de auditoría. No se guardaron cambios.';
    } else {
        $accionPost = $_POST['accion'] ?? '';
        $accionPost = is_string($accionPost) ? $accionPost : '';

        try {
            if ($accionPost === 'guardar') {
                $campos = [
                    'tipo_documento' => 20,
                    'numero_documento' => 20,
                    'razon_social' => 200,
                    'nombre_comercial' => 200,
                    'contacto' => 150,
                    'telefono' => 30,
                    'telefono_alternativo' => 30,
                    'correo' => 150,
                    'direccion' => 300,
                    'observaciones' => 65535,
                ];
                foreach ($campos as $campo => $maximo) {
                    $raw = $_POST[$campo] ?? '';
                    if (!is_string($raw)) {
                        $errores[$campo] = 'Valor no válido.';
                        $raw = '';
                    }
                    $raw = trim($raw);
                    $valores[$campo] = $raw;
                    $longitud = longitudTexto($raw);
                    if ($campo === 'observaciones' ? strlen($raw) > 65535 : $longitud > $maximo) {
                        $errores[$campo] = 'El valor excede la longitud permitida.';
                    }
                }
                $tiposDocumento = ['RUC', 'CEDULA', 'PASAPORTE', 'OTRO'];
                if (!in_array($valores['tipo_documento'], $tiposDocumento, true)) {
                    $errores['tipo_documento'] = 'Selecciona un tipo de documento válido.';
                }
                if ($valores['razon_social'] === '') {
                    $errores['razon_social'] = 'La razón social es obligatoria.';
                }
                if ($valores['correo'] !== '' && filter_var($valores['correo'], FILTER_VALIDATE_EMAIL) === false) {
                    $errores['correo'] = 'Ingresa un correo válido.';
                }

                if (!$errores) {
                    $idRaw = $_POST['proveedor_id'] ?? null;
                    $idPost = $idRaw === null ? null : filter_var($idRaw, FILTER_VALIDATE_INT);
                    if ($idPost === false || ($idPost !== null && $idPost < 1)) {
                        $errores['proveedor_id'] = 'El identificador del proveedor no es válido.';
                    }
                }

                if (!$errores) {
                    $idPost = $idPost !== false && $idPost !== null && $idPost > 0 ? (int) $idPost : null;
                    $tipo = $valores['tipo_documento'];
                    $datos = [
                        'tipo_documento' => $tipo,
                        'numero_documento' => $valores['numero_documento'] !== '' ? $valores['numero_documento'] : null,
                        'razon_social' => $valores['razon_social'],
                        'nombre_comercial' => $valores['nombre_comercial'] !== '' ? $valores['nombre_comercial'] : null,
                        'contacto' => $valores['contacto'] !== '' ? $valores['contacto'] : null,
                        'telefono' => $valores['telefono'] !== '' ? $valores['telefono'] : null,
                        'telefono_alternativo' => $valores['telefono_alternativo'] !== '' ? $valores['telefono_alternativo'] : null,
                        'correo' => $valores['correo'] !== '' ? $valores['correo'] : null,
                        'direccion' => $valores['direccion'] !== '' ? $valores['direccion'] : null,
                        'observaciones' => $valores['observaciones'] !== '' ? $valores['observaciones'] : null,
                    ];

                    $pdo->beginTransaction();
                    if ($idPost === null) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO proveedores
                                (tipo_documento, numero_documento, razon_social, nombre_comercial,
                                 contacto, telefono, telefono_alternativo, correo, direccion, observaciones)
                             VALUES
                                (:tipo_documento, :numero_documento, :razon_social, :nombre_comercial,
                                 :contacto, :telefono, :telefono_alternativo, :correo, :direccion, :observaciones)'
                        );
                        $stmt->execute($datos);
                        $idPost = (int) $pdo->lastInsertId();
                        $postSnapshot = instantaneaProveedor($datos + ['activo' => 1]);
                        registrarAuditoria($pdo, $usuarioId, 'CREAR', 'proveedores', $idPost, null, $postSnapshot);
                        $_SESSION['proveedores_flash'] = ['tipo' => 'success', 'texto' => 'Proveedor creado.'];
                    } else {
                        $stmt = $pdo->prepare(
                            'SELECT * FROM proveedores WHERE proveedor_id = :id FOR UPDATE'
                        );
                        $stmt->execute(['id' => $idPost]);
                        $anterior = $stmt->fetch();
                        if (!$anterior || (int) $anterior['activo'] !== 1) {
                            throw new DomainException('Proveedor no disponible para edición.');
                        }
                        $stmt = $pdo->prepare(
                            'UPDATE proveedores SET
                                tipo_documento = :tipo_documento,
                                numero_documento = :numero_documento,
                                razon_social = :razon_social,
                                nombre_comercial = :nombre_comercial,
                                contacto = :contacto,
                                telefono = :telefono,
                                telefono_alternativo = :telefono_alternativo,
                                correo = :correo,
                                direccion = :direccion,
                                observaciones = :observaciones
                             WHERE proveedor_id = :id AND activo = 1'
                        );
                        $stmt->execute($datos + ['id' => $idPost]);
                        $postSnapshot = instantaneaProveedor($datos + ['activo' => 1]);
                        registrarAuditoria(
                            $pdo,
                            $usuarioId,
                            'MODIFICAR',
                            'proveedores',
                            $idPost,
                            instantaneaProveedor($anterior),
                            $postSnapshot
                        );
                        $_SESSION['proveedores_flash'] = ['tipo' => 'success', 'texto' => 'Proveedor actualizado.'];
                    }
                    $pdo->commit();
                    header('Location: /proveedores.php?accion=ver&id=' . $idPost, true, 303);
                    exit;
                }
                $idReenvio = filter_var($_POST['proveedor_id'] ?? null, FILTER_VALIDATE_INT);
                $modo = $idReenvio !== false && $idReenvio !== null && $idReenvio > 0 ? 'editar' : 'crear';
                $proveedorId = $modo === 'editar' ? (int) $idReenvio : null;
            } elseif ($accionPost === 'desactivar') {
                $idPost = filter_var($_POST['proveedor_id'] ?? null, FILTER_VALIDATE_INT);
                if ($idPost === false || $idPost === null || $idPost < 1) {
                    throw new DomainException('Identificador no válido.');
                }
                $idPost = (int) $idPost;
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM proveedores WHERE proveedor_id = :id FOR UPDATE');
                $stmt->execute(['id' => $idPost]);
                $anterior = $stmt->fetch();
                if (!$anterior || (int) $anterior['activo'] !== 1) {
                    throw new DomainException('El proveedor no está activo.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE proveedores SET activo = 0 WHERE proveedor_id = :id AND activo = 1'
                );
                $stmt->execute(['id' => $idPost]);
                $posterior = instantaneaProveedor($anterior);
                $posterior['activo'] = 0;
                registrarAuditoria(
                    $pdo,
                    $usuarioId,
                    'DESACTIVAR',
                    'proveedores',
                    $idPost,
                    instantaneaProveedor($anterior),
                    $posterior
                );
                $pdo->commit();
                $_SESSION['proveedores_flash'] = ['tipo' => 'success', 'texto' => 'Proveedor desactivado; sus relaciones se conservaron.'];
                header('Location: /proveedores.php?estado=INACTIVOS', true, 303);
                exit;
            } elseif ($accionPost === 'relacionar') {
                $idPost = filter_var($_POST['proveedor_id'] ?? null, FILTER_VALIDATE_INT);
                $productoPost = filter_var($_POST['producto_id'] ?? null, FILTER_VALIDATE_INT);
                $idPost = $idPost !== false && $idPost !== null && $idPost > 0 ? (int) $idPost : null;
                $productoPost = $productoPost !== false && $productoPost !== null && $productoPost > 0 ? (int) $productoPost : null;
                $codigo = $_POST['codigo_producto_proveedor'] ?? '';
                $plazo = $_POST['plazo_entrega_dias'] ?? '';
                $observacionRelacion = $_POST['observaciones_relacion'] ?? '';
                $preferidoRaw = $_POST['es_proveedor_preferido'] ?? '';
                $preferido = is_string($preferidoRaw) && $preferidoRaw === '1' ? 1 : 0;
                if (!is_string($codigo) || !is_string($plazo) || !is_string($observacionRelacion)) {
                    $errores['relacion'] = 'Los datos de la relación no son válidos.';
                }
                $codigo = is_string($codigo) ? trim($codigo) : '';
                $plazo = is_string($plazo) ? trim($plazo) : '';
                $observacionRelacion = is_string($observacionRelacion) ? trim($observacionRelacion) : '';
                if (!$idPost || !$productoPost) {
                    $errores['relacion'] = 'Selecciona un proveedor y producto válidos.';
                }
                if (longitudTexto($codigo) > 100 || longitudTexto($observacionRelacion) > 500) {
                    $errores['relacion'] = 'El código o la observación exceden la longitud permitida.';
                }
                $plazoInt = null;
                if ($plazo !== '') {
                    $plazoInt = filter_var($plazo, FILTER_VALIDATE_INT);
                    if ($plazoInt === false || $plazoInt < 0 || $plazoInt > 65535) {
                        $errores['relacion'] = 'El plazo debe ser un entero entre 0 y 65535 días.';
                    }
                }
                if (!$errores) {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('SELECT activo FROM proveedores WHERE proveedor_id = :id FOR UPDATE');
                    $stmt->execute(['id' => $idPost]);
                    if ((int) $stmt->fetchColumn() !== 1) {
                        throw new DomainException('El proveedor debe estar activo.');
                    }
                    $stmt = $pdo->prepare('SELECT nombre, activo FROM productos WHERE producto_id = :id');
                    $stmt->execute(['id' => $productoPost]);
                    $productoLink = $stmt->fetch();
                    if (!$productoLink || (int) $productoLink['activo'] !== 1) {
                        throw new DomainException('El producto no existe o está inactivo.');
                    }
                    $stmt = $pdo->prepare(
                        'INSERT INTO proveedor_productos
                            (proveedor_id, producto_id, codigo_producto_proveedor,
                             es_proveedor_preferido, plazo_entrega_dias, observaciones)
                         VALUES (:proveedor, :producto, :codigo, :preferido, :plazo, :observaciones)'
                    );
                    $stmt->execute([
                        'proveedor' => $idPost,
                        'producto' => $productoPost,
                        'codigo' => $codigo !== '' ? $codigo : null,
                        'preferido' => $preferido,
                        'plazo' => $plazoInt,
                        'observaciones' => $observacionRelacion !== '' ? $observacionRelacion : null,
                    ]);
                    $relacionId = (int) $pdo->lastInsertId();
                    registrarAuditoria(
                        $pdo,
                        $usuarioId,
                        'CREAR',
                        'proveedor_productos',
                        $relacionId,
                        null,
                        [
                            'proveedor_id' => $idPost,
                            'producto_id' => $productoPost,
                            'codigo_producto_proveedor' => $codigo !== '' ? $codigo : null,
                            'es_proveedor_preferido' => $preferido,
                            'plazo_entrega_dias' => $plazoInt,
                            'observaciones_omitidas_por_confidencialidad' => $observacionRelacion !== '',
                        ]
                    );
                    $pdo->commit();
                    $_SESSION['proveedores_flash'] = ['tipo' => 'success', 'texto' => 'Producto relacionado con el proveedor.'];
                    header('Location: /proveedores.php?accion=ver&id=' . $idPost, true, 303);
                    exit;
                }
                $modo = 'ver';
                $proveedorId = $idPost;
            } else {
                http_response_code(400);
                $errorGeneral = 'Acción no válida.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Corozao proveedores: operación transaccional fallida.');
            if ($e instanceof DomainException) {
                $errorGeneral = $e->getMessage();
                http_response_code(422);
            } elseif ($e instanceof PDOException && $e->getCode() === '23000') {
                $errorGeneral = 'El documento o la relación ya existe, o una referencia dejó de estar disponible.';
                http_response_code(409);
            } else {
                $errorGeneral = 'No se pudo guardar la operación; cualquier cambio fue revertido.';
                http_response_code(500);
            }
        }
    }
}

if ($modo === 'editar' && $proveedorId !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM proveedores WHERE proveedor_id = :id LIMIT 1');
    $stmt->execute(['id' => $proveedorId]);
    $proveedor = $stmt->fetch();
    if (!$proveedor || (int) $proveedor['activo'] !== 1) {
        $modo = 'listar';
        $errorGeneral = 'No se encontró un proveedor activo para editar.';
    } else {
        foreach ($valores as $campo => $valor) {
            $valores[$campo] = (string) ($proveedor[$campo] ?? '');
        }
    }
} elseif ($modo === 'ver' && $proveedorId !== null) {
    $stmt = $pdo->prepare('SELECT * FROM proveedores WHERE proveedor_id = :id LIMIT 1');
    $stmt->execute(['id' => $proveedorId]);
    $proveedor = $stmt->fetch() ?: null;
    if ($proveedor) {
        $stmt = $pdo->prepare(
            'SELECT pp.proveedor_producto_id, pp.codigo_producto_proveedor,
                    pp.es_proveedor_preferido, pp.plazo_entrega_dias, pp.observaciones,
                    p.producto_id, p.nombre AS producto_nombre, p.marca, p.activo AS producto_activo
             FROM proveedor_productos pp
             INNER JOIN productos p ON p.producto_id = pp.producto_id
             WHERE pp.proveedor_id = :id
             ORDER BY p.nombre, p.producto_id'
        );
        $stmt->execute(['id' => $proveedorId]);
        $productosRelacionados = $stmt->fetchAll();

        if ((int) $proveedor['activo'] === 1) {
            $productosDisponibles = $pdo->query(
                'SELECT producto_id, nombre, marca FROM productos
                 WHERE activo = 1 ORDER BY nombre, producto_id LIMIT 1000'
            )->fetchAll();
        }
        if ($auditoriaDisponible) {
            $stmt = $pdo->prepare(
                'SELECT a.evento_id, a.fecha_hora, a.accion, a.entidad, a.registro_id,
                        a.valores_anteriores, a.valores_posteriores,
                        u.nombre_completo AS usuario_nombre
                 FROM auditoria_eventos a
                 INNER JOIN usuarios u ON u.usuario_id = a.usuario_id
                 WHERE (a.entidad = :entidad_proveedor AND a.registro_id = :id_proveedor)
                    OR (a.entidad = :entidad_relacion AND a.registro_id IN
                        (SELECT proveedor_producto_id FROM proveedor_productos WHERE proveedor_id = :id_relacion))
                 ORDER BY a.fecha_hora DESC, a.evento_id DESC LIMIT 100'
            );
            $stmt->execute([
                'entidad_proveedor' => 'proveedores',
                'id_proveedor' => $proveedorId,
                'entidad_relacion' => 'proveedor_productos',
                'id_relacion' => $proveedorId,
            ]);
            $historial = $stmt->fetchAll();
        }
    } else {
        $modo = 'listar';
        $errorGeneral = 'No se encontró el proveedor solicitado.';
    }
}

if ($modo === 'listar') {
    $where = [];
    $params = [];
    if ($estado === 'ACTIVOS') {
        $where[] = 'pr.activo = 1';
    } elseif ($estado === 'INACTIVOS') {
        $where[] = 'pr.activo = 0';
    }
    if ($busqueda !== '') {
        $where[] = '(pr.razon_social LIKE :razon OR pr.nombre_comercial LIKE :comercial
            OR pr.numero_documento LIKE :documento OR pr.contacto LIKE :contacto
            OR pr.telefono LIKE :telefono OR pr.correo LIKE :correo)';
        $term = '%' . $busqueda . '%';
        $params = [
            'razon' => $term,
            'comercial' => $term,
            'documento' => $term,
            'contacto' => $term,
            'telefono' => $term,
            'correo' => $term,
        ];
    }
    $condicion = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM proveedores pr $condicion");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    $paginaMaxima = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $paginaMaxima);
    $offset = ($pagina - 1) * $porPagina;
    $stmt = $pdo->prepare(
        "SELECT pr.proveedor_id, pr.tipo_documento, pr.numero_documento,
                pr.razon_social, pr.nombre_comercial, pr.contacto, pr.telefono,
                pr.correo, pr.activo,
                (SELECT COUNT(*) FROM proveedor_productos pp WHERE pp.proveedor_id = pr.proveedor_id) AS productos_vinculados
         FROM proveedores pr $condicion
         ORDER BY pr.activo DESC, pr.razon_social, pr.proveedor_id
         LIMIT $porPagina OFFSET $offset"
    );
    $stmt->execute($params);
    $proveedores = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Proveedores | Corozao</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<header class="navbar navbar-dark shadow-sm" style="background-color: #173b65">
    <div class="container-xxl">
        <a class="navbar-brand fw-semibold" href="/">Corozao</a>
        <span class="navbar-text text-white">Proveedores</span>
    </div>
</header>
<main class="container-xxl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h2 mb-1">Proveedores</h1>
            <p class="text-body-secondary mb-0">Administración y relación con productos</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="/">Volver al panel</a>
            <?php if ($modo === 'listar'): ?>
                <a class="btn btn-primary" href="/proveedores.php?accion=crear">Nuevo proveedor</a>
            <?php elseif ($modo !== 'crear'): ?>
                <a class="btn btn-outline-primary" href="/proveedores.php">Lista de proveedores</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$auditoriaDisponible): ?>
        <div class="alert alert-warning" role="alert">
            La tabla de auditoría aún no existe. Puedes consultar proveedores, pero las escrituras están bloqueadas.
            Revisa y aplica manualmente <code>migrations/20261020_create_auditoria_eventos.sql</code> después de respaldar la base.
        </div>
    <?php endif; ?>
    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['tipo'] ?? 'info') ?>" role="status"><?= h($flash['texto'] ?? '') ?></div>
    <?php endif; ?>
    <?php if ($errorGeneral !== null): ?>
        <div class="alert alert-danger" role="alert"><?= h($errorGeneral) ?></div>
    <?php endif; ?>
    <?php foreach ($errores as $mensaje): ?>
        <div class="alert alert-danger" role="alert"><?= h($mensaje) ?></div>
    <?php endforeach; ?>

    <?php if ($modo === 'crear' || $modo === 'editar'): ?>
        <section class="card shadow-sm">
            <div class="card-body p-3 p-md-4">
                <h2 class="h4 mb-3"><?= $modo === 'crear' ? 'Nuevo proveedor' : 'Editar proveedor' ?></h2>
                <form method="post" action="/proveedores.php">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="accion" value="guardar">
                    <?php if ($modo === 'editar' && $proveedorId): ?>
                        <input type="hidden" name="proveedor_id" value="<?= h($proveedorId) ?>">
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="tipo_documento">Tipo de documento</label>
                            <select class="form-select" id="tipo_documento" name="tipo_documento" required>
                                <?php foreach (['RUC', 'CEDULA', 'PASAPORTE', 'OTRO'] as $tipo): ?>
                                    <option value="<?= h($tipo) ?>" <?= $valores['tipo_documento'] === $tipo ? 'selected' : '' ?>><?= h($tipo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label" for="numero_documento">Número de documento</label>
                            <input class="form-control" id="numero_documento" name="numero_documento" maxlength="20" value="<?= h($valores['numero_documento']) ?>">
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="razon_social">Razón social <span class="text-danger">*</span></label>
                            <input class="form-control" id="razon_social" name="razon_social" maxlength="200" required value="<?= h($valores['razon_social']) ?>">
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="nombre_comercial">Nombre comercial</label>
                            <input class="form-control" id="nombre_comercial" name="nombre_comercial" maxlength="200" value="<?= h($valores['nombre_comercial']) ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="contacto">Persona de contacto</label>
                            <input class="form-control" id="contacto" name="contacto" maxlength="150" value="<?= h($valores['contacto']) ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="correo">Correo</label>
                            <input class="form-control" type="email" id="correo" name="correo" maxlength="150" value="<?= h($valores['correo']) ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="telefono">Teléfono</label>
                            <input class="form-control" id="telefono" name="telefono" maxlength="30" value="<?= h($valores['telefono']) ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="telefono_alternativo">Teléfono alternativo</label>
                            <input class="form-control" id="telefono_alternativo" name="telefono_alternativo" maxlength="30" value="<?= h($valores['telefono_alternativo']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="direccion">Dirección</label>
                            <input class="form-control" id="direccion" name="direccion" maxlength="300" value="<?= h($valores['direccion']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="observaciones">Observaciones</label>
                            <textarea class="form-control" id="observaciones" name="observaciones" rows="3"><?= h($valores['observaciones']) ?></textarea>
                            <div class="form-text">El historial no almacena el texto libre de observaciones. No incluyas contraseñas, tokens ni datos de acceso.</div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button class="btn btn-primary" type="submit" <?= !$auditoriaDisponible ? 'disabled' : '' ?>>Guardar proveedor</button>
                            <a class="btn btn-outline-secondary" href="/proveedores.php">Cancelar</a>
                        </div>
                    </div>
                </form>
            </div>
        </section>
    <?php elseif ($modo === 'ver' && $proveedor): ?>
        <section class="card shadow-sm mb-4">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                            <h2 class="h3 mb-0"><?= h($proveedor['razon_social']) ?></h2>
                            <span class="badge text-bg-<?= (int) $proveedor['activo'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $proveedor['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span>
                        </div>
                        <p class="text-body-secondary mb-0">Proveedor #<?= h($proveedor['proveedor_id']) ?></p>
                    </div>
                    <?php if ((int) $proveedor['activo'] === 1): ?>
                        <div class="d-flex gap-2">
                            <a class="btn btn-outline-primary" href="/proveedores.php?accion=editar&amp;id=<?= h($proveedor['proveedor_id']) ?>">Editar</a>
                            <form method="post" action="/proveedores.php" onsubmit="return confirm('¿Desactivar este proveedor? Sus vínculos con productos se conservarán.');">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="accion" value="desactivar">
                                <input type="hidden" name="proveedor_id" value="<?= h($proveedor['proveedor_id']) ?>">
                                <button class="btn btn-outline-danger" type="submit" <?= !$auditoriaDisponible ? 'disabled' : '' ?>>Desactivar</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
                <hr>
                <dl class="row mb-0">
                    <dt class="col-sm-4 col-lg-3">Documento</dt><dd class="col-sm-8 col-lg-9"><?= h($proveedor['tipo_documento']) ?><?= $proveedor['numero_documento'] ? ' · ' . h($proveedor['numero_documento']) : '' ?></dd>
                    <dt class="col-sm-4 col-lg-3">Nombre comercial</dt><dd class="col-sm-8 col-lg-9"><?= h($proveedor['nombre_comercial'] ?? '—') ?></dd>
                    <dt class="col-sm-4 col-lg-3">Contacto</dt><dd class="col-sm-8 col-lg-9"><?= h($proveedor['contacto'] ?? '—') ?></dd>
                    <dt class="col-sm-4 col-lg-3">Teléfono</dt><dd class="col-sm-8 col-lg-9"><?= h($proveedor['telefono'] ?? '—') ?><?= $proveedor['telefono_alternativo'] ? ' · ' . h($proveedor['telefono_alternativo']) : '' ?></dd>
                    <dt class="col-sm-4 col-lg-3">Correo</dt><dd class="col-sm-8 col-lg-9"><?= h($proveedor['correo'] ?? '—') ?></dd>
                    <dt class="col-sm-4 col-lg-3">Dirección</dt><dd class="col-sm-8 col-lg-9"><?= h($proveedor['direccion'] ?? '—') ?></dd>
                    <dt class="col-sm-4 col-lg-3">Observaciones</dt><dd class="col-sm-8 col-lg-9 mb-0"><?= nl2br(h($proveedor['observaciones'] ?? '—')) ?></dd>
                </dl>
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-body p-3 p-md-4">
                <h3 class="h5">Productos relacionados</h3>
                <div class="table-responsive mb-4">
                    <table class="table table-striped table-hover align-middle">
                        <thead><tr><th>Producto</th><th>Código del proveedor</th><th>Preferido</th><th>Plazo</th><th>Observaciones</th></tr></thead>
                        <tbody>
                        <?php foreach ($productosRelacionados as $relacion): ?>
                            <tr>
                                <td><?= h($relacion['producto_nombre']) ?><?= $relacion['marca'] ? ' · ' . h($relacion['marca']) : '' ?><?= (int) $relacion['producto_activo'] === 0 ? ' (inactivo)' : '' ?></td>
                                <td><?= h($relacion['codigo_producto_proveedor'] ?? '—') ?></td>
                                <td><?= (int) $relacion['es_proveedor_preferido'] === 1 ? 'Sí' : 'No' ?></td>
                                <td><?= $relacion['plazo_entrega_dias'] === null ? '—' : h($relacion['plazo_entrega_dias']) . ' días' ?></td>
                                <td><?= h($relacion['observaciones'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$productosRelacionados): ?><tr><td colspan="5" class="text-body-secondary">No hay productos relacionados.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ((int) $proveedor['activo'] === 1): ?>
                    <h4 class="h6">Relacionar un producto existente</h4>
                    <form method="post" action="/proveedores.php?accion=ver&amp;id=<?= h($proveedor['proveedor_id']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="accion" value="relacionar">
                        <input type="hidden" name="proveedor_id" value="<?= h($proveedor['proveedor_id']) ?>">
                        <div class="row g-3 align-items-end">
                            <div class="col-12 col-lg-5">
                                <label class="form-label" for="producto_id">Producto</label>
                                <select class="form-select" id="producto_id" name="producto_id" required>
                                    <option value="">Selecciona</option>
                                    <?php foreach ($productosDisponibles as $productoOpcion): ?>
                                        <option value="<?= h($productoOpcion['producto_id']) ?>"><?= h($productoOpcion['nombre']) ?><?= $productoOpcion['marca'] ? ' · ' . h($productoOpcion['marca']) : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6 col-lg-3">
                                <label class="form-label" for="codigo_producto_proveedor">Código del proveedor</label>
                                <input class="form-control" id="codigo_producto_proveedor" name="codigo_producto_proveedor" maxlength="100">
                            </div>
                            <div class="col-12 col-md-6 col-lg-2">
                                <label class="form-label" for="plazo_entrega_dias">Plazo (días)</label>
                                <input class="form-control" type="number" min="0" max="65535" id="plazo_entrega_dias" name="plazo_entrega_dias">
                            </div>
                            <div class="col-12 col-lg-2">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="es_proveedor_preferido" name="es_proveedor_preferido" value="1">
                                    <label class="form-check-label" for="es_proveedor_preferido">Preferido</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="observaciones_relacion">Observaciones de la relación</label>
                                <input class="form-control" id="observaciones_relacion" name="observaciones_relacion" maxlength="500">
                            </div>
                            <div class="col-12"><button class="btn btn-primary" type="submit" <?= !$auditoriaDisponible || !$productosDisponibles ? 'disabled' : '' ?>>Relacionar producto</button></div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="card shadow-sm">
            <div class="card-body p-3 p-md-4">
                <h3 class="h5">Historial de cambios</h3>
                <?php if (!$auditoriaDisponible): ?>
                    <p class="text-body-secondary mb-0">Disponible cuando se aplique la migración de auditoría.</p>
                <?php elseif (!$historial): ?>
                    <p class="text-body-secondary mb-0">No hay eventos registrados para este proveedor.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>Valores anteriores</th><th>Valores posteriores</th></tr></thead>
                            <tbody>
                            <?php foreach ($historial as $evento): ?>
                                <?php
                                $antes = $evento['valores_anteriores'] !== null ? json_decode($evento['valores_anteriores'], true) : null;
                                $despues = $evento['valores_posteriores'] !== null ? json_decode($evento['valores_posteriores'], true) : null;
                                ?>
                                <tr>
                                    <td><?= h($evento['fecha_hora']) ?></td>
                                    <td><?= h($evento['usuario_nombre']) ?></td>
                                    <td><?= h($evento['accion']) ?></td>
                                    <td><?= h($evento['entidad']) ?> #<?= h($evento['registro_id']) ?></td>
                                    <td><pre class="small mb-0 text-wrap"><?= h($antes === null ? '—' : json_encode($antes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></td>
                                    <td><pre class="small mb-0 text-wrap"><?= h($despues === null ? '—' : json_encode($despues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="card shadow-sm mb-4">
            <div class="card-body p-3 p-md-4">
                <form method="get" action="/proveedores.php">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-lg-7">
                            <label class="form-label" for="q">Buscar proveedor</label>
                            <input class="form-control" id="q" name="q" maxlength="100" value="<?= h($busqueda) ?>" placeholder="Razón social, documento, contacto, teléfono o correo">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label" for="estado">Estado</label>
                            <select class="form-select" id="estado" name="estado">
                                <option value="ACTIVOS" <?= $estado === 'ACTIVOS' ? 'selected' : '' ?>>Activos</option>
                                <option value="INACTIVOS" <?= $estado === 'INACTIVOS' ? 'selected' : '' ?>>Inactivos</option>
                                <option value="TODOS" <?= $estado === 'TODOS' ? 'selected' : '' ?>>Todos</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-2"><button class="btn btn-primary w-100" type="submit">Buscar</button></div>
                    </div>
                </form>
            </div>
        </section>
        <section class="card shadow-sm">
            <div class="card-body p-3 p-md-4">
                <p class="text-body-secondary"><strong><?= h($total) ?></strong> proveedor(es) · hasta <?= h($porPagina) ?> por página</p>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead><tr><th>Proveedor</th><th>Documento</th><th>Contacto</th><th>Productos</th><th>Estado</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($proveedores as $fila): ?>
                            <tr>
                                <td><strong><?= h($fila['razon_social']) ?></strong><?php if ($fila['nombre_comercial']): ?><br><small class="text-body-secondary"><?= h($fila['nombre_comercial']) ?></small><?php endif; ?></td>
                                <td><?= h($fila['tipo_documento']) ?><?= $fila['numero_documento'] ? '<br>' . h($fila['numero_documento']) : '' ?></td>
                                <td><?= h($fila['contacto'] ?? '—') ?><?php if ($fila['telefono']): ?><br><?= h($fila['telefono']) ?><?php endif; ?></td>
                                <td><?= h($fila['productos_vinculados']) ?></td>
                                <td><span class="badge text-bg-<?= (int) $fila['activo'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $fila['activo'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="/proveedores.php?accion=ver&amp;id=<?= h($fila['proveedor_id']) ?>">Ver</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$proveedores): ?><tr><td colspan="6" class="text-body-secondary">No hay proveedores para estos filtros.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($paginaMaxima > 1): ?>
                    <nav class="d-flex flex-wrap align-items-center gap-3" aria-label="Paginación">
                        <?php if ($pagina > 1): ?><a class="btn btn-sm btn-outline-primary" href="?<?= h(http_build_query(['q' => $busqueda, 'estado' => $estado, 'pagina' => $pagina - 1])) ?>">← Anterior</a><?php endif; ?>
                        <span>Página <?= h($pagina) ?> de <?= h($paginaMaxima) ?></span>
                        <?php if ($pagina < $paginaMaxima): ?><a class="btn btn-sm btn-outline-primary" href="?<?= h(http_build_query(['q' => $busqueda, 'estado' => $estado, 'pagina' => $pagina + 1])) ?>">Siguiente →</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
