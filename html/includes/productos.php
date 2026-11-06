<?php
declare(strict_types=1);

function corozao_producto_texto(mixed $valor, string $campo, int $maximo, bool $requerido = false): ?string
{
    if (!is_string($valor)) {
        throw new InvalidArgumentException("El campo {$campo} no es válido.");
    }
    $valor = trim($valor);
    $longitud = preg_match_all('/./us', $valor);
    if ($longitud === false || ($requerido && $valor === '') || $longitud > $maximo) {
        throw new InvalidArgumentException("Revisa el campo {$campo}; debe tener máximo {$maximo} caracteres.");
    }
    return $valor === '' ? null : $valor;
}

function corozao_producto_categoria(PDO $pdo, mixed $id): ?int
{
    if ($id === '' || $id === null) {
        return null;
    }
    $categoriaId = filter_var($id, FILTER_VALIDATE_INT);
    if (!$categoriaId || $categoriaId < 1) {
        throw new InvalidArgumentException('Selecciona una categoría válida.');
    }
    $stmt = $pdo->prepare('SELECT 1 FROM categorias WHERE categoria_id = :id');
    $stmt->execute(['id' => $categoriaId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('La categoría seleccionada no existe.');
    }
    return $categoriaId;
}

function corozao_producto_linea_presentacion(array $entrada): ?array
{
    $nombre = corozao_producto_texto($entrada['presentacion_nombre'] ?? '', 'presentación', 100);
    $unidades = trim((string)($entrada['unidades_base'] ?? ''));
    $codigo = corozao_producto_texto($entrada['codigo'] ?? '', 'código', 100);
    $tipoCodigo = corozao_producto_texto($entrada['tipo_codigo'] ?? 'VENTA', 'tipo de código', 30, true);
    if ($nombre === null && $unidades === '' && $codigo === null) {
        return null;
    }
    if ($nombre === null || !preg_match('/^\d{1,9}$/D', $unidades) || (float)$unidades <= 0) {
        throw new InvalidArgumentException('Completa la presentación y sus unidades base con un valor mayor que cero.');
    }
    return [
        'nombre' => $nombre,
        'unidades_base' => number_format((float)$unidades, 0, '.', ''),
        'codigo' => $codigo,
        'tipo_codigo' => $tipoCodigo ?? 'VENTA',
    ];
}

function corozao_producto_insertar_presentacion(PDO $pdo, int $productoId, array $linea): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO presentaciones (producto_id, nombre, unidades_base)
         VALUES (:producto, :nombre, :unidades)'
    );
    $stmt->execute([
        'producto' => $productoId,
        'nombre' => $linea['nombre'],
        'unidades' => $linea['unidades_base'],
    ]);
    $presentacionId = (int)$pdo->lastInsertId();
    if ($linea['codigo'] !== null) {
        $stmt = $pdo->prepare(
            'INSERT INTO producto_codigos (presentacion_id, codigo, tipo_codigo)
             VALUES (:presentacion, :codigo, :tipo)'
        );
        $stmt->execute([
            'presentacion' => $presentacionId,
            'codigo' => $linea['codigo'],
            'tipo' => $linea['tipo_codigo'],
        ]);
    }
    return $presentacionId;
}

