<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$position = strtolower(trim($_SESSION['user_rh']['position'] ?? ''));
$userFullName = trim((string) ($_SESSION['user_rh']['full_name'] ?? $_SESSION['user_rh']['name'] ?? ''));
$normalizedUserFullName = strtolower($userFullName);
$division = ems_normalize_division($_SESSION['user_rh']['division'] ?? '');
$isTrainee = ($position === 'trainee');
$currentUnit = isset($pdo) ? ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []) : ems_normalize_unit_code($_SESSION['user_rh']['unit_code'] ?? 'roxwood');
$userUnit = isset($pdo) ? ems_current_user_unit($pdo, $_SESSION['user_rh'] ?? []) : ems_normalize_unit_code($_SESSION['user_rh']['unit_code'] ?? 'roxwood');
$canViewAllUnits = isset($pdo) ? ems_user_can_view_all_units($pdo, $_SESSION['user_rh'] ?? []) : !empty($_SESSION['user_rh']['can_view_all_units']);
$isMedicalPosition = ems_is_medical_position($_SESSION['user_rh']['position'] ?? '');
$isMedicalDivision = $division === 'Medis';
$isAltaUnit = $userUnit === 'alta';
$currentHospitalName = ems_unit_hospital_name($currentUnit);

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
        sidebarItem('/dashboard/medical_roster.php', 'medical_roster.php', 'Daftar Medis Roxwood', 'user-group'),
        sidebarItem('/dashboard/events.php', 'events.php', 'Event', 'ticket'),
        sidebarItem('/dashboard/struktur_organisasi.php', 'struktur_organisasi.php', 'Struktur Organisasi', 'building-office-2'),
    ],
    'Medis' => [
        sidebarItem('/dashboard/ems_services.php', 'ems_services.php', 'Layanan Medis', 'building-office-2'),
        sidebarItem('/dashboard/rekam_medis_list.php', 'rekam_medis_list.php', 'Rekam Medis', 'clipboard-document-list'),
        sidebarItem('/dashboard/operasi_plastik.php', 'operasi_plastik.php', 'Operasi Plastik', 'building-office-2'),
        sidebarItem('/dashboard/emt_doj.php', 'emt_doj.php', 'EMT DOJ', 'identification'),
        sidebarItem('/dashboard/sertifikat_heli_pendaftaran.php', 'sertifikat_heli_pendaftaran.php', 'Sertifikat Heli', 'document-text'),
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
        sidebarItem('/dashboard/general_affair_kerjasama_input.php', 'general_affair_kerjasama_input.php', 'Input Kerja Sama', 'building-office'),
    ],
    'Administrasi' => [
        sidebarItem('/dashboard/pengajuan_jabatan.php', 'pengajuan_jabatan.php', 'Pengajuan Jabatan', 'arrow-up-tray'),
        sidebarItem('/dashboard/pengajuan_cuti_resign.php', 'pengajuan_cuti_resign.php', 'Pengajuan Cuti & Resign', 'calendar'),
    ],
    'Pengaturan' => [
        sidebarItem('/dashboard/setting_akun.php', 'setting_akun.php', 'Setting Akun', 'cog-6-tooth'),
    ],
];

if (!ems_is_staff_role($userRole)) {
    $groupedNav['Utama'][] = sidebarItem('/dashboard/input_dokumen_medis.php', 'input_dokumen_medis.php', 'Input Dokumen Medis', 'arrow-up-tray');
    $groupedNav['Utama'][] = sidebarItem('/dashboard/farmasi_billing_audit.php', 'farmasi_billing_audit.php', 'Audit Billing Farmasi', 'exclamation-triangle');
    $groupedNav['Utama'][] = sidebarItem('/dashboard/training_group_generator.php', 'training_group_generator.php', 'Generator Kelompok', 'sparkles');
}

$groupedNav['Utama'][] = sidebarItem('/dashboard/user_availability.php', 'user_availability.php', 'Availability User', 'signal');

if ($division !== 'General Affair') {
    $groupedNav['Keuangan'][] = sidebarItem('/dashboard/gaji.php', 'gaji.php', 'Gaji', 'banknotes');
}

