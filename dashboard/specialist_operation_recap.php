<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/medical_records_api.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/ai_queue.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_require_division_access(['Medical Affair'], '/dashboard/index.php');
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Rekap Operasi Medis';
$errors = $_SESSION['flash_errors'] ?? [];
$messages = $_SESSION['flash_messages'] ?? [];
unset($_SESSION['flash_errors'], $_SESSION['flash_messages']);

$search = trim((string) ($_GET['search'] ?? ''));
$searchNeedle = mb_strtolower($search);
$hasVisibilityScope = ems_column_exists($pdo, 'medical_records', 'visibility_scope');
$hasRecordCode = ems_column_exists($pdo, 'medical_records', 'record_code');
$hasAssistantsTable = ems_table_exists($pdo, 'medical_record_assistants');

function specialistOperationSafeValue(mixed $value, string $fallback = '-'): string
{
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

function specialistOperationRecordCode(array $row, bool $hasRecordCode): string
{
    $recordCode = $hasRecordCode ? trim((string) ($row['record_code'] ?? '')) : '';
    if ($recordCode !== '') {
        return $recordCode;
    }

    return 'MR-' . str_pad((string) ((int) ($row['medical_record_id'] ?? $row['id'] ?? 0)), 6, '0', STR_PAD_LEFT);
}

function specialistOperationRoleLabel(string $roleKey): string
{
    return $roleKey === 'dpjp' ? 'DPJP' : 'Asisten';
}

function specialistOperationTypeLabel(string $operationType): string
{
    return strtolower($operationType) === 'major' ? 'Mayor' : 'Minor';
}

$scopeWhere = $hasVisibilityScope
    ? "COALESCE(r.visibility_scope, 'standard') = 'standard'"
    : '1=1';

$recordStats = [
    'medical_staff' => 0,
    'linked_records' => 0,
    'major' => 0,
    'minor' => 0,
    'role_assignments' => 0,
];
$staffRecap = [];
$validationRows = [];
$validationFindings = [];
$validationAiQueued = 0;
$validationActiveKeys = [];
$validationAiEnabled = false;
$validationAiClearCount = 0;

function specialistValidationNormalizeId(string $value): string
{
    return strtoupper((string) preg_replace('/[^a-z0-9]/i', '', $value));
}

function specialistValidationIdsLookSimilar(string $left, string $right): bool
{
    if ($left === '' || $right === '' || $left === $right || abs(strlen($left) - strlen($right)) > 1 || min(strlen($left), strlen($right)) < 6) {
        return false;
    }
    return levenshtein($left, $right) <= 1;
}

try {
    $doctorSql = "
        SELECT
            r.id AS medical_record_id,
            " . ($hasRecordCode ? "COALESCE(r.record_code, '')" : "''") . " AS record_code,
            r.created_at,
            r.patient_name,
            r.patient_occupation,
            r.operasi_type,
            u.id AS user_id,
            u.full_name,
            u.position,
            'dpjp' AS role_key
        FROM medical_records r
        INNER JOIN user_rh u ON u.id = r.doctor_id
        WHERE {$scopeWhere}
    ";

    $doctorRows = $pdo->query($doctorSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($hasAssistantsTable) {
        $assistantSql = "
            SELECT
                r.id AS medical_record_id,
                " . ($hasRecordCode ? "COALESCE(r.record_code, '')" : "''") . " AS record_code,
                r.created_at,
                r.patient_name,
                r.patient_occupation,
                r.operasi_type,
                u.id AS user_id,
                u.full_name,
                u.position,
                'assistant' AS role_key
            FROM medical_record_assistants mra
            INNER JOIN medical_records r ON r.id = mra.medical_record_id
            INNER JOIN user_rh u ON u.id = mra.assistant_user_id
            WHERE {$scopeWhere}
            ORDER BY r.created_at DESC, mra.sort_order ASC
        ";
    } else {
        $assistantSql = "
            SELECT
                r.id AS medical_record_id,
                " . ($hasRecordCode ? "COALESCE(r.record_code, '')" : "''") . " AS record_code,
                r.created_at,
                r.patient_name,
                r.patient_occupation,
                r.operasi_type,
                u.id AS user_id,
                u.full_name,
                u.position,
                'assistant' AS role_key
            FROM medical_records r
            INNER JOIN user_rh u ON u.id = r.assistant_id
            WHERE {$scopeWhere}
        ";
    }

    $assistantRows = $pdo->query($assistantSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $roleAssignments = array_merge($doctorRows, $assistantRows);

    foreach ($roleAssignments as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        $position = (string) ($row['position'] ?? '');

        if ($userId <= 0 || !ems_is_medical_position($position)) {
            continue;
        }

        $recordId = (int) ($row['medical_record_id'] ?? 0);
        $recordCode = specialistOperationRecordCode($row, $hasRecordCode);
        $fullName = trim((string) ($row['full_name'] ?? ''));
        $patientName = trim((string) ($row['patient_name'] ?? ''));
        $patientOccupation = trim((string) ($row['patient_occupation'] ?? ''));
        $operationType = strtolower((string) ($row['operasi_type'] ?? 'minor')) === 'major' ? 'major' : 'minor';
        $roleKey = (string) ($row['role_key'] ?? 'assistant');

        $haystack = mb_strtolower(implode(' ', [
            $fullName,
            ems_position_label($position),
            $patientName,
            $patientOccupation,
            $recordCode,
            specialistOperationRoleLabel($roleKey),
            specialistOperationTypeLabel($operationType),
        ]));

        if ($searchNeedle !== '' && !str_contains($haystack, $searchNeedle)) {
            continue;
        }

        if (!isset($staffRecap[$userId])) {
            $staffRecap[$userId] = [
                'user_id' => $userId,
                'full_name' => $fullName,
                'position' => ems_position_label($position),
                'dpjp_major' => 0,
                'dpjp_minor' => 0,
                'assistant_major' => 0,
                'assistant_minor' => 0,
                'records' => [],
                'record_keys' => [],
            ];
        }

        $bucket = $roleKey . '_' . $operationType;
        if (isset($staffRecap[$userId][$bucket])) {
            $staffRecap[$userId][$bucket]++;
        }

        $recordRoleKey = $recordId . ':' . $roleKey;
        if (!isset($staffRecap[$userId]['record_keys'][$recordRoleKey])) {
            $staffRecap[$userId]['record_keys'][$recordRoleKey] = true;
            $staffRecap[$userId]['records'][] = [
                'medical_record_id' => $recordId,
                'record_code' => $recordCode,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'patient_name' => $patientName,
                'patient_occupation' => $patientOccupation,
                'operasi_type' => $operationType,
                'role_key' => $roleKey,
                'view_url' => 'rekam_medis_view.php?id=' . $recordId,
            ];
        }

        $recordStats['role_assignments']++;
        if ($operationType === 'major') {
            $recordStats['major']++;
        } else {
            $recordStats['minor']++;
        }
    }

    foreach ($staffRecap as &$staff) {
        usort($staff['records'], static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        $staff['total_dpjp'] = (int) $staff['dpjp_major'] + (int) $staff['dpjp_minor'];
        $staff['total_assistant'] = (int) $staff['assistant_major'] + (int) $staff['assistant_minor'];
        $staff['total_operations'] = $staff['total_dpjp'] + $staff['total_assistant'];
        $staff['linked_records_count'] = count($staff['records']);
    }
    unset($staff);

    usort($staffRecap, static function (array $left, array $right): int {
        $scoreCompare = ($right['total_operations'] <=> $left['total_operations']);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        return strcmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
    });

    $recordStats['medical_staff'] = count($staffRecap);
    $linkedRecordKeys = [];
    foreach ($staffRecap as $staff) {
        foreach ($staff['records'] as $record) {
            $linkedRecordKeys[(string) ($record['medical_record_id'] ?? 0)] = true;
        }
    }
    $recordStats['linked_records'] = count($linkedRecordKeys);

    if (ems_table_exists($pdo, 'medical_record_validation_findings')) {
        $hasCitizenId = ems_column_exists($pdo, 'medical_records', 'patient_citizen_id');
        $hasAssistantTable = ems_table_exists($pdo, 'medical_record_assistants');
        $validationSql = "SELECT r.*, COALESCE(u.full_name, '') AS doctor_name, COALESCE(c.full_name, '') AS creator_name, "
            . ($hasAssistantTable ? "GREATEST((SELECT COUNT(*) FROM medical_record_assistants mra WHERE mra.medical_record_id=r.id),IF(r.assistant_id IS NULL,0,1))" : "IF(r.assistant_id IS NULL,0,1)") . " AS assistant_count "
            . "FROM medical_records r LEFT JOIN user_rh u ON u.id = r.doctor_id LEFT JOIN user_rh c ON c.id=r.created_by "
            . "WHERE {$scopeWhere} AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY r.created_at DESC";
        $validationRows = $pdo->query($validationSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ids = [];
        foreach ($validationRows as $row) {
            if (($row['source_provider'] ?? '') === 'medical_center') {
                $remotePayload = ems_medical_center_decode_array($row['remote_payload_json'] ?? []);
                if ($remotePayload !== []) {
                    foreach (ems_medical_center_normalize_record($remotePayload, $pdo) as $field => $value) {
                        if (in_array($field, ['patient_name', 'patient_occupation', 'patient_citizen_id', 'patient_gender', 'operasi_type', 'jenis_operasi'], true)
                            && trim((string) ($row[$field] ?? '')) === '' && trim((string) ($value ?? '')) !== '') $row[$field] = $value;
                    }
                }
                $remoteDpjp = ems_medical_center_person(ems_medical_center_decode_array($row['remote_dpjp_json'] ?? []));
                if (trim((string) ($row['doctor_name'] ?? '')) === '') $row['doctor_name'] = $remoteDpjp['local_name'] ?: $remoteDpjp['name'];
                if (trim((string) ($row['creator_name'] ?? '')) === '') {
                    $remoteDetails = ems_medical_center_decode_array($row['remote_medical_details_json'] ?? []);
                    $creator = ems_medical_center_person($remotePayload['creator'] ?? ($remoteDetails['creator'] ?? ($remoteDetails['tim']['first_responder'] ?? null)));
                    $row['creator_name'] = $creator['name'] ?? '';
                }
                if (ems_medical_center_decode_array($row['remote_assistants_json'] ?? []) !== []) $row['assistant_count'] = 1;
            }
            $recordId = (int) $row['id'];
            $patientName = trim((string) $row['patient_name']);
            $empty = static fn (mixed $value): bool => in_array(mb_strtolower(trim(strip_tags((string) ($value ?? '')))), ['', '-', 'n/a', 'null'], true);
            $citizenId = specialistValidationNormalizeId((string) ($row['patient_citizen_id'] ?? ''));
            $requiredListFields = [
                'patient_name' => 'nama pasien', 'patient_occupation' => 'pekerjaan pasien',
                'patient_dob' => 'tanggal lahir pasien', 'patient_gender' => 'jenis kelamin pasien',
                'doctor_name' => 'nama DPJP', 'creator_name' => 'nama petugas input',
                'operasi_type' => 'jenis operasi (mayor/minor)',
            ];
            if ($hasCitizenId) $requiredListFields['patient_citizen_id'] = 'Citizen ID pasien';
            if (ems_column_exists($pdo, 'medical_records', 'jenis_operasi')) $requiredListFields['jenis_operasi'] = 'nama tindakan/operasi';
            if ((int) ($row['assistant_count'] ?? 0) === 0 && empty($row['remote_assistants_json'])) $requiredListFields['assistant_id'] = 'nama asisten operasi';
            foreach ($requiredListFields as $field => $label) {
                $value = $field === 'assistant_id' ? null : ($field === 'doctor_name' ? $row['doctor_name'] : ($field === 'creator_name' ? $row['creator_name'] : ($row[$field] ?? null)));
                if (!$empty($value)) continue;
                $rule = 'field_missing_' . $field;
                $validationFindings[$recordId . ':' . $rule] = [$recordId, (int) ($row['doctor_id'] ?? 0), $rule, 'administrative', ucfirst($label) . ' belum diisi pada rekam medis.', 'Minta petugas terkait melengkapi ' . $label . ' pada data rekam medis.', null, null, null];
            }
            if ($citizenId !== '') {
                $ids[] = ['id' => $citizenId, 'name' => mb_strtolower($patientName), 'record' => $recordId, 'doctor' => (int) $row['doctor_id']];
            }
        }
        $idBuckets = [];
        foreach ($ids as $index => $candidate) {
            $signatures = [$candidate['id']];
            for ($offset = 0, $length = strlen($candidate['id']); $offset < $length; $offset++) {
                $signatures[] = substr($candidate['id'], 0, $offset) . substr($candidate['id'], $offset + 1);
            }
            foreach (array_unique($signatures) as $signature) $idBuckets[$signature][] = $index;
        }
        $checkedPairs = [];
        foreach ($idBuckets as $bucket) {
            $bucket = array_values(array_unique($bucket));
            for ($i = 0, $count = count($bucket); $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $left = $ids[$bucket[$i]];
                    $right = $ids[$bucket[$j]];
                    $pairKey = min($left['record'], $right['record']) . ':' . max($left['record'], $right['record']);
                    if (isset($checkedPairs[$pairKey]) || $left['name'] === $right['name']) continue;
                    $checkedPairs[$pairKey] = true;
                    $exact = $left['id'] === $right['id'];
                    if (!$exact && !specialistValidationIdsLookSimilar($left['id'], $right['id'])) continue;
                    $rule = $exact ? 'citizen_id_duplicate' : 'citizen_id_similar';
                    $message = $exact ? 'Citizen ID yang sama tercatat pada pasien dengan nama berbeda.' : 'Citizen ID ini sangat mirip dengan ID pasien lain yang namanya berbeda. Periksa kemungkinan salah ketik.';
                    foreach ([$left, $right] as $candidate) {
                        $key = $candidate['record'] . ':' . $rule;
                        $validationFindings[$key] = [$candidate['record'], $candidate['doctor'], $rule, 'review', $message, 'Cocokkan Citizen ID dengan dokumen pasien sebelum diperbaiki.', null, null, null];
                    }
                }
            }
        }
        $upsertFinding = $pdo->prepare("INSERT INTO medical_record_validation_findings (medical_record_id,subject_user_id,rule_key,source,severity,finding,recommendation,reference_title,reference_location,reference_url) VALUES (?,?,?,'offline',?,?,?,?,?,?) ON DUPLICATE KEY UPDATE subject_user_id=VALUES(subject_user_id),severity=VALUES(severity),finding=VALUES(finding),recommendation=VALUES(recommendation),reference_title=VALUES(reference_title),reference_location=VALUES(reference_location),reference_url=VALUES(reference_url),status=IF(status='resolved','pending',status)");
        foreach ($validationFindings as $finding) { $upsertFinding->execute($finding); $validationActiveKeys[] = (int) $finding[0] . ':' . (string) $finding[2]; }
        $existingOffline = $pdo->query("SELECT f.id,f.medical_record_id,f.rule_key FROM medical_record_validation_findings f INNER JOIN medical_records r ON r.id=f.medical_record_id WHERE f.source='offline' AND f.status='pending' AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $resolveFinding = $pdo->prepare("UPDATE medical_record_validation_findings SET status='resolved' WHERE id=? AND status='pending'");
        foreach ($existingOffline as $existing) { if (!in_array((int) $existing['medical_record_id'] . ':' . (string) $existing['rule_key'], $validationActiveKeys, true)) $resolveFinding->execute([(int) $existing['id']]); }
        $hasCitizenIdForList = ems_column_exists($pdo, 'medical_records', 'patient_citizen_id');
        $validationStmt = $pdo->query("SELECT f.*, r.patient_name, " . ($hasCitizenIdForList ? "r.patient_citizen_id" : "''") . " AS patient_citizen_id, COALESCE(u.full_name,'Tenaga medis') AS subject_name FROM medical_record_validation_findings f INNER JOIN medical_records r ON r.id=f.medical_record_id LEFT JOIN user_rh u ON u.id=f.subject_user_id WHERE f.status='pending' AND f.rule_key<>'ai_completed' AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY f.created_at DESC, f.id DESC LIMIT 100");
        $validationFindings = $validationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $validationAiClearCount = (int) $pdo->query("SELECT COUNT(*) FROM medical_record_validation_findings f INNER JOIN medical_records r ON r.id=f.medical_record_id WHERE f.source='ai' AND f.rule_key='ai_v2_completed' AND f.status='resolved' AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND NOT EXISTS (SELECT 1 FROM medical_record_validation_findings x WHERE x.medical_record_id=f.medical_record_id AND x.source='ai' AND x.rule_key LIKE 'ai_v2_%' AND x.rule_key<>'ai_v2_completed' AND x.status='pending')")->fetchColumn();

        $validationAiEnabled = ems_ai_global_provider_ready($pdo);
        if (ems_table_exists($pdo, 'system_ai_request_queue') && $validationAiEnabled
            && ems_table_exists($pdo, 'medical_record_validation_findings')
            && ems_column_exists($pdo, 'medical_record_validation_findings', 'severity')) {
            $hasScopeForQueue = ems_column_exists($pdo, 'medical_records', 'visibility_scope');
            $activeAiJobs = $pdo->query("SELECT COUNT(*) FROM system_ai_request_queue WHERE job_type='medical_record_validation' AND status IN ('queued','running')")->fetchColumn();
            $queueLimit = max(0, 3 - (int) $activeAiJobs);
            $queueSql = "SELECT r.id FROM medical_records r WHERE r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) " . ($hasScopeForQueue ? "AND COALESCE(r.visibility_scope,'standard')='standard' " : '')
                . "AND NOT EXISTS (SELECT 1 FROM medical_record_validation_findings f WHERE f.medical_record_id=r.id AND f.source='ai' AND f.rule_key LIKE 'ai_v2_%') "
                . "AND (SELECT COUNT(*) FROM system_ai_request_queue q WHERE q.job_type='medical_record_validation' AND q.dedupe_key=SHA2(CONCAT('medical-record-validation-v2:',r.id),256)) < 2 "
                . "ORDER BY r.created_at DESC LIMIT " . $queueLimit;
            foreach ($pdo->query($queueSql)->fetchAll(PDO::FETCH_COLUMN) as $recordId) {
                try {
                    $requesterId = (int) ($_SESSION['user_rh']['id'] ?? 0);
                    ems_ai_queue_enqueue($pdo, 'medical_record_validation', $requesterId, 'Validasi awal rekam medis', ['requester_id' => $requesterId, 'record_id' => (int) $recordId], 'medical-record-validation-v2:' . (int) $recordId);
                    $validationAiQueued++;
                } catch (Throwable $queueError) {
                    error_log('Medical record validation queue: ' . $queueError->getMessage());
                    break;
                }
            }
        }
    }
} catch (Throwable $exception) {
    $errors[] = 'Gagal memuat rekap operasi medis: ' . $exception->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex justify-between items-center gap-4 mb-4">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Klik nama tenaga medis untuk membuka daftar rekam medis operasi yang pernah ditangani sebagai DPJP maupun asisten.</p>
            </div>
            <a href="rekam_medis_list.php" class="btn-secondary">
                <?= ems_icon('clipboard-document-list', 'h-4 w-4') ?>
                <span>Buka Rekam Medis</span>
            </a>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Tenaga Medis', 'value' => number_format((int) $recordStats['medical_staff']), 'icon' => 'user-group', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Rekam Medis Terkait', 'value' => number_format((int) $recordStats['linked_records']), 'icon' => 'clipboard-document-list', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Operasi Mayor', 'value' => number_format((int) $recordStats['major']), 'icon' => 'arrow-trending-up', 'tone' => 'danger']);
            ems_component('ui/statistic-card', ['label' => 'Operasi Minor', 'value' => number_format((int) $recordStats['minor']), 'icon' => 'shield-check', 'tone' => 'warning']);
            ?>
        </div>

        <div class="card card-section mb-4">
            <div class="card-body">
                <form method="GET" action="" class="flex gap-2">
                    <input
                        type="text"
                        name="search"
                        class="form-input flex-1"
                        placeholder="Cari nama tenaga medis, jabatan, pasien, atau no rekam medis..."
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    />
                    <button type="submit" class="btn-primary">
                        <?= ems_icon('magnifying-glass', 'h-4 w-4') ?>
                        <span>Cari</span>
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="specialist_operation_recap.php" class="btn-secondary">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card card-section mb-4" id="medical-record-validation">
            <div class="card-header"><span>Validasi Temuan Rekam Medis</span><span class="badge-info"><?= count($validationFindings) ?> perlu diperiksa</span></div>
            <div class="card-body">
                <p class="text-sm text-gray-600 mb-3">Rekam medis yang diperiksa sejak <?= htmlspecialchars(date('d M Y', strtotime('-7 days')), ENT_QUOTES, 'UTF-8') ?> hingga seterusnya.</p>
                <?php if (!$validationAiEnabled): ?><p class="text-sm text-amber-700 mb-3">Pemeriksaan AI belum berjalan karena model global belum aktif/terhubung di Pengaturan AI.</p><?php endif; ?>
                <?php if (!ems_table_exists($pdo, 'medical_record_validation_findings')): ?>
                    <div class="alert alert-warning">Jalankan SQL <code>docs/sql/110_2026-11-14_medical_record_validation.sql</code> dan <code>docs/sql/111_2026-11-14_medical_record_validation_severity.sql</code> untuk mengaktifkan daftar temuan dan tingkat risikonya.</div>
                <?php elseif ($validationFindings === []): ?>
                    <div class="text-sm text-gray-500">Belum ada temuan data yang perlu diperiksa.</div>
                <?php else: ?>
                    <div class="overflow-x-auto"><table class="table-custom w-full"><thead><tr><th>Rekam medis</th><th>Temuan</th><th>Petugas terkait</th><th>Rujukan</th><th>Tindakan</th></tr></thead><tbody>
                    <?php foreach ($validationFindings as $finding): ?>
                        <?php $severity = (string) ($finding['severity'] ?? 'administrative'); $rowTone = $severity === 'risk' ? 'bg-red-50 border-l-4 border-red-500' : ($severity === 'review' ? 'bg-amber-50 border-l-4 border-amber-400' : ''); $severityLabel = $severity === 'risk' ? 'Risiko tinggi' : ($severity === 'review' ? 'Perlu verifikasi' : 'Administrasi'); ?>
                        <tr class="<?= $rowTone ?>"><td><a class="text-primary hover:underline" href="rekam_medis_view.php?id=<?= (int) $finding['medical_record_id'] ?>">#<?= (int) $finding['medical_record_id'] ?> · <?= htmlspecialchars((string) $finding['patient_name'], ENT_QUOTES, 'UTF-8') ?></a></td>
                        <td><?= htmlspecialchars((string) $finding['finding'], ENT_QUOTES, 'UTF-8') ?><div class="text-xs mt-1 <?= $severity === 'risk' ? 'text-red-700' : ($severity === 'review' ? 'text-amber-700' : 'text-gray-500') ?>"><?= htmlspecialchars($severityLabel, ENT_QUOTES, 'UTF-8') ?> · Perlu verifikasi</div></td>
                        <td><?= htmlspecialchars((string) $finding['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?php if ($finding['reference_title'] && $finding['reference_url']): ?><a href="<?= htmlspecialchars((string) $finding['reference_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline"><?= htmlspecialchars((string) $finding['reference_title'], ENT_QUOTES, 'UTF-8') ?></a><?= $finding['reference_location'] ? ' · ' . htmlspecialchars((string) $finding['reference_location'], ENT_QUOTES, 'UTF-8') : '' ?><?php else: ?>Pemeriksaan kelengkapan data<?php endif; ?></td>
                        <td><div class="flex flex-wrap gap-2">
                            <form method="POST" action="specialist_operation_validation_action.php"><?= csrfField() ?><input type="hidden" name="finding_id" value="<?= (int) $finding['id'] ?>"><input type="hidden" name="action" value="notice"><button class="btn-warning btn-sm" type="submit">Teguran</button></form>
                            <button type="button" class="btn-danger btn-sm js-open-validation-report" data-finding-id="<?= (int) $finding['id'] ?>" data-finding-text="<?= htmlspecialchars((string) $finding['finding'], ENT_QUOTES, 'UTF-8') ?>">Laporkan Komdis</button>
                            <form method="POST" action="specialist_operation_validation_action.php"><?= csrfField() ?><input type="hidden" name="finding_id" value="<?= (int) $finding['id'] ?>"><input type="hidden" name="action" value="ignore"><button class="btn-secondary btn-sm" type="submit">Abaikan</button></form>
                        </div></td></tr>
                    <?php endforeach; ?></tbody></table></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card card-section">
            <div class="card-header">
                <span>Daftar Rekap Operasi Medis</span>
                <?php
                $exportParams = [];
                if ($search !== '') {
                    $exportParams['search'] = $search;
                }
                $exportUrl = ems_url('/dashboard/specialist_operation_recap_export.php' . ($exportParams ? '?' . http_build_query($exportParams) : ''));
                ?>
                <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
                    <?= ems_icon('document-arrow-down', 'h-4 w-4') ?>
                    <span>Export Excel</span>
                </a>
            </div>
            <div class="card-body">
                <?php if ($staffRecap === []): ?>
                    <div class="text-center py-8 text-gray-500">
                        Belum ada data operasi medis yang cocok dengan pencarian.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table id="specialistOperationRecapTable" class="table-custom w-full">
                            <thead>
                                <tr>
                                    <th class="text-left">Tenaga Medis</th>
                                    <th class="text-center">DPJP Mayor</th>
                                    <th class="text-center">DPJP Minor</th>
                                    <th class="text-center">Asisten Mayor</th>
                                    <th class="text-center">Asisten Minor</th>
                                    <th class="text-center">Total DPJP</th>
                                    <th class="text-center">Total Asisten</th>
                                    <th class="text-center">Total Operasi</th>
                                    <th class="text-center">Rekam Medis</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($staffRecap as $staff): ?>
                                    <tr>
                                        <td>
                                            <a href="specialist_operation_records.php?user_id=<?= (int) $staff['user_id'] ?>" class="font-semibold text-primary hover:underline">
                                                <?= htmlspecialchars((string) $staff['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars((string) $staff['position'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td class="text-center"><span class="badge-danger"><?= (int) $staff['dpjp_major'] ?></span></td>
                                        <td class="text-center"><span class="badge-warning"><?= (int) $staff['dpjp_minor'] ?></span></td>
                                        <td class="text-center"><span class="badge-danger"><?= (int) $staff['assistant_major'] ?></span></td>
                                        <td class="text-center"><span class="badge-warning"><?= (int) $staff['assistant_minor'] ?></span></td>
                                        <td class="text-center font-semibold"><?= (int) $staff['total_dpjp'] ?></td>
                                        <td class="text-center font-semibold"><?= (int) $staff['total_assistant'] ?></td>
                                        <td class="text-center">
                                            <span class="badge-info"><?= (int) $staff['total_operations'] ?></span>
                                        </td>
                                        <td class="text-center"><?= (int) $staff['linked_records_count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div id="validationReportModal" class="modal-overlay hidden" aria-hidden="true" style="display:none">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div>
                <div id="validationReportTitle" class="modal-title">Laporkan Temuan ke Komdis</div>
                <p class="meta-text">Tuliskan alasan laporan. Komdis akan menerima temuan dan alasan ini untuk ditinjau.</p>
            </div>
            <button type="button" class="modal-close-btn js-close-validation-report" aria-label="Tutup modal"><?= ems_icon('x-mark', 'h-5 w-5') ?></button>
        </div>
        <form id="validationReportForm" method="POST" action="specialist_operation_validation_action.php" class="form modal-form">
            <div class="modal-content" style="max-height:60vh; overflow-y:auto">
                <?= csrfField() ?>
                <input type="hidden" name="finding_id" id="validationReportFindingId" value="">
                <input type="hidden" name="action" value="report">
                <p class="meta-text mb-3"><strong>Temuan otomatis:</strong> <span id="validationReportFinding"></span></p>
                <label for="validationReporterReason">Alasan pelapor</label>
                <textarea id="validationReporterReason" name="reporter_reason" rows="4" maxlength="2000" required placeholder="Jelaskan mengapa temuan ini perlu ditinjau Komdis, misalnya dampaknya atau hal yang perlu diperiksa."></textarea>
                <div class="meta-text-xs">Alasan ini akan ikut masuk ke catatan kasus Komdis. Pasal dan poin ditetapkan oleh Komdis.</div>
            </div>
            <div class="modal-foot"><div class="modal-actions">
                <button type="button" class="btn-secondary js-close-validation-report">Batal</button>
                <button type="submit" class="btn-danger">Kirim ke Komdis</button>
            </div></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const reportModal = document.getElementById('validationReportModal');
    const reportFindingId = document.getElementById('validationReportFindingId');
    const reportFindingText = document.getElementById('validationReportFinding');
    const reporterReason = document.getElementById('validationReporterReason');
    const openReportModal = function () {
        reportModal.classList.remove('hidden');
        reportModal.style.display = 'flex';
        document.body.classList.add('modal-open');
    };
    const closeReportModal = function () {
        reportModal.style.display = 'none';
        reportModal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    };
    document.querySelectorAll('.js-open-validation-report').forEach(function (button) {
        button.addEventListener('click', function () {
            reportFindingId.value = button.dataset.findingId || '';
            reportFindingText.textContent = button.dataset.findingText || '';
            reporterReason.value = '';
            openReportModal();
            reporterReason.focus();
        });
    });
    document.querySelectorAll('.js-close-validation-report').forEach(function (button) {
        button.addEventListener('click', closeReportModal);
    });
    reportModal.addEventListener('click', function (event) {
        if (event.target === reportModal) closeReportModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !reportModal.classList.contains('hidden')) closeReportModal();
    });
    if (window.jQuery && $.fn.DataTable) {
        $('#specialistOperationRecapTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[7, 'desc'], [0, 'asc']]
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
