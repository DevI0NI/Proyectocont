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

    // Obtener la capacidad máxima del bloque
    $stmt = $pdo->prepare('SELECT capacidad_maxima FROM bloques WHERE id = :bloque_id');
    $stmt->execute(['bloque_id' => $bloqueId]);
    $capacidadMaxima = $stmt->fetchColumn();

    if (!$capacidadMaxima) {
        echo json_encode(['error' => 'Bloque no encontrado']);
        exit;
    }

    // Obtener las posiciones ocupadas en el bloque
    $stmt = $pdo->prepare('SELECT posicion FROM contenedores WHERE bloque_id = :bloque_id');
    $stmt->execute(['bloque_id' => $bloqueId]);
    $posicionesOcupadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Calcular la posición más alta disponible
    $posicionDisponible = $capacidadMaxima;
    while (in_array($posicionDisponible, $posicionesOcupadas)) {
        $posicionDisponible--;
    }

    if ($posicionDisponible > 0) {
        echo json_encode(['posicion' => $posicionDisponible]);
    } else {
        echo json_encode(['error' => 'No hay posiciones disponibles en este bloque.']);
    }
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}