function corozao_producto_crear(PDO $pdo, int $usuarioId, array $entrada): int
{
    $nombre = corozao_producto_texto($entrada['nombre'] ?? '', 'nombre', 200, true);
    $marca = corozao_producto_texto($entrada['marca'] ?? '', 'marca', 150);
    $contenido = corozao_producto_texto($entrada['contenido'] ?? '', 'contenido', 100);
    $proveedorReferencia = corozao_producto_texto($entrada['proveedor_referencial'] ?? '', 'proveedor de referencia', 200);
    $unidad = corozao_producto_texto($entrada['unidad_base'] ?? 'unidad', 'unidad base', 30, true);
    $categoriaId = corozao_producto_categoria($pdo, $entrada['categoria_id'] ?? null);
    $estadoComercial = $entrada['estado_comercial'] ?? 'PENDIENTE_REVISION';
    if (!in_array($estadoComercial, ['PENDIENTE_REVISION', 'VALIDADO'], true)) {
        throw new InvalidArgumentException('Estado comercial no válido.');
    }
    $ventaHabilitada = filter_var($entrada['venta_habilitada'] ?? '0', FILTER_VALIDATE_INT);
    if (!in_array($ventaHabilitada, [0, 1], true)) {
        throw new InvalidArgumentException('La habilitación de venta no es válida.');
    }
    $presentacion = corozao_producto_linea_presentacion($entrada);
    if ($presentacion === null) {
        throw new InvalidArgumentException('Registra al menos una presentación para el producto.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO productos
                (categoria_id, nombre, marca, contenido, proveedor_referencial, unidad_base, estado_comercial, venta_habilitada, activo)
             VALUES (:categoria, :nombre, :marca, :contenido, :proveedor, :unidad, :estado, :venta, 1)'
        );
        $stmt->execute([
            'categoria' => $categoriaId,
            'nombre' => $nombre,
            'marca' => $marca,
            'contenido' => $contenido,
            'proveedor' => $proveedorReferencia,
            'unidad' => $unidad,
            'estado' => $estadoComercial,
            'venta' => $ventaHabilitada,
        ]);
        $productoId = (int)$pdo->lastInsertId();
        $presentacionId = corozao_producto_insertar_presentacion($pdo, $productoId, $presentacion);
        corozao_registrar_auditoria(
            $pdo,
            $usuarioId,
            'CREAR',
            'productos',
            $productoId,
            null,
            [
                'nombre' => $nombre,
                'marca' => $marca,
                'contenido' => $contenido,
                'proveedor_referencial' => $proveedorReferencia,
                'unidad_base' => $unidad,
                'categoria_id' => $categoriaId,
                'estado_comercial' => $estadoComercial,
                'venta_habilitada' => $ventaHabilitada,
                'presentacion_id' => $presentacionId,
                'presentacion' => $presentacion['nombre'],
                'codigo' => $presentacion['codigo'],
            ]
        );
        $pdo->commit();
        return $productoId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new RuntimeException('El nombre de presentación o el código ya está registrado.');
        }
        throw $e;
    }
}

function corozao_producto_obtener(PDO $pdo, int $productoId, bool $bloquear = false): array
{
    $stmt = $pdo->prepare(
        'SELECT producto_id, categoria_id, nombre, marca, contenido, proveedor_referencial, unidad_base,
                estado_comercial, venta_habilitada, activo
         FROM productos WHERE producto_id = :id' . ($bloquear ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['id' => $productoId]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$producto) {
        throw new RuntimeException('No se encontró el producto.');
    }
    return $producto;
}

