<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

/*
|--------------------------------------------------------------------------
| HARD GUARD & CONFIG
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| USER SESSION & ROLE CHECK
|--------------------------------------------------------------------------
*/
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$role   = strtolower(trim($user['role'] ?? ''));

$allowedRoles = ['manager', 'director', 'vice director'];

if ($userId <= 0 || !in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Akses ditolak');
}

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/
$code = trim($_POST['code'] ?? '');

if ($code === '') {
    http_response_code(400);
    exit('Kode reimbursement tidak valid');
}

/*
|--------------------------------------------------------------------------
| UPDATE STATUS (1 KODE = SEMUA ITEM)
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    UPDATE reimbursements
    SET
        status  = 'paid',
        paid_by = ?,
        paid_at = NOW()
    WHERE reimbursement_code = ?
");

$stmt->execute([$userId, $code]);

http_response_code(200);
echo 'OK';
