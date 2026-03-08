<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Rekap Rekam Medis | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];

// Get pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get search keyword
$search = trim($_GET['search'] ?? '');

// Build query
$whereClause = '1=1';
$params = [];

if ($search !== '') {
    $whereClause .= ' AND (r.patient_name LIKE ? OR r.patient_occupation LIKE ?)';
    $params = ["%$search%", "%$search%"];
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

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="page-title">Rekap Rekam Medis</h1>
                <p class="page-subtitle">Daftar semua rekam medis pasien</p>
            </div>
            <a href="rekam_medis.php" class="btn-primary">
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
                           placeholder="Cari nama pasien atau pekerjaan..." 
                           value="<?= htmlspecialchars($search) ?>" />
                    <button type="submit" class="btn-primary">
                        <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        Cari
                    </button>
                    <?php if ($search): ?>
                        <a href="rekam_medis_list.php" class="btn-secondary">Reset</a>
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
                        <p class="text-gray-500">Belum ada rekam medis</p>
                        <a href="rekam_medis.php" class="btn-primary mt-4">Tambah Rekam Medis Pertama</a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table-custom w-full">
                            <thead>
                                <tr>
                                    <th class="text-left">Tanggal Dibuat</th>
                                    <th class="text-left">Nama Pasien</th>
                                    <th class="text-left">Pekerjaan</th>
                                    <th class="text-left">Jenis Kelamin</th>
                                    <th class="text-left">Dokter DPJP</th>
                                    <th class="text-left">Jenis Operasi</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $record): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td>
                                            <?= date('d/m/Y H:i', strtotime($record['created_at'])) ?>
                                        </td>
                                        <td class="font-semibold">
                                            <?= htmlspecialchars($record['patient_name']) ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($record['patient_occupation']) ?>
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
                                                <a href="rekam_medis_edit.php?id=<?= $record['id'] ?>" 
                                                   class="btn-primary btn-sm" 
                                                   title="Edit">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                </a>
                                                <button onclick="confirmDelete(<?= $record['id'] ?>, '<?= htmlspecialchars($record['patient_name'], ENT_QUOTES) ?>')" 
                                                        class="btn-error btn-sm" 
                                                        title="Hapus">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
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
                                    <a href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>" 
                                       class="btn-secondary btn-sm">
                                        <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                        </svg>
                                        Sebelumnya
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php if ($i == 1 || $i == $totalPages || abs($i - $page) <= 2): ?>
                                        <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>" 
                                           class="btn-sm <?= $i == $page ? 'btn-primary' : 'btn-secondary' ?>">
                                            <?= $i ?>
                                        </a>
                                    <?php elseif (abs($i - $page) == 3): ?>
                                        <span class="px-2">...</span>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>" 
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

<script>
function confirmDelete(id, name) {
    if (confirm(`Apakah Anda yakin ingin menghapus rekam medis pasien "${name}"?\n\nData yang dihapus tidak dapat dikembalikan.`)) {
        window.location.href = `rekam_medis_delete.php?id=${id}`;
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
