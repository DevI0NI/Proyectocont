<?php
session_start();

// 1. Seguridad: Bloquear acceso directo
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

require '../config/database.php';

if (isset($_GET['patio_id'])) {
    $patioId = (int)$_GET['patio_id'];

    try {
        // 2. Consulta Corregida
        // - Filtramos por estado_id = 1 (Solo activos)
        // - Seleccionamos capacidad_maxima y actual (Necesarios para el JS)
        $stmt = $pdo->prepare('
            SELECT id, numero, capacidad_maxima, capacidad_actual 
            FROM bloques 
            WHERE patio_id = :patio_id AND estado_id = 1 
            ORDER BY numero ASC
        ');
        
        $stmt->execute(['patio_id' => $patioId]);
        $bloques = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Devolver JSON
        header('Content-Type: application/json');
        echo json_encode($bloques);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al cargar bloques']);
    }
}
?>