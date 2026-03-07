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
        if ($filterStatus === 'on_cuti') {
            $whereClause .= " AND u.cuti_status = 'active'";
        } elseif ($filterStatus === 'resigned') {
            $whereClause .= " AND u.is_active = 0";
        } elseif ($filterStatus === 'active') {
            $whereClause .= " AND u.is_active = 1 AND (u.cuti_status != 'active' OR u.cuti_status IS NULL)";
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

    // Hitung statistik
    $stats['total'] = count($allUsers);
    foreach ($allUsers as $u) {
        if ((int)$u['is_active'] === 0) {
            $stats['resigned']++;
        } elseif ($u['cuti_status'] === 'active') {
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

        <!-- Statistik Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">Total User</div>
                <div class="text-2xl font-extrabold"><?= $stats['total'] ?></div>
            </div>
            <div class="card card-section border-l-4 border-green-500">
                <div class="meta-text-xs">User Aktif</div>
                <div class="text-2xl font-extrabold text-green-600"><?= $stats['active'] ?></div>
            </div>
            <div class="card card-section border-l-4 border-amber-500">
                <div class="meta-text-xs">Sedang Cuti</div>
                <div class="text-2xl font-extrabold text-amber-600"><?= $stats['on_cuti'] ?></div>
            </div>
            <div class="card card-section border-l-4 border-red-500">
                <div class="meta-text-xs">Resigned</div>
                <div class="text-2xl font-extrabold text-red-600"><?= $stats['resigned'] ?></div>
            </div>
        </div>

        <!-- Filter -->
        <div class="card card-section mb-4">
            <form method="GET" class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Filter Status</label>
                    <select name="status" class="border border-gray-300 rounded-lg px-3 py-2">
                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Semua Status</option>
                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Aktif</option>
                        <option value="on_cuti" <?= $filterStatus === 'on_cuti' ? 'selected' : '' ?>>Sedang Cuti</option>
                        <option value="resigned" <?= $filterStatus === 'resigned' ? 'selected' : '' ?>>Resigned</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Filter Batch</label>
                    <select name="batch" class="border border-gray-300 rounded-lg px-3 py-2">
                        <option value="all">Semua Batch</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?= $batch ?>" <?= $filterBatch == $batch ? 'selected' : '' ?>>Batch <?= $batch ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-secondary">Filter</button>
                <a href="tracking_cuti_resign.php" class="btn-secondary">Reset</a>
            </form>
        </div>

        <!-- User List -->
        <div class="card card-section">
            <div class="card-header">Daftar User (<?= count($allUsers) ?>)</div>
            <div class="table-wrapper">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Position</th>
                            <th>Batch</th>
                            <th>Status</th>
                            <th>Cuti Progress</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$allUsers): ?>
                            <tr>
                                <td colspan="6" class="muted-placeholder">Tidak ada data user.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allUsers as $u): ?>
                                <?php
                                // Tentukan status user
                                if ((int)$u['is_active'] === 0) {
                                    $statusBadge = '<span class="badge-counter badge-error">Resigned</span>';
                                    $statusRow = 'bg-red-50';
                                } elseif ($u['cuti_status'] === 'active') {
                                    $statusBadge = '<span class="badge-counter badge-warning">Sedang Cuti</span>';
                                    $statusRow = 'bg-amber-50';

                                    // Hitung progress cuti
                                    $cutiProgress = hitung_sisa_cuti($u['cuti_start_date'], $u['cuti_end_date']);
                                } else {
                                    $statusBadge = '<span class="badge-counter badge-success">Aktif</span>';
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
                                    <td><?= $statusBadge ?></td>
                                    <td>
                                        <?php if ($u['cuti_status'] === 'active' && isset($cutiProgress)): ?>
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
                                        <?php elseif ($u['cuti_status'] === 'active'): ?>
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
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Legend -->
        <div class="card card-section mt-4">
            <div class="card-header">Legend</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <span class="badge-counter badge-success">Aktif</span>
                    <p class="mt-1 text-gray-600">User dapat login dan bekerja normal.</p>
                </div>
                <div>
                    <span class="badge-counter badge-warning">Sedang Cuti</span>
                    <p class="mt-1 text-gray-600">User dalam masa cuti. Progress bar menunjukkan sisa hari cuti.</p>
                </div>
                <div>
                    <span class="badge-counter badge-error">Resigned</span>
                    <p class="mt-1 text-gray-600">User sudah resign dan dinonaktifkan. Tidak bisa login.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
