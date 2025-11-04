<?php
$password = '222080';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
echo $hashed_password;
?>