<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

if (!isset($_GET['range'])) {
    $_GET['range'] = 'week4';
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/police_partnership.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'police_partnership.php', '/dashboard/index.php');
policePartnershipEnsureTable($pdo);

$pageTitle = 'Kerja Sama Police';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$canViewGlobalRecap = ems_is_manager_plus_role($user['role'] ?? '');
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$errors = array_values(array_filter($errors, static function ($error) {
    return trim((string)$error) !== 'Akses halaman ditolak untuk division Anda.';
}));

$recentStmt = $pdo->prepare("
    SELECT *
    FROM police_partnership_records
    WHERE unit_code = ?
      AND DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) = CURDATE()
    ORDER BY COALESCE(service_at, CONCAT(service_date, ' 00:00:00')) DESC, id DESC
");
$recentStmt->execute([$effectiveUnit]);
$recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filteredStmt = $pdo->prepare("
    SELECT *
    FROM police_partnership_records
    WHERE unit_code = ?
      AND DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) BETWEEN ? AND ?
    ORDER BY COALESCE(service_at, CONCAT(service_date, ' 00:00:00')) DESC, id DESC
");
$filteredStmt->execute([
    $effectiveUnit,
    substr($rangeStart, 0, 10),
    substr($rangeEnd, 0, 10),
]);
$filteredRows = $filteredStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between mb-4">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle"><?= htmlspecialchars(ems_unit_label($effectiveUnit), ENT_QUOTES, 'UTF-8') ?> &bull; Input tindakan kerja sama Police dan rumah sakit tanpa nominal biaya.</p>
            </div>
            <?php if ($canViewGlobalRecap): ?>
                <a href="police_partnership_recap.php" class="btn-secondary">
                    <?= ems_icon('chart-bar', 'h-4 w-4') ?>
                    <span>Lihat Rekap Global</span>
                </a>
            <?php endif; ?>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
            <div class="card">
                <div class="card-header">Input Tindakan Police</div>
                <form method="POST" action="police_partnership_action.php" class="form" enctype="multipart/form-data">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="create">

                    <label>Foto Badge Police</label>
                    <input type="file" name="badge_file" accept="image/jpeg,image/png,image/webp" required>
                    <p class="meta-text-xs">JPG, PNG, atau WebP. Sistem akan kompres otomatis mendekati 200KB.</p>

                    <label>Jam dan Tanggal</label>
                    <input type="datetime-local" name="service_at" required value="<?= htmlspecialchars(date('Y-m-d\TH:i'), ENT_QUOTES, 'UTF-8') ?>">

                    <label>Tindakan</label>
                    <select name="action_type" required>
                        <option value="">Pilih tindakan</option>
                        <?php foreach (policePartnershipActionOptions() as $option): ?>
                            <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-success">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Simpan Input</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Input Terbaru</div>
                <div class="table-wrapper">
                    <table id="policeInputTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Jam dan Tanggal</th>
                                <th>Foto Badge</th>
                                <th>Tindakan</th>
                                <th>Input</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRows as $row): ?>
                                <tr>
                                    <td data-order="<?= htmlspecialchars((string)($row['service_at'] ?? $row['service_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(policePartnershipDateTimeLabel($row['service_at'] ?? '', $row['service_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php $badgeUrl = policePartnershipSecureFileUrl($row['badge_file_path'] ?? ''); ?>
                                        <?php if ($badgeUrl !== ''): ?>
                                            <a href="#" class="doc-badge is-verified btn-preview-doc" data-src="<?= htmlspecialchars($badgeUrl, ENT_QUOTES, 'UTF-8') ?>" data-title="Foto Badge Police">Lihat Foto</a>
                                        <?php else: ?>
                                            <span class="muted-placeholder">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)$row['action_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)$row['input_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card card-section mb-4">
            <div class="card-header">Filter Rentang Tanggal Rekap Perorangan</div>
            <div class="card-body">
                <form method="GET" class="filter-bar">
                    <div class="filter-group">
                        <label>Rentang</label>
                        <select name="range" id="rangeSelect" class="form-control">
                            <option value="week1" <?= ($_GET['range'] ?? '') === 'week1' ? 'selected' : '' ?>>3 Minggu Lalu</option>
                            <option value="week2" <?= ($_GET['range'] ?? '') === 'week2' ? 'selected' : '' ?>>2 Minggu Lalu</option>
                            <option value="week3" <?= ($_GET['range'] ?? '') === 'week3' ? 'selected' : '' ?>>Minggu Lalu</option>
                            <option value="week4" <?= ($_GET['range'] ?? 'week4') === 'week4' ? 'selected' : '' ?>>Minggu Ini</option>
                            <option value="custom" <?= ($_GET['range'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
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
            <div class="card-header">Rekap Perorangan &bull; <?= htmlspecialchars($rangeLabel ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="table-wrapper">
                <table id="policePersonRecapTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>Jam dan Tanggal</th>
                            <th>Foto Badge</th>
                            <th>Tindakan</th>
                            <th>Input</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredRows as $row): ?>
                            <tr>
                                <td data-order="<?= htmlspecialchars((string)($row['service_at'] ?? $row['service_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(policePartnershipDateTimeLabel($row['service_at'] ?? '', $row['service_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php $badgeUrl = policePartnershipSecureFileUrl($row['badge_file_path'] ?? ''); ?>
                                    <?php if ($badgeUrl !== ''): ?>
                                        <a href="#" class="doc-badge is-verified btn-preview-doc" data-src="<?= htmlspecialchars($badgeUrl, ENT_QUOTES, 'UTF-8') ?>" data-title="Foto Badge Police">Lihat Foto</a>
                                    <?php else: ?>
                                        <span class="muted-placeholder">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string)$row['action_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$row['input_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <form method="POST" action="police_partnership_action.php" onsubmit="return confirm('Hapus data input Police ini?')" class="inline-flex">
                                        <?= csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_record">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="redirect" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? 'police_partnership.php'), ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Hapus data" aria-label="Hapus data">
                                            <?= ems_icon('trash', 'h-4 w-4') ?>
                                        </button>
                                    </form>
                                </td>
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
        const rangeSelect = document.getElementById('rangeSelect');
        const customFields = document.querySelectorAll('.filter-custom');
        const toggleCustomFields = function() {
            const isCustom = rangeSelect && rangeSelect.value === 'custom';
            customFields.forEach(function(field) {
                field.style.display = isCustom ? 'block' : 'none';
            });
        };

        if (rangeSelect) {
            toggleCustomFields();
            rangeSelect.addEventListener('change', toggleCustomFields);
        }

        if (!window.jQuery || !jQuery.fn.DataTable) {
            return;
        }

        jQuery('#policeInputTable').DataTable({
            pageLength: 10,
            order: [[0, 'desc']],
            language: {
                url: '/assets/design/js/datatables-id.json',
                emptyTable: 'Belum ada input kerja sama Police.'
            }
        });

        jQuery('#policePersonRecapTable').DataTable({
            pageLength: 10,
            order: [[0, 'desc']],
            language: {
                url: '/assets/design/js/datatables-id.json',
                emptyTable: 'Belum ada rekap perorangan.'
            }
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
