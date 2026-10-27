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
    if (!preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,3})?$/', $valor)) {
        throw new DomainException('La cantidad física debe ser un valor no negativo con hasta tres decimales.');
    }
    [$entero, $fraccion] = array_pad(explode('.', $valor, 2), 2, '');
    return ((int) $entero * 1000) + (int) str_pad($fraccion, 3, '0');
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
        $teoricoM = corozao_conteo_milesimas($teorico);
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
