<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_require_division_access(['Specialist Medical Authority'], '/dashboard/index.php');

$pageTitle = 'List Medis Specialist Medical Authority';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$positionFilter = ems_normalize_position($_GET['position'] ?? '');
$allowedPositionFilters = [
    '' => 'Semua',
    'trainee' => 'Trainee',
    'paramedic' => 'Paramedic',
    'co_asst' => 'Co. Asst',
    'general_practitioner' => 'Doctor',
    'specialist' => 'Doctor Specialist',
];

if (!array_key_exists($positionFilter, $allowedPositionFilters)) {
    $positionFilter = '';
}

$sessionUser = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $sessionUser);
$hasUnitCodeColumn = ems_column_exists($pdo, 'user_rh', 'unit_code');

function specialistMedicStatusMeta(array $user): array
{
    $cutiPeriodStatus = get_cuti_period_status(
        $user['cuti_start_date'] ?? null,
        $user['cuti_end_date'] ?? null,
        $user['cuti_status'] ?? null
    );

    if ((int)($user['is_active'] ?? 0) !== 1) {
        $hasResigned = !empty($user['resigned_at']) || trim((string)($user['resign_reason'] ?? '')) !== '';
        return [
            'label' => $hasResigned ? 'Resigned' : 'Inactive',
            'class' => $hasResigned ? 'badge-danger' : 'badge-secondary',
            'sort' => $hasResigned ? 4 : 5,
            'detail' => $hasResigned && !empty($user['resigned_at'])
                ? 'Resign ' . formatTanggalIndo((string)$user['resigned_at'])
                : 'Tidak aktif',
        ];
    }

    if ($cutiPeriodStatus === 'active') {
        return [
            'label' => 'On Leave',
            'class' => 'badge-warning',
            'sort' => 2,
            'detail' => formatTanggalIndo((string)($user['cuti_start_date'] ?? null)) . ' - ' . formatTanggalIndo((string)($user['cuti_end_date'] ?? null)),
        ];
    }

    if ($cutiPeriodStatus === 'scheduled') {
        return [
            'label' => 'Scheduled Leave',
            'class' => 'badge-info',
            'sort' => 3,
            'detail' => 'Mulai ' . formatTanggalIndo((string)($user['cuti_start_date'] ?? null)),
        ];
    }

    return [
        'label' => 'Available',
        'class' => 'badge-success',
        'sort' => 1,
        'detail' => 'Aktif dan tersedia',
    ];
}

function specialistMedicPromotionDate(array $user): ?string
{
    $position = ems_normalize_position($user['position'] ?? '');

    return match ($position) {
        'paramedic' => trim((string)($user['tanggal_naik_paramedic'] ?? '')) ?: trim((string)($user['tanggal_masuk'] ?? '')),
        'co_asst' => trim((string)($user['tanggal_naik_co_asst'] ?? '')) ?: trim((string)($user['tanggal_naik_paramedic'] ?? '')) ?: trim((string)($user['tanggal_masuk'] ?? '')),
        'general_practitioner' => trim((string)($user['tanggal_naik_dokter'] ?? '')) ?: trim((string)($user['tanggal_naik_co_asst'] ?? '')) ?: trim((string)($user['tanggal_masuk'] ?? '')),
        'specialist' => trim((string)($user['tanggal_naik_dokter_spesialis'] ?? '')) ?: trim((string)($user['tanggal_naik_dokter'] ?? '')) ?: trim((string)($user['tanggal_masuk'] ?? '')),
        default => trim((string)($user['tanggal_masuk'] ?? '')),
    };
}

function specialistMedicTenureDays(?string $date): int
{
    if (!$date) {
        return 0;
    }

    try {
        $start = new DateTime($date);
        $today = new DateTime('today');
        if ($start > $today) {
            return 0;
        }

        return (int)$start->diff($today)->days;
    } catch (Throwable $e) {
        return 0;
    }
}

