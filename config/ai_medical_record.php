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
            $diagnosisResult = ems_ai_ds_normalize_diagnosis_result($decoded, (string) ($diagnosisRow['anamnesis'] ?? ''));
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
        ];
        $surgeryText = strtolower($surgery['kasus_tindakan']);
        if (str_contains($surgeryText, 'orif') || str_contains($surgeryText, 'open reduction internal fixation')) {
            $surgery['jenis_operasi_kategori'] = 'Mayor';
        }
        $surgery += [
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
        'performed_operation_result' => '',
        'diagnosis' => [
            'id' => (int) $diagnosisRow['id'],
            'report_code' => (string) $diagnosisRow['report_code'],
            'anamnesis' => (string) $diagnosisRow['anamnesis'],
            'anamnesis_lengkap' => (string) ($diagnosisResult['anamnesis_lengkap'] ?? ''),
            'status' => (string) ($diagnosisResult['status'] ?? '-'),
            'diagnosis_utama' => (string) ($diagnosisResult['diagnosis_utama'] ?? '-'),
            'diagnosis_banding' => $diagnosisResult['diagnosis_banding'] ?? [],
            'laboratory_scenario_results' => is_array($diagnosisResult['lab'] ?? null) ? $diagnosisResult['lab'] : [],
            'radiology_scenario_results' => is_array($diagnosisResult['radiologi'] ?? null) ? $diagnosisResult['radiologi'] : [],
            'radiology_recommendation' => is_array($diagnosisResult['radiologi_terstruktur'] ?? null) ? $diagnosisResult['radiologi_terstruktur'] : [],
            'laboratory_recommendation' => is_array($diagnosisResult['laboratorium_terstruktur'] ?? null) ? $diagnosisResult['laboratorium_terstruktur'] : [],
            'emergency_actions' => is_array($diagnosisResult['emergency'] ?? null) ? $diagnosisResult['emergency'] : [],
            'handoff' => $diagnosisResult['handoff'] ?? '',
            'gcs' => (string) ($diagnosisResult['gcs'] ?? 'Data belum tersedia'),
            'kesadaran' => (string) ($diagnosisResult['kesadaran'] ?? 'Data belum tersedia'),
            'motorik' => (string) ($diagnosisResult['motorik'] ?? 'Data belum tersedia'),
            'ttv' => $diagnosisResult['ttv'] ?? [],
            'kasus_tindakan' => (string) ($diagnosisResult['kasus_tindakan'] ?? 'Data belum tersedia'),
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
    return "Anda menyusun CATATAN MEDIS SIMULASI ROLEPLAY FiveM Roxwood Hospital berdasarkan seluruh data skenario yang tersedia (AI Diagnosis, AI Surgery Planner, Radiology Center, Laboratory AI, dan Psychiatry Center bila ada). Ini materi permainan, bukan rekam medis pasien nyata atau panduan pelayanan. Tulis satu dokumen naratif yang utuh, rinci, dan profesional untuk konteks roleplay; jangan menyebutnya dokumen klinis nyata.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. Tulis narasi rapi dan cukup rinci di setiap bagian (terutama Anamnesis, Status Lokalis, Laporan Tindakan Operasi, Hasil Operasi, dan Status Pasca Operasi) dengan mempertahankan fakta sumber tanpa menambah kejadian.\n"
        . "2. \"laporan_tindakan\" (persiapan/operasi/hemostasis/penutupan) wajib berupa paragraf naratif gaya catatan operasi; jangan membuat daftar langkah bernomor, format /me, /do, atau checklist.\n"
        . "3. Data yang tidak tersedia wajib ditulis \"Belum diukur\", \"Belum dinilai\", atau \"Data belum tersedia\". Jangan mengarang motorik, sensorik, refleks, sirkulasi, GCS, TTV, kesadaran, saturasi, suhu, hasil tindakan, prognosis, atau respons terapi. Semua field tetap harus ada dan tidak boleh kosong.\n"
        . "4. Anamnesis wajib menggambarkan kondisi aktual sebelum operasi. Jika pasien sadar, tulis kesadaran dan anamnesis hanya dari fakta yang tersedia. Jika pasien pingsan atau kesadarannya menurun, jangan menulis pasien sadar penuh.\n"
        . "5. GCS harus aritmetis: total = E + V + M. E4 V4 M6 adalah GCS 14, bukan 13. Jangan mempertahankan total yang bertentangan dengan komponen; jika komponen atau total tidak tersedia, tulis data belum tersedia.\n"
        . "6. Jika suhu 33°C tercatat, pertahankan nilai sumber dan tandai perlu verifikasi; jangan otomatis menulis suhu normal atau hipotermia bila status klinis sumber menyatakan lain. Jika saturasi 95% tercatat, gunakan 95%; jangan menggantinya dengan 85% atau angka lain.\n"
        . "7. Nama tindakan, anestesi, laporan tindakan, dan hasil operasi harus mengikuti data aktual sumber. Hasil operasi harus menjelaskan hasil tindakan yang benar-benar tercatat; jangan menulis pasien meninggal, janin berhasil diekstraksi, benda asing terangkat, atau hasil lain tanpa bukti. Kematian saat operasi bukan Death on Arrival; DOA hanya bila pasien sudah meninggal ketika tiba sebelum tindakan. Jika sumber bertentangan, tulis konflik data dan minta verifikasi, jangan memilih diam-diam.\n"
        . "8. ORIF/Open Reduction Internal Fixation selalu dikategorikan sebagai operasi Mayor sesuai kebijakan kewenangan medis. Jangan menulis ORIF sebagai Minor.\n"
        . "9. Jangan mengubah anestesi aktual menjadi anestesi yang dianggap lebih ideal. Jika anestesi lokal dilakukan oleh co-ass, catat sebagai fakta hanya bila ada di sumber; alasan kewenangan, supervisi, dan pertimbangannya harus ditulis \"tidak tercatat\" bila tidak tersedia. Jangan menyimpulkan bahwa co-ass otomatis berwenang hanya karena anestesi lokal.\n"
        . "10. Diagnosis utama, diagnosis banding, dan hasil radiologi harus dibedakan. TBI tanpa dukungan anamnesis/pemeriksaan/hasil pencitraan tidak boleh menjadi diagnosis utama; bila hanya dugaan, letakkan sebagai diagnosis banding. Hasil CT tanpa cedera kepala tidak boleh ditulis sebagai cedera kepala terkonfirmasi.\n"
        . "11. AI Surgery Planner adalah rencana/rekomendasi, bukan bukti tindakan benar-benar dilakukan. Jangan memakai rencana, langkah, durasi, obat, atau laporan pasca-operasi dari Planner sebagai hasil aktual kecuali sumber eksplisit menyatakan tindakan sudah dilakukan. Jika tidak ada bukti pelaksanaan, hasil operasi dan status pasca-operasi wajib Data belum tersedia.\n"
        . "12. Konsisten secara medis: seluruh bagian harus selaras dengan data Diagnosis, Surgery Planner, Radiology, Laboratory, Psychiatry, dan dokumen resmi. Dokumen resmi adalah referensi aturan, bukan bukti kondisi pasien.\n"
        . "13. Bahasa Indonesia medis baku (EYD), objektif, tidak berlebihan, dan tidak berspekulasi di luar konteks.\n"
        . "14. Beri label jelas bahwa dokumen adalah catatan simulasi roleplay FiveM dan bukan rekam medis pasien nyata.\n"
        . "15. Jangan menyertakan penanganan emergency bergaya /me /do.\n"
        . "16. Jika narasi sumber memuat tindakan fisik, pertahankan nama alat, instrumen, dan bahan spesifik yang benar-benar tercantum; tulis 'mesin suction bedah dengan kateter suction steril' bila itulah alat sumbernya, bukan 'menghisap darah' saja. Hubungkan balutan IGD dengan langkah operasi: catat pembukaan balutan yang memang tercatat, alat yang dipakai, lalu tindakan operasi berikutnya. Jangan menambah instrumen sebagai fakta bila tidak ada pada sumber.\n"
        . "17. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n\n"
        . "Struktur JSON WAJIB (SEMUA field wajib ada; isi data yang belum tersedia dengan label eksplisit, bukan kosong):\n"
        . "{\n"
        . "  \"judul_operasi\": \"nama tindakan/operasi faktual atau Data belum tersedia\",\n"
        . "  \"ruang_perawatan\": \"alur ruang perawatan faktual dipisah tanda panah atau Data belum tersedia\",\n"
        . "  \"diagnosis_list\": [\"diagnosis utama yang didukung sumber\"],\n"
        . "  \"diagnosis_banding_list\": [\"diagnosis banding dari sumber, jangan nyatakan sebagai diagnosis terkonfirmasi\"],\n"
        . "  \"indikasi_operasi\": [\"indikasi yang didukung data atau Data belum tersedia\"],\n"
        . "  \"jenis_operasi_nama\": \"nama tindakan operasi faktual atau Data belum tersedia\",\n"
        . "  \"jenis_operasi_deskripsi\": \"deskripsi berdasarkan data atau Data belum tersedia\",\n"
        . "  \"jenis_anestesi_nama\": \"jenis anestesi aktual atau Data belum tersedia\",\n"
        . "  \"obat_anestesi\": [\"obat aktual atau Data belum tersedia\"],\n"
        . "  \"obat_intraoperatif\": [\"obat aktual atau Data belum tersedia\"],\n"
        . "  \"anamnesis_singkat\": \"narasi anamnesis berdasarkan fakta sumber atau Data belum tersedia\",\n"
        . "  \"status_lokalis_temuan\": [\"temuan aktual atau Data belum tersedia\"],\n"
        . "  \"status_neurovaskular\": {\"motorik\": \"temuan aktual atau Data belum tersedia\", \"sensorik\": \"temuan aktual atau Data belum tersedia\", \"refleks\": \"temuan aktual atau Data belum tersedia\", \"sirkulasi_perifer\": \"temuan aktual atau Data belum tersedia\"},\n"
        . "  \"ttv_pra_operasi\": {\"tekanan_darah\": \"nilai aktual atau Data belum tersedia\", \"nadi\": \"nilai aktual atau Data belum tersedia\", \"respirasi\": \"nilai aktual atau Data belum tersedia\", \"suhu\": \"nilai aktual atau Data belum tersedia\", \"saturasi_o2\": \"nilai aktual atau Data belum tersedia\"},\n"
        . "  \"gcs_nilai\": \"GCS aktual dengan total konsisten atau Data belum tersedia\",\n"
        . "  \"gcs_e\": \"respon mata aktual atau Data belum tersedia\", \"gcs_v\": \"respon verbal aktual atau Data belum tersedia\", \"gcs_m\": \"respon motorik GCS aktual atau Data belum tersedia\",\n"
        . "  \"radiologi_temuan\": [\"temuan 1\", \"temuan 2\"],\n"
        . "  \"radiologi_kesan\": [\"kesan 1\", \"kesan 2\"],\n"
        . "  \"laporan_tindakan\": {\"persiapan\": \"paragraf naratif\", \"operasi\": \"beberapa paragraf naratif\", \"hemostasis\": \"paragraf naratif\", \"penutupan\": \"paragraf naratif\"},\n"
        . "  \"hasil_operasi\": [\"hasil 1\", \"hasil 2\", \"hasil 3\"],\n"
        . "  \"status_pasca_operasi_umum\": \"Baik/Cukup/Kritis hanya jika didukung data, selain itu Data belum tersedia\",\n"
        . "  \"status_pasca_operasi_narasi\": \"narasi kondisi pasca operasi hanya dari observasi aktual, atau Data belum tersedia\",\n"
        . "  \"ttv_pasca_operasi\": {\"tekanan_darah\": \"nilai aktual atau Data belum tersedia\", \"nadi\": \"nilai aktual atau Data belum tersedia\", \"respirasi\": \"nilai aktual atau Data belum tersedia\", \"suhu\": \"nilai aktual atau Data belum tersedia\", \"saturasi_o2\": \"nilai aktual atau Data belum tersedia\"},\n"
        . "  \"prognosis_kategori\": \"kategori hanya jika didukung data, selain itu Data belum tersedia\",\n"
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
        'Jangan menyimpulkan jumlah proyektil dari jumlah luka masuk; sebut jumlah hanya jika sumber menyebutkannya eksplisit.',
        'Diagnosis Utama: ' . $d['diagnosis_utama'],
        'Diagnosis Banding: ' . implode(', ', $d['diagnosis_banding']),
        'GCS: ' . $d['gcs'],
        'Kesadaran aktual: ' . ($d['kesadaran'] ?? 'Data belum tersedia'),
        'Motorik aktual: ' . ($d['motorik'] ?? 'Data belum tersedia'),
        'TTV: ' . implode(', ', array_map(static fn ($v) => ($v['label'] ?? '') . ' ' . ($v['value'] ?? '') . (!empty($v['note']) ? ' (' . $v['note'] . ')' : ''), $d['ttv'])),
        'Kasus/Tindakan yang Diperlukan: ' . $d['kasus_tindakan'],
        'Jenis Operasi (dari Diagnosis): ' . $d['jenis_operasi'],
        'Jenis Anestesi (dari Diagnosis): ' . $d['jenis_anestesi'],
    ];

    if (!empty($d['laboratory_scenario_results'])) {
        $lines[] = '';
        $lines[] = '=== HASIL LABORATORIUM SKENARIO DARI AI DIAGNOSIS (nilai yang tercatat pada sumber) ===';
        foreach ($d['laboratory_scenario_results'] as $item) {
            if (is_array($item)) {
                $lines[] = trim(implode(' | ', array_filter([
                    (string) ($item['parameter'] ?? ''),
                    (string) ($item['nilai'] ?? $item['value'] ?? ''),
                    !empty($item['rentang']) ? 'Rentang: ' . (string) $item['rentang'] : '',
                    (string) ($item['interpretasi'] ?? $item['note'] ?? ''),
                ])));
            } elseif (is_scalar($item)) {
                $lines[] = (string) $item;
            }
        }
    }
    if (!empty($d['radiology_scenario_results'])) {
        $lines[] = '';
        $lines[] = '=== TEMUAN RADIOLOGI SKENARIO DARI AI DIAGNOSIS ===';
        foreach ($d['radiology_scenario_results'] as $item) {
            if (is_array($item)) {
                $lines[] = trim(implode(' | ', array_filter(array_map('strval', $item))));
            } elseif (is_scalar($item)) {
                $lines[] = (string) $item;
            }
        }
    }
    if (trim((string) ($d['handoff'] ?? '')) !== '') {
        $lines[] = 'Handoff praoperasi dari Diagnosis: ' . (string) $d['handoff'];
    }

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
        $lines[] = 'Ringkasan Tahapan Prosedur (RENCANA dari Surgery Planner, bukan bukti tindakan dilakukan; jangan tulis sebagai hasil aktual): ' . implode(' | ', array_map(static fn ($step) => ($step['aksi'] ?? '') . ' -> ' . ($step['hasil'] ?? ''), $s['tahapan_prosedur']));
        $lines[] = 'Risiko & Komplikasi: ' . implode(', ', array_map(static fn ($rk) => ($rk['judul'] ?? '') . ': ' . ($rk['deskripsi'] ?? ''), $s['risiko_komplikasi']));
        $lines[] = 'Laporan Pasca Operasi (TEKS RENCANA dari Surgery Planner, bukan bukti tindakan dilakukan): ' . $s['laporan_pasca_operasi'];
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

function ems_ai_medical_record_first_known_ttv(array $ttv, array $needles): ?string
{
    foreach ($ttv as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = mb_strtolower(trim((string) ($item['label'] ?? '')));
        $value = trim((string) ($item['value'] ?? ''));
        if ($label === '' || $value === '' || in_array(mb_strtolower($value), ['-', 'data belum tersedia', 'belum diukur', 'belum dinilai'], true)) {
            continue;
        }
        foreach ($needles as $needle) {
            if (str_contains($label, mb_strtolower($needle))) {
                return $value;
            }
        }
    }

    return null;
}

function ems_ai_medical_record_apply_source_facts(array $data, array $agg): array
{
    $diagnosis = is_array($agg['diagnosis'] ?? null) ? $agg['diagnosis'] : [];
    $sourceAnamnesis = trim((string) ($diagnosis['anamnesis_lengkap'] ?? $diagnosis['anamnesis'] ?? ''));
    if ($sourceAnamnesis !== '') {
        $data['anamnesis_singkat'] = $sourceAnamnesis;
    }

    $sourceHandoff = is_scalar($diagnosis['handoff'] ?? null) ? trim((string) $diagnosis['handoff']) : '';
    $emergencyActions = is_array($diagnosis['emergency_actions'] ?? null) ? $diagnosis['emergency_actions'] : [];
    $lastEmergency = $emergencyActions !== [] ? $emergencyActions[array_key_last($emergencyActions)] : [];
    $lastEmergencyText = is_array($lastEmergency)
        ? implode(' ', array_map('strval', [$lastEmergency['aksi'] ?? '', $lastEmergency['hasil'] ?? '']))
        : '';
    $handoffConfirmsTransfer = $sourceHandoff !== ''
        && preg_match('/pasien\s+(?:telah\s+)?(?:dipindahkan|diantar|dibawa|diserahterimakan)[^.!?]{0,120}(?:ruang\s+operasi|kamar\s+operasi)/iu', $sourceHandoff) === 1;
    $lastActionConfirmsTransfer = preg_match('/\b(?:memindahkan|mengantar|mendorong|membawa|mentransfer)\b/iu', $lastEmergencyText) === 1
        && preg_match('/ruang\s+operasi|kamar\s+operasi/iu', $lastEmergencyText) === 1
        && preg_match('/\btiba\b|\bsampai\b|memasuki\s+ruang/iu', $lastEmergencyText) === 1;
    if ($handoffConfirmsTransfer || $lastActionConfirmsTransfer) {
        // Preserve the actual destination recorded by the diagnosis handoff;
        // the AI must not downgrade a completed transfer to a planned one.
        $data['ruang_perawatan'] = 'IGD Roxwood Hospital → Ruang Operasi (pasien diantar sampai area penerimaan tim bedah)';
    }

    $sourceDiagnosis = trim((string) ($diagnosis['diagnosis_utama'] ?? ''));
    $sourceDifferential = is_array($diagnosis['diagnosis_banding'] ?? null) ? $diagnosis['diagnosis_banding'] : [];
    if ($sourceDiagnosis !== '') {
        $data['diagnosis_list'] = [$sourceDiagnosis];
        $data['diagnosis_banding_list'] = array_values(array_filter(array_map('strval', $sourceDifferential)));
    }

    // Diagnosis Assistant already records the completed RP-scene findings.
    // Preserve them when the optional dedicated Lab/Radiology module has not
    // been run; a linked module report takes precedence when it exists.
    if (empty($agg['radiology']) && !empty($diagnosis['radiology_scenario_results'])) {
        $scenarioFindings = [];
        foreach ($diagnosis['radiology_scenario_results'] as $finding) {
            if (is_array($finding)) {
                $finding = implode(' — ', array_filter(array_map('strval', $finding)));
            }
            if (is_scalar($finding) && trim((string) $finding) !== '') {
                $scenarioFindings[] = trim((string) $finding);
            }
        }
        if ($scenarioFindings !== []) {
            $data['radiologi_temuan'] = $scenarioFindings;
        }
    }

    $data['status_neurovaskular']['sensorik'] = 'Data belum tersedia';
    $data['status_neurovaskular']['refleks'] = 'Data belum tersedia';
    $data['status_neurovaskular']['sirkulasi_perifer'] = 'Data belum tersedia';

    $sourceTtv = is_array($diagnosis['ttv'] ?? null) ? $diagnosis['ttv'] : [];
    foreach ([
        'tekanan_darah' => ['tekanan darah', 'blood pressure'],
        'nadi' => ['nadi', 'heart rate', 'pulse'],
        'respirasi' => ['respirasi', 'respiratory rate', 'frekuensi napas'],
        'suhu' => ['suhu', 'temperature'],
        'saturasi_o2' => ['saturasi', 'spo2', 'sao2', 'oxygen saturation'],
    ] as $field => $needles) {
        $value = ems_ai_medical_record_first_known_ttv($sourceTtv, $needles);
        if ($value !== null) {
            $data['ttv_pra_operasi'][$field] = $value;
        }
    }

    $sourceMotorik = trim((string) ($diagnosis['motorik'] ?? ''));
    if ($sourceMotorik === '' || in_array(mb_strtolower($sourceMotorik), ['-', 'data belum tersedia', 'belum dinilai'], true)) {
        $data['status_neurovaskular']['motorik'] = 'Data belum tersedia';
    } else {
        $data['status_neurovaskular']['motorik'] = $sourceMotorik;
    }

    $sourceGcs = trim((string) ($diagnosis['gcs'] ?? ''));
    if ($sourceGcs !== '' && !in_array(mb_strtolower($sourceGcs), ['-', 'data belum tersedia', 'belum dinilai'], true)) {
        $data['gcs_nilai'] = $sourceGcs;
        if (preg_match('/\bE\s*([1-4])\s*V\s*([1-5])\s*M\s*([1-6])\b/i', $sourceGcs, $gcsParts)) {
            $data['gcs_e'] = 'E' . $gcsParts[1];
            $data['gcs_v'] = 'V' . $gcsParts[2];
            $data['gcs_m'] = 'M' . $gcsParts[3];
        } else {
            $data['gcs_e'] = 'Data belum tersedia';
            $data['gcs_v'] = 'Data belum tersedia';
            $data['gcs_m'] = 'Data belum tersedia';
        }
    } else {
        $data['gcs_nilai'] = 'Data belum tersedia';
        $data['gcs_e'] = 'Data belum tersedia';
        $data['gcs_v'] = 'Data belum tersedia';
        $data['gcs_m'] = 'Data belum tersedia';
    }

    // Diagnosis data has no observed postoperative source. Never reuse a plan
    // or preoperative value as a postoperative fact.
    $observedResult = trim((string) ($agg['performed_operation_result'] ?? ''));
    if ($observedResult === '') {
        $data['hasil_operasi'] = ['Data belum tersedia'];
        $data['status_pasca_operasi_umum'] = 'Data belum tersedia';
        $data['status_pasca_operasi_narasi'] = 'Data belum tersedia';
        $data['ttv_pasca_operasi'] = array_fill_keys(['tekanan_darah', 'nadi', 'respirasi', 'suhu', 'saturasi_o2'], 'Data belum tersedia');
        $data['prognosis_kategori'] = 'Data belum tersedia';
        $data['prognosis_penjelasan'] = 'Data belum tersedia';
    }

    $operationText = mb_strtolower(implode(' ', [
        (string) ($diagnosis['jenis_operasi'] ?? ''),
        (string) ($diagnosis['kasus_tindakan'] ?? ''),
    ]));
    if (str_contains($operationText, 'orif') || str_contains($operationText, 'open reduction internal fixation')) {
        $name = trim((string) ($data['jenis_operasi_nama'] ?? ''));
        $name = preg_replace('/\bminor\b/i', 'Mayor', $name) ?? $name;
        $data['jenis_operasi_nama'] = $name === ''
            ? 'Mayor - Open Reduction Internal Fixation (ORIF)'
            : (preg_match('/\bmayor\b/i', $name) ? $name : 'Mayor - ' . $name);
    }

    $sourceAnesthesia = trim((string) ($diagnosis['jenis_anestesi'] ?? ''));
    if ($sourceAnesthesia !== '' && !in_array(mb_strtolower($sourceAnesthesia), ['-', 'data belum tersedia', 'belum dinilai'], true)) {
        $data['jenis_anestesi_nama'] = $sourceAnesthesia;
    }

    $sourceFacts = mb_strtolower((string) ($diagnosis['anamnesis_lengkap'] ?? '') . ' ' . (string) ($diagnosis['anamnesis'] ?? ''));
    if (str_contains($sourceFacts, 'proyektil masih bersarang') && preg_match('/\b(?:dua|2)\s+proyektil\b/iu', json_encode($data, JSON_UNESCAPED_UNICODE) ?: '') === 1) {
        $rewriteProjectileCount = static function (&$value) use (&$rewriteProjectileCount): void {
            if (is_string($value)) {
                $value = preg_replace('/\b(?:dua|2)\s+proyektil(?:-proyektil)?\b/iu', 'proyektil', $value) ?? $value;
            } elseif (is_array($value)) {
                foreach ($value as &$child) $rewriteProjectileCount($child);
                unset($child);
            }
        };
        $rewriteProjectileCount($data);
    }

    if ($observedResult === '') {
        $sourceOperation = trim((string) ($diagnosis['jenis_operasi'] ?? ''));
        $data['judul_operasi'] = 'Catatan Pra-Operasi IGD' . ($sourceOperation !== '' ? ' — ' . $sourceOperation : '');
    }

    return $data;
}

function ems_ai_medical_record_sanitize(array $data, ?array $agg = null): array
{
    $toStringArray = static function ($value): array {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn ($v) => trim($v) !== ''));
        }
        $value = trim((string) $value);
        return $value !== '' ? [$value] : [];
    };
    $str = static fn ($v, $fallback = 'Data belum tersedia') => trim((string) ($v ?? '')) !== '' ? trim((string) $v) : $fallback;

    $neuro = is_array($data['status_neurovaskular'] ?? null) ? $data['status_neurovaskular'] : [];
    $ttvPra = is_array($data['ttv_pra_operasi'] ?? null) ? $data['ttv_pra_operasi'] : [];
    $ttvPasca = is_array($data['ttv_pasca_operasi'] ?? null) ? $data['ttv_pasca_operasi'] : [];
    $laporan = is_array($data['laporan_tindakan'] ?? null) ? $data['laporan_tindakan'] : [];

    $sanitized = [
        'judul_operasi' => $str($data['judul_operasi'] ?? null),
        'ruang_perawatan' => $str($data['ruang_perawatan'] ?? null),
        'diagnosis_list' => $toStringArray($data['diagnosis_list'] ?? []),
        'diagnosis_banding_list' => $toStringArray($data['diagnosis_banding_list'] ?? []),
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
        'status_pasca_operasi_umum' => $str($data['status_pasca_operasi_umum'] ?? null, 'Data belum tersedia'),
        'status_pasca_operasi_narasi' => $str($data['status_pasca_operasi_narasi'] ?? null),
        'ttv_pasca_operasi' => [
            'tekanan_darah' => $str($ttvPasca['tekanan_darah'] ?? null),
            'nadi' => $str($ttvPasca['nadi'] ?? null),
            'respirasi' => $str($ttvPasca['respirasi'] ?? null),
            'suhu' => $str($ttvPasca['suhu'] ?? null),
            'saturasi_o2' => $str($ttvPasca['saturasi_o2'] ?? null),
        ],
        'prognosis_kategori' => $str($data['prognosis_kategori'] ?? null, 'Data belum tersedia'),
        'prognosis_penjelasan' => $str($data['prognosis_penjelasan'] ?? null),
    ];

    return $agg !== null ? ems_ai_medical_record_apply_source_facts($sanitized, $agg) : $sanitized;
}

