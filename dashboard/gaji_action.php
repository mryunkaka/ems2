<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

ems_require_not_trainee_html('Aksi Gaji');

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$salaryHasUnitCode = ems_column_exists($pdo, 'salary', 'unit_code');
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}

$id = (int)($_GET['id'] ?? 0);
$paidBy = $_SESSION['user_rh']['name'] ?? 'System';

$stmt = $pdo->prepare("
    UPDATE salary
    SET status='paid', paid_at=NOW(), paid_by=?
    WHERE id=?
    " . ($salaryHasUnitCode ? " AND unit_code = ?" : "") . "
");
$params = [$paidBy, $id];
if ($salaryHasUnitCode) {
    $params[] = $effectiveUnit;
}
$stmt->execute($params);

header('Location: gaji.php');
exit;
