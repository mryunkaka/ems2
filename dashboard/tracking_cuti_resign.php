<?php
/**
 * HALAMAN TRACKING & MONITORING CUTI & RESIGN
 *
 * Fitur:
 * - Monitoring semua user dengan status: Aktif, Cuti, Resigned
 * - Progress bar untuk user yang sedang cuti
 * - Filter berdasarkan status dan batch
 * - Detail view untuk setiap user
 */

date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

// Ambil data user dari session
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userRole = $user['role'] ?? '';
$canApprove = can_approve_cuti_resign($userRole);

// Hanya Manager+ yang bisa akses halaman ini
if (!$canApprove) {
    http_response_code(403);
    die('Akses ditolak');
}

// Page title
$pageTitle = 'Monitoring Cuti & Resign';

// Flash messages
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

// Ambil filter dari URL
$filterStatus = $_GET['status'] ?? 'all';
$filterBatch = $_GET['batch'] ?? 'all';

// Variabel untuk data
$allUsers = [];
$stats = [
    'total' => 0,
    'active' => 0,
    'on_cuti' => 0,
    'resigned' => 0
];

try {
    // Build query dengan filter
    $whereClause = "WHERE 1=1";
    $params = [];

    if ($filterStatus !== 'all') {
        if ($filterStatus === 'resigned') {
            $whereClause .= " AND u.is_active = 0";
        } elseif ($filterStatus === 'active' || $filterStatus === 'on_cuti') {
            $whereClause .= " AND u.is_active = 1";
        }
    }

    if ($filterBatch !== 'all') {
        $whereClause .= " AND u.batch = ?";
        $params[] = (int)$filterBatch;
    }

    // Ambil semua user dengan data cuti dan resign
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.full_name,
            u.position,
            u.role,
            u.batch,
            u.is_active,
            u.cuti_start_date,
            u.cuti_end_date,
            u.cuti_days_total,
            u.cuti_status,
            u.cuti_approved_at,
            u.resign_reason,
            u.resigned_by,
            u.resigned_at,
            resigner.full_name as resigned_by_name,
            approver.full_name as cuti_approved_by_name,
            (SELECT COUNT(*) FROM cuti_requests WHERE user_id = u.id AND status = 'approved') as total_cuti_approved,
            (SELECT COUNT(*) FROM resign_requests WHERE user_id = u.id AND status = 'approved') as total_resign_approved
        FROM user_rh u
        LEFT JOIN user_rh resigner ON resigner.id = u.resigned_by
        LEFT JOIN user_rh approver ON approver.id = u.cuti_approved_by
        {$whereClause}
        ORDER BY
            u.is_active DESC,
            u.cuti_status DESC,
            u.full_name ASC
        LIMIT 500
    ");
    $stmt->execute($params);
    $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allUsers as &$u) {
        $u['cuti_period_status'] = get_cuti_period_status(
            $u['cuti_start_date'] ?? null,
            $u['cuti_end_date'] ?? null,
            $u['cuti_status'] ?? null
        );
        if ((int)$u['is_active'] === 0) {
            $u['tracking_sort_priority'] = 3;
        } elseif (($u['cuti_period_status'] ?? 'none') === 'scheduled') {
            $u['tracking_sort_priority'] = 0;
        } elseif (($u['cuti_period_status'] ?? 'none') === 'active') {
            $u['tracking_sort_priority'] = 1;
        } else {
            $u['tracking_sort_priority'] = 2;
        }
    }
    unset($u);

    if ($filterStatus !== 'all') {
        $allUsers = array_values(array_filter($allUsers, static function (array $u) use ($filterStatus): bool {
            if ($filterStatus === 'resigned') {
                return (int)$u['is_active'] === 0;
            }

            if ($filterStatus === 'on_cuti') {
                return ($u['cuti_period_status'] ?? 'none') === 'active';
            }

            if ($filterStatus === 'active') {
                return (int)$u['is_active'] === 1 && ($u['cuti_period_status'] ?? 'none') !== 'active';
            }

            return true;
        }));
    }

    usort($allUsers, static function (array $a, array $b): int {
        $priorityCompare = ($a['tracking_sort_priority'] ?? 99) <=> ($b['tracking_sort_priority'] ?? 99);
        if ($priorityCompare !== 0) {
            return $priorityCompare;
        }

        return strcmp((string)($a['full_name'] ?? ''), (string)($b['full_name'] ?? ''));
    });

    // Hitung statistik
    $stats['total'] = count($allUsers);
    foreach ($allUsers as $u) {
        if ((int)$u['is_active'] === 0) {
            $stats['resigned']++;
        } elseif (($u['cuti_period_status'] ?? 'none') === 'active') {
            $stats['on_cuti']++;
        } else {
            $stats['active']++;
        }
    }

    // Ambil list batch untuk filter
    $stmt = $pdo->query("
        SELECT DISTINCT batch
        FROM user_rh
        WHERE batch IS NOT NULL
        ORDER BY batch ASC
    ");
    $batches = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $e) {
    $errors[] = 'Gagal memuat data: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="section-intro">Monitoring status cuti dan resign untuk seluruh tenaga medis EMS.</p>

        <!-- Flash Messages -->
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error) ?></div>
        <?php endforeach; ?>

        <div class="tracking-hero mb-4">
            <div>
                <div class="tracking-kicker">Ringkasan Hari Ini</div>
                <h2 class="tracking-hero-title">Status cuti dan resign seluruh tenaga medis dalam satu tampilan.</h2>
                <p class="tracking-hero-copy">Statistik, filter, dan daftar user dirapikan agar monitoring harian lebih cepat dibaca.</p>
            </div>
            <div class="tracking-hero-meta">
                <div class="tracking-hero-meta-label">Snapshot</div>
                <div class="tracking-hero-meta-value"><?= date('d M Y') ?></div>
            </div>
        </div>

        <!-- Statistik Cards -->
        <div class="tracking-stats-grid mb-4">
            <article class="tracking-stat-card">
                <div class="tracking-stat-icon is-total"><?= ems_icon('user-group', 'h-5 w-5') ?></div>
                <div class="tracking-stat-body">
                    <div class="tracking-stat-label">Total User</div>
                    <div class="tracking-stat-value"><?= $stats['total'] ?></div>
                    <div class="tracking-stat-note">Seluruh user yang terdaftar</div>
                </div>
            </article>
            <article class="tracking-stat-card">
                <div class="tracking-stat-icon is-active"><?= ems_icon('check-circle', 'h-5 w-5') ?></div>
                <div class="tracking-stat-body">
                    <div class="tracking-stat-label">User Aktif</div>
                    <div class="tracking-stat-value text-emerald-700"><?= $stats['active'] ?></div>
                    <div class="tracking-stat-note">Bisa login dan bekerja normal</div>
                </div>
            </article>
            <article class="tracking-stat-card">
                <div class="tracking-stat-icon is-cuti"><?= ems_icon('calendar-days', 'h-5 w-5') ?></div>
                <div class="tracking-stat-body">
                    <div class="tracking-stat-label">Sedang Cuti</div>
                    <div class="tracking-stat-value text-amber-700"><?= $stats['on_cuti'] ?></div>
                    <div class="tracking-stat-note">Sedang berada dalam periode cuti</div>
                </div>
            </article>
            <article class="tracking-stat-card">
                <div class="tracking-stat-icon is-resigned"><?= ems_icon('user-minus', 'h-5 w-5') ?></div>
                <div class="tracking-stat-body">
                    <div class="tracking-stat-label">Resigned</div>
                    <div class="tracking-stat-value text-rose-700"><?= $stats['resigned'] ?></div>
                    <div class="tracking-stat-note">Sudah dinonaktifkan dari sistem</div>
                </div>
            </article>
        </div>

        <!-- Filter -->
        <div class="card card-section tracking-filter-card mb-4">
            <div class="tracking-card-head">
                <div>
                    <div class="tracking-card-title">Filter Monitoring</div>
                    <div class="tracking-card-subtitle">Saring data berdasarkan status dan batch user.</div>
                </div>
            </div>
            <form method="GET" class="tracking-filter-form">
                <div class="tracking-field">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Filter Status</label>
                    <select name="status" class="border border-gray-300 rounded-lg px-3 py-2">
                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Semua Status</option>
                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Aktif</option>
                        <option value="on_cuti" <?= $filterStatus === 'on_cuti' ? 'selected' : '' ?>>Sedang Cuti</option>
                        <option value="resigned" <?= $filterStatus === 'resigned' ? 'selected' : '' ?>>Resigned</option>
                    </select>
                </div>
                <div class="tracking-field">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Filter Batch</label>
                    <select name="batch" class="border border-gray-300 rounded-lg px-3 py-2">
                        <option value="all">Semua Batch</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?= $batch ?>" <?= $filterBatch == $batch ? 'selected' : '' ?>>Batch <?= $batch ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tracking-filter-actions">
                    <button type="submit" class="btn-secondary">Filter</button>
                    <a href="tracking_cuti_resign.php" class="btn-secondary">Reset</a>
                </div>
            </form>
        </div>

        <!-- User List -->
        <div class="card card-section tracking-table-card">
            <div class="tracking-card-head">
                <div>
                    <div class="tracking-card-title">Daftar User</div>
                    <div class="tracking-card-subtitle"><?= count($allUsers) ?> user ditampilkan pada tabel monitoring.</div>
                </div>
                <span class="badge-counter"><?= count($allUsers) ?> user</span>
            </div>
            <div class="table-wrapper">
                <table id="trackingCutiTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Position</th>
                            <th>Batch</th>
                            <th>Status</th>
                            <th>Cuti Progress</th>
                            <th>Detail</th>
                            <?php if ($canApprove): ?>
                            <th>Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$allUsers): ?>
                            <tr>
                                <td colspan="<?= $canApprove ? 7 : 6 ?>" class="muted-placeholder">Tidak ada data user.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allUsers as $u): ?>
                                <?php
                                // Tentukan status user
                                $cutiProgress = null;
                                $cutiPeriodStatus = $u['cuti_period_status'] ?? 'none';

                                if ((int)$u['is_active'] === 0) {
                                    $statusBadge = '<span class="tracking-status-badge tracking-status-badge-resigned">Resigned</span>';
                                    $statusRow = 'bg-red-50';
                                } elseif ($cutiPeriodStatus === 'active') {
                                    $statusBadge = '<span class="tracking-status-badge tracking-status-badge-cuti">Sedang Cuti</span>';
                                    $statusRow = 'bg-amber-50';

                                    // Hitung progress cuti
                                    $cutiProgress = hitung_sisa_cuti($u['cuti_start_date'], $u['cuti_end_date']);
                                } elseif ($cutiPeriodStatus === 'scheduled') {
                                    $statusBadge = '<span class="tracking-status-badge tracking-status-badge-scheduled">Menunggu Cuti</span>';
                                    $statusRow = 'bg-blue-50';
                                } else {
                                    $statusBadge = '<span class="tracking-status-badge tracking-status-badge-active">Aktif</span>';
                                    $statusRow = '';
                                }
                                ?>
                                <tr class="<?= $statusRow ?>">
                                    <td>
                                        <strong><?= htmlspecialchars($u['full_name']) ?></strong>
                                        <div class="meta-text-xs">ID: <?= (int)$u['id'] ?></div>
                                    </td>
                                    <td><?= htmlspecialchars(ems_position_label($u['position'])) ?></td>
                                    <td><?= (int)$u['batch'] ?></td>
                                    <td data-order="<?= (int)($u['tracking_sort_priority'] ?? 99) ?>"><?= $statusBadge ?></td>
                                    <td>
                                        <?php if ($cutiPeriodStatus === 'active' && isset($cutiProgress)): ?>
                                            <div class="w-full">
                                                <div class="flex justify-between text-xs mb-1">
                                                    <span><?= $cutiProgress['used'] ?>/<?= $cutiProgress['total'] ?> hari</span>
                                                    <span><?= $cutiProgress['percentage'] ?>%</span>
                                                </div>
                                                <div class="w-full bg-gray-200 rounded-full h-2.5">
                                                    <div class="bg-amber-500 h-2.5 rounded-full" style="width: <?= $cutiProgress['percentage'] ?>%"></div>
                                                </div>
                                                <div class="text-xs text-gray-600 mt-1">
                                                    Sisa: <strong><?= $cutiProgress['remaining'] ?> hari</strong>
                                                </div>
                                                <div class="meta-text-xs">
                                                    <?= formatTanggalIndo($u['cuti_start_date']) ?> - <?= formatTanggalIndo($u['cuti_end_date']) ?>
                                                </div>
                                            </div>
                                        <?php elseif ($cutiPeriodStatus === 'scheduled'): ?>
                                            <div class="text-sm text-blue-700">
                                                Menunggu mulai cuti
                                                <div class="meta-text-xs">
                                                    <?= formatTanggalIndo($u['cuti_start_date']) ?> - <?= formatTanggalIndo($u['cuti_end_date']) ?>
                                                </div>
                                            </div>
                                        <?php elseif ((int)$u['total_cuti_approved'] > 0): ?>
                                            <div class="text-sm text-gray-600">
                                                <?= (int)$u['total_cuti_approved'] ?>x riwayat cuti
                                            </div>
                                        <?php else: ?>
                                            <span class="meta-text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$u['is_active'] === 0): ?>
                                            <!-- Resigned -->
                                            <div class="text-sm">
                                                <div class="font-medium text-red-700">Resigned</div>
                                                <div class="meta-text-xs">
                                                    <?= formatTanggalIndo($u['resigned_at']) ?>
                                                </div>
                                                <?php if ($u['resigned_by_name']): ?>
                                                    <div class="meta-text-xs">Oleh: <?= htmlspecialchars($u['resigned_by_name']) ?></div>
                                                <?php endif; ?>
                                                <?php if ($u['resign_reason']): ?>
                                                    <details class="mt-1">
                                                        <summary class="cursor-pointer text-xs text-blue-600 hover:text-blue-800">Lihat alasan</summary>
                                                        <div class="mt-1 p-2 bg-gray-50 rounded text-xs whitespace-pre-line"><?= htmlspecialchars($u['resign_reason']) ?></div>
                                                    </details>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($cutiPeriodStatus === 'active'): ?>
                                            <!-- Sedang Cuti -->
                                            <div class="text-sm">
                                                <div class="font-medium text-amber-700">Sedang Cuti</div>
                                                <div class="meta-text-xs">
                                                    Approved: <?= formatTanggalIndo($u['cuti_approved_at']) ?>
                                                </div>
                                                <?php if ($u['cuti_approved_by_name']): ?>
                                                    <div class="meta-text-xs">Oleh: <?= htmlspecialchars($u['cuti_approved_by_name']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($cutiPeriodStatus === 'scheduled'): ?>
                                            <div class="text-sm">
                                                <div class="font-medium text-blue-700">Menunggu Cuti</div>
                                                <div class="meta-text-xs">
                                                    Mulai: <?= formatTanggalIndo($u['cuti_start_date']) ?>
                                                </div>
                                                <?php if ($u['cuti_approved_at']): ?>
                                                    <div class="meta-text-xs">
                                                        Approved: <?= formatTanggalIndo($u['cuti_approved_at']) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($u['cuti_approved_by_name']): ?>
                                                    <div class="meta-text-xs">Oleh: <?= htmlspecialchars($u['cuti_approved_by_name']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <!-- Aktif -->
                                            <div class="text-sm text-gray-600">
                                                User Aktif
                                                <?php if ((int)$u['total_cuti_approved'] > 0): ?>
                                                    <div class="meta-text-xs"><?= (int)$u['total_cuti_approved'] ?>x cuti sebelumnya</div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($canApprove): ?>
                                    <td>
                                        <?php if ($cutiPeriodStatus === 'active' && isset($cutiProgress)): ?>
                                            <button type="button" 
                                                    onclick="kembaliKerja(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['full_name']) ?>', <?= (int)$cutiProgress['total'] ?>, <?= (int)$cutiProgress['used'] ?>)" 
                                                    class="btn-success btn-sm">
                                                <?= ems_icon('arrow-left-on-rectangle', 'h-4 w-4') ?>
                                                <span>Kembali Kerja</span>
                                            </button>
                                        <?php else: ?>
                                            <span class="meta-text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Legend -->
        <div class="card card-section mt-4">
            <div class="tracking-card-head">
                <div>
                    <div class="tracking-card-title">Legend</div>
                    <div class="tracking-card-subtitle">Arti status yang muncul di tabel monitoring.</div>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 text-sm">
                <div class="tracking-legend-card">
                    <span class="tracking-status-badge tracking-status-badge-active">Aktif</span>
                    <p class="mt-1 text-gray-600">User dapat login dan bekerja normal.</p>
                </div>
                <div class="tracking-legend-card">
                    <span class="tracking-status-badge tracking-status-badge-cuti">Sedang Cuti</span>
                    <p class="mt-1 text-gray-600">User dalam masa cuti. Progress bar menunjukkan sisa hari cuti.</p>
                </div>
                <div class="tracking-legend-card">
                    <span class="tracking-status-badge tracking-status-badge-scheduled">Menunggu Cuti</span>
                    <p class="mt-1 text-gray-600">Cuti sudah disetujui tetapi tanggal mulai belum berjalan.</p>
                </div>
                <div class="tracking-legend-card">
                    <span class="tracking-status-badge tracking-status-badge-resigned">Resigned</span>
                    <p class="mt-1 text-gray-600">User sudah resign dan dinonaktifkan. Tidak bisa login.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Modal untuk Kembali Kerja -->
<div id="kembaliKerjaModal" class="modal-overlay hidden">
    <div class="modal-card max-w-md">
        <div class="modal-header">
            <h3 id="kembaliKerjaTitle" class="text-base font-semibold">Konfirmasi Kembali Kerja</h3>
            <button type="button" class="btn-secondary btn-compact btn-cancel" onclick="closeKembaliKerjaModal()" aria-label="Tutup">
                <?= ems_icon('x-mark', 'h-4 w-4') ?>
            </button>
        </div>
        <div class="modal-body">
            <p id="kembaliKerjaMessage" class="text-sm text-gray-600 mb-4"></p>
            
            <div class="bg-blue-50 p-3 rounded-lg mb-4">
                <div class="text-sm font-medium text-blue-800 mb-2">Info Cuti:</div>
                <div class="text-sm text-blue-700">
                    <div>Total cuti: <strong id="kembaliKerjaTotal">0</strong> hari</div>
                    <div>Digunakan: <strong id="kembaliKerjaUsed">0</strong> hari</div>
                    <div>Sisa cuti: <strong id="kembaliKerjaRemaining" class="text-red-600">0</strong> hari (dipotong)</div>
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Catatan (Opsional)</label>
                <textarea id="kembaliKerjaNotes" class="w-full border border-gray-300 rounded-lg p-2 text-sm" rows="2" placeholder="Catatan kembali kerja..."></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" onclick="closeKembaliKerjaModal()" class="btn-secondary">Batal</button>
                <button type="button" id="kembaliKerjaConfirmBtn" class="btn-success">Konfirmasi</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// KEMBALI KERJA HANDLER
// ============================================
var currentUserId = null;
var currentUserName = '';
var currentTotalDays = 0;
var currentUsedDays = 0;
var csrfToken = '<?= generateCsrfToken() ?>';

function kembaliKerja(userId, userName, totalDays, usedDays) {
    console.log('kembaliKerja called:', userId, userName, totalDays, usedDays);
    
    currentUserId = userId;
    currentUserName = userName;
    currentTotalDays = totalDays;
    currentUsedDays = usedDays;
    
    var modal = document.getElementById('kembaliKerjaModal');
    var message = document.getElementById('kembaliKerjaMessage');
    var totalEl = document.getElementById('kembaliKerjaTotal');
    var usedEl = document.getElementById('kembaliKerjaUsed');
    var remainingEl = document.getElementById('kembaliKerjaRemaining');
    
    if (!modal || !message) {
        alert('Error: Modal tidak ditemukan!');
        return;
    }
    
    message.textContent = 'Apakah Anda yakin ingin mengakhiri cuti ' + userName + ' dan mengembalikan ke kerja?';
    totalEl.textContent = totalDays;
    usedEl.textContent = usedDays;
    remainingEl.textContent = (totalDays - usedDays) + ' hari';
    
    modal.classList.remove('hidden');
}

function closeKembaliKerjaModal() {
    var modal = document.getElementById('kembaliKerjaModal');
    if (modal) {
        modal.classList.add('hidden');
    }
    var notesInput = document.getElementById('kembaliKerjaNotes');
    if (notesInput) {
        notesInput.value = '';
    }
    currentUserId = null;
    currentUserName = '';
    currentTotalDays = 0;
    currentUsedDays = 0;
}

// Bind confirm button
document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#trackingCutiTable').DataTable({
            pageLength: 10,
            scrollX: true,
            autoWidth: false,
            order: [[3, 'asc'], [0, 'asc']],
            language: {
                url: '/assets/design/js/datatables-id.json'
            },
            columnDefs: [
                { orderable: false, targets: <?= $canApprove ? '[4, 5, 6]' : '[4, 5]' ?> }
            ]
        });
    }

    var confirmBtn = document.getElementById('kembaliKerjaConfirmBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            if (!currentUserId) {
                alert('Error: Tidak ada user yang dipilih!');
                return;
            }
            
            var formData = new FormData();
            formData.append('action', 'kembali_cuti');
            formData.append('user_id', currentUserId);
            formData.append('csrf_token', csrfToken);
            
            var notes = document.getElementById('kembaliKerjaNotes').value.trim();
            if (notes) {
                formData.append('notes', notes);
            }
            
            console.log('Submitting kembali kerja:', currentUserId);
            
            fetch(window.emsUrl('/dashboard/tracking_cuti_resign_action.php'), {
                method: 'POST',
                body: formData
            })
            .then(function(response) { 
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json(); 
            })
            .then(function(data) {
                if (data.success) {
                    alert(data.message || 'User berhasil dikembalikan ke kerja!');
                    window.location.reload();
                } else {
                    alert(data.error || 'Terjadi kesalahan!');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan: ' + error.message);
            });
            
            closeKembaliKerjaModal();
        });
    }
    
    // Close modal when clicking outside
    var modal = document.getElementById('kembaliKerjaModal');
    if (modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeKembaliKerjaModal();
            }
        });
    }
});
</script>

