<?php
require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']);
    $tipo = $_POST['tipo'];
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    try {
        // Verificar si el nombre ya existe para el tipo seleccionado (excluyendo el actual)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM estatus_contenedores WHERE nombre = :nombre AND tipo = :tipo AND id != :id');
        $stmt->execute(['nombre' => $nombre, 'tipo' => $tipo, 'id' => $id]);
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