<?php

/**
 * Document Library module ("Dokumen" menu) — lihat docs/DOCUMENT_LIBRARY_MODULE.md
 * untuk PRD/ERD lengkap & keputusan akses yang sudah dikonfirmasi.
 */

require_once __DIR__ . '/helpers.php';

function ems_document_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `document_folders` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
            `division` varchar(60) NOT NULL,
            `parent_id` int(11) DEFAULT NULL,
            `name` varchar(150) NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `created_by` int(11) DEFAULT NULL,
            `created_by_name_snapshot` varchar(150) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_document_folders_parent` (`parent_id`),
            KEY `idx_document_folders_unit_division` (`unit_code`, `division`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `document_files` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
            `folder_id` int(11) NOT NULL,
            `division` varchar(60) NOT NULL,
            `title` varchar(255) NOT NULL,
            `original_filename` varchar(255) NOT NULL,
            `file_path` varchar(255) NOT NULL,
            `file_ext` varchar(10) DEFAULT NULL,
            `mime_type` varchar(100) DEFAULT NULL,
            `file_size_bytes` int(11) NOT NULL DEFAULT 0,
            `tags` varchar(255) DEFAULT NULL,
            `extracted_text` longtext DEFAULT NULL,
            `extraction_status` enum('pending','done','unsupported','failed') NOT NULL DEFAULT 'pending',
            `uploaded_by` int(11) DEFAULT NULL,
            `uploaded_by_name_snapshot` varchar(150) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_document_files_folder` (`folder_id`),
            KEY `idx_document_files_unit_division` (`unit_code`, `division`),
            FULLTEXT KEY `ftx_document_files_search` (`title`, `tags`, `extracted_text`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `document_activity_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `unit_code` varchar(20) NOT NULL DEFAULT 'roxwood',
            `document_id` int(11) DEFAULT NULL,
            `folder_id` int(11) DEFAULT NULL,
            `division` varchar(60) DEFAULT NULL,
            `action` enum('uploaded','edited','replaced','moved','deleted','folder_created','folder_renamed','folder_moved','folder_deleted') NOT NULL,
            `note` varchar(255) DEFAULT NULL,
            `actor_user_id` int(11) DEFAULT NULL,
            `actor_name_snapshot` varchar(150) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_document_activity_logs_document` (`document_id`),
            KEY `idx_document_activity_logs_folder` (`folder_id`),
            KEY `idx_document_activity_logs_division` (`unit_code`, `division`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // Kolom `division` ditambahkan belakangan (2026-08-30) — guard defensif
    // untuk instalasi yang tabelnya sudah lebih dulu ada tanpa kolom ini,
    // mengikuti konvensi project (migration file + ems_column_exists() guard).
    if (!ems_column_exists($pdo, 'document_activity_logs', 'division')) {
        $pdo->exec("ALTER TABLE document_activity_logs ADD COLUMN division varchar(60) DEFAULT NULL AFTER folder_id");
        $pdo->exec("ALTER TABLE document_activity_logs ADD KEY idx_document_activity_logs_division (unit_code, division)");
    }

    // Nilai enum 'manual' (dokumen — biasanya PDF hasil scan/gambar — yang
    // ekstraksi otomatisnya gagal, lalu isinya diketik manual oleh admin)
    // ditambahkan belakangan (2026-08-30, lihat docs/sql/75_...) — cek
    // COLUMN_TYPE langsung dari INFORMATION_SCHEMA, bukan cuma
    // ems_column_exists(), karena kolomnya sudah ada dari awal, yang perlu
    // dideteksi adalah enum-nya yang mungkin masih versi lama.
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_files' AND COLUMN_NAME = 'extraction_status'
    ");
    $stmt->execute();
    $columnType = (string) $stmt->fetchColumn();
    if ($columnType !== '' && strpos($columnType, "'manual'") === false) {
        $pdo->exec("ALTER TABLE document_files MODIFY COLUMN extraction_status ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending'");
    }
}

// ===================================================================
// Permission helpers
// ===================================================================

function ems_document_is_executive_manager(array $user): bool
{
    return ems_normalize_division($user['division'] ?? '') === 'Executive'
        && ems_is_manager_plus_role($user['role'] ?? '');
}

function ems_document_can_open_manage_page(array $user): bool
{
    return ems_is_manager_plus_role($user['role'] ?? '');
}

function ems_document_can_upload_to_division(array $user, string $targetDivision): bool
{
    if (ems_document_is_executive_manager($user)) {
        return true;
    }
    if (!ems_is_manager_plus_role($user['role'] ?? '')) {
        return false;
    }
    return ems_normalize_division($user['division'] ?? '') === ems_normalize_division($targetDivision);
}

// Dikoreksi 2026-08-30 (user-requested): manager non-Executive boleh
// edit/hapus SEMUA dokumen di division-nya sendiri, bukan cuma yang dia
// upload sendiri — supaya manager benar-benar bisa mengelola dokumen
// division-nya, bukan cuma dokumen milik pribadinya.
function ems_document_can_edit_or_delete(array $user, array $documentRow): bool
{
    if (ems_document_is_executive_manager($user)) {
        return true;
    }
    if (!ems_is_manager_plus_role($user['role'] ?? '')) {
        return false;
    }
    return ems_normalize_division($user['division'] ?? '') === ems_normalize_division((string)($documentRow['division'] ?? ''));
}

// ===================================================================
// Upload: batas ukuran, ekstensi diizinkan, penyimpanan flat
// ===================================================================

function ems_document_upload_limit_bytes(): int
{
    return 10 * 1024 * 1024; // 10 MB — keputusan §11 poin 5, pas dengan batas .user.ini produksi
}

function ems_document_upload_limit_label(): string
{
    return '10 MB';
}

// Foto/gambar (jpg/jpeg/png) sengaja TIDAK diizinkan lagi di sini
// (2026-08-30, user-requested) — modul Dokumen ini untuk dokumen asli yang
// bisa dibaca/di-extract, bukan tempat upload foto; dokumen jenis
// gambar-saja (PDF hasil scan) tetap bisa masuk lewat isi manual
// (`extraction_status='manual'`, lihat ems_document_store_manual_content()),
// bukan lewat upload file gambar langsung. Baris document_files lama yang
// masih berekstensi jpg/jpeg/png (dari import awal) tetap bisa dibuka —
// lihat cabang gambar di document_view.php — ini hanya menutup jalur
// upload BARU, bukan menghapus data lama.
function ems_document_allowed_extensions(): array
{
    return ['pdf', 'doc', 'docx', 'odt', 'txt', 'md', 'csv', 'json', 'xml', 'log', 'ini', 'xlsx', 'xls'];
}

function ems_document_extractable_extensions(): array
{
    return ['pdf', 'doc', 'docx', 'odt', 'txt', 'md', 'csv', 'json', 'xml', 'log', 'ini', 'xlsx', 'xls'];
}

function ems_document_save_uploaded_file(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > ems_document_upload_limit_bytes()) {
        return null;
    }

    $sourcePath = (string)($file['tmp_name'] ?? '');
    if ($sourcePath === '' || !is_uploaded_file($sourcePath)) {
        return null;
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ems_document_allowed_extensions(), true)) {
        return null;
    }

    $baseDir = __DIR__ . '/../storage/documents';
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        return null;
    }

    $filename = uniqid('', true) . '_' . time() . '.' . $ext;
    $targetPath = $baseDir . '/' . $filename;

    if (!move_uploaded_file($sourcePath, $targetPath)) {
        return null;
    }

    return [
        'path' => 'storage/documents/' . $filename,
        'full_path' => $targetPath,
        'ext' => $ext,
        'mime' => (string)(mime_content_type($targetPath) ?: ''),
        'size' => filesize($targetPath) ?: $size,
    ];
}

