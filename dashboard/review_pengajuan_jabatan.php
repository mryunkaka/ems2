<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}

$pageTitle = 'Review Pengajuan Jabatan';

$status = strtolower(trim($_GET['status'] ?? 'pending'));
if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $status = 'pending';
}

$requestId = (int)($_GET['id'] ?? 0);

$detail = null;
$detailOps = [];
$detailUser = null;

if ($requestId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            r.*,
            rb.full_name AS reviewed_by_name
        FROM position_promotion_requests r
        LEFT JOIN user_rh rb ON rb.id = r.reviewed_by
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
    $detail = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($detail) {
        $stmt = $pdo->prepare("
            SELECT id, full_name, position, batch, tanggal_masuk
            FROM user_rh
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$detail['user_id']]);
        $detailUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stmt = $pdo->prepare("
            SELECT sort_order, patient_name, procedure_name, dpjp, operation_role, operation_level
            FROM position_promotion_request_operations
            WHERE request_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$requestId]);
        $detailOps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.user_id,
        r.from_position,
        r.to_position,
        r.status,
        r.submitted_at,
        r.reviewed_at,
        rb.full_name AS reviewed_by_name,
        r.join_date_snapshot,
        r.batch_snapshot,
        u.full_name,
        u.position AS current_position
    FROM position_promotion_requests r
    JOIN user_rh u ON u.id = r.user_id
    LEFT JOIN user_rh rb ON rb.id = r.reviewed_by
    WHERE r.status = ?
    ORDER BY r.submitted_at DESC
    LIMIT 200
