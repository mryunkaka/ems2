<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Pengajuan Pengurangan Poin';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
if (ems_normalize_division($user['division'] ?? '') !== 'Medis') {
    $_SESSION['flash_errors'][] = 'Halaman pengajuan hanya tersedia untuk division Medis.';
    header('Location: /dashboard/index.php');
    exit;
}
$options = ems_disciplinary_point_reduction_options();
$caseRows = [];
$requestRows = [];
$activePoints = 0;
$canSubmit = false;

try {
    $caseStmt = $pdo->prepare('SELECT id, case_code, case_name, case_date FROM disciplinary_cases WHERE subject_user_id = ? ORDER BY case_date DESC, id DESC LIMIT 500');
    $caseStmt->execute([$userId]);
    $caseRows = $caseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $casePointsStmt = $pdo->prepare('SELECT COALESCE(SUM(total_points), 0) FROM disciplinary_cases WHERE subject_user_id = ?');
    $casePointsStmt->execute([$userId]);
    $casePoints = (int)$casePointsStmt->fetchColumn();
    $reductionStmt = $pdo->prepare('SELECT COALESCE(SUM(reduction_points), 0) FROM disciplinary_point_reductions WHERE subject_user_id = ?');
    $reductionStmt->execute([$userId]);
    $approvedReductions = (int)$reductionStmt->fetchColumn();
    $activePoints = max(0, $casePoints - $approvedReductions);
    $canSubmit = $activePoints > 0;

    $requestStmt = $pdo->prepare('SELECT r.*, reviewer.full_name AS reviewer_name, dc.case_code FROM disciplinary_point_reduction_requests r LEFT JOIN user_rh reviewer ON reviewer.id = r.reviewed_by LEFT JOIN disciplinary_cases dc ON dc.id = r.related_case_id WHERE r.subject_user_id = ? ORDER BY r.created_at DESC LIMIT 500');
    $requestStmt->execute([$userId]);
    $requestRows = $requestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat pengajuan pengurangan poin: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <?php foreach ($messages as $message): ?><div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
        <?php foreach ($errors as $error): ?><div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

        <div class="page-header mb-4">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Ajukan kegiatan pengurangan poin. COMDIS memvalidasi sebelum saldo poin berubah.</p>
            </div>
            <div class="card card-section"><div class="meta-text-xs">Poin Aktif</div><div class="text-2xl font-extrabold text-rose-700"><?= number_format($activePoints, 0, ',', '.') ?></div></div>
        </div>

        <?php if (!$canSubmit): ?>
            <div class="card mb-4"><div class="muted-placeholder p-4">Pengajuan muncul setelah akun memiliki poin pelanggaran aktif.</div></div>
        <?php else: ?>
            <div class="card mb-4">
                <div class="card-header">Form Pengajuan Kegiatan</div>
                <form method="POST" action="disciplinary_point_reduction_request_action.php" class="form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="submit_request">
                    <input type="hidden" name="redirect_to" value="disciplinary_point_reduction_requests.php">
                    <label>Jenis Kegiatan</label>
                    <select name="reduction_type" required><option value="">Pilih kegiatan</option><?php foreach ($options as $key => $option): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$option['label'], ENT_QUOTES, 'UTF-8') ?> | -<?= (int)$option['points'] ?> poin</option><?php endforeach; ?></select>
                    <label>Tanggal Kegiatan</label><input type="date" name="activity_date" value="<?= date('Y-m-d') ?>" required>
                    <label>Kasus Terkait</label><select name="related_case_id"><option value="">Tidak terkait kasus tertentu</option><?php foreach ($caseRows as $case): ?><option value="<?= (int)$case['id'] ?>"><?= htmlspecialchars((string)$case['case_code'] . ' | ' . $case['case_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                    <label>Bukti / Catatan Kegiatan</label><textarea name="notes" rows="5" required placeholder="Jelaskan kegiatan dan bukti pelaksanaan."></textarea>
                    <div class="modal-actions mt-4"><button type="submit" class="btn-success">Kirim Pengajuan</button></div>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Riwayat Pengajuan Saya</div>
            <div class="table-wrapper"><table class="table-custom"><thead><tr><th>Tanggal</th><th>Kegiatan</th><th>Poin</th><th>Status</th><th>Catatan Validasi</th></tr></thead><tbody>
            <?php foreach ($requestRows as $row): ?><tr><td><?= htmlspecialchars(formatTanggalIndo((string)$row['activity_date']), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(ems_disciplinary_point_reduction_label((string)$row['reduction_type']), ENT_QUOTES, 'UTF-8') ?><div class="meta-text-xs"><?= htmlspecialchars((string)($row['notes'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div></td><td>-<?= (int)$row['reduction_points'] ?> poin</td><td><?= htmlspecialchars(match ($row['status']) { 'approved' => 'Disetujui', 'rejected' => 'Ditolak', default => 'Menunggu Validasi' }, ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string)($row['review_notes'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?>
            </tbody></table><?php if ($requestRows === []): ?><div class="muted-placeholder p-4">Belum ada pengajuan.</div><?php endif; ?></div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../partials/footer.php'; ?>