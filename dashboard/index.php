<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$isTrainee = (strtolower(trim($_SESSION['user_rh']['position'] ?? '')) === 'trainee');
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);

$pageTitle = 'Dashboard | Farmasi EMS';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// DATA DASHBOARD
require_once __DIR__ . ($isTrainee ? '/dashboard_data_medis.php' : '/dashboard_data.php');
?>

<?php if (!$isTrainee): ?>
    <script>
        window.DASHBOARD_DATA = <?= json_encode($dashboard, JSON_NUMERIC_CHECK); ?>;
    </script>
<?php endif; ?>

<h1 class="page-title">Dashboard</h1>
<p class="page-subtitle">
    <?= htmlspecialchars(ems_unit_label($effectiveUnit)) ?> &bull; <?= htmlspecialchars($rangeLabel) ?>
</p>

<?php if (!$isTrainee): ?>
<h3 class="section-title section-farmasi"><?= ems_icon('beaker', 'h-5 w-5 text-primary') ?> Rekap Farmasi</h3>

<div class="dashboard-grid">

    <div class="card card-farmasi">
        <div class="card-header">Total Medis Melayani</div>
        <h2><?= number_format($dashboard['total_medic']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Total Konsumen Farmasi</div>
        <h2><?= number_format($dashboard['total_consumer']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Paket A Terjual</div>
        <h2><?= number_format($dashboard['total_paket_a']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Paket B Terjual</div>
        <h2><?= number_format($dashboard['total_paket_b']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Bandage Terjual</div>
        <h2><?= number_format($dashboard['total_bandage']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Painkiller Terjual</div>
        <h2><?= number_format($dashboard['total_painkiller']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">IFAKS Terjual</div>
        <h2><?= number_format($dashboard['total_ifaks']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Total Transaksi</div>
        <h2><?= number_format($dashboard['total_transaksi']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Total Item Terjual</div>
        <h2><?= number_format($dashboard['total_item']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Total Pemasukan</div>
        <h2>Rp <?= number_format($dashboard['total_income']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Bonus Medis (40%)</div>
        <h2>Rp <?= number_format($dashboard['total_bonus']) ?></h2>
    </div>

    <div class="card card-farmasi">
        <div class="card-header">Keuntungan Perusahaan (60%)</div>
        <h2>Rp <?= number_format($dashboard['company_profit']) ?></h2>
    </div>

</div>
<?php endif; ?>

<h3 class="section-title section-medis"><?= ems_icon('building-office-2', 'h-5 w-5 text-success') ?> Rekap Medis</h3>

<div class="dashboard-grid">

    <div class="card card-medis">
        <div class="card-header">Total P3K</div>
        <h2><?= number_format($dashboard['rekap_medis']['p3k']) ?></h2>
    </div>

    <div class="card card-medis">
        <div class="card-header">Total Bandage</div>
        <h2><?= number_format($dashboard['rekap_medis']['bandage']) ?></h2>
    </div>

    <div class="card card-medis">
        <div class="card-header">Total Gauze</div>
        <h2><?= number_format($dashboard['rekap_medis']['gauze']) ?></h2>
    </div>

    <div class="card card-medis">
        <div class="card-header">Total Iodine</div>
        <h2><?= number_format($dashboard['rekap_medis']['iodine']) ?></h2>
    </div>

    <div class="card card-medis">
        <div class="card-header">Total Syringe</div>
        <h2><?= number_format($dashboard['rekap_medis']['syringe']) ?></h2>
    </div>

    <div class="card card-medis">
        <div class="card-header">Operasi Plastik</div>
        <h2><?= number_format($dashboard['rekap_medis']['operasi_plastik']) ?></h2>
    </div>

    <div class="card card-medis">
        <div class="card-header">Operasi Ringan</div>
        <h2><?= number_format($dashboard['rekap_medis']['operasi_ringan']) ?></h2>
    </div>

    <div class="card card-medis">
        <div class="card-header">Operasi Berat</div>
        <h2><?= number_format($dashboard['rekap_medis']['operasi_berat']) ?></h2>
    </div>

</div>

<?php if (!$isTrainee): ?>
<div class="card" style="margin-top:20px;">
    <div class="card-header"><?= ems_icon('chart-bar', 'h-5 w-5 text-primary') ?> Penjualan Mingguan (Perusahaan)</div>

    <div class="chart-container">
        <canvas id="weeklyChart"></canvas>
    </div>
</div>

<!-- ===================== -->
<!-- JUARA MINGGUAN -->
<!-- ===================== -->
<div class="card" style="margin-top:20px;">
    <div class="card-header"><?= ems_icon('check-circle', 'h-5 w-5 text-success') ?> Juara Mingguan (Medis)</div>

    <div class="dashboard-grid">
        <?php foreach ($dashboard['weekly_winner'] as $week => $data): ?>
            <div class="card">
                <strong><?= strtoupper($week) ?></strong><br>
                <?= htmlspecialchars($data['medic']) ?><br>
                <small>Rp <?= number_format($data['bonus_40']) ?></small>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
</div>
</section>
<?php if (!$isTrainee): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {

        if (!window.Chart || !window.DASHBOARD_DATA) return;

        const canvas = document.getElementById('weeklyChart');
        if (!canvas) return;

        const weekly = window.DASHBOARD_DATA.chart_weekly;

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: weekly.labels,
                datasets: [{
                    label: 'Total Keuntungan (100%)',
                    data: weekly.values, // ⬅️ NILAI SUDAH 100%
                    backgroundColor: 'rgba(14, 165, 233, 0.6)',
                    borderColor: 'rgba(14, 165, 233, 1)',
                    borderWidth: 1,
                    borderRadius: 8,
                    maxBarThickness: 48
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,

                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return 'Rp ' + ctx.raw.toLocaleString('id-ID');
                            }
                        }
                    }
                },

                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + value.toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        });

    });
</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
