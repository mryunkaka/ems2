<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_settings.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../actions/ai_guard.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';

ems_require_programmer_roxwood_access();

$redirectTo = ems_url('/dashboard/ai_settings.php');
$action = trim((string)($_GET['action'] ?? ''));
$userId = (int)($_SESSION['user_rh']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_errors'][] = 'Method tidak valid.';
    header('Location: ' . $redirectTo);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_errors'][] = 'CSRF token tidak valid.';
    header('Location: ' . $redirectTo);
    exit;
}

if (!ems_ai_settings_table_exists($pdo)) {
    $_SESSION['flash_errors'][] = 'Tabel AI belum tersedia. Jalankan migration SQL AI terlebih dahulu.';
    header('Location: ' . $redirectTo);
    exit;
}

$currentSettings = ems_ai_get_settings($pdo);
$apiKeyInput = trim((string)($_POST['gemini_api_key'] ?? ''));
$apiKey = $apiKeyInput !== '' ? $apiKeyInput : (string)($currentSettings['gemini_api_key'] ?? '');

$incomingSettings = [
    'provider' => 'gemini',
    'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
    'gemini_api_key' => $apiKey,
    'gemini_base_url' => rtrim(trim((string)($_POST['gemini_base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta')), '/'),
    'default_model' => trim((string)($_POST['default_model'] ?? 'gemini-2.5-flash')),
    'summary_model' => trim((string)($_POST['summary_model'] ?? 'gemini-2.5-flash')),
    'interview_question_model' => trim((string)($_POST['interview_question_model'] ?? 'gemini-2.5-flash')),
    'criteria_scoring_model' => trim((string)($_POST['criteria_scoring_model'] ?? 'gemini-2.5-flash')),
    'temperature' => max(0, min(2, (float)($_POST['temperature'] ?? 0.4))),
    'top_p' => max(0, min(1, (float)($_POST['top_p'] ?? 0.95))),
    'top_k' => max(1, min(100, (int)($_POST['top_k'] ?? 40))),
    'max_output_tokens' => max(128, min(8192, (int)($_POST['max_output_tokens'] ?? 2048))),
    'timeout_seconds' => max(5, min(120, (int)($_POST['timeout_seconds'] ?? 30))),
    'daily_request_limit' => max(1, min(5000, (int)($_POST['daily_request_limit'] ?? 200))),
];

$modelOptions = ems_ai_model_options();
foreach (['default_model', 'summary_model', 'interview_question_model', 'criteria_scoring_model'] as $modelField) {
    if (!in_array($incomingSettings[$modelField], $modelOptions, true)) {
        $_SESSION['flash_errors'][] = 'Model AI yang dipilih tidak valid.';
        header('Location: ' . $redirectTo);
        exit;
    }
}

if ($incomingSettings['gemini_base_url'] === '') {
    $_SESSION['flash_errors'][] = 'Base URL Gemini wajib diisi.';
    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'save') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_ai_settings
            (
                id,
                provider,
                is_enabled,
                gemini_api_key,
                gemini_base_url,
                default_model,
                summary_model,
                interview_question_model,
                criteria_scoring_model,
                temperature,
                top_p,
                top_k,
                max_output_tokens,
                timeout_seconds,
                daily_request_limit,
                created_by,
                updated_by
            ) VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                provider = VALUES(provider),
                is_enabled = VALUES(is_enabled),
                gemini_api_key = VALUES(gemini_api_key),
                gemini_base_url = VALUES(gemini_base_url),
                default_model = VALUES(default_model),
                summary_model = VALUES(summary_model),
                interview_question_model = VALUES(interview_question_model),
                criteria_scoring_model = VALUES(criteria_scoring_model),
                temperature = VALUES(temperature),
                top_p = VALUES(top_p),
                top_k = VALUES(top_k),
                max_output_tokens = VALUES(max_output_tokens),
                timeout_seconds = VALUES(timeout_seconds),
                daily_request_limit = VALUES(daily_request_limit),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            (int)($currentSettings['id'] ?: 1),
            $incomingSettings['provider'],
            $incomingSettings['is_enabled'],
            $incomingSettings['gemini_api_key'],
            $incomingSettings['gemini_base_url'],
            $incomingSettings['default_model'],
            $incomingSettings['summary_model'],
            $incomingSettings['interview_question_model'],
            $incomingSettings['criteria_scoring_model'],
            number_format((float)$incomingSettings['temperature'], 2, '.', ''),
            number_format((float)$incomingSettings['top_p'], 2, '.', ''),
            $incomingSettings['top_k'],
            $incomingSettings['max_output_tokens'],
            $incomingSettings['timeout_seconds'],
            $incomingSettings['daily_request_limit'],
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ]);

        $_SESSION['flash_messages'][] = 'Setting AI berhasil disimpan.';
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Gagal menyimpan setting AI: ' . $e->getMessage();
    }

    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'test_connection') {
    try {
        if ($incomingSettings['gemini_api_key'] === '') {
            throw new RuntimeException('Isi atau simpan API key Gemini terlebih dahulu.');
        }

        $result = ems_gemini_test_connection($pdo, $incomingSettings, $userId > 0 ? $userId : null);
        $responseText = trim((string)($result['text'] ?? ''));
        $summary = $responseText !== '' ? $responseText : 'Koneksi berhasil, tetapi respons teks kosong.';
        $_SESSION['flash_messages'][] = 'Test connection Gemini berhasil dengan model ' . $result['model'] . '.';
        $_SESSION['flash_messages'][] = 'Response: ' . $summary;
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Test connection Gemini gagal: ' . $e->getMessage();
    }

    header('Location: ' . $redirectTo);
    exit;
}

$_SESSION['flash_errors'][] = 'Action AI tidak dikenali.';
header('Location: ' . $redirectTo);
exit;
