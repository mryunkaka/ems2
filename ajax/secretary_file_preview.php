<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/helpers.php';

ems_require_division_access(['Secretary'], '/dashboard/index.php');

header('Content-Type: application/json; charset=UTF-8');

function secretaryPreviewJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$path = trim((string) ($_GET['path'] ?? ''));
if ($path === '') {
    secretaryPreviewJson([
        'success' => false,
        'message' => 'Path file tidak valid.',
    ], 400);
}

$relativePath = ltrim(str_replace('\\', '/', $path), '/');
if (!str_starts_with($relativePath, 'storage/secretary/file_records/')) {
    secretaryPreviewJson([
        'success' => false,
        'message' => 'Akses file tidak diizinkan.',
    ], 403);
}

$fullPath = realpath(__DIR__ . '/../' . $relativePath);
$allowedBase = realpath(__DIR__ . '/../storage/secretary/file_records');

if ($fullPath === false || $allowedBase === false || !str_starts_with(str_replace('\\', '/', $fullPath), str_replace('\\', '/', $allowedBase))) {
    secretaryPreviewJson([
        'success' => false,
        'message' => 'File tidak ditemukan.',
    ], 404);
}

if (!is_file($fullPath)) {
    secretaryPreviewJson([
        'success' => false,
        'message' => 'File tidak ditemukan.',
    ], 404);
}

$name = trim((string) ($_GET['name'] ?? '')) ?: basename($fullPath);
$extension = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));
$mime = strtolower((string) (@mime_content_type($fullPath) ?: ''));
$publicSrc = '/' . ltrim($relativePath, '/');

if (in_array($extension, ['jpg', 'jpeg', 'png'], true) || str_starts_with($mime, 'image/')) {
    secretaryPreviewJson([
        'success' => true,
        'type' => 'image',
        'title' => $name,
        'src' => $publicSrc,
    ]);
}

if ($extension === 'pdf' || $mime === 'application/pdf') {
    secretaryPreviewJson([
        'success' => true,
        'type' => 'pdf',
        'title' => $name,
        'src' => $publicSrc,
    ]);
}

if ($extension === 'doc') {
    $text = emsExtractLegacyDocText($fullPath);
    secretaryPreviewJson([
        'success' => true,
        'type' => 'doc',
        'title' => $name,
        'content' => $text !== '' ? $text : 'Preview dokumen tidak tersedia untuk file ini.',
        'src' => $publicSrc,
    ]);
}

if ($extension === 'docx') {
    $text = emsExtractDocxText($fullPath);
    secretaryPreviewJson([
        'success' => true,
        'type' => 'doc',
        'title' => $name,
        'content' => $text !== '' ? $text : 'Preview dokumen tidak tersedia untuk file ini.',
        'src' => $publicSrc,
    ]);
}

secretaryPreviewJson([
    'success' => false,
    'message' => 'Tipe file tidak didukung untuk preview.',
], 415);
