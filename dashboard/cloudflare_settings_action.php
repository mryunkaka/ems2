<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/cloudflare_settings.php';
require_once __DIR__ . '/../actions/ai_guard.php';
require_once __DIR__ . '/../actions/cloudflare_client.php';

ems_require_programmer_roxwood_access();
ems_cloudflare_ensure_tables($pdo);

$redirectTo = ems_url('/dashboard/cloudflare_settings.php');
$action = trim((string) ($_GET['action'] ?? ''));
$userId = (int) ($_SESSION['user_rh']['id'] ?? 0);

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

$currentSettings = ems_cloudflare_get_settings($pdo);
$tokenInput = trim((string) ($_POST['api_token'] ?? ''));
$apiToken = $tokenInput !== '' ? $tokenInput : (string) ($currentSettings['api_token'] ?? '');

$incoming = [
    'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
    'account_id' => trim((string) ($_POST['account_id'] ?? '')),
    'api_token' => $apiToken,
    'default_model' => trim((string) ($_POST['default_model'] ?? '@cf/black-forest-labs/flux-1-schnell')),
];

if (!array_key_exists($incoming['default_model'], ems_cloudflare_model_options())) {
    $_SESSION['flash_errors'][] = 'Model image-generation yang dipilih tidak valid.';
    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'save') {
    try {
        ems_cloudflare_save_settings($pdo, $incoming, $userId > 0 ? $userId : 0);
        $_SESSION['flash_messages'][] = 'Setting Cloudflare AI berhasil disimpan.';
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Gagal menyimpan setting: ' . $e->getMessage();
    }

    header('Location: ' . $redirectTo);
    exit;
}

if ($action === 'test_connection') {
    try {
        if ($incoming['account_id'] === '' || $incoming['api_token'] === '') {
            throw new RuntimeException('Isi atau simpan Account ID dan API Token terlebih dahulu.');
        }

        $result = ems_cloudflare_test_connection($pdo, $incoming, $userId > 0 ? $userId : null);
        $_SESSION['flash_messages'][] = 'Test generate gambar berhasil dengan model ' . $result['model'] . ' (mime: ' . $result['image']['mime_type'] . ').';
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Test generate gambar gagal: ' . $e->getMessage();
    }

    header('Location: ' . $redirectTo);
    exit;
}

$_SESSION['flash_errors'][] = 'Action tidak dikenali.';
header('Location: ' . $redirectTo);
exit;
