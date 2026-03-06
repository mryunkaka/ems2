<?php
date_default_timezone_set('Asia/Makassar');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

// ==================
// SECURITY GUARD
// ==================
$allowedRoles = ['vice director', 'director'];
$userRole = strtolower($_SESSION['user_rh']['role'] ?? '');

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
$firstSale = $pdo->query("
    SELECT MIN(DATE(created_at)) FROM sales
")->fetchColumn();

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
    ");
    $check->execute([$periodStart, $periodEnd]);

    if ($check->fetchColumn() > 0) {
        $startDate->modify('+7 days');
        continue;
    }

    // Ambil sales
    $stmt = $pdo->prepare("
        SELECT
            medic_name,
            MAX(medic_jabatan) AS medic_jabatan,
            COUNT(*) AS total_transaksi,
            SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
            SUM(price) AS total_rupiah
        FROM sales
        WHERE DATE(created_at) BETWEEN :start AND :end
        GROUP BY medic_name
    ");
    $stmt->execute([
        ':start' => $periodStart,
        ':end'   => $periodEnd
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $startDate->modify('+7 days');
        continue;
    }

    $insert = $pdo->prepare("
        INSERT INTO salary
        (medic_name, medic_jabatan, period_start, period_end,
         total_transaksi, total_item, total_rupiah, bonus_40)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($rows as $r) {
        $insert->execute([
            $r['medic_name'],
            $r['medic_jabatan'],
            $periodStart,
            $periodEnd,
            $r['total_transaksi'],
            $r['total_item'],
            $r['total_rupiah'],
            floor($r['total_rupiah'] * 0.4)
        ]);
    }

    $generated++;
    $startDate->modify('+7 days');
}

header('Location: gaji.php?generated=' . $generated);
exit;
