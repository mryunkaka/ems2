<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_require_division_access(['Specialist Medical Authority'], '/dashboard/index.php');
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

        <div class="card card-section">
            <div class="card-header">Daftar Rekap Operasi Medis</div>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
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
