<?php
require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = $_POST['nombre'];
    $nombreOriginal = $_POST['nombre_original'];

    // Verificar si el contenedor ya existe, excluyendo el nombre actual
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE nombre = :nombre AND nombre != :nombre_original');
    $stmt->execute([
        'nombre' => $nombre,
        'nombre_original' => $nombreOriginal
    ]);
    
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        echo 'exists';
    } else {
        echo 'not_exists';
    }
}
?>