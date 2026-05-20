<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$userId = $_SESSION['user_rh']['id'] ?? 0;
if (!$userId) {
    echo json_encode([
        'has_notif' => false,
        'status'    => 'offline'
    ]);
    exit;
}

emsRequireRateLimit('check_farmasi_notif', emsCurrentRequestIdentifier((int)$userId), 30, 60, 'Pengecekan notifikasi terlalu sering.');

// ambil status user
$stmt = $pdo->prepare("
    SELECT status
    FROM user_farmasi_status
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$status = $stmt->fetchColumn() ?: 'offline';

// jika sudah offline → tidak boleh ada modal
if ($status === 'offline') {
    echo json_encode([
        'has_notif' => false,
        'status'    => 'offline'
    ]);
    exit;
}

// cek notif check_online
$stmt = $pdo->prepare("
    SELECT message
    FROM user_farmasi_notifications
    WHERE user_id = ?
      AND type = 'check_online'
      AND is_read = 0
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$notif = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'has_notif' => (bool) $notif,
    'message'  => $notif['message'] ?? null,
    'status'   => 'online'
]);
