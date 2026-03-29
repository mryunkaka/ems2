<?php
date_default_timezone_set('Asia/jakarta');
session_start();

if (!isset($_GET['range'])) {
    $_GET['range'] = 'week3';
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/date_range.php'; // WAJIB
require_once __DIR__ . '/../config/helpers.php';    // untuk dollar()
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_not_trainee_html('Gaji');

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$salaryHasUnitCode = ems_column_exists($pdo, 'salary', 'unit_code');
$userHasAllUnits = ems_user_can_view_all_units($pdo, $_SESSION['user_rh'] ?? []);
$isMedicalPosition = ems_is_medical_position($_SESSION['user_rh']['position'] ?? '');
$isMedicalDivision = ems_normalize_division($_SESSION['user_rh']['division'] ?? '') === 'Medis';

if (!$userHasAllUnits && $effectiveUnit === 'alta' && $isMedicalDivision && $isMedicalPosition) {
    header('Location: index.php');
    exit;
}

$isStaff = ($userRole === 'staff');
$userName = $_SESSION['user_rh']['name'] ?? '';

$pageTitle = 'Gaji Mingguan';

// =======================
// QUERY REKAP GAJI
// =======================
$stmtRekap = $pdo->prepare("
    SELECT
        COUNT(DISTINCT medic_user_id) AS total_medis,
        SUM(total_transaksi) AS total_transaksi,
        SUM(total_item) AS total_item,
        SUM(total_rupiah) AS total_rupiah,
        SUM(bonus_40) AS total_bonus
    FROM salary
    WHERE period_end BETWEEN :start AND :end
    " . ($salaryHasUnitCode ? " AND unit_code = :unit_code" : "") . "
");

$rekapParams = [
    ':start' => $rangeStart,
    ':end'   => $rangeEnd
];
if ($salaryHasUnitCode) {
    $rekapParams[':unit_code'] = $effectiveUnit;
}
$stmtRekap->execute($rekapParams);

$rekap = $stmtRekap->fetch(PDO::FETCH_ASSOC);

// SAFETY DEFAULT (ANTI NULL & TYPE FIX)
$rekap = [
    'total_medis'      => (int)($rekap['total_medis'] ?? 0),
    'total_transaksi' => (int)($rekap['total_transaksi'] ?? 0),
    'total_item'      => (int)($rekap['total_item'] ?? 0),
    'total_rupiah'    => (int)($rekap['total_rupiah'] ?? 0),
    'total_bonus'     => (int)($rekap['total_bonus'] ?? 0),
];

// =======================
// QUERY TOTAL SUDAH DIBAYARKAN
// =======================
$stmtPaid = $pdo->prepare("
    SELECT SUM(bonus_40) AS total_paid_bonus
    FROM salary
    WHERE period_end BETWEEN :start AND :end
    AND status = 'paid'
    " . ($salaryHasUnitCode ? " AND unit_code = :unit_code" : "") . "
");

$paidParams = [
    ':start' => $rangeStart,
    ':end'   => $rangeEnd
];
if ($salaryHasUnitCode) {
    $paidParams[':unit_code'] = $effectiveUnit;
}
$stmtPaid->execute($paidParams);

$paidData = $stmtPaid->fetch(PDO::FETCH_ASSOC);
$totalPaidBonus = (int)($paidData['total_paid_bonus'] ?? 0);

// Hitung sisa bonus
$sisaBonus = $rekap['total_bonus'] - $totalPaidBonus;
$paidPct = ($rekap['total_bonus'] > 0)
    ? (int)min(100, round(($totalPaidBonus / max(1, $rekap['total_bonus'])) * 100))
    : 0;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// ================= QUERY =================
if ($userRole === 'staff') {

    // STAFF: lihat SEMUA gaji milik dia (tanpa filter tanggal)
    $stmt = $pdo->prepare("
        SELECT *
        FROM salary
        WHERE medic_user_id = ?
        " . ($salaryHasUnitCode ? " AND unit_code = ?" : "") . "
        ORDER BY period_end DESC
    ");

    $staffParams = [$_SESSION['user_rh']['id']];
    if ($salaryHasUnitCode) {
        $staffParams[] = $effectiveUnit;
    }
    $stmt->execute($staffParams);
} else {

    // NON-STAFF: tabel HARUS ikut filter tanggal
    $stmt = $pdo->prepare("
        SELECT *
        FROM salary
        WHERE period_end BETWEEN :start AND :end
        " . ($salaryHasUnitCode ? " AND unit_code = :unit_code" : "") . "
        ORDER BY period_end DESC
    ");

    $salaryParams = [
        ':start' => $rangeStart,
        ':end'   => $rangeEnd
    ];
    if ($salaryHasUnitCode) {
        $salaryParams[':unit_code'] = $effectiveUnit;
    }
    $stmt->execute($salaryParams);
}

$salary = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title">Rekap Gaji Mingguan</h1>

        <p class="page-subtitle"><?= htmlspecialchars(ems_unit_label($effectiveUnit)) ?> &bull; <?= htmlspecialchars($rangeLabel ?? '-') ?>
        </p>
        <?php if (!$isStaff && ($_GET['range'] ?? '') !== 'all'): ?>

            <div class="card card-section">
                <div class="card-header">
                    Filter Rentang Tanggal
                </div>

                <div class="card-body">
                    <form method="GET" id="filterForm" class="filter-bar">

                        <div class="filter-group">
                            <label>Rentang</label>
                            <select name="range" id="rangeSelect" class="form-control">
                                <option value="week1" <?= ($_GET['range'] ?? '') === 'week1' ? 'selected' : '' ?>>
                                    3 Minggu Lalu
                                </option>
                                <option value="week2" <?= ($_GET['range'] ?? '') === 'week2' ? 'selected' : '' ?>>
                                    2 Minggu Lalu
                                </option>
                                <option value="week3" <?= ($_GET['range'] ?? '') === 'week3' ? 'selected' : '' ?>>
                                    Minggu Lalu
                                </option>
                                <option value="week4" <?= ($_GET['range'] ?? 'week4') === 'week4' ? 'selected' : '' ?>>
                                    Minggu Ini
                                </option>
                                <option value="custom" <?= ($_GET['range'] ?? '') === 'custom' ? 'selected' : '' ?>>
                                    Custom
                                </option>
                            </select>
                        </div>

                        <div class="filter-group filter-custom">
                            <label>Tanggal Awal</label>
                            <input type="date" name="from" value="<?= $_GET['from'] ?? '' ?>" class="form-control">
                        </div>

                        <div class="filter-group filter-custom">
                            <label>Tanggal Akhir</label>
                            <input type="date" name="to" value="<?= $_GET['to'] ?? '' ?>" class="form-control">
                        </div>

                        <div class="filter-group filter-action-end">
                            <button type="submit" class="btn btn-primary">
                                Terapkan
                            </button>
                        </div>

                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <?= ems_icon('receipt-percent', 'h-5 w-5') ?> Ringkasan Gaji
                </div>

                <div class="salary-summary">
                    <div class="salary-summary-hero">
                        <div class="min-w-0">
                            <div class="salary-summary-title">Total Bonus (40%)</div>
                            <div class="salary-summary-value"><?= dollar($rekap['total_bonus']) ?></div>
                            <div class="salary-summary-sub">
                                Sudah dibayarkan: <span class="font-semibold text-emerald-700"><?= dollar($totalPaidBonus) ?></span>
                                <span class="mx-1 text-slate-300">•</span>
                                Sisa: <span class="font-semibold text-amber-700"><?= dollar($sisaBonus) ?></span>
                            </div>
                        </div>

                        <div class="salary-progress">
                            <div class="salary-progress-track" role="progressbar" aria-valuenow="<?= $paidPct ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="salary-progress-bar" style="width: <?= (int)$paidPct ?>%"></div>
                            </div>
                            <div class="salary-progress-meta">
                                <span><?= (int)$paidPct ?>% dibayarkan</span>
                                <span><?= (int)$rekap['total_medis'] ?> medis</span>
                            </div>
                        </div>
                    </div>

                    <div class="salary-metrics">
                        <div class="metric-tile metric-teal">
                            <div class="metric-head">
                                <div>
                                    <div class="metric-label">Total Transaksi</div>
                                    <div class="metric-value"><?= (int)$rekap['total_transaksi'] ?></div>
                                </div>
                                <div class="metric-icon" aria-hidden="true">
                                    <?= ems_icon('clipboard-document-list', 'h-5 w-5') ?>
                                </div>
                            </div>
                        </div>

                        <div class="metric-tile">
                            <div class="metric-head">
                                <div>
                                    <div class="metric-label">Total Item</div>
                                    <div class="metric-value"><?= (int)$rekap['total_item'] ?></div>
                                </div>
                                <div class="metric-icon" aria-hidden="true">
                                    <?= ems_icon('user-group', 'h-5 w-5') ?>
                                </div>
                            </div>
                        </div>

                        <div class="metric-tile">
                            <div class="metric-head">
                                <div>
                                    <div class="metric-label">Total Pemasukan</div>
                                    <div class="metric-value"><?= dollar($rekap['total_rupiah']) ?></div>
                                </div>
                                <div class="metric-icon" aria-hidden="true">
                                    <?= ems_icon('chart-bar', 'h-5 w-5') ?>
                                </div>
                            </div>
                        </div>

                        <div class="metric-tile metric-emerald">
                            <div class="metric-head">
                                <div>
                                    <div class="metric-label">Sudah Dibayarkan</div>
                                    <div class="metric-value"><?= dollar($totalPaidBonus) ?></div>
                                </div>
                                <div class="metric-icon" aria-hidden="true">
                                    <?= ems_icon('check-circle', 'h-5 w-5') ?>
                                </div>
                            </div>
                        </div>

                        <div class="metric-tile metric-amber">
                            <div class="metric-head">
                                <div>
                                    <div class="metric-label">Sisa Bonus</div>
                                    <div class="metric-value"><?= dollar($sisaBonus) ?></div>
                                </div>
                                <div class="metric-icon" aria-hidden="true">
                                    <?= ems_icon('exclamation-triangle', 'h-5 w-5') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php
        $allowedRoles = ['vice director', 'director'];
        ?>

        <?php if (isset($_GET['generated'])): ?>
            <div class="alert alert-success" id="autoAlert">
                Generate gaji manual selesai.
                Periode baru dibuat: <strong><?= (int)$_GET['generated'] ?></strong>
            </div>
        <?php elseif (($_GET['msg'] ?? '') === 'nosales'): ?>
            <div class="alert alert-warning" id="autoAlert">
                Tidak ada data sales untuk dihitung.
            </div>
        <?php endif; ?>

        <?php if ($userRole === 'staff'): ?>
            <p class="text-muted">Menampilkan gaji Anda saja.</p>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Daftar Gaji</div>

            <?php if (in_array(strtolower($userRole), $allowedRoles, true)): ?>
                <form action="gaji_generate_manual.php" method="POST" class="mb-3.5">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ??= bin2hex(random_bytes(16)) ?>">
                    <button
                        type="submit"
                        class="btn btn-warning"
                        onclick="return confirm('Generate gaji mingguan sekarang? Digunakan jika otomatis generate bermasalah.')">
                        <?= ems_icon('arrow-path', 'h-4 w-4') ?> <span>Generate Gaji Manual</span>
                    </button>
                </form>
            <?php endif; ?>

            <div class="table-wrapper">
                <table id="salaryTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Jabatan</th>
                            <th>Periode</th>
                            <th>Bonus</th>
                            <th>Status</th>
                            <th>Dibayar Oleh</th>
                            <?php if ($userRole !== 'staff'): ?>
                                <th>Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($salary as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['medic_name']) ?></td>
                                <td><?= htmlspecialchars($row['medic_jabatan']) ?></td>
                                <td>
                                    <?= date('d M Y', strtotime($row['period_start'])) ?>
                                    -
                                    <?= date('d M Y', strtotime($row['period_end'])) ?>
                                </td>
                                <td>$ <?= number_format($row['bonus_40']) ?></td>

                                <td>
                                    <?php if ($row['status'] === 'paid'): ?>
                                        <div class="status-box verified">Dibayar</div>
                                    <?php else: ?>
                                        <div class="status-box pending">Pending</div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= $row['paid_by'] ?? '-' ?>
                                    <?php if (!empty($row['paid_at'])): ?>
                                        <div class="status-meta">
                                            <?= formatTanggalID($row['paid_at']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <?php if ($userRole !== 'staff'): ?>
                                    <td>
                                        <?php if ($row['status'] === 'pending'): ?>
                                            <button type="button"
                                                class="btn btn-success btn-sm"
                                                onclick="openPayModal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['medic_name']) ?>', <?= $row['bonus_40'] ?>)">
                                                Bayar
                                            </button>
                                            <?php else: ?>-<?php endif; ?>
                                    </td>
                                <?php endif; ?>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th colspan="4" class="table-align-right font-semibold">
                                TOTAL :
                            </th>
                            <th id="totalBonus">0</th>
                            <th colspan="<?= ($userRole !== 'staff') ? 3 : 2 ?>"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL PEMBAYARAN GAJI -->
    <div id="payModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-md">
            <div class="modal-head">
                <div class="modal-title inline-flex items-center gap-2">
                    <?= ems_icon('banknotes', 'h-5 w-5') ?>
                    <span>Konfirmasi Pembayaran Gaji</span>
                </div>
                <button type="button" class="modal-close-btn" onclick="closePayModal()" aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>

            <form id="payForm" class="form modal-form">
                <div class="modal-content">
                    <input type="hidden" id="paySalaryId" name="salary_id">

                    <!-- Info Target Pembayaran -->
                    <div class="pay-target-box">
                        <div class="pay-target-label">Target Pembayaran:</div>
                        <div class="pay-target-name" id="payTargetName">-</div>
                        <div class="pay-target-value">
                            $<span id="payTargetBonus">0</span>
                        </div>
                    </div>

                    <!-- Pilihan Metode Pembayaran -->
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

                    <!-- Input Titip ke Siapa (dengan autocomplete) -->
                    <div id="titipSection" class="hidden payment-extra">
                        <label class="payment-method-label">Titip ke Siapa:</label>
                        <div class="relative">
                            <input type="text" id="titipInput" name="titip_to"
                                placeholder="Ketik nama orang..."
                                autocomplete="off"
                                class="payment-input">
                            <!-- DROPDOWN AUTOCOMPLETE (seperti events.php) -->
                            <div id="titipDropdown" class="consumer-search-dropdown consumer-search-dropdown-field hidden"></div>
                        </div>
                        <small class="payment-help">
                            Jika nama belum ada, akun akan dibuat otomatis seperti form event.
                        </small>
                    </div>

                </div>

                <div class="modal-foot">
                    <div class="modal-actions">
                        <button type="button" onclick="closePayModal()" class="btn-secondary">Batal</button>
                        <button type="submit" class="btn-success"><?= ems_icon('banknotes', 'h-4 w-4') ?> <span>Proses Pembayaran</span></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        let selectedUserId = null;

        // Buka modal pembayaran
        function openPayModal(id, medicName, bonus) {
            document.getElementById('paySalaryId').value = id;
            document.getElementById('payTargetName').textContent = medicName;
            document.getElementById('payTargetBonus').textContent = bonus.toLocaleString('id-ID');
            document.getElementById('payModal').classList.remove('hidden');
            document.getElementById('payModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Reset form
            document.querySelector('input[name="pay_method"][value="direct"]').checked = true;
            document.getElementById('titipSection').classList.add('hidden');
            document.querySelectorAll('.payment-option').forEach(option => option.classList.remove('selected'));
            document.querySelector('input[name="pay_method"][value="direct"]').closest('.payment-option').classList.add('selected');
            document.getElementById('titipInput').value = '';
            document.getElementById('titipDropdown').classList.add('hidden');
            document.getElementById('titipDropdown').style.display = '';
            selectedUserId = null;
        }

        // Tutup modal
        function closePayModal() {
            document.getElementById('payModal').style.display = 'none';
            document.getElementById('payModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        // Handle perubahan metode pembayaran
        document.querySelectorAll('input[name="pay_method"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const titipSection = document.getElementById('titipSection');
                // Remove selected class dari semua label
                document.querySelectorAll('.payment-option').forEach(lbl => {
                    lbl.classList.remove('selected');
                });
                // Tambah selected class ke parent label yang dipilih
                if (this.value === 'titip') {
                    titipSection.classList.remove('hidden');
                    this.closest('label').classList.add('selected');
                } else {
                    titipSection.classList.add('hidden');
                    selectedUserId = null;
                    this.closest('label').classList.add('selected');
                }
            });
        });

        // Set initial selected state untuk radio button direct
        document.addEventListener('DOMContentLoaded', function() {
            const directRadio = document.querySelector('input[name="pay_method"][value="direct"]');
            if (directRadio && directRadio.checked) {
                directRadio.closest('label').classList.add('selected');
            }
        });

        // Autocomplete untuk "Titip ke Siapa" (seperti events.php)
        const titipInput = document.getElementById('titipInput');
        const titipDropdown = document.getElementById('titipDropdown');
        let titipController = null;

        titipInput.addEventListener('input', () => {
            const keyword = titipInput.value.trim();

            // Reset form field lain
            if (keyword.length < 2) {
                titipDropdown.classList.add('hidden');
                titipDropdown.innerHTML = '';
                return;
            }

            // Abort previous request
            if (titipController) titipController.abort();
            titipController = new AbortController();

            // Fetch data user
            fetch('../ajax/search_user_rh.php?q=' + encodeURIComponent(keyword), {
                    signal: titipController.signal
                })
                .then(res => res.json())
                .then(data => {
                    console.log('Hasil pencarian:', data); // DEBUG

                    // Clear dropdown
                    titipDropdown.innerHTML = '';

                    if (!data.length) {
                        titipDropdown.classList.add('hidden');
                        return;
                    }

                    // Create dan append setiap item
                    data.forEach(user => {
                        const item = document.createElement('div');
                        item.className = 'consumer-search-item';

                        // Nama
                        const nameDiv = document.createElement('div');
                        nameDiv.className = 'consumer-search-name';
                        nameDiv.textContent = user.full_name;
                        item.appendChild(nameDiv);

                        // Meta (jabatan & batch)
                        const metaDiv = document.createElement('div');
                        metaDiv.className = 'consumer-search-meta';
                        metaDiv.innerHTML = `
                        <span>${user.position ?? '-'}</span>
	                        <span class="dot">&bull;</span>
                        <span>Batch ${user.batch ?? '-'}</span>
                    `;
                        item.appendChild(metaDiv);

                        // Click handler
                        item.addEventListener('click', () => {
                            titipInput.value = user.full_name;
                            selectedUserId = user.id;
                            titipDropdown.classList.add('hidden');
                            titipDropdown.innerHTML = '';
                        });

                        titipDropdown.appendChild(item);
                    });

                    // Show dropdown
                    titipDropdown.classList.remove('hidden');
                })
                .catch(error => {
                    console.error('Error fetching user:', error);
                });
        });

        // Close dropdown saat klik di luar
        document.addEventListener('click', (e) => {
            if (!titipInput.contains(e.target) && !titipDropdown.contains(e.target)) {
                titipDropdown.classList.add('hidden');
            }
        });

        // Submit form
        document.getElementById('payForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const method = formData.get('pay_method');
            const salaryId = formData.get('salary_id');

            // Validasi jika pilih titip
            if (method === 'titip' && !selectedUserId) {
                alert('Silakan pilih user terlebih dahulu dari dropdown pencarian.');
                return;
            }

            // Kirim data
            const submitData = {
                salary_id: salaryId,
                pay_method: method,
                titip_to: selectedUserId || null
            };

            fetch('gaji_pay_process.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(submitData)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'Pembayaran berhasil diproses.');
                        closePayModal();
                        location.reload();
                    } else {
                        alert(data.message || 'Terjadi kesalahan');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Terjadi kesalahan saat memproses pembayaran.');
                });
        });

        // Close modal dengan Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePayModal();
            }
        });
    </script>

    <script>
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

</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#salaryTable').DataTable({
                order: [
                    [3, 'desc']
                ],
                pageLength: 10,
                language: {
                    url: '/assets/design/js/datatables-id.json'
                },

                footerCallback: function(row, data, start, end, display) {
                    const api = this.api();

                    // Ambil kolom Bonus (index ke-4, hitung dari 0)
                    const totalBonus = api
                        .column(4, {
                            page: 'current'
                        })
                        .data()
                        .reduce(function(a, b) {
                            // Hilangkan simbol & dan koma
                            const x = typeof a === 'string' ? a.replace(/[^0-9.-]+/g, '') : a;
                            const y = typeof b === 'string' ? b.replace(/[^0-9.-]+/g, '') : b;
                            return Number(x) + Number(y);
                        }, 0);

                    // Tampilkan ke footer
                    jQuery('#totalBonus').html(
                        '$ ' + totalBonus.toLocaleString('id-ID')
                    );
                }
            });
        } else {
            console.error('DataTables atau jQuery belum ter-load');
        }
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const alertBox = document.getElementById('autoAlert');

        if (alertBox) {
            setTimeout(() => {
                alertBox.style.transition = 'opacity 0.4s ease, max-height 0.4s ease';
                alertBox.style.maxHeight = '0';
                alertBox.style.padding = '0';

                setTimeout(() => {
                    alertBox.remove();
                }, 500);
            }, 5000); // 5 detik
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
