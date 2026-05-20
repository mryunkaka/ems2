<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/general_affair_cooperation_helper.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';


if (!isset($_GET['range'])) {
    $_GET['range'] = 'week4';
}

require_once __DIR__ . '/../config/date_range.php';

$pageTitle = 'Input Kerja Sama';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);
$userRole = strtolower(trim((string)($_SESSION['user_rh']['role'] ?? '')));
$userDivision = ems_normalize_division((string)($_SESSION['user_rh']['division'] ?? ''));

function gaInputMarker(): string
{
    return 'ga_cooperation_input';
}

function gaInputTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $cache[$table] = (bool)$stmt->fetchColumn();

    return $cache[$table];
}

function gaInputStatusMeta(string $status): array
{
    return match ($status) {
        'review' => ['label' => 'VERIFIKASI', 'class' => 'badge-counter'],
        'active' => ['label' => 'AKTIF', 'class' => 'badge-success'],
        'paid' => ['label' => 'PAID', 'class' => 'badge-success'],
        'archived' => ['label' => 'ARSIP', 'class' => 'badge-muted'],
        default => ['label' => 'PENDING', 'class' => 'badge-warning'],
    };
}

function gaInputColumnsReady(PDO $pdo): array
{
    return [
        'document_time' => ems_column_exists($pdo, 'secretary_file_records', 'document_time'),
        'paid_by' => ems_column_exists($pdo, 'secretary_file_records', 'paid_by'),
        'paid_at' => ems_column_exists($pdo, 'secretary_file_records', 'paid_at'),
    ];
}

function gaInputCanMarkPaid(string $userRole): bool
{
    return in_array($userRole, ['executive vice director', 'vice director', 'director'], true);
}

function gaInputCanDelete(string $userDivision): bool
{
    return in_array($userDivision, ['General Affair', 'Executive'], true);
}

function gaInputGroupAttachments(array $rows): array
{
    $grouped = [];
    foreach ($rows as $row) {
        $recordId = (int)($row['record_id'] ?? 0);
        if ($recordId <= 0) {
            continue;
        }

        $grouped[$recordId][] = $row;
    }

    return $grouped;
}

function gaInputFindAttachment(array $attachments, string $label): ?array
{
    foreach ($attachments as $attachment) {
        if (strcasecmp(trim((string)($attachment['file_name'] ?? '')), $label) === 0) {
            return $attachment;
        }
    }

    return null;
}

function gaInputParseKeywordMeta(?string $keywords): array
{
    $meta = [
        'cooperation_id' => 0,
    ];

    $parts = array_filter(array_map('trim', explode(',', (string)$keywords)));
    foreach ($parts as $part) {
        if (str_starts_with($part, 'cooperation_id:')) {
            $meta['cooperation_id'] = (int)trim(substr($part, 15));
        }
    }

    return $meta;
}

function gaInputExcerpt(?string $text, int $limit = 80): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '-';
    }

    $normalized = preg_replace('/\s+/u', ' ', $text) ?: $text;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $limit - 3)) . '...';
    }

    if (strlen($normalized) <= $limit) {
        return $normalized;
    }

    return rtrim(substr($normalized, 0, $limit - 3)) . '...';
}

function gaInputTransactionQuotaLabel(string $claimScope, int $memberCount): string
{
    if ($claimScope === 'per_institution') {
        return '1x / periode';
    }

    return max(0, $memberCount) . 'x / periode';
}

function gaInputCountActiveMembers(PDO $pdo, int $cooperationId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM general_affair_cooperation_members
        WHERE cooperation_id = ?
          AND is_active = 1
    ");
    $stmt->execute([$cooperationId]);
    return (int)$stmt->fetchColumn();
}

function gaInputCompactMedicineLines(array $effectiveQtys): array
{
    $lines = [];
    $labels = [
        'bandage_qty' => 'Bandage',
        'ifaks_qty' => 'Ifaks',
        'painkiller_qty' => 'Painkiller',
    ];

    foreach ($labels as $field => $label) {
        $qty = max(0, (int)($effectiveQtys[$field] ?? 0));
        if ($qty <= 0) {
            continue;
        }

        $lines[] = $label . ' = ' . number_format($qty, 0, ',', '.');
    }

    return $lines;
}

