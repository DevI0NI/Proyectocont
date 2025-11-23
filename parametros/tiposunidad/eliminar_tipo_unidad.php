<?php
// filepath: c:\xampp\htdocs\proyectocont\parametros\tiposunidad\eliminar_tipo_unidad.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Configuración de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/database.php';

if (!isset($_GET['id'])) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'Solicitud inválida.'];
    header('Location: tipos_unidad.php');
    exit;
}

$id = (int)$_GET['id'];

try {
    $pdo->beginTransaction();

    // 1. VERIFICACIÓN DE INTEGRIDAD (USOS ACTIVOS)
    // No permitimos "borrar" si hay contenedores activos en el patio usando este tipo.
    // (Ignoramos los contenedores históricos/inactivos).
    $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM contenedores WHERE tipo_unidad_id = :id AND estado_id = 1');
    $stmtCheck->execute(['id' => $id]);
    $usosActivos = $stmtCheck->fetchColumn();

    if ($usosActivos > 0) {
        throw new Exception("No se puede eliminar: Hay $usosActivos contenedor(es) activo(s) usando este tipo de unidad.");
    }

    // 2. SOFT DELETE DE MODELOS (Cascada Lógica)
    // Desactivamos los modelos asociados a este tipo para mantener la coherencia.
    $stmtModelos = $pdo->prepare('UPDATE modelos SET estado_id = 2 WHERE tipo_unidad_id = :id');
    $stmtModelos->execute(['id' => $id]);

    // 3. SOFT DELETE DEL TIPO DE UNIDAD
    // Marcamos el registro principal como inactivo.
    $stmtUnidad = $pdo->prepare('UPDATE tipos_unidad SET estado_id = 2 WHERE id = :id');
    $stmtUnidad->execute(['id' => $id]);

    $pdo->commit();

    $_SESSION['toast'] = ['type' => 'success', 'text' => 'Tipo de unidad eliminado correctamente.'];
    header('Location: tipos_unidad.php');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Mostramos el mensaje de error específico (ej. "Hay contenedores activos...")
    $_SESSION['toast'] = ['type' => 'error', 'text' => $e->getMessage()];
    header('Location: tipos_unidad.php');
    exit;
}
?>