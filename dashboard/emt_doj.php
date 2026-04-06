<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'EMT DOJ';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

// Jika halaman EMT DOJ berhasil diakses, jangan tampilkan flash error guard division
// yang tersisa dari redirect halaman lain karena akan membingungkan user.
$errors = array_values(array_filter($errors, static function ($error) {
    return trim((string)$error) !== 'Akses halaman ditolak untuk division Anda.';
}));

$user = $_SESSION['user_rh'] ?? [];
$userRole = $user['role'] ?? '';
$userDivision = ems_normalize_division($user['division'] ?? '');
$currentUserUnit = ems_current_user_unit($pdo, $user);

$canManageMaster = ems_is_manager_plus_role($userRole);
$canInputDelivery = $userDivision === 'Medis' || $canManageMaster;
function emtDojStatusMeta(array $row): array
{
    $isActive = (int)($row['is_active'] ?? 0) === 1;
    $deliveredCount = (int)($row['delivered_count'] ?? 0);
    $targetPatients = (int)($row['target_patients'] ?? 0);

    if ($deliveredCount >= $targetPatients && $targetPatients > 0) {
        return ['label' => 'Selesai', 'class' => 'badge-success'];
    }

    if (!$isActive) {
        return ['label' => 'Nonaktif', 'class' => 'badge-muted'];
    }

    return ['label' => 'Berjalan', 'class' => 'badge-warning'];
}

