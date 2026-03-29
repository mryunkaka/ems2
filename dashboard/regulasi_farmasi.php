<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

// Block access for users on cuti
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

ems_require_not_trainee_html('Regulasi Paket Farmasi');

/* ===============================
   ROLE GUARD (NON-STAFF)
   =============================== */
$userRole = strtolower($_SESSION['user_rh']['role'] ?? '');
if ($userRole === 'staff') {
    http_response_code(403);
    die('Akses ditolak');
}
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$packagesHasUnitCode = ems_column_exists($pdo, 'packages', 'unit_code');

$pageTitle = 'Regulasi Paket Farmasi';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_package') {
    header('Content-Type: application/json');

    try {
        $sql = "
            UPDATE packages
            SET
                name = ?,
                bandage_qty = ?,
                ifaks_qty = ?,
                painkiller_qty = ?,
                price = ?
            WHERE id = ?
            " . ($packagesHasUnitCode ? " AND COALESCE(unit_code, 'roxwood') = ?" : "") . "
        ";

        $params = [
            trim((string)($_POST['name'] ?? '')),
            (int)($_POST['bandage_qty'] ?? 0),
            (int)($_POST['ifaks_qty'] ?? 0),
            (int)($_POST['painkiller_qty'] ?? 0),
            (int)($_POST['price'] ?? 0),
            (int)($_POST['id'] ?? 0),
        ];
        if ($packagesHasUnitCode) {
            $params[] = $effectiveUnit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_package') {
    header('Content-Type: application/json');

    try {
        $name = trim((string)($_POST['name'] ?? ''));
        $bandageQty = (int)($_POST['bandage_qty'] ?? 0);
        $ifaksQty = (int)($_POST['ifaks_qty'] ?? 0);
        $painkillerQty = (int)($_POST['painkiller_qty'] ?? 0);
        $price = (int)($_POST['price'] ?? 0);

        if ($name === '') {
            throw new RuntimeException('Nama paket wajib diisi.');
        }

        $sql = "
            INSERT INTO packages (
                name,
                bandage_qty,
                ifaks_qty,
                painkiller_qty,
                price
                " . ($packagesHasUnitCode ? ", unit_code" : "") . "
            ) VALUES (
                ?, ?, ?, ?, ?
                " . ($packagesHasUnitCode ? ", ?" : "") . "
            )
        ";

        $params = [$name, $bandageQty, $ifaksQty, $painkillerQty, $price];
        if ($packagesHasUnitCode) {
            $params[] = $effectiveUnit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'item' => [
                'id' => (int)$pdo->lastInsertId(),
                'name' => $name,
                'bandage_qty' => $bandageQty,
                'ifaks_qty' => $ifaksQty,
                'painkiller_qty' => $painkillerQty,
                'price' => $price,
            ],
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ===============================
   LOAD PACKAGES
   =============================== */
$packagesStmt = $pdo->prepare("
    SELECT id, name, bandage_qty, ifaks_qty, painkiller_qty, price
    FROM packages
    " . ($packagesHasUnitCode ? "WHERE COALESCE(unit_code, 'roxwood') = :unit_code" : "") . "
    ORDER BY name
");
$packagesStmt->execute($packagesHasUnitCode ? [':unit_code' => $effectiveUnit] : []);
$packages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Regulasi Paket Farmasi</h1>
        <p class="page-subtitle">Manajemen paket farmasi</p>

        <div id="ajaxAlert"></div>

        <div class="card">
            <div class="card-header card-header-actions card-header-flex">
                <div class="card-header-actions-title">
                    <?= ems_icon('beaker', 'h-5 w-5') ?> Paket Farmasi
                </div>
                <button type="button" id="openAddPackageModal" class="btn-success">
                    <?= ems_icon('plus', 'h-4 w-4') ?> <span>Tambah Regulasi Baru</span>
                </button>
            </div>

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
                                <td><?= (int)$p['bandage_qty'] ?></td>
                                <td><?= (int)$p['ifaks_qty'] ?></td>
                                <td><?= (int)$p['painkiller_qty'] ?></td>
                                <td>$<?= number_format((int)$p['price']) ?></td>
                                <td>
                                    <button type="button" class="btn-secondary btn-edit-package">Ubah</button>
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
     MODAL EDIT PACKAGE
     =============================== -->
<div id="editPackageModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Ubah Paket Farmasi</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form id="editPackageForm" class="form modal-form">
            <div class="modal-content">
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

<div id="addPackageModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Tambah Paket Farmasi</div>
            <button type="button" class="modal-close-btn btn-add-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form id="addPackageForm" class="form modal-form">
            <div class="modal-content">
                <input type="hidden" name="action" value="create_package">

                <label>Nama</label>
                <input type="text" name="name" id="addPkgName" required>

                <label>Bandage</label>
                <input type="number" name="bandage_qty" id="addPkgBandage" min="0" value="0" required>

                <label>Ifaks</label>
                <input type="number" name="ifaks_qty" id="addPkgIfaks" min="0" value="0" required>

                <label>Painkiller</label>
                <input type="number" name="painkiller_qty" id="addPkgPainkiller" min="0" value="0" required>

                <label>Harga</label>
                <input type="number" name="price" id="addPkgPrice" min="0" value="0" required>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-add-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (!(window.jQuery && jQuery.fn.DataTable)) return;

        const packageTable = jQuery('#packageTable').DataTable({
            pageLength: 10,
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });

        const editPackageModal = document.getElementById('editPackageModal');
        const editPackageForm = document.getElementById('editPackageForm');
        const addPackageModal = document.getElementById('addPackageModal');
        const addPackageForm = document.getElementById('addPackageForm');
        const openAddPackageModalBtn = document.getElementById('openAddPackageModal');

        const pkgId = document.getElementById('pkgId');
        const pkgName = document.getElementById('pkgName');
        const pkgBandage = document.getElementById('pkgBandage');
        const pkgIfaks = document.getElementById('pkgIfaks');
        const pkgPainkiller = document.getElementById('pkgPainkiller');
        const pkgPrice = document.getElementById('pkgPrice');
        const addPkgName = document.getElementById('addPkgName');
        const addPkgBandage = document.getElementById('addPkgBandage');
        const addPkgIfaks = document.getElementById('addPkgIfaks');
        const addPkgPainkiller = document.getElementById('addPkgPainkiller');
        const addPkgPrice = document.getElementById('addPkgPrice');

        let activeRow = null;

        function openModal(modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        }

        function closeModal(modal) {
            modal.style.display = 'none';
            modal.classList.add('hidden');
            document.body.classList.remove('modal-open');
        }

        editPackageModal.addEventListener('click', (e) => {
            if (e.target === editPackageModal) closeModal(editPackageModal);
        });
        addPackageModal.addEventListener('click', (e) => {
            if (e.target === addPackageModal) closeModal(addPackageModal);
        });

        editPackageModal.querySelectorAll('.btn-cancel, .modal-close-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                closeModal(editPackageModal);
            });
        });
        addPackageModal.querySelectorAll('.btn-add-cancel, .modal-close-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                closeModal(addPackageModal);
            });
        });

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

            openModal(editPackageModal);
        });

        openAddPackageModalBtn?.addEventListener('click', function() {
            addPackageForm.reset();
            addPkgBandage.value = '0';
            addPkgIfaks.value = '0';
            addPkgPainkiller.value = '0';
            addPkgPrice.value = '0';
            openModal(addPackageModal);
        });

        editPackageForm.addEventListener('submit', function(e) {
            e.preventDefault();

            fetch('regulasi_farmasi.php', {
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
                    node.dataset.name = pkgName.value;
                    node.dataset.bandage = pkgBandage.value;
                    node.dataset.ifaks = pkgIfaks.value;
                    node.dataset.painkiller = pkgPainkiller.value;
                    node.dataset.price = pkgPrice.value;

                    activeRow.data([
                        pkgName.value,
                        pkgBandage.value,
                        pkgIfaks.value,
                        pkgPainkiller.value,
                        '$' + Number(pkgPrice.value).toLocaleString(),
                        '<button type="button" class="btn-secondary btn-edit-package">Ubah</button>'
                    ]).draw(false);

                    showAlert('success', 'Paket berhasil diperbarui');
                    closeModal(editPackageModal);
                })
                .catch(err => showAlert('error', 'Terjadi kesalahan: ' + err.message));
        });

        addPackageForm.addEventListener('submit', function(e) {
            e.preventDefault();

            fetch('regulasi_farmasi.php', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(r => r.json())
                .then(r => {
                    if (!r.success) {
                        showAlert('error', r.message || 'Gagal menambah data');
                        return;
                    }

                    const item = r.item || {};
                    const rowNode = packageTable.row.add([
                        item.name,
                        Number(item.bandage_qty || 0),
                        Number(item.ifaks_qty || 0),
                        Number(item.painkiller_qty || 0),
                        '$' + Number(item.price || 0).toLocaleString(),
                        '<button type="button" class="btn-secondary btn-edit-package">Ubah</button>'
                    ]).draw(false).node();

                    rowNode.dataset.id = item.id || '';
                    rowNode.dataset.name = item.name || '';
                    rowNode.dataset.bandage = Number(item.bandage_qty || 0);
                    rowNode.dataset.ifaks = Number(item.ifaks_qty || 0);
                    rowNode.dataset.painkiller = Number(item.painkiller_qty || 0);
                    rowNode.dataset.price = Number(item.price || 0);

                    showAlert('success', 'Paket baru berhasil ditambahkan');
                    closeModal(addPackageModal);
                })
                .catch(err => showAlert('error', 'Terjadi kesalahan: ' + err.message));
        });
    });

    function showAlert(type, message) {
        const box = document.getElementById('ajaxAlert');
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
