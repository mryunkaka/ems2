<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/ai_medical_record.php';
require_once __DIR__ . '/../config/ai_laboratory.php';
require_once __DIR__ . '/../config/ai_official_documents.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';

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
assert(str_contains(ems_ai_official_consistency_guardrail(), 'Nilai suhu <36°C wajib ditulis sebagai hipotermia'));

$diagnosisPrompt = ems_ai_ds_default_diagnosis_system_prompt();
assert(str_contains($diagnosisPrompt, 'rangkai uraian lengkap yang spesifik terhadap kasus'));
assert(str_contains($diagnosisPrompt, 'jangan mengulang template statis'));
assert(!str_contains($diagnosisPrompt, 'Pasien tidak sadarkan diri habis kecelakaan'));

$surgeryPrompt = ems_ai_ds_default_surgery_system_prompt();
assert(str_contains($surgeryPrompt, 'spesifik terhadap tindakan, kasus, dan SOP'));
assert(str_contains($surgeryPrompt, 'jangan menyalin template langkah yang sama'));
assert(!str_contains($surgeryPrompt, 'Fraktur tungkai kiri'));

$normalizedEmpty = ems_ai_ds_normalize_diagnosis_result([]);
assert($normalizedEmpty['kesadaran'] === 'Data belum tersedia');
assert($normalizedEmpty['motorik'] === 'Data belum tersedia');

