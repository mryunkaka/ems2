<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/auth_guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$oldName = trim($_POST['old_name'] ?? '');
$newName = trim($_POST['new_name'] ?? '');

if ($oldName === '' || $newName === '') {
    http_response_code(400);
    exit('Nama tidak valid');
}

if (strcasecmp($oldName, $newName) === 0) {
    exit('Nama sama, tidak perlu dikoreksi');
}

// 🔒 optional: hanya role tertentu
$userRole = strtolower($_SESSION['user_rh']['role'] ?? '');
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Tidak punya izin koreksi');
}

$pdo->beginTransaction();

try {
    // Update semua data sales
    $stmt = $pdo->prepare("
        UPDATE sales 
        SET consumer_name = :new 
        WHERE consumer_name = :old
    ");
    $stmt->execute([
        ':new' => $newName,
        ':old' => $oldName
    ]);

    $affected = $stmt->rowCount();

    // (OPSIONAL tapi direkomendasikan) log audit
    $log = $pdo->prepare("
        INSERT INTO audit_logs 
        (action, detail, created_at)
        VALUES (?, ?, NOW())
    ");
    $log->execute([
        'KOREKSI_NAMA_KONSUMEN',
        "Dari '{$oldName}' → '{$newName}' ({$affected} baris)"
    ]);

    $pdo->commit();

    echo "OK|{$affected}";
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "ERROR|" . $e->getMessage();
}
