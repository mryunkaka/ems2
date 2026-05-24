<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/farmasi_quiz.php';

try {
    emsRequireJsonCsrf();
    $userId = (int)($_SESSION['user_rh']['id'] ?? 0);
    if ($userId <= 0) {
        ems_json_error('Unauthorized', 401);
    }

    $result = farmasi_quiz_force_finish_session($pdo, $userId);
    ems_json_response([
        'success' => true,
        'data' => $result,
    ]);
} catch (Throwable $e) {
    error_log('[farmasi_quiz_finish] ' . $e->getMessage());
    ems_json_error($e->getMessage() !== '' ? $e->getMessage() : 'Gagal mengakhiri quiz.', 503);
}
