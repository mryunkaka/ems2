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
| SET DEFAULT RANGE = MINGGU LALU (SEBELUM INCLUDE date_range.php)
|--------------------------------------------------------------------------
*/
if (!isset($_GET['range'])) {
    $_GET['range'] = 'week3';
}

require_once __DIR__ . '/../config/date_range.php';

/*
|--------------------------------------------------------------------------
| PAGE INFO
|--------------------------------------------------------------------------
*/
$pageTitle = 'Reimbursement';

/*
|--------------------------------------------------------------------------
| INCLUDE LAYOUT
|--------------------------------------------------------------------------
*/
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

/*
|--------------------------------------------------------------------------
| ROLE USER
|--------------------------------------------------------------------------
*/
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$userId   = (int)($_SESSION['user_rh']['id'] ?? 0);

$isDirector = in_array($userRole, ['vice director', 'director'], true);

$canPayReimbursement = $userRole !== 'staff';

/*
|--------------------------------------------------------------------------
| FILTER INPUT
|--------------------------------------------------------------------------
*/
$startDate = $_GET['from'] ?? '';
$endDate   = $_GET['to'] ?? '';

/*
|--------------------------------------------------------------------------
| QUERY DATA (AMAN ONLY_FULL_GROUP_BY)
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        r.reimbursement_code,
        MAX(r.billing_source_type) AS billing_source_type,
        MAX(r.billing_source_name) AS billing_source_name,
        MAX(r.item_name) AS item_name,
        MAX(r.status) AS status,
        MIN(r.created_at) AS created_at,
        SUM(r.amount) AS total_amount,
        MAX(r.receipt_file) AS receipt_file,
        MAX(r.paid_at) AS paid_at,
        MAX(u.full_name) AS paid_by_name,
        MAX(r.created_by) AS created_by_id,
        MAX(cby.full_name) AS created_by_name
    FROM reimbursements r
    LEFT JOIN user_rh u ON u.id = r.paid_by
    LEFT JOIN user_rh cby ON cby.id = r.created_by
    WHERE 1=1
";

$params = [];

// 📅 FILTER TANGGAL - gunakan rangeStart/rangeEnd dari date_range.php
$range = $_GET['range'] ?? 'week3';

if ($range !== 'custom') {
    $sql .= " AND DATE(r.created_at) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $rangeStart;
    $params[':end_date']   = $rangeEnd;
} elseif ($startDate && $endDate) {
    $sql .= " AND DATE(r.created_at) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $startDate;
    $params[':end_date']   = $endDate;
} else {
    // Jika custom tapi tidak ada tanggal, jangan filter apa-apa
}

$sql .= "
    GROUP BY reimbursement_code
    ORDER BY MIN(r.created_at) DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<section class="content">
    <div class="page" style="max-width:1200px;margin:auto;">

        <h1>Reimbursement</h1>
        <p class="text-muted">
            <?= htmlspecialchars($rangeLabel ?? '-') ?>
        </p>

        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">Filter Rentang Tanggal</div>
            <div class="card-body">
                <form method="get" id="filterForm" class="filter-bar">
                    <div class="filter-group">
                        <label>Rentang</label>
                        <select name="range" id="rangeSelect" class="form-control">
                            <option value="week1" <?= ($_GET['range'] ?? 'week3') === 'week1' ? 'selected' : '' ?>>
                                3 Minggu Lalu
                            </option>
                            <option value="week2" <?= ($_GET['range'] ?? 'week3') === 'week2' ? 'selected' : '' ?>>
                                2 Minggu Lalu
                            </option>
                            <option value="week3" <?= ($_GET['range'] ?? 'week3') === 'week3' ? 'selected' : '' ?>>
                                Minggu Lalu
                            </option>
                            <option value="week4" <?= ($_GET['range'] ?? 'week3') === 'week4' ? 'selected' : '' ?>>
                                Minggu Ini
                            </option>
                            <option value="custom" <?= ($_GET['range'] ?? 'week3') === 'custom' ? 'selected' : '' ?>>
                                Custom
                            </option>
                        </select>
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Awal</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($startDate) ?>" class="form-control">
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Akhir</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($endDate) ?>" class="form-control">
                    </div>
                    <div class="filter-group" style="align-self:flex-end;">
                        <button type="submit" class="btn btn-primary">Terapkan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"
                style="display:flex;justify-content:space-between;align-items:center;">
                <span>Daftar Reimbursement</span>
                <button id="btnAddReim" class="btn-success">
                    ➕ Input Reimbursement
                </button>
            </div>

            <div class="table-wrapper">
                <table id="reimTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tanggal</th>
                            <th>Kode</th>
                            <th>Sumber</th>
                            <th>Diajukan Oleh</th>
                            <th>Status</th>
                            <th>Bukti</th>
                            <th>Total</th>
                            <th>Dibayar Oleh</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <tr>
                                <!-- # -->
                                <td><?= $i + 1 ?></td>

                                <!-- TANGGAL PENGAJUAN -->
                                <td><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>

                                <!-- KODE -->
                                <td><?= htmlspecialchars($r['reimbursement_code']) ?></td>

                                <!-- SUMBER -->
                                <td>
                                    <div>
                                        <strong><?= ucfirst($r['billing_source_type']) ?> – <?= htmlspecialchars($r['billing_source_name']) ?></strong>
                                    </div>
                                    <?php if (!empty($r['item_name'])): ?>
                                        <small style="color:#64748b;">
                                            Item: <?= htmlspecialchars($r['item_name']) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <!-- DIAJUKAN OLEH -->
                                <td>
                                    <?= !empty($r['created_by_name']) ? htmlspecialchars($r['created_by_name']) : '<span style="color:#9ca3af;">-</span>' ?>
                                </td>

                                <!-- STATUS -->
                                <td>
                                    <span class="badge-status badge-<?= htmlspecialchars($r['status']) ?>">
                                        <?= strtoupper($r['status']) ?>
                                    </span>
                                </td>

                                <!-- BUKTI -->
                                <td>
                                    <?php if (!empty($r['receipt_file'])): ?>
                                        <a href="#"
                                            class="doc-badge btn-preview-doc"
                                            data-src="/<?= htmlspecialchars($r['receipt_file']) ?>"
                                            data-title="Bukti Pembayaran <?= htmlspecialchars($r['reimbursement_code']) ?>">
                                            📄 Bukti
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- TOTAL -->
                                <td><?= dollar((int)$r['total_amount']) ?></td>

                                <!-- DIBAYAR OLEH (NAMA + WAKTU) -->
                                <td>
                                    <?php if (!empty($r['paid_by_name'])): ?>
                                        <div style="display:flex;flex-direction:column;">
                                            <strong><?= htmlspecialchars($r['paid_by_name']) ?></strong>
                                            <?php if (!empty($r['paid_at'])): ?>
                                                <small style="color:#64748b;">
                                                    <?= date('d M Y H:i', strtotime($r['paid_at'])) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- AKSI -->
                                <td style="white-space:nowrap;">
                                    <?php if ($canPayReimbursement && $r['status'] === 'submitted'): ?>
                                        <button class="btn-success"
                                            onclick="payReimbursement('<?= htmlspecialchars($r['reimbursement_code']) ?>')">
                                            💰 Dibayarkan
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!empty($isDirector) && $isDirector): ?>
                                        <button class="btn-danger"
                                            onclick="deleteReimbursement('<?= htmlspecialchars($r['reimbursement_code']) ?>')">
                                            🗑 Hapus
                                        </button>
                                    <?php endif; ?>

                                    <?php if (
                                        ($userRole === 'staff' || $r['status'] !== 'submitted')
                                        && empty($isDirector)
                                    ): ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</section>
<script>
    function deleteReimbursement(code) {
        if (!confirm('Yakin hapus reimbursement ini? Data akan hilang permanen!')) return;

        fetch('reimbursement_delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'code=' + encodeURIComponent(code)
        }).then(() => location.reload());
    }
</script>

<!-- =================================================
     MODAL INPUT REIMBURSEMENT
     ================================================= -->
<div id="reimModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">

        <h3>Input Reimbursement</h3>

        <form method="POST"
            action="reimbursement_action.php"
            class="form"
            enctype="multipart/form-data">

            <input type="hidden"
                name="reimbursement_code"
                value="REIMB-<?= date('Ymd-His') ?>">

            <label>Sumber Tagihan</label>
            <select name="billing_source_type" required>
                <option value="instansi">Instansi</option>
                <option value="restoran">Restoran</option>
                <option value="toko">Toko</option>
                <option value="vendor">Vendor</option>
                <option value="lainnya">Lainnya</option>
            </select>

            <label>Nama Sumber</label>
            <input type="text" name="billing_source_name" placeholder="Contoh : Up And Atom, Queen Beach, Goverment, Dll" required>

            <label>Nama Item</label>
            <input type="text" name="item_name" placeholder="Contoh : Makanan & Minuman, Surat Keramaian" required>

            <div class="row-form-2">
                <div>
                    <label>Qty</label>
                    <input type="number" name="qty" value="1" min="1" required>
                </div>
                <div>
                    <label>Harga</label>
                    <input type="number" name="price" min="0" required>
                </div>
            </div>

            <!-- FILE UPLOAD STYLE (SETTING_AKUN) -->
            <div class="doc-upload-wrapper">
                <div class="doc-upload-header">
                    <label class="doc-label">Bukti Pembayaran</label>
                    <span class="badge-muted-mini">PNG / JPG</span>
                </div>

                <div class="doc-upload-input">
                    <label for="receipt_file" class="file-upload-label">
                        <span class="file-icon">📁</span>
                        <span class="file-text">
                            <strong>Pilih file</strong>
                            <small>PNG atau JPG</small>
                        </span>
                    </label>
                    <input type="file"
                        id="receipt_file"
                        name="receipt_file"
                        accept="image/png,image/jpeg"
                        style="display:none;">
                    <div class="file-selected-name" data-for="receipt_file"></div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-success">Simpan</button>
            </div>

        </form>
    </div>
</div>

<script>
    /* ===============================
       TOGGLE CUSTOM DATE FIELDS
   =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        const rangeSelect = document.getElementById('rangeSelect');
        const customFields = document.querySelectorAll('.filter-custom');

        function toggleCustom() {
            if (rangeSelect.value === 'custom') {
                customFields.forEach(el => el.style.display = 'block');
            } else {
                customFields.forEach(el => el.style.display = 'none');
            }
        }

        rangeSelect.addEventListener('change', toggleCustom);
        toggleCustom(); // initial load
    });
</script>

<script>
    /* ===============================
   DATATABLES
   =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#reimTable').DataTable({
                pageLength: 10,
                order: [
                    [5, 'desc']
                ],
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                }
            });
        }
    });

    /* ===============================
       FILE NAME DISPLAY (SETTING_AKUN STYLE)
       =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                const display = document.querySelector(
                    '.file-selected-name[data-for="' + this.id + '"]'
                );
                if (!display) return;

                if (this.files.length > 0) {
                    const f = this.files[0];
                    display.innerHTML = `
                    <span class="selected-file-info">
                        <strong>${f.name}</strong>
                        <small>${(f.size / 1024).toFixed(1)} KB</small>
                    </span>
                `;
                    display.style.display = 'flex';
                } else {
                    display.style.display = 'none';
                }
            });
        });
    });

    /* ===============================
       MODAL HANDLER
       =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('reimModal');
        const btnOpen = document.getElementById('btnAddReim');

        btnOpen.addEventListener('click', () => {
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
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

    /* ===============================
       PAY REIMBURSEMENT
       =============================== */
    function payReimbursement(code) {
        if (!confirm('Tandai reimbursement ini sebagai DIBAYARKAN?')) return;

        fetch('reimbursement_pay.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'code=' + encodeURIComponent(code)
        }).then(() => location.reload());
    }
