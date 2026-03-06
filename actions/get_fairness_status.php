<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
$THRESHOLD = 10;

// ===============================
// VALIDASI LOGIN
// ===============================
if ($userId <= 0) {
    echo json_encode([
        'blocked'     => false,
        'selisih'     => 0,
        'threshold'   => $THRESHOLD,
        'user_status' => 'offline'
    ]);
    exit;
}

// ===============================
// STATUS USER SENDIRI
// ===============================
$stmtSelf = $pdo->prepare("
    SELECT status
    FROM user_farmasi_status
    WHERE user_id = ?
    LIMIT 1
");
$stmtSelf->execute([$userId]);
$selfStatus = $stmtSelf->fetchColumn() ?: 'offline';

// ===============================
// AMBIL SEMUA MEDIS + TRANSAKSI HARI INI
// ===============================
$stmt = $pdo->query("
    SELECT
        ufs.user_id,
        ur.full_name AS medic_name,
        ur.position  AS medic_jabatan,
        COUNT(s.id)  AS total_transaksi
    FROM user_farmasi_status ufs
    JOIN user_rh ur ON ur.id = ufs.user_id
    LEFT JOIN sales s
        ON s.medic_user_id = ufs.user_id
       AND DATE(s.created_at) = CURDATE()
    GROUP BY ufs.user_id, ur.full_name, ur.position
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// FILTER MEDIS ONLINE SAJA
// ===============================
$onlineRows = [];

foreach ($rows as $r) {
    $stmtStatus = $pdo->prepare("
        SELECT status
        FROM user_farmasi_status
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmtStatus->execute([$r['user_id']]);

    if ($stmtStatus->fetchColumn() === 'online') {
        $onlineRows[] = $r;
    }
}

// Jika < 2 medis online → fairness tidak berlaku
if (count($onlineRows) < 2) {
    echo json_encode([
        'blocked'     => false,
        'selisih'     => 0,
        'threshold'   => $THRESHOLD,
        'user_status' => $selfStatus
    ]);
    exit;
}

// ===============================
// URUTKAN ONLINE MEDIS (PALING SEDIKIT TRX)
// ===============================
usort($onlineRows, function ($a, $b) {
    return (int)$a['total_transaksi'] <=> (int)$b['total_transaksi'];
});

$lowestOnline = $onlineRows[0];
$current = null;

foreach ($onlineRows as $r) {
    if ((int)$r['user_id'] === $userId) {
        $current = $r;
        break;
    }
}

// Jika user bukan peserta fairness (offline)
if (!$current) {
    echo json_encode([
        'blocked'     => false,
        'selisih'     => 0,
        'threshold'   => $THRESHOLD,
        'user_status' => $selfStatus
    ]);
    exit;
}

// ===============================
// HITUNG SELISIH (ONLINE vs ONLINE)
// ===============================
$diff = (int)$current['total_transaksi']
    - (int)$lowestOnline['total_transaksi'];

$response = [
    'blocked'     => false,
    'selisih'     => max(0, $diff),
    'threshold'   => $THRESHOLD,
    'user_status' => $selfStatus
];

// ===============================
// 🔒 FAIRNESS HARD LOCK
// ===============================
if (
    $diff >= $THRESHOLD &&
    (int)$lowestOnline['user_id'] !== $userId
) {
    $response['blocked'] = true;
    $response['medic_name'] = $lowestOnline['medic_name'];
    $response['medic_jabatan'] = $lowestOnline['medic_jabatan'];
    $response['total_transaksi'] = (int)$lowestOnline['total_transaksi'];
}

echo json_encode($response);
exit;
