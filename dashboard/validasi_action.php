<?php
session_start();
require __DIR__ . '/../config/database.php';

// Guard role
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}

$id  = (int)($_GET['id'] ?? 0);
$act = $_GET['act'] ?? '';

if ($id <= 0 || !in_array($act, ['approve', 'reject'])) {
    header("Location: validasi.php");
    exit;
}

$status = ($act === 'approve') ? 1 : 0;

$stmt = $pdo->prepare("UPDATE user_rh SET is_verified = ? WHERE id = ?");
$stmt->execute([$status, $id]);

header("Location: validasi.php");
exit;
