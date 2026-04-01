<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_require_division_access(['Secretary'], '/dashboard/index.php');

$pageTitle = 'Data File Divisi';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function secretaryFileCategoryMeta(string $category): array
{
    return match ($category) {
        'proposal' => ['label' => 'PROPOSAL', 'class' => 'badge-counter'],
        'cooperation' => ['label' => 'KERJA SAMA', 'class' => 'badge-success'],
        'contract' => ['label' => 'KONTRAK', 'class' => 'badge-danger'],
        'report' => ['label' => 'LAPORAN', 'class' => 'badge-warning'],
        default => ['label' => 'LAINNYA', 'class' => 'badge-muted'],
    };
}

function secretaryFileStatusMeta(string $status): array
{
    return match ($status) {
        'review' => ['label' => 'REVIEW', 'class' => 'badge-counter'],
        'active' => ['label' => 'ACTIVE', 'class' => 'badge-success'],
        'archived' => ['label' => 'ARCHIVED', 'class' => 'badge-muted'],
        default => ['label' => 'DRAFT', 'class' => 'badge-warning'],
    };
}

function secretaryFileTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $cache[$table] = (bool) $stmt->fetchColumn();

    return $cache[$table];
}

function secretaryFileGroupAttachments(array $rows, string $foreignKey): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $key = (int) ($row[$foreignKey] ?? 0);
        if ($key <= 0) {
            continue;
        }

        $grouped[$key][] = $row;
    }

    return $grouped;
}

function secretaryFileAttachmentPayload(array $attachments): array
{
    return array_map(static function (array $attachment): array {
        return [
            'src' => '/' . ltrim((string) ($attachment['file_path'] ?? ''), '/'),
            'name' => (string) (($attachment['file_name'] ?? '') ?: 'Lampiran'),
        ];
    }, $attachments);
}

