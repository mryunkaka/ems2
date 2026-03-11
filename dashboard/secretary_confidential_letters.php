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

$pageTitle = 'Rekap Surat Rahasia';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function secretaryConfidentialStatusMeta(string $status): array
{
    return match ($status) {
        'sealed' => ['label' => 'SEALED', 'class' => 'badge-danger'],
        'distributed' => ['label' => 'DISTRIBUTED', 'class' => 'badge-success'],
        'archived' => ['label' => 'ARCHIVED', 'class' => 'badge-muted'],
        default => ['label' => strtoupper($status), 'class' => 'badge-warning'],
    };
}

function secretaryConfidentialLevelMeta(string $level): array
{
    return match ($level) {
        'top_secret' => ['label' => 'TOP SECRET', 'class' => 'badge-danger'],
        'secret' => ['label' => 'SECRET', 'class' => 'badge-warning'],
        default => ['label' => 'CONFIDENTIAL', 'class' => 'badge-muted'],
    };
}

$summary = ['logged' => 0, 'sealed' => 0, 'distributed' => 0, 'archived' => 0];
$rows = [];

try {
    $summary['logged'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'logged'")->fetchColumn();
    $summary['sealed'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'sealed'")->fetchColumn();
    $summary['distributed'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'distributed'")->fetchColumn();
    $summary['archived'] = (int) $pdo->query("SELECT COUNT(*) FROM secretary_confidential_letters WHERE status = 'archived'")->fetchColumn();

    $rows = $pdo->query("
        SELECT *
        FROM secretary_confidential_letters
        ORDER BY letter_date DESC, id DESC
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
        <p class="page-subtitle">Register surat rahasia, arah surat, level kerahasiaan, dan status distribusinya.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="stats-grid mb-4">
            <?php
            ems_component('ui/statistic-card', ['label' => 'Baru Tercatat', 'value' => $summary['logged'], 'icon' => 'document-text', 'tone' => 'primary']);
            ems_component('ui/statistic-card', ['label' => 'Sealed', 'value' => $summary['sealed'], 'icon' => 'lock-closed', 'tone' => 'danger']);
            ems_component('ui/statistic-card', ['label' => 'Distributed', 'value' => $summary['distributed'], 'icon' => 'paper-airplane', 'tone' => 'success']);
            ems_component('ui/statistic-card', ['label' => 'Archived', 'value' => $summary['archived'], 'icon' => 'inbox', 'tone' => 'muted']);
            ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Surat Rahasia</div>
                <p class="meta-text mb-4">Register surat masuk/keluar yang butuh kendali distribusi dan kerahasiaan.</p>

                <form method="POST" action="secretary_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_confidential_letter">
                    <input type="hidden" name="redirect_to" value="secretary_confidential_letters.php">

                    <div class="row-form-2">
                        <div>
                            <label>Nomor Referensi</label>
                            <input type="text" name="reference_number" required>
                        </div>
                        <div>
                            <label>Arah Surat</label>
                            <select name="letter_direction">
                                <option value="incoming">Incoming</option>
                                <option value="outgoing">Outgoing</option>
                            </select>
                        </div>
                    </div>

                    <label>Subjek Surat</label>
                    <input type="text" name="subject" required>

                    <label>Pengirim / Penerima Utama</label>
                    <input type="text" name="counterparty_name" required>

                    <div class="row-form-2">
                        <div>
                            <label>Level Kerahasiaan</label>
                            <select name="confidentiality_level">
                                <option value="confidential">Confidential</option>
                                <option value="secret">Secret</option>
                                <option value="top_secret">Top Secret</option>
                            </select>
                        </div>
                        <div>
                            <label>Tanggal Surat</label>
                            <input type="date" name="letter_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <label>Status</label>
                    <select name="status">
                        <option value="logged">Logged</option>
                        <option value="sealed">Sealed</option>
                        <option value="distributed">Distributed</option>
                        <option value="archived">Archived</option>
                    </select>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('inbox', 'h-4 w-4') ?>
                            <span>Simpan Register</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Surat Rahasia</div>
                <p class="meta-text mb-4">Pantau surat rahasia berdasarkan nomor referensi, level, dan status distribusi.</p>

                <div class="table-wrapper">
                    <table id="secretaryConfidentialTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Referensi</th>
                                <th>Subjek</th>
                                <th>Level</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php $statusMeta = secretaryConfidentialStatusMeta((string) $row['status']); ?>
                                <?php $levelMeta = secretaryConfidentialLevelMeta((string) $row['confidentiality_level']); ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $row['register_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['reference_number'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars(strtoupper((string) $row['letter_direction']), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $row['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $row['counterparty_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><span class="<?= htmlspecialchars($levelMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($levelMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <form method="POST" action="secretary_action.php" class="inline-flex gap-2 items-center">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="update_confidential_status">
                                            <input type="hidden" name="redirect_to" value="secretary_confidential_letters.php">
                                            <input type="hidden" name="letter_id" value="<?= (int) $row['id'] ?>">
                                            <select name="status">
                                                <?php foreach (['logged', 'sealed', 'distributed', 'archived'] as $status): ?>
                                                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $row['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8') ?></option>
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
        $('#secretaryConfidentialTable').DataTable({
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
