<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$position = strtolower(trim($_SESSION['user_rh']['position'] ?? ''));
$isTrainee = ($position === 'trainee');

require_once __DIR__ . '/../assets/design/ui/icon.php';

function isActive($page)
{
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}

$navItems = [
    ['href' => '/dashboard/index.php', 'page' => 'index.php', 'label' => 'Dashboard', 'icon' => 'home'],
    ['href' => '/dashboard/events.php', 'page' => 'events.php', 'label' => 'Event', 'icon' => 'ticket'],
];

if ($userRole !== 'staff') {
    $navItems[] = ['href' => '/dashboard/event_manage.php', 'page' => 'event_manage.php', 'label' => 'Manajemen Event', 'icon' => 'wrench'];
}

$navItems[] = ['href' => '/dashboard/ems_services.php', 'page' => 'ems_services.php', 'label' => 'Layanan Medis', 'icon' => 'building-office-2'];

$navItems[] = ['href' => '/dashboard/rekam_medis_list.php', 'page' => 'rekam_medis_list.php', 'label' => 'Rekam Medis', 'icon' => 'clipboard-document-list'];

$navItems[] = ['href' => '/dashboard/rekap_farmasi.php', 'page' => 'rekap_farmasi.php', 'label' => 'Rekap Farmasi', 'icon' => 'beaker'];

$navItems = array_merge($navItems, [
    ['href' => '/dashboard/reimbursement.php', 'page' => 'reimbursement.php', 'label' => 'Reimbursement', 'icon' => 'receipt-percent'],
    ['href' => '/dashboard/restaurant_consumption.php', 'page' => 'restaurant_consumption.php', 'label' => 'Konsumsi Restoran', 'icon' => 'cake'],
    ['href' => '/dashboard/operasi_plastik.php', 'page' => 'operasi_plastik.php', 'label' => 'Operasi Plastik', 'icon' => 'building-office-2'],
    ['href' => '/dashboard/konsumen.php', 'page' => 'konsumen.php', 'label' => 'Konsumen', 'icon' => 'user-group'],
    ['href' => '/dashboard/ranking.php', 'page' => 'ranking.php', 'label' => 'Ranking', 'icon' => 'chart-bar'],
    ['href' => '/dashboard/absensi_ems.php', 'page' => 'absensi_ems.php', 'label' => 'Jam Kerja Web', 'icon' => 'clock'],
    ['href' => '/dashboard/gaji.php', 'page' => 'gaji.php', 'label' => 'Gaji', 'icon' => 'banknotes'],
    ['href' => '/dashboard/pengajuan_jabatan.php', 'page' => 'pengajuan_jabatan.php', 'label' => 'Pengajuan Jabatan', 'icon' => 'arrow-up-tray'],
    ['href' => '/dashboard/pengajuan_cuti_resign.php', 'page' => 'pengajuan_cuti_resign.php', 'label' => 'Pengajuan Cuti & Resign', 'icon' => 'calendar'],
]);

if ($userRole !== 'staff') {
    $navItems[] = ['href' => '/dashboard/validasi.php', 'page' => 'validasi.php', 'label' => 'Validasi', 'icon' => 'receipt-percent'];
    $navItems[] = ['href' => '/dashboard/regulasi_medis.php', 'page' => 'regulasi_medis.php', 'label' => 'Regulasi Medis', 'icon' => 'document-text'];
    $navItems[] = ['href' => '/dashboard/regulasi_farmasi.php', 'page' => 'regulasi_farmasi.php', 'label' => 'Regulasi Paket Farmasi', 'icon' => 'beaker'];
    $navItems[] = ['href' => '/dashboard/persyaratan_jabatan.php', 'page' => 'persyaratan_jabatan.php', 'label' => 'Syarat Jabatan', 'icon' => 'wrench'];
    $navItems[] = ['href' => '/dashboard/review_pengajuan_jabatan.php', 'page' => 'review_pengajuan_jabatan.php', 'label' => 'Review Jabatan', 'icon' => 'check-circle'];
    $navItems[] = ['href' => '/dashboard/manage_users.php', 'page' => 'manage_users.php', 'label' => 'Manajemen User', 'icon' => 'user-group'];
}

