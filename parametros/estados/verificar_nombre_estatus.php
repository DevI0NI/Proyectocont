<?php
require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']);
    $tipo = $_POST['tipo'];

    try {
        // Verificar si el nombre ya existe para el tipo seleccionado
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM estatus_contenedores WHERE nombre = :nombre AND tipo = :tipo');
        $stmt->execute(['nombre' => $nombre, 'tipo' => $tipo]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            echo 'exists';
        } else {
            echo 'available';
        }
    } catch (Exception $e) {
        echo 'error';
    }
}
?>