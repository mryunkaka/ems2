<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Secretary'], '/dashboard/index.php');

$pageTitle = 'Koordinasi Internal Divisi';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function secretaryCoordinationStatusMeta(string $status): array
{
    return match ($status) {
        'scheduled' => ['label' => 'SCHEDULED', 'class' => 'badge-counter'],
        'done' => ['label' => 'DONE', 'class' => 'badge-success'],
        'cancelled' => ['label' => 'CANCELLED', 'class' => 'badge-danger'],
        default => ['label' => strtoupper($status), 'class' => 'badge-muted'],
    };
}

$rows = [];

try {
    $rows = $pdo->query("
        SELECT
            sic.*,
            host.full_name AS host_name
        FROM secretary_internal_coordinations sic
        INNER JOIN user_rh host ON host.id = sic.host_user_id
        ORDER BY sic.coordination_date DESC, sic.start_time DESC, sic.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Tabel Secretary belum siap. Jalankan SQL `docs/sql/07_2026-03-11_secretary_module.sql` terlebih dahulu.';
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Pencatatan koordinasi internal, penanggung jawab, ringkasan, dan tindak lanjut.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Koordinasi Internal</div>
                <p class="meta-text mb-4">Catat topik koordinasi, host, jadwal, dan tindak lanjut divisi.</p>

                <form method="POST" action="secretary_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_internal_coordination">
                    <input type="hidden" name="redirect_to" value="secretary_internal_coordination.php">

                    <label>Judul Koordinasi</label>
                    <input type="text" name="title" required>

                    <label>Divisi Terkait</label>
                    <input type="text" name="division_scope" required>

                    <label>Host / Penanggung Jawab</label>
                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required>
                        <input type="text" data-user-autocomplete-input placeholder="Ketik nama host..." required>
                        <input type="hidden" name="host_user_id" data-user-autocomplete-hidden>
                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                    </div>

                    <div class="row-form-2">
                        <div>
                            <label>Tanggal Koordinasi</label>
                            <input type="date" name="coordination_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label>Jam Mulai</label>
                            <input type="time" name="start_time" required>
                        </div>
                    </div>

                    <label>Status</label>
                    <select name="status">
                        <option value="draft">Draft</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="done">Done</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    <label>Ringkasan Pembahasan</label>
                    <textarea name="summary_notes" rows="3"></textarea>

                    <label>Tindak Lanjut</label>
                    <textarea name="follow_up_notes" rows="3"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('user-group', 'h-4 w-4') ?>
                            <span>Simpan Koordinasi</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Koordinasi</div>
                <p class="meta-text mb-4">Daftar koordinasi internal divisi beserta status dan follow up.</p>

                <div class="table-wrapper">
                    <table id="secretaryCoordinationTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Judul</th>
                                <th>Jadwal</th>
                                <th>Host</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $statusMeta = secretaryCoordinationStatusMeta((string) $row['status']); ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $row['coordination_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $row['division_scope'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['coordination_date'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(substr((string) $row['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> WIB</div>
                                    </td>
                                    <td><?= htmlspecialchars((string) $row['host_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <form method="POST" action="secretary_action.php" class="inline-flex gap-2 items-center">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="update_coordination_status">
                                            <input type="hidden" name="redirect_to" value="secretary_internal_coordination.php">
                                            <input type="hidden" name="coordination_id" value="<?= (int) $row['id'] ?>">
                                            <select name="status">
                                                <?php foreach (['draft', 'scheduled', 'done', 'cancelled'] as $status): ?>
                                                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucwords($status), ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-secondary btn-sm">Status</button>
                                        </form>
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
        $('#secretaryCoordinationTable').DataTable({
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
