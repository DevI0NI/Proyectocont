<?php
require '../../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// 1. OBTENER LA SUCURSAL ACTIVA (Contexto de Seguridad)
// Si no está definida, usamos la del usuario o 1 por defecto (para evitar fallos fatales)
$sucursal_activa = $_SESSION['sucursal_activa'] ?? ($_SESSION['sucursal_usuario'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bloques = $_POST['bloques'] ?? [];
    $bloquesNuevos = $_POST['bloques_nuevos'] ?? [];
    $bloquesEliminar = $_POST['bloques_eliminar'] ?? [];

    try {
        $pdo->beginTransaction();

        // --- A. ELIMINAR BLOQUES (Soft Delete) ---
        foreach ($bloquesEliminar as $id) {
            // Validar integridad: ¿Tiene contenedores activos?
            $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :bloque_id AND estado_id = 1');
            $stmtCheck->execute(['bloque_id' => $id]);
            $contenedoresCount = $stmtCheck->fetchColumn();

            if ($contenedoresCount > 0) {
                // Si hay contenedores, cancelamos todo
                $pdo->rollBack();
                $_SESSION['toast_message'] = "No se puede eliminar el bloque porque tiene $contenedoresCount contenedores activos. Por favor, muévalos antes de continuar.";
                $_SESSION['toast_type'] = 'error';
                header('Location: configuracion.php');
                exit;
            }

            // Validar pertenencia a la sucursal (Seguridad)
            // Solo permitimos borrar si el bloque pertenece a un patio de MI sucursal
            $stmtSuc = $pdo->prepare('
                SELECT p.sucursal_id 
                FROM bloques b 
                JOIN patios p ON b.patio_id = p.id 
                WHERE b.id = :id
            ');
            $stmtSuc->execute(['id' => $id]);
            $sucursalBloque = $stmtSuc->fetchColumn();

            if ($sucursalBloque == $sucursal_activa) {
                // Soft Delete
                $stmtDel = $pdo->prepare('UPDATE bloques SET estado_id = 2 WHERE id = :id');
                $stmtDel->execute(['id' => $id]);
            }
        }

        // --- B. ACTUALIZAR BLOQUES EXISTENTES ---
        $stmtUpd = $pdo->prepare('UPDATE bloques SET capacidad_maxima = :capacidad_maxima WHERE id = :id');
        
        foreach ($bloques as $id => $capacidadMaxima) {
            // Validar pertenencia a la sucursal antes de actualizar
            $stmtCheckSuc = $pdo->prepare('SELECT p.sucursal_id FROM bloques b JOIN patios p ON b.patio_id = p.id WHERE b.id = :id');
            $stmtCheckSuc->execute(['id' => $id]);
            
            if ($stmtCheckSuc->fetchColumn() == $sucursal_activa) {
                $stmtUpd->execute(['capacidad_maxima' => $capacidadMaxima, 'id' => $id]);
            }
        }

        // --- C. INSERTAR NUEVOS BLOQUES ---
        // La estructura de $bloquesNuevos es: [patio_id => [capacidad1, capacidad2...]]
        if (!empty($bloquesNuevos)) {
            $stmtCheckPatio = $pdo->prepare('SELECT sucursal_id FROM patios WHERE id = :pid');
            // Buscamos el último número usado en ese patio (incluso si está borrado, para no repetir y mantener orden)
            $stmtMaxNum = $pdo->prepare('SELECT COALESCE(MAX(numero), 0) FROM bloques WHERE patio_id = :pid');
            $stmtIns = $pdo->prepare('INSERT INTO bloques (patio_id, numero, capacidad_maxima, capacidad_actual, estado_id) VALUES (:pid, :num, :cap, 0, 1)');

            foreach ($bloquesNuevos as $patioId => $nuevos) {
                // Seguridad: Validar que el patio destino pertenece a mi sucursal
                $stmtCheckPatio->execute(['pid' => $patioId]);
                $sucursalPatio = $stmtCheckPatio->fetchColumn();

                if ($sucursalPatio == $sucursal_activa) {
                    // Obtener el siguiente número
                    $stmtMaxNum->execute(['pid' => $patioId]);
                    $maxNumero = (int)$stmtMaxNum->fetchColumn();

                    foreach ($nuevos as $capacidadMaxima) {
                        $maxNumero++; // Siguiente número disponible
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
        $_SESSION['toast_message'] = 'Cambios en los bloques guardados correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: configuracion.php');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        // Capturar mensaje del Trigger de MySQL para hacerlo amigable
        $msg = $e->getMessage();
        if (strpos($msg, 'La capacidad actual no puede exceder') !== false) {
            $msg = 'Error: No puedes reducir la capacidad máxima por debajo de la cantidad de contenedores que ya existen en el bloque.';
        }

        $_SESSION['toast_message'] = 'Error al guardar: ' . $msg;
        $_SESSION['toast_type'] = 'error';
        header('Location: configuracion.php');
        exit;
    }
}
?>