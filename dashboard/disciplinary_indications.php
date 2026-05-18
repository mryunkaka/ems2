<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Disciplinary Committee'], '/dashboard/index.php');

$pageTitle = 'Master Poin Pelanggaran';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$hasCreatedByColumn = ems_column_exists($pdo, 'disciplinary_indications', 'created_by');
$hasUpdatedByColumn = ems_column_exists($pdo, 'disciplinary_indications', 'updated_by');

function disciplinaryIndicationDescriptionHtml(?string $description): string
{
    $description = trim((string)$description);
    if ($description === '') {
        return '<span class="text-muted">-</span>';
    }

    return nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8'));
}

$indications = [];

try {
    $selectColumns = [
        'di.id',
        'di.code',
        'di.name',
        'di.description',
        'di.default_points',
        'di.tolerance_type',
        'di.is_active',
        'di.created_at',
        'di.updated_at',
    ];
    $joinSql = '';

    if ($hasCreatedByColumn) {
        $selectColumns[] = "COALESCE(creator.full_name, '-') AS created_by_name";
        $joinSql .= "
        LEFT JOIN user_rh creator ON creator.id = di.created_by";
    } else {
        $selectColumns[] = "'-' AS created_by_name";
    }

    if ($hasUpdatedByColumn) {
        $selectColumns[] = "COALESCE(updater.full_name, '-') AS updated_by_name";
        $joinSql .= "
        LEFT JOIN user_rh updater ON updater.id = di.updated_by";
    } else {
        $selectColumns[] = "'-' AS updated_by_name";
    }

    $stmt = $pdo->query("
        SELECT " . implode(",\n            ", $selectColumns) . "
        FROM disciplinary_indications di" . $joinSql . "
        ORDER BY di.is_active DESC, di.default_points DESC, di.name ASC
    ");
    $indications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat master indikasi: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Kelola kategori pelanggaran, bobot poin, deskripsi, dan tingkat toleransi sesuai kebutuhan SOP Komdis.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header card-header-actions card-header-flex disciplinary-indications-header">
                <div class="card-header-actions-title disciplinary-indications-title">
                    <?= ems_icon('list-bullet', 'h-4 w-4') ?> Daftar Poin Pelanggaran
                </div>
                <button type="button" id="openAddIndicationModal" class="btn-success">
                    <?= ems_icon('plus', 'h-4 w-4') ?>
                    <span>Tambah Poin</span>
                </button>
            </div>

            <div class="meta-text-xs mb-3">Geser tabel ke samping untuk melihat seluruh kolom.</div>

            <div class="table-wrapper disciplinary-indications-table-wrap">
                <table id="disciplinaryIndicationsTable" class="table-custom disciplinary-indications-table">
                    <thead>
                        <tr>
                            <th>Kategori / Pelanggaran</th>
                            <th>Poin</th>
                            <th>Toleransi</th>
                            <th>Status</th>
                            <th>Deskripsi</th>
                            <th>Riwayat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($indications as $row): ?>
                            <tr
                                data-id="<?= (int)$row['id'] ?>"
                                data-name="<?= htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-default-points="<?= (int)$row['default_points'] ?>"
                                data-tolerance-type="<?= htmlspecialchars((string)$row['tolerance_type'], ENT_QUOTES, 'UTF-8') ?>"
                                data-description="<?= htmlspecialchars((string)($row['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-is-active="<?= (int)$row['is_active'] ?>">
                                <td><strong><?= htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><?= (int)$row['default_points'] ?></td>
                                <td><?= htmlspecialchars(ems_disciplinary_tolerance_options()[$row['tolerance_type']] ?? (string)$row['tolerance_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="badge-counter<?= ((int)$row['is_active'] === 1) ? '' : ' badge-muted' ?>">
                                        <?= ((int)$row['is_active'] === 1) ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td class="disciplinary-description-cell"><?= disciplinaryIndicationDescriptionHtml($row['description'] ?? '') ?></td>
                                <td>
                                    <div><strong>Tambah:</strong> <?= htmlspecialchars((string)($row['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="meta-text-xs mb-2"><?= htmlspecialchars(formatTanggalID($row['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div><strong>Update:</strong> <?= htmlspecialchars((string)($row['updated_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="meta-text-xs"><?= htmlspecialchars(formatTanggalID($row['updated_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="table-actions">
                                    <div class="action-row-nowrap">
                                        <button type="button" class="btn-secondary action-icon-btn btn-edit-indication" title="Update indikasi" aria-label="Update indikasi">
                                            <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                        </button>
                                        <form method="POST" action="disciplinary_committee_action.php" class="inline js-delete-indication" data-confirm="Yakin ingin menghapus indikasi ini?">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_indication">
                                            <input type="hidden" name="redirect_to" value="disciplinary_indications.php">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn-danger action-icon-btn" title="Hapus poin pelanggaran" aria-label="Hapus poin pelanggaran">
                                                <?= ems_icon('trash', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!$indications): ?>
                    <div class="muted-placeholder p-4">Belum ada master indikasi.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div id="addIndicationModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Tambah Poin Pelanggaran</div>
            <button type="button" class="modal-close-btn btn-add-indication-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="disciplinary_committee_action.php" id="addIndicationForm" class="form modal-form">
            <div class="modal-content">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="save_indication">
                <input type="hidden" name="redirect_to" value="disciplinary_indications.php">

                <label for="newIndicationName">Nama Pelanggaran / Kategori</label>
                <input type="text" id="newIndicationName" name="name" required>

                <label for="newIndicationPoints">Poin Default</label>
                <input type="number" id="newIndicationPoints" name="default_points" min="0" required>

                <label for="newIndicationTolerance">Toleransi</label>
                <select id="newIndicationTolerance" name="tolerance_type" required>
                    <?php foreach (ems_disciplinary_tolerance_options() as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="newIndicationDescription">Deskripsi</label>
                <textarea id="newIndicationDescription" name="description" rows="5" placeholder="Tulis penjelasan pelanggaran, contoh kejadian, atau batasan penilaian."></textarea>

                <label class="inline-flex items-center gap-2 mt-3">
                    <input type="checkbox" name="is_active" checked>
                    <span>Aktif</span>
                </label>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-add-indication-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan Poin</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="editIndicationModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Edit Poin Pelanggaran</div>
            <button type="button" class="modal-close-btn btn-edit-indication-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="disciplinary_committee_action.php" class="form modal-form">
            <div class="modal-content">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="save_indication">
                <input type="hidden" name="redirect_to" value="disciplinary_indications.php">
                <input type="hidden" name="id" id="editIndicationId">

                <label for="editIndicationName">Nama Pelanggaran / Kategori</label>
                <input type="text" id="editIndicationName" name="name" required>

                <label for="editIndicationPoints">Poin Default</label>
                <input type="number" id="editIndicationPoints" name="default_points" min="0" required>

                <label for="editIndicationTolerance">Toleransi</label>
                <select id="editIndicationTolerance" name="tolerance_type" required>
                    <?php foreach (ems_disciplinary_tolerance_options() as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="editIndicationDescription">Deskripsi</label>
                <textarea id="editIndicationDescription" name="description" rows="5" placeholder="Tulis penjelasan pelanggaran, contoh kejadian, atau batasan penilaian."></textarea>

                <label class="inline-flex items-center gap-2 mt-3">
                    <input type="checkbox" name="is_active" id="editIndicationIsActive">
                    <span>Aktif</span>
                </label>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-edit-indication-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan Perubahan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
    .disciplinary-indications-header {
        align-items: center;
    }

    .disciplinary-indications-title {
        font-size: 13px;
    }

    .disciplinary-indications-table-wrap {
        overflow-x: auto;
    }

    .disciplinary-indications-table {
        min-width: 1160px;
    }

    .disciplinary-indications-table thead th,
    .disciplinary-indications-table tbody td {
        vertical-align: top;
    }

    .disciplinary-description-cell {
        min-width: 280px;
        white-space: normal;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const datatableLanguageUrl = '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
    const addModal = document.getElementById('addIndicationModal');
    const editModal = document.getElementById('editIndicationModal');
    const addForm = document.getElementById('addIndicationForm');

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

        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#disciplinaryIndicationsTable').DataTable({
            pageLength: 10,
            scrollX: true,
            autoWidth: false,
            order: [[2, 'desc'], [0, 'asc']],
            language: { url: datatableLanguageUrl }
        });
    }

    document.getElementById('openAddIndicationModal')?.addEventListener('click', function() {
        if (addForm) {
            addForm.reset();
        }
        openModal(addModal);
    });

    document.querySelectorAll('.btn-add-indication-cancel').forEach(function(button) {
        button.addEventListener('click', function() {
            closeModal(addModal);
        });
    });

    document.querySelectorAll('.btn-edit-indication-cancel').forEach(function(button) {
        button.addEventListener('click', function() {
            closeModal(editModal);
        });
    });

    document.querySelectorAll('.btn-edit-indication').forEach(function(button) {
        button.addEventListener('click', function() {
            const row = button.closest('tr');
            if (!row) {
                return;
            }

            document.getElementById('editIndicationId').value = row.dataset.id || '';
            document.getElementById('editIndicationName').value = row.dataset.name || '';
            document.getElementById('editIndicationPoints').value = row.dataset.defaultPoints || '0';
            document.getElementById('editIndicationTolerance').value = row.dataset.toleranceType || 'tolerable';
            document.getElementById('editIndicationDescription').value = row.dataset.description || '';
            document.getElementById('editIndicationIsActive').checked = (row.dataset.isActive || '0') === '1';

            openModal(editModal);
        });
    });

    document.querySelectorAll('.js-delete-indication').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const message = form.dataset.confirm || 'Yakin ingin menghapus data ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal(addModal);
            closeModal(editModal);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