function specialistMedicDocStatus(?string $path, string $requiredLabel = 'Completed', string $missingLabel = 'Not Yet'): array
{
    $hasFile = trim((string)$path) !== '';
    return [
        'label' => $hasFile ? $requiredLabel : $missingLabel,
        'class' => $hasFile ? 'badge-success' : 'badge-secondary',
        'sort' => $hasFile ? 1 : 0,
    ];
}

function specialistMedicMedicalClassMeta(array $user): array
{
    $position = ems_normalize_position($user['position'] ?? '');

    if ($position === 'trainee') {
        return ['label' => 'Not Required', 'class' => 'badge-muted', 'sort' => 2];
    }

    if ($position === 'paramedic') {
        return specialistMedicDocStatus($user['sertifikat_class_paramedic'] ?? null);
    }

    return specialistMedicDocStatus($user['sertifikat_class_co_asst'] ?? null);
}

function specialistMedicCertificateRequirements(array $user): array
{
    $position = ems_normalize_position($user['position'] ?? '');

    $requirements = [
        'file_ktp' => 'KTP',
        'file_kta' => 'KTA',
        'file_skb' => 'SKB',
    ];

    if (in_array($position, ['paramedic', 'co_asst', 'general_practitioner', 'specialist'], true)) {
        $requirements['sertifikat_class_paramedic'] = 'Class Paramedic';
    }

    if (in_array($position, ['co_asst', 'general_practitioner', 'specialist'], true)) {
        $requirements['sertifikat_class_co_asst'] = 'Class Co. Asst';
    }

    if (in_array($position, ['general_practitioner', 'specialist'], true)) {
        $requirements['sertifikat_operasi_kecil'] = 'Operasi Kecil';
    }

    if ($position === 'specialist') {
        $requirements['sertifikat_operasi_besar'] = 'Operasi Besar';
        $requirements['sertifikat_operasi_plastik'] = 'Operasi Plastik';
    }

    return $requirements;
}

function specialistMedicCertificateSummary(array $user): array
{
    $requirements = specialistMedicCertificateRequirements($user);
    $missing = [];

    foreach ($requirements as $field => $label) {
        if (trim((string)($user[$field] ?? '')) === '') {
            $missing[] = $label;
        }
    }

    if ($missing === []) {
        return [
            'label' => 'Lengkap',
            'class' => 'badge-success',
            'sort' => 1,
            'detail' => count($requirements) . ' syarat terpenuhi',
        ];
    }

    return [
        'label' => 'Belum Lengkap',
        'class' => 'badge-warning',
        'sort' => 0,
        'detail' => 'Kurang: ' . implode(', ', $missing),
    ];
}

$baseColumns = [
    'id',
    'full_name',
    'position',
    'batch',
    'tanggal_masuk',
    'kode_nomor_induk_rs',
    'citizen_id',
    'no_hp_ic',
    'jenis_kelamin',
    'is_active',
    'cuti_status',
    'cuti_start_date',
    'cuti_end_date',
    'resign_reason',
    'resigned_at',
    'file_ktp',
    'file_kta',
    'file_skb',
    'file_sim',
    'sertifikat_heli',
    'sertifikat_operasi',
    'dokumen_lainnya',
    'sertifikat_operasi_plastik',
    'sertifikat_operasi_kecil',
    'sertifikat_operasi_besar',
    'sertifikat_class_co_asst',
    'sertifikat_class_paramedic',
];

$optionalColumns = [
    'unit_code',
    'division',
    'tanggal_naik_paramedic',
    'tanggal_naik_co_asst',
    'tanggal_naik_dokter',
    'tanggal_naik_dokter_spesialis',
];

$selectColumns = $baseColumns;
foreach ($optionalColumns as $optionalColumn) {
    if (ems_column_exists($pdo, 'user_rh', $optionalColumn)) {
        $selectColumns[] = $optionalColumn;
    }
}

$whereParts = [
    "position IN ('trainee', 'paramedic', 'co_asst', 'general_practitioner', 'specialist')",
];
$params = [];

