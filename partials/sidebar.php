<?php
$currentPage = basename($_SERVER['PHP_SELF']);

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));

function isActive($page)
{
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}
?>

<aside id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <div class="brand">
            <div class="avatar-logo" style="background: <?= $avatarColor ?>;">
                <?= $avatarInitials ?>
            </div>
            <div class="brand-text">
                <strong><?= htmlspecialchars($medicName) ?></strong>
                <span><?= htmlspecialchars($medicJabatan) ?></span>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <a href="/dashboard/index.php" class="<?= isActive('index.php') ?>">
            <span class="icon">🏠</span>
            <span class="text">Dashboard</span>
        </a>

        <a href="/dashboard/events.php" class="<?= isActive('events.php') ?>">
            <span class="icon">🎫</span>
            <span class="text">Event</span>
        </a>

        <?php if (strtolower($_SESSION['user_rh']['role'] ?? '') !== 'staff'): ?>
            <a href="/dashboard/event_manage.php" class="<?= isActive('event_manage.php') ?>">
                <span class="icon">🛠️</span>
                <span class="text">Manajemen Event</span>
            </a>
        <?php endif; ?>

        <a href="/dashboard/ems_services.php" class="<?= isActive('ems_services.php') ?>">
            <span class="icon">🏥</span>
            <span class="text">Layanan Medis</span>
        </a>

        <?php
        $position = strtolower(trim($_SESSION['user_rh']['position'] ?? ''));
        ?>

        <?php if ($position !== 'trainee'): ?>
            <a href="/dashboard/rekap_farmasi.php" class="<?= isActive('rekap_farmasi.php') ?>">
                <span class="icon">💊</span>
                <span class="text">Rekap Farmasi</span>
            </a>
        <?php endif; ?>

        <a href="/dashboard/reimbursement.php" class="<?= isActive('reimbursement.php') ?>">
            <span class="icon">🧾</span>
            <span class="text">Reimbursement</span>
        </a>

        <a href="/dashboard/restaurant_consumption.php" class="<?= isActive('restaurant_consumption.php') ?>">
            <span class="icon">🍔</span>
            <span class="text">Restaurant Consumption</span>
        </a>

        <a href="/dashboard/operasi_plastik.php" class="<?= isActive('operasi_plastik.php') ?>">
            <span class="icon">🩺</span>
            <span class="text">Operasi Plastik</span>
        </a>

        <a href="/dashboard/konsumen.php" class="<?= isActive('konsumen.php') ?>">
            <span class="icon">👥</span>
            <span class="text">Konsumen</span>
        </a>

        <a href="/dashboard/ranking.php" class="<?= isActive('ranking.php') ?>">
            <span class="icon">📊</span>
            <span class="text">Ranking</span>
        </a>

        <a href="/dashboard/absensi_ems.php" class="<?= isActive('absensi_ems.php') ?>">
            <span class="icon">⏱️</span>
            <span class="text">Jumlah Jam Web</span>
        </a>

        <a href="/dashboard/gaji.php" class="<?= isActive('gaji.php') ?>">
            <span class="icon">💰</span>
            <span class="text">Gajian</span>
        </a>

        <!-- <a href="/dashboard/setting_spreadsheet.php" class="<?= isActive('setting_spreadsheet.php') ?>">
            <span class="icon">⚙️</span>
            <span class="text">Setting Spreadsheet</span>
        </a> -->

        <!-- ============================= -->
        <!-- VALIDASI (MANAGER ONLY) -->
        <!-- ============================= -->
        <?php if ($userRole !== 'staff'): ?>
            <a href="/dashboard/validasi.php" class="<?= isActive('validasi.php') ?>">
                <span class="icon">🧾</span>
                <span class="text">Validasi</span>
            </a>
            <a href="/dashboard/regulasi.php" class="<?= isActive('regulasi.php') ?>">
                <span class="icon">📜</span>
                <span class="text">Regulasi</span>
            </a>
        <?php endif; ?>

        <?php if ($userRole !== 'staff'): ?>
            <a href="/dashboard/manage_users.php" class="<?= isActive('manage_users.php') ?>">
                <span class="icon">👥</span>
                <span>Manajemen User</span>
            </a>
        <?php endif; ?>

        <a href="/dashboard/setting_akun.php" class="<?= isActive('setting_akun.php') ?>">
            <span class="icon">⚙️</span>
            <span>Setting Akun</span>
        </a>

        <?php if (strtolower($_SESSION['user_rh']['role'] ?? '') !== 'staff'): ?>
            <a href="/dashboard/candidates.php" class="<?= isActive('candidates.php') ?>">
                <span class="icon">📋</span>
                <span>Calon Kandidat</span>
            </a>
        <?php endif; ?>

        <!-- LOGOUT -->
        <a href="/auth/logout.php"
            onclick="
                if (confirm('Yakin ingin logout?')) {
                    sessionStorage.removeItem('farmasi_activity_closed');
                    return true;
                }
                return false;
            "
            class="logout">
            <span class="icon">🚪</span>
            <span class="text">Logout</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        EMS © <?= date('Y') ?>
    </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<main class="main-content">