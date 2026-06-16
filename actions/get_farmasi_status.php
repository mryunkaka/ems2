<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

try {
    $medicName = $_SESSION['user_rh']['name'] ?? '';
    $userId = (int)($_SESSION['user_rh']['id'] ?? 0);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($medicName === '') {
        ems_json_response(['status' => 'offline']);
    }

    if ($userId > 0) {
        ems_auto_offline_expired_farmasi_sessions($pdo, $userId);
    }

    $stmt = $pdo->prepare("
        SELECT status
        FROM user_farmasi_status
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    $status = $stmt->fetchColumn() ?: 'offline';
    ems_json_response(['status' => $status]);
} catch (Throwable $e) {
    error_log('[get_farmasi_status] ' . $e->getMessage());
    ems_json_error('Failed to load farmasi status', 503, ['status' => 'offline']);
}