<style>
.tracking-hero {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    border: 1px solid rgba(186, 230, 253, 0.9);
    border-radius: 1.5rem;
    background: linear-gradient(135deg, rgba(224, 242, 254, 0.95), rgba(255, 255, 255, 0.96));
}

.tracking-kicker {
    margin-bottom: 0.35rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #0369a1;
}

.tracking-hero-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 800;
    color: #0f172a;
}

.tracking-hero-copy {
    margin-top: 0.45rem;
    max-width: 48rem;
    font-size: 0.92rem;
    color: #475569;
}

.tracking-hero-meta {
    min-width: 9rem;
    align-self: flex-start;
    padding: 0.9rem 1rem;
    border-radius: 1rem;
    background: rgba(12, 74, 110, 0.08);
    text-align: right;
}

.tracking-hero-meta-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #0369a1;
}

.tracking-hero-meta-value {
    margin-top: 0.3rem;
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
}

.tracking-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}

.tracking-stat-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.1rem 1.15rem;
    border: 1px solid rgba(226, 232, 240, 0.9);
    border-radius: 1.25rem;
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
}

.tracking-stat-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 3rem;
    border-radius: 1rem;
    flex-shrink: 0;
}

.tracking-stat-icon.is-total {
    background: rgba(14, 165, 233, 0.12);
    color: #0369a1;
}

