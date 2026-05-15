<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

$pageTitle = 'Daftar Medis Roxwood';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$targetUnit = 'roxwood';
$hasUnitCodeColumn = ems_column_exists($pdo, 'user_rh', 'unit_code');
$hasSalesUnitCode = ems_column_exists($pdo, 'sales', 'unit_code');
$hasDisciplinaryTables = ems_table_exists($pdo, 'disciplinary_cases');
$hasCutiRequestsTable = ems_table_exists($pdo, 'cuti_requests');
$hasMedicalRecordsTable = ems_table_exists($pdo, 'medical_records');
$hasSalesTable = ems_table_exists($pdo, 'sales');
$hasAssistantPivotTable = ems_table_exists($pdo, 'medical_record_assistants');
$hasFarmasiSessionTable = ems_table_exists($pdo, 'user_farmasi_sessions');

function medicalRosterStatusMeta(array $user): array
{
    $cutiPeriodStatus = get_cuti_period_status(
        $user['cuti_start_date'] ?? null,
        $user['cuti_end_date'] ?? null,
        $user['cuti_status'] ?? null
    );

    if ((int)($user['is_active'] ?? 0) !== 1) {
        $hasResigned = !empty($user['resigned_at']) || trim((string)($user['resign_reason'] ?? '')) !== '';
        return [
            'label' => $hasResigned ? 'Resign' : 'Nonaktif',
            'class' => $hasResigned ? 'badge-danger' : 'badge-secondary',
            'detail' => $hasResigned && !empty($user['resigned_at'])
                ? 'Resign ' . formatTanggalIndo((string)$user['resigned_at'])
                : 'Tidak aktif',
        ];
    }

    if ($cutiPeriodStatus === 'active') {
        return [
            'label' => 'Sedang Cuti',
            'class' => 'badge-warning',
            'detail' => formatTanggalIndo((string)($user['cuti_start_date'] ?? null)) . ' - ' . formatTanggalIndo((string)($user['cuti_end_date'] ?? null)),
        ];
    }

    if ($cutiPeriodStatus === 'scheduled') {
        return [
            'label' => 'Menunggu Cuti',
            'class' => 'badge-info',
            'detail' => 'Mulai ' . formatTanggalIndo((string)($user['cuti_start_date'] ?? null)),
        ];
    }

    return [
        'label' => 'Aktif',
        'class' => 'badge-success',
        'detail' => 'Aktif bekerja',
    ];
}

function medicalRosterTenureLabel(?string $joinedAt): string
{
    if (!$joinedAt) {
        return '-';
    }

    try {
        $start = new DateTimeImmutable($joinedAt);
        $today = new DateTimeImmutable('today');
        if ($start > $today) {
            return '0 bulan';
        }

        $diff = $start->diff($today);
        $years = (int)$diff->y;
        $months = (int)$diff->m;
        $days = (int)$diff->d;

        if ($years > 0) {
            return $years . ' th ' . $months . ' bln';
        }
        if ($months > 0) {
            return $months . ' bln ' . $days . ' hr';
        }

        return max(0, $days) . ' hr';
    } catch (Throwable $e) {
        return '-';
    }
}

function medicalRosterDocMeta(?string $path): array
{
    $hasFile = trim((string)$path) !== '';
    return [
        'label' => $hasFile ? 'Ada' : 'Belum',
        'class' => $hasFile ? 'badge-success' : 'badge-secondary',
    ];
}

function medicalRosterDutyHoursLabel(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours > 0) {
        return number_format($hours, 0, ',', '.') . ' jam ' . $minutes . ' mnt';
    }

    return $minutes . ' mnt';
}

$summary = [
    'total_all' => 0,
    'doctors' => 0,
    'specialists' => 0,
    'paramedics' => 0,
    'co_asst' => 0,
    'trainees' => 0,
];
$medics = [];

