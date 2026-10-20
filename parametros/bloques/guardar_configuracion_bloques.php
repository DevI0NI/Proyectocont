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
    $patioRedirectId = (int)($_POST['patio_id'] ?? 0);
    $redirectBloques = 'configuracion_bloques.php' . ($patioRedirectId > 0 ? '?patio_id=' . $patioRedirectId : '');

    if (in_array($action, ['create_block', 'update_block', 'delete_block'], true)) {
        $id = (int)($_POST['id'] ?? 0);
        $capacidadMaxima = max(0, (int)($_POST['capacidad_maxima'] ?? 0));

        try {
            $pdo->beginTransaction();

            $stmtPatio = $pdo->prepare('
                SELECT id, nombre
                FROM patios
                WHERE id = :id
                  AND sucursal_id = :sucursal
                  AND estado_id = 1
            ');
            $stmtPatio->execute([
                'id' => $patioRedirectId,
                'sucursal' => $sucursal_activa,
            ]);
            $patio = $stmtPatio->fetch(PDO::FETCH_ASSOC);

            if (!$patio) {
                throw new Exception('El patio seleccionado no existe en la sucursal activa.');
            }

            if ($action === 'create_block') {
                $stmtMaxNumero = $pdo->prepare('SELECT COALESCE(MAX(numero), 0) FROM bloques WHERE patio_id = :patio_id');
                $stmtMaxNumero->execute(['patio_id' => $patioRedirectId]);
                $nuevoNumero = ((int)$stmtMaxNumero->fetchColumn()) + 1;

                $stmtInsert = $pdo->prepare('
                    INSERT INTO bloques (patio_id, numero, capacidad_maxima, capacidad_actual, estado_id)
                    VALUES (:patio_id, :numero, :capacidad_maxima, 0, 1)
                ');
                $stmtInsert->execute([
                    'patio_id' => $patioRedirectId,
                    'numero' => $nuevoNumero,
                    'capacidad_maxima' => $capacidadMaxima,
                ]);

                $pdo->commit();
                $_SESSION['toast_message'] = "Bloque $nuevoNumero creado correctamente.";
                $_SESSION['toast_type'] = 'success';
                header("Location: $redirectBloques");
                exit;
            }

            $stmtBloque = $pdo->prepare('
                SELECT b.id, b.numero
                FROM bloques b
                JOIN patios p ON p.id = b.patio_id
                WHERE b.id = :id
                  AND b.estado_id = 1
                  AND p.sucursal_id = :sucursal
                  AND p.id = :patio_id
            ');
            $stmtBloque->execute([
                'id' => $id,
                'sucursal' => $sucursal_activa,
                'patio_id' => $patioRedirectId,
            ]);
            $bloque = $stmtBloque->fetch(PDO::FETCH_ASSOC);

            if (!$bloque) {
                throw new Exception('El bloque seleccionado no existe en este patio.');
            }

            if ($action === 'update_block') {
                $stmtUpdate = $pdo->prepare('UPDATE bloques SET capacidad_maxima = :capacidad_maxima WHERE id = :id');
                $stmtUpdate->execute([
                    'capacidad_maxima' => $capacidadMaxima,
                    'id' => $id,
                ]);

                $pdo->commit();
                $_SESSION['toast_message'] = 'Bloque actualizado correctamente.';
                $_SESSION['toast_type'] = 'success';
                header("Location: $redirectBloques");
                exit;
            }

            if ($action === 'delete_block') {
                $stmtContenedores = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :id AND estado_id = 1');
                $stmtContenedores->execute(['id' => $id]);
                $contenedoresActivos = (int)$stmtContenedores->fetchColumn();

                if ($contenedoresActivos > 0) {
                    throw new Exception("No se puede eliminar el Bloque {$bloque['numero']} porque tiene $contenedoresActivos contenedor(es) activos.");
                }

                $stmtDelete = $pdo->prepare('UPDATE bloques SET estado_id = 2 WHERE id = :id');
                $stmtDelete->execute(['id' => $id]);

                $pdo->commit();
                $_SESSION['toast_message'] = 'Bloque eliminado correctamente.';
                $_SESSION['toast_type'] = 'success';
                header("Location: $redirectBloques");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $_SESSION['toast_message'] = $e->getMessage();
            $_SESSION['toast_type'] = 'error';
            header("Location: $redirectBloques");
            exit;
        }
    }

    $bloques = $_POST['bloques'] ?? [];
    $bloquesNuevos = $_POST['bloques_nuevos'] ?? [];
    $bloquesEliminar = array_map('intval', $_POST['bloques_eliminar'] ?? []);

    try {
        $pdo->beginTransaction();

        $bloqueIds = [];
        foreach (array_keys($bloques) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $bloqueIds[] = $id;
            }
        }
        foreach ($bloquesEliminar as $id) {
            if ($id > 0) {
                $bloqueIds[] = $id;
            }
        }
        $bloqueIds = array_values(array_unique($bloqueIds));

        $bloquesExistentes = [];
        $contenedoresActivosPorBloque = [];

        if ($bloqueIds !== []) {
            $placeholders = implode(',', array_fill(0, count($bloqueIds), '?'));

            $stmtInfo = $pdo->prepare("
                SELECT b.id, b.numero, p.sucursal_id
                FROM bloques b
                JOIN patios p ON b.patio_id = p.id
                WHERE b.id IN ($placeholders)
            ");
            $stmtInfo->execute($bloqueIds);
            foreach ($stmtInfo->fetchAll(PDO::FETCH_ASSOC) as $bloque) {
                $bloquesExistentes[(int)$bloque['id']] = [
                    'numero' => (int)$bloque['numero'],
                    'sucursal_id' => (int)$bloque['sucursal_id'],
                ];
            }

            $stmtContenedores = $pdo->prepare("
                SELECT bloque_id, COUNT(*) AS total
                FROM contenedores
                WHERE estado_id = 1 AND bloque_id IN ($placeholders)
                GROUP BY bloque_id
            ");
            $stmtContenedores->execute($bloqueIds);
            foreach ($stmtContenedores->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $contenedoresActivosPorBloque[(int)$fila['bloque_id']] = (int)$fila['total'];
            }
        }

        foreach ($bloquesEliminar as $id) {
            if ($id <= 0) {
                continue;
            }

            $bloque = $bloquesExistentes[$id] ?? null;
            if (!$bloque) {
                throw new Exception("El bloque con ID $id no existe.");
            }

            if ($bloque['sucursal_id'] !== $sucursal_activa) {
                throw new Exception("No tienes permiso para eliminar el Bloque {$bloque['numero']} (Pertenece a otra sucursal).");
            }

            $contenedoresCount = $contenedoresActivosPorBloque[$id] ?? 0;
            if ($contenedoresCount > 0) {
                throw new Exception("No se puede eliminar el Bloque {$bloque['numero']} porque tiene $contenedoresCount contenedores activos.");
            }
        }

        if ($bloquesEliminar !== []) {
            $stmtDel = $pdo->prepare('UPDATE bloques SET estado_id = 2 WHERE id = :id');
            foreach ($bloquesEliminar as $id) {
                $bloque = $bloquesExistentes[$id] ?? null;
                if ($bloque && $bloque['sucursal_id'] === $sucursal_activa) {
                    $stmtDel->execute(['id' => $id]);
                }
            }
        }

        $stmtUpd = $pdo->prepare('UPDATE bloques SET capacidad_maxima = :capacidad_maxima WHERE id = :id');
        foreach ($bloques as $id => $capacidadMaxima) {
            $id = (int)$id;
            $bloque = $bloquesExistentes[$id] ?? null;
            if (!$bloque || $bloque['sucursal_id'] !== $sucursal_activa) {
                continue;
            }

            $stmtUpd->execute([
                'capacidad_maxima' => max(0, (int)$capacidadMaxima),
                'id' => $id,
            ]);
        }

        if (!empty($bloquesNuevos)) {
            $patioIds = [];
            foreach (array_keys($bloquesNuevos) as $patioId) {
                $patioId = (int)$patioId;
                if ($patioId > 0) {
                    $patioIds[] = $patioId;
                }
            }
            $patioIds = array_values(array_unique($patioIds));

            $patiosPropios = [];
            if ($patioIds !== []) {
                $placeholders = implode(',', array_fill(0, count($patioIds), '?'));
                $stmtPatios = $pdo->prepare("
                    SELECT id
                    FROM patios
                    WHERE sucursal_id = ? AND id IN ($placeholders)
                ");
                $stmtPatios->execute(array_merge([$sucursal_activa], $patioIds));
                foreach ($stmtPatios->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $patiosPropios[(int)$id] = true;
                }
            }

            $stmtMaxNum = $pdo->prepare('SELECT COALESCE(MAX(numero), 0) FROM bloques WHERE patio_id = :pid');
            $stmtIns = $pdo->prepare('
                INSERT INTO bloques (patio_id, numero, capacidad_maxima, capacidad_actual, estado_id)
                VALUES (:pid, :num, :cap, 0, 1)
            ');

            foreach ($bloquesNuevos as $patioId => $nuevos) {
                $patioId = (int)$patioId;
                if ($patioId <= 0 || !isset($patiosPropios[$patioId])) {
                    continue;
                }

                $stmtMaxNum->execute(['pid' => $patioId]);
                $maxNumero = (int)$stmtMaxNum->fetchColumn();

                foreach ($nuevos as $capacidadMaxima) {
                    $maxNumero++;
                    $stmtIns->execute([
                        'pid' => $patioId,
                        'num' => $maxNumero,
                        'cap' => max(0, (int)$capacidadMaxima),
                    ]);
                }
            }
        }

        $pdo->commit();
        $_SESSION['toast_message'] = 'Cambios guardados correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: configuracion.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['toast_message'] = $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: configuracion.php');
        exit;
    }
}
?>
