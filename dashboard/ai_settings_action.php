<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_settings.php';
require_once __DIR__ . '/../config/ai_queue.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../actions/ai_guard.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';
require_once __DIR__ . '/../actions/ai_custom_client.php';

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
$provider = strtolower(trim((string)($_POST['provider'] ?? 'gemini')));
$customProvider = trim((string)($_POST['custom_provider'] ?? ($currentSettings['custom_provider'] ?? 'OpenAI-Compatible')));
$customApiKeyInput = trim((string)($_POST['custom_api_key'] ?? ''));
$customApiKey = $customApiKeyInput !== '' ? $customApiKeyInput : (string)($currentSettings['custom_api_key'] ?? '');
$customBaseUrl = rtrim(trim((string)($_POST['custom_base_url'] ?? ($currentSettings['custom_base_url'] ?? ''))), '/');
$customModel = trim((string)($_POST['custom_default_model'] ?? ($currentSettings['custom_default_model'] ?? '')));

if (!in_array($provider, ['gemini', 'openai_compatible'], true)) {
    $_SESSION['flash_errors'][] = 'Provider AI tidak dikenal.';
    header('Location: ' . $redirectTo); exit;
}

$incomingSettings = [
    'provider' => $provider,
    'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
    'gemini_api_key' => $apiKey,
    'gemini_base_url' => rtrim(trim((string)($_POST['gemini_base_url'] ?? ($currentSettings['gemini_base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'))), '/'),
    'custom_provider' => $customProvider !== '' ? $customProvider : 'OpenAI-Compatible',
    'custom_api_key' => $customApiKey,
    'custom_base_url' => $customBaseUrl,
    'custom_default_model' => $customModel,
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
    'ai_max_concurrent_requests' => max(1, min(3, (int)($_POST['ai_max_concurrent_requests'] ?? $currentSettings['ai_max_concurrent_requests'] ?? 1))),
    'ai_max_queue_size' => max(10, min(200, (int)($_POST['ai_max_queue_size'] ?? $currentSettings['ai_max_queue_size'] ?? 50))),
];

$modelOptions = ems_ai_model_options();
foreach (['default_model', 'summary_model', 'interview_question_model', 'criteria_scoring_model'] as $modelField) {
    if ($provider === 'gemini' && !in_array($incomingSettings[$modelField], $modelOptions, true)) {
        $_SESSION['flash_errors'][] = 'Model AI yang dipilih tidak valid.';
        header('Location: ' . $redirectTo);
        exit;
    }
}

if ($provider === 'gemini' && $incomingSettings['gemini_base_url'] === '') {
    $_SESSION['flash_errors'][] = 'Base URL Gemini wajib diisi.';
    header('Location: ' . $redirectTo);
    exit;
}

if ($provider === 'openai_compatible') {
    $customUrlParts = $customBaseUrl !== '' ? parse_url($customBaseUrl) : false;
    if ($customProvider === '' || mb_strlen($customProvider) > 100 || preg_match('/[\x00-\x1F\x7F]/', $customProvider)) {
        $_SESSION['flash_errors'][] = 'Nama custom provider tidak valid.'; header('Location: ' . $redirectTo); exit;
    }
    if ($customModel === '' || mb_strlen($customModel) > 100 || preg_match('/[\x00-\x1F\x7F]/', $customModel)) {
        $_SESSION['flash_errors'][] = 'Model custom wajib diisi (maksimal 100 karakter).'; header('Location: ' . $redirectTo); exit;
    }
    if ($customApiKey === '' || mb_strlen($customApiKey) > 255 || preg_match('/[\x00-\x1F\x7F]/', $customApiKey)) {
        $_SESSION['flash_errors'][] = 'API key custom wajib diisi dan tidak boleh mengandung karakter kontrol.'; header('Location: ' . $redirectTo); exit;
    }
    if ($customUrlParts === false || strtolower((string)($customUrlParts['scheme'] ?? '')) !== 'https' || trim((string)($customUrlParts['host'] ?? '')) === '' || isset($customUrlParts['user'], $customUrlParts['pass'], $customUrlParts['query'], $customUrlParts['fragment'])) {
        $_SESSION['flash_errors'][] = 'Endpoint custom harus URL HTTPS publik yang valid.'; header('Location: ' . $redirectTo); exit;
    }
    foreach (['default_model', 'summary_model', 'interview_question_model', 'criteria_scoring_model'] as $field) $incomingSettings[$field] = $customModel;
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
                custom_provider,
                custom_api_key,
                custom_base_url,
                custom_default_model,
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
                ai_max_concurrent_requests,
                ai_max_queue_size,
                created_by,
                updated_by
            ) VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                provider = VALUES(provider),
                is_enabled = VALUES(is_enabled),
                gemini_api_key = VALUES(gemini_api_key),
                custom_provider = VALUES(custom_provider),
                custom_api_key = VALUES(custom_api_key),
                custom_base_url = VALUES(custom_base_url),
                custom_default_model = VALUES(custom_default_model),
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
                ai_max_concurrent_requests = VALUES(ai_max_concurrent_requests),
                ai_max_queue_size = VALUES(ai_max_queue_size),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            (int)($currentSettings['id'] ?: 1),
            $incomingSettings['provider'],
            $incomingSettings['is_enabled'],
            $incomingSettings['gemini_api_key'],
            $incomingSettings['custom_provider'],
            $incomingSettings['custom_api_key'],
            $incomingSettings['custom_base_url'],
            $incomingSettings['custom_default_model'],
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
            $incomingSettings['ai_max_concurrent_requests'],
            $incomingSettings['ai_max_queue_size'],
            $userId > 0 ? $userId : null,
            $userId > 0 ? $userId : null,
        ]);

        $_SESSION['flash_messages'][] = 'Setting AI global berhasil disimpan untuk seluruh fitur.';
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Gagal menyimpan setting AI: ' . $e->getMessage();
    }

    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'test_connection') {
    try {
        if ($provider !== 'gemini' || strtolower((string)($currentSettings['provider']??'')) !== 'gemini') throw new RuntimeException('Simpan dan pilih Gemini sebagai provider aktif sebelum menguji koneksi.');
        if (($incomingSettings['gemini_api_key']??'') !== ($currentSettings['gemini_api_key']??'') || $incomingSettings['gemini_base_url'] !== ($currentSettings['gemini_base_url']??'') || $incomingSettings['default_model'] !== ($currentSettings['default_model']??'')) throw new RuntimeException('Simpan perubahan pengaturan Gemini terlebih dahulu, lalu jalankan uji koneksi.');
        if (!ems_ai_global_provider_ready($pdo)) throw new RuntimeException('Provider Gemini global belum aktif atau kredensial belum tersimpan.');
        $job=ems_ai_queue_provider_test($pdo,$userId,'global','gemini');
        $_SESSION['ai_queue_return_'.$job['job_id']]='/dashboard/ai_settings.php';
        header('Location: '.ems_url('/dashboard/ai_queue_wait.php?job_id='.$job['job_id'])); exit;
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Test connection Gemini gagal: ' . $e->getMessage();
    }

    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'test_connection_custom') {
    try {
        if ($provider !== 'openai_compatible' || strtolower((string)($currentSettings['provider']??'')) !== 'openai_compatible') throw new RuntimeException('Simpan dan pilih OpenAI-Compatible sebagai provider aktif sebelum menguji koneksi.');
        foreach(['custom_provider','custom_api_key','custom_base_url','custom_default_model'] as $field) if((string)($incomingSettings[$field]??'') !== (string)($currentSettings[$field]??'')) throw new RuntimeException('Simpan perubahan provider terlebih dahulu, lalu jalankan uji koneksi.');
        if(!ems_ai_global_provider_ready($pdo)) throw new RuntimeException('Provider global belum aktif atau kredensial belum tersimpan.');
        $job=ems_ai_queue_provider_test($pdo,$userId,'global','openai_compatible');
        $_SESSION['ai_queue_return_'.$job['job_id']]='/dashboard/ai_settings.php';
        header('Location: '.ems_url('/dashboard/ai_queue_wait.php?job_id='.$job['job_id'])); exit;
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Test custom provider gagal: ' . $e->getMessage();
    }
    header('Location: ' . $redirectTo); exit;
}

$_SESSION['flash_errors'][] = 'Action AI tidak dikenali.';
header('Location: ' . $redirectTo);
exit;
