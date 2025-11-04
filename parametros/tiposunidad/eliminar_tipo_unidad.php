<?php
// filepath: c:\xampp\htdocs\proyectocont\parametros\tiposunidad\eliminar_tipo_unidad.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

ini_set('display_errors', 0);
require '../../config/database.php';

if (!isset($_GET['id'])) {
    $_SESSION['toast'] = ['type' => 'error', 'text' => 'Solicitud inválida.'];
    header('Location: tipos_unidad.php');
    exit;
}

$id = (int)$_GET['id'];

try {
    $pdo->beginTransaction();

    // 1) Eliminar modelos del tipo
    $stmtModelos = $pdo->prepare('DELETE FROM modelos WHERE tipo_unidad_id = :id');
    $stmtModelos->execute([':id' => $id]);

    // 2) Eliminar el tipo de unidad
    $stmtUnidad = $pdo->prepare('DELETE FROM tipos_unidad WHERE id = :id');
    $stmtUnidad->execute([':id' => $id]);

    // 3) Reiniciar AUTO_INCREMENT si quedaron vacías
    $countModelos = (int)$pdo->query('SELECT COUNT(*) FROM modelos')->fetchColumn();
    if ($countModelos === 0) {
        $pdo->exec('ALTER TABLE modelos AUTO_INCREMENT = 1');
    }
    $countTipos = (int)$pdo->query('SELECT COUNT(*) FROM tipos_unidad')->fetchColumn();
    if ($countTipos === 0) {
        $pdo->exec('ALTER TABLE tipos_unidad AUTO_INCREMENT = 1');
    }

    $pdo->commit();

    $_SESSION['toast'] = ['type' => 'success', 'text' => 'Tipo de unidad eliminado correctamente.'];
    header('Location: tipos_unidad.php');
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $sqlState   = $e->getCode();
    $driverCode = $e->errorInfo[1] ?? null;

    if ($sqlState === '23000' || $driverCode === 1451) {
        $_SESSION['toast'] = ['type' => 'error', 'text' => 'No se puede eliminar el tipo: hay contenedores usando sus modelos.'];
    } else {
        $_SESSION['toast'] = ['type' => 'error', 'text' => 'Error al eliminar. Inténtalo de nuevo.'];
    }

    header('Location: tipos_unidad.php');
    exit;
}
