<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$userId = (int)($_SESSION['user_rh']['id'] ?? 0);

// =====================================
// ⏰ JAM RAMAI → COOLDOWN DIMATIKAN
// WIB:
// - 15:00 - 18:00
// - 22:00 - 03:00
// =====================================
date_default_timezone_set('Asia/Jakarta');

$currentHour = (int)date('H');

// Jam 15 - 18
$isAfternoonPeak = ($currentHour >= 15 && $currentHour < 18);

// Jam 22 - 03 (lintas hari)
$isNightPeak = ($currentHour >= 21 || $currentHour < 3);

if ($isAfternoonPeak || $isNightPeak) {
    echo json_encode([
        'active' => false,
        'reason' => 'peak_hours'
    ]);
    exit;
}

// ⏱️ COOLDOWN 1 MENIT
$COOLDOWN_SECONDS = 60;

// Jika belum login → tidak ada cooldown
if ($userId <= 0) {
    echo json_encode(['active' => false]);
    exit;
}

// Ambil transaksi TERAKHIR hari ini (1 saja)
$stmt = $pdo->query("
    SELECT medic_user_id, medic_name, created_at
    FROM sales
    WHERE DATE(created_at) = CURDATE()
    ORDER BY created_at DESC
    LIMIT 1
");

$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Belum ada transaksi → tidak ada cooldown
if (!$row) {
    echo json_encode(['active' => false]);
    exit;
}

$lastMedicId = (int)$row['medic_user_id'];
$lastTime    = strtotime($row['created_at']);
$now         = time();
$remain      = $COOLDOWN_SECONDS - ($now - $lastTime);

// ⛔ Cooldown sudah habis
if ($remain <= 0) {
    echo json_encode(['active' => false]);
    exit;
}

// 🔑 INI KUNCI UTAMA:
// Cooldown HANYA UNTUK MEDIS TERAKHIR
if ($lastMedicId !== $userId) {
    echo json_encode(['active' => false]);
    exit;
}

// ✅ COOLDOWN AKTIF KHUSUS USER INI
echo json_encode([
    'active'   => true,
    'remain'   => $remain,
    'last_by'  => $row['medic_name']
]);
exit;
