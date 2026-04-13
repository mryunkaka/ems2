<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_require_division_access(['Specialist Medical Authority'], '/dashboard/index.php');
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Detail Riwayat Operasi Medis';
$errors = $_SESSION['flash_errors'] ?? [];
$messages = $_SESSION['flash_messages'] ?? [];
unset($_SESSION['flash_errors'], $_SESSION['flash_messages']);

$userId = (int) ($_GET['user_id'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
$hasVisibilityScope = ems_column_exists($pdo, 'medical_records', 'visibility_scope');
$hasRecordCode = ems_column_exists($pdo, 'medical_records', 'record_code');
$hasAssistantsTable = ems_table_exists($pdo, 'medical_record_assistants');

function specialistOperationRecordsRecordCode(array $row, bool $hasRecordCode): string
{
    $recordCode = $hasRecordCode ? trim((string) ($row['record_code'] ?? '')) : '';
    if ($recordCode !== '') {
        return $recordCode;
    }

    return 'MR-' . str_pad((string) ((int) ($row['id'] ?? 0)), 6, '0', STR_PAD_LEFT);
}

function specialistOperationRecordsRoleText(array $roles): string
{
    $labels = [];
    foreach ($roles as $role) {
        $labels[] = $role === 'dpjp' ? 'DPJP' : 'Asisten';
    }

    $labels = array_values(array_unique($labels));
    return $labels !== [] ? implode(', ', $labels) : '-';
}

function specialistOperationRecordsOperationTitle(array $row): string
{
    $type = strtolower((string) ($row['operasi_type'] ?? 'minor')) === 'major' ? 'Mayor' : 'Minor';
    return 'Operasi ' . $type;
}

function specialistOperationRecordsAssistantMap(PDO $pdo, array $recordIds, bool $hasAssistantsTable): array
{
    $map = [];
    $recordIds = array_values(array_unique(array_filter(array_map('intval', $recordIds), static fn (int $id): bool => $id > 0)));
    if ($recordIds === []) {
        return $map;
    }

    if ($hasAssistantsTable) {
        $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
        $stmt = $pdo->prepare("
            SELECT
                mra.medical_record_id,
                GROUP_CONCAT(u.full_name ORDER BY mra.sort_order ASC, u.full_name ASC SEPARATOR ', ') AS assistant_names
            FROM medical_record_assistants mra
            INNER JOIN user_rh u ON u.id = mra.assistant_user_id
            WHERE mra.medical_record_id IN ($placeholders)
            GROUP BY mra.medical_record_id
        ");
        $stmt->execute($recordIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) ($row['medical_record_id'] ?? 0)] = trim((string) ($row['assistant_names'] ?? ''));
        }
    }

    $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
    $stmt = $pdo->prepare("
        SELECT
            r.id AS medical_record_id,
            COALESCE(a.full_name, '') AS assistant_names
        FROM medical_records r
        LEFT JOIN user_rh a ON a.id = r.assistant_id
        WHERE r.id IN ($placeholders)
    ");
    $stmt->execute($recordIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $recordId = (int) ($row['medical_record_id'] ?? 0);
        if ($recordId <= 0 || (isset($map[$recordId]) && $map[$recordId] !== '')) {
            continue;
        }

        $map[$recordId] = trim((string) ($row['assistant_names'] ?? ''));
    }

    return $map;
}

$staff = null;
$operationRows = [];
$summary = [
    'total_operations' => 0,
    'dpjp_total' => 0,
    'assistant_total' => 0,
    'major_total' => 0,
    'minor_total' => 0,
];

try {
    if ($userId <= 0) {
        throw new RuntimeException('Tenaga medis tidak valid.');
    }

    $staffStmt = $pdo->prepare("
        SELECT id, full_name, position, division
        FROM user_rh
        WHERE id = ?
        LIMIT 1
    ");
    $staffStmt->execute([$userId]);
    $staff = $staffStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$staff || !ems_is_medical_position((string) ($staff['position'] ?? ''))) {
        throw new RuntimeException('Tenaga medis tidak ditemukan atau bukan posisi medis.');
    }

    $scopeWhere = $hasVisibilityScope
        ? "COALESCE(r.visibility_scope, 'standard') = 'standard'"
        : '1=1';

    $roleAssignments = [];

    $doctorStmt = $pdo->prepare("
        SELECT r.id AS medical_record_id, 'dpjp' AS role_key
        FROM medical_records r
        WHERE {$scopeWhere} AND r.doctor_id = ?
    ");
    $doctorStmt->execute([$userId]);
    foreach ($doctorStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $recordId = (int) ($row['medical_record_id'] ?? 0);
        if ($recordId > 0) {
            $roleAssignments[$recordId][] = 'dpjp';
        }
    }

    if ($hasAssistantsTable) {
        $assistantStmt = $pdo->prepare("
            SELECT r.id AS medical_record_id, 'assistant' AS role_key
            FROM medical_record_assistants mra
            INNER JOIN medical_records r ON r.id = mra.medical_record_id
            WHERE {$scopeWhere} AND mra.assistant_user_id = ?
        ");
    } else {
        $assistantStmt = $pdo->prepare("
            SELECT r.id AS medical_record_id, 'assistant' AS role_key
            FROM medical_records r
            WHERE {$scopeWhere} AND r.assistant_id = ?
        ");
    }
    $assistantStmt->execute([$userId]);
    foreach ($assistantStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $recordId = (int) ($row['medical_record_id'] ?? 0);
        if ($recordId > 0) {
            $roleAssignments[$recordId][] = 'assistant';
        }
    }

    $recordIds = array_keys($roleAssignments);
    if ($recordIds !== []) {
        $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
        $recordStmt = $pdo->prepare("
            SELECT
                r.*,
                d.full_name AS doctor_name,
                d.position AS doctor_position
            FROM medical_records r
            LEFT JOIN user_rh d ON d.id = r.doctor_id
            WHERE r.id IN ($placeholders)
            ORDER BY r.created_at DESC, r.id DESC
        ");
        $recordStmt->execute($recordIds);
        $records = $recordStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $assistantMap = specialistOperationRecordsAssistantMap($pdo, $recordIds, $hasAssistantsTable);
        $searchNeedle = mb_strtolower($search);

        foreach ($records as $record) {
            $recordId = (int) ($record['id'] ?? 0);
            $roles = array_values(array_unique($roleAssignments[$recordId] ?? []));
            if ($roles === []) {
                continue;
            }

            $assistantNames = trim((string) ($assistantMap[$recordId] ?? ''));
            $roleText = specialistOperationRecordsRoleText($roles);
            $operationType = strtolower((string) ($record['operasi_type'] ?? 'minor')) === 'major' ? 'major' : 'minor';
            $operationTitle = specialistOperationRecordsOperationTitle($record);
            $recordCode = specialistOperationRecordsRecordCode($record, $hasRecordCode);

            $haystack = mb_strtolower(implode(' ', [
                $operationTitle,
                (string) ($record['patient_name'] ?? ''),
                (string) ($record['patient_citizen_id'] ?? ''),
                (string) ($record['patient_occupation'] ?? ''),
                $roleText,
                (string) ($record['doctor_name'] ?? ''),
                $assistantNames,
                $recordCode,
            ]));

            if ($searchNeedle !== '' && !str_contains($haystack, $searchNeedle)) {
                continue;
            }

            $operationRows[] = [
                'id' => $recordId,
                'record_code' => $recordCode,
                'operation_title' => $operationTitle,
                'patient_name' => (string) ($record['patient_name'] ?? ''),
                'patient_citizen_id' => (string) ($record['patient_citizen_id'] ?? ''),
                'patient_occupation' => (string) ($record['patient_occupation'] ?? ''),
                'patient_gender' => (string) ($record['patient_gender'] ?? ''),
                'created_at' => (string) ($record['created_at'] ?? ''),
                'operation_type' => $operationType,
                'role_text' => $roleText,
                'doctor_name' => (string) ($record['doctor_name'] ?? ''),
                'doctor_position' => (string) ($record['doctor_position'] ?? ''),
                'assistant_names' => $assistantNames !== '' ? $assistantNames : '-',
                'view_url' => 'rekam_medis_view.php?id=' . $recordId,
            ];

            $summary['total_operations']++;
            if (in_array('dpjp', $roles, true)) {
                $summary['dpjp_total']++;
            }
            if (in_array('assistant', $roles, true)) {
                $summary['assistant_total']++;
            }
            if ($operationType === 'major') {
                $summary['major_total']++;
            } else {
                $summary['minor_total']++;
            }
        }
    }
} catch (Throwable $exception) {
    $errors[] = 'Gagal memuat detail operasi medis: ' . $exception->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex justify-between items-center gap-4 mb-4">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Daftar rekam medis operasi yang pernah ditangani tenaga medis terpilih, lengkap dengan peran, DPJP, asisten, dan nomor rekam medis.</p>
            </div>
            <a href="specialist_operation_recap.php" class="btn-secondary">
                <?= ems_icon('chevron-left', 'h-4 w-4') ?>
                <span>Kembali ke Rekap</span>
            </a>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if ($staff): ?>
            <div class="card card-section mb-4">
                <div class="card-body">
                    <div class="flex justify-between items-start gap-4 flex-wrap">
                        <div>
                            <div class="page-title text-2xl mb-1"><?= htmlspecialchars((string) ($staff['full_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-sm text-gray-600">
                                <?= htmlspecialchars(ems_position_label((string) ($staff['position'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($staff['division'])): ?>
                                    • <?= htmlspecialchars((string) $staff['division'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-sm text-gray-600">
                            Total rekam medis operasi: <strong><?= (int) $summary['total_operations'] ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stats-grid mb-4">
                <?php
                ems_component('ui/statistic-card', ['label' => 'Total Rekam Medis', 'value' => number_format((int) $summary['total_operations']), 'icon' => 'clipboard-document-list', 'tone' => 'primary']);
                ems_component('ui/statistic-card', ['label' => 'Sebagai DPJP', 'value' => number_format((int) $summary['dpjp_total']), 'icon' => 'check-circle', 'tone' => 'success']);
                ems_component('ui/statistic-card', ['label' => 'Sebagai Asisten', 'value' => number_format((int) $summary['assistant_total']), 'icon' => 'user-group', 'tone' => 'warning']);
                ems_component('ui/statistic-card', ['label' => 'Mayor / Minor', 'value' => number_format((int) $summary['major_total']) . ' / ' . number_format((int) $summary['minor_total']), 'icon' => 'scale', 'tone' => 'danger']);
                ?>
            </div>
        <?php endif; ?>

        <div class="card card-section mb-4">
            <div class="card-body">
                <form method="GET" action="" class="flex gap-2">
                    <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                    <input type="text" name="search" class="form-input flex-1" placeholder="Cari judul operasi, pasien, peran, DPJP, asisten, atau no rekam medis..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn-primary">
                        <?= ems_icon('magnifying-glass', 'h-4 w-4') ?>
                        <span>Cari</span>
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="specialist_operation_records.php?user_id=<?= (int) $userId ?>" class="btn-secondary">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card card-section">
            <div class="card-header">Riwayat Rekam Medis Operasi</div>
            <div class="card-body">
                <?php if ($operationRows === []): ?>
                    <div class="text-center py-8 text-gray-500">Belum ada rekam medis operasi yang cocok.</div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table id="specialistOperationRecordsTable" class="table-custom w-full">
                            <thead>
                                <tr>
                                    <th class="text-left">Judul Operasi</th>
                                    <th class="text-left">Nama Pasien</th>
                                    <th class="text-left">Tanggal Operasi</th>
                                    <th class="text-left">Sebagai</th>
                                    <th class="text-left">DPJP</th>
                                    <th class="text-left">Asisten</th>
                                    <th class="text-left">No. Rekam Medis</th>
                                    <th class="text-left">Citizen ID</th>
                                    <th class="text-left">Pekerjaan</th>
                                    <th class="text-left">Jenis Operasi</th>
                                    <th class="text-center">Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($operationRows as $row): ?>
                                    <tr>
                                        <td class="font-semibold"><?= htmlspecialchars((string) $row['operation_title'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <div class="font-medium"><?= htmlspecialchars((string) $row['patient_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars((string) $row['patient_gender'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td><?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="badge-info"><?= htmlspecialchars((string) $row['role_text'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td>
                                            <div class="font-medium"><?= htmlspecialchars((string) $row['doctor_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars((string) $row['doctor_position'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td><?= htmlspecialchars((string) $row['assistant_names'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="font-semibold"><?= htmlspecialchars((string) $row['record_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($row['patient_citizen_id'] !== '' ? $row['patient_citizen_id'] : '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($row['patient_occupation'] !== '' ? $row['patient_occupation'] : '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <span class="<?= $row['operation_type'] === 'major' ? 'badge-danger' : 'badge-warning' ?>">
                                                <?= htmlspecialchars($row['operation_type'] === 'major' ? 'Mayor' : 'Minor', ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <a href="<?= htmlspecialchars((string) $row['view_url'], ENT_QUOTES, 'UTF-8') ?>" class="btn-primary btn-sm action-icon-btn" title="Lihat detail rekam medis" aria-label="Lihat detail rekam medis">
                                                <?= ems_icon('arrow-top-right-on-square', 'h-4 w-4') ?>
                                            </a>
                                        </td>
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
        $('#specialistOperationRecordsTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[2, 'desc'], [1, 'asc']]
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
