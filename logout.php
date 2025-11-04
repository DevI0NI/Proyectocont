<?php
// filepath: c:\xampp\htdocs\proyectov2\logout.php
session_start();
session_destroy();
header('Location: login.php');
exit;
?>