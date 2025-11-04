<?php
require '../config/database.php';

if (isset($_GET['tipo_unidad_id'])) {
    $tipoUnidadId = $_GET['tipo_unidad_id'];

    $stmt = $pdo->prepare('SELECT id, nombre FROM modelos WHERE tipo_unidad_id = :tipo_unidad_id');
    $stmt->execute(['tipo_unidad_id' => $tipoUnidadId]);
    $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($modelos);
}
?>