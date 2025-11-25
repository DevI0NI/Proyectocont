<?php
session_start();

// 1. Seguridad
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']);

    try {
        // 2. Verificación Inteligente
        // Buscamos si existe y cuál es su estado actual
        $stmt = $pdo->prepare('SELECT estado_id FROM contenedores WHERE nombre = :nombre');
        $stmt->execute(['nombre' => $nombre]);
        $contenedor = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contenedor) {
            if ($contenedor['estado_id'] == 1) {
                // Ya existe y está ACTIVO en el patio -> ERROR (No duplicar)
                echo 'exists';
            } else {
                // Existe pero está INACTIVO (Estado 2) -> DISPONIBLE (Reingreso)
                // El backend (ingresos.php) deberá manejar el UPDATE para reactivarlo
                echo 'available'; // O 'reentry' si quisieras manejar un mensaje especial en JS
            }
        } else {
            // No existe -> DISPONIBLE (Nuevo Ingreso)
            echo 'available'; // O 'not_exists'
        }

    } catch (Exception $e) {
        echo 'error';
    }
}
?>