<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php';

$rangeType = $_GET['range'] ?? 'current_week';

if ($rangeType === 'last_week') {
    // Minggu sebelumnya (Senin–Minggu)
    $rangeStart = date('Y-m-d 00:00:00', strtotime('monday last week'));
    $rangeEnd   = date('Y-m-d 23:59:59', strtotime('sunday last week'));
    $rangeLabel = 'Minggu Sebelumnya';
} elseif ($rangeType === 'custom' && !empty($_GET['start']) && !empty($_GET['end'])) {
    $rangeStart = $_GET['start'] . ' 00:00:00';
    $rangeEnd   = $_GET['end'] . ' 23:59:59';
    $rangeLabel = 'Custom: ' . $_GET['start'] . ' s/d ' . $_GET['end'];
}

$pageTitle = 'Ranking Medis';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// === QUERY RANKING (ASLI DARI REKAP_FARMASI) ===
$stmtRank = $pdo->prepare("
    SELECT 
        medic_name,
        medic_jabatan,
        COUNT(*) AS total_transaksi,
        SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
        SUM(price) AS total_rupiah
    FROM sales
    WHERE created_at BETWEEN :start AND :end
    GROUP BY medic_name, medic_jabatan
    ORDER BY total_rupiah DESC
");
$stmtRank->execute([
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
]);
$medicRanking = $stmtRank->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="content">
    <div class="page" style="max-width:1200px;margin:auto;">
        <h1>Ranking Medis</h1>

        <p style="font-size:13px;color:#9ca3af;">
            <?= htmlspecialchars($rangeLabel) ?>
        </p>

        <div class="card">
            <div class="card-header">
                Ranking Medis Berdasarkan Total Harga
            </div>

            <form method="GET" id="filterForm" class="filter-bar">
                <div class="filter-group">
                    <select name="range" id="rangeSelect" class="form-control">
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

                <div class="filter-group filter-custom">
                    <input type="date" name="start" value="<?= $_GET['start'] ?? '' ?>" class="form-control">
                </div>

                <div class="filter-group filter-custom">
                    <input type="date" name="end" value="<?= $_GET['end'] ?? '' ?>" class="form-control">
                </div>

                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">Terapkan</button>
                </div>
            </form>

            <div class="table-wrapper">
                <table id="rankingTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Medis</th>
                            <th>Jabatan</th>
                            <th>Total Transaksi</th>
                            <th>Total Item</th>
                            <th>Total Harga</th>
                            <th>Bonus (40%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medicRanking as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['medic_name']) ?></td>
                                <td><?= htmlspecialchars($row['medic_jabatan']) ?></td>
                                <td><?= (int)$row['total_transaksi'] ?></td>
                                <td><?= (int)$row['total_item'] ?></td>
                                <td><?= dollar($row['total_rupiah']) ?></td>
                                <td><?= dollar(floor($row['total_rupiah'] * 0.4)) ?></td>
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
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#rankingTable').DataTable({
                order: [
                    [5, 'desc']
                ],
                pageLength: 10,
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                }
            });
        }
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const rangeSelect = document.getElementById('rangeSelect');
        const customFields = document.querySelectorAll('.filter-custom');

        function toggleCustom() {
            if (rangeSelect.value === 'custom') {
                customFields.forEach(el => el.style.display = 'block');
            } else {
                customFields.forEach(el => el.style.display = 'none');
            }
        }

        rangeSelect.addEventListener('change', toggleCustom);
        toggleCustom(); // initial load
    });
</script>


<?php include __DIR__ . '/../partials/footer.php'; ?>