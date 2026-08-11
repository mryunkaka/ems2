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

$jenisOperasi = in_array($_POST['jenis_operasi'] ?? '', ['Mayor', 'Minor'], true) ? $_POST['jenis_operasi'] : 'Mayor';
$jenisAnestesi = trim((string) ($_POST['jenis_anestesi'] ?? ''));
$kompleksitas = in_array($_POST['kompleksitas'] ?? '', ['Mudah', 'Sedang', 'Panjang'], true) ? $_POST['kompleksitas'] : 'Sedang';
$kasusTindakan = trim((string) ($_POST['kasus_tindakan'] ?? ''));

if ($jenisAnestesi === '' || $kasusTindakan === '') {
    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Jenis anestesi dan kasus medis / tindakan wajib diisi.'], 422);
}

$stepCountMap = ['Mudah' => 10, 'Sedang' => 20, 'Panjang' => 30];
$jumlahLangkah = $stepCountMap[$kompleksitas];

$effectiveUnit = ems_effective_unit($pdo, $user);
$division = (string) ($user['division'] ?? '');

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
        INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, result_json, status, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'error', ?)
    ");
    $insertFail->execute([
        isset($user['id']) ? (int) $user['id'] : 0,
        $effectiveUnit,
        $division,
        $jenisOperasi,
        $jenisAnestesi,
        $kompleksitas,
        $kasusTindakan,
        $errorMessage,
    ]);

    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Model AI error: ' . $errorMessage . ' Harap coba lagi.'], 502);
}

$data = $result['data'];
if (isset($data['tahapan_prosedur']) && is_array($data['tahapan_prosedur'])) {
    $data['tahapan_prosedur'] = ems_ai_ds_sanitize_step_items($data['tahapan_prosedur']);
}

$insert = $pdo->prepare("
    INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, result_json, status, error_message)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'done', NULL)
");
$insert->execute([
    isset($user['id']) ? (int) $user['id'] : 0,
    $effectiveUnit,
    $division,
    $jenisOperasi,
    $jenisAnestesi,
    $kompleksitas,
    $kasusTindakan,
    json_encode($data, JSON_UNESCAPED_UNICODE),
]);

$planId = (int) $pdo->lastInsertId();

ems_ai_ds_surgery_json_response(['ok' => true, 'message' => 'Rencana operasi berhasil dibuat.', 'plan_id' => $planId]);
