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
        <div class="card card-section mb-4">
            <div class="flex gap-2 border-b border-gray-200">
                <a href="?tab=cuti" class="px-4 py-2 font-medium <?= $activeTab === 'cuti' ? 'border-b-2 border-primary text-primary' : 'text-gray-600 hover:text-gray-900' ?>">
                    <?= ems_icon('calendar', 'h-4 w-4 inline mr-1') ?>
                    <span>Pengajuan Cuti</span>
                </a>
                <a href="?tab=resign" class="px-4 py-2 font-medium <?= $activeTab === 'resign' ? 'border-b-2 border-primary text-primary' : 'text-gray-600 hover:text-gray-900' ?>">
                    <?= ems_icon('user-minus', 'h-4 w-4 inline mr-1') ?>
                    <span>Pengajuan Resign</span>
                </a>
                <?php if ($canApprove): ?>
                <a href="?tab=approval" class="px-4 py-2 font-medium <?= $activeTab === 'approval' ? 'border-b-2 border-primary text-primary' : 'text-gray-600 hover:text-gray-900' ?>">
                    <?= ems_icon('check-circle', 'h-4 w-4 inline mr-1') ?>
                    <span>Approval Request</span>
                    <?php if (count($pendingCuti) + count($pendingResign) > 0): ?>
                        <span class="ml-1 px-2 py-0.5 text-xs bg-red-500 text-white rounded-full"><?= count($pendingCuti) + count($pendingResign) ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB CUTI -->
        <?php if ($activeTab === 'cuti'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Form Pengajuan Cuti -->
                <div class="card card-section">
                    <div class="card-header">Form Pengajuan Cuti</div>
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

                        <div class="alert alert-info mt-4">
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
                <div class="card card-section">
                    <div class="card-header">Riwayat Pengajuan Cuti</div>
                    <div class="table-wrapper table-wrapper-sm">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Tanggal</th>
                                    <th>Durasi</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$myCutiRequests): ?>
                                    <tr>
                                        <td colspan="4" class="muted-placeholder">Belum ada pengajuan cuti.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($myCutiRequests as $req): ?>
                                        <?php
                                        $badge = get_status_badge($req['status']);
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
                                                <span class="badge-counter <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                                                <?php if ($req['status'] === 'approved' && $req['approved_by_name']): ?>
                                                    <div class="meta-text-xs">Oleh <?= htmlspecialchars($req['approved_by_name']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB RESIGN -->
        <?php if ($activeTab === 'resign'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Form Pengajuan Resign -->
                <div class="card card-section">
                    <div class="card-header">Form Pengajuan Resign</div>
                    <form method="POST" action="pengajuan_cuti_resign_action.php" class="form">
                        <?= csrfField(); ?>
                        <input type="hidden" name="action" value="submit_resign">
                        <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                        <label>Alasan IC (In Character)</label>
                        <textarea name="reason_ic" rows="3" required placeholder="Contoh: Pulang ke kampung halaman, buka praktik sendiri, dll."></textarea>

                        <label>Alasan OOC (Out of Character)</label>
                        <textarea name="reason_ooc" rows="3" required placeholder="Contoh: Ada kerjaan lain, fokus kuliah, dll."></textarea>

                        <div class="alert alert-error mt-4">
                            <strong>PERHATIAN!</strong>
                            <ul class="list-disc list-inside mt-2 text-sm">
                                <li>Resign yang sudah disetujui TIDAK BISA dibatalkan</li>
                                <li>Akun Anda akan dinonaktifkan secara otomatis</li>
                                <li>Anda tidak akan bisa login setelah resign disetujui</li>
                                <li>Data Anda akan tetap tersimpan untuk arsip</li>
                            </ul>
                        </div>

                        <div class="modal-actions mt-4">
                            <button type="submit" class="btn-error">
                                <?= ems_icon('exclamation-triangle', 'h-4 w-4') ?>
                                <span>Ajukan Resign</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Riwayat Pengajuan Resign -->
                <div class="card card-section">
                    <div class="card-header">Riwayat Pengajuan Resign</div>
                    <div class="table-wrapper table-wrapper-sm">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Tanggal</th>
                                    <th>Alasan</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$myResignRequests): ?>
                                    <tr>
                                        <td colspan="4" class="muted-placeholder">Belum ada pengajuan resign.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($myResignRequests as $req): ?>
                                        <?php
                                        $badge = get_status_badge($req['status']);
                                        $reasonIC = strlen($req['reason_ic'] ?? '') > 50
                                            ? substr($req['reason_ic'], 0, 50) . '...'
                                            : ($req['reason_ic'] ?? '-');
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
                                                <span class="badge-counter <?= $badge['class'] ?>"><?= $badge['label'] ?></span>
                                                <?php if ($req['status'] === 'approved' && $req['approved_by_name']): ?>
                                                    <div class="meta-text-xs">Oleh <?= htmlspecialchars($req['approved_by_name']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB APPROVAL (Hanya untuk Manager+) -->
        <?php if ($activeTab === 'approval' && $canApprove): ?>
            <div class="grid grid-cols-1 gap-4">
                <!-- Pending Cuti Requests -->
                <?php if ($pendingCuti): ?>
                    <div class="card card-section">
                        <div class="card-header">Pengajuan Cuti Pending (<?= count($pendingCuti) ?>)</div>
                        <div class="space-y-4">
                            <?php foreach ($pendingCuti as $req): ?>
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex justify-between items-start mb-3">
                                        <div>
                                            <div class="font-bold text-lg"><?= htmlspecialchars($req['full_name']) ?></div>
                                            <div class="meta-text-xs">
                                                <?= ems_position_label($req['position']) ?> | Batch <?= (int)$req['batch'] ?>
                                            </div>
                                            <div class="meta-text-xs">
                                                <?= htmlspecialchars($req['request_code']) ?> | <?= formatTanggalIndo($req['created_at']) ?>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-2xl font-bold text-primary"><?= (int)$req['days_total'] ?> hari</div>
                                            <div class="meta-text-xs">Durasi Cuti</div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-4 mb-3">
                                        <div>
                                            <div class="text-sm font-medium text-gray-700">Tanggal Cuti</div>
                                            <div class="text-sm">
                                                <?= formatTanggalIndo($req['start_date']) ?> - <?= formatTanggalIndo($req['end_date']) ?>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-700">Alasan</div>
                                            <div class="text-sm whitespace-pre-line"><?= htmlspecialchars($req['reason_ic'] ?? '-') ?></div>
                                        </div>
                                    </div>

                                    <div class="flex gap-2">
                                        <button type="button" onclick="approveCuti(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['request_code']) ?>')" class="btn-success btn-sm">
                                            <?= ems_icon('check', 'h-4 w-4') ?>
                                            <span>Setujui</span>
                                        </button>
                                        <button type="button" onclick="rejectCuti(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['request_code']) ?>')" class="btn-error btn-sm">
                                            <?= ems_icon('x', 'h-4 w-4') ?>
                                            <span>Tolak</span>
                                        </button>
                                        <button type="button" onclick="viewCutiDetail(<?= (int)$req['id'] ?>)" class="btn-secondary btn-sm">
                                            <?= ems_icon('eye', 'h-4 w-4') ?>
                                            <span>Detail</span>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
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
                    <div class="card card-section">
                        <div class="card-header">Pengajuan Resign Pending (<?= count($pendingResign) ?>)</div>
                        <div class="space-y-4">
                            <?php foreach ($pendingResign as $req): ?>
                                <div class="border border-red-200 bg-red-50 rounded-lg p-4">
                                    <div class="flex justify-between items-start mb-3">
                                        <div>
                                            <div class="font-bold text-lg text-red-700"><?= htmlspecialchars($req['full_name']) ?></div>
                                            <div class="meta-text-xs">
                                                <?= ems_position_label($req['position']) ?> | Batch <?= (int)$req['batch'] ?>
                                            </div>
                                            <div class="meta-text-xs">
                                                <?= htmlspecialchars($req['request_code']) ?> | <?= formatTanggalIndo($req['created_at']) ?>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-lg font-bold text-red-600">RESIGN</div>
                                            <div class="meta-text-xs text-red-600">Pengunduran Diri</div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <div class="text-sm font-medium text-gray-700">Alasan IC</div>
                                        <div class="text-sm whitespace-pre-line"><?= htmlspecialchars($req['reason_ic'] ?? '-') ?></div>
                                    </div>

                                    <div class="flex gap-2">
                                        <button type="button" onclick="approveResign(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['request_code']) ?>', '<?= htmlspecialchars($req['full_name']) ?>')" class="btn-error btn-sm">
                                            <?= ems_icon('check', 'h-4 w-4') ?>
                                            <span>Setujui Resign</span>
                                        </button>
                                        <button type="button" onclick="rejectResign(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['request_code']) ?>')" class="btn-secondary btn-sm">
                                            <?= ems_icon('x', 'h-4 w-4') ?>
                                            <span>Tolak</span>
                                        </button>
                                        <button type="button" onclick="viewResignDetail(<?= (int)$req['id'] ?>)" class="btn-secondary btn-sm">
                                            <?= ems_icon('eye', 'h-4 w-4') ?>
                                            <span>Detail</span>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
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

<!-- Modal untuk Approval -->
<div id="approvalModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden" style="display: none;" onclick="if (event.target === this) closeModal();">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4 shadow-xl" onclick="event.stopPropagation();">
        <h3 id="modalTitle" class="text-lg font-bold mb-4">Konfirmasi</h3>
        <p id="modalMessage" class="text-sm text-gray-600 mb-4"></p>

        <div id="rejectionSection" class="hidden mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">Alasan Penolakan</label>
            <textarea id="rejectionReason" class="w-full border border-gray-300 rounded-lg p-2 text-sm" rows="3" placeholder="Masukkan alasan penolakan..."></textarea>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" onclick="closeModal()" class="btn-secondary">Batal</button>
            <button type="button" id="modalConfirmBtn" class="btn-success">Konfirmasi</button>
        </div>
    </div>
</div>

<script>
let currentAction = null;
let currentRequestId = null;

function showModal(title, message, action, requestId, showRejection = false) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalMessage').textContent = message;
    currentAction = action;
    currentRequestId = requestId;

    const rejectionSection = document.getElementById('rejectionSection');
    const confirmBtn = document.getElementById('modalConfirmBtn');
    const modal = document.getElementById('approvalModal');

    if (showRejection) {
        rejectionSection.classList.remove('hidden');
        confirmBtn.className = 'btn-error';
        confirmBtn.textContent = 'Tolak';
    } else {
        rejectionSection.classList.add('hidden');
        confirmBtn.className = action === 'approve_resign' ? 'btn-error' : 'btn-success';
        confirmBtn.textContent = action === 'approve_resign' ? 'Setujui Resign' : 'Setujui';
    }

    // Tampilkan modal dengan flex layout
    modal.style.display = 'flex';
    modal.classList.add('items-center', 'justify-center');
}

function closeModal() {
    const modal = document.getElementById('approvalModal');
    modal.style.display = 'none';
    modal.classList.remove('items-center', 'justify-center');
    currentAction = null;
    currentRequestId = null;
}

function approveCuti(id, code) {
    showModal(
        'Setujui Cuti',
        `Apakah Anda yakin ingin menyetujui pengajuan cuti ${code}?`,
        'approve_cuti',
        id
    );
}

function rejectCuti(id, code) {
    showModal(
        'Tolak Cuti',
        `Apakah Anda yakin ingin menolak pengajuan cuti ${code}?`,
        'reject_cuti',
        id,
        true
    );
}

function approveResign(id, code, name) {
    showModal(
        'Setujui Resign',
        `PERINGATAN: User ${name} akan dinonaktifkan dan tidak bisa login lagi. Lanjutkan?`,
        'approve_resign',
        id
    );
}

function rejectResign(id, code) {
    showModal(
        'Tolak Resign',
        `Apakah Anda yakin ingin menolak pengajuan resign ${code}?`,
        'reject_resign',
        id,
        true
    );
}

function viewCutiDetail(id) {
    // Implementasi modal detail cuti
    alert('Fitur detail akan segera tersedia');
}

function viewResignDetail(id) {
    // Implementasi modal detail resign
    alert('Fitur detail akan segera tersedia');
}

document.getElementById('modalConfirmBtn').addEventListener('click', function() {
    if (!currentAction || !currentRequestId) return;

    const formData = new FormData();
    formData.append('action', currentAction);
    formData.append('request_id', currentRequestId);
    formData.append('csrf_token', '<?= csrfToken() ?>');

    if (currentAction.includes('reject')) {
        const reason = document.getElementById('rejectionReason').value.trim();
        if (!reason) {
            alert('Alasan penolakan harus diisi!');
            return;
        }
        formData.append('rejection_reason', reason);
    }

    fetch('pengajuan_cuti_resign_action.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Aksi berhasil!');
            window.location.reload();
        } else {
            alert(data.error || 'Terjadi kesalahan!');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan koneksi!');
    });

    closeModal();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
