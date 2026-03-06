<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

$userId = $_SESSION['user_rh']['id'] ?? 0;
if (!$userId) {
    echo json_encode([
        'active' => false
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Ambil sisa waktu (detik) menuju auto_offline_at
|--------------------------------------------------------------------------
| ⚠️ JANGAN kirim datetime ke frontend
| ✅ Kirim remaining_seconds agar countdown stabil
*/
$stmt = $pdo->prepare("
    SELECT
        auto_offline_at,
        TIMESTAMPDIFF(SECOND, NOW(), auto_offline_at) AS remaining_seconds
    FROM user_farmasi_status
    WHERE user_id = ?
      AND status = 'online'
      AND auto_offline_at IS NOT NULL
    LIMIT 1
");

$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && (int)$row['remaining_seconds'] > 0) {
    echo json_encode([
        'active'    => true,
        'remaining' => (int)$row['remaining_seconds']
    ]);
    exit;
}

// Jika deadline sudah lewat / tidak valid
echo json_encode([
    'active' => false
]);
