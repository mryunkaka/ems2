<?php

/**
 * Ekstraksi teks untuk lampiran di luar modul Dokumen (Secretary File
 * Registry, Notulen, lampiran Komdis) — Opsi A dari
 * docs/AI_ASSISTANT_MODULE.md §6.3, supaya isinya ikut FULLTEXT-searchable
 * dan bisa dipakai basis pengetahuan chat bot AI nanti.
 *
 * Reuse penuh mesin ekstraksi yang sudah ada & teruji di modul Dokumen
 * (config/document_library.php) — tidak menduplikasi logika ekstraksi PDF/
 * DOCX/ODT/DOC/xlsx, cuma menambahkan lapisan tipis untuk menyimpan
 * hasilnya ke tabel-tabel lampiran yang berbeda ini.
 */

require_once __DIR__ . '/document_library.php';

// $table selalu literal string dari kode kita sendiri di tiap call site,
// tidak pernah dari input user — pola yang sama dipakai
// ems_document_delete_folder_cascade() di config/document_library.php.
function ems_attachment_ensure_extraction_columns(PDO $pdo, string $table): void
{
    if (!ems_column_exists($pdo, $table, 'extracted_text')) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL");
    }
    if (!ems_column_exists($pdo, $table, 'extraction_status')) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending'");
        return;
    }

    // Kolom bisa saja sudah ada dari migration 73 (sebelum nilai 'manual'
    // ditambahkan di migration 74) — pastikan enum-nya ikut diperbarui,
    // bukan cuma dicek "kolomnya ada atau tidak".
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'extraction_status'
    ");
    $stmt->execute([$table]);
    $columnType = (string) $stmt->fetchColumn();
    if ($columnType !== '' && strpos($columnType, "'manual'") === false) {
        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending'");
    }
}

// Untuk lampiran yang PASTI berupa foto (dipaksa .jpg/.png oleh
// pipeline upload-nya, mis. Surat Keluar/Surat Masuk/Agenda Kunjungan/
// Koordinasi Internal/Surat Rahasia) — deskripsi manual dari uploader
// jadi pengganti ekstraksi otomatis, supaya tetap bisa dicari basis
// pengetahuan chat bot AI nanti. Tidak menimpa hasil ekstraksi asli kalau
// baris itu ternyata sudah 'done' (PDF/DOC asli yang berhasil diekstrak).
function ems_attachment_store_manual_description(PDO $pdo, string $table, int $rowId, string $description): void
{
    $description = trim($description);
    if ($description === '' || $rowId <= 0) {
        return;
    }

    ems_attachment_ensure_extraction_columns($pdo, $table);

    $stmt = $pdo->prepare("
        UPDATE `{$table}`
        SET extracted_text = ?, extraction_status = 'manual'
        WHERE id = ? AND extraction_status != 'done'
    ");
    $stmt->execute([$description, $rowId]);
}

// Dipanggil setelah satu baris lampiran berhasil disimpan (file sudah ada
// di disk, baris sudah ter-INSERT) — mengekstrak lalu langsung UPDATE baris
// itu juga. Sinkron di request yang sama, sama seperti modul Dokumen.
function ems_attachment_extract_and_store(PDO $pdo, string $table, int $rowId, string $relativeFilePath): void
{
    ems_attachment_ensure_extraction_columns($pdo, $table);

    $ext = strtolower((string) pathinfo($relativeFilePath, PATHINFO_EXTENSION));
    $fullPath = __DIR__ . '/../' . ltrim($relativeFilePath, '/');

    $result = is_file($fullPath)
        ? ems_document_extract_text($fullPath, $ext)
        : ['text' => '', 'status' => 'failed'];

    $stmt = $pdo->prepare("UPDATE `{$table}` SET extracted_text = ?, extraction_status = ? WHERE id = ?");
    $stmt->execute([
        $result['text'] !== '' ? $result['text'] : null,
        $result['status'],
        $rowId,
    ]);
}
