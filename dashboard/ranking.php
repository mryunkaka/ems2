<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php';

// Block access for users on cuti
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

function ranking_format_indo_short_date(string $dateTime): string
{
    static $months = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'Mei',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Agu',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Des',
    ];

    $date = new DateTime($dateTime);
    $month = $months[(int)$date->format('n')] ?? $date->format('M');

    return $date->format('d') . ' ' . $month . ' ' . $date->format('Y');
}

$rangeType = $_GET['range'] ?? 'current_week';

if ($rangeType === 'last_week') {
    // Minggu sebelumnya (Senin–Minggu)
    $rangeStart = date('Y-m-d 00:00:00', strtotime('monday last week'));
    $rangeEnd   = date('Y-m-d 23:59:59', strtotime('sunday last week'));
    $rangeTitle = 'Minggu Sebelumnya';
} elseif ($rangeType === 'custom' && !empty($_GET['start']) && !empty($_GET['end'])) {
    $rangeStart = $_GET['start'] . ' 00:00:00';
    $rangeEnd   = $_GET['end'] . ' 23:59:59';
    $rangeTitle = 'Custom';
} else {
    $rangeStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $rangeEnd   = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    $rangeTitle = 'Minggu Ini';
}

$rangeStartLabel = ranking_format_indo_short_date($rangeStart);
$rangeEndLabel = ranking_format_indo_short_date($rangeEnd);

$pageTitle = 'Ranking Medis';

