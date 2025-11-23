<?php
require '../../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bloques = $_POST['bloques'] ?? [];
    $bloquesNuevos = $_POST['bloques_nuevos'] ?? [];
    $bloquesEliminar = $_POST['bloques_eliminar'] ?? []; // Bloques seleccionados para eliminar

    try {
        $pdo->beginTransaction();

        // 1. ELIMINAR BLOQUES (Soft Delete)
        foreach ($bloquesEliminar as $id) {
            // Verificar si el bloque tiene contenedores ACTIVOS
            // Importante: Solo nos importa si tiene contenedores "vivos" en el patio.
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :bloque_id AND estado_id = 1');
            $stmt->execute(['bloque_id' => $id]);
            $contenedoresCount = $stmt->fetchColumn();

            if ($contenedoresCount > 0) {
                $_SESSION['toast_message'] = "No se puede eliminar el bloque porque tiene $contenedoresCount contenedores activos. Por favor, muévalos antes de continuar.";
                $_SESSION['toast_type'] = 'error';
                $pdo->rollBack();
                header('Location: configuracion.php');
                exit;
            }

            // "Eliminar" el bloque (Soft Delete: estado_id = 2)
            $stmt = $pdo->prepare('UPDATE bloques SET estado_id = 2 WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }

        // 2. ACTUALIZAR BLOQUES EXISTENTES (Capacidad Máxima)
        foreach ($bloques as $id => $capacidadMaxima) {
            // Intentamos actualizar. Si la nueva capacidad máxima es menor que la actual,
            // el TRIGGER de la base de datos lanzará un error que atraparemos abajo.
            $stmt = $pdo->prepare('UPDATE bloques SET capacidad_maxima = :capacidad_maxima WHERE id = :id');
            $stmt->execute(['capacidad_maxima' => $capacidadMaxima, 'id' => $id]);
        }

        // 3. INSERTAR NUEVOS BLOQUES
        // La estructura de $bloquesNuevos es: [patio_id => [capacidad1, capacidad2...]]
        foreach ($bloquesNuevos as $patioId => $nuevos) {
            // Obtener el último número de bloque en el patio (incluyendo inactivos para no repetir números si no se desea)
            // OJO: Si prefieres reciclar números de bloques borrados, la lógica sería más compleja.
            // Aquí simplemente buscamos el máximo y sumamos 1.
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(numero), 0) FROM bloques WHERE patio_id = :patio_id');
            $stmt->execute(['patio_id' => $patioId]);
            $maxNumero = (int)$stmt->fetchColumn();

            $stmtInsert = $pdo->prepare('INSERT INTO bloques (patio_id, numero, capacidad_maxima, capacidad_actual, estado_id) VALUES (:patio_id, :numero, :capacidad_maxima, 0, 1)');

            foreach ($nuevos as $capacidadMaxima) {
                $maxNumero++; // Incrementar el número del bloque
                $stmtInsert->execute([
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
        
        // Mensaje amigable si el error viene del trigger de capacidad
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