if ($userRole !== 'staff') {
    $navItems[] = ['href' => '/dashboard/surat_menyurat.php', 'page' => 'surat_menyurat.php', 'label' => 'Surat & Notulen', 'icon' => 'document-text'];
    // Menu Monitoring Cuti & Resign (hanya untuk Manager+)
    $navItems[] = ['href' => '/dashboard/tracking_cuti_resign.php', 'page' => 'tracking_cuti_resign.php', 'label' => 'Monitoring Cuti & Resign', 'icon' => 'chart-bar'];
}

$navItems[] = ['href' => '/dashboard/setting_akun.php', 'page' => 'setting_akun.php', 'label' => 'Setting Akun', 'icon' => 'cog-6-tooth'];

if ($userRole !== 'staff') {
    $navItems[] = ['href' => '/dashboard/candidates.php', 'page' => 'candidates.php', 'label' => 'Calon Kandidat', 'icon' => 'clipboard-document-list'];
}

// Trainee: hide all menu Farmasi (UI only).
if ($isTrainee) {
    $hiddenPages = [
        'rekap_farmasi.php',
        'regulasi_farmasi.php',
        'konsumen.php',
        'ranking.php',
        'absensi_ems.php',
        'gaji.php',
    ];
    $navItems = array_values(array_filter($navItems, function ($it) use ($hiddenPages) {
        return !in_array($it['page'] ?? '', $hiddenPages, true);
    }));
}

// Re-group for sidebar rendering (UI-only; href/page remain the contract).
$groupedNav = [
    'Utama' => [],
    'Medis' => [],
    'Farmasi' => [],
    'Keuangan' => [],
    'Administrasi' => [],
    'Pengaturan' => [],
];

foreach ($navItems as $it) {
    switch ($it['page']) {
        case 'index.php':
        case 'events.php':
        case 'event_manage.php':
            $groupedNav['Utama'][] = $it;
            break;
        case 'ems_services.php':
        case 'operasi_plastik.php':
        case 'rekam_medis_list.php':
        case 'regulasi_medis.php':
            $groupedNav['Medis'][] = $it;
            break;
        case 'rekap_farmasi.php':
        case 'regulasi_farmasi.php':
        case 'konsumen.php':
        case 'ranking.php':
        case 'absensi_ems.php':
        case 'gaji.php':
            $groupedNav['Farmasi'][] = $it;
            break;
        case 'reimbursement.php':
        case 'restaurant_consumption.php':
            $groupedNav['Keuangan'][] = $it;
            break;
        case 'validasi.php':
        case 'manage_users.php':
        case 'candidates.php':
        case 'pengajuan_jabatan.php':
        case 'persyaratan_jabatan.php':
        case 'review_pengajuan_jabatan.php':
        case 'surat_menyurat.php':
        case 'pengajuan_cuti_resign.php':
        case 'tracking_cuti_resign.php':
            $groupedNav['Administrasi'][] = $it;
            break;
        case 'setting_akun.php':
        default:
            $groupedNav['Pengaturan'][] = $it;
            break;
    }
}
?>

<aside id="sidebar" class="sidebar">
    <div class="sidebar-header">
        <div class="brand">
            <div class="avatar-logo" style="background: <?= htmlspecialchars($avatarColor, ENT_QUOTES, 'UTF-8') ?>;">
                <?= htmlspecialchars($avatarInitials, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="brand-text">
                <strong><?= htmlspecialchars($medicName) ?></strong>
                <span><?= htmlspecialchars($medicJabatan) ?></span>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <?php foreach ($groupedNav as $groupTitle => $items): ?>
            <?php if (empty($items)) continue; ?>
            <div class="sidebar-group-title"><?= htmlspecialchars($groupTitle) ?></div>
            <?php foreach ($items as $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= isActive($item['page']) ?>">
                    <span class="icon"><?= ems_icon($item['icon'], 'h-5 w-5') ?></span>
                    <span class="text"><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <a href="/auth/logout.php"
            onclick="
                if (confirm('Yakin ingin keluar?')) {
                    sessionStorage.removeItem('farmasi_activity_closed');
                    return true;
                }
                return false;
            "
            class="logout">
            <span class="icon"><?= ems_icon('arrow-right-on-rectangle', 'h-5 w-5') ?></span>
            <span class="text">Keluar</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        EMS &copy; <?= date('Y') ?>
    </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="document.body.classList.remove('sidebar-open');"></div>
<main class="main-content">
