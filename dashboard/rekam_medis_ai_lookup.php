<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_medical_record.php';

/**
 * Agregator "Rekam Medis AI": dari SATU kode referensi (DGN-...), tarik
 * seluruh data yang berelasi lewat source_report_code di 4 modul AI lain
 * (Surgery Planner/Radiology Center/Laboratory AI/Psychiatry Center),
 * supaya dashboard/rekam_medis_ai.php bisa membangun preview & draft rekam
 * medis otomatis tanpa user perlu buka satu-satu laporan itu. Logika
 * agregasinya sendiri ada di ems_rmai_aggregate() (config/ai_medical_record.php)
 * supaya dipakai bersama dengan rekam_medis_ai_generate.php.
 */

function ems_rmai_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ems_ai_ds_ensure_tables($pdo);
ems_ai_radiology_ensure_tables($pdo);
ems_ai_laboratory_ensure_tables($pdo);
ems_ai_psychiatry_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$code = trim((string) ($_GET['code'] ?? ''));

if ($code === '') {
    ems_rmai_response(['ok' => false, 'message' => 'Kode referensi wajib diisi.'], 422);
}

$effectiveUnit = ems_effective_unit($pdo, $user);

$agg = ems_rmai_aggregate($pdo, $code, $effectiveUnit);
if ($agg === null) {
    ems_rmai_response(['ok' => false, 'message' => 'Laporan AI Diagnosis Assistant dengan kode tersebut tidak ditemukan.'], 404);
}

ems_rmai_response(array_merge(['ok' => true], $agg));
