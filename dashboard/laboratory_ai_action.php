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

function ems_ai_lab_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'laboratory_ai.php', '/dashboard/index.php');
ems_ai_laboratory_ensure_tables($pdo);
ems_ai_ds_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ems_ai_lab_json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    ems_ai_lab_json_response(['ok' => false, 'message' => 'Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.'], 419);
}

$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$division = (string) ($user['division'] ?? '');

// "Generate Ulang" dari riwayat: pakai ulang PERSIS input dari baris asal
// (bukan dari form), dan lewati pengecekan kode-sudah-dipakai karena ini
// memang sengaja generate ulang dengan kode referensi yang sama.
$regenerateOfId = (int) ($_POST['regenerate_of'] ?? 0);
$diagnosisCode = null;

if ($regenerateOfId > 0) {
    $origStmt = $pdo->prepare("SELECT * FROM ai_laboratory_results WHERE id = ? AND unit_code = ?");
    $origStmt->execute([$regenerateOfId, $effectiveUnit]);
    $orig = $origStmt->fetch(PDO::FETCH_ASSOC);
    if (!$orig) {
        ems_ai_lab_json_response(['ok' => false, 'message' => 'Hasil laboratorium asal untuk generate ulang tidak ditemukan.'], 404);
    }
    $department = (string) $orig['department'];
    $category = (string) $orig['category'];
    $level3Value = $orig['level3_option'] !== null ? (string) $orig['level3_option'] : null;
    $specimenType = (string) $orig['specimen_type'];
    $clinicalInfo = (string) $orig['clinical_info'];
    $customParameters = !empty($orig['custom_parameters']) ? array_map('trim', explode(',', (string) $orig['custom_parameters'])) : [];
    $patientName = (string) ($orig['patient_name'] ?? '');
    $patientDob = $orig['patient_dob'] !== null ? (string) $orig['patient_dob'] : null;
    $patientCitizenId = (string) ($orig['patient_citizen_id'] ?? '');
    $doctorName = (string) ($orig['doctor_name'] ?? '');
    $diagnosisCode = $orig['source_report_code'] !== null ? (string) $orig['source_report_code'] : null;
} else {
    $department = trim((string) ($_POST['department'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $level3Option = trim((string) ($_POST['level3_option'] ?? ''));
    $specimenType = trim((string) ($_POST['specimen_type'] ?? ''));
    $clinicalInfo = trim((string) ($_POST['clinical_info'] ?? ''));
    $customParametersInput = isset($_POST['custom_parameters']) && is_array($_POST['custom_parameters']) ? $_POST['custom_parameters'] : [];
    $diagnosisCodeInput = trim((string) ($_POST['diagnosis_code'] ?? ''));
    $diagnosisCode = $diagnosisCodeInput !== '' ? $diagnosisCodeInput : null;

    if ($department === '' || $category === '' || $specimenType === '' || $clinicalInfo === '') {
        ems_ai_lab_json_response(['ok' => false, 'message' => 'Departemen, kategori, spesimen, dan info klinis wajib diisi.'], 422);
    }

    if (!in_array($department, ems_ai_laboratory_departments(), true)) {
        ems_ai_lab_json_response(['ok' => false, 'message' => 'Departemen tidak valid.'], 422);
    }
    if (!in_array($category, ems_ai_laboratory_categories($department), true)) {
        ems_ai_lab_json_response(['ok' => false, 'message' => 'Kategori pemeriksaan tidak valid.'], 422);
    }

    $catInfo = ems_ai_laboratory_category_info($department, $category);
    $level3Value = null;
    if (($catInfo['type'] ?? '') === 'select') {
        if ($level3Option === '' || !in_array($level3Option, $catInfo['options'] ?? [], true)) {
            ems_ai_lab_json_response(['ok' => false, 'message' => 'Pilihan spesifik untuk kategori ini wajib dipilih.'], 422);
        }
        $level3Value = $level3Option;
    }

    if (!in_array($specimenType, ems_ai_laboratory_specimens_for($department, $category), true)) {
        ems_ai_lab_json_response(['ok' => false, 'message' => 'Jenis spesimen tidak valid untuk departemen/kategori ini.'], 422);
    }

    $customParameters = [];
    if ($level3Value !== null && ems_ai_laboratory_should_show_custom_params($category, $level3Value)) {
        $allowedCustom = ems_ai_laboratory_custom_params($department, $category);
        foreach ($customParametersInput as $param) {
            $param = trim((string) $param);
            if ($param !== '' && in_array($param, $allowedCustom, true)) {
                $customParameters[] = $param;
            }
        }
        if ($customParameters === []) {
            ems_ai_lab_json_response(['ok' => false, 'message' => 'Pilih minimal satu parameter kustom.'], 422);
        }
    }

    $patientName = trim((string) ($_POST['patient_name'] ?? ''));
    $patientDobInput = trim((string) ($_POST['patient_dob'] ?? ''));
    $patientDob = null;
    if ($patientDobInput !== '') {
        $dobTimestamp = strtotime($patientDobInput);
        $patientDob = $dobTimestamp ? date('Y-m-d', $dobTimestamp) : null;
    }
    $patientCitizenId = trim((string) ($_POST['patient_citizen_id'] ?? ''));
    $doctorName = trim((string) ($_POST['doctor_name'] ?? '')) ?: (string) ($user['name'] ?? '');

    if ($diagnosisCode !== null && ems_ai_ds_report_code_used_on($pdo, 'ai_laboratory_results', $diagnosisCode, $effectiveUnit)) {
        ems_ai_lab_json_response(['ok' => false, 'message' => 'Kode referensi ini sudah pernah dipakai di Laboratory AI. Gunakan tombol "Generate Ulang" pada riwayat kalau ingin membuat ulang dengan kode yang sama, atau pakai kode referensi lain.'], 409);
    }
}

$systemPrompt = ems_ai_laboratory_default_system_prompt();
$userPrompt = ems_ai_laboratory_build_user_prompt([
    'department' => $department,
    'category' => $category,
    'level3_option' => $level3Value,
    'custom_parameters' => $customParameters,
    'specimen_type' => $specimenType,
    'clinical_info' => $clinicalInfo,
]);

$identityLines = [];
if ($patientName !== '') {
    $identityLines[] = 'Nama: ' . $patientName;
}
if ($patientDob !== null) {
    $identityLines[] = 'Usia: ' . ems_ai_radiology_age_label($patientDob);
}
if ($patientCitizenId !== '') {
    $identityLines[] = 'Citizen ID: ' . $patientCitizenId;
}
if ($identityLines !== []) {
    $userPrompt = "IDENTITAS PASIEN:\n" . implode("\n", $identityLines) . "\n\n" . $userPrompt;
}
if ($doctorName !== '') {
    $userPrompt .= "\n\nDokter Pemeriksa: " . $doctorName;
}

$result = ['ok' => false, 'error' => 'Model AI belum merespons.'];
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $result = ems_ai_ds_call_gemini($pdo, $systemPrompt, $userPrompt, 'ai_laboratory', isset($user['id']) ? (int) $user['id'] : null);
    if ($result['ok']) {
        break;
    }
}

$customParametersStore = $customParameters !== [] ? implode(', ', $customParameters) : null;

if (!$result['ok']) {
    $errorMessage = (string) ($result['error'] ?? 'Model AI gagal merespons. Silakan coba lagi.');

    $insertFail = $pdo->prepare("
        INSERT INTO ai_laboratory_results
            (user_id, unit_code, division_snapshot, patient_name, patient_dob, patient_citizen_id, doctor_name,
             department, category, level3_option, custom_parameters, specimen_type, clinical_info, source_report_code, result_json, status, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'error', ?)
    ");
    $insertFail->execute([
        isset($user['id']) ? (int) $user['id'] : 0,
        $effectiveUnit,
        $division,
        $patientName !== '' ? $patientName : null,
        $patientDob,
        $patientCitizenId !== '' ? $patientCitizenId : null,
        $doctorName !== '' ? $doctorName : null,
        $department,
        $category,
        $level3Value,
        $customParametersStore,
        $specimenType,
        $clinicalInfo,
        $diagnosisCode,
        $errorMessage,
    ]);

    ems_ai_lab_json_response(['ok' => false, 'message' => 'Model AI error: ' . $errorMessage . ' Harap coba lagi.'], 502);
}

$data = ems_ai_laboratory_sanitize_result($result['data']);

$reportCode = null;
for ($codeAttempt = 0; $codeAttempt < 5; $codeAttempt++) {
    $candidate = ems_ai_laboratory_generate_report_code();
    $dupCheck = $pdo->prepare('SELECT 1 FROM ai_laboratory_results WHERE report_code = ?');
    $dupCheck->execute([$candidate]);
    if (!$dupCheck->fetchColumn()) {
        $reportCode = $candidate;
        break;
    }
}

$insert = $pdo->prepare("
    INSERT INTO ai_laboratory_results
        (report_code, user_id, unit_code, division_snapshot, patient_name, patient_dob, patient_citizen_id, doctor_name,
         department, category, level3_option, custom_parameters, specimen_type, clinical_info, source_report_code, result_json, status, error_message)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'done', NULL)
");
$insert->execute([
    $reportCode,
    isset($user['id']) ? (int) $user['id'] : 0,
    $effectiveUnit,
    $division,
    $patientName !== '' ? $patientName : null,
    $patientDob,
    $patientCitizenId !== '' ? $patientCitizenId : null,
    $doctorName !== '' ? $doctorName : null,
    $department,
    $category,
    $level3Value,
    $customParametersStore,
    $specimenType,
    $clinicalInfo,
    $diagnosisCode,
    json_encode($data, JSON_UNESCAPED_UNICODE),
]);

$reportId = (int) $pdo->lastInsertId();

ems_ai_lab_json_response(['ok' => true, 'message' => 'Hasil laboratorium berhasil dibuat.', 'report_id' => $reportId]);