$estimatedAssessment = ems_ai_ds_normalize_diagnosis_result([
    'gcs' => 'Data belum tersedia',
    'gcs_estimasi_ai' => [
        'score' => 'E4 V5 M6 (15)',
        'interpretation' => 'Estimasi compos mentis untuk simulasi.',
    ],
    'ttv' => [
        ['label' => 'Tekanan Darah', 'value' => 'Data belum tersedia', 'note' => ''],
        ['label' => 'Nadi', 'value' => 'Data belum tersedia', 'note' => ''],
        ['label' => 'Suhu', 'value' => 'Data belum tersedia', 'note' => ''],
        ['label' => 'Respirasi', 'value' => 'Data belum tersedia', 'note' => ''],
        ['label' => 'Saturasi O2', 'value' => 'Data belum tersedia', 'note' => ''],
    ],
    'ttv_estimasi_ai' => [
        ['label' => 'Tekanan Darah', 'value' => 'Estimasi 120/80 mmHg', 'note' => 'Berdasarkan konteks kasus.'],
        ['label' => 'Nadi', 'value' => 'Estimasi 90 x/menit', 'note' => 'Berdasarkan konteks kasus.'],
        ['label' => 'Suhu', 'value' => 'Estimasi 36,8°C', 'note' => 'Berdasarkan konteks kasus.'],
        ['label' => 'Respirasi', 'value' => 'Estimasi 18 x/menit', 'note' => 'Berdasarkan konteks kasus.'],
        ['label' => 'Saturasi O2', 'value' => 'Estimasi 95%', 'note' => 'Berdasarkan konteks kasus.'],
    ],
]);
assert($estimatedAssessment['gcs_estimasi_ai']['score'] === 'E4 V5 M6 (15)');
assert($estimatedAssessment['gcs_estimasi_ai']['status'] === ems_ai_ds_estimate_status_label());
assert($estimatedAssessment['ttv_estimasi_ai'][0]['status'] === ems_ai_ds_estimate_status_label());
assert(ems_ai_ds_clinical_estimates_missing(['gcs' => 'Data belum tersedia', 'ttv' => []]) === true);
assert(ems_ai_ds_clinical_estimates_missing($estimatedAssessment) === false);
assert(ems_ai_ds_clinical_estimates_missing(['gcs' => 'E4 V4 M6 (14)', 'ttv' => [['label' => 'Tekanan Darah', 'value' => '120/80 mmHg']]]) === false);
$lowTemperatureAssessment = ems_ai_ds_normalize_diagnosis_result([
    'gcs' => 'Data belum tersedia',
    'ttv' => [
        ['label' => 'Tekanan Darah', 'value' => 'Data belum tersedia', 'note' => ''],
        ['label' => 'Nadi', 'value' => 'Data belum tersedia', 'note' => ''],
        ['label' => 'Suhu', 'value' => '33°C', 'note' => 'Suhu sumber 33°C wajib dipertahankan dan diverifikasi'],
        ['label' => 'Respirasi', 'value' => 'Data belum tersedia', 'note' => ''],
        ['label' => 'Saturasi O2', 'value' => 'Data belum tersedia', 'note' => ''],
    ],
    'ttv_estimasi_ai' => [
        ['label' => 'Tekanan Darah', 'value' => '85/50 mmHg', 'note' => 'Hipotensi berat'],
        ['label' => 'Nadi', 'value' => '130 bpm', 'note' => 'Takikardia kompensasi'],
        ['label' => 'Suhu', 'value' => '33°C', 'note' => 'Data sumber 33°C'],
        ['label' => 'Respirasi', 'value' => '28 x/menit', 'note' => 'Takipnea'],
        ['label' => 'Saturasi O2', 'value' => '88%', 'note' => 'Hipoksia'],
    ],
], 'Pasien jatuh; suhu belum dicantumkan pada anamnesis.');
assert($lowTemperatureAssessment['ttv'][2]['value'] === 'Data belum tersedia');
$lowTemperatureDisplay = ems_ai_ds_prepare_ttv_display(
    $lowTemperatureAssessment['ttv'],
    $lowTemperatureAssessment['ttv_estimasi_ai']
);
$lowTemperatureVital = $lowTemperatureDisplay[2];
assert($lowTemperatureVital['label'] === 'Suhu');
assert($lowTemperatureVital['value'] === '33°C');
assert($lowTemperatureVital['_is_estimate'] === true);
assert($lowTemperatureVital['_estimate_status'] === 'Estimasi AI — wajib verifikasi');
assert(str_contains($lowTemperatureVital['note'], 'HIPOTERMIA'));
assert(str_contains($lowTemperatureVital['note'], 'koagulopati'));
assert(str_contains($lowTemperatureVital['note'], 'trauma triad of death'));
assert(!str_contains(mb_strtolower($lowTemperatureVital['note']), 'wajib dipertahankan'));
$lowTemperatureReport = ems_ai_ds_format_diagnosis_report_text(
    ['anamnesis' => 'Pasien trauma dengan syok hemoragik berat.'],
    $lowTemperatureAssessment,
    $lowTemperatureDisplay
);
assert(str_contains($lowTemperatureReport, 'Suhu            : 33°C [Estimasi AI — wajib verifikasi] (HIPOTERMIA'));
assert(str_contains($lowTemperatureReport, 'koagulopati'));
assert(str_contains($lowTemperatureReport, 'trauma triad of death'));
$explicitTemperatureAssessment = ems_ai_ds_normalize_diagnosis_result([
    'ttv' => [
        ['label' => 'Suhu', 'value' => '33°C', 'note' => 'terukur'],
        ['label' => 'Saturasi O2', 'value' => '95%', 'note' => 'terukur'],
    ],
    'ttv_estimasi_ai' => [
        ['label' => 'Suhu', 'value' => '33°C', 'note' => 'estimasi'],
        ['label' => 'Saturasi O2', 'value' => '95%', 'note' => 'estimasi'],
    ],
], 'TTV tercatat: Suhu 33°C dan SpO2 95%.');
assert($explicitTemperatureAssessment['ttv'][2]['value'] === '33°C');
assert($explicitTemperatureAssessment['ttv'][4]['value'] === '95%');
assert(str_contains($explicitTemperatureAssessment['ttv'][2]['note'], 'HIPOTERMIA'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'gcs_estimasi_ai'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'ttv_estimasi_ai'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'M3 = fleksi abnormal/dekortikasi'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'M4 = withdrawal/menarik diri normal'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'osmoterapi'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'informed consent'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'crossmatch'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'KOHERENSI DIAGNOSIS BANDING'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'TRAUMA ABDOMEN DENGAN TANDA KEGAWATAN ABSOLUT'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'eviserasi'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'kassa steril basah/lembab'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'kassa kering'));
assert(str_contains(ems_ai_ds_model_completion_contract('ai_diagnosis_assistant'), 'M3 = fleksi abnormal/dekortikasi'));
assert(str_contains(ems_ai_ds_model_completion_contract('ai_diagnosis_assistant'), 'intubasi'));
assert(str_contains(ems_ai_ds_model_completion_contract('ai_diagnosis_assistant'), 'laparotomi cito'));
assert(ems_ai_ds_estimate_status_label() === 'Estimasi AI — wajib verifikasi');