.tracking-stat-icon.is-active {
    background: rgba(16, 185, 129, 0.12);
    color: #047857;
}

.tracking-stat-icon.is-cuti {
    background: rgba(245, 158, 11, 0.14);
    color: #b45309;
}

.tracking-stat-icon.is-resigned {
    background: rgba(244, 63, 94, 0.12);
    color: #be123c;
}

.tracking-stat-body {
    min-width: 0;
}

.tracking-stat-label {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}

.tracking-stat-value {
    margin-top: 0.2rem;
    font-size: 2rem;
    line-height: 1;
    font-weight: 800;
    color: #0f172a;
}

.tracking-stat-note {
    margin-top: 0.35rem;
    font-size: 0.82rem;
    color: #64748b;
}

.tracking-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.tracking-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}

.tracking-card-subtitle {
    margin-top: 0.2rem;
    font-size: 0.86rem;
    color: #64748b;
}

.tracking-filter-card {
    padding: 1.25rem;
}

.tracking-filter-form {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
    align-items: end;
}

.tracking-field {
    min-width: 0;
}

.tracking-filter-actions {
    display: flex;
    gap: 0.75rem;
    align-items: end;
    justify-content: flex-start;
    flex-wrap: wrap;
}

.tracking-table-card {
    overflow: hidden;
}

