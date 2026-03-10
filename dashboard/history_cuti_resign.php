<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Human Resource'], '/dashboard/index.php');

$pageTitle = 'History Cuti & Resign';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$selectedUserId = (int)($_GET['user_id'] ?? 0);
$users = [];
$selectedUser = null;
$cutiHistory = [];
$resignHistory = [];
$summary = [
    'total_cuti_requests' => 0,
    'total_cuti_days_approved' => 0,
    'cuti_pending' => 0,
    'cuti_rejected' => 0,
    'total_resign_requests' => 0,
    'resign_approved' => 0,
];

try {
    $users = $pdo->query("
        SELECT id, full_name, role, position, division, is_active
        FROM user_rh
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($selectedUserId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, full_name, role, position, division, is_active, resign_reason, resigned_at
            FROM user_rh
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$selectedUserId]);
        $selectedUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($selectedUser) {
            $stmt = $pdo->prepare("
                SELECT
                    cr.*,
                    approver.full_name AS approved_by_name
                FROM cuti_requests cr
                LEFT JOIN user_rh approver ON approver.id = cr.approved_by
                WHERE cr.user_id = ?
                ORDER BY cr.created_at DESC
            ");
            $stmt->execute([$selectedUserId]);
            $cutiHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                SELECT
                    rr.*,
                    approver.full_name AS approved_by_name
                FROM resign_requests rr
                LEFT JOIN user_rh approver ON approver.id = rr.approved_by
                WHERE rr.user_id = ?
                ORDER BY rr.created_at DESC
            ");
            $stmt->execute([$selectedUserId]);
            $resignHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $summary['total_cuti_requests'] = count($cutiHistory);
            $summary['total_cuti_days_approved'] = array_sum(array_map(
                static fn(array $row): int => ($row['status'] ?? '') === 'approved' ? (int)($row['days_total'] ?? 0) : 0,
                $cutiHistory
            ));
            $summary['cuti_pending'] = count(array_filter($cutiHistory, static fn(array $row): bool => ($row['status'] ?? '') === 'pending'));
            $summary['cuti_rejected'] = count(array_filter($cutiHistory, static fn(array $row): bool => ($row['status'] ?? '') === 'rejected'));
            $summary['total_resign_requests'] = count($resignHistory);
            $summary['resign_approved'] = count(array_filter($resignHistory, static fn(array $row): bool => ($row['status'] ?? '') === 'approved'));
        } else {
            $errors[] = 'User tidak ditemukan.';
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat history cuti & resign: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="page-subtitle">Pilih satu nama user untuk melihat seluruh riwayat cuti, total hari cuti, dan histori resign tanpa memuat semua data sekaligus.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error) ?></div>
        <?php endforeach; ?>

        <div class="card card-section history-filter-card mb-4">
            <div class="history-filter-head">
                <div>
                    <h2 class="history-filter-title">Filter User</h2>
                    <p class="history-filter-copy">Halaman ini sengaja tidak menampilkan semua data. Pilih satu user agar riwayat cuti dan resign tampil lebih fokus.</p>
                </div>
            </div>
            <form method="GET" class="history-filter-form">
                <div class="history-filter-field">
                    <label for="historyUserId">Nama User</label>
                    <select id="historyUserId" name="user_id" required>
                        <option value="">Pilih user untuk melihat history</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= $selectedUserId === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name']) ?> | <?= htmlspecialchars(ems_role_label($u['role'])) ?> | <?= htmlspecialchars(ems_normalize_division($u['division'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="history-filter-actions">
                    <button type="submit" class="btn-secondary">Tampilkan History</button>
                    <a href="history_cuti_resign.php" class="btn-secondary">Reset</a>
                </div>
            </form>
        </div>

        <?php if ($selectedUser): ?>
            <div class="tracking-hero mb-4">
                <div>
                    <div class="tracking-kicker">Riwayat User</div>
                    <h2 class="tracking-hero-title"><?= htmlspecialchars($selectedUser['full_name']) ?></h2>
                    <p class="tracking-hero-copy">
                        <?= htmlspecialchars(ems_role_label($selectedUser['role'])) ?> |
                        <?= htmlspecialchars(ems_position_label($selectedUser['position'])) ?> |
                        <?= htmlspecialchars(ems_normalize_division($selectedUser['division'] ?? '')) ?>
                    </p>
                </div>
                <div class="tracking-hero-meta">
                    <div class="tracking-hero-meta-label">Status Akun</div>
                    <div class="tracking-hero-meta-value"><?= (int)$selectedUser['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></div>
                </div>
            </div>

            <div class="tracking-stats-grid mb-4">
                <article class="tracking-stat-card">
                    <div class="tracking-stat-icon is-total"><?= ems_icon('calendar-days', 'h-5 w-5') ?></div>
                    <div class="tracking-stat-body">
                        <div class="tracking-stat-label">Total Pengajuan Cuti</div>
                        <div class="tracking-stat-value"><?= $summary['total_cuti_requests'] ?></div>
                        <div class="tracking-stat-note">Semua request cuti user ini</div>
                    </div>
                </article>
                <article class="tracking-stat-card">
                    <div class="tracking-stat-icon is-cuti"><?= ems_icon('check-circle', 'h-5 w-5') ?></div>
                    <div class="tracking-stat-body">
                        <div class="tracking-stat-label">Total Hari Cuti Approved</div>
                        <div class="tracking-stat-value text-emerald-700"><?= $summary['total_cuti_days_approved'] ?></div>
                        <div class="tracking-stat-note">Akumulasi hari cuti yang disetujui</div>
                    </div>
                </article>
                <article class="tracking-stat-card">
                    <div class="tracking-stat-icon is-active"><?= ems_icon('clock', 'h-5 w-5') ?></div>
                    <div class="tracking-stat-body">
                        <div class="tracking-stat-label">Cuti Pending / Rejected</div>
                        <div class="tracking-stat-value text-amber-700"><?= $summary['cuti_pending'] ?> / <?= $summary['cuti_rejected'] ?></div>
                        <div class="tracking-stat-note">Pending dibanding ditolak</div>
                    </div>
                </article>
                <article class="tracking-stat-card">
                    <div class="tracking-stat-icon is-resigned"><?= ems_icon('user-minus', 'h-5 w-5') ?></div>
                    <div class="tracking-stat-body">
                        <div class="tracking-stat-label">Total Pengajuan Resign</div>
                        <div class="tracking-stat-value text-rose-700"><?= $summary['total_resign_requests'] ?></div>
                        <div class="tracking-stat-note">Approved: <?= $summary['resign_approved'] ?></div>
                    </div>
                </article>
            </div>

            <div class="history-section-grid">
                <div class="card card-section history-table-card">
                    <div class="history-table-head">
                        <div>
                            <h3 class="history-table-title">History Cuti</h3>
                            <p class="history-table-copy">Semua pengajuan cuti user terpilih beserta periode, status, dan approver.</p>
                        </div>
                    </div>
                    <div class="table-wrapper table-wrapper-sm">
                        <table id="hrCutiHistoryTable" class="table-custom">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Tanggal Pengajuan</th>
                                    <th>Periode Cuti</th>
                                    <th>Total Hari</th>
                                    <th>Status</th>
                                    <th>Approver</th>
                                    <th>Alasan Ditolak</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cutiHistory as $row): ?>
                                    <?php $badge = get_status_badge((string)($row['status'] ?? 'pending')); ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars((string)$row['request_code']) ?></strong></td>
                                        <td><?= htmlspecialchars(formatTanggalID((string)$row['created_at'])) ?></td>
                                        <td>
                                            <?= htmlspecialchars(formatTanggalIndo((string)$row['start_date'])) ?>
                                            -
                                            <?= htmlspecialchars(formatTanggalIndo((string)$row['end_date'])) ?>
                                        </td>
                                        <td><?= (int)($row['days_total'] ?? 0) ?> hari</td>
                                        <td><span class="request-status-badge request-status-<?= htmlspecialchars((string)$row['status']) ?>"><?= htmlspecialchars($badge['label']) ?></span></td>
                                        <td><?= htmlspecialchars((string)($row['approved_by_name'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars((string)($row['rejection_reason'] ?? '-')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (!$cutiHistory): ?>
                            <div class="muted-placeholder p-4">User ini belum pernah mengajukan cuti.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card card-section history-table-card">
                    <div class="history-table-head">
                        <div>
                            <h3 class="history-table-title">History Resign</h3>
                            <p class="history-table-copy">Riwayat pengajuan resign, status persetujuan, dan ringkasan alasan pengajuan.</p>
                        </div>
                    </div>
                    <div class="table-wrapper table-wrapper-sm">
                        <table id="hrResignHistoryTable" class="table-custom">
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Tanggal Pengajuan</th>
                                    <th>Status</th>
                                    <th>Approver</th>
                                    <th>Alasan Ditolak</th>
                                    <th>Ringkasan Alasan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resignHistory as $row): ?>
                                    <?php
                                    $badge = get_status_badge((string)($row['status'] ?? 'pending'));
                                    $reasonPreview = trim((string)($row['reason_ic'] ?? ''));
                                    if ($reasonPreview !== '' && strlen($reasonPreview) > 80) {
                                        $reasonPreview = substr($reasonPreview, 0, 80) . '...';
                                    }
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars((string)$row['request_code']) ?></strong></td>
                                        <td><?= htmlspecialchars(formatTanggalID((string)$row['created_at'])) ?></td>
                                        <td><span class="request-status-badge request-status-<?= htmlspecialchars((string)$row['status']) ?>"><?= htmlspecialchars($badge['label']) ?></span></td>
                                        <td><?= htmlspecialchars((string)($row['approved_by_name'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars((string)($row['rejection_reason'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars($reasonPreview !== '' ? $reasonPreview : '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (!$resignHistory): ?>
                            <div class="muted-placeholder p-4">User ini belum pernah mengajukan resign.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card card-section">
                <div class="muted-placeholder">Pilih nama user terlebih dahulu agar riwayat cuti dan resign ditampilkan.</div>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.page-shell {
    max-width: 1380px;
}

.history-filter-card,
.history-table-card {
    border: 1px solid rgba(226, 232, 240, 0.9);
    box-shadow: 0 18px 44px rgba(15, 23, 42, 0.08);
}

.history-filter-card {
    padding: 1.25rem;
    background:
        linear-gradient(135deg, rgba(240, 249, 255, 0.92), rgba(255, 255, 255, 0.96));
}

.history-filter-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.history-filter-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
}

.history-filter-copy {
    margin-top: 0.35rem;
    font-size: 0.86rem;
    color: #64748b;
}

.history-filter-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: end;
}

.history-filter-field label {
    display: block;
    margin-bottom: 0.45rem;
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #0369a1;
}

.history-filter-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.tracking-hero {
    display: flex;
    align-items: stretch;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.3rem 1.4rem;
    border: 1px solid rgba(191, 219, 254, 0.7);
    border-radius: 1.5rem;
    background:
        radial-gradient(circle at top right, rgba(125, 211, 252, 0.22), transparent 34%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(240, 249, 255, 0.95));
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
}

.tracking-kicker {
    font-size: 0.74rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #0369a1;
}

.tracking-hero-title {
    margin: 0.2rem 0 0;
    font-size: 1.9rem;
    line-height: 1.1;
    font-weight: 800;
    color: #0f172a;
}

.tracking-hero-copy {
    margin-top: 0.5rem;
    font-size: 0.95rem;
    color: #475569;
}

.tracking-hero-meta {
    min-width: 11rem;
    padding: 1rem 1.1rem;
    border-radius: 1.1rem;
    border: 1px solid rgba(186, 230, 253, 0.9);
    background: rgba(224, 242, 254, 0.72);
    text-align: right;
}

.tracking-hero-meta-label {
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #0369a1;
}

.tracking-hero-meta-value {
    margin-top: 0.35rem;
    font-size: 1.15rem;
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
    min-height: 126px;
    padding: 1.15rem;
    border: 1px solid rgba(226, 232, 240, 0.95);
    border-radius: 1.25rem;
    background: rgba(255, 255, 255, 0.96);
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
}

.tracking-stat-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 3rem;
    flex-shrink: 0;
    border-radius: 1rem;
}

.tracking-stat-icon.is-total {
    background: rgba(14, 165, 233, 0.12);
    color: #0369a1;
}

.tracking-stat-icon.is-cuti {
    background: rgba(16, 185, 129, 0.12);
    color: #047857;
}

.tracking-stat-icon.is-active {
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
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: #64748b;
}

.tracking-stat-value {
    margin-top: 0.3rem;
    font-size: 2rem;
    line-height: 1;
    font-weight: 800;
    color: #0f172a;
}

.tracking-stat-note {
    margin-top: 0.35rem;
    font-size: 0.84rem;
    color: #64748b;
}

.history-section-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.history-table-card {
    overflow: hidden;
    background: rgba(255, 255, 255, 0.97);
}

.history-table-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.1rem 1.2rem 0;
}

.history-table-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
}

.history-table-copy {
    margin-top: 0.25rem;
    font-size: 0.84rem;
    color: #64748b;
}

.table-wrapper.table-wrapper-sm {
    padding: 1rem 1.2rem 1.2rem;
}

.table-custom thead th {
    background: #f8fafc;
    color: #334155;
    font-size: 0.77rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.table-custom tbody td {
    vertical-align: top;
}

.muted-placeholder {
    border: 1px dashed rgba(148, 163, 184, 0.45);
    border-radius: 1rem;
    background: rgba(248, 250, 252, 0.9);
    color: #64748b;
}

@media (max-width: 1180px) {
    .tracking-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .history-section-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 840px) {
    .history-filter-form {
        grid-template-columns: 1fr;
    }

    .tracking-hero {
        flex-direction: column;
    }

    .tracking-hero-meta {
        min-width: 0;
        width: 100%;
        text-align: left;
    }
}

@media (max-width: 640px) {
    .tracking-stats-grid {
        grid-template-columns: 1fr;
    }

    .tracking-hero-title {
        font-size: 1.45rem;
    }

    .history-filter-card,
    .history-table-card {
        border-radius: 1.1rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (!window.jQuery || !jQuery.fn.DataTable) {
        return;
    }

    if (document.querySelector('#hrCutiHistoryTable tbody tr')) {
        jQuery('#hrCutiHistoryTable').DataTable({
            pageLength: 10,
            order: [[1, 'desc']],
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });
    }

    if (document.querySelector('#hrResignHistoryTable tbody tr')) {
        jQuery('#hrResignHistoryTable').DataTable({
            pageLength: 10,
            order: [[1, 'desc']],
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
