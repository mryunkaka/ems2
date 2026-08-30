<?php

/**
 * Backfill/re-extract teks untuk dokumen di modul Dokumen
 * (`document_files`) — dua kegunaan sekaligus:
 *
 * 1. Dokumen yang belum ke-extract sama sekali (`extraction_status`
 *    'pending'/'failed', mis. karena dependency `smalot/pdfparser` /
 *    `phpoffice/phpspreadsheet` belum ter-install lengkap di server saat
 *    upload/import terjadi — vendor/ gitignored, tidak ikut ter-deploy
 *    otomatis lewat Git Version Control, harus di-`composer install`
 *    terpisah di server).
 * 2. Dokumen yang SUDAH `extraction_status='done'` tapi diextract sebelum
 *    perbaikan pembersihan artefak "<>" kosong dari smalot/pdfparser
 *    (lihat catatan di ems_document_extract_text() config/document_library.php,
 *    2026-08-30) — re-extract ulang untuk membersihkan teks lamanya.
 *
 * Baris `extraction_status='manual'` (isi ketikan manusia) SENGAJA tidak
 * disentuh sama sekali.
 *
 * CLI only. Jalankan lewat CLI, contoh:
 *   php bin/backfill_document_extraction.php --dry-run
 *   php bin/backfill_document_extraction.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/document_library.php';

ems_document_ensure_tables($pdo);

$options = getopt('', ['dry-run']);
$dryRun = array_key_exists('dry-run', $options);

$stats = ['done' => 0, 'unsupported' => 0, 'failed' => 0, 'skipped_missing_file' => 0];

echo "=== Backfill/Re-extract Dokumen (Modul Dokumen) ===\n";
echo "Mode: " . ($dryRun ? 'DRY RUN (tidak menulis apa pun)' : 'LIVE') . "\n\n";

$stmt = $pdo->query("SELECT id, title, file_path, file_ext, extraction_status FROM document_files WHERE extraction_status != 'manual' ORDER BY id ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo count($rows) . " dokumen akan diproses (status pending/done/failed/unsupported, kecuali 'manual').\n\n";

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $path = (string)$row['file_path'];
    $ext = (string)$row['file_ext'];
    $fullPath = __DIR__ . '/../' . ltrim($path, '/');

    if (!is_file($fullPath)) {
        echo "  [SKIP] id={$id} \"{$row['title']}\" — file tidak ditemukan di disk: {$path}\n";
        $stats['skipped_missing_file']++;
        continue;
    }

    if ($dryRun) {
        echo "  [DRY-RUN] id={$id} \"{$row['title']}\" (status saat ini: {$row['extraction_status']}) akan diekstrak ulang\n";
        continue;
    }

    $result = ems_document_extract_text($fullPath, $ext);

    $update = $pdo->prepare("UPDATE document_files SET extracted_text = ?, extraction_status = ?, updated_at = NOW() WHERE id = ?");
    $update->execute([
        $result['text'] !== '' ? $result['text'] : null,
        $result['status'],
        $id,
    ]);

    $stats[$result['status']] = ($stats[$result['status']] ?? 0) + 1;
    echo "  [OK] id={$id} \"{$row['title']}\" -> {$result['status']}\n";
}

echo "\n=== Selesai ===\n";
if (!$dryRun) {
    echo "done: {$stats['done']}\n";
    echo "unsupported: {$stats['unsupported']}\n";
    echo "failed: {$stats['failed']}\n";
    echo "dilewati (file tidak ada di disk): {$stats['skipped_missing_file']}\n";
    if ($stats['failed'] > 0) {
        echo "\nKalau masih ada yang 'failed', kemungkinan besar dependency Composer\n";
        echo "(smalot/pdfparser untuk PDF, phpoffice/phpspreadsheet untuk xlsx/xls)\n";
        echo "belum lengkap ter-install di server ini — cek dengan:\n";
        echo "  php -r \"require 'vendor/autoload.php'; var_dump(class_exists('Smalot\\\\PdfParser\\\\Parser'));\"\n";
    }
} else {
    echo "Ini baru dry-run — jalankan tanpa --dry-run untuk benar-benar menulis.\n";
}
