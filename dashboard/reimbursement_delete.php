<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json; charset=UTF-8');

$userRole = $_SESSION['user_rh']['role'] ?? '';
$code = trim((string)($_POST['code'] ?? ''));

if (!ems_is_director_role($userRole)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Hanya Director dan Vice Director yang dapat menghapus reimbursement.'
    ]);
    exit;
}

if ($code === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Kode reimbursement tidak valid.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT receipt_file
        FROM reimbursements
        WHERE reimbursement_code = :code
        LIMIT 1
    ");
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Data reimbursement tidak ditemukan.'
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare("
        DELETE FROM reimbursements
        WHERE reimbursement_code = :code
    ");
    $deleteStmt->execute([':code' => $code]);

    $pdo->commit();

    $receiptFile = trim((string)($row['receipt_file'] ?? ''));
    if ($receiptFile !== '') {
        $receiptPath = realpath(__DIR__ . '/../' . $receiptFile);
        $storageRoot = realpath(__DIR__ . '/../storage/reimbursements');

        if ($receiptPath !== false && $storageRoot !== false && strncmp($receiptPath, $storageRoot, strlen($storageRoot)) === 0 && is_file($receiptPath)) {
            @unlink($receiptPath);
        }
    }

    $folderPath = __DIR__ . '/../storage/reimbursements/' . $code;
    emsRemoveDirectory($folderPath);

    echo json_encode([
        'success' => true,
        'message' => 'Reimbursement berhasil dihapus permanen.'
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menghapus reimbursement.'
    ]);
}
