<?php
require '../../config/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patios = $_POST['patios'] ?? [];
    $patiosNuevos = $_POST['patios_nuevos'] ?? [];
    $patiosEliminar = $_POST['patios_eliminar'] ?? []; // Patios seleccionados para eliminar

    try {
        $pdo->beginTransaction();

        // Eliminar patios seleccionados
        foreach ($patiosEliminar as $id) {
            // Verificar si el patio tiene bloques
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM bloques WHERE patio_id = :patio_id');
            $stmt->execute(['patio_id' => $id]);
            $bloquesCount = $stmt->fetchColumn();

            if ($bloquesCount > 0) {
                $_SESSION['toast_message'] = 'No se puede eliminar el patio porque tiene bloques asociados. Por favor, elimine los bloques antes de continuar.';
                $_SESSION['toast_type'] = 'error';
                $pdo->rollBack();
                header('Location: configuracion.php');
                exit;
            }

            // Eliminar el patio
            $stmt = $pdo->prepare('DELETE FROM patios WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        // Actualizar patios existentes
        foreach ($patios as $id => $patio) {
            if (!empty($patio['nombre'])) { // Validar que el nombre no esté vacío
                $stmt = $pdo->prepare('UPDATE patios SET nombre = :nombre WHERE id = :id');
                $stmt->execute([
                    'nombre' => $patio['nombre'],
                    'id' => $id
                ]);
            }
        }

        // Insertar nuevos patios
        foreach ($patiosNuevos as $nuevoPatio) {
            if (!empty($nuevoPatio['nombre'])) { // Validar que el nombre no esté vacío
                $stmt = $pdo->prepare('INSERT INTO patios (nombre) VALUES (:nombre)');
                $stmt->execute(['nombre' => $nuevoPatio['nombre']]);
            }
        }

        $pdo->commit();
        $_SESSION['toast_message'] = 'Cambios guardados correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: configuracion.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['toast_message'] = 'Error al guardar la configuración de patios: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: configuracion.php');
        exit;
    }
}
?>