if (ems_is_manager_plus_role($_SESSION['user_rh']['role'] ?? '')) {
    $groupedNav['Keuangan'][] = sidebarItem('/dashboard/regulasi_medis.php', 'regulasi_medis.php', 'Regulasi Medis', 'document-text');
    $groupedNav['Keuangan'][] = sidebarItem('/dashboard/regulasi_farmasi.php', 'regulasi_farmasi.php', 'Regulasi Farmasi', 'beaker');
}

if ($division !== 'Medis') {
    $groupedNav['Utama'][] = sidebarItem('/dashboard/surat_monitoring.php', 'surat_monitoring.php', 'Monitoring Surat', 'inbox');
}

if (ems_can_access_division_menu($division, 'Human Resource')) {
    $groupedNav['Human Resource'] = [
        sidebarItem('/dashboard/manage_users.php', 'manage_users.php', 'Manajemen User', 'user-group'),
        sidebarItem('/dashboard/pengajuan_cuti_resign.php', 'pengajuan_cuti_resign.php', 'Pengajuan Cuti & Resign', 'calendar'),
        sidebarItem('/dashboard/tracking_cuti_resign.php', 'tracking_cuti_resign.php', 'Tracking Cuti & Resign', 'clock'),
        sidebarItem('/dashboard/history_cuti_resign.php', 'history_cuti_resign.php', 'History Cuti & Resign', 'clipboard-document-list'),
        sidebarItem('/dashboard/validasi.php', 'validasi.php', 'Validasi', 'receipt-percent'),
    ];
}

if (!$isMedicalDivision && ems_is_manager_plus_role($_SESSION['user_rh']['role'] ?? '')) {
    $groupedNav['Recruitment'] = [
        sidebarItem('/dashboard/candidates.php', 'candidates.php', 'Calon Kandidat', 'clipboard-document-list'),
        sidebarItem('/dashboard/assistant_manager_candidates.php', 'assistant_manager_candidates.php', 'Calon Asisten Manager', 'briefcase'),
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
        sidebarItem('/dashboard/blacklist_names.php', 'blacklist_names.php', 'Blacklist Nama', 'no-symbol'),
            ];

    if (!ems_is_staff_role($userRole)) {
        $groupedNav['General Affair'][] = sidebarItem('/dashboard/manage_users.php', 'manage_users.php', 'Manajemen User', 'user-group');
    }

    if ($division === 'General Affair' && ems_is_manager_plus_role($_SESSION['user_rh']['role'] ?? '')) {
        $groupedNav['General Affair'][] = sidebarItem('/dashboard/general_affair_kerjasama.php', 'general_affair_kerjasama.php', 'Setting Kerjasama', 'clipboard-document-list');
        $groupedNav['General Affair'][] = sidebarItem('/dashboard/general_affair_kerjasama_history.php', 'general_affair_kerjasama_history.php', 'History Paket Gratis', 'archive-box');
    }
}

if (ems_can_access_division_menu($division, 'Specialist Medical Authority')) {
    $groupedNav['Specialist Medical Authority'] = [
        sidebarItem('/dashboard/specialist_medics.php', 'specialist_medics.php', 'List Medis', 'table-cells'),
        sidebarItem('/dashboard/specialist_operation_recap.php', 'specialist_operation_recap.php', 'Rekap Operasi Medis', 'clipboard-document-list'),
        sidebarItem('/dashboard/specialist_training_recap.php', 'specialist_training_recap.php', 'Rekap Pelatihan Medis', 'clipboard-document-list'),
        sidebarItem('/dashboard/specialist_promotion_assessment.php', 'specialist_promotion_assessment.php', 'Penilaian Layak Naik Jabatan', 'check-circle'),
        sidebarItem('/dashboard/persyaratan_jabatan.php', 'persyaratan_jabatan.php', 'Syarat Jabatan', 'wrench'),
        sidebarItem('/dashboard/review_pengajuan_jabatan.php', 'review_pengajuan_jabatan.php', 'Review Jabatan', 'check-circle'),
        sidebarItem('/dashboard/specialist_authorizations.php', 'specialist_authorizations.php', 'Otorisasi Medis Spesialis', 'check'),
    ];
}

