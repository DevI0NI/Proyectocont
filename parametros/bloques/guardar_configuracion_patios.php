<?php
require '../../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// 1. SEGURIDAD: Verificar Contexto de Sucursal
if (!isset($_SESSION['sucursal_activa'])) {
    $_SESSION['toast_message'] = 'Error: No se ha seleccionado una sucursal de trabajo.';
    $_SESSION['toast_type'] = 'error';
    header('Location: configuracion.php');
    exit;
}
$sucursal_activa = $_SESSION['sucursal_activa'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patios = $_POST['patios'] ?? [];
    $patiosNuevos = $_POST['patios_nuevos'] ?? [];
    $patiosEliminar = $_POST['patios_eliminar'] ?? [];

    try {
        $pdo->beginTransaction();

        // --- A. ELIMINAR PATIOS (Soft Delete) ---
        foreach ($patiosEliminar as $id) {
            // Validar Pertenencia a Sucursal Activa
            $stmtCheckSuc = $pdo->prepare('SELECT sucursal_id FROM patios WHERE id = :id');
            $stmtCheckSuc->execute(['id' => $id]);
            if ($stmtCheckSuc->fetchColumn() != $sucursal_activa) {
                continue; // Ignorar intentos de borrar patios ajenos
            }

            // Validar Integridad (Bloques Activos)
            $stmtCheckBloques = $pdo->prepare('SELECT COUNT(*) FROM bloques WHERE patio_id = :id AND estado_id = 1');
            $stmtCheckBloques->execute(['id' => $id]);
            if ($stmtCheckBloques->fetchColumn() > 0) {
                $pdo->rollBack();
                $_SESSION['toast_message'] = 'No se puede eliminar el patio porque tiene bloques activos.';
                $_SESSION['toast_type'] = 'error';
                header('Location: configuracion.php');
                exit;
            }

            // Ejecutar Soft Delete
            $stmtDel = $pdo->prepare('UPDATE patios SET estado_id = 2 WHERE id = :id');
            $stmtDel->execute(['id' => $id]);
        }

        // --- B. ACTUALIZAR PATIOS EXISTENTES ---
        $stmtUpd = $pdo->prepare('UPDATE patios SET nombre = :nombre WHERE id = :id');
        foreach ($patios as $id => $patioData) {
            $nombre = trim($patioData['nombre']);
            if (!empty($nombre)) {
                // Validar Pertenencia a Sucursal Activa antes de editar
                $stmtCheckSuc = $pdo->prepare('SELECT sucursal_id FROM patios WHERE id = :id');
                $stmtCheckSuc->execute(['id' => $id]);
                if ($stmtCheckSuc->fetchColumn() == $sucursal_activa) {
                    $stmtUpd->execute(['nombre' => $nombre, 'id' => $id]);
                }
            }
        }

        // --- C. INSERTAR NUEVOS PATIOS ---
        if (!empty($patiosNuevos)) {
            $stmtIns = $pdo->prepare('INSERT INTO patios (nombre, sucursal_id, estado_id) VALUES (:nombre, :sucursal, 1)');
            
            foreach ($patiosNuevos as $nuevo) {
                $nombre = trim($nuevo['nombre']);
                if (!empty($nombre)) {
                    // Verificar si ya existe (activo o inactivo) en ESTA sucursal para reactivar
                    $stmtCheckExist = $pdo->prepare('SELECT id, estado_id FROM patios WHERE nombre = :nombre AND sucursal_id = :sucursal');
                    $stmtCheckExist->execute(['nombre' => $nombre, 'sucursal' => $sucursal_activa]);
                    $existing = $stmtCheckExist->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        if ($existing['estado_id'] == 2) {
                            // Reactivar
                            $stmtReactivate = $pdo->prepare('UPDATE patios SET estado_id = 1 WHERE id = :id');
                            $stmtReactivate->execute(['id' => $existing['id']]);
                        }
                        // Si ya existe activo, lo ignoramos silenciosamente o podríamos lanzar error
                    } else {
                        // Crear Nuevo en la Sucursal Activa
                        $stmtIns->execute(['nombre' => $nombre, 'sucursal' => $sucursal_activa]);
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
        $_SESSION['toast_message'] = 'Error: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: configuracion.php');
        exit;
    }
}
?>