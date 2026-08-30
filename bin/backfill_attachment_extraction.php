<?php

/**
 * Backfill sekali-jalan: ekstrak teks untuk lampiran lama (yang sudah ada
 * sebelum kolom extracted_text/extraction_status ditambahkan) di 4 tabel
 * lampiran yang menerima dokumen berteks — lihat
 * docs/AI_ASSISTANT_MODULE.md §6.3 (Opsi A) dan
 * docs/sql/73_2026-08-30_attachment_text_extraction.sql.
 *
 * CLI only. Jalankan lewat CLI, contoh:
 *   php bin/backfill_attachment_extraction.php --dry-run
 *   php bin/backfill_attachment_extraction.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/attachment_extraction.php';

$options = getopt('', ['dry-run']);
$dryRun = array_key_exists('dry-run', $options);

// Cuma 4 tabel yang genuinely bisa berisi dokumen berteks (bukan foto) —
// lihat docs/AI_ASSISTANT_MODULE.md §6.3 untuk daftar lengkap & alasannya.
$targets = [
    'secretary_file_record_attachments',
    'meeting_minutes_attachments',
    'disciplinary_case_attachments',
    'disciplinary_warning_letter_attachments',
];

$stats = ['done' => 0, 'unsupported' => 0, 'failed' => 0, 'skipped_missing_file' => 0];

echo "=== Backfill Ekstraksi Lampiran ===\n";
echo "Mode: " . ($dryRun ? 'DRY RUN (tidak menulis apa pun)' : 'LIVE') . "\n\n";

foreach ($targets as $table) {
    ems_attachment_ensure_extraction_columns($pdo, $table);

    $stmt = $pdo->prepare("SELECT id, file_path FROM `{$table}` WHERE extraction_status = 'pending'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "--- {$table} ({$stmt->rowCount()} baris pending) ---\n";

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $path = (string)$row['file_path'];
        $fullPath = __DIR__ . '/../' . ltrim($path, '/');

        if (!is_file($fullPath)) {
            echo "  [SKIP] id={$id} file tidak ditemukan di disk: {$path}\n";
            $stats['skipped_missing_file']++;
            continue;
        }

        if ($dryRun) {
            echo "  [DRY-RUN] id={$id} akan diekstrak: {$path}\n";
            continue;
        }

        ems_attachment_extract_and_store($pdo, $table, $id, $path);

        $status = $pdo->prepare("SELECT extraction_status FROM `{$table}` WHERE id = ?");
        $status->execute([$id]);
        $result = (string)$status->fetchColumn();
        $stats[$result] = ($stats[$result] ?? 0) + 1;

        echo "  [OK] id={$id} -> {$result} ({$path})\n";
    }
}

echo "\n=== Selesai ===\n";
if (!$dryRun) {
    echo "done: {$stats['done']}\n";
    echo "unsupported: {$stats['unsupported']}\n";
    echo "failed: {$stats['failed']}\n";
    echo "dilewati (file tidak ada di disk): {$stats['skipped_missing_file']}\n";
} else {
    echo "Ini baru dry-run — jalankan tanpa --dry-run untuk benar-benar menulis.\n";
}