if (ems_can_access_division_menu($division, 'Forensic')) {
    $groupedNav['Forensic'] = [
        sidebarItem('/dashboard/forensic_medics.php', 'forensic_medics.php', 'List Medis', 'table-cells'),
        sidebarItem('/dashboard/forensic_medical_records_list.php', 'forensic_medical_records_list.php', 'Rekam Medis Private', 'clipboard-document-list'),
        sidebarItem('/dashboard/forensic_private_patients.php', 'forensic_private_patients.php', 'Data Pasien Private', 'lock-closed'),
        sidebarItem('/dashboard/forensic_visum_results.php', 'forensic_visum_results.php', 'Hasil Visum', 'document-text'),
        sidebarItem('/dashboard/forensic_archive.php', 'forensic_archive.php', 'Arsip Forensic', 'inbox'),
    ];
}

if (ems_can_access_division_menu($division, 'Secretary')) {
    $groupedNav['Secretary'] = [
        sidebarItem('/dashboard/surat_menyurat.php', 'surat_menyurat.php', 'Surat & Notulen', 'document-text'),
        sidebarItem('/dashboard/secretary_file_registry.php', 'secretary_file_registry.php', 'Data File Divisi', 'archive-box'),
        sidebarItem('/dashboard/secretary_visit_agenda.php', 'secretary_visit_agenda.php', 'Agenda Kunjungan Divisi', 'calendar-days'),
        sidebarItem('/dashboard/secretary_internal_coordination.php', 'secretary_internal_coordination.php', 'Koordinasi Internal Divisi', 'user-group'),
        sidebarItem('/dashboard/secretary_confidential_letters.php', 'secretary_confidential_letters.php', 'Rekap Surat Rahasia', 'inbox'),
    ];
}

if ($isTrainee) {
    $groupedNav['Pengaturan'][] = sidebarItem('#', '', 'Info Trainee', 'exclamation-triangle');
}

if ($isAltaUnit && !$canViewAllUnits) {
    if (ems_is_staff_role($userRole) && $isMedicalDivision) {
        $groupedNav = [
            'Utama' => [
                sidebarItem('/dashboard/index.php', 'index.php', 'Dashboard', 'home'),
                sidebarItem('/dashboard/user_availability.php', 'user_availability.php', 'Availability User', 'signal'),
            ],
            'Medis' => [
                sidebarItem('/dashboard/emt_doj.php', 'emt_doj.php', 'EMT DOJ', 'identification'),
                sidebarItem('/dashboard/sertifikat_heli_pendaftaran.php', 'sertifikat_heli_pendaftaran.php', 'Sertifikat Heli', 'document-text'),
            ],
            'Farmasi' => [
                sidebarItem('/dashboard/rekap_farmasi.php', 'rekap_farmasi.php', 'Rekap Farmasi', 'beaker'),
                sidebarItem('/dashboard/konsumen.php', 'konsumen.php', 'Konsumen', 'user-group'),
                sidebarItem('/dashboard/ranking.php', 'ranking.php', 'Ranking', 'chart-bar'),
            ],
            'Pengaturan' => [
                sidebarItem('/dashboard/setting_akun.php', 'setting_akun.php', 'Setting Akun', 'cog-6-tooth'),
            ],
            'Administrasi' => [
                sidebarItem('/dashboard/general_affair_kerjasama_input.php', 'general_affair_kerjasama_input.php', 'Input Kerja Sama', 'building-office'),
            ],
        ];
    } else {
        $groupedNav = [
            'Utama' => [
                sidebarItem('/dashboard/index.php', 'index.php', 'Dashboard', 'home'),
                sidebarItem('/dashboard/farmasi_billing_audit.php', 'farmasi_billing_audit.php', 'Audit Billing Farmasi', 'exclamation-triangle'),
                sidebarItem('/dashboard/training_group_generator.php', 'training_group_generator.php', 'Generator Kelompok', 'sparkles'),
                sidebarItem('/dashboard/user_availability.php', 'user_availability.php', 'Availability User', 'signal'),
            ],
            'Medis' => [
                sidebarItem('/dashboard/emt_doj.php', 'emt_doj.php', 'EMT DOJ', 'identification'),
                sidebarItem('/dashboard/sertifikat_heli_pendaftaran.php', 'sertifikat_heli_pendaftaran.php', 'Sertifikat Heli', 'document-text'),
            ],
            'Farmasi' => [
                sidebarItem('/dashboard/rekap_farmasi.php', 'rekap_farmasi.php', 'Rekap Farmasi', 'beaker'),
                sidebarItem('/dashboard/konsumen.php', 'konsumen.php', 'Konsumen', 'user-group'),
                sidebarItem('/dashboard/ranking.php', 'ranking.php', 'Ranking', 'chart-bar'),
            ],
            'Keuangan' => [
                sidebarItem('/dashboard/gaji.php', 'gaji.php', 'Gaji', 'banknotes'),
                sidebarItem('/dashboard/regulasi_medis.php', 'regulasi_medis.php', 'Regulasi Medis', 'document-text'),
                sidebarItem('/dashboard/regulasi_farmasi.php', 'regulasi_farmasi.php', 'Regulasi Farmasi', 'beaker'),
                sidebarItem('/dashboard/regulasi_roxwood_hospital.php', 'regulasi_roxwood_hospital.php', 'Regulasi Roxwood Hospital', 'document-text'),
                sidebarItem('/dashboard/restaurant_consumption.php', 'restaurant_consumption.php', 'Konsumsi Restoran', 'cake'),
                sidebarItem('/dashboard/general_affair_kerjasama_input.php', 'general_affair_kerjasama_input.php', 'Input Kerja Sama', 'building-office'),
                sidebarItem('/dashboard/validasi.php', 'validasi.php', 'Validasi', 'check-circle'),
                sidebarItem('/dashboard/blacklist_names.php', 'blacklist_names.php', 'Blacklist Nama', 'no-symbol'),
                sidebarItem('/dashboard/manage_users.php', 'manage_users.php', 'Manajemen User', 'user-group'),
            ],
            'Pengaturan' => [
                sidebarItem('/dashboard/setting_akun.php', 'setting_akun.php', 'Setting Akun', 'cog-6-tooth'),
            ],
        ];
    }
}

