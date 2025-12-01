<?php
require '../../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

$sucursal_activa = $_SESSION['sucursal_activa'] ?? ($_SESSION['sucursal_usuario'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bloques = $_POST['bloques'] ?? [];
    $bloquesNuevos = $_POST['bloques_nuevos'] ?? [];
    $bloquesEliminar = $_POST['bloques_eliminar'] ?? [];

    try {
        $pdo->beginTransaction();

        // --- A. ELIMINAR BLOQUES ---
        foreach ($bloquesEliminar as $id) {
            // 1. Primero verificamos que el bloque exista y sea de la sucursal
            $stmtInfo = $pdo->prepare('
                SELECT b.id, p.sucursal_id, b.numero 
                FROM bloques b 
                JOIN patios p ON b.patio_id = p.id 
                WHERE b.id = :id
            ');
            $stmtInfo->execute(['id' => $id]);
            $bloque = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            if (!$bloque) {
                // Si el ID no existe, saltamos error
                throw new Exception("El bloque con ID $id no existe.");
            }

            if ($bloque['sucursal_id'] != $sucursal_activa) {
                // Si intenta borrar un bloque de otra sucursal
                throw new Exception("No tienes permiso para eliminar el Bloque {$bloque['numero']} (Pertenece a otra sucursal).");
            }

            // 2. Ahora sí, validamos contenedores (Aquí es donde te fallaba el aviso)
            $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :bloque_id AND estado_id = 1');
            $stmtCheck->execute(['bloque_id' => $id]);
            $contenedoresCount = $stmtCheck->fetchColumn();

            if ($contenedoresCount > 0) {
                // Lanzamos Exception para cortar el flujo inmediatamente
                throw new Exception("No se puede eliminar el Bloque {$bloque['numero']} porque tiene $contenedoresCount contenedores activos.");
            }

            // 3. Si pasa todas las validaciones, eliminamos
            $stmtDel = $pdo->prepare('UPDATE bloques SET estado_id = 2 WHERE id = :id');
            $stmtDel->execute(['id' => $id]);
        }

        // --- B. ACTUALIZAR BLOQUES EXISTENTES ---
        $stmtUpd = $pdo->prepare('UPDATE bloques SET capacidad_maxima = :capacidad_maxima WHERE id = :id');
        foreach ($bloques as $id => $capacidadMaxima) {
            // Validamos sucursal antes de editar también
            $stmtCheckSuc = $pdo->prepare('SELECT p.sucursal_id FROM bloques b JOIN patios p ON b.patio_id = p.id WHERE b.id = :id');
            $stmtCheckSuc->execute(['id' => $id]);
            if ($stmtCheckSuc->fetchColumn() == $sucursal_activa) {
                $stmtUpd->execute(['capacidad_maxima' => $capacidadMaxima, 'id' => $id]);
            }
        }

        // --- C. INSERTAR NUEVOS BLOQUES ---
        if (!empty($bloquesNuevos)) {
            $stmtCheckPatio = $pdo->prepare('SELECT sucursal_id FROM patios WHERE id = :pid');
            $stmtMaxNum = $pdo->prepare('SELECT COALESCE(MAX(numero), 0) FROM bloques WHERE patio_id = :pid');
            $stmtIns = $pdo->prepare('INSERT INTO bloques (patio_id, numero, capacidad_maxima, capacidad_actual, estado_id) VALUES (:pid, :num, :cap, 0, 1)');

            foreach ($bloquesNuevos as $patioId => $nuevos) {
                $stmtCheckPatio->execute(['pid' => $patioId]);
                if ($stmtCheckPatio->fetchColumn() == $sucursal_activa) {
                    $stmtMaxNum->execute(['pid' => $patioId]);
                    $maxNumero = (int)$stmtMaxNum->fetchColumn();

                    foreach ($nuevos as $capacidadMaxima) {
                        $maxNumero++;
                        $stmtIns->execute([
                            'pid' => $patioId,
                            'num' => $maxNumero,
                            'cap' => $capacidadMaxima
                        ]);
                    }
                }
            }
        }

        $pdo->commit();
        $_SESSION['toast_message'] = 'Cambios guardados correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: configuracion.php');
        exit;

    } catch (Exception $e) {
        // Si ocurre CUALQUIER error (incluyendo la validación de contenedores), caemos aquí
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        // Limpiamos mensaje de SQL raro si existe
        $msg = $e->getMessage();
        
        $_SESSION['toast_message'] = $msg;
        $_SESSION['toast_type'] = 'error';
        header('Location: configuracion.php');
        exit;
    }
}
?>