<?php
date_default_timezone_set('Asia/Jakarta');

$DB_HOST = 'localhost';
$DB_NAME = 'farmasi_ems';
$DB_USER = 'root';
$DB_PASS = 'Jal&jar123';

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
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    die("Database connection failed");
}
