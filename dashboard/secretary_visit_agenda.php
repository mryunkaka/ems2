<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_require_division_access(['Secretary'], '/dashboard/index.php');

$pageTitle = 'Agenda Kunjungan Divisi';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function secretaryAgendaStatusMeta(string $status): array
{
    return match ($status) {
        'ongoing' => ['label' => 'ONGOING', 'class' => 'badge-counter'],
        'completed' => ['label' => 'COMPLETED', 'class' => 'badge-success'],
        'cancelled' => ['label' => 'CANCELLED', 'class' => 'badge-danger'],
        default => ['label' => strtoupper($status), 'class' => 'badge-muted'],
    };
}

$summary = ['total' => 0, 'scheduled' => 0, 'today' => 0, 'completed' => 0];
$agendas = [];

try {
    $summary['total'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_visit_agendas")->fetchColumn();
    $summary['scheduled'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_visit_agendas WHERE status IN ('scheduled', 'ongoing')")->fetchColumn();
    $summary['today'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_visit_agendas WHERE visit_date = CURDATE()")->fetchColumn();
    $summary['completed'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_visit_agendas WHERE status = 'completed'")->fetchColumn();

    $agendas = $pdo->query("
        SELECT
            sva.*,
            pic.full_name AS pic_name
        FROM secretary_visit_agendas sva
        INNER JOIN user_rh pic ON pic.id = sva.pic_user_id
        ORDER BY sva.visit_date DESC, sva.visit_time DESC, sva.id DESC
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
        <p class="page-subtitle">Pendataan jadwal kunjungan divisi, tamu, lokasi, dan PIC internal.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Total Agenda', 'value' => $summary['total'], 'icon' => 'calendar-days', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Agenda Aktif', 'value' => $summary['scheduled'], 'icon' => 'clock', 'tone' => 'warning']);
            ems_component('ui/statistic-card', ['label' => 'Agenda Hari Ini', 'value' => $summary['today'], 'icon' => 'ticket', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Kunjungan Selesai', 'value' => $summary['completed'], 'icon' => 'check-circle', 'tone' => 'muted']);
            ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Agenda Kunjungan</div>
                <p class="meta-text mb-4">Simpan jadwal kunjungan divisi dan PIC internal yang bertanggung jawab.</p>

                <form method="POST" action="secretary_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_visit_agenda">
                    <input type="hidden" name="redirect_to" value="secretary_visit_agenda.php">

                    <label>Nama Tamu / Pengunjung</label>
                    <input type="text" name="visitor_name" required>

                    <label>Instansi / Asal</label>
                    <input type="text" name="origin_name">

                    <label>Tujuan Kunjungan</label>
                    <textarea name="visit_purpose" rows="3" required></textarea>

                    <div class="row-form-2">
                        <div>
                            <label>Tanggal Kunjungan</label>
                            <input type="date" name="visit_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label>Jam Kunjungan</label>
                            <input type="time" name="visit_time" required>
                        </div>
                    </div>

                    <label>Lokasi</label>
                    <input type="text" name="location" required>

                    <label>PIC Internal</label>
                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required>
                        <input type="text" data-user-autocomplete-input placeholder="Ketik nama PIC..." required>
                        <input type="hidden" name="pic_user_id" data-user-autocomplete-hidden>
                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                    </div>

                    <label>Status</label>
                    <select name="status">
                        <option value="scheduled">Scheduled</option>
                        <option value="ongoing">Ongoing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Simpan Agenda</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Agenda Kunjungan</div>
                <p class="meta-text mb-4">Monitoring agenda kunjungan yang sedang dijadwalkan maupun sudah selesai.</p>

                <div class="table-wrapper">
                    <table id="secretaryAgendaTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Tamu</th>
                                <th>Jadwal</th>
                                <th>PIC</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agendas as $agenda): ?>
                                <?php $statusMeta = secretaryAgendaStatusMeta((string) $agenda['status']); ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $agenda['agenda_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $agenda['visitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) ($agenda['origin_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $agenda['visit_date'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(substr((string) $agenda['visit_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars((string) $agenda['location'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string) $agenda['pic_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <form method="POST" action="secretary_action.php" class="inline-flex gap-2 items-center">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="update_visit_status">
                                            <input type="hidden" name="redirect_to" value="secretary_visit_agenda.php">
                                            <input type="hidden" name="agenda_id" value="<?= (int) $agenda['id'] ?>">
                                            <select name="status">
                                                <?php foreach (['scheduled', 'ongoing', 'completed', 'cancelled'] as $status): ?>
                                                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $agenda['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucwords($status), ENT_QUOTES, 'UTF-8') ?></option>
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
        $('#secretaryAgendaTable').DataTable({
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
