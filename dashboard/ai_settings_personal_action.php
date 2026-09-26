<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/groq_settings.php';
require_once __DIR__ . '/../actions/groq_client.php';
require_once __DIR__ . '/../actions/ai_custom_client.php';

ems_ai_ds_ensure_tables($pdo);

$redirectTo = 'ai_settings_personal.php';
$action = trim((string) ($_GET['action'] ?? ''));
$userId = (int) ($_SESSION['user_rh']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_errors'] = ['Metode tidak diizinkan.'];
    header('Location: ' . $redirectTo);
    exit;
}

if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    $_SESSION['flash_errors'] = ['Sesi kedaluwarsa, silakan coba lagi.'];
    header('Location: ' . $redirectTo);
    exit;
}

if ($userId <= 0) {
    $_SESSION['flash_errors'] = ['Sesi pengguna tidak valid, silakan login ulang.'];
    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'clear_all') {
    try {
        ems_ai_ds_clear_user_settings($pdo, $userId);
        $_SESSION['flash_messages'] = ['Semua API key, provider, endpoint, dan model berhasil dikosongkan. Semua provider AI pribadi nonaktif.'];
    } catch (Throwable $e) {
        $_SESSION['flash_errors'] = ['Gagal mengosongkan setting AI pribadi.'];
    }

    header('Location: ' . $redirectTo);
    exit;
}

$existing = ems_ai_ds_get_user_settings($pdo, $userId);
$isProgrammer = ems_current_user_is_programmer_roxwood();

