<?php
session_start();
// Seguridad: Solo Admins/Super Admins pueden acceder a este validador
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol_id'], [1, 2])) { 
    header("HTTP/1.1 401 Unauthorized"); 
    exit; 
}
require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Identificar qué campo se está validando (username o email)
    $field = isset($_POST['username']) ? 'username' : (isset($_POST['email']) ? 'email' : '');
    $value = trim($_POST[$field] ?? '');

    if (empty($field) || empty($value)) {
        echo 'error';
        exit;
    }

    try {
        // La consulta verifica si el valor existe en la tabla de usuarios.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $field = :val AND estado_id = 1");
        $stmt->execute(['val' => $value]);
        $count = $stmt->fetchColumn();

        // Nota: Al no usar 'AND id != :id', este script NO sirve para editar, solo para creación.
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