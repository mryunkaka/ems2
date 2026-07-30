<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/dispatcher.php';
if (!isset($_GET['range'])) {
    $_GET['range'] = 'today';
}
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Monitoring Dispatcher';

ems_dispatcher_ensure_tables($pdo);

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

// Halaman ini bisa diakses semua user login, jadi flash error guard division yang
// tersisa dari redirect halaman lain diabaikan karena menyesatkan pengguna.
$errors = array_values(array_filter($errors, static function ($error) {
    return trim((string)$error) !== 'Akses halaman ditolak untuk division Anda.';
}));

$user = $_SESSION['user_rh'] ?? [];
$unitCode = ems_effective_unit($pdo, $user);

$statusOptions = ems_dispatcher_status_options();

// ===============================
// SEMUA ASSIGNMENT (aktif + cleared) DALAM RANGE, PER MEDIS
// ===============================
$stmt = $pdo->prepare("
    SELECT
        ur.id AS medic_id, ur.full_name, ur.position,
        da.id AS assignment_id, da.assignment_code, da.status_code, da.status_label_custom,
        da.coordinate, da.location_name, da.koordinasi_note, da.note,
        da.status, da.started_at, da.cleared_at
    FROM user_rh ur
    LEFT JOIN dispatcher_assignment_members dam ON dam.medic_user_id = ur.id
    LEFT JOIN dispatcher_assignments da
        ON da.id = dam.assignment_id
       AND da.unit_code = ?
       AND da.started_at BETWEEN ? AND ?
    WHERE ur.division = 'Medis'
      AND ur.is_active = 1
      AND COALESCE(ur.unit_code, 'roxwood') = ?
    ORDER BY ur.full_name ASC, da.started_at DESC
");
$stmt->execute([$unitCode, $rangeStart, $rangeEnd, $unitCode]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$contributionStatuses = ['respon_lapangan', 'bantu_igd', 'standby_resepsionis'];

$medicStats = [];
foreach ($rows as $row) {
    $medicId = (int)$row['medic_id'];
    if (!isset($medicStats[$medicId])) {
        $medicStats[$medicId] = [
            'name' => $row['full_name'],
            'jabatan' => ems_position_label($row['position'] ?? '-'),
            'counts' => array_fill_keys(array_keys($statusOptions), 0),
            'total_assignments' => 0,
            'total_duration_seconds' => 0,
            'contribution_count' => 0,
            'history' => [],
        ];
    }

    if ($row['assignment_id'] === null) {
        continue;
    }

    $statusCode = (string)$row['status_code'];
    $medicStats[$medicId]['total_assignments']++;
    if (isset($medicStats[$medicId]['counts'][$statusCode])) {
        $medicStats[$medicId]['counts'][$statusCode]++;
    }
    if (in_array($statusCode, $contributionStatuses, true)) {
        $medicStats[$medicId]['contribution_count']++;
    }

    $startTs = strtotime((string)$row['started_at']) ?: 0;
    $endTs = $row['cleared_at'] ? (strtotime((string)$row['cleared_at']) ?: $startTs) : time();
    $duration = max(0, $endTs - $startTs);
    $medicStats[$medicId]['total_duration_seconds'] += $duration;

    $medicStats[$medicId]['history'][] = [
        'code' => $row['assignment_code'],
        'status' => ems_dispatcher_status_label($statusCode, $row['status_label_custom']),
        'info' => trim(implode(' | ', array_filter([
            $row['coordinate'] ? ('Koordinat: ' . $row['coordinate'] . ($row['location_name'] ? ' (' . $row['location_name'] . ')' : '')) : '',
            $row['koordinasi_note'] ? ('Koordinasi: ' . $row['koordinasi_note']) : '',
            $row['note'] ? ('Catatan: ' . $row['note']) : '',
        ]))),
        'started_at' => ems_dispatcher_datetime_label($row['started_at']),
        'cleared_at' => $row['status'] === 'active' ? 'Masih Aktif' : ems_dispatcher_datetime_label($row['cleared_at']),
        'duration' => ems_dispatcher_duration_label($duration),
    ];
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">

        <h1 class="page-title">Monitoring Dispatcher</h1>
        <p class="page-subtitle">Rekap riwayat status &amp; kontribusi respon lapangan setiap medis unit <?= htmlspecialchars(ems_unit_hospital_name($unitCode)) ?>.</p>

        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string)$message, 'success', 'Monitoring Dispatcher') ?>
        <?php endforeach; ?>
        <?php foreach ($warnings as $warning): ?>
            <?= ems_render_toast_script((string)$warning, 'warning', 'Monitoring Dispatcher') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string)$error, 'error', 'Monitoring Dispatcher', 6800) ?>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header-actions card-section">
                <div class="card-header-actions-title">Rekap Aktivitas (<?= htmlspecialchars($rangeLabel) ?>)</div>
                <div class="card-header-actions-right">
                    <form method="GET" class="filter-bar" id="monitoringRangeForm">
                        <select name="range" id="monitoringRangeSelect" class="form-control">
                            <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Hari Ini</option>
                            <option value="yesterday" <?= $range === 'yesterday' ? 'selected' : '' ?>>Kemarin</option>
                            <option value="last7" <?= $range === 'last7' ? 'selected' : '' ?>>7 Hari Terakhir</option>
                            <option value="week4" <?= $range === 'week4' ? 'selected' : '' ?>>Minggu Ini</option>
                            <option value="month1" <?= $range === 'month1' ? 'selected' : '' ?>>Bulan Ini</option>
                            <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                        <input type="date" name="from" id="monitoringRangeFrom" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>" class="form-control<?= $range === 'custom' ? '' : ' hidden' ?>">
                        <input type="date" name="to" id="monitoringRangeTo" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>" class="form-control<?= $range === 'custom' ? '' : ' hidden' ?>">
                        <button type="submit" class="btn-secondary">Terapkan</button>
                    </form>
                </div>
            </div>
            <div class="table-wrapper">
                <table id="dispatcherMonitoringTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Jabatan</th>
                            <th>Total Tugas</th>
                            <th>Respon Lapangan</th>
                            <th>Bantu IGD</th>
                            <th>Standby</th>
                            <th>Rapat</th>
                            <th>Kunjungan</th>
                            <th>Istirahat/10-7</th>
                            <th>Lainnya</th>
                            <th>Total Durasi</th>
                            <th>Skor Kontribusi</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medicStats as $medicId => $stat): ?>
                            <?php
                            $total = $stat['total_assignments'];
                            $contributionScore = $total > 0 ? round(($stat['contribution_count'] / $total) * 100) : null;
                            $scoreBadge = 'badge-muted';
                            if ($contributionScore !== null) {
                                $scoreBadge = $contributionScore >= 50 ? 'badge-success' : ($contributionScore >= 20 ? 'badge-warning' : 'badge-danger');
                            }
                            ?>
                            <tr data-history="<?= htmlspecialchars(json_encode($stat['history'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" data-medic-name="<?= htmlspecialchars($stat['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <td><?= htmlspecialchars($stat['name']) ?></td>
                                <td><?= htmlspecialchars($stat['jabatan']) ?></td>
                                <td><?= (int)$total ?></td>
                                <td><?= (int)$stat['counts']['respon_lapangan'] ?></td>
                                <td><?= (int)$stat['counts']['bantu_igd'] ?></td>
                                <td><?= (int)$stat['counts']['standby_resepsionis'] ?></td>
                                <td><?= (int)$stat['counts']['rapat'] ?></td>
                                <td><?= (int)$stat['counts']['kunjungan'] ?></td>
                                <td><?= (int)$stat['counts']['off_duty'] ?></td>
                                <td><?= (int)$stat['counts']['lainnya'] ?></td>
                                <td><?= htmlspecialchars(ems_dispatcher_duration_label($stat['total_duration_seconds'])) ?></td>
                                <td><span class="<?= $scoreBadge ?>"><?= $contributionScore !== null ? $contributionScore . '%' : '-' ?></span></td>
                                <td>
                                    <button type="button" class="btn-secondary action-icon-btn btn-dispatcher-history" title="Lihat Riwayat">
                                        <?= ems_icon('eye', 'h-4 w-4') ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="meta-text-xs" style="margin-top:10px;">
                Skor Kontribusi = persentase tugas berupa Respon Lapangan / Bantu IGD / Standby Resepsionis dibanding total tugas tercatat pada rentang ini. Skor rendah menandakan medis lebih banyak Istirahat/Rapat/Kunjungan/Lainnya dibanding kontribusi aktif.
            </p>
        </div>

    </div>
