<?php
session_start();

// 1. Seguridad de Sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// 2. Seguridad de Rol: SOLO SUPER ADMIN
if ($_SESSION['rol_id'] != 1) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'Acceso denegado. Solo el Super Admin puede eliminar sucursales.'];
    header('Location: sucursales.php');
    exit;
}

// Habilitar errores para desarrollo
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

if (!isset($_GET['id'])) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'ID de sucursal no especificado.'];
    header('Location: sucursales.php');
    exit;
}

$id = (int)$_GET['id'];

try {
    $pdo->beginTransaction();

    // 3. VERIFICACIÓN DE PATIOS ACTIVOS
    // Una sucursal no puede borrarse si tiene patios funcionando
    $stmtCheckPatios = $pdo->prepare('SELECT COUNT(*) FROM patios WHERE sucursal_id = :id AND estado_id = 1');
    $stmtCheckPatios->execute(['id' => $id]);
    $patiosActivos = $stmtCheckPatios->fetchColumn();

    if ($patiosActivos > 0) {
        throw new Exception("No se puede eliminar: Esta sucursal tiene $patiosActivos patio(s) activos asociados. Desactívelos primero.");
    }

    // 4. VERIFICACIÓN DE USUARIOS ACTIVOS
    // Una sucursal no puede borrarse si tiene personal asignado
    $stmtCheckUsers = $pdo->prepare('SELECT COUNT(*) FROM users WHERE sucursal_id = :id AND estado_id = 1');
    $stmtCheckUsers->execute(['id' => $id]);
    $usuariosActivos = $stmtCheckUsers->fetchColumn();

    if ($usuariosActivos > 0) {
        throw new Exception("No se puede eliminar: Hay $usuariosActivos usuario(s) activo(s) asignados a esta sucursal.");
    }

    // 5. SOFT DELETE
    // Si pasó las verificaciones, es seguro desactivarla
    $stmtDelete = $pdo->prepare('UPDATE sucursales SET estado_id = 2 WHERE id = :id');
    $stmtDelete->execute(['id' => $id]);

    $pdo->commit();

    $_SESSION['toast'] = ['type' => 'success', 'text' => 'Sucursal eliminada correctamente.'];

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['toast'] = ['type' => 'error', 'text' => $e->getMessage()];
}

// Redirigir siempre al listado
header('Location: sucursales.php');
exit;
?>