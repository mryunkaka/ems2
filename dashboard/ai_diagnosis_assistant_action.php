<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/ai_radiology.php';
require_once __DIR__ . '/../config/ai_laboratory.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';

function ems_ai_ds_merge_stage_reports(array $core, array $final): array
{
    if (is_array($core['ttv'] ?? null) && is_array($final['ttv'] ?? null)) {
        foreach ($final['ttv'] as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $finalValue = trim((string) ($item['value'] ?? ''));
            if (preg_match('/data\s+belum|belum\s+(?:diukur|dinilai|tersedia)/iu', $finalValue) === 1 && isset($core['ttv'][$index]) && is_array($core['ttv'][$index])) {
                $coreValue = trim((string) ($core['ttv'][$index]['value'] ?? ''));
                $core['ttv'][$index] = $coreValue !== '' && preg_match('/data\s+belum|belum\s+(?:diukur|dinilai|tersedia)/iu', $coreValue) !== 1 ? $core['ttv'][$index] : $item;
            } else {
                $core['ttv'][$index] = $item;
            }
        }
        $final['ttv'] = $core['ttv'];
    }
    foreach (['gcs_score' => 'gcs', 'tanda_vital' => 'ttv', 'vital_signs' => 'ttv', 'laboratorium' => 'lab', 'emergency_actions' => 'emergency', 'tindakan_emergency' => 'emergency'] as $source => $target) {
        if ((!isset($final[$target]) || $final[$target] === '' || $final[$target] === []) && isset($final[$source])) {
            $final[$target] = $final[$source];
        }
        if ((!isset($core[$target]) || $core[$target] === '' || $core[$target] === []) && isset($core[$source])) {
            $core[$target] = $core[$source];
        }
    }
    if (is_array($core['gcs'] ?? null)) {
        $core['gcs'] = trim((string) ($core['gcs']['score'] ?? $core['gcs']['value'] ?? json_encode($core['gcs'], JSON_UNESCAPED_UNICODE)));
    }
    if (is_array($final['gcs'] ?? null)) {
        $final['gcs'] = trim((string) ($final['gcs']['score'] ?? $final['gcs']['value'] ?? json_encode($final['gcs'], JSON_UNESCAPED_UNICODE)));
    }
    foreach (['core', 'final'] as $stage) {
        $stageData =& $$stage;
        if (is_array($stageData['ttv'] ?? null) && is_array($stageData['ttv_estimasi_ai'] ?? null)) {
            $estimated = [];
            foreach ($stageData['ttv_estimasi_ai'] as $item) {
                if (is_array($item) && trim((string) ($item['label'] ?? '')) !== '') {
                    $estimated[mb_strtolower(trim((string) $item['label']))] = $item;
                }
            }
            foreach ($stageData['ttv'] as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = mb_strtolower(trim((string) ($item['label'] ?? '')));
                $value = trim((string) ($item['value'] ?? ''));
                $estimateItem = $estimated[$key] ?? ($stageData['ttv_estimasi_ai'][$index] ?? null);
                if (preg_match('/data\s+belum|belum\s+(?:diukur|dinilai|tersedia)/iu', $value) === 1 && is_array($estimateItem)) {
                    $item['value'] = $estimateItem['value'] ?? $value;
                    $item['note'] = $estimateItem['note'] ?? ($item['note'] ?? '');
                    $stageData['ttv'][$index] = $item;
                }
            }
        }
        unset($stageData);
    }
    foreach (['core', 'final'] as $stage) {
        $stageData =& $$stage;
        if ((!is_array($stageData['lab'] ?? null) || $stageData['lab'] === []) && is_array($stageData['laboratorium_terstruktur'] ?? null)) {
            $lab = $stageData['laboratorium_terstruktur'];
            $stageData['lab'] = [trim(implode(' > ', array_filter([
                $lab['department'] ?? '', $lab['category'] ?? '', $lab['level3_option'] ?? '',
                'Hasil: ' . ($lab['result'] ?? $lab['clinical_finding'] ?? 'Dalam batas skenario final')
            ])))];
        }
        if ((!is_array($stageData['radiologi'] ?? null) || $stageData['radiologi'] === []) && is_array($stageData['radiologi_terstruktur'] ?? null)) {
            $rad = $stageData['radiologi_terstruktur'];
            $stageData['radiologi'] = [trim(implode(' > ', array_filter([
                $rad['modality'] ?? '', $rad['category'] ?? '', $rad['body_region'] ?? '', $rad['projection'] ?? '',
                'Temuan: ' . ($rad['result'] ?? $rad['clinical_finding'] ?? 'Dalam batas skenario final')
            ])))];
        }
        unset($stageData);
    }
    foreach (['core', 'final'] as $stage) {
        if (!is_array(($$stage)['emergency'] ?? null)) {
            continue;
        }
        foreach (($$stage)['emergency'] as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['actor' => 'pelaku', 'action' => 'aksi', 'result' => 'hasil', 'animation' => 'animasi', 'instruction' => 'instruksi'] as $source => $target) {
                if ((!isset($item[$target]) || trim((string) $item[$target]) === '') && isset($item[$source])) {
                    $item[$target] = $item[$source];
                }
            }
            foreach (['aksi', 'hasil'] as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    $item[$field] = trim((string) preg_replace('/^\s*\/(?:me|do)\s+/iu', '', $item[$field]));
                }
            }
            $$stage['emergency'][$index] = $item;
        }
    }
    foreach ($final as $key => $value) {
        if (is_array($value) && is_array($core[$key] ?? null) && $value !== []) {
            $core[$key] = $value;
            continue;
        }
        if (is_string($value) && trim($value) === '' && isset($core[$key])) {
            continue;
        }
        if ($value !== null && $value !== [] && $value !== '') {
            $core[$key] = $value;
        }
    }
    return $core;
}

