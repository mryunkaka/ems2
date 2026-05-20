<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/training_groups.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_rh']['id'])) {
    echo json_encode([]);
    exit;
}

emsRequireRateLimit('search_available_managers', emsCurrentRequestIdentifier((int)($_SESSION['user_rh']['id'] ?? 0)), 20, 60, 'Pencarian terlalu sering. Coba lagi nanti.');

if (!ems_training_availability_tables_ready($pdo)) {
    echo json_encode([]);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$unitCode = ems_normalize_unit_code($_GET['unit_code'] ?? 'roxwood');

$stmt = $pdo->prepare("
    SELECT
        ur.id,
        ur.full_name,
        ur.role,
        ur.position,
        ur.division,
        ur.batch
    FROM user_rh ur
    JOIN training_user_availability tua ON tua.user_id = ur.id
    WHERE tua.status = 'online'
      AND ur.is_active = 1
      AND ur.role <> 'Staff'
      AND COALESCE(ur.unit_code, 'roxwood') = ?
      AND (
        LOWER(ur.full_name) LIKE LOWER(CONCAT('%', ?, '%'))
        OR LOWER(COALESCE(ur.position, '')) LIKE LOWER(CONCAT('%', ?, '%'))
        OR LOWER(COALESCE(ur.division, '')) LIKE LOWER(CONCAT('%', ?, '%'))
      )
    ORDER BY ur.full_name ASC
    LIMIT 10
");
$stmt->execute([$unitCode, $q, $q, $q]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rows as &$row) {
    $row['role'] = ems_role_label($row['role'] ?? '');
    $row['position'] = ems_position_label($row['position'] ?? '');
    $row['division'] = ems_normalize_division($row['division'] ?? '');
}
unset($row);

echo json_encode($rows);
