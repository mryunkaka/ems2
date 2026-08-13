<?php

/**
 * Rekam Medis AI: agregasi data lintas 5 modul "Roxwood Hospital AI"
 * (Diagnosis/Surgery/Radiology/Laboratory/Psychiatry) lewat satu kode
 * referensi, lalu minta Gemini menyusun narasi rekam medis LENGKAP &
 * PANJANG (bukan sekadar tempel data mentah) mengikuti struktur baku
 * dokumen rekam medis yang sudah dipakai `dashboard/rekam_medis.php`
 * (medicalTemplate: Informasi Waktu, Diagnosis, Indikasi Operasi, Jenis
 * Operasi, Jenis Anestesi, Anamnesis, Status Lokalis, TTV, Status
 * Neurologis, Laporan Tindakan Operasi (naratif, BUKAN daftar langkah
 * /me /do), Hasil Operasi, Status Pasca Operasi, TTV Pasca Operasi,
 * Prognosis) — supaya user tidak perlu tulis manual, tapi hasilnya tetap
 * terasa seperti rekam medis rumah sakit sungguhan, bukan ringkasan data.
 */

require_once __DIR__ . '/ai_diagnosis_surgery.php';
require_once __DIR__ . '/ai_radiology.php';
require_once __DIR__ . '/ai_laboratory.php';
require_once __DIR__ . '/ai_psychiatry.php';

