<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Disciplinary Committee'], '/dashboard/index.php');
$pageTitle = 'Validasi Pengurangan Poin';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);
$rows = [];

try {
    $stmt = $pdo->query("SELECT r.*, subject.full_name AS subject_name, submitter.full_name AS submitter_name, dc.case_code, mr.record_code, mr.patient_name FROM disciplinary_point_reduction_requests r INNER JOIN user_rh subject ON subject.id = r.subject_user_id INNER JOIN user_rh submitter ON submitter.id = r.submitted_by LEFT JOIN disciplinary_cases dc ON dc.id = r.related_case_id LEFT JOIN medical_records mr ON mr.id = r.medical_record_id WHERE r.status = 'pending' ORDER BY r.created_at ASC LIMIT 500");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat validasi pengurangan poin: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content"><div class="page page-shell">
    <?php foreach ($messages as $message): ?><div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
    <div class="page-header mb-4"><div><h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1><p class="page-subtitle">COMDIS memeriksa kegiatan medis. Approval membuat transaksi pengurangan poin dan memperbarui saldo otomatis.</p></div></div>
    <div class="card"><div class="card-header">Pengajuan Menunggu Validasi</div><div class="table-wrapper"><table class="table-custom"><thead><tr><th>Medis</th><th>Kegiatan</th><th>Tanggal</th><th>Bukti / Catatan</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><tr><td><strong><?= htmlspecialchars((string)$row['subject_name'], ENT_QUOTES, 'UTF-8') ?></strong><div class="meta-text-xs">Diajukan oleh: <?= htmlspecialchars((string)$row['submitter_name'], ENT_QUOTES, 'UTF-8') ?></div></td><td><?= htmlspecialchars(ems_disciplinary_point_reduction_label((string)$row['reduction_type']), ENT_QUOTES, 'UTF-8') ?><div class="meta-text-xs">-<?= (int)$row['reduction_points'] ?> poin</div></td><td><?= htmlspecialchars(formatTanggalIndo((string)$row['activity_date']), ENT_QUOTES, 'UTF-8') ?></td><td><?php if (!empty($row['record_code'])): ?><div><strong>Rekam: <?= htmlspecialchars((string)$row['record_code'], ENT_QUOTES, 'UTF-8') ?></strong></div><div class="meta-text-xs">Pasien: <?= htmlspecialchars((string)($row['patient_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><?= nl2br(htmlspecialchars((string)($row['notes'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></td><td><form method="POST" action="disciplinary_point_reduction_request_action.php" class="form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="redirect_to" value="disciplinary_point_reduction_validation.php"><textarea name="review_notes" rows="2" placeholder="Catatan validasi"></textarea><div class="modal-actions mt-2"><button name="action" value="approve_request" class="btn-success">Setujui</button><button name="action" value="reject_request" class="btn-danger">Tolak</button></div></form></td></tr><?php endforeach; ?>
    </tbody></table><?php if ($rows === []): ?><div class="muted-placeholder p-4">Tidak ada pengajuan menunggu validasi.</div><?php endif; ?></div></div>
</div></section>
<?php include __DIR__ . '/../partials/footer.php'; ?>