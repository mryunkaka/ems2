<?php
date_default_timezone_set('Asia/Makassar');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

ems_require_not_trainee_html('Generate Gaji');

// ==================
// SECURITY GUARD
// ==================
$allowedRoles = ['vice director', 'director'];
$userRole = strtolower($_SESSION['user_rh']['role'] ?? '');
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$salesHasUnitCode = ems_column_exists($pdo, 'sales', 'unit_code');
$salaryHasUnitCode = ems_column_exists($pdo, 'salary', 'unit_code');

if (!in_array($userRole, $allowedRoles, true)) {
    http_response_code(403);
    exit('Akses ditolak');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    exit('CSRF validation failed');
}

// ==================
// LOCK FILE (ANTI DOUBLE RUN)
// ==================
$lock = fopen(__DIR__ . '/../cron/salary_manual.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit('Proses generate sedang berjalan');
}

// ==================
// FUNCTION PERIODE
// ==================
function getWeekPeriod(DateTime $date): array
{
    $start = clone $date;
    $start->modify('monday this week');

    $end = clone $start;
    $end->modify('+6 days');

    return [
        'start' => $start->format('Y-m-d'),
        'end'   => $end->format('Y-m-d'),
    ];
}

// ==================
// LOGIC GENERATE
// ==================
$firstSaleSql = "SELECT MIN(DATE(created_at)) FROM sales";
$firstSaleParams = [];
if ($salesHasUnitCode) {
    $firstSaleSql .= " WHERE unit_code = ?";
    $firstSaleParams[] = $effectiveUnit;
}
$stmtFirstSale = $pdo->prepare($firstSaleSql);
$stmtFirstSale->execute($firstSaleParams);
$firstSale = $stmtFirstSale->fetchColumn();

if (!$firstSale) {
    header('Location: gaji.php?msg=nosales');
    exit;
}

$startDate = new DateTime($firstSale);
$startDate->modify('monday this week');

$today = new DateTime();
$today->modify('monday this week');

$now = new DateTime();
$generated = 0;

while ($startDate <= $today) {

    $period = getWeekPeriod($startDate);
    $periodStart = $period['start'];
    $periodEnd   = $period['end'];

    $periodEndDate = new DateTime($periodEnd);

    // Skip minggu berjalan
    if ($periodEndDate >= $now) {
        $startDate->modify('+7 days');
        continue;
    }

    // Cek sudah ada
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM salary
        WHERE period_start = ? AND period_end = ?
        " . ($salaryHasUnitCode ? " AND unit_code = ?" : "") . "
    ");
    $checkParams = [$periodStart, $periodEnd];
    if ($salaryHasUnitCode) {
        $checkParams[] = $effectiveUnit;
    }
    $check->execute($checkParams);

    if ($check->fetchColumn() > 0) {
        $startDate->modify('+7 days');
        continue;
    }

    // Ambil sales
    $stmt = $pdo->prepare("
        SELECT
            medic_user_id,
            MAX(medic_name) AS medic_name,
            MAX(medic_jabatan) AS medic_jabatan,
            COUNT(*) AS total_transaksi,
            SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
            SUM(price) AS total_rupiah
        FROM sales
        WHERE DATE(created_at) BETWEEN :start AND :end
        " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
        GROUP BY medic_user_id
    ");
    $salesParams = [
        ':start' => $periodStart,
        ':end'   => $periodEnd
    ];
    if ($salesHasUnitCode) {
        $salesParams[':unit_code'] = $effectiveUnit;
    }
    $stmt->execute($salesParams);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $startDate->modify('+7 days');
        continue;
    }

    $salaryColumns = 'medic_user_id, medic_name, medic_jabatan, period_start, period_end, total_transaksi, total_item, total_rupiah, bonus_40';
    $salaryPlaceholders = '?, ?, ?, ?, ?, ?, ?, ?, ?';
    if ($salaryHasUnitCode) {
        $salaryColumns .= ', unit_code';
        $salaryPlaceholders .= ', ?';
    }
    $insert = $pdo->prepare("
        INSERT INTO salary
        ({$salaryColumns})
        VALUES ({$salaryPlaceholders})
    ");

    foreach ($rows as $r) {
        $insertParams = [
            $r['medic_user_id'],
            $r['medic_name'],
            $r['medic_jabatan'],
            $periodStart,
            $periodEnd,
            $r['total_transaksi'],
            $r['total_item'],
            $r['total_rupiah'],
            floor($r['total_rupiah'] * 0.4)
        ];
        if ($salaryHasUnitCode) {
            $insertParams[] = $effectiveUnit;
        }
        $insert->execute($insertParams);
    }

    $generated++;
    $startDate->modify('+7 days');
}

header('Location: gaji.php?generated=' . $generated);
exit;
