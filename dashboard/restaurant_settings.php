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
    echo '<div style="padding:40px;text-align:center;">
        <h3>🚫 Akses Ditolak</h3>
        <p>Anda tidak memiliki izin untuk mengakses halaman ini.</p>
        <a href="/dashboard/index.php" class="btn btn-secondary">Kembali</a>
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
    <div class="page" style="max-width:1000px;margin:auto;">

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <div>
                <h1>Restaurant Settings</h1>
                <p class="text-muted">Kelola daftar restoran dan harga per paket</p>
            </div>
            <a href="/dashboard/restaurant_consumption.php" class="btn btn-secondary">
                ← Kembali ke Konsumsi
            </a>
        </div>

        <!-- FLASH MESSAGES -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $msg): ?>
                <div class="alert alert-success" style="margin-bottom:15px;padding:12px;background:#dcfce7;color:#166534;border-radius:8px;">
                    ✅ <?= htmlspecialchars($msg) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error" style="margin-bottom:15px;padding:12px;background:#fee2e2;color:#991b1b;border-radius:8px;">
                    ❌ <?= htmlspecialchars($err) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- FORM TAMBAH RESTORAN -->
        <div class="card" style="margin-bottom:20px;">
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
                        <div style="display:flex;align-items:flex-end;">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" name="is_active" value="1" checked>
                                <span>Aktif</span>
                            </label>
                        </div>
                    </div>

                    <div style="margin-top:10px;">
                        <button type="submit" class="btn btn-success">
                            ➕ Tambah Restoran
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
                <table class="table-custom">
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
                                    <span style="color:#0369a1;font-weight:600;">
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
                                <td>
                                    <small style="color:#64748b;">
                                        <?= date('d M Y', strtotime($r['created_at'])) ?>
                                    </small>
                                </td>
                                <td style="white-space:nowrap;">
                                    <button class="btn-secondary btn-sm"
                                        onclick="editRestaurant(<?= $r['id'] ?>, '<?= htmlspecialchars($r['restaurant_name'], ENT_QUOTES) ?>', <?= $r['price_per_packet'] ?>, <?= $r['tax_percentage'] ?>, <?= $r['is_active'] ?>)">
                                        ✏️ Edit
                                    </button>
                                    <?php if ($r['is_active']): ?>
                                        <button class="btn-warning btn-sm"
                                            onclick="toggleStatus(<?= $r['id'] ?>, 0)">
                                            🔒 Nonaktifkan
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-success btn-sm"
                                            onclick="toggleStatus(<?= $r['id'] ?>, 1)">
                                            🔓 Aktifkan
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn-danger btn-sm"
                                        onclick="deleteRestaurant(<?= $r['id'] ?>)">
                                        🗑 Hapus
                                    </button>
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
<div id="editModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Edit Restoran</h3>

        <form method="POST" action="restaurant_settings_action.php?action=update" class="form">
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

            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="is_active" id="editActive" value="1">
                <span>Aktif</span>
            </label>

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-success">Simpan Perubahan</button>
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
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
