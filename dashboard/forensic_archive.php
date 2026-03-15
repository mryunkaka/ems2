<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Forensic'], '/dashboard/index.php');

$pageTitle = 'Arsip Forensic';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function forensicArchiveStatusMeta(string $status): array
{
    return match ($status) {
        'stored' => ['label' => 'STORED', 'class' => 'badge-warning'],
        'sealed' => ['label' => 'SEALED', 'class' => 'badge-danger'],
        'released' => ['label' => 'RELEASED', 'class' => 'badge-success'],
        default => ['label' => strtoupper($status), 'class' => 'badge-secondary'],
    };
}

function forensicArchiveValue(mixed $value, string $fallback = '-'): string
{
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

$cases = [];
$visumResults = [];
$archives = [];

try {
    $cases = $pdo->query("
        SELECT id, case_code, patient_name
        FROM forensic_private_patients
        ORDER BY incident_date DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $visumResults = $pdo->query("
        SELECT id, visum_code
        FROM forensic_visum_results
        ORDER BY examination_date DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $archives = $pdo->query("
        SELECT
            fa.*,
            fpp.case_code,
            fvr.visum_code
        FROM forensic_archives fa
        LEFT JOIN forensic_private_patients fpp ON fpp.id = fa.private_patient_id
        LEFT JOIN forensic_visum_results fvr ON fvr.id = fa.visum_result_id
        ORDER BY fa.created_at DESC, fa.id DESC
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
        <p class="page-subtitle">Penyimpanan arsip forensic berdasarkan kasus dan hasil visum yang sudah dibuat.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="card card-section">
                <div class="card-header">Input Arsip Forensic</div>
                <p class="meta-text mb-4">Hubungkan arsip ke kasus forensic, hasil visum, atau keduanya.</p>

                <form method="POST" action="forensic_action.php" class="form">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="save_archive">
                    <input type="hidden" name="redirect_to" value="forensic_archive.php">

                    <label>Kasus Forensic</label>
                    <select name="private_patient_id">
                        <option value="">Tanpa kasus</option>
                        <?php foreach ($cases as $case): ?>
                            <option value="<?= (int) $case['id'] ?>"><?= htmlspecialchars((string) ($case['case_code'] . ' - ' . $case['patient_name']), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Hasil Visum</label>
                    <select name="visum_result_id">
                        <option value="">Tanpa visum</option>
                        <?php foreach ($visumResults as $visum): ?>
                            <option value="<?= (int) $visum['id'] ?>"><?= htmlspecialchars((string) $visum['visum_code'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Judul Arsip</label>
                    <input type="text" name="archive_title" required>

                    <div class="row-form-2">
                        <div>
                            <label>Tipe Dokumen</label>
                            <input type="text" name="document_type" required>
                        </div>
                        <div>
                            <label>Retensi Sampai</label>
                            <input type="date" name="retention_until">
                        </div>
                    </div>

                    <label>Status Arsip</label>
                    <select name="status">
                        <option value="stored">Stored</option>
                        <option value="sealed">Sealed</option>
                        <option value="released">Released</option>
                    </select>

                    <label>Catatan</label>
                    <textarea name="notes" rows="3"></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('archive-box', 'h-4 w-4') ?>
                            <span>Simpan Arsip</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card card-section">
                <div class="card-header">Daftar Arsip Forensic</div>
                <p class="meta-text mb-4">Pantau status penyimpanan dan keterkaitan arsip dengan kasus maupun visum.</p>

                <div class="table-wrapper">
                    <table id="forensicArchiveTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Judul</th>
                                <th>Referensi</th>
                                <th>Retensi</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archives as $archive): ?>
                                <?php $statusMeta = forensicArchiveStatusMeta((string) $archive['status']); ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $archive['archive_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $archive['archive_title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string) $archive['document_type'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <div class="meta-text-xs">Kasus: <?= htmlspecialchars((string) ($archive['case_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="meta-text-xs">Visum: <?= htmlspecialchars((string) ($archive['visum_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($archive['retention_until'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td>
                                        <div class="inline-flex gap-2 items-center">
                                        <button
                                            type="button"
                                            class="btn-primary btn-sm btn-forensic-detail"
                                            data-modal-title="<?= htmlspecialchars('Detail Arsip ' . (string) $archive['archive_code'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-modal-subtitle="<?= htmlspecialchars('Review dokumen arsip forensic beserta referensi kasus dan visum.', ENT_QUOTES, 'UTF-8') ?>">
                                            <?= ems_icon('eye', 'h-4 w-4') ?>
                                            <span>Detail</span>
                                        </button>
                                        <form method="POST" action="forensic_action.php" class="inline-flex gap-2 items-center">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="update_archive_status">
                                            <input type="hidden" name="redirect_to" value="forensic_archive.php">
                                            <input type="hidden" name="archive_id" value="<?= (int) $archive['id'] ?>">
                                            <select name="status">
                                                <?php foreach (['stored', 'sealed', 'released'] as $status): ?>
                                                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $archive['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucwords($status), ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-secondary btn-sm">Status</button>
                                        </form>
                                        <form method="POST" action="forensic_action.php" onsubmit="return confirm('Hapus permanen arsip forensic ini? Tindakan ini tidak bisa dibatalkan.');" class="inline-flex">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_archive">
                                            <input type="hidden" name="redirect_to" value="forensic_archive.php">
                                            <input type="hidden" name="archive_id" value="<?= (int) $archive['id'] ?>">
                                            <button type="submit" class="btn-error btn-sm">Hapus</button>
                                        </form>
                                        <div class="hidden forensic-detail-template">
                                            <div class="forensic-detail-shell">
                                                <div class="forensic-detail-hero">
                                                    <div class="forensic-detail-panel">
                                                        <div class="forensic-detail-label">Identitas Arsip</div>
                                                        <div class="forensic-detail-value"><?= htmlspecialchars(forensicArchiveValue($archive['archive_title']), ENT_QUOTES, 'UTF-8') ?></div>
                                                        <div class="forensic-detail-meta">
                                                            Kode arsip: <?= htmlspecialchars(forensicArchiveValue($archive['archive_code']), ENT_QUOTES, 'UTF-8') ?><br>
                                                            Tipe dokumen: <?= htmlspecialchars(forensicArchiveValue($archive['document_type']), ENT_QUOTES, 'UTF-8') ?><br>
                                                            Dibuat: <?= htmlspecialchars(forensicArchiveValue($archive['created_at']), ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                    </div>
                                                    <div class="forensic-detail-panel">
                                                        <div class="forensic-detail-label">Status Arsip</div>
                                                        <div class="forensic-detail-badges">
                                                            <span class="<?= htmlspecialchars($statusMeta['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMeta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        </div>
                                                        <div class="forensic-detail-meta">
                                                            Retensi sampai: <?= htmlspecialchars(forensicArchiveValue($archive['retention_until']), ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="forensic-detail-grid">
                                                    <div class="forensic-detail-block">
                                                        <div class="forensic-detail-label">Referensi Kasus</div>
                                                        <div class="forensic-detail-value"><?= htmlspecialchars(forensicArchiveValue($archive['case_code']), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                    <div class="forensic-detail-block">
                                                        <div class="forensic-detail-label">Referensi Visum</div>
                                                        <div class="forensic-detail-value"><?= htmlspecialchars(forensicArchiveValue($archive['visum_code']), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                </div>
                                                <div class="forensic-detail-block">
                                                    <div class="forensic-detail-label">Catatan Arsip</div>
                                                    <div class="forensic-detail-value<?= trim((string) ($archive['notes'] ?? '')) === '' ? ' is-muted' : '' ?>"><?= htmlspecialchars(forensicArchiveValue($archive['notes']), ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                            </div>
                                        </div>
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

<div id="forensicDetailModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg forensic-detail-modal">
        <div class="forensic-detail-head">
            <div class="min-w-0">
                <div id="forensicDetailModalTitle" class="forensic-detail-title">Detail</div>
                <div id="forensicDetailModalSubtitle" class="forensic-detail-subtitle"></div>
            </div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div id="forensicDetailModalBody" class="forensic-detail-content"></div>
        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary btn-cancel">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && $.fn.DataTable) {
        $('#forensicArchiveTable').DataTable({
            language: {
                url: '<?= htmlspecialchars(ems_url('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>'
            },
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('forensicDetailModal');
    const title = document.getElementById('forensicDetailModalTitle');
    const subtitle = document.getElementById('forensicDetailModalSubtitle');
    const body = document.getElementById('forensicDetailModalBody');

    if (!modal || !title || !subtitle || !body) {
        return;
    }

    function closeModal() {
        modal.classList.add('hidden');
        body.innerHTML = '';
        document.body.classList.remove('modal-open');
    }

    document.body.addEventListener('click', function (event) {
        const trigger = event.target.closest('.btn-forensic-detail');
        if (trigger) {
            const template = trigger.parentElement ? trigger.parentElement.querySelector('.forensic-detail-template') : null;
            if (!template) {
                return;
            }

            title.textContent = trigger.getAttribute('data-modal-title') || 'Detail';
            subtitle.textContent = trigger.getAttribute('data-modal-subtitle') || '';
            body.innerHTML = template.innerHTML;
            modal.classList.remove('hidden');
            document.body.classList.add('modal-open');
            return;
        }

        if (event.target === modal || event.target.closest('.btn-cancel')) {
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
