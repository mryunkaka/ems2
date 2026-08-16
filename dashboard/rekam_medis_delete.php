<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/forensic_private_access.php';

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$mode = trim($_GET['mode'] ?? 'standard');
$isForensicPrivate = ($mode === 'forensic_private');

if ($isForensicPrivate) {
    ems_forensic_private_ensure_tables($pdo);
    $forensicPerms = ems_forensic_private_effective_permissions($pdo, $user);
    if (!$forensicPerms['has_any_access']) {
        $_SESSION['flash_errors'][] = 'Akses Rekam Medis Private ditolak.';
        header('Location: /dashboard/index.php');
        exit;
    }
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
    $userName = strtolower(trim($user['full_name'] ?? ''));
    $userDivision = strtolower(trim($user['division'] ?? ''));
    $isProgrammerRoxwood = (strpos($userName, 'programmer') !== false && strpos($userName, 'roxwood') !== false);
    $isExecutive = (strpos($userDivision, 'executive') !== false);

    // Programmer Roxwood and Executive division can delete all records - skip all checks
    if (!$isProgrammerRoxwood && !$isExecutive) {
        if ($recordScope !== 'forensic_private' && (int) ($record['created_by'] ?? 0) !== $userId) {
            throw new Exception('Hanya pembuat rekam medis yang dapat menghapus data ini.');
        }
        if ($recordScope === 'forensic_private') {
            ems_forensic_private_ensure_tables($pdo);
            $rowForensicPerms = $forensicPerms ?? ems_forensic_private_effective_permissions($pdo, $user);
            if (!ems_forensic_private_can_delete_row($rowForensicPerms, $record, $userId)) {
                throw new Exception('Akses rekam medis private ditolak.');
            }
        }
        if ($isForensicPrivate && $recordScope !== 'forensic_private') {
            throw new Exception('Rekam medis private tidak ditemukan.');
        }
        if (!$isForensicPrivate && $recordScope === 'forensic_private') {
            throw new Exception('Akses rekam medis private ditolak.');
        }
    }

    if ($recordScope === 'forensic_private') {
        $recordCodeForLog = (string) ($record['record_code'] ?? ('MR-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)));
        ems_forensic_private_log_action($pdo, $id, 'deleted', $user, 'Record ' . $recordCodeForLog . ' (' . (string) ($record['patient_name'] ?? '-') . ') dihapus.');
    }

    // Delete files
    if ($record['ktp_file_path'] && file_exists(__DIR__ . '/../' . $record['ktp_file_path'])) {
        unlink(__DIR__ . '/../' . $record['ktp_file_path']);
    }

    if ($record['mri_file_path'] && file_exists(__DIR__ . '/../' . $record['mri_file_path'])) {
        unlink(__DIR__ . '/../' . $record['mri_file_path']);
    }

    if (!empty($record['visum_letter_file_path']) && file_exists(__DIR__ . '/../' . $record['visum_letter_file_path'])) {
        unlink(__DIR__ . '/../' . $record['visum_letter_file_path']);
    }

    foreach (ems_delete_medical_record_supporting_images($pdo, $id) as $attachmentPath) {
        $fullPath = __DIR__ . '/../' . ltrim((string)$attachmentPath, '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
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