$m3Assessment = ems_ai_ds_normalize_diagnosis_result([
    'gcs' => 'E4 V4 M3 (11)',
    'motorik' => 'fleksi abnormal/dekortikasi',
]);
assert($m3Assessment['gcs_motor_definition'] === 'M3 = fleksi abnormal/dekortikasi.');
assert(!str_contains(mb_strtolower(json_encode($m3Assessment, JSON_UNESCAPED_UNICODE)), 'fleksi abnormal/withdrawal'));

$m4Assessment = ems_ai_ds_normalize_diagnosis_result([
    'gcs' => 'E4 V4 M4 (12)',
    'motorik' => 'withdrawal/menarik diri normal',
]);
assert($m4Assessment['gcs_motor_definition'] === 'M4 = withdrawal/menarik diri normal.');
assert(!str_contains(mb_strtolower(json_encode($m4Assessment, JSON_UNESCAPED_UNICODE)), 'fleksi abnormal/withdrawal'));

$conflictingMotorAssessment = ems_ai_ds_normalize_diagnosis_result([
    'gcs' => 'E4 V4 M5 (13)',
    'motorik' => 'fleksi abnormal/dekortikasi',
    'gcs_motor_definition' => 'M3 = fleksi abnormal/dekortikasi.',
]);
assert(!isset($conflictingMotorAssessment['gcs_motor_definition']));
assert(str_contains($conflictingMotorAssessment['motorik'], 'Data belum tersedia'));
assert(!str_contains(mb_strtolower(json_encode($conflictingMotorAssessment, JSON_UNESCAPED_UNICODE)), 'fleksi abnormal/withdrawal'));

$oppositeMotorAssessment = ems_ai_ds_normalize_diagnosis_result([
    'gcs' => 'E4 V4 M3 (11)',
    'motorik' => 'withdrawal/menarik diri normal',
]);
assert(!isset($oppositeMotorAssessment['gcs_motor_definition']));
assert($oppositeMotorAssessment['motorik'] === 'Data belum tersedia');
assert(!str_contains(mb_strtolower(json_encode($oppositeMotorAssessment, JSON_UNESCAPED_UNICODE)), 'fleksi abnormal/withdrawal'));

$headTentative = ems_ai_ds_normalize_diagnosis_result([
    'gcs' => 'E2 V2 M4 (8)',
    'kasus_tindakan' => 'Trauma kepala dengan kecurigaan peningkatan tekanan intrakranial.',
    'pemeriksaan_pupil' => [
        'status' => 'isokor',
        'reaktivitas' => 'reaktif',
    ],
]);
assert($headTentative['pemeriksaan_pupil']['status'] === 'isokor');
assert($headTentative['pemeriksaan_pupil']['reaktivitas'] === 'reaktif');
assert($headTentative['status_rencana_operasi'] === 'rencana tentatif — menunggu hasil CT scan');

$headCito = ems_ai_ds_normalize_diagnosis_result([
    'gcs' => 'E1 V1 M3 (5)',
    'kasus_tindakan' => 'Cedera kepala berat dengan kecurigaan peningkatan tekanan intrakranial.',
    'pupil' => [
        'status' => 'anisokor',
        'reaktivitas' => 'non-reaktif',
    ],
]);
assert($headCito['pemeriksaan_pupil']['status'] === 'anisokor');
assert(str_contains($headCito['status_rencana_operasi'], 'cito tanpa menunggu hasil CT scan'));

