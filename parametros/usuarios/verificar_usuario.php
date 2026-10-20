<?php
session_start();

if (!isset($_SESSION['user_id']) || !in_array((int)($_SESSION['rol_id'] ?? 0), [1, 2], true)) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

require '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentId = (int)($_POST['id'] ?? 0);
    $field = isset($_POST['username']) ? 'username' : (isset($_POST['email']) ? 'email' : '');
    $value = trim($_POST[$field] ?? '');

    if ($field === '' || $value === '') {
        echo 'error';
        exit;
    }

    try {
        $sql = "SELECT estado_id FROM users WHERE $field = :val";
        $params = ['val' => $value];

        if ($currentId > 0) {
            $sql .= ' AND id != :id';
            $params['id'] = $currentId;
        }

        $sql .= ' ORDER BY estado_id ASC, id DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $estado = $stmt->fetchColumn();

        if ($estado === false) {
            echo 'available';
        } elseif ((int)$estado === 1) {
            echo 'exists';
        } else {
            echo 'inactive';
        }
    } catch (Exception $e) {
        echo 'error';
    }
}
?>
