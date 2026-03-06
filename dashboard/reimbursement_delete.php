<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$code     = $_POST['code'] ?? '';

if (!in_array($userRole, ['wakil direktur', 'direktur'])) {
    http_response_code(403);
    exit;
}

if (!$code) {
    http_response_code(400);
    exit;
}

$stmt = $pdo->prepare("
    DELETE FROM reimbursements
    WHERE reimbursement_code = :code
");
$stmt->execute([':code' => $code]);