$pupilEmergency = ems_ai_ds_sanitize_igd_emergency_items(
    [['aksi' => 'memasang oksigen', 'hasil' => 'Oksigenasi direncanakan; hasil wajib diverifikasi.', 'animasi' => 'mechanic']],
    8,
    'Trauma kepala dengan kecurigaan peningkatan tekanan intrakranial.'
);
$pupilEmergencyText = mb_strtolower(implode(' ', array_map(static fn (array $item): string => implode(' ', array_map('strval', $item)), $pupilEmergency)));
assert(str_contains($pupilEmergencyText, 'pupil'));
$pupilHandoffIndex = null;
$pupilExamIndex = null;
foreach ($pupilEmergency as $pupilIndex => $pupilItem) {
    $pupilItemText = mb_strtolower(implode(' ', array_map('strval', $pupilItem)));
    if (str_contains($pupilItemText, 'siap dipindahkan')) {
        $pupilHandoffIndex = $pupilIndex;
    }
    if (str_contains($pupilItemText, 'memeriksa ukuran') && str_contains($pupilItemText, 'pupil')) {
        $pupilExamIndex = $pupilIndex;
    }
}
assert($pupilExamIndex !== null && $pupilHandoffIndex !== null && $pupilExamIndex < $pupilHandoffIndex);

$permissiveHypotensionAssessment = ems_ai_ds_normalize_diagnosis_result([
    'ttv' => [
        ['label' => 'Tekanan Darah', 'value' => '85/50 mmHg', 'note' => 'Hipotensi permisif sebagai temuan TTV'],
    ],
]);
$permissiveHypotensionTtv = json_encode($permissiveHypotensionAssessment['ttv'], JSON_UNESCAPED_UNICODE);
assert(!str_contains(mb_strtolower($permissiveHypotensionTtv), 'hipotensi permisif'));
assert(str_contains(mb_strtolower($permissiveHypotensionTtv), 'tanda awal syok hemoragik ringan-sedang'));
$estimatedHypotensionAssessment = ems_ai_ds_normalize_diagnosis_result([
    'ttv' => [['label' => 'Tekanan Darah', 'value' => 'Data belum tersedia', 'note' => '']],
    'ttv_estimasi_ai' => [['label' => 'Tekanan Darah', 'value' => '85/50 mmHg — hipotensi permisif', 'note' => 'Hipotensi permisif dipakai sebagai temuan TTV', 'basis' => 'Hipotensi permisif untuk pasien trauma.']],
]);
$estimatedHypotensionTtv = json_encode($estimatedHypotensionAssessment['ttv_estimasi_ai'], JSON_UNESCAPED_UNICODE);
assert(!str_contains(mb_strtolower($estimatedHypotensionTtv), 'hipotensi permisif'));
assert(str_contains(mb_strtolower($estimatedHypotensionTtv), 'tanda awal syok hemoragik ringan-sedang'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'hipotensi permisif'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'tanda awal syok hemoragik ringan-sedang'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'pupil'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'rencana tentatif — menunggu hasil CT scan'));
assert(str_contains(ems_ai_ds_model_completion_contract('ai_diagnosis_assistant'), 'M3 = fleksi abnormal/dekortikasi'));
assert(str_contains(ems_ai_ds_model_completion_contract('ai_diagnosis_assistant'), 'M4 = withdrawal/menarik diri normal'));
assert(str_contains(ems_ai_ds_model_completion_contract('ai_diagnosis_assistant'), 'M3 = fleksi abnormal/dekortikasi'));
assert(str_contains(ems_ai_ds_model_completion_contract('ai_diagnosis_assistant'), 'pemeriksaan pupil'));
assert(str_contains(ems_ai_ds_model_completion_contract('ai_diagnosis_assistant'), 'hipotensi permisif'));