");
$stmt->execute([$status]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$m) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-warning"><?= htmlspecialchars((string)$w) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$e) ?></div>
        <?php endforeach; ?>

        <div class="card card-section">
            <div class="card-header">Filter Status</div>
            <div class="flex gap-2 flex-wrap">
                <a class="btn-secondary" href="review_pengajuan_jabatan.php?status=pending">Pending</a>
                <a class="btn-secondary" href="review_pengajuan_jabatan.php?status=approved">Approved</a>
                <a class="btn-secondary" href="review_pengajuan_jabatan.php?status=rejected">Rejected</a>
            </div>
        </div>

        <?php if ($detail): ?>
            <div class="card card-section">
                <div class="card-header">
                    Detail Pengajuan #<?= (int)$detail['id'] ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <strong>Nama</strong>
                        <div><?= htmlspecialchars($detailUser['full_name'] ?? '-') ?></div>
                    </div>
                    <div>
                        <strong>Jabatan Saat Ini (DB)</strong>
                        <div><?= htmlspecialchars(ems_position_label($detailUser['position'] ?? '')) ?></div>
                    </div>
                    <div>
                        <strong>Pengajuan</strong>
                        <div><?= htmlspecialchars(ems_position_label($detail['from_position'] ?? '')) ?> → <?= htmlspecialchars(ems_position_label($detail['to_position'] ?? '')) ?></div>
                    </div>
                    <div>
                        <strong>Status</strong>
                        <div><?= htmlspecialchars($detail['status'] ?? '-') ?></div>
                    </div>
                    <div>
                        <strong>Batch (snapshot)</strong>
                        <div><?= htmlspecialchars((string)($detail['batch_snapshot'] ?? '-')) ?></div>
                    </div>
                    <div>
                        <strong>Tanggal Masuk (snapshot)</strong>
                        <div><?= htmlspecialchars((string)($detail['join_date_snapshot'] ?? '-')) ?></div>
                    </div>
                </div>

                <?php if (!empty($detail['requirement_notes_snapshot'])): ?>
                    <div class="alert alert-warning" style="margin-top:12px;">
                        <strong>Syarat (snapshot saat submit)</strong><br>
                        <?= nl2br(htmlspecialchars($detail['requirement_notes_snapshot'])) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($detailOps)): ?>
                    <hr class="section-divider">
                    <h3 class="section-form-title">Riwayat Operasi (diinput saat pengajuan)</h3>
	                    <div class="table-wrapper-sm">
	                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nama Pasien</th>
                                    <th>Tindakan</th>
                                    <th>DPJP</th>
                                    <th>Peran</th>
                                    <th>Tingkat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($detailOps as $op): ?>
                                    <tr>
                                        <td><?= (int)$op['sort_order'] ?></td>
                                        <td><?= htmlspecialchars($op['patient_name']) ?></td>
                                        <td><?= htmlspecialchars($op['procedure_name']) ?></td>
                                        <td><?= htmlspecialchars($op['dpjp']) ?></td>
                                        <td><?= htmlspecialchars($op['operation_role'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($op['operation_level'] ?: '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (!empty($detail['case_title']) || !empty($detail['case_subject'])): ?>
                    <hr class="section-divider">
                    <h3 class="section-form-title">Laporan Kasus</h3>
                    <div><strong>Judul:</strong> <?= htmlspecialchars($detail['case_title'] ?? '-') ?></div>
                    <div style="margin-top:8px;"><strong>Perihal:</strong><br><?= nl2br(htmlspecialchars($detail['case_subject'] ?? '-')) ?></div>
                <?php endif; ?>

                <hr class="section-divider">

                <?php if (($detail['status'] ?? '') === 'pending'): ?>
                    <form method="POST" action="review_pengajuan_jabatan_action.php" class="form">
                        <?= csrfField(); ?>
                        <input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>">

                        <label>Catatan Reviewer</label>
                        <textarea name="reviewer_note" rows="3" placeholder="Catatan untuk pemohon (opsional)"></textarea>

                        <div class="flex gap-2 flex-wrap" style="margin-top:10px;">
                            <button type="submit" name="action" value="approve" class="btn-success"
                                onclick="return confirm('Approve pengajuan ini? Jabatan user akan diupdate.')">
                                <?= ems_icon('check-circle', 'h-4 w-4') ?> <span>Approve</span>
                            </button>
                            <button type="submit" name="action" value="reject" class="btn-danger"
                                onclick="return confirm('Reject pengajuan ini?')">
                                <?= ems_icon('x-mark', 'h-4 w-4') ?> <span>Reject</span>
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info">
                        Sudah diproses oleh <strong><?= htmlspecialchars((string)($detail['reviewed_by_name'] ?? '-')) ?></strong>
                        pada <strong><?= htmlspecialchars((string)($detail['reviewed_at'] ?? '-')) ?></strong>
                    </div>
                    <?php if (!empty($detail['reviewer_note'])): ?>
                        <div class="alert alert-warning">
                            <strong>Catatan Reviewer</strong><br>
                            <?= nl2br(htmlspecialchars($detail['reviewer_note'])) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card card-section">
            <div class="card-header">Daftar Pengajuan (<?= strtoupper($status) ?>)</div>
	            <div class="table-wrapper-sm">
	                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Nama</th>
                            <th>Jabatan (DB)</th>
                            <th>Pengajuan</th>
                            <th>Batch</th>
                            <th>Join</th>
                            <th>Diproses</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="8" class="muted-placeholder">Tidak ada data.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['submitted_at'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($r['full_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars(ems_position_label($r['current_position'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars(ems_position_label($r['from_position'] ?? '')) ?> → <?= htmlspecialchars(ems_position_label($r['to_position'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($r['batch_snapshot'] ?? '-')) ?></td>
                                    <td><?= htmlspecialchars((string)($r['join_date_snapshot'] ?? '-')) ?></td>
                                    <td>
                                        <?php if (!empty($r['reviewed_at'])): ?>
                                            <div><strong><?= htmlspecialchars((string)($r['reviewed_by_name'] ?? '-')) ?></strong></div>
                                            <small class="meta-text"><?= htmlspecialchars((string)$r['reviewed_at']) ?></small>
                                        <?php else: ?>
                                            <span class="muted-placeholder">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a class="btn-secondary"
                                            href="review_pengajuan_jabatan.php?status=<?= htmlspecialchars($status, ENT_QUOTES) ?>&id=<?= (int)$r['id'] ?>">
                                            Detail
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