function emtDojDateTimeLabel(?string $value): string
{
    if (!$value) {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d M Y H:i');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

$emtRows = [];
$deliveryRows = [];
$availableEmtRows = [];
$summary = [
    'total' => 0,
    'active' => 0,
    'in_progress' => 0,
    'completed' => 0,
];

try {
    if (!ems_column_exists($pdo, 'emt_doj', 'id') || !ems_column_exists($pdo, 'emt_doj_deliveries', 'id')) {
        throw new RuntimeException('Tabel EMT DOJ belum tersedia. Jalankan SQL `docs/sql/17_2026-04-06_emt_doj_module.sql` terlebih dahulu.');
    }

    $emtRows = $pdo->query("
        SELECT
            e.*,
            creator.full_name AS created_by_name,
            COALESCE(stats.delivered_count, 0) AS delivered_count,
            GREATEST(e.target_patients - COALESCE(stats.delivered_count, 0), 0) AS remaining_patients
        FROM emt_doj e
        LEFT JOIN (
            SELECT emt_id, COUNT(*) AS delivered_count
            FROM emt_doj_deliveries
            GROUP BY emt_id
        ) stats ON stats.emt_id = e.id
        LEFT JOIN user_rh creator ON creator.id = e.created_by
        ORDER BY
            CASE
                WHEN COALESCE(stats.delivered_count, 0) >= e.target_patients THEN 1
                ELSE 0
            END ASC,
            e.is_active DESC,
            e.created_at DESC,
            e.full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($emtRows as $row) {
        $summary['total']++;
        if ((int)($row['is_active'] ?? 0) === 1) {
            $summary['active']++;
        }
        if ((int)($row['delivered_count'] ?? 0) >= (int)($row['target_patients'] ?? 0) && (int)($row['target_patients'] ?? 0) > 0) {
            $summary['completed']++;
        } else {
            $summary['in_progress']++;
        }
    }

    foreach ($emtRows as $row) {
        if ((int)($row['is_active'] ?? 0) !== 1) {
            continue;
        }

        if ((int)($row['delivered_count'] ?? 0) >= (int)($row['target_patients'] ?? 0)) {
            continue;
        }

        $availableEmtRows[] = $row;
    }

    $deliveryRows = $pdo->query("
        SELECT
            d.*,
            e.full_name,
            e.cid
        FROM emt_doj_deliveries d
        INNER JOIN emt_doj e ON e.id = d.emt_id
        ORDER BY d.delivered_at DESC, d.id DESC
        LIMIT 300
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Monitoring EMT DOJ, target pengantaran pasien, dan histori penginputan dari seluruh unit.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <div class="stats-grid mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">Total EMT DOJ</div>
                <div class="text-2xl font-extrabold text-slate-900"><?= (int)$summary['total'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Data Aktif</div>
                <div class="text-2xl font-extrabold text-primary"><?= (int)$summary['active'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Belum Selesai</div>
                <div class="text-2xl font-extrabold text-amber-700"><?= (int)$summary['in_progress'] ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Target Terpenuhi</div>
                <div class="text-2xl font-extrabold text-success"><?= (int)$summary['completed'] ?></div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
            <?php if ($canManageMaster): ?>
                <div class="card">
                    <div class="card-header">Input EMT DOJ Baru</div>
                    <form method="POST" action="emt_doj_action.php" class="form">
                        <?= csrfField(); ?>
                        <input type="hidden" name="action" value="create_emt">

                        <label>Nama Lengkap</label>
                        <input type="text" name="full_name" maxlength="150" required placeholder="Nama lengkap tahanan kota">

                        <label>CID</label>
                        <input type="text" name="cid" maxlength="20" required placeholder="Contoh: DOJX1029">

                        <label>Jumlah Pasien Yang Harus Dipenuhi</label>
                        <input type="number" name="target_patients" min="1" step="1" required placeholder="Contoh: 10">

                        <div class="modal-actions mt-4">
                            <button type="submit" class="btn-success">
                                <?= ems_icon('plus', 'h-4 w-4') ?>
                                <span>Simpan EMT DOJ</span>
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($canInputDelivery): ?>
                <div class="card">
                    <div class="card-header">Input Pengantaran Pasien</div>
                    <form method="POST" action="emt_doj_action.php" class="form">
                        <?= csrfField(); ?>
                        <input type="hidden" name="action" value="create_delivery">

                        <label>Nama EMT DOJ</label>
                        <select name="emt_id" required>
                            <option value="">Pilih EMT DOJ</option>
                            <?php foreach ($availableEmtRows as $row): ?>
                                <option value="<?= (int)$row['id'] ?>">
                                    <?= htmlspecialchars((string)$row['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                    - <?= htmlspecialchars((string)$row['cid'], ENT_QUOTES, 'UTF-8') ?>
                                    (<?= (int)$row['remaining_patients'] ?> sisa)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label>Unit Medis Login</label>
                        <input type="text" value="<?= htmlspecialchars(ems_unit_label($currentUserUnit), ENT_QUOTES, 'UTF-8') ?>" readonly>

                        <p class="meta-text-xs">Tanggal, jam, nama penginput, dan unit akan diisi otomatis dari akun yang sedang login.</p>

                        <div class="modal-actions mt-4">
                            <button type="submit" class="btn-success" <?= $availableEmtRows === [] ? 'disabled' : '' ?>>
                                <?= ems_icon('check', 'h-4 w-4') ?>
                                <span>Simpan Pengantaran</span>
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!$canManageMaster && !$canInputDelivery): ?>
                <div class="card">
                    <div class="card-header">Informasi Akses</div>
                    <div class="card-body">
                        <p class="meta-text">Halaman ini bisa dilihat semua user. Input data EMT DOJ hanya untuk manager, dan input pengantaran pasien hanya untuk division Medis atau manager.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card mb-4">
            <div class="card-header">Daftar EMT DOJ</div>
            <div class="table-wrapper">
                <table id="emtDojTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>CID</th>
                            <th>Target</th>
                            <th>Sudah Diantar</th>
                            <th>Sisa</th>
                            <th>Status</th>
                            <th>Input Manager</th>
                            <?php if ($canManageMaster): ?>
                                <th>Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($emtRows as $row): ?>
                            <?php $statusMeta = emtDojStatusMeta($row); ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string)$row['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs">Dibuat: <?= htmlspecialchars(emtDojDateTimeLabel($row['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td><?= htmlspecialchars((string)$row['cid'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)$row['target_patients'] ?></td>
                                <td><?= (int)$row['delivered_count'] ?></td>
                                <td><?= (int)$row['remaining_patients'] ?></td>
                                <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string)($row['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <?php if ($canManageMaster): ?>
                                    <td class="table-actions">
                                        <div class="action-row-nowrap">
                                            <button
                                                type="button"
                                                class="btn-secondary btn-sm action-icon-btn btn-edit-emt"
                                                data-id="<?= (int)$row['id'] ?>"
                                                data-full-name="<?= htmlspecialchars((string)$row['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-cid="<?= htmlspecialchars((string)$row['cid'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-target-patients="<?= (int)$row['target_patients'] ?>"
                                                data-is-active="<?= (int)$row['is_active'] ?>"
                                                title="Edit EMT DOJ"
                                                aria-label="Edit EMT DOJ">
                                                <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                            </button>

                                            <form method="POST" action="emt_doj_action.php" class="inline js-delete-emt" data-confirm="Yakin ingin menghapus permanen data EMT DOJ ini? Semua history pengantarannya juga akan ikut terhapus.">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_emt">
                                                <input type="hidden" name="emt_id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn-danger btn-sm action-icon-btn" title="Hapus permanen EMT DOJ" aria-label="Hapus permanen EMT DOJ">
                                                    <?= ems_icon('trash', 'h-4 w-4') ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($emtRows === [] && empty($errors)): ?>
                    <div class="muted-placeholder p-4">Belum ada data EMT DOJ.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">History Pengantaran Pasien</div>
            <div class="table-wrapper">
                <table id="emtDojHistoryTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Tanggal & Jam</th>
                            <th>Nama EMT DOJ</th>
                            <th>CID</th>
                            <th>Unit</th>
                            <th>Diinput Oleh</th>
                            <?php if ($canManageMaster): ?>
                                <th>Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deliveryRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars(emtDojDateTimeLabel($row['delivered_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$row['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$row['cid'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(ems_unit_label($row['unit_code'] ?? 'roxwood'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$row['input_by_name_snapshot'], ENT_QUOTES, 'UTF-8') ?></td>
                                <?php if ($canManageMaster): ?>
                                    <td class="table-actions">
                                        <form method="POST" action="emt_doj_action.php" class="inline js-delete-delivery" data-confirm="Yakin ingin menghapus permanen history pengantaran pasien ini?">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_delivery">
                                            <input type="hidden" name="delivery_id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn-danger btn-sm action-icon-btn" title="Hapus permanen history pengantaran" aria-label="Hapus permanen history pengantaran">
                                                <?= ems_icon('trash', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($deliveryRows === [] && empty($errors)): ?>
                    <div class="muted-placeholder p-4">Belum ada history pengantaran pasien.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php if ($canManageMaster): ?>
    <div id="emtEditModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-md">
            <div class="modal-head">
                <div class="modal-title">Edit EMT DOJ</div>
                <button type="button" class="modal-close-btn btn-emt-cancel" aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>

            <form method="POST" action="emt_doj_action.php" class="form modal-form">
                <div class="modal-content">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="update_emt">
                    <input type="hidden" name="emt_id" id="editEmtId">

                    <label>Nama Lengkap</label>
                    <input type="text" name="full_name" id="editEmtFullName" maxlength="150" required>

                    <label>CID</label>
                    <input type="text" name="cid" id="editEmtCid" maxlength="20" required>

                    <label>Jumlah Pasien Yang Harus Dipenuhi</label>
                    <input type="number" name="target_patients" id="editEmtTargetPatients" min="1" step="1" required>

                    <label>Status</label>
                    <select name="is_active" id="editEmtIsActive" required>
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                </div>

                <div class="modal-foot">
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary btn-emt-cancel">Batal</button>
                        <button type="submit" class="btn-success">Simpan Perubahan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const datatableLanguageUrl = '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
    const emtEditModal = document.getElementById('emtEditModal');

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#emtDojTable').DataTable({
            pageLength: 10,
            scrollX: true,
            autoWidth: false,
            order: [[0, 'asc']],
            language: { url: datatableLanguageUrl }
        });

        jQuery('#emtDojHistoryTable').DataTable({
            pageLength: 10,
            scrollX: true,
            autoWidth: false,
            order: [[0, 'desc']],
            language: { url: datatableLanguageUrl }
        });
    }

    function closeEmtModal() {
        if (!emtEditModal) {
            return;
        }

        emtEditModal.classList.add('hidden');
        emtEditModal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    document.querySelectorAll('.js-delete-emt, .js-delete-delivery').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const message = form.dataset.confirm || 'Yakin ingin menghapus data ini secara permanen?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('.btn-edit-emt').forEach(function(button) {
        button.addEventListener('click', function() {
            if (!emtEditModal) {
                return;
            }

            document.getElementById('editEmtId').value = button.dataset.id || '';
            document.getElementById('editEmtFullName').value = button.dataset.fullName || '';
            document.getElementById('editEmtCid').value = button.dataset.cid || '';
            document.getElementById('editEmtTargetPatients').value = button.dataset.targetPatients || '';
            document.getElementById('editEmtIsActive').value = button.dataset.isActive || '1';

            emtEditModal.classList.remove('hidden');
            emtEditModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });
    });

    if (emtEditModal) {
        emtEditModal.querySelectorAll('.btn-emt-cancel').forEach(function(button) {
            button.addEventListener('click', closeEmtModal);
        });
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeEmtModal();
        }
    });
});
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
