<?php
require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = $_POST['nombre'];

    // Verificar si el contenedor ya existe en la base de datos
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE nombre = :nombre');
    $stmt->execute(['nombre' => $nombre]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        echo 'exists';
    } else {
        echo 'not_exists';
    }
}
?>