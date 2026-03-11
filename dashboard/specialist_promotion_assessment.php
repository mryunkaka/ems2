<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Specialist Medical Authority'], '/dashboard/index.php');

$pageTitle = 'Penilaian Layak Naik Jabatan';
$errors = $_SESSION['flash_errors'] ?? [];
$successMessage = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_errors'], $_SESSION['flash_success']);

$promotionRequests = [];
$assessments = [];

try {
    $requestsStmt = $pdo->query(
        "SELECT
            ppr.id,
            ppr.from_position,
            ppr.to_position,
            ppr.status,
            ppr.submitted_at,
            u.full_name AS employee_name
        FROM position_promotion_requests ppr
        INNER JOIN user_rh u ON u.id = ppr.user_id
        ORDER BY
            CASE WHEN ppr.status = 'pending' THEN 0 ELSE 1 END,
            ppr.id DESC"
    );
    $promotionRequests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

    $assessmentsStmt = $pdo->query(
        "SELECT
            spa.*,
            req.from_position,
            req.to_position,
            req.submitted_at,
            assessed.full_name AS assessed_name,
            assessor.full_name AS assessor_name
        FROM specialist_promotion_assessments spa
        INNER JOIN position_promotion_requests req ON req.id = spa.promotion_request_id
        INNER JOIN user_rh assessed ON assessed.id = spa.assessed_user_id
        INNER JOIN user_rh assessor ON assessor.id = spa.assessor_user_id
        ORDER BY spa.assessed_at DESC, spa.id DESC"
    );
    $assessments = $assessmentsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $errors[] = 'Data penilaian Specialist Medical Authority belum siap. Jalankan SQL `docs/sql/04_2026-03-10_specialist_medical_authority_module.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Validasi kesiapan klinis, pelatihan, dan rekomendasi promosi jabatan medis.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars((string) $successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Form Penilaian</div>
                <p class="meta-text mb-4">Hubungkan penilaian dengan pengajuan yang sudah masuk.</p>

                <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/specialist_medical_authority_action.php'), ENT_QUOTES, 'UTF-8') ?>" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_assessment">
                    <input type="hidden" name="redirect_to" value="specialist_promotion_assessment.php">

                    <label>Pengajuan Jabatan</label>
                    <select name="promotion_request_id" required>
                        <option value="">Pilih pengajuan</option>
                        <?php foreach ($promotionRequests as $request): ?>
                            <option value="<?= (int) $request['id'] ?>">
                                <?= htmlspecialchars(
                                    'REQ-' . str_pad((string) $request['id'], 5, '0', STR_PAD_LEFT)
                                    . ' - ' . $request['employee_name']
                                    . ' (' . ems_position_label((string) $request['from_position'])
                                    . ' -> ' . ems_position_label((string) $request['to_position']) . ')',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="row-form-2">
                        <div>
                            <label>Skor Klinis</label>
                            <input type="number" name="clinical_score" min="0" max="100" value="0" required>
                        </div>
                        <div>
                            <label>Skor Pelatihan</label>
                            <input type="number" name="training_score" min="0" max="100" value="0" required>
                        </div>
                    </div>

                    <label>Skor Kesiapan</label>
                    <input type="number" name="readiness_score" min="0" max="100" value="0" required>

                    <label>Rekomendasi</label>
                    <select name="recommendation" required>
                        <option value="recommended">Recommended</option>
                        <option value="follow_up_required">Follow Up Required</option>
                        <option value="not_recommended">Not Recommended</option>
                    </select>

                    <label>Catatan</label>
                    <textarea name="notes" rows="4"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('check-circle', 'h-4 w-4') ?>
                            <span>Simpan Penilaian</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Riwayat Penilaian</div>
                <p class="meta-text mb-4">Hasil penilaian kesiapan promosi jabatan medis spesialis.</p>

                <div class="table-wrapper">
                    <table id="specialistAssessmentTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Tenaga Medis</th>
                                <th>Pengajuan</th>
                                <th>Skor</th>
                                <th>Rekomendasi</th>
                                <th>Assessor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assessments as $assessment): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $assessment['assessment_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $assessment['assessed_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars('REQ-' . str_pad((string) $assessment['promotion_request_id'], 5, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(ems_position_label((string) $assessment['from_position']) . ' -> ' . ems_position_label((string) $assessment['to_position']), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= number_format((float) $assessment['total_score'], 2) ?></td>
                                    <td><span class="badge-muted"><?= htmlspecialchars(strtoupper((string) $assessment['recommendation']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars((string) $assessment['assessor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.jQuery && $.fn.DataTable) {
            $('#specialistAssessmentTable').DataTable({
                language: {
                    url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json')) ?>'
                },
                pageLength: 10,
                order: [[0, 'desc']]
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
