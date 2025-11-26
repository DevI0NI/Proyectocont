<?php
session_start();

// 1. SEGURIDAD: Bloquear acceso si no hay sesión o sucursal activa
if (!isset($_SESSION['user_id']) || !isset($_SESSION['sucursal_activa'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'No autorizado']);
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
    $sucursalId = $_SESSION['sucursal_activa'];

    // 2. VALIDAR PERTENENCIA A SUCURSAL
    // Nos aseguramos de que el patio solicitado pertenezca a la sucursal activa.
    // Si alguien intenta cambiar el ID en la URL para ver datos de otra sucursal, esto lo detiene.
    $stmtCheck = $pdo->prepare('SELECT id FROM patios WHERE id = :id AND sucursal_id = :sucursal AND estado_id = 1');
    $stmtCheck->execute(['id' => $patioId, 'sucursal' => $sucursalId]);
    
    if (!$stmtCheck->fetch()) {
        // Si el patio no es válido o no es de esta sucursal, devolvemos lista vacía
        echo json_encode([]); 
        exit;
    }

    // 3. OBTENER BLOQUES (Con datos de capacidad)
    // Agregamos capacidad_maxima y capacidad_actual a la consulta
    $sql = '
        SELECT id, numero, capacidad_maxima, capacidad_actual 
        FROM bloques 
        WHERE patio_id = :patio_id AND estado_id = 1 
        ORDER BY numero ASC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['patio_id' => $patioId]);
    $bloques = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$bloques) {
        // En lugar de error 404, devolvemos un array vacío que es más fácil de manejar en JS
        echo json_encode([]);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode($bloques);

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    // En producción, no mostrar el mensaje exacto de la BD por seguridad
    echo json_encode(['error' => 'Error al consultar la base de datos']);
}
?>