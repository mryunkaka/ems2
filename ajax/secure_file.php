<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

function secureFileAbort(int $statusCode = 404, string $message = 'File tidak ditemukan.'): void
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

function secureFileNormalizedPath(string $path): string
{
    return ltrim(str_replace('\\', '/', trim($path)), '/');
}

function secureFileResolvedPath(string $relativePath): ?string
{
    $fullPath = realpath(__DIR__ . '/../' . $relativePath);
    if ($fullPath === false || !is_file($fullPath)) {
        return null;
    }

    return $fullPath;
}

function secureFileIsInside(string $fullPath, string $basePath): bool
{
    return str_starts_with(str_replace('\\', '/', $fullPath), str_replace('\\', '/', $basePath));
}

$relativePath = secureFileNormalizedPath((string)($_GET['path'] ?? ''));
if ($relativePath === '' || !str_starts_with($relativePath, 'storage/')) {
    secureFileAbort(400, 'Path file tidak valid.');
}

$fullPath = secureFileResolvedPath($relativePath);
if ($fullPath === null) {
    secureFileAbort(404);
}

$storageRoot = realpath(__DIR__ . '/../storage');
if ($storageRoot === false || !secureFileIsInside($fullPath, $storageRoot)) {
    secureFileAbort(403, 'Akses file tidak diizinkan.');
}

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userRole = strtolower(trim((string)($user['role'] ?? '')));
$userDivision = ems_normalize_division($user['division'] ?? '');
if ($userId <= 0) {
    secureFileAbort(401, 'Session user tidak valid.');
}

if (str_starts_with($relativePath, 'storage/identity/')) {
    // Identity wajib lewat endpoint terproteksi, session login sudah cukup untuk akses internal.
} elseif (str_starts_with($relativePath, 'storage/reimbursements/')) {
    $stmt = $pdo->prepare("SELECT 1 FROM reimbursements WHERE receipt_file = ? LIMIT 1");
    $stmt->execute([$relativePath]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/disciplinary/cases/')) {
    $canViewAll = $userDivision === 'Disciplinary Committee';
    $stmt = $pdo->prepare("
        SELECT 1
        FROM disciplinary_case_attachments dca
        INNER JOIN disciplinary_cases dc ON dc.id = dca.case_id
        WHERE dca.file_path = ?
          AND (? = 1 OR dc.subject_user_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$relativePath, $canViewAll ? 1 : 0, $userId]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/disciplinary/warning_letters/')) {
    $canViewAll = $userDivision === 'Disciplinary Committee';
    $stmt = $pdo->prepare("
        SELECT 1
        FROM disciplinary_warning_letter_attachments dwla
        INNER JOIN disciplinary_warning_letters dwl ON dwl.id = dwla.warning_letter_id
        WHERE dwla.file_path = ?
          AND (? = 1 OR dwl.subject_user_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$relativePath, $canViewAll ? 1 : 0, $userId]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/secretary/file_records/')) {
    if ($userDivision !== 'Secretary') {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/user_docs/')) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM user_rh
        WHERE file_ktp = ?
           OR file_sim = ?
           OR file_kta = ?
           OR file_skb = ?
           OR sertifikat_heli = ?
           OR sertifikat_operasi = ?
           OR COALESCE(dokumen_lainnya, '') LIKE ?
        LIMIT 1
    ");
    $likePath = '%' . $relativePath . '%';
    $stmt->execute([$relativePath, $relativePath, $relativePath, $relativePath, $relativePath, $relativePath, $likePath]);
    $ownerId = (int)$stmt->fetchColumn();
    if ($ownerId <= 0 || ($ownerId !== $userId && ems_is_staff_role($userRole))) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/applicants/')) {
    $stmt = $pdo->prepare("SELECT 1 FROM applicant_documents WHERE file_path = ? LIMIT 1");
    $stmt->execute([$relativePath]);
    if (!(bool)$stmt->fetchColumn() || ems_is_staff_role($userRole)) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/restaurant_ktp/')) {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM restaurant_consumptions
        WHERE ktp_file = ?
          AND (recipient_user_id = ? OR created_by = ? OR ? = 1)
        LIMIT 1
    ");
    $stmt->execute([$relativePath, $userId, $userId, ems_is_staff_role($userRole) ? 0 : 1]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/letters/')) {
    if (!ems_is_letter_receiver_role((string)($user['role'] ?? '')) && !in_array($userDivision, ['Secretary', 'General Affair', 'Executive'], true)) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/general_affair/cooperation_inputs/')) {
    if (!in_array($userDivision, ['General Affair', 'Executive', 'Secretary'], true)) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/medical_records/')) {
    $canAccessForensicMedicalRecord = ems_can_access_division_menu($userDivision, 'Forensic');
    $allowed = false;

    $stmt = $pdo->prepare("
        SELECT COALESCE(mr.visibility_scope, 'standard') AS visibility_scope
        FROM medical_records mr
        WHERE (mr.ktp_file_path = ? OR mr.mri_file_path = ?)
        LIMIT 1
    ");
    $stmt->execute([$relativePath, $relativePath]);
    $scope = (string)($stmt->fetchColumn() ?: '');
    if ($scope !== '') {
        $allowed = $scope === 'standard' || ($scope === 'forensic_private' && $canAccessForensicMedicalRecord);
    }

    if (!$allowed && ems_table_exists($pdo, 'medical_record_supporting_images')) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(mr.visibility_scope, 'standard') AS visibility_scope
            FROM medical_record_supporting_images si
            INNER JOIN medical_records mr ON mr.id = si.medical_record_id
            WHERE si.file_path = ?
            LIMIT 1
        ");
        $stmt->execute([$relativePath]);
        $scope = (string)($stmt->fetchColumn() ?: '');
        if ($scope !== '') {
            $allowed = $scope === 'standard' || ($scope === 'forensic_private' && $canAccessForensicMedicalRecord);
        }
    }
    if (!$allowed) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} else {
    secureFileAbort(403, 'Akses file tidak diizinkan.');
}

$mime = (string)(@mime_content_type($fullPath) ?: 'application/octet-stream');
$filename = basename($fullPath);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
readfile($fullPath);
exit;
