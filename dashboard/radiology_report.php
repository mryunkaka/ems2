<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_radiology.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'radiology_center.php', '/dashboard/index.php');
ems_ai_radiology_ensure_tables($pdo);

$pageTitle = 'Hasil Citra Radiologi | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$canDelete = ems_is_manager_plus_role($user['role'] ?? '');

$imageId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_errors'] = ['Sesi kedaluwarsa, silakan coba lagi.'];
        header('Location: radiology_report.php?id=' . $imageId);
        exit;
    }
    if (!$canDelete) {
        $_SESSION['flash_errors'] = ['Anda tidak memiliki akses untuk menghapus citra ini.'];
        header('Location: radiology_report.php?id=' . $imageId);
        exit;
    }

    $stmtFile = $pdo->prepare("SELECT image_path FROM ai_radiology_images WHERE id = ? AND unit_code = ?");
    $stmtFile->execute([$imageId, $effectiveUnit]);
    $filePath = (string) $stmtFile->fetchColumn();

    $del = $pdo->prepare("DELETE FROM ai_radiology_images WHERE id = ? AND unit_code = ?");
    $del->execute([$imageId, $effectiveUnit]);

    if ($filePath !== '') {
        $fullPath = realpath(__DIR__ . '/../' . $filePath);
        $storageRoot = realpath(__DIR__ . '/../storage');
        if ($fullPath !== false && $storageRoot !== false && str_starts_with(str_replace('\\', '/', $fullPath), str_replace('\\', '/', $storageRoot))) {
            @unlink($fullPath);
        }
    }

    $_SESSION['flash_messages'] = ['Citra radiologi berhasil dihapus.'];
    header('Location: radiology_center.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.*, u.full_name AS created_by_name
    FROM ai_radiology_images r
    LEFT JOIN user_rh u ON u.id = r.user_id
    WHERE r.id = ? AND r.unit_code = ?
");
$stmt->execute([$imageId, $effectiveUnit]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['flash_errors'] = ['Citra radiologi tidak ditemukan.'];
    header('Location: radiology_center.php');
    exit;
}

$imageUrl = $report['status'] === 'done' && !empty($report['image_path']) ? ems_secure_file_url((string) $report['image_path']) : '';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between mb-4">
            <div>
                <h1 class="page-title">Hasil Citra Radiologi #<?= (int) $report['id'] ?></h1>
                <p class="page-subtitle">Digenerate <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $report['created_at'])), ENT_QUOTES, 'UTF-8') ?> oleh <?= htmlspecialchars((string) ($report['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="flex items-center gap-2">
                <a href="radiology_center.php" class="btn-secondary">
                    <?= ems_icon('arrow-left', 'h-4 w-4') ?>
                    <span>Kembali</span>
                </a>
                <?php if ($canDelete): ?>
                    <form method="POST" action="radiology_report.php?id=<?= (int) $report['id'] ?>" onsubmit="return confirm('Hapus citra radiologi #<?= (int) $report['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
                        <?= csrfField(); ?>
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn-danger">
                            <?= ems_icon('trash', 'h-4 w-4') ?>
                            <span>Hapus</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($report['status'] !== 'done'): ?>
            <div class="alert alert-error">
                Generate citra gagal: <?= htmlspecialchars((string) ($report['error_message'] ?? 'Terjadi kesalahan.'), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
            <div class="xl:col-span-2 card mb-0">
                <div class="card-header flex items-center justify-between">
                    <span>Output Imaging</span>
                    <?php if ($imageUrl !== ''): ?>
                        <button type="button" id="radZoomBtn" class="btn-secondary btn-sm">
                            <?= ems_icon('magnifying-glass', 'h-4 w-4') ?>
                            <span>Perbesar</span>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-section">
                    <?php if ($imageUrl !== ''): ?>
                        <div style="background:#000;border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center;min-height:420px;">
                            <img id="radImage" src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Citra Radiologi" style="max-width:100%;max-height:70vh;object-fit:contain;cursor:zoom-in;">
                        </div>
                    <?php else: ?>
                        <div style="background:#000;border-radius:12px;min-height:420px;display:flex;align-items:center;justify-content:center;color:#64748b;">
                            <?= ems_icon('exclamation-triangle', 'h-10 w-10') ?>
                            <span class="ml-2">Citra tidak tersedia.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-0">
                <div class="card-header">Detail Pemeriksaan</div>
                <div class="card-section space-y-3">
                    <div>
                        <div class="meta-text-xs">Modality</div>
                        <div class="font-semibold"><?= htmlspecialchars((string) $report['modality'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div>
                        <div class="meta-text-xs">Category</div>
                        <div class="font-semibold"><?= htmlspecialchars((string) $report['category'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div>
                        <div class="meta-text-xs">Body Region</div>
                        <div class="font-semibold"><?= htmlspecialchars((string) $report['body_region'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div>
                        <div class="meta-text-xs">Projection / Options</div>
                        <div class="font-semibold"><?= htmlspecialchars((string) $report['projection'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div>
                        <div class="meta-text-xs">Temuan Klinis</div>
                        <div class="font-semibold"><?= htmlspecialchars((string) $report['clinical_finding'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <hr>
                    <div>
                        <div class="meta-text-xs">Nama Pasien</div>
                        <div class="font-semibold"><?= htmlspecialchars((string) ($report['patient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div>
                        <div class="meta-text-xs">Tanggal Lahir</div>
                        <div class="font-semibold"><?= $report['patient_dob'] ? htmlspecialchars(date('d/m/Y', strtotime((string) $report['patient_dob'])), ENT_QUOTES, 'UTF-8') : '-' ?></div>
                    </div>
                    <div>
                        <div class="meta-text-xs">Citizen ID</div>
                        <div class="font-semibold"><?= htmlspecialchars((string) ($report['patient_citizen_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div>
                        <div class="meta-text-xs">Dokter Pemeriksa</div>
                        <div class="font-semibold"><?= htmlspecialchars((string) ($report['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <?php if (!empty($report['anamnesis'])): ?>
                        <hr>
                        <div>
                            <div class="meta-text-xs">Anamnesis</div>
                            <div class="text-sm"><?= nl2br(htmlspecialchars((string) $report['anamnesis'], ENT_QUOTES, 'UTF-8')) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if ($imageUrl !== ''): ?>
<div id="radZoomModal" class="modal-overlay hidden" style="align-items:center;justify-content:center;">
    <div style="position:relative;max-width:94vw;max-height:94vh;">
        <button type="button" id="radZoomCloseBtn" class="btn-secondary" style="position:absolute;top:-44px;right:0;">
            <?= ems_icon('x-mark', 'h-4 w-4') ?>
            <span>Tutup</span>
        </button>
        <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Citra Radiologi (Perbesar)" style="max-width:94vw;max-height:94vh;object-fit:contain;background:#000;border-radius:8px;">
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('radZoomModal');
    var openBtn = document.getElementById('radZoomBtn');
    var closeBtn = document.getElementById('radZoomCloseBtn');
    var image = document.getElementById('radImage');

    function openModal() {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
    }
    function closeModal() {
        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    openBtn && openBtn.addEventListener('click', openModal);
    image && image.addEventListener('click', openModal);
    closeBtn && closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) { closeModal(); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeModal(); }
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
