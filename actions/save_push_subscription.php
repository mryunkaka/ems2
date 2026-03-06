<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/database.php';

$userId = $_SESSION['user_rh']['id'] ?? 0;
if (!$userId) {
    http_response_code(401);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['endpoint'])) {
    http_response_code(400);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO user_push_subscriptions
    (user_id, endpoint, p256dh, auth, created_at)
    VALUES (?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        p256dh = VALUES(p256dh),
        auth   = VALUES(auth),
        updated_at = NOW()

");

$stmt->execute([
    $userId,
    $data['endpoint'],
    $data['keys']['p256dh'] ?? null,
    $data['keys']['auth'] ?? null
]);

echo 'OK';
