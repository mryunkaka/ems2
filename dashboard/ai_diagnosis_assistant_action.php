<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';

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

$effectiveUnit = ems_effective_unit($pdo, $user);
$division = (string) ($user['division'] ?? '');

$systemPrompt = ems_ai_ds_build_system_prompt($pdo, 'ai_diagnosis_assistant', ems_ai_ds_default_diagnosis_system_prompt());
$template = ems_ai_get_active_prompt_template($pdo, 'ai_diagnosis_assistant');
$userPromptTemplate = trim((string) ($template['user_prompt_template'] ?? '')) !== ''
    ? (string) $template['user_prompt_template']
    : "ANAMNESIS:\n{{anamnesis}}";
$userPrompt = str_replace('{{anamnesis}}', $anamnesis, $userPromptTemplate);

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
        INSERT INTO ai_diagnosis_reports (user_id, unit_code, division_snapshot, anamnesis, result_json, status, error_message)
        VALUES (?, ?, ?, ?, NULL, 'error', ?)
    ");
    $insertFail->execute([
        isset($user['id']) ? (int) $user['id'] : 0,
        $effectiveUnit,
        $division,
        $anamnesis,
        $errorMessage,
    ]);

    ems_ai_ds_json_response(['ok' => false, 'message' => 'Model AI error: ' . $errorMessage . ' Harap coba lagi.'], 502);
}

$data = $result['data'];
if (isset($data['emergency']) && is_array($data['emergency'])) {
    $data['emergency'] = ems_ai_ds_sanitize_step_items($data['emergency']);
}

$insert = $pdo->prepare("
    INSERT INTO ai_diagnosis_reports (user_id, unit_code, division_snapshot, anamnesis, result_json, status, error_message)
    VALUES (?, ?, ?, ?, ?, 'done', NULL)
");
$insert->execute([
    isset($user['id']) ? (int) $user['id'] : 0,
    $effectiveUnit,
    $division,
    $anamnesis,
    json_encode($data, JSON_UNESCAPED_UNICODE),
]);

$reportId = (int) $pdo->lastInsertId();

ems_ai_ds_json_response(['ok' => true, 'message' => 'Diagnosis berhasil dibuat.', 'report_id' => $reportId]);
