<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_laboratory.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'laboratory_ai.php', '/dashboard/index.php');
ems_ai_laboratory_ensure_tables($pdo);

$pageTitle = 'Hasil Laboratorium | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$canDelete = ems_is_manager_plus_role($user['role'] ?? '');

$reportId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_errors'] = ['Sesi kedaluwarsa, silakan coba lagi.'];
        header('Location: laboratory_ai_report.php?id=' . $reportId);
        exit;
    }
    if (!$canDelete) {
        $_SESSION['flash_errors'] = ['Anda tidak memiliki akses untuk menghapus hasil ini.'];
        header('Location: laboratory_ai_report.php?id=' . $reportId);
        exit;
    }
    $del = $pdo->prepare("DELETE FROM ai_laboratory_results WHERE id = ? AND unit_code = ?");
    $del->execute([$reportId, $effectiveUnit]);
    $_SESSION['flash_messages'] = ['Hasil laboratorium berhasil dihapus.'];
    header('Location: laboratory_ai.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT l.*, u.full_name AS created_by_name
    FROM ai_laboratory_results l
    LEFT JOIN user_rh u ON u.id = l.user_id
    WHERE l.id = ? AND l.unit_code = ?
");
$stmt->execute([$reportId, $effectiveUnit]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['flash_errors'] = ['Hasil laboratorium tidak ditemukan.'];
    header('Location: laboratory_ai.php');
    exit;
}

$result = [];
if ($report['status'] === 'done' && $report['result_json']) {
    $decoded = json_decode((string) $report['result_json'], true);
    if (is_array($decoded)) {
        $result = ems_ai_laboratory_sanitize_result($decoded);
    }
}

$flagBadgeClass = static function (string $flag): string {
    return match ($flag) {
        'High' => 'inline-flex items-center rounded-full border border-rose-300 bg-rose-50 text-rose-700 px-2.5 py-0.5 text-xs font-bold',
        'Low' => 'inline-flex items-center rounded-full border border-amber-300 bg-amber-50 text-amber-700 px-2.5 py-0.5 text-xs font-bold',
        default => 'inline-flex items-center rounded-full border border-emerald-300 bg-emerald-50 text-emerald-700 px-2.5 py-0.5 text-xs font-bold',
    };
};

$labResults = is_array($result['results'] ?? null) ? $result['results'] : [];
$examLabel = (string) $report['department'] . ' - ' . (string) $report['category']
    . (!empty($report['level3_option']) ? ' (' . (string) $report['level3_option'] . ')' : '');

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-4">
            <div>
                <h1 class="page-title">Hasil Pemeriksaan Laboratorium</h1>
                <p class="page-subtitle">
                    Dibuat <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $report['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                    oleh <?= htmlspecialchars((string) ($report['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <?php if ($report['status'] === 'done' && !empty($report['report_code'])): ?>
                    <div class="mt-2 inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5">
                        <span class="text-xs font-semibold text-slate-500">Kode Referensi:</span>
                        <code class="text-xs font-bold text-slate-800"><?= htmlspecialchars((string) $report['report_code'], ENT_QUOTES, 'UTF-8') ?></code>
                        <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars((string) $report['report_code'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= ems_icon('clipboard-document-check', 'h-4 w-4') ?>
                            <span>Salin</span>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2">
                <?php if ($report['status'] === 'done'): ?>
                    <button type="button" id="aiLabPrintBtn" class="btn-primary">
                        <?= ems_icon('printer', 'h-4 w-4') ?>
                        <span>Print / Save PDF</span>
                    </button>
                <?php endif; ?>
                <a href="laboratory_ai.php" class="btn-secondary">
                    <?= ems_icon('arrow-left', 'h-4 w-4') ?>
                    <span>Kembali</span>
                </a>
                <?php if ($canDelete): ?>
                    <form method="POST" action="laboratory_ai_report.php?id=<?= (int) $report['id'] ?>" onsubmit="return confirm('Hapus hasil laboratorium #<?= (int) $report['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
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

        <div class="card mb-4">
            <div class="card-header">Identitas Pasien &amp; Pemeriksaan</div>
            <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wide">NAMA PASIEN</div>
                    <div><?= htmlspecialchars((string) ($report['patient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wide">TANGGAL LAHIR</div>
                    <div><?= $report['patient_dob'] ? htmlspecialchars(date('d/m/Y', strtotime((string) $report['patient_dob'])), ENT_QUOTES, 'UTF-8') : '-' ?></div>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wide">CITIZEN ID</div>
                    <div><?= htmlspecialchars((string) ($report['patient_citizen_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wide">DOKTER PEMERIKSA</div>
                    <div><?= htmlspecialchars((string) ($report['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="col-span-2 md:col-span-2">
                    <div class="text-xs font-bold text-slate-500 tracking-wide">PEMERIKSAAN</div>
                    <div><?= htmlspecialchars($examLabel, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="col-span-2 md:col-span-2">
                    <div class="text-xs font-bold text-slate-500 tracking-wide">JENIS SPESIMEN</div>
                    <div><?= htmlspecialchars((string) $report['specimen_type'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Info Klinis / Anamnesis</div>
            <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars((string) $report['clinical_info'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <?php if ($report['status'] !== 'done'): ?>
            <div class="alert alert-error">
                Model AI gagal menghasilkan hasil ini: <?= htmlspecialchars((string) ($report['error_message'] ?? 'Kesalahan tidak diketahui.'), ENT_QUOTES, 'UTF-8') ?>
                Silakan buat ulang dari halaman Laboratory AI.
            </div>
        <?php else: ?>

            <div class="card mb-4">
                <div class="card-header">Hasil Pemeriksaan</div>
                <div class="table-wrapper">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Hasil</th>
                                <th>Satuan</th>
                                <th>Rentang Rujukan</th>
                                <th>Flag</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($labResults as $item): ?>
                                <tr>
                                    <td class="font-semibold"><?= htmlspecialchars((string) ($item['parameter'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($item['result'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($item['reference_range'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="<?= $flagBadgeClass((string) ($item['flag'] ?? 'Normal')) ?>"><?= htmlspecialchars((string) ($item['flag'] ?? 'Normal'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($labResults)): ?>
                                <tr><td colspan="5" class="text-center text-slate-400 py-6">Tidak ada parameter hasil.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Interpretasi</div>
                <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars((string) ($result['interpretation'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Korelasi Klinis</div>
                <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars((string) ($result['clinical_correlation'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Kesan (Diagnosis)</div>
                <div class="p-4 text-sm whitespace-pre-line font-semibold"><?= htmlspecialchars((string) ($result['diagnosis'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Rekomendasi</div>
                <ul class="list-disc pl-5 p-4 text-sm space-y-1">
                    <?php foreach ((array) ($result['recommendations'] ?? []) as $rec): ?>
                        <li><?= htmlspecialchars((string) $rec, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                    <?php if (empty($result['recommendations'])): ?>
                        <li class="text-slate-400 list-none -ml-5">Tidak ada rekomendasi.</li>
                    <?php endif; ?>
                </ul>
            </div>

        <?php endif; ?>
    </div>
</section>

<?php if ($report['status'] === 'done'): ?>
<div id="aiLabPrintTemplate" class="hidden">
    <div style="font-family: 'Times New Roman', serif; color:#1f2937; padding:32px; max-width:800px; margin:0 auto;">
        <div style="text-align:center; border-bottom:3px solid #0ea5e9; padding-bottom:12px; margin-bottom:20px;">
            <div style="font-size:20px; font-weight:bold; letter-spacing:1px;">ROXWOOD HOSPITAL LABORATORY</div>
            <div style="font-size:12px; color:#6b7280;">Laporan Hasil Pemeriksaan Laboratorium</div>
        </div>

        <table style="width:100%; font-size:12px; margin-bottom:16px; border-collapse:collapse;">
            <tr>
                <td style="padding:3px 0; width:20%;"><strong>Nama Pasien</strong></td>
                <td style="padding:3px 0; width:30%;">: <?= htmlspecialchars((string) ($report['patient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:3px 0; width:20%;"><strong>Tgl Lahir</strong></td>
                <td style="padding:3px 0; width:30%;">: <?= $report['patient_dob'] ? htmlspecialchars(date('d/m/Y', strtotime((string) $report['patient_dob'])), ENT_QUOTES, 'UTF-8') : '-' ?></td>
            </tr>
            <tr>
                <td style="padding:3px 0;"><strong>Citizen ID</strong></td>
                <td style="padding:3px 0;">: <?= htmlspecialchars((string) ($report['patient_citizen_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:3px 0;"><strong>Tgl Periksa</strong></td>
                <td style="padding:3px 0;">: <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $report['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <tr>
                <td style="padding:3px 0;"><strong>Pemeriksaan</strong></td>
                <td style="padding:3px 0;" colspan="3">: <?= htmlspecialchars($examLabel, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string) $report['specimen_type'], ENT_QUOTES, 'UTF-8') ?>)</td>
            </tr>
        </table>

        <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:16px;">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th style="border:1px solid #cbd5e1; padding:6px; text-align:left;">Parameter</th>
                    <th style="border:1px solid #cbd5e1; padding:6px; text-align:left;">Hasil</th>
                    <th style="border:1px solid #cbd5e1; padding:6px; text-align:left;">Satuan</th>
                    <th style="border:1px solid #cbd5e1; padding:6px; text-align:left;">Rentang Rujukan</th>
                    <th style="border:1px solid #cbd5e1; padding:6px; text-align:center;">Flag</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($labResults as $item): ?>
                    <?php $flag = (string) ($item['flag'] ?? 'Normal'); ?>
                    <tr>
                        <td style="border:1px solid #cbd5e1; padding:6px;"><?= htmlspecialchars((string) ($item['parameter'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="border:1px solid #cbd5e1; padding:6px; font-weight:<?= $flag !== 'Normal' ? 'bold' : 'normal' ?>; color:<?= $flag === 'High' ? '#dc2626' : ($flag === 'Low' ? '#d97706' : '#111827') ?>;"><?= htmlspecialchars((string) ($item['result'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="border:1px solid #cbd5e1; padding:6px;"><?= htmlspecialchars((string) ($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="border:1px solid #cbd5e1; padding:6px;"><?= htmlspecialchars((string) ($item['reference_range'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="border:1px solid #cbd5e1; padding:6px; text-align:center;"><?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="font-size:12px; margin-bottom:12px;"><strong>Interpretasi:</strong><br><?= nl2br(htmlspecialchars((string) ($result['interpretation'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
        <div style="font-size:12px; margin-bottom:12px;"><strong>Korelasi Klinis:</strong><br><?= nl2br(htmlspecialchars((string) ($result['clinical_correlation'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
        <div style="font-size:12px; margin-bottom:12px;"><strong>Kesan:</strong><br><?= nl2br(htmlspecialchars((string) ($result['diagnosis'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
        <div style="font-size:12px; margin-bottom:20px;">
            <strong>Rekomendasi:</strong>
            <ul style="margin:4px 0 0 18px; padding:0;">
                <?php foreach ((array) ($result['recommendations'] ?? []) as $rec): ?>
                    <li><?= htmlspecialchars((string) $rec, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:40px;">
            <div style="text-align:center; font-size:12px;">
                <div>Roxwood, <?= htmlspecialchars(date('d/m/Y', strtotime((string) $report['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                <div style="height:60px;"></div>
                <div style="border-top:1px solid #1f2937; padding-top:4px; font-weight:bold;"><?= htmlspecialchars((string) ($report['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                <div style="font-size:10px; color:#6b7280;">Dokter Pemeriksa</div>
            </div>
        </div>

        <div style="text-align:center; margin-top:24px; font-size:10px; color:#9ca3af; border-top:1px solid #e5e7eb; padding-top:8px;">
            Dokumen ini dihasilkan otomatis oleh Laboratory AI — Roxwood Hospital Medical Center. Kode Referensi: <?= htmlspecialchars((string) ($report['report_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.mantra-copy-btn');
    if (!btn) return;
    var text = btn.getAttribute('data-copy') || '';
    var original = btn.innerHTML;
    function showResult(ok) {
        btn.textContent = ok ? 'Tersalin' : 'Gagal menyalin';
        setTimeout(function () { btn.innerHTML = original; }, 1500);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { showResult(true); }).catch(function () { showResult(false); });
        return;
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); showResult(true); } catch (err) { showResult(false); }
    document.body.removeChild(ta);
});

var printBtn = document.getElementById('aiLabPrintBtn');
if (printBtn) {
    printBtn.addEventListener('click', function () {
        var content = document.getElementById('aiLabPrintTemplate').innerHTML;
        var win = window.open('', '_blank');
        win.document.open();
        win.document.write('<!doctype html><html><head><title>Hasil Laboratorium</title><meta charset="utf-8"></head><body>' + content + '</body></html>');
        win.document.close();
        win.focus();
        setTimeout(function () { win.print(); }, 300);
    });
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
