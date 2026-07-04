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
    $badgeFile = $_FILES['badge_file'] ?? null;
    $badgePath = is_array($badgeFile) ? policePartnershipUploadBadgeFile($badgeFile) : null;
    $badgeNo = 'ATTACH-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $actionType = trim((string)($_POST['action_type'] ?? ''));
    $serviceAtInput = trim((string)($_POST['service_at'] ?? ''));
    $allowedActions = policePartnershipActionOptions();
    $errors = [];

    if ($badgePath === null) {
        $errors[] = 'Foto badge police wajib diupload dalam format JPG, PNG, atau WebP maksimal 5 MB.';
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
            (police_badge_no, badge_file_path, action_type, treatment_detail, service_date, service_at, input_by_user_id, input_by_name, input_by_position, unit_code, amount)
        VALUES
            (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 1000)
    ");
    $stmt->execute([
        $badgeNo,
        $badgePath,
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

if ($action === 'delete_record') {
    $id = (int)($_POST['id'] ?? 0);
    $redirect = (string)($_POST['redirect'] ?? 'police_partnership.php');
    if (!str_starts_with($redirect, '/dashboard/') && !str_starts_with($redirect, 'police_partnership.php')) {
        $redirect = 'police_partnership.php';
    }

    if ($id <= 0) {
        $_SESSION['flash_errors'][] = 'Data input tidak valid.';
        header('Location: ' . $redirect);
        exit;
    }

    $params = [$id, $effectiveUnit];
    $ownerSql = '';
    if (!ems_is_manager_plus_role($user['role'] ?? '')) {
        $ownerSql = ' AND input_by_user_id = ?';
        $params[] = (int)($user['id'] ?? 0);
    }

    $fileStmt = $pdo->prepare("SELECT badge_file_path FROM police_partnership_records WHERE id = ? AND unit_code = ? {$ownerSql} LIMIT 1");
    $fileStmt->execute($params);
    $badgePath = (string)($fileStmt->fetchColumn() ?: '');

    $stmt = $pdo->prepare("DELETE FROM police_partnership_records WHERE id = ? AND unit_code = ? {$ownerSql}");
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        if ($badgePath !== '') {
            $fullPath = realpath(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $badgePath), '/'));
            $storageRoot = realpath(__DIR__ . '/../storage/police_badges');
            if ($fullPath && $storageRoot && str_starts_with(str_replace('\\', '/', $fullPath), str_replace('\\', '/', $storageRoot))) {
                @unlink($fullPath);
            }
        }
        $_SESSION['flash_messages'][] = 'Data input Police berhasil dihapus.';
    } else {
        $_SESSION['flash_errors'][] = 'Data tidak ditemukan atau Anda tidak memiliki akses hapus.';
    }

    header('Location: ' . $redirect);
    exit;
}

if ($action === 'update_pricing') {
    if (!ems_is_manager_plus_role($user['role'] ?? '')) {
        http_response_code(403);
        exit('Akses ditolak');
    }

    $pricingMode = (string)($_POST['pricing_mode'] ?? 'per_qty');
    if (!in_array($pricingMode, ['per_qty', 'per_week', 'per_month'], true)) {
        $pricingMode = 'per_qty';
    }

    $totalAmount = max(0, (int)($_POST['total_amount'] ?? 0));
    $rangeStartPost = (string)($_POST['range_start'] ?? '');
    $rangeEndPost = (string)($_POST['range_end'] ?? '');
    $redirect = (string)($_POST['redirect'] ?? 'police_partnership_recap.php');
    if (!str_starts_with($redirect, '/dashboard/') && !str_starts_with($redirect, 'police_partnership_recap.php')) {
        $redirect = 'police_partnership_recap.php';
    }

    $startDate = substr($rangeStartPost, 0, 10);
    $endDate = substr($rangeEndPost, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $_SESSION['flash_errors'][] = 'Rentang tanggal harga tidak valid.';
        header('Location: ' . $redirect);
        exit;
    }

    $rowsStmt = $pdo->prepare("
        SELECT id
        FROM police_partnership_records
        WHERE unit_code = ?
          AND DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) BETWEEN ? AND ?
        ORDER BY COALESCE(service_at, CONCAT(service_date, ' 00:00:00')) ASC, id ASC
    ");
    $rowsStmt->execute([$effectiveUnit, $startDate, $endDate]);
    $recordIds = array_map('intval', $rowsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    if ($recordIds === []) {
        $_SESSION['flash_errors'][] = 'Tidak ada data pada rentang ini untuk diperbarui.';
        header('Location: ' . $redirect);
        exit;
    }

    $baseAmount = $pricingMode === 'per_qty' ? $totalAmount : intdiv($totalAmount, count($recordIds));
    $remainder = $pricingMode === 'per_qty' ? 0 : ($totalAmount % count($recordIds));

    $pdo->beginTransaction();
    try {
        $updateStmt = $pdo->prepare("
            UPDATE police_partnership_records
            SET amount = ?,
                pricing_mode = ?,
                amount_updated_by = ?,
                amount_updated_at = NOW(),
                payment_status = 'pending',
                paid_at = NULL,
                paid_by = NULL
            WHERE id = ? AND unit_code = ?
        ");

        foreach ($recordIds as $index => $recordId) {
            $amount = $baseAmount;
            if ($pricingMode !== 'per_qty' && $index < $remainder) {
                $amount++;
            }
            $updateStmt->execute([$amount, $pricingMode, $inputName, $recordId, $effectiveUnit]);
        }

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Harga kerja sama Police berhasil diperbarui (' . policePartnershipPricingModeLabel($pricingMode) . ').';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_errors'][] = 'Gagal memperbarui harga: ' . $e->getMessage();
    }

    header('Location: ' . $redirect);
    exit;
}

$_SESSION['flash_errors'][] = 'Action kerja sama Police tidak dikenali.';
header('Location: police_partnership.php');
exit;
