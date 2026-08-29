<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/document_library.php';

header('Content-Type: application/json');

emsRequireRateLimit('document_search', emsCurrentRequestIdentifier((int)($_SESSION['user_rh']['id'] ?? 0)), 40, 60, 'Pencarian terlalu sering. Coba lagi nanti.');

ems_document_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$unitCode = ems_effective_unit($pdo, $user);

$query = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($query) < 2) {
    echo json_encode(['items' => []]);
    exit;
}

$rows = ems_document_search($pdo, $unitCode, $query, 20);

$allFolders = ems_document_fetch_folders($pdo, $unitCode);
$foldersById = ems_document_folders_by_id($allFolders);

$items = [];
foreach ($rows as $row) {
    $folderId = (int)$row['folder_id'];
    $breadcrumb = ems_document_folder_breadcrumb($foldersById, $folderId);
    $snippet = ems_document_build_snippet((string)($row['extracted_text'] ?? ''), $query);

    $items[] = [
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'division' => (string)$row['division'],
        'breadcrumb' => $breadcrumb !== '' ? $breadcrumb : (string)$row['division'],
        'ext' => (string)$row['file_ext'],
        'snippet' => $snippet,
    ];
}

echo json_encode(['items' => $items]);