$formattedSampleReport = ems_ai_ds_format_diagnosis_report_text(
    [
        'report_code' => 'DGN-20260924-001520-31F5',
        'created_at' => '2026-09-24 00:15:20',
        'created_by_name' => 'Dokter Jaga',
        'patient_name' => 'Budi Santoso',
        'patient_gender' => 'Laki-Laki',
        'patient_dob' => '1995-05-10',
        'patient_citizen_id' => 'RH102030',
        'anamnesis' => 'Pasien kecelakaan',
    ],
    $estimatedAssessment
);
assert(str_contains($formattedSampleReport, 'LAPORAN DIAGNOSIS INSTALASI GAWAT DARURAT (IGD)'));
assert(str_contains($formattedSampleReport, 'DGN-20260924-001520-31F5'));
assert(str_contains($formattedSampleReport, 'Budi Santoso'));
assert(str_contains($formattedSampleReport, 'E4 V5 M6 (15)'));
assert(str_contains($formattedSampleReport, '[Estimasi AI — wajib verifikasi]'));
assert(str_contains($formattedSampleReport, 'Tekanan Darah'));
assert(str_contains($formattedSampleReport, 'Nadi'));
assert(str_contains($formattedSampleReport, 'Suhu'));
assert(str_contains($formattedSampleReport, 'Respirasi'));
assert(str_contains($formattedSampleReport, 'Saturasi O2'));
assert(str_contains($formattedSampleReport, '120/80 mmHg'));
assert(str_contains($formattedSampleReport, 'AKHIR LAPORAN DIAGNOSIS'));
$formattedNeurologicReport = ems_ai_ds_format_diagnosis_report_text(
    ['anamnesis' => 'Trauma kepala dengan kecurigaan peningkatan tekanan intrakranial.'],
    [
        'gcs' => 'E1 V1 M3 (5)',
        'motorik' => 'fleksi abnormal/dekortikasi',
        'gcs_motor_definition' => 'M3 = fleksi abnormal/dekortikasi.',
        'pemeriksaan_pupil' => ['status' => 'anisokor', 'reaktivitas' => 'non reaktif'],
        'status_rencana_operasi' => 'rencana operasi definitif cito tanpa menunggu hasil CT scan',
        'ttv' => [],
    ]
);
assert(str_contains($formattedNeurologicReport, 'Definisi Motor GCS : M3 = fleksi abnormal/dekortikasi.'));
assert(str_contains($formattedNeurologicReport, 'Pemeriksaan Pupil : anisokor; non reaktif'));
assert(str_contains($formattedNeurologicReport, 'Status Rencana Operasi : rencana operasi definitif cito tanpa menunggu hasil CT scan'));
assert(!str_contains($formattedNeurologicReport, 'fleksi abnormal/withdrawal'));

assert(ems_ai_ds_sanitize_step_items([['aksi' => '', 'hasil' => '']]) === []);
assert(ems_ai_ds_sanitize_step_items([['aksi' => 'Tindakan sesuai kasus', 'hasil' => 'Rencana menunggu verifikasi']])[0]['aksi'] === 'Tindakan sesuai kasus');
$cleanTags = ems_ai_ds_sanitize_step_items([
    ['aksi' => '/me me melakukan akses IV', 'hasil' => '/do do ditemukan akses IV'],
]);
assert($cleanTags[0]['aksi'] === 'melakukan akses IV');
assert($cleanTags[0]['hasil'] === 'ditemukan akses IV');
$emergencyWithRepeatedHandoff = ems_ai_ds_sanitize_igd_emergency_items([
    ['aksi' => 'menyelesaikan stabilisasi dan siap dipindahkan ke Radiologi', 'hasil' => 'Pasien siap dipindahkan ke Radiologi.', 'animasi' => 'type'],
    ['aksi' => 'melakukan akses IV', 'hasil' => 'Akses IV tersedia.', 'animasi' => 'mechanic'],
    ['aksi' => 'melakukan akses IV', 'hasil' => 'Akses IV tersedia.', 'animasi' => 'mechanic'],
    ['aksi' => 'handoff ke Radiologi', 'hasil' => 'Pasien siap diserahkan.', 'animasi' => 'type'],
]);
$handoffCount = 0;
foreach ($emergencyWithRepeatedHandoff as $emergencyItem) {
    $emergencyText = implode(' ', array_map('strval', $emergencyItem));
    $handoffCount += preg_match('/(?:handoff|serah[- ]terima|siap\s+(?:untuk\s+)?dipindahkan|siap\s+(?:untuk\s+)?diserahkan|dipindahkan\s+ke\s+(?:laboratorium|radiologi|ruang\s+operasi)|diserahkan\s+ke\s+(?:laboratorium|radiologi|ruang\s+operasi))/iu', $emergencyText) === 1 ? 1 : 0;
    assert(!preg_match('/^\s*(?:\/me\s*)?me\b/iu', (string) $emergencyItem['aksi']));
    assert(!preg_match('/^\s*(?:\/do\s*)?do\b/iu', (string) $emergencyItem['hasil']));
}
assert($handoffCount === 1);
$lastEmergency = $emergencyWithRepeatedHandoff[array_key_last($emergencyWithRepeatedHandoff)];
assert(str_contains(mb_strtolower((string) $lastEmergency['hasil']), 'siap dipindahkan'));

