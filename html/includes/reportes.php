<?php
declare(strict_types=1);

function corozao_reportes_instalados(PDO $pdo): bool
{
    $nombres = ['caja_conciliaciones', 'conteos_inventario', 'conteo_inventario_detalles'];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_TYPE=\'BASE TABLE\' AND TABLE_NAME IN (' . implode(',', array_fill(0, count($nombres), '?')) . ')');
    $stmt->execute($nombres);
    return (int) $stmt->fetchColumn() === count($nombres);
}

function corozao_conteo_milesimas(string $valor): int
{
    $valor = trim($valor);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})$/', $valor)) {
        throw new DomainException('La cantidad física debe ser un número entero no negativo.');
    }
    return (int) $valor * 1000;
}

/** Convierte saldos DECIMAL existentes; la entrada física del usuario sigue siendo entera. */
function corozao_conteo_saldo_milesimas(string $valor): int
{
    if (!preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,3}))?$/D', $valor, $partes)) {
        throw new DomainException('El saldo teórico tiene un formato no válido.');
    }
    return (int) $partes[1] * 1000 + (int) str_pad($partes[2] ?? '', 3, '0');
}

function corozao_conteo_detalle_bloquear(PDO $pdo, int $detalleId): array
{
    $stmt = $pdo->prepare('SELECT conteo_id FROM conteo_inventario_detalles WHERE conteo_detalle_id=:detalle');
    $stmt->execute(['detalle' => $detalleId]);
    $conteoId = $stmt->fetchColumn();
    if (!$conteoId) throw new DomainException('La lectura del conteo no existe.');
    $stmt = $pdo->prepare('SELECT estado FROM conteos_inventario WHERE conteo_id=:conteo FOR UPDATE');
    $stmt->execute(['conteo' => $conteoId]);
    $estado = $stmt->fetchColumn();
    if (!in_array($estado, ['ABIERTO', 'CERRADO'], true)) throw new DomainException('La sesión de conteo no existe.');
    $stmt = $pdo->prepare('SELECT conteo_detalle_id,conteo_id,producto_id,cantidad_teorica,cantidad_fisica,diferencia
        FROM conteo_inventario_detalles WHERE conteo_detalle_id=:detalle AND conteo_id=:conteo FOR UPDATE');
    $stmt->execute(['detalle' => $detalleId, 'conteo' => $conteoId]);
    $detalle = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$detalle) throw new DomainException('La lectura del conteo ya no está disponible.');
    $detalle['estado_conteo'] = $estado;
    return $detalle;
}

function corozao_conteo_detalle_cargado(PDO $pdo, int $detalleId): bool
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'cargas_inventario_inicial_detalles\'');
    if ((int) $stmt->fetchColumn() !== 1) return false;
    $stmt = $pdo->prepare('SELECT 1 FROM cargas_inventario_inicial_detalles WHERE conteo_detalle_id=:detalle LIMIT 1');
    $stmt->execute(['detalle' => $detalleId]);
    return $stmt->fetchColumn() !== false;
}

