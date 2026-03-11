<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$position = strtolower(trim($_SESSION['user_rh']['position'] ?? ''));
$division = ems_normalize_division($_SESSION['user_rh']['division'] ?? '');
$isTrainee = ($position === 'trainee');

require_once __DIR__ . '/../assets/design/ui/icon.php';

function isActive($page)
{
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}

function sidebarItem(string $href, string $page, string $label, string $icon): array
{
    return [
        'href' => $href,
        'page' => $page,
        'label' => $label,
        'icon' => $icon,
    ];
}

$groupedNav = [
    'Utama' => [
        sidebarItem('/dashboard/index.php', 'index.php', 'Dashboard', 'home'),
        sidebarItem('/dashboard/events.php', 'events.php', 'Event', 'ticket'),
    ],
    'Medis' => [
        sidebarItem('/dashboard/ems_services.php', 'ems_services.php', 'Layanan Medis', 'building-office-2'),
        sidebarItem('/dashboard/rekam_medis_list.php', 'rekam_medis_list.php', 'Rekam Medis', 'clipboard-document-list'),
        sidebarItem('/dashboard/operasi_plastik.php', 'operasi_plastik.php', 'Operasi Plastik', 'building-office-2'),
    ],
    'Farmasi' => [
        sidebarItem('/dashboard/rekap_farmasi.php', 'rekap_farmasi.php', 'Rekap Farmasi', 'beaker'),
        sidebarItem('/dashboard/konsumen.php', 'konsumen.php', 'Konsumen', 'user-group'),
        sidebarItem('/dashboard/ranking.php', 'ranking.php', 'Ranking', 'chart-bar'),
        sidebarItem('/dashboard/absensi_ems.php', 'absensi_ems.php', 'Jam Kerja Web', 'clock'),
    ],
    'Keuangan' => [
        sidebarItem('/dashboard/reimbursement.php', 'reimbursement.php', 'Reimbursement', 'receipt-percent'),
        sidebarItem('/dashboard/restaurant_consumption.php', 'restaurant_consumption.php', 'Konsumsi Restoran', 'cake'),
    ],
    'Administrasi' => [
        sidebarItem('/dashboard/pengajuan_jabatan.php', 'pengajuan_jabatan.php', 'Pengajuan Jabatan', 'arrow-up-tray'),
        sidebarItem('/dashboard/pengajuan_cuti_resign.php', 'pengajuan_cuti_resign.php', 'Pengajuan Cuti & Resign', 'calendar'),
    ],
    'Pengaturan' => [
        sidebarItem('/dashboard/setting_akun.php', 'setting_akun.php', 'Setting Akun', 'cog-6-tooth'),
    ],
];

if ($division !== 'General Affair') {
    $groupedNav['Keuangan'][] = sidebarItem('/dashboard/gaji.php', 'gaji.php', 'Gaji', 'banknotes');
}

if (ems_can_access_division_menu($division, 'Executive')) {
    $groupedNav['Executive'] = [
        sidebarItem('#', '', 'Executive Briefing', 'clipboard-document-list'),
        sidebarItem('#', '', 'Strategic Reports', 'chart-bar'),
        sidebarItem('#', '', 'Executive Visits', 'ticket'),
    ];
}

if (ems_can_access_division_menu($division, 'Human Resource')) {
    $groupedNav['Human Resource'] = [
        sidebarItem('/dashboard/manage_users.php', 'manage_users.php', 'Manajemen User', 'user-group'),
        sidebarItem('/dashboard/pengajuan_cuti_resign.php', 'pengajuan_cuti_resign.php', 'Pengajuan Cuti & Resign', 'calendar'),
        sidebarItem('/dashboard/tracking_cuti_resign.php', 'tracking_cuti_resign.php', 'Tracking Cuti & Resign', 'clock'),
        sidebarItem('/dashboard/history_cuti_resign.php', 'history_cuti_resign.php', 'History Cuti & Resign', 'clipboard-document-list'),
        sidebarItem('/dashboard/validasi.php', 'validasi.php', 'Validasi', 'receipt-percent'),
        sidebarItem('/dashboard/candidates.php', 'candidates.php', 'Calon Kandidat', 'clipboard-document-list'),
    ];
}

if (ems_can_access_division_menu($division, 'Disciplinary Committee')) {
    $groupedNav['Disciplinary Committee'] = [
        sidebarItem('/dashboard/disciplinary_indications.php', 'disciplinary_indications.php', 'Point Pelanggaran', 'clipboard-document-list'),
        sidebarItem('/dashboard/disciplinary_warning_letters.php', 'disciplinary_warning_letters.php', 'Surat Peringatan', 'exclamation-triangle'),
        sidebarItem('/dashboard/disciplinary_cases.php', 'disciplinary_cases.php', 'Disciplinary Cases', 'document-text'),
    ];
}

if (ems_can_access_division_menu($division, 'General Affair')) {
    $groupedNav['General Affair'] = [
        sidebarItem('/dashboard/sertifikat_heli.php', 'sertifikat_heli.php', 'Sertifikat Heli Medis', 'document-text'),
        sidebarItem('/dashboard/event_manage.php', 'event_manage.php', 'Manajemen Event', 'wrench'),
        sidebarItem('/dashboard/restaurant_settings.php', 'restaurant_settings.php', 'Manajemen Konsumsi', 'cake'),
        sidebarItem('/dashboard/gaji.php', 'gaji.php', 'Gaji', 'banknotes'),
        sidebarItem('/dashboard/general_affair_visits.php', 'general_affair_visits.php', 'General Affair Visits', 'ticket'),
    ];
}

if (ems_can_access_division_menu($division, 'Specialist Medical Authority')) {
    $groupedNav['Specialist Medical Authority'] = [
        sidebarItem('/dashboard/specialist_training_recap.php', 'specialist_training_recap.php', 'Rekap Pelatihan Medis', 'clipboard-document-list'),
        sidebarItem('/dashboard/specialist_promotion_assessment.php', 'specialist_promotion_assessment.php', 'Penilaian Layak Naik Jabatan', 'check-circle'),
        sidebarItem('/dashboard/persyaratan_jabatan.php', 'persyaratan_jabatan.php', 'Syarat Jabatan', 'wrench'),
        sidebarItem('/dashboard/review_pengajuan_jabatan.php', 'review_pengajuan_jabatan.php', 'Review Jabatan', 'check-circle'),
        sidebarItem('/dashboard/specialist_authorizations.php', 'specialist_authorizations.php', 'Otorisasi Medis Spesialis', 'check'),
    ];
}

if (ems_can_access_division_menu($division, 'Forensic')) {
    $groupedNav['Forensic'] = [
        sidebarItem('#', '', 'Data Pasien Private', 'lock-closed'),
        sidebarItem('#', '', 'Hasil Visum', 'document-text'),
        sidebarItem('#', '', 'Arsip Forensic', 'inbox'),
    ];
}

if (ems_can_access_division_menu($division, 'Secretary')) {
    $groupedNav['Secretary'] = [
        sidebarItem('/dashboard/surat_menyurat.php', 'surat_menyurat.php', 'Surat & Notulen', 'document-text'),
        sidebarItem('#', '', 'Agenda Kunjungan Divisi', 'calendar-days'),
        sidebarItem('#', '', 'Koordinasi Internal Divisi', 'user-group'),
        sidebarItem('#', '', 'Rekap Surat Rahasia', 'inbox'),
    ];
}

if ($isTrainee) {
    $groupedNav['Pengaturan'][] = sidebarItem('#', '', 'Info Trainee', 'information-circle');
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