assert(ems_ai_ds_text_indicates_evisceration('Trauma abdomen dengan eviserasi usus.') === true);
assert(ems_ai_ds_text_indicates_evisceration('Tidak ditemukan eviserasi atau organ terpapar.') === false);
assert(ems_ai_ds_text_indicates_evisceration('Tidak ada usus terpapar.') === false);
$eviscerationEmergency = ems_ai_ds_sanitize_igd_emergency_items([
    ['aksi' => 'menutup luka dengan kassa kering', 'hasil' => 'Kassa kering terpasang.', 'animasi' => 'mechanic'],
], null, 'Trauma abdomen dengan eviserasi usus dan omentum terpapar.');
$eviscerationText = mb_strtolower(implode(' ', array_map(static fn (array $item): string => implode(' ', array_map('strval', $item)), $eviscerationEmergency)));
assert(!str_contains($eviscerationText, 'kassa kering'));
assert(str_contains($eviscerationText, 'kassa steril basah/lembab'));
assert(str_contains($eviscerationText, 'nacl 0,9%'));
assert(str_contains($eviscerationText, 'tanpa mendorong organ kembali'));
$eviscerationLast = $eviscerationEmergency[array_key_last($eviscerationEmergency)];
assert(str_contains(mb_strtolower((string) $eviscerationLast['hasil']), 'siap dipindahkan'));

$labWithoutEvidence = ems_ai_laboratory_sanitize_result([
    'results' => [['parameter' => 'Hemoglobin', 'result' => '', 'flag' => 'Normal']],
]);
assert($labWithoutEvidence['results'][0]['result'] === 'Data belum tersedia');
assert($labWithoutEvidence['results'][0]['flag'] === 'Belum dinilai');
$radiologyWithoutEvidence = ems_ai_radiology_sanitize_report([]);
assert($radiologyWithoutEvidence['findings'] === ['Data belum tersedia']);
assert($radiologyWithoutEvidence['diagnosis'] === 'Data belum tersedia');
assert($radiologyWithoutEvidence['recommendations'] === ['Data belum tersedia']);

$medicalMissingPostop = ems_ai_medical_record_sanitize([], [
    'diagnosis' => [
        'anamnesis' => 'Pasien tidak sadarkan diri habis kecelakaan dan terlihat kaki kiri patah',
        'diagnosis_utama' => 'Suspek fraktur ekstremitas bawah kiri pascatrauma',
        'diagnosis_banding' => ['Cedera kepala/TBI sebagai diagnosis banding'],
        'jenis_operasi' => 'Minor - reduksi tertutup dan imobilisasi',
        'jenis_anestesi' => 'Anestesi lokal',
        'gcs' => 'Data belum tersedia',
        'ttv' => [],
        'motorik' => 'Data belum tersedia',
    ],
]);
assert($medicalMissingPostop['diagnosis_list'][0] === 'Suspek fraktur ekstremitas bawah kiri pascatrauma');
assert($medicalMissingPostop['hasil_operasi'] === ['Data belum tersedia']);
assert($medicalMissingPostop['prognosis_kategori'] === 'Data belum tersedia');

$completionContract = ems_ai_ds_model_completion_contract('ai_diagnosis_assistant');
assert(str_contains($completionContract, 'MODEL AI, bukan PHP dan bukan user'));
assert(str_contains($completionContract, 'rencana tindakan'));
assert(!str_contains($completionContract, 'Pasien tidak sadarkan diri habis kecelakaan'));

