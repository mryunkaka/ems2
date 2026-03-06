<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

/* ===============================
   ROLE GUARD (NON-STAFF)
   =============================== */
$userRole = strtolower($_SESSION['user_rh']['role'] ?? '');
if ($userRole === 'staff') {
    http_response_code(403);
    die('Akses ditolak');
}

$user = $_SESSION['user_rh'] ?? [];
$medicName    = $user['name'] ?? '';
$medicJabatan = $user['position'] ?? '';

/* ===============================
   HANDLE UPDATE (AJAX)
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {

        /* ===== UPDATE PACKAGE ===== */
        if ($_POST['action'] === 'update_package') {

            $stmt = $pdo->prepare("
                UPDATE packages
                SET
                    name = ?,
                    bandage_qty = ?,
                    ifaks_qty = ?,
                    painkiller_qty = ?,
                    price = ?
                WHERE id = ?
            ");

            $stmt->execute([
                trim($_POST['name']),
                (int)$_POST['bandage_qty'],
                (int)$_POST['ifaks_qty'],
                (int)$_POST['painkiller_qty'],
                (int)$_POST['price'],
                (int)$_POST['id']
            ]);

            echo json_encode(['success' => true]);
            exit;
        }

        /* ===== UPDATE MEDICAL REGULATION ===== */
        if ($_POST['action'] === 'update_regulation') {

            $stmt = $pdo->prepare("
                UPDATE medical_regulations
                SET
                    category = ?,
                    name = ?,
                    location = ?,
                    price_type = ?,
                    price_min = ?,
                    price_max = ?,
                    payment_type = ?,
                    duration_minutes = ?,
                    notes = ?,
                    is_active = ?
                WHERE id = ?
            ");

            $stmt->execute([
                trim($_POST['category']),
                trim($_POST['name']),
                $_POST['location'] !== '' ? trim($_POST['location']) : null,
                $_POST['price_type'],
                (int)$_POST['price_min'],
                (int)$_POST['price_max'],
                $_POST['payment_type'],
                $_POST['duration_minutes'] !== '' ? (int)$_POST['duration_minutes'] : null,
                $_POST['notes'] !== '' ? trim($_POST['notes']) : null,
                isset($_POST['is_active']) ? 1 : 0,
                (int)$_POST['id']
            ]);

            echo json_encode(['success' => true]);
            exit;
        }

        throw new Exception('Aksi tidak valid');
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

/* ===============================
   LOAD DATA
   =============================== */
$packages = $pdo->query("
    SELECT id, name, bandage_qty, ifaks_qty, painkiller_qty, price
    FROM packages
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$regs = $pdo->query("
    SELECT
        id, category, code, name, location,
        price_type, price_min, price_max,
        payment_type, duration_minutes,
        notes, is_active
    FROM medical_regulations
    ORDER BY category, code
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page" style="max-width:1200px;margin:auto">

        <h1>Regulasi EMS</h1>
        <p class="text-muted">Manajemen paket & regulasi medis</p>

        <div id="ajaxAlert"></div>

        <!-- ================= PACKAGES ================= -->
        <div class="card">
            <div class="card-header">📦 Packages</div>

            <div class="table-wrapper">
                <table id="packageTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Bandage</th>
                            <th>Ifaks</th>
                            <th>Painkiller</th>
                            <th>Harga</th>
                            <th width="80">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $p): ?>
                            <tr
                                data-id="<?= $p['id'] ?>"
                                data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
                                data-bandage="<?= $p['bandage_qty'] ?>"
                                data-ifaks="<?= $p['ifaks_qty'] ?>"
                                data-painkiller="<?= $p['painkiller_qty'] ?>"
                                data-price="<?= $p['price'] ?>">
                                <td><?= htmlspecialchars($p['name']) ?></td>
                                <td><?= $p['bandage_qty'] ?></td>
                                <td><?= $p['ifaks_qty'] ?></td>
                                <td><?= $p['painkiller_qty'] ?></td>
                                <td>$<?= number_format($p['price']) ?></td>
                                <td><button class="btn-secondary btn-edit-package">Edit</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="regAlert"></div>

        <!-- ================= MEDICAL REGULATIONS ================= -->
        <div class="card">
            <div class="card-header">📜 Medical Regulations</div>

            <div class="table-wrapper">
                <table id="regTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Harga</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th width="80">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regs as $r): ?>
                            <tr
                                data-id="<?= $r['id'] ?>"
                                data-category="<?= htmlspecialchars($r['category'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"
                                data-location="<?= htmlspecialchars($r['location'] ?? '', ENT_QUOTES) ?>"
                                data-price_type="<?= $r['price_type'] ?>"
                                data-min="<?= $r['price_min'] ?>"
                                data-max="<?= $r['price_max'] ?>"
                                data-payment="<?= $r['payment_type'] ?>"
                                data-duration="<?= $r['duration_minutes'] ?>"
                                data-notes="<?= htmlspecialchars($r['notes'] ?? '', ENT_QUOTES) ?>"
                                data-active="<?= $r['is_active'] ?>">
                                <td><?= htmlspecialchars($r['category']) ?></td>
                                <td><?= htmlspecialchars($r['code']) ?></td>
                                <td><?= htmlspecialchars($r['name']) ?></td>
                                <td>
                                    <?= $r['price_type'] === 'FIXED'
                                        ? '$' . number_format($r['price_min'])
                                        : '$' . number_format($r['price_min']) . ' - $' . number_format($r['price_max']) ?>
                                </td>
                                <td><?= $r['payment_type'] ?></td>
                                <td><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></td>
                                <td><button class="btn-secondary btn-edit-reg">Edit</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>

<!-- ===============================
     MODAL EDIT PACKAGE
     =============================== -->
<div id="editPackageModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Edit Package</h3>

        <form id="editPackageForm" class="form">
            <input type="hidden" name="action" value="update_package">
            <input type="hidden" name="id" id="pkgId">

            <label>Nama</label>
            <input type="text" name="name" id="pkgName" required>

            <label>Bandage</label>
            <input type="number" name="bandage_qty" id="pkgBandage" min="0" required>

            <label>Ifaks</label>
            <input type="number" name="ifaks_qty" id="pkgIfaks" min="0" required>

            <label>Painkiller</label>
            <input type="number" name="painkiller_qty" id="pkgPainkiller" min="0" required>

            <label>Harga</label>
            <input type="number" name="price" id="pkgPrice" min="0" required>

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-success">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- ===============================
     MODAL EDIT REGULATION
     =============================== -->
<div id="editRegModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Edit Regulasi Medis</h3>

        <form id="editRegForm" class="form">
            <input type="hidden" name="action" value="update_regulation">
            <input type="hidden" name="id" id="regId">

            <label>Kategori</label>
            <input type="text" name="category" id="regCategory" required>

            <label>Nama</label>
            <input type="text" name="name" id="regName" required>

            <label>Lokasi</label>
            <input type="text" name="location" id="regLocation">

            <label>Price Type</label>
            <select name="price_type" id="regPriceType">
                <option value="FIXED">FIXED</option>
                <option value="RANGE">RANGE</option>
            </select>

            <label>Harga Min</label>
            <input type="number" name="price_min" id="regMin" min="0" required>

            <label>Harga Max</label>
            <input type="number" name="price_max" id="regMax" min="0" required>

            <label>Payment</label>
            <select name="payment_type" id="regPayment">
                <option value="CASH">CASH</option>
                <option value="INVOICE">INVOICE</option>
                <option value="BILLING">BILLING</option>
            </select>

            <label>Durasi (menit)</label>
            <input type="number" name="duration_minutes" id="regDuration">

            <label>Catatan</label>
            <textarea name="notes" id="regNotes"></textarea>

            <label>
                <input type="checkbox" name="is_active" id="regActive"> Aktif
            </label>

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        let activeRow = null;

        /* ===============================
           INIT DATATABLES (WAJIB DIPISAH)
           =============================== */
        const packageTable = jQuery('#packageTable').DataTable({
            pageLength: 10,
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
            }
        });

        const regTable = jQuery('#regTable').DataTable({
            pageLength: 10,
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
            }
        });

        /* ===============================
           OPEN PACKAGE MODAL
           =============================== */
        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-edit-package');
            if (!btn) return;

            const row = btn.closest('tr');
            activeRow = packageTable.row(row);

            pkgId.value = row.dataset.id;
            pkgName.value = row.dataset.name;
            pkgBandage.value = row.dataset.bandage;
            pkgIfaks.value = row.dataset.ifaks;
            pkgPainkiller.value = row.dataset.painkiller;
            pkgPrice.value = row.dataset.price;

            editPackageModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        /* ===============================
           OPEN REGULATION MODAL
           =============================== */
        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-edit-reg');
            if (!btn) return;

            const row = btn.closest('tr');
            activeRow = regTable.row(row);

            regId.value = row.dataset.id;
            regCategory.value = row.dataset.category;
            regName.value = row.dataset.name;
            regLocation.value = row.dataset.location || '';
            regPriceType.value = row.dataset.price_type;
            regMin.value = row.dataset.min;
            regMax.value = row.dataset.max;
            regPayment.value = row.dataset.payment;
            regDuration.value = row.dataset.duration || '';
            regNotes.value = row.dataset.notes || '';
            regActive.checked = row.dataset.active === '1';

            editRegModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        /* ===============================
           CLOSE MODAL
           =============================== */
        function closeModal() {
            editPackageModal.style.display = 'none';
            editRegModal.style.display = 'none';
            document.body.classList.remove('modal-open');
            activeRow = null;
        }

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        /* ===============================
           AJAX SUBMIT - PACKAGE
           =============================== */
        editPackageForm.addEventListener('submit', function(e) {
            e.preventDefault();

            fetch('regulasi.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json())
                .then(r => {

                    if (!r.success) {
                        showAlert('error', '❌ ' + (r.message || 'Gagal menyimpan data'));
                        return;
                    }

                    // Update dataset row
                    const node = activeRow.node();
                    node.dataset.id = pkgId.value;
                    node.dataset.name = pkgName.value;
                    node.dataset.bandage = pkgBandage.value;
                    node.dataset.ifaks = pkgIfaks.value;
                    node.dataset.painkiller = pkgPainkiller.value;
                    node.dataset.price = pkgPrice.value;

                    // Update DataTables display
                    activeRow.data([
                        pkgName.value,
                        pkgBandage.value,
                        pkgIfaks.value,
                        pkgPainkiller.value,
                        '$' + Number(pkgPrice.value).toLocaleString(),
                        '<button class="btn-secondary btn-edit-package">Edit</button>'
                    ]).draw(false);

                    showAlert('success', '✅ Data package berhasil diperbarui', 'ajaxAlert');
                    closeModal();
                })
                .catch(err => {
                    showAlert('error', '❌ Terjadi kesalahan: ' + err.message);
                });
        });

        /* ===============================
           AJAX SUBMIT - REGULATION
           =============================== */
        editRegForm.addEventListener('submit', function(e) {
            e.preventDefault();

            fetch('regulasi.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json())
                .then(r => {

                    if (!r.success) {
                        showAlert('error', '❌ ' + (r.message || 'Gagal menyimpan data'));
                        return;
                    }

                    // Update dataset row
                    const node = activeRow.node();
                    const currentData = activeRow.data();

                    node.dataset.id = regId.value;
                    node.dataset.category = regCategory.value;
                    node.dataset.name = regName.value;
                    node.dataset.location = regLocation.value;
                    node.dataset.price_type = regPriceType.value;
                    node.dataset.min = regMin.value;
                    node.dataset.max = regMax.value;
                    node.dataset.payment = regPayment.value;
                    node.dataset.duration = regDuration.value;
                    node.dataset.notes = regNotes.value;
                    node.dataset.active = regActive.checked ? '1' : '0';

                    // Format harga
                    const harga = regPriceType.value === 'FIXED' ?
                        '$' + Number(regMin.value).toLocaleString() :
                        '$' + Number(regMin.value).toLocaleString() +
                        ' - $' + Number(regMax.value).toLocaleString();

                    // Update DataTables display
                    activeRow.data([
                        regCategory.value,
                        currentData[1], // Keep original code
                        regName.value,
                        harga,
                        regPayment.value,
                        regActive.checked ? 'Aktif' : 'Nonaktif',
                        '<button class="btn-secondary btn-edit-reg">Edit</button>'
                    ]).draw(false);

                    showAlert('success', '✅ Data regulasi berhasil diperbarui', 'regAlert');

                    closeModal();
                })
                .catch(err => {
                    showAlert('error', '❌ Terjadi kesalahan: ' + err.message);
                });
        });
    });

    /* ===============================
       ALERT HANDLER (5 DETIK)
       =============================== */
    function showAlert(type, message, target = 'ajaxAlert') {
        const box = document.getElementById(target);
        if (!box) return;

        box.innerHTML = `
        <div class="alert alert-${type}">
            ${message}
        </div>
    `;

        setTimeout(() => {
            const alert = box.querySelector('.alert');
            if (alert) {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 600);
            }
        }, 5000);
    }
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>