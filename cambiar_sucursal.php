<?php
session_start();

// Seguridad: Solo usuarios logueados y Super Admins (Rol 1) pueden usar esto
// Si un usuario normal intenta entrar aquí, lo devolvemos al inicio.
if (!isset($_SESSION['user_id']) || $_SESSION['rol_id'] != 1) {
    header('Location: index.php');
    exit;
}

// Si recibimos el ID de la sucursal por POST
if (isset($_POST['sucursal_id'])) {
    $nuevaSucursal = (int)$_POST['sucursal_id'];
    
    // Actualizamos la variable de sesión que controla qué datos se ven
    $_SESSION['sucursal_activa'] = $nuevaSucursal;
    
    // Opcional: Mensaje de confirmación (si tu index.php soporta toasts, se verá)
    // $_SESSION['toast_message'] = 'Cambiado a la sucursal seleccionada.';
}

// Regresar inmediatamente a la página anterior (o al index si no hay referer)
// Esto hace que el usuario sienta que la página solo se "recargó" con los nuevos datos.
$redirect = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header("Location: $redirect");
exit;
?>