<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// =======================
// GUARD ROLE (KECUALI STAFF)
// =======================
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}

$pageTitle = 'Validasi User';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// =======================
// QUERY SEMUA USER
// =======================
$stmt = $pdo->query("
    SELECT 
        id,
        full_name,
        role,
        position,
        is_verified,
        created_at
    FROM user_rh
    ORDER BY 
        is_verified ASC,       -- 0 (belum valid) di atas
        created_at DESC        -- yang terbaru lebih dulu
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<section class="content">
    <div class="page" style="max-width:1200px;margin:auto;">
        <h1>Validasi User</h1>

        <p style="font-size:13px;color:#9ca3af;">
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
                                <td><?= htmlspecialchars($u['position']) ?></td>

                                <td>
                                    <?php if ((int)$u['is_verified'] === 1): ?>
                                        <div class="status-box verified">
                                            <span class="icon">✔</span>
                                            Terverifikasi
                                        </div>
                                    <?php else: ?>
                                        <div class="status-box pending">
                                            <span class="icon">⏳</span>
                                            Belum Verifikasi
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td><?= date('d-m-Y H:i', strtotime($u['created_at'])) ?></td>

                                <td>
                                    <?php if ((int)$u['is_verified'] === 0): ?>
                                        <a href="/dashboard/validasi_action.php?id=<?= $u['id'] ?>&act=approve"
                                            class="btn btn-sm btn-success"
                                            onclick="return confirm('Verifikasi user ini?')">
                                            Validasi
                                        </a>
                                    <?php else: ?>
                                        <a href="/dashboard/validasi_action.php?id=<?= $u['id'] ?>&act=reject"
                                            class="btn btn-sm btn-danger"
                                            onclick="return confirm('Batalkan verifikasi user ini?')">
                                            Batalkan
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
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>