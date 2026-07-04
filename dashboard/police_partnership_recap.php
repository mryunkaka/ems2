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
        COUNT(DISTINCT police_badge_no) AS total_badge,
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
    SELECT *
    FROM police_partnership_records
    WHERE DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) BETWEEN :start AND :end
      AND unit_code = :unit_code
    ORDER BY COALESCE(service_at, CONCAT(service_date, ' 00:00:00')) DESC, id DESC
");
$rowsStmt->execute([
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
    ':unit_code' => $effectiveUnit,
]);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
                <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-success">
                    <?= ems_icon('arrow-down-tray', 'h-4 w-4') ?>
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
                <div class="meta-text-xs">Badge Police Unik</div>
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
                            <th>Jam dan Tanggal</th>
                            <th>No Badge</th>
                            <th>Tindakan</th>
                            <th>Diinput Oleh</th>
                            <th>Biaya Per Input</th>
                            <?php if ($canEditAmount): ?>
                                <th>Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $index => $row): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td data-order="<?= htmlspecialchars((string)($row['service_at'] ?? $row['service_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(policePartnershipDateTimeLabel($row['service_at'] ?? '', $row['service_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$row['police_badge_no'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$row['action_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?= htmlspecialchars((string)$row['input_by_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($row['created_at'])): ?>
                                        <div class="status-meta"><?= htmlspecialchars(formatTanggalID($row['created_at']), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= dollar((int)$row['amount']) ?></strong>
                                    <?php if (!empty($row['amount_updated_by'])): ?>
                                        <div class="status-meta">Edit: <?= htmlspecialchars((string)$row['amount_updated_by'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canEditAmount): ?>
                                    <td>
                                        <form method="POST" action="police_partnership_action.php" class="inline-flex items-center gap-2">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="update_amount">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="redirect" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? 'police_partnership_recap.php'), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="number" name="amount" min="0" step="1" value="<?= (int)$row['amount'] ?>" class="form-control" style="width: 120px;">
                                            <button type="submit" class="btn btn-primary btn-sm" title="Simpan biaya" aria-label="Simpan biaya">
                                                <?= ems_icon('check', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="5" class="table-align-right font-semibold">TOTAL :</th>
                            <th><?= dollar((int)($summary['total_amount'] ?? 0)) ?></th>
                            <?php if ($canEditAmount): ?><th></th><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
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
                order: [[1, 'desc']],
                language: {
                    url: '/assets/design/js/datatables-id.json',
                    emptyTable: 'Belum ada data pada rentang ini.'
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