$hasMainTable = gaInputTableExists($pdo, 'secretary_file_records');
$hasAttachmentTable = gaInputTableExists($pdo, 'secretary_file_record_attachments');
$columnReady = gaInputColumnsReady($pdo);
$canMarkPaid = gaInputCanMarkPaid($userRole);
$canDelete = gaInputCanDelete($userDivision);
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$pricePerPcs = gaCooperationRegulationPricePerPcs($pdo, $effectiveUnit);
$rows = [];
$attachmentsMap = [];
$cooperationSettings = [];
$cooperationSettingsMap = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'review' => 0,
    'active' => 0,
];

if (gaCooperationTablesReady($pdo)) {
    $stmtCoop = $pdo->prepare("
        SELECT
            gc.id,
            gc.institution_name,
            gc.period_type,
            gc.notes,
            gc.is_active
        FROM general_affair_cooperations gc
        WHERE gc.unit_code = :unit_code
        ORDER BY gc.is_active DESC, gc.institution_name ASC, gc.id DESC
    ");
    $stmtCoop->execute([':unit_code' => $effectiveUnit]);
    $cooperationRows = $stmtCoop->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($cooperationRows as $cooperationRow) {
        $cooperationId = (int)($cooperationRow['id'] ?? 0);
        if ($cooperationId <= 0) {
            continue;
        }

        $notesMeta = gaCooperationParseNotesMeta((string)($cooperationRow['notes'] ?? ''));
        $medicineQtys = gaCooperationNormalizeMedicineQtys($notesMeta);

        if (!gaCooperationHasConfiguredMedicines($medicineQtys)) {
            $stmtPkg = $pdo->prepare("
                SELECT p.bandage_qty, p.ifaks_qty, p.painkiller_qty
                FROM general_affair_cooperation_packages gcp
                INNER JOIN packages p ON p.id = gcp.package_id
                WHERE gcp.cooperation_id = :cooperation_id
                  AND COALESCE(p.unit_code, 'roxwood') = :unit_code
            ");
            $stmtPkg->execute([
                ':cooperation_id' => $cooperationId,
                ':unit_code' => $effectiveUnit,
            ]);
            $medicineQtys = gaCooperationResolveMedicineQtys($notesMeta, $stmtPkg->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        $memberCount = gaInputCountActiveMembers($pdo, $cooperationId);
        $calculationMode = (string)($notesMeta['calculation_mode'] ?? 'manual');
        $summary = gaCooperationSummarizeMedicines($medicineQtys, $pricePerPcs, $calculationMode, $memberCount);

        $entry = [
            'id' => $cooperationId,
            'institution_name' => (string)($cooperationRow['institution_name'] ?? ''),
            'period_type' => (string)($cooperationRow['period_type'] ?? ''),
            'period_label' => gaCooperationPeriodLabel((string)($cooperationRow['period_type'] ?? '')),
            'notes' => (string)($notesMeta['notes'] ?? ''),
            'is_active' => (int)($cooperationRow['is_active'] ?? 0) === 1,
            'claim_scope' => (string)($notesMeta['claim_scope'] ?? 'per_person'),
            'claim_scope_label' => gaCooperationClaimScopeLabel((string)($notesMeta['claim_scope'] ?? 'per_person')),
            'calculation_mode' => $calculationMode,
            'calculation_mode_label' => gaCooperationCalculationModeLabel($calculationMode),
            'member_count' => $memberCount,
            'quota_label' => gaInputTransactionQuotaLabel((string)($notesMeta['claim_scope'] ?? 'per_person'), $memberCount),
            'medicine_qtys' => $medicineQtys,
            'medicine_summary_lines' => gaInputCompactMedicineLines((array)($summary['qtys'] ?? [])),
            'medicine_total_price' => $summary['total_price'],
        ];

        $cooperationSettings[] = $entry;
        $cooperationSettingsMap[$cooperationId] = $entry;
    }
}

if ($hasMainTable && $hasAttachmentTable) {
    $sql = "
        SELECT
            sfr.*,
            creator.full_name AS created_by_name,
            updater.full_name AS updated_by_name" . ($columnReady['paid_by'] ? ",
            paid_user.full_name AS paid_by_name" : "") . "
        FROM secretary_file_records sfr
        LEFT JOIN user_rh creator ON creator.id = sfr.created_by
        LEFT JOIN user_rh updater ON updater.id = sfr.updated_by" . ($columnReady['paid_by'] ? "
        LEFT JOIN user_rh paid_user ON paid_user.id = sfr.paid_by" : "") . "
        WHERE sfr.file_category = 'cooperation'
          AND COALESCE(sfr.keywords, '') LIKE :marker
          AND sfr.document_date BETWEEN :start_date AND :end_date
        ORDER BY sfr.document_date DESC, sfr.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':marker' => '%' . gaInputMarker() . '%',
        ':start_date' => (string)$rangeStart,
        ':end_date' => (string)$rangeEnd,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($rows) {
        $recordIds = array_values(array_filter(array_map(static fn($row) => (int)($row['id'] ?? 0), $rows)));
        $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
        $stmtAttachment = $pdo->prepare("
            SELECT record_id, file_path, file_name, sort_order
            FROM secretary_file_record_attachments
            WHERE record_id IN ($placeholders)
            ORDER BY record_id ASC, sort_order ASC, id ASC
        ");
        $stmtAttachment->execute($recordIds);
        $attachmentsMap = gaInputGroupAttachments($stmtAttachment->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    $stats['total'] = count($rows);
    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? 'draft');
        if ($status === 'review') {
            $stats['review']++;
        } elseif ($status === 'active') {
            $stats['active']++;
        } elseif ($status === 'paid') {
            $stats['active']++;
        } elseif ($status === 'draft') {
            $stats['pending']++;
        }
    }
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page mx-auto max-w-[1400px]">
        <div class="card card-section mb-4">
            <div class="card-header card-header-between">
                <div>
                    <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="page-subtitle"><?= htmlspecialchars($rangeLabel ?? '-', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="inline-actions">
                    <?php
                    $exportParams = [];
                    if (!empty($_GET['range'])) {
                        $exportParams['range'] = $_GET['range'];
                    }
                    if (!empty($_GET['from'])) {
                        $exportParams['from'] = $_GET['from'];
                    }
                    if (!empty($_GET['to'])) {
                        $exportParams['to'] = $_GET['to'];
                    }
                    $exportQuery = http_build_query($exportParams);
                    $exportUrl = ems_url('/dashboard/general_affair_kerjasama_input_export.php' . ($exportQuery ? '?' . $exportQuery : ''));
                    ?>
                    <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
                        <?= ems_icon('document-arrow-down', 'h-4 w-4') ?>
                        <span>Export Excel</span>
                    </a>
                    <button type="button" id="btnAddCooperationInput" class="btn-success">
                        <?= ems_icon('plus', 'h-4 w-4') ?>
                        <span>Input Kerja Sama</span>
                    </button>
                </div>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if (!$hasMainTable || !$hasAttachmentTable): ?>
            <div class="alert alert-error">
                Modul input kerja sama belum siap. Jalankan SQL <code>docs/sql/16_2026-04-01_secretary_file_registry.sql</code> terlebih dahulu.
            </div>
        <?php endif; ?>

        <div class="stats-grid mb-4">
            <div class="card stats-card">
                <div class="card-body stats-body-center">
                    <small class="stats-label">Total Input</small>
                    <div class="stats-value-blue"><?= number_format($stats['total'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="card stats-card">
                <div class="card-body stats-body-center">
                    <small class="stats-label">Pending</small>
                    <div class="stats-value-amber"><?= number_format($stats['pending'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="card stats-card">
                <div class="card-body stats-body-center">
                    <small class="stats-label">Verifikasi</small>
                    <div class="stats-value-teal"><?= number_format($stats['review'], 0, ',', '.') ?></div>
                </div>
            </div>
            <div class="card stats-card">
                <div class="card-body stats-body-center">
                    <small class="stats-label">Aktif</small>
                    <div class="stats-value-green"><?= number_format($stats['active'], 0, ',', '.') ?></div>
                </div>
            </div>
        </div>

        <div class="card card-section mb-4">
            <div class="card-header">Filter Rentang Tanggal</div>
            <div class="card-body">
                <form method="get" class="filter-bar">
                    <div class="filter-group">
                        <label>Rentang</label>
                        <select name="range" id="rangeSelect" class="form-control">
                            <option value="week1" <?= ($_GET['range'] ?? 'week4') === 'week1' ? 'selected' : '' ?>>3 Minggu Lalu</option>
                            <option value="week2" <?= ($_GET['range'] ?? 'week4') === 'week2' ? 'selected' : '' ?>>2 Minggu Lalu</option>
                            <option value="week3" <?= ($_GET['range'] ?? 'week4') === 'week3' ? 'selected' : '' ?>>Minggu Lalu</option>
                            <option value="week4" <?= ($_GET['range'] ?? 'week4') === 'week4' ? 'selected' : '' ?>>Minggu Ini</option>
                            <option value="month1" <?= ($_GET['range'] ?? 'week4') === 'month1' ? 'selected' : '' ?>>Bulan Ini</option>
                            <option value="custom" <?= ($_GET['range'] ?? 'week4') === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Awal</label>
                        <input type="date" name="from" value="<?= htmlspecialchars((string)($_GET['from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Akhir</label>
                        <input type="date" name="to" value="<?= htmlspecialchars((string)($_GET['to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                    </div>
                    <div class="filter-group filter-action-end">
                        <button type="submit" class="btn btn-primary">Terapkan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Daftar Input Kerja Sama</div>
            <div class="table-wrapper">
                <table id="gaCooperationInputTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Kode</th>
                            <th>Tanggal & Jam</th>
                            <th>Instansi</th>
                            <th>Setting Instansi</th>
                            <th>Kuota Periode</th>
                            <th>Input Oleh</th>
                            <th>Obat Regulasi</th>
                            <th>KTP</th>
                            <th>KTA</th>
                            <th>Status</th>
                            <th>Catatan</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php
                            $statusMeta = gaInputStatusMeta((string)($row['status'] ?? 'draft'));
                            $attachments = $attachmentsMap[(int)$row['id']] ?? [];
                            $ktpAttachment = gaInputFindAttachment($attachments, 'KTP');
                            $ktaAttachment = gaInputFindAttachment($attachments, 'KTA');
                            $docDate = (string)($row['document_date'] ?? '');
                            $docTime = $columnReady['document_time'] ? (string)($row['document_time'] ?? '00:00:00') : '00:00:00';
                            $docTs = strtotime(trim($docDate . ' ' . $docTime));
                            $keywordMeta = gaInputParseKeywordMeta((string)($row['keywords'] ?? ''));
                            $selectedSetting = $cooperationSettingsMap[(int)$keywordMeta['cooperation_id']] ?? null;
                            $packageSummaryLines = $selectedSetting['medicine_summary_lines'] ?? [];
                            $packageTotalPrice = (int)($selectedSetting['medicine_total_price'] ?? 0);
                            $fullDescription = trim((string)($row['description'] ?? ''));
                            $descriptionExcerpt = gaInputExcerpt($fullDescription);
                            ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><small><?= htmlspecialchars((string)($row['file_code'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small></td>
                                <td data-order="<?= (int)$docTs ?>" style="white-space: nowrap;">
                                    <?php if ($docTs > 0): ?>
                                        <div><strong><?= htmlspecialchars(date('d M Y', $docTs), ENT_QUOTES, 'UTF-8') ?></strong></div>
                                        <small class="meta-text-xs"><?= htmlspecialchars($columnReady['document_time'] ? date('H:i', $docTs) : '-', ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php else: ?>
                                        <span class="muted-placeholder">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars((string)($row['counterparty_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td>
                                    <?php if ($selectedSetting): ?>
                                        <div><strong><?= htmlspecialchars((string)$selectedSetting['institution_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                                        <small class="meta-text-xs"><?= htmlspecialchars((string)$selectedSetting['period_label'], ENT_QUOTES, 'UTF-8') ?></small>
                                        <small class="meta-text-xs"><?= htmlspecialchars((string)($selectedSetting['calculation_mode_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php else: ?>
                                        <span class="muted-placeholder">Tidak terhubung</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($selectedSetting): ?>
                                        <div><strong><?= htmlspecialchars((string)($selectedSetting['claim_scope_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                                        <small class="meta-text-xs"><?= htmlspecialchars((string)($selectedSetting['quota_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php else: ?>
                                        <span class="muted-placeholder">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars((string)($row['created_by_name'] ?? $row['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <small class="meta-text-xs"><?= htmlspecialchars((string)($row['title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></small>
                                </td>
                                <td>
                                    <?php if ($packageSummaryLines): ?>
                                        <div class="meta-text-xs whitespace-pre-line"><?= htmlspecialchars(implode("\n", $packageSummaryLines), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="meta-text-xs mt-1"><strong>Total:</strong> $<?= number_format($packageTotalPrice, 0, ',', '.') ?></div>
                                    <?php else: ?>
                                        <span class="muted-placeholder">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ktpAttachment): ?>
                                         <a href="#"
                                           class="doc-badge btn-preview-doc"
                                           data-src="<?= htmlspecialchars(ems_secure_file_url((string)$ktpAttachment['file_path']), ENT_QUOTES, 'UTF-8') ?>"
                                           data-title="KTP - <?= htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= ems_icon('document-text', 'h-4 w-4') ?>
                                            <span>Lihat</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="muted-placeholder">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ktaAttachment): ?>
                                         <a href="#"
                                           class="doc-badge btn-preview-doc"
                                           data-src="<?= htmlspecialchars(ems_secure_file_url((string)$ktaAttachment['file_path']), ENT_QUOTES, 'UTF-8') ?>"
                                           data-title="KTA - <?= htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= ems_icon('document-text', 'h-4 w-4') ?>
                                            <span>Lihat</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="muted-placeholder">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (($row['status'] ?? '') === 'paid' && $columnReady['paid_by'] && !empty($row['paid_by_name'])): ?>
                                        <div class="meta-text-xs mt-1">
                                            Paid by: <strong><?= htmlspecialchars((string)$row['paid_by_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <?php if ($columnReady['paid_at'] && !empty($row['paid_at'])): ?>
                                                <br><?= htmlspecialchars(date('d M Y H:i', strtotime((string)$row['paid_at'])), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="ga-note-cell">
                                        <div class="meta-text-xs ga-note-excerpt"><?= htmlspecialchars($descriptionExcerpt, ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if ($fullDescription !== ''): ?>
                                            <button type="button"
                                                    class="btn-secondary btn-sm ga-note-view-btn"
                                                    data-note="<?= htmlspecialchars($fullDescription, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-title="<?= htmlspecialchars((string)($row['counterparty_name'] ?? 'Catatan Kerja Sama'), ENT_QUOTES, 'UTF-8') ?>">
                                                <?= ems_icon('eye', 'h-4 w-4') ?>
                                                <span>View</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-row-nowrap">
                                        <?php if (($row['status'] ?? 'draft') === 'draft'): ?>
                                            <form method="post" action="general_affair_kerjasama_input_action.php" class="inline">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="record_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="status" value="review">
                                                <input type="hidden" name="redirect_to" value="general_affair_kerjasama_input.php">
                                                <button type="submit" class="btn-secondary btn-sm action-icon-btn" title="Verifikasi">
                                                    <?= ems_icon('check-badge', 'h-4 w-4') ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canMarkPaid && $columnReady['paid_by'] && $columnReady['paid_at'] && ($row['status'] ?? '') !== 'paid' && ($row['status'] ?? '') !== 'archived'): ?>
                                            <form method="post" action="general_affair_kerjasama_input_action.php" class="inline">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="record_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="status" value="paid">
                                                <input type="hidden" name="redirect_to" value="general_affair_kerjasama_input.php">
                                                <button type="submit" class="btn-primary btn-sm action-icon-btn" title="Tandai Sudah Bayar">
                                                    <?= ems_icon('banknotes', 'h-4 w-4') ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canDelete): ?>
                                            <form method="post" action="general_affair_kerjasama_input_action.php" class="inline js-delete-form" data-confirm="Yakin ingin menghapus input kerja sama ini?">
                                                <?= csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_record">
                                                <input type="hidden" name="record_id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="redirect_to" value="general_affair_kerjasama_input.php">
                                                <button type="submit" class="btn-danger btn-sm action-icon-btn" title="Hapus">
                                                    <?= ems_icon('trash', 'h-4 w-4') ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$rows && $hasMainTable && $hasAttachmentTable): ?>
                    <div class="muted-placeholder p-4">Belum ada input kerja sama pada rentang ini.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div id="gaCooperationInputModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Input Kerja Sama</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="post"
              action="general_affair_kerjasama_input_action.php"
              enctype="multipart/form-data"
              class="form modal-form">
            <?= csrfField(); ?>
            <input type="hidden" name="action" value="create_record">
            <input type="hidden" name="redirect_to" value="general_affair_kerjasama_input.php">

            <div class="modal-content">
                <label>Setting Instansi Kerja Sama</label>
                <select name="cooperation_id" id="gaCooperationSettingSelect" required>
                    <option value="">-- Pilih Setting Instansi --</option>
                    <?php foreach ($cooperationSettings as $setting): ?>
                        <option value="<?= (int)$setting['id'] ?>"
                                data-setting='<?= htmlspecialchars((string)json_encode($setting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'
                            <?= !empty($setting['is_active']) ? '' : ' disabled' ?>>
                            <?= htmlspecialchars((string)$setting['institution_name'], ENT_QUOTES, 'UTF-8') ?>
                            <?= !empty($setting['is_active']) ? '' : ' (Nonaktif)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div id="gaCooperationSettingInfo" class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" style="display:none;">
                    <div class="font-semibold text-slate-900" id="gaSettingName">-</div>
                    <div class="meta-text-xs mt-1" id="gaSettingPeriod">-</div>
                    <div class="meta-text-xs mt-1" id="gaSettingQuota">-</div>
                    <div class="meta-text-xs mt-1 whitespace-pre-line" id="gaSettingNotes"></div>
                    <div class="mt-3">
                        <div class="font-semibold text-slate-900">Obat Gratis yang Harus Diberikan</div>
                        <div id="gaSettingPackages" class="meta-text-xs mt-2 whitespace-pre-line">-</div>
                        <div class="meta-text-xs mt-2"><strong>Total Harga Regulasi Gratis:</strong> <span id="gaSettingTotalPrice">$0</span></div>
                    </div>
                </div>

                <label>Tanggal & Jam Dokumen</label>
                <div class="delivery-grid">
                    <div class="delivery-field">
                        <small class="delivery-label">Tanggal</small>
                        <input type="date" name="document_date" required value="<?= date('Y-m-d') ?>" class="delivery-input">
                    </div>
                    <div class="delivery-field">
                        <small class="delivery-label">Jam</small>
                        <input type="time" name="document_time" required value="<?= date('H:i') ?>" class="delivery-input">
                    </div>
                </div>

                <label>Catatan</label>
                <textarea name="notes" rows="3" placeholder="Opsional"></textarea>

                <div class="doc-upload-wrapper">
                    <div class="doc-upload-header">
                        <label class="doc-label">Upload KTP</label>
                        <span class="badge-muted-mini">PNG / JPG akan dikompresi otomatis ke ±300KB</span>
                    </div>
                    <div class="doc-upload-input">
                        <label for="gaKtpFile" class="file-upload-label" data-target-input="gaKtpFile">
                            <span class="file-icon"><?= ems_icon('folder', 'h-5 w-5') ?></span>
                            <span class="file-text">
                                <strong>Pilih file</strong>
                                <small>PNG atau JPG - Otomatis dikompresi</small>
                            </span>
                        </label>
                        <input type="file" id="gaKtpFile" name="ktp_file" accept="image/png,image/jpeg" required class="hidden">
                        <div class="file-selected-name" data-for="gaKtpFile"></div>
                        <div id="gaKtpFileSizeInfo" data-for="gaKtpFile" class="file-size-info"></div>
                    </div>
                </div>

                <div class="doc-upload-wrapper">
                    <div class="doc-upload-header">
                        <label class="doc-label">Upload KTA</label>
                        <span class="badge-muted-mini">PNG / JPG akan dikompresi otomatis ke ±300KB</span>
                    </div>
                    <div class="doc-upload-input">
                        <label for="gaKtaFile" class="file-upload-label" data-target-input="gaKtaFile">
                            <span class="file-icon"><?= ems_icon('folder', 'h-5 w-5') ?></span>
                            <span class="file-text">
                                <strong>Pilih file</strong>
                                <small>PNG atau JPG - Otomatis dikompresi</small>
                            </span>
                        </label>
                        <input type="file" id="gaKtaFile" name="kta_file" accept="image/png,image/jpeg" required class="hidden">
                        <div class="file-selected-name" data-for="gaKtaFile"></div>
                        <div id="gaKtaFileSizeInfo" data-for="gaKtaFile" class="file-size-info"></div>
                    </div>
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

<div id="gaNoteViewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="min-w-0">
                <div class="modal-title">Catatan Lengkap</div>
                <div id="gaNoteViewModalMeta" class="meta-text-xs mt-1 text-slate-500"></div>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <div class="modal-content">
            <div id="gaNoteViewModalBody" class="ga-note-modal-body"></div>
        </div>

        <div class="modal-foot">
            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rangeSelect = document.getElementById('rangeSelect');
    const customFields = document.querySelectorAll('.filter-custom');
    const modal = document.getElementById('gaCooperationInputModal');
    const openButton = document.getElementById('btnAddCooperationInput');
    const settingSelect = document.getElementById('gaCooperationSettingSelect');
    const settingInfo = document.getElementById('gaCooperationSettingInfo');
    const settingName = document.getElementById('gaSettingName');
    const settingPeriod = document.getElementById('gaSettingPeriod');
    const settingQuota = document.getElementById('gaSettingQuota');
    const settingNotes = document.getElementById('gaSettingNotes');
    const settingPackages = document.getElementById('gaSettingPackages');
    const settingTotalPrice = document.getElementById('gaSettingTotalPrice');
    const noteViewModal = document.getElementById('gaNoteViewModal');
    const noteViewModalMeta = document.getElementById('gaNoteViewModalMeta');
    const noteViewModalBody = document.getElementById('gaNoteViewModalBody');

    function toggleCustom() {
        customFields.forEach(function(el) {
            el.style.display = rangeSelect && rangeSelect.value === 'custom' ? 'block' : 'none';
        });
    }

    if (rangeSelect) {
        rangeSelect.addEventListener('change', toggleCustom);
        toggleCustom();
    }

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#gaCooperationInputTable').DataTable({
            pageLength: 10,
            order: [[2, 'desc']],
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });
    }

    function formatCurrency(value) {
        return '$' + Number(value || 0).toLocaleString('en-US');
    }

    function renderSettingInfo() {
        if (!settingSelect || !settingInfo) return;

        const option = settingSelect.options[settingSelect.selectedIndex];
        const raw = option && option.dataset ? option.dataset.setting : '';
        if (!raw) {
            settingInfo.style.display = 'none';
            return;
        }

        let setting = null;
        try {
            setting = JSON.parse(raw);
        } catch (error) {
            settingInfo.style.display = 'none';
            return;
        }

        const packageLines = Array.isArray(setting.medicine_summary_lines) ? setting.medicine_summary_lines : [];
        const totalPrice = Number(setting.medicine_total_price || 0);

        if (settingName) settingName.textContent = setting.institution_name || '-';
        if (settingPeriod) settingPeriod.textContent = setting.period_label || '-';
        if (settingQuota) {
            settingQuota.textContent = (setting.claim_scope_label || '-') + ' | ' + (setting.calculation_mode_label || '-') + ' | Kuota: ' + (setting.quota_label || '-');
        }
        if (settingNotes) settingNotes.textContent = setting.notes || '';
        if (settingPackages) settingPackages.textContent = packageLines.length ? packageLines.join('\n') : '-';
        if (settingTotalPrice) settingTotalPrice.textContent = formatCurrency(totalPrice);
        settingInfo.style.display = 'block';
    }

    if (settingSelect) {
        settingSelect.addEventListener('change', renderSettingInfo);
        renderSettingInfo();
    }

    if (openButton && modal) {
        openButton.addEventListener('click', function() {
            const form = modal.querySelector('form');
            if (form) {
                form.reset();
            }
            const now = new Date();
            const dateField = modal.querySelector('input[name="document_date"]');
            const timeField = modal.querySelector('input[name="document_time"]');
            if (dateField) {
                dateField.value = now.toISOString().slice(0, 10);
            }
            if (timeField) {
                timeField.value = now.toTimeString().slice(0, 5);
            }
            if (settingInfo) {
                settingInfo.style.display = 'none';
            }
            document.querySelectorAll('.file-selected-name').forEach(function(node) {
                node.textContent = '';
                node.style.display = 'none';
                node.classList.add('hidden');
            });
            document.querySelectorAll('.file-size-info').forEach(function(node) {
                node.textContent = '';
            });
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });
    }

    document.body.addEventListener('click', function(event) {
        if (!modal) return;
        if (event.target === modal || event.target.closest('.btn-cancel')) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        if (noteViewModal && (event.target === noteViewModal || event.target.closest('#gaNoteViewModal .btn-cancel'))) {
            noteViewModal.classList.add('hidden');
            noteViewModal.style.display = 'none';
            document.body.classList.remove('modal-open');
            return;
        }

        const noteButton = event.target.closest('.ga-note-view-btn');
        if (noteButton && noteViewModal) {
            if (noteViewModalMeta) {
                noteViewModalMeta.textContent = noteButton.dataset.title || 'Catatan Kerja Sama';
            }
            if (noteViewModalBody) {
                noteViewModalBody.textContent = noteButton.dataset.note || '-';
            }
            noteViewModal.classList.remove('hidden');
            noteViewModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }
        if (event.key === 'Escape' && noteViewModal) {
            noteViewModal.classList.add('hidden');
            noteViewModal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }
    });

    document.querySelectorAll('input[type="file"]').forEach(function(input) {
        input.addEventListener('change', function() {
            const display = document.querySelector('.file-selected-name[data-for="' + this.id + '"]');
            const sizeInfo = document.querySelector('.file-size-info[data-for="' + this.id + '"]');
            if (!display) return;

            if (this.files && this.files[0]) {
                const file = this.files[0];
                const sizeKB = (file.size / 1024).toFixed(1);
                const sizeMB = (file.size / 1024 / 1024).toFixed(2);
                const sizeText = file.size > 1024 * 1024 ? (sizeMB + ' MB') : (sizeKB + ' KB');

                display.innerHTML = `
                    <span class="selected-file-info">
                        <strong>${file.name}</strong>
                        <small>Ukuran asli: ${sizeText}</small>
                    </span>
                `;
                display.style.display = 'flex';
                display.classList.remove('hidden');

                if (sizeInfo) {
                    sizeInfo.textContent = 'Setelah upload, foto akan dikompresi otomatis.';
                }
            } else {
                display.textContent = '';
                display.style.display = 'none';
                display.classList.add('hidden');
                if (sizeInfo) {
                    sizeInfo.textContent = '';
                }
            }
        });
    });

    document.querySelectorAll('.file-upload-label[data-target-input]').forEach(function(label) {
        label.addEventListener('click', function(event) {
            event.preventDefault();
            const inputId = label.getAttribute('data-target-input');
            const target = inputId ? document.getElementById(inputId) : null;
            if (target) {
                target.click();
            }
        });
    });

    document.querySelectorAll('.js-delete-form').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const message = form.dataset.confirm || 'Yakin ingin menghapus data ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
});
</script>

<style>
    .ga-note-cell {
        display: grid;
        gap: 8px;
        align-items: start;
    }

    .ga-note-excerpt {
        white-space: normal;
        line-height: 1.6;
    }

    .ga-note-view-btn {
        width: fit-content;
    }

    .ga-note-modal-body {
        white-space: pre-line;
        line-height: 1.8;
        color: #334155;
    }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
