<?php
// filepath: c:\xampp\htdocs\proyectocont\traslados\obtener_info_contenedor.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

require '../config/database.php';

if (!isset($_GET['contenedor_id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'El ID del contenedor es requerido']);
    exit;
}

try {
    $contenedorId = (int) $_GET['contenedor_id'];

    $sql = '
        SELECT c.id, c.nombre, c.posicion, b.numero AS bloque_numero, p.nombre AS patio_nombre
        FROM contenedores c
        INNER JOIN bloques b ON c.bloque_id = b.id
        INNER JOIN patios p ON b.patio_id = p.id
        WHERE c.id = :contenedor_id
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['contenedor_id' => $contenedorId]);
    $contenedor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contenedor) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['error' => 'Contenedor no encontrado']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode($contenedor);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}