<?php
// filepath: c:\xampp\htdocs\proyectov2\authenticate.php
session_start();
require 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Preparar y ejecutar la consulta SQL
    // Obtenemos todos los datos necesarios para la sesión
    $stmt = $pdo->prepare('SELECT id, username, password, rol_id, sucursal_id, estado_id FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verificar si se encontró el usuario
    if ($user) {
        
        // 1. Verificar si el usuario está ACTIVO
        if ($user['estado_id'] != 1) {
            $_SESSION['error'] = "Usuario desactivado. Contacte al administrador.";
            header('Location: login.php');
            exit;
        }

        // 2. Verificar la contraseña
        if (password_verify($password, $user['password'])) {
            // -- INICIO DE SESIÓN EXITOSO --
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['rol_id'] = (int)$user['rol_id'];
            
            // Guardamos la sucursal asignada en la base de datos
            $_SESSION['sucursal_usuario'] = $user['sucursal_id']; 

            // Lógica de Contexto (Sucursal Activa)
            if ($user['rol_id'] == 1) {
                // Es Super Admin:
                // Si tiene una sucursal asignada en la BD (como Puerto Barrios), iniciamos allí por defecto.
                // Si no tiene (es NULL), dejamos que index.php elija la primera disponible.
                if ($user['sucursal_id']) {
                    $_SESSION['sucursal_activa'] = $user['sucursal_id'];
                } else {
                    unset($_SESSION['sucursal_activa']);
                }
            } else {
                // Es Usuario Normal: Su sucursal activa es OBLIGATORIAMENTE su sucursal asignada.
                $_SESSION['sucursal_activa'] = $user['sucursal_id'];
            }

            // Redirección directa (sin JS, más seguro y rápido)
            header('Location: index.php');
            exit;

        } else {
            $_SESSION['error'] = "Contraseña incorrecta.";
            header('Location: login.php');
            exit;
        }
    } else {
        $_SESSION['error'] = "Usuario no encontrado.";
        header('Location: login.php');
        exit;
    }
}
?>