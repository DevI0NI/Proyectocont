<?php
session_start();

// 1. Seguridad: Verificar Sesión y Sucursal Activa
if (!isset($_SESSION['user_id']) || !isset($_SESSION['sucursal_activa'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'No autorizado']);
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
    $sucursalId = $_SESSION['sucursal_activa'];

    // 2. Consulta Segura con Filtros de Integridad
    $sql = '
        SELECT 
            c.id, 
            c.nombre, 
            c.posicion, 
            b.numero AS bloque_numero, 
            p.nombre AS patio_nombre
        FROM contenedores c
        INNER JOIN bloques b ON c.bloque_id = b.id
        INNER JOIN patios p ON b.patio_id = p.id
        WHERE c.id = :contenedor_id
          AND c.estado_id = 1           -- Solo contenedores activos
          AND p.sucursal_id = :sucursal -- Solo de la sucursal actual
    ';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'contenedor_id' => $contenedorId,
        'sucursal' => $sucursalId
    ]);
    
    $contenedor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contenedor) {
        // Si no existe, está borrado o es de otra sucursal
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['error' => 'Contenedor no encontrado o acceso denegado']);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode($contenedor);

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    // En producción, evita enviar $e->getMessage() directo
    echo json_encode(['error' => 'Error al procesar la solicitud']);
}
?>