if ($hasUnitCodeColumn) {
    $whereParts[] = "COALESCE(unit_code, 'roxwood') = :unit_code";
    $params[':unit_code'] = $effectiveUnit;
}

if ($positionFilter !== '') {
    $whereParts[] = "position = :position";
    $params[':position'] = $positionFilter;
}

$medics = [];
$summary = [
    'total' => 0,
    'available' => 0,
    'on_leave' => 0,
    'resigned' => 0,
    'complete' => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT
            " . implode(",\n            ", $selectColumns) . "
        FROM user_rh
        WHERE " . implode(' AND ', $whereParts) . "
        ORDER BY is_active DESC, full_name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $statusMeta = specialistMedicStatusMeta($row);
        $promotionDate = specialistMedicPromotionDate($row);
        $tenureDays = specialistMedicTenureDays($promotionDate);

        // Process documents
        $docs = [
            'KTP' => $row['file_ktp'] ?? null,
            'SKB' => $row['file_skb'] ?? null,
            'SIM' => $row['file_sim'] ?? null,
            'KTA' => $row['file_kta'] ?? null,
            'HELI' => $row['sertifikat_heli'] ?? null,
            'Operasi' => $row['sertifikat_operasi'] ?? null,
            'Operasi Plastik' => $row['sertifikat_operasi_plastik'] ?? null,
            'Operasi Kecil' => $row['sertifikat_operasi_kecil'] ?? null,
            'Operasi Besar' => $row['sertifikat_operasi_besar'] ?? null,
            'Class Paramedic' => $row['sertifikat_class_paramedic'] ?? null,
            'Class Co. Asst' => $row['sertifikat_class_co_asst'] ?? null,
        ];

        $academyDocs = ensureAcademyDocIds(parseAcademyDocs($row['dokumen_lainnya'] ?? ''));
        foreach ($academyDocs as $ad) {
            $label = trim((string)($ad['name'] ?? 'File Lainnya'));
            $docs[$label] = $ad['path'] ?? null;
        }

        $medics[] = [
            'id' => (int)($row['id'] ?? 0),
            'full_name' => (string)($row['full_name'] ?? ''),
            'position' => ems_position_label((string)($row['position'] ?? '')),
            'batch' => (int)($row['batch'] ?? 0),
            'kode_nomor_induk_rs' => (string)($row['kode_nomor_induk_rs'] ?? ''),
            'citizen_id' => (string)($row['citizen_id'] ?? ''),
            'no_hp_ic' => (string)($row['no_hp_ic'] ?? ''),
            'jenis_kelamin' => (string)($row['jenis_kelamin'] ?? ''),
            'status_meta' => $statusMeta,
            'promotion_date' => $promotionDate,
            'promotion_date_label' => $promotionDate ? formatTanggalIndo($promotionDate) : '-',
            'tenure_days' => $tenureDays,
            'docs' => $docs,
        ];

        $summary['total']++;
        if ($statusMeta['label'] === 'Available') {
            $summary['available']++;
        } elseif ($statusMeta['label'] === 'On Leave' || $statusMeta['label'] === 'Scheduled Leave') {
            $summary['on_leave']++;
        } elseif ($statusMeta['label'] === 'Resigned') {
            $summary['resigned']++;
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat daftar medis: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="forensic-medics-hero mb-4">
            <div>
                <div class="forensic-medics-kicker">Specialist Medical Authority</div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Daftar tenaga medis berdasarkan jabatan, status operasional, dan kelengkapan sertifikat dari data `user_rh`.</p>
            </div>
            <div class="forensic-medics-hero-meta">
                <div class="forensic-medics-hero-label">Unit Aktif</div>
                <div class="forensic-medics-hero-value"><?= htmlspecialchars(ems_unit_label($effectiveUnit), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Total Medis', 'value' => number_format($summary['total']), 'icon' => 'user-group', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Available', 'value' => number_format($summary['available']), 'icon' => 'check-circle', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Cuti', 'value' => number_format($summary['on_leave']), 'icon' => 'calendar-days', 'tone' => 'warning']);
            ?>
        </div>

        <div class="card card-section mb-4">
            <div class="card-header">Filter Jabatan</div>
            <div class="card-body">
                <div class="forensic-medics-tabs">
                    <?php foreach ($allowedPositionFilters as $value => $label): ?>
                        <?php
                        $href = $value === ''
                            ? ems_url('/dashboard/specialist_medics.php')
                            : ems_url('/dashboard/specialist_medics.php?position=' . urlencode($value));
                        ?>
                        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="forensic-medics-tab<?= $positionFilter === $value ? ' is-active' : '' ?>">
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card card-section">
            <div class="card-header">
                <span>Daftar Medis (Read-Only)</span>
            </div>
            <p class="meta-text mb-4">Halaman ini hanya untuk melihat data. Tidak ada fitur edit, tambah, atau hapus. Data diambil dari tabel `user_rh`.</p>

            <div class="table-wrapper">
                <table id="specialistMedicsTable" class="table-custom w-full">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama</th>
                            <th>NIK</th>
                            <th>No. HP</th>
                            <th>Jenis Kelamin</th>
                            <th>Jabatan</th>
                            <th>Batch</th>
                            <th>Status</th>
                            <th>Tanggal Masuk</th>
                            <th>Tenure (Hari)</th>
                            <th>Dokumen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medics as $index => $medic): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($medic['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </td>
                                <td><?= htmlspecialchars($medic['citizen_id'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($medic['no_hp_ic'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($medic['jenis_kelamin'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($medic['position'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $medic['batch'] > 0 ? (int)$medic['batch'] : '-' ?></td>
                                <td data-order="<?= (int)$medic['status_meta']['sort'] ?>">
                                    <span class="<?= htmlspecialchars($medic['status_meta']['class'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($medic['status_meta']['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td data-order="<?= htmlspecialchars((string)($medic['promotion_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($medic['promotion_date_label'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td data-order="<?= (int)$medic['tenure_days'] ?>"><?= number_format((int)$medic['tenure_days']) ?></td>
                                <td>
                                    <?php
                                    $hasAnyDoc = false;
                                    foreach ($medic['docs'] as $docLabel => $docPath) {
                                        if (empty($docPath)) continue;
                                        $hasAnyDoc = true;
                                        ?>
                                        <a href="#"
                                            class="doc-badge btn-preview-doc"
                                            data-src="/<?= htmlspecialchars($docPath, ENT_QUOTES, 'UTF-8') ?>"
                                            data-title="<?= htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') ?>"
                                            title="Lihat <?= htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php }
                                    if (!$hasAnyDoc): ?>
                                        <span class="muted-placeholder">-</span>
                                    <?php endif; ?>
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
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && $.fn.DataTable) {
        $('#specialistMedicsTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            scrollX: true,
            autoWidth: false,
            order: [[7, 'asc'], [9, 'asc'], [1, 'asc']]
        });
    }
});
</script>

<style>
.forensic-medics-hero {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    border: 1px solid rgba(186, 230, 253, 0.9);
    border-radius: 1.5rem;
    background: linear-gradient(135deg, rgba(224, 242, 254, 0.96), rgba(255, 255, 255, 0.98));
}

.forensic-medics-kicker {
    margin-bottom: 0.35rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #0369a1;
}

.forensic-medics-hero-meta {
    min-width: 10rem;
    align-self: flex-start;
    padding: 0.9rem 1rem;
    border-radius: 1rem;
    background: rgba(12, 74, 110, 0.08);
    text-align: right;
}

.forensic-medics-hero-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #0369a1;
}

.forensic-medics-hero-value {
    margin-top: 0.3rem;
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
}

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

@media (max-width: 768px) {
    .forensic-medics-hero {
        flex-direction: column;
    }

    .forensic-medics-hero-meta {
        width: 100%;
        text-align: left;
    }
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
