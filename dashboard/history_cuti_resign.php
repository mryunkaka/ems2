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

$cutiHistory = [];
$summary = [
    'total_pengaju_cuti' => 0,
    'total_pengajuan_cuti' => 0,
    'cuti_approved' => 0,
    'kembali_lebih_awal' => 0,
];

function userRhColumnExists(PDO $pdo, string $columnName): bool
{
    static $cache = [];

    if (array_key_exists($columnName, $cache)) {
        return $cache[$columnName];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM user_rh LIKE ?");
    $stmt->execute([$columnName]);
    $cache[$columnName] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$columnName];
}

function cutiRequestColumnExists(PDO $pdo, string $columnName): bool
{
    static $cache = [];

    if (array_key_exists($columnName, $cache)) {
        return $cache[$columnName];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM cuti_requests LIKE ?");
    $stmt->execute([$columnName]);
    $cache[$columnName] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$columnName];
}

try {
    $hasCutiEndedAt = userRhColumnExists($pdo, 'cuti_ended_at');
    $hasCutiEndedBy = userRhColumnExists($pdo, 'cuti_ended_by');
    $hasCutiOriginalDays = userRhColumnExists($pdo, 'cuti_original_days');

    $earlyReturnColumns = [];
    if ($hasCutiEndedAt) {
        $earlyReturnColumns[] = 'u.cuti_ended_at';
    } else {
        $earlyReturnColumns[] = 'NULL AS cuti_ended_at';
    }

    if ($hasCutiEndedBy) {
        $earlyReturnColumns[] = 'u.cuti_ended_by';
        $earlyReturnColumns[] = 'returner.full_name AS cuti_ended_by_name';
    } else {
        $earlyReturnColumns[] = 'NULL AS cuti_ended_by';
        $earlyReturnColumns[] = 'NULL AS cuti_ended_by_name';
    }

    if ($hasCutiOriginalDays) {
        $earlyReturnColumns[] = 'u.cuti_original_days';
    } else {
        $earlyReturnColumns[] = 'NULL AS cuti_original_days';
    }

    $hasActualEndDate = cutiRequestColumnExists($pdo, 'actual_end_date');
    $hasReturnedToWorkAt = cutiRequestColumnExists($pdo, 'returned_to_work_at');
    $hasReturnedToWorkBy = cutiRequestColumnExists($pdo, 'returned_to_work_by');
    $hasActualDaysUsed = cutiRequestColumnExists($pdo, 'actual_days_used');

    $requestReturnColumns = [];
    $requestReturnColumns[] = $hasActualEndDate ? 'cr.actual_end_date' : 'NULL AS actual_end_date';
    $requestReturnColumns[] = $hasReturnedToWorkAt ? 'cr.returned_to_work_at' : 'NULL AS returned_to_work_at';
    if ($hasReturnedToWorkBy) {
        $requestReturnColumns[] = 'cr.returned_to_work_by';
        $requestReturnColumns[] = 'returned_by.full_name AS returned_to_work_by_name';
    } else {
        $requestReturnColumns[] = 'NULL AS returned_to_work_by';
        $requestReturnColumns[] = 'NULL AS returned_to_work_by_name';
    }
    $requestReturnColumns[] = $hasActualDaysUsed ? 'cr.actual_days_used' : 'NULL AS actual_days_used';

    $joinReturner = $hasCutiEndedBy
        ? "LEFT JOIN user_rh returner ON returner.id = u.cuti_ended_by"
        : "";
    $joinReturnedBy = $hasReturnedToWorkBy
        ? "LEFT JOIN user_rh returned_by ON returned_by.id = cr.returned_to_work_by"
        : "";

    $stmt = $pdo->query("
        SELECT
            cr.*,
            u.full_name,
            u.role,
            u.position,
            u.division,
            u.is_active,
            approver.full_name AS approved_by_name,
            " . implode(",\n            ", $earlyReturnColumns) . ",
            " . implode(",\n            ", $requestReturnColumns) . "
        FROM cuti_requests cr
        INNER JOIN user_rh u ON u.id = cr.user_id
        LEFT JOIN user_rh approver ON approver.id = cr.approved_by
        {$joinReturner}
        {$joinReturnedBy}
        ORDER BY cr.created_at DESC, cr.id DESC
    ");
    $cutiHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cutiHistory as &$row) {
        $isEarlyReturn = false;

        $approvedDays = (int)($row['days_total'] ?? 0);
        $actualDaysUsed = (int)($row['actual_days_used'] ?? 0);
        $returnedEffectiveDate = null;
        $returnedByName = null;

        if (($row['status'] ?? '') === 'approved') {
            if (!empty($row['actual_end_date'])) {
                $returnedEffectiveDate = (string)$row['actual_end_date'];
                $returnedByName = (string)($row['returned_to_work_by_name'] ?? '');
                $isEarlyReturn = $actualDaysUsed > 0 && $approvedDays > 0 && $actualDaysUsed < $approvedDays;
            } else {
                $originalDays = (int)($row['cuti_original_days'] ?? 0);
                $usedDays = 0;

                if (!empty($row['start_date']) && !empty($row['end_date'])) {
                    try {
                        $usedDays = (new DateTime((string)$row['start_date']))->diff(new DateTime((string)$row['end_date']))->days + 1;
                    } catch (Throwable $e) {
                        $usedDays = 0;
                    }
                }

                $isEarlyReturn = !empty($row['cuti_ended_at'])
                    && $originalDays > 0
                    && $originalDays === $approvedDays
                    && $usedDays > 0
                    && $usedDays < $approvedDays;

                if ($isEarlyReturn) {
                    $returnedEffectiveDate = (string)($row['end_date'] ?? '');
                    $returnedByName = (string)($row['cuti_ended_by_name'] ?? '');
                }
            }
        }

        $row['is_early_return'] = $isEarlyReturn;
        $row['returned_effective_date'] = $returnedEffectiveDate;
        $row['returned_by_name'] = $returnedByName;
        $row['created_at_sort'] = !empty($row['created_at']) ? strtotime((string)$row['created_at']) : 0;
        $row['returned_effective_date_sort'] = !empty($row['returned_effective_date']) ? strtotime((string)$row['returned_effective_date']) : 0;
        $row['days_used_after_return'] = $isEarlyReturn
            ? (($actualDaysUsed > 0) ? $actualDaysUsed : null)
            : null;
    }
    unset($row);

    $summary['total_pengaju_cuti'] = count(array_unique(array_map(
        static fn(array $row): int => (int)($row['user_id'] ?? 0),
        $cutiHistory
    )));
    $summary['total_pengajuan_cuti'] = count($cutiHistory);
    $summary['cuti_approved'] = count(array_filter($cutiHistory, static fn(array $row): bool => ($row['status'] ?? '') === 'approved'));
    $summary['kembali_lebih_awal'] = count(array_filter($cutiHistory, static fn(array $row): bool => !empty($row['is_early_return'])));
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat history cuti: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="page-subtitle">Monitoring seluruh tenaga medis yang pernah mengajukan cuti, termasuk siapa yang kembali kerja lebih cepat dan tanggal efektif kembali kerjanya.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error) ?></div>
        <?php endforeach; ?>

        <div class="tracking-hero mb-4">
            <div>
                <div class="tracking-kicker">History & Monitoring</div>
                <h2 class="tracking-hero-title">Seluruh histori pengajuan cuti medis dalam satu tampilan.</h2>
                <p class="tracking-hero-copy">Tabel ini hanya menampilkan user yang pernah mengajukan cuti. Data paling baru berada di urutan paling atas.</p>
            </div>
            <div class="tracking-hero-meta">
                <div class="tracking-hero-meta-label">Urutan Data</div>
                <div class="tracking-hero-meta-value">Terbaru Dulu</div>
            </div>
        </div>

        <div class="tracking-stats-grid mb-4">
            <article class="tracking-stat-card">
                <div class="tracking-stat-icon is-total"><?= ems_icon('user-group', 'h-5 w-5') ?></div>
                <div class="tracking-stat-body">
                    <div class="tracking-stat-label">Total Pengaju Cuti</div>
                    <div class="tracking-stat-value"><?= $summary['total_pengaju_cuti'] ?></div>
                    <div class="tracking-stat-note">User unik yang pernah mengajukan cuti</div>
                </div>
            </article>
            <article class="tracking-stat-card">
                <div class="tracking-stat-icon is-cuti"><?= ems_icon('calendar-days', 'h-5 w-5') ?></div>
                <div class="tracking-stat-body">
                    <div class="tracking-stat-label">Total Pengajuan Cuti</div>
                    <div class="tracking-stat-value text-sky-700"><?= $summary['total_pengajuan_cuti'] ?></div>
                    <div class="tracking-stat-note">Semua histori request cuti</div>
                </div>
            </article>
            <article class="tracking-stat-card">
                <div class="tracking-stat-icon is-approved"><?= ems_icon('check-circle', 'h-5 w-5') ?></div>
                <div class="tracking-stat-body">
                    <div class="tracking-stat-label">Cuti Approved</div>
                    <div class="tracking-stat-value text-emerald-700"><?= $summary['cuti_approved'] ?></div>
                    <div class="tracking-stat-note">Pengajuan yang sudah disetujui</div>
                </div>
            </article>
            <article class="tracking-stat-card">
                <div class="tracking-stat-icon is-return"><?= ems_icon('arrow-uturn-left', 'h-5 w-5') ?></div>
                <div class="tracking-stat-body">
                    <div class="tracking-stat-label">Kembali Lebih Awal</div>
                    <div class="tracking-stat-value text-amber-700"><?= $summary['kembali_lebih_awal'] ?></div>
                    <div class="tracking-stat-note">Cuti yang diakhiri sebelum tanggal rencana</div>
                </div>
            </article>
        </div>

        <div class="card card-section history-table-card">
            <div class="history-table-head">
                <div>
                    <h3 class="history-table-title">History Pengajuan Cuti</h3>
                    <p class="history-table-copy">Menampilkan semua histori pengajuan cuti beserta periode, status, approver, dan informasi kembali kerja bila cuti dipercepat selesai.</p>
                </div>
            </div>
            <div class="table-wrapper table-wrapper-sm">
                <table id="hrCutiHistoryTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Tanggal Pengajuan</th>
                            <th>Nama Medis</th>
                            <th>Divisi / Posisi</th>
                            <th>Kode</th>
                            <th>Periode Pengajuan</th>
                            <th>Total Hari</th>
                            <th>Status</th>
                            <th>Approver</th>
                            <th>Tanggal Kembali Kerja</th>
                            <th>Dikonfirmasi Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cutiHistory as $row): ?>
                            <?php $badge = get_status_badge((string)($row['status'] ?? 'pending')); ?>
                            <tr>
                                <td data-order="<?= (int)($row['created_at_sort'] ?? 0) ?>"><?= htmlspecialchars(formatTanggalID((string)$row['created_at'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars((string)($row['full_name'] ?? '-')) ?></strong>
                                    <div class="table-meta"><?= (int)($row['is_active'] ?? 0) === 1 ? 'Aktif' : 'Nonaktif' ?></div>
                                </td>
                                <td>
                                    <?= htmlspecialchars(ems_normalize_division((string)($row['division'] ?? ''))) ?>
                                    <div class="table-meta"><?= htmlspecialchars(ems_position_label((string)($row['position'] ?? ''))) ?></div>
                                </td>
                                <td><strong><?= htmlspecialchars((string)($row['request_code'] ?? '-')) ?></strong></td>
                                <td>
                                    <?= htmlspecialchars(formatTanggalIndo((string)($row['start_date'] ?? ''))) ?>
                                    -
                                    <?= htmlspecialchars(formatTanggalIndo((string)($row['end_date'] ?? ''))) ?>
                                </td>
                                <td>
                                    <?= (int)($row['days_total'] ?? 0) ?> hari
                                    <?php if (!empty($row['is_early_return']) && $row['days_used_after_return'] !== null): ?>
                                        <div class="table-meta">Dipakai: <?= (int)$row['days_used_after_return'] ?> hari</div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="request-status-badge request-status-<?= htmlspecialchars((string)($row['status'] ?? 'pending')) ?>"><?= htmlspecialchars($badge['label']) ?></span></td>
                                <td><?= htmlspecialchars((string)($row['approved_by_name'] ?? '-')) ?></td>
                                <td data-order="<?= (int)($row['returned_effective_date_sort'] ?? 0) ?>"><?= htmlspecialchars(!empty($row['returned_effective_date']) ? formatTanggalIndo((string)$row['returned_effective_date']) : '-') ?></td>
                                <td><?= htmlspecialchars((string)($row['returned_by_name'] ?? '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$cutiHistory): ?>
                    <div class="muted-placeholder p-4">Belum ada histori pengajuan cuti.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<style>
.page-shell {
    max-width: 1480px;
}

.history-table-card {
    border: 1px solid rgba(226, 232, 240, 0.9);
    box-shadow: 0 18px 44px rgba(15, 23, 42, 0.08);
    overflow: hidden;
    background: rgba(255, 255, 255, 0.97);
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
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}

.tracking-stat-icon.is-approved {
    background: rgba(16, 185, 129, 0.12);
    color: #047857;
}

.tracking-stat-icon.is-return {
    background: rgba(245, 158, 11, 0.14);
    color: #b45309;
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
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

#hrCutiHistoryTable {
    min-width: 1420px;
}

.table-custom thead th {
    background: #f8fafc;
    color: #334155;
    font-size: 0.67rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
}

.table-custom tbody td {
    vertical-align: top;
    font-size: 0.75rem;
    line-height: 1.35;
    white-space: nowrap;
}

.table-meta {
    margin-top: 0.25rem;
    font-size: 0.68rem;
    color: #64748b;
    white-space: nowrap;
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
}

@media (max-width: 840px) {
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
            pageLength: 25,
            order: [[0, 'desc']],
            scrollX: true,
            autoWidth: false,
            language: {
                url: '/assets/design/js/datatables-id.json'
            },
            columnDefs: [
                { targets: '_all', className: 'dt-nowrap' }
            ],
            drawCallback: function() {
                jQuery('#hrCutiHistoryTable th, #hrCutiHistoryTable td').css('white-space', 'nowrap');
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
