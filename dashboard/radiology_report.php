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
$report['created_by_name'] = ems_medical_display_name_for_user($pdo, (string) ($report['created_by_name'] ?? '')) ?: '-';
$report['doctor_name'] = ems_medical_doctor_display_name_for_user($pdo, (string) ($report['doctor_name'] ?? '')) ?: (string) ($report['doctor_name'] ?? '');

$imageUrl = $report['status'] === 'done' && !empty($report['image_path']) ? ems_secure_file_url((string) $report['image_path']) : '';
$referenceImage = ems_ai_radiology_reference_match($report);

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
                <?php if (($report['report_status'] ?? null) === 'done'): ?>
                    <button type="button" id="radPrintBtn" class="btn-primary">
                        <?= ems_icon('printer', 'h-4 w-4') ?>
                        <span>Print / Save PDF</span>
                    </button>
                <?php endif; ?>
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
        <?php if (($report['report_status'] ?? null) === 'error'): ?>
            <div class="alert alert-error">
                Bacaan radiologi (Sp.Rad) gagal dibuat: <?= htmlspecialchars((string) ($report['report_error_message'] ?? 'Terjadi kesalahan.'), ENT_QUOTES, 'UTF-8') ?>
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
                            <img id="radImage" src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($report['image_source_label'] ?: 'Ilustrasi radiologi simulasi'), ENT_QUOTES, 'UTF-8') ?>" style="max-width:100%;max-height:70vh;object-fit:contain;cursor:zoom-in;">
                        </div>
                        <div class="meta-text-xs mt-2"><strong>Dokter pemeriksa:</strong> <?= htmlspecialchars((string) ($report['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="alert alert-info mt-3 mb-0">
                            <strong>Sumber/jenis gambar:</strong> <?= htmlspecialchars((string) ($report['image_source_label'] ?: 'Ilustrasi simulasi'), ENT_QUOTES, 'UTF-8') ?>.
                            <?php if (str_starts_with((string) ($report['image_source_label'] ?? ''), 'Skema anatomi terarah')): ?>
                                Lokasi tulang dan sisi mengikuti keterangan kasus. Garis patah pada gambar hanya skema; gambar ini bukan radiograf pasien dan tidak menetapkan pola fraktur.
                            <?php else: ?>
                                Gambar generatif hanya ilustrasi roleplay dan tidak memverifikasi temuan klinis.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="background:#000;border-radius:12px;min-height:420px;display:flex;align-items:center;justify-content:center;color:#64748b;">
                            <?= ems_icon('exclamation-triangle', 'h-10 w-10') ?>
                            <span class="ml-2">Citra tidak tersedia.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($referenceImage !== null): ?>
                <div class="xl:col-span-2 card mb-0">
                    <div class="card-header">Gambar Rujukan Fraktur (FracAtlas)</div>
                    <div class="card-section">
                        <div style="background:#000;border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center;min-height:260px;">
                            <img src="<?= htmlspecialchars($referenceImage['path'], ENT_QUOTES, 'UTF-8') ?>" alt="Contoh citra rujukan fraktur tungkai bawah dari FracAtlas; bukan pasien ini" style="max-width:100%;max-height:65vh;object-fit:contain;">
                        </div>
                        <div class="alert alert-info mt-3 mb-0">
                            <strong><?= htmlspecialchars($referenceImage['title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                            <?= htmlspecialchars($referenceImage['match'], ENT_QUOTES, 'UTF-8') ?><br>
                            <?= htmlspecialchars($referenceImage['limitations'], ENT_QUOTES, 'UTF-8') ?><br>
                            <small><?= htmlspecialchars($referenceImage['attribution'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

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

        <?php if (($report['report_status'] ?? null) === 'done'): ?>
            <?php
                $reportFindings = array_values(array_filter(array_map('trim', explode("\n", (string) $report['report_findings']))));
                $reportRecommendations = array_values(array_filter(array_map('trim', explode("\n", (string) $report['report_recommendations']))));
            ?>
            <div class="card mt-4">
                <div class="card-header">Bacaan Radiologi (Sp.Rad)</div>
                <div class="card-section">
                    <div class="alert alert-info mb-4">Teks ini dirangkai dari data klinis yang dimasukkan; gambar simulasi di atas tidak dianalisis sebagai radiograf pasien.</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <div class="meta-text-xs mb-1">Findings</div>
                        <ul class="list-disc pl-5 text-sm space-y-1">
                            <?php foreach ($reportFindings as $item): ?>
                                <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div>
                        <div class="meta-text-xs mb-1">Impression / Kesan</div>
                        <p class="text-sm font-bold"><?= htmlspecialchars((string) $report['report_diagnosis'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="meta-text-xs mb-1 mt-3">Recommendation</div>
                        <ul class="list-disc pl-5 text-sm space-y-1">
                            <?php foreach ($reportRecommendations as $item): ?>
                                <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    </div>
                </div>
                <div class="card-section" style="border-top:1px solid #e2e8f0;">
                    <div class="meta-text-xs mb-2">Laporan Lengkap</div>
                    <div style="background:#0f172a;color:#e2e8f0;border-radius:12px;padding:16px;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.7;white-space:pre-wrap;">
                        <?php
                            $formattedReport = htmlspecialchars((string) $report['report_text'], ENT_QUOTES, 'UTF-8');
                            $formattedReport = preg_replace('/^(TECHNIQUE|FINDINGS|IMPRESSION|RECOMMENDATION)$/m', '<span style="color:#38bdf8;font-weight:700;">$1</span>', $formattedReport);
                            echo $formattedReport;
                        ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if (($report['report_status'] ?? null) === 'done'): ?>
<div id="radPrintTemplate" class="hidden">
    <div class="print-sheet" style="font-family:Arial,sans-serif; color:#111827; padding:0; max-width:100%; margin:0 auto; font-size:9px; line-height:1.25;">
        <div style="text-align:center; font-weight:bold; font-size:14px; border-bottom:2px solid #111827; padding-bottom:6px; margin-bottom:9px;">
            ROXWOOD HOSPITAL<br>
            <span style="font-size:10px; color:#374151;">DEPARTEMEN RADIOLOGI</span>
        </div>

        <div style="margin-bottom:8px;">
            <div style="font-weight:bold; border-bottom:1px solid #cbd5e1; padding-bottom:3px; margin-bottom:4px;">IDENTITAS PASIEN</div>
            <div style="display:grid;grid-template-columns:100px 1fr 90px 1fr;gap:2px 5px;">
                <span>Nama</span><span>: <?= htmlspecialchars((string) ($report['patient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></span>
                <span>Tanggal lahir</span><span>: <?= $report['patient_dob'] ? htmlspecialchars(date('d/m/Y', strtotime((string) $report['patient_dob'])), ENT_QUOTES, 'UTF-8') : '-' ?></span>
                <span>Citizen ID</span><span>: <?= htmlspecialchars((string) ($report['patient_citizen_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></span>
                <span>Dokter pemeriksa</span><span>: <?= htmlspecialchars((string) ($report['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <div style="margin-bottom:8px;">
            <div style="font-weight:bold; border-bottom:1px solid #cbd5e1; padding-bottom:3px; margin-bottom:4px;">PEMERIKSAAN</div>
            <div style="display:grid;grid-template-columns:100px 1fr 90px 1fr;gap:2px 5px;">
                <span>Modalitas</span><span>: <?= htmlspecialchars((string) $report['modality'], ENT_QUOTES, 'UTF-8') ?></span>
                <span>Kategori</span><span>: <?= htmlspecialchars((string) $report['category'], ENT_QUOTES, 'UTF-8') ?></span>
                <span>Area tubuh</span><span>: <?= htmlspecialchars((string) $report['body_region'], ENT_QUOTES, 'UTF-8') ?></span>
                <span>Proyeksi</span><span>: <?= htmlspecialchars((string) $report['projection'], ENT_QUOTES, 'UTF-8') ?></span>
                <span>Tanggal</span><span>: <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $report['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div style="margin-top:4px;"><strong>Indikasi klinis:</strong> <?= htmlspecialchars((string) ($report['anamnesis'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <div style="margin-bottom:10px; white-space:pre-wrap;"><?= nl2br(htmlspecialchars((string) $report['report_text'], ENT_QUOTES, 'UTF-8')) ?></div>

        <div style="display:flex; justify-content:flex-end; margin-top:12px;">
            <div style="text-align:center; font-size:9px; min-width:180px;">
                <div style="height:24px;"></div>
                <div style="border-top:1px solid #111827; padding-top:3px; font-weight:bold;"><?= htmlspecialchars((string) ($report['doctor_name'] ?: 'Dokter Pemeriksa'), ENT_QUOTES, 'UTF-8') ?></div>
                <div style="font-size:8px; color:#6b7280;">Dokter Pemeriksa · Roxwood Hospital</div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var printBtn = document.getElementById('radPrintBtn');
    if (!printBtn) return;
    printBtn.addEventListener('click', function () {
        var content = document.getElementById('radPrintTemplate').innerHTML;
        var win = window.open('', '_blank');
        win.document.open();
        win.document.write('<!doctype html><html><head><title>Laporan Radiologi</title><meta charset="utf-8"><style>@page{size:A4;margin:8mm}html,body{margin:0;padding:0}body{font-family:Arial,sans-serif}.print-sheet{font-size:8px!important;line-height:1.2!important}.print-sheet *{box-sizing:border-box} .print-sheet div{break-inside:avoid}</style></head><body>' + content + '</body></html>');
        win.document.close();
        win.focus();
        setTimeout(function () { win.print(); }, 300);
    });
})();
</script>
<?php endif; ?>

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