ems_require_not_trainee_html('Ranking');

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// === QUERY RANKING (BERDASARKAN USER ID) ===
$stmtRank = $pdo->prepare("
    SELECT
        medic_user_id,
        MAX(medic_name) AS medic_name,
        MAX(medic_jabatan) AS medic_jabatan,
        COUNT(*) AS total_transaksi,
        SUM(qty_bandage) AS total_bandage,
        SUM(qty_ifaks) AS total_ifaks,
        SUM(qty_painkiller) AS total_painkiller,
        SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
        SUM(price) AS total_rupiah
    FROM sales
    WHERE created_at BETWEEN :start AND :end
    GROUP BY medic_user_id
    ORDER BY total_rupiah DESC
");
$stmtRank->execute([
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
]);
$medicRanking = $stmtRank->fetchAll(PDO::FETCH_ASSOC);

$stmtSummary = $pdo->prepare("
    SELECT
        COALESCE(SUM(qty_bandage), 0) AS total_bandage,
        COALESCE(SUM(qty_ifaks), 0) AS total_ifaks,
        COALESCE(SUM(qty_painkiller), 0) AS total_painkiller
    FROM sales
    WHERE created_at BETWEEN :start AND :end
");
$stmtSummary->execute([
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
]);
$summary = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [
    'total_bandage' => 0,
    'total_ifaks' => 0,
    'total_painkiller' => 0,
];
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Ranking Medis</h1>

        <p class="page-subtitle">
            <?= htmlspecialchars($rangeTitle) ?> &bull; <?= htmlspecialchars($rangeStartLabel) ?> &ndash; <?= htmlspecialchars($rangeEndLabel) ?>
        </p>

        <div class="card card-section">
            <div class="card-header">Filter Rentang Tanggal</div>
            <div class="card-body">
                <div class="stats-grid-3 mb-4">
                    <div class="card stats-card">
                        <div class="card-body stats-body-center">
                            <small class="stats-label">Total Bandage</small>
                            <div class="stats-value-teal">
                                <?= number_format((int)$summary['total_bandage'], 0, ',', '.') ?>
                            </div>
                        </div>
                    </div>
                    <div class="card stats-card">
                        <div class="card-body stats-body-center">
                            <small class="stats-label">Total IFAKS</small>
                            <div class="stats-value-blue">
                                <?= number_format((int)$summary['total_ifaks'], 0, ',', '.') ?>
                            </div>
                        </div>
                    </div>
                    <div class="card stats-card">
                        <div class="card-body stats-body-center">
                            <small class="stats-label">Total Painkiller</small>
                            <div class="stats-value-amber">
                                <?= number_format((int)$summary['total_painkiller'], 0, ',', '.') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="GET" id="filterForm" class="filter-bar">
                    <div class="filter-group">
                        <label>Rentang</label>
                        <select name="range" id="rangeSelect" class="form-control min-w-[220px] md:min-w-[280px]">
                            <option value="current_week" <?= ($_GET['range'] ?? '') === 'current_week' ? 'selected' : '' ?>>
                                Minggu Ini
                            </option>
                            <option value="last_week" <?= ($_GET['range'] ?? '') === 'last_week' ? 'selected' : '' ?>>
                                Minggu Sebelumnya
                            </option>
                            <option value="custom" <?= ($_GET['range'] ?? '') === 'custom' ? 'selected' : '' ?>>
                                Custom
                            </option>
                        </select>
                    </div>

                    <div class="filter-group filter-custom <?= $rangeType === 'custom' ? '' : 'hidden' ?>">
                        <label>Tanggal Awal</label>
                        <input type="date" name="start" value="<?= $_GET['start'] ?? '' ?>" class="form-control">
                    </div>

                    <div class="filter-group filter-custom <?= $rangeType === 'custom' ? '' : 'hidden' ?>">
                        <label>Tanggal Akhir</label>
                        <input type="date" name="end" value="<?= $_GET['end'] ?? '' ?>" class="form-control">
                    </div>

                    <div class="filter-group filter-action-end">
                        <button type="submit" class="btn-secondary">Terapkan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                Ranking Medis Berdasarkan Total Harga
            </div>

            <div class="table-wrapper">
                <table id="rankingTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Medis</th>
                            <th>Jabatan</th>
                            <th>Total Transaksi</th>
                            <th>Total Bandage</th>
                            <th>Total IFAKS</th>
                            <th>Total Painkiller</th>
                            <th>Total Item</th>
                            <th>Total Harga</th>
                            <th>Bonus (40%)</th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr>
                            <th colspan="3" class="table-align-right">TOTAL</th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                        </tr>
                    </tfoot>
                    <tbody>
                        <?php foreach ($medicRanking as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['medic_name']) ?></td>
                                <td><?= htmlspecialchars($row['medic_jabatan']) ?></td>
                                <td><?= (int)$row['total_transaksi'] ?></td>
                                <td><?= (int)$row['total_bandage'] ?></td>
                                <td><?= (int)$row['total_ifaks'] ?></td>
                                <td><?= (int)$row['total_painkiller'] ?></td>
                                <td><?= (int)$row['total_item'] ?></td>
                                <td data-order="<?= (int)$row['total_rupiah'] ?>"><?= dollar($row['total_rupiah']) ?></td>
                                <td data-order="<?= (int)floor($row['total_rupiah'] * 0.4) ?>"><?= dollar(floor($row['total_rupiah'] * 0.4)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        function parseNumber(value) {
            if (value == null) return 0;
            if (typeof value === 'number') return value;

            const cleaned = String(value).replace(/[^\d.-]/g, '');
            return cleaned ? parseFloat(cleaned) : 0;
        }

        function formatNumber(value) {
            return new Intl.NumberFormat('id-ID').format(value || 0);
        }

        function formatDollar(value) {
            return '$' + formatNumber(value);
        }

        function updateFooterFallback() {
            const table = document.getElementById('rankingTable');
            if (!table) return;

            const footerCells = table.querySelectorAll('tfoot th');
            if (footerCells.length < 8) return;

            let totalTransaksi = 0;
            let totalBandage = 0;
            let totalIfaks = 0;
            let totalPainkiller = 0;
            let totalItem = 0;
            let totalHarga = 0;
            let totalBonus = 0;

            table.querySelectorAll('tbody tr').forEach(function(row) {
                const cells = row.cells;
                if (!cells || cells.length < 10) return;

                totalTransaksi += parseNumber(cells[3].textContent);
                totalBandage += parseNumber(cells[4].textContent);
                totalIfaks += parseNumber(cells[5].textContent);
                totalPainkiller += parseNumber(cells[6].textContent);
                totalItem += parseNumber(cells[7].textContent);
                totalHarga += parseNumber(cells[8].textContent);
                totalBonus += parseNumber(cells[9].textContent);
            });

            footerCells[1].textContent = formatNumber(totalTransaksi);
            footerCells[2].textContent = formatNumber(totalBandage);
            footerCells[3].textContent = formatNumber(totalIfaks);
            footerCells[4].textContent = formatNumber(totalPainkiller);
            footerCells[5].textContent = formatNumber(totalItem);
            footerCells[6].textContent = formatDollar(totalHarga);
            footerCells[7].textContent = formatDollar(totalBonus);
        }

        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#rankingTable').DataTable({
                order: [
                    [8, 'desc']
                ],
                pageLength: 10,
                footerCallback: function() {
                    const api = this.api();

                    const sumColumn = function(columnIndex) {
                        return api
                            .column(columnIndex, { search: 'applied' })
                            .data()
                            .reduce(function(total, value) {
                                return total + parseNumber(value);
                            }, 0);
                    };

                    api.column(3).footer().textContent = formatNumber(sumColumn(3));
                    api.column(4).footer().textContent = formatNumber(sumColumn(4));
                    api.column(5).footer().textContent = formatNumber(sumColumn(5));
                    api.column(6).footer().textContent = formatNumber(sumColumn(6));
                    api.column(7).footer().textContent = formatNumber(sumColumn(7));
                    api.column(8).footer().textContent = formatDollar(sumColumn(8));
                    api.column(9).footer().textContent = formatDollar(sumColumn(9));
                },
                language: {
                    url: '/assets/design/js/datatables-id.json'
                }
            });
        } else {
            updateFooterFallback();
        }
    });
</script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const rangeSelect = document.getElementById('rangeSelect');
            const customFields = document.querySelectorAll('.filter-custom');

            function toggleCustom() {
                if (rangeSelect.value === 'custom') {
                    customFields.forEach(el => el.classList.remove('hidden'));
                } else {
                    customFields.forEach(el => el.classList.add('hidden'));
                }
            }

            rangeSelect.addEventListener('change', toggleCustom);
            toggleCustom(); // initial load
        });
    </script>


<?php include __DIR__ . '/../partials/footer.php'; ?>