</section>

<div id="dispatcherHistoryModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title" id="dispatcherHistoryModalTitle">Riwayat Dispatcher</div>
            <button type="button" class="modal-close-btn btn-dispatcher-history-close" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div class="table-wrapper">
                <table class="table-custom" id="dispatcherHistoryModalTable">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Status</th>
                            <th>Info</th>
                            <th>Mulai</th>
                            <th>Selesai</th>
                            <th>Durasi</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        <div class="modal-foot">
            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-dispatcher-history-close">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    function openModal(modal) {
        if (!modal) { return; }
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
    }
    function closeModal(modal) {
        if (!modal) { return; }
        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    var historyModal = document.getElementById('dispatcherHistoryModal');
    var historyModalTitle = document.getElementById('dispatcherHistoryModalTitle');
    var historyModalTbody = document.querySelector('#dispatcherHistoryModalTable tbody');

    document.querySelectorAll('.btn-dispatcher-history').forEach(function (button) {
        button.addEventListener('click', function () {
            var row = button.closest('tr');
            var history = [];
            try {
                history = JSON.parse(row.dataset.history || '[]');
            } catch (e) {
                history = [];
            }

            historyModalTitle.textContent = 'Riwayat Dispatcher — ' + (row.dataset.medicName || '');
            historyModalTbody.innerHTML = '';

            if (history.length === 0) {
                historyModalTbody.innerHTML = '<tr><td colspan="6" class="meta-text-xs">Tidak ada riwayat pada rentang ini.</td></tr>';
            } else {
                history.forEach(function (item) {
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td>' + (item.code || '-') + '</td>' +
                        '<td>' + (item.status || '-') + '</td>' +
                        '<td>' + (item.info || '-') + '</td>' +
                        '<td>' + (item.started_at || '-') + '</td>' +
                        '<td>' + (item.cleared_at || '-') + '</td>' +
                        '<td>' + (item.duration || '-') + '</td>';
                    historyModalTbody.appendChild(tr);
                });
            }

            openModal(historyModal);
        });
    });

    document.querySelectorAll('.btn-dispatcher-history-close').forEach(function (btn) {
        btn.addEventListener('click', function () { closeModal(historyModal); });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeModal(historyModal); }
    });

    var rangeSelect = document.getElementById('monitoringRangeSelect');
    if (rangeSelect) {
        rangeSelect.addEventListener('change', function () {
            var isCustom = rangeSelect.value === 'custom';
            document.getElementById('monitoringRangeFrom').classList.toggle('hidden', !isCustom);
            document.getElementById('monitoringRangeTo').classList.toggle('hidden', !isCustom);
        });
    }

    var datatableLanguageUrl = '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#dispatcherMonitoringTable').DataTable({ pageLength: 25, scrollX: true, autoWidth: false, order: [[2, 'desc']], language: { url: datatableLanguageUrl } });
    }
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