if ($isAltaUnit && !$canViewAllUnits && ems_is_manager_plus_role($_SESSION['user_rh']['role'] ?? '')) {
    if (!isset($groupedNav['Keuangan']) || !is_array($groupedNav['Keuangan'])) {
        $groupedNav['Keuangan'] = [];
    }

    $requiredFinanceMenus = [
        sidebarItem('/dashboard/regulasi_medis.php', 'regulasi_medis.php', 'Regulasi Medis', 'document-text'),
        sidebarItem('/dashboard/regulasi_farmasi.php', 'regulasi_farmasi.php', 'Regulasi Farmasi', 'beaker'),
    ];

    foreach ($requiredFinanceMenus as $requiredItem) {
        $exists = false;
        foreach ($groupedNav['Keuangan'] as $item) {
            if (($item['page'] ?? '') === ($requiredItem['page'] ?? '')) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $groupedNav['Keuangan'][] = $requiredItem;
        }
    }
}

if (!isset($groupedNav['Keuangan']) || !is_array($groupedNav['Keuangan'])) {
    $groupedNav['Keuangan'] = [];
}

$hasRegulasiHospitalMenu = false;
foreach ($groupedNav['Keuangan'] as $item) {
    if (($item['page'] ?? '') === 'regulasi_roxwood_hospital.php') {
        $hasRegulasiHospitalMenu = true;
        break;
    }
}

if (!$hasRegulasiHospitalMenu) {
    $groupedNav['Keuangan'][] = sidebarItem('/dashboard/regulasi_roxwood_hospital.php', 'regulasi_roxwood_hospital.php', 'Regulasi Roxwood Hospital', 'document-text');
}

$canAccessOcrApiStatus = in_array($normalizedUserFullName, [
    'programmer roxwood',
    'programmer alta',
], true);

if (ems_current_user_is_programmer_roxwood()) {
    if (!isset($groupedNav['Pengaturan']) || !is_array($groupedNav['Pengaturan'])) {
        $groupedNav['Pengaturan'] = [];
    }

    $groupedNav['Pengaturan'][] = sidebarItem('/dashboard/ai_settings.php', 'ai_settings.php', 'Setting AI', 'cog-6-tooth');
}

if ($canAccessOcrApiStatus) {
    if (!isset($groupedNav['Pengaturan']) || !is_array($groupedNav['Pengaturan'])) {
        $groupedNav['Pengaturan'] = [];
    }

    $hasOcrApiStatusMenu = false;
    foreach ($groupedNav as $items) {
        foreach ($items as $item) {
            if (($item['page'] ?? '') === 'ocr_api_status.php') {
                $hasOcrApiStatusMenu = true;
                break 2;
            }
        }
    }

    if (!$hasOcrApiStatusMenu) {
        $groupedNav['Pengaturan'][] = sidebarItem('/dashboard/ocr_api_status.php', 'ocr_api_status.php', 'Status OCR API', 'arrow-path');
    }
}