// Dipakai importer CLI (bin/import_dokumen_seed.php) — sumbernya file lokal
// di storage/dokumen_import/, bukan $_FILES hasil HTTP upload, jadi pakai
// copy() biasa, bukan move_uploaded_file().
function ems_document_store_local_file(string $sourcePath, string $ext): ?array
{
    if (!is_file($sourcePath)) {
        return null;
    }

    $ext = strtolower($ext);
    $baseDir = __DIR__ . '/../storage/documents';
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        return null;
    }

    $filename = uniqid('', true) . '_' . time() . '.' . $ext;
    $targetPath = $baseDir . '/' . $filename;

    if (!copy($sourcePath, $targetPath)) {
        return null;
    }

    return [
        'path' => 'storage/documents/' . $filename,
        'full_path' => $targetPath,
        'ext' => $ext,
        'mime' => (string)(mime_content_type($targetPath) ?: ''),
        'size' => filesize($targetPath) ?: 0,
    ];
}

// ===================================================================
// Ekstraksi teks — inti dari pencarian cepat (dijalankan sekali saat
// upload, bukan saat search). docx/odt/doc reuse fungsi yang sudah ada
// di config/helpers.php; pdf pakai smalot/pdfparser (baru, lihat §8 & §11
// poin 6 docs/DOCUMENT_LIBRARY_MODULE.md).
// ===================================================================

