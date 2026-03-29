<?php
session_start();
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/helpers.php';

// Guard role
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$hasUnitCodeColumn = ems_column_exists($pdo, 'user_rh', 'unit_code');

$id  = (int)($_GET['id'] ?? 0);
$act = $_GET['act'] ?? '';

if ($id <= 0 || !in_array($act, ['approve', 'reject'])) {
    header("Location: validasi.php");
    exit;
}

$status = ($act === 'approve') ? 1 : 0;

$stmt = $pdo->prepare("
    UPDATE user_rh
    SET
        is_verified = ?,
        is_active = ?
    WHERE id = ?
    " . ($hasUnitCodeColumn ? " AND COALESCE(unit_code, 'roxwood') = ?" : "") . "
");
$params = [$status, $status, $id];
if ($hasUnitCodeColumn) {
    $params[] = $effectiveUnit;
}
$stmt->execute($params);

header("Location: validasi.php");
exit;
