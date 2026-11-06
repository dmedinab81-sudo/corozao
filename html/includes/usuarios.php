<?php
declare(strict_types=1);

function corozao_usuario_entrada_texto(mixed $valor, string $campo, int $maximo): string
{
    if (!is_string($valor)) {
        throw new InvalidArgumentException("El campo {$campo} no es válido.");
    }
    $valor = trim($valor);
    $longitud = preg_match_all('/./us', $valor);
    if ($valor === '' || $longitud === false || $longitud > $maximo) {
        throw new InvalidArgumentException("El campo {$campo} es obligatorio y admite máximo {$maximo} caracteres.");
    }
    return $valor;
}

function corozao_usuario_rol_validar(mixed $rol): string
{
    if (!is_string($rol) || !in_array($rol, ['ADMINISTRADOR', 'CAJERO', 'MESERO'], true)) {
        throw new InvalidArgumentException('Selecciona un rol válido.');
    }
    return $rol;
}

function corozao_usuario_activo_validar(mixed $activo): int
{
    $valor = filter_var($activo, FILTER_VALIDATE_INT);
    if (!in_array($valor, [0, 1], true)) {
        throw new InvalidArgumentException('Selecciona un estado válido.');
    }
    return $valor;
}

function corozao_usuario_password_validar(mixed $password, mixed $confirmacion): string
{
    if (!is_string($password) || !is_string($confirmacion)) {
        throw new InvalidArgumentException('La contraseña no es válida.');
    }
    $longitud = preg_match_all('/./us', $password);
    if ($password !== $confirmacion || $longitud === false || $longitud < 12 || strlen($password) > 72) {
        throw new InvalidArgumentException('La contraseña debe coincidir con su confirmación, tener al menos 12 caracteres y no superar 72 bytes.');
    }
    return $password;
}

function corozao_usuarios_bloquear_para_administrar(PDO $pdo): void
{
    // Serializa cambios de rol/estado para impedir que operaciones concurrentes
    // dejen el sistema sin ningún administrador activo.
    $pdo->query('SELECT usuario_id FROM usuarios ORDER BY usuario_id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
}

function corozao_usuario_crear(PDO $pdo, int $actorId, array $entrada): int
{
    $nombre = corozao_usuario_entrada_texto($entrada['nombre_completo'] ?? null, 'nombre completo', 150);
    $usuario = corozao_usuario_entrada_texto($entrada['nombre_usuario'] ?? null, 'nombre de usuario', 60);
    $rol = corozao_usuario_rol_validar($entrada['rol'] ?? null);
    $activo = corozao_usuario_activo_validar($entrada['activo'] ?? null);
    $password = corozao_usuario_password_validar($entrada['password'] ?? null, $entrada['password_confirmacion'] ?? null);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('No se pudo proteger la contraseña.');
    }

    $pdo->beginTransaction();
    try {
        corozao_usuarios_bloquear_para_administrar($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nombre_completo, nombre_usuario, password_hash, rol, activo)
             VALUES (:nombre, :usuario, :password, :rol, :activo)'
        );
        $stmt->execute([
            'nombre' => $nombre,
            'usuario' => $usuario,
            'password' => $hash,
            'rol' => $rol,
            'activo' => $activo,
        ]);
        $id = (int)$pdo->lastInsertId();
        corozao_registrar_auditoria($pdo, $actorId, 'CREAR', 'usuarios', $id, null, [
            'nombre_completo' => $nombre,
            'nombre_usuario' => $usuario,
            'rol' => $rol,
            'activo' => $activo,
        ]);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new RuntimeException('Ese nombre de usuario ya está registrado.');
        }
        throw $e;
    }
}

function corozao_usuario_obtener_para_editar(PDO $pdo, int $id, bool $bloquear = false): array
{
    $stmt = $pdo->prepare(
        'SELECT usuario_id, nombre_completo, nombre_usuario, rol, activo, fecha_creacion
         FROM usuarios WHERE usuario_id = :id' . ($bloquear ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        throw new RuntimeException('No se encontró el usuario.');
    }
    return $fila;
}

function corozao_usuario_actualizar(PDO $pdo, int $actorId, int $id, array $entrada): void
{
    $nombre = corozao_usuario_entrada_texto($entrada['nombre_completo'] ?? null, 'nombre completo', 150);
    $usuario = corozao_usuario_entrada_texto($entrada['nombre_usuario'] ?? null, 'nombre de usuario', 60);
    $rol = corozao_usuario_rol_validar($entrada['rol'] ?? null);
    $activo = corozao_usuario_activo_validar($entrada['activo'] ?? null);

    $pdo->beginTransaction();
    try {
        corozao_usuarios_bloquear_para_administrar($pdo);
        $anterior = corozao_usuario_obtener_para_editar($pdo, $id, true);
        $eraAdministradorActivo = $anterior['rol'] === 'ADMINISTRADOR' && (int)$anterior['activo'] === 1;
        $seraAdministradorActivo = $rol === 'ADMINISTRADOR' && $activo === 1;
        if ($eraAdministradorActivo && !$seraAdministradorActivo) {
            $total = (int)$pdo->query(
                "SELECT COUNT(*) FROM usuarios WHERE rol = 'ADMINISTRADOR' AND activo = 1"
            )->fetchColumn();
            if ($total <= 1) {
                throw new RuntimeException('No se puede desactivar ni cambiar el rol del último administrador activo.');
            }
        }

        $posterior = [
            'nombre_completo' => $nombre,
            'nombre_usuario' => $usuario,
            'rol' => $rol,
            'activo' => $activo,
        ];
        $stmt = $pdo->prepare(
            'UPDATE usuarios SET nombre_completo = :nombre, nombre_usuario = :usuario,
                    rol = :rol, activo = :activo
             WHERE usuario_id = :id'
        );
        $stmt->execute([
            'nombre' => $nombre,
            'usuario' => $usuario,
            'rol' => $rol,
            'activo' => $activo,
            'id' => $id,
        ]);
        $accion = $activo === 0 && (int)$anterior['activo'] === 1 ? 'DESACTIVAR' : 'MODIFICAR';
        corozao_registrar_auditoria($pdo, $actorId, $accion, 'usuarios', $id, $anterior, $posterior);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new RuntimeException('Ese nombre de usuario ya está registrado.');
        }
        throw $e;
    }
}

function corozao_usuario_cambiar_password(PDO $pdo, int $actorId, int $id, array $entrada): void
{
    $password = corozao_usuario_password_validar($entrada['password'] ?? null, $entrada['password_confirmacion'] ?? null);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('No se pudo proteger la contraseña.');
    }
    $pdo->beginTransaction();
    try {
        corozao_usuarios_bloquear_para_administrar($pdo);
        corozao_usuario_obtener_para_editar($pdo, $id, true);
        $stmt = $pdo->prepare('UPDATE usuarios SET password_hash = :password WHERE usuario_id = :id');
        $stmt->execute(['password' => $hash, 'id' => $id]);
        corozao_registrar_auditoria(
            $pdo,
            $actorId,
            'MODIFICAR',
            'usuarios',
            $id,
            null,
            ['password_cambiada' => true]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