/**
 * Model Gemini kadang memakai nama properti sinonim walaupun maknanya sama.
 * Selaraskan sinonim ke schema laporan sebelum kontrak final diperiksa.
 * Fungsi ini hanya memetakan nama field; isi klinis tetap berasal dari model.
 */
function ems_ai_ds_align_model_schema(array $data): array
{
    // Nilai yang sudah disajikan model sebagai skenario final tidak boleh
    // membawa label internal "Estimasi AI" ke laporan pemain.
    unset($data['gcs_estimasi_ai'], $data['ttv_estimasi_ai']);
    if (isset($data['diagnosis_banding']) && !is_array($data['diagnosis_banding'])) {
        $data['diagnosis_banding'] = [(string) $data['diagnosis_banding']];
    }

    foreach (['ttv', 'tanda_vital', 'vital_signs'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            continue;
        }
        $normalized = [];
        foreach ($data[$key] as $item) {
            if (is_string($item)) {
                $parts = explode(':', $item, 2);
                $item = ['label' => trim($parts[0] ?? ''), 'value' => trim($parts[1] ?? $item)];
            }
            if (!is_array($item)) continue;
            $item['label'] = trim((string) ($item['label'] ?? $item['parameter'] ?? $item['name'] ?? $item['jenis'] ?? ''));
            $item['value'] = trim((string) ($item['value'] ?? $item['nilai'] ?? $item['reading'] ?? $item['hasil'] ?? ''));
            $item['note'] = trim((string) ($item['note'] ?? $item['keterangan'] ?? $item['status'] ?? $item['interpretasi'] ?? ''));
            if ($item['label'] !== '' || $item['value'] !== '') {
                $normalized[] = $item;
            }
        }
        if ($normalized !== []) {
            $data['ttv'] = $normalized;
            break;
        }
    }

    foreach (['emergency', 'emergency_actions', 'tindakan_emergency'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            continue;
        }
        $normalized = [];
        foreach ($data[$key] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item['pelaku'] = trim((string) ($item['pelaku'] ?? $item['actor'] ?? $item['role'] ?? 'DPJP'));
            $item['instruksi'] = trim((string) ($item['instruksi'] ?? $item['instruction'] ?? ''));
            $item['aksi'] = trim((string) ($item['aksi'] ?? $item['action'] ?? $item['me'] ?? $item['aksi_me'] ?? ''));
            $item['hasil'] = trim((string) ($item['hasil'] ?? $item['result'] ?? $item['do'] ?? $item['hasil_do'] ?? ''));
            $item['animasi'] = trim((string) ($item['animasi'] ?? $item['animation'] ?? $item['emote'] ?? ''));
            $item['aksi'] = trim((string) preg_replace('/^\s*\/me\s*/iu', '', $item['aksi']));
            $item['hasil'] = trim((string) preg_replace('/^\s*\/do\s*/iu', '', $item['hasil']));
            $normalized[] = $item;
        }
        if ($normalized !== []) {
            $data['emergency'] = $normalized;
            break;
        }
    }

    if (isset($data['gcs']) && is_string($data['gcs'])) {
        $decodedGcs = json_decode($data['gcs'], true);
        if (is_array($decodedGcs)) {
            $data['gcs'] = $decodedGcs;
        }
    }
    if (isset($data['gcs']) && is_array($data['gcs'])) {
        $score = trim((string) ($data['gcs']['score'] ?? $data['gcs']['value'] ?? $data['gcs']['total'] ?? ''));
        $e = $data['gcs']['e'] ?? $data['gcs']['eye'] ?? null;
        $v = $data['gcs']['v'] ?? $data['gcs']['verbal'] ?? null;
        $m = $data['gcs']['m'] ?? $data['gcs']['motor'] ?? null;
        if ($e !== null && $v !== null && $m !== null) {
            $score = 'E' . (int) $e . ' V' . (int) $v . ' M' . (int) $m . ($score !== '' ? ' (' . $score . ')' : '');
        }
        $data['gcs'] = $score;
    }

    return $data;
}

