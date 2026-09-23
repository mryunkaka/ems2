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

if (!function_exists('ems_create_database_connection')) {
    function ems_create_database_connection(): PDO
    {
    $host = (string) ems_env('DB_HOST', '127.0.0.1');
    $name = (string) ems_env('DB_NAME', '');
    $user = (string) ems_env('DB_USER', '');
    $pass = (string) ems_env('DB_PASS', '');
    $timezone = (string) ems_env('DB_TIMEZONE', '+07:00');

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database configuration missing');
    }

    $connection = new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]
    );
    $connection->exec("SET time_zone = " . $connection->quote($timezone));

        return $connection;
    }
}

/**
 * MariaDB hosting can close idle PDO connections while an external AI request
 * is running. Reconnect before the next DB operation instead of exposing
 * SQLSTATE[HY000] 2006 to the user.
 */
if (!function_exists('ems_reconnect_database_if_needed')) {
    function ems_reconnect_database_if_needed(PDO &$pdo): void
    {
        try {
            $pdo->query('SELECT 1');
        } catch (PDOException $e) {
            $pdo = ems_create_database_connection();
        }
    }
}

try {
    $pdo = ems_create_database_connection();
} catch (Throwable $e) {
    die("Database connection failed");
}
