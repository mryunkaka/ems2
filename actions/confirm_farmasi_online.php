<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/database.php';
date_default_timezone_set('Asia/Jakarta');

$userId = $_SESSION['user_rh']['id'] ?? 0;
if (!$userId) {
    http_response_code(401);
    exit;
}

$pdo->beginTransaction();

try {
    // Reset status & timer
    $stmt = $pdo->prepare("
        UPDATE user_farmasi_status
        SET
            status = 'online',
            last_confirm_at = NOW(),
            last_activity_at = NOW(), -- 🔥 WAJIB
            auto_offline_at = NULL,
            updated_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);

    // HAPUS notif check_online (BIAR TIDAK NUMPUK)
    $stmt = $pdo->prepare("
        UPDATE user_farmasi_notifications
        SET is_read = 1
        WHERE user_id = ?
            AND type = 'check_online'
    ");
    $stmt->execute([$userId]);

    $pdo->commit();
    echo 'OK';
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
}
