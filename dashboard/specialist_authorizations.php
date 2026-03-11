<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Specialist Medical Authority'], '/dashboard/index.php');

$pageTitle = 'Otorisasi Medis Spesialis';
$errors = $_SESSION['flash_errors'] ?? [];
$successMessage = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_errors'], $_SESSION['flash_success']);

$assessments = [];
$authorizations = [];

try {
    $assessmentStmt = $pdo->query(
        "SELECT
            spa.id,
            spa.assessment_code,
            assessed.full_name AS assessed_name
        FROM specialist_promotion_assessments spa
        INNER JOIN user_rh assessed ON assessed.id = spa.assessed_user_id
        ORDER BY spa.assessed_at DESC, spa.id DESC"
    );
    $assessments = $assessmentStmt->fetchAll(PDO::FETCH_ASSOC);

    $authorizationStmt = $pdo->query(
        "SELECT
            sa.*,
            u.full_name AS employee_name,
            approver.full_name AS approver_name
        FROM specialist_authorizations sa
        INNER JOIN user_rh u ON u.id = sa.user_id
        LEFT JOIN user_rh approver ON approver.id = sa.approved_by
        ORDER BY sa.effective_date DESC, sa.id DESC"
    );
    $authorizations = $authorizationStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $errors[] = 'Data otorisasi Specialist Medical Authority belum tersedia. Jalankan SQL `docs/sql/04_2026-03-10_specialist_medical_authority_module.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Penerbitan otorisasi praktik, privilege klinis, dan masa berlaku kewenangan spesialis.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars((string) $successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Form Otorisasi</div>
                <p class="meta-text mb-4">Simpan kewenangan medis spesialis yang berlaku.</p>

                <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/specialist_medical_authority_action.php'), ENT_QUOTES, 'UTF-8') ?>" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_authorization">
                    <input type="hidden" name="redirect_to" value="specialist_authorizations.php">

                    <label>Tenaga Medis</label>
                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required="1">
                        <input type="text" placeholder="Ketik nama tenaga medis" autocomplete="off" data-user-autocomplete-input required>
                        <input type="hidden" name="user_id" data-user-autocomplete-hidden>
                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                    </div>

                    <label>Hasil Penilaian</label>
                    <select name="assessment_id">
                        <option value="">Tanpa penilaian</option>
                        <?php foreach ($assessments as $assessment): ?>
                            <option value="<?= (int) $assessment['id'] ?>">
                                <?= htmlspecialchars((string) ($assessment['assessment_code'] . ' - ' . $assessment['assessed_name']), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label>Spesialisasi</label>
                    <input type="text" name="specialty_name" required>

                    <label>Privilege Scope</label>
                    <textarea name="privilege_scope" rows="4" required></textarea>

                    <div class="row-form-2">
                        <div>
                            <label>Efektif</label>
                            <input type="date" name="effective_date" required>
                        </div>
                        <div>
                            <label>Berakhir</label>
                            <input type="date" name="expiry_date">
                        </div>
                    </div>

                    <label>Status</label>
                    <select name="status">
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                        <option value="revoked">Revoked</option>
                    </select>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('shield-check', 'h-4 w-4') ?>
                            <span>Simpan Otorisasi</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Otorisasi</div>
                <p class="meta-text mb-4">Daftar kewenangan medis spesialis yang sudah diterbitkan.</p>

                <div class="table-wrapper">
                    <table id="specialistAuthorizationTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Tenaga Medis</th>
                                <th>Spesialisasi</th>
                                <th>Periode</th>
                                <th>Status</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($authorizations as $authorization): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $authorization['authorization_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $authorization['employee_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) ($authorization['approver_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string) $authorization['specialty_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $authorization['effective_date'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) ($authorization['expiry_date'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><span class="badge-muted"><?= htmlspecialchars(strtoupper((string) $authorization['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars((string) ($authorization['notes'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
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
            $('#specialistAuthorizationTable').DataTable({
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
