<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';

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

$existing = ems_ai_ds_get_user_settings($pdo, $userId);
$isProgrammer = ems_current_user_is_programmer_roxwood();

$apiKeyInput = trim((string) ($_POST['gemini_api_key'] ?? ''));
$apiKey = $apiKeyInput !== '' ? $apiKeyInput : (string) ($existing['gemini_api_key'] ?? '');

// Base URL & Model hanya boleh diubah oleh Programmer Roxwood — user lain (medis)
// tidak diberi field ini di UI, dan di sini nilai POST dari mereka diabaikan sama
// sekali (bukan cuma disembunyikan di form) supaya tidak bisa dilewati lewat
// request mentah. Mereka tetap memakai nilai yang sudah tersimpan / default sistem.
if ($isProgrammer) {
    $baseUrl = rtrim(trim((string) ($_POST['gemini_base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta')), '/');
    $model = trim((string) ($_POST['default_model'] ?? 'gemini-2.5-flash'));
} else {
    $baseUrl = rtrim(trim((string) ($existing['gemini_base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta')), '/');
    $model = trim((string) ($existing['default_model'] ?? 'gemini-2.5-flash'));
}

if (!in_array($model, ems_ai_model_options(), true)) {
    $_SESSION['flash_errors'] = ['Model AI yang dipilih tidak valid.'];
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

$_SESSION['flash_errors'] = ['Action tidak dikenali.'];
header('Location: ' . $redirectTo);
exit;
