<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Forensic'], '/dashboard/index.php');

$pageTitle = 'Hasil Visum';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function forensicVisumStatusMeta(string $status): array
{
    return match ($status) {
        'issued' => ['label' => 'ISSUED', 'class' => 'badge-success'],
        'revised' => ['label' => 'REVISED', 'class' => 'badge-warning'],
        'archived' => ['label' => 'ARCHIVED', 'class' => 'badge-muted'],
        default => ['label' => strtoupper($status), 'class' => 'badge-muted'],
    };
}

$cases = [];
$visumResults = [];

try {
    $cases = $pdo->query("
        SELECT id, case_code, patient_name, case_type
        FROM forensic_private_patients
        ORDER BY incident_date DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $visumResults = $pdo->query("
        SELECT
            fvr.*,
            fpp.case_code,
            fpp.patient_name,
            doctor.full_name AS doctor_name
        FROM forensic_visum_results fvr
        INNER JOIN forensic_private_patients fpp ON fpp.id = fvr.private_patient_id
        INNER JOIN user_rh doctor ON doctor.id = fvr.doctor_user_id
        ORDER BY fvr.examination_date DESC, fvr.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Tabel Forensic belum siap. Jalankan SQL `docs/sql/05_2026-03-11_forensic_module.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Pencatatan hasil visum, temuan, dan kesimpulan pemeriksaan forensic.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Hasil Visum</div>
                <p class="meta-text mb-4">Hubungkan hasil visum dengan kasus private yang sudah terdaftar.</p>

                <form method="POST" action="forensic_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_visum">
                    <input type="hidden" name="redirect_to" value="forensic_visum_results.php">

                    <label>Kasus Forensic</label>
                    <select name="private_patient_id" required>
                        <option value="">Pilih kasus</option>
                        <?php foreach ($cases as $case): ?>
                            <option value="<?= (int) $case['id'] ?>"><?= htmlspecialchars((string) ($case['case_code'] . ' - ' . $case['patient_name'] . ' (' . $case['case_type'] . ')'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Dokter Pemeriksa</label>
                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required="1">
                        <input type="text" placeholder="Ketik nama dokter pemeriksa" autocomplete="off" data-user-autocomplete-input required>
                        <input type="hidden" name="doctor_user_id" data-user-autocomplete-hidden>
                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                    </div>

                    <div class="row-form-2">
                        <div>
                            <label>Tanggal Pemeriksaan</label>
                            <input type="date" name="examination_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label>Pihak Peminta</label>
                            <input type="text" name="requesting_party" required>
                        </div>
                    </div>

                    <label>Ringkasan Temuan</label>
                    <textarea name="finding_summary" rows="4" required></textarea>

                    <label>Kesimpulan</label>
                    <textarea name="conclusion_text" rows="3"></textarea>

                    <label>Rekomendasi</label>
                    <textarea name="recommendation_text" rows="3"></textarea>

                    <label>Status</label>
                    <select name="status">
                        <option value="draft">Draft</option>
                        <option value="issued">Issued</option>
                        <option value="revised">Revised</option>
                        <option value="archived">Archived</option>
                    </select>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('document-text', 'h-4 w-4') ?>
                            <span>Simpan Visum</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Hasil Visum</div>
                <p class="meta-text mb-4">Monitoring hasil visum berdasarkan kasus, dokter pemeriksa, dan status dokumen.</p>

                <div class="table-wrapper">
                    <table id="forensicVisumTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Kasus</th>
                                <th>Dokter</th>
                                <th>Temuan</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visumResults as $row): ?>
                                <?php $statusMeta = forensicVisumStatusMeta((string) $row['status']); ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['visum_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $row['examination_date'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['case_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $row['patient_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['doctor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $row['requesting_party'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td class="whitespace-pre-line"><?= htmlspecialchars((string) $row['finding_summary'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <div class="inline-flex gap-2 items-center">
                                        <form method="POST" action="forensic_action.php" class="inline-flex gap-2 items-center">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="update_visum_status">
                                            <input type="hidden" name="redirect_to" value="forensic_visum_results.php">
                                            <input type="hidden" name="visum_id" value="<?= (int) $row['id'] ?>">
                                            <select name="status">
                                                <?php foreach (['draft', 'issued', 'revised', 'archived'] as $status): ?>
                                                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucwords($status), ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-secondary btn-sm">Status</button>
                                        </form>
                                        <form method="POST" action="forensic_action.php" onsubmit="return confirm('Hapus permanen hasil visum ini? Tindakan ini tidak bisa dibatalkan.');" class="inline-flex">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_visum">
                                            <input type="hidden" name="redirect_to" value="forensic_visum_results.php">
                                            <input type="hidden" name="visum_id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn-error btn-sm">Hapus</button>
                                        </form>
                                        </div>
                                    </td>
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
        $('#forensicVisumTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