$hasSettingAkunMenu = false;
foreach ($groupedNav as $items) {
    foreach ($items as $item) {
        if (($item['page'] ?? '') === 'setting_akun.php') {
            $hasSettingAkunMenu = true;
            break 2;
        }
    }
}

if (!$hasSettingAkunMenu) {
    if (!isset($groupedNav['Pengaturan']) || !is_array($groupedNav['Pengaturan'])) {
        $groupedNav['Pengaturan'] = [];
    }

    $groupedNav['Pengaturan'][] = sidebarItem('/dashboard/setting_akun.php', 'setting_akun.php', 'Setting Akun', 'cog-6-tooth');
}

$hasCooperationInputMenu = false;
foreach ($groupedNav as $items) {
    foreach ($items as $item) {
        if (($item['page'] ?? '') === 'general_affair_kerjasama_input.php') {
            $hasCooperationInputMenu = true;
            break 2;
        }
    }
}

if (!$hasCooperationInputMenu) {
    if (!isset($groupedNav['Keuangan']) || !is_array($groupedNav['Keuangan'])) {
        $groupedNav['Keuangan'] = [];
    }

    $inserted = false;
    foreach ($groupedNav['Keuangan'] as $index => $item) {
        if (($item['page'] ?? '') === 'restaurant_consumption.php') {
            array_splice(
                $groupedNav['Keuangan'],
                $index + 1,
                0,
                [sidebarItem('/dashboard/general_affair_kerjasama_input.php', 'general_affair_kerjasama_input.php', 'Input Kerja Sama', 'building-office')]
            );
            $inserted = true;
            break;
        }
    }

    if (!$inserted) {
        $groupedNav['Keuangan'][] = sidebarItem('/dashboard/general_affair_kerjasama_input.php', 'general_affair_kerjasama_input.php', 'Input Kerja Sama', 'building-office');
    }
}

