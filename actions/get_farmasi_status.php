<?php
session_start();
require __DIR__ . '/../config/database.php';

$medicName = $_SESSION['user_rh']['name'] ?? '';

if ($medicName === '') {
    echo json_encode(['status' => 'offline']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT status
    FROM user_farmasi_status
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_rh']['id']]);

$status = $stmt->fetchColumn() ?: 'offline';

echo json_encode(['status' => $status]);
