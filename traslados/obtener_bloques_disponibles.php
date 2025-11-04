<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

require '../config/database.php';

if (!isset($_GET['patio_id']) || empty($_GET['patio_id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'El ID del patio es requerido']);
    exit;
}

try {
    $patioId = (int) $_GET['patio_id'];

    // Obtener los bloques asociados al patio
    $sql = 'SELECT id, numero FROM bloques WHERE patio_id = :patio_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['patio_id' => $patioId]);
    $bloques = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$bloques) {
        echo json_encode(['error' => 'No se encontraron bloques para este patio.']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode($bloques);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}