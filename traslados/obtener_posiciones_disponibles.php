<?php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['sucursal_activa'])) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

require '../config/database.php';

if (!isset($_GET['bloque_id']) || empty($_GET['bloque_id'])) {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'El ID del bloque es requerido']);
    exit;
}

try {
    $bloqueId = (int)$_GET['bloque_id'];
    $sucursalId = (int)$_SESSION['sucursal_activa'];

    $stmt = $pdo->prepare('
        SELECT b.capacidad_maxima
        FROM bloques b
        JOIN patios p ON b.patio_id = p.id
        WHERE b.id = :bloque_id
          AND b.estado_id = 1
          AND p.estado_id = 1
          AND p.sucursal_id = :sucursal
        LIMIT 1
    ');
    $stmt->execute([
        'bloque_id' => $bloqueId,
        'sucursal' => $sucursalId
    ]);
    $capacidadMaxima = $stmt->fetchColumn();

    if ($capacidadMaxima === false) {
        echo json_encode(['error' => 'Bloque no encontrado o acceso denegado']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT posicion FROM contenedores WHERE bloque_id = :bloque_id AND estado_id = 1');
    $stmt->execute(['bloque_id' => $bloqueId]);
    $posicionesOcupadas = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $posicionDisponible = (int)$capacidadMaxima;
    while (in_array($posicionDisponible, $posicionesOcupadas, true) && $posicionDisponible > 0) {
        $posicionDisponible--;
    }

    header('Content-Type: application/json');
    if ($posicionDisponible > 0) {
        echo json_encode([
            'posicion' => $posicionDisponible,
            'ocupadas' => $posicionesOcupadas
        ]);
    } else {
        echo json_encode(['error' => 'El bloque está completamente lleno.']);
    }
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Error al calcular la posición disponible']);
}