// Validasi field Gemini di bawah ini HANYA relevan untuk action Gemini
// ('save'/'test_connection') — dilewati sama sekali untuk action Groq
// ('save_groq'/'test_connection_groq', lihat blok tersendiri di bawah),
// supaya user yang belum pernah setting Gemini sama sekali tetap bisa
// setup Groq saja tanpa ketolak validasi "API key Gemini wajib diisi".
if (in_array($action, ['save', 'test_connection'], true)) {
    $apiKeyInput = trim((string) ($_POST['gemini_api_key'] ?? ''));
    $apiKey = $apiKeyInput !== '' ? $apiKeyInput : (string) ($existing['gemini_api_key'] ?? '');

    // Base URL & Model hanya boleh diubah oleh Programmer Roxwood — user lain (medis)
    // tidak diberi field ini di UI, dan di sini nilai POST dari mereka diabaikan sama
    // sekali (bukan cuma disembunyikan di form) supaya tidak bisa dilewati lewat
    // request mentah. Mereka tetap memakai nilai yang sudah tersimpan / default sistem.
    if ($isProgrammer) {
        $baseUrl = rtrim(trim((string) ($_POST['gemini_base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta')), '/');
        $model = trim((string) ($_POST['default_model'] ?? 'gemini-3.5-flash-lite'));
    } else {
        $savedBaseUrl = trim((string) ($existing['gemini_base_url'] ?? ''));
        $savedModel = trim((string) ($existing['default_model'] ?? ''));
        $baseUrl = rtrim($savedBaseUrl !== '' ? $savedBaseUrl : 'https://generativelanguage.googleapis.com/v1beta', '/');
        $model = $savedModel !== '' ? $savedModel : 'gemini-3.5-flash-lite';
    }

    if ($model === '' || mb_strlen($model) > 100) {
        $_SESSION['flash_errors'] = ['Model AI wajib diisi dan maksimal 100 karakter.'];
        header('Location: ' . $redirectTo);
        exit;
    }
    $baseUrlParts = $baseUrl !== '' ? parse_url($baseUrl) : false;
    if ($baseUrlParts === false || !in_array(strtolower((string) ($baseUrlParts['scheme'] ?? '')), ['http', 'https'], true) || trim((string) ($baseUrlParts['host'] ?? '')) === '') {
        $_SESSION['flash_errors'] = ['Base URL Gemini harus URL HTTP/HTTPS yang valid.'];
        header('Location: ' . $redirectTo);
        exit;
    }
    if ($baseUrl === '') {
        $_SESSION['flash_errors'] = ['Base URL Gemini wajib diisi.'];
        header('Location: ' . $redirectTo);
        exit;
    }
    if ($apiKey === '') {
        $_SESSION['flash_errors'] = ['API key Gemini wajib diisi.'];
        header('Location: ' . $redirectTo);
        exit;
    }
}

if ($action === 'save') {
    try {
        ems_ai_ds_save_user_settings($pdo, $userId, $apiKey, $baseUrl, $model);
        $_SESSION['flash_messages'] = ['Setting AI Saya berhasil disimpan.'];
    } catch (Throwable $e) {
        $_SESSION['flash_errors'] = ['Gagal menyimpan setting AI: ' . $e->getMessage()];
    }

    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'save_custom' || $action === 'test_connection_custom') {
    if (!$isProgrammer) {
        $_SESSION['flash_errors'] = ['Custom provider hanya dapat diatur Programmer Roxwood.'];
        header('Location: ' . $redirectTo);
        exit;
    }

    $customProvider = trim((string) ($_POST['custom_provider'] ?? ''));
    $customApiKeyInput = trim((string) ($_POST['custom_api_key'] ?? ''));
    $customApiKey = $customApiKeyInput !== '' ? $customApiKeyInput : (string) ($existing['custom_api_key'] ?? '');
    $customBaseUrl = rtrim(trim((string) ($_POST['custom_base_url'] ?? '')), '/');
    $customModel = trim((string) ($_POST['custom_default_model'] ?? ''));
    $customUrlParts = $customBaseUrl !== '' ? parse_url($customBaseUrl) : false;

    $customIsCleared = $customProvider === '' && $customApiKeyInput === '' && $customBaseUrl === '' && $customModel === '';
    if (!$customIsCleared) {
        if ($customProvider === '' || mb_strlen($customProvider) > 100 || preg_match('/[\x00-\x1F\x7F]/', $customProvider)) {
            $_SESSION['flash_errors'] = ['Nama custom provider wajib diisi, maksimal 100 karakter, dan tidak boleh mengandung karakter kontrol.'];
            header('Location: ' . $redirectTo);
            exit;
        }
        if ($customModel === '' || mb_strlen($customModel) > 100 || preg_match('/[\x00-\x1F\x7F]/', $customModel)) {
            $_SESSION['flash_errors'] = ['Model custom wajib diisi, maksimal 100 karakter, dan tidak boleh mengandung karakter kontrol.'];
            header('Location: ' . $redirectTo);
            exit;
        }
        if ($customApiKey !== '' && (mb_strlen($customApiKey) > 255 || preg_match('/[\x00-\x1F\x7F]/', $customApiKey))) {
            $_SESSION['flash_errors'] = ['API key custom maksimal 255 karakter dan tidak boleh mengandung karakter kontrol.'];
            header('Location: ' . $redirectTo);
            exit;
        }
        if ($customBaseUrl === '' || mb_strlen($customBaseUrl) > 255) {
            $_SESSION['flash_errors'] = ['Endpoint custom wajib diisi dan maksimal 255 karakter.'];
            header('Location: ' . $redirectTo);
            exit;
        }
        if (
            $customUrlParts === false
            || !in_array(strtolower((string) ($customUrlParts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($customUrlParts['host'] ?? '')) === ''
            || isset($customUrlParts['user'], $customUrlParts['pass'], $customUrlParts['query'], $customUrlParts['fragment'])
        ) {
            $_SESSION['flash_errors'] = ['Endpoint custom harus URL HTTP/HTTPS yang valid.'];
            header('Location: ' . $redirectTo);
            exit;
        }
    } else {
        $customProvider = '';
        $customApiKey = '';
        $customBaseUrl = '';
        $customModel = '';
    }

    try {
        if ($action === 'save_custom') {
            ems_ai_ds_save_custom_user_settings($pdo, $userId, $customProvider, $customApiKey, $customBaseUrl, $customModel);
            $_SESSION['flash_messages'] = [$customIsCleared ? 'Custom provider dinonaktifkan. Gemini kembali menjadi provider utama.' : 'Custom provider berhasil disimpan.'];
        } else {
            $result = ems_custom_test_connection($pdo, [
                'custom_provider' => $customProvider,
                'custom_api_key' => $customApiKey,
                'custom_base_url' => $customBaseUrl,
                'custom_default_model' => $customModel,
            ], $userId);
            $_SESSION['flash_messages'] = ['Test custom provider berhasil dengan model ' . $result['model'] . '.', 'Response: ' . trim((string) $result['content'])];
        }
    } catch (Throwable $e) {
        $_SESSION['flash_errors'] = [$action === 'save_custom' ? 'Gagal menyimpan custom provider: ' . $e->getMessage() : 'Test custom provider gagal: ' . $e->getMessage()];
    }

    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'test_connection') {
    try {
        $settings = array_merge(ems_ai_settings_defaults(), [
            'provider' => 'gemini',
            'is_enabled' => 1,
            'gemini_api_key' => $apiKey,
            'gemini_base_url' => $baseUrl,
            'default_model' => $model,
            'timeout_seconds' => 60,
            'daily_request_limit' => 0,
        ]);

        $result = ems_gemini_test_connection($pdo, $settings, $userId);
        $responseText = trim((string) ($result['text'] ?? ''));
        $summary = $responseText !== '' ? $responseText : 'Koneksi berhasil, tetapi respons teks kosong.';
        $_SESSION['flash_messages'] = [
            'Test koneksi Gemini berhasil dengan model ' . $result['model'] . '.',
            'Response: ' . $summary,
        ];
    } catch (Throwable $e) {
        $_SESSION['flash_errors'] = ['Test koneksi Gemini gagal: ' . $e->getMessage()];
    }

    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'save_groq' || $action === 'test_connection_groq') {
    $groqExisting = ems_groq_get_user_settings($pdo, $userId) ?? [];
    $groqKeyInput = trim((string) ($_POST['groq_api_key'] ?? ''));
    $groqApiKey = $groqKeyInput !== '' ? $groqKeyInput : (string) ($groqExisting['groq_api_key'] ?? '');
    $groqModel = trim((string) ($_POST['groq_model'] ?? 'openai/gpt-oss-120b'));

    if ($groqModel === '' || mb_strlen($groqModel) > 100) {
        $_SESSION['flash_errors'] = ['Model Groq wajib diisi dan maksimal 100 karakter.'];
        header('Location: ' . $redirectTo);
        exit;
    }
    if ($groqApiKey === '') {
        $_SESSION['flash_errors'] = ['API key Groq wajib diisi.'];
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'save_groq') {
        try {
            ems_groq_save_user_settings($pdo, $userId, $groqApiKey, $groqModel);
            $_SESSION['flash_messages'] = ['Setting Groq berhasil disimpan.'];
        } catch (Throwable $e) {
            $_SESSION['flash_errors'] = ['Gagal menyimpan setting Groq: ' . $e->getMessage()];
        }

        header('Location: ' . $redirectTo);
        exit;
    }

    try {
        $result = ems_groq_test_connection($pdo, [
            'groq_api_key' => $groqApiKey,
            'groq_default_model' => $groqModel,
        ], $userId);
        $_SESSION['flash_messages'] = [
            'Test koneksi Groq berhasil dengan model ' . $result['model'] . '.',
            'Response: ' . trim((string) $result['content']),
        ];
    } catch (Throwable $e) {
        $_SESSION['flash_errors'] = ['Test koneksi Groq gagal: ' . $e->getMessage()];
    }

    header('Location: ' . $redirectTo);
    exit;
}

$_SESSION['flash_errors'] = ['Action tidak dikenali.'];
header('Location: ' . $redirectTo);
exit;
