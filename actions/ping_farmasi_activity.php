<?php
session_start();
require __DIR__ . '/../config/database.php';

if (empty($_SESSION['user_rh']['id'])) {
    http_response_code(204);
    exit;
}

$userId = (int)$_SESSION['user_rh']['id'];

$pdo->prepare("
    UPDATE user_farmasi_status
    SET last_activity_at = NOW()
    WHERE user_id = ?
")->execute([$userId]);

http_response_code(204);
