<?php
// A 30-step roleplay plan can need more time than the PHP default while the
// configured provider completes its JSON response and quality checks run.
@set_time_limit(300);
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';

function ems_ai_ds_surgery_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_surgery_planner.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.'], 419);
}

$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$division = (string) ($user['division'] ?? '');

// "Generate Ulang" dari riwayat: pakai ulang PERSIS input dari baris asal
// (bukan dari form), dan lewati pengecekan kode-sudah-dipakai karena ini
// memang sengaja generate ulang dengan kode referensi yang sama.
$regenerateOfId = (int) ($_POST['regenerate_of'] ?? 0);
$diagnosisCode = null;
$isRegenerate = false;

if ($regenerateOfId > 0) {
    $origStmt = $pdo->prepare("SELECT * FROM ai_surgery_plans WHERE id = ? AND unit_code = ?");
    $origStmt->execute([$regenerateOfId, $effectiveUnit]);
    $orig = $origStmt->fetch(PDO::FETCH_ASSOC);
    if (!$orig) {
        ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Rencana operasi asal untuk generate ulang tidak ditemukan.'], 404);
    }
    $jenisOperasi = (string) $orig['jenis_operasi_kategori'];
    $jenisAnestesi = (string) $orig['jenis_anestesi_input'];
    $kompleksitas = (string) $orig['kompleksitas'];
    $kasusTindakan = (string) $orig['kasus_tindakan'];
    $diagnosisCode = $orig['source_report_code'] !== null ? (string) $orig['source_report_code'] : null;
    $isRegenerate = true;
} else {
    $jenisOperasi = in_array($_POST['jenis_operasi'] ?? '', ['Mayor', 'Minor'], true) ? $_POST['jenis_operasi'] : 'Mayor';
    $jenisAnestesi = trim((string) ($_POST['jenis_anestesi'] ?? ''));
    $kompleksitasInput = trim((string) ($_POST['kompleksitas'] ?? 'Auto'));
    $kompleksitas = in_array($kompleksitasInput, ['Mudah', 'Sedang', 'Panjang'], true) ? $kompleksitasInput : 'Auto';
    $kasusTindakan = trim((string) ($_POST['kasus_tindakan'] ?? ''));
    $diagnosisCodeInput = trim((string) ($_POST['diagnosis_code'] ?? ''));
    $diagnosisCode = $diagnosisCodeInput !== '' ? $diagnosisCodeInput : null;

    if ($jenisAnestesi === '' || $kasusTindakan === '') {
        ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Jenis anestesi dan kasus medis / tindakan wajib diisi.'], 422);
    }

    if ($diagnosisCode !== null && ems_ai_ds_report_code_used_on($pdo, 'ai_surgery_plans', $diagnosisCode, $effectiveUnit)) {
        ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Kode referensi ini sudah pernah dipakai di AI Surgery Planner. Gunakan tombol "Generate Ulang" pada riwayat kalau ingin membuat ulang dengan kode yang sama, atau pakai kode referensi lain.'], 409);
    }
}

$jenisOperasi = ems_ai_ds_effective_operation_category($jenisOperasi, $kasusTindakan);