$legacyPrompt = ems_ai_ds_strip_hallucination_instructions(
    'Bila singkat, lengkapi SENDIRI seluruh data yang hilang dengan asumsi klinis yang realistis.',
    'ai_diagnosis_assistant'
);
assert(!str_contains($legacyPrompt, 'lengkapi SENDIRI'));
assert(str_contains($legacyPrompt, 'MODEL AI, bukan PHP dan bukan user'));

$legacyEvidenceOnlyPrompt = ems_ai_ds_strip_hallucination_instructions(
    'TUGAS: Susun laporan medis berdasarkan fakta eksplisit pada anamnesis dan data yang diberikan. Bila data tidak tersedia, tulis Data belum tersedia dan tandai perlu verifikasi; jangan mengarang nilai, temuan, diagnosis, tindakan, atau hasil.',
    'ai_diagnosis_assistant'
);
assert(!str_contains($legacyEvidenceOnlyPrompt, 'berdasarkan fakta eksplisit'));
assert(str_contains($legacyEvidenceOnlyPrompt, 'MODEL AI, bukan PHP dan bukan user'));

$legacySurgeryEvidenceOnlyPrompt = ems_ai_ds_strip_hallucination_instructions(
    'TUGAS: Susun rencana operasi berbasis input dan dokumen SOP yang diberikan. Pertahankan jenis anestesi aktual. Jika data klinis atau hasil tindakan belum tersedia, tulis Data belum tersedia; jangan mengarang temuan, obat, dosis, hasil operasi, atau kondisi pasien.',
    'ai_surgery_planner'
);
assert(!str_contains($legacySurgeryEvidenceOnlyPrompt, 'berbasis input dan dokumen SOP yang diberikan'));
assert(str_contains($legacySurgeryEvidenceOnlyPrompt, 'MODEL AI, bukan PHP dan bukan user'));

$comparison = ems_ai_official_document_comparison([
    [
        'id' => 43,
        'title' => '3. Peraturan dan Standar Operasional Prosedur (SOP) 10 Mei 2026',
        'updated_at' => '2026-05-10 00:00:00',
        'extracted_text' => 'Asisten Dokter menjadi Dokter Umum: minimal tiga operasi minor dan dua operasi mayor.',
        '_official_relevance' => 10,
    ],
    [
        'id' => 44,
        'title' => '4. Peraturan dan Standar Operasional Prosedur (SOP) 11 Juli 2026',
        'updated_at' => '2026-07-11 00:00:00',
        'extracted_text' => 'Asisten Dokter menjadi Dokter Umum: minimal tiga operasi minor dan dua operasi mayor.',
        '_official_relevance' => 10,
    ],
], 'cara naik co-ass ke dokter');
assert(count($comparison['documents']) === 2);
assert($comparison['documents'][0]['id'] === 43);
assert($comparison['documents'][1]['id'] === 44);
assert($comparison['same_content'] === true);
assert($comparison['latest']['reference'] === 'https://roxwoodhospitalime.my.id/dashboard/document_view.php?id=44');

$differentComparison = ems_ai_official_document_comparison([
    [
        'id' => 1,
        'title' => 'SOP Kenaikan Jabatan Lama',
        'updated_at' => '2026-05-10 00:00:00',
        'extracted_text' => 'Syarat lama.',
        '_official_relevance' => 10,
    ],
    [
        'id' => 2,
        'title' => 'SOP Kenaikan Jabatan Baru',
        'updated_at' => '2026-07-11 00:00:00',
        'extracted_text' => 'Syarat baru.',
        '_official_relevance' => 10,
    ],
], 'cara naik jabatan');
assert($differentComparison['same_content'] === false);
assert($differentComparison['latest']['id'] === 2);

$comparisonAnswer = ems_roxy_append_official_document_comparison('Jawaban.', [
    ['_type' => 'official_document_comparison', 'comparison' => $differentComparison],
]);
assert(str_contains($comparisonAnswer, 'https://roxwoodhospitalime.my.id/dashboard/document_view.php?id=1'));
assert(str_contains($comparisonAnswer, 'https://roxwoodhospitalime.my.id/dashboard/document_view.php?id=2'));
assert(str_contains($comparisonAnswer, 'dokumen terbaru'));

