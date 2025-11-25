<?php
session_start();

// 1. Seguridad: Bloquear acceso directo
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // No autorizado
    exit;
}

require '../config/database.php';

if (isset($_GET['tipo_unidad_id'])) {
    $tipoUnidadId = (int)$_GET['tipo_unidad_id'];

    try {
        // 2. Consulta Corregida (Filtrar por estado_id = 1)
        $stmt = $pdo->prepare('
            SELECT id, nombre 
            FROM modelos 
            WHERE tipo_unidad_id = :tipo_unidad_id AND estado_id = 1 
            ORDER BY nombre ASC
        ');
        
        $stmt->execute(['tipo_unidad_id' => $tipoUnidadId]);
        $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($modelos);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al cargar modelos']);
    }
}
?>