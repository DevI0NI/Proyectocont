<?php
require '../config/database.php';

if (isset($_GET['patio_id'])) {
    $patioId = $_GET['patio_id'];

    $stmt = $pdo->prepare('SELECT id, numero FROM bloques WHERE patio_id = :patio_id');
    $stmt->execute(['patio_id' => $patioId]);
    $bloques = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($bloques);
}
?>