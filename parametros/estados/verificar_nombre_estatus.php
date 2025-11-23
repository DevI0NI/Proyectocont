<?php
// filepath: c:\xampp\htdocs\proyectocont\parametros\estados\verificar_nombre_estatus.php

// 1. SEGURIDAD: Bloquear acceso directo
session_start();
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit;
}

require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Limpiar entradas
    $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $nombreTipo = isset($_POST['tipo']) ? $_POST['tipo'] : ''; // Recibimos "Unidad" o "Estructura"
    
    // ID opcional para edición (para excluir el registro actual de la búsqueda)
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($nombre === '' || $nombreTipo === '') {
        echo 'error';
        exit;
    }

    try {
        // 2. OBTENER ID DEL TIPO
        // Buscamos el ID que corresponde al nombre del tipo (ej. Unidad -> 1)
        $stmtTipo = $pdo->prepare('SELECT id FROM tipos_estatus WHERE nombre = :nombre');
        $stmtTipo->execute(['nombre' => $nombreTipo]);
        $tipoId = $stmtTipo->fetchColumn();

        if (!$tipoId) {
            // Si el tipo no existe en la BD, no podemos validar correctamente
            echo 'error';
            exit;
        }

        // 3. VALIDACIÓN ESTRICTA (GLOBAL)
        // Buscamos si existe el nombre para ese tipo de estatus.
        // Ignoramos estado_id (buscamos en todo el historial).
        // Excluimos el ID actual (útil si este script se reutiliza en edición).
        $stmt = $pdo->prepare('
            SELECT COUNT(*) 
            FROM estatus_contenedores 
            WHERE nombre = :nombre 
            AND tipo_estatus_id = :tipo_id 
            AND id != :id
        ');
        
        $stmt->execute([
            'nombre' => $nombre, 
            'tipo_id' => $tipoId,
            'id' => $id
        ]);
        
        $count = $stmt->fetchColumn();

        // 4. RESPUESTA
        if ($count > 0) {
            echo 'exists';
        } else {
            echo 'available';
        }
    } catch (Exception $e) {
        // En desarrollo puedes hacer echo $e->getMessage(), pero en prod mejor solo 'error'
        echo 'error';
    }
}
?>