function ems_document_normalize_extracted_text(string $text, int $maxChars = 500000): string
{
    $text = trim($text);
    if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        if ($converted !== false) {
            $text = $converted;
        }
    }
    if (mb_strlen($text) > $maxChars) {
        $text = mb_substr($text, 0, $maxChars);
    }
    return $text;
}

function ems_document_extract_text(string $fullPath, string $ext): array
{
    $ext = strtolower($ext);

    try {
        if (in_array($ext, ['txt', 'md', 'csv', 'json', 'xml', 'log', 'ini'], true)) {
            $raw = @file_get_contents($fullPath);
            $content = $raw === false ? '' : ems_document_normalize_extracted_text($raw);
            return ['text' => $content, 'status' => $content !== '' ? 'done' : 'failed'];
        }

        if ($ext === 'docx') {
            $content = ems_document_normalize_extracted_text(emsExtractDocxText($fullPath));
            return ['text' => $content, 'status' => $content !== '' ? 'done' : 'failed'];
        }

        if ($ext === 'odt') {
            $content = ems_document_normalize_extracted_text(emsExtractOdtText($fullPath));
            return ['text' => $content, 'status' => $content !== '' ? 'done' : 'failed'];
        }

        if ($ext === 'doc') {
            $content = ems_document_normalize_extracted_text(emsExtractLegacyDocText($fullPath));
            return ['text' => $content, 'status' => $content !== '' ? 'done' : 'failed'];
        }

        if ($ext === 'pdf') {
            require_once __DIR__ . '/../vendor/autoload.php';
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($fullPath);
            $content = ems_document_normalize_extracted_text((string)$pdf->getText());
            return ['text' => $content, 'status' => $content !== '' ? 'done' : 'failed'];
        }

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            require_once __DIR__ . '/../vendor/autoload.php';
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fullPath);
            $lines = [];
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                $lines[] = '=== ' . $sheet->getTitle() . ' ===';
                $totalRows = $sheet->getHighestDataRow();
                $totalColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
                for ($row = 1; $row <= $totalRows; $row++) {
                    $cells = [];
                    for ($col = 1; $col <= $totalColIndex; $col++) {
                        $cells[] = trim((string)$sheet->getCell([$col, $row])->getFormattedValue());
                    }
                    $lineText = trim(implode(' | ', $cells));
                    if ($lineText !== '') {
                        $lines[] = $lineText;
                    }
                }
            }
            $content = ems_document_normalize_extracted_text(implode("\n", $lines));
            return ['text' => $content, 'status' => $content !== '' ? 'done' : 'failed'];
        }
    } catch (\Throwable $e) {
        return ['text' => '', 'status' => 'failed'];
    }

    return ['text' => '', 'status' => 'unsupported'];
}

