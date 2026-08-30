<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/document_library.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_document_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$unitCode = ems_effective_unit($pdo, $user);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$stmt = $pdo->prepare("SELECT * FROM document_files WHERE id = ? AND unit_code = ? LIMIT 1");
$stmt->execute([$id, $unitCode]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    exit('Dokumen tidak ditemukan.');
}

$allFolders = ems_document_fetch_folders($pdo, $unitCode);
$foldersById = ems_document_folders_by_id($allFolders);
$breadcrumb = ems_document_folder_breadcrumb($foldersById, (int)$doc['folder_id']);

$pageTitle = (string)$doc['title'];
$canManageThis = ems_document_can_edit_or_delete($user, $doc);
$fileUrl = '/ajax/secure_file.php?path=' . rawurlencode((string)$doc['file_path']);
$ext = strtolower((string)$doc['file_ext']);
$isPdf = $ext === 'pdf';
$isImage = in_array($ext, ['jpg', 'jpeg', 'png'], true);
$isSpreadsheet = in_array($ext, ['xlsx', 'xls'], true);
$hasExtractedText = (string)$doc['extraction_status'] === 'done' && trim((string)$doc['extracted_text']) !== '';

// Datang dari hasil pencarian (dokumen.php) dengan ?q=... — sorot & auto-
// scroll ke kalimat yang dicari, sama seperti mekanisme snippet-nya
// ems_document_search() (frasa utuh dulu, fallback per-kata).
$searchQuery = trim((string)($_GET['q'] ?? ''));
$textHighlight = ['html' => htmlspecialchars((string)$doc['extracted_text'], ENT_QUOTES, 'UTF-8'), 'matched' => false];
if ($hasExtractedText && $searchQuery !== '') {
    $textHighlight = ems_document_highlight_text((string)$doc['extracted_text'], $searchQuery, 'docSearchHit');
}

$spreadsheetHtml = null;
if ($isSpreadsheet) {
    $spreadsheetHtml = ems_document_render_spreadsheet_html(__DIR__ . '/../' . $doc['file_path']);
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<style>
.doc-view-meta { display:flex; flex-wrap:wrap; gap:8px 20px; color:#64748b; font-size:13px; margin-bottom:16px; }
.doc-view-text { white-space: pre-wrap; word-break: break-word; font: 15px/1.75 "Segoe UI", Tahoma, Arial, sans-serif; color:#1e293b; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:24px; max-height:70vh; overflow-y:auto; }
.doc-view-text mark { background:#fef08a; color:inherit; padding:0 2px; border-radius:2px; scroll-margin:80px; }
.doc-view-embed { width:100%; height:75vh; border:1px solid #e2e8f0; border-radius:12px; }
.doc-view-image { max-width:100%; border-radius:12px; border:1px solid #e2e8f0; }
.doc-xlsx-sheet-title { margin:20px 0 8px; font-size:16px; font-weight:600; color:#0f172a; }
.doc-xlsx-sheet-title:first-child { margin-top:0; }
.doc-xlsx-table-wrap { overflow-x:auto; border:1px solid #e2e8f0; border-radius:10px; max-height:70vh; overflow-y:auto; }
.doc-xlsx-table { border-collapse:collapse; width:100%; font-size:13px; }
.doc-xlsx-table td { border:1px solid #e2e8f0; padding:6px 10px; white-space:nowrap; color:#1e293b; }
.doc-xlsx-table tr:nth-child(even) { background:#f8fafc; }
</style>

<section class="content">
    <div class="page page-shell">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
                <h1 class="page-title"><?= htmlspecialchars((string)$doc['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle"><?= htmlspecialchars($breadcrumb, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="/dashboard/dokumen.php?folder=<?= (int)$doc['folder_id'] ?>" class="btn-secondary"><?= ems_icon('arrow-left', 'h-4 w-4') ?> Kembali</a>
                <a href="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn-secondary"><?= ems_icon('document-arrow-down', 'h-4 w-4') ?> Unduh File Asli</a>
                <?php if ($canManageThis): ?>
                    <a href="/dashboard/document_manage.php?edit=<?= (int)$doc['id'] ?>" class="btn-primary"><?= ems_icon('pencil-square', 'h-4 w-4') ?> Edit / Hapus</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="doc-view-meta">
            <span><?= ems_icon('user-group', 'h-4 w-4') ?> <?= htmlspecialchars((string)$doc['uploaded_by_name_snapshot'] ?: '-', ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= ems_icon('building-office', 'h-4 w-4') ?> <?= htmlspecialchars((string)$doc['division'], ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= ems_icon('calendar', 'h-4 w-4') ?> <?= htmlspecialchars(date('d M Y H:i', strtotime((string)$doc['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= ems_icon('document', 'h-4 w-4') ?> <?= strtoupper(htmlspecialchars($ext, ENT_QUOTES, 'UTF-8')) ?> &middot; <?= number_format(((int)$doc['file_size_bytes']) / 1024, 0) ?> KB</span>
        </div>

        <?php if (trim((string)$doc['tags']) !== ''): ?>
            <p class="meta-text" style="margin-bottom:16px;"><?= ems_icon('paper-clip', 'h-4 w-4') ?> Tag: <?= htmlspecialchars((string)$doc['tags'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <div class="card">
            <?php if ($isSpreadsheet && $spreadsheetHtml !== null): ?>
                <p class="meta-text" style="margin-bottom:12px;"><?= ems_icon('information-circle', 'h-4 w-4') ?> Ditampilkan sebagai tabel langsung dari isi file Excel (tanpa perlu download).</p>
                <?= $spreadsheetHtml ?>
            <?php elseif ($hasExtractedText): ?>
                <?php if ($isPdf): ?>
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; flex-wrap:wrap;">
                        <p class="meta-text" style="margin:0;"><?= ems_icon('information-circle', 'h-4 w-4') ?> Ditampilkan sebagai teks hasil ekstraksi otomatis dari PDF (langsung terbaca, tanpa perlu download).</p>
                        <button type="button" class="btn-secondary" style="padding:4px 10px;" onclick="document.getElementById('docViewOriginalEmbed').style.display='block'; this.style.display='none';">Tampilkan Tampilan Asli PDF</button>
                    </div>
                <?php endif; ?>
                <div class="doc-view-text"><?= $textHighlight['html'] ?></div>
                <?php if ($isPdf): ?>
                    <embed id="docViewOriginalEmbed" src="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" type="application/pdf" class="doc-view-embed" style="display:none; margin-top:16px;">
                <?php endif; ?>
            <?php elseif ($isPdf): ?>
                <embed src="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" type="application/pdf" class="doc-view-embed">
            <?php elseif ($isImage): ?>
                <img src="<?= htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)$doc['title'], ENT_QUOTES, 'UTF-8') ?>" class="doc-view-image">
            <?php else: ?>
                <p class="meta-text">Pratinjau tidak tersedia untuk tipe file ini. Silakan unduh file asli untuk membukanya.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($textHighlight['matched']): ?>
<script>
(function () {
    var hit = document.getElementById('docSearchHit');
    if (hit) {
        setTimeout(function () {
            hit.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 150);
    }
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