function corozao_producto_actualizar(PDO $pdo, int $usuarioId, int $productoId, array $entrada): void
{
    $nombre = corozao_producto_texto($entrada['nombre'] ?? '', 'nombre', 200, true);
    $marca = corozao_producto_texto($entrada['marca'] ?? '', 'marca', 150);
    $contenido = corozao_producto_texto($entrada['contenido'] ?? '', 'contenido', 100);
    $proveedorReferencia = corozao_producto_texto($entrada['proveedor_referencial'] ?? '', 'proveedor de referencia', 200);
    $unidad = corozao_producto_texto($entrada['unidad_base'] ?? '', 'unidad base', 30, true);
    $categoriaId = corozao_producto_categoria($pdo, $entrada['categoria_id'] ?? null);
    $estadoComercial = $entrada['estado_comercial'] ?? '';
    if (!in_array($estadoComercial, ['PENDIENTE_REVISION', 'VALIDADO'], true)) {
        throw new InvalidArgumentException('Estado comercial no válido.');
    }
    $ventaHabilitada = filter_var($entrada['venta_habilitada'] ?? null, FILTER_VALIDATE_INT);
    if (!in_array($ventaHabilitada, [0, 1], true)) {
        throw new InvalidArgumentException('La habilitación de venta no es válida.');
    }

    $pdo->beginTransaction();
    try {
        $anterior = corozao_producto_obtener($pdo, $productoId, true);
        $posterior = [
            'categoria_id' => $categoriaId,
            'nombre' => $nombre,
            'marca' => $marca,
            'contenido' => $contenido,
            'proveedor_referencial' => $proveedorReferencia,
            'unidad_base' => $unidad,
            'estado_comercial' => $estadoComercial,
            'venta_habilitada' => $ventaHabilitada,
            'activo' => (int)$anterior['activo'],
        ];
        $stmt = $pdo->prepare(
            'UPDATE productos SET categoria_id = :categoria, nombre = :nombre, marca = :marca,
                    contenido = :contenido, proveedor_referencial = :proveedor_referencial,
                    unidad_base = :unidad, estado_comercial = :estado,
                    venta_habilitada = :venta
             WHERE producto_id = :id'
        );
        $stmt->execute([
            'categoria' => $categoriaId,
            'nombre' => $nombre,
            'marca' => $marca,
            'contenido' => $contenido,
            'proveedor_referencial' => $proveedorReferencia,
            'unidad' => $unidad,
            'estado' => $estadoComercial,
            'venta' => $ventaHabilitada,
            'id' => $productoId,
        ]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'productos', $productoId, $anterior, $posterior);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function corozao_producto_cambiar_estado(PDO $pdo, int $usuarioId, int $productoId, bool $activo): void
{
    $pdo->beginTransaction();
    try {
        $anterior = corozao_producto_obtener($pdo, $productoId, true);
        $stmt = $pdo->prepare('UPDATE productos SET activo = :activo WHERE producto_id = :id');
        $stmt->execute(['activo' => $activo ? 1 : 0, 'id' => $productoId]);
        $posterior = $anterior;
        $posterior['activo'] = $activo ? 1 : 0;
        corozao_registrar_auditoria(
            $pdo,
            $usuarioId,
            $activo ? 'MODIFICAR' : 'DESACTIVAR',
            'productos',
            $productoId,
            $anterior,
            $posterior
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function corozao_producto_agregar_presentacion(PDO $pdo, int $usuarioId, int $productoId, array $entrada): int
{
    $linea = corozao_producto_linea_presentacion($entrada);
    if ($linea === null) {
        throw new InvalidArgumentException('Completa los datos de la presentación.');
    }
    $pdo->beginTransaction();
    try {
        corozao_producto_obtener($pdo, $productoId, true);
        $presentacionId = corozao_producto_insertar_presentacion($pdo, $productoId, $linea);
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'presentaciones', $presentacionId, null, [
            'producto_id' => $productoId,
            'nombre' => $linea['nombre'],
            'unidades_base' => $linea['unidades_base'],
            'codigo' => $linea['codigo'],
            'tipo_codigo' => $linea['tipo_codigo'],
        ]);
        $pdo->commit();
        return $presentacionId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new RuntimeException('El nombre de presentación o el código ya está registrado.');
        }
        throw $e;
    }
}

function corozao_producto_agregar_codigo(PDO $pdo, int $usuarioId, int $productoId, int $presentacionId, array $entrada): void
{
    $codigo = corozao_producto_texto($entrada['codigo'] ?? '', 'código', 100, true);
    $tipo = corozao_producto_texto($entrada['tipo_codigo'] ?? 'VENTA', 'tipo de código', 30, true);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT presentacion_id FROM presentaciones
             WHERE presentacion_id = :presentacion AND producto_id = :producto FOR UPDATE'
        );
        $stmt->execute(['presentacion' => $presentacionId, 'producto' => $productoId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('La presentación no pertenece al producto indicado.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO producto_codigos (presentacion_id, codigo, tipo_codigo)
             VALUES (:presentacion, :codigo, :tipo)'
        );
        $stmt->execute(['presentacion' => $presentacionId, 'codigo' => $codigo, 'tipo' => $tipo]);
        $codigoId = (int)$pdo->lastInsertId();
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'producto_codigos', $codigoId, null, [
            'producto_id' => $productoId,
            'presentacion_id' => $presentacionId,
            'codigo' => $codigo,
            'tipo_codigo' => $tipo,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new RuntimeException('Ese código ya está asociado a otro producto o presentación.');
        }
        throw $e;
    }
}

function corozao_producto_cambiar_estado_presentacion(PDO $pdo, int $usuarioId, int $productoId, int $presentacionId, bool $activo): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT presentacion_id, producto_id, nombre, unidades_base, activo
             FROM presentaciones WHERE presentacion_id = :presentacion AND producto_id = :producto FOR UPDATE'
        );
        $stmt->execute(['presentacion' => $presentacionId, 'producto' => $productoId]);
        $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$anterior) {
            throw new RuntimeException('No se encontró la presentación.');
        }
        $stmt = $pdo->prepare('UPDATE presentaciones SET activo = :activo WHERE presentacion_id = :id');
        $stmt->execute(['activo' => $activo ? 1 : 0, 'id' => $presentacionId]);
        $posterior = $anterior;
        $posterior['activo'] = $activo ? 1 : 0;
        corozao_registrar_auditoria(
            $pdo,
            $usuarioId,
            $activo ? 'MODIFICAR' : 'DESACTIVAR',
            'presentaciones',
            $presentacionId,
            $anterior,
            $posterior
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
