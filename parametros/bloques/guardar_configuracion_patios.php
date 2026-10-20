<?php
require '../../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

if (!in_array((int)($_SESSION['rol_id'] ?? 0), [1, 2], true)) {
    header('Location: ../../index.php');
    exit;
}

if (!isset($_SESSION['sucursal_activa'])) {
    $_SESSION['toast_message'] = 'Error: No se ha seleccionado una sucursal de trabajo.';
    $_SESSION['toast_type'] = 'error';
    header('Location: configuracion.php');
    exit;
}

$sucursal_activa = (int)$_SESSION['sucursal_activa'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['create_patio', 'update_patio', 'delete_patio'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim((string)($_POST['nombre'] ?? ''));

        try {
            $pdo->beginTransaction();

            $stmtPatiosSucursal = $pdo->prepare('
                SELECT id, nombre, estado_id
                FROM patios
                WHERE sucursal_id = :sucursal
            ');
            $stmtPatiosSucursal->execute(['sucursal' => $sucursal_activa]);
            $patiosSucursal = $stmtPatiosSucursal->fetchAll(PDO::FETCH_ASSOC);

            $patiosPorNombre = [];
            foreach ($patiosSucursal as $patioSucursal) {
                $patiosPorNombre[mb_strtolower(trim((string)$patioSucursal['nombre']))] = [
                    'id' => (int)$patioSucursal['id'],
                    'nombre' => (string)$patioSucursal['nombre'],
                    'estado_id' => (int)$patioSucursal['estado_id'],
                ];
            }

            if ($action === 'create_patio') {
                if ($nombre === '') {
                    throw new Exception('Escribe un nombre para el patio.');
                }

                $nombreKey = mb_strtolower($nombre);
                $existing = $patiosPorNombre[$nombreKey] ?? null;

                if ($existing && (int)$existing['estado_id'] === 1) {
                    throw new Exception("Ya existe un patio activo con el nombre \"$nombre\" en esta sucursal.");
                }

                if ($existing && (int)$existing['estado_id'] === 2) {
                    $stmtReactivate = $pdo->prepare('UPDATE patios SET estado_id = 1 WHERE id = :id');
                    $stmtReactivate->execute(['id' => (int)$existing['id']]);
                    $pdo->commit();
                    $_SESSION['toast_message'] = "Patio \"$nombre\" reactivado correctamente.";
                    $_SESSION['toast_type'] = 'success';
                    header('Location: configuracion.php');
                    exit;
                }

                $stmtInsert = $pdo->prepare('INSERT INTO patios (nombre, sucursal_id, estado_id) VALUES (:nombre, :sucursal, 1)');
                $stmtInsert->execute([
                    'nombre' => $nombre,
                    'sucursal' => $sucursal_activa,
                ]);

                $pdo->commit();
                $_SESSION['toast_message'] = 'Patio creado correctamente.';
                $_SESSION['toast_type'] = 'success';
                header('Location: configuracion.php');
                exit;
            }

            if ($action === 'update_patio') {
                if ($id <= 0 || $nombre === '') {
                    throw new Exception('No se pudo actualizar el patio seleccionado.');
                }

                $stmtPatio = $pdo->prepare('SELECT id FROM patios WHERE id = :id AND sucursal_id = :sucursal AND estado_id = 1');
                $stmtPatio->execute([
                    'id' => $id,
                    'sucursal' => $sucursal_activa,
                ]);
                if (!$stmtPatio->fetchColumn()) {
                    throw new Exception('El patio que intentas editar no existe en esta sucursal.');
                }

                $nombreKey = mb_strtolower($nombre);
                $existing = $patiosPorNombre[$nombreKey] ?? null;
                if ($existing && (int)$existing['id'] !== $id) {
                    if ((int)$existing['estado_id'] === 1) {
                        throw new Exception("Ya existe un patio activo con el nombre \"$nombre\" en esta sucursal.");
                    }
                    throw new Exception("Ya existe un patio inactivo con el nombre \"$nombre\". Crealo como nuevo para reactivarlo.");
                }

                $stmtUpdate = $pdo->prepare('UPDATE patios SET nombre = :nombre WHERE id = :id');
                $stmtUpdate->execute([
                    'nombre' => $nombre,
                    'id' => $id,
                ]);

                $pdo->commit();
                $_SESSION['toast_message'] = 'Patio actualizado correctamente.';
                $_SESSION['toast_type'] = 'success';
                header('Location: configuracion.php');
                exit;
            }

            if ($action === 'delete_patio') {
                if ($id <= 0) {
                    throw new Exception('No se pudo eliminar el patio seleccionado.');
                }

                $stmtPatio = $pdo->prepare('SELECT id, nombre FROM patios WHERE id = :id AND sucursal_id = :sucursal AND estado_id = 1');
                $stmtPatio->execute([
                    'id' => $id,
                    'sucursal' => $sucursal_activa,
                ]);
                $patio = $stmtPatio->fetch(PDO::FETCH_ASSOC);
                if (!$patio) {
                    throw new Exception('El patio que intentas eliminar no existe en esta sucursal.');
                }

                $stmtBloques = $pdo->prepare('SELECT COUNT(*) FROM bloques WHERE patio_id = :id AND estado_id = 1');
                $stmtBloques->execute(['id' => $id]);
                if ((int)$stmtBloques->fetchColumn() > 0) {
                    throw new Exception('No se puede eliminar el patio porque tiene bloques activos.');
                }

                $stmtDelete = $pdo->prepare('UPDATE patios SET estado_id = 2 WHERE id = :id');
                $stmtDelete->execute(['id' => $id]);

                $pdo->commit();
                $_SESSION['toast_message'] = 'Patio eliminado correctamente.';
                $_SESSION['toast_type'] = 'success';
                header('Location: configuracion.php');
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['toast_message'] = 'Error: ' . $e->getMessage();
            $_SESSION['toast_type'] = 'error';
            header('Location: configuracion.php');
            exit;
        }
    }

    $patios = $_POST['patios'] ?? [];
    $patiosNuevos = $_POST['patios_nuevos'] ?? [];
    $patiosEliminar = array_map('intval', $_POST['patios_eliminar'] ?? []);

    try {
        $pdo->beginTransaction();

        $patioIds = [];
        foreach (array_keys($patios) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $patioIds[] = $id;
            }
        }
        foreach ($patiosEliminar as $id) {
            if ($id > 0) {
                $patioIds[] = $id;
            }
        }
        $patioIds = array_values(array_unique($patioIds));

        $patiosPropios = [];
        $bloquesActivosPorPatio = [];

        if ($patioIds !== []) {
            $placeholders = implode(',', array_fill(0, count($patioIds), '?'));

            $stmtPatiosPropios = $pdo->prepare("
                SELECT id
                FROM patios
                WHERE sucursal_id = ? AND id IN ($placeholders)
            ");
            $stmtPatiosPropios->execute(array_merge([$sucursal_activa], $patioIds));
            foreach ($stmtPatiosPropios->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $patiosPropios[(int)$id] = true;
            }

            $stmtBloquesActivos = $pdo->prepare("
                SELECT patio_id, COUNT(*) AS total
                FROM bloques
                WHERE estado_id = 1 AND patio_id IN ($placeholders)
                GROUP BY patio_id
            ");
            $stmtBloquesActivos->execute($patioIds);
            foreach ($stmtBloquesActivos->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $bloquesActivosPorPatio[(int)$fila['patio_id']] = (int)$fila['total'];
            }
        }

        $stmtPatiosSucursal = $pdo->prepare('
            SELECT id, nombre, estado_id
            FROM patios
            WHERE sucursal_id = :sucursal
        ');
        $stmtPatiosSucursal->execute(['sucursal' => $sucursal_activa]);
        $patiosSucursal = $stmtPatiosSucursal->fetchAll(PDO::FETCH_ASSOC);

        $patiosPorNombre = [];
        foreach ($patiosSucursal as $patioSucursal) {
            $patiosPorNombre[mb_strtolower(trim((string)$patioSucursal['nombre']))] = [
                'id' => (int)$patioSucursal['id'],
                'nombre' => (string)$patioSucursal['nombre'],
                'estado_id' => (int)$patioSucursal['estado_id'],
            ];
        }

        $nombresReservados = [];
        foreach ($patiosSucursal as $patioSucursal) {
            if ((int)$patioSucursal['estado_id'] === 1 && !in_array((int)$patioSucursal['id'], $patiosEliminar, true)) {
                $nombresReservados[mb_strtolower(trim((string)$patioSucursal['nombre']))] = (int)$patioSucursal['id'];
            }
        }

        foreach ($patiosEliminar as $id) {
            if ($id <= 0 || !isset($patiosPropios[$id])) {
                continue;
            }

            if (($bloquesActivosPorPatio[$id] ?? 0) > 0) {
                throw new Exception('No se puede eliminar el patio porque tiene bloques activos.');
            }
        }

        if ($patiosEliminar !== []) {
            $stmtDel = $pdo->prepare('UPDATE patios SET estado_id = 2 WHERE id = :id');
            foreach ($patiosEliminar as $id) {
                if ($id > 0 && isset($patiosPropios[$id])) {
                    $stmtDel->execute(['id' => $id]);
                }
            }
        }

        $stmtUpd = $pdo->prepare('UPDATE patios SET nombre = :nombre WHERE id = :id');
        foreach ($patios as $id => $patioData) {
            $id = (int)$id;
            $nombre = trim((string)($patioData['nombre'] ?? ''));

            if ($id <= 0 || $nombre === '' || !isset($patiosPropios[$id])) {
                continue;
            }

            $nombreKey = mb_strtolower($nombre);
            $conflicto = $patiosPorNombre[$nombreKey] ?? null;

            if ($conflicto && (int)$conflicto['id'] !== $id) {
                if ((int)$conflicto['estado_id'] === 1 && !in_array((int)$conflicto['id'], $patiosEliminar, true)) {
                    throw new Exception("Ya existe un patio activo con el nombre \"$nombre\" en esta sucursal.");
                }

                if ((int)$conflicto['estado_id'] === 2) {
                    throw new Exception("Ya existe un patio inactivo con el nombre \"$nombre\". Para recuperarlo, agregalo como nuevo y el sistema lo reactivara.");
                }
            }

            $reservadoPor = $nombresReservados[$nombreKey] ?? null;
            if ($reservadoPor !== null && $reservadoPor !== $id) {
                throw new Exception("El nombre \"$nombre\" ya esta siendo usado por otro patio en esta misma configuracion.");
            }

            $stmtUpd->execute([
                'nombre' => $nombre,
                'id' => $id,
            ]);

            $nombresReservados[$nombreKey] = $id;
        }

        if (!empty($patiosNuevos)) {
            $stmtIns = $pdo->prepare('INSERT INTO patios (nombre, sucursal_id, estado_id) VALUES (:nombre, :sucursal, 1)');
            $stmtCheckExist = $pdo->prepare('SELECT id, estado_id FROM patios WHERE nombre = :nombre AND sucursal_id = :sucursal');
            $stmtReactivate = $pdo->prepare('UPDATE patios SET estado_id = 1 WHERE id = :id');

            foreach ($patiosNuevos as $nuevo) {
                $nombre = trim((string)($nuevo['nombre'] ?? ''));
                if ($nombre === '') {
                    continue;
                }

                $nombreKey = mb_strtolower($nombre);
                if (isset($nombresReservados[$nombreKey])) {
                    throw new Exception("Ya existe un patio activo con el nombre \"$nombre\" en esta sucursal.");
                }

                $stmtCheckExist->execute([
                    'nombre' => $nombre,
                    'sucursal' => $sucursal_activa,
                ]);
                $existing = $stmtCheckExist->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    if ((int)$existing['estado_id'] === 2) {
                        $stmtReactivate->execute(['id' => (int)$existing['id']]);
                        $nombresReservados[$nombreKey] = (int)$existing['id'];
                    }
                    if ((int)$existing['estado_id'] === 1) {
                        throw new Exception("Ya existe un patio activo con el nombre \"$nombre\" en esta sucursal.");
                    }
                    continue;
                }

                $stmtIns->execute([
                    'nombre' => $nombre,
                    'sucursal' => $sucursal_activa,
                ]);
                $nombresReservados[$nombreKey] = (int)$pdo->lastInsertId();
            }
        }

        $pdo->commit();
        $_SESSION['toast_message'] = 'Cambios en patios guardados correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: configuracion.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['toast_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: configuracion.php');
        exit;
    }
}
?>
