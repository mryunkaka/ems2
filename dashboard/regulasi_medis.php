<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

// Block access for users on cuti
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

/* ===============================
   ROLE GUARD (NON-STAFF)
   =============================== */
$userRole = strtolower($_SESSION['user_rh']['role'] ?? '');
if ($userRole === 'staff') {
    http_response_code(403);
    die('Akses ditolak');
}

$pageTitle = 'Regulasi Medis';

/* ===============================
   LOAD MEDICAL REGULATIONS
   =============================== */
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
    <div class="page page-shell">
        <h1 class="page-title">Regulasi Medis</h1>
        <p class="page-subtitle">Manajemen regulasi layanan medis</p>

        <div id="regAlert"></div>

        <div class="card">
            <div class="card-header">
                <?= ems_icon('document-text', 'h-5 w-5') ?> Regulasi Medis
            </div>

            <div class="table-wrapper">
                <table id="regTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Kategori</th>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Harga</th>
                            <th>Pembayaran</th>
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
                                        ? '$' . number_format((int)$r['price_min'])
                                        : '$' . number_format((int)$r['price_min']) . ' - $' . number_format((int)$r['price_max']) ?>
                                </td>
                                <td><?= htmlspecialchars($r['payment_type']) ?></td>
                                <td><?= $r['is_active'] ? 'Aktif' : 'Nonaktif' ?></td>
                                <td>
                                    <button type="button" class="btn-secondary btn-edit-reg">Ubah</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- ===============================
     MODAL EDIT REGULATION
     =============================== -->
<div id="editRegModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Ubah Regulasi Medis</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form id="editRegForm" class="form modal-form">
            <div class="modal-content">
                <input type="hidden" name="action" value="update_regulation">
                <input type="hidden" name="id" id="regId">

                <label>Kategori</label>
                <input type="text" name="category" id="regCategory" required>

                <label>Nama</label>
                <input type="text" name="name" id="regName" required>

                <label>Lokasi</label>
                <input type="text" name="location" id="regLocation">

                <label>Tipe Harga</label>
                <select name="price_type" id="regPriceType">
                    <option value="FIXED">FIXED</option>
                    <option value="RANGE">RANGE</option>
                </select>

                <label>Harga Min</label>
                <input type="number" name="price_min" id="regMin" min="0" required>

                <label>Harga Max</label>
                <input type="number" name="price_max" id="regMax" min="0" required>

                <label>Pembayaran</label>
                <select name="payment_type" id="regPayment">
                    <option value="CASH">CASH</option>
                    <option value="INVOICE">INVOICE</option>
                    <option value="BILLING">BILLING</option>
                </select>

                <label>Durasi (menit)</label>
                <input type="number" name="duration_minutes" id="regDuration">

                <label>Catatan</label>
                <textarea name="notes" id="regNotes"></textarea>

                <label class="checkbox-label checkbox-pill">
                    <input type="checkbox" name="is_active" id="regActive"> Aktif
                </label>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (!(window.jQuery && jQuery.fn.DataTable)) return;

        const regTable = jQuery('#regTable').DataTable({
            pageLength: 10,
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });

        const editRegModal = document.getElementById('editRegModal');
        const editRegForm = document.getElementById('editRegForm');

        const regId = document.getElementById('regId');
        const regCategory = document.getElementById('regCategory');
        const regName = document.getElementById('regName');
        const regLocation = document.getElementById('regLocation');
        const regPriceType = document.getElementById('regPriceType');
        const regMin = document.getElementById('regMin');
        const regMax = document.getElementById('regMax');
        const regPayment = document.getElementById('regPayment');
        const regDuration = document.getElementById('regDuration');
        const regNotes = document.getElementById('regNotes');
        const regActive = document.getElementById('regActive');

        let activeRow = null;

        function openModal() {
            editRegModal.classList.remove('hidden');
            editRegModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        }

        function closeModal() {
            editRegModal.style.display = 'none';
            editRegModal.classList.add('hidden');
            document.body.classList.remove('modal-open');
        }

        editRegModal.querySelectorAll('.btn-cancel, .modal-close-btn').forEach(btn => {
            btn.addEventListener('click', closeModal);
        });

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

            openModal();
        });

        editRegForm.addEventListener('submit', function(e) {
            e.preventDefault();

            fetch('regulasi.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json())
                .then(r => {
                    if (!r.success && r.success !== undefined) {
                        showAlert('error', r.message || 'Gagal menyimpan data');
                        return;
                    }

                    const node = activeRow.node();
                    const currentData = activeRow.data();

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

                    const harga = regPriceType.value === 'FIXED' ?
                        '$' + Number(regMin.value).toLocaleString() :
                        '$' + Number(regMin.value).toLocaleString() + ' - $' + Number(regMax.value).toLocaleString();

                    activeRow.data([
                        regCategory.value,
                        currentData[1],
                        regName.value,
                        harga,
                        regPayment.value,
                        regActive.checked ? 'Aktif' : 'Nonaktif',
                        '<button type="button" class="btn-secondary btn-edit-reg">Ubah</button>'
                    ]).draw(false);

                    showAlert('success', 'Data regulasi berhasil diperbarui');
                    closeModal();
                })
                .catch(err => showAlert('error', 'Terjadi kesalahan: ' + err.message));
        });
    });

    function showAlert(type, message) {
        const box = document.getElementById('regAlert');
        if (!box) return;
        box.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
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