// Untuk dokumen yang ekstraksi otomatisnya gagal (paling sering: PDF hasil
// scan/berisi gambar, tidak punya text layer sama sekali) — admin bisa
// ketik ulang isinya secara manual lewat form upload/edit di
// document_manage.php, supaya dokumen tsb tetap masuk index FULLTEXT.
// Sengaja TIDAK menimpa baris yang status-nya sudah 'done' (ekstraksi asli
// berhasil) — sama seperti ems_attachment_store_manual_description() di
// config/attachment_extraction.php.
function ems_document_store_manual_content(PDO $pdo, int $docId, string $content): bool
{
    $content = trim($content);
    if ($content === '' || $docId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE document_files
        SET extracted_text = ?, extraction_status = 'manual', updated_at = NOW()
        WHERE id = ? AND extraction_status != 'done'
    ");
    $stmt->execute([$content, $docId]);

    return $stmt->rowCount() > 0;
}

// Render tabel HTML dari xlsx/xls untuk ditampilkan langsung di
// document_view.php (bukan disimpan di DB — dibaca ulang dari file setiap
// kali dibuka, supaya extracted_text tetap plain text untuk FULLTEXT
// search, tidak tercampur markup). Dibatasi jumlah baris/kolom supaya
// spreadsheet raksasa tidak bikin halaman berat.
function ems_document_render_spreadsheet_html(string $fullPath, int $maxRows = 300, int $maxCols = 40): ?string
{
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fullPath);
    } catch (\Throwable $e) {
        return null;
    }

    $html = '';
    foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
        $totalRows = $sheet->getHighestDataRow();
        $totalColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $renderRows = min($totalRows, $maxRows);
        $renderCols = min($totalColIndex, $maxCols);

        $html .= '<h3 class="doc-xlsx-sheet-title">' . htmlspecialchars($sheet->getTitle(), ENT_QUOTES, 'UTF-8') . '</h3>';
        $html .= '<div class="doc-xlsx-table-wrap"><table class="doc-xlsx-table">';

        for ($row = 1; $row <= $renderRows; $row++) {
            $html .= '<tr>';
            for ($col = 1; $col <= $renderCols; $col++) {
                $value = (string) $sheet->getCell([$col, $row])->getFormattedValue();
                $html .= '<td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table></div>';

        if ($totalRows > $maxRows || $totalColIndex > $maxCols) {
            $html .= '<p class="meta-text">Ditampilkan ' . $renderRows . ' baris &times; ' . $renderCols
                . ' kolom pertama dari total ' . $totalRows . ' baris.</p>';
        }
    }

    return $html !== '' ? $html : null;
}

// ===================================================================
// Folder tree
// ===================================================================

