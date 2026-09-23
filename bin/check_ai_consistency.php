<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/ai_medical_record.php';
require_once __DIR__ . '/../config/ai_laboratory.php';
require_once __DIR__ . '/../config/ai_official_documents.php';

assert(ems_ai_ds_effective_operation_category('Minor', 'ORIF radius') === 'Mayor');

$diagnosis = ems_ai_ds_normalize_diagnosis_result([
    'gcs' => 'E4 V4 M6 (13)',
    'jenis_operasi' => 'Minor - ORIF',
    'kasus_tindakan' => 'ORIF radius',
]);
assert($diagnosis['gcs'] === 'E4 V4 M6 (14)');
assert(str_contains($diagnosis['roleplay_note'], 'Konflik data GCS'));
assert(str_starts_with($diagnosis['jenis_operasi'], 'Mayor'));

$lab = ems_ai_laboratory_sanitize_result([
    'results' => [['parameter' => 'Suhu', 'result' => '', 'flag' => 'Normal']],
]);
assert($lab['results'][0]['result'] === 'Data belum tersedia');
assert($lab['results'][0]['flag'] === 'Belum dinilai');

$medical = ems_ai_medical_record_sanitize([]);
assert($medical['status_pasca_operasi_umum'] === 'Data belum tersedia');
assert($medical['prognosis_kategori'] === 'Data belum tersedia');
assert(str_contains(ems_ai_official_consistency_guardrail(), 'ORIF/Open Reduction Internal Fixation'));

echo "AI consistency self-check: OK\n";
