<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_radiology.php';

function ems_ai_radiology_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'radiology_center.php', '/dashboard/index.php');
ems_ai_radiology_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ems_ai_radiology_json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    ems_ai_radiology_json_response(['ok' => false, 'message' => 'Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.'], 419);
}

$user = $_SESSION['user_rh'] ?? [];
$userId = isset($user['id']) ? (int) $user['id'] : 0;
$effectiveUnit = ems_effective_unit($pdo, $user);
$division = (string) ($user['division'] ?? '');

// "Generate Ulang" dari riwayat: pakai ulang PERSIS input dari baris asal
// (bukan dari form), dan lewati pengecekan kode-sudah-dipakai karena ini
// memang sengaja generate ulang dengan kode referensi yang sama.
$regenerateOfId = (int) ($_POST['regenerate_of'] ?? 0);
$diagnosisCode = null;

if ($regenerateOfId > 0) {
    $origStmt = $pdo->prepare("SELECT * FROM ai_radiology_images WHERE id = ? AND unit_code = ?");
    $origStmt->execute([$regenerateOfId, $effectiveUnit]);
    $orig = $origStmt->fetch(PDO::FETCH_ASSOC);
    if (!$orig) {
        ems_ai_radiology_json_response(['ok' => false, 'message' => 'Citra radiologi asal untuk generate ulang tidak ditemukan.'], 404);
    }
    $modality = (string) $orig['modality'];
    $category = (string) $orig['category'];
    $bodyRegion = (string) $orig['body_region'];
    $projection = (string) $orig['projection'];
    $clinicalFinding = (string) $orig['clinical_finding'];
    $anamnesis = (string) ($orig['anamnesis'] ?? '');
    $patientName = (string) ($orig['patient_name'] ?? '');
    $patientDob = $orig['patient_dob'] !== null ? (string) $orig['patient_dob'] : null;
    $patientCitizenId = (string) ($orig['patient_citizen_id'] ?? '');
    $doctorName = (string) ($orig['doctor_name'] ?? '');
    $diagnosisCode = $orig['source_report_code'] !== null ? (string) $orig['source_report_code'] : null;
} else {
    $modality = trim((string) ($_POST['modality'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $bodyRegion = trim((string) ($_POST['body_region'] ?? ''));
    $projection = trim((string) ($_POST['projection'] ?? ''));
    $clinicalFinding = trim((string) ($_POST['clinical_finding'] ?? ''));
    $anamnesis = trim((string) ($_POST['anamnesis'] ?? ''));
    $patientName = trim((string) ($_POST['patient_name'] ?? ''));
    $patientDobInput = trim((string) ($_POST['patient_dob'] ?? ''));
    $patientCitizenId = trim((string) ($_POST['patient_citizen_id'] ?? ''));
    $doctorName = trim((string) ($_POST['doctor_name'] ?? ''));
    $diagnosisCodeInput = trim((string) ($_POST['diagnosis_code'] ?? ''));
    $diagnosisCode = $diagnosisCodeInput !== '' ? $diagnosisCodeInput : null;

    if (!in_array($modality, ems_ai_radiology_modalities(), true)) {
        ems_ai_radiology_json_response(['ok' => false, 'message' => 'Modality tidak valid.'], 422);
    }
    if (!in_array($category, ems_ai_radiology_categories($modality), true)) {
        ems_ai_radiology_json_response(['ok' => false, 'message' => 'Category tidak valid untuk modality ini.'], 422);
    }
    if (!in_array($bodyRegion, ems_ai_radiology_body_regions_for($modality, $category), true)) {
        ems_ai_radiology_json_response(['ok' => false, 'message' => 'Body Region tidak valid untuk category ini.'], 422);
    }
    if (!ems_ai_radiology_is_valid_selection($modality, $category, $bodyRegion, $projection)) {
        ems_ai_radiology_json_response(['ok' => false, 'message' => 'Projection/Options tidak valid untuk body region ini.'], 422);
    }
    if (!in_array($clinicalFinding, ems_ai_radiology_clinical_findings(), true)) {
        ems_ai_radiology_json_response(['ok' => false, 'message' => 'Temuan klinis tidak valid.'], 422);
    }

    $patientDob = null;
    if ($patientDobInput !== '') {
        $dobTimestamp = strtotime($patientDobInput);
        $patientDob = $dobTimestamp ? date('Y-m-d', $dobTimestamp) : null;
    }

    if ($diagnosisCode !== null && ems_ai_ds_report_code_used_on($pdo, 'ai_radiology_images', $diagnosisCode, $effectiveUnit)) {
        ems_ai_radiology_json_response(['ok' => false, 'message' => 'Kode referensi ini sudah pernah dipakai di Radiology Center. Gunakan tombol "Generate Ulang" pada riwayat kalau ingin membuat ulang dengan kode yang sama, atau pakai kode referensi lain.'], 409);
    }
}

$promptInput = [
    'modality' => $modality,
    'category' => $category,
    'body_region' => $bodyRegion,
    'projection' => $projection,
    'clinical_finding' => $clinicalFinding,
    'anamnesis' => $anamnesis,
    'patient_name' => $patientName,
];
$prompt = ems_ai_radiology_build_prompt($promptInput);

function ems_ai_radiology_insert_row(PDO $pdo, array $values): int
{
    $insert = $pdo->prepare("
        INSERT INTO ai_radiology_images
            (user_id, unit_code, division_snapshot, patient_name, patient_dob, patient_citizen_id, doctor_name,
             anamnesis, source_report_code, modality, category, body_region, projection, clinical_finding, prompt_used, image_path, status, error_message,
             report_findings, report_diagnosis, report_recommendations, report_text, report_status, report_error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute($values);
    return (int) $pdo->lastInsertId();
}

$baseValues = [
    $userId, $effectiveUnit, $division,
    $patientName !== '' ? $patientName : null,
    $patientDob,
    $patientCitizenId !== '' ? $patientCitizenId : null,
    $doctorName !== '' ? $doctorName : null,
    $anamnesis !== '' ? $anamnesis : null,
    $diagnosisCode,
    $modality, $category, $bodyRegion, $projection, $clinicalFinding,
    $prompt,
];

// Laporan bacaan (teks) dan citra digenerate independen — satu gagal tidak
// boleh menggagalkan yang lain, karena jalur AI-nya beda (teks lewat
// ems_ai_ds_call_gemini, citra lewat Gemini image-gen/Cloudflare).
$reportInput = [
    'modality' => $modality,
    'category' => $category,
    'body_region' => $bodyRegion,
    'projection' => $projection,
    'clinical_finding' => $clinicalFinding,
    'anamnesis' => $anamnesis,
];
$reportResult = ['ok' => false, 'error' => 'Model AI belum merespons.'];
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $reportResult = ems_ai_radiology_generate_report($pdo, $reportInput, $userId ?: null);
    if ($reportResult['ok']) {
        break;
    }
}
if ($reportResult['ok']) {
    $reportValues = [
        implode("\n", $reportResult['data']['findings']),
        $reportResult['data']['diagnosis'],
        implode("\n", $reportResult['data']['recommendations']),
        $reportResult['data']['report_text'],
        'done',
        null,
    ];
} else {
    $reportValues = [null, null, null, null, 'error', (string) ($reportResult['error'] ?? 'Model AI gagal merespons.')];
}

$imageResult = ['ok' => false, 'error' => 'Model AI belum merespons.'];
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $imageResult = ems_ai_radiology_generate_image($pdo, $prompt, $userId ?: null);
    if ($imageResult['ok']) {
        break;
    }
}

// Citra dan laporan teks independen — kalau citra gagal tapi laporan teks
// berhasil (atau sebaliknya), tetap simpan & arahkan user ke halaman hasil
// (bukan gagal total), supaya apa pun yang berhasil tidak hilang begitu saja.
// Halaman radiology_report.php sudah menampilkan alert error terpisah untuk
// masing-masing (status citra vs report_status) kalau salah satunya gagal.
if (!$imageResult['ok']) {
    $errorMessage = (string) ($imageResult['error'] ?? 'Model AI gagal merespons. Silakan coba lagi.');
    $imageId = ems_ai_radiology_insert_row($pdo, array_merge($baseValues, [null, 'error', $errorMessage], $reportValues));

    if (!$reportResult['ok']) {
        ems_ai_radiology_json_response(['ok' => false, 'message' => 'Model AI error: ' . $errorMessage . ' Harap coba lagi.'], 502);
    }

    ems_ai_radiology_json_response(['ok' => true, 'message' => 'Bacaan radiologi berhasil dibuat, tapi generate citra gagal: ' . $errorMessage, 'image_id' => $imageId]);
}

$image = $imageResult['image'];
$imagePath = ems_ai_radiology_save_image_file((string) $image['data'], (string) $image['mime_type']);

if ($imagePath === null) {
    $imageId = ems_ai_radiology_insert_row($pdo, array_merge($baseValues, [null, 'error', 'Gagal menyimpan file gambar hasil generate ke storage server.'], $reportValues));

    if (!$reportResult['ok']) {
        ems_ai_radiology_json_response(['ok' => false, 'message' => 'Gagal menyimpan file gambar hasil generate. Silakan coba lagi.'], 500);
    }

    ems_ai_radiology_json_response(['ok' => true, 'message' => 'Bacaan radiologi berhasil dibuat, tapi gagal menyimpan file gambar.', 'image_id' => $imageId]);
}

// Tempel info pasien/dokter/temuan ke pojok gambar (bukan minta AI menuliskannya —
// model image-generation tidak bisa diandalkan untuk teks akurat). Kalau overlay
// gagal (mis. format gambar tak terduga), citra mentah tanpa overlay tetap dipakai
// daripada menggagalkan seluruh generate.
ems_ai_radiology_apply_overlay($imagePath, [
    'patient_name' => $patientName,
    'age_label' => ems_ai_radiology_age_label($patientDob),
    'patient_citizen_id' => $patientCitizenId,
    'doctor_name' => $doctorName,
    'modality' => $modality,
    'body_region' => $bodyRegion,
    'projection' => $projection,
    'clinical_finding' => $clinicalFinding,
]);

$imageId = ems_ai_radiology_insert_row($pdo, array_merge($baseValues, [$imagePath, 'done', null], $reportValues));

ems_ai_radiology_json_response(['ok' => true, 'message' => 'Citra radiologi berhasil dibuat.', 'image_id' => $imageId]);
