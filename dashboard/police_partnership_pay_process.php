<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/police_partnership.php';

header('Content-Type: application/json; charset=utf-8');

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'police_partnership_recap.php', '/dashboard/index.php');
policePartnershipEnsureTable($pdo);

$user = $_SESSION['user_rh'] ?? [];
if (!ems_is_manager_plus_role($user['role'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Payload tidak valid']);
    exit;
}

if (!validateCsrfToken((string)($input['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'CSRF token tidak valid']);
    exit;
}

$effectiveUnit = ems_effective_unit($pdo, $user);
$inputByUserId = (int)($input['input_by_user_id'] ?? 0);
$inputByName = trim((string)($input['input_by_name'] ?? ''));
$startDate = substr((string)($input['range_start'] ?? ''), 0, 10);
$endDate = substr((string)($input['range_end'] ?? ''), 0, 10);
$payMethod = (string)($input['pay_method'] ?? 'direct');
$titipTo = (int)($input['titip_to'] ?? 0);

if ($inputByName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    echo json_encode(['success' => false, 'message' => 'Data pembayaran tidak valid']);
    exit;
}

try {
    $paidBy = (string)($user['name'] ?? $user['full_name'] ?? 'System');
    if ($payMethod === 'titip') {
        $stmtUser = $pdo->prepare('SELECT full_name FROM user_rh WHERE id = ? LIMIT 1');
        $stmtUser->execute([$titipTo]);
        $titipUser = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$titipUser) {
            throw new RuntimeException('User tujuan titip tidak ditemukan.');
        }
        $paidBy = 'Titip ke: ' . (string)$titipUser['full_name'] . ' (oleh ' . $paidBy . ')';
    }

    $whereUserSql = $inputByUserId > 0 ? 'input_by_user_id = ?' : 'input_by_name = ?';
    $whereUserParam = $inputByUserId > 0 ? $inputByUserId : $inputByName;

    $stmt = $pdo->prepare("
        UPDATE police_partnership_records
        SET payment_status = 'paid',
            paid_at = NOW(),
            paid_by = ?
        WHERE unit_code = ?
          AND {$whereUserSql}
          AND DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) BETWEEN ? AND ?
    ");
    $stmt->execute([$paidBy, $effectiveUnit, $whereUserParam, $startDate, $endDate]);

    if ($stmt->rowCount() <= 0) {
        throw new RuntimeException('Tidak ada data pending yang ditemukan pada filter ini.');
    }

    echo json_encode(['success' => true, 'message' => 'Pembayaran Police berhasil diproses untuk ' . $inputByName]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal memproses pembayaran: ' . $e->getMessage()]);
}
