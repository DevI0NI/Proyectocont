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

// Obtener ID
if (!isset($_GET['id'])) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'ID no especificado.'];
    header('Location: estados.php');
    exit;
}
$id = (int)$_GET['id'];

try {
    $pdo->beginTransaction();

    // 1. VERIFICACIÓN DE INTEGRIDAD (Usos Activos)
    // Buscamos si algún contenedor ACTIVO (estado_id=1) usa este estatus
    // ya sea como estatus de unidad O como estatus de box.
    $stmtCheck = $pdo->prepare('
        SELECT COUNT(*) FROM contenedores 
        WHERE (estatus_unidad_id = :id OR estatus_box_id = :id) 
        AND estado_id = 1
    ');
    $stmtCheck->execute(['id' => $id]);
    $usosActivos = $stmtCheck->fetchColumn();

    if ($usosActivos > 0) {
        // Bloquear eliminación
        throw new Exception("No se puede eliminar: Hay $usosActivos contenedor(es) activo(s) usando este estatus.");
    }

    // 2. SOFT DELETE
    // Marcamos el estatus como inactivo (estado_id = 2)
    $stmtDelete = $pdo->prepare('UPDATE estatus_contenedores SET estado_id = 2 WHERE id = :id');
    $stmtDelete->execute(['id' => $id]);

    // Obtener nombre del tipo para redirigir al filtro correcto
    // Hacemos esto antes de confirmar, aunque el registro ya estará marcado como inactivo
    $stmtTipo = $pdo->prepare('
        SELECT te.nombre 
        FROM estatus_contenedores ec
        JOIN tipos_estatus te ON ec.tipo_estatus_id = te.id
        WHERE ec.id = :id
    ');
    $stmtTipo->execute(['id' => $id]);
    $nombreTipo = $stmtTipo->fetchColumn() ?: 'Unidad';

    $pdo->commit();

    $_SESSION['toast'] = ['type' => 'success', 'text' => 'Estatus eliminado correctamente.'];
    header('Location: estados.php?tipo=' . urlencode($nombreTipo));
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $_SESSION['toast'] = ['type' => 'error', 'text' => $e->getMessage()];
    // Intentamos redirigir de vuelta, pero como hubo error, redirigimos a la vista general
    header('Location: estados.php');
    exit;
}
?>