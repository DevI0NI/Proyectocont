<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Habilitar la visualización de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    // Eliminar el estatus de la base de datos
    $stmt = $pdo->prepare('DELETE FROM estatus_contenedores WHERE id = :id');
    $stmt->execute(['id' => $id]);

    // Redirigir a la página de estatus
    header('Location: estados.php');
    exit;
} catch (Exception $e) {
    die('Error al eliminar el estatus: ' . $e->getMessage());
}
?>