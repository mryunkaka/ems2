<?php
date_default_timezone_set('Asia/jakarta');
session_start();

if (!isset($_GET['range'])) {
    $_GET['range'] = 'week3';
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/date_range.php'; // 🔴 WAJIB
require_once __DIR__ . '/../config/helpers.php';    // untuk dollar()

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$isStaff = ($userRole === 'staff');
$userName = $_SESSION['user_rh']['name'] ?? '';

$pageTitle = 'Gaji Mingguan';

// =======================
// QUERY REKAP GAJI
// =======================
$stmtRekap = $pdo->prepare("
    SELECT
        COUNT(DISTINCT medic_name) AS total_medis,
        SUM(total_transaksi) AS total_transaksi,
        SUM(total_item) AS total_item,
        SUM(total_rupiah) AS total_rupiah,
        SUM(bonus_40) AS total_bonus
    FROM salary
    WHERE period_end BETWEEN :start AND :end
");

$stmtRekap->execute([
    ':start' => $rangeStart,
    ':end'   => $rangeEnd
]);

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
");

$stmtPaid->execute([
    ':start' => $rangeStart,
    ':end'   => $rangeEnd
]);

$paidData = $stmtPaid->fetch(PDO::FETCH_ASSOC);
$totalPaidBonus = (int)($paidData['total_paid_bonus'] ?? 0);

// Hitung sisa bonus
$sisaBonus = $rekap['total_bonus'] - $totalPaidBonus;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// ================= QUERY =================
if ($userRole === 'staff') {

    // STAFF: lihat SEMUA gaji milik dia (tanpa filter tanggal)
    $stmt = $pdo->prepare("
        SELECT *
        FROM salary
        WHERE medic_name = ?
        ORDER BY period_end DESC
    ");

    $stmt->execute([$userName]);
} else {

    // NON-STAFF: tabel HARUS ikut filter tanggal
    $stmt = $pdo->prepare("
        SELECT *
        FROM salary
        WHERE period_end BETWEEN :start AND :end
        ORDER BY period_end DESC
    ");

    $stmt->execute([
        ':start' => $rangeStart,
        ':end'   => $rangeEnd
    ]);
}

$salary = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="content">
    <div class="page" style="max-width:1100px;margin:auto;">
        <h1>Rekap Gaji Mingguan</h1>

        <p class="text-muted"><?= htmlspecialchars($rangeLabel ?? '-') ?>
        </p>
        <?php if (!$isStaff && ($_GET['range'] ?? '') !== 'all'): ?>

            <div class="card" style="margin-bottom:20px;">
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

                        <div class="filter-group" style="align-self:flex-end;">
                            <button type="submit" class="btn btn-primary">
                                Terapkan
                            </button>
                        </div>

                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Ringkasan Gaji</div>

                <div class="card-body ringkasan-gaji-grid">

                    <div class="stat-box">
                        <small>Total Transaksi</small>
                        <h3><?= (int)$rekap['total_transaksi'] ?></h3>
                    </div>

                    <div class="stat-box">
                        <small>Total Bonus</small>
                        <h3><?= dollar($rekap['total_rupiah']) ?></h3>
                    </div>

                    <div class="stat-box highlight">
                        <small>Total Bonus (40%)</small>
                        <h3><?= dollar($rekap['total_bonus']) ?></h3>
                    </div>

                    <div class="stat-box" style="background: linear-gradient(145deg, #15803d, #166534);">
                        <small>Sudah Dibayarkan</small>
                        <h3><?= dollar($totalPaidBonus) ?></h3>
                    </div>

                    <div class="stat-box" style="background: linear-gradient(145deg, #f59e0b, #d97706);">
                        <small>Sisa Bonus</small>
                        <h3><?= dollar($sisaBonus) ?></h3>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php
        $allowedRoles = ['vice director', 'director'];
        ?>

        <?php if (isset($_GET['generated'])): ?>
            <div class="alert alert-success" id="autoAlert">
                ✅ Generate gaji manual selesai.
                Periode baru dibuat: <strong><?= (int)$_GET['generated'] ?></strong>
            </div>
        <?php elseif (($_GET['msg'] ?? '') === 'nosales'): ?>
            <div class="alert alert-warning" id="autoAlert">
                ⚠️ Tidak ada data sales untuk dihitung.
            </div>
        <?php endif; ?>

        <?php if ($userRole === 'staff'): ?>
            <p class="text-muted">Menampilkan gaji Anda saja.</p>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Daftar Gaji</div>

            <?php if (in_array(strtolower($userRole), $allowedRoles, true)): ?>
                <form action="gaji_generate_manual.php" method="POST" style="margin-bottom:14px;">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ??= bin2hex(random_bytes(16)) ?>">
                    <button
                        type="submit"
                        class="btn btn-warning"
                        onclick="return confirm('Generate gaji mingguan sekarang? Digunakan jika otomatis generate bermasalah.')">
                        🔄 Generate Gaji Manual
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
                                        <div class="status-box verified">✔ Dibayar</div>
                                    <?php else: ?>
                                        <div class="status-box pending">⏳ Pending</div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= $row['paid_by'] ?? '-' ?>
                                    <?php if (!empty($row['paid_at'])): ?>
                                        <div style="font-size:11px;color:#64748b;margin-top:2px;">
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
                            <th colspan="4" style="text-align:right;font-weight:600;">
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
    <div id="payModal" class="modal-overlay" style="display:none;">
        <div class="modal-box">
            <h3>💰 Konfirmasi Pembayaran Gaji</h3>

            <form id="payForm" class="form" style="margin-top:16px;">
                <input type="hidden" id="paySalaryId" name="salary_id">

                <!-- Info Target Pembayaran -->
                <div style="background:#f8fafc;padding:12px;border-radius:10px;margin-bottom:14px;border:1px solid #e2e8f0;">
                    <div style="font-size:12px;color:#64748b;margin-bottom:4px;">Target Pembayaran:</div>
                    <div style="font-size:15px;font-weight:700;color:#0f172a;" id="payTargetName">-</div>
                    <div style="font-size:13px;color:#16a34a;margin-top:4px;font-weight:600;">
                        $<span id="payTargetBonus">0</span>
                    </div>
                </div>

                <!-- Pilihan Metode Pembayaran -->
                <div style="margin-bottom:14px;">
                    <label style="font-size:13px;font-weight:700;">Metode Pembayaran:</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px;">
                        <label style="display:flex;align-items:center;padding:10px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all 0.2s;margin:0;">
                            <input type="radio" name="pay_method" value="direct" checked style="margin-right:8px;">
                            <span style="font-size:13px;font-weight:600;">Langsung Dibayar</span>
                        </label>
                        <label style="display:flex;align-items:center;padding:10px;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:all 0.2s;margin:0;">
                            <input type="radio" name="pay_method" value="titip" style="margin-right:8px;">
                            <span style="font-size:13px;font-weight:600;">Titip ke:</span>
                        </label>
                    </div>
                </div>

                <!-- Input Titip ke Siapa (dengan autocomplete) -->
                <div id="titipSection" style="display:none;margin-bottom:14px;">
                    <label style="font-size:13px;font-weight:700;">Titip ke Siapa:</label>
                    <div style="position:relative;">
                        <input type="text" id="titipInput" name="titip_to"
                            placeholder="Ketik nama orang..."
                            autocomplete="off"
                            style="width:100%;padding:12px 14px;font-size:14px;border:1px solid #cbd5e1;border-radius:12px;">
                        <!-- DROPDOWN AUTOCOMPLETE (seperti events.php) -->
                        <div id="titipDropdown" class="consumer-search-dropdown hidden"></div>
                    </div>
                    <small style="color:#64748b;font-size:11px;display:block;margin-top:4px;">
                        💡 Jika nama belum ada, akun akan dibuat otomatis (seperti form event)
                    </small>
                </div>

                <!-- Actions -->
                <div class="modal-actions" style="margin-top:16px;">
                    <button type="button" onclick="closePayModal()" style="background:#e5e7eb;color:#334155;">Batal</button>
                    <button type="submit" class="btn-success" style="background:linear-gradient(135deg, #22c55e, #16a34a);color:#fff;">💰 Proses Pembayaran</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        /* Highlight radio button saat dipilih */
        input[type="radio"]:checked+span {
            color: #0369a1;
        }

        label.selected {
            border-color: #0ea5e9 !important;
            background: #f0f9ff;
        }

        /* Modal box animation */
        #payModal .modal-box {
            animation: slideUp 0.3s ease;
        }

        #payModal h3 {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0;
        }
    </style>

    <script>
        let selectedUserId = null;

        // Buka modal pembayaran
        function openPayModal(id, medicName, bonus) {
            document.getElementById('paySalaryId').value = id;
            document.getElementById('payTargetName').textContent = medicName;
            document.getElementById('payTargetBonus').textContent = bonus.toLocaleString('id-ID');
            document.getElementById('payModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Reset form
            document.querySelector('input[name="pay_method"][value="direct"]').checked = true;
            document.getElementById('titipSection').style.display = 'none';
            document.getElementById('titipInput').value = '';
            document.getElementById('titipDropdown').classList.add('hidden');
            document.getElementById('titipDropdown').style.display = '';
            selectedUserId = null;
        }

        // Tutup modal
        function closePayModal() {
            document.getElementById('payModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        // Handle perubahan metode pembayaran
        document.querySelectorAll('input[name="pay_method"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const titipSection = document.getElementById('titipSection');
                // Remove selected class dari semua label
                document.querySelectorAll('label[style*="display:grid"]').forEach(lbl => {
                    lbl.classList.remove('selected');
                });
                // Tambah selected class ke parent label yang dipilih
                if (this.value === 'titip') {
                    titipSection.style.display = 'block';
                    this.closest('label').classList.add('selected');
                } else {
                    titipSection.style.display = 'none';
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
                        <span class="dot">•</span>
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
                        alert('✅ ' + data.message);
                        closePayModal();
                        location.reload();
                    } else {
                        alert('❌ ' + (data.message || 'Terjadi kesalahan'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('❌ Terjadi kesalahan saat memproses pembayaran.');
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
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
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