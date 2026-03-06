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
    $_GET['range'] = 'week4';
}

require_once __DIR__ . '/../config/date_range.php';

/*
|--------------------------------------------------------------------------
| PAGE INFO
|--------------------------------------------------------------------------
*/
$pageTitle = 'Restaurant Consumption';

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
$userName = $_SESSION['user_rh']['name'] ?? '';

$isDirector = in_array($userRole, ['vice director', 'director'], true);
$canManage = !in_array($userRole, ['staff', 'manager'], true);

/*
|--------------------------------------------------------------------------
| AMBIL DATA RESTAURAN SETTINGS
|--------------------------------------------------------------------------
*/
$stmtResto = $pdo->query("
    SELECT id, restaurant_name, price_per_packet, tax_percentage, is_active
    FROM restaurant_settings
    WHERE is_active = 1
    ORDER BY restaurant_name ASC
");
$restaurants = $stmtResto->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| FILTER INPUT
|--------------------------------------------------------------------------
*/
$startDate = $_GET['from'] ?? '';
$endDate   = $_GET['to'] ?? '';

/*
|--------------------------------------------------------------------------
| QUERY DATA
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        rc.*,
        u1.full_name AS created_by_name,
        u2.full_name AS approved_by_name,
        u3.full_name AS paid_by_name
    FROM restaurant_consumptions rc
    LEFT JOIN user_rh u1 ON u1.id = rc.created_by
    LEFT JOIN user_rh u2 ON u2.id = rc.approved_by
    LEFT JOIN user_rh u3 ON u3.id = rc.paid_by
    WHERE 1=1
";

$params = [];

// 📅 FILTER TANGGAL
$range = $_GET['range'] ?? 'week4';

if ($range !== 'custom') {
    $sql .= " AND DATE(rc.delivery_date) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $rangeStart;
    $params[':end_date']   = $rangeEnd;
} elseif ($startDate && $endDate) {
    $sql .= " AND DATE(rc.delivery_date) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $startDate;
    $params[':end_date']   = $endDate;
}

$sql .= " ORDER BY rc.delivery_date DESC, rc.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| HITUNG TOTAL STATISTIK
|--------------------------------------------------------------------------
*/
$sqlTotal = "
    SELECT
        COUNT(*) AS total_record,
        SUM(packet_count) AS total_packets,
        SUM(subtotal) AS total_subtotal,
        SUM(tax_amount) AS total_tax,
        SUM(total_amount) AS total_grand
    FROM restaurant_consumptions rc
    WHERE 1=1
";

$paramsTotal = [];

if ($range !== 'custom') {
    $sqlTotal .= " AND DATE(rc.delivery_date) BETWEEN :start_date AND :end_date";
    $paramsTotal[':start_date'] = $rangeStart;
    $paramsTotal[':end_date']   = $rangeEnd;
} elseif ($startDate && $endDate) {
    $sqlTotal .= " AND DATE(rc.delivery_date) BETWEEN :start_date AND :end_date";
    $paramsTotal[':start_date'] = $startDate;
    $paramsTotal[':end_date']   = $endDate;
}

$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute($paramsTotal);
$stats = $stmtTotal->fetch(PDO::FETCH_ASSOC);

?>

<section class="content">
    <div class="page" style="max-width:1400px;margin:auto;">

        <h1>Restaurant Consumption</h1>
        <p class="text-muted">
            <?= htmlspecialchars($rangeLabel ?? '-') ?>
        </p>

        <!-- STATISTIK -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:20px;">
            <div class="card" style="margin:0;">
                <div class="card-body" style="text-align:center;">
                    <small style="color:#64748b;">Total Paket</small>
                    <div style="font-size:24px;font-weight:700;color:#0f766e;">
                        <?= number_format($stats['total_packets'] ?? 0, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
            <div class="card" style="margin:0;">
                <div class="card-body" style="text-align:center;">
                    <small style="color:#64748b;">Subtotal</small>
                    <div style="font-size:20px;font-weight:700;color:#0369a1;">
                        $<?= number_format($stats['total_subtotal'] ?? 0, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
            <div class="card" style="margin:0;">
                <div class="card-body" style="text-align:center;">
                    <small style="color:#64748b;">Pajak (5%)</small>
                    <div style="font-size:20px;font-weight:700;color:#b45309;">
                        $<?= number_format($stats['total_tax'] ?? 0, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
            <div class="card" style="margin:0;">
                <div class="card-body" style="text-align:center;">
                    <small style="color:#64748b;">Grand Total</small>
                    <div style="font-size:24px;font-weight:700;color:#047857;">
                        $<?= number_format($stats['total_grand'] ?? 0, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">Filter Rentang Tanggal</div>
            <div class="card-body">
                <form method="get" id="filterForm" class="filter-bar">
                    <div class="filter-group">
                        <label>Rentang</label>
                        <select name="range" id="rangeSelect" class="form-control">
                            <option value="week1" <?= ($_GET['range'] ?? 'week4') === 'week1' ? 'selected' : '' ?>>
                                3 Minggu Lalu
                            </option>
                            <option value="week2" <?= ($_GET['range'] ?? 'week4') === 'week2' ? 'selected' : '' ?>>
                                2 Minggu Lalu
                            </option>
                            <option value="week3" <?= ($_GET['range'] ?? 'week4') === 'week3' ? 'selected' : '' ?>>
                                Minggu Lalu
                            </option>
                            <option value="week4" <?= ($_GET['range'] ?? 'week4') === 'week4' ? 'selected' : '' ?>>
                                Minggu Ini
                            </option>
                            <option value="month1" <?= ($_GET['range'] ?? 'week4') === 'month1' ? 'selected' : '' ?>>
                                Bulan Ini
                            </option>
                            <option value="custom" <?= ($_GET['range'] ?? 'week4') === 'custom' ? 'selected' : '' ?>>
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
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:nowrap;gap:15px;">
                <span style="white-space:nowrap;min-width:fit-content;">Daftar Konsumsi Restoran</span>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button id="btnAddConsumption" class="btn-success">
                        ➕ Input Konsumsi
                    </button>
                    <?php if ($canManage): ?>
                        <button id="btnManageResto" class="btn-secondary">
                            ⚙️ Setting Restoran
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-wrapper">
                <table id="consumptionTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Kode</th>
                            <th>Tanggal & Jam</th>
                            <th>Restoran</th>
                            <th>Penerima</th>
                            <th>Paket</th>
                            <th>Harga/Paket</th>
                            <th>Subtotal</th>
                            <th>Pajak</th>
                            <th>Total</th>
                            <th>KTP</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <tr>
                                <!-- # -->
                                <td><?= $i + 1 ?></td>

                                <!-- KODE -->
                                <td>
                                    <small><?= htmlspecialchars($r['consumption_code']) ?></small>
                                </td>

                                <!-- TANGGAL & JAM -->
                                <td>
                                    <?php
                                    $daysIndonesian = [
                                        'Monday' => 'Senin',
                                        'Tuesday' => 'Selasa',
                                        'Wednesday' => 'Rabu',
                                        'Thursday' => 'Kamis',
                                        'Friday' => 'Jumat',
                                        'Saturday' => 'Sabtu',
                                        'Sunday' => 'Minggu'
                                    ];
                                    $dayEnglish = date('l', strtotime($r['delivery_date']));
                                    $dayIndo = $daysIndonesian[$dayEnglish] ?? $dayEnglish;
                                    $dateFormatted = date('d M Y', strtotime($r['delivery_date']));
                                    ?>
                                    <div><strong><?= $dayIndo ?></strong>, <?= $dateFormatted ?></div>
                                    <small style="color:#64748b;"><?= date('H:i', strtotime($r['delivery_time'])) ?></small>
                                </td>

                                <!-- RESTORAN -->
                                <td>
                                    <strong><?= htmlspecialchars($r['restaurant_name']) ?></strong>
                                </td>

                                <!-- PENERIMA -->
                                <td>
                                    <div><?= htmlspecialchars($r['recipient_name']) ?></div>
                                    <small style="color:#64748b;">Diajukan oleh: <?= htmlspecialchars($r['created_by_name']) ?></small>
                                </td>

                                <!-- PAKET -->
                                <td style="text-align:center;">
                                    <strong><?= number_format($r['packet_count'], 0, ',', '.') ?></strong> pkt
                                </td>

                                <!-- HARGA/PAKET -->
                                <td>$<?= number_format($r['price_per_packet'], 0, ',', '.') ?></td>

                                <!-- SUBTOTAL -->
                                <td>$<?= number_format($r['subtotal'], 0, ',', '.') ?></td>

                                <!-- PAJAK -->
                                <td>
                                    <small style="color:#64748b;"><?= number_format($r['tax_percentage'], 0) ?>%</small><br>
                                    $<?= number_format($r['tax_amount'], 0, ',', '.') ?>
                                </td>

                                <!-- TOTAL -->
                                <td style="font-weight:700;color:#047857;">
                                    $<?= number_format($r['total_amount'], 0, ',', '.') ?>
                                </td>

                                <!-- KTP -->
                                <td>
                                    <?php if (!empty($r['ktp_file'])): ?>
                                        <a href="#"
                                            class="doc-badge btn-preview-doc"
                                            data-src="/<?= htmlspecialchars($r['ktp_file']) ?>"
                                            data-title="KTP - <?= htmlspecialchars($r['restaurant_name']) ?>">
                                            📄 Lihat
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>

                                <!-- STATUS -->
                                <td>
                                    <div style="display:flex;flex-direction:column;gap:6px;">
                                        <span class="badge-status badge-<?= htmlspecialchars($r['status']) ?>">
                                            <?= strtoupper(htmlspecialchars($r['status'])) ?>
                                        </span>

                                        <?php if (!empty($r['approved_by_name']) && !empty($r['approved_at'])): ?>
                                            <?php
                                            $daysIndonesian = [
                                                'Monday' => 'Senin',
                                                'Tuesday' => 'Selasa',
                                                'Wednesday' => 'Rabu',
                                                'Thursday' => 'Kamis',
                                                'Friday' => 'Jumat',
                                                'Saturday' => 'Sabtu',
                                                'Sunday' => 'Minggu'
                                            ];
                                            $approvedDayEnglish = date('l', strtotime($r['approved_at']));
                                            $approvedDayIndo = $daysIndonesian[$approvedDayEnglish] ?? $approvedDayEnglish;
                                            $approvedDateFormatted = date('d M Y H:i', strtotime($r['approved_at']));
                                            ?>
                                            <small style="color:#059669;font-size:11px;">
                                                ✅ Approved by: <strong><?= htmlspecialchars($r['approved_by_name']) ?></strong><br>
                                                <span style="color:#64748b;"><?= $approvedDayIndo ?>, <?= $approvedDateFormatted ?></span>
                                            </small>
                                        <?php endif; ?>

                                        <?php if (!empty($r['paid_by_name']) && !empty($r['paid_at'])): ?>
                                            <?php
                                            $daysIndonesian = [
                                                'Monday' => 'Senin',
                                                'Tuesday' => 'Selasa',
                                                'Wednesday' => 'Rabu',
                                                'Thursday' => 'Kamis',
                                                'Friday' => 'Jumat',
                                                'Saturday' => 'Sabtu',
                                                'Sunday' => 'Minggu'
                                            ];
                                            $paidDayEnglish = date('l', strtotime($r['paid_at']));
                                            $paidDayIndo = $daysIndonesian[$paidDayEnglish] ?? $paidDayEnglish;
                                            $paidDateFormatted = date('d M Y H:i', strtotime($r['paid_at']));
                                            ?>
                                            <small style="color:#0369a1;font-size:11px;">
                                                💰 Paid by: <strong><?= htmlspecialchars($r['paid_by_name']) ?></strong><br>
                                                <span style="color:#64748b;"><?= $paidDayIndo ?>, <?= $paidDateFormatted ?></span>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- AKSI -->
                                <td style="white-space:nowrap;min-width:200px;">
                                    <div style="display:flex;gap:5px;flex-wrap:nowrap;">
                                        <?php if ($canManage && $r['status'] === 'pending'): ?>
                                            <button class="btn-success"
                                                onclick="approveConsumption(<?= $r['id'] ?>)"
                                                style="min-width:80px;padding:6px 10px;font-size:12px;">
                                                ✅ Approve
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($canManage && $r['status'] === 'approved'): ?>
                                            <button class="btn-primary"
                                                onclick="markPaid(<?= $r['id'] ?>)"
                                                style="min-width:80px;padding:6px 10px;font-size:12px;">
                                                💰 Paid
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!empty($isDirector) && $isDirector): ?>
                                            <button class="btn-danger"
                                                onclick="deleteConsumption(<?= $r['id'] ?>)"
                                                style="min-width:80px;padding:6px 10px;font-size:12px;">
                                                🗑️ Delete
                                            </button>
                                        <?php endif; ?>
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

<script>
    function deleteConsumption(id) {
        if (!confirm('Yakin hapus data konsumsi ini? Data akan hilang permanen!')) return;

        fetch('restaurant_consumption_action.php?action=delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'id=' + encodeURIComponent(id)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Data berhasil dihapus!');
                    location.reload();
                } else {
                    alert('❌ ' + (data.message || 'Gagal menghapus data'));
                }
            })
            .catch(err => {
                alert('❌ Terjadi kesalahan: ' + err.message);
            });
    }

    function approveConsumption(id) {
        if (!confirm('Setujui konsumsi ini?')) return;

        fetch('restaurant_consumption_action.php?action=approve', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'id=' + encodeURIComponent(id)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Konsumsi berhasil disetujui!');
                    location.reload();
                } else {
                    alert('❌ ' + (data.message || 'Gagal menyetujui'));
                }
            })
            .catch(err => {
                alert('❌ Terjadi kesalahan: ' + err.message);
            });
    }

    function markPaid(id) {
        if (!confirm('Tandai sebagai DIBAYAR?')) return;

        fetch('restaurant_consumption_action.php?action=paid', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'id=' + encodeURIComponent(id)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Konsumsi berhasil ditandai LUNAS!');
                    location.reload();
                } else {
                    alert('❌ ' + (data.message || 'Gagal memperbarui status'));
                }
            })
            .catch(err => {
                alert('❌ Terjadi kesalahan: ' + err.message);
            });
    }
