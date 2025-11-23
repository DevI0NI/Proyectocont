<?php
require '../../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Asumimos que la sucursal es 1 por defecto si no se maneja multi-sucursal todavía en la sesión
// Esto es necesario porque la nueva tabla 'patios' requiere 'sucursal_id'
$sucursal_id = $_SESSION['sucursal_id'] ?? 1; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patios = $_POST['patios'] ?? [];
    $patiosNuevos = $_POST['patios_nuevos'] ?? [];
    $patiosEliminar = $_POST['patios_eliminar'] ?? []; // Patios seleccionados para eliminar

    try {
        $pdo->beginTransaction();

        // 1. ELIMINAR PATIOS (Soft Delete)
        foreach ($patiosEliminar as $id) {
            // Verificar si tiene BLOQUES ACTIVOS antes de desactivar el patio
            // Si tiene bloques activos, no permitimos borrar el patio para mantener la integridad visual
            $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM bloques WHERE patio_id = :id AND estado_id = 1');
            $stmtCheck->execute(['id' => $id]);
            $bloquesCount = $stmtCheck->fetchColumn();

            if ($bloquesCount > 0) {
                // Si tiene bloques activos, revertimos y avisamos
                $pdo->rollBack();
                $_SESSION['toast_message'] = 'No se puede eliminar el patio porque tiene bloques activos asociados. Elimine los bloques primero.';
                $_SESSION['toast_type'] = 'error';
                header('Location: configuracion.php');
                exit;
            }

            // Soft Delete: Marcar como inactivo
            $stmtDel = $pdo->prepare('UPDATE patios SET estado_id = 2 WHERE id = :id');
            $stmtDel->execute(['id' => $id]);
        }

        // 2. ACTUALIZAR PATIOS EXISTENTES (Nombre)
        if (!empty($patios)) {
            $stmtUpd = $pdo->prepare('UPDATE patios SET nombre = :nombre WHERE id = :id');
            foreach ($patios as $id => $patioData) {
                $nombre = trim($patioData['nombre']);
                if (!empty($nombre)) {
                    // Aquí podrías añadir validación de duplicados si lo deseas, 
                    // pero la BD lanzará error si duplicas nombre en la misma sucursal.
                    $stmtUpd->execute(['nombre' => $nombre, 'id' => $id]);
                }
            }
        }

        // 3. INSERTAR NUEVOS PATIOS
        if (!empty($patiosNuevos)) {
            // Insertamos con estado_id = 1 (Activo) y sucursal_id
            $stmtIns = $pdo->prepare('INSERT INTO patios (nombre, sucursal_id, estado_id) VALUES (:nombre, :sucursal, 1)');
            
            // El array $patiosNuevos viene como [0 => ['nombre'=>'A'], 1 => ['nombre'=>'B']]
            // debido a la estructura name="patios_nuevos[][nombre]"
            foreach ($patiosNuevos as $nuevo) {
                $nombre = trim($nuevo['nombre']);
                if (!empty($nombre)) {
                    // Verificar si ya existe (activo o inactivo) para reactivarlo si es necesario
                    // O simplemente intentar insertar y dejar que la BD maneje duplicados (pero fallaría)
                    // Hacemos la verificación simple:
                    
                    $stmtCheckExist = $pdo->prepare('SELECT id, estado_id FROM patios WHERE nombre = :nombre AND sucursal_id = :sucursal');
                    $stmtCheckExist->execute(['nombre' => $nombre, 'sucursal' => $sucursal_id]);
                    $existing = $stmtCheckExist->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        if ($existing['estado_id'] == 2) {
                            // Reactivar
                            $stmtReactivate = $pdo->prepare('UPDATE patios SET estado_id = 1 WHERE id = :id');
                            $stmtReactivate->execute(['id' => $existing['id']]);
                        } else {
                            // Ya existe activo, lanzamos error o ignoramos
                            // (Lanzar error cancelaría todo, mejor ignoramos este y seguimos con los otros)
                            continue; 
                        }
                    } else {
                        // Insertar nuevo
                        $stmtIns->execute(['nombre' => $nombre, 'sucursal' => $sucursal_id]);
                    }
                }
            }
        }

        $pdo->commit();
        $_SESSION['toast_message'] = 'Cambios en patios guardados correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: configuracion.php');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        
        // Manejo de errores de duplicados de MySQL (Código 23000)
        if (strpos($e->getMessage(), '23000') !== false) {
            $_SESSION['toast_message'] = 'Error: Ya existe un patio con ese nombre en esta sucursal.';
        } else {
            $_SESSION['toast_message'] = 'Error al guardar patios: ' . $e->getMessage();
        }
        
        $_SESSION['toast_type'] = 'error';
        header('Location: configuracion.php');
        exit;
    }
}
?>