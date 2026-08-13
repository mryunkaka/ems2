<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_medical_record.php';

/**
 * Minta Gemini menyusun narasi REKAM MEDIS LENGKAP (bukan sekadar tempel
 * data mentah) dari seluruh data yang berelasi dengan satu kode referensi
 * AI Diagnosis Assistant — dipanggil dari dashboard/rekam_medis_ai.php
 * setelah "Ambil Data" berhasil, sebelum draf dimuat ke editor Quill.
 */

function ems_rmai_gen_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ems_ai_ds_ensure_tables($pdo);
ems_ai_radiology_ensure_tables($pdo);
ems_ai_laboratory_ensure_tables($pdo);
ems_ai_psychiatry_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ems_rmai_gen_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    ems_rmai_gen_response(['ok' => false, 'message' => 'Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.'], 419);
}

$user = $_SESSION['user_rh'] ?? [];
$userId = isset($user['id']) ? (int) $user['id'] : 0;
$code = trim((string) ($_POST['code'] ?? ''));

if ($code === '') {
    ems_rmai_gen_response(['ok' => false, 'message' => 'Kode referensi wajib diisi.'], 422);
}

$effectiveUnit = ems_effective_unit($pdo, $user);

$agg = ems_rmai_aggregate($pdo, $code, $effectiveUnit);
if ($agg === null) {
    ems_rmai_gen_response(['ok' => false, 'message' => 'Laporan AI Diagnosis Assistant dengan kode tersebut tidak ditemukan.'], 404);
}

$result = ['ok' => false, 'error' => 'Model AI belum merespons.'];
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $result = ems_ai_medical_record_generate($pdo, $agg, $userId ?: null);
    if ($result['ok']) {
        break;
    }
}

if (!$result['ok']) {
    ems_rmai_gen_response(['ok' => false, 'message' => 'Model AI error: ' . (string) ($result['error'] ?? 'Gagal merespons.') . ' Harap coba lagi.'], 502);
}

$html = ems_ai_medical_record_build_html($result['data'], $agg);

ems_rmai_gen_response(['ok' => true, 'medical_result_html' => $html]);
