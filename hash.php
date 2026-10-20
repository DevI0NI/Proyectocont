<?php
$password = 'jp123';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
echo $hashed_password;
?>

//Esto es temporal inge, es solo para ver las contraseñas ya hasheadas