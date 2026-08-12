<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_psychiatry.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'psychiatry_center.php', '/dashboard/index.php');
ems_ai_psychiatry_ensure_tables($pdo);

$pageTitle = 'Laporan Asesmen Psikiatri | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$canDelete = ems_is_manager_plus_role($user['role'] ?? '');

$reportId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_errors'] = ['Sesi kedaluwarsa, silakan coba lagi.'];
        header('Location: psychiatry_report.php?id=' . $reportId);
        exit;
    }
    if (!$canDelete) {
        $_SESSION['flash_errors'] = ['Anda tidak memiliki akses untuk menghapus asesmen ini.'];
        header('Location: psychiatry_report.php?id=' . $reportId);
        exit;
    }
    $del = $pdo->prepare("DELETE FROM ai_psychiatry_assessments WHERE id = ? AND unit_code = ?");
    $del->execute([$reportId, $effectiveUnit]);
    $_SESSION['flash_messages'] = ['Asesmen psikiatri berhasil dihapus.'];
    header('Location: psychiatry_center.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.*, u.full_name AS created_by_name
    FROM ai_psychiatry_assessments p
    LEFT JOIN user_rh u ON u.id = p.user_id
    WHERE p.id = ? AND p.unit_code = ?
");
$stmt->execute([$reportId, $effectiveUnit]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['flash_errors'] = ['Asesmen psikiatri tidak ditemukan.'];
    header('Location: psychiatry_center.php');
    exit;
}

$result = [];
if ($report['status'] === 'done' && $report['result_json']) {
    $decoded = json_decode((string) $report['result_json'], true);
    if (is_array($decoded)) {
        $result = $decoded;
    }
}
$chatHistory = [];
if (!empty($report['chat_transcript'])) {
    $decodedChat = json_decode((string) $report['chat_transcript'], true);
    if (is_array($decodedChat)) {
        $chatHistory = $decodedChat;
    }
}

$mseLabels = [
    'appearance' => 'Appearance', 'behavior' => 'Behavior', 'speech' => 'Speech',
    'mood' => 'Mood', 'affect' => 'Affect', 'thought_process' => 'Thought Process',
    'thought_content' => 'Thought Content', 'perception' => 'Perception', 'insight' => 'Insight',
    'judgment' => 'Judgment', 'cognition' => 'Cognition', 'orientation' => 'Orientation',
];

$riskDotColor = static function (string $level): string {
    return match ($level) {
        'Tinggi', 'Berat' => '#ef4444',
        'Sedang' => '#f59e0b',
        default => '#22c55e',
    };
};

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-4">
            <div>
                <h1 class="page-title">Laporan Asesmen Psikiatri</h1>
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
                    <button type="button" id="psyPrintBtn" class="btn-primary">
                        <?= ems_icon('printer', 'h-4 w-4') ?>
                        <span>Print / Save PDF</span>
                    </button>
                <?php endif; ?>
                <a href="psychiatry_center.php" class="btn-secondary">
                    <?= ems_icon('arrow-left', 'h-4 w-4') ?>
                    <span>Kembali</span>
                </a>
                <?php if ($canDelete): ?>
                    <form method="POST" action="psychiatry_report.php?id=<?= (int) $report['id'] ?>" onsubmit="return confirm('Hapus asesmen psikiatri #<?= (int) $report['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
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
            <div class="card-header">Identitas Pasien &amp; Asesmen</div>
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
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wide">DEPARTEMEN</div>
                    <div><?= htmlspecialchars((string) $report['department'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wide">TIPE ASESMEN</div>
                    <div><?= htmlspecialchars((string) $report['assessment_type'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-500 tracking-wide">PRIORITAS</div>
                    <div><?= htmlspecialchars((string) $report['priority'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Keluhan Utama &amp; Anamnesis</div>
            <div class="p-4 text-sm space-y-2">
                <div><span class="font-bold">Keluhan Utama:</span> <?= htmlspecialchars((string) $report['chief_complaint'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="whitespace-pre-line"><span class="font-bold">Anamnesis:</span> <?= htmlspecialchars((string) $report['anamnesis'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <?php if (!empty($chatHistory)): ?>
            <details class="card mb-4">
                <summary class="card-header cursor-pointer">Transkrip Wawancara Klinis (<?= count($chatHistory) ?> giliran)</summary>
                <div class="p-4 space-y-3 text-sm">
                    <?php foreach ($chatHistory as $entry): ?>
                        <?php $isAi = ($entry['role'] ?? '') === 'ai'; ?>
                        <div class="rounded-lg border p-3 <?= $isAi ? 'border-sky-200 bg-sky-50' : 'border-slate-200 bg-slate-50' ?>">
                            <div class="text-xs font-bold uppercase tracking-wide <?= $isAi ? 'text-sky-700' : 'text-slate-500' ?>"><?= $isAi ? 'AI — Roxwood Specialist' : 'Pasien' ?></div>
                            <div class="mt-1"><?= htmlspecialchars((string) ($entry['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($report['status'] !== 'done'): ?>
            <div class="alert alert-error">
                Model AI gagal menghasilkan laporan ini: <?= htmlspecialchars((string) ($report['error_message'] ?? 'Kesalahan tidak diketahui.'), ENT_QUOTES, 'UTF-8') ?>
                Silakan buat ulang asesmen dari halaman Psychiatry Center.
            </div>
        <?php else: ?>

            <div class="card mb-4">
                <div class="card-header">Clinical Impressions (Final)</div>
                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ((array) ($result['clinical_impressions'] ?? []) as $imp): ?>
                        <div class="rounded-lg border border-slate-200 p-3">
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="font-bold"><?= htmlspecialchars((string) ($imp['condition'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="font-extrabold text-sky-600"><?= (int) ($imp['probability'] ?? 0) ?>%</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2">
                                <div class="bg-sky-500 h-2 rounded-full" style="width:<?= (int) ($imp['probability'] ?? 0) ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Diagnosis</div>
                <div class="p-4">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-flex items-center rounded-lg bg-sky-50 border border-sky-200 text-sky-800 px-2.5 py-1 text-xs font-extrabold"><?= htmlspecialchars((string) ($result['diagnosis']['code'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="font-bold text-lg"><?= htmlspecialchars((string) ($result['diagnosis']['primary'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="text-xs font-bold text-slate-500 tracking-wide mt-3">DIAGNOSIS BANDING</div>
                    <ul class="list-disc pl-5 mt-1 text-sm space-y-1">
                        <?php foreach ((array) ($result['diagnosis']['differential'] ?? []) as $item): ?>
                            <li><?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Mental Status Examination (MSE)</div>
                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ($mseLabels as $key => $label): ?>
                        <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-2">
                            <span class="text-sm font-semibold text-slate-600"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="text-sm text-right"><?= htmlspecialchars((string) ($result['mse'][$key] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Severity &amp; Risk Assessment</div>
                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php
                        $riskItems = [
                            'Severity' => (string) ($result['risk_assessment']['severity'] ?? '-'),
                            'Suicide Risk' => (string) ($result['risk_assessment']['suicide_risk'] ?? '-'),
                            'Violence Risk' => (string) ($result['risk_assessment']['violence_risk'] ?? '-'),
                            'Self Harm Risk' => (string) ($result['risk_assessment']['self_harm_risk'] ?? '-'),
                        ];
                    ?>
                    <?php foreach ($riskItems as $label => $value): ?>
                        <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                            <span class="text-sm font-semibold"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="inline-flex items-center gap-2 text-sm font-bold">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:999px;background:<?= $riskDotColor($value) ?>;"></span>
                                <?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Treatment Plan</div>
                <ul class="list-disc pl-5 p-4 text-sm space-y-1">
                    <?php foreach ((array) ($result['treatment_plan'] ?? []) as $item): ?>
                        <li><?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                    <?php if (empty($result['treatment_plan'])): ?>
                        <li class="text-slate-400 list-none -ml-5">Tidak ada rencana terapi tercatat.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="card mb-4">
                <div class="card-header">Medication Recommendation</div>
                <div class="table-wrapper">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Medikasi</th>
                                <th>Dosis</th>
                                <th>Frekuensi</th>
                                <th>Durasi</th>
                                <th>Rencana Pemantauan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ((array) ($result['medications'] ?? []) as $med): ?>
                                <tr>
                                    <td class="font-semibold"><?= htmlspecialchars((string) ($med['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($med['dose'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($med['frequency'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($med['duration'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($med['monitoring'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($result['medications'])): ?>
                                <tr><td colspan="5" class="text-center text-slate-400 py-6">Farmakoterapi tidak direkomendasikan saat ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Kesimpulan Klinis</div>
                <div class="p-4 text-sm whitespace-pre-line font-semibold"><?= htmlspecialchars((string) ($result['clinical_summary'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

        <?php endif; ?>
    </div>
</section>

<?php if ($report['status'] === 'done'): ?>
<div id="psyPrintTemplate" class="hidden">
    <div style="font-family: 'Times New Roman', serif; color:#1f2937; padding:32px; max-width:800px; margin:0 auto;">
        <div style="text-align:center; border-bottom:3px solid #0ea5e9; padding-bottom:12px; margin-bottom:20px;">
            <div style="font-size:20px; font-weight:bold; letter-spacing:1px;">ROXWOOD HOSPITAL PSYCHIATRY CENTER</div>
            <div style="font-size:12px; color:#6b7280;">Laporan Pemeriksaan Kesehatan Jiwa &amp; Asesmen Psikiatri</div>
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
                <td style="padding:3px 0;"><strong>Dokter</strong></td>
                <td style="padding:3px 0;">: <?= htmlspecialchars((string) ($report['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:3px 0;"><strong>Departemen</strong></td>
                <td style="padding:3px 0;">: <?= htmlspecialchars((string) $report['department'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        </table>

        <div style="font-size:12px; margin-bottom:12px;"><strong>Keluhan Utama:</strong> <?= htmlspecialchars((string) $report['chief_complaint'], ENT_QUOTES, 'UTF-8') ?></div>
        <div style="font-size:12px; margin-bottom:16px;"><strong>Anamnesis:</strong><br><?= nl2br(htmlspecialchars((string) $report['anamnesis'], ENT_QUOTES, 'UTF-8')) ?></div>

        <?php if ($report['status'] === 'done'): ?>
        <div style="font-size:12px; margin-bottom:12px;">
            <strong>Diagnosis:</strong> [<?= htmlspecialchars((string) ($result['diagnosis']['code'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>] <?= htmlspecialchars((string) ($result['diagnosis']['primary'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
            <br><strong>Diagnosis Banding:</strong> <?= htmlspecialchars(implode(', ', (array) ($result['diagnosis']['differential'] ?? [])), ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div style="font-size:12px; margin-bottom:12px;">
            <strong>Severity &amp; Risk:</strong>
            Severity <?= htmlspecialchars((string) ($result['risk_assessment']['severity'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>,
            Suicide Risk <?= htmlspecialchars((string) ($result['risk_assessment']['suicide_risk'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>,
            Violence Risk <?= htmlspecialchars((string) ($result['risk_assessment']['violence_risk'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>,
            Self Harm Risk <?= htmlspecialchars((string) ($result['risk_assessment']['self_harm_risk'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div style="font-size:12px; margin-bottom:12px;">
            <strong>Mental Status Examination:</strong>
            <table style="width:100%; border-collapse:collapse; margin-top:6px;">
                <?php foreach ($mseLabels as $key => $label): ?>
                    <tr>
                        <td style="border:1px solid #cbd5e1; padding:4px 6px; width:35%;"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="border:1px solid #cbd5e1; padding:4px 6px;"><?= htmlspecialchars((string) ($result['mse'][$key] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div style="font-size:12px; margin-bottom:12px;">
            <strong>Treatment Plan:</strong>
            <ul style="margin:4px 0 0 18px; padding:0;">
                <?php foreach ((array) ($result['treatment_plan'] ?? []) as $item): ?>
                    <li><?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div style="font-size:12px; margin-bottom:16px;">
            <strong>Rencana Farmakoterapi:</strong>
            <table style="width:100%; border-collapse:collapse; margin-top:6px;">
                <thead>
                    <tr style="background:#f1f5f9;">
                        <th style="border:1px solid #cbd5e1; padding:4px 6px; text-align:left;">Medikasi</th>
                        <th style="border:1px solid #cbd5e1; padding:4px 6px; text-align:left;">Dosis</th>
                        <th style="border:1px solid #cbd5e1; padding:4px 6px; text-align:left;">Frekuensi</th>
                        <th style="border:1px solid #cbd5e1; padding:4px 6px; text-align:left;">Durasi</th>
                        <th style="border:1px solid #cbd5e1; padding:4px 6px; text-align:left;">Pemantauan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ((array) ($result['medications'] ?? []) as $med): ?>
                        <tr>
                            <td style="border:1px solid #cbd5e1; padding:4px 6px;"><?= htmlspecialchars((string) ($med['name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="border:1px solid #cbd5e1; padding:4px 6px;"><?= htmlspecialchars((string) ($med['dose'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="border:1px solid #cbd5e1; padding:4px 6px;"><?= htmlspecialchars((string) ($med['frequency'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="border:1px solid #cbd5e1; padding:4px 6px;"><?= htmlspecialchars((string) ($med['duration'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="border:1px solid #cbd5e1; padding:4px 6px;"><?= htmlspecialchars((string) ($med['monitoring'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="font-size:12px; margin-bottom:20px;"><strong>Kesimpulan Klinis:</strong><br><?= nl2br(htmlspecialchars((string) ($result['clinical_summary'] ?? '-'), ENT_QUOTES, 'UTF-8')) ?></div>
        <?php endif; ?>

        <div style="display:flex; justify-content:flex-end; margin-top:40px;">
            <div style="text-align:center; font-size:12px;">
                <div>Roxwood, <?= htmlspecialchars(date('d/m/Y', strtotime((string) $report['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                <div style="height:60px;"></div>
                <div style="border-top:1px solid #1f2937; padding-top:4px; font-weight:bold;"><?= htmlspecialchars((string) ($report['doctor_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                <div style="font-size:10px; color:#6b7280;">Dokter Spesialis Pemeriksa</div>
            </div>
        </div>

        <div style="text-align:center; margin-top:24px; font-size:10px; color:#9ca3af; border-top:1px solid #e5e7eb; padding-top:8px;">
            Dokumen ini dihasilkan otomatis oleh Psychiatry Center — Roxwood Hospital Medical Center. Kode Referensi: <?= htmlspecialchars((string) ($report['report_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?>
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

var printBtn = document.getElementById('psyPrintBtn');
if (printBtn) {
    printBtn.addEventListener('click', function () {
        var content = document.getElementById('psyPrintTemplate').innerHTML;
        var win = window.open('', '_blank');
        win.document.open();
        win.document.write('<!doctype html><html><head><title>Laporan Asesmen Psikiatri</title><meta charset="utf-8"></head><body>' + content + '</body></html>');
        win.document.close();
        win.focus();
        setTimeout(function () { win.print(); }, 300);
    });
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
