<?php
session_start();

// 1. Seguridad de sesión y contexto
if (!isset($_SESSION['user_id']) || !isset($_SESSION['sucursal_activa'])) {
    // Si no hay sesión o sucursal, devolvemos vacío o error
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

require '../config/database.php';

$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$sucursal_id = $_SESSION['sucursal_activa'];

if ($query === '') {
    echo json_encode([]);
    exit;
}

try {
    // 2. Consulta con JOINs para filtrar por Sucursal
    // Buscamos el contenedor, pero verificamos que su bloque pertenezca a un patio de ESTA sucursal.
    $sql = '
        SELECT c.id, c.nombre
        FROM contenedores c
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        WHERE c.nombre LIKE :query
          AND c.estado_id = 1          -- Solo contenedores activos
          AND p.sucursal_id = :sucursal -- Solo de esta sucursal
        ORDER BY c.nombre
        LIMIT 10
    ';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'query' => '%' . $query . '%',
        'sucursal' => $sucursal_id
    ]);
    
    $contenedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($contenedores);

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Error al buscar']);
}
?>