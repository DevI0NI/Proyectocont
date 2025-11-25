<?php
session_start();

// 1. Seguridad: Bloquear acceso directo
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

require '../config/database.php';

if (isset($_GET['bloque_id'])) {
    $bloqueId = (int)$_GET['bloque_id'];

    try {
        // 2. Obtener la capacidad máxima del bloque (Solo si el bloque está activo)
        $stmt = $pdo->prepare('SELECT capacidad_maxima FROM bloques WHERE id = :id AND estado_id = 1');
        $stmt->execute(['id' => $bloqueId]);
        $capacidadMaxima = $stmt->fetchColumn();

        if ($capacidadMaxima === false) {
            echo json_encode(['error' => 'Bloque no encontrado']);
            exit;
        }

        // 3. Obtener las posiciones ocupadas (Solo por contenedores ACTIVOS)
        // Los contenedores inactivos (historial) no ocupan espacio físico.
        $stmt = $pdo->prepare('SELECT posicion FROM contenedores WHERE bloque_id = :bloque_id AND estado_id = 1');
        $stmt->execute(['bloque_id' => $bloqueId]);
        $posicionesOcupadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 4. Calcular la posición disponible (Lógica Inversa: Max -> 1)
        // Empezamos por la capacidad máxima (ej. 25) y buscamos hacia abajo el primer hueco libre.
        $posicionDisponible = $capacidadMaxima;
        
        while (in_array($posicionDisponible, $posicionesOcupadas) && $posicionDisponible > 0) {
            $posicionDisponible--;
        }

        // 5. Respuesta
        if ($posicionDisponible > 0) {
            echo json_encode(['posicion' => $posicionDisponible]);
        } else {
            // Si llegamos a 0, no hay huecos libres (aunque la validación de capacidad debería haberlo prevenido antes)
            echo json_encode(['error' => 'El bloque está completamente lleno.']);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error en el servidor']);
    }
}
?>