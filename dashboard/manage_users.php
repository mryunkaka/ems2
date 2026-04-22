<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$user = $_SESSION['user_rh'] ?? [];
$role = $user['role'] ?? '';
$effectiveUnit = ems_effective_unit($pdo, $user);

// HARD GUARD: staff dilarang
if (ems_is_staff_role($role)) {
    header('Location: setting_akun.php');
    exit;
}

$pageTitle = 'Manajemen User';
$roleOptions = ems_role_options();
$divisionOptions = ems_division_options();
$unitOptions = ems_unit_options();
$editPromotionDateConfigs = [
    'tanggal_naik_paramedic' => 'Tanggal Naik ke Paramedic',
    'tanggal_naik_co_asst' => 'Tanggal Naik ke Co. Asst',
    'tanggal_naik_dokter' => 'Tanggal Naik ke Dokter',
    'tanggal_naik_dokter_spesialis' => 'Tanggal Naik ke Dokter Spesialis',
    'tanggal_join_manager' => 'Tanggal Join Manager',
];
$editOptionalColumns = array_keys($editPromotionDateConfigs);

function manageUsersHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM user_rh LIKE ?");
    $stmt->execute([$column]);
    $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

function manageUsersEnsureUpdateHistoryTable(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS account_update_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            target_user_id INT NOT NULL,
            target_name VARCHAR(100) NOT NULL,
            editor_user_id INT DEFAULT NULL,
            editor_name VARCHAR(100) DEFAULT NULL,
            editor_role VARCHAR(100) DEFAULT NULL,
            action_type VARCHAR(50) NOT NULL DEFAULT 'edit',
            summary VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_account_update_logs_target (target_user_id),
            KEY idx_account_update_logs_created_at (created_at),
            KEY idx_account_update_logs_editor (editor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $initialized = true;
}

function formatManageUsersHistoryDate(?string $value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        return (new DateTime((string)$value))->format('d M Y H:i');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function manageUsersNormalizeDocName(?string $name): string
{
    $value = strtolower(trim((string)$name));
    $value = preg_replace('/\s+/', ' ', $value) ?: '';
    return $value;
}

function manageUsersIsProtectedUser(?string $name): bool
{
    $normalized = strtolower(trim((string)$name));
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?: '';

    return in_array($normalized, ['programmer alta', 'programmer roxwood'], true);
}

function manageUsersCanManageProtectedUser(array $sessionUser, array $targetUser): bool
{
    if (!manageUsersIsProtectedUser($targetUser['full_name'] ?? '')) {
        return true;
    }

    return (int)($sessionUser['id'] ?? 0) > 0
        && (int)($sessionUser['id'] ?? 0) === (int)($targetUser['id'] ?? 0);
}

// FLASH NOTIF EMS
$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors   = $_SESSION['flash_errors']   ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

// View mode: per_batch atau all
$viewMode = trim($_GET['view'] ?? '');
if (!in_array($viewMode, ['per_batch', 'all'], true)) {
    $viewMode = 'per_batch';
}

$hasDivisionColumn = manageUsersHasColumn($pdo, 'division');
$hasUnitCodeColumn = manageUsersHasColumn($pdo, 'unit_code');
$hasCanViewAllUnitsColumn = manageUsersHasColumn($pdo, 'can_view_all_units');
$hasCitizenIdColumn = manageUsersHasColumn($pdo, 'citizen_id');
$hasNoHpIcColumn = manageUsersHasColumn($pdo, 'no_hp_ic');
$hasJenisKelaminColumn = manageUsersHasColumn($pdo, 'jenis_kelamin');
$divisionSelect = $hasDivisionColumn ? "u.division," : "NULL AS division,";
$unitSelect = $hasUnitCodeColumn ? "u.unit_code," : "'roxwood' AS unit_code,";
$allUnitsSelect = $hasCanViewAllUnitsColumn ? "u.can_view_all_units," : "NULL AS can_view_all_units,";
$citizenIdSelect = $hasCitizenIdColumn ? "u.citizen_id," : "NULL AS citizen_id,";
$noHpIcSelect = $hasNoHpIcColumn ? "u.no_hp_ic," : "NULL AS no_hp_ic,";
$jenisKelaminSelect = $hasJenisKelaminColumn ? "u.jenis_kelamin," : "NULL AS jenis_kelamin,";
$optionalSelectParts = [];
foreach ($editOptionalColumns as $optionalColumn) {
    $optionalSelectParts[] = manageUsersHasColumn($pdo, $optionalColumn)
        ? "u.{$optionalColumn}"
        : "NULL AS {$optionalColumn}";
}
$optionalSelectSql = $optionalSelectParts ? implode(",\n        ", $optionalSelectParts) . "," : '';
$unitWhere = $hasUnitCodeColumn ? "WHERE COALESCE(u.unit_code, 'roxwood') = :unit_code" : "";
manageUsersEnsureUpdateHistoryTable($pdo);

// AMBIL SEMUA USER (SESUAI DATABASE)
$stmtUsers = $pdo->prepare("
        SELECT 
        u.id,
        u.full_name,
        u.position,
        u.role,
        {$divisionSelect}
        {$unitSelect}
        {$allUnitsSelect}
        {$citizenIdSelect}
        {$noHpIcSelect}
        {$jenisKelaminSelect}
        u.is_active,
        u.tanggal_masuk,
        {$optionalSelectSql}

        u.batch,
        u.kode_nomor_induk_rs,

        u.file_ktp,
        u.file_sim,
        u.file_kta,
        u.file_skb,
        u.sertifikat_heli,
        u.sertifikat_operasi,
        u.dokumen_lainnya,

        u.resign_reason,
        u.resigned_at,
        r.full_name AS resigned_by_name,

        u.reactivated_at,
        u.reactivated_note,
        ra.full_name AS reactivated_by_name

    FROM user_rh u
    LEFT JOIN user_rh r  ON r.id  = u.resigned_by
    LEFT JOIN user_rh ra ON ra.id = u.reactivated_by
    {$unitWhere}

    ORDER BY 
        u.is_active DESC,
        u.full_name ASC

");
$stmtUsers->execute($hasUnitCodeColumn ? [':unit_code' => $effectiveUnit] : []);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

$dynamicOtherDocFilters = [];

foreach ($users as &$userRow) {
    $userOtherDocs = ensureAcademyDocIds(parseAcademyDocs($userRow['dokumen_lainnya'] ?? ''));
    $userRow['_other_docs'] = $userOtherDocs;
    $userRow['_other_doc_names'] = [];

    foreach ($userOtherDocs as $otherDoc) {
        $docName = trim((string)($otherDoc['name'] ?? ''));
        $normalizedDocName = manageUsersNormalizeDocName($docName);
        if ($normalizedDocName === '') {
            continue;
        }

        $userRow['_other_doc_names'][] = $normalizedDocName;
        if (!isset($dynamicOtherDocFilters[$normalizedDocName])) {
            $dynamicOtherDocFilters[$normalizedDocName] = $docName;
        }
    }

    $userRow['_other_doc_names'] = array_values(array_unique($userRow['_other_doc_names']));
}
unset($userRow);

asort($dynamicOtherDocFilters, SORT_NATURAL | SORT_FLAG_CASE);

$dynamicOtherDocFilterOptions = [];
foreach ($dynamicOtherDocFilters as $normalizedDocName => $docName) {
    $filterValue = 'missing_other_' . substr(sha1($normalizedDocName), 0, 12);
    $dynamicOtherDocFilterOptions[] = [
        'value' => $filterValue,
        'label' => $docName,
        'normalized' => $normalizedDocName,
    ];
}

$historyWhere = $hasUnitCodeColumn
    ? "WHERE COALESCE(target.unit_code, 'roxwood') = :unit_code"
    : "";
$stmtHistory = $pdo->prepare("
    SELECT
        l.id,
        l.target_user_id,
        l.target_name,
        l.editor_user_id,
        l.editor_name,
        l.editor_role,
        l.action_type,
        l.summary,
        l.created_at
    FROM account_update_logs l
    LEFT JOIN user_rh target ON target.id = l.target_user_id
    {$historyWhere}
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 12
");
$stmtHistory->execute($hasUnitCodeColumn ? [':unit_code' => $effectiveUnit] : []);
$accountUpdateHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// KELOMPOKKAN USER BERDASARKAN BATCH
// ===============================
$usersByBatch = [];

function formatDurasiMedis(?string $tanggalMasuk): string
{
    if (empty($tanggalMasuk)) return '-';

    $start = new DateTime($tanggalMasuk);
    $now   = new DateTime();

    if ($start > $now) return '-';

    $diff = $start->diff($now);

    if ($diff->y > 0) {
        return $diff->y . ' tahun' . ($diff->m > 0 ? ' ' . $diff->m . ' bulan' : '');
    }

    if ($diff->m > 0) {
        return $diff->m . ' bulan';
    }

    $days = $diff->days;

    if ($days >= 7) {
        return floor($days / 7) . ' minggu';
    }

    return $days . ' hari';
}

foreach ($users as $u) {
    $batchKey = !empty($u['batch']) ? 'Batch ' . (int)$u['batch'] : 'Tanpa Batch';
    $usersByBatch[$batchKey][] = $u;
}

// Urutkan batch (Batch 1,2,3... lalu Tanpa Batch di akhir)
uksort($usersByBatch, function ($a, $b) {
    if ($a === 'Tanpa Batch') return 1;
    if ($b === 'Tanpa Batch') return -1;

    preg_match('/\d+/', $a, $ma);
    preg_match('/\d+/', $b, $mb);

    return ((int)$ma[0]) <=> ((int)$mb[0]);
});

?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<style>
    .manage-users-page {
        max-width: 92rem;
    }

    .manage-users-card {
        overflow: hidden;
    }

    .manage-users-card > .table-wrapper {
        overflow-x: auto;
    }

    .edit-user-modal {
        max-width: 980px;
    }

    .edit-user-modal .modal-content {
        display: grid;
        gap: 1rem;
    }

    .edit-user-grid {
        display: grid;
        gap: 1rem;
    }

    .edit-user-section {
        display: grid;
        gap: 0.875rem;
        padding: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 18px;
        background: rgba(248, 250, 252, 0.78);
    }

    .edit-user-section-title {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
    }

    .edit-user-help {
        margin-top: -0.375rem;
        font-size: 0.78rem;
        color: #64748b;
    }

    .edit-user-checkbox {
        display: inline-flex;
        align-items: center;
        gap: 0.625rem;
        font-size: 0.875rem;
        font-weight: 500;
        color: #334155;
    }

    .edit-user-checkbox input {
        width: 1rem;
        height: 1rem;
    }

    .edit-user-grid .row-form-2 {
        margin: 0;
    }

    .account-update-history-card {
        margin-bottom: 1rem;
    }

    .account-update-history-list {
        display: grid;
        gap: 0.75rem;
        max-height: 23rem;
        overflow-y: auto;
        padding-right: 0.35rem;
        scrollbar-gutter: stable;
    }

    .account-update-history-list::-webkit-scrollbar {
        width: 8px;
    }

    .account-update-history-list::-webkit-scrollbar-track {
        background: rgba(226, 232, 240, 0.7);
        border-radius: 999px;
    }

    .account-update-history-list::-webkit-scrollbar-thumb {
        background: rgba(100, 116, 139, 0.55);
        border-radius: 999px;
    }

    .account-update-history-list::-webkit-scrollbar-thumb:hover {
        background: rgba(71, 85, 105, 0.7);
    }

    .account-update-history-item {
        display: grid;
        gap: 0.25rem;
        padding: 0.875rem 1rem;
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 16px;
        background: rgba(248, 250, 252, 0.86);
    }

    .account-update-history-text {
        color: #0f172a;
        line-height: 1.5;
    }

    .account-update-history-meta {
        font-size: 0.78rem;
        color: #64748b;
    }

    .account-update-history-empty {
        padding: 1rem;
        border: 1px dashed rgba(148, 163, 184, 0.4);
        border-radius: 16px;
        color: #64748b;
        background: rgba(248, 250, 252, 0.55);
    }

    @media (min-width: 1024px) {
        .edit-user-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: start;
        }
    }

    /* Tabs styling like forensic_medics.php */
    .forensic-medics-tabs {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .forensic-medics-tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 2.4rem;
        padding: 0.55rem 1rem;
        border-radius: 999px;
        border: 1px solid rgba(186, 230, 253, 0.95);
        background: rgba(240, 249, 255, 0.9);
        color: #075985;
        font-weight: 700;
    }

    .forensic-medics-tab:hover {
        color: #0c4a6e;
        background: rgba(224, 242, 254, 0.98);
    }

    .forensic-medics-tab.is-active {
        border-color: #0ea5e9;
        background: linear-gradient(135deg, #0ea5e9, #0369a1);
        color: #ffffff;
    }

    /* Horizontal action buttons for all-users view */
    .manage-user-action-horizontal {
        display: flex;
        gap: 0.5rem;
        flex-wrap: nowrap;
    }

    /* Prevent text wrapping and enable horizontal scroll for table */
    .table-wrapper {
        overflow-x: auto;
        max-width: 100%;
    }

    .table-custom th,
    .table-custom td {
        white-space: nowrap;
        min-width: fit-content;
    }

    .table-custom td {
        vertical-align: middle;
    }

    /* Ensure action column has minimum width */
    .table-custom th:last-child,
    .table-custom td:last-child {
        min-width: 140px;
    }

    /* Batch column min width */
    .table-custom th:nth-child(3),
    .table-custom td:nth-child(3) {
        min-width: 80px;
    }
</style>

<section class="content">
    <div class="page page-shell-md manage-users-page">

        <h1 class="page-title">Manajemen User</h1>
        <p class="page-subtitle">Kelola akun, jabatan, role, dan PIN pengguna</p>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card account-update-history-card">
            <div class="card-header">History Update Akun User</div>
            <div class="account-update-history-list">
                <?php if (empty($accountUpdateHistory)): ?>
                    <div class="account-update-history-empty">
                        Belum ada riwayat update akun user.
                    </div>
                <?php else: ?>
                    <?php foreach ($accountUpdateHistory as $historyRow): ?>
                        <?php
                        $editorName = trim((string)($historyRow['editor_name'] ?? 'Sistem'));
                        $editorRole = trim((string)($historyRow['editor_role'] ?? ''));
                        $editorLabel = $editorRole !== ''
                            ? ems_role_label($editorRole) . ' ' . $editorName
                            : $editorName;
                        $targetName = trim((string)($historyRow['target_name'] ?? 'User'));
                        $summary = trim((string)($historyRow['summary'] ?? ''));
                        $actionType = strtolower(trim((string)($historyRow['action_type'] ?? 'edit')));
                        $actionTextMap = [
                            'edit' => 'telah melakukan perubahan pada medis',
                            'add_user' => 'telah menambahkan akun medis',
                            'delete' => 'telah menghapus akun medis',
                            'resign' => 'telah menonaktifkan akun medis',
                            'reactivate' => 'telah mengaktifkan kembali akun medis',
                        ];
                        $actionText = $actionTextMap[$actionType] ?? 'telah memperbarui akun medis';
                        ?>
                        <div class="account-update-history-item">
                            <div class="account-update-history-text">
                                <strong><?= htmlspecialchars($editorLabel) ?></strong>
                                <?= htmlspecialchars($actionText) ?>
                                <strong><?= htmlspecialchars($targetName) ?></strong>.
                                <?php if ($summary !== ''): ?>
                                    <span><?= htmlspecialchars($summary) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="account-update-history-meta">
                                <?= htmlspecialchars(formatManageUsersHistoryDate($historyRow['created_at'] ?? null)) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Statistics Summary Card -->
        <div class="card mb-4">
            <div class="card-header">Ringkasan Total</div>
            <div class="card-body">
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div class="stat-card" style="padding: 1rem; border-radius: 1rem; background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white;">
                        <div style="font-size: 0.875rem; opacity: 0.9;">Total User</div>
                        <div style="font-size: 1.75rem; font-weight: 800;"><?= count($users) ?></div>
                    </div>
                    <div class="stat-card" style="padding: 1rem; border-radius: 1rem; background: linear-gradient(135deg, #10b981, #059669); color: white;">
                        <div style="font-size: 0.875rem; opacity: 0.9;">Aktif</div>
                        <div style="font-size: 1.75rem; font-weight: 800;">
                            <?= count(array_filter($users, fn($u) => (int)($u['is_active'] ?? 0) === 1)) ?>
                        </div>
                    </div>
                    <div class="stat-card" style="padding: 1rem; border-radius: 1rem; background: linear-gradient(135deg, #f59e0b, #d97706); color: white;">
                        <div style="font-size: 0.875rem; opacity: 0.9;">Non-Aktif/Resign</div>
                        <div style="font-size: 1.75rem; font-weight: 800;">
                            <?= count(array_filter($users, fn($u) => (int)($u['is_active'] ?? 0) === 0)) ?>
                        </div>
                    </div>
                    <div class="stat-card" style="padding: 1rem; border-radius: 1rem; background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white;">
                        <div style="font-size: 0.875rem; opacity: 0.9;">Jumlah Batch</div>
                        <div style="font-size: 1.75rem; font-weight: 800;"><?= count($usersByBatch) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- View Mode Tabs -->
        <div class="card card-section mb-4">
            <div class="card-header">Tampilan Daftar</div>
            <div class="card-body">
                <div class="forensic-medics-tabs" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <a href="<?= htmlspecialchars(ems_url('/dashboard/manage_users.php?view=per_batch'), ENT_QUOTES, 'UTF-8') ?>"
                       class="forensic-medics-tab<?= $viewMode === 'per_batch' ? ' is-active' : '' ?>">
                        Per Batch
                    </a>
                    <a href="<?= htmlspecialchars(ems_url('/dashboard/manage_users.php?view=all'), ENT_QUOTES, 'UTF-8') ?>"
                       class="forensic-medics-tab<?= $viewMode === 'all' ? ' is-active' : '' ?>">
                        Semua
                    </a>
                </div>
            </div>
        </div>

        <div class="card manage-users-card">
            <div class="card-header card-toolbar">
                <span>Daftar User <?= $viewMode === 'all' ? '(Semua)' : '(Per Batch)' ?></span>
	                <div class="toolbar-group">
	                    <select id="docStatusFilter" class="toolbar-select">
	                        <option value="all" selected>Semua Dokumen</option>
	                        <option value="missing_ktp">Belum Upload KTP</option>
	                        <option value="missing_sim">Belum Upload SIM</option>
	                        <option value="missing_kta">Belum Upload KTA</option>
	                        <option value="missing_skb">Belum Upload SKB</option>
	                        <option value="missing_sertifikat_heli">Belum Upload Sertifikat Heli</option>
	                        <option value="missing_sertifikat_operasi">Belum Upload Sertifikat Operasi</option>
	                        <?php foreach ($dynamicOtherDocFilterOptions as $docFilter): ?>
	                            <option value="<?= htmlspecialchars($docFilter['value']) ?>">
	                                Belum Upload <?= htmlspecialchars($docFilter['label']) ?>
	                            </option>
	                        <?php endforeach; ?>
	                    </select>
	                    <select id="searchColumn" class="toolbar-select">
	                        <option value="all" selected>Semua Kolom</option>
	                        <option value="name">Nama</option>
	                        <option value="position">Jabatan</option>
	                        <option value="role">Role</option>
	                        <option value="division">Division</option>
	                        <option value="docs">Dokumen</option>
	                        <option value="join">Tanggal Join</option>
	                    </select>
	                    <input type="text"
	                        id="searchUser"
                        placeholder="Cari nama..."
                        class="toolbar-input">

                    <button id="btnExportText" class="btn-secondary" type="button">
                        <?= ems_icon('document-text', 'h-4 w-4') ?> Export Teks
                    </button>

                    <button id="btnClearUserFilters" class="btn-secondary" type="button">
                        <?= ems_icon('x-mark', 'h-4 w-4') ?> Clear
                    </button>

                    <button id="btnAddUser" class="btn-success">
                        <?= ems_icon('plus', 'h-4 w-4') ?> Tambah Anggota
                    </button>
                </div>
            </div>

	            <div class="table-wrapper">
                <?php if ($viewMode === 'per_batch'): ?>
                    <?php foreach ($usersByBatch as $batchName => $batchUsers): ?>
	                    <div class="card batch-card">
	                        <div class="card-header batch-card-header">
	                            <div>
	                                <?= htmlspecialchars($batchName) ?>
	                                <span class="batch-count">
	                                    (<?= count($batchUsers) ?> user)
	                                </span>
	                            </div>

	                            <?php if ($batchName === 'Tanpa Batch'): ?>
	                                <button id="btnExportTanpaBatch" class="btn-secondary button-compact" type="button">
	                                    <?= ems_icon('document-text', 'h-4 w-4') ?> Export Tanpa Batch
	                                </button>
	                            <?php endif; ?>
	                        </div>

	                        <div class="table-wrapper">
	                            <table class="table-custom user-batch-table">
	                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nama</th>
                                        <th>Jabatan</th>
                                        <th>Role</th>
                                        <th>Division</th>
                                        <th>Unit</th>
                                        <th>Tanggal Join</th>
                                        <th>Dokumen</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
	                                <tbody>
	                                    <?php foreach ($batchUsers as $i => $u): ?>
	                                        <?php
	                                        $docs = [
	                                            'KTP' => $u['file_ktp'] ?? null,
	                                            'SIM' => $u['file_sim'] ?? null,
	                                            'KTA' => $u['file_kta'] ?? null,
	                                            'SKB' => $u['file_skb'] ?? null,
	                                            'SERTIFIKAT HELI' => $u['sertifikat_heli'] ?? null,
	                                            'SERTIFIKAT OPERASI' => $u['sertifikat_operasi'] ?? null,
	                                        ];

	                                        $academyDocs = $u['_other_docs'] ?? [];
	                                        foreach ($academyDocs as $ad) {
	                                            $label = trim((string)($ad['name'] ?? 'File Lainnya'));
	                                            $docs[$label] = $ad['path'] ?? null;
	                                        }

	                                        $docSearchTokens = [];
		                                        foreach ($docs as $label => $path) {
		                                            if (empty($path)) continue;
		                                            $docSearchTokens[] = strtolower($label);
		                                            $docSearchTokens[] = strtolower(basename((string)$path));
		                                        }
			                                        $docSearch = trim(implode(' ', $docSearchTokens));

		                                        $posSearch = strtolower(trim((string)($u['position'] ?? '')));
		                                        $roleSearch = strtolower(trim((string)($u['role'] ?? '')));
		                                        $divisionSearch = strtolower(trim((string)ems_normalize_division($u['division'] ?? '')));
		                                        $unitSearch = strtolower(trim((string)ems_normalize_unit_code($u['unit_code'] ?? 'roxwood')));
		                                        $joinSearch = '';
		                                        if (!empty($u['tanggal_masuk'])) {
		                                            try {
		                                                $dtJoin = new DateTime((string)$u['tanggal_masuk']);
		                                                $joinSearch = strtolower($dtJoin->format('d M Y')) . ' ' . strtolower($dtJoin->format('Y-m-d'));
		                                            } catch (Throwable $e) {
		                                                $joinSearch = strtolower((string)$u['tanggal_masuk']);
		                                            }
		                                        }

		                                        $allSearch = trim(implode(' ', array_filter([
		                                            strtolower((string)$u['full_name']),
		                                            $posSearch,
		                                            $roleSearch,
		                                            $divisionSearch,
		                                            $unitSearch,
		                                            $joinSearch,
		                                            $docSearch,
		                                        ])));
		                                        $hasKtp = !empty($u['file_ktp']);
		                                        $hasSim = !empty($u['file_sim']);
		                                        $hasKta = !empty($u['file_kta']);
		                                        $hasSkb = !empty($u['file_skb']);
		                                        $hasSertifikatHeli = !empty($u['sertifikat_heli']);
		                                        $hasSertifikatOperasi = !empty($u['sertifikat_operasi']);
		                                        $otherDocNames = $u['_other_doc_names'] ?? [];
		                                        $hasAnyDoc = false;
		                                        foreach ($docs as $path) {
		                                            if (!empty($path)) {
		                                                $hasAnyDoc = true;
		                                                break;
		                                            }
		                                        }
		                                        $isProtectedUser = manageUsersIsProtectedUser($u['full_name'] ?? '');
                                                $canManageProtectedUser = manageUsersCanManageProtectedUser($user, $u);
		                                        ?>
			                                        <tr
			                                            data-search-name="<?= htmlspecialchars(strtolower($u['full_name'])) ?>"
			                                            data-search-position="<?= htmlspecialchars($posSearch) ?>"
			                                            data-search-role="<?= htmlspecialchars($roleSearch) ?>"
			                                            data-search-division="<?= htmlspecialchars($divisionSearch) ?>"
			                                            data-search-unit="<?= htmlspecialchars($unitSearch) ?>"
			                                            data-search-join="<?= htmlspecialchars($joinSearch) ?>"
			                                            data-search-docs="<?= htmlspecialchars($docSearch) ?>"
			                                            data-search-all="<?= htmlspecialchars($allSearch) ?>"
			                                            data-has-ktp="<?= $hasKtp ? '1' : '0' ?>"
			                                            data-has-sim="<?= $hasSim ? '1' : '0' ?>"
			                                            data-has-kta="<?= $hasKta ? '1' : '0' ?>"
			                                            data-has-skb="<?= $hasSkb ? '1' : '0' ?>"
			                                            data-has-sertifikat-heli="<?= $hasSertifikatHeli ? '1' : '0' ?>"
			                                            data-has-sertifikat-operasi="<?= $hasSertifikatOperasi ? '1' : '0' ?>"
			                                            data-other-doc-names="<?= htmlspecialchars(implode('|', $otherDocNames)) ?>">
	                                            <td><?= $i + 1 ?></td>
	                                            <td>
                                                <strong><?= htmlspecialchars($u['full_name']) ?></strong>

                                                <?php if ($isProtectedUser && !$canManageProtectedUser): ?>
                                                    <div class="status-note-muted">
                                                        Akun dilindungi: hanya pemilik akun yang bisa edit, resign, atau hapus.
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($u['reactivated_at'])): ?>
                                                    <div class="status-note-success">
                                                        Aktif kembali:
                                                        <?= (new DateTime($u['reactivated_at']))->format('d M Y') ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ((int)$u['is_active'] === 0 && !empty($u['resigned_at'])): ?>
                                                    <div class="status-note-muted">
                                                        Resign: <?= (new DateTime($u['resigned_at']))->format('d M Y') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td><?= htmlspecialchars(ems_position_label($u['position'])) ?></td>
                                            <td><?= htmlspecialchars(ems_role_label($u['role'])) ?></td>
                                            <td><?= htmlspecialchars(ems_normalize_division($u['division'] ?? '') ?: '-') ?></td>
                                            <td><?= htmlspecialchars(ems_unit_label($u['unit_code'] ?? 'roxwood')) ?></td>
                                            <td>
                                                <?php if (!empty($u['tanggal_masuk'])): ?>
                                                    <div>
                                                        <?= (new DateTime($u['tanggal_masuk']))->format('d M Y') ?>
                                                    </div>
                                                    <small class="meta-text">
                                                        <?= formatDurasiMedis($u['tanggal_masuk']) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="muted-placeholder">-</span>
                                                <?php endif; ?>
	                                            </td>
	                                            <td>
	                                                <?php
	                                                foreach ($docs as $label => $path):
	                                                    if (!empty($path)):
	                                                ?>
                                                        <a href="#"
                                                            class="doc-badge btn-preview-doc"
                                                            data-src="/<?= htmlspecialchars($path) ?>"
                                                            data-title="<?= htmlspecialchars($label) ?>"
                                                            title="Lihat <?= htmlspecialchars($label) ?>">
                                                            <?= $label ?>
                                                        </a>
                                                <?php
                                                    endif;
                                                endforeach;

	                                                if (!$hasAnyDoc):
	                                                ?>
                                                        <span class="muted-placeholder">-</span>
                                                <?php
	                                                endif;
                                                ?>
                                            </td>
                                            <td>
                                                <div class="manage-user-action-stack">
                                                    <?php if (!$isProtectedUser || $canManageProtectedUser): ?>
                                                        <button
                                                            class="btn-secondary btn-sm candidate-action-btn action-icon-btn btn-edit-user"
                                                            data-id="<?= (int)$u['id'] ?>"
                                                            data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                            data-position="<?= htmlspecialchars(ems_normalize_position($u['position']), ENT_QUOTES) ?>"
                                                            data-role="<?= strtolower(trim($u['role'])) ?>"
                                                            data-division="<?= htmlspecialchars(ems_normalize_division($u['division'] ?? ''), ENT_QUOTES) ?>"
                                                            data-unit="<?= htmlspecialchars(ems_normalize_unit_code($u['unit_code'] ?? 'roxwood'), ENT_QUOTES) ?>"
                                                            data-can-view-all-units="<?= !empty($u['can_view_all_units']) ? '1' : '0' ?>"
                                                            data-batch="<?= (int)($u['batch'] ?? 0) ?>"
                                                            data-kode="<?= htmlspecialchars($u['kode_nomor_induk_rs'] ?? '', ENT_QUOTES) ?>"
                                                            data-citizen-id="<?= htmlspecialchars((string)($u['citizen_id'] ?? ''), ENT_QUOTES) ?>"
                                                            data-no-hp-ic="<?= htmlspecialchars((string)($u['no_hp_ic'] ?? ''), ENT_QUOTES) ?>"
                                                            data-jenis-kelamin="<?= htmlspecialchars((string)($u['jenis_kelamin'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-masuk="<?= htmlspecialchars((string)($u['tanggal_masuk'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-naik-paramedic="<?= htmlspecialchars((string)($u['tanggal_naik_paramedic'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-naik-co-asst="<?= htmlspecialchars((string)($u['tanggal_naik_co_asst'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-naik-dokter="<?= htmlspecialchars((string)($u['tanggal_naik_dokter'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-naik-dokter-spesialis="<?= htmlspecialchars((string)($u['tanggal_naik_dokter_spesialis'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-join-manager="<?= htmlspecialchars((string)($u['tanggal_join_manager'] ?? ''), ENT_QUOTES) ?>"
                                                            title="Edit user"
                                                            aria-label="Edit user">
                                                            <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn-secondary btn-sm candidate-action-btn action-icon-btn" type="button" disabled title="Hanya pemilik akun yang bisa edit akun ini" aria-label="Akun dilindungi">
                                                            <?= ems_icon('shield-check', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($u['is_active']): ?>
                                                        <?php if (!$isProtectedUser || $canManageProtectedUser): ?>
                                                            <button class="btn-resign btn-sm candidate-action-btn action-icon-btn btn-resign-user"
                                                                data-id="<?= (int)$u['id'] ?>"
                                                                data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                                title="Resign user"
                                                                aria-label="Resign user">
                                                                <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                                                            </button>
                                                        <?php else: ?>
                                                            <button class="btn-secondary btn-sm candidate-action-btn action-icon-btn" type="button" disabled title="Hanya pemilik akun yang bisa resign akun ini" aria-label="Akun dilindungi">
                                                                <?= ems_icon('shield-check', 'h-4 w-4') ?>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <button class="btn-success btn-sm candidate-action-btn action-icon-btn btn-reactivate-user"
                                                            data-id="<?= (int)$u['id'] ?>"
                                                            data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                            title="Aktifkan kembali user"
                                                            aria-label="Aktifkan kembali user">
                                                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!$isProtectedUser || $canManageProtectedUser): ?>
                                                        <button class="btn-danger btn-sm candidate-action-btn action-icon-btn btn-delete-user"
                                                            data-id="<?= (int)$u['id'] ?>"
                                                            data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                            title="Hapus user"
                                                            aria-label="Hapus user">
                                                            <?= ems_icon('trash', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn-secondary btn-sm candidate-action-btn action-icon-btn" type="button" disabled title="Akun ini dilindungi dan tidak bisa dihapus" aria-label="Akun dilindungi">
                                                            <?= ems_icon('shield-check', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($viewMode === 'all'): ?>
                    <!-- All Users View (Single Table) -->
                    <div class="card batch-card">
                        <div class="card-header batch-card-header">
                            <div>
                                Semua User
                                <span class="batch-count">
                                    (<?= count($users) ?> user)
                                </span>
                            </div>
                        </div>

                        <div class="table-wrapper">
                            <table class="table-custom user-batch-table" id="allUsersTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Nama</th>
                                        <th>Batch</th>
                                        <th>Jabatan</th>
                                        <th>Role</th>
                                        <th>Division</th>
                                        <th>Unit</th>
                                        <th>Tanggal Join</th>
                                        <th>Dokumen</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $i => $u): ?>
                                        <?php
                                        $docs = [
                                            'KTP' => $u['file_ktp'] ?? null,
                                            'SIM' => $u['file_sim'] ?? null,
                                            'KTA' => $u['file_kta'] ?? null,
                                            'SKB' => $u['file_skb'] ?? null,
                                            'SERTIFIKAT HELI' => $u['sertifikat_heli'] ?? null,
                                            'SERTIFIKAT OPERASI' => $u['sertifikat_operasi'] ?? null,
                                        ];

                                        $academyDocs = $u['_other_docs'] ?? [];
                                        foreach ($academyDocs as $ad) {
                                            $label = trim((string)($ad['name'] ?? 'File Lainnya'));
                                            $docs[$label] = $ad['path'] ?? null;
                                        }

                                        $docSearchTokens = [];
                                        foreach ($docs as $label => $path) {
                                            if (empty($path)) continue;
                                            $docSearchTokens[] = strtolower($label);
                                            $docSearchTokens[] = strtolower(basename((string)$path));
                                        }
                                        $docSearch = trim(implode(' ', $docSearchTokens));

                                        $posSearch = strtolower(trim((string)($u['position'] ?? '')));
                                        $roleSearch = strtolower(trim((string)($u['role'] ?? '')));
                                        $divisionSearch = strtolower(trim((string)ems_normalize_division($u['division'] ?? '')));
                                        $unitSearch = strtolower(trim((string)ems_normalize_unit_code($u['unit_code'] ?? 'roxwood')));
                                        $batchSearch = !empty($u['batch']) ? 'batch ' . (int)$u['batch'] : 'tanpa batch';
                                        $joinSearch = '';
                                        if (!empty($u['tanggal_masuk'])) {
                                            try {
                                                $dtJoin = new DateTime((string)$u['tanggal_masuk']);
                                                $joinSearch = strtolower($dtJoin->format('d M Y')) . ' ' . strtolower($dtJoin->format('Y-m-d'));
                                            } catch (Throwable $e) {
                                                $joinSearch = strtolower((string)$u['tanggal_masuk']);
                                            }
                                        }

                                        $allSearch = trim(implode(' ', array_filter([
                                            strtolower((string)$u['full_name']),
                                            $posSearch,
                                            $roleSearch,
                                            $divisionSearch,
                                            $unitSearch,
                                            $batchSearch,
                                            $joinSearch,
                                            $docSearch,
                                        ])));
                                        $hasKtp = !empty($u['file_ktp']);
                                        $hasSim = !empty($u['file_sim']);
                                        $hasKta = !empty($u['file_kta']);
                                        $hasSkb = !empty($u['file_skb']);
                                        $hasSertifikatHeli = !empty($u['sertifikat_heli']);
                                        $hasSertifikatOperasi = !empty($u['sertifikat_operasi']);
                                        $otherDocNames = $u['_other_doc_names'] ?? [];
                                        $hasAnyDoc = false;
                                        foreach ($docs as $path) {
                                            if (!empty($path)) {
                                                $hasAnyDoc = true;
                                                break;
                                            }
                                        }
                                        $isProtectedUser = manageUsersIsProtectedUser($u['full_name'] ?? '');
                                        $canManageProtectedUser = manageUsersCanManageProtectedUser($user, $u);
                                        ?>
                                        <tr
                                            data-search-name="<?= htmlspecialchars(strtolower($u['full_name'])) ?>"
                                            data-search-position="<?= htmlspecialchars($posSearch) ?>"
                                            data-search-role="<?= htmlspecialchars($roleSearch) ?>"
                                            data-search-division="<?= htmlspecialchars($divisionSearch) ?>"
                                            data-search-unit="<?= htmlspecialchars($unitSearch) ?>"
                                            data-search-batch="<?= htmlspecialchars($batchSearch) ?>"
                                            data-search-join="<?= htmlspecialchars($joinSearch) ?>"
                                            data-search-docs="<?= htmlspecialchars($docSearch) ?>"
                                            data-search-all="<?= htmlspecialchars($allSearch) ?>"
                                            data-has-ktp="<?= $hasKtp ? '1' : '0' ?>"
                                            data-has-sim="<?= $hasSim ? '1' : '0' ?>"
                                            data-has-kta="<?= $hasKta ? '1' : '0' ?>"
                                            data-has-skb="<?= $hasSkb ? '1' : '0' ?>"
                                            data-has-sertifikat-heli="<?= $hasSertifikatHeli ? '1' : '0' ?>"
                                            data-has-sertifikat-operasi="<?= $hasSertifikatOperasi ? '1' : '0' ?>"
                                            data-other-doc-names="<?= htmlspecialchars(implode('|', $otherDocNames)) ?>">
                                            <td><?= $i + 1 ?></td>
                                            <td>
                                                <strong><?= htmlspecialchars($u['full_name']) ?></strong>

                                                <?php if ($isProtectedUser && !$canManageProtectedUser): ?>
                                                    <div class="status-note-muted">
                                                        Akun dilindungi: hanya pemilik akun yang bisa edit, resign, atau hapus.
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($u['reactivated_at'])): ?>
                                                    <div class="status-note-success">
                                                        Aktif kembali:
                                                        <?= (new DateTime($u['reactivated_at']))->format('d M Y') ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ((int)$u['is_active'] === 0 && !empty($u['resigned_at'])): ?>
                                                    <div class="status-note-muted">
                                                        Resign: <?= (new DateTime($u['resigned_at']))->format('d M Y') ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($u['batch'])): ?>
                                                    <span class="badge badge-primary">Batch <?= (int)$u['batch'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Tanpa Batch</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars(ems_position_label($u['position'])) ?></td>
                                            <td><?= htmlspecialchars(ems_role_label($u['role'])) ?></td>
                                            <td><?= htmlspecialchars(ems_normalize_division($u['division'] ?? '') ?: '-') ?></td>
                                            <td><?= htmlspecialchars(ems_unit_label($u['unit_code'] ?? 'roxwood')) ?></td>
                                            <td>
                                                <?php if (!empty($u['tanggal_masuk'])): ?>
                                                    <div>
                                                        <?= (new DateTime($u['tanggal_masuk']))->format('d M Y') ?>
                                                    </div>
                                                    <small class="meta-text">
                                                        <?= formatDurasiMedis($u['tanggal_masuk']) ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="muted-placeholder">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                foreach ($docs as $label => $path):
                                                    if (!empty($path)):
                                                ?>
                                                        <a href="#"
                                                            class="doc-badge btn-preview-doc"
                                                            data-src="/<?= htmlspecialchars($path) ?>"
                                                            data-title="<?= htmlspecialchars($label) ?>"
                                                            title="Lihat <?= htmlspecialchars($label) ?>">
                                                            <?= $label ?>
                                                        </a>
                                                <?php
                                                    endif;
                                                endforeach;

                                                if (!$hasAnyDoc):
                                                ?>
                                                        <span class="muted-placeholder">-</span>
                                                <?php
                                                endif;
                                                ?>
                                            </td>
                                            <td>
                                                <div class="manage-user-action-horizontal">
                                                    <?php if (!$isProtectedUser || $canManageProtectedUser): ?>
                                                        <button
                                                            class="btn-secondary btn-sm candidate-action-btn action-icon-btn btn-edit-user"
                                                            data-id="<?= (int)$u['id'] ?>"
                                                            data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                            data-position="<?= htmlspecialchars(ems_normalize_position($u['position']), ENT_QUOTES) ?>"
                                                            data-role="<?= strtolower(trim($u['role'])) ?>"
                                                            data-division="<?= htmlspecialchars(ems_normalize_division($u['division'] ?? ''), ENT_QUOTES) ?>"
                                                            data-unit="<?= htmlspecialchars(ems_normalize_unit_code($u['unit_code'] ?? 'roxwood'), ENT_QUOTES) ?>"
                                                            data-can-view-all-units="<?= !empty($u['can_view_all_units']) ? '1' : '0' ?>"
                                                            data-batch="<?= (int)($u['batch'] ?? 0) ?>"
                                                            data-kode="<?= htmlspecialchars($u['kode_nomor_induk_rs'] ?? '', ENT_QUOTES) ?>"
                                                            data-citizen-id="<?= htmlspecialchars((string)($u['citizen_id'] ?? ''), ENT_QUOTES) ?>"
                                                            data-no-hp-ic="<?= htmlspecialchars((string)($u['no_hp_ic'] ?? ''), ENT_QUOTES) ?>"
                                                            data-jenis-kelamin="<?= htmlspecialchars((string)($u['jenis_kelamin'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-masuk="<?= htmlspecialchars((string)($u['tanggal_masuk'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-naik-paramedic="<?= htmlspecialchars((string)($u['tanggal_naik_paramedic'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-naik-co-asst="<?= htmlspecialchars((string)($u['tanggal_naik_co_asst'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-naik-dokter="<?= htmlspecialchars((string)($u['tanggal_naik_dokter'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-naik-dokter-spesialis="<?= htmlspecialchars((string)($u['tanggal_naik_dokter_spesialis'] ?? ''), ENT_QUOTES) ?>"
                                                            data-tanggal-join-manager="<?= htmlspecialchars((string)($u['tanggal_join_manager'] ?? ''), ENT_QUOTES) ?>"
                                                            title="Edit user"
                                                            aria-label="Edit user">
                                                            <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn-secondary btn-sm candidate-action-btn action-icon-btn" type="button" disabled title="Hanya pemilik akun yang bisa edit akun ini" aria-label="Akun dilindungi">
                                                            <?= ems_icon('shield-check', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($u['is_active']): ?>
                                                        <?php if (!$isProtectedUser || $canManageProtectedUser): ?>
                                                            <button class="btn-resign btn-sm candidate-action-btn action-icon-btn btn-resign-user"
                                                                data-id="<?= (int)$u['id'] ?>"
                                                                data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                                title="Resign user"
                                                                aria-label="Resign user">
                                                                <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                                                            </button>
                                                        <?php else: ?>
                                                            <button class="btn-secondary btn-sm candidate-action-btn action-icon-btn" type="button" disabled title="Hanya pemilik akun yang bisa resign akun ini" aria-label="Akun dilindungi">
                                                                <?= ems_icon('shield-check', 'h-4 w-4') ?>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <button class="btn-success btn-sm candidate-action-btn action-icon-btn btn-reactivate-user"
                                                            data-id="<?= (int)$u['id'] ?>"
                                                            data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                            title="Aktifkan kembali user"
                                                            aria-label="Aktifkan kembali user">
                                                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!$isProtectedUser || $canManageProtectedUser): ?>
                                                        <button class="btn-danger btn-sm candidate-action-btn action-icon-btn btn-delete-user"
                                                            data-id="<?= (int)$u['id'] ?>"
                                                            data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
                                                            title="Hapus user"
                                                            aria-label="Hapus user">
                                                            <?= ems_icon('trash', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn-secondary btn-sm candidate-action-btn action-icon-btn" type="button" disabled title="Akun ini dilindungi dan tidak bisa dihapus" aria-label="Akun dilindungi">
                                                            <?= ems_icon('shield-check', 'h-4 w-4') ?>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

</section>

<div id="resignModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Resign User</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="action" value="resign">
            <input type="hidden" name="user_id" id="resignUserId">

            <p>
                Apakah Anda yakin ingin menonaktifkan
                <strong id="resignUserName"></strong>?
            </p>

	            <label for="resignReason">Alasan Resign</label>
	            <textarea id="resignReason" name="resign_reason" autocomplete="off" required></textarea>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-nonaktif action-icon-btn" title="Nonaktifkan user" aria-label="Nonaktifkan user"><?= ems_icon('user-minus', 'h-4 w-4') ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="reactivateModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Kembali Bekerja</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="user_id" id="reactivateUserId">

            <p>
                Aktifkan kembali
                <strong id="reactivateUserName"></strong>?
            </p>

	            <label for="reactivateNote">Keterangan (opsional)</label>
	            <textarea id="reactivateNote" name="reactivate_note" autocomplete="off"
	                placeholder="Contoh: Kontrak baru / dipanggil kembali"></textarea>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success action-icon-btn" title="Aktifkan user" aria-label="Aktifkan user"><?= ems_icon('arrow-path', 'h-4 w-4') ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg edit-user-modal">
        <div class="modal-head">
            <div class="modal-title">Edit User</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
                <input type="hidden" name="user_id" id="editUserId">

                <div class="edit-user-grid">
                    <div class="edit-user-section">
                        <h3 class="edit-user-section-title">Identitas Medis</h3>

                        <div class="row-form-2">
                            <div>
                                <label for="editBatch">Batch</label>
                                <input type="number"
                                    name="batch"
                                    id="editBatch"
                                    autocomplete="off"
                                    min="1"
                                    max="26"
                                    placeholder="Contoh: 3">
                            </div>

                            <div>
                                <label for="editTanggalMasuk">Tanggal Masuk Trainee</label>
                                <input type="date" name="tanggal_masuk" id="editTanggalMasuk">
                            </div>
                        </div>

                        <div class="hidden" aria-hidden="true">
                            <label for="editKodeMedis">Kode Medis / Nomor Induk RS</label>

                            <div class="ems-kode-medis">
                                <input type="text"
                                    id="editKodeMedis"
                                    readonly>

                                <button type="button"
                                    id="btnDeleteKodeMedis"
                                    title="Hapus kode medis">
                                    <?= ems_icon('trash', 'h-4 w-4') ?>
                                </button>
                            </div>

                            <small class="danger-note-sm" id="kodeMedisWarning">
                                Menghapus kode medis akan mengizinkan sistem membuat ulang kode baru.
                            </small>
                        </div>

                        <label for="editName">Nama</label>
                        <input type="text" name="full_name" id="editName" autocomplete="username" required>

                        <?php if ($hasCitizenIdColumn): ?>
                            <label for="editCitizenId">Citizen ID</label>
                            <input type="text"
                                id="editCitizenId"
                                name="citizen_id"
                                placeholder="RH39IQLC"
                                pattern="[A-Z0-9]+"
                                title="Hanya huruf besar dan angka, tanpa spasi"
                                class="uppercase">
                            <small class="edit-user-help">Gunakan huruf besar atau kombinasi huruf besar dan angka tanpa spasi.</small>
                        <?php endif; ?>

                        <div class="row-form-2">
                            <?php if ($hasJenisKelaminColumn): ?>
                                <div>
                                    <label for="editJenisKelamin">Jenis Kelamin</label>
                                    <select name="jenis_kelamin" id="editJenisKelamin">
                                        <option value="">-- Pilih --</option>
                                        <option value="Laki-laki">Laki-laki</option>
                                        <option value="Perempuan">Perempuan</option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasNoHpIcColumn): ?>
                                <div>
                                    <label for="editNoHpIc">No HP IC</label>
                                    <input type="number"
                                        id="editNoHpIc"
                                        name="no_hp_ic"
                                        inputmode="numeric"
                                        placeholder="Contoh: 544322">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="edit-user-section">
                        <h3 class="edit-user-section-title">Akses dan Jabatan</h3>

                        <div class="row-form-2">
                            <div>
                                <label for="editPosition">Jabatan</label>
                                <select name="position" id="editPosition" autocomplete="organization-title" required>
                                    <?php foreach (ems_position_options() as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($opt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="editRole">Role</label>
                                <select name="role" id="editRole" autocomplete="off" required>
                                    <?php foreach ($roleOptions as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($opt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row-form-2">
                            <div>
                                <label for="editDivision">Division</label>
                                <select name="division" id="editDivision" autocomplete="organization" required>
                                    <?php foreach ($divisionOptions as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($opt['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if ($hasUnitCodeColumn): ?>
                                <div>
                                    <label for="editUnitCode">Unit</label>
                                    <select name="unit_code" id="editUnitCode" autocomplete="organization" required>
                                        <?php foreach ($unitOptions as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($opt['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($hasCanViewAllUnitsColumn && ems_is_director_role($role)): ?>
                            <label class="edit-user-checkbox">
                                <input type="checkbox" name="can_view_all_units" id="editCanViewAllUnits" value="1">
                                <span>Akses semua unit (khusus owner)</span>
                            </label>
                        <?php endif; ?>

                        <label for="editNewPin">PIN Baru <small>(4 digit, kosongkan jika tidak ganti)</small></label>
                        <input type="password"
                            id="editNewPin"
                            name="new_pin"
                            autocomplete="new-password"
                            inputmode="numeric"
                            pattern="[0-9]{4}"
                            maxlength="4">
                    </div>

                    <?php
                    $availablePromotionFields = array_values(array_filter(
                        $editOptionalColumns,
                        static fn($column) => manageUsersHasColumn($pdo, $column)
                    ));
                    ?>
                    <?php if (!empty($availablePromotionFields)): ?>
                        <div class="edit-user-section" style="grid-column: 1 / -1;">
                            <h3 class="edit-user-section-title">Riwayat Tanggal Kenaikan</h3>
                            <p class="edit-user-help">Tanpa upload dokumen. Isi hanya tanggal yang memang sudah aktif untuk user tersebut.</p>

                            <div class="row-form-2">
                                <?php foreach ($availablePromotionFields as $promotionField): ?>
                                    <div>
                                        <label for="edit_<?= htmlspecialchars($promotionField) ?>">
                                            <?= htmlspecialchars($editPromotionDateConfigs[$promotionField] ?? $promotionField) ?>
                                        </label>
                                        <input
                                            type="date"
                                            id="edit_<?= htmlspecialchars($promotionField) ?>"
                                            name="<?= htmlspecialchars($promotionField) ?>">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Hapus User</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="deleteUserId">

            <p class="danger-note">
                User <strong id="deleteUserName"></strong> akan dihapus permanen.
                <br>Tindakan ini <strong>tidak dapat dibatalkan</strong>.
            </p>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-danger">Hapus Permanen</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="addUserModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Tambah Anggota Baru</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="manage_users_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="action" value="add_user">

	            <label for="addFullName">Nama Lengkap</label>
	            <input type="text" id="addFullName" name="full_name" autocomplete="name" required>

		            <label for="addPosition">Jabatan</label>
		            <select id="addPosition" name="position" autocomplete="organization-title" required>
	                    <?php foreach (ems_position_options() as $opt): ?>
	                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
	                            <?= htmlspecialchars($opt['label']) ?>
	                        </option>
	                    <?php endforeach; ?>
	            </select>

	            <label for="addRole">Role</label>
	            <select id="addRole" name="role" autocomplete="off" required>
                <?php foreach ($roleOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars($opt['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

                <label for="addDivision">Division</label>
                <select id="addDivision" name="division" autocomplete="organization" required>
                    <?php foreach ($divisionOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars($opt['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($hasUnitCodeColumn): ?>
                    <label for="addUnitCode">Unit</label>
                    <select id="addUnitCode" name="unit_code" autocomplete="organization" required>
                        <?php foreach ($unitOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['value'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($opt['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ($hasCanViewAllUnitsColumn && ems_is_director_role($role)): ?>
                    <label class="inline-flex items-center gap-2 mt-2">
                        <input type="checkbox" name="can_view_all_units" id="addCanViewAllUnits" value="1">
                        <span>Akses semua unit (khusus owner)</span>
                    </label>
                <?php endif; ?>

	            <label for="addBatch">Batch <small>(opsional)</small></label>
	            <input type="number" id="addBatch" name="batch" autocomplete="off" min="1" max="26" placeholder="Contoh: 3">

            <small class="helper-note">
                PIN awal akan otomatis dibuat: <strong>0000</strong>
            </small>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const resignModal = document.getElementById('resignModal');

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-resign-user');
            if (!btn) return;

            document.getElementById('resignUserId').value = btn.dataset.id;
            document.getElementById('resignUserName').innerText = btn.dataset.name;

            resignModal.classList.remove('hidden');
            resignModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                resignModal.classList.add('hidden');
                resignModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<script>
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('editModal');
            if (modal && modal.style.display === 'flex') {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        }
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const modal = document.getElementById('editModal');
        const promotionFieldIds = [
            'tanggal_naik_paramedic',
            'tanggal_naik_co_asst',
            'tanggal_naik_dokter',
            'tanggal_naik_dokter_spesialis',
            'tanggal_join_manager'
        ];

        const roleMap = {
            'staff': 'Staff',
            'probation manager': 'Probation Manager',
            'assisten manager': 'Assisten Manager',
            'lead manager': 'Lead Manager',
            'head manager': 'Head Manager',
            'vice director': 'Vice Director',
            'director': 'Director'
        };

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-edit-user');
            if (!btn) return;

            document.getElementById('editUserId').value = btn.dataset.id;
            document.getElementById('editName').value = btn.dataset.name;
            document.getElementById('editPosition').value = btn.dataset.position;
            document.getElementById('editRole').value = roleMap[btn.dataset.role] || 'Staff';
            document.getElementById('editDivision').value = btn.dataset.division || 'Executive';
            const editUnitEl = document.getElementById('editUnitCode');
            if (editUnitEl) {
                editUnitEl.value = btn.dataset.unit || 'roxwood';
            }
            const editCanViewAllUnitsEl = document.getElementById('editCanViewAllUnits');
            if (editCanViewAllUnitsEl) {
                editCanViewAllUnitsEl.checked = btn.dataset.canViewAllUnits === '1';
            }

            const editCitizenIdEl = document.getElementById('editCitizenId');
            if (editCitizenIdEl) {
                editCitizenIdEl.value = btn.dataset.citizenId || '';
            }

            const editJenisKelaminEl = document.getElementById('editJenisKelamin');
            if (editJenisKelaminEl) {
                editJenisKelaminEl.value = btn.dataset.jenisKelamin || '';
            }

            const editNoHpIcEl = document.getElementById('editNoHpIc');
            if (editNoHpIcEl) {
                editNoHpIcEl.value = btn.dataset.noHpIc || '';
            }

            document.getElementById('editBatch').value = btn.dataset.batch || '';
            const editTanggalMasukEl = document.getElementById('editTanggalMasuk');
            if (editTanggalMasukEl) {
                editTanggalMasukEl.value = btn.dataset.tanggalMasuk || '';
            }
            document.getElementById('editKodeMedis').value = btn.dataset.kode || '';
            document.getElementById('editNewPin').value = '';

            promotionFieldIds.forEach(function(fieldName) {
                const input = document.getElementById('edit_' + fieldName);
                if (!input) return;

                const dataKey = fieldName
                    .replace(/_([a-z])/g, function(_, chr) {
                        return chr.toUpperCase();
                    });
                input.value = btn.dataset[dataKey] || '';
            });

            document.getElementById('kodeMedisWarning').style.display =
                btn.dataset.kode ? 'block' : 'none';

            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        // close modal
        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<script>
    window.manageUsersOtherDocFilterMap = <?= json_encode(array_column($dynamicOtherDocFilterOptions, 'normalized', 'value'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function closeModal() {
        const modal = document.getElementById('editModal');
        if (!modal) return;
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

	    document.addEventListener('DOMContentLoaded', function() {
	        // ===============================
	        // FITUR PENCARIAN USER - VANILLA JS (NO DATATABLES API)
	        // ===============================
	        const searchInput = document.getElementById('searchUser');
	        const searchColumn = document.getElementById('searchColumn');
	        const docStatusFilter = document.getElementById('docStatusFilter');
	        const clearFilterButton = document.getElementById('btnClearUserFilters');
	        const otherDocFilterMap = window.manageUsersOtherDocFilterMap || {};

	        function updateSearchPlaceholder() {
	            if (!searchInput) return;
	            const mode = searchColumn ? searchColumn.value : 'all';
		            const map = {
		                all: 'Cari (semua kolom)...',
		                name: 'Cari nama...',
		                position: 'Cari jabatan...',
		                role: 'Cari role...',
		                division: 'Cari division...',
		                docs: 'Cari dokumen (KTP, SIM, KTA, SKB, Heli, Operasi, File Lainnya)...',
		                join: 'Cari tanggal join...'
	            };
	            searchInput.placeholder = map[mode] || 'Cari...';
	        }

	        function getRowSearchValue(row, mode) {
	            const getAttr = (attr) => (row.getAttribute(attr) || '');

	            switch (mode) {
	                case 'name':
	                    return getAttr('data-search-name');
	                case 'position':
	                    return getAttr('data-search-position');
	                case 'role':
	                    return getAttr('data-search-role');
	                case 'division':
	                    return getAttr('data-search-division');
	                case 'docs':
	                    return getAttr('data-search-docs');
	                case 'join':
	                    return getAttr('data-search-join');
	                case 'all':
	                default:
	                    return getAttr('data-search-all');
	            }
	        }

	        function applyUserFilters() {
	            const keyword = (searchInput?.value || '').toLowerCase().trim();
	            const terms = keyword.split(/\s+/).filter(Boolean);
	            const mode = searchColumn ? searchColumn.value : 'all';
	            const docFilterValue = docStatusFilter ? docStatusFilter.value : 'all';
	            const batchCards = document.querySelectorAll('.table-wrapper > .card');

	            batchCards.forEach(card => {
	                const table = card.querySelector('.user-batch-table');
	                if (!table) return;

	                const rows = table.querySelectorAll('tbody tr');
	                let visibleCount = 0;

	                rows.forEach(row => {
	                    const haystack = getRowSearchValue(row, mode);
	                    const docAttrMap = {
	                        missing_ktp: 'data-has-ktp',
	                        missing_sim: 'data-has-sim',
	                        missing_kta: 'data-has-kta',
	                        missing_skb: 'data-has-skb',
	                        missing_sertifikat_heli: 'data-has-sertifikat-heli',
	                        missing_sertifikat_operasi: 'data-has-sertifikat-operasi'
	                    };
	                    const matchesSearch = terms.length === 0 ? true : terms.every(t => haystack.includes(t));
	                    const docAttr = docAttrMap[docFilterValue] || '';
	                    const otherDocName = otherDocFilterMap[docFilterValue] || '';
	                    const otherDocNames = (row.getAttribute('data-other-doc-names') || '').split('|').filter(Boolean);
	                    const matchesDocFilter = docAttr
	                        ? row.getAttribute(docAttr) !== '1'
	                        : (otherDocName ? !otherDocNames.includes(otherDocName) : true);
	                    const isMatch = matchesSearch && matchesDocFilter;

	                    if (isMatch) {
	                        row.style.display = '';
	                        visibleCount++;
	                    } else {
	                        row.style.display = 'none';
	                    }
	                });

	                const batchCountEl = card.querySelector('.batch-count');
	                if (batchCountEl) {
	                    batchCountEl.textContent = `(${visibleCount} user)`;
	                }

	                card.style.display = visibleCount === 0 ? 'none' : '';
	            });
	        }

	        if (searchInput) {
	            updateSearchPlaceholder();
	            if (searchColumn) {
	                searchColumn.addEventListener('change', function() {
	                    updateSearchPlaceholder();
	                    applyUserFilters();
	                });
	            }

		            searchInput.addEventListener('input', applyUserFilters);
	            if (docStatusFilter) {
	                docStatusFilter.addEventListener('change', applyUserFilters);
	            }
	            if (clearFilterButton) {
	                clearFilterButton.addEventListener('click', function() {
	                    if (docStatusFilter) {
	                        docStatusFilter.value = 'all';
	                    }
	                    if (searchColumn) {
	                        searchColumn.value = 'all';
	                    }
	                    if (searchInput) {
	                        searchInput.value = '';
	                    }
	                    updateSearchPlaceholder();
	                    applyUserFilters();
	                });
	            }
	            applyUserFilters();
	        }

        // auto hide notif
        setTimeout(function() {
            document.querySelectorAll('.alert-info,.alert-error').forEach(function(el) {
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 600);
            });
        }, 5000);
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const reactivateModal = document.getElementById('reactivateModal');

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-reactivate-user');
            if (!btn) return;

            document.getElementById('reactivateUserId').value = btn.dataset.id;
            document.getElementById('reactivateUserName').innerText = btn.dataset.name;

            reactivateModal.classList.remove('hidden');
            reactivateModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                reactivateModal.classList.add('hidden');
                reactivateModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const deleteModal = document.getElementById('deleteModal');

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-delete-user');
            if (!btn) return;

            document.getElementById('deleteUserId').value = btn.dataset.id;
            document.getElementById('deleteUserName').innerText = btn.dataset.name;

            deleteModal.classList.remove('hidden');
            deleteModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                deleteModal.classList.add('hidden');
                deleteModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<script>
    document.getElementById('btnDeleteKodeMedis').addEventListener('click', function() {

        if (!confirm('Yakin ingin menghapus kode medis?')) return;

        const userId = document.getElementById('editUserId').value;

        fetch('manage_users_action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'delete_kode_medis',
                    user_id: userId
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('editKodeMedis').value = '';
                    document.getElementById('kodeMedisWarning').style.display = 'none';
                    alert('Kode medis berhasil dihapus.');
                } else {
                    alert(data.message || 'Gagal menghapus kode medis.');
                }
            })
            .catch(() => alert('Terjadi kesalahan server.'));
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('addUserModal');
        const btnOpen = document.getElementById('btnAddUser');

        if (btnOpen) {
            btnOpen.addEventListener('click', () => {
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                document.body.classList.add('modal-open');
            });
        }

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        function toRoman(num) {
            const map = [
                [1000, 'M'],
                [900, 'CM'],
                [500, 'D'],
                [400, 'CD'],
                [100, 'C'],
                [90, 'XC'],
                [50, 'L'],
                [40, 'XL'],
                [10, 'X'],
                [9, 'IX'],
                [5, 'V'],
                [4, 'IV'],
                [1, 'I']
            ];

            let n = Number(num);
            if (!Number.isFinite(n) || n <= 0) return '';
            n = Math.floor(n);

            let out = '';
            for (const [value, roman] of map) {
                while (n >= value) {
                    out += roman;
                    n -= value;
                }
            }
            return out;
        }

        function getDataTableInstance(table) {
            return null;
        }

        function collectVisibleRows(table) {
            return Array.from(table.querySelectorAll('tbody tr')).filter(function(row) {
                return window.getComputedStyle(row).display !== 'none';
            });
        }

        function withExpandedTable(table, work) {
            return work();
        }

        function downloadText(filename, content) {
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        function exportTimestamp() {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            return `${year}-${month}-${day}_${hours}-${minutes}-${seconds}`;
        }

        const otherDocFilterMap = window.manageUsersOtherDocFilterMap || {};

        document.body.addEventListener('click', function(e) {
            const exportAllBtn = e.target.closest('#btnExportText');
            if (exportAllBtn) {
                const sections = [];
                const currentDocFilter = document.getElementById('docStatusFilter')?.value || 'all';
                const exportMetaMap = {
                    all: {
                        title: 'Daftar User',
                        empty: 'Tidak ada data untuk diexport.',
                        filename: `daftar_medis_${exportTimestamp()}.txt`
                    },
                    missing_ktp: {
                        title: 'Daftar User Belum Upload KTP',
                        empty: 'Tidak ada user yang belum upload KTP untuk diexport.',
                        filename: `daftar_user_belum_upload_ktp_${exportTimestamp()}.txt`
                    },
                    missing_sim: {
                        title: 'Daftar User Belum Upload SIM',
                        empty: 'Tidak ada user yang belum upload SIM untuk diexport.',
                        filename: `daftar_user_belum_upload_sim_${exportTimestamp()}.txt`
                    },
                    missing_kta: {
                        title: 'Daftar User Belum Upload KTA',
                        empty: 'Tidak ada user yang belum upload KTA untuk diexport.',
                        filename: `daftar_user_belum_upload_kta_${exportTimestamp()}.txt`
                    },
                    missing_skb: {
                        title: 'Daftar User Belum Upload SKB',
                        empty: 'Tidak ada user yang belum upload SKB untuk diexport.',
                        filename: `daftar_user_belum_upload_skb_${exportTimestamp()}.txt`
                    },
                    missing_sertifikat_heli: {
                        title: 'Daftar User Belum Upload Sertifikat Heli',
                        empty: 'Tidak ada user yang belum upload Sertifikat Heli untuk diexport.',
                        filename: `daftar_user_belum_upload_sertifikat_heli_${exportTimestamp()}.txt`
                    },
                    missing_sertifikat_operasi: {
                        title: 'Daftar User Belum Upload Sertifikat Operasi',
                        empty: 'Tidak ada user yang belum upload Sertifikat Operasi untuk diexport.',
                        filename: `daftar_user_belum_upload_sertifikat_operasi_${exportTimestamp()}.txt`
                    }
                };
                const exportMeta = exportMetaMap[currentDocFilter] || (function() {
                    const otherDocName = otherDocFilterMap[currentDocFilter] || '';
                    if (!otherDocName) {
                        return exportMetaMap.all;
                    }

                    const safeName = otherDocName
                        .toLowerCase()
                        .replace(/[^a-z0-9]+/g, '_')
                        .replace(/^_+|_+$/g, '') || 'dokumen_lainnya';

                    return {
                        title: `Daftar User Belum Upload ${otherDocName}`,
                        empty: `Tidak ada user yang belum upload ${otherDocName} untuk diexport.`,
                        filename: `daftar_user_belum_upload_${safeName}_${exportTimestamp()}.txt`
                    };
                })();

                document.querySelectorAll('.user-batch-table').forEach(function(table) {
                    const batchCard = table.closest('.batch-card');
                    if (batchCard && window.getComputedStyle(batchCard).display === 'none') {
                        return;
                    }

                    withExpandedTable(table, function() {
                        const rows = collectVisibleRows(table);
                        if (!rows.length) {
                            return;
                        }

                        const batchCardHeader = batchCard?.querySelector('.batch-card-header')?.innerText || '';
                        const batchMatch = batchCardHeader.match(/Batch\s+(\d+)/i);
                        const sectionTitle = batchMatch ? `Batch ${batchMatch[1]}` : 'Tanpa Batch';
                        const sectionLines = [];
                        let no = 1;

                        rows.forEach(function(row) {
                            const nama = row.querySelector('td:nth-child(2) strong')?.innerText || '';
                            const noStr = String(no).padStart(2, '0');
                            sectionLines.push(`${noStr}. ${nama}`);
                            no++;
                        });

                        sections.push(`${sectionTitle}\n${sectionLines.join('\n')}`);
                    });
                });

                if (!sections.length) {
                    alert(exportMeta.empty);
                    return;
                }

                downloadText(exportMeta.filename, exportMeta.title + '\n\n' + sections.join('\n\n') + '\n');
                return;
            }

            const exportNoBatchBtn = e.target.closest('#btnExportTanpaBatch');
            if (exportNoBatchBtn) {
                const batchCard = exportNoBatchBtn.closest('.batch-card');
                const table = batchCard ? batchCard.querySelector('.user-batch-table') : null;
                if (!table) {
                    alert('Tabel Tanpa Batch tidak ditemukan.');
                    return;
                }

                const lines = withExpandedTable(table, function() {
                    const rows = collectVisibleRows(table);
                    let no = 1;

                    return rows.map(function(row) {
                        const nama = row.querySelector('td:nth-child(2) strong')?.innerText || '';
                        const noStr = String(no++).padStart(2, '0');
                        return `${noStr}. ${nama}`;
                    });
                });

                if (!lines.length) {
                    alert('Tidak ada data Tanpa Batch untuk diexport.');
                    return;
                }

                downloadText(`tanpa_batch_${exportTimestamp()}.txt`, 'Tanpa Batch\n' + lines.join('\n') + '\n');
            }
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const editCitizenIdInput = document.getElementById('editCitizenId');

        if (editCitizenIdInput) {
            editCitizenIdInput.addEventListener('input', function(e) {
                let value = e.target.value || '';
                value = value.replace(/[^A-Z0-9]/gi, '');
                e.target.value = value.toUpperCase();
            });
        }
    });
</script>

<!-- DataTables for All Users View -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && $.fn.DataTable && document.getElementById('allUsersTable')) {
        $('#allUsersTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 25,
            scrollX: true,
            autoWidth: false,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: -1 }
            ]
        });
    }
});
</script>

	<?php include __DIR__ . '/../partials/footer.php'; ?>

