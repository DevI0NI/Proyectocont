<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['sucursal_activa'])) {
    echo json_encode([]); exit;
}

require '../config/database.php';

$query = $_GET['query'] ?? '';
$sucursal_id = $_SESSION['sucursal_activa'];

if (strlen($query) < 2) { echo json_encode([]); exit; }

try {
    // Buscar contenedores activos que coincidan
    $sql = "
        SELECT 
            c.id, c.nombre, c.posicion, c.bloque_id,
            c.estatus_ipsa_id, ei.nombre as estatus_ipsa,
            pa.estatus_pyp,
            b.numero as bloque, p.nombre as patio
        FROM contenedores c
        JOIN bloques b ON c.bloque_id = b.id
        JOIN patios p ON b.patio_id = p.id
        LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
        LEFT JOIN pyp_auditoria pa ON c.id = pa.contenedor_id
        WHERE c.nombre LIKE :q 
          AND c.estado_id = 1
          AND p.sucursal_id = :suc
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['q' => "%$query%", 'suc' => $sucursal_id]);
    $contenedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular bloqueo físico para cada resultado
    foreach ($contenedores as &$cont) {
        $stmtBlock = $pdo->prepare("
            SELECT COUNT(*) FROM contenedores 
            WHERE bloque_id = :bid 
              AND posicion < :pos 
              AND estado_id = 1
        ");
        $stmtBlock->execute(['bid' => $cont['bloque_id'], 'pos' => $cont['posicion']]);
        // Si hay alguien en una posición menor (más cerca de la salida = 1), entonces NO está bloqueado?
        // ESPERA: Tu lógica es inversa (Stacking). 
        // 25 (Fondo) -> 1 (Salida/Frente).
        // Si estoy en la 25, y la 24 está ocupada, NO puedo salir.
        // Entonces: Bloqueado si existe alguien con posicion < mi_posicion.
        
        $cont['bloqueado_fisicamente'] = ($stmtBlock->fetchColumn() > 0);
    }

    echo json_encode($contenedores);

} catch (Exception $e) {
    echo json_encode([]);
}
?>