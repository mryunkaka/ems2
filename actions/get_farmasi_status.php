<?php
session_start();
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/helpers.php';

try {
    $medicName = $_SESSION['user_rh']['name'] ?? '';

    if ($medicName === '') {
        ems_json_response(['status' => 'offline']);
    }

    $stmt = $pdo->prepare("
        SELECT status
        FROM user_farmasi_status
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_rh']['id']]);

    $status = $stmt->fetchColumn() ?: 'offline';
    ems_json_response(['status' => $status]);
} catch (Throwable $e) {
    error_log('[get_farmasi_status] ' . $e->getMessage());
    ems_json_error('Failed to load farmasi status', 503, ['status' => 'offline']);
}
