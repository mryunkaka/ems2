<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    header('Location: rekam_medis_list.php');
    exit;
}

try {
    // Get record ID
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('ID rekam medis tidak valid.');
    }
    
    // Check if record exists
    $stmt = $pdo->prepare("SELECT * FROM medical_records WHERE id = ?");
    $stmt->execute([$id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        throw new Exception('Rekam medis tidak ditemukan.');
    }
    
    // Delete files
    if ($record['ktp_file_path'] && file_exists(__DIR__ . '/../' . $record['ktp_file_path'])) {
        unlink(__DIR__ . '/../' . $record['ktp_file_path']);
    }
    
    if ($record['mri_file_path'] && file_exists(__DIR__ . '/../' . $record['mri_file_path'])) {
        unlink(__DIR__ . '/../' . $record['mri_file_path']);
    }
    
    // Delete record
    $stmt = $pdo->prepare("DELETE FROM medical_records WHERE id = ?");
    $stmt->execute([$id]);
    
    $_SESSION['flash_messages'][] = 'Rekam medis berhasil dihapus.';
    
} catch (Exception $e) {
    $_SESSION['flash_errors'][] = $e->getMessage();
}

header('Location: rekam_medis_list.php');
exit;
