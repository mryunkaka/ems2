<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

/* ===============================
   RANGE DEFAULT: MINGGU INI
   =============================== */
$rangeType = $_GET['range'] ?? 'current_week';

if ($rangeType === 'last_week') {
    $rangeStart = date('Y-m-d 00:00:00', strtotime('monday last week'));
    $rangeEnd   = date('Y-m-d 23:59:59', strtotime('sunday last week'));
    $rangeLabel = 'Minggu Sebelumnya';
} elseif ($rangeType === 'custom' && !empty($_GET['start']) && !empty($_GET['end'])) {
    $rangeStart = $_GET['start'] . ' 00:00:00';
    $rangeEnd   = $_GET['end'] . ' 23:59:59';
    $rangeLabel = 'Custom';
} else {
    $rangeStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $rangeEnd   = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    $rangeLabel = 'Minggu Ini';
}

$pageTitle = 'Jam Kerja EMS';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

/* ===============================
   QUERY SUMMARY (CARD ATAS)
   =============================== */
$userId = $_SESSION['user_rh']['id'] ?? null;

$stmtSummary = $pdo->prepare("
    SELECT
        SUM(duration_seconds) AS total_seconds
    FROM user_farmasi_sessions
    WHERE user_id = :uid
      AND session_start BETWEEN :start AND :end
");
$stmtSummary->execute([
    ':uid'   => $userId,
    ':start' => $rangeStart,
    ':end'   => $rangeEnd
]);

$totalSecondsWeek = (int)$stmtSummary->fetchColumn();
$totalWeek = gmdate('H:i:s', $totalSecondsWeek);

/* ===============================
   SESI HARI INI (USER)
   =============================== */
$stmtTodaySessions = $pdo->prepare("
    SELECT
        session_start,
        session_end,
        duration_seconds,
        end_reason,
        UNIX_TIMESTAMP(session_start) as start_timestamp
    FROM user_farmasi_sessions
    WHERE user_id = :uid
      AND DATE(session_start) = CURDATE()
    ORDER BY session_start ASC
");
$stmtTodaySessions->execute([
    ':uid' => $userId
]);

$todaySessions = $stmtTodaySessions->fetchAll(PDO::FETCH_ASSOC);

/* Akumulasi seluruh waktu */
$stmtAll = $pdo->prepare("
    SELECT SUM(duration_seconds)
    FROM user_farmasi_sessions
    WHERE user_id = :uid
");
$stmtAll->execute([':uid' => $userId]);
$totalAll = gmdate('H:i:s', (int)$stmtAll->fetchColumn());

/* ===============================
   LEADERBOARD MINGGUAN
   =============================== */
$stmtLeaderboard = $pdo->prepare("
    SELECT
        medic_name,
        medic_jabatan,
        SUM(duration_seconds) AS total_seconds,
        COUNT(*) AS total_sesi
    FROM user_farmasi_sessions
    WHERE session_start BETWEEN :start AND :end
    GROUP BY user_id, medic_name, medic_jabatan
    ORDER BY total_seconds DESC
");
$stmtLeaderboard->execute([
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
]);
$leaderboard = $stmtLeaderboard->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="content">
    <div class="page page-absensi-ems" style="max-width:1200px;margin:auto;">

        <h1>Jam Kerja EMS</h1>
        <p style="font-size:13px;color:#9ca3af;">
            <?= htmlspecialchars($rangeLabel) ?>
        </p>

        <!-- ===============================
     SUMMARY CARDS
     =============================== -->
        <div class="ems-summary-grid">

            <div class="stat-box">
                <small>Total Jam Kerja Minggu Ini</small>
                <h3><?= $totalWeek ?></h3>
            </div>

            <div class="stat-box highlight">
                <small>Akumulasi Jam Pengguna Web</small>
                <h3><?= $totalAll ?></h3>
            </div>

        </div>

        <!-- ===============================
     SESI HARI INI
     =============================== -->
        <div class="card">
            <div class="card-header">
                ⏱️ Sesi Hari Ini
                <span class="weekly-badge">
                    <?= count($todaySessions) ?> Sesi
                </span>
            </div>

            <?php if (empty($todaySessions)): ?>
                <p style="font-size:13px;color:#64748b;">
                    Belum ada sesi hari ini.
                </p>
            <?php else: ?>
                <div class="table-wrapper-sm">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Mulai</th>
                                <th>Selesai</th>
                                <th>Durasi</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todaySessions as $i => $s): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= date('H:i:s', strtotime($s['session_start'])) ?></td>
                                    <td>
                                        <?= $s['session_end']
                                            ? date('H:i:s', strtotime($s['session_end']))
                                            : '<span class="status-badge status-online"><span class="dot"></span> Aktif</span>' ?>
                                    </td>
                                    <td>
                                        <?php if (!$s['session_end']): ?>
                                            <?php
                                            // PENTING: Paksa timezone ke WIB untuk konsistensi
                                            $dt = new DateTime($s['session_start'], new DateTimeZone('Asia/Jakarta'));
                                            $startTimestamp = $dt->getTimestamp();
                                            ?>
                                            <span
                                                class="realtime-duration"
                                                data-start-timestamp="<?= $s['start_timestamp'] ?>">
                                                00:00:00
                                            </span>
                                        <?php else: ?>
                                            <?= gmdate('H:i:s', (int)$s['duration_seconds']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$s['session_end']): ?>
                                            <span class="status-badge status-online">
                                                <span class="dot"></span> ONLINE
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge status-offline">
                                                OFFLINE
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===============================
     FILTER BAR
     =============================== -->
        <div class="card">
            <form method="GET" class="filter-bar">
                <div class="filter-group">
                    <select name="range" id="rangeSelect">
                        <option value="current_week" <?= $rangeType === 'current_week' ? 'selected' : '' ?>>Minggu Ini</option>
                        <option value="last_week" <?= $rangeType === 'last_week' ? 'selected' : '' ?>>Minggu Sebelumnya</option>
                        <option value="custom" <?= $rangeType === 'custom' ? 'selected' : '' ?>>Custom</option>
                    </select>
                </div>

                <div class="filter-group filter-custom">
                    <input type="date" name="start" value="<?= $_GET['start'] ?? '' ?>">
                </div>

                <div class="filter-group filter-custom">
                    <input type="date" name="end" value="<?= $_GET['end'] ?? '' ?>">
                </div>

                <div class="filter-group">
                    <button class="btn-primary">Terapkan</button>
                </div>
            </form>
        </div>

        <!-- ===============================
     LEADERBOARD
     =============================== -->
        <div class="card">
            <div class="card-header">
                🏆 Leaderboard Pengguna Web Farmasi & Layanan Medis Mingguan
            </div>

            <div class="table-wrapper">
                <table id="leaderboardTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Medis</th>
                            <th>Jabatan</th>
                            <th>Total Sesi</th>
                            <th>Total Jam Online</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaderboard as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($row['medic_name']) ?></td>
                                <td><?= htmlspecialchars($row['medic_jabatan']) ?></td>
                                <td><?= (int)$row['total_sesi'] ?></td>
                                <td><?= gmdate('H:i:s', (int)$row['total_seconds']) ?></td>
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
            jQuery('#leaderboardTable').DataTable({
                order: [
                    [4, 'desc']
                ],
                pageLength: 10,
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                }
            });
        }

        const rangeSelect = document.getElementById('rangeSelect');
        const customFields = document.querySelectorAll('.filter-custom');

        function toggleCustom() {
            customFields.forEach(el => {
                el.style.display = (rangeSelect.value === 'custom') ? 'block' : 'none';
            });
        }

        rangeSelect.addEventListener('change', toggleCustom);
        toggleCustom();
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>