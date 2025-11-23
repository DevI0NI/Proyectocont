<?php
// filepath: parametros/sucursales/verificar_sucursal.php

// 1. SEGURIDAD: Bloquear acceso directo
session_start();
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit;
}

require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Limpiar entrada
    $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    
    // ID opcional para edición (para excluir el registro actual de la búsqueda)
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($nombre === '') {
        echo 'error';
        exit;
    }

    try {
        // 2. VALIDACIÓN ESTRICTA (GLOBAL)
        // Buscamos si existe el nombre en todo el historial.
        // Excluimos el ID actual (útil si este script se reutiliza en edición).
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sucursales WHERE nombre = :nombre AND id != :id');
        
        $stmt->execute([
            'nombre' => $nombre,
            'id' => $id
        ]);
        
        $count = $stmt->fetchColumn();

        // 3. RESPUESTA
        if ($count > 0) {
            echo 'exists';
        } else {
            echo 'available';
        }
    } catch (Exception $e) {
        // En caso de error de BD
        echo 'error';
    }
}
?>