</script>

<!-- ======================================
     MODAL PREVIEW BUKTI PEMBAYARAN
     ====================================== -->
<div id="docPreviewModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:900px;">

        <!-- HEADER -->
        <div class="modal-header">
            <strong id="docPreviewTitle">📄 Bukti Pembayaran</strong>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" class="zoom-control-btn" id="docZoomOut">➖</button>
                <button type="button" class="zoom-control-btn" id="docZoomIn">➕</button>
                <button type="button" class="zoom-control-btn" id="docZoomReset">🔄</button>
                <button type="button" onclick="closeDocModal()">✕</button>
            </div>
        </div>

        <!-- BODY -->
        <div class="modal-body"
            style="background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:60vh;">
            <img id="docPreviewImage"
                src=""
                alt="Bukti Pembayaran"
                style="max-width:100%;max-height:75vh;object-fit:contain;transition:transform 0.2s ease;">
        </div>

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('docPreviewModal');
        const img = document.getElementById('docPreviewImage');
        const title = document.getElementById('docPreviewTitle');

        let scale = 1;
        let currentSrc = '';

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-preview-doc');
            if (!btn) return;

            e.preventDefault();

            currentSrc = btn.dataset.src;
            img.src = currentSrc;
            title.textContent = btn.dataset.title || 'Bukti Pembayaran';

            scale = 1;
            img.style.transform = 'scale(1)';

            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        window.closeDocModal = function() {
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
            img.src = '';
            scale = 1;
        };

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeDocModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                closeDocModal();
            }
        });

        document.getElementById('docZoomIn').onclick = () => {
            scale = Math.min(scale + 0.2, 3);
            img.style.transform = `scale(${scale})`;
        };
        document.getElementById('docZoomOut').onclick = () => {
            scale = Math.max(scale - 0.2, 0.5);
            img.style.transform = `scale(${scale})`;
        };
        document.getElementById('docZoomReset').onclick = () => {
            scale = 1;
            img.style.transform = 'scale(1)';
        };
    });
</script>


<?php include __DIR__ . '/../partials/footer.php'; ?>