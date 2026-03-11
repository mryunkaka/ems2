<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_require_division_access(['Specialist Medical Authority'], '/dashboard/index.php');

$pageTitle = 'Rekap Pelatihan Medis';
$errors = $_SESSION['flash_errors'] ?? [];
$successMessage = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_errors'], $_SESSION['flash_success']);

$summary = [
    'total' => 0,
    'completed' => 0,
    'ongoing' => 0,
    'expired' => 0,
];
$trainingRecords = [];

try {
    $summaryStmt = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired
        FROM specialist_training_records"
    );
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: $summary;

    $recordsStmt = $pdo->query(
        "SELECT
            str.*,
            u.full_name AS employee_name,
            u.position AS employee_position
        FROM specialist_training_records str
        INNER JOIN user_rh u ON u.id = str.user_id
        ORDER BY str.start_date DESC, str.id DESC"
    );
    $trainingRecords = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $errors[] = 'Tabel Specialist Medical Authority belum tersedia. Jalankan SQL `docs/sql/04_2026-03-10_specialist_medical_authority_module.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Pemantauan pelatihan, sertifikasi, dan masa berlaku kompetensi tenaga spesialis.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars((string) $successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Total Pelatihan', 'value' => number_format((int) ($summary['total'] ?? 0)), 'icon' => 'clipboard-document-list', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Selesai', 'value' => number_format((int) ($summary['completed'] ?? 0)), 'icon' => 'check-circle', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Berjalan', 'value' => number_format((int) ($summary['ongoing'] ?? 0)), 'icon' => 'clock', 'tone' => 'warning']);
            ems_component('ui/statistic-card', ['label' => 'Kedaluwarsa', 'value' => number_format((int) ($summary['expired'] ?? 0)), 'icon' => 'exclamation-triangle', 'tone' => 'danger']);
            ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Pelatihan Baru</div>
                <p class="meta-text mb-4">Simpan riwayat pelatihan dan sertifikat medis spesialis.</p>

                <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/specialist_medical_authority_action.php'), ENT_QUOTES, 'UTF-8') ?>" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_training">
                    <input type="hidden" name="redirect_to" value="specialist_training_recap.php">

                    <label>Tenaga Medis</label>
                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required="1">
                        <input type="text" placeholder="Ketik nama tenaga medis" autocomplete="off" data-user-autocomplete-input required>
                        <input type="hidden" name="user_id" data-user-autocomplete-hidden>
                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                    </div>

                    <label>Nama Pelatihan</label>
                    <input type="text" name="training_name" required>

                    <label>Penyelenggara</label>
                    <input type="text" name="provider_name">

                    <div class="row-form-2">
                        <div>
                            <label>Kategori</label>
                            <input type="text" name="category" required>
                        </div>
                        <div>
                            <label>No. Sertifikat</label>
                            <input type="text" name="certificate_number">
                        </div>
                    </div>

                    <div class="row-form-2">
                        <div>
                            <label>Mulai</label>
                            <input type="date" name="start_date" required>
                        </div>
                        <div>
                            <label>Selesai</label>
                            <input type="date" name="end_date">
                        </div>
                    </div>

                    <div class="row-form-2">
                        <div>
                            <label>Status</label>
                            <select name="status">
                                <option value="planned">Planned</option>
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                                <option value="expired">Expired</option>
                            </select>
                        </div>
                    </div>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Simpan Pelatihan</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Pelatihan</div>
                <p class="meta-text mb-4">Riwayat pelatihan spesialis yang sudah tercatat.</p>

                <div class="table-wrapper">
                    <table id="specialistTrainingTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Tenaga Medis</th>
                                <th>Pelatihan</th>
                                <th>Periode</th>
                                <th>Status</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trainingRecords as $record): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $record['training_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $record['employee_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) ($record['employee_position'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $record['training_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) ($record['provider_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $record['start_date'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) ($record['end_date'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><span class="badge-muted"><?= htmlspecialchars(strtoupper((string) $record['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars((string) ($record['notes'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
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
            $('#specialistTrainingTable').DataTable({
                language: {
                    url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json')) ?>'
                },
                pageLength: 10,
                order: [[3, 'desc']]
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