function ems_document_fetch_folders(PDO $pdo, string $unitCode): array
{
    $stmt = $pdo->prepare("SELECT * FROM document_folders WHERE unit_code = ? ORDER BY division ASC, sort_order ASC, name ASC");
    $stmt->execute([$unitCode]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ems_document_folders_for_user(array $folders, array $user): array
{
    if (ems_document_is_executive_manager($user)) {
        return $folders;
    }
    $division = ems_normalize_division($user['division'] ?? '');
    return array_values(array_filter($folders, static function ($f) use ($division) {
        return ems_normalize_division((string)$f['division']) === $division;
    }));
}

function ems_document_build_tree(array $folders): array
{
    $byId = [];
    $byParent = [];
    foreach ($folders as $f) {
        $id = (int)$f['id'];
        $parentId = $f['parent_id'] !== null ? (int)$f['parent_id'] : null;
        $byId[$id] = $f;
        $byParent[$parentId === null ? 0 : $parentId][] = $id;
    }

    $build = function (int $parentKey) use (&$build, $byParent, $byId): array {
        $nodes = [];
        foreach ($byParent[$parentKey] ?? [] as $id) {
            $node = $byId[$id];
            $node['children'] = $build($id);
            $nodes[] = $node;
        }
        return $nodes;
    };

    return $build(0);
}

function ems_document_folder_descendant_ids(array $folders, int $folderId): array
{
    $childrenOf = [];
    foreach ($folders as $f) {
        $parentId = $f['parent_id'] !== null ? (int)$f['parent_id'] : null;
        if ($parentId !== null) {
            $childrenOf[$parentId][] = (int)$f['id'];
        }
    }

    $result = [];
    $queue = [$folderId];
    while ($queue) {
        $current = array_shift($queue);
        foreach ($childrenOf[$current] ?? [] as $childId) {
            $result[] = $childId;
            $queue[] = $childId;
        }
    }

    return $result;
}

function ems_document_folder_breadcrumb(array $foldersById, int $folderId): string
{
    $parts = [];
    $current = $foldersById[$folderId] ?? null;
    $guard = 0;
    while ($current !== null && $guard < 50) {
        array_unshift($parts, (string)$current['name']);
        $parentId = $current['parent_id'] !== null ? (int)$current['parent_id'] : null;
        $current = $parentId !== null ? ($foldersById[$parentId] ?? null) : null;
        $guard++;
    }
    return implode(' / ', $parts);
}

function ems_document_folders_by_id(array $folders): array
{
    $byId = [];
    foreach ($folders as $f) {
        $byId[(int)$f['id']] = $f;
    }
    return $byId;
}

// Keputusan §11 poin 4: hapus folder berisi diblokir default, Executive bisa
// hapus paksa (cascade) — menghapus seluruh subfolder + dokumen + file fisik.
function ems_document_delete_folder_cascade(PDO $pdo, string $unitCode, int $folderId, array $actor): array
{
    $allFolders = ems_document_fetch_folders($pdo, $unitCode);
    $folderDivision = (string)(ems_document_folders_by_id($allFolders)[$folderId]['division'] ?? '');
    $descendantIds = ems_document_folder_descendant_ids($allFolders, $folderId);
    $allFolderIds = array_merge([$folderId], $descendantIds);
    $placeholders = implode(',', array_fill(0, count($allFolderIds), '?'));

    $stmt = $pdo->prepare("SELECT id, file_path FROM document_files WHERE unit_code = ? AND folder_id IN ($placeholders)");
    $stmt->execute(array_merge([$unitCode], $allFolderIds));
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        if ($docs) {
            $docIds = array_map(static fn($d) => (int)$d['id'], $docs);
            $docPlaceholders = implode(',', array_fill(0, count($docIds), '?'));
            $pdo->prepare("DELETE FROM document_files WHERE id IN ($docPlaceholders)")->execute($docIds);
        }
        $pdo->prepare("DELETE FROM document_folders WHERE id IN ($placeholders)")->execute($allFolderIds);

        ems_document_log_activity(
            $pdo,
            $unitCode,
            null,
            $folderId,
            $folderDivision !== '' ? $folderDivision : null,
            'folder_deleted',
            'Hapus paksa (cascade): ' . count($allFolderIds) . ' folder & ' . count($docs) . ' dokumen ikut terhapus',
            $actor
        );

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    foreach ($docs as $d) {
        $full = __DIR__ . '/../' . $d['file_path'];
        if (is_file($full)) {
            @unlink($full);
        }
    }

    return ['folders' => count($allFolderIds), 'documents' => count($docs)];
}

// ===================================================================
// Pencarian — hybrid FULLTEXT (BOOLEAN MODE, prefix match) + LIKE
// fallback untuk query pendek yang tidak lolos ft_min_word_len.
// Basis pengetahuan bersama (§11 poin 3): tidak ada filter division,
// hanya filter unit_code (§11 poin 7).
// ===================================================================

function ems_document_search_fulltext(PDO $pdo, string $unitCode, string $boolExpr, int $limit): array
{
    if ($boolExpr === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT df.*, MATCH(df.title, df.tags, df.extracted_text) AGAINST (:boolExpr IN BOOLEAN MODE) AS relevance
            FROM document_files df
            WHERE df.unit_code = :unitCode
              AND MATCH(df.title, df.tags, df.extracted_text) AGAINST (:boolExpr2 IN BOOLEAN MODE)
            ORDER BY relevance DESC, df.created_at DESC
            LIMIT :limitVal
        ");
        $stmt->bindValue(':boolExpr', $boolExpr, PDO::PARAM_STR);
        $stmt->bindValue(':boolExpr2', $boolExpr, PDO::PARAM_STR);
        $stmt->bindValue(':unitCode', $unitCode, PDO::PARAM_STR);
        $stmt->bindValue(':limitVal', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

function ems_document_search(PDO $pdo, string $unitCode, string $query, int $limit = 20): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
    $rows = [];

    // Query 2+ kata dicoba dulu sebagai FRASA UTUH (diapit tanda kutip di
    // BOOLEAN MODE) — supaya mencari kalimat lengkap yang memang persis ada
    // di dokumen (mis. judul sub-bab) langsung ketemu 1 dokumen yang tepat,
    // bukan "AND semua kata muncul di mana saja" yang gampang cocok ke
    // banyak dokumen tak terkait hanya karena sama-sama mengandung kata umum
    // seperti "dalam"/"dan". Baru jatuh ke pencarian per-kata (AND, prefix
    // match) di bawah kalau pencarian frasa ini nihil — supaya pencarian
    // kata kunci pendek/sebagian tetap jalan seperti sebelumnya.
    if (count($words) >= 2) {
        $phraseClean = trim(preg_replace('/[+\-<>()~*"@]+/', ' ', $query));
        if ($phraseClean !== '') {
            $phraseExpr = '"' . $phraseClean . '"';
            $rows = ems_document_search_fulltext($pdo, $unitCode, $phraseExpr, $limit);
        }
    }

    // Fallback: AND-kan tiap kata dengan prefix match (search-as-you-type).
    if (empty($rows)) {
        $boolTerms = [];
        foreach ($words as $w) {
            $clean = preg_replace('/[+\-<>()~*"@]+/', '', $w);
            if ($clean !== '') {
                $boolTerms[] = '+' . $clean . '*';
            }
        }
        $boolExpr = implode(' ', $boolTerms);
        $rows = ems_document_search_fulltext($pdo, $unitCode, $boolExpr, $limit);
    }

    $foundIds = array_map(static fn($r) => (int)$r['id'], $rows);

    $likeParam = '%' . $query . '%';
    $likeStmt = $pdo->prepare("
        SELECT df.*, 0 AS relevance
        FROM document_files df
        WHERE df.unit_code = ? AND (df.title LIKE ? OR df.tags LIKE ?)
        ORDER BY df.created_at DESC
        LIMIT ?
    ");
    $likeStmt->bindValue(1, $unitCode, PDO::PARAM_STR);
    $likeStmt->bindValue(2, $likeParam, PDO::PARAM_STR);
    $likeStmt->bindValue(3, $likeParam, PDO::PARAM_STR);
    $likeStmt->bindValue(4, $limit, PDO::PARAM_INT);
    $likeStmt->execute();

    foreach ($likeStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
        $id = (int)$lr['id'];
        if (!in_array($id, $foundIds, true)) {
            $rows[] = $lr;
            $foundIds[] = $id;
        }
    }

    return array_slice($rows, 0, $limit);
}

// Meng-escape $text lalu menandai kemunculan match terbaik dari $query
// dengan <mark> — coba FRASA UTUH dulu (persis seperti prioritas di
// ems_document_search(): kalimat lengkap yang memang ada di teks harus
// tersorot sebagai satu blok, bukan pecah per-kata umum yang kebetulan ada
// di tempat lain). Baru fallback ke tiap kata individual kalau frasa utuh
// itu tidak ditemukan sama sekali di potongan teks ini. $firstMarkId
// (opsional) ditempelkan HANYA ke kemunculan <mark> pertama, supaya
// caller bisa scrollIntoView() ke situ secara presisi (dipakai
// document_view.php untuk auto-scroll ke kalimat yang dicari).
function ems_document_highlight_text(string $text, string $query, ?string $firstMarkId = null): array
{
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $words = array_values(array_filter(preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY)));
    if (empty($words)) {
        return ['html' => $safe, 'matched' => false];
    }

    $markCounter = 0;
    $applyMark = function (string $inner) use (&$markCounter, $firstMarkId): string {
        $markCounter++;
        $attr = ($markCounter === 1 && $firstMarkId !== null)
            ? ' id="' . htmlspecialchars($firstMarkId, ENT_QUOTES, 'UTF-8') . '"'
            : '';
        return '<mark' . $attr . '>' . $inner . '</mark>';
    };

    if (count($words) >= 2) {
        $safeQuery = htmlspecialchars(trim($query), ENT_QUOTES, 'UTF-8');
        if ($safeQuery !== '') {
            $phraseResult = preg_replace_callback(
                '/(' . preg_quote($safeQuery, '/') . ')/iu',
                static fn($m) => $applyMark($m[1]),
                $safe
            );
            if ($phraseResult !== null && $markCounter > 0) {
                return ['html' => $phraseResult, 'matched' => true];
            }
        }
    }

    foreach ($words as $w) {
        $safeWord = htmlspecialchars($w, ENT_QUOTES, 'UTF-8');
        if ($safeWord === '') {
            continue;
        }
        $safe = preg_replace_callback(
            '/(' . preg_quote($safeWord, '/') . ')/iu',
            static fn($m) => $applyMark($m[1]),
            $safe
        ) ?? $safe;
    }

    return ['html' => $safe, 'matched' => $markCounter > 0];
}

function ems_document_build_snippet(string $text, string $query, int $radius = 160): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $words = array_values(array_filter(preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY)));

    // Cari titik jangkar potongan teks: coba frasa utuh dulu (biar
    // potongannya berpusat di kalimat yang benar-benar cocok), baru kata
    // pertama yang ketemu kalau frasa utuh tidak ada di dokumen ini.
    $pos = false;
    if (count($words) >= 2) {
        $pos = mb_stripos($text, trim($query));
    }
    if ($pos === false) {
        foreach ($words as $w) {
            $p = mb_stripos($text, $w);
            if ($p !== false) {
                $pos = $p;
                break;
            }
        }
    }

    if ($pos === false) {
        $snippet = mb_substr($text, 0, $radius * 2);
        $highlighted = ems_document_highlight_text($snippet, $query);
        return $highlighted['html'] . (mb_strlen($text) > $radius * 2 ? '…' : '');
    }

    $start = max(0, $pos - $radius);
    $length = $radius * 2;
    $snippet = mb_substr($text, $start, $length);
    $prefix = $start > 0 ? '…' : '';
    $suffix = ($start + $length) < mb_strlen($text) ? '…' : '';
    $highlighted = ems_document_highlight_text($snippet, $query);

    return $prefix . $highlighted['html'] . $suffix;
}

// ===================================================================
// Activity log
// ===================================================================

function ems_document_log_activity(PDO $pdo, string $unitCode, ?int $documentId, ?int $folderId, ?string $division, string $action, ?string $note, array $actor): void
{
    $stmt = $pdo->prepare("
        INSERT INTO document_activity_logs (unit_code, document_id, folder_id, division, action, note, actor_user_id, actor_name_snapshot, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $unitCode,
        $documentId,
        $folderId,
        $division !== '' ? $division : null,
        $action,
        $note,
        (int)($actor['id'] ?? 0) ?: null,
        trim((string)($actor['full_name'] ?? $actor['name'] ?? '')),
    ]);
}

// Riwayat aktivitas — dipakai di document_manage.php. $divisionFilter=null
// berarti tanpa filter (khusus Executive, lihat semua division).
function ems_document_activity_logs(PDO $pdo, string $unitCode, ?string $divisionFilter, int $limit = 50): array
{
    if ($divisionFilter !== null) {
        $stmt = $pdo->prepare("
            SELECT * FROM document_activity_logs
            WHERE unit_code = ? AND division = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $unitCode, PDO::PARAM_STR);
        $stmt->bindValue(2, $divisionFilter, PDO::PARAM_STR);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM document_activity_logs
            WHERE unit_code = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $unitCode, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ems_document_activity_action_label(string $action): string
{
    return match ($action) {
        'uploaded' => 'Upload dokumen',
        'edited' => 'Edit dokumen',
        'replaced' => 'Ganti file dokumen',
        'moved' => 'Pindah dokumen',
        'deleted' => 'Hapus dokumen',
        'folder_created' => 'Buat folder',
        'folder_renamed' => 'Ganti nama folder',
        'folder_moved' => 'Pindah folder',
        'folder_deleted' => 'Hapus folder',
        default => $action,
    };
}