function corozao_conteo_detalle_modificar(PDO $pdo, int $detalleId, int $conteoId, int $usuarioId, string $fisico): void
{
    $usuario = corozao_exigir_permiso($pdo, 'inventory.physical_count.modify');
    if ($detalleId < 1 || $conteoId < 1 || $usuarioId !== (int) $usuario['usuario_id']) throw new DomainException('La solicitud no es válida.');
    $fisicoM = corozao_conteo_milesimas($fisico);
    $pdo->beginTransaction();
    try {
        $detalle = corozao_conteo_detalle_bloquear($pdo, $detalleId);
        if ((int) $detalle['conteo_id'] !== $conteoId) throw new DomainException('La lectura no pertenece a este conteo.');
        if (corozao_conteo_detalle_cargado($pdo, $detalleId)) {
            throw new DomainException('La lectura ya se usó en una carga inicial y no puede modificarse.');
        }
        $teoricoM = corozao_conteo_saldo_milesimas((string) $detalle['cantidad_teorica']);
        $nuevaDiferencia = corozao_conteo_formato($fisicoM - $teoricoM);
        $stmt = $pdo->prepare('UPDATE conteo_inventario_detalles SET cantidad_fisica=:fisico,diferencia=:diferencia
            WHERE conteo_detalle_id=:detalle');
        $stmt->execute(['fisico' => corozao_conteo_formato($fisicoM), 'diferencia' => $nuevaDiferencia, 'detalle' => $detalleId]);
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'conteo_inventario_detalles', $detalleId,
            ['conteo_id' => (int) $detalle['conteo_id'], 'producto_id' => (int) $detalle['producto_id'],
                'cantidad_teorica' => (string) $detalle['cantidad_teorica'], 'cantidad_fisica' => (string) $detalle['cantidad_fisica'],
                'diferencia' => (string) $detalle['diferencia']],
            ['cantidad_fisica' => corozao_conteo_formato($fisicoM), 'diferencia' => $nuevaDiferencia]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function corozao_conteo_detalle_eliminar(PDO $pdo, int $detalleId, int $conteoId, int $usuarioId): void
{
    $usuario = corozao_exigir_permiso($pdo, 'inventory.physical_count.remove');
    if ($detalleId < 1 || $conteoId < 1 || $usuarioId !== (int) $usuario['usuario_id']) throw new DomainException('La solicitud no es válida.');
    $pdo->beginTransaction();
    try {
        $detalle = corozao_conteo_detalle_bloquear($pdo, $detalleId);
        if ((int) $detalle['conteo_id'] !== $conteoId) throw new DomainException('La lectura no pertenece a este conteo.');
        if (corozao_conteo_detalle_cargado($pdo, $detalleId)) {
            throw new DomainException('La lectura ya se usó en una carga inicial y no puede retirarse.');
        }
        corozao_registrar_auditoria($pdo, $usuarioId, 'DESACTIVAR', 'conteo_inventario_detalles', $detalleId,
            ['conteo_id' => (int) $detalle['conteo_id'], 'producto_id' => (int) $detalle['producto_id'],
                'cantidad_teorica' => (string) $detalle['cantidad_teorica'], 'cantidad_fisica' => (string) $detalle['cantidad_fisica'],
                'diferencia' => (string) $detalle['diferencia']], ['estado' => 'RETIRADO_DEL_CONTEO']);
        $stmt = $pdo->prepare('DELETE FROM conteo_inventario_detalles WHERE conteo_detalle_id=:detalle');
        $stmt->execute(['detalle' => $detalleId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('No se pudo retirar la lectura del conteo.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function corozao_conteo_formato(int $milesimas): string
{
    $absolutas = abs($milesimas);
    return ($milesimas < 0 ? '-' : '') . intdiv($absolutas, 1000) . '.'
        . str_pad((string) ($absolutas % 1000), 3, '0', STR_PAD_LEFT);
}

function corozao_conteo_abrir(PDO $pdo, int $bodegaId, int $usuarioId): int
{
    if ($bodegaId < 1 || $usuarioId < 1) throw new DomainException('Bodega o usuario no válidos.');
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT bodega_id FROM bodegas WHERE bodega_id=:bodega AND activo=1 FOR UPDATE');
        $stmt->execute(['bodega' => $bodegaId]);
        if (!$stmt->fetchColumn()) throw new DomainException('La bodega no existe o está inactiva.');
        $stmt = $pdo->prepare('SELECT conteo_id FROM conteos_inventario WHERE bodega_id=:bodega AND estado=\'ABIERTO\' FOR UPDATE');
        $stmt->execute(['bodega' => $bodegaId]);
        if ($stmt->fetchColumn()) throw new DomainException('Ya existe un conteo abierto para esta bodega.');
        $stmt = $pdo->prepare('INSERT INTO conteos_inventario (bodega_id, estado, usuario_id) VALUES (:bodega, \'ABIERTO\', :usuario)');
        $stmt->execute(['bodega' => $bodegaId, 'usuario' => $usuarioId]);
        $id = (int) $pdo->lastInsertId();
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'conteos_inventario', $id, null,
            ['estado' => 'ABIERTO', 'bodega_id' => $bodegaId]);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') throw new DomainException('Ya existe un conteo abierto para esta bodega.');
        throw $e;
    }
}

/** Registra una lectura inmutable y captura el saldo teórico en ese instante. */
function corozao_conteo_registrar(PDO $pdo, int $conteoId, int $productoId, int $usuarioId, string $fisico): void
{
    if ($conteoId < 1 || $productoId < 1 || $usuarioId < 1) throw new DomainException('Los datos del conteo no son válidos.');
    $fisicoM = corozao_conteo_milesimas($fisico);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT conteo_id,bodega_id,estado FROM conteos_inventario WHERE conteo_id=:id FOR UPDATE');
        $stmt->execute(['id' => $conteoId]);
        $conteo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conteo || $conteo['estado'] !== 'ABIERTO') throw new DomainException('El conteo está cerrado o no existe.');
        $stmt = $pdo->prepare('SELECT producto_id FROM productos WHERE producto_id=:producto AND activo=1 FOR UPDATE');
        $stmt->execute(['producto' => $productoId]);
        if (!$stmt->fetchColumn()) throw new DomainException('El producto no existe o está inactivo.');
        $stmt = $pdo->prepare('SELECT cantidad_base FROM inventario_saldos WHERE bodega_id=:bodega AND producto_id=:producto FOR UPDATE');
        $stmt->execute(['bodega' => $conteo['bodega_id'], 'producto' => $productoId]);
        $saldo = $stmt->fetchColumn();
        $teorico = $saldo === false ? '0.000' : (string) $saldo;
        $teoricoM = corozao_conteo_saldo_milesimas($teorico);
        $stmt = $pdo->prepare('INSERT INTO conteo_inventario_detalles
            (conteo_id,producto_id,usuario_id,cantidad_teorica,cantidad_fisica,diferencia)
            VALUES (:conteo,:producto,:usuario,:teorico,:fisico,:diferencia)');
        $stmt->execute(['conteo' => $conteoId, 'producto' => $productoId, 'usuario' => $usuarioId,
            'teorico' => corozao_conteo_formato($teoricoM), 'fisico' => corozao_conteo_formato($fisicoM),
            'diferencia' => corozao_conteo_formato($fisicoM - $teoricoM)]);
        $id = (int) $pdo->lastInsertId();
        corozao_registrar_auditoria($pdo, $usuarioId, 'CREAR', 'conteo_inventario_detalles', $id, null,
            ['conteo_id' => $conteoId, 'producto_id' => $productoId,
                'cantidad_teorica' => corozao_conteo_formato($teoricoM)],
            ['cantidad_fisica' => corozao_conteo_formato($fisicoM),
                'diferencia' => corozao_conteo_formato($fisicoM - $teoricoM)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') throw new DomainException('Este producto ya fue contado en la sesión abierta.');
        throw $e;
    }
}

function corozao_conteo_cerrar(PDO $pdo, int $conteoId, int $usuarioId): void
{
    if ($conteoId < 1 || $usuarioId < 1) throw new DomainException('El conteo no es válido.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT estado FROM conteos_inventario WHERE conteo_id=:id FOR UPDATE');
        $stmt->execute(['id' => $conteoId]);
        if ($stmt->fetchColumn() !== 'ABIERTO') throw new DomainException('El conteo ya fue cerrado o no existe.');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM conteo_inventario_detalles WHERE conteo_id=:id');
        $stmt->execute(['id' => $conteoId]);
        if ((int) $stmt->fetchColumn() < 1) throw new DomainException('Registra al menos un producto antes de cerrar el conteo.');
        $stmt = $pdo->prepare('UPDATE conteos_inventario SET estado=\'CERRADO\',cerrado_por_usuario_id=:usuario,fecha_cierre=CURRENT_TIMESTAMP(6) WHERE conteo_id=:id AND estado=\'ABIERTO\'');
        $stmt->execute(['usuario' => $usuarioId, 'id' => $conteoId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('No se pudo cerrar el conteo.');
        corozao_registrar_auditoria($pdo, $usuarioId, 'MODIFICAR', 'conteos_inventario', $conteoId,
            ['estado' => 'ABIERTO'], ['estado' => 'CERRADO', 'cerrado_por_usuario_id' => $usuarioId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
