<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$mode = trim($_GET['mode'] ?? 'standard');
$isForensicPrivate = ($mode === 'forensic_private');

if ($isForensicPrivate) {
    ems_require_division_access(['Forensic'], '/dashboard/index.php');
}

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    header('Location: ' . ($isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php'));
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
    
    $recordScope = $record['visibility_scope'] ?? 'standard';
    if ($recordScope !== 'forensic_private' && (int) ($record['created_by'] ?? 0) !== $userId) {
        throw new Exception('Hanya pembuat rekam medis yang dapat menghapus data ini.');
    }
    if ($recordScope === 'forensic_private' && !ems_can_access_division_menu(ems_normalize_division($user['division'] ?? ''), 'Forensic')) {
        throw new Exception('Akses rekam medis private ditolak.');
    }
    if ($isForensicPrivate && $recordScope !== 'forensic_private') {
        throw new Exception('Rekam medis private tidak ditemukan.');
    }
    if (!$isForensicPrivate && $recordScope === 'forensic_private') {
        throw new Exception('Akses rekam medis private ditolak.');
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

header('Location: ' . ($isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php'));
exit;
