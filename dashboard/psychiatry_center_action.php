<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/ai_psychiatry.php';

function ems_ai_psy_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'psychiatry_center.php', '/dashboard/index.php');
ems_ai_psychiatry_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ems_ai_psy_json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    ems_ai_psy_json_response(['ok' => false, 'message' => 'Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.'], 419);
}

$user = $_SESSION['user_rh'] ?? [];
$userId = isset($user['id']) ? (int) $user['id'] : 0;
$action = trim((string) ($_POST['action'] ?? ''));
$effectiveUnit = ems_effective_unit($pdo, $user);
$division = (string) ($user['division'] ?? '');

// "Generate Ulang" dari riwayat: bukan interview baru, langsung finalize
// ulang PERSIS dengan input + transcript dari baris asal (bukan dari
// form/state klien), dan lewati pengecekan kode-sudah-dipakai karena ini
// memang sengaja generate ulang dengan kode referensi yang sama.
$regenerateOfId = (int) ($_POST['regenerate_of'] ?? 0);
$diagnosisCode = null;
$isRegenerate = false;

if ($regenerateOfId > 0) {
    $origStmt = $pdo->prepare("SELECT * FROM ai_psychiatry_assessments WHERE id = ? AND unit_code = ?");
    $origStmt->execute([$regenerateOfId, $effectiveUnit]);
    $orig = $origStmt->fetch(PDO::FETCH_ASSOC);
    if (!$orig) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Asesmen psikiatri asal untuk generate ulang tidak ditemukan.'], 404);
    }

    $action = 'finalize';
    $isRegenerate = true;
    $department = (string) $orig['department'];
    $assessmentType = (string) $orig['assessment_type'];
    $priority = (string) $orig['priority'];
    $chiefComplaint = (string) $orig['chief_complaint'];
    $anamnesis = (string) ($orig['anamnesis'] ?? '');
    $patientNameOrig = (string) ($orig['patient_name'] ?? '');
    $patientDobOrig = $orig['patient_dob'] !== null ? (string) $orig['patient_dob'] : null;
    $patientCitizenIdOrig = (string) ($orig['patient_citizen_id'] ?? '');
    $doctorNameOrig = (string) ($orig['doctor_name'] ?? '');
    $diagnosisCode = $orig['source_report_code'] !== null ? (string) $orig['source_report_code'] : null;
    $chatHistory = [];
    if (!empty($orig['chat_transcript'])) {
        $decodedChat = json_decode((string) $orig['chat_transcript'], true);
        if (is_array($decodedChat)) {
            $chatHistory = $decodedChat;
        }
    }
} else {
    $department = trim((string) ($_POST['department'] ?? ''));
    $assessmentType = trim((string) ($_POST['assessment_type'] ?? ''));
    $priority = trim((string) ($_POST['priority'] ?? ''));
    $chiefComplaint = trim((string) ($_POST['chief_complaint'] ?? ''));
    $anamnesis = trim((string) ($_POST['anamnesis'] ?? ''));
    $diagnosisCodeInput = trim((string) ($_POST['diagnosis_code'] ?? ''));
    $diagnosisCode = $diagnosisCodeInput !== '' ? $diagnosisCodeInput : null;

    if (!in_array($department, ems_ai_psychiatry_departments(), true)) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Departemen tidak valid.'], 422);
    }
    if ($chiefComplaint === '' || $anamnesis === '') {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Keluhan utama dan anamnesis wajib diisi.'], 422);
    }

    if ($action === 'finalize' && $diagnosisCode !== null && ems_ai_ds_report_code_used_on($pdo, 'ai_psychiatry_assessments', $diagnosisCode, $effectiveUnit)) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Kode referensi ini sudah pernah dipakai di Psychiatry Center. Gunakan tombol "Generate Ulang" pada riwayat kalau ingin membuat ulang dengan kode yang sama, atau pakai kode referensi lain.'], 409);
    }

    $chatHistoryRaw = trim((string) ($_POST['chat_history'] ?? '[]'));
    $chatHistory = json_decode($chatHistoryRaw, true);
    if (!is_array($chatHistory)) {
        $chatHistory = [];
    }
}

if ($action === 'start') {
    if (!in_array($assessmentType, ems_ai_psychiatry_assessment_types(), true)) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Tipe asesmen tidak valid.'], 422);
    }
    if (!in_array($priority, ems_ai_psychiatry_priorities(), true)) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Prioritas tidak valid.'], 422);
    }

    $result = ['ok' => false, 'error' => 'Model AI belum merespons.'];
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $result = ems_ai_psychiatry_generate_start($pdo, [
            'department' => $department,
            'assessment_type' => $assessmentType,
            'priority' => $priority,
            'chief_complaint' => $chiefComplaint,
            'anamnesis' => $anamnesis,
        ], $userId ?: null);
        if ($result['ok']) {
            break;
        }
    }

    if (!$result['ok']) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Model AI error: ' . (string) ($result['error'] ?? 'Gagal merespons.') . ' Harap coba lagi.'], 502);
    }

    ems_ai_psy_json_response([
        'ok' => true,
        'clinical_impressions' => $result['data']['clinical_impressions'],
        'first_question' => $result['data']['first_question'],
    ]);
}