$physiologicMismatch = ems_ai_ds_normalize_diagnosis_result([
    'anamnesis_lengkap' => 'Amputasi satu jari tangan kanan distal dengan syok hemoragik berat dan koma.',
    'kasus_tindakan' => 'Amputasi traumatik satu jari tangan kanan distal.',
    'diagnosis_utama' => 'Suspek amputasi traumatik satu jari tangan kanan.',
    'gcs' => 'E1 V1 M2 (4)',
], 'Amputasi satu jari tangan kanan distal dengan syok hemoragik berat dan koma.');
$physiologicRedFlag = 'RED FLAG: derajat syok/penurunan kesadaran tidak proporsional dengan cedera yang teridentifikasi — curigai cedera tambahan tersembunyi (kepala, toraks, abdomen, sumber perdarahan lain) yang belum teridentifikasi, perlu secondary survey menyeluruh dan pencitraan tambahan.';
assert($physiologicMismatch['konsistensi_fisiologis']['status'] === 'red_flag');
assert(in_array($physiologicRedFlag, $physiologicMismatch['red_flags'], true));
assert(str_contains($physiologicMismatch['roleplay_note'], $physiologicRedFlag));
assert(str_contains($physiologicMismatch['anamnesis_lengkap'], $physiologicRedFlag));

$physiologicallyPlausible = ems_ai_ds_normalize_diagnosis_result([
    'anamnesis_lengkap' => 'Trauma kepala berat setelah benturan dengan penurunan kesadaran.',
    'kasus_tindakan' => 'Cedera kepala berat dengan kecurigaan peningkatan tekanan intrakranial.',
    'gcs' => 'E1 V1 M2 (4)',
], 'Trauma kepala berat setelah benturan dengan penurunan kesadaran.');
assert($physiologicallyPlausible['konsistensi_fisiologis']['status'] !== 'red_flag');

$ctConsistency = ems_ai_ds_normalize_diagnosis_result([
    'kasus_tindakan' => 'Trauma kepala dengan kecurigaan peningkatan tekanan intrakranial.',
    'pemeriksaan_pupil' => ['status' => 'isokor', 'reaktivitas' => 'reaktif'],
]);
$ctRecommendations = mb_strtolower(implode(' ', array_map('strval', $ctConsistency['radiologi'])));
assert(str_contains($ctConsistency['status_rencana_operasi'], 'menunggu hasil CT scan'));
assert(str_contains($ctRecommendations, 'ct scan'));

$anatomyCorrected = ems_ai_ds_normalize_diagnosis_result([
    'anamnesis_lengkap' => 'Luka pada tungkai jari tangan kanan.',
    'kasus_tindakan' => 'Cedera tungkai pada jari tangan.',
], 'Luka pada jari tangan kanan.');
$anatomyText = mb_strtolower(json_encode($anatomyCorrected, JSON_UNESCAPED_UNICODE));
assert(!str_contains($anatomyText, 'tungkai jari tangan'));
assert(str_contains($anatomyText, 'ekstremitas atas'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'secondary survey menyeluruh'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'Section 6'));
assert(str_contains(ems_ai_ds_default_diagnosis_system_prompt(), 'tungkai'));
assert(ems_ai_custom_completion_url('http://127.0.0.1:20128/v1') === 'http://127.0.0.1:20128/v1/chat/completions');
assert(ems_ai_custom_completion_url('http://127.0.0.1:20128/v1/chat/completions') === 'http://127.0.0.1:20128/v1/chat/completions');
assert(ems_ai_ds_has_custom_provider([
    'custom_provider' => '9Router',
    'custom_base_url' => 'http://127.0.0.1:20128/v1',
    'custom_default_model' => 'Step',
]) === true);
assert(ems_ai_ds_has_custom_provider([
    'custom_provider' => '9Router',
    'custom_base_url' => '',
    'custom_default_model' => 'Step',
]) === false);

echo "AI consistency self-check: OK\n";
