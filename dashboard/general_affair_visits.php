<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['General Affair'], '/dashboard/index.php');
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'General Affair Visits';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function gaVisitStatusMeta(string $status): array
{
    return match ($status) {
        'scheduled' => ['label' => 'Scheduled', 'class' => 'badge-warning'],
        'confirmed' => ['label' => 'Confirmed', 'class' => 'badge-success'],
        'in_progress' => ['label' => 'In Progress', 'class' => 'badge-counter'],
        'completed' => ['label' => 'Completed', 'class' => 'badge-success'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'badge-muted'],
        default => ['label' => ucwords(str_replace('_', ' ', $status)), 'class' => 'badge-muted'],
    };
}

function gaVisitDateLabel(?string $date): string
{
    if (!$date) return '-';
    try {
        return (new DateTime($date))->format('d M Y');
    } catch (Throwable $e) {
        return (string)$date;
    }
}

function gaVisitTimeLabel(?string $time): string
{
    if (!$time) return '-';
    return substr((string)$time, 0, 5);
}

$picUsers = [];
$visits = [];
$summary = [
    'total' => 0,
    'scheduled' => 0,
    'today' => 0,
    'completed' => 0,
];

try {
    $picUsers = $pdo->query("
        SELECT id, full_name, position, division
        FROM user_rh
        WHERE is_active = 1
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $visits = $pdo->query("
        SELECT
            gav.*,
            pic.full_name AS pic_name,
            creator.full_name AS created_by_name,
            updater.full_name AS updated_by_name
        FROM general_affair_visits gav
        INNER JOIN user_rh pic ON pic.id = gav.pic_user_id
        INNER JOIN user_rh creator ON creator.id = gav.created_by
        LEFT JOIN user_rh updater ON updater.id = gav.updated_by
        ORDER BY gav.visit_date DESC, gav.start_time DESC, gav.id DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);

    $summary['total'] = (int)$pdo->query("SELECT COUNT(*) FROM general_affair_visits")->fetchColumn();
    $summary['scheduled'] = (int)$pdo->query("SELECT COUNT(*) FROM general_affair_visits WHERE status IN ('scheduled', 'confirmed', 'in_progress')")->fetchColumn();
    $summary['today'] = (int)$pdo->query("SELECT COUNT(*) FROM general_affair_visits WHERE visit_date = CURDATE()")->fetchColumn();
    $summary['completed'] = (int)$pdo->query("SELECT COUNT(*) FROM general_affair_visits WHERE status = 'completed'")->fetchColumn();
} catch (Throwable $e) {
    $errors[] = 'Tabel General Affair Visits belum siap. Jalankan SQL `docs/sql/03_2026-03-11_general_affair_visits_module.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Penjadwalan, monitoring, dan histori kunjungan yang dikelola General Affair.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="ga-visit-stats-grid mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">Total Visit</div>
                <div class="text-2xl font-extrabold text-slate-900"><?= (int)$summary['total'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Visit Aktif</div>
                <div class="text-2xl font-extrabold text-amber-700"><?= (int)$summary['scheduled'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Visit Hari Ini</div>
                <div class="text-2xl font-extrabold text-primary"><?= (int)$summary['today'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Visit Selesai</div>
                <div class="text-2xl font-extrabold text-success"><?= (int)$summary['completed'] ?></div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-[420px_minmax(0,1fr)] gap-4">
            <div class="card">
                <div class="card-header">Input Visit Baru</div>
                <form method="POST" action="general_affair_visits_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="create_visit">
                    <input type="hidden" name="redirect_to" value="general_affair_visits.php">

                    <label>Nama Pengunjung</label>
                    <input type="text" name="visitor_name" maxlength="150" required>

                    <label>Instansi</label>
                    <input type="text" name="institution_name" maxlength="150" placeholder="Opsional">

                    <label>Kontak Pengunjung</label>
                    <input type="text" name="visitor_phone" maxlength="50" placeholder="Opsional">

                    <label>Tujuan Kunjungan</label>
                    <textarea name="visit_purpose" rows="4" required></textarea>

                    <div class="row-form-2">
                        <div>
                            <label>Tanggal Visit</label>
                            <input type="date" name="visit_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label>Lokasi</label>
                            <input type="text" name="location" maxlength="150" required placeholder="Contoh: Lobby Utama">
                        </div>
                    </div>

                    <div class="row-form-2">
                        <div>
                            <label>Jam Mulai</label>
                            <input type="time" name="start_time" required>
                        </div>
                        <div>
                            <label>Jam Selesai</label>
                            <input type="time" name="end_time">
                        </div>
                    </div>

                    <label>PIC Internal</label>
                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required>
                        <input type="text" data-user-autocomplete-input placeholder="Ketik nama PIC..." required>
                        <input type="hidden" name="pic_user_id" data-user-autocomplete-hidden>
                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                    </div>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3" placeholder="Opsional"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-success">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Simpan Visit</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Daftar Visit</div>
                <div class="table-wrapper">
                    <table id="generalAffairVisitsTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Pengunjung</th>
                                <th>Jadwal</th>
                                <th>PIC</th>
                                <th>Status</th>
                                <th>Tujuan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visits as $row): ?>
                                <?php $statusMeta = gaVisitStatusMeta((string)$row['status']); ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$row['visit_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string)($row['institution_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$row['visitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string)($row['visitor_phone'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars(gaVisitDateLabel($row['visit_date'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(gaVisitTimeLabel($row['start_time'] ?? null), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars(gaVisitTimeLabel($row['end_time'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string)$row['location'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$row['pic_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs">Input: <?= htmlspecialchars((string)$row['created_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td>
                                        <div class="text-sm text-slate-700"><?= htmlspecialchars((string)$row['visit_purpose'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($row['notes'])): ?>
                                            <div class="meta-text-xs whitespace-pre-line mt-1"><?= htmlspecialchars((string)$row['notes'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-row-nowrap">
                                            <form method="POST" action="general_affair_visits_action.php" class="inline-flex gap-2 items-center">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="redirect_to" value="general_affair_visits.php">
                                                <input type="hidden" name="visit_id" value="<?= (int)$row['id'] ?>">
                                                <select name="status">
                                                    <?php foreach (['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled'] as $status): ?>
                                                        <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= ($row['status'] === $status) ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn-secondary btn-sm action-icon-btn" title="Update status visit" aria-label="Update status visit"><?= ems_icon('arrow-path', 'h-4 w-4') ?></button>
                                            </form>

                                            <button type="button" class="btn-secondary btn-sm action-icon-btn btn-edit-visit"
                                                data-id="<?= (int)$row['id'] ?>"
                                                data-visitor-name="<?= htmlspecialchars((string)$row['visitor_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-institution-name="<?= htmlspecialchars((string)($row['institution_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-visitor-phone="<?= htmlspecialchars((string)($row['visitor_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                data-visit-purpose="<?= htmlspecialchars((string)$row['visit_purpose'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-visit-date="<?= htmlspecialchars((string)$row['visit_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-start-time="<?= htmlspecialchars(substr((string)$row['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>"
                                                data-end-time="<?= htmlspecialchars($row['end_time'] ? substr((string)$row['end_time'], 0, 5) : '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-location="<?= htmlspecialchars((string)$row['location'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-pic-user-id="<?= (int)$row['pic_user_id'] ?>"
                                                data-notes="<?= htmlspecialchars((string)($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                title="Edit visit"
                                                aria-label="Edit visit">
                                                <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                            </button>

                                            <form method="POST" action="general_affair_visits_action.php" class="inline js-delete-visit" data-confirm="Yakin ingin menghapus visit ini?">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_visit">
                                                <input type="hidden" name="redirect_to" value="general_affair_visits.php">
                                                <input type="hidden" name="visit_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn-danger btn-sm action-icon-btn" title="Hapus visit" aria-label="Hapus visit"><?= ems_icon('trash', 'h-4 w-4') ?></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!$visits && empty($errors)): ?>
                        <div class="muted-placeholder p-4">Belum ada visit yang tercatat.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<div id="visitEditModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Edit Visit</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="general_affair_visits_action.php" class="form modal-form">
            <div class="modal-content">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="update_visit">
                <input type="hidden" name="redirect_to" value="general_affair_visits.php">
                <input type="hidden" name="visit_id" id="editVisitId">

                <label>Nama Pengunjung</label>
                <input type="text" name="visitor_name" id="editVisitorName" maxlength="150" required>

                <label>Instansi</label>
                <input type="text" name="institution_name" id="editInstitutionName" maxlength="150">

                <label>Kontak Pengunjung</label>
                <input type="text" name="visitor_phone" id="editVisitorPhone" maxlength="50">

                <label>Tujuan Kunjungan</label>
                <textarea name="visit_purpose" id="editVisitPurpose" rows="4" required></textarea>

                <div class="row-form-2">
                    <div>
                        <label>Tanggal Visit</label>
                        <input type="date" name="visit_date" id="editVisitDate" required>
                    </div>
                    <div>
                        <label>Lokasi</label>
                        <input type="text" name="location" id="editLocation" maxlength="150" required>
                    </div>
                </div>

                <div class="row-form-2">
                    <div>
                        <label>Jam Mulai</label>
                        <input type="time" name="start_time" id="editStartTime" required>
                    </div>
                    <div>
                        <label>Jam Selesai</label>
                        <input type="time" name="end_time" id="editEndTime">
                    </div>
                </div>

                <label>PIC Internal</label>
                <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required>
                    <input type="text" id="editPicUserName" data-user-autocomplete-input placeholder="Ketik nama PIC..." required>
                    <input type="hidden" name="pic_user_id" id="editPicUserId" data-user-autocomplete-hidden>
                    <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                </div>

                <label>Catatan</label>
                <textarea name="notes" id="editNotes" rows="3"></textarea>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan Perubahan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const datatableLanguageUrl = '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
    const modal = document.getElementById('visitEditModal');

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#generalAffairVisitsTable').DataTable({
            pageLength: 10,
            scrollX: true,
            autoWidth: false,
            order: [[2, 'desc']],
            language: { url: datatableLanguageUrl }
        });
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    document.querySelectorAll('.btn-edit-visit').forEach(function(button) {
        button.addEventListener('click', function() {
            document.getElementById('editVisitId').value = button.dataset.id || '';
            document.getElementById('editVisitorName').value = button.dataset.visitorName || '';
            document.getElementById('editInstitutionName').value = button.dataset.institutionName || '';
            document.getElementById('editVisitorPhone').value = button.dataset.visitorPhone || '';
            document.getElementById('editVisitPurpose').value = button.dataset.visitPurpose || '';
            document.getElementById('editVisitDate').value = button.dataset.visitDate || '';
            document.getElementById('editStartTime').value = button.dataset.startTime || '';
            document.getElementById('editEndTime').value = button.dataset.endTime || '';
            document.getElementById('editLocation').value = button.dataset.location || '';
            document.getElementById('editPicUserId').value = button.dataset.picUserId || '';
            document.getElementById('editPicUserName').value = button.closest('tr')?.querySelector('td:nth-child(4) strong')?.textContent?.trim() || '';
            document.getElementById('editNotes').value = button.dataset.notes || '';

            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });
    });

    document.querySelectorAll('.js-delete-visit').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const message = form.dataset.confirm || 'Yakin ingin menghapus data ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    if (modal) {
        modal.querySelectorAll('.btn-cancel').forEach(function(button) {
            button.addEventListener('click', closeModal);
        });

    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
});
</script>

<style>
.ga-visit-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}

@media (max-width: 1100px) {
    .ga-visit-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .ga-visit-stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