if ($action === 'next') {
    $turn = (int) ($_POST['turn'] ?? 0);
    $dialogContext = ems_ai_psychiatry_render_dialog_context($chatHistory);

    $result = ['ok' => false, 'error' => 'Model AI belum merespons.'];
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $result = ems_ai_psychiatry_generate_next($pdo, [
            'department' => $department,
            'chief_complaint' => $chiefComplaint,
            'anamnesis' => $anamnesis,
            'dialog_context' => $dialogContext,
            'turn' => $turn,
        ], $userId ?: null);
        if ($result['ok']) {
            break;
        }
    }

    if (!$result['ok']) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Model AI error: ' . (string) ($result['error'] ?? 'Gagal merespons.') . ' Harap coba lagi.'], 502);
    }

    ems_ai_psy_json_response([
        'ok' => true,
        'clinical_impressions' => $result['data']['clinical_impressions'],
        'next_question' => $result['data']['next_question'],
    ]);
}

if ($action === 'finalize') {
    if (!in_array($assessmentType, ems_ai_psychiatry_assessment_types(), true)) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Tipe asesmen tidak valid.'], 422);
    }
    if (!in_array($priority, ems_ai_psychiatry_priorities(), true)) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Prioritas tidak valid.'], 422);
    }

    if ($isRegenerate) {
        $patientName = $patientNameOrig;
        $patientDob = $patientDobOrig;
        $patientCitizenId = $patientCitizenIdOrig;
        $doctorName = $doctorNameOrig;
        $skipped = true;
    } else {
        $patientName = trim((string) ($_POST['patient_name'] ?? ''));
        $patientDobInput = trim((string) ($_POST['patient_dob'] ?? ''));
        $patientDob = null;
        if ($patientDobInput !== '') {
            $dobTimestamp = strtotime($patientDobInput);
            $patientDob = $dobTimestamp ? date('Y-m-d', $dobTimestamp) : null;
        }
        $patientCitizenId = trim((string) ($_POST['patient_citizen_id'] ?? ''));
        $doctorName = trim((string) ($_POST['doctor_name'] ?? '')) ?: (string) ($user['name'] ?? '');
        $skipped = !empty($_POST['skipped']);
    }

    $dialogContext = ems_ai_psychiatry_render_dialog_context($chatHistory);

    $result = ['ok' => false, 'error' => 'Model AI belum merespons.'];
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $result = ems_ai_psychiatry_generate_final($pdo, [
            'patient_name' => $patientName,
            'patient_dob' => $patientDob,
            'patient_citizen_id' => $patientCitizenId,
            'department' => $department,
            'assessment_type' => $assessmentType,
            'chief_complaint' => $chiefComplaint,
            'anamnesis' => $anamnesis,
            'dialog_context' => $dialogContext,
            'skipped' => $skipped,
        ], $userId ?: null);
        if ($result['ok']) {
            break;
        }
    }

    $reportCode = null;
    if ($result['ok']) {
        for ($codeAttempt = 0; $codeAttempt < 5; $codeAttempt++) {
            $candidate = ems_ai_psychiatry_generate_report_code();
            $dupCheck = $pdo->prepare('SELECT 1 FROM ai_psychiatry_assessments WHERE report_code = ?');
            $dupCheck->execute([$candidate]);
            if (!$dupCheck->fetchColumn()) {
                $reportCode = $candidate;
                break;
            }
        }
    }

    $insert = $pdo->prepare("
        INSERT INTO ai_psychiatry_assessments
            (report_code, user_id, unit_code, division_snapshot, patient_name, patient_dob, patient_citizen_id, doctor_name,
             department, assessment_type, priority, chief_complaint, anamnesis, source_report_code, chat_transcript, result_json, status, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $reportCode,
        $userId,
        $effectiveUnit,
        $division,
        $patientName !== '' ? $patientName : null,
        $patientDob,
        $patientCitizenId !== '' ? $patientCitizenId : null,
        $doctorName !== '' ? $doctorName : null,
        $department,
        $assessmentType,
        $priority,
        $chiefComplaint,
        $anamnesis,
        $diagnosisCode,
        json_encode($chatHistory, JSON_UNESCAPED_UNICODE),
        $result['ok'] ? json_encode($result['data'], JSON_UNESCAPED_UNICODE) : null,
        $result['ok'] ? 'done' : 'error',
        $result['ok'] ? null : (string) ($result['error'] ?? 'Model AI gagal merespons.'),
    ]);
    $reportId = (int) $pdo->lastInsertId();

    if (!$result['ok']) {
        ems_ai_psy_json_response(['ok' => false, 'message' => 'Model AI error: ' . (string) ($result['error'] ?? 'Gagal merespons.') . ' Harap coba lagi.', 'report_id' => $reportId], 502);
    }

    ems_ai_psy_json_response(['ok' => true, 'message' => 'Asesmen psikiatri berhasil dibuat.', 'report_id' => $reportId]);
}

ems_ai_psy_json_response(['ok' => false, 'message' => 'Aksi tidak dikenali.'], 422);
