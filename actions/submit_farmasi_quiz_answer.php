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

    $payload = json_decode((string)file_get_contents('php://input'), true);
    $sessionQuestionId = (int)($payload['session_question_id'] ?? 0);
    $selectedOption = (string)($payload['selected_option'] ?? '');

    if ($sessionQuestionId <= 0 || $selectedOption === '') {
        ems_json_error('Data jawaban quiz tidak lengkap.', 422);
    }

    $result = farmasi_quiz_submit_answer($pdo, $userId, $sessionQuestionId, $selectedOption);
    ems_json_response([
        'success' => true,
        'data' => $result,
    ]);
} catch (Throwable $e) {
    error_log('[farmasi_quiz_submit] ' . $e->getMessage());
    ems_json_error($e->getMessage() !== '' ? $e->getMessage() : 'Gagal menyimpan jawaban quiz.', 503);
}
