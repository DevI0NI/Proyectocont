<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

require '../config/database.php';

if (!isset($_GET['bloque_id']) || empty($_GET['bloque_id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'El ID del bloque es requerido']);
    exit;
}

try {
    $bloqueId = (int) $_GET['bloque_id'];

    // 1. Obtener la capacidad máxima del bloque (Solo si está activo)
    $stmt = $pdo->prepare('SELECT capacidad_maxima FROM bloques WHERE id = :bloque_id AND estado_id = 1');
    $stmt->execute(['bloque_id' => $bloqueId]);
    $capacidadMaxima = $stmt->fetchColumn();

    if ($capacidadMaxima === false) {
        echo json_encode(['error' => 'Bloque no encontrado o inactivo']);
        exit;
    }

    // 2. Obtener las posiciones ocupadas (SOLO ACTIVAS)
    // Importante: Ignoramos los contenedores con estado_id = 2 (históricos/borrados)
    $stmt = $pdo->prepare('SELECT posicion FROM contenedores WHERE bloque_id = :bloque_id AND estado_id = 1');
    $stmt->execute(['bloque_id' => $bloqueId]);
    $posicionesOcupadas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Aseguramos que sean enteros para la comparación
    $posicionesOcupadas = array_map('intval', $posicionesOcupadas);

    // 3. Calcular la posición más alta disponible (Lógica de Pila: Max -> 1)
    $posicionDisponible = (int)$capacidadMaxima;
    
    while (in_array($posicionDisponible, $posicionesOcupadas) && $posicionDisponible > 0) {
        $posicionDisponible--;
    }

    // 4. Respuesta
    if ($posicionDisponible > 0) {
        // Devolvemos la posición y también el array de ocupadas por si quieres pintar el mapa en el futuro
        echo json_encode([
            'posicion' => $posicionDisponible,
            'ocupadas' => $posicionesOcupadas
        ]);
    } else {
        echo json_encode(['error' => 'El bloque está completamente lleno.']);
    }

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    // En producción es mejor no mostrar el mensaje crudo de la BD
    echo json_encode(['error' => 'Error al calcular posición']);
}
?>