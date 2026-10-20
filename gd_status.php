<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'loaded_ini' => php_ini_loaded_file(),
    'gd_loaded' => extension_loaded('gd'),
    'php_version' => PHP_VERSION,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