/**
 * Model kadang membalas satu field sebagai array (mis. daftar semua opsi
 * proyeksi) padahal diminta satu string â€” ambil elemen pertama sebagai
 * fallback yang masuk akal alih-alih langsung menolak seluruh object.
 */
function ems_ai_ds_scalar_or_first($value): string
{
    if (is_array($value)) {
        $value = reset($value);
    }
    return trim((string) $value);
}

function ems_ai_ds_sanitize_structured_radiology($input): ?array
{
    if (!is_array($input)) {
        return null;
    }

    $modality = ems_ai_ds_scalar_or_first($input['modality'] ?? '');
    $category = ems_ai_ds_scalar_or_first($input['category'] ?? '');
    $bodyRegion = ems_ai_ds_scalar_or_first($input['body_region'] ?? '');
    $projection = ems_ai_ds_scalar_or_first($input['projection'] ?? '');
    $clinicalFinding = ems_ai_ds_scalar_or_first($input['clinical_finding'] ?? '');

    // Pasien tidak butuh pencitraan sama sekali â€” tetap valid, hanya tanpa target modality.
    if ($modality === '' && $category === '' && $bodyRegion === '' && $projection === '') {
        return $clinicalFinding !== '' ? ['modality' => '', 'category' => '', 'body_region' => '', 'projection' => '', 'clinical_finding' => $clinicalFinding] : null;
    }

    if (!in_array($modality, ems_ai_radiology_modalities(), true)) {
        return null;
    }
    if (!in_array($category, ems_ai_radiology_categories($modality), true)) {
        return null;
    }
    if (!in_array($bodyRegion, ems_ai_radiology_body_regions_for($modality, $category), true)) {
        return null;
    }
    if (!ems_ai_radiology_is_valid_selection($modality, $category, $bodyRegion, $projection)) {
        return null;
    }
    if (!in_array($clinicalFinding, ems_ai_radiology_clinical_findings(), true)) {
        return null;
    }

    return [
        'modality' => $modality,
        'category' => $category,
        'body_region' => $bodyRegion,
        'projection' => $projection,
        'clinical_finding' => $clinicalFinding,
    ];
}

