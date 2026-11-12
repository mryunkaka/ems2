<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/duty_hours_api.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_not_trainee_html('Laporan Absensi');
if (ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []) !== 'roxwood') {
    header('Location: /dashboard/index.php');
    exit;
}

$type = (string) ($_GET['type'] ?? 'daily');
if (!in_array($type, ['daily', 'weekly', 'monthly', 'custom'], true)) $type = 'daily';
$today = date('Y-m-d');
$startDate = trim((string) ($_GET['start'] ?? $today));
$endDate = trim((string) ($_GET['end'] ?? $today));
$period = match ($type) { 'weekly' => '1_week', 'monthly' => '1_month', default => 'custom_range' };
$errors = [];
$report = ['date_range' => [], 'summary' => [], 'rows' => []];
if (($type === 'daily' && !ems_duty_hours_date($startDate)) || ($type === 'custom' && (!ems_duty_hours_date($startDate) || !ems_duty_hours_date($endDate) || $startDate > $endDate))) {
    $errors[] = 'Rentang tanggal tidak valid.';
} else {
    if ($type === 'daily') $endDate = $startDate;
    try {
        $report = ems_duty_hours_request($pdo, $period, $startDate, $endDate);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
$periodLabel = ['daily' => 'Harian', 'weekly' => 'Mingguan', 'monthly' => 'Bulanan', 'custom' => 'Custom'][ $type ];
$dateFrom = trim((string) ($report['date_range']['from'] ?? ''));
$dateTo = trim((string) ($report['date_range']['to'] ?? ''));
$dateRangeLabel = $dateFrom !== '' && $dateTo !== '' ? $dateFrom . ' — ' . $dateTo : $periodLabel;
$pageTitle = 'Laporan Absensi';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Laporan Absensi</h1>
        <p class="page-subtitle">Data diambil langsung dari Medical Center saat laporan dimuat. Jam duty hanya untuk medis Roxwood yang terdaftar. Periode: <?= htmlspecialchars($dateRangeLabel ?: $periodLabel, ENT_QUOTES, 'UTF-8') ?></p>

        <div class="kpi-grid">
            <div class="kpi-card"><div class="kpi-label">Medis Terdaftar</div><div class="kpi-value"><?= number_format(count($report['rows']), 0, ',', '.') ?></div></div>
            <div class="kpi-card kpi-card-accent"><div class="kpi-label">Total Jam Duty</div><div class="kpi-value"><?= number_format(array_sum(array_map(static fn($r) => (int) ($r['total_seconds'] ?? 0), $report['rows'])) / 3600, 2, ',', '.') ?></div></div>
            <div class="kpi-card"><div class="kpi-label">Medis Bertugas</div><div class="kpi-value"><?= number_format(count(array_filter($report['rows'], static fn($r) => (int) ($r['total_seconds'] ?? 0) > 0)), 0, ',', '.') ?></div></div>
        </div>

        <div class="card card-section mb-4">
            <div class="card-header">Filter Periode</div>
            <div class="card-body">
                <form method="get" class="filter-bar" id="attendanceFilter">
                    <div class="filter-group"><label for="type">Jenis Laporan</label><select name="type" id="type" class="form-control"><option value="daily" <?= $type === 'daily' ? 'selected' : '' ?>>Harian</option><option value="weekly" <?= $type === 'weekly' ? 'selected' : '' ?>>Mingguan</option><option value="monthly" <?= $type === 'monthly' ? 'selected' : '' ?>>Bulanan</option><option value="custom" <?= $type === 'custom' ? 'selected' : '' ?>>Custom Tanggal</option></select></div>
                    <div class="filter-group date-filter"><label for="start">Tanggal Mulai / Hari</label><input class="form-control" type="date" name="start" id="start" value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="filter-group custom-filter"><label for="end">Tanggal Akhir</label><input class="form-control" type="date" name="end" id="end" value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="filter-group filter-action-end"><div class="filter-action-buttons"><button class="btn btn-primary" type="submit">Terapkan</button><button class="btn-secondary" type="submit" formaction="laporan_absensi_export.php"><?= ems_icon('document-arrow-down', 'h-4 w-4') ?><span>Download Excel</span></button></div></div>
                </form>
            </div>
        </div>

        <?php foreach ($errors as $error): ?><div class="alert alert-danger mb-4"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <div class="card"><div class="card-header">Rekap Medis <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></div><div class="table-wrapper"><table id="attendanceReportTable" class="table-custom" data-auto-datatable="true" data-dt-page-length="25" data-dt-order='[[4,"desc"]]'><thead><tr><th>#</th><th>Nama Medis</th><th>Citizen ID</th><th>Jabatan</th><th>Total Jam</th><th>Total Sesi</th><th>Duty Terakhir</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($report['rows'] as $i => $row): $staff = $row['registered_staff']; ?>
                <tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars((string) ($staff['full_name'] ?? $row['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($staff['citizen_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(ems_position_label($staff['position'] ?? $row['role'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($row['formatted_duty_time'] ?? '0 Jam 0 Menit'), ENT_QUOTES, 'UTF-8') ?></td><td><?= number_format((int) ($row['total_sessions'] ?? 0), 0, ',', '.') ?></td><td><?= htmlspecialchars((string) ($row['last_duty_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td><td><?= !empty($row['is_currently_on_duty']) ? 'Sedang duty' : 'Selesai' ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$report['rows']): ?><tr><td colspan="8" class="text-center">Tidak ada data absensi untuk medis Roxwood yang terdaftar.</td></tr><?php endif; ?>
        </tbody></table></div></div>
    </div>
</section>
<script>
const typeSelect=document.getElementById('type');
function updateDateFilters(){const daily=typeSelect.value==='daily',custom=typeSelect.value==='custom';document.querySelector('.date-filter').classList.toggle('hidden',!(daily||custom));document.querySelector('.custom-filter').classList.toggle('hidden',!custom);}
typeSelect.addEventListener('change',updateDateFilters);updateDateFilters();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
