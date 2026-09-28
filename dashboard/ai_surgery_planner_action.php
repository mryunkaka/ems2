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

// Do not hold the per-user PHP session lock while waiting for the AI provider.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

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

$data = [];
$generationError = '';
$batchSize = 10;
$validateBatch = static function (array $steps, int $expectedCount): array {
    return ems_ai_ds_surgery_quality_errors([
        'durasi' => 'akan ditetapkan pada ringkasan operasi',
        'farmakologi' => ['pra_operatif' => [], 'intra_operatif' => [], 'post_operatif' => [], 'pemulangan' => []],
        'tahapan_prosedur' => $steps,
        'risiko_komplikasi' => [],
        'laporan_pasca_operasi' => 'akan ditetapkan pada ringkasan operasi',
        'sop_references' => ['akan ditetapkan pada ringkasan operasi'],
    ], $expectedCount);
};

for ($offset = 0; $offset < $jumlahLangkah; $offset += $batchSize) {
    $batchNumber = intdiv($offset, $batchSize) + 1;
    $batchCount = min($batchSize, $jumlahLangkah - $offset);
    $firstStepNumber = $offset + 1;
    $lastStepNumber = $offset + $batchCount;
    $batchPrompt = $userPrompt
        . "\n\nMODE GENERASI BERTAHAP — BAGIAN {$batchNumber}: susun hanya tahapan nomor {$firstStepNumber} sampai {$lastStepNumber} dari total {$jumlahLangkah}. Kembalikan tepat {$batchCount} objek pada tahapan_prosedur. Jangan membuat tahapan di luar rentang ini. Pertahankan urutan kronologis kasus, keselamatan pasien, dan kontinuitas alat/tindakan. Setiap aksi fisik wajib menyebut alat atau bahan spesifik yang benar-benar dipakai; tugas briefing/identitas/dokumentasi harus menyebut formulir, checklist, papan operasi, atau perangkat dokumentasi yang digunakan. Jika Asisten bertugas, instruksi harus berupa perintah DPJP yang menyebut alat spesifik dan aksi menyatakan alat itu diserahkan/dipakai. Periksa sendiri tiap aksi dan instruksi sebelum mengeluarkan JSON.";
    if ($offset > 0) {
        $priorSteps = array_slice($data['tahapan_prosedur'] ?? [], -10);
        $batchPrompt .= "\n\nKONTEKS LANGKAH SEBELUMNYA (jangan ulangi atau bertentangan):\n"
            . json_encode($priorSteps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\nLanjutkan tepat setelah tahap " . $offset . '. Ulangi identitas, anatomi, sisi cedera, alur perdarahan, tindakan yang sudah dilakukan, dan status alat/akses secara konsisten.';
    }

    $attemptErrors = [];
    $acceptedSteps = null;
    $acceptedHeader = null;
    for ($batchAttempt = 1; $batchAttempt <= 2 && $acceptedSteps === null; $batchAttempt++) {
        $batchResult = ems_ai_ds_call_gemini(
            $pdo,
            $systemPrompt,
            $batchPrompt,
            'ai_surgery_planner',
            isset($user['id']) ? (int) $user['id'] : null,
            $offset === 0 ? ems_ai_ds_surgery_response_schema() : ems_ai_ds_surgery_response_schema(true)
        );
        if (!$batchResult['ok'] || !is_array($batchResult['data'] ?? null)) {
            $attemptErrors[] = (string) ($batchResult['error'] ?? 'Model tidak mengembalikan JSON tahap operasi.');
            continue;
        }

        $candidate = $batchResult['data'];
        $candidateSteps = ems_ai_ds_extract_surgery_steps($candidate);
        $candidateSteps = ems_ai_ds_compact_surgery_documentation_overflow($candidateSteps, $batchCount);

        // Count errors do not identify a particular stage number, so the
        // per-stage repair below cannot select anything to fix. Ask the model
        // to reconcile the whole current batch to the exact requested count;
        // preserve its clinical sequence and merge related preparation or
        // documentation only when it generated too many stages.
        for ($countRepairAttempt = 1; count($candidateSteps) !== $batchCount && $countRepairAttempt <= 1; $countRepairAttempt++) {
            $currentCount = count($candidateSteps);
            $countRepairPrompt = "REKONSILIASI JUMLAH TAHAP — keluarkan tepat {$batchCount} tahap untuk rentang nomor {$firstStepNumber} sampai {$lastStepNumber} (total rencana {$jumlahLangkah}). JSON masukan memiliki {$currentCount} tahap setelah normalisasi. "
                . ($currentCount > $batchCount
                    ? "Gabungkan hanya tahap administratif/persiapan/pemantauan yang saling terkait; jangan menghapus tindakan klinis penting, jangan mengubah urutan tindakan, fakta kasus, anatomi/sisi, alat, pelaku, atau hasil /do."
                    : "Lengkapi kekurangan dengan tahap yang memang diperlukan dalam urutan kronologis; jangan mengulang tindakan yang sudah selesai dan jangan membuat temuan atau hasil klinis baru.")
                . " Kembalikan tepat {$batchCount} item pada key tahapan_prosedur, tanpa field lain. Setiap item harus tetap konkret, memiliki aksi /me dan hasil /do, serta memenuhi kontrak alat. JSON batch saat ini:\n"
                . json_encode(['tahapan_prosedur' => $candidateSteps], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nKONTEKS KASUS DAN RENTANG TAHAP:\n" . $batchPrompt;
            $countRepair = ems_ai_ds_call_gemini(
                $pdo,
                $systemPrompt,
                $countRepairPrompt,
                'ai_surgery_planner',
                isset($user['id']) ? (int) $user['id'] : null,
                ems_ai_ds_surgery_response_schema(true)
            );
            $reconciledSteps = is_array($countRepair['data'] ?? null)
                ? ems_ai_ds_extract_surgery_steps($countRepair['data'])
                : [];
            if (!$countRepair['ok'] || count($reconciledSteps) !== $batchCount) {
                $attemptErrors[] = 'Perbaikan jumlah tahap ' . $firstStepNumber . '–' . $lastStepNumber
                    . ' menghasilkan ' . count($reconciledSteps) . '/' . $batchCount . ' tahap.';
                continue;
            }
            $candidateSteps = $reconciledSteps;
        }

        $batchIssues = $validateBatch($candidateSteps, $batchCount);

        // Correct only invalid items in this ten-step batch. Keep every valid
        // action/result untouched so the repair cannot rewrite prior surgery.
        for ($repairAttempt = 1; $batchIssues !== [] && $repairAttempt <= 2; $repairAttempt++) {
            $badIndexes = [];
            foreach ($batchIssues as $issue) {
                if (preg_match('/tahap\s+(\d+)/iu', $issue, $match) === 1) {
                    $localIndex = (int) $match[1] - 1;
                    if (isset($candidateSteps[$localIndex])) $badIndexes[$localIndex] = $candidateSteps[$localIndex];
                }
            }
            if ($badIndexes === []) break;

            $repairPrompt = "Perbaiki hanya item tahap yang tercantum pada JSON ini. Output hanya JSON sesuai schema dengan key tahapan_prosedur, tepat " . count($badIndexes) . " item dalam urutan masukan. Item diberikan berurutan dengan nomor tahap global; jangan menambah/menghapus tahap. Kekurangan: " . implode('; ', $batchIssues)
                . ". Pertahankan pelaku, hasil /do, dan animasi persis kecuali field tersebut kosong/tidak valid. Ubah aksi /me dan instruksi hanya bila perlu untuk melengkapi alat spesifik yang relevan, instruksi DPJP, dan hasil yang bisa langsung dimainkan. Jangan menambahkan diagnosis, temuan, atau tindakan definitif baru.\nTAHAP BERMASALAH:\n"
                . json_encode(array_values($badIndexes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nKONTEKS KASUS DAN TAHAP SEBELUMNYA:\n" . $batchPrompt;
            $repairResult = ems_ai_ds_call_gemini(
                $pdo,
                $systemPrompt,
                $repairPrompt,
                'ai_surgery_planner',
                isset($user['id']) ? (int) $user['id'] : null,
                ems_ai_ds_surgery_response_schema(true)
            );
            $repairedSteps = is_array($repairResult['data'] ?? null)
                ? ems_ai_ds_extract_surgery_steps($repairResult['data'])
                : [];
            if (!$repairResult['ok'] || count($repairedSteps) !== count($badIndexes)) {
                $attemptErrors[] = 'Perbaikan terarah untuk tahap ' . implode(', ', array_map(static fn (int $i): int => $i + $firstStepNumber, array_keys($badIndexes))) . ' tidak mengembalikan jumlah item yang sama.';
                break;
            }

            foreach (array_keys($badIndexes) as $repairIndex => $localIndex) {
                $replacement = $repairedSteps[$repairIndex];
                foreach (['aksi', 'instruksi'] as $field) {
                    if (trim((string) ($replacement[$field] ?? '')) !== '') {
                        $candidateSteps[$localIndex][$field] = trim((string) $replacement[$field]);
                    }
                }
                foreach (['pelaku', 'hasil', 'animasi'] as $field) {
                    if (trim((string) ($candidateSteps[$localIndex][$field] ?? '')) === '' && trim((string) ($replacement[$field] ?? '')) !== '') {
                        $candidateSteps[$localIndex][$field] = trim((string) $replacement[$field]);
                    }
                }
            }
            $batchIssues = $validateBatch($candidateSteps, $batchCount);
        }

        if ($batchIssues === []) {
            $acceptedSteps = $candidateSteps;
            if ($offset === 0) $acceptedHeader = $candidate;
            break;
        }
        $attemptErrors[] = implode('; ', $batchIssues);
    }

    if ($acceptedSteps === null) {
        $generationError = 'Tahap ' . $firstStepNumber . '–' . $lastStepNumber . ' belum lolos pemeriksaan setelah dua percobaan bertahap: ' . implode(' | ', $attemptErrors);
        break;
    }

    if ($offset === 0) {
        $data = $acceptedHeader ?? [];
        $data['tahapan_prosedur'] = $acceptedSteps;
    } else {
        $data['tahapan_prosedur'] = array_merge($data['tahapan_prosedur'] ?? [], $acceptedSteps);
    }
}

if ($generationError !== '') {
    $errorMessage = 'Model AI belum dapat menyelesaikan rencana operasi. ' . $generationError . '. Tidak disimpan sebagai rencana selesai. Coba ulangi setelah memeriksa koneksi/provider AI.';

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

    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => $errorMessage], 502);
}
$qualityErrors = ems_ai_ds_surgery_quality_errors($data, $jumlahLangkah);
$serializedPlan = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (preg_match('/\b(?:usia|berusia|tahun)\b[^\n]{0,50}\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b|\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b/iu', (string) $serializedPlan) === 1) {
    $qualityErrors[] = 'usia pasien ditulis sebagai rentang yang ambigu';
}

$headerErrors = array_values(array_filter($qualityErrors, static fn (string $error): bool => !str_starts_with($error, 'Tahapan operasi') && !str_contains($error, 'jumlah tahapan')));
for ($repairAttempt = 1; $headerErrors !== [] && $repairAttempt <= 2; $repairAttempt++) {
    $preservedSteps = $data['tahapan_prosedur'] ?? [];
    $headerData = $data;
    unset($headerData['tahapan_prosedur']);
    $repairPrompt = "Perbaiki hanya field ringkasan berikut yang belum lolos. Jangan keluarkan tahapan_prosedur. Pertahankan fakta dan keputusan yang konsisten dengan input. Kembalikan hanya object JSON berisi field header lengkap sesuai schema. Kekurangan: "
        . implode('; ', $headerErrors) . "\nINPUT KASUS:\n" . $userPrompt . "\nHEADER SAAT INI:\n"
        . json_encode($headerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $repairResult = ems_ai_ds_call_gemini(
        $pdo,
        $systemPrompt,
        $repairPrompt,
        'ai_surgery_planner',
        isset($user['id']) ? (int) $user['id'] : null,
        ems_ai_ds_surgery_response_schema(false, true)
    );
    if (!$repairResult['ok'] || !is_array($repairResult['data'] ?? null)) {
        $headerErrors[] = 'model tidak mengembalikan JSON header pada perbaikan ke-' . $repairAttempt;
        break;
    }
    $data = array_merge($data, $repairResult['data']);
    $data['tahapan_prosedur'] = $preservedSteps;
    $qualityErrors = ems_ai_ds_surgery_quality_errors($data, $jumlahLangkah);
    $serializedPlan = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (preg_match('/\b(?:usia|berusia|tahun)\b[^\n]{0,50}\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b|\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b/iu', (string) $serializedPlan) === 1) {
        $qualityErrors[] = 'usia pasien ditulis sebagai rentang yang ambigu';
    }
    $headerErrors = array_values(array_filter($qualityErrors, static fn (string $error): bool => !str_starts_with($error, 'Tahapan operasi') && !str_contains($error, 'jumlah tahapan')));
}
if ($qualityErrors !== []) {
    $message = 'Rencana operasi belum lolos validasi akhir: ' . implode('; ', $qualityErrors) . '. Tahapan yang lolos tetap diperiksa per bagian dan tidak diganti oleh perbaikan ringkasan. Tidak disimpan sebagai rencana selesai.';
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
