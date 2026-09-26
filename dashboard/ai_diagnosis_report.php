<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/ai_radiology.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_diagnosis_assistant.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

$pageTitle = 'Laporan Diagnosis | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$canDelete = function_exists('ems_is_manager_plus_role') ? ems_is_manager_plus_role($user['role'] ?? '') : false;

$reportId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        $_SESSION['flash_errors'] = ['Sesi kedaluwarsa, silakan coba lagi.'];
        header('Location: ai_diagnosis_report.php?id=' . $reportId);
        exit;
    }
    if (!$canDelete) {
        $_SESSION['flash_errors'] = ['Anda tidak memiliki akses untuk menghapus laporan ini.'];
        header('Location: ai_diagnosis_report.php?id=' . $reportId);
        exit;
    }
    $del = $pdo->prepare("DELETE FROM ai_diagnosis_reports WHERE id = ? AND unit_code = ?");
    $del->execute([$reportId, $effectiveUnit]);
    $_SESSION['flash_messages'] = ['Laporan diagnosis berhasil dihapus.'];
    header('Location: ai_diagnosis_assistant.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.*, u.full_name AS created_by_name
    FROM ai_diagnosis_reports r
    LEFT JOIN user_rh u ON u.id = r.user_id
    WHERE r.id = ? AND r.unit_code = ?
");
$stmt->execute([$reportId, $effectiveUnit]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['flash_errors'] = ['Laporan diagnosis tidak ditemukan.'];
    header('Location: ai_diagnosis_assistant.php');
    exit;
}
$priorMedicalRecords = [];
$lookupCitizen = trim((string) ($report['patient_citizen_id'] ?? ''));
$lookupName = trim((string) ($report['patient_name'] ?? ''));
if ($lookupCitizen !== '' || $lookupName !== '') {
    $hasMedicalUnit = ems_column_exists($pdo, 'medical_records', 'unit_code');
    $hasMedicalCitizen = ems_column_exists($pdo, 'medical_records', 'patient_citizen_id');
    $hasMedicalJenis = ems_column_exists($pdo, 'medical_records', 'jenis_operasi');
    $where = [];
    $params = [];
    if ($hasMedicalCitizen && $lookupCitizen !== '') {
        $where[] = 'LOWER(TRIM(COALESCE(r.patient_citizen_id, \'\'))) = LOWER(TRIM(?))';
        $params[] = $lookupCitizen;
    } elseif ($lookupName !== '') {
        $where[] = 'LOWER(TRIM(r.patient_name)) = LOWER(TRIM(?))';
        $params[] = $lookupName;
    }
    if ($hasMedicalUnit) {
        $where[] = 'r.unit_code = ?';
        $params[] = $effectiveUnit;
    }
    if ($where !== []) {
        $sql = "SELECT r.id, r.patient_name, " . ($hasMedicalCitizen ? "r.patient_citizen_id" : "''") . " AS patient_citizen_id, r.operasi_type, " . ($hasMedicalJenis ? "r.jenis_operasi" : "''") . " AS jenis_operasi, r.created_at FROM medical_records r WHERE " . implode(' AND ', $where) . " ORDER BY r.created_at DESC LIMIT 10";
        $historyStmt = $pdo->prepare($sql);
        $historyStmt->execute($params);
        $priorMedicalRecords = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$roxyCaseCode = trim((string) ($report['report_code'] ?? ''));

$result = [];
if ($report['status'] === 'done' && $report['result_json']) {
    $decoded = json_decode((string) $report['result_json'], true);
    if (is_array($decoded)) {
        // Laporan final ditampilkan persis seperti keluaran model yang tersimpan.
        // Tidak ada normalizer/finalizer yang boleh mengubah isi klinis di sini.
        $result = $decoded;
    }
}
$displayModelItem = static function (mixed $item): string {
    if (is_string($item) || is_numeric($item)) {
        return (string) $item;
    }
    if (is_array($item)) {
        return trim((string) ($item['hasil'] ?? $item['result'] ?? $item['name'] ?? $item['test'] ?? json_encode($item, JSON_UNESCAPED_UNICODE)));
    }
    return '';
};
$priorSurgery = is_array($result['riwayat_operasi'] ?? null) ? $result['riwayat_operasi'] : [];
$displayGender = (string) ($report['patient_gender'] ?? '');
if (preg_match('/\b(?:hamil|janin|plasenta|gravid|abrupsi|kehamilan|seksio\s+sesarea|caesar)\b/iu', (string) ($report['anamnesis'] ?? '') . ' ' . ems_ai_ds_case_text($result)) === 1) {
    $displayGender = 'Perempuan';
}
$gcsComponents = [];
$gcsRaw = $result['gcs'] ?? '';
$gcsActual = is_array($gcsRaw)
    ? trim((string) ($gcsRaw['score'] ?? $gcsRaw['value'] ?? json_encode($gcsRaw, JSON_UNESCAPED_UNICODE)))
    : trim((string) $gcsRaw);
$gcsDisplay = $gcsActual !== '' ? $gcsActual : 'Data belum tersedia';
$gcsTotal = ems_ai_ds_gcs_total($gcsDisplay);
if (preg_match('/E\s*([1-4])\s*V\s*([1-5])\s*M\s*([1-6])/i', $gcsDisplay, $gcsParts)) {
    $gcsDescriptions = [
        'E' => [1 => 'Tidak membuka mata', 2 => 'Membuka mata terhadap rangsang nyeri', 3 => 'Membuka mata saat dipanggil', 4 => 'Membuka mata spontan'],
        'V' => [1 => 'Tidak bersuara', 2 => 'Suara tidak jelas/mengerang', 3 => 'Kata-kata tidak sesuai', 4 => 'Bicara bingung/disorientasi', 5 => 'Orientasi baik'],
        'M' => [1 => 'Tidak ada respons motorik', 2 => 'Ekstensi abnormal', 3 => 'Fleksi abnormal/dekortikasi', 4 => 'Menarik diri terhadap nyeri (withdrawal)', 5 => 'Melokalisasi nyeri', 6 => 'Mengikuti perintah'],
    ];
    foreach (['E', 'V', 'M'] as $index => $component) {
        $score = (int) $gcsParts[$index + 1];
        $gcsComponents[] = ['label' => $component . $score, 'text' => $gcsDescriptions[$component][$score]];
    }
    $gcsTotalMeaning = $gcsTotal !== null
        ? ($gcsTotal <= 8 ? 'GCS ≤8: penurunan kesadaran berat/koma; airway perlu diamankan dan pasien masuk prioritas resusitasi.' : ($gcsTotal <= 12 ? 'GCS 9–12: penurunan kesadaran sedang; lakukan monitoring ketat dan reassessment neurologis.' : 'GCS 13–15: penurunan kesadaran ringan atau sadar baik, tetap cocokkan dengan kondisi klinis.'))
        : 'Total GCS belum dapat dihitung.';
} else {
    $gcsTotalMeaning = 'Format GCS belum lengkap sehingga komponen tidak dapat dijelaskan.';
}

$roleBadgeClass = static function (string $role): string {
    return match ($role) {
        'DPJP' => 'inline-flex items-center rounded-full border border-blue-300 bg-blue-50 text-blue-800 px-3 py-1 text-xs font-bold',
        'Asisten 2' => 'inline-flex items-center rounded-full border border-amber-300 bg-amber-50 text-amber-800 px-3 py-1 text-xs font-bold',
        default => 'inline-flex items-center rounded-full border border-emerald-300 bg-emerald-50 text-emerald-800 px-3 py-1 text-xs font-bold',
    };
};
$emergencyLine = static function (array $action): string {
    $instruksi = trim((string) ($action['instruksi'] ?? ''));
    return trim(
        ($instruksi !== '' ? $instruksi . "\n" : '')
        . '/e ' . ($action['animasi'] ?? 'mechanic') . "\n"
        . '/me ' . ($action['aksi'] ?? '') . "\n"
        . '/do ' . ($action['hasil'] ?? '')
    );
};
$emergencyItems = is_array($result['emergency'] ?? null) ? $result['emergency'] : [];
$mantraAllText = implode("\n\n", array_map($emergencyLine, $emergencyItems));
$labText = mb_strtolower(implode(' ', array_map('strval', (array) ($result['lab'] ?? []))));
$radText = mb_strtolower(implode(' ', array_map('strval', (array) ($result['radiologi'] ?? []))));
$labMandatory = preg_match('/crossmatch|golongan darah|laktat|hemoglobin|darah lengkap|koagulasi|syok|perdarahan/iu', $labText . ' ' . (string) ($result['diagnosis_utama'] ?? '')) === 1;
$radMandatory = preg_match('/fraktur|patah|benda asing|proyektil|tembak|dislokasi|cedera kepala|abdomen|tulang/iu', $radText . ' ' . (string) ($result['diagnosis_utama'] ?? '') . ' ' . (string) ($result['kasus_tindakan'] ?? '')) === 1;
$estimateStatusLabel = function_exists('ems_ai_ds_estimate_status_label')
    ? ems_ai_ds_estimate_status_label()
    : 'Estimasi AI — wajib verifikasi';
$missingClinicalValue = static function (string $value): bool {
    return in_array(mb_strtolower(trim($value)), ['', '-', 'data belum tersedia', 'belum diukur', 'belum dinilai'], true);
};
$gcsRaw = $result['gcs'] ?? '';
$gcsActual = is_array($gcsRaw)
    ? trim((string) ($gcsRaw['score'] ?? $gcsRaw['value'] ?? json_encode($gcsRaw, JSON_UNESCAPED_UNICODE)))
    : trim((string) $gcsRaw);
$gcsEstimate = is_array($result['gcs_estimasi_ai'] ?? null) ? $result['gcs_estimasi_ai'] : null;
$gcsIsEstimate = $missingClinicalValue($gcsActual) && $gcsEstimate !== null && trim((string) ($gcsEstimate['score'] ?? '')) !== '';
$gcsDisplay = $gcsIsEstimate ? (string) $gcsEstimate['score'] : ($gcsActual !== '' ? $gcsActual : 'Data belum tersedia');
$pupilAssessment = is_array($result['pemeriksaan_pupil'] ?? null) ? $result['pemeriksaan_pupil'] : [];
$operationPlanStatus = trim((string) ($result['status_rencana_operasi'] ?? ''));
$ttvDisplayItems = ems_ai_ds_prepare_ttv_display(
    $result['ttv'] ?? [],
    []
);
$bpValue = '';
foreach ($ttvDisplayItems as $vital) {
    if (preg_match('/tekanan\s*darah|\btd\b|\bbp\b/iu', (string) ($vital['label'] ?? ''))) {
        $bpValue = (string) ($vital['value'] ?? '');
        break;
    }
}
$bpNumbers = [];
if (preg_match('/(\d{2,3})\s*\/\s*(\d{2,3})/u', $bpValue, $bpMatch)) {
    $bpNumbers = [(int) $bpMatch[1], (int) $bpMatch[2]];
}
$bpGuidance = 'Normal dewasa umumnya sekitar 90–120 / 60–80 mmHg.';
if ($bpNumbers !== []) {
    [$bpSys, $bpDia] = $bpNumbers;
    if ($bpSys < 90 || $bpDia < 60) {
        $bpGuidance = 'TTV rendah/hipotensi: evaluasi ulang ABCDE, perdarahan, perfusi dan kesadaran; kontrol perdarahan, pertahankan akses IV, resusitasi sesuai instruksi DPJP, lalu reassessment berkala.';
    } elseif ($bpSys >= 140 || $bpDia >= 90) {
        $bpGuidance = 'TTV tinggi/hipertensi: ulangi pengukuran dengan manset sesuai, nilai nyeri, kecemasan, hipoksia, dan tanda neurologis; tangani penyebab serta ikuti instruksi DPJP, bukan menurunkan tekanan secara mendadak.';
    } else {
        $bpGuidance = 'TTV dalam rentang skenario stabil: tetap monitor berkala dan reassessment bila nyeri, perdarahan, sesak, atau kesadaran berubah.';
    }
}
$ttvGuidance = [
    ['title' => 'Tekanan Darah', 'normal' => 'Sekitar 90–120 / 60–80 mmHg', 'action' => 'Rendah: ulangi ukur, cek perdarahan/perfusi/kesadaran, pertahankan akses IV dan lakukan resusitasi sesuai kondisi. Tinggi: ulangi ukur, cek nyeri, hipoksia, kecemasan, dan tanda neurologis; tangani penyebabnya.'],
    ['title' => 'Nadi / HR', 'normal' => '60–100 x/menit', 'action' => 'Cepat: cari perdarahan, nyeri, demam, hipoksia, atau syok; pasang monitor dan reassessment. Lambat: cek kesadaran, perfusi, oksigenasi, obat, dan lakukan evaluasi DPJP.'],
    ['title' => 'Respirasi / RR', 'normal' => '12–20 x/menit', 'action' => 'Cepat atau dangkal: nilai airway dan breathing, berikan oksigen, cek ekspansi/suara napas, dan siapkan bantuan airway bila memburuk. Lambat atau tidak efektif: amankan airway dan bantu ventilasi sesuai kondisi.'],
    ['title' => 'Suhu', 'normal' => '36,0–37,5 °C', 'action' => 'Rendah: hangatkan pasien, ganti pakaian/selimut basah, monitor koagulasi dan perfusi. Tinggi: evaluasi infeksi, lingkungan, obat, hidrasi, dan lakukan pendinginan bertahap bila diperlukan.'],
    ['title' => 'Saturasi O₂ / SpO₂', 'normal' => '95–100% pada dewasa tanpa kondisi khusus', 'action' => 'Turun: pastikan sensor dan airway benar, posisikan pasien, berikan oksigen, nilai breathing, dan eskalasi airway bila tidak membaik.'],
];
$gcsTotal = ems_ai_ds_gcs_total($gcsDisplay);
$mantraAllText = implode("\n\n", array_map($emergencyLine, $emergencyItems));

$fullReportText = ems_ai_ds_format_diagnosis_report_text(
    $report,
    $result,
    $ttvDisplayItems,
    $gcsDisplay,
    $gcsIsEstimate,
    $gcsEstimate
);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-4">
            <div>
                <h1 class="page-title">Laporan Diagnosis IGD</h1>
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
                        <span class="text-xs text-slate-400">— tempel di AI Surgery Planner / Radiology Center untuk ambil data kasus ini otomatis</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <?php if ($report['status'] === 'done'): ?>
                    <button type="button" class="btn-primary mantra-copy-btn" data-copy-target="#fullReportText" data-copy="<?= htmlspecialchars($fullReportText, ENT_QUOTES, 'UTF-8') ?>">
                        <?= ems_icon('clipboard-document-list', 'h-4 w-4') ?>
                        <span>Salin Semua Teks</span>
                    </button>
                <?php endif; ?>
                <a href="ai_diagnosis_assistant.php" class="btn-secondary">
                    <?= ems_icon('arrow-left', 'h-4 w-4') ?>
                    <span>Kembali</span>
                </a>
                <?php if ($canDelete): ?>
                    <form method="POST" action="ai_diagnosis_report.php?id=<?= (int) $report['id'] ?>" onsubmit="return confirm('Hapus laporan diagnosis #<?= (int) $report['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
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

        <?php if (!empty($report['patient_name']) || !empty($report['patient_gender']) || !empty($report['patient_dob']) || !empty($report['patient_citizen_id'])): ?>
            <div class="card mb-4">
                <div class="card-header">Identitas Pasien</div>
                <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div>
                        <div class="text-xs font-bold text-slate-500 tracking-wide">NAMA</div>
                        <div><?= htmlspecialchars((string) ($report['patient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500 tracking-wide">JENIS KELAMIN</div>
                        <div><?= htmlspecialchars($displayGender !== '' ? $displayGender : '-', ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500 tracking-wide">TANGGAL LAHIR</div>
                        <div><?= $report['patient_dob'] ? htmlspecialchars(date('d/m/Y', strtotime((string) $report['patient_dob'])), ENT_QUOTES, 'UTF-8') : '-' ?></div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-slate-500 tracking-wide">CITIZEN ID</div>
                        <div><?= htmlspecialchars((string) ($report['patient_citizen_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php $anamnesisLengkap = trim((string) ($result['anamnesis_lengkap'] ?? '')); ?>
        <div class="card mb-4">
            <div class="card-header"><?= $anamnesisLengkap !== '' ? 'Anamnesis / Temuan Medis (Lengkap &amp; Direvisi AI)' : 'Anamnesis / Temuan Medis' ?></div>
            <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars($anamnesisLengkap !== '' ? $anamnesisLengkap : (string) $report['anamnesis'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($anamnesisLengkap !== '' && $anamnesisLengkap !== trim((string) $report['anamnesis'])): ?>
                <details class="px-4 pb-4">
                    <summary class="cursor-pointer text-xs font-semibold text-slate-500">Lihat input anamnesis asli (sebelum dilengkapi AI)</summary>
                    <div class="mt-2 text-sm text-slate-600 whitespace-pre-line"><?= htmlspecialchars((string) $report['anamnesis'], ENT_QUOTES, 'UTF-8') ?></div>
                </details>
            <?php endif; ?>
        </div>

        <?php $physiologicConsistency = is_array($result['konsistensi_fisiologis'] ?? null) ? $result['konsistensi_fisiologis'] : []; ?>
        <?php if (($physiologicConsistency['status'] ?? '') === 'red_flag'): ?>
            <div class="card mb-4 border-l-4 border-red-600 bg-red-50">
                <div class="card-header text-red-800">RED FLAG — Ketidaksesuaian Fisiologis</div>
                <div class="p-4 text-sm text-red-900 space-y-2">
                    <p class="font-semibold"><?= htmlspecialchars((string) ($physiologicConsistency['red_flag'] ?? ems_ai_ds_physiologic_red_flag_text()), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if (trim((string) ($physiologicConsistency['alasan'] ?? '')) !== ''): ?>
                        <p><?= htmlspecialchars((string) $physiologicConsistency['alasan'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($report['status'] !== 'done'): ?>
            <div class="alert alert-error">
                Model AI gagal menghasilkan laporan ini: <?= htmlspecialchars((string) ($report['error_message'] ?? 'Kesalahan tidak diketahui.'), ENT_QUOTES, 'UTF-8') ?>
                Silakan buat ulang diagnosis dari halaman AI Diagnosis Assistant.
            </div>
        <?php else: ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="card">
                    <div class="card-header">Diagnosis Utama & Banding</div>
                    <div class="p-4">
                        <div class="text-xs font-bold text-slate-500 tracking-wide">DIAGNOSIS UTAMA</div>
                        <p class="text-lg font-bold mt-1"><?= htmlspecialchars((string) ($result['diagnosis_utama'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="text-xs font-bold text-slate-500 tracking-wide mt-4">DIAGNOSIS BANDING</div>
                        <ul class="list-disc pl-5 mt-1 text-sm space-y-1">
                            <?php foreach ((array) ($result['diagnosis_banding'] ?? []) as $item): ?>
                                <li><?= htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">GCS Score</div>
                    <div class="p-4 flex flex-col items-center justify-center gap-2 text-center">
                        <div class="text-3xl font-bold font-mono break-words"><?= htmlspecialchars($gcsDisplay, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($gcsComponents !== []): ?>
                            <div class="w-full mt-2 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-left text-xs text-indigo-950">
                                <div class="font-bold mb-1">Arti Komponen GCS</div>
                                <?php foreach ($gcsComponents as $component): ?>
                                    <div><strong><?= htmlspecialchars($component['label'], ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($component['text'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endforeach; ?>
                                <div class="mt-2 pt-2 border-t border-indigo-200"><strong>Total <?= htmlspecialchars((string) ($gcsTotal ?? '-'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($gcsTotalMeaning, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($gcsIsEstimate): ?>
                            <div class="text-xs font-semibold text-amber-700"><?= htmlspecialchars($estimateStatusLabel, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (trim((string) ($gcsEstimate['basis'] ?? '')) !== ''): ?>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars((string) $gcsEstimate['basis'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($pupilAssessment !== [] && trim((string) ($pupilAssessment['status'] ?? '')) !== '' && !preg_match('/data belum|belum tersedia/iu', (string) ($pupilAssessment['status'] ?? ''))): ?>
                            <div class="mt-4 w-full border-t border-slate-200 pt-3 text-left text-xs text-slate-600">
                                <div class="font-bold text-slate-500">Disability — Pemeriksaan Pupil</div>
                                <div>Status: <?= htmlspecialchars((string) ($pupilAssessment['status'] ?? 'Data belum tersedia'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div>Reaktivitas: <?= htmlspecialchars((string) ($pupilAssessment['reaktivitas'] ?? 'Data belum tersedia'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($operationPlanStatus !== ''): ?>
                <div class="card mb-4 border-l-4 <?= str_contains(mb_strtolower($operationPlanStatus), 'cito') ? 'border-red-500' : 'border-amber-500' ?>">
                    <div class="card-header">Status Rencana Operasi</div>
                    <div class="p-4 text-sm font-semibold"><?= htmlspecialchars($operationPlanStatus, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            <?php endif; ?>

            <?php if ($priorMedicalRecords !== []): ?>
                <div class="card mb-4 border border-amber-200">
                    <div class="card-header">Riwayat Operasi / Rekam Medis Sebelumnya</div>
                    <div class="p-4 text-sm">
                        <p class="text-xs text-slate-500 mb-3">Pencocokan berdasarkan Citizen ID terlebih dahulu, lalu nama pasien. Gunakan riwayat ini untuk mencegah tindakan ulang yang tidak logis pada lokasi yang sama.</p>
                        <div class="space-y-2">
                            <?php foreach ($priorMedicalRecords as $history): ?>
                                <a class="block rounded-lg border border-amber-100 bg-amber-50 p-3 hover:bg-amber-100" href="<?= htmlspecialchars(ems_url('/dashboard/rekam_medis_view.php?id=' . (int) $history['id']), ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="font-bold"><?= htmlspecialchars((string) ($history['patient_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs mt-1">Citizen ID: <?= htmlspecialchars((string) ($history['patient_citizen_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) (($history['operasi_type'] ?? '') === 'major' ? 'Mayor' : 'Minor'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (trim((string) ($history['jenis_operasi'] ?? '')) !== ''): ?><div class="text-xs mt-1">Tindakan: <?= htmlspecialchars((string) $history['jenis_operasi'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                    <div class="text-xs text-slate-500 mt-1">Tanggal: <?= htmlspecialchars((string) ($history['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Full report text is kept hidden for the single top-level copy button. -->
            <textarea id="fullReportText" readonly class="sr-only"><?= htmlspecialchars($fullReportText, ENT_QUOTES, 'UTF-8') ?></textarea>
            <!-- duplicate full-report copy card removed; use the top "Salin Semua Teks" button -->
            <div class="card mb-4">
                <div class="card-header flex items-center justify-between gap-3 flex-wrap">
                    <span>Kasus Medis / Tindakan yang Diperlukan</span>
                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars((string) ($result['kasus_tindakan'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <?= ems_icon('clipboard-document-check', 'h-4 w-4') ?>
                        <span>Salin untuk AI Surgery Planner</span>
                    </button>
                </div>
                <div class="p-4 text-sm whitespace-pre-line"><?= htmlspecialchars((string) ($result['kasus_tindakan'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="card">
                    <div class="card-header">Jenis Operasi</div>
                    <div class="p-4 text-sm"><?= htmlspecialchars((string) ($result['jenis_operasi'] ?? 'Belum ditentukan'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="card">
                    <div class="card-header">Jenis Anestesi</div>
                    <div class="p-4 text-sm"><?= htmlspecialchars((string) ($result['jenis_anestesi'] ?? 'Belum ditentukan'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Tanda-Tanda Vital (TTV)</div>
                <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php foreach ($ttvDisplayItems as $vital): ?>
                        <div class="rounded-lg border <?= !empty($vital['_is_estimate']) ? 'border-amber-300 bg-amber-50' : 'border-slate-200' ?> p-3 min-w-0">
                            <div class="text-xs text-slate-500 font-semibold"><?= htmlspecialchars((string) ($vital['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-lg font-bold mt-1 break-words">
                                <?= htmlspecialchars((string) ($vital['value'] ?? 'Data belum tersedia'), ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($vital['_is_estimate'])): ?>
                                    <span class="text-xs font-semibold text-amber-700">[<?= htmlspecialchars((string) ($vital['_estimate_status'] ?? $estimateStatusLabel), ENT_QUOTES, 'UTF-8') ?>]</span>
                                <?php endif; ?>
                            </div>
                            <?php if (trim((string) ($vital['note'] ?? '')) !== ''): ?>
                                <div class="text-xs text-slate-500">(<?= htmlspecialchars((string) $vital['note'], ENT_QUOTES, 'UTF-8') ?>)</div>
                            <?php endif; ?>
                            <?php if (!empty($vital['_is_estimate']) && trim((string) ($vital['_estimate_basis'] ?? '')) !== ''): ?>
                                <div class="text-xs text-amber-800 mt-1"><?= htmlspecialchars((string) $vital['_estimate_basis'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($ttvDisplayItems === []): ?>
                        <div class="col-span-full text-sm text-slate-500">Data TTV belum tersedia dari model.</div>
                    <?php endif; ?>
                </div>
        </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="card">
                    <div class="card-header">Rekomendasi Laboratorium</div>
                    <ul class="list-disc pl-5 p-4 pb-2 text-sm space-y-1">
                        <?php foreach ((array) ($result['lab'] ?? []) as $item): ?>
                            <li><?= htmlspecialchars($displayModelItem($item), ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                        <?php if (empty($result['lab'])): ?>
                            <li class="text-slate-400 list-none -ml-5">Tidak ada rekomendasi laboratorium.</li>
                        <?php endif; ?>
                    </ul>
                    <?php
                        $structLab = is_array($result['laboratorium_terstruktur'] ?? null) ? $result['laboratorium_terstruktur'] : null;
                        $structLabHasDept = $structLab && trim((string) ($structLab['department'] ?? '')) !== '';
                    ?>
                    <?php if ($structLabHasDept): ?>
                        <div class="mx-4 mb-4 rounded-lg border border-cyan-200 bg-cyan-50 p-3">
                            <div class="text-xs font-bold text-cyan-800 uppercase tracking-wide mb-1.5">Pilihan Laboratory AI</div>
                            <div class="text-xs text-cyan-900 leading-relaxed">
                                <?= htmlspecialchars((string) $structLab['department'], ENT_QUOTES, 'UTF-8') ?>
                                &rsaquo; <?= htmlspecialchars((string) $structLab['category'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if (trim((string) ($structLab['level3_option'] ?? '')) !== ''): ?>
                                    &rsaquo; <?= htmlspecialchars((string) $structLab['level3_option'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                                <br>Spesimen: <strong><?= htmlspecialchars((string) ($structLab['specimen_type'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <p class="text-[11px] text-cyan-700 mt-1.5">Pilihan ini ditentukan model AI berdasarkan kasus dan konteks SOP dari Modul Dokumen.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <div class="card-header">Rekomendasi Radiologi</div>
                    <ul class="list-disc pl-5 p-4 pb-2 text-sm space-y-1">
                        <?php foreach ((array) ($result['radiologi'] ?? []) as $item): ?>
                            <li><?= htmlspecialchars($displayModelItem($item), ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                        <?php if (empty($result['radiologi'])): ?>
                            <li class="text-slate-400 list-none -ml-5">Tidak ada rekomendasi radiologi.</li>
                        <?php endif; ?>
                    </ul>
                    <?php
                        $structRad = is_array($result['radiologi_terstruktur'] ?? null) ? $result['radiologi_terstruktur'] : null;
                        $structRadHasModality = $structRad && trim((string) ($structRad['modality'] ?? '')) !== '';
                    ?>
                    <?php if ($structRadHasModality): ?>
                        <div class="mx-4 mb-4 rounded-lg border border-cyan-200 bg-cyan-50 p-3">
                            <div class="text-xs font-bold text-cyan-800 uppercase tracking-wide mb-1.5">Pilihan Radiology Center</div>
                            <div class="text-xs text-cyan-900 leading-relaxed">
                                <?= htmlspecialchars((string) $structRad['modality'], ENT_QUOTES, 'UTF-8') ?>
                                &rsaquo; <?= htmlspecialchars((string) $structRad['category'], ENT_QUOTES, 'UTF-8') ?>
                                &rsaquo; <?= htmlspecialchars((string) $structRad['body_region'], ENT_QUOTES, 'UTF-8') ?>
                                &rsaquo; <?= htmlspecialchars((string) $structRad['projection'], ENT_QUOTES, 'UTF-8') ?>
                                <br>Temuan Klinis: <strong><?= htmlspecialchars((string) ($structRad['clinical_finding'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                            <p class="text-[11px] text-cyan-700 mt-1.5">Otomatis terisi kalau kode referensi laporan ini ditempel di Radiology Center.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header flex items-center justify-between gap-3 flex-wrap">
                    <span>Penanganan Emergency (BLS &amp; Initial Care)</span>
                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars($mantraAllText, ENT_QUOTES, 'UTF-8') ?>">
                        <?= ems_icon('clipboard-document-check', 'h-4 w-4') ?>
                        <span>Salin Semua Mantra</span>
                    </button>
                </div>
                <div class="p-4 space-y-4">
                    <?php foreach ($emergencyItems as $i => $action): ?>
                        <?php
                            $pelaku = (string) ($action['pelaku'] ?? 'DPJP');
                            $instruksi = (string) ($action['instruksi'] ?? '');
                            $aksi = (string) ($action['aksi'] ?? '');
                            $hasil = (string) ($action['hasil'] ?? '');
                            $anim = (string) ($action['animasi'] ?? 'mechanic');
                        ?>
                        <div class="rounded-lg border border-slate-200 p-4">
                            <div class="flex items-center gap-3 mb-3 flex-wrap">
                                <div class="w-7 h-7 shrink-0 rounded-full bg-slate-100 flex items-center justify-center font-bold text-sm"><?= (int) $i + 1 ?></div>
                                <span class="text-xs font-bold text-slate-400 tracking-wide">TINDAKAN <?= (int) $i + 1 ?></span>
                                <span class="<?= $roleBadgeClass($pelaku) ?>"><?= htmlspecialchars($pelaku, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php if ($instruksi !== ''): ?>
                                <div class="mb-3 md:ml-10 rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-xs italic text-slate-600 whitespace-pre-line"><?= htmlspecialchars($instruksi, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <div class="space-y-2 text-sm md:ml-10">
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                    <div><span class="font-bold">/e</span> <?= htmlspecialchars($anim, ENT_QUOTES, 'UTF-8') ?></div>
                                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars('/e ' . $anim, ENT_QUOTES, 'UTF-8') ?>">Salin</button>
                                </div>
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                    <div><span class="font-bold">/me</span> <?= htmlspecialchars($aksi, ENT_QUOTES, 'UTF-8') ?></div>
                                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars('/me ' . $aksi, ENT_QUOTES, 'UTF-8') ?>">Salin</button>
                                </div>
                                <div class="flex items-center justify-between gap-3 rounded-lg border-l-4 border-emerald-500 bg-emerald-50 px-3 py-2">
                                    <div><span class="font-bold">/do</span> <?= htmlspecialchars($hasil, ENT_QUOTES, 'UTF-8') ?></div>
                                    <button type="button" class="btn-secondary btn-sm mantra-copy-btn" data-copy="<?= htmlspecialchars('/do ' . $hasil, ENT_QUOTES, 'UTF-8') ?>">Salin</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($emergencyItems)): ?>
                        <p class="text-sm text-slate-400">Tidak ada langkah penanganan.</p>
                    <?php endif; ?>
                    <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-xs text-indigo-900">
                        <div class="font-bold mb-1">Handoff Pemeriksaan Penunjang</div>
                        <div>Laboratorium: <strong><?= $labMandatory ? 'WAJIB — ambil sampel darah sekarang' : 'OPSIONAL — lakukan bila ada indikasi klinis atau perubahan kondisi' ?></strong>.</div>
                        <div>Radiologi: <strong><?= $radMandatory ? 'WAJIB — lanjutkan setelah stabilisasi' : 'OPSIONAL — lanjutkan bila diperlukan oleh temuan klinis' ?></strong>.</div>
                        <div class="mt-1">Setelah pemeriksaan yang berstatus wajib selesai, lakukan reassessment ABCDE dan serah-terima ke tahap berikutnya sesuai rencana laporan.</div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Catatan Medis &amp; Roleplay</div>
                <div class="p-4 rounded-b-lg bg-slate-800 text-emerald-300 font-mono text-xs leading-relaxed whitespace-pre-line"><?= htmlspecialchars((string) ($result['roleplay_note'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="card mb-4 border border-violet-200">
                <div class="card-header flex items-center justify-between gap-3 flex-wrap">
                    <span>Tanya Roxy tentang Kasus Ini</span>
                    <button type="button" id="askRoxyCaseBtn" class="btn-primary btn-sm" data-case-code="<?= htmlspecialchars($roxyCaseCode, ENT_QUOTES, 'UTF-8') ?>">Buka Roxy</button>
                </div>
                <div class="p-4 text-sm text-slate-600">Tanyakan kondisi pasien, arti TTV, pertanyaan keluarga, atau langkah roleplay. Roxy akan dikunci ke kode kasus <strong><?= htmlspecialchars($roxyCaseCode !== '' ? $roxyCaseCode : ('ID #' . $reportId), ENT_QUOTES, 'UTF-8') ?></strong> agar tidak mengambil kasus lain.</div>
            </div>

            <?php if (!empty($priorSurgery) && (!empty($priorSurgery['ada']) || trim((string) ($priorSurgery['ringkasan'] ?? '')) !== '')): ?>
                <div class="card mb-4 border border-amber-200">
                    <div class="card-header">Riwayat Operasi Sebelumnya &amp; Pertimbangan Tindakan</div>
                    <div class="p-4 text-sm space-y-2">
                        <div><?= nl2br(htmlspecialchars((string) ($priorSurgery['ringkasan'] ?? 'Riwayat operasi tercatat pada kasus ini.'), ENT_QUOTES, 'UTF-8')) ?></div>
                        <?php if (trim((string) ($priorSurgery['rencana_penanganan'] ?? '')) !== ''): ?>
                            <div class="rounded-lg bg-amber-50 border border-amber-200 p-3"><strong>Keputusan AI:</strong><br><?= nl2br(htmlspecialchars((string) $priorSurgery['rencana_penanganan'], ENT_QUOTES, 'UTF-8')) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <details class="card p-4">
                <summary class="cursor-pointer font-bold">Rujukan SOP</summary>
                <ul class="list-disc pl-5 mt-3 text-sm space-y-1">
                    <?php foreach ((array) ($result['sop_references'] ?? []) as $ref): ?>
                        <li><?= htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>

        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('click', function (e) {
    var roxyBtn = e.target.closest('#askRoxyCaseBtn');
    if (roxyBtn) {
        var code = roxyBtn.getAttribute('data-case-code') || '';
        var toggle = document.getElementById('roxyWidgetToggle');
        var panel = document.getElementById('roxyWidgetPanel');
        var input = document.getElementById('roxyWidgetInput');
        if (toggle) toggle.click();
        if (panel) panel.classList.remove('hidden');
        if (input) {
            input.value = code ? ('Gunakan hanya konteks kasus ' + code + '. Saya punya pertanyaan tentang pasien/kasus ini: ') : 'Saya punya pertanyaan tentang laporan ini: ';
            input.focus();
        }
        return;
    }
    var btn = e.target.closest('.mantra-copy-btn');
    if (!btn) return;

    var text = btn.getAttribute('data-copy') || '';
    var targetSelector = btn.getAttribute('data-copy-target');
    if (targetSelector) {
        var targetEl = document.querySelector(targetSelector);
        if (targetEl) {
            text = targetEl.value !== undefined ? targetEl.value : targetEl.textContent;
        }
    }
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
    try {
        document.execCommand('copy');
        showResult(true);
    } catch (err) {
        showResult(false);
    }
    document.body.removeChild(ta);
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