function ems_ai_ds_sanitize_structured_laboratory($input): ?array
{
    if (!is_array($input)) {
        return null;
    }

    $department = ems_ai_ds_scalar_or_first($input['department'] ?? '');
    $category = ems_ai_ds_scalar_or_first($input['category'] ?? '');
    $level3Option = ems_ai_ds_scalar_or_first($input['level3_option'] ?? '');
    $specimenType = ems_ai_ds_scalar_or_first($input['specimen_type'] ?? '');

    // Pasien tidak butuh pemeriksaan laboratorium sama sekali.
    if ($department === '' && $category === '' && $specimenType === '') {
        return null;
    }

    if (!in_array($department, ems_ai_laboratory_departments(), true)) {
        return null;
    }
    if (!in_array($category, ems_ai_laboratory_categories($department), true)) {
        return null;
    }

    $catInfo = ems_ai_laboratory_category_info($department, $category);
    $level3Value = ($catInfo['type'] ?? '') === 'select' ? $level3Option : null;

    if (!ems_ai_laboratory_is_valid_selection($department, $category, $level3Value, $specimenType)) {
        return null;
    }

    return [
        'department' => $department,
        'category' => $category,
        'level3_option' => $level3Value ?? '',
        'specimen_type' => $specimenType,
    ];
}

function ems_ai_ds_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ems_ai_ds_store_failure(PDO $pdo, array $user, string $unit, string $division, string $anamnesis, string $patientName, ?string $patientGender, ?string $patientDob, string $patientCitizenId, string $errorMessage): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO ai_diagnosis_reports
            (user_id, unit_code, division_snapshot, anamnesis, patient_name, patient_gender, patient_dob, patient_citizen_id, result_json, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'error', ?)");
        $stmt->execute([(int) ($user['id'] ?? 0), $unit, $division, $anamnesis,
            $patientName !== '' ? $patientName : null, $patientGender, $patientDob,
            $patientCitizenId !== '' ? $patientCitizenId : null, $errorMessage]);
    } catch (Throwable $storageError) {
        error_log('AI Diagnosis Assistant gagal menyimpan riwayat error: ' . $storageError->getMessage());
    }
}

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_diagnosis_assistant.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ems_ai_ds_json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    ems_ai_ds_json_response(['ok' => false, 'message' => 'Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.'], 419);
}

$user = $_SESSION['user_rh'] ?? [];
$anamnesis = trim((string) ($_POST['anamnesis'] ?? ''));

if ($anamnesis === '') {
    ems_ai_ds_json_response(['ok' => false, 'message' => 'Anamnesis / temuan medis wajib diisi.'], 422);
}

$patientName = trim((string) ($_POST['patient_name'] ?? ''));
$patientGenderInput = trim((string) ($_POST['patient_gender'] ?? ''));
$patientGender = in_array($patientGenderInput, ['Laki-laki', 'Perempuan'], true) ? $patientGenderInput : null;
// Validasi koherensi identitas: kehamilan/riwayat janin tidak mungkin
// dipasangkan dengan identitas laki-laki. Ini koreksi konflik input, bukan
// pengganti penalaran klinis model.
if ($patientGender === 'Laki-laki' && preg_match('/\b(?:hamil|janin|plasenta|gravid|abrupsi|kehamilan|seksio\s+sesarea|caesar)\b/iu', $anamnesis) === 1) {
    $patientGender = 'Perempuan';
}
$patientDobInput = trim((string) ($_POST['patient_dob'] ?? ''));
$patientDob = null;
if ($patientDobInput !== '') {
    $dobTimestamp = strtotime($patientDobInput);
    $patientDob = $dobTimestamp ? date('Y-m-d', $dobTimestamp) : null;
}
$patientCitizenId = trim((string) ($_POST['patient_citizen_id'] ?? ''));

$effectiveUnit = ems_effective_unit($pdo, $user);
$division = (string) ($user['division'] ?? '');

$systemPrompt = ems_ai_ds_build_system_prompt($pdo, 'ai_diagnosis_assistant', ems_ai_ds_default_diagnosis_system_prompt());
$systemPrompt .= "\n\nREFERENSI KATALOG RADIOLOGI (format: Modality > Category > Body Region > [Projection/Options], pilih PERSIS salah satu kombinasi untuk \"radiologi_terstruktur\"):\n"
    . ems_ai_radiology_catalog_reference_text()
    . "\n\nREFERENSI TEMUAN KLINIS (pilih PERSIS salah satu untuk \"radiologi_terstruktur.clinical_finding\"):\n"
    . ems_ai_radiology_clinical_findings_reference_text();
$systemPrompt .= "\n\nREFERENSI KATALOG LABORATORIUM (format: Department > Category > [Level3 Options] > Spesimen: [opsi spesimen], pilih PERSIS salah satu kombinasi untuk \"laboratorium_terstruktur\"):\n"
    . ems_ai_laboratory_catalog_reference_text();

