<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// DPJP autocomplete: jabatan Co. Asst ke atas
$stmt = $pdo->prepare("
    SELECT full_name, position
    FROM user_rh
    WHERE is_active = 1
      AND position IN ('co_asst', 'general_practitioner', 'specialist', '(Co.Ast)', 'Dokter Umum', 'Dokter Spesialis')
      AND LOWER(full_name) LIKE LOWER(CONCAT('%', ?, '%'))
    ORDER BY full_name ASC
    LIMIT 10
");
$stmt->execute([$q]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) {
    $r['position_label'] = ems_position_label($r['position'] ?? '');
}
unset($r);

echo json_encode($rows, JSON_UNESCAPED_UNICODE);

