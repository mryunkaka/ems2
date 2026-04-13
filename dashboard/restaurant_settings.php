<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

/*
|--------------------------------------------------------------------------
| HARD GUARD & CONFIG
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

/*
|--------------------------------------------------------------------------
| PAGE INFO
|--------------------------------------------------------------------------
*/
$pageTitle = 'Restaurant Settings';

/*
|--------------------------------------------------------------------------
| INCLUDE LAYOUT
|--------------------------------------------------------------------------
*/
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

/*
|--------------------------------------------------------------------------
| ROLE CHECK - Hanya selain staff/manager yang boleh akses
|--------------------------------------------------------------------------
*/
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));

if (in_array($userRole, ['staff', 'manager'], true)) {
    http_response_code(403);
    echo '<div class="access-card">
        <h3 class="access-title">Akses Ditolak</h3>
        <p>Anda tidak memiliki izin untuk mengakses halaman ini.</p>
        <a href="/dashboard/index.php" class="btn btn-secondary top-spaced-button">Kembali</a>
    </div>';
    include __DIR__ . '/../partials/footer.php';
    exit;
}

$userId = (int)($_SESSION['user_rh']['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| AMBIL DATA RESTAURAN
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT *
    FROM restaurant_settings
    ORDER BY restaurant_name ASC
");
$restaurants = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/
$messages = $_SESSION['flash_messages'] ?? [];
$errors   = $_SESSION['flash_errors']   ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

?>

<section class="content">
    <div class="page mx-auto max-w-[1000px]">

        <div class="header-row">
            <div>
                <h1 class="page-title">Pengaturan Restoran</h1>
                <p class="page-subtitle">Kelola daftar restoran dan harga per paket</p>
            </div>
            <a href="/dashboard/restaurant_consumption.php" class="btn btn-secondary">
                <?= ems_icon('arrow-left', 'h-4 w-4') ?>
                <span>Kembali ke Konsumsi</span>
            </a>
        </div>

        <!-- FLASH MESSAGES -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $msg): ?>
                <div class="flash-success">
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <div class="flash-error">
                    <?= htmlspecialchars($err) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- FORM TAMBAH RESTORAN -->
        <div class="card card-section">
            <div class="card-header">
                <span>Tambah Restoran Baru</span>
            </div>
            <div class="card-body">
                <form method="POST" action="restaurant_settings_action.php?action=create" class="form">
                    <div class="row-form-2">
                        <div>
                            <label>Nama Restoran</label>
                            <input type="text" name="restaurant_name" required placeholder="Contoh: Up And Atom">
                        </div>
                        <div>
                            <label>Harga per Paket ($)</label>
                            <input type="number" name="price_per_packet" step="0.01" min="0" required placeholder="400">
                        </div>
                    </div>

                    <div class="row-form-2">
                        <div>
                            <label>Pajak (%)</label>
                            <input type="number" name="tax_percentage" step="0.01" min="0" max="100" value="5" required>
                        </div>
                        <div class="flex items-end">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" value="1" checked>
                                <span>Aktif</span>
                            </label>
                        </div>
                    </div>

                    <div class="mt-2">
                        <button type="submit" class="btn btn-success">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Tambah Restoran</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- DAFTAR RESTORAN -->
        <div class="card">
            <div class="card-header">
                Daftar Restoran
            </div>

            <div class="table-wrapper">
                <table id="restaurantSettingsTable" class="table-custom" data-auto-datatable="true" data-dt-order='[[1,"asc"]]' data-dt-column-defs='[{"targets":[6],"orderable":false,"searchable":false}]'>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Restoran</th>
                            <th>Harga/Paket</th>
                            <th>Pajak</th>
                            <th>Status</th>
                            <th>Dibuat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($restaurants as $i => $r): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($r['restaurant_name']) ?></strong>
                                </td>
                                <td>
                                    <span class="price-text">
                                        $<?= number_format($r['price_per_packet'], 0, ',', '.') ?>
                                    </span>
                                </td>
                                <td><?= number_format($r['tax_percentage'], 0) ?>%</td>
                                <td>
                                    <?php if ($r['is_active']): ?>
                                        <span class="badge-status badge-approved">AKTIF</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-cancelled">NON-AKTIF</span>
                                    <?php endif; ?>
                                </td>
                                <td data-order="<?= strtotime((string) ($r['created_at'] ?? '')) ?: 0 ?>">
                                    <small class="meta-text">
                                        <?= date('d M Y', strtotime($r['created_at'])) ?>
                                    </small>
                                </td>
                                <td class="action-cell">
                                    <div class="action-row-nowrap">
                                        <button type="button" class="btn-secondary btn-sm action-icon-btn"
                                            title="Edit restoran"
                                            aria-label="Edit restoran"
                                            onclick="editRestaurant(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>', <?= $r['price_per_packet'] ?>, <?= $r['tax_percentage'] ?>, <?= $r['is_active'] ?>)">
                                            <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                        </button>
                                        <?php if ($r['is_active']): ?>
                                            <button type="button" class="btn-warning btn-sm action-icon-btn"
                                                title="Nonaktifkan restoran"
                                                aria-label="Nonaktifkan restoran"
                                                onclick="toggleStatus(<?= $r['id'] ?>, 0)">
                                                <?= ems_icon('lock-closed', 'h-4 w-4') ?>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn-success btn-sm action-icon-btn"
                                                title="Aktifkan restoran"
                                                aria-label="Aktifkan restoran"
                                                onclick="toggleStatus(<?= $r['id'] ?>, 1)">
                                                <?= ems_icon('lock-open', 'h-4 w-4') ?>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn-danger btn-sm action-icon-btn"
                                            title="Hapus restoran"
                                            aria-label="Hapus restoran"
                                            onclick="deleteRestaurant(<?= $r['id'] ?>)">
                                            <?= ems_icon('trash', 'h-4 w-4') ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>

<!-- =================================================
     MODAL EDIT RESTORAN
     ================================================= -->
<div id="editModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Edit Restoran</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="restaurant_settings_action.php?action=update" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="id" id="editId">

            <label>Nama Restoran</label>
            <input type="text" name="restaurant_name" id="editName" required>

            <div class="row-form-2">
                <div>
                    <label>Harga per Paket ($)</label>
                    <input type="number" name="price_per_packet" id="editPrice" step="0.01" min="0" required>
                </div>
                <div>
                    <label>Pajak (%)</label>
                    <input type="number" name="tax_percentage" id="editTax" step="0.01" min="0" max="100" required>
                </div>
            </div>

            <label class="checkbox-label">
                <input type="checkbox" name="is_active" id="editActive" value="1">
                <span>Aktif</span>
            </label>

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
    function editRestaurant(id, name, price, tax, active) {
        document.getElementById('editId').value = id;
        document.getElementById('editName').value = name;
        document.getElementById('editPrice').value = price;
        document.getElementById('editTax').value = tax;
        document.getElementById('editActive').checked = active === 1;

        const modal = document.getElementById('editModal');
        if (modal) modal.classList.remove('hidden');
        document.getElementById('editModal').style.display = 'flex';
        document.body.classList.add('modal-open');
    }

    function toggleStatus(id, status) {
        const action = status === 1 ? 'aktifkan' : 'nonaktifkan';
        if (!confirm('Yakin ingin ' + action + ' restoran ini?')) return;

        const formData = new FormData();
        formData.append('id', id);
        formData.append('is_active', status);

        fetch('restaurant_settings_action.php?action=toggle', {
            method: 'POST',
            body: formData
        }).then(() => location.reload());
    }

    function deleteRestaurant(id) {
        if (!confirm('Yakin ingin menghapus restoran ini? Data tidak bisa dikembalikan!')) return;

        const formData = new FormData();
        formData.append('id', id);

        fetch('restaurant_settings_action.php?action=delete', {
            method: 'POST',
            body: formData
        }).then(() => location.reload());
    }

    // Modal handler
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('editModal');

        document.body.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay') || e.target.closest('.btn-cancel')) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
