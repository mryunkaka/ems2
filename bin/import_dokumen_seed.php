<?php

/**
 * Import sekali-jalan: baca storage/dokumen_import/ (struktur folder asli
 * Handbook-EMS dkk) lalu buat document_folders/document_files yang sesuai
 * di database + jalankan ekstraksi teks, supaya hierarki folder yang sudah
 * disusun manual langsung jadi struktur di modul "Dokumen".
 *
 * CLI only (lihat docs/DOCUMENT_LIBRARY_MODULE.md §11 poin 8 untuk
 * pemetaan folder→division). Jalankan lewat CLI, contoh:
 *   php bin/import_dokumen_seed.php --unit=roxwood --actor-id=1
 *   php bin/import_dokumen_seed.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/document_library.php';

$options = getopt('', ['unit::', 'actor-id::', 'dry-run']);
$unitCode = ems_normalize_unit_code($options['unit'] ?? 'roxwood');
$actorId = isset($options['actor-id']) ? (int)$options['actor-id'] : 0;
$dryRun = array_key_exists('dry-run', $options);

$actorName = 'Import Dokumen (CLI)';
if ($actorId > 0) {
    $stmt = $pdo->prepare("SELECT full_name FROM user_rh WHERE id = ? LIMIT 1");
    $stmt->execute([$actorId]);
    $found = $stmt->fetchColumn();
    if ($found) {
        $actorName = (string)$found;
    } else {
        fwrite(STDERR, "Peringatan: --actor-id={$actorId} tidak ditemukan di user_rh, dilanjutkan tanpa actor.\n");
        $actorId = 0;
    }
}
$actor = ['id' => $actorId, 'full_name' => $actorName];

$sourceRoot = realpath(__DIR__ . '/../storage/dokumen_import');
if ($sourceRoot === false || !is_dir($sourceRoot)) {
    fwrite(STDERR, "storage/dokumen_import/ tidak ditemukan.\n");
    exit(1);
}

// Selalu pastikan tabel ada (idempotent, tidak menulis data) — bahkan saat
// dry-run, supaya query SELECT pengecekan folder existing di bawah tetap
// bisa jalan tanpa error "table doesn't exist".
ems_document_ensure_tables($pdo);

// Keputusan §11 poin 8 docs/DOCUMENT_LIBRARY_MODULE.md — nama folder persis
// (case-sensitive) yang punya division pemilik spesifik; selain itu
// mewarisi division folder induknya, default root = Medis.
$divisionNameMap = [
    '1. KEBIJAKAN DAN SOP SMA' => 'Medical Affair',
    '2. SOP Committee Discipline' => 'Disciplinary Committee',
    '3. SOP General Affairs' => 'General Affair',
    '4. SOP Sekretariat Relation' => 'Secretary',
    '5. SOP Human Resource' => 'Human Resource',
    '6. SOP Forensic' => 'Forensic',
];

$stats = ['folders_created' => 0, 'folders_existing' => 0, 'files_imported' => 0, 'files_skipped' => 0, 'files_existing' => 0];

function importFindOrCreateFolder(PDO $pdo, string $unitCode, ?int $parentId, string $name, string $division, array $actor, bool $dryRun, array &$stats): int
{
    $stmt = $pdo->prepare("SELECT id FROM document_folders WHERE unit_code = ? AND parent_id <=> ? AND name = ? LIMIT 1");
    $stmt->execute([$unitCode, $parentId, $name]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        $stats['folders_existing']++;
        return (int)$existingId;
    }

    $stats['folders_created']++;
    echo ($dryRun ? '[DRY-RUN] ' : '') . "Buat folder: {$name} (division: {$division}, parent_id: " . ($parentId ?? 'root') . ")\n";

    if ($dryRun) {
        return -1 * (1000000 + $stats['folders_created']); // id semu, unik & negatif supaya tidak bentrok saat dry-run
    }

    $stmt = $pdo->prepare("
        INSERT INTO document_folders (unit_code, division, parent_id, name, created_by, created_by_name_snapshot, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([$unitCode, $division, $parentId, $name, $actor['id'] ?: null, $actor['full_name']]);
    $newId = (int)$pdo->lastInsertId();
    ems_document_log_activity($pdo, $unitCode, null, $newId, $division, 'folder_created', $name . ' (import seed)', $actor);

    return $newId;
}

function importFile(PDO $pdo, string $unitCode, int $folderId, string $division, string $fullPath, string $filename, array $actor, bool $dryRun, array &$stats): void
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, ems_document_allowed_extensions(), true)) {
        echo "  [SKIP] {$filename} (ekstensi .{$ext} tidak didukung)\n";
        $stats['files_skipped']++;
        return;
    }

    if (!$dryRun) {
        $stmt = $pdo->prepare("SELECT id FROM document_files WHERE unit_code = ? AND folder_id = ? AND original_filename = ? LIMIT 1");
        $stmt->execute([$unitCode, $folderId, $filename]);
        if ($stmt->fetchColumn()) {
            echo "  [ADA] {$filename} (sudah pernah diimport, dilewati)\n";
            $stats['files_existing']++;
            return;
        }
    }

    $title = pathinfo($filename, PATHINFO_FILENAME);

    if ($dryRun) {
        echo "  [DRY-RUN] Import file: {$filename} -> folder_id={$folderId}, division={$division}\n";
        $stats['files_imported']++;
        return;
    }

    $saved = ems_document_store_local_file($fullPath, $ext);
    if ($saved === null) {
        echo "  [GAGAL] {$filename} (tidak bisa disalin ke storage/documents/)\n";
        $stats['files_skipped']++;
        return;
    }

    $extraction = ems_document_extract_text($saved['full_path'], $ext);

    $stmt = $pdo->prepare("
        INSERT INTO document_files (
            unit_code, folder_id, division, title, original_filename, file_path, file_ext, mime_type,
            file_size_bytes, extracted_text, extraction_status, uploaded_by, uploaded_by_name_snapshot, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $unitCode,
        $folderId,
        $division,
        $title,
        $filename,
        $saved['path'],
        $ext,
        $saved['mime'],
        $saved['size'],
        $extraction['text'] !== '' ? $extraction['text'] : null,
        $extraction['status'],
        $actor['id'] ?: null,
        $actor['full_name'],
    ]);

    $newId = (int)$pdo->lastInsertId();
    ems_document_log_activity($pdo, $unitCode, $newId, $folderId, $division, 'uploaded', $title . ' (import seed)', $actor);

    echo "  [OK] {$filename} -> extraction: {$extraction['status']}\n";
    $stats['files_imported']++;
}

function importProcessDirectory(
    PDO $pdo,
    string $unitCode,
    string $dirPath,
    ?int $parentFolderId,
    string $inheritedDivision,
    array $divisionNameMap,
    array $actor,
    bool $dryRun,
    array &$stats,
    ?int &$fallbackRootFolderId
): void {
    $entries = scandir($dirPath);
    if ($entries === false) {
        return;
    }
    sort($entries, SORT_NATURAL);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $fullEntryPath = $dirPath . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($fullEntryPath)) {
            $division = $divisionNameMap[$entry] ?? $inheritedDivision;
            $folderId = importFindOrCreateFolder($pdo, $unitCode, $parentFolderId, $entry, $division, $actor, $dryRun, $stats);
            importProcessDirectory($pdo, $unitCode, $fullEntryPath, $folderId, $division, $divisionNameMap, $actor, $dryRun, $stats, $fallbackRootFolderId);
            continue;
        }

        $targetFolderId = $parentFolderId;
        if ($targetFolderId === null) {
            if ($fallbackRootFolderId === null) {
                $fallbackRootFolderId = importFindOrCreateFolder($pdo, $unitCode, null, 'Umum', 'Medis', $actor, $dryRun, $stats);
            }
            $targetFolderId = $fallbackRootFolderId;
        }

        importFile($pdo, $unitCode, $targetFolderId, $inheritedDivision, $fullEntryPath, $entry, $actor, $dryRun, $stats);
    }
}

echo "=== Import Dokumen Seed ===\n";
echo "Sumber : {$sourceRoot}\n";
echo "Unit   : {$unitCode}\n";
echo "Actor  : {$actorName}" . ($actorId > 0 ? " (id {$actorId})" : ' (tanpa akun)') . "\n";
echo "Mode   : " . ($dryRun ? 'DRY RUN (tidak menulis apa pun)' : 'LIVE') . "\n\n";

$fallbackRootFolderId = null;
importProcessDirectory($pdo, $unitCode, $sourceRoot, null, 'Medis', $divisionNameMap, $actor, $dryRun, $stats, $fallbackRootFolderId);

echo "\n=== Selesai ===\n";
echo "Folder dibuat   : {$stats['folders_created']}\n";
echo "Folder sudah ada: {$stats['folders_existing']}\n";
echo "File diimport   : {$stats['files_imported']}\n";
echo "File sudah ada  : {$stats['files_existing']}\n";
echo "File dilewati   : {$stats['files_skipped']}\n";

if ($dryRun) {
    echo "\nIni baru dry-run — jalankan tanpa --dry-run untuk benar-benar menulis ke database & storage/documents/.\n";
}
