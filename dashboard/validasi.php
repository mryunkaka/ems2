<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

// =======================
// GUARD ROLE (KECUALI STAFF)
// =======================
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$hasUnitCodeColumn = ems_column_exists($pdo, 'user_rh', 'unit_code');

$pageTitle = 'Validasi User';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// Sinkronisasi data lama:
// user yang sudah terverifikasi tetapi belum aktif akan diaktifkan,
// kecuali yang memang sudah resign/nonaktif permanen.
try {
    $sqlSync = "
        UPDATE user_rh
        SET is_active = 1
        WHERE is_verified = 1
          AND is_active = 0
          AND (resigned_at IS NULL OR resigned_at = '0000-00-00 00:00:00')
          " . ($hasUnitCodeColumn ? " AND COALESCE(unit_code, 'roxwood') = :unit_code" : "") . "
    ";
    $stmtSync = $pdo->prepare($sqlSync);
    $stmtSync->execute($hasUnitCodeColumn ? [':unit_code' => $effectiveUnit] : []);
} catch (Throwable $e) {
    // Abaikan agar halaman tetap bisa dibuka.
}

// =======================
// QUERY SEMUA USER
// =======================
$stmt = $pdo->prepare("
    SELECT 
        id,
        full_name,
        role,
        position,
        is_verified,
        is_active,
        created_at
    FROM user_rh
    WHERE 1=1
      " . ($hasUnitCodeColumn ? " AND COALESCE(unit_code, 'roxwood') = :unit_code" : "") . "
    ORDER BY 
        is_verified ASC,       -- 0 (belum valid) di atas
        created_at DESC        -- yang terbaru lebih dulu
");
$stmt->execute($hasUnitCodeColumn ? [':unit_code' => $effectiveUnit] : []);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Validasi User</h1>

        <p class="page-subtitle">
            Halaman ini digunakan untuk memverifikasi akun user baru
        </p>

        <div class="card">
            <div class="card-header">
                Daftar User Terdaftar
            </div>

            <div class="table-wrapper">
                <table id="validasiTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Role</th>
                            <th>Jabatan</th>
                            <th>Status</th>
                            <th>Tanggal Daftar</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $i => $u): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($u['full_name']) ?></td>
                                <td><?= htmlspecialchars($u['role']) ?></td>
                                <td><?= htmlspecialchars(ems_position_label($u['position'])) ?></td>

	                                <td>
	                                    <?php if ((int)$u['is_verified'] === 1 && (int)$u['is_active'] === 1): ?>
	                                        <div class="status-box verified">
	                                            <span class="icon"><?= ems_icon('check-circle', 'h-4 w-4') ?></span>
	                                            Terverifikasi
	                                        </div>
	                                    <?php elseif ((int)$u['is_verified'] === 1): ?>
	                                        <div class="status-box pending">
	                                            <span class="icon"><?= ems_icon('clock', 'h-4 w-4') ?></span>
	                                            Verifikasi OK, belum aktif
	                                        </div>
	                                    <?php else: ?>
	                                        <div class="status-box pending">
	                                            <span class="icon"><?= ems_icon('clock', 'h-4 w-4') ?></span>
	                                            Belum Verifikasi
	                                        </div>
	                                    <?php endif; ?>
	                                </td>

                                <td><?= date('d-m-Y H:i', strtotime($u['created_at'])) ?></td>

	                                <td>
	                                    <?php if ((int)$u['is_verified'] === 0): ?>
	                                        <a href="/dashboard/validasi_action.php?id=<?= $u['id'] ?>&act=approve"
	                                            class="btn-success btn-compact action-icon-btn"
	                                            onclick="return confirm('Verifikasi user ini?')"
	                                            title="Validasi user"
	                                            aria-label="Validasi user">
	                                            <?= ems_icon('check-circle', 'h-4 w-4') ?>
	                                        </a>
	                                    <?php else: ?>
	                                        <a href="/dashboard/validasi_action.php?id=<?= $u['id'] ?>&act=reject"
	                                            class="btn-danger btn-compact action-icon-btn"
	                                            onclick="return confirm('Batalkan verifikasi user ini?')"
	                                            title="Batalkan validasi user"
	                                            aria-label="Batalkan validasi user">
	                                            <?= ems_icon('x-mark', 'h-4 w-4') ?>
	                                        </a>
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
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#validasiTable').DataTable({
                pageLength: 10,
                language: {
                    url: '/assets/design/js/datatables-id.json'
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
