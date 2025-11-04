<?php
require '../../config/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bloques = $_POST['bloques'] ?? [];
    $bloquesNuevos = $_POST['bloques_nuevos'] ?? [];
    $bloquesEliminar = $_POST['bloques_eliminar'] ?? []; // Bloques seleccionados para eliminar

    try {
        $pdo->beginTransaction();

        // Eliminar bloques seleccionados
        foreach ($bloquesEliminar as $id) {
            // Verificar si el bloque tiene contenedores
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :bloque_id');
            $stmt->execute(['bloque_id' => $id]);
            $contenedoresCount = $stmt->fetchColumn();

            if ($contenedoresCount > 0) {
                $_SESSION['toast_message'] = 'No se puede eliminar el bloque porque tiene contenedores asociados. Por favor, mueva los contenedores antes de continuar.';
                $_SESSION['toast_type'] = 'error';
                $pdo->rollBack();
                header('Location: configuracion.php');
                exit;
            }

            // Eliminar el bloque
            $stmt = $pdo->prepare('DELETE FROM bloques WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        // Actualizar bloques existentes
        foreach ($bloques as $id => $capacidadMaxima) {
            $stmt = $pdo->prepare('UPDATE bloques SET capacidad_maxima = :capacidad_maxima WHERE id = :id');
            $stmt->execute(['capacidad_maxima' => $capacidadMaxima, 'id' => $id]);
        }

        // Insertar nuevos bloques
        foreach ($bloquesNuevos as $patioId => $nuevos) {
            // Obtener el último número de bloque en el patio
            $stmt = $pdo->prepare('SELECT MAX(numero) AS max_numero FROM bloques WHERE patio_id = :patio_id');
            $stmt->execute(['patio_id' => $patioId]);
            $maxNumero = $stmt->fetchColumn() ?? 0;

            foreach ($nuevos as $capacidadMaxima) {
                $maxNumero++; // Incrementar el número del bloque
                $stmt = $pdo->prepare('INSERT INTO bloques (patio_id, numero, capacidad_maxima) VALUES (:patio_id, :numero, :capacidad_maxima)');
                $stmt->execute([
                    'patio_id' => $patioId,
                    'numero' => $maxNumero,
                    'capacidad_maxima' => $capacidadMaxima
                ]);
            }
        }

        $pdo->commit();
        $_SESSION['toast_message'] = 'Cambios en los bloques guardados correctamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: configuracion.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['toast_message'] = 'Error al guardar la configuración de bloques: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: configuracion.php');
        exit;
    }
}
?>