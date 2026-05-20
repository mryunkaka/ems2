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
