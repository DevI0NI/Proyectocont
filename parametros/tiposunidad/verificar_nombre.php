<?php
// filepath: c:\xampp\htdocs\proyectocont\parametros\tiposunidad\verificar_nombre.php

// 1. SEGURIDAD: Iniciar sesión y verificar acceso
session_start();
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit;
}

require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 2. LIMPIEZA: Usar trim() para evitar problemas con espacios accidentales
    $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tipos_unidad WHERE nombre = :nombre AND id != :id');
        $stmt->execute([
            'nombre' => $nombre,
            'id' => $id,
        ]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            echo 'exists';
        } else {
            echo 'available';
        }
    } catch (Exception $e) {
        // En producción no es recomendable hacer echo del error exacto, pero para dev está bien
        echo 'error'; 
    }
}
?>
