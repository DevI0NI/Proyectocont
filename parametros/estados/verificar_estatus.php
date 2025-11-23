<?php
// filepath: c:\xampp\htdocs\proyectocont\parametros\estados\verificar_estatus.php

session_start();
// 1. Seguridad: Bloquear acceso directo
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit;
}

require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = trim($_POST['nombre']);
    $nombreTipo = $_POST['tipo']; // Recibimos "Unidad" o "Estructura"
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    try {
        // 2. Obtener el ID del Tipo de Estatus
        // Como la nueva BD usa relaciones, primero buscamos qué ID tiene "Unidad"
        $stmtTipo = $pdo->prepare('SELECT id FROM tipos_estatus WHERE nombre = :nombre');
        $stmtTipo->execute(['nombre' => $nombreTipo]);
        $tipoId = $stmtTipo->fetchColumn();

        if (!$tipoId) {
            // Si envían un tipo raro, devolvemos error
            echo 'error';
            exit;
        }

        // 3. Verificar existencia (Validación Estricta)
        // Buscamos nombre + tipo_estatus_id, EXCLUYENDO el ID actual (id != :id)
        // No filtramos por estado_id para evitar duplicar nombres históricos.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM estatus_contenedores WHERE nombre = :nombre AND tipo_estatus_id = :tipo_id AND id != :id');
        $stmt->execute([
            'nombre' => $nombre, 
            'tipo_id' => $tipoId, 
            'id' => $id
        ]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            echo 'exists';
        } else {
            echo 'available';
        }
    } catch (Exception $e) {
        echo 'error';
    }
}
?>