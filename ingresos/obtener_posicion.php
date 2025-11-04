<?php
require '../config/database.php';

if (isset($_GET['bloque_id'])) {
    $bloqueId = $_GET['bloque_id'];

    // Obtener la capacidad máxima del bloque
    $stmt = $pdo->prepare('SELECT capacidad_maxima FROM bloques WHERE id = :bloque_id');
    $stmt->execute(['bloque_id' => $bloqueId]);
    $capacidadMaxima = $stmt->fetchColumn();

    // Obtener las posiciones ocupadas en el bloque
    $stmt = $pdo->prepare('SELECT posicion FROM contenedores WHERE bloque_id = :bloque_id');
    $stmt->execute(['bloque_id' => $bloqueId]);
    $posicionesOcupadas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Calcular la posición más alta disponible
    $posicionDisponible = $capacidadMaxima;
    while (in_array($posicionDisponible, $posicionesOcupadas)) {
        $posicionDisponible--;
    }

    echo json_encode(['posicion' => $posicionDisponible]);
}
?> 