function secretaryFileJsonAttr(array $payload): string
{
    return htmlspecialchars((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
}

$summary = ['total' => 0, 'proposal' => 0, 'cooperation' => 0, 'archived' => 0];
$rows = [];
$attachmentsMap = [];
$hasMainTable = false;
$hasAttachmentTable = false;

try {
    $hasMainTable = secretaryFileTableExists($pdo, 'secretary_file_records');
    $hasAttachmentTable = secretaryFileTableExists($pdo, 'secretary_file_record_attachments');

    if ($hasMainTable) {
        $summary['total'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_file_records")->fetchColumn();
        $summary['proposal'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_file_records WHERE file_category = 'proposal'")->fetchColumn();
        $summary['cooperation'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_file_records WHERE file_category = 'cooperation'")->fetchColumn();
        $summary['archived'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_file_records WHERE status = 'archived'")->fetchColumn();

        $rows = $pdo->query("
            SELECT *
            FROM secretary_file_records
            ORDER BY document_date DESC, id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if ($hasAttachmentTable && !empty($rows)) {
            $recordIds = array_values(array_filter(array_map(static fn($row) => (int) ($row['id'] ?? 0), $rows)));
            if (!empty($recordIds)) {
                $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM secretary_file_record_attachments
                    WHERE record_id IN ($placeholders)
                    ORDER BY record_id ASC, sort_order ASC, id ASC
                ");
                $stmt->execute($recordIds);
                $attachmentsMap = secretaryFileGroupAttachments($stmt->fetchAll(PDO::FETCH_ASSOC), 'record_id');
            }
        }
    } else {
        $errors[] = 'Tabel data file Secretary belum tersedia. Jalankan SQL `docs/sql/16_2026-04-01_secretary_file_registry.sql` terlebih dahulu.';
    }
} catch (Throwable $e) {
    $errors[] = 'Tabel data file Secretary belum siap. Jalankan SQL `docs/sql/16_2026-04-01_secretary_file_registry.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<style>
    .secretary-action-row {
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        gap: 0.5rem;
        overflow-x: auto;
        padding-bottom: 0.25rem;
    }

    .secretary-action-row form,
    .secretary-action-row button,
    .secretary-action-row select {
        flex: 0 0 auto;
    }

    .secretary-action-row .btn-sm,
    .secretary-action-row select {
        white-space: nowrap;
    }

    .secretary-action-icon {
        width: 2.25rem;
        min-width: 2.25rem;
        height: 2.25rem;
        padding: 0;
        border-radius: 0.75rem;
    }
</style>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Pendataan proposal, kerja sama, kontrak, laporan, dan file lain agar mudah dicari serta rapi terdokumentasi.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php if ($hasMainTable && !$hasAttachmentTable): ?>
            <div class="alert alert-warning">Tabel lampiran file Secretary belum tersedia. Jalankan SQL <code>docs/sql/16_2026-04-01_secretary_file_registry.sql</code>.</div>
        <?php endif; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Total File', 'value' => $summary['total'], 'icon' => 'archive-box', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Proposal', 'value' => $summary['proposal'], 'icon' => 'document-text', 'tone' => 'warning']);
            ems_component('ui/statistic-card', ['label' => 'Kerja Sama', 'value' => $summary['cooperation'], 'icon' => 'user-group', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Arsip', 'value' => $summary['archived'], 'icon' => 'archive-box', 'tone' => 'muted']);
            ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Data File</div>
                <p class="meta-text mb-4">Pilih jenis file lalu simpan nomor, judul, pihak terkait, kata kunci, dan lampiran foto.</p>

                <form method="POST" action="secretary_action.php" enctype="multipart/form-data" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_file_record">
                    <input type="hidden" name="redirect_to" value="secretary_file_registry.php">

                    <label>Nomor File</label>
                    <div class="flex gap-2">
                        <input type="text" name="file_code" id="addFileCode" maxlength="100" placeholder="Otomatis muncul setelah field wajib lengkap">
                        <button type="button" class="btn-secondary whitespace-nowrap" id="addFileCodeAutoBtn">Auto</button>
                    </div>
                    <div class="meta-text-xs mt-1">Nomor file otomatis bisa diedit manual.</div>

                    <div class="row-form-2">
                        <div>
                            <label>Jenis File</label>
                            <select name="file_category" id="addFileCategory">
                                <option value="proposal">Proposal</option>
                                <option value="cooperation">Kerja Sama</option>
                                <option value="contract">Kontrak</option>
                                <option value="report">Laporan</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>
                        <div>
                            <label id="addFileReferenceLabel">Nomor Dokumen</label>
                            <input type="text" name="reference_number" id="addFileReference" required>
                        </div>
                    </div>

                    <label id="addFileTitleLabel">Judul File</label>
                    <input type="text" name="title" id="addFileTitle" required>

                    <label id="addFileCounterpartyLabel">Pihak Terkait</label>
                    <input type="text" name="counterparty_name" id="addFileCounterparty" required>

                    <div class="row-form-2">
                        <div>
                            <label>Tanggal Dokumen</label>
                            <input type="date" name="document_date" id="addFileDate" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label>Status</label>
                            <select name="status">
                                <option value="draft">Draft</option>
                                <option value="review">Review</option>
                                <option value="active">Active</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                    </div>

                    <label>Kata Kunci Pencarian</label>
                    <input type="text" name="keywords" id="addFileKeywords" placeholder="Contoh: proposal, klinik, kerja sama">
                    <div class="meta-text-xs mt-1">Pisahkan dengan koma agar pencarian tabel lebih mudah.</div>

                    <label id="addFileDescriptionLabel">Catatan File</label>
                    <textarea name="description" id="addFileDescription" rows="3"></textarea>

                    <div class="doc-upload-wrapper m-0">
                        <div class="doc-upload-header">
                            <label class="text-sm font-semibold text-slate-900">Lampiran File</label>
                            <span class="badge-muted-mini">Opsional, bisa beberapa foto</span>
                        </div>
                        <div class="doc-upload-input">
                            <label for="fileAttachments" class="file-upload-label">
                                <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                                <span class="file-text">
                                    <strong>Pilih lampiran</strong>
                                    <small>JPG / PNG, multi file</small>
                                </span>
                            </label>
                            <input type="file" id="fileAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                            <div class="file-selected-name" data-for="fileAttachments"></div>
                            <div id="fileAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
                        </div>
                    </div>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('archive-box', 'h-4 w-4') ?>
                            <span>Simpan Data File</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar File Divisi</div>
                <p class="meta-text mb-4">Cari berdasarkan jenis file, nomor dokumen, judul, pihak terkait, atau kata kunci.</p>

                <div class="table-wrapper">
                    <table id="secretaryFileTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Nomor File</th>
                                <th>Jenis</th>
                                <th>Referensi</th>
                                <th>Detail File</th>
                                <th>Tanggal</th>
                                <th>Status</th>
                                <th>Lampiran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $statusMeta = secretaryFileStatusMeta((string) $row['status']); ?>
                                <?php $categoryMeta = secretaryFileCategoryMeta((string) $row['file_category']); ?>
                                <?php $attachments = $attachmentsMap[(int) $row['id']] ?? []; ?>
                                <?php
                                $recordPayload = secretaryFileJsonAttr([
                                    'id' => (int) $row['id'],
                                    'file_code' => (string) $row['file_code'],
                                    'file_category' => (string) $row['file_category'],
                                    'file_category_label' => (string) $categoryMeta['label'],
                                    'reference_number' => (string) $row['reference_number'],
                                    'title' => (string) $row['title'],
                                    'counterparty_name' => (string) $row['counterparty_name'],
                                    'document_date' => (string) $row['document_date'],
                                    'status' => (string) $row['status'],
                                    'status_label' => (string) $statusMeta['label'],
                                    'keywords' => (string) ($row['keywords'] ?? ''),
                                    'description' => (string) ($row['description'] ?? ''),
                                    'attachments' => secretaryFileAttachmentPayload($attachments),
                                ]);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $row['file_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($categoryMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($categoryMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars((string) $row['reference_number'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $row['counterparty_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($row['keywords'])): ?>
                                            <div class="meta-text-xs">Keyword: <?= htmlspecialchars((string) $row['keywords'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) $row['document_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <?php if (!empty($attachments)): ?>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($attachments as $attachment): ?>
                                                    <a href="#"
                                                        class="doc-badge btn-preview-doc"
                                                        data-src="/<?= htmlspecialchars(ltrim((string) $attachment['file_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-title="<?= htmlspecialchars((string) ($attachment['file_name'] ?: ('Lampiran ' . $row['file_code'])), ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= ems_icon('paper-clip', 'h-4 w-4') ?>
                                                        <span><?= htmlspecialchars((string) ($attachment['file_name'] ?: 'Lampiran'), ENT_QUOTES, 'UTF-8') ?></span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="meta-text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="secretary-action-row">
                                            <button type="button" class="btn-secondary btn-sm secretary-action-icon btn-view-file-record" data-record="<?= $recordPayload ?>" title="Lihat detail file" aria-label="Lihat detail file">
                                                <?= ems_icon('eye', 'h-4 w-4') ?>
                                            </button>
                                            <button type="button" class="btn-primary btn-sm secretary-action-icon btn-edit-file-record" data-record="<?= $recordPayload ?>" title="Edit data file" aria-label="Edit data file">
                                                <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                            </button>
                                            <form method="POST" action="secretary_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus permanen data file ini?">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_file_record">
                                                <input type="hidden" name="redirect_to" value="secretary_file_registry.php">
                                                <input type="hidden" name="record_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn-danger btn-sm secretary-action-icon" title="Hapus permanen file" aria-label="Hapus permanen file">
                                                    <?= ems_icon('trash', 'h-4 w-4') ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<div id="fileRecordViewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('eye', 'h-5 w-5 text-primary') ?>
                <span>Detail Data File</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Nomor File</div><div id="fileRecordViewCode" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Status</div><div id="fileRecordViewStatus" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Jenis File</div><div id="fileRecordViewCategory" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Nomor Dokumen</div><div id="fileRecordViewReference" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Judul File</div><div id="fileRecordViewTitle" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Pihak Terkait</div><div id="fileRecordViewCounterparty" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Tanggal Dokumen</div><div id="fileRecordViewDate" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Kata Kunci</div><div id="fileRecordViewKeywords" class="mt-1 font-semibold text-slate-900">-</div></div>
            </div>

            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div class="meta-text-xs">Catatan / Deskripsi</div>
                <div id="fileRecordViewDescription" class="mt-1 whitespace-pre-line text-sm text-slate-900">-</div>
            </div>

            <div class="mt-4">
                <div class="meta-text-xs">Lampiran</div>
                <div id="fileRecordViewAttachments" class="mt-2 flex flex-wrap gap-2"></div>
            </div>

            <div class="modal-actions mt-4">
                <button type="button" class="btn-secondary btn-cancel">Tutup</button>
            </div>
        </div>
    </div>
</div>

<div id="fileRecordEditModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('pencil-square', 'h-5 w-5 text-primary') ?>
                <span>Edit Data File</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <form method="POST" action="secretary_action.php" enctype="multipart/form-data" class="form">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="edit_file_record">
                <input type="hidden" name="redirect_to" value="secretary_file_registry.php">
                <input type="hidden" name="record_id" id="editFileRecordId">

                <label>Nomor File</label>
                <div class="flex gap-2">
                    <input type="text" name="file_code" id="editFileCode" maxlength="100">
                    <button type="button" class="btn-secondary whitespace-nowrap" id="editFileCodeAutoBtn">Auto</button>
                </div>
                <div class="meta-text-xs mt-1">Nomor file bisa diubah manual.</div>

                <div class="row-form-2">
                    <div>
                        <label>Jenis File</label>
                        <select name="file_category" id="editFileCategory">
                            <option value="proposal">Proposal</option>
                            <option value="cooperation">Kerja Sama</option>
                            <option value="contract">Kontrak</option>
                            <option value="report">Laporan</option>
                            <option value="other">Lainnya</option>
                        </select>
                    </div>
                    <div>
                        <label id="editFileReferenceLabel">Nomor Dokumen</label>
                        <input type="text" name="reference_number" id="editFileReference" required>
                    </div>
                </div>

                <label id="editFileTitleLabel">Judul File</label>
                <input type="text" name="title" id="editFileTitle" required>

                <label id="editFileCounterpartyLabel">Pihak Terkait</label>
                <input type="text" name="counterparty_name" id="editFileCounterparty" required>

                <div class="row-form-2">
                    <div>
                        <label>Tanggal Dokumen</label>
                        <input type="date" name="document_date" id="editFileDate" required>
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="status" id="editFileStatus">
                            <option value="draft">Draft</option>
                            <option value="review">Review</option>
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>

                <label>Kata Kunci Pencarian</label>
                <input type="text" name="keywords" id="editFileKeywords">

                <label id="editFileDescriptionLabel">Catatan File</label>
                <textarea name="description" id="editFileDescription" rows="3"></textarea>

                <div class="mt-4">
                    <div class="meta-text-xs">Lampiran Saat Ini</div>
                    <div id="editFileCurrentAttachments" class="mt-2 flex flex-wrap gap-2"></div>
                </div>

                <div class="doc-upload-wrapper m-0">
                    <div class="doc-upload-header">
                        <label class="text-sm font-semibold text-slate-900">Tambah Lampiran</label>
                        <span class="badge-muted-mini">Opsional, menambah lampiran baru</span>
                    </div>
                    <div class="doc-upload-input">
                        <label for="editFileAttachments" class="file-upload-label">
                            <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                            <span class="file-text">
                                <strong>Pilih lampiran</strong>
                                <small>JPG / PNG, multi file</small>
                            </span>
                        </label>
                        <input type="file" id="editFileAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                        <div class="file-selected-name" data-for="editFileAttachments"></div>
                        <div id="editFileAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
                    </div>
                </div>

                <div class="modal-actions mt-4">
                    <button type="submit" class="btn-success"><?= ems_icon('check-circle', 'h-4 w-4') ?> <span>Simpan Perubahan</span></button>
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const generateCodeUrl = '<?= htmlspecialchars(ems_url('/ajax/generate_surat_code.php'), ENT_QUOTES, 'UTF-8') ?>';
    const fileTypeConfig = {
        proposal: {
            referenceLabel: 'Nomor Proposal',
            referencePlaceholder: 'Contoh: PROP/001/2026',
            titleLabel: 'Judul Proposal',
            titlePlaceholder: 'Contoh: Proposal Layanan Kesehatan',
            counterpartyLabel: 'Instansi Tujuan',
            counterpartyPlaceholder: 'Contoh: Dinas Kesehatan',
            descriptionLabel: 'Ringkasan Proposal',
            descriptionPlaceholder: 'Tuliskan ringkasan proposal yang disimpan.',
            keywordsPlaceholder: 'Contoh: proposal, kesehatan, layanan'
        },
        cooperation: {
            referenceLabel: 'Nomor Dokumen Kerja Sama',
            referencePlaceholder: 'Contoh: MOU/RS/III/2026',
            titleLabel: 'Nama Kerja Sama',
            titlePlaceholder: 'Contoh: Kerja Sama Medical Standby',
            counterpartyLabel: 'Mitra Kerja Sama',
            counterpartyPlaceholder: 'Contoh: PT Contoh Indonesia',
            descriptionLabel: 'Ruang Lingkup Kerja Sama',
            descriptionPlaceholder: 'Tuliskan ruang lingkup atau catatan kerja sama.',
            keywordsPlaceholder: 'Contoh: kerja sama, MOU, mitra'
        },
        contract: {
            referenceLabel: 'Nomor Kontrak',
            referencePlaceholder: 'Contoh: KTR/EMS/009/2026',
            titleLabel: 'Nama Kontrak',
            titlePlaceholder: 'Contoh: Kontrak Layanan Ambulans',
            counterpartyLabel: 'Pihak Kontrak',
            counterpartyPlaceholder: 'Contoh: Vendor / Klien terkait',
            descriptionLabel: 'Catatan Kontrak',
            descriptionPlaceholder: 'Tuliskan catatan penting kontrak.',
            keywordsPlaceholder: 'Contoh: kontrak, vendor, ambulans'
        },
        report: {
            referenceLabel: 'Nomor Laporan',
            referencePlaceholder: 'Contoh: LPR/OPS/04/2026',
            titleLabel: 'Judul Laporan',
            titlePlaceholder: 'Contoh: Laporan Evaluasi Bulanan',
            counterpartyLabel: 'Unit / Sumber Laporan',
            counterpartyPlaceholder: 'Contoh: Operasional / Klinik',
            descriptionLabel: 'Ringkasan Laporan',
            descriptionPlaceholder: 'Tuliskan ringkasan isi laporan.',
            keywordsPlaceholder: 'Contoh: laporan, evaluasi, bulanan'
        },
        other: {
            referenceLabel: 'Nomor Dokumen',
            referencePlaceholder: 'Contoh: DOC/001/2026',
            titleLabel: 'Judul File',
            titlePlaceholder: 'Contoh: Arsip Dokumen Pendukung',
            counterpartyLabel: 'Pihak Terkait',
            counterpartyPlaceholder: 'Contoh: Internal / Eksternal',
            descriptionLabel: 'Catatan File',
            descriptionPlaceholder: 'Tuliskan catatan file yang disimpan.',
            keywordsPlaceholder: 'Contoh: arsip, dokumen, pendukung'
        }
    };

    function getTypeConfig(type) {
        return fileTypeConfig[type] || fileTypeConfig.other;
    }

    function debounce(fn, delay) {
        let timer = null;
        return function () {
            const args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(null, args);
            }, delay);
        };
    }

    function setupAutoCode(options) {
        const codeInput = document.getElementById(options.codeInputId);
        const autoButton = document.getElementById(options.autoButtonId);
        const requiredInputs = options.requiredInputIds.map(function (id) {
            return document.getElementById(id);
        }).filter(Boolean);
        const watchedInputs = options.watchedInputIds.map(function (id) {
            return document.getElementById(id);
        }).filter(Boolean);

        if (!codeInput || !requiredInputs.length) {
            return {
                refresh: function () {}
            };
        }

        codeInput.dataset.autoMode = 'true';
        codeInput.dataset.generatedCode = codeInput.value || '';

        async function refreshCode(forceAuto) {
            const requiredReady = requiredInputs.every(function (input) {
                return String(input.value || '').trim() !== '';
            });

            if (!requiredReady) {
                if (forceAuto) {
                    codeInput.value = '';
                    codeInput.dataset.generatedCode = '';
                }
                return;
            }

            const url = new URL(generateCodeUrl, window.location.origin);
            url.searchParams.set('type', options.type);

            const dateInput = document.getElementById(options.dateInputId);
            if (dateInput) {
                url.searchParams.set('date', (dateInput.value || '').trim());
            }

            const counterpartyInput = document.getElementById(options.counterpartyInputId);
            if (counterpartyInput) {
                url.searchParams.set('counterparty_name', (counterpartyInput.value || '').trim());
            }

            if (typeof options.extraParams === 'function') {
                const extraParams = options.extraParams() || {};
                Object.keys(extraParams).forEach(function (key) {
                    url.searchParams.set(key, String(extraParams[key] || '').trim());
                });
            }

            const response = await fetch(url.toString(), { credentials: 'same-origin' });
            const payload = await response.json();
            if (!payload.success) {
                return;
            }

            const currentValue = codeInput.value.trim();
            const previousGenerated = codeInput.dataset.generatedCode || '';
            const shouldApply = forceAuto || codeInput.dataset.autoMode === 'true' || currentValue === '' || currentValue === previousGenerated;

            codeInput.dataset.generatedCode = payload.code || '';
            if (shouldApply) {
                codeInput.value = payload.code || '';
                codeInput.dataset.autoMode = 'true';
            }
        }

        const debouncedRefresh = debounce(function () {
            refreshCode(false).catch(function () {});
        }, 250);

        watchedInputs.forEach(function (input) {
            input.addEventListener('input', debouncedRefresh);
            input.addEventListener('change', debouncedRefresh);
        });

        codeInput.addEventListener('input', function () {
            const currentValue = codeInput.value.trim();
            codeInput.dataset.autoMode = (currentValue === '' || currentValue === (codeInput.dataset.generatedCode || '')) ? 'true' : 'false';
        });

        if (autoButton) {
            autoButton.addEventListener('click', function () {
                codeInput.dataset.autoMode = 'true';
                refreshCode(true).catch(function () {});
            });
        }

        refreshCode(false).catch(function () {});

        return {
            refresh: function (forceAuto) {
                refreshCode(Boolean(forceAuto)).catch(function () {});
            }
        };
    }

    function setupFileFormState(options) {
        const categoryInput = document.getElementById(options.categoryId);
        const referenceLabel = document.getElementById(options.referenceLabelId);
        const referenceInput = document.getElementById(options.referenceInputId);
        const titleLabel = document.getElementById(options.titleLabelId);
        const titleInput = document.getElementById(options.titleInputId);
        const counterpartyLabel = document.getElementById(options.counterpartyLabelId);
        const counterpartyInput = document.getElementById(options.counterpartyInputId);
        const descriptionLabel = document.getElementById(options.descriptionLabelId);
        const descriptionInput = document.getElementById(options.descriptionInputId);
        const keywordsInput = document.getElementById(options.keywordsInputId);

        if (!categoryInput) {
            return {
                apply: function () {}
            };
        }

        function apply() {
            const config = getTypeConfig(categoryInput.value);

            if (referenceLabel) {
                referenceLabel.textContent = config.referenceLabel;
            }
            if (referenceInput) {
                referenceInput.placeholder = config.referencePlaceholder;
            }
            if (titleLabel) {
                titleLabel.textContent = config.titleLabel;
            }
            if (titleInput) {
                titleInput.placeholder = config.titlePlaceholder;
            }
            if (counterpartyLabel) {
                counterpartyLabel.textContent = config.counterpartyLabel;
            }
            if (counterpartyInput) {
                counterpartyInput.placeholder = config.counterpartyPlaceholder;
            }
            if (descriptionLabel) {
                descriptionLabel.textContent = config.descriptionLabel;
            }
            if (descriptionInput) {
                descriptionInput.placeholder = config.descriptionPlaceholder;
            }
            if (keywordsInput) {
                keywordsInput.placeholder = config.keywordsPlaceholder;
            }
        }

        categoryInput.addEventListener('change', apply);
        apply();

        return {
            apply: apply
        };
    }

    function openModal(modal) {
        if (!modal) {
            return;
        }

        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
    }

    function closeModal(modal) {
        if (!modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    function attachModalClose(modal) {
        if (!modal) {
            return;
        }

        modal.querySelectorAll('.btn-cancel, .modal-close-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                closeModal(modal);
            });
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    }

    function setupMultiImagePreview(inputId, previewId) {
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewId);
        const nameBox = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
        if (!input || !preview || !nameBox) {
            return;
        }

        let objectUrls = [];

        function clearPreview() {
            objectUrls.forEach(function (url) {
                try { URL.revokeObjectURL(url); } catch (_) {}
            });
            objectUrls = [];
            preview.innerHTML = '';
            nameBox.textContent = '';
            nameBox.classList.add('hidden');
        }

        input.addEventListener('change', function () {
            clearPreview();

            const files = Array.from(this.files || []);
            if (!files.length) {
                return;
            }

            nameBox.textContent = files.length + ' file dipilih';
            nameBox.classList.remove('hidden');

            files.forEach(function (file) {
                if (!String(file.type || '').startsWith('image/')) {
                    return;
                }

                const url = URL.createObjectURL(file);
                objectUrls.push(url);

                const item = document.createElement('div');
                item.className = 'rounded-2xl border border-slate-200 bg-slate-50 p-2';
                item.innerHTML = `
                    <img src="${url}" class="identity-photo h-28 w-full rounded-xl object-cover cursor-zoom-in" alt="Preview lampiran">
                    <div class="mt-2 truncate text-xs text-slate-600">${file.name}</div>
                `;
                preview.appendChild(item);
            });
        });
    }

    function resetMultiImagePreview(inputId, previewId) {
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewId);
        const nameBox = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
        if (input) {
            input.value = '';
        }
        if (preview) {
            preview.innerHTML = '';
        }
        if (nameBox) {
            nameBox.textContent = '';
            nameBox.classList.add('hidden');
        }
    }

    function parseRecord(button) {
        try {
            return JSON.parse(button.dataset.record || '{}');
        } catch (_) {
            return {};
        }
    }

    function renderAttachmentBadges(container, attachments, emptyText) {
        if (!container) {
            return;
        }

        container.innerHTML = '';
        if (!Array.isArray(attachments) || !attachments.length) {
            container.innerHTML = '<span class="meta-text-xs">' + (emptyText || '-') + '</span>';
            return;
        }

        attachments.forEach(function (attachment) {
            const link = document.createElement('a');
            link.href = '#';
            link.className = 'doc-badge btn-preview-doc';
            link.dataset.src = attachment.src || '';
            link.dataset.title = attachment.name || 'Lampiran';
            link.innerHTML = `<?= ems_icon('paper-clip', 'h-4 w-4') ?><span></span>`;
            link.querySelector('span').textContent = attachment.name || 'Lampiran';
            container.appendChild(link);
        });
    }

    if (window.jQuery && $.fn.DataTable) {
        $('#secretaryFileTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[4, 'desc']]
        });
    }

    const fileRecordViewModal = document.getElementById('fileRecordViewModal');
    const fileRecordEditModal = document.getElementById('fileRecordEditModal');
    const addFileFormState = setupFileFormState({
        categoryId: 'addFileCategory',
        referenceLabelId: 'addFileReferenceLabel',
        referenceInputId: 'addFileReference',
        titleLabelId: 'addFileTitleLabel',
        titleInputId: 'addFileTitle',
        counterpartyLabelId: 'addFileCounterpartyLabel',
        counterpartyInputId: 'addFileCounterparty',
        descriptionLabelId: 'addFileDescriptionLabel',
        descriptionInputId: 'addFileDescription',
        keywordsInputId: 'addFileKeywords'
    });
    const editFileFormState = setupFileFormState({
        categoryId: 'editFileCategory',
        referenceLabelId: 'editFileReferenceLabel',
        referenceInputId: 'editFileReference',
        titleLabelId: 'editFileTitleLabel',
        titleInputId: 'editFileTitle',
        counterpartyLabelId: 'editFileCounterpartyLabel',
        counterpartyInputId: 'editFileCounterparty',
        descriptionLabelId: 'editFileDescriptionLabel',
        descriptionInputId: 'editFileDescription',
        keywordsInputId: 'editFileKeywords'
    });
    const editFileCodeControl = setupAutoCode({
        type: 'secretary_file',
        codeInputId: 'editFileCode',
        autoButtonId: 'editFileCodeAutoBtn',
        dateInputId: 'editFileDate',
        counterpartyInputId: 'editFileCounterparty',
        requiredInputIds: ['editFileDate', 'editFileCounterparty', 'editFileCategory'],
        watchedInputIds: ['editFileDate', 'editFileCounterparty', 'editFileCategory'],
        extraParams: function () {
            const categoryInput = document.getElementById('editFileCategory');
            return {
                file_category: categoryInput ? categoryInput.value : ''
            };
        }
    });

    attachModalClose(fileRecordViewModal);
    attachModalClose(fileRecordEditModal);
    addFileFormState.apply();
    editFileFormState.apply();

    setupAutoCode({
        type: 'secretary_file',
        codeInputId: 'addFileCode',
        autoButtonId: 'addFileCodeAutoBtn',
        dateInputId: 'addFileDate',
        counterpartyInputId: 'addFileCounterparty',
        requiredInputIds: ['addFileDate', 'addFileCounterparty', 'addFileCategory'],
        watchedInputIds: ['addFileDate', 'addFileCounterparty', 'addFileCategory'],
        extraParams: function () {
            const categoryInput = document.getElementById('addFileCategory');
            return {
                file_category: categoryInput ? categoryInput.value : ''
            };
        }
    });

    setupMultiImagePreview('fileAttachments', 'fileAttachmentsPreview');
    setupMultiImagePreview('editFileAttachments', 'editFileAttachmentsPreview');

    document.addEventListener('click', function (event) {
        const viewButton = event.target.closest('.btn-view-file-record');
        if (viewButton) {
            const record = parseRecord(viewButton);
            document.getElementById('fileRecordViewCode').textContent = record.file_code || '-';
            document.getElementById('fileRecordViewStatus').textContent = record.status_label || record.status || '-';
            document.getElementById('fileRecordViewCategory').textContent = record.file_category_label || record.file_category || '-';
            document.getElementById('fileRecordViewReference').textContent = record.reference_number || '-';
            document.getElementById('fileRecordViewTitle').textContent = record.title || '-';
            document.getElementById('fileRecordViewCounterparty').textContent = record.counterparty_name || '-';
            document.getElementById('fileRecordViewDate').textContent = record.document_date || '-';
            document.getElementById('fileRecordViewKeywords').textContent = record.keywords || '-';
            document.getElementById('fileRecordViewDescription').textContent = record.description || '-';
            renderAttachmentBadges(document.getElementById('fileRecordViewAttachments'), record.attachments || [], 'Tidak ada lampiran');
            openModal(fileRecordViewModal);
            return;
        }

        const editButton = event.target.closest('.btn-edit-file-record');
        if (editButton) {
            const record = parseRecord(editButton);
            document.getElementById('editFileRecordId').value = record.id || '';
            document.getElementById('editFileCode').value = record.file_code || '';
            document.getElementById('editFileCode').dataset.generatedCode = '';
            document.getElementById('editFileCode').dataset.autoMode = 'false';
            document.getElementById('editFileCategory').value = record.file_category || 'other';
            editFileFormState.apply();
            document.getElementById('editFileReference').value = record.reference_number || '';
            document.getElementById('editFileTitle').value = record.title || '';
            document.getElementById('editFileCounterparty').value = record.counterparty_name || '';
            document.getElementById('editFileDate').value = record.document_date || '';
            document.getElementById('editFileStatus').value = record.status || 'draft';
            document.getElementById('editFileKeywords').value = record.keywords || '';
            document.getElementById('editFileDescription').value = record.description || '';
            renderAttachmentBadges(document.getElementById('editFileCurrentAttachments'), record.attachments || [], 'Tidak ada lampiran');
            resetMultiImagePreview('editFileAttachments', 'editFileAttachmentsPreview');
            editFileCodeControl.refresh(false);
            openModal(fileRecordEditModal);
        }
    });

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (form && form.matches('.js-delete-form')) {
            const message = form.dataset.confirm || 'Yakin ingin menghapus data ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal(fileRecordViewModal);
            closeModal(fileRecordEditModal);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
