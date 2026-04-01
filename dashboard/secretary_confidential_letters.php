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

$pageTitle = 'Rekap Surat Rahasia';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function secretaryConfidentialStatusMeta(string $status): array
{
    return match ($status) {
        'sealed' => ['label' => 'SEALED', 'class' => 'badge-danger'],
        'distributed' => ['label' => 'DISTRIBUTED', 'class' => 'badge-success'],
        'archived' => ['label' => 'ARCHIVED', 'class' => 'badge-muted'],
        default => ['label' => strtoupper($status), 'class' => 'badge-warning'],
    };
}

function secretaryConfidentialLevelMeta(string $level): array
{
    return match ($level) {
        'top_secret' => ['label' => 'TOP SECRET', 'class' => 'badge-danger'],
        'secret' => ['label' => 'SECRET', 'class' => 'badge-warning'],
        default => ['label' => 'CONFIDENTIAL', 'class' => 'badge-muted'],
    };
}

function secretaryConfidentialTableExists(PDO $pdo, string $table): bool
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

function secretaryConfidentialGroupAttachments(array $rows, string $foreignKey): array
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

function secretaryConfidentialAttachmentPayload(array $attachments): array
{
    return array_map(static function (array $attachment): array {
        return [
            'src' => '/' . ltrim((string) ($attachment['file_path'] ?? ''), '/'),
            'name' => (string) (($attachment['file_name'] ?? '') ?: 'Lampiran'),
        ];
    }, $attachments);
}

