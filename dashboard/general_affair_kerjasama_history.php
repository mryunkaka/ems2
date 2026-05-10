<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/general_affair_cooperation_helper.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['General Affair'], '/dashboard/index.php');
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

if (!isset($_GET['range'])) {
    $_GET['range'] = 'week4';
}

require_once __DIR__ . '/../config/date_range.php';

$pageTitle = 'History Paket Gratis Kerjasama';
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$unitLabel = ems_unit_label($effectiveUnit);
$tableReady = gaCooperationTablesReady($pdo) && gaCooperationSalesColumnsReady($pdo);
$selectedInstitutionId = (int)($_GET['institution_id'] ?? 0);
$startDate = $_GET['from'] ?? '';
$endDate = $_GET['to'] ?? '';
$selectedRange = $_GET['range'] ?? 'week4';

$institutions = [];
$groupedRows = [];
$summary = [
    'transactions' => 0,
    'institutions' => 0,
    'members' => 0,
    'packages' => 0,
    'discount_total' => 0,
];

if (gaCooperationTablesReady($pdo)) {
    $stmtInstitutions = $pdo->prepare("
        SELECT id, institution_name, is_active
        FROM general_affair_cooperations
        WHERE unit_code = :unit_code
        ORDER BY is_active DESC, institution_name ASC
    ");
    $stmtInstitutions->execute([':unit_code' => $effectiveUnit]);
    $institutions = $stmtInstitutions->fetchAll(PDO::FETCH_ASSOC);
}

$selectedInstitutionName = 'Semua Instansi';
foreach ($institutions as $institutionRow) {
    if ((int)($institutionRow['id'] ?? 0) === $selectedInstitutionId) {
        $selectedInstitutionName = (string)($institutionRow['institution_name'] ?? 'Semua Instansi');
        break;
    }
}

if ($tableReady) {
    $rows = gaCooperationFetchFreeClaimRows($pdo, $effectiveUnit, $rangeStart, $rangeEnd, $selectedInstitutionId);
    $groupedRows = gaCooperationGroupFreeClaimRows($rows);
    $summary = gaCooperationHistorySummary($groupedRows);
}

$exportParams = ['range' => $selectedRange];
if ($selectedRange === 'custom') {
    $exportParams['from'] = $startDate;
    $exportParams['to'] = $endDate;
}
if ($selectedInstitutionId > 0) {
    $exportParams['institution_id'] = $selectedInstitutionId;
}
$exportUrl = ems_url('/dashboard/general_affair_kerjasama_history_export.php?' . http_build_query($exportParams));

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page mx-auto max-w-[1400px]">
        <div class="ga-history-header">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Riwayat pengambilan paket gratis kerja sama instansi untuk unit <?= htmlspecialchars($unitLabel, ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            <div class="ga-history-header-actions">
                <a href="<?= htmlspecialchars(ems_url('/dashboard/general_affair_kerjasama.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
                    <?= ems_icon('building-office', 'h-4 w-4') ?>
                    <span>Setting Kerjasama</span>
                </a>
            </div>
        </div>

        <?php if (!$tableReady): ?>
            <div class="alert alert-error">
                Modul history paket gratis belum siap. Jalankan SQL <strong>`docs/sql/33_2026-05-08_general_affair_cooperation_settings.sql`</strong> terlebih dahulu.
            </div>
        <?php endif; ?>

        <div class="stats-grid mb-4">
            <div class="card stats-card">
                <div class="card-body stats-body-center">
                    <small class="stats-label">Transaksi Gratis</small>
                    <div class="text-2xl font-extrabold text-slate-900"><?= number_format((int)$summary['transactions'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="card stats-card">
                <div class="card-body stats-body-center">
                    <small class="stats-label">Instansi</small>
                    <div class="text-2xl font-extrabold text-primary"><?= number_format((int)$summary['institutions'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="card stats-card">
                <div class="card-body stats-body-center">
                    <small class="stats-label">Anggota Ambil</small>
                    <div class="text-2xl font-extrabold text-emerald-700"><?= number_format((int)$summary['members'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="card stats-card">
                <div class="card-body stats-body-center">
                    <small class="stats-label">Nilai Gratis</small>
                    <div class="text-2xl font-extrabold text-amber-700">$<?= number_format((int)$summary['discount_total'], 0, ',', '.') ?></div>
                </div>
            </div>
        </div>

        <div class="card card-section">
            <div class="card-header card-header-between">
                <span>Filter History Paket Gratis</span>
                <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary<?= $groupedRows ? '' : ' opacity-60' ?>"<?= $groupedRows ? '' : ' aria-disabled="true"' ?>>
                    <?= ems_icon('document-arrow-down', 'h-4 w-4') ?>
                    <span>Export Excel</span>
                </a>
            </div>
            <div class="card-body">
                <form method="get" class="filter-bar" id="gaHistoryFilterForm">
                    <div class="filter-group">
                        <label>Rentang</label>
                        <select name="range" id="rangeSelect" class="form-control">
                            <option value="week1" <?= $selectedRange === 'week1' ? 'selected' : '' ?>>3 Minggu Lalu</option>
                            <option value="week2" <?= $selectedRange === 'week2' ? 'selected' : '' ?>>2 Minggu Lalu</option>
                            <option value="week3" <?= $selectedRange === 'week3' ? 'selected' : '' ?>>Minggu Lalu</option>
                            <option value="week4" <?= $selectedRange === 'week4' ? 'selected' : '' ?>>Minggu Ini</option>
                            <option value="month1" <?= $selectedRange === 'month1' ? 'selected' : '' ?>>Bulan Ini</option>
                            <option value="custom" <?= $selectedRange === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Awal</label>
                        <input type="date" name="from" value="<?= htmlspecialchars((string)$startDate, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Akhir</label>
                        <input type="date" name="to" value="<?= htmlspecialchars((string)$endDate, ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                    </div>
                    <div class="filter-group">
                        <label>Instansi</label>
                        <select name="institution_id" class="form-control">
                            <option value="0">Semua Instansi</option>
                            <?php foreach ($institutions as $institution): ?>
                                <option value="<?= (int)$institution['id'] ?>" <?= (int)$institution['id'] === $selectedInstitutionId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$institution['institution_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group filter-action-end">
                        <button type="submit" class="btn btn-primary">Terapkan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header card-header-between">
                <span>Daftar History Paket Gratis</span>
                <span class="meta-text-xs"><?= htmlspecialchars($selectedInstitutionName, ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($rangeLabel ?? '-', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="table-wrapper">
                <table id="gaHistoryTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tanggal & Jam</th>
                            <th>Instansi</th>
                            <th>Anggota</th>
                            <th>Paket Gratis</th>
                            <th>Item</th>
                            <th>Nilai Gratis</th>
                            <th>Periode</th>
                            <th>Petugas Medis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupedRows as $index => $row): ?>
                            <?php
                            $timestamp = strtotime((string)($row['transaction_at'] ?? '')) ?: 0;
                            $displayMemberName = trim((string)($row['member_name'] ?? ''));
                            $displayCitizenId = trim((string)($row['citizen_id'] ?? ''));
                            $displayDate = $timestamp > 0 ? date('d M Y', $timestamp) : '-';
                            $displayTime = $timestamp > 0 ? date('H:i', $timestamp) : '-';
                            ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td data-order="<?= $timestamp ?>">
                                    <strong><?= htmlspecialchars($displayDate, ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs"><?= htmlspecialchars($displayTime, ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$row['institution_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($displayMemberName !== '' ? $displayMemberName : '-', ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs"><?= htmlspecialchars($displayCitizenId !== '' ? $displayCitizenId : '-', ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars((string)($row['package_summary'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="table-align-center">
                                    <strong><?= number_format((int)($row['package_count'] ?? 0), 0, ',', '.') ?></strong> paket
                                </td>
                                <td class="font-semibold text-emerald-700">
                                    $<?= number_format((int)($row['total_discount_amount'] ?? 0), 0, ',', '.') ?>
                                </td>
                                <td>
                                    <span class="badge-muted"><?= htmlspecialchars((string)($row['period_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars((string)($row['medic_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs"><?= htmlspecialchars((string)($row['medic_jabatan'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!$groupedRows): ?>
                    <div class="muted-placeholder p-4">
                        Belum ada history paket gratis pada filter yang dipilih.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rangeSelect = document.getElementById('rangeSelect');
    const customFields = document.querySelectorAll('.filter-custom');

    function toggleCustom() {
        const isCustom = rangeSelect && rangeSelect.value === 'custom';
        customFields.forEach(function(element) {
            element.style.display = isCustom ? 'block' : 'none';
        });
    }

    if (rangeSelect) {
        rangeSelect.addEventListener('change', toggleCustom);
        toggleCustom();
    }

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#gaHistoryTable').DataTable({
            pageLength: 10,
            order: [[1, 'desc']],
            scrollX: true,
            autoWidth: false,
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            }
        });
    }
});
</script>

<style>
.ga-history-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
}

.ga-history-header-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

@media (max-width: 768px) {
    .ga-history-header {
        flex-direction: column;
    }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
