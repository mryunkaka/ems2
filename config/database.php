<?php
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/env.php';

$DB_HOST = (string)ems_env('DB_HOST', '127.0.0.1');
$DB_NAME = (string)ems_env('DB_NAME', '');
$DB_USER = (string)ems_env('DB_USER', '');
$DB_PASS = (string)ems_env('DB_PASS', '');
$DB_TIMEZONE = (string)ems_env('DB_TIMEZONE', '+07:00');

if ($DB_NAME === '' || $DB_USER === '') {
    http_response_code(500);
    exit('Database configuration missing');
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    $pdo->exec("SET time_zone = " . $pdo->quote($DB_TIMEZONE));
} catch (PDOException $e) {
    die("Database connection failed");
}
