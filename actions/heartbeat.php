<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$user = $_SESSION['user_rh'] ?? null;
if (!$user || empty($user['id'])) {
    echo json_encode(['active' => false]);
    exit;
}

$userId = (int)$user['id'];
emsRequireRateLimit('heartbeat', emsCurrentRequestIdentifier($userId), 120, 60, 'Heartbeat terlalu sering.');

// 🔒 UPDATE HANYA JIKA STATUS ONLINE
$stmt = $pdo->prepare("
    UPDATE user_farmasi_status
    SET last_activity_at = NOW()
    WHERE user_id = ?
      AND status = 'online'
");
$stmt->execute([$userId]);

if ($stmt->rowCount() === 0) {
    // user offline → heartbeat tidak aktif
    echo json_encode(['active' => false]);
    exit;
}

echo json_encode([
    'active' => true,
    'time' => date('Y-m-d H:i:s')
]);
