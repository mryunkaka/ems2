<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$userDivision = ems_normalize_division($_SESSION['user_rh']['division'] ?? '');
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}

$canDeleteRequest = ($userDivision === 'Specialist Medical Authority');

$pageTitle = 'Review Pengajuan Jabatan';

$status = strtolower(trim($_GET['status'] ?? 'pending'));
if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $status = 'pending';
}

$positionFilter = strtolower(trim($_GET['position'] ?? ''));
$allowedPositions = ['trainee', 'paramedic', 'co_asst', 'general_practitioner', 'specialist'];
if ($positionFilter !== '' && !in_array($positionFilter, $allowedPositions, true)) {
    $positionFilter = '';
}

$requestId = (int)($_GET['id'] ?? 0);

$detail = null;
$detailUser = null;
$detailOps = [];
$detailTemplates = [];

if ($requestId > 0) {
    try {
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

            if (!$detailUser) {
                $errors[] = 'User yang mengajukan tidak ditemukan. Pengajuan #' . $requestId . ' telah dihapus karena data korup.';
                $detail = null;

                // Delete corrupted request
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("DELETE FROM position_promotion_request_operations WHERE request_id = ?");
                    $stmt->execute([$requestId]);
                    $stmt = $pdo->prepare("DELETE FROM position_promotion_requests WHERE id = ?");
                    $stmt->execute([$requestId]);
                    $pdo->commit();
                } catch (Throwable $deleteError) {
                    $pdo->rollBack();
                    $errors[] = 'Gagal menghapus pengajuan korup: ' . $deleteError->getMessage();
                }
            } else {
                $stmt = $pdo->prepare("
                    SELECT sort_order, patient_name, procedure_name, dpjp, operation_role, operation_level, medical_record_id
                    FROM position_promotion_request_operations
                    WHERE request_id = ?
                    ORDER BY sort_order ASC, id ASC
                ");
                $stmt->execute([$requestId]);
                $detailOps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            $errors[] = 'Pengajuan #' . $requestId . ' tidak ditemukan.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Gagal memuat detail pengajuan #' . $requestId . ': ' . $e->getMessage();
        $detail = null;
        $detailUser = null;
        $detailOps = [];
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
    " . ($positionFilter !== '' ? "AND u.position = ?" : "") . "
    ORDER BY r.submitted_at DESC
    LIMIT 200
");
$params = [$status];
if ($positionFilter !== '') {
    $params[] = $positionFilter;
}
$stmt->execute($params);
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

        <div class="card card-section">
            <div class="card-header">Filter Posisi</div>
            <div class="flex gap-2 flex-wrap">
                <a class="btn-secondary" href="review_pengajuan_jabatan.php?status=<?= htmlspecialchars($status, ENT_QUOTES) ?>">Semua</a>
                <a class="btn-secondary" href="review_pengajuan_jabatan.php?status=<?= htmlspecialchars($status, ENT_QUOTES) ?>&position=trainee">Trainee</a>
                <a class="btn-secondary" href="review_pengajuan_jabatan.php?status=<?= htmlspecialchars($status, ENT_QUOTES) ?>&position=paramedic">Paramedic</a>
                <a class="btn-secondary" href="review_pengajuan_jabatan.php?status=<?= htmlspecialchars($status, ENT_QUOTES) ?>&position=co_asst">Co-Asst</a>
                <a class="btn-secondary" href="review_pengajuan_jabatan.php?status=<?= htmlspecialchars($status, ENT_QUOTES) ?>&position=general_practitioner">Dokter Umum</a>
                <a class="btn-secondary" href="review_pengajuan_jabatan.php?status=<?= htmlspecialchars($status, ENT_QUOTES) ?>&position=specialist">Spesialis</a>
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

                <?php
                // Fetch medical record details for each operation
                $medicalRecordDetails = [];
                $detailTemplates = [];
                foreach ($detailOps as $op) {
                    $mrId = (int)($op['medical_record_id'] ?? 0);
                    if ($mrId > 0 && !isset($medicalRecordDetails[$mrId])) {
                        $stmt = $pdo->prepare("
                            SELECT
                                r.id,
                                r.record_code,
                                r.patient_name,
                                r.patient_citizen_id,
                                r.patient_occupation,
                                r.patient_dob,
                                r.patient_phone,
                                r.patient_gender,
                                r.patient_address,
                                r.patient_status,
                                r.operasi_type,
                                r.ktp_file_path,
                                r.mri_file_path,
                                r.medical_result_html,
                                r.created_at,
                                u.full_name AS dpjp_name,
                                u.position AS dpjp_position
                            FROM medical_records r
                            LEFT JOIN user_rh u ON u.id = r.doctor_id
                            WHERE r.id = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$mrId]);
                        $mr = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($mr) {
                            $medicalRecordDetails[$mrId] = $mr;

                            // Generate detail template for modal
                            ob_start();
                            ?>
                            <div class="forensic-detail-shell">
                                <div class="forensic-detail-hero">
                                    <div class="forensic-detail-panel">
                                        <div class="forensic-detail-label">Identitas Pasien</div>
                                        <div class="forensic-detail-value"><?= htmlspecialchars($mr['patient_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="forensic-detail-meta">
                                            No. rekam medis: <?= htmlspecialchars($mr['record_code'] ?? '', ENT_QUOTES, 'UTF-8') ?><br>
                                            Citizen ID: <?= htmlspecialchars($mr['patient_citizen_id'] ?? '', ENT_QUOTES, 'UTF-8') ?><br>
                                            Dibuat: <?= htmlspecialchars($mr['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                    <div class="forensic-detail-panel">
                                        <div class="forensic-detail-label">Tim Medis</div>
                                        <div class="forensic-detail-badges">
                                            <span class="badge-info"><?= htmlspecialchars($mr['patient_gender'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="<?= ($mr['operasi_type'] ?? '') === 'major' ? 'badge-danger' : 'badge-warning' ?>">
                                                <?= htmlspecialchars(($mr['operasi_type'] ?? '') === 'major' ? 'MAYOR' : 'MINOR', ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                        <div class="forensic-detail-meta">
                                            DPJP: <?= htmlspecialchars($mr['dpjp_name'] ?? '', ENT_QUOTES, 'UTF-8') ?><br>
                                            Dibuat oleh: <?= htmlspecialchars($detailUser['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="forensic-detail-grid">
                                    <div class="forensic-detail-block">
                                        <div class="forensic-detail-label">Pekerjaan</div>
                                        <div class="forensic-detail-value"><?= htmlspecialchars($mr['patient_occupation'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="forensic-detail-block">
                                        <div class="forensic-detail-label">Tanggal Lahir</div>
                                        <div class="forensic-detail-value"><?= htmlspecialchars($mr['patient_dob'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="forensic-detail-block">
                                        <div class="forensic-detail-label">Nomor Telepon</div>
                                        <div class="forensic-detail-value"><?= htmlspecialchars($mr['patient_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <div class="forensic-detail-block">
                                        <div class="forensic-detail-label">Status Pasien</div>
                                        <div class="forensic-detail-value"><?= htmlspecialchars($mr['patient_status'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>

                                <div class="forensic-detail-block">
                                    <div class="forensic-detail-label">Alamat</div>
                                    <div class="forensic-detail-value"><?= htmlspecialchars($mr['patient_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                </div>

                                <div class="forensic-detail-block is-richtext">
                                    <div class="forensic-detail-label">Hasil Rekam Medis</div>
                                    <div class="forensic-detail-richtext">
                                        <?= $mr['medical_result_html'] ?? '<p class="is-muted">Belum ada hasil rekam medis.</p>' ?>
                                    </div>
                                </div>

                                <div class="forensic-detail-grid">
                                    <div class="forensic-detail-block">
                                        <div class="forensic-detail-label">Lampiran KTP</div>
                                        <div class="forensic-detail-value">
                                            <?php if (!empty($mr['ktp_file_path'])): ?>
                                                <a href="/<?= htmlspecialchars($mr['ktp_file_path']) ?>" target="_blank" rel="noopener" class="btn-secondary btn-sm">Buka KTP</a>
                                            <?php else: ?>
                                                <div class="is-muted">Lampiran KTP belum tersedia.</div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($mr['ktp_file_path'])): ?>
                                        <div class="mt-3">
                                            <img src="/<?= htmlspecialchars($mr['ktp_file_path']) ?>" alt="Lampiran KTP" style="width:100%;max-height:260px;object-fit:cover;border-radius:0.9rem;border:1px solid rgba(148,163,184,0.2);">
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="forensic-detail-block">
                                        <div class="forensic-detail-label">Lampiran MRI</div>
                                        <div class="forensic-detail-value">
                                            <?php if (!empty($mr['mri_file_path'])): ?>
                                                <a href="/<?= htmlspecialchars($mr['mri_file_path']) ?>" target="_blank" rel="noopener" class="btn-secondary btn-sm">Buka MRI</a>
                                            <?php else: ?>
                                                <div class="is-muted">Lampiran MRI belum tersedia.</div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($mr['mri_file_path'])): ?>
                                        <div class="mt-3">
                                            <img src="/<?= htmlspecialchars($mr['mri_file_path']) ?>" alt="Lampiran MRI" style="width:100%;max-height:260px;object-fit:cover;border-radius:0.9rem;border:1px solid rgba(148,163,184,0.2);">
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                            $detailTemplates[$mrId] = ob_get_clean();
                        }
                    }
                }
                ?>

                <?php if (!empty($detailOps)): ?>
                    <hr class="section-divider">
                    <h3 class="section-form-title">Riwayat Operasi</h3>
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
	                                    <th>Dokumen</th>
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
	                                        <td>
	                                            <span class="badge" style="background:<?= ($op['operation_level'] ?? '') === 'major' ? '#dc2626' : '#059669' ?>; color:white;">
	                                                <?= htmlspecialchars(ucfirst($op['operation_level'] ?? '')) ?>
	                                            </span>
	                                        </td>
	                                        <td>
	                                            <?php
	                                            $mrId = (int)($op['medical_record_id'] ?? 0);
	                                            $mr = $medicalRecordDetails[$mrId] ?? null;
	                                            if ($mr): ?>
	                                                <div class="flex justify-center gap-2">
	                                                    <button
	                                                        type="button"
	                                                        class="btn-secondary btn-sm btn-medical-record-detail"
	                                                        data-modal-title="<?= htmlspecialchars('Detail Rekam Medis ' . ($mr['record_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
	                                                        data-modal-subtitle="<?= htmlspecialchars('Review keseluruhan rekam medis pasien.', ENT_QUOTES, 'UTF-8') ?>"
	                                                        data-template-id="medical-record-detail-<?= $mrId ?>"
	                                                        title="Detail"
	                                                        aria-label="Lihat detail rekam medis">
	                                                        <?= ems_icon('eye', 'h-4 w-4') ?>
	                                                    </button>
	                                                    <?php if (!empty($mr['ktp_file_path'])): ?>
	                                                        <a href="/<?= htmlspecialchars($mr['ktp_file_path']) ?>" target="_blank" class="btn-secondary action-icon-btn" title="Lihat KTP">
	                                                            <?= ems_icon('document', 'h-4 w-4') ?> KTP
	                                                        </a>
	                                                    <?php endif; ?>
	                                                    <?php if (!empty($mr['mri_file_path'])): ?>
	                                                        <a href="/<?= htmlspecialchars($mr['mri_file_path']) ?>" target="_blank" class="btn-secondary action-icon-btn" title="Lihat MRI">
	                                                            <?= ems_icon('document', 'h-4 w-4') ?> MRI
	                                                        </a>
	                                                    <?php endif; ?>
	                                                </div>
	                                            <?php else: ?>
	                                                <span class="muted-placeholder">-</span>
	                                            <?php endif; ?>
	                                        </td>
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
                            <button type="submit" name="action" value="approve" class="btn-success action-icon-btn"
                                title="Approve pengajuan"
                                aria-label="Approve pengajuan"
                                onclick="return confirm('Approve pengajuan ini? Jabatan user akan diupdate.')">
                                <?= ems_icon('check-circle', 'h-4 w-4') ?>
                            </button>
                            <button type="submit" name="action" value="reject" class="btn-danger action-icon-btn"
                                title="Reject pengajuan"
                                aria-label="Reject pengajuan"
                                onclick="return confirm('Reject pengajuan ini?')">
                                <?= ems_icon('x-mark', 'h-4 w-4') ?>
                            </button>
                            <?php if ($canDeleteRequest): ?>
                                <button type="submit" name="action" value="delete" class="btn-secondary action-icon-btn"
                                    title="Hapus permanen pengajuan"
                                    aria-label="Hapus permanen pengajuan"
                                    onclick="return confirm('Hapus permanen pengajuan ini? User harus mengajukan ulang.')">
                                    <?= ems_icon('trash', 'h-4 w-4') ?>
                                </button>
                            <?php endif; ?>
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
                    <?php if ($canDeleteRequest): ?>
                        <form method="POST" action="review_pengajuan_jabatan_action.php" class="form" style="margin-top:12px;">
                            <?= csrfField(); ?>
                            <input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>">
                            <button type="submit" name="action" value="delete" class="btn-danger action-icon-btn"
                                title="Hapus permanen pengajuan"
                                aria-label="Hapus permanen pengajuan"
                                onclick="return confirm('Hapus permanen pengajuan ini? User harus mengajukan ulang.')">
                                <?= ems_icon('trash', 'h-4 w-4') ?> Hapus Permanen
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card card-section">
            <div class="card-header">Daftar Pengajuan (<?= strtoupper($status) ?>)</div>
	            <div class="table-wrapper-sm">
	                <table class="table-custom" data-auto-datatable="true" data-dt-order='[[0,"desc"]]' data-dt-column-defs='[{"targets":[7],"orderable":false,"searchable":false}]'>
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
	                                        <a class="btn-secondary action-icon-btn"
	                                            href="review_pengajuan_jabatan.php?status=<?= htmlspecialchars($status, ENT_QUOTES) ?>&id=<?= (int)$r['id'] ?>"
	                                            title="Lihat detail pengajuan"
	                                            aria-label="Lihat detail pengajuan">
	                                            <?= ems_icon('eye', 'h-4 w-4') ?>
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

<?php foreach ($detailTemplates as $mrId => $templateHtml): ?>
    <template id="medical-record-detail-<?= (int) $mrId ?>">
        <?= $templateHtml ?>
    </template>
<?php endforeach; ?>

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

        if (event.target.closest('.btn-medical-record-close')) {
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
