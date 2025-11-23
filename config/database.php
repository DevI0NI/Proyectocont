<?php
// filepath: c:\xampp\htdocs\proyectov2\config\database.php
$host = 'localhost';
$db = 'proyectocont_v2';
$user = 'root'; // Cambia esto si tu usuario de MySQL es diferente
$pass = ''; // Cambia esto si tu contraseña de MySQL es diferente

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error al conectar a la base de datos: " . $e->getMessage());
}
?>