<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

emsRequireRateLimit('search_user_rh', emsCurrentRequestIdentifier((int)($_SESSION['user_rh']['id'] ?? 0)), 20, 60, 'Pencarian terlalu sering. Coba lagi nanti.');

$q = trim($_GET['q'] ?? '');
$scope = strtolower(trim($_GET['scope'] ?? 'all'));

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$scopeWhere = '';
if ($scope === 'doctor') {
    $scopeWhere = " AND position IN ('co_asst', 'general_practitioner', 'specialist', '(Co.Ast)', 'Dokter Umum', 'Dokter Spesialis')";
} elseif ($scope === 'assistant') {
    $scopeWhere = " AND position IN ('paramedic', 'co_asst', 'general_practitioner', 'specialist')";
}

$stmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        batch,
        position,
        jenis_kelamin,
        division
    FROM user_rh
    WHERE LOWER(full_name) LIKE LOWER(CONCAT('%', ?, '%'))
      AND is_active = 1
      {$scopeWhere}
    ORDER BY full_name ASC
    LIMIT 10
");

$stmt->execute([$q]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) {
    $r['position'] = ems_position_label($r['position'] ?? '');
    $r['division'] = ems_normalize_division($r['division'] ?? '');
}
unset($r);

echo json_encode($rows);