try {
    $selectColumns = [
        'u.id',
        'u.full_name',
        'u.position',
        'u.batch',
        'u.division',
        'u.role',
        'u.tanggal_masuk',
        'u.tanggal_naik_paramedic',
        'u.tanggal_naik_co_asst',
        'u.tanggal_naik_dokter',
        'u.tanggal_naik_dokter_spesialis',
        'u.is_active',
        'u.cuti_status',
        'u.cuti_start_date',
        'u.cuti_end_date',
        'u.resign_reason',
        'u.resigned_at',
        'u.sertifikat_heli',
        'u.sertifikat_class_paramedic',
        'u.sertifikat_class_co_asst',
    ];

    $joins = [];
    $params = [];

    if ($hasSalesTable) {
        $salesSql = "
            SELECT
                medic_user_id AS user_id,
                COUNT(*) AS total_farmasi_transactions,
                COALESCE(SUM(price), 0) AS total_farmasi_amount,
                FLOOR(COALESCE(SUM(price), 0) * 0.6) AS total_farmasi_bonus
            FROM sales
            WHERE medic_user_id IS NOT NULL
            " . ($hasSalesUnitCode ? "AND COALESCE(unit_code, 'roxwood') = :sales_unit_code" : "") . "
            GROUP BY medic_user_id
        ";
        $joins[] = "LEFT JOIN ({$salesSql}) farmasi_stats ON farmasi_stats.user_id = u.id";
        if ($hasSalesUnitCode) {
            $params[':sales_unit_code'] = $targetUnit;
        }
        $selectColumns[] = 'COALESCE(farmasi_stats.total_farmasi_transactions, 0) AS total_farmasi_transactions';
        $selectColumns[] = 'COALESCE(farmasi_stats.total_farmasi_amount, 0) AS total_farmasi_amount';
        $selectColumns[] = 'COALESCE(farmasi_stats.total_farmasi_bonus, 0) AS total_farmasi_bonus';
    } else {
        $selectColumns[] = '0 AS total_farmasi_transactions';
        $selectColumns[] = '0 AS total_farmasi_amount';
        $selectColumns[] = '0 AS total_farmasi_bonus';
    }

    if ($hasFarmasiSessionTable) {
        $sessionSql = "
            SELECT
                user_id,
                COALESCE(SUM(
                    CASE
                        WHEN session_end IS NULL THEN GREATEST(TIMESTAMPDIFF(SECOND, session_start, NOW()), 0)
                        ELSE GREATEST(COALESCE(duration_seconds, TIMESTAMPDIFF(SECOND, session_start, session_end)), 0)
                    END
                ), 0) AS total_duty_seconds
            FROM user_farmasi_sessions
            GROUP BY user_id
        ";
        $joins[] = "LEFT JOIN ({$sessionSql}) duty_stats ON duty_stats.user_id = u.id";
        $selectColumns[] = 'COALESCE(duty_stats.total_duty_seconds, 0) AS total_duty_seconds';
    } else {
        $selectColumns[] = '0 AS total_duty_seconds';
    }

    if ($hasMedicalRecordsTable) {
        $doctorSql = "
            SELECT
                doctor_id AS user_id,
                COUNT(*) AS total_medical_transactions,
                SUM(CASE WHEN operasi_type = 'minor' THEN 1 ELSE 0 END) AS dpjp_minor_count,
                SUM(CASE WHEN operasi_type = 'major' THEN 1 ELSE 0 END) AS dpjp_major_count
            FROM medical_records
            WHERE doctor_id IS NOT NULL
              AND COALESCE(visibility_scope, 'standard') = 'standard'
            GROUP BY doctor_id
        ";
        $joins[] = "LEFT JOIN ({$doctorSql}) doctor_stats ON doctor_stats.user_id = u.id";
        $selectColumns[] = 'COALESCE(doctor_stats.total_medical_transactions, 0) AS total_medical_transactions';
        $selectColumns[] = 'COALESCE(doctor_stats.dpjp_minor_count, 0) AS dpjp_minor_count';
        $selectColumns[] = 'COALESCE(doctor_stats.dpjp_major_count, 0) AS dpjp_major_count';

        if ($hasAssistantPivotTable) {
            $assistantSql = "
                SELECT
                    mra.assistant_user_id AS user_id,
                    COUNT(*) AS assistant_total_count,
                    SUM(CASE WHEN mr.operasi_type = 'minor' THEN 1 ELSE 0 END) AS assistant_minor_count,
                    SUM(CASE WHEN mr.operasi_type = 'major' THEN 1 ELSE 0 END) AS assistant_major_count
                FROM medical_record_assistants mra
                INNER JOIN medical_records mr ON mr.id = mra.medical_record_id
                WHERE COALESCE(mr.visibility_scope, 'standard') = 'standard'
                GROUP BY mra.assistant_user_id
            ";
        } else {
            $assistantSql = "
                SELECT
                    assistant_id AS user_id,
                    COUNT(*) AS assistant_total_count,
                    SUM(CASE WHEN operasi_type = 'minor' THEN 1 ELSE 0 END) AS assistant_minor_count,
                    SUM(CASE WHEN operasi_type = 'major' THEN 1 ELSE 0 END) AS assistant_major_count
                FROM medical_records
                WHERE assistant_id IS NOT NULL
                  AND COALESCE(visibility_scope, 'standard') = 'standard'
                GROUP BY assistant_id
            ";
        }
        $joins[] = "LEFT JOIN ({$assistantSql}) assistant_stats ON assistant_stats.user_id = u.id";
        $selectColumns[] = 'COALESCE(assistant_stats.assistant_total_count, 0) AS assistant_total_count';
        $selectColumns[] = 'COALESCE(assistant_stats.assistant_minor_count, 0) AS assistant_minor_count';
        $selectColumns[] = 'COALESCE(assistant_stats.assistant_major_count, 0) AS assistant_major_count';
    } else {
        $selectColumns[] = '0 AS total_medical_transactions';
        $selectColumns[] = '0 AS dpjp_minor_count';
        $selectColumns[] = '0 AS dpjp_major_count';
        $selectColumns[] = '0 AS assistant_total_count';
        $selectColumns[] = '0 AS assistant_minor_count';
        $selectColumns[] = '0 AS assistant_major_count';
    }

    if ($hasCutiRequestsTable) {
        $leaveSql = "
            SELECT
                user_id,
                COUNT(*) AS total_leave_requests,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_leave_requests,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_leave_requests
            FROM cuti_requests
            GROUP BY user_id
        ";
        $joins[] = "LEFT JOIN ({$leaveSql}) leave_stats ON leave_stats.user_id = u.id";
        $selectColumns[] = 'COALESCE(leave_stats.total_leave_requests, 0) AS total_leave_requests';
        $selectColumns[] = 'COALESCE(leave_stats.approved_leave_requests, 0) AS approved_leave_requests';
        $selectColumns[] = 'COALESCE(leave_stats.pending_leave_requests, 0) AS pending_leave_requests';
    } else {
        $selectColumns[] = '0 AS total_leave_requests';
        $selectColumns[] = '0 AS approved_leave_requests';
        $selectColumns[] = '0 AS pending_leave_requests';
    }

    if ($hasDisciplinaryTables) {
        $disciplineSql = "
            SELECT
                subject_user_id AS user_id,
                COUNT(*) AS disciplinary_case_count,
                COALESCE(SUM(total_points), 0) AS disciplinary_total_points
            FROM disciplinary_cases
            GROUP BY subject_user_id
        ";
        $joins[] = "LEFT JOIN ({$disciplineSql}) discipline_stats ON discipline_stats.user_id = u.id";
        $selectColumns[] = 'COALESCE(discipline_stats.disciplinary_case_count, 0) AS disciplinary_case_count';
        $selectColumns[] = 'COALESCE(discipline_stats.disciplinary_total_points, 0) AS disciplinary_total_points';
    } else {
        $selectColumns[] = '0 AS disciplinary_case_count';
        $selectColumns[] = '0 AS disciplinary_total_points';
    }

    $sql = "
        SELECT
            " . implode(",\n            ", $selectColumns) . "
        FROM user_rh u
        " . implode("\n", $joins) . "
        WHERE u.position IN ('trainee', 'paramedic', 'co_asst', 'general_practitioner', 'specialist')
        " . ($hasUnitCodeColumn ? "AND COALESCE(u.unit_code, 'roxwood') = :user_unit_code" : "") . "
        ORDER BY u.full_name ASC
    ";

    if ($hasUnitCodeColumn) {
        $params[':user_unit_code'] = $targetUnit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $medics = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($medics as &$medic) {
        $medic['position_label'] = ems_position_label((string)($medic['position'] ?? ''));
        $medic['status_meta'] = medicalRosterStatusMeta($medic);
        $medic['tenure_label'] = medicalRosterTenureLabel((string)($medic['tanggal_masuk'] ?? ''));
        $medic['duty_hours_label'] = medicalRosterDutyHoursLabel((int)($medic['total_duty_seconds'] ?? 0));
        $medic['heli_meta'] = medicalRosterDocMeta($medic['sertifikat_heli'] ?? null);
        $medic['paramedic_class_meta'] = medicalRosterDocMeta($medic['sertifikat_class_paramedic'] ?? null);
        $medic['coasst_class_meta'] = medicalRosterDocMeta($medic['sertifikat_class_co_asst'] ?? null);

        $position = ems_normalize_position((string)($medic['position'] ?? ''));
        $summary['total_all']++;
        if ($position === 'general_practitioner') {
            $summary['doctors']++;
        } elseif ($position === 'specialist') {
            $summary['specialists']++;
        } elseif ($position === 'paramedic') {
            $summary['paramedics']++;
        } elseif ($position === 'co_asst') {
            $summary['co_asst']++;
        } elseif ($position === 'trainee') {
            $summary['trainees']++;
        }
    }
    unset($medic);
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat daftar medis Roxwood: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Daftar Medis Roxwood</h1>
        <p class="page-subtitle">Ringkasan lengkap tenaga medis Roxwood Hospital, transaksi, sertifikat, cuti, dan pelanggaran.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Total Medis', 'value' => $summary['total_all'], 'icon' => 'user-group', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Total Dokter', 'value' => $summary['doctors'], 'icon' => 'document-text', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Dokter Specialist', 'value' => $summary['specialists'], 'icon' => 'clipboard-document-list', 'tone' => 'warning']);
            ems_component('ui/statistic-card', ['label' => 'Paramedic', 'value' => $summary['paramedics'], 'icon' => 'shield-check', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Co. Asst', 'value' => $summary['co_asst'], 'icon' => 'clipboard-document-list', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Trainee', 'value' => $summary['trainees'], 'icon' => 'clipboard-document-list', 'tone' => 'muted']);
            ?>
        </div>

        <div class="card">
            <div class="card-header">Daftar Medis Roxwood</div>
            <div class="table-wrapper">
                <table id="medicalRosterTable" class="table-custom medical-roster-table">
                    <thead>
                        <tr>
                            <th>Nama Medis</th>
                            <th>Jabatan</th>
                            <th>Status</th>
                            <th>Lama Bergabung</th>
                            <th>Lama Jam Duty</th>
                            <th>Batch</th>
                            <th>Bonus Farmasi 60%</th>
                            <th>Transaksi Medis</th>
                            <th>Asisten Minor</th>
                            <th>Asisten Mayor</th>
                            <th>DPJP Minor</th>
                            <th>DPJP Mayor</th>
                            <th>Class Paramedic</th>
                            <th>Class Co. Asst</th>
                            <th>Sertifikat Heli</th>
                            <th>Total Pengajuan Cuti</th>
                            <th>Point Pelanggaran</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medics as $medic): ?>
                            <tr>
                                <td>
                                    <div class="medical-roster-name"><?= htmlspecialchars((string)$medic['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="meta-text-xs">
                                        <?= htmlspecialchars((string)($medic['division'] ?? '-'), ENT_QUOTES, 'UTF-8') ?> |
                                        <?= htmlspecialchars((string)($medic['role'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars((string)$medic['position_label'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="meta-text-xs">Masuk: <?= htmlspecialchars(formatTanggalIndo((string)($medic['tanggal_masuk'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <span class="<?= htmlspecialchars((string)$medic['status_meta']['class'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)$medic['status_meta']['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <div class="meta-text-xs mt-1"><?= htmlspecialchars((string)$medic['status_meta']['detail'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td><?= htmlspecialchars((string)$medic['tenure_label'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$medic['duty_hours_label'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int)($medic['batch'] ?? 0) > 0 ? (int)$medic['batch'] : '-' ?></td>
                                <td>
                                    <div><strong><?= number_format((int)($medic['total_farmasi_transactions'] ?? 0), 0, ',', '.') ?></strong> trx</div>
                                    <div class="meta-text-xs"><?= dollar((int)($medic['total_farmasi_bonus'] ?? 0)) ?></div>
                                </td>
                                <td><strong><?= number_format((int)($medic['total_medical_transactions'] ?? 0), 0, ',', '.') ?></strong> trx</td>
                                <td><?= number_format((int)($medic['assistant_minor_count'] ?? 0), 0, ',', '.') ?></td>
                                <td><?= number_format((int)($medic['assistant_major_count'] ?? 0), 0, ',', '.') ?></td>
                                <td><?= number_format((int)($medic['dpjp_minor_count'] ?? 0), 0, ',', '.') ?></td>
                                <td><?= number_format((int)($medic['dpjp_major_count'] ?? 0), 0, ',', '.') ?></td>
                                <td><span class="<?= htmlspecialchars((string)$medic['paramedic_class_meta']['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$medic['paramedic_class_meta']['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="<?= htmlspecialchars((string)$medic['coasst_class_meta']['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$medic['coasst_class_meta']['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="<?= htmlspecialchars((string)$medic['heli_meta']['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$medic['heli_meta']['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <div><strong><?= number_format((int)($medic['total_leave_requests'] ?? 0), 0, ',', '.') ?></strong> pengajuan</div>
                                    <div class="meta-text-xs">Approved <?= number_format((int)($medic['approved_leave_requests'] ?? 0), 0, ',', '.') ?> | Pending <?= number_format((int)($medic['pending_leave_requests'] ?? 0), 0, ',', '.') ?></div>
                                </td>
                                <td>
                                    <div><strong><?= number_format((int)($medic['disciplinary_total_points'] ?? 0), 0, ',', '.') ?></strong> poin</div>
                                    <div class="meta-text-xs"><?= number_format((int)($medic['disciplinary_case_count'] ?? 0), 0, ',', '.') ?> kasus</div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$medics): ?>
                    <div class="muted-placeholder p-4">Belum ada data medis Roxwood yang dapat ditampilkan.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#medicalRosterTable').DataTable({
            pageLength: 25,
            order: [[0, 'asc']],
            scrollX: true,
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });
    }
});
</script>

<style>
    .medical-roster-table th,
    .medical-roster-table td {
        vertical-align: top;
        white-space: nowrap;
    }

    .medical-roster-table td:first-child,
    .medical-roster-table td:nth-child(2),
    .medical-roster-table td:nth-child(15),
    .medical-roster-table td:nth-child(16),
    .medical-roster-table td:nth-child(17) {
        white-space: normal;
    }

    .medical-roster-name {
        font-weight: 700;
        color: #0f172a;
    }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>

