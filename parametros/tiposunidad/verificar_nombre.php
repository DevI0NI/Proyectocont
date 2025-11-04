<?php
// filepath: c:\xampp\htdocs\proyectocont\parametros\tiposunidad\verificar_nombre.php

require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = $_POST['nombre'];

    try {
        // Preparar la consulta para verificar si el nombre ya existe
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tipos_unidad WHERE nombre = :nombre');
        $stmt->execute(['nombre' => $nombre]);
        $count = $stmt->fetchColumn();

        // Si el nombre ya existe, devolver "exists", de lo contrario "available"
        if ($count > 0) {
            echo 'exists';
        } else {
            echo 'available';
        }
    } catch (Exception $e) {
        // Manejar errores
        echo 'error';
    }
}
?>