function ems_ai_medical_record_generate(PDO $pdo, array $agg, ?int $createdBy): array
{
    $systemPrompt = ems_ai_medical_record_default_system_prompt();
    $userPrompt = ems_ai_medical_record_build_user_prompt($agg);
    if (trim((string) ($agg['performed_operation_result'] ?? '')) === '') {
        $systemPrompt .= "\n\nMODE CATATAN PRA-OPERASI: sumber ini tidak mencatat hasil tindakan bedah. Hasil yang diminta adalah catatan akhir episode IGD/pra-operasi berdasarkan data faktual yang tersedia. Pisahkan diagnosis utama dari diagnosis banding. Gunakan TTV, GCS, hasil skenario laboratorium/radiologi, tindakan stabilisasi IGD, handoff, dan rencana operasi yang ada di sumber. Jangan menyatakan cedera organ yang baru dicurigai sebagai diagnosis terkonfirmasi. Jangan menyimpulkan jumlah proyektil dari jumlah luka; sebut jumlah hanya jika dinyatakan eksplisit. Jangan mengarang hasil laboratorium/radiologi, temuan operasi, transfusi, obat, atau status pasca-operasi. Jangan memenuhi narasi dengan frasa 'Data belum tersedia'; bagian yang tidak relevan dengan catatan pra-operasi harus dibiarkan kosong. Narasi persiapan/operasi/hemostasis/penutupan dari Surgery Planner adalah rencana, bukan tindakan aktual.\n";
    }

    $result = ems_ai_ds_call_gemini($pdo, $systemPrompt, $userPrompt, 'rekam_medis_ai', $createdBy);
    if (!$result['ok']) {
        return $result;
    }

    return ['ok' => true, 'data' => ems_ai_medical_record_sanitize($result['data'], $agg)];
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
    $hasPerformedOperation = trim((string) ($agg['performed_operation_result'] ?? '')) !== '';
    $knownValue = static function ($value): bool {
        $value = mb_strtolower(trim((string) $value));
        return $value !== '' && !in_array($value, ['-'], true)
            && preg_match('/(?:data\s+)?(?:belum\s+(?:tersedia|diukur|dinilai|tercatat|terverifikasi|dilakukan)|tidak\s+tercatat|sedang\s+diproses|menunggu\s+hasil|tidak\s+membuktikan)/iu', $value) !== 1;
    };
    $knownList = static fn (array $items): array => array_values(array_filter($items, $knownValue));
    $cleanNarrative = static function ($value): string {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentences = array_values(array_filter($sentences, static fn (string $sentence): bool => preg_match('/(?:data\s+)?(?:belum\s+(?:tersedia|diukur|dinilai|tercatat|terverifikasi|dilakukan)|tidak\s+tercatat|sedang\s+diproses|menunggu\s+hasil|tidak\s+membuktikan)/iu', $sentence) !== 1));
        return trim(implode(' ', $sentences));
    };
    $ul = static function (array $items, string $emptyText) use ($e): string {
        if (!$items) {
            return '<p>' . $e($emptyText) . '</p>';
        }
        return '<ul>' . implode('', array_map(static fn ($i) => '<li>' . $e($i) . '</li>', $items)) . '</ul>';
    };
    $nl2br = static fn ($v) => nl2br($e($v), false);

    $recordTitle = $hasPerformedOperation ? 'REKAM MEDIS : ' : 'REKAM MEDIS IGD PRA-OPERASI : ';
    $html = '<p style="text-align:center;"><strong>CATATAN SIMULASI ROLEPLAY FIVEM — BUKAN REKAM MEDIS PASIEN NYATA</strong></p>';
    $html .= '<h1 style="text-align:center;"><strong>' . $e($recordTitle . $cleanNarrative($n['judul_operasi'])) . '</strong></h1>';

    $html .= '<h2><strong>INFORMASI WAKTU</strong></h2>';
    $html .= '<p><strong>RUANG PERAWATAN:</strong> ' . $e($cleanNarrative($n['ruang_perawatan'])) . '</p>';

    $html .= '<h2><strong>DIAGNOSIS</strong></h2>';
    $html .= $ul($knownList($n['diagnosis_list']), '-');
    if (!$hasPerformedOperation && !empty($n['diagnosis_banding_list'])) {
        $differentials = $knownList($n['diagnosis_banding_list']);
        if ($differentials !== []) $html .= '<h2><strong>DIAGNOSIS BANDING / KECURIGAAN KLINIS</strong></h2>' . $ul($differentials, '-');
    }

    $html .= '<h2><strong>INDIKASI OPERASI</strong></h2>';
    $html .= $ul($knownList($n['indikasi_operasi']), '-');

    $html .= $hasPerformedOperation ? '<h2><strong>JENIS OPERASI</strong></h2>' : '<h2><strong>RENCANA OPERASI</strong></h2>';
    if ($knownValue($n['jenis_operasi_nama'])) $html .= '<p><strong>' . $e($cleanNarrative($n['jenis_operasi_nama'])) . '</strong></p>';
    if ($knownValue($n['jenis_operasi_deskripsi'])) $html .= '<p>(' . $e($cleanNarrative($n['jenis_operasi_deskripsi'])) . ')</p>';

    $html .= $hasPerformedOperation ? '<h2><strong>JENIS ANESTESI</strong></h2>' : '<h2><strong>RENCANA ANESTESI</strong></h2>';
    if ($knownValue($n['jenis_anestesi_nama'])) $html .= '<p>' . $e($cleanNarrative($n['jenis_anestesi_nama'])) . '</p>';
    $anestheticDrugs = $knownList($n['obat_anestesi']);
    $intraOpDrugs = $knownList($n['obat_intraoperatif']);
    if ($anestheticDrugs !== []) {
        $html .= '<p><strong>Obat Anestesi:</strong></p>' . $ul($anestheticDrugs, '-');
    }
    if ($intraOpDrugs !== []) {
        $html .= '<p><strong>Obat Intraoperatif:</strong></p>' . $ul($intraOpDrugs, '-');
    }

    $html .= '<h2><strong>ANAMNESIS SINGKAT</strong></h2>';
    $html .= '<p>' . $nl2br($cleanNarrative($n['anamnesis_singkat'])) . '</p>';

    $html .= '<h2><strong>STATUS LOKALIS PRA OPERASI</strong></h2>';
    $html .= $ul($knownList($n['status_lokalis_temuan']), '-');
    $neuroItems = [];
    foreach (['motorik' => 'Motorik', 'sensorik' => 'Sensorik', 'refleks' => 'Refleks', 'sirkulasi_perifer' => 'Sirkulasi Perifer'] as $key => $label) {
        if ($knownValue($n['status_neurovaskular'][$key] ?? '')) $neuroItems[] = '<li><strong>' . $e($label) . ':</strong> ' . $e($n['status_neurovaskular'][$key]) . '</li>';
    }
    if ($neuroItems !== []) $html .= '<p><strong>Status Neurovaskular / Neurologis:</strong></p><ul>' . implode('', $neuroItems) . '</ul>';

    $html .= '<h2><strong>TANDA TANDA VITAL (TTV) PRA OPERASI</strong></h2>';
    foreach (['tekanan_darah' => 'Tekanan Darah', 'nadi' => 'Nadi', 'respirasi' => 'Respirasi', 'suhu' => 'Suhu Tubuh', 'saturasi_o2' => 'Saturasi O2'] as $key => $label) {
        if ($knownValue($n['ttv_pra_operasi'][$key] ?? '')) $html .= '<p><strong>' . $e($label) . ':</strong> ' . $e($n['ttv_pra_operasi'][$key]) . '</p>';
    }

    if ($knownValue($n['gcs_nilai'])) {
        $html .= '<h2><strong>STATUS NEUROLOGIS</strong></h2>';
        $html .= '<p><strong>GCS (Glasgow Coma Scale):</strong> ' . $e($n['gcs_nilai']) . '</p>';
        $gcsItems = [];
        foreach (['gcs_e' => 'E', 'gcs_v' => 'V', 'gcs_m' => 'M'] as $key => $label) if ($knownValue($n[$key] ?? '')) $gcsItems[] = '<li><strong>' . $label . ':</strong> ' . $e($n[$key]) . '</li>';
        if ($gcsItems !== []) $html .= '<ul>' . implode('', $gcsItems) . '</ul>';
    }

    if ($n['radiologi_temuan'] || $n['radiologi_kesan']) {
        $radiologySourceLabel = !empty($agg['radiology'])
            ? 'HASIL RADIOLOGY CENTER'
            : 'TEMUAN RADIOLOGI PADA LAPORAN DIAGNOSIS';
        $html .= '<h2><strong>' . $e($radiologySourceLabel) . '</strong></h2>';
        if ($n['radiologi_temuan']) {
            $html .= '<p><strong>Temuan</strong></p>' . $ul($n['radiologi_temuan'], '-');
        }
        if ($n['radiologi_kesan']) {
            $html .= '<p><strong>Kesan Radiologi</strong></p>' . $ul($n['radiologi_kesan'], '-');
        }
    }

    if (empty($agg['laboratory']) && !empty($agg['diagnosis']['laboratory_scenario_results'])) {
        $html .= '<h2><strong>HASIL LABORATORIUM PADA LAPORAN DIAGNOSIS</strong></h2><ul>';
        foreach ($agg['diagnosis']['laboratory_scenario_results'] as $labItem) {
            if (is_array($labItem)) {
                $label = trim((string) ($labItem['parameter'] ?? 'Pemeriksaan'));
                $value = trim((string) ($labItem['nilai'] ?? $labItem['value'] ?? ''));
                $range = trim((string) ($labItem['rentang'] ?? ''));
                $interpretation = trim((string) ($labItem['interpretasi'] ?? ''));
                $line = $label . ($value !== '' ? ': ' . $value : '');
                if ($range !== '') $line .= ' (rentang ' . $range . ')';
                if ($interpretation !== '') $line .= ' — ' . $interpretation;
                $html .= '<li>' . $e($line) . '</li>';
            } elseif (is_scalar($labItem) && trim((string) $labItem) !== '') {
                $html .= '<li>' . $e($labItem) . '</li>';
            }
        }
        $html .= '</ul>';
    }

    if ($hasPerformedOperation) {
        $html .= '<h2><strong>LAPORAN TINDAKAN OPERASI</strong></h2>';
        foreach (['persiapan' => 'A. Tahap Persiapan', 'operasi' => 'B. Tahap Operasi', 'hemostasis' => 'C. Hemostasis', 'penutupan' => 'D. Penutupan Operasi'] as $key => $label) {
            if ($knownValue($n['laporan_tindakan'][$key] ?? '')) $html .= '<p><strong>' . $e($label) . '</strong></p><p>' . $nl2br($n['laporan_tindakan'][$key]) . '</p>';
        }
        $html .= '<h2><strong>HASIL OPERASI</strong></h2>' . $ul($knownList($n['hasil_operasi']), '-');
        $html .= '<h2><strong>STATUS PASCA OPERASI (IMMEDIATE POST OP)</strong></h2>';
        if ($knownValue($n['status_pasca_operasi_umum'])) $html .= '<p><strong>Status Umum:</strong> ' . $e($n['status_pasca_operasi_umum']) . '</p>';
        if ($knownValue($n['status_pasca_operasi_narasi'])) $html .= '<p>' . $nl2br($n['status_pasca_operasi_narasi']) . '</p>';
        $postOpVitals = [];
        foreach (['tekanan_darah' => 'Tekanan Darah', 'nadi' => 'Nadi', 'respirasi' => 'Respirasi', 'suhu' => 'Suhu Tubuh', 'saturasi_o2' => 'Saturasi O2'] as $key => $label) {
            if ($knownValue($n['ttv_pasca_operasi'][$key] ?? '')) $postOpVitals[] = '<li><strong>' . $e($label) . ':</strong> ' . $e($n['ttv_pasca_operasi'][$key]) . '</li>';
        }
        if ($postOpVitals !== []) $html .= '<h2><strong>TANDA TANDA VITAL PASCA OPERASI</strong></h2><ul>' . implode('', $postOpVitals) . '</ul>';
    } else {
        $actions = $agg['diagnosis']['emergency_actions'] ?? [];
        if (is_array($actions) && $actions !== []) {
            $html .= '<h2><strong>STABILISASI IGD DAN HANDOFF PRA-OPERASI</strong></h2><ol>';
            foreach ($actions as $action) {
                if (!is_array($action)) continue;
                $line = trim((string) ($action['aksi'] ?? ''));
                $outcome = trim((string) ($action['hasil'] ?? ''));
                $text = $cleanNarrative(trim($line . ($outcome !== '' ? ' — ' . $outcome : '')));
                if ($knownValue($text)) $html .= '<li>' . $e($text) . '</li>';
            }
            $html .= '</ol>';
        }
        $handoffText = $cleanNarrative($agg['diagnosis']['handoff'] ?? '');
        if ($knownValue($handoffText)) $html .= '<p><strong>Handoff:</strong> ' . $e($handoffText) . '</p>';
        $plannedSteps = $agg['surgery']['tahapan_prosedur'] ?? [];
        if (is_array($plannedSteps) && $plannedSteps !== []) {
            $html .= '<h2><strong>RENCANA TAHAPAN BEDAH</strong></h2><ol>';
            foreach ($plannedSteps as $step) {
                if (!is_array($step)) continue;
                $text = $cleanNarrative($step['aksi'] ?? '');
                if ($knownValue($text)) $html .= '<li>' . $e($text) . '</li>';
            }
            $html .= '</ol>';
        }
    }

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

    if ($hasPerformedOperation && $knownValue($n['prognosis_kategori'])) {
        $html .= '<h2><strong>PROGNOSIS</strong></h2>';
        $html .= '<p><strong>' . $e($n['prognosis_kategori']) . '</strong></p>';
        if ($knownValue($n['prognosis_penjelasan'])) $html .= '<p>' . $nl2br($n['prognosis_penjelasan']) . '</p>';
    }

    return $html;
}
