<?php
session_start();
require __DIR__ . '/../config/database.php';

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
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
");
$stmt->execute([$paidBy, $id]);

header('Location: gaji.php');
exit;
