<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

require '../config/database.php';

$query = isset($_GET['query']) ? $_GET['query'] : '';

try {
    $sql = '
        SELECT id, nombre
        FROM contenedores
        WHERE nombre LIKE :query
        ORDER BY nombre
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['query' => '%' . $query . '%']);
    $contenedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($contenedores);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}