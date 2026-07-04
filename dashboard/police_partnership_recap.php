<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

if (!isset($_GET['range'])) {
    $_GET['range'] = 'week3';
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/police_partnership.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'police_partnership_recap.php', '/dashboard/index.php');
policePartnershipEnsureTable($pdo);

$pageTitle = 'Rekap Kerja Sama Police';
$user = $_SESSION['user_rh'] ?? [];
$userRole = strtolower(trim((string)($user['role'] ?? '')));
$effectiveUnit = ems_effective_unit($pdo, $user);
$canEditAmount = ems_is_manager_plus_role($userRole);
if (!$canEditAmount) {
    $_SESSION['flash_errors'][] = 'Rekap global kerja sama Police hanya bisa dilihat oleh manager.';
    header('Location: police_partnership.php');
    exit;
}
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_input,
        COUNT(badge_file_path) AS total_badge,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM police_partnership_records
    WHERE DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) BETWEEN :start AND :end
      AND unit_code = :unit_code
");
$summaryStmt->execute([
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
    ':unit_code' => $effectiveUnit,
]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$rowsStmt = $pdo->prepare("
    SELECT
        COALESCE(input_by_user_id, 0) AS input_by_user_id,
        input_by_name,
        COUNT(*) AS total_input,
        COUNT(badge_file_path) AS total_badges,
        COALESCE(SUM(amount), 0) AS total_amount,
        MIN(payment_status) AS min_payment_status,
        MAX(payment_status) AS max_payment_status,
        MAX(paid_at) AS paid_at,
        MAX(paid_by) AS paid_by,
        MAX(pricing_mode) AS pricing_mode
    FROM police_partnership_records
    WHERE DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) BETWEEN :start AND :end
      AND unit_code = :unit_code
    GROUP BY COALESCE(input_by_user_id, 0), input_by_name
    ORDER BY total_amount DESC, total_input DESC, input_by_name ASC
");
$rowsStmt->execute([
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
    ':unit_code' => $effectiveUnit,
]);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$detailStmt = $pdo->prepare("
    SELECT *
    FROM police_partnership_records
    WHERE DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) BETWEEN :start AND :end
      AND unit_code = :unit_code
    ORDER BY COALESCE(service_at, CONCAT(service_date, ' 00:00:00')) DESC, id DESC
");
$detailStmt->execute([
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
    ':unit_code' => $effectiveUnit,
]);
$detailRowsByInput = [];
foreach (($detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $detailRow) {
    $key = ((int)($detailRow['input_by_user_id'] ?? 0)) . '|' . (string)($detailRow['input_by_name'] ?? '');
    $detailRowsByInput[$key][] = [
        'service_at' => policePartnershipDateTimeLabel($detailRow['service_at'] ?? '', $detailRow['service_date'] ?? ''),
        'action_type' => (string)($detailRow['action_type'] ?? ''),
        'amount' => dollar((int)($detailRow['amount'] ?? 0)),
        'status' => (string)($detailRow['payment_status'] ?? 'pending') === 'paid' ? 'Dibayar' : 'Pending',
        'badge_url' => policePartnershipSecureFileUrl($detailRow['badge_file_path'] ?? ''),
    ];
}

$exportParams = $_GET;
$exportUrl = 'police_partnership_recap_export.php';
if ($exportParams !== []) {
    $exportUrl .= '?' . http_build_query($exportParams);
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between mb-4">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle"><?= htmlspecialchars(ems_unit_label($effectiveUnit), ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($rangeLabel ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-warning" onclick="openPricingModal()">
                    <?= ems_icon('banknotes', 'h-4 w-4') ?>
                    <span>Edit Harga</span>
                </button>
                <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-success">
                    <?= ems_icon('document-arrow-down', 'h-4 w-4') ?>
                    <span>Export Excel</span>
                </a>
                <a href="police_partnership.php" class="btn-secondary">
                    <?= ems_icon('plus', 'h-4 w-4') ?>
                    <span>Input Baru</span>
                </a>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="card card-section mb-4">
            <div class="card-header">Filter Rentang Tanggal</div>
            <div class="card-body">
                <form method="GET" class="filter-bar">
                    <div class="filter-group">
                        <label>Rentang</label>
                        <select name="range" id="rangeSelect" class="form-control">
                            <option value="week1" <?= ($_GET['range'] ?? '') === 'week1' ? 'selected' : '' ?>>3 Minggu Lalu</option>
                            <option value="week2" <?= ($_GET['range'] ?? '') === 'week2' ? 'selected' : '' ?>>2 Minggu Lalu</option>
                            <option value="week3" <?= ($_GET['range'] ?? 'week3') === 'week3' ? 'selected' : '' ?>>Minggu Lalu</option>
                            <option value="week4" <?= ($_GET['range'] ?? '') === 'week4' ? 'selected' : '' ?>>Minggu Ini</option>
                            <option value="custom" <?= ($_GET['range'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Awal</label>
                        <input type="date" name="from" value="<?= htmlspecialchars((string)($_GET['from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Akhir</label>
                        <input type="date" name="to" value="<?= htmlspecialchars((string)($_GET['to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                    </div>
                    <div class="filter-group filter-action-end">
                        <button type="submit" class="btn btn-primary">Terapkan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="stats-grid mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">Jumlah Input</div>
                <div class="text-2xl font-extrabold text-slate-900"><?= (int)($summary['total_input'] ?? 0) ?></div>
            </div>
            <div class="card card-section">
                            <div class="meta-text-xs">Total Foto Badge</div>
                <div class="text-2xl font-extrabold text-primary"><?= (int)($summary['total_badge'] ?? 0) ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Total Nilai Kerja Sama</div>
                <div class="text-2xl font-extrabold text-success"><?= dollar((int)($summary['total_amount'] ?? 0)) ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Daftar Input Police</div>
            <div class="table-wrapper">
                <table id="policePartnershipTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Medis</th>
                            <th>Total Input</th>
                            <th>Total Foto Badge</th>
                            <th>Hasil Diterima</th>
                            <th>Status</th>
                            <th>Dibayar Oleh</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php
                            $isPaid = (string)($row['min_payment_status'] ?? '') === 'paid'
                                && (string)($row['max_payment_status'] ?? '') === 'paid';
                            ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <?php $detailKey = ((int)$row['input_by_user_id']) . '|' . (string)$row['input_by_name']; ?>
                                <td>
                                    <button type="button"
                                        class="btn-link"
                                        onclick="openPoliceDetailModal(<?= htmlspecialchars(json_encode((string)$row['input_by_name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($detailRowsByInput[$detailKey] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">
                                        <?= htmlspecialchars((string)$row['input_by_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </td>
                                <td><?= (int)$row['total_input'] ?></td>
                                <td><?= (int)$row['total_badges'] ?></td>
                                <td data-order="<?= (int)$row['total_amount'] ?>"><strong><?= dollar((int)$row['total_amount']) ?></strong></td>
                                <td>
                                    <?php if ($isPaid): ?>
                                        <div class="status-box verified">Dibayar</div>
                                    <?php else: ?>
                                        <div class="status-box pending">Pending</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($row['paid_by'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($row['paid_at'])): ?>
                                        <div class="status-meta"><?= htmlspecialchars(formatTanggalID($row['paid_at']), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$isPaid && (int)$row['total_amount'] > 0): ?>
                                        <button type="button"
                                            class="btn btn-success btn-sm action-icon-btn"
                                            onclick="openPolicePayModal(<?= (int)$row['input_by_user_id'] ?>, <?= htmlspecialchars(json_encode((string)$row['input_by_name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, <?= (int)$row['total_amount'] ?>)"
                                            title="Bayarkan"
                                            aria-label="Bayarkan">
                                            <?= ems_icon('banknotes', 'h-4 w-4') ?>
                                        </button>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="table-align-right font-semibold">TOTAL :</th>
                            <th><?= dollar((int)($summary['total_amount'] ?? 0)) ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div id="pricingModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-md">
            <div class="modal-head">
                <div class="modal-title inline-flex items-center gap-2">
                    <?= ems_icon('banknotes', 'h-5 w-5') ?>
                    <span>Edit Harga Kerja Sama Police</span>
                </div>
                <button type="button" class="modal-close-btn" onclick="closePricingModal()" aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>
            <form method="POST" action="police_partnership_action.php" class="form modal-form">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="update_pricing">
                <input type="hidden" name="range_start" value="<?= htmlspecialchars($rangeStart, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="range_end" value="<?= htmlspecialchars($rangeEnd, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="redirect" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? 'police_partnership_recap.php'), ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-content">
                    <label>Mode Harga</label>
                    <select name="pricing_mode" required>
                        <option value="per_qty">Per Qty / Per Input</option>
                        <option value="per_week">Per Minggu</option>
                        <option value="per_month">Per Bulan</option>
                    </select>
                    <label>Total</label>
                    <input type="number" name="total_amount" min="0" step="1" required value="<?= (int)($summary['total_amount'] ?? 0) ?>">
                    <p class="meta-text-xs">Per Qty akan mengisi nominal yang sama untuk setiap input. Per Minggu dan Per Bulan akan membagi total ke seluruh input pada filter aktif.</p>
                </div>
                <div class="modal-foot">
                    <div class="modal-actions">
                        <button type="button" onclick="closePricingModal()" class="btn-secondary">Batal</button>
                        <button type="submit" class="btn-success"><?= ems_icon('check', 'h-4 w-4') ?> <span>Simpan Harga</span></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="policeDetailModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-lg">
            <div class="modal-head">
                <div class="modal-title inline-flex items-center gap-2">
                    <?= ems_icon('clipboard-document-list', 'h-5 w-5') ?>
                    <span id="policeDetailTitle">Detail Input Police</span>
                </div>
                <button type="button" class="modal-close-btn" onclick="closePoliceDetailModal()" aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>
            <div class="modal-content">
                <div class="table-wrapper">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Jam dan Tanggal</th>
                                <th>Tindakan</th>
                                <th>Hasil</th>
                                <th>Status</th>
                                <th>Foto Badge</th>
                            </tr>
                        </thead>
                        <tbody id="policeDetailRows"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" onclick="closePoliceDetailModal()" class="btn-secondary">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <div id="policePayModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-md">
            <div class="modal-head">
                <div class="modal-title inline-flex items-center gap-2">
                    <?= ems_icon('banknotes', 'h-5 w-5') ?>
                    <span>Konfirmasi Pembayaran Police</span>
                </div>
                <button type="button" class="modal-close-btn" onclick="closePolicePayModal()" aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>
            <form id="policePayForm" class="form modal-form">
                <div class="modal-content">
                    <input type="hidden" id="policePayUserId" name="input_by_user_id">
                    <input type="hidden" id="policePayUserName" name="input_by_name">
                    <div class="pay-target-box">
                        <div class="pay-target-label">Target Pembayaran:</div>
                        <div class="pay-target-name" id="policePayTargetName">-</div>
                        <div class="pay-target-value">$<span id="policePayTargetAmount">0</span></div>
                    </div>
                    <div class="payment-extra">
                        <label class="payment-method-label">Metode Pembayaran:</label>
                        <div class="payment-method-grid">
                            <label class="payment-option selected">
                                <input type="radio" name="pay_method" value="direct" checked>
                                <span>Langsung Dibayar</span>
                            </label>
                            <label class="payment-option">
                                <input type="radio" name="pay_method" value="titip">
                                <span>Titip ke:</span>
                            </label>
                        </div>
                    </div>
                    <div id="policeTitipSection" class="hidden payment-extra">
                        <label class="payment-method-label">Titip ke Siapa:</label>
                        <div class="relative">
                            <input type="text" id="policeTitipInput" name="titip_to" placeholder="Ketik nama orang..." autocomplete="off" class="payment-input">
                            <div id="policeTitipDropdown" class="consumer-search-dropdown consumer-search-dropdown-field hidden"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-foot">
                    <div class="modal-actions">
                        <button type="button" onclick="closePolicePayModal()" class="btn-secondary">Batal</button>
                        <button type="submit" class="btn-success"><?= ems_icon('banknotes', 'h-4 w-4') ?> <span>Proses Pembayaran</span></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>

<script>
    function openPricingModal() {
        const modal = document.getElementById('pricingModal');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closePricingModal() {
        const modal = document.getElementById('pricingModal');
        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }

    function openPoliceDetailModal(name, rows) {
        document.getElementById('policeDetailTitle').textContent = 'Detail Input Police - ' + name;
        const tbody = document.getElementById('policeDetailRows');
        tbody.innerHTML = '';

        if (!Array.isArray(rows) || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Tidak ada data pada filter ini.</td></tr>';
        } else {
            rows.forEach(function(row) {
                const tr = document.createElement('tr');
                const badgeCell = row.badge_url
                    ? `<a href="#" class="doc-badge is-verified btn-preview-doc" data-src="${escapeHtml(row.badge_url)}" data-title="Foto Badge Police">Lihat Foto</a>`
                    : '<span class="muted-placeholder">-</span>';
                tr.innerHTML = `
                    <td>${escapeHtml(row.service_at || '-')}</td>
                    <td>${escapeHtml(row.action_type || '-')}</td>
                    <td>${escapeHtml(row.amount || '$0')}</td>
                    <td>${escapeHtml(row.status || '-')}</td>
                    <td>${badgeCell}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        const modal = document.getElementById('policeDetailModal');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closePoliceDetailModal() {
        const modal = document.getElementById('policeDetailModal');
        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function(char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    let policeSelectedTitipUserId = null;

    function openPolicePayModal(userId, userName, amount) {
        document.getElementById('policePayUserId').value = userId;
        document.getElementById('policePayUserName').value = userName;
        document.getElementById('policePayTargetName').textContent = userName;
        document.getElementById('policePayTargetAmount').textContent = Number(amount || 0).toLocaleString('id-ID');
        document.querySelector('#policePayModal input[name="pay_method"][value="direct"]').checked = true;
        document.querySelectorAll('#policePayModal .payment-option').forEach(option => option.classList.remove('selected'));
        document.querySelector('#policePayModal input[name="pay_method"][value="direct"]').closest('.payment-option').classList.add('selected');
        document.getElementById('policeTitipSection').classList.add('hidden');
        document.getElementById('policeTitipInput').value = '';
        document.getElementById('policeTitipDropdown').classList.add('hidden');
        document.getElementById('policeTitipDropdown').innerHTML = '';
        policeSelectedTitipUserId = null;

        const modal = document.getElementById('policePayModal');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closePolicePayModal() {
        const modal = document.getElementById('policePayModal');
        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }

    document.addEventListener('DOMContentLoaded', function() {
        const rangeSelect = document.getElementById('rangeSelect');
        const customFields = document.querySelectorAll('.filter-custom');
        const toggleCustomFields = function() {
            const isCustom = rangeSelect && rangeSelect.value === 'custom';
            customFields.forEach(function(field) {
                field.style.display = isCustom ? 'block' : 'none';
            });
        };

        if (rangeSelect) {
            toggleCustomFields();
            rangeSelect.addEventListener('change', toggleCustomFields);
        }

        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#policePartnershipTable').DataTable({
                pageLength: 10,
                order: [[4, 'desc']],
                language: {
                    url: '/assets/design/js/datatables-id.json',
                    emptyTable: 'Belum ada data pada rentang ini.'
                }
            });
        }

        document.querySelectorAll('#policePayModal input[name="pay_method"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                document.querySelectorAll('#policePayModal .payment-option').forEach(option => option.classList.remove('selected'));
                this.closest('.payment-option').classList.add('selected');
                document.getElementById('policeTitipSection').classList.toggle('hidden', this.value !== 'titip');
                if (this.value !== 'titip') {
                    policeSelectedTitipUserId = null;
                }
            });
        });

        const titipInput = document.getElementById('policeTitipInput');
        const titipDropdown = document.getElementById('policeTitipDropdown');
        let titipController = null;
        titipInput?.addEventListener('input', function() {
            const keyword = titipInput.value.trim();
            policeSelectedTitipUserId = null;
            if (keyword.length < 2) {
                titipDropdown.classList.add('hidden');
                titipDropdown.innerHTML = '';
                return;
            }
            if (titipController) titipController.abort();
            titipController = new AbortController();
            fetch('../ajax/search_user_rh.php?q=' + encodeURIComponent(keyword), { signal: titipController.signal })
                .then(response => response.json())
                .then(data => {
                    titipDropdown.innerHTML = '';
                    if (!Array.isArray(data) || data.length === 0) {
                        titipDropdown.classList.add('hidden');
                        return;
                    }
                    data.forEach(user => {
                        const item = document.createElement('div');
                        item.className = 'consumer-search-item';
                        item.innerHTML = `<div class="consumer-search-name">${user.full_name ?? '-'}</div><div class="consumer-search-meta">${user.position ?? '-'}</div>`;
                        item.addEventListener('click', () => {
                            titipInput.value = user.full_name || '';
                            policeSelectedTitipUserId = user.id || null;
                            titipDropdown.classList.add('hidden');
                            titipDropdown.innerHTML = '';
                        });
                        titipDropdown.appendChild(item);
                    });
                    titipDropdown.classList.remove('hidden');
                })
                .catch(() => {});
        });

        document.getElementById('policePayForm')?.addEventListener('submit', function(event) {
            event.preventDefault();
            const formData = new FormData(this);
            const method = formData.get('pay_method');
            if (method === 'titip' && !policeSelectedTitipUserId) {
                alert('Silakan pilih user titip dari dropdown pencarian.');
                return;
            }

            fetch('police_partnership_pay_process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    input_by_user_id: formData.get('input_by_user_id'),
                    input_by_name: formData.get('input_by_name'),
                    range_start: <?= json_encode($rangeStart, JSON_UNESCAPED_SLASHES) ?>,
                    range_end: <?= json_encode($rangeEnd, JSON_UNESCAPED_SLASHES) ?>,
                    pay_method: method,
                    titip_to: policeSelectedTitipUserId,
                    csrf_token: String(window.EMS_CSRF_TOKEN || '')
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'Pembayaran berhasil diproses.');
                        closePolicePayModal();
                        location.reload();
                    } else {
                        alert(data.message || 'Pembayaran gagal.');
                    }
                })
                .catch(() => alert('Terjadi kesalahan saat memproses pembayaran.'));
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
