<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/farmasi_quiz.php';

try {
    $userId = (int)($_SESSION['user_rh']['id'] ?? 0);
    if ($userId <= 0) {
        ems_json_error('Unauthorized', 401);
    }

    $state = farmasi_quiz_get_session_payload($pdo, $userId, true);
    ems_json_response([
        'success' => true,
        'data' => $state,
    ]);
} catch (Throwable $e) {
    error_log('[farmasi_quiz_state] ' . $e->getMessage());
    ems_json_error($e->getMessage() !== '' ? $e->getMessage() : 'Gagal memuat quiz farmasi.', 503);
}
