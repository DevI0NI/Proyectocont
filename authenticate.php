<?php
// filepath: c:\xampp\htdocs\proyectov2\authenticate.php
session_start();
require 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Preparar y ejecutar la consulta SQL
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    // Verificar si se encontró el usuario
    if ($user) {
        // Verificar la contraseña
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username']; // Asumiendo que hay un campo 'username' en la tabla 'users'
            echo '<script>
                    setTimeout(function() {
                        window.location.href = "index.php";
                    }, 500);
                  </script>';
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