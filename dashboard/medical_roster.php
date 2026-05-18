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

function medicalRosterNormalizeDate(?string $raw, bool $endOfDay = false): ?DateTimeImmutable
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'd/m/Y',
        'd M y H:i:s',
        'd M y H:i',
        'd M y',
    ];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $raw);
        if ($date instanceof DateTimeImmutable) {
            return $endOfDay
                ? $date->setTime(23, 59, 59)
                : $date->setTime(0, 0, 0);
        }
    }

    try {
        $date = new DateTimeImmutable($raw);
        return $endOfDay
            ? $date->setTime(23, 59, 59)
            : $date->setTime(0, 0, 0);
    } catch (Throwable $e) {
        return null;
    }
}

function medicalRosterTenureLabel(?string $joinedAt, ?string $resignedAt = null): string
{
    $start = medicalRosterNormalizeDate($joinedAt, false);
    $end = $resignedAt
        ? medicalRosterNormalizeDate($resignedAt, true)
        : new DateTimeImmutable('today');

    if (!$start || !$end) {
        return '-';
    }

    if ($start > $end) {
        return '0 hr';
    }

    $diff = $start->diff($end);
    $totalDays = max(0, (int) $diff->days);
    $years = (int) $diff->y;
    $months = (int) $diff->m;
    $days = (int) $diff->d;

    if ($years > 0) {
        return $years . ' th ' . $months . ' bln';
    }

    if ($months > 0) {
        return $months . ' bln ' . $days . ' hr';
    }

    if ($totalDays >= 30) {
        $roundedMonths = intdiv($totalDays, 30);
        $remainingDays = $totalDays % 30;
        return $roundedMonths . ' bln ' . $remainingDays . ' hr';
    }

    return $totalDays . ' hr';
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

function medicalRosterRecordCode(array $row): string
{
    $recordCode = trim((string) ($row['record_code'] ?? ''));
    if ($recordCode !== '') {
        return $recordCode;
    }

    return 'MR-' . str_pad((string) ((int) ($row['id'] ?? 0)), 6, '0', STR_PAD_LEFT);
}

function medicalRosterOperationHistoryMap(PDO $pdo, array $medicIds, bool $hasMedicalRecordsTable, bool $hasVisibilityScope, bool $hasAssistantPivotTable): array
{
    $map = [];
    $medicIds = array_values(array_unique(array_filter(array_map('intval', $medicIds), static fn (int $id): bool => $id > 0)));
    if (!$hasMedicalRecordsTable || $medicIds === []) {
        return $map;
    }

    $scopeWhere = $hasVisibilityScope
        ? "COALESCE(r.visibility_scope, 'standard') = 'standard'"
        : '1=1';
    $placeholders = implode(',', array_fill(0, count($medicIds), '?'));

    $roleAssignments = [];

    $doctorStmt = $pdo->prepare("
        SELECT r.id AS medical_record_id, r.doctor_id AS user_id, 'dpjp' AS role_key
        FROM medical_records r
        WHERE {$scopeWhere}
          AND r.doctor_id IN ({$placeholders})
    ");
    $doctorStmt->execute($medicIds);
    foreach ($doctorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        $recordId = (int) ($row['medical_record_id'] ?? 0);
        if ($userId > 0 && $recordId > 0) {
            $roleAssignments[$userId][$recordId][] = 'dpjp';
        }
    }

    if ($hasAssistantPivotTable) {
        $assistantStmt = $pdo->prepare("
            SELECT r.id AS medical_record_id, mra.assistant_user_id AS user_id, 'assistant' AS role_key
            FROM medical_record_assistants mra
            INNER JOIN medical_records r ON r.id = mra.medical_record_id
            WHERE {$scopeWhere}
              AND mra.assistant_user_id IN ({$placeholders})
        ");
    } else {
        $assistantStmt = $pdo->prepare("
            SELECT r.id AS medical_record_id, r.assistant_id AS user_id, 'assistant' AS role_key
            FROM medical_records r
            WHERE {$scopeWhere}
              AND r.assistant_id IN ({$placeholders})
        ");
    }
    $assistantStmt->execute($medicIds);
    foreach ($assistantStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        $recordId = (int) ($row['medical_record_id'] ?? 0);
        if ($userId > 0 && $recordId > 0) {
            $roleAssignments[$userId][$recordId][] = 'assistant';
        }
    }

    $recordIds = [];
    foreach ($roleAssignments as $userAssignments) {
        foreach (array_keys($userAssignments) as $recordId) {
            $recordIds[(int) $recordId] = true;
        }
    }
    $recordIds = array_keys($recordIds);
    if ($recordIds === []) {
        return $map;
    }

    $recordPlaceholders = implode(',', array_fill(0, count($recordIds), '?'));
    $recordStmt = $pdo->prepare("
        SELECT
            r.id,
            r.record_code,
            r.patient_name,
            r.operasi_type,
            " . (ems_column_exists($pdo, 'medical_records', 'jenis_operasi') ? "COALESCE(r.jenis_operasi, '')" : "''") . " AS jenis_operasi,
            r.created_at,
            COALESCE(d.full_name, '') AS doctor_name
        FROM medical_records r
        LEFT JOIN user_rh d ON d.id = r.doctor_id
        WHERE r.id IN ({$recordPlaceholders})
        ORDER BY r.created_at DESC, r.id DESC
    ");
    $recordStmt->execute($recordIds);
    $records = [];
    foreach ($recordStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $records[(int) ($row['id'] ?? 0)] = $row;
    }

    foreach ($roleAssignments as $userId => $userAssignments) {
        $history = [];
        foreach ($userAssignments as $recordId => $roles) {
            $record = $records[(int) $recordId] ?? null;
            if (!$record) {
                continue;
            }

            $roleLabels = [];
            foreach (array_values(array_unique($roles)) as $role) {
                $roleLabels[] = $role === 'dpjp' ? 'DPJP' : 'Asisten';
            }

            $history[] = [
                'id' => (int) $recordId,
                'record_code' => medicalRosterRecordCode($record),
                'patient_name' => (string) ($record['patient_name'] ?? '-'),
                'doctor_name' => trim((string) ($record['doctor_name'] ?? '')) ?: '-',
                'operasi_type' => strtolower((string) ($record['operasi_type'] ?? 'minor')) === 'major' ? 'Mayor' : 'Minor',
                'jenis_operasi' => trim((string) ($record['jenis_operasi'] ?? '')),
                'created_at' => (string) ($record['created_at'] ?? ''),
                'role_text' => $roleLabels !== [] ? implode(', ', $roleLabels) : '-',
            ];
        }

        usort($history, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return $bTime <=> $aTime;
        });

        $map[(int) $userId] = $history;
    }

    return $map;
}

$summary = [
    'total_all' => 0,
    'doctors' => 0,
    'specialists' => 0,
    'paramedics' => 0,
    'co_asst' => 0,
    'trainees' => 0,
    'medics_with_cases' => 0,
    'disciplinary_cases' => 0,
];
$medics = [];
$historyTemplates = [];

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
    $historyMap = medicalRosterOperationHistoryMap(
        $pdo,
        array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $medics),
        $hasMedicalRecordsTable,
        ems_column_exists($pdo, 'medical_records', 'visibility_scope'),
        $hasAssistantPivotTable
    );

    foreach ($medics as &$medic) {
        $medic['position_label'] = ems_position_label((string)($medic['position'] ?? ''));
        $medic['status_meta'] = medicalRosterStatusMeta($medic);
        $resignedAt = !empty($medic['resigned_at']) ? (string) $medic['resigned_at'] : null;
        $medic['tenure_label'] = medicalRosterTenureLabel((string)($medic['tanggal_masuk'] ?? ''), $resignedAt);
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

        $disciplinaryCaseCount = (int)($medic['disciplinary_case_count'] ?? 0);
        if ($disciplinaryCaseCount > 0) {
            $summary['medics_with_cases']++;
            $summary['disciplinary_cases'] += $disciplinaryCaseCount;
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
        <p class="page-subtitle">Ringkasan lengkap tenaga medis Roxwood Hospital, transaksi, sertifikat, cuti, dan statistik kasus Komdis secara global.</p>

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
            ems_component('ui/statistic-card', ['label' => 'Medis Pelanggar', 'value' => $summary['medics_with_cases'], 'icon' => 'exclamation-triangle', 'tone' => 'warning']);
            ems_component('ui/statistic-card', ['label' => 'Total Kasus Komdis', 'value' => $summary['disciplinary_cases'], 'icon' => 'clipboard-document-list', 'tone' => 'danger']);
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medics as $medic): ?>
                            <?php $medicHistory = $historyMap[(int) ($medic['id'] ?? 0)] ?? []; ?>
                            <tr>
                                <td>
                                    <button
                                        type="button"
                                        class="medical-roster-name medical-roster-name-btn btn-open-medical-history"
                                        data-template-id="medical-roster-history-<?= (int) ($medic['id'] ?? 0) ?>"
                                        data-modal-title="History Rekam Medis"
                                        data-modal-subtitle="<?= htmlspecialchars((string) ($medic['full_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)$medic['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </button>
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
                            </tr>
                            <?php
                            ob_start();
                            ?>
                            <div class="medical-roster-history-shell">
                                <div class="medical-roster-history-summary">
                                    <span class="badge-info">Total Rekam Medis: <?= number_format(count($medicHistory), 0, ',', '.') ?></span>
                                </div>
                                <?php if ($medicHistory === []): ?>
                                    <div class="muted-placeholder">Belum ada history rekam medis untuk medis ini.</div>
                                <?php else: ?>
                                    <div class="table-wrapper">
                                        <table class="table-custom medical-roster-history-table">
                                            <thead>
                                                <tr>
                                                    <th>Tanggal</th>
                                                    <th>No. Rekam Medis</th>
                                                    <th>Pasien</th>
                                                    <th>Peran</th>
                                                    <th>DPJP</th>
                                                    <th>Jenis Operasi</th>
                                                    <th>Nama / Jenis Operasi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($medicHistory as $history): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($history['created_at'] !== '' ? date('d/m/Y H:i', strtotime((string) $history['created_at'])) : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><a href="rekam_medis_view.php?id=<?= (int) $history['id'] ?>" class="text-primary hover:underline"><?= htmlspecialchars((string) $history['record_code'], ENT_QUOTES, 'UTF-8') ?></a></td>
                                                        <td><?= htmlspecialchars((string) $history['patient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars((string) $history['role_text'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars((string) $history['doctor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><span class="<?= $history['operasi_type'] === 'Mayor' ? 'badge-danger' : 'badge-warning' ?>"><?= htmlspecialchars((string) $history['operasi_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                        <td><?= htmlspecialchars((string) ($history['jenis_operasi'] !== '' ? $history['jenis_operasi'] : '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php
                            $historyTemplates[] = [
                                'id' => (int) ($medic['id'] ?? 0),
                                'html' => ob_get_clean(),
                            ];
                            ?>
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

<?php foreach ($historyTemplates as $historyTemplate): ?>
    <template id="medical-roster-history-<?= (int) $historyTemplate['id'] ?>">
        <?= $historyTemplate['html'] ?>
    </template>
<?php endforeach; ?>

<div id="medicalRosterHistoryModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="forensic-detail-head">
            <div class="min-w-0">
                <div id="medicalRosterHistoryTitle" class="forensic-detail-title">History Rekam Medis</div>
                <div id="medicalRosterHistorySubtitle" class="forensic-detail-subtitle"></div>
            </div>
            <button type="button" class="modal-close-btn btn-close-medical-roster-history" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div id="medicalRosterHistoryBody" class="forensic-detail-content"></div>
        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary btn-close-medical-roster-history">Tutup</button>
            </div>
        </div>
    </div>
</div>

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

    const historyModal = document.getElementById('medicalRosterHistoryModal');
    const historyTitle = document.getElementById('medicalRosterHistoryTitle');
    const historySubtitle = document.getElementById('medicalRosterHistorySubtitle');
    const historyBody = document.getElementById('medicalRosterHistoryBody');

    function closeHistoryModal() {
        if (!historyModal || !historyBody) {
            return;
        }
        historyModal.classList.add('hidden');
        historyBody.innerHTML = '';
        document.body.classList.remove('modal-open');
    }

    document.body.addEventListener('click', function (event) {
        const trigger = event.target.closest('.btn-open-medical-history');
        if (trigger && historyModal && historyTitle && historySubtitle && historyBody) {
            const templateId = trigger.getAttribute('data-template-id') || '';
            const template = document.getElementById(templateId);
            if (!template) {
                return;
            }

            historyTitle.textContent = trigger.getAttribute('data-modal-title') || 'History Rekam Medis';
            historySubtitle.textContent = trigger.getAttribute('data-modal-subtitle') || '';
            historyBody.innerHTML = template.innerHTML;
            historyModal.classList.remove('hidden');
            document.body.classList.add('modal-open');
            return;
        }

        if (event.target.closest('.btn-close-medical-roster-history')) {
            closeHistoryModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && historyModal && !historyModal.classList.contains('hidden')) {
            closeHistoryModal();
        }
    });
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

    .medical-roster-name-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        border: 0;
        padding: 0;
        background: transparent;
        text-align: left;
        cursor: pointer;
        color: #0369a1;
        box-shadow: none;
        transition: color 0.16s ease;
    }

    .medical-roster-name-btn:hover {
        color: #075985;
        text-decoration: underline;
    }

    .medical-roster-history-shell {
        display: grid;
        gap: 1rem;
    }

    .medical-roster-history-summary {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .medical-roster-history-table th,
    .medical-roster-history-table td {
        white-space: nowrap;
        vertical-align: top;
    }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>