.tracking-legend-card {
    padding: 1rem;
    border: 1px solid rgba(226, 232, 240, 0.9);
    border-radius: 1rem;
    background: rgba(248, 250, 252, 0.72);
}

.tracking-status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 1.8rem;
    padding: 0.2rem 0.75rem;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    line-height: 1;
    text-transform: uppercase;
    white-space: nowrap;
    border: 1px solid transparent;
}

.tracking-status-badge-active {
    background: #dcfce7;
    border-color: #a7f3d0;
    color: #166534;
}

.tracking-status-badge-cuti {
    background: #fef3c7;
    border-color: #fcd34d;
    color: #92400e;
}

.tracking-status-badge-scheduled {
    background: #dbeafe;
    border-color: #93c5fd;
    color: #1d4ed8;
}

.tracking-status-badge-resigned {
    background: #ffe4e6;
    border-color: #fda4af;
    color: #be123c;
}

/* Modal Overlay - Full screen fixed position */
.modal-overlay {
    position: fixed;
    inset: 0;
    background-color: rgba(2, 6, 23, 0.4);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

/* Modal Card - Centered content */
.modal-card {
    position: relative;
    background-color: white;
    border-radius: 1rem;
    box-shadow: 0 14px 40px rgba(15, 23, 42, 0.08);
    max-height: 90vh;
    overflow-y: auto;
}

.modal-card.max-w-md {
    max-width: 28rem;
    width: 100%;
}

/* Hide scrollbar for modal body */
.modal-body {
    -ms-overflow-style: none;
    scrollbar-width: none;
}

.modal-body::-webkit-scrollbar {
    display: none;
}

/* Modal hidden state */
.modal-overlay.hidden {
    display: none !important;
}

@media (max-width: 1100px) {
    .tracking-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .tracking-filter-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .tracking-hero {
        flex-direction: column;
    }

    .tracking-hero-meta {
        width: 100%;
        text-align: left;
    }

    .tracking-stats-grid,
    .tracking-filter-form {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