$template = ems_ai_get_active_prompt_template($pdo, 'ai_diagnosis_assistant');
$userPromptTemplate = trim((string) ($template['user_prompt_template'] ?? '')) !== ''
    ? (string) $template['user_prompt_template']
    : "ANAMNESIS:\n{{anamnesis}}";
$userPrompt = str_replace('{{anamnesis}}', $anamnesis, $userPromptTemplate);

// Kalau identitas pasien diisi, sisipkan sebagai konteks pasti di depan anamnesis
// supaya model AI memakai data NYATA ini (usia/jenis kelamin dari input, bukan
// menebak sendiri) â€” usia dihitung dari DOB kalau ada.
$identityLines = [];
if ($patientName !== '') {
    $identityLines[] = 'Nama: ' . $patientName;
}
if ($patientGender !== null) {
    $identityLines[] = 'Jenis Kelamin: ' . $patientGender;
}
if ($patientDob !== null) {
    $identityLines[] = 'Usia: ' . ems_ai_radiology_age_label($patientDob);
}
if ($identityLines !== []) {
    $userPrompt = "IDENTITAS PASIEN:\n" . implode("\n", $identityLines) . "\n\n" . $userPrompt;
}

$stageOnePrompt = $userPrompt
    . "\n\nTAHAP 1/2 â€” SUSUN JSON INTI SAJA. Tangani identitas pasien, anamnesis final, diagnosis utama dan banding, GCS, TTV, pemeriksaan pupil, riwayat operasi, serta kasus medis/tindakan."
    . " Susun JSON INTI ringkas dengan key wajib: anamnesis_lengkap, diagnosis_utama, diagnosis_banding, gcs, ttv, pemeriksaan_pupil, riwayat_operasi, kasus_tindakan, jenis_operasi, jenis_anestesi, status_rencana_operasi."
    . " TTV wajib tepat 5 objek: Tekanan Darah, Nadi / HR, Suhu, Respirasi / RR, Saturasi O2; setiap value dan note wajib konkret. GCS wajib berupa teks E/V/M dan total. Jangan mengosongkan field inti, memakai placeholder, atau menulis markdown di luar JSON.";
$result = ems_ai_ds_call_gemini($pdo, $systemPrompt, $stageOnePrompt, 'ai_diagnosis_assistant_stage_1', isset($user['id']) ? (int) $user['id'] : null);

if (!$result['ok']) {
    $errorMessage = (string) ($result['error'] ?? 'Model AI gagal merespons. Silakan coba lagi.');

    ems_ai_ds_store_failure($pdo, $user, $effectiveUnit, $division, $anamnesis, $patientName, $patientGender, $patientDob, $patientCitizenId, $errorMessage);

    ems_ai_ds_json_response(['ok' => false, 'message' => 'Model AI error: ' . $errorMessage . ' Harap coba lagi.'], 502);
}

