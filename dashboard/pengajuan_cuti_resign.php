<?php
/**
 * HALAMAN PENGAJUAN CUTI DAN RESIGN
 *
 * Fitur:
 * - Form pengajuan cuti dengan tanggal dan alasan
 * - Form pengajuan resign dengan alasan
 * - List riwayat pengajuan
 * - Approval/reject untuk role Manager+
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
$medicName = $user['name'] ?? 'User';
$medicJabatan = ems_position_label($user['position'] ?? '');
$canApprove = can_approve_cuti_resign($userRole);

// Page title
$pageTitle = 'Pengajuan Cuti dan Resign';

// Flash messages
$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

// Ambil tab aktif dari URL (cuti atau resign)
$activeTab = $_GET['tab'] ?? 'cuti';
if (!in_array($activeTab, ['cuti', 'resign', 'approval'])) {
    $activeTab = 'cuti';
}

// Variabel untuk data
$myCutiRequests = [];
$myResignRequests = [];
$pendingCuti = [];
$pendingResign = [];

try {
    // Ambil riwayat pengajuan cuti user sendiri
    $stmt = $pdo->prepare("
        SELECT
            cr.*,
            u.full_name as approved_by_name
        FROM cuti_requests cr
        LEFT JOIN user_rh u ON u.id = cr.approved_by
        WHERE cr.user_id = ?
        ORDER BY cr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $myCutiRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil riwayat pengajuan resign user sendiri
    $stmt = $pdo->prepare("
        SELECT
            rr.*,
            u.full_name as approved_by_name
        FROM resign_requests rr
        LEFT JOIN user_rh u ON u.id = rr.approved_by
        WHERE rr.user_id = ?
        ORDER BY rr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $myResignRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Jika user bisa approve, ambil semua request pending
    if ($canApprove) {
        // Ambil semua cuti pending
        $stmt = $pdo->query("
            SELECT
                cr.*,
                u.full_name,
                u.position,
                u.role,
                u.batch,
                approver.full_name as approved_by_name
            FROM cuti_requests cr
            INNER JOIN user_rh u ON u.id = cr.user_id
            LEFT JOIN user_rh approver ON approver.id = cr.approved_by
            WHERE cr.status = 'pending'
            ORDER BY cr.created_at ASC
            LIMIT 100
        ");
        $pendingCuti = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ambil semua resign pending
        $stmt = $pdo->query("
            SELECT
                rr.*,
                u.full_name,
                u.position,
                u.role,
                u.batch,
                approver.full_name as approved_by_name
            FROM resign_requests rr
            INNER JOIN user_rh u ON u.id = rr.user_id
            LEFT JOIN user_rh approver ON approver.id = rr.approved_by
            WHERE rr.status = 'pending'
            ORDER BY rr.created_at ASC
            LIMIT 100
        ");
        $pendingResign = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat data: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <!-- Hidden CSRF Token for JavaScript -->
    <input type="hidden" id="csrf_token_input" value="<?= generateCsrfToken() ?>">
    
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="section-intro">Formulir pengajuan cuti dan resign untuk tenaga medis EMS.</p>

        <!-- Flash Messages -->
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $warning): ?>
            <div class="alert alert-warning"><?= htmlspecialchars((string)$warning) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error) ?></div>
        <?php endforeach; ?>

        <!-- Tab Navigation -->
        <div class="card card-section request-tab-shell mb-4">
            <div class="request-tab-list">
                <a href="?tab=cuti" class="request-tab-link <?= $activeTab === 'cuti' ? 'is-active' : '' ?>">
                    <?= ems_icon('calendar', 'h-4 w-4 inline mr-1') ?>
                    <span>Pengajuan Cuti</span>
                </a>
                <a href="?tab=resign" class="request-tab-link <?= $activeTab === 'resign' ? 'is-active' : '' ?>">
                    <?= ems_icon('user-minus', 'h-4 w-4 inline mr-1') ?>
                    <span>Pengajuan Resign</span>
                </a>
                <?php if ($canApprove): ?>
                <a href="?tab=approval" class="request-tab-link <?= $activeTab === 'approval' ? 'is-active' : '' ?>">
                    <?= ems_icon('check-circle', 'h-4 w-4 inline mr-1') ?>
                    <span>Approval Request</span>
                    <?php if (count($pendingCuti) + count($pendingResign) > 0): ?>
                        <span class="request-tab-badge"><?= count($pendingCuti) + count($pendingResign) ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB CUTI -->
        <?php if ($activeTab === 'cuti'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Form Pengajuan Cuti -->
                <div class="card card-section request-card">
                    <div class="request-card-header">
                        <div>
                            <div class="request-card-title">Form Pengajuan Cuti</div>
                            <div class="request-card-subtitle">Ajukan periode cuti dan jelaskan alasan IC maupun OOC.</div>
                        </div>
                        <span class="request-status-badge request-status-pending">Form Aktif</span>
                    </div>
                    <form method="POST" action="pengajuan_cuti_resign_action.php" class="form">
                        <?= csrfField(); ?>
                        <input type="hidden" name="action" value="submit_cuti">
                        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                        <div class="row-form-2">
                            <div class="col">
                                <label>Tanggal Mulai Cuti</label>
                                <input type="date" name="start_date" required min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col">
                                <label>Tanggal Selesai Cuti</label>
                                <input type="date" name="end_date" required min="<?= date('Y-m-d') ?>">
                            </div>
                        </div>

                        <label>Alasan IC (In Character)</label>
                        <textarea name="reason_ic" rows="3" required placeholder="Contoh: Istirahat sejenak, keperluan keluarga, dll."></textarea>

                        <label>Alasan OOC (Out of Character)</label>
                        <textarea name="reason_ooc" rows="3" required placeholder="Contoh: Libur kerja, acara keluarga, dll."></textarea>

                        <div class="request-info-box mt-4">
                            <strong>Info:</strong>
                            <ul class="list-disc list-inside mt-2 text-sm">
                                <li>Tanggal cuti akan dihitung otomatis</li>
                                <li>Setelah disetujui, status Anda akan berubah menjadi "Sedang Cuti"</li>
                                <li>Anda tetap bisa login selama masa cuti</li>
                            </ul>
                        </div>

                        <div class="modal-actions mt-4">
                            <button type="submit" class="btn-success">
                                <?= ems_icon('paper-airplane', 'h-4 w-4') ?>
                                <span>Ajukan Cuti</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Riwayat Pengajuan Cuti -->
                <div class="card card-section request-card request-table-card">
                    <div class="request-card-header">
                        <div>
                            <div class="request-card-title">Riwayat Pengajuan Cuti</div>
                            <div class="request-card-subtitle">Pantau status pengajuan cuti Anda dengan pencarian dan sorting.</div>
                        </div>
                    </div>
                    <div class="table-wrapper table-wrapper-sm">
                        <table id="cutiHistoryTable" class="table-custom">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Tanggal</th>
                                    <th>Durasi</th>
                                    <th>Status</th>
                                    <th>Alasan Ditolak</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($myCutiRequests): ?>
                                    <?php foreach ($myCutiRequests as $req): ?>
                                        <?php
                                        $badge = get_status_badge($req['status']);
                                        $rejectionReason = $req['rejection_reason'] ?? '';
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($req['request_code']) ?></strong>
                                                <div class="meta-text-xs"><?= formatTanggalIndo($req['created_at']) ?></div>
                                            </td>
                                            <td>
                                                <?= formatTanggalIndo($req['start_date']) ?> -<br>
                                                <?= formatTanggalIndo($req['end_date']) ?>
                                            </td>
                                            <td>
                                                <strong><?= (int)$req['days_total'] ?> hari</strong>
                                            </td>
                                            <td>
                                                <span class="request-status-badge request-status-<?= htmlspecialchars($req['status']) ?>"><?= $badge['label'] ?></span>
                                                <?php if ($req['status'] === 'approved' && $req['approved_by_name']): ?>
                                                    <div class="meta-text-xs">Oleh <?= htmlspecialchars($req['approved_by_name']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($req['status'] === 'rejected' && $rejectionReason): ?>
                                                    <div class="text-sm text-red-600 max-w-xs truncate" title="<?= htmlspecialchars($rejectionReason) ?>">
                                                        <?= htmlspecialchars($rejectionReason) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="meta-text-xs">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php if (!$myCutiRequests): ?>
                            <div class="muted-placeholder p-4">Belum ada pengajuan cuti.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB RESIGN -->
        <?php if ($activeTab === 'resign'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Form Pengajuan Resign -->
                <div class="card card-section request-card">
                    <div class="request-card-header">
                        <div>
                            <div class="request-card-title">Form Pengajuan Resign</div>
                            <div class="request-card-subtitle">Gunakan hanya jika Anda benar-benar siap keluar dari EMS.</div>
                        </div>
                        <span class="request-status-badge request-status-rejected">Perlu Konfirmasi</span>
                    </div>
                    <form method="POST" action="pengajuan_cuti_resign_action.php" class="form">
                        <?= csrfField(); ?>
                        <input type="hidden" name="action" value="submit_resign">
                        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                        <label>Alasan IC (In Character)</label>
                        <textarea name="reason_ic" rows="3" required placeholder="Contoh: Pulang ke kampung halaman, buka praktik sendiri, dll."></textarea>

                        <label>Alasan OOC (Out of Character)</label>
                        <textarea name="reason_ooc" rows="3" required placeholder="Contoh: Ada kerjaan lain, fokus kuliah, dll."></textarea>

                        <div class="request-danger-box mt-4">
                            <strong>PERHATIAN!</strong>
                            <ul class="list-disc list-inside mt-2 text-sm">
                                <li>Resign yang sudah disetujui TIDAK BISA dibatalkan</li>
                                <li>Akun Anda akan dinonaktifkan secara otomatis</li>
                                <li>Anda tidak akan bisa login setelah resign disetujui</li>
                                <li>Data Anda akan tetap tersimpan untuk arsip</li>
                            </ul>
                        </div>

                        <div class="modal-actions mt-4">
                            <button type="submit" class="btn-resign-soft">
                                <?= ems_icon('exclamation-triangle', 'h-4 w-4') ?>
                                <span>Ajukan Resign</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Riwayat Pengajuan Resign -->
                <div class="card card-section request-card request-table-card">
                    <div class="request-card-header">
                        <div>
                            <div class="request-card-title">Riwayat Pengajuan Resign</div>
                            <div class="request-card-subtitle">Lihat status resign dan alasan penolakan jika ada.</div>
                        </div>
                    </div>
                    <div class="table-wrapper table-wrapper-sm">
                        <table id="resignHistoryTable" class="table-custom">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Tanggal</th>
                                    <th>Alasan</th>
                                    <th>Status</th>
                                    <th>Alasan Ditolak</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($myResignRequests): ?>
                                    <?php foreach ($myResignRequests as $req): ?>
                                        <?php
                                        $badge = get_status_badge($req['status']);
                                        $reasonIC = strlen($req['reason_ic'] ?? '') > 50
                                            ? substr($req['reason_ic'], 0, 50) . '...'
                                            : ($req['reason_ic'] ?? '-');
                                        $rejectionReason = $req['rejection_reason'] ?? '';
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($req['request_code']) ?></strong>
                                                <div class="meta-text-xs"><?= formatTanggalIndo($req['created_at']) ?></div>
                                            </td>
                                            <td>
                                                <?= formatTanggalIndo($req['created_at']) ?>
                                            </td>
                                            <td>
                                                <div class="text-sm"><?= htmlspecialchars($reasonIC) ?></div>
                                            </td>
                                            <td>
                                                <span class="request-status-badge request-status-<?= htmlspecialchars($req['status']) ?>"><?= $badge['label'] ?></span>
                                                <?php if ($req['status'] === 'approved' && $req['approved_by_name']): ?>
                                                    <div class="meta-text-xs">Oleh <?= htmlspecialchars($req['approved_by_name']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($req['status'] === 'rejected' && $rejectionReason): ?>
                                                    <div class="text-sm text-red-600 max-w-xs truncate" title="<?= htmlspecialchars($rejectionReason) ?>">
                                                        <?= htmlspecialchars($rejectionReason) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="meta-text-xs">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <?php if (!$myResignRequests): ?>
                            <div class="muted-placeholder p-4">Belum ada pengajuan resign.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB APPROVAL (Hanya untuk Manager+) -->
        <?php if ($activeTab === 'approval' && $canApprove): ?>
            <div class="grid grid-cols-1 gap-4">
                <!-- Pending Cuti Requests -->
                <?php if ($pendingCuti): ?>
                    <div class="card card-section request-approval-card request-table-card">
                        <div class="request-card-header">
                            <div>
                                <div class="request-card-title">Pengajuan Cuti Pending</div>
                                <div class="request-card-subtitle"><?= count($pendingCuti) ?> pengajuan menunggu review.</div>
                            </div>
                        </div>
                        <div class="table-wrapper table-wrapper-sm">
                            <table id="approvalCutiTable" class="table-custom">
                                <thead>
                                    <tr>
                                        <th>Nama</th>
                                        <th>Kode</th>
                                        <th>Tanggal Cuti</th>
                                        <th>Durasi</th>
                                        <th>Alasan IC</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingCuti as $req): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($req['full_name']) ?></strong>
                                                <div class="meta-text-xs"><?= ems_position_label($req['position']) ?> | Batch <?= (int)$req['batch'] ?></div>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($req['request_code']) ?></strong>
                                                <div class="meta-text-xs"><?= formatTanggalIndo($req['created_at']) ?></div>
                                            </td>
                                            <td><?= formatTanggalIndo($req['start_date']) ?> - <?= formatTanggalIndo($req['end_date']) ?></td>
                                            <td><strong><?= (int)$req['days_total'] ?> hari</strong></td>
                                            <td><div class="text-sm max-w-xs truncate" title="<?= htmlspecialchars($req['reason_ic'] ?? '-') ?>"><?= htmlspecialchars($req['reason_ic'] ?? '-') ?></div></td>
                                            <td class="action-cell">
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" onclick="approveCuti(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['request_code']) ?>')" class="btn-success btn-sm">
                                                        <?= ems_icon('check', 'h-4 w-4') ?>
                                                        <span>Setujui</span>
                                                    </button>
                                                    <button type="button" onclick="rejectCuti(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['request_code']) ?>')" class="btn-reject-soft btn-sm">
                                                        <?= ems_icon('x', 'h-4 w-4') ?>
                                                        <span>Tolak</span>
                                                    </button>
                                                    <button type="button" onclick="viewCutiDetail(<?= (int)$req['id'] ?>)" class="btn-secondary btn-sm">
                                                        <?= ems_icon('eye', 'h-4 w-4') ?>
                                                        <span>Detail</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card card-section">
                        <div class="card-header">Pengajuan Cuti Pending</div>
                        <div class="muted-placeholder">Tidak ada pengajuan cuti yang menunggu approval.</div>
                    </div>
                <?php endif; ?>

                <!-- Pending Resign Requests -->
                <?php if ($pendingResign): ?>
                    <div class="card card-section request-approval-card is-resign request-table-card">
                        <div class="request-card-header">
                            <div>
                                <div class="request-card-title">Pengajuan Resign Pending</div>
                                <div class="request-card-subtitle"><?= count($pendingResign) ?> pengajuan resign menunggu keputusan.</div>
                            </div>
                        </div>
                        <div class="table-wrapper table-wrapper-sm">
                            <table id="approvalResignTable" class="table-custom">
                                <thead>
                                    <tr>
                                        <th>Nama</th>
                                        <th>Kode</th>
                                        <th>Role</th>
                                        <th>Alasan IC</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingResign as $req): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($req['full_name']) ?></strong>
                                                <div class="meta-text-xs"><?= ems_position_label($req['position']) ?> | Batch <?= (int)$req['batch'] ?></div>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($req['request_code']) ?></strong>
                                                <div class="meta-text-xs"><?= formatTanggalIndo($req['created_at']) ?></div>
                                            </td>
                                            <td><?= htmlspecialchars($req['role']) ?></td>
                                            <td><div class="text-sm max-w-xs truncate" title="<?= htmlspecialchars($req['reason_ic'] ?? '-') ?>"><?= htmlspecialchars($req['reason_ic'] ?? '-') ?></div></td>
                                            <td class="action-cell">
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" onclick="approveResign(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['request_code']) ?>', '<?= htmlspecialchars($req['full_name']) ?>')" class="btn-resign-soft btn-sm">
                                                        <?= ems_icon('check', 'h-4 w-4') ?>
                                                        <span>Setujui Resign</span>
                                                    </button>
                                                    <button type="button" onclick="rejectResign(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['request_code']) ?>')" class="btn-reject-soft btn-sm">
                                                        <?= ems_icon('x', 'h-4 w-4') ?>
                                                        <span>Tolak</span>
                                                    </button>
                                                    <button type="button" onclick="viewResignDetail(<?= (int)$req['id'] ?>)" class="btn-secondary btn-sm">
                                                        <?= ems_icon('eye', 'h-4 w-4') ?>
                                                        <span>Detail</span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card card-section">
                        <div class="card-header">Pengajuan Resign Pending</div>
                        <div class="muted-placeholder">Tidak ada pengajuan resign yang menunggu approval.</div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
// ============================================
// SISTEM CUTI & RESIGN - JAVASCRIPT HANDLER
// ============================================

// Helper functions
function formatTanggalIndo(date) {
    if (!date) return '-';
    // Handle YYYY-MM-DD format
    if (typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date)) {
        var parts = date.split('-');
        var months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        return parseInt(parts[2]) + ' ' + months[parseInt(parts[1]) - 1] + ' ' + parts[0];
    }
    var d = new Date(date);
    var months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
}

function ems_position_label(position) {
    var positions = {
        'doctor': 'Dokter',
        'nurse': 'Perawat',
        'paramedic': 'Paramedis',
        'pharmacist': 'Apoteker',
        'admin': 'Admin',
        'manager': 'Manager',
        'head': 'Kepala',
        'specialist': 'Spesialis'
    };
    return positions[position] || position || '-';
}

function get_status_badge(status) {
    var badges = {
        'pending': { class: 'request-status-badge request-status-pending', label: 'Pending' },
        'approved': { class: 'request-status-badge request-status-approved', label: 'Disetujui' },
        'rejected': { class: 'request-status-badge request-status-rejected', label: 'Ditolak' }
    };
    return badges[status] || { class: 'request-status-badge', label: status || '-' };
}

function htmlspecialchars(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Global variables - initialized when DOM is ready
var currentAction = null;
var currentRequestId = null;
var csrfToken = '';

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Get CSRF token from hidden input
    var csrfInput = document.getElementById('csrf_token_input');
    if (csrfInput) {
        csrfToken = csrfInput.value;
    } else {
        // Fallback: try to get from meta tag
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            csrfToken = meta.getAttribute('content');
        }
    }
    console.log('CSRF Token loaded:', csrfToken ? 'Yes' : 'No');
});

// ============================================
// MODAL FUNCTIONS
// ============================================

// Show approval/rejection modal
function showModal(title, message, action, requestId, showRejection) {
    if (showRejection === undefined) showRejection = false;

    var modalTitle = document.getElementById('approvalModalTitle');
    var modalMessage = document.getElementById('approvalModalMessage');
    var modal = document.getElementById('approvalModal');

    if (!modal || !modalTitle || !modalMessage) {
        console.error('Modal elements not found!');
        alert('Error: Modal tidak ditemukan!');
        return;
    }

    modalTitle.textContent = title;
    modalMessage.textContent = message;
    currentAction = action;
    currentRequestId = requestId;

    var rejectionSection = document.getElementById('approvalRejectionSection');
    var confirmBtn = document.getElementById('approvalConfirmBtn');

    if (showRejection) {
        rejectionSection.classList.remove('hidden');
        confirmBtn.className = 'btn-reject-soft';
        confirmBtn.textContent = 'Tolak';
    } else {
        rejectionSection.classList.add('hidden');
        if (action === 'approve_resign') {
            confirmBtn.className = 'btn-resign-soft';
            confirmBtn.textContent = 'Setujui Resign';
        } else {
            confirmBtn.className = 'btn-success';
            confirmBtn.textContent = 'Setujui';
        }
    }

    // Show modal using design system class
    modal.classList.remove('hidden');
    
    console.log('Modal shown for action:', action, 'requestId:', requestId);
}

// Close approval modal
function closeModal() {
    var modal = document.getElementById('approvalModal');
    if (modal) {
        modal.classList.add('hidden');
    }
    var rejectionInput = document.getElementById('approvalRejectionReason');
    if (rejectionInput) {
        rejectionInput.value = '';
    }
    currentAction = null;
    currentRequestId = null;
}

// Close detail modal
function closeDetailModal() {
    var modal = document.getElementById('detailModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

// ============================================
// BUTTON CLICK HANDLERS (Global scope)
// ============================================

// Approve Cuti
function approveCuti(id, code) {
    console.log('approveCuti called:', id, code);
    showModal('Setujui Cuti', 'Apakah Anda yakin ingin menyetujui pengajuan cuti ' + code + '?', 'approve_cuti', id);
}

// Reject Cuti
function rejectCuti(id, code) {
    console.log('rejectCuti called:', id, code);
    showModal('Tolak Cuti', 'Apakah Anda yakin ingin menolak pengajuan cuti ' + code + '?', 'reject_cuti', id, true);
}

// Approve Resign
function approveResign(id, code, name) {
    console.log('approveResign called:', id, code, name);
    showModal('Setujui Resign', 'PERINGATAN: User ' + name + ' akan dinonaktifkan dan tidak bisa login lagi. Lanjutkan?', 'approve_resign', id);
}

// Reject Resign
function rejectResign(id, code) {
    console.log('rejectResign called:', id, code);
    showModal('Tolak Resign', 'Apakah Anda yakin ingin menolak pengajuan resign ' + code + '?', 'reject_resign', id, true);
}

// View Cuti Detail
function viewCutiDetail(id) {
    console.log('viewCutiDetail called:', id);
    var url = window.emsUrl('/dashboard/pengajuan_cuti_resign_action.php?action=get_cuti_detail&request_id=' + id);
    console.log('Fetching:', url);
    
    fetch(url)
        .then(function(response) { 
            console.log('Response status:', response.status);
            return response.json(); 
        })
        .then(function(data) {
            console.log('Response data:', data);
            if (data.success && data.data) {
                showCutiDetailModal(data.data);
            } else {
                console.error('Error:', data.error);
                alert('Gagal memuat detail cuti: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(function(error) {
            console.error('Fetch error:', error);
            alert('Terjadi kesalahan koneksi!');
        });
}

// View Resign Detail
function viewResignDetail(id) {
    console.log('viewResignDetail called:', id);
    var url = window.emsUrl('/dashboard/pengajuan_cuti_resign_action.php?action=get_resign_detail&request_id=' + id);
    console.log('Fetching:', url);
    
    fetch(url)
        .then(function(response) { 
            console.log('Response status:', response.status);
            return response.json(); 
        })
        .then(function(data) {
            console.log('Response data:', data);
            if (data.success && data.data) {
                showResignDetailModal(data.data);
            } else {
                console.error('Error:', data.error);
                alert('Gagal memuat detail resign: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(function(error) {
            console.error('Fetch error:', error);
            alert('Terjadi kesalahan koneksi!');
        });
}

// ============================================
// DETAIL MODAL FUNCTIONS
// ============================================

function showCutiDetailModal(data) {
    var modal = document.getElementById('detailModal');
    var title = document.getElementById('detailTitle');
    var content = document.getElementById('detailContent');

    if (!modal || !title || !content) {
        alert('Error: Detail modal elements not found!');
        return;
    }

    title.textContent = 'Detail Pengajuan Cuti - ' + htmlspecialchars(data.request_code);

    var statusBadge = get_status_badge(data.status);

    content.innerHTML = `
        <div class="space-y-4">
            <div class="request-detail-hero">
                <div class="request-detail-panel">
                    <div class="request-detail-label">Tenaga Medis</div>
                    <div class="text-xl font-extrabold text-slate-900">${htmlspecialchars(data.full_name)}</div>
                    <div class="request-detail-meta mt-2">${ems_position_label(data.position)} | Batch ${parseInt(data.batch)} | ${htmlspecialchars(data.role)}</div>
                </div>
                <div class="request-detail-stat">
                    <div class="request-detail-label">Status Request</div>
                    <span class="${statusBadge.class}">${statusBadge.label}</span>
                    <div class="request-detail-meta mt-3">Diajukan ${formatTanggalIndo(data.created_at)}</div>
                </div>
            </div>

            <div class="request-detail-grid">
                <div class="request-detail-block">
                    <div class="request-detail-label">Tanggal Mulai</div>
                    <div class="request-detail-value">${formatTanggalIndo(data.start_date)}</div>
                </div>
                <div class="request-detail-block">
                    <div class="request-detail-label">Tanggal Selesai</div>
                    <div class="request-detail-value">${formatTanggalIndo(data.end_date)}</div>
                </div>
                <div class="request-detail-block">
                    <div class="request-detail-label">Approver</div>
                    <div class="request-detail-value">${data.approved_by ? htmlspecialchars(data.approved_by_name) : '-'}</div>
                    ${data.approved_by ? `<div class="request-detail-meta mt-1">${formatTanggalIndo(data.approved_at)}</div>` : ''}
                </div>
                <div class="request-detail-stat">
                    <div class="request-detail-label">Durasi Cuti</div>
                    <div class="text-3xl font-extrabold text-sky-700">${parseInt(data.days_total)} Hari</div>
                    <div class="request-detail-meta mt-2">Dihitung otomatis dari tanggal pengajuan</div>
                </div>
            </div>

            <div class="request-detail-block">
                <div class="request-detail-label">Alasan IC (In Character)</div>
                <div class="request-detail-value whitespace-pre-line">${htmlspecialchars(data.reason_ic)}</div>
            </div>

            <div class="request-detail-block">
                <div class="request-detail-label">Alasan OOC (Out of Character)</div>
                <div class="request-detail-value whitespace-pre-line">${htmlspecialchars(data.reason_ooc)}</div>
            </div>

            ${data.rejection_reason ? `
            <div class="request-detail-block request-detail-note-danger">
                <div class="request-detail-label">Alasan Penolakan</div>
                <div class="whitespace-pre-line">${htmlspecialchars(data.rejection_reason)}</div>
            </div>
            ` : ''}
        </div>
    `;

    // Show modal using proper class
    modal.classList.remove('hidden');
}

function showResignDetailModal(data) {
    var modal = document.getElementById('detailModal');
    var title = document.getElementById('detailTitle');
    var content = document.getElementById('detailContent');

    if (!modal || !title || !content) {
        alert('Error: Detail modal elements not found!');
        return;
    }

    title.textContent = 'Detail Pengajuan Resign - ' + htmlspecialchars(data.request_code);

    var statusBadge = get_status_badge(data.status);

    content.innerHTML = `
        <div class="space-y-4">
            <div class="request-detail-hero is-resign">
                <div class="request-detail-panel">
                    <div class="request-detail-label">Tenaga Medis</div>
                    <div class="text-xl font-extrabold text-slate-900">${htmlspecialchars(data.full_name)}</div>
                    <div class="request-detail-meta mt-2">${ems_position_label(data.position)} | Batch ${parseInt(data.batch)} | ${htmlspecialchars(data.role)}</div>
                </div>
                <div class="request-detail-stat is-danger">
                    <div class="request-detail-label">Status Request</div>
                    <span class="${statusBadge.class}">${statusBadge.label}</span>
                    <div class="request-detail-meta mt-3 text-rose-700">Jika disetujui, akun user akan dinonaktifkan permanen.</div>
                </div>
            </div>

            <div class="request-detail-grid">
                <div class="request-detail-block">
                    <div class="request-detail-label">Tanggal Pengajuan</div>
                    <div class="request-detail-value">${formatTanggalIndo(data.created_at)}</div>
                </div>
                <div class="request-detail-block">
                    <div class="request-detail-label">Approver</div>
                    <div class="request-detail-value">${data.approved_by ? htmlspecialchars(data.approved_by_name) : '-'}</div>
                    ${data.approved_by ? `<div class="request-detail-meta mt-1">${formatTanggalIndo(data.approved_at)}</div>` : ''}
                </div>
            </div>

            <div class="request-detail-block">
                <div class="request-detail-label">Alasan IC (In Character)</div>
                <div class="request-detail-value whitespace-pre-line">${htmlspecialchars(data.reason_ic)}</div>
            </div>

            <div class="request-detail-block">
                <div class="request-detail-label">Alasan OOC (Out of Character)</div>
                <div class="request-detail-value whitespace-pre-line">${htmlspecialchars(data.reason_ooc)}</div>
            </div>

            ${data.rejection_reason ? `
            <div class="request-detail-block request-detail-note-danger">
                <div class="request-detail-label">Alasan Penolakan</div>
                <div class="whitespace-pre-line">${htmlspecialchars(data.rejection_reason)}</div>
            </div>
            ` : ''}
        </div>
    `;

    // Show modal using proper class
    modal.classList.remove('hidden');
}

// ============================================
// APPROVAL FORM SUBMIT HANDLER
// ============================================

// Bind modal confirm button when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (window.jQuery && jQuery.fn.DataTable) {
        [
            { selector: '#cutiHistoryTable', order: [[1, 'desc']], noSort: [4] },
            { selector: '#resignHistoryTable', order: [[1, 'desc']], noSort: [4] },
            { selector: '#approvalCutiTable', order: [[2, 'asc']], noSort: [4, 5] },
            { selector: '#approvalResignTable', order: [[1, 'asc']], noSort: [3, 4] }
        ].forEach(function(cfg) {
            if (!document.querySelector(cfg.selector)) {
                return;
            }

            jQuery(cfg.selector).DataTable({
                pageLength: 10,
                scrollX: true,
                autoWidth: false,
                order: cfg.order,
                language: {
                    url: '/assets/design/js/datatables-id.json'
                },
                columnDefs: [
                    { orderable: false, targets: cfg.noSort }
                ]
            });
        });
    }

    var modalConfirmBtn = document.getElementById('approvalConfirmBtn');
    if (modalConfirmBtn) {
        modalConfirmBtn.addEventListener('click', function() {
            if (!currentAction || !currentRequestId) {
                alert('Error: Tidak ada action yang dipilih!');
                return;
            }

            var formData = new FormData();
            formData.append('action', currentAction);
            formData.append('request_id', currentRequestId);
            formData.append('csrf_token', csrfToken);

            if (currentAction.indexOf('reject') !== -1) {
                var reason = document.getElementById('approvalRejectionReason').value.trim();
                if (!reason) {
                    alert('Alasan penolakan harus diisi!');
                    return;
                }
                formData.append('rejection_reason', reason);
            }

            console.log('Submitting approval:', currentAction, 'requestId:', currentRequestId);

            fetch(window.emsUrl('/dashboard/pengajuan_cuti_resign_action.php'), {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers.get('content-type'));
                
                // Check if response is JSON
                var contentType = response.headers.get('content-type');
                if (contentType && contentType.indexOf('application/json') !== -1) {
                    return response.json();
                } else {
                    // Not JSON, return text for debugging
                    return response.text().then(function(text) {
                        throw new Error('Server returned HTML instead of JSON. Response: ' + text.substring(0, 200));
                    });
                }
            })
            .then(function(data) {
                console.log('Response data:', data);
                if (data.success) {
                    alert(data.message || 'Aksi berhasil!');
                    window.location.reload();
                } else {
                    alert(data.error || 'Terjadi kesalahan!');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alert('Terjadi kesalahan: ' + error.message);
            });

            closeModal();
        });
    }

    var approvalModal = document.getElementById('approvalModal');

    var detailModal = document.getElementById('detailModal');

    console.log('Cuti/Resign system initialized');
});
</script>

<!-- Modal untuk Approval -->
<div id="approvalModal" class="modal-overlay hidden">
    <div class="modal-card modal-frame-md request-modal-card">
        <div class="request-modal-head">
            <div>
                <h3 id="approvalModalTitle" class="request-modal-title">Konfirmasi</h3>
                <div class="request-modal-subtitle">Tinjau aksi approval sebelum dikirim.</div>
            </div>
            <button type="button" class="modal-close-btn request-modal-close" onclick="closeModal()" aria-label="Tutup">
                <?= ems_icon('x-mark', 'h-4 w-4') ?>
            </button>
        </div>
        <div class="request-modal-content modal-body">
            <p id="approvalModalMessage" class="text-sm text-gray-600 mb-4"></p>

            <div id="approvalRejectionSection" class="hidden mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Alasan Penolakan</label>
                <textarea id="approvalRejectionReason" class="w-full border border-gray-300 rounded-lg p-2 text-sm" rows="3" placeholder="Masukkan alasan penolakan..."></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" onclick="closeModal()" class="btn-secondary">Batal</button>
                <button type="button" id="approvalConfirmBtn" class="btn-success">Konfirmasi</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal untuk Detail -->
<div id="detailModal" class="modal-overlay hidden">
    <div class="modal-card modal-frame-lg request-modal-card">
        <div class="request-modal-head">
            <div>
                <h3 id="detailTitle" class="request-modal-title">Detail</h3>
                <div class="request-modal-subtitle">Informasi lengkap pengajuan cuti atau resign.</div>
            </div>
            <button type="button" class="modal-close-btn request-modal-close" onclick="closeDetailModal()" aria-label="Tutup">
                <?= ems_icon('x-mark', 'h-4 w-4') ?>
            </button>
        </div>
        <div class="request-modal-content modal-body" id="detailContent">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
