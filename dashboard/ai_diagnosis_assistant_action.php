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
require_once __DIR__ . '/../actions/ai_gemini_client.php';

/**
 * Model kadang membalas satu field sebagai array (mis. daftar semua opsi
 * proyeksi) padahal diminta satu string — ambil elemen pertama sebagai
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

    // Pasien tidak butuh pencitraan sama sekali — tetap valid, hanya tanpa target modality.
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

function ems_ai_ds_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
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

$template = ems_ai_get_active_prompt_template($pdo, 'ai_diagnosis_assistant');
$userPromptTemplate = trim((string) ($template['user_prompt_template'] ?? '')) !== ''
    ? (string) $template['user_prompt_template']
    : "ANAMNESIS:\n{{anamnesis}}";
$userPrompt = str_replace('{{anamnesis}}', $anamnesis, $userPromptTemplate);

// Kalau identitas pasien diisi, sisipkan sebagai konteks pasti di depan anamnesis
// supaya model AI memakai data NYATA ini (usia/jenis kelamin dari input, bukan
// menebak sendiri) — usia dihitung dari DOB kalau ada.
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

$result = ['ok' => false, 'error' => 'Model AI belum merespons.'];
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $result = ems_ai_ds_call_gemini($pdo, $systemPrompt, $userPrompt, 'ai_diagnosis_assistant', isset($user['id']) ? (int) $user['id'] : null);
    if ($result['ok']) {
        break;
    }
}

if (!$result['ok']) {
    $errorMessage = (string) ($result['error'] ?? 'Model AI gagal merespons. Silakan coba lagi.');

    $insertFail = $pdo->prepare("
        INSERT INTO ai_diagnosis_reports
            (user_id, unit_code, division_snapshot, anamnesis, patient_name, patient_gender, patient_dob, patient_citizen_id, result_json, status, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'error', ?)
    ");
    $insertFail->execute([
        isset($user['id']) ? (int) $user['id'] : 0,
        $effectiveUnit,
        $division,
        $anamnesis,
        $patientName !== '' ? $patientName : null,
        $patientGender,
        $patientDob,
        $patientCitizenId !== '' ? $patientCitizenId : null,
        $errorMessage,
    ]);

    ems_ai_ds_json_response(['ok' => false, 'message' => 'Model AI error: ' . $errorMessage . ' Harap coba lagi.'], 502);
}

$data = ems_ai_ds_normalize_diagnosis_result($result['data']);
if (isset($data['emergency']) && is_array($data['emergency'])) {
    $data['emergency'] = ems_ai_ds_sanitize_step_items($data['emergency']);
}
$data['radiologi_terstruktur'] = ems_ai_ds_sanitize_structured_radiology($data['radiologi_terstruktur'] ?? null);

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