try {
    if (!is_array($result['data'] ?? null)) {
        throw new RuntimeException('Tahap pertama tidak mengembalikan JSON inti.');
    }
    $draftJson = json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stageTwoPrompt = $userPrompt
        . "\nATURAN PRA-OPERASI: semua item emergency hanya stabilisasi IGD dan handoff ke Ruang Operasi; jangan menjahit atau melakukan tindakan definitif di IGD, termasuk pada kasus Minor. Catatan roleplay harus berisi urutan tindakan yang langsung dijalankan pemain.`r`nKEY FINAL WAJIB: lab=array hasil laboratorium konkret; radiologi=array hasil radiologi konkret; emergency=array 8-14 tindakan; handoff; sop_references; roleplay_note; laboratorium_terstruktur; radiologi_terstruktur. Key lab dan radiologi tidak boleh kosong atau diganti nama."
        . "\n\nTAHAP 2/2 â€” LANJUTKAN JSON INTI BERIKUT MENJADI LAPORAN FINAL LENGKAP."
        . " Pertahankan fakta dan keputusan klinis dari JSON inti, lalu isi seluruh kartu yang belum ada: laboratorium, radiologi, Penanganan Emergency ABCDE 8â€“14 langkah, handoff, rujukan SOP, roleplay note, dan seluruh field schema."
        . " Jangan mengulang anamnesis mentah, jangan membuat pilihan tindakan, jangan mengubah jenis kelamin, dan jangan menulis penjelasan di luar satu JSON final."
        . "\nJSON INTI TAHAP 1:\n" . $draftJson;
    $finalResult = ems_ai_ds_call_gemini($pdo, $systemPrompt, $stageTwoPrompt, 'ai_diagnosis_assistant_stage_2', isset($user['id']) ? (int) $user['id'] : null);
    if (!$finalResult['ok']) {
        throw new RuntimeException('Tahap kedua gagal: ' . (string) ($finalResult['error'] ?? 'respons tidak valid'));
    }
    $data = ems_ai_ds_align_model_schema(ems_ai_ds_merge_stage_reports($result['data'], $finalResult['data']));
    $auditJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stageThreePrompt = $userPrompt
        . "\n\nTAHAP 3/3 â€” AUDIT FINAL. Pertahankan fakta, jenis kelamin, diagnosis, GCS, TTV, dan klasifikasi minor/mayor dari JSON gabungan. Perbaiki hanya kelengkapan, konsistensi, dan format."
        . " Pastikan anamnesis final panjang, GCS E/V/M dengan total, TTV tepat 5 item bernilai konkret, lab dan radiologi memiliki hasil konkret, emergency 8â€“14 tindakan ABCDE playable, serta tidak ada Array, placeholder, Data belum tersedia, pilihan tindakan, atau prefix /me dan /do di dalam field."
        . " Kembalikan SATU JSON final lengkap tanpa markdown.\nJSON GABUNGAN:\n" . $auditJson;
    $auditResult = ems_ai_ds_call_gemini($pdo, $systemPrompt, $stageThreePrompt, 'ai_diagnosis_assistant_stage_3', isset($user['id']) ? (int) $user['id'] : null);
    if (!$auditResult['ok']) {
        throw new RuntimeException('Tahap ketiga audit gagal: ' . (string) ($auditResult['error'] ?? 'respons tidak valid'));
    }
    $data = ems_ai_ds_align_model_schema(ems_ai_ds_merge_stage_reports($data, $auditResult['data']));
    $data = ems_ai_ds_require_complete_model_report($data);
} catch (Throwable $finalError) {
    // Tiga tahap eksplisit (inti, lengkap, audit) tanpa retry tersembunyi.
    $errorMessage = 'Laporan final tidak memenuhi kontrak: ' . $finalError->getMessage();
    ems_ai_ds_store_failure($pdo, $user, $effectiveUnit, $division, $anamnesis, $patientName, $patientGender, $patientDob, $patientCitizenId, $errorMessage);
    ems_ai_ds_json_response(['ok' => false, 'message' => $errorMessage . ' Silakan ulangi analisis.'], 422);
}

$reportCode = null;
for ($codeAttempt = 0; $codeAttempt < 5; $codeAttempt++) {
    $candidate = ems_ai_ds_generate_report_code();
    $dupCheck = $pdo->prepare('SELECT 1 FROM ai_diagnosis_reports WHERE report_code = ?');
    $dupCheck->execute([$candidate]);
    if (!$dupCheck->fetchColumn()) {
        $reportCode = $candidate;
        break;
    }
}

$insert = $pdo->prepare("
    INSERT INTO ai_diagnosis_reports
        (report_code, user_id, unit_code, division_snapshot, anamnesis, patient_name, patient_gender, patient_dob, patient_citizen_id, result_json, status, error_message)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'done', NULL)
");
$insert->execute([
    $reportCode,
    isset($user['id']) ? (int) $user['id'] : 0,
    $effectiveUnit,
    $division,
    $anamnesis,
    $patientName !== '' ? $patientName : null,
    $patientGender,
    $patientDob,
    $patientCitizenId !== '' ? $patientCitizenId : null,
    json_encode($data, JSON_UNESCAPED_UNICODE),
]);

$reportId = (int) $pdo->lastInsertId();

ems_ai_ds_json_response(['ok' => true, 'message' => 'Diagnosis berhasil dibuat.', 'report_id' => $reportId]);
