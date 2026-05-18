<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json; charset=UTF-8');

function disciplinaryPreviewJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$path = trim((string)($_GET['path'] ?? ''));
if ($path === '') {
    disciplinaryPreviewJson([
        'success' => false,
        'message' => 'Path file tidak valid.',
    ], 400);
}

$relativePath = ltrim(str_replace('\\', '/', $path), '/');
$allowedPrefixes = [
    'storage/disciplinary/cases/',
    'storage/disciplinary/warning_letters/',
];

$isAllowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (str_starts_with($relativePath, $prefix)) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    disciplinaryPreviewJson([
        'success' => false,
        'message' => 'Akses file tidak diizinkan.',
    ], 403);
}

$fullPath = realpath(__DIR__ . '/../' . $relativePath);
$storageBase = realpath(__DIR__ . '/../storage/disciplinary');
$normalizedFullPath = $fullPath !== false ? str_replace('\\', '/', $fullPath) : '';
$normalizedStorageBase = $storageBase !== false ? str_replace('\\', '/', $storageBase) : '';

if ($fullPath === false || $storageBase === false || !str_starts_with($normalizedFullPath, $normalizedStorageBase)) {
    disciplinaryPreviewJson([
        'success' => false,
        'message' => 'File tidak ditemukan.',
    ], 404);
}

if (!is_file($fullPath)) {
    disciplinaryPreviewJson([
        'success' => false,
        'message' => 'File tidak ditemukan.',
    ], 404);
}

$currentUser = $_SESSION['user_rh'] ?? [];
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserDivision = ems_normalize_division($currentUser['division'] ?? '');
$canViewAllDisciplinaryFiles = $currentUserDivision === 'Disciplinary Committee';
if ($currentUserId <= 0) {
    disciplinaryPreviewJson([
        'success' => false,
        'message' => 'Session user tidak valid.',
    ], 401);
}

$ownerCheckPassed = false;

if (str_starts_with($relativePath, 'storage/disciplinary/cases/')) {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM disciplinary_case_attachments dca
        INNER JOIN disciplinary_cases dc ON dc.id = dca.case_id
        WHERE dca.file_path = ?
          AND (? = 1 OR dc.subject_user_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$relativePath, $canViewAllDisciplinaryFiles ? 1 : 0, $currentUserId]);
    $ownerCheckPassed = (bool)$stmt->fetchColumn();
} elseif (str_starts_with($relativePath, 'storage/disciplinary/warning_letters/')) {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM disciplinary_warning_letter_attachments dwla
        INNER JOIN disciplinary_warning_letters dwl ON dwl.id = dwla.warning_letter_id
        WHERE dwla.file_path = ?
          AND (? = 1 OR dwl.subject_user_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$relativePath, $canViewAllDisciplinaryFiles ? 1 : 0, $currentUserId]);
    $ownerCheckPassed = (bool)$stmt->fetchColumn();
}

if (!$ownerCheckPassed) {
    disciplinaryPreviewJson([
        'success' => false,
        'message' => 'Anda tidak memiliki akses ke lampiran ini.',
    ], 403);
}

$name = trim((string)($_GET['name'] ?? '')) ?: basename($fullPath);
$extension = strtolower((string)pathinfo($fullPath, PATHINFO_EXTENSION));
$mime = strtolower((string)(@mime_content_type($fullPath) ?: ''));
$publicSrc = '/' . ltrim($relativePath, '/');

if (in_array($extension, ['jpg', 'jpeg', 'png'], true) || str_starts_with($mime, 'image/')) {
    disciplinaryPreviewJson([
        'success' => true,
        'type' => 'image',
        'title' => $name,
        'src' => $publicSrc,
    ]);
}

if ($extension === 'pdf' || in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
    disciplinaryPreviewJson([
        'success' => true,
        'type' => 'pdf',
        'title' => $name,
        'src' => $publicSrc,
    ]);
}

disciplinaryPreviewJson([
    'success' => false,
    'message' => 'Tipe file tidak didukung untuk preview.',
], 415);
