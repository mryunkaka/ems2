<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

// Block access for users on cuti
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$pageTitle = 'Rekap Rekam Medis | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$mode = trim($_GET['mode'] ?? 'standard');
$isForensicPrivate = ($mode === 'forensic_private');

if ($isForensicPrivate) {
    ems_require_division_access(['Forensic'], '/dashboard/index.php');
}

function medicalRecordsHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM medical_records LIKE ?");
    $stmt->execute([$column]);
    $cache[$column] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

function medicalRecordValue(mixed $value, string $fallback = '-'): string
{
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

// Get pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get search keyword
$search = trim($_GET['search'] ?? '');

// Build query
$hasVisibilityScope = medicalRecordsHasColumn($pdo, 'visibility_scope');
$hasRecordCode = medicalRecordsHasColumn($pdo, 'record_code');
$hasPatientCitizenId = medicalRecordsHasColumn($pdo, 'patient_citizen_id');

$whereClause = '1=1';
$migrationMissing = false;

if ($hasVisibilityScope) {
    $whereClause .= $isForensicPrivate
        ? " AND COALESCE(r.visibility_scope, 'standard') = 'forensic_private'"
        : " AND COALESCE(r.visibility_scope, 'standard') = 'standard'";
} elseif ($isForensicPrivate) {
    $whereClause .= " AND 1=0";
    $migrationMissing = true;
}
$params = [];

if ($search !== '') {
    $searchParts = [
        'r.patient_name LIKE ?',
        'r.patient_occupation LIKE ?',
    ];
    $params = ["%$search%", "%$search%"];

    if ($hasPatientCitizenId) {
        $searchParts[] = "COALESCE(r.patient_citizen_id, '') LIKE ?";
        $params[] = "%$search%";
    }

    if ($hasRecordCode) {
        $searchParts[] = "COALESCE(r.record_code, '') LIKE ?";
        $params[] = "%$search%";
    }

    $whereClause .= ' AND (' . implode(' OR ', $searchParts) . ')';
}

// Get total records
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM medical_records r WHERE $whereClause");
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// Get records with joins
$stmt = $pdo->prepare("
    SELECT 
        r.*,
        u.full_name AS doctor_name,
        u.position AS doctor_position,
        a.full_name AS assistant_name,
        a.position AS assistant_position,
        c.full_name AS created_by_name
    FROM medical_records r
    LEFT JOIN user_rh u ON u.id = r.doctor_id
    LEFT JOIN user_rh a ON a.id = r.assistant_id
    LEFT JOIN user_rh c ON c.id = r.created_by
    WHERE $whereClause
    ORDER BY r.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

if ($migrationMissing) {
    $errors[] = 'Kolom rekam medis private belum tersedia. Jalankan SQL `docs/sql/06_2026-03-11_forensic_private_medical_records.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="page-title"><?= $isForensicPrivate ? 'Rekap Rekam Medis Private' : 'Rekap Rekam Medis' ?></h1>
                <p class="page-subtitle"><?= $isForensicPrivate ? 'Daftar rekam medis private yang hanya bisa diakses division forensic' : 'Daftar semua rekam medis pasien' ?></p>
            </div>
            <a href="<?= $isForensicPrivate ? 'forensic_medical_records.php' : 'rekam_medis.php' ?>" class="btn-primary">
                <svg class="w-5 h-5 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Tambah Rekam Medis
            </a>
        </div>

        <!-- Flash Messages -->
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <!-- Search -->
        <div class="card card-section mb-4">
            <div class="card-body">
                <form method="GET" action="" class="flex gap-2">
                    <input type="text" name="search" class="form-input flex-1" 
                           placeholder="Cari nama pasien, citizen ID, atau no rekam medis..." 
                           value="<?= htmlspecialchars($search) ?>" />
                    <button type="submit" class="btn-primary">
                        <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        Cari
                    </button>
                    <?php if ($search): ?>
                        <a href="<?= $isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php' ?>" class="btn-secondary">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Table -->
        <div class="card card-section">
            <div class="card-header">Daftar Rekam Medis</div>
            <div class="card-body">
                <?php if (empty($records)): ?>
                    <div class="text-center py-8">
                        <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <p class="text-gray-500"><?= $isForensicPrivate ? 'Belum ada rekam medis private' : 'Belum ada rekam medis' ?></p>
                        <a href="<?= $isForensicPrivate ? 'forensic_medical_records.php' : 'rekam_medis.php' ?>" class="btn-primary mt-4">Tambah Rekam Medis Pertama</a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table-custom w-full">
                            <thead>
                                <tr>
                                    <th class="text-left">Tanggal Dibuat</th>
                                    <th class="text-left">No. Rekam Medis</th>
                                    <th class="text-left">Nama Pasien</th>
                                    <th class="text-left">Pekerjaan</th>
                                    <th class="text-left">Citizen ID</th>
                                    <th class="text-left">Jenis Kelamin</th>
                                    <th class="text-left">Dokter DPJP</th>
                                    <th class="text-left">Jenis Operasi</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $record): ?>
                                    <?php
                                    $canEditRecord = ($record['visibility_scope'] ?? 'standard') === 'forensic_private'
                                        ? ems_can_access_division_menu(ems_normalize_division($user['division'] ?? ''), 'Forensic')
                                        : (int) ($record['created_by'] ?? 0) === (int) ($user['id'] ?? 0);
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td>
                                            <?= date('d/m/Y H:i', strtotime($record['created_at'])) ?>
                                        </td>
                                        <td class="font-semibold">
                                            <?php $recordCode = (string)(($hasRecordCode ? ($record['record_code'] ?? null) : null) ?: ('MR-' . str_pad((string)$record['id'], 6, '0', STR_PAD_LEFT))); ?>
                                            <a href="<?= $isForensicPrivate ? 'forensic_medical_records_view.php' : 'rekam_medis_view.php' ?>?id=<?= (int)$record['id'] ?><?= $isForensicPrivate ? '&mode=forensic_private' : '' ?>" class="text-primary hover:underline">
                                                <?= htmlspecialchars($recordCode, ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </td>
                                        <td class="font-semibold">
                                            <?= htmlspecialchars($record['patient_name']) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($record['patient_occupation']) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars((string)(($hasPatientCitizenId ? ($record['patient_citizen_id'] ?? null) : null) ?: '-')) ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= $record['patient_gender'] === 'Laki-laki' ? 'info' : 'pink' ?>">
                                                <?= htmlspecialchars($record['patient_gender']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="text-sm">
                                                <div class="font-medium"><?= htmlspecialchars($record['doctor_name'] ?? '-') ?></div>
                                                <div class="text-gray-500 text-xs"><?= htmlspecialchars($record['doctor_position'] ?? '') ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= $record['operasi_type'] === 'major' ? 'error' : 'warning' ?>">
                                                <?= $record['operasi_type'] === 'major' ? 'Mayor' : 'Minor' ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="flex justify-center gap-2">
                                                <button
                                                    type="button"
                                                    class="btn-secondary btn-sm btn-medical-record-detail"
                                                    data-modal-title="<?= htmlspecialchars('Detail Rekam Medis ' . $recordCode, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-modal-subtitle="<?= htmlspecialchars($isForensicPrivate ? 'Review keseluruhan rekam medis private forensic.' : 'Review keseluruhan rekam medis pasien.', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-template-id="medical-record-detail-<?= (int) $record['id'] ?>"
                                                    title="Detail">
                                                    <?= ems_icon('eye', 'h-4 w-4') ?>
                                                    <span>Detail</span>
                                                </button>
                                                <?php if ($canEditRecord): ?>
                                                    <a href="rekam_medis_edit.php?id=<?= $record['id'] ?><?= $isForensicPrivate ? '&mode=forensic_private' : '' ?>" 
                                                       class="btn-primary btn-sm" 
                                                       title="Edit">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </a>
                                                    <button onclick="confirmDelete(<?= $record['id'] ?>, '<?= htmlspecialchars($record['patient_name'], ENT_QUOTES) ?>', '<?= $isForensicPrivate ? 'forensic_private' : 'standard' ?>')" 
                                                            class="btn-error btn-sm" 
                                                            title="Hapus">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-400">View only</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <template id="medical-record-detail-<?= (int) $record['id'] ?>">
                                        <div class="forensic-detail-shell">
                                            <div class="forensic-detail-hero">
                                                <div class="forensic-detail-panel">
                                                    <div class="forensic-detail-label">Identitas Pasien</div>
                                                    <div class="forensic-detail-value"><?= htmlspecialchars(medicalRecordValue($record['patient_name']), ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="forensic-detail-meta">
                                                        No. rekam medis: <?= htmlspecialchars($recordCode, ENT_QUOTES, 'UTF-8') ?><br>
                                                        Citizen ID: <?= htmlspecialchars(medicalRecordValue($record['patient_citizen_id'] ?? null), ENT_QUOTES, 'UTF-8') ?><br>
                                                        Dibuat: <?= htmlspecialchars(medicalRecordValue($record['created_at']), ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                </div>
                                                <div class="forensic-detail-panel">
                                                    <div class="forensic-detail-label">Tim Medis</div>
                                                    <div class="forensic-detail-badges">
                                                        <span class="badge-info"><?= htmlspecialchars(medicalRecordValue($record['patient_gender']), ENT_QUOTES, 'UTF-8') ?></span>
                                                        <span class="<?= ($record['operasi_type'] ?? '') === 'major' ? 'badge-danger' : 'badge-warning' ?>">
                                                            <?= htmlspecialchars(($record['operasi_type'] ?? '') === 'major' ? 'MAYOR' : 'MINOR', ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    </div>
                                                    <div class="forensic-detail-meta">
                                                        DPJP: <?= htmlspecialchars(medicalRecordValue($record['doctor_name'] ?? null), ENT_QUOTES, 'UTF-8') ?><br>
                                                        Asisten: <?= htmlspecialchars(medicalRecordValue($record['assistant_name'] ?? null), ENT_QUOTES, 'UTF-8') ?><br>
                                                        Dibuat oleh: <?= htmlspecialchars(medicalRecordValue($record['created_by_name'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="forensic-detail-grid">
                                                <div class="forensic-detail-block">
                                                    <div class="forensic-detail-label">Pekerjaan</div>
                                                    <div class="forensic-detail-value"><?= htmlspecialchars(medicalRecordValue($record['patient_occupation']), ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="forensic-detail-block">
                                                    <div class="forensic-detail-label">Tanggal Lahir</div>
                                                    <div class="forensic-detail-value"><?= htmlspecialchars(medicalRecordValue($record['patient_dob'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="forensic-detail-block">
                                                    <div class="forensic-detail-label">Nomor Telepon</div>
                                                    <div class="forensic-detail-value"><?= htmlspecialchars(medicalRecordValue($record['patient_phone'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                                <div class="forensic-detail-block">
                                                    <div class="forensic-detail-label">Status Pasien</div>
                                                    <div class="forensic-detail-value"><?= htmlspecialchars(medicalRecordValue($record['patient_status'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                            </div>

                                            <div class="forensic-detail-block">
                                                <div class="forensic-detail-label">Alamat</div>
                                                <div class="forensic-detail-value"><?= htmlspecialchars(medicalRecordValue($record['patient_address'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>

                                            <div class="forensic-detail-block is-richtext">
                                                <div class="forensic-detail-label">Hasil Rekam Medis</div>
                                                <div class="forensic-detail-richtext">
                                                    <?= $record['medical_result_html'] ?: '<p class="forensic-detail-value is-muted">Belum ada hasil rekam medis.</p>' ?>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="flex justify-between items-center mt-4">
                            <div class="text-sm text-gray-600">
                                Menampilkan <?= $offset + 1 ?> - <?= min($offset + $perPage, $totalRecords) ?> dari <?= $totalRecords ?> data
                            </div>
                            <div class="flex gap-2">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $isForensicPrivate ? '&mode=forensic_private' : '' ?>" 
                                       class="btn-secondary btn-sm">
                                        <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                        </svg>
                                        Sebelumnya
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php if ($i == 1 || $i == $totalPages || abs($i - $page) <= 2): ?>
                                        <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $isForensicPrivate ? '&mode=forensic_private' : '' ?>" 
                                           class="btn-sm <?= $i == $page ? 'btn-primary' : 'btn-secondary' ?>">
                                            <?= $i ?>
                                        </a>
                                    <?php elseif (abs($i - $page) == 3): ?>
                                        <span class="px-2">...</span>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $isForensicPrivate ? '&mode=forensic_private' : '' ?>" 
                                       class="btn-secondary btn-sm">
                                        Selanjutnya
                                        <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div id="medicalRecordDetailModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg forensic-detail-modal">
        <div class="forensic-detail-head">
            <div class="min-w-0">
                <div id="medicalRecordDetailTitle" class="forensic-detail-title">Detail Rekam Medis</div>
                <div id="medicalRecordDetailSubtitle" class="forensic-detail-subtitle"></div>
            </div>
            <button type="button" class="modal-close-btn btn-medical-record-close" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div id="medicalRecordDetailBody" class="forensic-detail-content"></div>
        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary btn-medical-record-close">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, name, mode) {
    if (confirm(`Apakah Anda yakin ingin menghapus rekam medis pasien "${name}"?\n\nData yang dihapus tidak dapat dikembalikan.`)) {
        const suffix = mode === 'forensic_private' ? '&mode=forensic_private' : '';
        window.location.href = `rekam_medis_delete.php?id=${id}${suffix}`;
    }
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('medicalRecordDetailModal');
    const title = document.getElementById('medicalRecordDetailTitle');
    const subtitle = document.getElementById('medicalRecordDetailSubtitle');
    const body = document.getElementById('medicalRecordDetailBody');

    if (!modal || !title || !subtitle || !body) {
        return;
    }

    function closeModal() {
        modal.classList.add('hidden');
        body.innerHTML = '';
        document.body.classList.remove('modal-open');
    }

    document.body.addEventListener('click', function (event) {
        const trigger = event.target.closest('.btn-medical-record-detail');
        if (trigger) {
            const template = document.getElementById(trigger.getAttribute('data-template-id') || '');
            if (!template) {
                return;
            }

            title.textContent = trigger.getAttribute('data-modal-title') || 'Detail Rekam Medis';
            subtitle.textContent = trigger.getAttribute('data-modal-subtitle') || '';
            body.innerHTML = template.innerHTML;
            modal.classList.remove('hidden');
            document.body.classList.add('modal-open');
            return;
        }

        if (event.target === modal || event.target.closest('.btn-medical-record-close')) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
