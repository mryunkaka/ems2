<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['General Affair']);
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Sertifikat Heli Medis';

function heliPageHasColumn(PDO $pdo, string $column): bool
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

$hasDivisionColumn = heliPageHasColumn($pdo, 'division');
$divisionSelect = $hasDivisionColumn ? 'u.division,' : "'' AS division,";

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$stmt = $pdo->query("
    SELECT
        u.id,
        u.full_name,
        u.position,
        u.role,
        {$divisionSelect}
        u.tanggal_masuk,
        u.is_active,
        u.sertifikat_heli
    FROM user_rh u
    WHERE TRIM(COALESCE(u.sertifikat_heli, '')) <> ''
    ORDER BY u.is_active DESC, u.full_name ASC
");
$certificateUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalLicensed = count($certificateUsers);
$totalActiveLicensed = 0;
$divisionCounts = [];

foreach ($certificateUsers as $row) {
    if ((int)($row['is_active'] ?? 0) === 1) {
        $totalActiveLicensed++;
    }

    $divisionName = ems_normalize_division($row['division'] ?? '') ?: 'Tanpa Division';
    $divisionCounts[$divisionName] = ($divisionCounts[$divisionName] ?? 0) + 1;
}

arsort($divisionCounts);
$topDivisionLabel = array_key_first($divisionCounts) ?: '-';
$topDivisionCount = $topDivisionLabel !== '-' ? (int)$divisionCounts[$topDivisionLabel] : 0;

function heliFormatJoinDate(?string $date): string
{
    if (!$date) {
        return '-';
    }

    try {
        return (new DateTime($date))->format('d M Y');
    } catch (Throwable $e) {
        return (string)$date;
    }
}
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title">Sertifikat Heli Medis</h1>
        <p class="page-subtitle">Menampilkan user yang sudah memiliki lisensi sertifikat heli.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="card">
                <div class="card-header">Total Pemilik Sertifikat</div>
                <div class="text-3xl font-semibold text-slate-900"><?= $totalLicensed ?></div>
            </div>

            <div class="card">
                <div class="card-header">User Aktif</div>
                <div class="text-3xl font-semibold text-slate-900"><?= $totalActiveLicensed ?></div>
            </div>

            <div class="card">
                <div class="card-header">Division Terbanyak</div>
                <div class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($topDivisionLabel, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="text-sm text-slate-500"><?= $topDivisionCount ?> user</div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header card-header-between">
                <span>Daftar Lisensi Sertifikat Heli</span>
                <span class="badge-muted"><?= $totalLicensed ?> data</span>
            </div>

            <div class="table-wrapper">
                <table id="heliCertificateTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Jabatan</th>
                            <th>Role</th>
                            <th>Division</th>
                            <th>Tanggal Masuk</th>
                            <th>Status</th>
                            <th>Sertifikat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($certificateUsers as $index => $row): ?>
                            <?php $divisionName = ems_normalize_division($row['division'] ?? '') ?: '-'; ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><strong><?= htmlspecialchars($row['full_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><?= htmlspecialchars(ems_position_label($row['position'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(ems_role_label($row['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($divisionName, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(heliFormatJoinDate($row['tanggal_masuk'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ((int)($row['is_active'] ?? 0) === 1): ?>
                                        <span class="badge-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge-muted">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a
                                        href="#"
                                        class="doc-badge btn-preview-doc"
                                        data-src="/<?= htmlspecialchars(ltrim((string)($row['sertifikat_heli'] ?? ''), '/'), ENT_QUOTES, 'UTF-8') ?>"
                                        data-title="<?= htmlspecialchars('Sertifikat Heli - ' . ($row['full_name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?>">
                                        Lihat Dokumen
                                    </a>
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
        if (!window.jQuery || !jQuery.fn.DataTable) {
            return;
        }

        jQuery('#heliCertificateTable').DataTable({
            pageLength: 10,
            order: [
                [1, 'asc']
            ],
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