</script>

<!-- =================================================
     MODAL INPUT KONSUMSI
     ================================================= -->
<div id="consumptionModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">

        <h3>Input Konsumsi Restoran</h3>

        <form method="POST"
            action="restaurant_consumption_action.php?action=create"
            class="form"
            enctype="multipart/form-data"
            id="consumptionForm">

            <input type="hidden" name="consumption_code" value="CONS-<?= date('Ymd-His') ?>">
            <input type="hidden" name="recipient_user_id" value="<?= $userId ?>">
            <input type="hidden" name="recipient_name" value="<?= htmlspecialchars($userName) ?>">

            <label>Pilih Restoran</label>
            <select name="restaurant_id" id="restaurantSelect" required onchange="updatePriceInfo()">
                <option value="">-- Pilih Restoran --</option>
                <?php foreach ($restaurants as $r): ?>
                    <option value="<?= $r['id'] ?>"
                        data-price="<?= $r['price_per_packet'] ?>"
                        data-tax="<?= $r['tax_percentage'] ?>">
                        <?= htmlspecialchars($r['restaurant_name']) ?> ($<?= number_format($r['price_per_packet'], 0, ',', '.') ?>/pkt)
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Tanggal & Jam Pengiriman</label>
            <div style="display:flex;gap:12px;align-items:center;background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
                <div style="flex:1;">
                    <small style="color:#64748b;display:block;margin-bottom:4px;">Tanggal</small>
                    <input type="date" name="delivery_date" required value="<?= date('Y-m-d') ?>"
                        style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;background:#fff;">
                </div>
                <div style="flex:1;">
                    <small style="color:#64748b;display:block;margin-bottom:4px;">Jam</small>
                    <input type="time" name="delivery_time" required value="<?= date('H:i') ?>"
                        style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;background:#fff;">
                </div>
            </div>

            <label>Jumlah Paket</label>
            <input type="number" name="packet_count" id="packetCount" min="1" required oninput="calculateTotal()">

            <!-- INFO HARGA (READONLY) -->
            <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-bottom:15px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                    <span>Harga per Paket:</span>
                    <strong id="displayPrice">$0</strong>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                    <span>Subtotal:</span>
                    <span id="displaySubtotal">$0</span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                    <span>Pajak (<span id="displayTaxPercent">0</span>%):</span>
                    <span id="displayTax">$0</span>
                </div>
                <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:8px;margin-top:8px;">
                    <strong>TOTAL:</strong>
                    <strong id="displayTotal" style="color:#047857;font-size:16px;">$0</strong>
                </div>
            </div>

            <!-- HIDDEN FIELDS FOR CALCULATION -->
            <input type="hidden" name="price_per_packet" id="inputPrice" value="0">
            <input type="hidden" name="tax_percentage" id="inputTax" value="0">
            <input type="hidden" name="subtotal" id="inputSubtotal" value="0">
            <input type="hidden" name="tax_amount" id="inputTaxAmount" value="0">
            <input type="hidden" name="total_amount" id="inputTotal" value="0">

            <label>Catatan (Opsional)</label>
            <textarea name="notes" rows="2" placeholder="Catatan tambahan..."></textarea>

            <!-- FILE UPLOAD KTP -->
            <div class="doc-upload-wrapper">
                <div class="doc-upload-header">
                    <label class="doc-label">Foto KTP Karyawan Restoran</label>
                    <span class="badge-muted-mini">PNG / JPG (Akan dikompresi otomatis ke ±300KB)</span>
                </div>

                <div class="doc-upload-input">
                    <label for="ktp_file" class="file-upload-label">
                        <span class="file-icon">📁</span>
                        <span class="file-text">
                            <strong>Pilih file</strong>
                            <small>PNG atau JPG - Otomatis dikompresi</small>
                        </span>
                    </label>
                    <input type="file"
                        id="ktp_file"
                        name="ktp_file"
                        accept="image/png,image/jpeg"
                        required
                        style="display:none;">
                    <div class="file-selected-name" data-for="ktp_file"></div>
                    <div id="fileSizeInfo" data-for="ktp_file" style="font-size:11px;color:#64748b;margin-top:4px;"></div>
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
        toggleCustom();
    });

    /* ===============================
       CALCULATION FUNCTIONS
    =============================== */
    function updatePriceInfo() {
        const select = document.getElementById('restaurantSelect');
        const option = select.options[select.selectedIndex];

        if (option.value) {
            const price = parseFloat(option.dataset.price) || 0;
            const tax = parseFloat(option.dataset.tax) || 0;

            document.getElementById('inputPrice').value = price;
            document.getElementById('inputTax').value = tax;
            document.getElementById('displayPrice').textContent = '$' + price.toLocaleString('en-US');
            document.getElementById('displayTaxPercent').textContent = tax;

            calculateTotal();
        }
    }

    function calculateTotal() {
        const price = parseFloat(document.getElementById('inputPrice').value) || 0;
        const taxPercent = parseFloat(document.getElementById('inputTax').value) || 0;
        const packets = parseInt(document.getElementById('packetCount').value) || 0;

        const subtotal = price * packets;
        const taxAmount = subtotal * (taxPercent / 100);
        const total = subtotal + taxAmount;

        document.getElementById('displaySubtotal').textContent = '$' + subtotal.toLocaleString('en-US');
        document.getElementById('displayTax').textContent = '$' + taxAmount.toLocaleString('en-US');
        document.getElementById('displayTotal').textContent = '$' + total.toLocaleString('en-US');

        document.getElementById('inputSubtotal').value = subtotal.toFixed(2);
        document.getElementById('inputTaxAmount').value = taxAmount.toFixed(2);
        document.getElementById('inputTotal').value = total.toFixed(2);
    }

    /* ===============================
       DATATABLES
    =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#consumptionTable').DataTable({
                pageLength: 10,
                order: [
                    [2, 'desc']
                ],
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                }
            });
        }
    });

    /* ===============================
       FILE NAME DISPLAY WITH SIZE INFO
    =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                const display = document.querySelector(
                    '.file-selected-name[data-for="' + this.id + '"]'
                );
                const sizeInfo = document.querySelector(
                    '#fileSizeInfo[data-for="' + this.id + '"]'
                );
                if (!display) return;

                if (this.files.length > 0) {
                    const f = this.files[0];
                    const sizeKB = (f.size / 1024).toFixed(1);
                    const sizeMB = (f.size / 1024 / 1024).toFixed(2);

                    // Tampilkan info file (tanpa batasan ukuran)
                    let sizeText = sizeKB + ' KB';
                    if (f.size > 1024 * 1024) {
                        sizeText = sizeMB + ' MB';
                    }

                    display.innerHTML = `
                        <span class="selected-file-info">
                            <strong>${f.name}</strong>
                            <small>Ukuran asli: ${sizeText}</small>
                        </span>
                    `;
                    display.style.display = 'flex';

                    // Estimasi ukuran setelah kompresi
                    let estimatedSize = '';
                    if (f.size > 1024 * 1024) {
                        // File > 1MB, target ~150-300KB
                        estimatedSize = '~' + Math.round(f.size / 4000) + ' KB';
                    } else if (f.size > 500 * 1024) {
                        // File 500KB-1MB, target ~100-250KB
                        estimatedSize = '~' + Math.round(f.size / 3000) + ' KB';
                    } else {
                        // File < 500KB, target ~50-200KB
                        estimatedSize = '~' + Math.round(f.size / 5) + ' KB';
                    }


                } else {
                    display.style.display = 'none';
                    if (sizeInfo) {
                        sizeInfo.textContent = '';
                    }
                }
            });
        });
    });

    /* ===============================
       MODAL HANDLER
    =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('consumptionModal');
        const btnOpen = document.getElementById('btnAddConsumption');

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
       MANAGE RESTAURANT BUTTON
    =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        const btnManage = document.getElementById('btnManageResto');
        if (btnManage) {
            btnManage.addEventListener('click', () => {
                window.location.href = '/dashboard/restaurant_settings.php';
            });
        }
    });

    /* ===============================
       FORM SUBMIT WITH AJAX
    =============================== */
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('consumptionForm');
        if (!form) return;

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;

            // Disable button dan show loading
            submitBtn.disabled = true;
            submitBtn.textContent = 'Menyimpan...';

            fetch('restaurant_consumption_action.php?action=create', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + (data.message || 'Konsumsi berhasil dicatat!'));
                        // Tutup modal
                        document.getElementById('consumptionModal').style.display = 'none';
                        document.body.classList.remove('modal-open');
                        // Reset form
                        form.reset();
                        // Reload halaman
                        location.reload();
                    } else {
                        alert('❌ ' + (data.message || 'Gagal menyimpan data'));
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    }
                })
                .catch(err => {
                    alert('❌ Terjadi kesalahan: ' + err.message);
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                });
        });
    });
</script>

<!-- ======================================
     MODAL PREVIEW KTP
     ====================================== -->
<div id="docPreviewModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:900px;">
        <div class="modal-header">
            <strong id="docPreviewTitle">📄 Foto KTP</strong>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" class="zoom-control-btn" id="docZoomOut">➖</button>
                <button type="button" class="zoom-control-btn" id="docZoomIn">➕</button>
                <button type="button" class="zoom-control-btn" id="docZoomReset">🔄</button>
                <button type="button" onclick="closeDocModal()">✕</button>
            </div>
        </div>

        <div class="modal-body"
            style="background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:60vh;">
            <img id="docPreviewImage"
                src=""
                alt="KTP"
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
            title.textContent = btn.dataset.title || 'Foto KTP';

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