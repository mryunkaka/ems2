<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';

function ems_ai_diag_lookup_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_diagnosis_assistant.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$code = trim((string) ($_GET['code'] ?? ''));

if ($code === '') {
    ems_ai_diag_lookup_response(['ok' => false, 'message' => 'Kode referensi wajib diisi.'], 422);
}

$effectiveUnit = ems_effective_unit($pdo, $user);
$report = ems_ai_ds_find_diagnosis_report_by_code($pdo, $code, $effectiveUnit);

if (!$report) {
    ems_ai_diag_lookup_response(['ok' => false, 'message' => 'Laporan diagnosis dengan kode tersebut tidak ditemukan.'], 404);
}

// Kalau halaman pemanggil mengirim ?target=<tabel tujuan>, kasih tahu SAAT
// fetch ini juga (bukan baru ketahuan pas submit ditolak 409) kalau kode
// ini sudah pernah dipakai di halaman itu, plus siapa yang memakainya.
$targetTable = trim((string) ($_GET['target'] ?? ''));
$allowedTargets = ['ai_surgery_plans', 'ai_radiology_images', 'ai_laboratory_results', 'ai_psychiatry_assessments'];
$usedOnTarget = null;
if (in_array($targetTable, $allowedTargets, true)) {
    $usageInfo = ems_ai_ds_report_code_usage_info($pdo, $targetTable, $code, $effectiveUnit);
    if ($usageInfo !== null) {
        $usedOnTarget = [
            'user_name' => (string) ($usageInfo['user_name'] ?? '-'),
            'created_at' => (string) ($usageInfo['created_at'] ?? ''),
        ];
    }
}

$result = [];
if (!empty($report['result_json'])) {
    $decoded = json_decode((string) $report['result_json'], true);
    if (is_array($decoded)) {
        $result = ems_ai_ds_normalize_diagnosis_result($decoded);
    }
}

$structuredRadiology = is_array($result['radiologi_terstruktur'] ?? null) ? $result['radiologi_terstruktur'] : null;
if ($structuredRadiology !== null && trim((string) ($structuredRadiology['modality'] ?? '')) === '') {
    $structuredRadiology = null;
}

$structuredLaboratory = is_array($result['laboratorium_terstruktur'] ?? null) ? $result['laboratorium_terstruktur'] : null;
if ($structuredLaboratory !== null && trim((string) ($structuredLaboratory['department'] ?? '')) === '') {
    $structuredLaboratory = null;
}

$anamnesisLengkap = trim((string) ($result['anamnesis_lengkap'] ?? ''));

ems_ai_diag_lookup_response([
    'ok' => true,
    'report_id' => (int) $report['id'],
    'report_code' => (string) $report['report_code'],
    'anamnesis' => $anamnesisLengkap !== '' ? $anamnesisLengkap : (string) $report['anamnesis'],
    'diagnosis_utama' => (string) ($result['diagnosis_utama'] ?? ''),
    'kasus_tindakan' => (string) ($result['kasus_tindakan'] ?? ''),
    'jenis_operasi' => (string) ($result['jenis_operasi'] ?? ''),
    'jenis_anestesi' => (string) ($result['jenis_anestesi'] ?? ''),
    'radiologi_terstruktur' => $structuredRadiology,
    'laboratorium_terstruktur' => $structuredLaboratory,
    'patient_name' => (string) ($report['patient_name'] ?? ''),
    'patient_gender' => (string) ($report['patient_gender'] ?? ''),
    'patient_dob' => (string) ($report['patient_dob'] ?? ''),
    'patient_citizen_id' => (string) ($report['patient_citizen_id'] ?? ''),
    'used_on_target' => $usedOnTarget,
]);