function ems_rmai_latest_row(PDO $pdo, string $table, string $code, string $unitCode, string $statusCondition): ?array
{
    if (!ems_column_exists($pdo, $table, 'source_report_code')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE source_report_code = ? AND unit_code = ? AND {$statusCondition} ORDER BY id DESC LIMIT 1");
    $stmt->execute([$code, $unitCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Kumpulkan data dari AI Diagnosis Assistant (wajib) + AI Surgery Planner/
 * Radiology Center/Laboratory AI/Psychiatry Center (opsional, berelasi
 * lewat source_report_code) jadi satu array. Dipakai bersama oleh
 * rekam_medis_ai_lookup.php (preview di form) dan rekam_medis_ai_generate.php
 * (bahan prompt Gemini) supaya query tidak dobel-tulis.
 */
function ems_rmai_aggregate(PDO $pdo, string $code, string $unitCode): ?array
{
    $diagnosisRow = ems_ai_ds_find_diagnosis_report_by_code($pdo, $code, $unitCode);
    if (!$diagnosisRow) {
        return null;
    }

    $diagnosisResult = [];
    if (!empty($diagnosisRow['result_json'])) {
        $decoded = json_decode((string) $diagnosisRow['result_json'], true);
        if (is_array($decoded)) {
            $diagnosisResult = ems_ai_ds_normalize_diagnosis_result($decoded);
        }
    }

    $surgeryRow = ems_rmai_latest_row($pdo, 'ai_surgery_plans', $code, $unitCode, "status = 'done'");
    $surgery = null;
    if ($surgeryRow) {
        $surgeryData = [];
        if (!empty($surgeryRow['result_json'])) {
            $decoded = json_decode((string) $surgeryRow['result_json'], true);
            if (is_array($decoded)) {
                $surgeryData = $decoded;
            }
        }
        $surgery = [
            'id' => (int) $surgeryRow['id'],
            'jenis_operasi_kategori' => (string) $surgeryRow['jenis_operasi_kategori'],
            'jenis_anestesi_input' => (string) $surgeryRow['jenis_anestesi_input'],
            'kompleksitas' => (string) $surgeryRow['kompleksitas'],
            'kasus_tindakan' => (string) $surgeryRow['kasus_tindakan'],
            'durasi' => (string) ($surgeryData['durasi'] ?? '-'),
            'farmakologi' => $surgeryData['farmakologi'] ?? null,
            'tahapan_prosedur' => is_array($surgeryData['tahapan_prosedur'] ?? null) ? $surgeryData['tahapan_prosedur'] : [],
            'risiko_komplikasi' => is_array($surgeryData['risiko_komplikasi'] ?? null) ? $surgeryData['risiko_komplikasi'] : [],
            'laporan_pasca_operasi' => (string) ($surgeryData['laporan_pasca_operasi'] ?? '-'),
            'created_at' => (string) $surgeryRow['created_at'],
        ];
    }

    $radiologyRow = ems_rmai_latest_row($pdo, 'ai_radiology_images', $code, $unitCode, "(status = 'done' OR report_status = 'done')");
    $radiology = null;
    if ($radiologyRow) {
        $radiology = [
            'id' => (int) $radiologyRow['id'],
            'modality' => (string) $radiologyRow['modality'],
            'category' => (string) $radiologyRow['category'],
            'body_region' => (string) $radiologyRow['body_region'],
            'projection' => (string) $radiologyRow['projection'],
            'clinical_finding' => (string) $radiologyRow['clinical_finding'],
            'image_url' => ($radiologyRow['status'] === 'done' && !empty($radiologyRow['image_path']))
                ? ems_secure_file_url((string) $radiologyRow['image_path'])
                : null,
            'report_findings' => trim((string) ($radiologyRow['report_findings'] ?? '')),
            'report_diagnosis' => trim((string) ($radiologyRow['report_diagnosis'] ?? '')),
            'report_recommendations' => trim((string) ($radiologyRow['report_recommendations'] ?? '')),
            'report_text' => trim((string) ($radiologyRow['report_text'] ?? '')),
            'created_at' => (string) $radiologyRow['created_at'],
        ];
    }

    $laboratoryRow = ems_rmai_latest_row($pdo, 'ai_laboratory_results', $code, $unitCode, "status = 'done'");
    $laboratory = null;
    if ($laboratoryRow) {
        $labData = [];
        if (!empty($laboratoryRow['result_json'])) {
            $decoded = json_decode((string) $laboratoryRow['result_json'], true);
            if (is_array($decoded)) {
                $labData = ems_ai_laboratory_sanitize_result($decoded);
            }
        }
        $laboratory = [
            'id' => (int) $laboratoryRow['id'],
            'department' => (string) $laboratoryRow['department'],
            'category' => (string) $laboratoryRow['category'],
            'level3_option' => (string) ($laboratoryRow['level3_option'] ?? ''),
            'specimen_type' => (string) $laboratoryRow['specimen_type'],
            'results' => $labData['results'] ?? [],
            'interpretation' => (string) ($labData['interpretation'] ?? '-'),
            'clinical_correlation' => (string) ($labData['clinical_correlation'] ?? '-'),
            'diagnosis' => (string) ($labData['diagnosis'] ?? '-'),
            'recommendations' => $labData['recommendations'] ?? [],
            'created_at' => (string) $laboratoryRow['created_at'],
        ];
    }

    $psychiatryRow = ems_rmai_latest_row($pdo, 'ai_psychiatry_assessments', $code, $unitCode, "status = 'done'");
    $psychiatry = null;
    if ($psychiatryRow) {
        $psyData = [];
        if (!empty($psychiatryRow['result_json'])) {
            $decoded = json_decode((string) $psychiatryRow['result_json'], true);
            if (is_array($decoded)) {
                $psyData = $decoded;
            }
        }
        $psychiatry = [
            'id' => (int) $psychiatryRow['id'],
            'department' => (string) $psychiatryRow['department'],
            'assessment_type' => (string) $psychiatryRow['assessment_type'],
            'chief_complaint' => (string) $psychiatryRow['chief_complaint'],
            'mse' => $psyData['mse'] ?? null,
            'diagnosis' => $psyData['diagnosis'] ?? null,
            'risk_assessment' => $psyData['risk_assessment'] ?? null,
            'treatment_plan' => $psyData['treatment_plan'] ?? [],
            'medications' => $psyData['medications'] ?? [],
            'clinical_summary' => (string) ($psyData['clinical_summary'] ?? '-'),
            'created_at' => (string) $psychiatryRow['created_at'],
        ];
    }

    return [
        'diagnosis' => [
            'id' => (int) $diagnosisRow['id'],
            'report_code' => (string) $diagnosisRow['report_code'],
            'anamnesis' => (string) $diagnosisRow['anamnesis'],
            'anamnesis_lengkap' => (string) ($diagnosisResult['anamnesis_lengkap'] ?? ''),
            'status' => (string) ($diagnosisResult['status'] ?? '-'),
            'diagnosis_utama' => (string) ($diagnosisResult['diagnosis_utama'] ?? '-'),
            'diagnosis_banding' => $diagnosisResult['diagnosis_banding'] ?? [],
            'gcs' => (string) ($diagnosisResult['gcs'] ?? '-'),
            'ttv' => $diagnosisResult['ttv'] ?? [],
            'kasus_tindakan' => (string) ($diagnosisResult['kasus_tindakan'] ?? '-'),
            'jenis_operasi' => (string) ($diagnosisResult['jenis_operasi'] ?? '-'),
            'jenis_anestesi' => (string) ($diagnosisResult['jenis_anestesi'] ?? '-'),
            'roleplay_note' => (string) ($diagnosisResult['roleplay_note'] ?? ''),
            'sop_references' => $diagnosisResult['sop_references'] ?? [],
            'patient_name' => (string) ($diagnosisRow['patient_name'] ?? ''),
            'patient_gender' => (string) ($diagnosisRow['patient_gender'] ?? ''),
            'patient_dob' => (string) ($diagnosisRow['patient_dob'] ?? ''),
            'patient_citizen_id' => (string) ($diagnosisRow['patient_citizen_id'] ?? ''),
            'created_at' => (string) $diagnosisRow['created_at'],
        ],
        'surgery' => $surgery,
        'radiology' => $radiology,
        'laboratory' => $laboratory,
        'psychiatry' => $psychiatry,
    ];
}

/**
 * Persona + aturan penulisan narasi rekam medis LENGKAP. Sengaja TIDAK
 * memakai ems_ai_ds_build_system_prompt() (pembungkus SOP/mantra/animasi
 * milik Diagnosis/Surgery) karena keluaran di sini adalah dokumen naratif
 * utuh, bukan instruksi tindakan /me /do per langkah.
 */
function ems_ai_medical_record_default_system_prompt(): string
{
    return "Anda adalah dokter spesialis senior di Roxwood Hospital yang ditugaskan menyusun REKAM MEDIS RESMI pasien secara LENGKAP berdasarkan seluruh data klinis yang sudah tersedia (hasil AI Diagnosis, AI Surgery Planner, Radiology Center, Laboratory AI, dan Psychiatry Center bila ada). Tugas Anda adalah menulis ULANG seluruh data itu menjadi SATU dokumen rekam medis yang utuh, panjang, rinci, dan profesional — persis gaya rekam medis rumah sakit tipe A sungguhan, BUKAN ringkasan atau daftar poin data mentah.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. Tulis narasi LENGKAP dan PANJANG di setiap bagian (terutama Anamnesis, Status Lokalis, Laporan Tindakan Operasi, dan Status Pasca Operasi WAJIB berbentuk beberapa PARAGRAF naratif yang mengalir, bukan satu-dua kalimat singkat).\n"
        . "2. \"laporan_tindakan\" (persiapan/operasi/hemostasis/penutupan) WAJIB berbentuk PARAGRAF NARASI mengalir gaya catatan operasi dokter bedah sungguhan — JANGAN membuat daftar langkah bernomor, JANGAN memakai format /me atau /do, JANGAN checklist per-langkah.\n"
        . "3. Data yang tidak eksplisit tersedia dari konteks (misalnya rincian pemeriksaan motorik/sensorik/refleks/sirkulasi perifer, uraian E/V/M GCS) WAJIB tetap diisi dengan asumsi klinis yang REALISTIS dan KONSISTEN dengan diagnosis, GCS, dan TTV yang diberikan — JANGAN kosongkan, JANGAN tulis 'tidak tersedia' atau 'data tidak ada'.\n"
        . "4. Kalau data AI Surgery Planner TIDAK tersedia dalam konteks, sesuaikan seluruh bagian terkait operasi (Jenis Operasi, Jenis Anestesi, Laporan Tindakan Operasi) berdasarkan kasus_tindakan/jenis_operasi dari data Diagnosis — uraikan tindakan medis/definitif yang relevan (bisa non-operatif) sebagai gantinya, tetap dalam bentuk narasi lengkap.\n"
        . "5. Konsisten secara medis: seluruh bagian harus selaras satu sama lain (diagnosis, temuan radiologi/lab, tindakan, dan prognosis tidak boleh saling bertentangan).\n"
        . "6. Bahasa Indonesia medis baku (EYD), objektif, tidak berlebihan, dan tidak berspekulasi di luar konteks yang diberikan.\n"
        . "7. JANGAN PERNAH memakai kata 'simulasi', 'roleplay', atau frasa sejenis di mana pun dalam dokumen — tulis seolah ini benar-benar rekam medis resmi.\n"
        . "8. JANGAN menyertakan bagian mengenai penanganan emergency/tindakan awal bergaya /me /do — dokumen ini hanya rekam medis formal.\n"
        . "9. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n\n"
        . "Struktur JSON WAJIB (SEMUA field wajib terisi lengkap, tidak boleh kosong):\n"
        . "{\n"
        . "  \"judul_operasi\": \"nama tindakan/operasi untuk judul REKAM MEDIS\",\n"
        . "  \"ruang_perawatan\": \"alur ruang perawatan pasien dipisah tanda panah, mis. IGD -> Radiologi -> Ruang Operasi -> ICU -> Rawat Inap\",\n"
        . "  \"diagnosis_list\": [\"diagnosis 1\", \"diagnosis 2\", \"diagnosis 3\"],\n"
        . "  \"indikasi_operasi\": [\"indikasi 1\", \"indikasi 2\", \"indikasi 3\"],\n"
        . "  \"jenis_operasi_nama\": \"nama tindakan operasi\",\n"
        . "  \"jenis_operasi_deskripsi\": \"deskripsi 1-2 kalimat tentang tindakan tsb\",\n"
        . "  \"jenis_anestesi_nama\": \"jenis anestesi\",\n"
        . "  \"obat_anestesi\": [\"obat 1\", \"obat 2\", \"obat 3\"],\n"
        . "  \"obat_intraoperatif\": [\"obat 1\", \"obat 2\", \"obat 3\"],\n"
        . "  \"anamnesis_singkat\": \"anamnesis LENGKAP beberapa paragraf\",\n"
        . "  \"status_lokalis_temuan\": [\"temuan 1\", \"temuan 2\", \"temuan 3\"],\n"
        . "  \"status_neurovaskular\": {\"motorik\": \"...\", \"sensorik\": \"...\", \"refleks\": \"...\", \"sirkulasi_perifer\": \"...\"},\n"
        . "  \"ttv_pra_operasi\": {\"tekanan_darah\": \"...\", \"nadi\": \"...\", \"respirasi\": \"...\", \"suhu\": \"...\", \"saturasi_o2\": \"...\"},\n"
        . "  \"gcs_nilai\": \"contoh: E2 V2 M4 (8)\",\n"
        . "  \"gcs_e\": \"deskripsi respon mata\", \"gcs_v\": \"deskripsi respon verbal\", \"gcs_m\": \"deskripsi respon motorik\",\n"
        . "  \"radiologi_temuan\": [\"temuan 1\", \"temuan 2\"],\n"
        . "  \"radiologi_kesan\": [\"kesan 1\", \"kesan 2\"],\n"
        . "  \"laporan_tindakan\": {\"persiapan\": \"paragraf naratif\", \"operasi\": \"beberapa paragraf naratif\", \"hemostasis\": \"paragraf naratif\", \"penutupan\": \"paragraf naratif\"},\n"
        . "  \"hasil_operasi\": [\"hasil 1\", \"hasil 2\", \"hasil 3\"],\n"
        . "  \"status_pasca_operasi_umum\": \"Baik/Cukup/Kritis\",\n"
        . "  \"status_pasca_operasi_narasi\": \"beberapa paragraf naratif kondisi pasca operasi\",\n"
        . "  \"ttv_pasca_operasi\": {\"tekanan_darah\": \"...\", \"nadi\": \"...\", \"respirasi\": \"...\", \"suhu\": \"...\", \"saturasi_o2\": \"...\"},\n"
        . "  \"prognosis_kategori\": \"Dubia ad Bonam/Dubia ad Malam/Infaust\",\n"
        . "  \"prognosis_penjelasan\": \"1 paragraf penjelasan prognosis\"\n"
        . "}";
}

function ems_ai_medical_record_build_user_prompt(array $agg): string
{
    $d = $agg['diagnosis'];
    $s = $agg['surgery'];
    $r = $agg['radiology'];
    $l = $agg['laboratory'];
    $p = $agg['psychiatry'];

    $lines = [
        '=== DATA AI DIAGNOSIS ASSISTANT (WAJIB ADA) ===',
        'Status/Kondisi Ringkas: ' . $d['status'],
        'Anamnesis Lengkap: ' . ($d['anamnesis_lengkap'] ?: $d['anamnesis']),
        'Diagnosis Utama: ' . $d['diagnosis_utama'],
        'Diagnosis Banding: ' . implode(', ', $d['diagnosis_banding']),
        'GCS: ' . $d['gcs'],
        'TTV: ' . implode(', ', array_map(static fn ($v) => ($v['label'] ?? '') . ' ' . ($v['value'] ?? '') . (!empty($v['note']) ? ' (' . $v['note'] . ')' : ''), $d['ttv'])),
        'Kasus/Tindakan yang Diperlukan: ' . $d['kasus_tindakan'],
        'Jenis Operasi (dari Diagnosis): ' . $d['jenis_operasi'],
        'Jenis Anestesi (dari Diagnosis): ' . $d['jenis_anestesi'],
    ];

    if ($s) {
        $farm = $s['farmakologi'] ?? [];
        $lines[] = '';
        $lines[] = '=== DATA AI SURGERY PLANNER ===';
        $lines[] = 'Kategori: ' . $s['jenis_operasi_kategori'] . ', Durasi: ' . $s['durasi'] . ', Anestesi: ' . $s['jenis_anestesi_input'];
        $lines[] = 'Kasus/Tindakan: ' . $s['kasus_tindakan'];
        foreach (['pra_operatif' => 'Obat Pra-Operatif', 'intra_operatif' => 'Obat Intra-Operatif', 'post_operatif' => 'Obat Post-Operatif', 'pemulangan' => 'Obat Pemulangan'] as $key => $label) {
            $meds = $farm[$key] ?? [];
            if ($meds) {
                $lines[] = $label . ': ' . implode(', ', array_map(static fn ($m) => ($m['nama'] ?? '') . ' ' . ($m['dosis'] ?? ''), $meds));
            }
        }
        $lines[] = 'Ringkasan Tahapan Prosedur (untuk referensi Anda menulis narasi Laporan Tindakan Operasi, JANGAN disalin sebagai daftar): ' . implode(' | ', array_map(static fn ($step) => ($step['aksi'] ?? '') . ' -> ' . ($step['hasil'] ?? ''), $s['tahapan_prosedur']));
        $lines[] = 'Risiko & Komplikasi: ' . implode(', ', array_map(static fn ($rk) => ($rk['judul'] ?? '') . ': ' . ($rk['deskripsi'] ?? ''), $s['risiko_komplikasi']));
        $lines[] = 'Laporan Pasca Operasi (ringkas dari Surgery Planner): ' . $s['laporan_pasca_operasi'];
    } else {
        $lines[] = '';
        $lines[] = '=== DATA AI SURGERY PLANNER: TIDAK TERSEDIA — gunakan data Diagnosis untuk bagian operasi/tindakan ===';
    }

    if ($r) {
        $lines[] = '';
        $lines[] = '=== DATA RADIOLOGY CENTER ===';
        $lines[] = 'Pemeriksaan: ' . $r['modality'] . ' - ' . $r['category'] . ' - ' . $r['body_region'] . ' (' . $r['projection'] . ')';
        $lines[] = 'Temuan Klinis Utama: ' . $r['clinical_finding'];
        if ($r['report_findings']) $lines[] = 'Findings: ' . $r['report_findings'];
        if ($r['report_diagnosis']) $lines[] = 'Impression/Diagnosis Radiologi: ' . $r['report_diagnosis'];
        if ($r['report_recommendations']) $lines[] = 'Rekomendasi Radiologi: ' . $r['report_recommendations'];
    }

    if ($l) {
        $lines[] = '';
        $lines[] = '=== DATA LABORATORY AI ===';
        $lines[] = 'Pemeriksaan: ' . $l['department'] . ' - ' . $l['category'];
        $lines[] = 'Hasil: ' . implode(', ', array_map(static fn ($res) => ($res['parameter'] ?? '') . ' ' . ($res['result'] ?? '') . ($res['unit'] ?? '') . ' [' . ($res['flag'] ?? '') . ']', $l['results']));
        $lines[] = 'Interpretasi: ' . $l['interpretation'];
        $lines[] = 'Kesan Laboratorium: ' . $l['diagnosis'];
    }

    if ($p) {
        $diag = $p['diagnosis'] ?? [];
        $risk = $p['risk_assessment'] ?? [];
        $lines[] = '';
        $lines[] = '=== DATA PSYCHIATRY CENTER (opsional) ===';
        $lines[] = 'Diagnosis Psikiatri: [' . ($diag['code'] ?? '-') . '] ' . ($diag['primary'] ?? '-');
        $lines[] = 'Risiko: Severity ' . ($risk['severity'] ?? '-') . ', Suicide ' . ($risk['suicide_risk'] ?? '-') . ', Violence ' . ($risk['violence_risk'] ?? '-') . ', Self Harm ' . ($risk['self_harm_risk'] ?? '-');
        $lines[] = 'Kesimpulan Klinis Psikiatri: ' . ($p['clinical_summary'] ?? '-');
    }

    $lines[] = '';
    $lines[] = 'Susun rekam medis lengkap sesuai struktur JSON yang diminta, berdasarkan SELURUH data di atas.';

    return implode("\n", $lines);
}

function ems_ai_medical_record_sanitize(array $data): array
{
    $toStringArray = static function ($value): array {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn ($v) => trim($v) !== ''));
        }
        $value = trim((string) $value);
        return $value !== '' ? [$value] : [];
    };
    $str = static fn ($v, $fallback = '-') => trim((string) ($v ?? '')) !== '' ? trim((string) $v) : $fallback;

    $neuro = is_array($data['status_neurovaskular'] ?? null) ? $data['status_neurovaskular'] : [];
    $ttvPra = is_array($data['ttv_pra_operasi'] ?? null) ? $data['ttv_pra_operasi'] : [];
    $ttvPasca = is_array($data['ttv_pasca_operasi'] ?? null) ? $data['ttv_pasca_operasi'] : [];
    $laporan = is_array($data['laporan_tindakan'] ?? null) ? $data['laporan_tindakan'] : [];

    return [
        'judul_operasi' => $str($data['judul_operasi'] ?? null, 'Tindakan Medis'),
        'ruang_perawatan' => $str($data['ruang_perawatan'] ?? null, 'IGD'),
        'diagnosis_list' => $toStringArray($data['diagnosis_list'] ?? []),
        'indikasi_operasi' => $toStringArray($data['indikasi_operasi'] ?? []),
        'jenis_operasi_nama' => $str($data['jenis_operasi_nama'] ?? null),
        'jenis_operasi_deskripsi' => $str($data['jenis_operasi_deskripsi'] ?? null),
        'jenis_anestesi_nama' => $str($data['jenis_anestesi_nama'] ?? null),
        'obat_anestesi' => $toStringArray($data['obat_anestesi'] ?? []),
        'obat_intraoperatif' => $toStringArray($data['obat_intraoperatif'] ?? []),
        'anamnesis_singkat' => $str($data['anamnesis_singkat'] ?? null),
        'status_lokalis_temuan' => $toStringArray($data['status_lokalis_temuan'] ?? []),
        'status_neurovaskular' => [
            'motorik' => $str($neuro['motorik'] ?? null),
            'sensorik' => $str($neuro['sensorik'] ?? null),
            'refleks' => $str($neuro['refleks'] ?? null),
            'sirkulasi_perifer' => $str($neuro['sirkulasi_perifer'] ?? null),
        ],
        'ttv_pra_operasi' => [
            'tekanan_darah' => $str($ttvPra['tekanan_darah'] ?? null),
            'nadi' => $str($ttvPra['nadi'] ?? null),
            'respirasi' => $str($ttvPra['respirasi'] ?? null),
            'suhu' => $str($ttvPra['suhu'] ?? null),
            'saturasi_o2' => $str($ttvPra['saturasi_o2'] ?? null),
        ],
        'gcs_nilai' => $str($data['gcs_nilai'] ?? null),
        'gcs_e' => $str($data['gcs_e'] ?? null),
        'gcs_v' => $str($data['gcs_v'] ?? null),
        'gcs_m' => $str($data['gcs_m'] ?? null),
        'radiologi_temuan' => $toStringArray($data['radiologi_temuan'] ?? []),
        'radiologi_kesan' => $toStringArray($data['radiologi_kesan'] ?? []),
        'laporan_tindakan' => [
            'persiapan' => $str($laporan['persiapan'] ?? null),
            'operasi' => $str($laporan['operasi'] ?? null),
            'hemostasis' => $str($laporan['hemostasis'] ?? null),
            'penutupan' => $str($laporan['penutupan'] ?? null),
        ],
        'hasil_operasi' => $toStringArray($data['hasil_operasi'] ?? []),
        'status_pasca_operasi_umum' => $str($data['status_pasca_operasi_umum'] ?? null, 'Baik'),
        'status_pasca_operasi_narasi' => $str($data['status_pasca_operasi_narasi'] ?? null),
        'ttv_pasca_operasi' => [
            'tekanan_darah' => $str($ttvPasca['tekanan_darah'] ?? null),
            'nadi' => $str($ttvPasca['nadi'] ?? null),
            'respirasi' => $str($ttvPasca['respirasi'] ?? null),
            'suhu' => $str($ttvPasca['suhu'] ?? null),
            'saturasi_o2' => $str($ttvPasca['saturasi_o2'] ?? null),
        ],
        'prognosis_kategori' => $str($data['prognosis_kategori'] ?? null, 'Dubia ad Bonam'),
        'prognosis_penjelasan' => $str($data['prognosis_penjelasan'] ?? null),
    ];
}

function ems_ai_medical_record_generate(PDO $pdo, array $agg, ?int $createdBy): array
{
    $systemPrompt = ems_ai_medical_record_default_system_prompt();
    $userPrompt = ems_ai_medical_record_build_user_prompt($agg);

    $result = ems_ai_ds_call_gemini($pdo, $systemPrompt, $userPrompt, 'rekam_medis_ai', $createdBy);
    if (!$result['ok']) {
        return $result;
    }

    return ['ok' => true, 'data' => ems_ai_medical_record_sanitize($result['data'])];
}

/**
 * Bangun HTML final (format sama dengan medicalTemplate di rekam_medis.php:
 * h1/h2/p/ul) dari hasil narasi terstruktur di atas — dibangun server-side
 * (bukan dari HTML mentah balasan AI) supaya escaping & format selalu
 * konsisten dan aman dari markup liar.
 */
function ems_ai_medical_record_build_html(array $n, array $agg): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $ul = static function (array $items, string $emptyText) use ($e): string {
        if (!$items) {
            return '<p>' . $e($emptyText) . '</p>';
        }
        return '<ul>' . implode('', array_map(static fn ($i) => '<li>' . $e($i) . '</li>', $items)) . '</ul>';
    };
    $nl2br = static fn ($v) => nl2br($e($v), false);

    $html = '<h1 style="text-align:center;"><strong>REKAM MEDIS : ' . $e($n['judul_operasi']) . '</strong></h1>';

    $html .= '<h2><strong>INFORMASI WAKTU</strong></h2>';
    $html .= '<p><strong>RUANG PERAWATAN:</strong> ' . $e($n['ruang_perawatan']) . '</p>';

    $html .= '<h2><strong>DIAGNOSIS</strong></h2>';
    $html .= $ul($n['diagnosis_list'], '-');

    $html .= '<h2><strong>INDIKASI OPERASI</strong></h2>';
    $html .= $ul($n['indikasi_operasi'], '-');

    $html .= '<h2><strong>JENIS OPERASI</strong></h2>';
    $html .= '<p><strong>' . $e($n['jenis_operasi_nama']) . '</strong></p>';
    $html .= '<p>(' . $e($n['jenis_operasi_deskripsi']) . ')</p>';

    $html .= '<h2><strong>JENIS ANESTESI</strong></h2>';
    $html .= '<p>' . $e($n['jenis_anestesi_nama']) . '</p>';
    if ($n['obat_anestesi']) {
        $html .= '<p><strong>Obat Anestesi:</strong></p>' . $ul($n['obat_anestesi'], '-');
    }
    if ($n['obat_intraoperatif']) {
        $html .= '<p><strong>Obat Intraoperatif:</strong></p>' . $ul($n['obat_intraoperatif'], '-');
    }

    $html .= '<h2><strong>ANAMNESIS SINGKAT</strong></h2>';
    $html .= '<p>' . $nl2br($n['anamnesis_singkat']) . '</p>';

    $html .= '<h2><strong>STATUS LOKALIS PRA OPERASI</strong></h2>';
    $html .= $ul($n['status_lokalis_temuan'], '-');
    $html .= '<p><strong>Status Neurovaskular / Neurologis:</strong></p>';
    $html .= '<ul>'
        . '<li><strong>Motorik:</strong> ' . $e($n['status_neurovaskular']['motorik']) . '</li>'
        . '<li><strong>Sensorik:</strong> ' . $e($n['status_neurovaskular']['sensorik']) . '</li>'
        . '<li><strong>Refleks:</strong> ' . $e($n['status_neurovaskular']['refleks']) . '</li>'
        . '<li><strong>Sirkulasi Perifer:</strong> ' . $e($n['status_neurovaskular']['sirkulasi_perifer']) . '</li>'
        . '</ul>';

    $html .= '<h2><strong>TANDA TANDA VITAL (TTV) PRA OPERASI</strong></h2>';
    $html .= '<p><strong>Tekanan Darah:</strong> ' . $e($n['ttv_pra_operasi']['tekanan_darah']) . '</p>';
    $html .= '<p><strong>Nadi:</strong> ' . $e($n['ttv_pra_operasi']['nadi']) . '</p>';
    $html .= '<p><strong>Respirasi:</strong> ' . $e($n['ttv_pra_operasi']['respirasi']) . '</p>';
    $html .= '<p><strong>Suhu Tubuh:</strong> ' . $e($n['ttv_pra_operasi']['suhu']) . '</p>';
    $html .= '<p><strong>Saturasi O₂:</strong> ' . $e($n['ttv_pra_operasi']['saturasi_o2']) . '</p>';

    $html .= '<h2><strong>STATUS NEUROLOGIS</strong></h2>';
    $html .= '<p><strong>GCS (Glasgow Coma Scale):</strong> ' . $e($n['gcs_nilai']) . '</p>';
    $html .= '<ul>'
        . '<li><strong>E:</strong> ' . $e($n['gcs_e']) . '</li>'
        . '<li><strong>V:</strong> ' . $e($n['gcs_v']) . '</li>'
        . '<li><strong>M:</strong> ' . $e($n['gcs_m']) . '</li>'
        . '</ul>';

    if ($n['radiologi_temuan'] || $n['radiologi_kesan']) {
        $html .= '<h2><strong>HASIL PEMERIKSAAN RADIOLOGI</strong></h2>';
        if ($n['radiologi_temuan']) {
            $html .= '<p><strong>Temuan</strong></p>' . $ul($n['radiologi_temuan'], '-');
        }
        if ($n['radiologi_kesan']) {
            $html .= '<p><strong>Kesan Radiologi</strong></p>' . $ul($n['radiologi_kesan'], '-');
        }
    }

    $html .= '<h2><strong>LAPORAN TINDAKAN OPERASI</strong></h2>';
    $html .= '<p><strong>A. Tahap Persiapan</strong></p><p>' . $nl2br($n['laporan_tindakan']['persiapan']) . '</p>';
    $html .= '<p><strong>B. Tahap Operasi</strong></p><p>' . $nl2br($n['laporan_tindakan']['operasi']) . '</p>';
    $html .= '<p><strong>C. Hemostasis</strong></p><p>' . $nl2br($n['laporan_tindakan']['hemostasis']) . '</p>';
    $html .= '<p><strong>D. Penutupan Operasi</strong></p><p>' . $nl2br($n['laporan_tindakan']['penutupan']) . '</p>';

    $html .= '<h2><strong>HASIL OPERASI</strong></h2>';
    $html .= $ul($n['hasil_operasi'], '-');

    $html .= '<h2><strong>STATUS PASCA OPERASI (IMMEDIATE POST OP)</strong></h2>';
    $html .= '<p><strong>Status Umum:</strong> ' . $e($n['status_pasca_operasi_umum']) . '</p>';
    $html .= '<p>' . $nl2br($n['status_pasca_operasi_narasi']) . '</p>';

    $html .= '<h2><strong>TANDA TANDA VITAL PASCA OPERASI</strong></h2>';
    $html .= '<p><strong>Tekanan Darah:</strong> ' . $e($n['ttv_pasca_operasi']['tekanan_darah']) . '</p>';
    $html .= '<p><strong>Nadi:</strong> ' . $e($n['ttv_pasca_operasi']['nadi']) . '</p>';
    $html .= '<p><strong>Respirasi:</strong> ' . $e($n['ttv_pasca_operasi']['respirasi']) . '</p>';
    $html .= '<p><strong>Suhu Tubuh:</strong> ' . $e($n['ttv_pasca_operasi']['suhu']) . '</p>';
    $html .= '<p><strong>Saturasi O₂:</strong> ' . $e($n['ttv_pasca_operasi']['saturasi_o2']) . '</p>';

    if (!empty($agg['laboratory']) || !empty($agg['psychiatry'])) {
        $html .= '<h2><strong>PEMERIKSAAN PENUNJANG TAMBAHAN</strong></h2>';
        if (!empty($agg['laboratory'])) {
            $l = $agg['laboratory'];
            $html .= '<p><strong>Laboratorium (' . $e($l['department'] . ' - ' . $l['category']) . '):</strong> ' . $e($l['diagnosis']) . '</p>';
        }
        if (!empty($agg['psychiatry'])) {
            $p = $agg['psychiatry'];
            $diag = $p['diagnosis'] ?? [];
            $html .= '<p><strong>Asesmen Psikiatri:</strong> [' . $e($diag['code'] ?? '-') . '] ' . $e($diag['primary'] ?? '-') . '</p>';
        }
    }

    $html .= '<h2><strong>PROGNOSIS</strong></h2>';
    $html .= '<p><strong>' . $e($n['prognosis_kategori']) . '</strong></p>';
    $html .= '<p>' . $nl2br($n['prognosis_penjelasan']) . '</p>';

    return $html;
}
