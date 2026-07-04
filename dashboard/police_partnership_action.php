<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/police_partnership.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'police_partnership_action.php', '/dashboard/index.php');
policePartnershipEnsureTable($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['flash_errors'][] = 'CSRF token tidak valid.';
    header('Location: police_partnership.php');
    exit;
}

$action = (string)($_POST['action'] ?? '');
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$inputName = trim((string)($user['full_name'] ?? $user['name'] ?? ''));
$inputPosition = ems_position_label($user['position'] ?? '');

if ($inputName === '') {
    $_SESSION['flash_errors'][] = 'Session login tidak valid. Silakan login ulang.';
    header('Location: /auth/logout.php');
    exit;
}

if ($action === 'create') {
    $badgeNo = policePartnershipNormalizeBadge($_POST['police_badge_no'] ?? '');
    $actionType = trim((string)($_POST['action_type'] ?? ''));
    $serviceAtInput = trim((string)($_POST['service_at'] ?? ''));
    $allowedActions = policePartnershipActionOptions();
    $errors = [];

    if ($badgeNo === '') {
        $errors[] = 'No badge police wajib diisi.';
    }

    if (!in_array($actionType, $allowedActions, true)) {
        $errors[] = 'Tindakan tidak valid.';
    }

    $serviceAt = null;
    $serviceDate = '';
    if ($serviceAtInput !== '') {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $serviceAtInput);
        if ($dt && $dt->format('Y-m-d\TH:i') === $serviceAtInput) {
            $serviceAt = $dt->format('Y-m-d H:i:s');
            $serviceDate = $dt->format('Y-m-d');
        }
    }
    if ($serviceAt === null) {
        $errors[] = 'Jam dan tanggal tindakan tidak valid.';
    }

    if ($errors !== []) {
        $_SESSION['flash_errors'] = $errors;
        header('Location: police_partnership.php');
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO police_partnership_records
            (police_badge_no, action_type, treatment_detail, service_date, service_at, input_by_user_id, input_by_name, input_by_position, unit_code, amount)
        VALUES
            (?, ?, NULL, ?, ?, ?, ?, ?, ?, 1000)
    ");
    $stmt->execute([
        $badgeNo,
        $actionType,
        $serviceDate,
        $serviceAt,
        (int)($user['id'] ?? 0) ?: null,
        $inputName,
        $inputPosition,
        $effectiveUnit,
    ]);

    $_SESSION['flash_messages'][] = 'Input kerja sama Police berhasil disimpan.';
    header('Location: police_partnership.php');
    exit;
}

if ($action === 'update_amount') {
    if (!ems_is_manager_plus_role($user['role'] ?? '')) {
        http_response_code(403);
        exit('Akses ditolak');
    }

    $id = (int)($_POST['id'] ?? 0);
    $amount = max(0, (int)($_POST['amount'] ?? 0));
    $redirect = (string)($_POST['redirect'] ?? 'police_partnership_recap.php');
    if (!str_starts_with($redirect, '/dashboard/') && !str_starts_with($redirect, 'police_partnership_recap.php')) {
        $redirect = 'police_partnership_recap.php';
    }

    if ($id <= 0) {
        $_SESSION['flash_errors'][] = 'Data input tidak valid.';
        header('Location: ' . $redirect);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE police_partnership_records
        SET amount = ?, amount_updated_by = ?, amount_updated_at = NOW()
        WHERE id = ? AND unit_code = ?
    ");
    $stmt->execute([$amount, $inputName, $id, $effectiveUnit]);

    $_SESSION['flash_messages'][] = 'Biaya per input berhasil diperbarui.';
    header('Location: ' . $redirect);
    exit;
}

$_SESSION['flash_errors'][] = 'Action kerja sama Police tidak dikenali.';
header('Location: police_partnership.php');
exit;
