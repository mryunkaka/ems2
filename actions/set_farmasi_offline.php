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

$pdo->beginTransaction();

try {
    // Set OFFLINE
    $pdo->prepare("
        UPDATE user_farmasi_status
        SET status = 'offline',
            auto_offline_at = NOW()
        WHERE user_id = ?
    ")->execute([$userId]);

    // HAPUS SEMUA NOTIF (OFFLINE = TIDAK PERLU NOTIF)
    $pdo->prepare("
        DELETE FROM user_farmasi_notifications
        WHERE user_id = ?
    ")->execute([$userId]);

    $pdo->commit();
    echo 'OK';
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
}
