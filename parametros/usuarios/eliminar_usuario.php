<?php
session_start();

// 1. Seguridad de Sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// 2. Seguridad de Rol (Solo Super Admin y Admin)
if (!in_array($_SESSION['rol_id'], [1, 2])) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'Acceso denegado.'];
    header('Location: ../../index.php');
    exit;
}

require '../../config/database.php';

// Validar ID
if (!isset($_GET['id'])) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'ID de usuario no especificado.'];
    header('Location: usuarios.php');
    exit;
}

$id = (int)$_GET['id'];
$mi_id = $_SESSION['user_id'];
$mi_rol = $_SESSION['rol_id'];

try {
    // 3. VALIDACIÓN CRÍTICA: AUTOPROTECCIÓN
    if ($id == $mi_id) {
        throw new Exception('No puedes eliminar tu propia cuenta mientras estás conectado.');
    }

    // Obtener datos del usuario objetivo para verificar jerarquía
    $stmtUser = $pdo->prepare("SELECT rol_id, username FROM users WHERE id = :id");
    $stmtUser->execute(['id' => $id]);
    $targetUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        throw new Exception("El usuario no existe.");
    }

    // 4. VALIDACIÓN DE JERARQUÍA
    // Si soy Admin (2), no puedo tocar a un Super Admin (1) ni a otro Admin (2)
    if ($mi_rol == 2 && $targetUser['rol_id'] <= 2) {
        throw new Exception("No tienes permisos para eliminar a un usuario de nivel Administrador o superior.");
    }

    // 5. SOFT DELETE (Desactivación)
    $pdo->beginTransaction();
    
    // Marcamos como inactivo (estado_id = 2)
    $stmtDel = $pdo->prepare("UPDATE users SET estado_id = 2 WHERE id = :id");
    $stmtDel->execute(['id' => $id]);
    
    $pdo->commit();

    $_SESSION['toast'] = ['type' => 'success', 'text' => 'Usuario "' . $targetUser['username'] . '" desactivado correctamente.'];

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['toast'] = ['type' => 'error', 'text' => $e->getMessage()];
}

// Redirigir siempre al listado
header('Location: usuarios.php');
exit;
?>