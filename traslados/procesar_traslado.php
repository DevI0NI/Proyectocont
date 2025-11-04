<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Depuración: Inicio del proceso
        file_put_contents('debug.log', "Inicio del proceso de traslado\n", FILE_APPEND);

        // Obtener los datos del formulario
        $contenedorId = $_POST['contenedor_id'];
        $nuevoBloqueId = $_POST['nuevo_bloque_id'];
        $nuevaPosicion = $_POST['nueva_posicion'];

        // Depuración: Datos recibidos
        file_put_contents('debug.log', "Datos recibidos: Contenedor ID: $contenedorId, Nuevo Bloque ID: $nuevoBloqueId, Nueva Posición: $nuevaPosicion\n", FILE_APPEND);

        // Validar que los datos sean válidos
        if (empty($contenedorId) || empty($nuevoBloqueId) || empty($nuevaPosicion)) {
            file_put_contents('debug.log', "Error: Datos incompletos\n", FILE_APPEND);
            $_SESSION['toast_message'] = 'Todos los campos son obligatorios.';
            $_SESSION['toast_type'] = 'error';
            header('Location: traslados.php');
            exit;
        }

        // Obtener información del contenedor actual
        $stmt = $pdo->prepare('SELECT bloque_id, posicion FROM contenedores WHERE id = :contenedor_id');
        $stmt->execute(['contenedor_id' => $contenedorId]);
        $contenedorInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        // Depuración: Información del contenedor
        file_put_contents('debug.log', "Información del contenedor: " . json_encode($contenedorInfo) . "\n", FILE_APPEND);

        if (!$contenedorInfo) {
            file_put_contents('debug.log', "Error: El contenedor no existe\n", FILE_APPEND);
            $_SESSION['toast_message'] = 'El contenedor no existe.';
            $_SESSION['toast_type'] = 'error';
            header('Location: traslados.php');
            exit;
        }

        $bloqueActualId = $contenedorInfo['bloque_id'];
        $posicionActual = $contenedorInfo['posicion'];

        // Verificar si hay contenedores debajo del contenedor que se desea mover
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :bloque_id AND posicion < :posicion');
        $stmt->execute([
            'bloque_id' => $bloqueActualId,
            'posicion' => $posicionActual
        ]);
        $contenedoresDebajo = $stmt->fetchColumn();

        // Depuración: Contenedores debajo
        file_put_contents('debug.log', "Contenedores debajo encontrados: $contenedoresDebajo\n", FILE_APPEND);

        if ($contenedoresDebajo > 0) {
            file_put_contents('debug.log', "Error: Hay contenedores debajo, no se puede trasladar\n", FILE_APPEND);
            $_SESSION['toast_message'] = 'No se puede trasladar el contenedor porque hay otros debajo.';
            $_SESSION['toast_type'] = 'error';
            header('Location: traslados.php');
            exit;
        }

        // Verificar si la posición está disponible en el nuevo bloque
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE bloque_id = :bloque_id AND posicion = :posicion');
        $stmt->execute(['bloque_id' => $nuevoBloqueId, 'posicion' => $nuevaPosicion]);
        $posicionOcupada = $stmt->fetchColumn();

        // Depuración: Posición ocupada
        file_put_contents('debug.log', "Posición ocupada encontrada: $posicionOcupada\n", FILE_APPEND);

        if ($posicionOcupada > 0) {
            file_put_contents('debug.log', "Error: La posición seleccionada ya está ocupada\n", FILE_APPEND);
            $_SESSION['toast_message'] = 'La posición seleccionada ya está ocupada.';
            $_SESSION['toast_type'] = 'error';
            header('Location: traslados.php');
            exit;
        }

        // Actualizar la ubicación del contenedor
        $stmt = $pdo->prepare('UPDATE contenedores SET bloque_id = :nuevo_bloque_id, posicion = :nueva_posicion WHERE id = :contenedor_id');
        $stmt->execute([
            'nuevo_bloque_id' => $nuevoBloqueId,
            'nueva_posicion' => $nuevaPosicion,
            'contenedor_id' => $contenedorId
        ]);

        // Depuración: Actualización de ubicación
        file_put_contents('debug.log', "Ubicación actualizada: Nuevo Bloque ID: $nuevoBloqueId, Nueva Posición: $nuevaPosicion\n", FILE_APPEND);

        // Ajustar la capacidad actual de los bloques
        $stmt = $pdo->prepare('UPDATE bloques SET capacidad_actual = capacidad_actual + 1 WHERE id = :nuevo_bloque_id');
        $stmt->execute(['nuevo_bloque_id' => $nuevoBloqueId]);

        $stmt = $pdo->prepare('UPDATE bloques SET capacidad_actual = capacidad_actual - 1 WHERE id = :bloque_id');
        $stmt->execute(['bloque_id' => $bloqueActualId]);

        // Depuración: Ajuste de capacidad
        file_put_contents('debug.log', "Capacidad ajustada: Nuevo Bloque ID: $nuevoBloqueId, Bloque Actual ID: $bloqueActualId\n", FILE_APPEND);

        $pdo->commit();

        // Depuración: Proceso exitoso
        file_put_contents('debug.log', "Proceso de traslado completado exitosamente\n", FILE_APPEND);

        $_SESSION['toast_message'] = 'El contenedor se trasladó exitosamente.';
        $_SESSION['toast_type'] = 'success';
        header('Location: traslados.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();

        // Depuración: Error en la transacción
        file_put_contents('debug.log', "Error en la transacción: " . $e->getMessage() . "\n", FILE_APPEND);

        $_SESSION['toast_message'] = 'Error al trasladar el contenedor: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: traslados.php');
        exit;
    }
}
?>