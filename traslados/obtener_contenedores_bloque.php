<?php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['sucursal_activa'])) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

require '../config/database.php';

$bloqueId = isset($_GET['bloque_id']) ? (int)$_GET['bloque_id'] : 0;
$sucursalId = (int)$_SESSION['sucursal_activa'];

if ($bloqueId <= 0) {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'El bloque es requerido']);
    exit;
}

try {
    $stmtMeta = $pdo->prepare('
        SELECT
            b.id,
            b.numero,
            p.id AS patio_id,
            p.nombre AS patio_nombre
        FROM bloques b
        JOIN patios p ON b.patio_id = p.id
        WHERE b.id = :bloque
          AND b.estado_id = 1
          AND p.estado_id = 1
          AND p.sucursal_id = :sucursal
        LIMIT 1
    ');
    $stmtMeta->execute([
        'bloque' => $bloqueId,
        'sucursal' => $sucursalId
    ]);
    $meta = $stmtMeta->fetch(PDO::FETCH_ASSOC);

    if (!$meta) {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Bloque no encontrado o acceso denegado']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT
            c.id,
            c.nombre,
            c.posicion,
            eu.nombre AS estatus_unidad,
            eb.nombre AS estatus_box,
            ei.nombre AS estatus_ipsa,
            (
                SELECT COUNT(*)
                FROM contenedores c2
                WHERE c2.bloque_id = c.bloque_id
                  AND c2.estado_id = 1
                  AND c2.posicion < c.posicion
            ) AS bloqueadores
        FROM contenedores c
        LEFT JOIN estatus_contenedores eu ON c.estatus_unidad_id = eu.id
        LEFT JOIN estatus_contenedores eb ON c.estatus_box_id = eb.id
        LEFT JOIN estatus_ipsa ei ON c.estatus_ipsa_id = ei.id
        WHERE c.bloque_id = :bloque
          AND c.estado_id = 1
        ORDER BY c.posicion ASC, c.nombre ASC
    ');
    $stmt->execute(['bloque' => $bloqueId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $contenedores = [];
    $trasladables = 0;

    foreach ($rows as $row) {
        $bloqueadores = (int)$row['bloqueadores'];
        $puede = $bloqueadores === 0;
        if ($puede) {
            $trasladables++;
        }

        $contenedores[] = [
            'id' => (int)$row['id'],
            'nombre' => $row['nombre'],
            'posicion' => (int)$row['posicion'],
            'estatus_unidad' => $row['estatus_unidad'] ?: '',
            'estatus_box' => $row['estatus_box'] ?: '',
            'estatus_ipsa' => $row['estatus_ipsa'] ?: '',
            'bloqueadores' => $bloqueadores,
            'puede_trasladar' => $puede,
            'motivo_bloqueo' => $puede
                ? 'Libre para traslado.'
                : 'Tiene ' . $bloqueadores . ' contenedor(es) bloqueando la salida.'
        ];
    }

    header('Content-Type: application/json');
    echo json_encode([
        'meta' => [
            'patio_id' => (int)$meta['patio_id'],
            'patio_nombre' => $meta['patio_nombre'],
            'bloque_id' => (int)$meta['id'],
            'bloque_numero' => (string)$meta['numero'],
            'total' => count($contenedores),
            'trasladables' => $trasladables
        ],
        'contenedores' => array_map(function ($item) use ($meta) {
            $item['patio_nombre'] = $meta['patio_nombre'];
            $item['bloque_numero'] = (string)$meta['numero'];
            return $item;
        }, $contenedores)
    ]);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Error al consultar los contenedores del bloque']);
}