// Attach the canonical diagnosis context server-side. Do not rely only on the
// browser's copied textarea: it may be stale or edited after fetching a code.
$diagnosisContext = '';
if ($diagnosisCode !== null) {
    $sourceReport = ems_ai_ds_find_diagnosis_report_by_code($pdo, $diagnosisCode, $effectiveUnit);
    if (!$sourceReport) {
        ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Kode laporan diagnosis tidak ditemukan pada unit aktif. Ambil ulang data diagnosis sebelum membuat rencana.'], 404);
    }
    $sourceData = [];
    $decodedSource = json_decode((string) ($sourceReport['result_json'] ?? ''), true);
    if (is_array($decodedSource)) {
        $sourceData = ems_ai_ds_normalize_diagnosis_result($decodedSource, (string) ($sourceReport['anamnesis'] ?? ''));
    }
    $sourceDob = trim((string) ($sourceReport['patient_dob'] ?? ''));
    $sourceAge = null;
    if ($sourceDob !== '') {
        try {
            $birthDate = new DateTimeImmutable($sourceDob);
            $sourceAge = $birthDate->diff(new DateTimeImmutable('today'))->y;
        } catch (Throwable $ignored) {
            $sourceAge = null;
        }
    }
    $diagnosisContext = json_encode([
        'kode_laporan' => (string) ($sourceReport['report_code'] ?? $diagnosisCode),
        'nama_pasien' => (string) ($sourceReport['patient_name'] ?? ''),
        'jenis_kelamin' => (string) ($sourceReport['patient_gender'] ?? ''),
        'tanggal_lahir' => $sourceDob,
        'usia_pada_tanggal_pemeriksaan' => $sourceAge,
        'anamnesis_awal' => (string) ($sourceReport['anamnesis'] ?? ''),
        'anamnesis_final' => (string) ($sourceData['anamnesis_lengkap'] ?? ''),
        'diagnosis_utama' => (string) ($sourceData['diagnosis_utama'] ?? ''),
        'diagnosis_banding' => $sourceData['diagnosis_banding'] ?? [],
        'kasus_tindakan' => (string) ($sourceData['kasus_tindakan'] ?? ''),
        'jenis_operasi' => (string) ($sourceData['jenis_operasi'] ?? ''),
        'jenis_anestesi' => (string) ($sourceData['jenis_anestesi'] ?? ''),
        'gcs' => (string) ($sourceData['gcs'] ?? ''),
        'ttv' => $sourceData['ttv'] ?? [],
        'tindakan_igd_selesai' => $sourceData['emergency'] ?? [],
        'hasil_lab_skenario' => $sourceData['lab'] ?? [],
        'rekomendasi_radiologi' => $sourceData['radiologi_terstruktur'] ?? [],
        'handoff' => $sourceData['handoff_pemeriksaan_penunjang'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$complexityEvidence = implode("\n", array_filter([$kasusTindakan, $diagnosisContext], static fn ($value) => trim((string) $value) !== ''));
if ($kompleksitas === 'Auto') {
    $kompleksitas = ems_ai_ds_recommend_surgery_complexity($complexityEvidence);
}

$stepCountMap = ['Mudah' => 10, 'Sedang' => 20, 'Panjang' => 30];
$jumlahLangkah = $stepCountMap[$kompleksitas];

$systemPrompt = ems_ai_ds_build_system_prompt($pdo, 'ai_surgery_planner', ems_ai_ds_default_surgery_system_prompt());
$template = ems_ai_get_active_prompt_template($pdo, 'ai_surgery_planner');
$userPromptTemplate = trim((string) ($template['user_prompt_template'] ?? '')) !== ''
    ? (string) $template['user_prompt_template']
    : "JENIS OPERASI: {{jenis_operasi}}\nJENIS ANESTESI: {{jenis_anestesi}}\nTINGKAT KOMPLEKSITAS: {{kompleksitas}}\nJUMLAH LANGKAH: {{jumlah_langkah}}\nKASUS MEDIS / TINDAKAN YANG DIPERLUKAN:\n{{kasus_tindakan}}";
$userPrompt = str_replace(
    ['{{jenis_operasi}}', '{{jenis_anestesi}}', '{{kompleksitas}}', '{{jumlah_langkah}}', '{{kasus_tindakan}}'],
    [$jenisOperasi, $jenisAnestesi, $kompleksitas, (string) $jumlahLangkah, $kasusTindakan],
    $userPromptTemplate
);
if ($diagnosisContext !== '') {
    $userPrompt .= "\n\nKONTEKS KANONIK DARI LAPORAN DIAGNOSIS " . $diagnosisCode . " (sumber utama; jangan bertentangan dengan ini). Identitas/usia dihitung tepat dari DOB; jangan menulis rentang usia atau mengubah jenis kelamin/nama:\n" . $diagnosisContext
        . "\n\nKESINAMBUNGAN TINDAKAN WAJIB: bagian tindakan_igd_selesai di atas adalah tindakan yang telah dilakukan sebelum transfer. Mulai skenario operasi dari keadaan pasien saat tiba di OK dan lanjutkan secara kronologis. Jika IGD menekan/membalut luka, pada awal tindakan OK DPJP membuka balutan spesifik itu dengan gunting perban sambil Asisten 1 menyiapkan kasa steril baru untuk mempertahankan tekanan. Jika laporan IGD menyebut darah menggenang/aktif, Asisten 2 menyerahkan kateter suction steril yang tersambung ke mesin suction bedah kepada DPJP untuk mengangkat darah; jangan menulis 'menghisap darah' tanpa mesin dan kateter suction, jangan menggunakan mulut. Jangan melakukan ulang penanganan IGD tanpa alasan klinis dalam skenario. Setiap tahap wajib menyebut instrumen/bahan yang dipakai di aksi /me. Jika Asisten mengambil alat, tulis perintah DPJP dengan nama alat, jawaban singkat Asisten, lalu aksi pengambilan/penyerahan alat.";
}

$result = ['ok' => false, 'error' => 'Model AI belum merespons.'];
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $result = ems_ai_ds_call_gemini($pdo, $systemPrompt, $userPrompt, 'ai_surgery_planner', isset($user['id']) ? (int) $user['id'] : null);
    if ($result['ok']) {
        break;
    }
}

if (!$result['ok']) {
    $errorMessage = (string) ($result['error'] ?? 'Model AI gagal merespons. Silakan coba lagi.');

    $insertFail = $pdo->prepare("
        INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, source_report_code, result_json, status, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'error', ?)
    ");
    $insertFail->execute([
        isset($user['id']) ? (int) $user['id'] : 0,
        $effectiveUnit,
        $division,
        $jenisOperasi,
        $jenisAnestesi,
        $kompleksitas,
        $kasusTindakan,
        $diagnosisCode,
        $errorMessage,
    ]);

    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Model AI error: ' . $errorMessage . ' Harap coba lagi.'], 502);
}

$data = $result['data'];
$data['tahapan_prosedur'] = is_array($data['tahapan_prosedur'] ?? null)
    ? ems_ai_ds_sanitize_step_items($data['tahapan_prosedur'])
    : [];
$data['tahapan_prosedur'] = ems_ai_ds_compact_surgery_documentation_overflow($data['tahapan_prosedur'], $jumlahLangkah);
$qualityErrors = ems_ai_ds_surgery_quality_errors($data, $jumlahLangkah);
$serializedPlan = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (preg_match('/\b(?:usia|berusia|tahun)\b[^\n]{0,50}\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b|\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b/iu', (string) $serializedPlan) === 1) {
    $qualityErrors[] = 'usia pasien ditulis sebagai rentang yang ambigu';
}

for ($repairAttempt = 1; $qualityErrors !== [] && $repairAttempt <= 3; $repairAttempt++) {
    $actualCount = count($data['tahapan_prosedur'] ?? []);
    $repairPrompt = "Perbaiki JSON skenario operasi roleplay FiveM di bawah. Output HANYA JSON lengkap dengan field yang sama.\n"
        . "Daftar tahapan saat ini memiliki {$actualCount} item; hasil akhir wajib memiliki TEPAT {$jumlahLangkah} objek tahapan_prosedur. Tiap item wajib memiliki satu pelaku terpilih dari DPJP/Asisten 1/Asisten 2, aksi /me yang spesifik, hasil /do final bentuk lampau, dan animasi. Hapus semua frasa placeholder termasuk 'Data belum tersedia', 'petugas belum tersedia', 'wajib diverifikasi', 'belum dilakukan', 'belum diketahui', 'belum tercatat', 'sedang diproses', dan 'menunggu hasil'. Untuk detail yang tidak ditetapkan input, pilih satu hasil skenario roleplay yang koheren dengan cedera dan gunakan konsisten. Aksi tidak boleh mengandung 'atau/or'. Lengkapi ringkasan, risiko relevan, SOP; farmakologi tetap terisi dengan array kosong hanya pada kategori obat yang memang tidak dipakai.\n"
        . "Kegagalan validasi: " . implode('; ', $qualityErrors) . "\n\nJSON YANG HARUS DIPERBAIKI:\n"
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\n\nINPUT KASUS ASLI:\n" . $userPrompt;
    $repairResult = ems_ai_ds_call_gemini($pdo, $systemPrompt, $repairPrompt, 'ai_surgery_planner', isset($user['id']) ? (int) $user['id'] : null);
    if (!$repairResult['ok'] || !is_array($repairResult['data'] ?? null)) {
        $qualityErrors[] = 'model tidak memberi JSON pada perbaikan ke-' . $repairAttempt;
        break;
    }

    $data = $repairResult['data'];
    $data['tahapan_prosedur'] = is_array($data['tahapan_prosedur'] ?? null)
        ? ems_ai_ds_sanitize_step_items($data['tahapan_prosedur'])
        : [];
    $data['tahapan_prosedur'] = ems_ai_ds_compact_surgery_documentation_overflow($data['tahapan_prosedur'], $jumlahLangkah);
    $qualityErrors = ems_ai_ds_surgery_quality_errors($data, $jumlahLangkah);
    $serializedPlan = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (preg_match('/\b(?:usia|berusia|tahun)\b[^\n]{0,50}\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b|\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b/iu', (string) $serializedPlan) === 1) {
        $qualityErrors[] = 'usia pasien ditulis sebagai rentang yang ambigu';
    }
}

if ($qualityErrors !== []) {
    $message = 'Model AI menghasilkan rencana yang belum lengkap setelah tiga perbaikan terarah: ' . implode('; ', $qualityErrors) . '. Tidak disimpan sebagai rencana selesai. Coba ulangi atau pilih jumlah tahapan yang sesuai.';
    $failStmt = $pdo->prepare("INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, source_report_code, result_json, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'error', ?)");
    $failStmt->execute([
        isset($user['id']) ? (int) $user['id'] : 0, $effectiveUnit, $division, $jenisOperasi,
        $jenisAnestesi, $kompleksitas, $kasusTindakan, $diagnosisCode,
        json_encode($data, JSON_UNESCAPED_UNICODE), $message,
    ]);
    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => $message], 502);
}

$insert = $pdo->prepare("
    INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, source_report_code, result_json, status, error_message)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'done', NULL)
");
$insert->execute([
    isset($user['id']) ? (int) $user['id'] : 0,
    $effectiveUnit,
    $division,
    $jenisOperasi,
    $jenisAnestesi,
    $kompleksitas,
    $kasusTindakan,
    $diagnosisCode,
    json_encode($data, JSON_UNESCAPED_UNICODE),
]);

$planId = (int) $pdo->lastInsertId();

ems_ai_ds_surgery_json_response(['ok' => true, 'message' => 'Rencana operasi berhasil dibuat.', 'plan_id' => $planId]);
