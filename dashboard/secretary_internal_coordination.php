<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Secretary'], '/dashboard/index.php');

$pageTitle = 'Koordinasi Internal Divisi';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function secretaryCoordinationStatusMeta(string $status): array
{
    return match ($status) {
        'scheduled' => ['label' => 'SCHEDULED', 'class' => 'badge-counter'],
        'done' => ['label' => 'DONE', 'class' => 'badge-success'],
        'cancelled' => ['label' => 'CANCELLED', 'class' => 'badge-danger'],
        default => ['label' => strtoupper($status), 'class' => 'badge-muted'],
    };
}

function secretaryCoordinationTableExists(PDO $pdo, string $table): bool
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

function secretaryCoordinationGroupAttachments(array $rows, string $foreignKey): array
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

function secretaryCoordinationAttachmentPayload(array $attachments): array
{
    return array_map(static function (array $attachment): array {
        return [
            'src' => '/' . ltrim((string) ($attachment['file_path'] ?? ''), '/'),
            'name' => (string) (($attachment['file_name'] ?? '') ?: 'Lampiran'),
        ];
    }, $attachments);
}

function secretaryCoordinationJsonAttr(array $payload): string
{
    return htmlspecialchars((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
}

$rows = [];
$attachmentsMap = [];
$hasAttachmentTable = false;

try {
    $hasAttachmentTable = secretaryCoordinationTableExists($pdo, 'secretary_internal_coordination_attachments');
    $rows = $pdo->query("
        SELECT
            sic.*,
            host.full_name AS host_name
        FROM secretary_internal_coordinations sic
        INNER JOIN user_rh host ON host.id = sic.host_user_id
        ORDER BY sic.coordination_date DESC, sic.start_time DESC, sic.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($hasAttachmentTable && !empty($rows)) {
        $coordinationIds = array_values(array_filter(array_map(static fn($row) => (int) ($row['id'] ?? 0), $rows)));
        if (!empty($coordinationIds)) {
            $placeholders = implode(',', array_fill(0, count($coordinationIds), '?'));
            $stmt = $pdo->prepare("
                SELECT *
                FROM secretary_internal_coordination_attachments
                WHERE coordination_id IN ($placeholders)
                ORDER BY coordination_id ASC, sort_order ASC, id ASC
            ");
            $stmt->execute($coordinationIds);
            $attachmentsMap = secretaryCoordinationGroupAttachments($stmt->fetchAll(PDO::FETCH_ASSOC), 'coordination_id');
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
        <p class="page-subtitle">Pencatatan koordinasi internal, penanggung jawab, ringkasan, dan tindak lanjut.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php if (!$hasAttachmentTable): ?>
            <div class="alert alert-warning">Fitur lampiran koordinasi internal memerlukan SQL <code>docs/sql/15_2026-03-31_secretary_attachments.sql</code>.</div>
        <?php endif; ?>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Koordinasi Internal</div>
                <p class="meta-text mb-4">Catat topik koordinasi, host, jadwal, dan tindak lanjut divisi.</p>

                <form method="POST" action="secretary_action.php" enctype="multipart/form-data" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_internal_coordination">
                    <input type="hidden" name="redirect_to" value="secretary_internal_coordination.php">

                    <label>Nomor Surat</label>
                    <div class="flex gap-2">
                        <input type="text" name="coordination_code" id="addCoordinationCode" maxlength="100" placeholder="Otomatis muncul setelah field wajib lengkap">
                        <button type="button" class="btn-secondary whitespace-nowrap" id="addCoordinationCodeAutoBtn">Auto</button>
                    </div>
                    <div class="meta-text-xs mt-1">Nomor surat otomatis bisa diedit manual.</div>

                    <label>Judul Koordinasi</label>
                    <input type="text" name="title" required>

                    <label>Divisi Terkait</label>
                    <input type="text" name="division_scope" id="addCoordinationDivisionScope" required>

                    <label>Host / Penanggung Jawab</label>
                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required>
                        <input type="text" data-user-autocomplete-input placeholder="Ketik nama host..." required>
                        <input type="hidden" name="host_user_id" data-user-autocomplete-hidden>
                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                    </div>

                    <div class="row-form-2">
                        <div>
                            <label>Tanggal Koordinasi</label>
                            <input type="date" name="coordination_date" id="addCoordinationDate" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label>Jam Mulai</label>
                            <input type="time" name="start_time" required>
                        </div>
                    </div>

                    <label>Status</label>
                    <select name="status">
                        <option value="draft">Draft</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="done">Done</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    <label>Ringkasan Pembahasan</label>
                    <textarea name="summary_notes" rows="3"></textarea>

                    <label>Tindak Lanjut</label>
                    <textarea name="follow_up_notes" rows="3"></textarea>

                    <div class="doc-upload-wrapper m-0">
                        <div class="doc-upload-header">
                            <label class="text-sm font-semibold text-slate-900">Lampiran Koordinasi</label>
                            <span class="badge-muted-mini">Opsional, bisa beberapa file</span>
                        </div>
                        <div class="doc-upload-input">
                            <label for="coordinationAttachments" class="file-upload-label">
                                <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                                <span class="file-text">
                                    <strong>Pilih lampiran</strong>
                                    <small>JPG / PNG, multi file</small>
                                </span>
                            </label>
                            <input type="file" id="coordinationAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                            <div class="file-selected-name" data-for="coordinationAttachments"></div>
                            <div id="coordinationAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
                        </div>
                    </div>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('user-group', 'h-4 w-4') ?>
                            <span>Simpan Koordinasi</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Koordinasi</div>
                <p class="meta-text mb-4">Daftar koordinasi internal divisi beserta status dan follow up.</p>

                <div class="table-wrapper">
                    <table id="secretaryCoordinationTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Nomor Surat</th>
                                <th>Judul</th>
                                <th>Jadwal</th>
                                <th>Host</th>
                                <th>Status</th>
                                <th>Lampiran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $statusMeta = secretaryCoordinationStatusMeta((string) $row['status']); ?>
                                <?php $attachments = $attachmentsMap[(int) $row['id']] ?? []; ?>
                                <?php
                                $recordPayload = secretaryCoordinationJsonAttr([
                                    'id' => (int) $row['id'],
                                    'coordination_code' => (string) $row['coordination_code'],
                                    'title' => (string) $row['title'],
                                    'division_scope' => (string) $row['division_scope'],
                                    'host_user_id' => (int) $row['host_user_id'],
                                    'host_name' => (string) $row['host_name'],
                                    'coordination_date' => (string) $row['coordination_date'],
                                    'start_time' => substr((string) $row['start_time'], 0, 5),
                                    'status' => (string) $row['status'],
                                    'summary_notes' => (string) ($row['summary_notes'] ?? ''),
                                    'follow_up_notes' => (string) ($row['follow_up_notes'] ?? ''),
                                    'attachments' => secretaryCoordinationAttachmentPayload($attachments),
                                ]);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $row['coordination_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $row['division_scope'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['coordination_date'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(substr((string) $row['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> WIB</div>
                                    </td>
                                    <td><?= htmlspecialchars((string) $row['host_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <?php if (!empty($attachments)): ?>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($attachments as $attachment): ?>
                                                    <a href="#"
                                                        class="doc-badge btn-preview-doc"
                                                        data-src="/<?= htmlspecialchars(ltrim((string) $attachment['file_path'], '/'), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-title="<?= htmlspecialchars((string) ($attachment['file_name'] ?: ('Lampiran ' . $row['coordination_code'])), ENT_QUOTES, 'UTF-8') ?>">
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
                                            <button type="button" class="btn-secondary btn-sm secretary-action-icon btn-view-coordination" data-record="<?= $recordPayload ?>" title="Lihat detail koordinasi" aria-label="Lihat detail koordinasi">
                                                <?= ems_icon('eye', 'h-4 w-4') ?>
                                            </button>
                                            <button type="button" class="btn-primary btn-sm secretary-action-icon btn-edit-coordination" data-record="<?= $recordPayload ?>" title="Edit koordinasi" aria-label="Edit koordinasi">
                                                <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                            </button>
                                            <form method="POST" action="secretary_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus permanen koordinasi internal ini?">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_internal_coordination">
                                                <input type="hidden" name="redirect_to" value="secretary_internal_coordination.php">
                                                <input type="hidden" name="coordination_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn-danger btn-sm secretary-action-icon" title="Hapus permanen koordinasi" aria-label="Hapus permanen koordinasi">
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

<div id="coordinationViewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('eye', 'h-5 w-5 text-primary') ?>
                <span>Detail Koordinasi Internal</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Nomor Surat</div><div id="coordinationViewCode" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Status</div><div id="coordinationViewStatus" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Judul</div><div id="coordinationViewTitle" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Divisi Terkait</div><div id="coordinationViewDivision" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Tanggal</div><div id="coordinationViewDate" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3"><div class="meta-text-xs">Jam</div><div id="coordinationViewTime" class="mt-1 font-semibold text-slate-900">-</div></div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 md:col-span-2"><div class="meta-text-xs">Host</div><div id="coordinationViewHost" class="mt-1 font-semibold text-slate-900">-</div></div>
            </div>

            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div class="meta-text-xs">Ringkasan Pembahasan</div>
                <div id="coordinationViewSummary" class="mt-1 whitespace-pre-line text-sm text-slate-900">-</div>
            </div>

            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                <div class="meta-text-xs">Tindak Lanjut</div>
                <div id="coordinationViewFollowUp" class="mt-1 whitespace-pre-line text-sm text-slate-900">-</div>
            </div>

            <div class="mt-4">
                <div class="meta-text-xs">Lampiran</div>
                <div id="coordinationViewAttachments" class="mt-2 flex flex-wrap gap-2"></div>
            </div>

            <div class="modal-actions mt-4">
                <button type="button" class="btn-secondary btn-cancel">Tutup</button>
            </div>
        </div>
    </div>
</div>

<div id="coordinationEditModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('pencil-square', 'h-5 w-5 text-primary') ?>
                <span>Edit Koordinasi Internal</span>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <form method="POST" action="secretary_action.php" enctype="multipart/form-data" class="form">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="edit_internal_coordination">
                <input type="hidden" name="redirect_to" value="secretary_internal_coordination.php">
                <input type="hidden" name="coordination_id" id="editCoordinationId">

                <label>Nomor Surat</label>
                <div class="flex gap-2">
                    <input type="text" name="coordination_code" id="editCoordinationCode" maxlength="100">
                    <button type="button" class="btn-secondary whitespace-nowrap" id="editCoordinationCodeAutoBtn">Auto</button>
                </div>
                <div class="meta-text-xs mt-1">Nomor surat bisa diubah manual.</div>

                <label>Judul Koordinasi</label>
                <input type="text" name="title" id="editCoordinationTitle" required>

                <label>Divisi Terkait</label>
                <input type="text" name="division_scope" id="editCoordinationDivisionScope" required>

                <label>Host / Penanggung Jawab</label>
                <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required>
                    <input type="text" data-user-autocomplete-input id="editCoordinationHostName" placeholder="Ketik nama host..." required>
                    <input type="hidden" name="host_user_id" id="editCoordinationHostUserId" data-user-autocomplete-hidden>
                    <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                </div>

                <div class="row-form-2">
                    <div>
                        <label>Tanggal Koordinasi</label>
                        <input type="date" name="coordination_date" id="editCoordinationDate" required>
                    </div>
                    <div>
                        <label>Jam Mulai</label>
                        <input type="time" name="start_time" id="editCoordinationTime" required>
                    </div>
                </div>

                <label>Status</label>
                <select name="status" id="editCoordinationStatus">
                    <option value="draft">Draft</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="done">Done</option>
                    <option value="cancelled">Cancelled</option>
                </select>

                <label>Ringkasan Pembahasan</label>
                <textarea name="summary_notes" id="editCoordinationSummary" rows="3"></textarea>

                <label>Tindak Lanjut</label>
                <textarea name="follow_up_notes" id="editCoordinationFollowUp" rows="3"></textarea>

                <div class="mt-4">
                    <div class="meta-text-xs">Lampiran Saat Ini</div>
                    <div id="editCoordinationCurrentAttachments" class="mt-2 flex flex-wrap gap-2"></div>
                </div>

                <div class="doc-upload-wrapper m-0">
                    <div class="doc-upload-header">
                        <label class="text-sm font-semibold text-slate-900">Tambah Lampiran</label>
                        <span class="badge-muted-mini">Opsional, menambah lampiran baru</span>
                    </div>
                    <div class="doc-upload-input">
                        <label for="editCoordinationAttachments" class="file-upload-label">
                            <span class="file-icon"><?= ems_icon('paper-clip', 'h-5 w-5') ?></span>
                            <span class="file-text">
                                <strong>Pilih lampiran</strong>
                                <small>JPG / PNG, multi file</small>
                            </span>
                        </label>
                        <input type="file" id="editCoordinationAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="sr-only" multiple>
                        <div class="file-selected-name" data-for="editCoordinationAttachments"></div>
                        <div id="editCoordinationAttachmentsPreview" class="mt-3 grid gap-3 sm:grid-cols-2 md:grid-cols-3"></div>
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

            const institutionInput = document.getElementById(options.institutionInputId);
            if (institutionInput) {
                url.searchParams.set('institution_name', (institutionInput.value || '').trim());
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
        $('#secretaryCoordinationTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }

    const coordinationViewModal = document.getElementById('coordinationViewModal');
    const coordinationEditModal = document.getElementById('coordinationEditModal');
    const editCoordinationCodeControl = setupAutoCode({
        type: 'internal_coordination',
        codeInputId: 'editCoordinationCode',
        autoButtonId: 'editCoordinationCodeAutoBtn',
        dateInputId: 'editCoordinationDate',
        institutionInputId: 'editCoordinationDivisionScope',
        requiredInputIds: ['editCoordinationDate', 'editCoordinationDivisionScope'],
        watchedInputIds: ['editCoordinationDate', 'editCoordinationDivisionScope']
    });

    attachModalClose(coordinationViewModal);
    attachModalClose(coordinationEditModal);

    setupAutoCode({
        type: 'internal_coordination',
        codeInputId: 'addCoordinationCode',
        autoButtonId: 'addCoordinationCodeAutoBtn',
        dateInputId: 'addCoordinationDate',
        institutionInputId: 'addCoordinationDivisionScope',
        requiredInputIds: ['addCoordinationDate', 'addCoordinationDivisionScope'],
        watchedInputIds: ['addCoordinationDate', 'addCoordinationDivisionScope']
    });

    setupMultiImagePreview('coordinationAttachments', 'coordinationAttachmentsPreview');
    setupMultiImagePreview('editCoordinationAttachments', 'editCoordinationAttachmentsPreview');

    document.addEventListener('click', function (event) {
        const viewButton = event.target.closest('.btn-view-coordination');
        if (viewButton) {
            const record = parseRecord(viewButton);
            document.getElementById('coordinationViewCode').textContent = record.coordination_code || '-';
            document.getElementById('coordinationViewStatus').textContent = record.status || '-';
            document.getElementById('coordinationViewTitle').textContent = record.title || '-';
            document.getElementById('coordinationViewDivision').textContent = record.division_scope || '-';
            document.getElementById('coordinationViewDate').textContent = record.coordination_date || '-';
            document.getElementById('coordinationViewTime').textContent = record.start_time ? record.start_time + ' WIB' : '-';
            document.getElementById('coordinationViewHost').textContent = record.host_name || '-';
            document.getElementById('coordinationViewSummary').textContent = record.summary_notes || '-';
            document.getElementById('coordinationViewFollowUp').textContent = record.follow_up_notes || '-';
            renderAttachmentBadges(document.getElementById('coordinationViewAttachments'), record.attachments || [], 'Tidak ada lampiran');
            openModal(coordinationViewModal);
            return;
        }

        const editButton = event.target.closest('.btn-edit-coordination');
        if (editButton) {
            const record = parseRecord(editButton);
            document.getElementById('editCoordinationId').value = record.id || '';
            document.getElementById('editCoordinationCode').value = record.coordination_code || '';
            document.getElementById('editCoordinationCode').dataset.generatedCode = '';
            document.getElementById('editCoordinationCode').dataset.autoMode = 'false';
            document.getElementById('editCoordinationTitle').value = record.title || '';
            document.getElementById('editCoordinationDivisionScope').value = record.division_scope || '';
            document.getElementById('editCoordinationHostName').value = record.host_name || '';
            document.getElementById('editCoordinationHostUserId').value = record.host_user_id || '';
            document.getElementById('editCoordinationDate').value = record.coordination_date || '';
            document.getElementById('editCoordinationTime').value = record.start_time || '';
            document.getElementById('editCoordinationStatus').value = record.status || 'draft';
            document.getElementById('editCoordinationSummary').value = record.summary_notes || '';
            document.getElementById('editCoordinationFollowUp').value = record.follow_up_notes || '';
            renderAttachmentBadges(document.getElementById('editCoordinationCurrentAttachments'), record.attachments || [], 'Tidak ada lampiran');
            resetMultiImagePreview('editCoordinationAttachments', 'editCoordinationAttachmentsPreview');
            editCoordinationCodeControl.refresh(false);
            openModal(coordinationEditModal);
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
            closeModal(coordinationViewModal);
            closeModal(coordinationEditModal);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