function sidebarBuildUnitSwitchUrl(string $targetUnit): string
{
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/dashboard/index.php');
    $parts = parse_url($requestUri);
    $path = $parts['path'] ?? '/dashboard/index.php';
    $query = [];

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $query['unit'] = $targetUnit;

    return $path . '?' . http_build_query($query);
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
                <span><?= htmlspecialchars($medicJabatan) ?> • <?= htmlspecialchars($currentHospitalName) ?></span>
            </div>
        </div>
        <?php if ($canViewAllUnits): ?>
            <div class="mt-3 flex gap-2">
                <a href="<?= htmlspecialchars(sidebarBuildUnitSwitchUrl('roxwood')) ?>" class="btn-secondary button-compact<?= $currentUnit === 'roxwood' ? ' active' : '' ?>">
                    Roxwood
                </a>
                <a href="<?= htmlspecialchars(sidebarBuildUnitSwitchUrl('alta')) ?>" class="btn-secondary button-compact<?= $currentUnit === 'alta' ? ' active' : '' ?>">
                    Alta
                </a>
            </div>
        <?php endif; ?>
    </div>

    <nav class="sidebar-menu">
        <div class="sidebar-search-wrap">
            <label for="sidebarMenuSearch" class="sidebar-search-label">Cari Menu</label>
            <div class="sidebar-search-input-wrap">
                <span class="sidebar-search-icon"><?= ems_icon('magnifying-glass', 'h-4 w-4') ?></span>
                <input type="text" id="sidebarMenuSearch" class="sidebar-search-input" placeholder="Cari menu sidebar...">
            </div>
        </div>
        <div class="sidebar-menu-scroll">
        <?php foreach ($groupedNav as $groupTitle => $items): ?>
            <?php
            $visibleItems = array_values(array_filter($items, static function (array $item): bool {
                return ($item['page'] ?? '') !== 'setting_akun.php';
            }));
            ?>
            <?php if (empty($visibleItems)) continue; ?>
            <div class="sidebar-group-block" data-sidebar-group>
            <div class="sidebar-group-title" data-sidebar-group-title><?= htmlspecialchars($groupTitle) ?></div>
            <?php foreach ($visibleItems as $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= isActive($item['page']) ?>" data-sidebar-link data-sidebar-label="<?= htmlspecialchars(strtolower($item['label'] . ' ' . $groupTitle), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="icon"><?= ems_icon($item['icon'], 'h-5 w-5') ?></span>
                    <span class="text"><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </nav>

    <div class="sidebar-footer">
        <span class="sidebar-footer-copy">EMS &copy; <?= date('Y') ?></span>
        <div class="sidebar-footer-actions">
            <a href="/dashboard/setting_akun.php" class="sidebar-footer-action <?= isActive('setting_akun.php') ?>" aria-label="Setting Akun" title="Setting Akun">
                <?= ems_icon('cog-6-tooth', 'h-4 w-4') ?>
            </a>
            <a href="/auth/logout.php"
                onclick="
                    if (confirm('Yakin ingin keluar?')) {
                        sessionStorage.removeItem('farmasi_activity_closed');
                        return true;
                    }
                    return false;
                "
                class="sidebar-footer-action"
                aria-label="Keluar"
                title="Keluar">
                <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
            </a>
        </div>
    </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="document.body.classList.remove('sidebar-open');"></div>
<style>
    .sidebar {
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .sidebar-menu {
        flex: 1 1 auto;
        min-height: 0;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .sidebar-menu-scroll {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        padding: 0 0 20px;
    }

    .sidebar-search-wrap {
        flex: 0 0 auto;
        padding: 0 3px 10px;
        margin-bottom: 4px;
        position: sticky;
        top: 0;
        z-index: 2;
        background: transparent;
        backdrop-filter: none;
    }

    .sidebar-search-label {
        display: block;
        margin-bottom: 8px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: rgba(186, 230, 253, 0.82);
    }

    .sidebar-search-input-wrap {
        position: relative;
    }

    .sidebar-search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: rgba(148, 163, 184, 0.9);
        pointer-events: none;
    }

    .sidebar-search-input {
        width: 100%;
        height: 44px;
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.08);
        color: #f8fafc;
        padding: 0 14px 0 40px;
        outline: none;
        transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
    }

    .sidebar-search-input::placeholder {
        color: rgba(191, 219, 254, 0.72);
    }

    .sidebar-search-input:focus {
        border-color: rgba(125, 211, 252, 0.45);
        background: rgba(255, 255, 255, 0.12);
        box-shadow: 0 0 0 3px rgba(125, 211, 252, 0.12);
    }

    .sidebar-group-block.is-hidden,
    .sidebar-menu a.is-hidden {
        display: none;
    }

    .sidebar-footer {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .sidebar-footer-copy {
        min-width: 0;
    }

    .sidebar-footer-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }

    .sidebar-footer-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 999px;
        border: 1px solid rgba(255, 255, 255, 0.14);
        background: rgba(255, 255, 255, 0.06);
        color: #e2e8f0;
        transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    }

    .sidebar-footer-action:hover {
        background: rgba(255, 255, 255, 0.12);
        color: #ffffff;
        border-color: rgba(255, 255, 255, 0.22);
    }

    .sidebar-footer-action.active {
        background: rgba(255, 255, 255, 0.16);
        color: #ffffff;
    }

    @media (max-width: 767px) {
        .sidebar {
            height: calc(100vh - 4rem);
            max-height: calc(100dvh - 4rem);
            padding-bottom: env(safe-area-inset-bottom, 0);
        }

        .sidebar-menu {
            min-height: 0;
        }

        .sidebar-menu-scroll {
            padding-bottom: 12px;
        }
    }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('sidebarMenuSearch');
    if (!searchInput) {
        return;
    }

    const groupBlocks = Array.from(document.querySelectorAll('[data-sidebar-group]'));

    function applySidebarFilter() {
        const keyword = String(searchInput.value || '').trim().toLowerCase();

        groupBlocks.forEach(function (group) {
            const links = Array.from(group.querySelectorAll('[data-sidebar-link]'));
            let visibleCount = 0;

            links.forEach(function (link) {
                const label = String(link.getAttribute('data-sidebar-label') || '').toLowerCase();
                const isVisible = keyword === '' || label.indexOf(keyword) !== -1;
                link.classList.toggle('is-hidden', !isVisible);
                if (isVisible) {
                    visibleCount += 1;
                }
            });

            group.classList.toggle('is-hidden', visibleCount === 0);
        });
    }

    searchInput.addEventListener('input', applySidebarFilter);
    applySidebarFilter();
});
</script>
<main class="main-content">
