<?php
require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patios = $_POST['patios'];

    try {
        $pdo->beginTransaction();

        foreach ($patios as $id => $patio) {
            // Actualizar el nombre del patio
            $stmt = $pdo->prepare('UPDATE patios SET nombre = :nombre WHERE id = :id');
            $stmt->execute(['nombre' => $patio['nombre'], 'id' => $id]);

            // Actualizar el número de bloques (si es necesario)
            $numeroBloques = (int)$patio['bloques'];
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM bloques WHERE patio_id = :id');
            $stmt->execute(['id' => $id]);
            $bloquesActuales = (int)$stmt->fetchColumn();

            if ($numeroBloques > $bloquesActuales) {
                // Agregar bloques adicionales
                for ($i = $bloquesActuales + 1; $i <= $numeroBloques; $i++) {
                $stmt = $pdo->prepare('INSERT INTO bloques (patio_id, numero, capacidad_maxima, capacidad_actual) VALUES (:patio_id, :numero, 25, 25)');
                $stmt->execute(['patio_id' => $id, 'numero' => $i]);
                }
            } elseif ($numeroBloques < $bloquesActuales) {
                // Eliminar bloques sobrantes
                $stmt = $pdo->prepare('DELETE FROM bloques WHERE patio_id = :id AND numero > :numero');
                $stmt->execute(['id' => $id, 'numero' => $numeroBloques]);
            }
        }

        $pdo->commit();
        header('Location: configuracion.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Error al guardar la configuración: ' . $e->getMessage());
    }
}
?>