function secretaryConfidentialJsonAttr(array $payload): string
{
    return htmlspecialchars((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
}

$summary = ['logged' => 0, 'sealed' => 0, 'distributed' => 0, 'archived' => 0];
$rows = [];
$attachmentsMap = [];
$hasAttachmentTable = false;

try {
    $hasAttachmentTable = secretaryConfidentialTableExists($pdo, 'secretary_confidential_letter_attachments');
    $summary['logged'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'logged'")->fetchColumn();
    $summary['sealed'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'sealed'")->fetchColumn();
    $summary['distributed'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'distributed'")->fetchColumn();
    $summary['archived'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'archived'")->fetchColumn();

    $rows = $pdo->query("
        SELECT *
        FROM secretary_confidential_letters
        ORDER BY letter_date DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($hasAttachmentTable && !empty($rows)) {
        $letterIds = array_values(array_filter(array_map(static fn($row) => (int) ($row['id'] ?? 0), $rows)));
        if (!empty($letterIds)) {
            $placeholders = implode(',', array_fill(0, count($letterIds), '?'));
            $stmt = $pdo->prepare("
                SELECT *
                FROM secretary_confidential_letter_attachments
                WHERE letter_id IN ($placeholders)
                ORDER BY letter_id ASC, sort_order ASC, id ASC
            ");
            $stmt->execute($letterIds);
            $attachmentsMap = secretaryConfidentialGroupAttachments($stmt->fetchAll(PDO::FETCH_ASSOC), 'letter_id');
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Tabel Secretary belum siap. Jalankan SQL `docs/sql/07_2026-03-11_secretary_module.sql` terlebih dahulu.';
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
        <p class="page-subtitle">Register surat rahasia, arah surat, level kerahasiaan, dan status distribusinya.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php if (!$hasAttachmentTable): ?>
            <div class="alert alert-warning">Fitur lampiran surat rahasia memerlukan SQL <code>docs/sql/15_2026-03-31_secretary_attachments.sql</code>.</div>
        <?php endif; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Baru Tercatat', 'value' => $summary['logged'], 'icon' => 'document-text', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Sealed', 'value' => $summary['sealed'], 'icon' => 'lock-closed', 'tone' => 'danger']);
            ems_component('ui/statistic-card', ['label' => 'Distributed', 'value' => $summary['distributed'], 'icon' => 'paper-airplane', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Archived', 'value' => $summary['archived'], 'icon' => 'inbox', 'tone' => 'muted']);
            ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Surat Rahasia</div>
                <p class="meta-text mb-4">Register surat masuk/keluar yang butuh kendali distribusi dan kerahasiaan.</p>

                <form method="POST" action="secretary_action.php" enctype="multipart/form-data" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_confidential_letter">
                    <input type="hidden" name="redirect_to" value="secretary_confidential_letters.php">

                    <label>Nomor Surat</label>
                    <div class="flex gap-2">
                        <input type="text" name="register_code" id="addConfidentialCode" maxlength="100" placeholder="Otomatis muncul setelah field wajib lengkap">
                        <button type="button" class="btn-secondary whitespace-nowrap" id="addConfidentialCodeAutoBtn">Auto</button>
                    </div>
                    <div class="meta-text-xs mt-1">Nomor surat otomatis bisa diedit manual.</div>

                    <div class="row-form-2">
                        <div>
                            <label>Nomor Referensi</label>
                            <input type="text" name="reference_number" required>
                        </div>
                        <div>
                            <label>Arah Surat</label>
                            <select name="letter_direction" id="addConfidentialDirection">
                                <option value="incoming">Incoming</option>
                                <option value="outgoing">Outgoing</option>
                            </select>
                        </div>
                    </div>

                    <label>Subjek Surat</label>
                    <input type="text" name="subject" required>

                    <label>Pengirim / Penerima Utama</label>
                    <input type="text" name="counterparty_name" id="addConfidentialCounterpartyName" required>

                    <div class="row-form-2">
                        <div>
                            <label>Level Kerahasiaan</label>
                            <select name="confidentiality_level">
                                <option value="confidential">Confidential</option>
                                <option value="secret">Secret</option>
                                <option value="top_secret">Top Secret</option>
                            </select>
                        </div>
                        <div>
                            <label>Tanggal Surat</label>
                            <input type="date" name="letter_date" id="addConfidentialDate" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <label>Status</label>
                    <select name="status">
                        <option value="logged">Logged</option>
                        <option value="sealed">Sealed</option>
                        <option value="distributed">Distributed</option>
                        <option value="archived">Archived</option>
                    </select>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3"></textarea>

                    <div class="doc-upload-wrapper m-0">
                        <div class="doc-upload-header">
                            <label class="text-sm font-semibold text-slate-900">Lampiran Surat Rahasia</label>
                            <span class="badge-muted-mini">Opsional, bisa beberapa file</span>
                        </div>
                        <div class="doc-upload-input">
                            <label for="confidentialAttachments" class="file-upload-label">
                                <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                                <span class="file-text">
                                    <strong>Pilih lampiran</strong>
                                    <small>JPG / PNG, multi file</small>
                                </span>
                            </label>
                            <input type="file" id="confidentialAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                            <div class="file-selected-name" data-for="confidentialAttachments"></div>
                            <div id="confidentialAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
                        </div>
                    </div>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('inbox', 'h-4 w-4') ?>
                            <span>Simpan Register</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Surat Rahasia</div>
                <p class="meta-text mb-4">Pantau surat rahasia berdasarkan nomor referensi, level, dan status distribusi.</p>

                <div class="table-wrapper">
                    <table id="secretaryConfidentialTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Nomor Surat</th>
                                <th>Referensi</th>
                                <th>Subjek</th>
                                <th>Level</th>
                                <th>Status</th>
                                <th>Lampiran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $statusMeta = secretaryConfidentialStatusMeta((string) $row['status']); ?>
                                <?php $levelMeta = secretaryConfidentialLevelMeta((string) $row['confidentiality_level']); ?>
                                <?php $attachments = $attachmentsMap[(int) $row['id']] ?? []; ?>
                                <?php
                                $recordPayload = secretaryConfidentialJsonAttr([
                                    'id' => (int) $row['id'],
                                    'register_code' => (string) $row['register_code'],
                                    'reference_number' => (string) $row['reference_number'],
                                    'letter_direction' => (string) $row['letter_direction'],
                                    'subject' => (string) $row['subject'],
                                    'counterparty_name' => (string) $row['counterparty_name'],
                                    'confidentiality_level' => (string) $row['confidentiality_level'],
                                    'letter_date' => (string) $row['letter_date'],
                                    'status' => (string) $row['status'],
                                    'notes' => (string) ($row['notes'] ?? ''),
                                    'attachments' => secretaryConfidentialAttachmentPayload($attachments),
                                ]);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $row['register_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['reference_number'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(strtoupper((string) $row['letter_direction']), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $row['counterparty_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><span class="<?= htmlspecialchars($levelMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($levelMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <?php if (!empty($attachments)): ?>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($attachments as $attachment): ?>
                                                    <a href="#"
                                                        class="doc-badge btn-preview-doc"
                                                        data-src="/<?= htmlspecialchars(ltrim((string) $attachment['file_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-title="<?= htmlspecialchars((string) ($attachment['file_name'] ?: ('Lampiran ' . $row['register_code'])), ENT_QUOTES, 'UTF-8') ?>">
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
                                            <button type="button" class="btn-secondary btn-sm secretary-action-icon btn-view-confidential" data-record="<?= $recordPayload ?>" title="Lihat detail surat rahasia" aria-label="Lihat detail surat rahasia">
                                                <?= ems_icon('eye', 'h-4 w-4') ?>
                                            </button>
                                            <button type="button" class="btn-primary btn-sm secretary-action-icon btn-edit-confidential" data-record="<?= $recordPayload ?>" title="Edit surat rahasia" aria-label="Edit surat rahasia">
                                                <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                            </button>
                                            <form method="POST" action="secretary_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus permanen dokumen surat rahasia ini?">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_confidential_letter">
                                                <input type="hidden" name="redirect_to" value="secretary_confidential_letters.php">
                                                <input type="hidden" name="letter_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn-danger btn-sm secretary-action-icon" title="Hapus permanen surat rahasia" aria-label="Hapus permanen surat rahasia">
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

<div id="confidentialViewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('eye', 'h-5 w-5 text-primary') ?>
                <span>Detail Surat Rahasia</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Nomor Surat</div><div id="confidentialViewCode" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Status</div><div id="confidentialViewStatus" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Nomor Referensi</div><div id="confidentialViewReference" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Arah Surat</div><div id="confidentialViewDirection" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Subjek</div><div id="confidentialViewSubject" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Pengirim / Penerima</div><div id="confidentialViewCounterparty" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Level Kerahasiaan</div><div id="confidentialViewLevel" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Tanggal Surat</div><div id="confidentialViewDate" class="mt-1 font-semibold text-slate-900">-</div></div>
            </div>

            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div class="meta-text-xs">Catatan</div>
                <div id="confidentialViewNotes" class="mt-1 whitespace-pre-line text-sm text-slate-900">-</div>
            </div>

            <div class="mt-4">
                <div class="meta-text-xs">Lampiran</div>
                <div id="confidentialViewAttachments" class="mt-2 flex flex-wrap gap-2"></div>
            </div>

            <div class="modal-actions mt-4">
                <button type="button" class="btn-secondary btn-cancel">Tutup</button>
            </div>
        </div>
    </div>
</div>

<div id="confidentialEditModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('pencil-square', 'h-5 w-5 text-primary') ?>
                <span>Edit Surat Rahasia</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <form method="POST" action="secretary_action.php" enctype="multipart/form-data" class="form">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="edit_confidential_letter">
                <input type="hidden" name="redirect_to" value="secretary_confidential_letters.php">
                <input type="hidden" name="letter_id" id="editConfidentialId">

                <label>Nomor Surat</label>
                <div class="flex gap-2">
                    <input type="text" name="register_code" id="editConfidentialCode" maxlength="100">
                    <button type="button" class="btn-secondary whitespace-nowrap" id="editConfidentialCodeAutoBtn">Auto</button>
                </div>
                <div class="meta-text-xs mt-1">Nomor surat bisa diubah manual.</div>

                <div class="row-form-2">
                    <div>
                        <label>Nomor Referensi</label>
                        <input type="text" name="reference_number" id="editConfidentialReference" required>
                    </div>
                    <div>
                        <label>Arah Surat</label>
                        <select name="letter_direction" id="editConfidentialDirection">
                            <option value="incoming">Incoming</option>
                            <option value="outgoing">Outgoing</option>
                        </select>
                    </div>
                </div>

                <label>Subjek Surat</label>
                <input type="text" name="subject" id="editConfidentialSubject" required>

                <label>Pengirim / Penerima Utama</label>
                <input type="text" name="counterparty_name" id="editConfidentialCounterpartyName" required>

                <div class="row-form-2">
                    <div>
                        <label>Level Kerahasiaan</label>
                        <select name="confidentiality_level" id="editConfidentialLevel">
                            <option value="confidential">Confidential</option>
                            <option value="secret">Secret</option>
                            <option value="top_secret">Top Secret</option>
                        </select>
                    </div>
                    <div>
                        <label>Tanggal Surat</label>
                        <input type="date" name="letter_date" id="editConfidentialDate" required>
                    </div>
                </div>

                <label>Status</label>
                <select name="status" id="editConfidentialStatus">
                    <option value="logged">Logged</option>
                    <option value="sealed">Sealed</option>
                    <option value="distributed">Distributed</option>
                    <option value="archived">Archived</option>
                </select>

                <label>Catatan</label>
                <textarea name="notes" id="editConfidentialNotes" rows="3"></textarea>

                <div class="mt-4">
                    <div class="meta-text-xs">Lampiran Saat Ini</div>
                    <div id="editConfidentialCurrentAttachments" class="mt-2 flex flex-wrap gap-2"></div>
                </div>

                <div class="doc-upload-wrapper m-0">
                    <div class="doc-upload-header">
                        <label class="text-sm font-semibold text-slate-900">Tambah Lampiran</label>
                        <span class="badge-muted-mini">Opsional, menambah lampiran baru</span>
                    </div>
                    <div class="doc-upload-input">
                        <label for="editConfidentialAttachments" class="file-upload-label">
                            <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                            <span class="file-text">
                                <strong>Pilih lampiran</strong>
                                <small>JPG / PNG, multi file</small>
                            </span>
                        </label>
                        <input type="file" id="editConfidentialAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                        <div class="file-selected-name" data-for="editConfidentialAttachments"></div>
                        <div id="editConfidentialAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
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

            const directionInput = document.getElementById(options.directionInputId);
            if (directionInput) {
                url.searchParams.set('letter_direction', (directionInput.value || '').trim());
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
        $('#secretaryConfidentialTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }

    const confidentialViewModal = document.getElementById('confidentialViewModal');
    const confidentialEditModal = document.getElementById('confidentialEditModal');
    const editConfidentialCodeControl = setupAutoCode({
        type: 'confidential',
        codeInputId: 'editConfidentialCode',
        autoButtonId: 'editConfidentialCodeAutoBtn',
        dateInputId: 'editConfidentialDate',
        counterpartyInputId: 'editConfidentialCounterpartyName',
        directionInputId: 'editConfidentialDirection',
        requiredInputIds: ['editConfidentialDate', 'editConfidentialCounterpartyName'],
        watchedInputIds: ['editConfidentialDate', 'editConfidentialCounterpartyName', 'editConfidentialDirection']
    });

    attachModalClose(confidentialViewModal);
    attachModalClose(confidentialEditModal);

    setupAutoCode({
        type: 'confidential',
        codeInputId: 'addConfidentialCode',
        autoButtonId: 'addConfidentialCodeAutoBtn',
        dateInputId: 'addConfidentialDate',
        counterpartyInputId: 'addConfidentialCounterpartyName',
        directionInputId: 'addConfidentialDirection',
        requiredInputIds: ['addConfidentialDate', 'addConfidentialCounterpartyName'],
        watchedInputIds: ['addConfidentialDate', 'addConfidentialCounterpartyName', 'addConfidentialDirection']
    });

    setupMultiImagePreview('confidentialAttachments', 'confidentialAttachmentsPreview');
    setupMultiImagePreview('editConfidentialAttachments', 'editConfidentialAttachmentsPreview');

    document.addEventListener('click', function (event) {
        const viewButton = event.target.closest('.btn-view-confidential');
        if (viewButton) {
            const record = parseRecord(viewButton);
            document.getElementById('confidentialViewCode').textContent = record.register_code || '-';
            document.getElementById('confidentialViewStatus').textContent = record.status || '-';
            document.getElementById('confidentialViewReference').textContent = record.reference_number || '-';
            document.getElementById('confidentialViewDirection').textContent = record.letter_direction || '-';
            document.getElementById('confidentialViewSubject').textContent = record.subject || '-';
            document.getElementById('confidentialViewCounterparty').textContent = record.counterparty_name || '-';
            document.getElementById('confidentialViewLevel').textContent = record.confidentiality_level || '-';
            document.getElementById('confidentialViewDate').textContent = record.letter_date || '-';
            document.getElementById('confidentialViewNotes').textContent = record.notes || '-';
            renderAttachmentBadges(document.getElementById('confidentialViewAttachments'), record.attachments || [], 'Tidak ada lampiran');
            openModal(confidentialViewModal);
            return;
        }

        const editButton = event.target.closest('.btn-edit-confidential');
        if (editButton) {
            const record = parseRecord(editButton);
            document.getElementById('editConfidentialId').value = record.id || '';
            document.getElementById('editConfidentialCode').value = record.register_code || '';
            document.getElementById('editConfidentialCode').dataset.generatedCode = '';
            document.getElementById('editConfidentialCode').dataset.autoMode = 'false';
            document.getElementById('editConfidentialReference').value = record.reference_number || '';
            document.getElementById('editConfidentialDirection').value = record.letter_direction || 'incoming';
            document.getElementById('editConfidentialSubject').value = record.subject || '';
            document.getElementById('editConfidentialCounterpartyName').value = record.counterparty_name || '';
            document.getElementById('editConfidentialLevel').value = record.confidentiality_level || 'confidential';
            document.getElementById('editConfidentialDate').value = record.letter_date || '';
            document.getElementById('editConfidentialStatus').value = record.status || 'logged';
            document.getElementById('editConfidentialNotes').value = record.notes || '';
            renderAttachmentBadges(document.getElementById('editConfidentialCurrentAttachments'), record.attachments || [], 'Tidak ada lampiran');
            resetMultiImagePreview('editConfidentialAttachments', 'editConfidentialAttachmentsPreview');
            editConfidentialCodeControl.refresh(false);
            openModal(confidentialEditModal);
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
            closeModal(confidentialViewModal);
            closeModal(confidentialEditModal);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
