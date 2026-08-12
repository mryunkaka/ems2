<?php

/**
 * Psychiatry Center: asesmen psikiatri multi-turn (AI mengajukan pertanyaan
 * wawancara klinis dinamis, memperbarui clinical impressions tiap giliran)
 * yang berujung ke laporan diagnosis formal DSM-5/ICD-10 + Mental Status
 * Examination (MSE) + risk assessment + treatment plan + farmakoterapi.
 * Memakai API key Gemini pribadi yang sama dengan AI Diagnosis/Surgery/
 * Radiology/Laboratory (lihat config/ai_diagnosis_surgery.php) — text/JSON
 * generation, bukan image generation.
 *
 * Arsitektur multi-turn: state percakapan (chatHistory) disimpan di JS sisi
 * klien (persis pola reference tool-nya) dan dikirim ulang sebagai teks
 * transcript setiap giliran — server tetap stateless per-request, tidak ada
 * tabel sesi interview. Hanya asesmen yang SUDAH final (dari action
 * "finalize") yang disimpan ke DB; interview yang belum selesai/ditinggal
 * begitu saja sengaja TIDAK tercatat di riwayat, karena tidak ada laporan
 * koheren yang bisa ditampilkan untuk interview setengah jalan.
 */

require_once __DIR__ . '/ai_diagnosis_surgery.php';

function ems_ai_psychiatry_ensure_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    if (!ems_table_exists($pdo, 'ai_psychiatry_assessments')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ai_psychiatry_assessments` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `report_code` VARCHAR(40) NULL,
                `user_id` INT NOT NULL,
                `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
                `division_snapshot` VARCHAR(60) NULL,
                `patient_name` VARCHAR(150) NULL,
                `patient_dob` DATE NULL,
                `patient_citizen_id` VARCHAR(50) NULL,
                `doctor_name` VARCHAR(150) NULL,
                `department` VARCHAR(60) NOT NULL,
                `assessment_type` VARCHAR(40) NOT NULL,
                `priority` VARCHAR(20) NOT NULL,
                `chief_complaint` TEXT NULL,
                `anamnesis` TEXT NULL,
                `chat_transcript` LONGTEXT NULL,
                `result_json` LONGTEXT NULL,
                `status` ENUM('done','error') NOT NULL DEFAULT 'done',
                `error_message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ai_psychiatry_report_code` (`report_code`),
                KEY `idx_ai_psychiatry_user` (`user_id`),
                KEY `idx_ai_psychiatry_unit_created` (`unit_code`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    // Guard "1 kode referensi hanya boleh dipakai 1x per halaman", migration 66.
    if (!ems_column_exists($pdo, 'ai_psychiatry_assessments', 'source_report_code')) {
        $pdo->exec("ALTER TABLE `ai_psychiatry_assessments` ADD COLUMN `source_report_code` VARCHAR(40) NULL AFTER `anamnesis`");
    }
}

function ems_ai_psychiatry_departments(): array
{
    return ['Adult Psychiatry', 'Child & Adolescent Psychiatry', 'Geriatric Psychiatry'];
}

function ems_ai_psychiatry_assessment_types(): array
{
    return ['Initial Assessment', 'Follow-up', 'Emergency Evaluation'];
}

function ems_ai_psychiatry_priorities(): array
{
    return ['Routine', 'Urgent'];
}

/**
 * Jumlah giliran wawancara sebelum finalisasi otomatis diminta di UI (user
 * tetap bisa menekan "Selesaikan & Diagnosis Sekarang" lebih awal).
 */
function ems_ai_psychiatry_total_turns(): int
{
    return 4;
}

function ems_ai_psychiatry_severity_options(): array
{
    return ['Ringan', 'Sedang', 'Berat'];
}

function ems_ai_psychiatry_risk_options(): array
{
    return ['Rendah', 'Sedang', 'Tinggi'];
}

function ems_ai_psychiatry_generate_report_code(): string
{
    return 'PSY-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function ems_ai_psychiatry_find_by_code(PDO $pdo, string $code, string $unitCode): ?array
{
    $code = trim($code);
    if ($code === '' || !ems_column_exists($pdo, 'ai_psychiatry_assessments', 'report_code')) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT * FROM ai_psychiatry_assessments
        WHERE report_code = ? AND unit_code = ? AND status = 'done'
        LIMIT 1
    ");
    $stmt->execute([$code, $unitCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Ubah riwayat chat terstruktur ({role: 'ai'|'user', text}) jadi teks
 * "AI: .../Pasien: ..." untuk disisipkan ke prompt tahap next/finalize —
 * server yang membangun teks ini (bukan percaya begitu saja teks dari
 * client) supaya formatnya selalu konsisten.
 */
function ems_ai_psychiatry_render_dialog_context(array $chatHistory): string
{
    $lines = [];
    foreach ($chatHistory as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $role = (string) ($entry['role'] ?? '');
        $text = trim((string) ($entry['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $lines[] = ($role === 'ai' ? 'AI: ' : 'Pasien: ') . $text;
    }

    return implode("\n", $lines);
}

/**
 * Bagian ATURAN yang sama di ketiga tahap (start/next/final) — persona +
 * batasan gaya bahasa, supaya konsisten dan tidak perlu diulang manual di
 * tiap system prompt.
 */
function ems_ai_psychiatry_persona_rules(): string
{
    return "Anda adalah Dokter Spesialis Psikiatri Konsultan Senior (Sp.KJ) di Roxwood Hospital, memimpin asesmen psikiatri berbasis evidence-based medicine. Clinical Information yang diberikan adalah sumber utama clinical reasoning Anda.\n\n"
        . "ATURAN GAYA BAHASA WAJIB:\n"
        . "- Bahasa Indonesia formal, terminologi medis psikiatri baku. JANGAN memakai bahasa percakapan/santai.\n"
        . "- Output harus menyerupai dokumentasi psikiater sungguhan di rumah sakit modern.\n"
        . "- HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n";
}

function ems_ai_psychiatry_start_system_prompt(): string
{
    return ems_ai_psychiatry_persona_rules()
        . "\nTUGAS TAHAP INI (awal asesmen):\n"
        . "1. Analisis seluruh data klinis awal yang diberikan (keluhan utama + anamnesis).\n"
        . "2. Susun clinical impressions awal (2-4 kemungkinan kondisi) beserta persentase probabilitasnya (total tidak harus 100%, ini estimasi independen per kondisi).\n"
        . "3. Rumuskan SATU pertanyaan wawancara klinis pertama yang paling tajam untuk mempersempit diagnosis banding.\n\n"
        . "ATURAN TAMBAHAN:\n"
        . "- HANYA satu pertanyaan dalam satu waktu.\n"
        . "- JANGAN langsung memberikan diagnosis pasti di tahap ini.\n\n"
        . "Struktur JSON WAJIB:\n"
        . "{\n"
        . "  \"clinical_impressions\": [{\"condition\": \"nama kondisi\", \"probability\": 45}],\n"
        . "  \"first_question\": \"satu pertanyaan wawancara klinis pertama\"\n"
        . "}";
}

function ems_ai_psychiatry_build_start_user_prompt(array $input): string
{
    $lines = [
        'Departemen: ' . ($input['department'] ?? ''),
        'Tipe Asesmen: ' . ($input['assessment_type'] ?? ''),
        'Prioritas: ' . ($input['priority'] ?? ''),
        'Keluhan Utama: ' . ($input['chief_complaint'] ?? ''),
        'Anamnesis / Temuan Klinis / Diagnosis Kerja Awal: ' . ($input['anamnesis'] ?? ''),
        '',
        'Berikan clinical impressions awal dan pertanyaan wawancara pertama.',
    ];

    return implode("\n", $lines);
}

function ems_ai_psychiatry_next_system_prompt(): string
{
    return ems_ai_psychiatry_persona_rules()
        . "\nTUGAS TAHAP INI (lanjutan wawancara):\n"
        . "1. Analisis respons terbaru pasien dalam konteks seluruh riwayat wawancara sebelumnya.\n"
        . "2. Perbarui clinical impressions (probabilitas) secara akurat berdasarkan jawaban baru.\n"
        . "3. Rumuskan SATU pertanyaan klinis lanjutan yang mempertimbangkan seluruh jawaban sebelumnya — JANGAN mengulang pertanyaan yang sudah ditanyakan.\n\n"
        . "ATURAN TAMBAHAN:\n"
        . "- HANYA satu pertanyaan dalam satu waktu.\n"
        . "- JANGAN menyebutkan diagnosis spesifik secara langsung kepada pasien di tahap ini.\n\n"
        . "Struktur JSON WAJIB:\n"
        . "{\n"
        . "  \"clinical_impressions\": [{\"condition\": \"nama kondisi\", \"probability\": 45}],\n"
        . "  \"next_question\": \"satu pertanyaan wawancara lanjutan\"\n"
        . "}";
}

function ems_ai_psychiatry_build_next_user_prompt(array $input): string
{
    $lines = [
        'Departemen: ' . ($input['department'] ?? ''),
        'Keluhan Utama: ' . ($input['chief_complaint'] ?? ''),
        'Anamnesis Awal: ' . ($input['anamnesis'] ?? ''),
        '',
        'Riwayat Wawancara Sejauh Ini:',
        (string) ($input['dialog_context'] ?? ''),
        '',
        'Buat pertanyaan lanjutan (Pertanyaan ke-' . (int) ($input['turn'] ?? 0) . ' dari ' . ems_ai_psychiatry_total_turns() . ').',
    ];

    return implode("\n", $lines);
}

function ems_ai_psychiatry_final_system_prompt(): string
{
    return ems_ai_psychiatry_persona_rules()
        . "\nTUGAS TAHAP INI (finalisasi laporan):\n"
        . "1. Tegakkan diagnosis utama sesuai kriteria DSM-5/ICD-10 lengkap dengan kode (contoh: F32.1), beserta diagnosis banding.\n"
        . "2. Isi Mental Status Examination (MSE) LENGKAP untuk seluruh 12 parameter, konsisten dengan hasil wawancara.\n"
        . "3. Nilai tingkat keparahan (severity) dan risiko (suicide/violence/self-harm) berdasarkan seluruh data.\n"
        . "4. Susun treatment plan non-farmakologi yang konkret dan sesuai diagnosis.\n"
        . "5. Rekomendasikan farmakoterapi spesifik sesuai guideline psikiatri (nama generik, dosis, frekuensi, durasi, rencana pemantauan) — atau array kosong kalau memang tidak diindikasikan, sebutkan alasannya di clinical_summary.\n"
        . "6. Tulis clinical_summary: ringkasan naratif kesimpulan klinis 2-4 kalimat.\n\n"
        . "ATURAN KONSISTENSI WAJIB:\n"
        . "- Diagnosis harus konsisten dengan anamnesis, seluruh hasil wawancara, dan MSE — jangan kontradiksi.\n"
        . "- \"severity\" WAJIB PERSIS salah satu dari: \"Ringan\", \"Sedang\", \"Berat\".\n"
        . "- \"suicide_risk\", \"violence_risk\", \"self_harm_risk\" masing-masing WAJIB PERSIS salah satu dari: \"Rendah\", \"Sedang\", \"Tinggi\".\n\n"
        . "Struktur JSON WAJIB:\n"
        . "{\n"
        . "  \"clinical_impressions\": [{\"condition\": \"...\", \"probability\": 80}],\n"
        . "  \"mse\": {\"appearance\": \"...\", \"behavior\": \"...\", \"speech\": \"...\", \"mood\": \"...\", \"affect\": \"...\", \"thought_process\": \"...\", \"thought_content\": \"...\", \"perception\": \"...\", \"insight\": \"...\", \"judgment\": \"...\", \"cognition\": \"...\", \"orientation\": \"...\"},\n"
        . "  \"diagnosis\": {\"code\": \"F32.1\", \"primary\": \"nama diagnosis utama\", \"differential\": [\"diagnosis banding 1\"]},\n"
        . "  \"risk_assessment\": {\"severity\": \"Ringan|Sedang|Berat\", \"suicide_risk\": \"Rendah|Sedang|Tinggi\", \"violence_risk\": \"Rendah|Sedang|Tinggi\", \"self_harm_risk\": \"Rendah|Sedang|Tinggi\"},\n"
        . "  \"treatment_plan\": [\"rencana terapi non-farmakologi 1\"],\n"
        . "  \"medications\": [{\"name\": \"nama generik obat\", \"dose\": \"dosis\", \"frequency\": \"frekuensi\", \"duration\": \"durasi\", \"monitoring\": \"rencana pemantauan\"}],\n"
        . "  \"clinical_summary\": \"ringkasan kesimpulan klinis\"\n"
        . "}";
}

function ems_ai_psychiatry_build_final_user_prompt(array $input): string
{
    $lines = [
        'Nama Pasien: ' . ($input['patient_name'] ?? '') . ' (DOB: ' . ($input['patient_dob'] ?? '-') . ', Citizen ID: ' . ($input['patient_citizen_id'] ?? '-') . ')',
        'Departemen: ' . ($input['department'] ?? ''),
        'Tipe Asesmen: ' . ($input['assessment_type'] ?? ''),
        'Keluhan Utama: ' . ($input['chief_complaint'] ?? ''),
        'Anamnesis Awal: ' . ($input['anamnesis'] ?? ''),
        '',
        'Dinamika Dialog Wawancara:',
        (string) ($input['dialog_context'] ?? ''),
    ];

    if (!empty($input['skipped'])) {
        $lines[] = '';
        $lines[] = '(Wawancara klinis dipersingkat/diselesaikan lebih awal oleh dokter pemeriksa — lakukan integrasi berdasarkan data objektif yang tersedia sejauh ini.)';
    }

    $lines[] = '';
    $lines[] = 'Kembalikan hasil evaluasi lengkap dalam bentuk JSON terstruktur sesuai skema.';

    return implode("\n", $lines);
}

/**
 * Normalisasi "clinical_impressions": pastikan array of {condition, probability
 * integer 0-100} — melindungi dari model membalas probability sebagai string
 * ("45%") atau condition kosong.
 */
function ems_ai_psychiatry_sanitize_impressions($impressions): array
{
    if (!is_array($impressions)) {
        return [];
    }

    $out = [];
    foreach ($impressions as $item) {
        if (!is_array($item)) {
            continue;
        }
        $condition = trim((string) ($item['condition'] ?? ''));
        if ($condition === '') {
            continue;
        }
        $probability = (int) preg_replace('/[^0-9\-]/', '', (string) ($item['probability'] ?? 0));
        $probability = max(0, min(100, $probability));
        $out[] = ['condition' => $condition, 'probability' => $probability];
    }

    return $out;
}

function ems_ai_psychiatry_sanitize_start_or_next(array $data, string $questionKey): array
{
    return [
        'clinical_impressions' => ems_ai_psychiatry_sanitize_impressions($data['clinical_impressions'] ?? []),
        $questionKey => trim((string) ($data[$questionKey] ?? '')),
    ];
}

/**
 * Normalisasi salah satu field enum (severity/risk) ke daftar opsi baku —
 * sama pola dengan ems_ai_laboratory_sanitize_result()'s flag normalizer,
 * mengantisipasi model membalas variasi kata lain.
 */
function ems_ai_psychiatry_normalize_enum(string $value, array $options, string $fallback): string
{
    $value = strtolower(trim($value));
    foreach ($options as $option) {
        if ($value === strtolower($option)) {
            return $option;
        }
    }
    // Pencocokan longgar berbasis kata kunci umum (Indonesia & Inggris)
    $map = [
        'Ringan' => ['mild', 'ringan', 'low'],
        'Sedang' => ['moderate', 'sedang', 'medium', 'mod'],
        'Berat' => ['severe', 'berat', 'high', 'tinggi'],
        'Rendah' => ['low', 'rendah', 'minimal'],
        'Tinggi' => ['high', 'tinggi', 'severe', 'berat'],
    ];
    foreach ($options as $option) {
        foreach ($map[$option] ?? [] as $needle) {
            if (str_contains($value, $needle)) {
                return $option;
            }
        }
    }

    return $fallback;
}

function ems_ai_psychiatry_sanitize_final(array $data): array
{
    $mseFields = ['appearance', 'behavior', 'speech', 'mood', 'affect', 'thought_process', 'thought_content', 'perception', 'insight', 'judgment', 'cognition', 'orientation'];
    $mseIn = is_array($data['mse'] ?? null) ? $data['mse'] : [];
    $mse = [];
    foreach ($mseFields as $field) {
        $mse[$field] = trim((string) ($mseIn[$field] ?? '-'));
    }

    $diagIn = is_array($data['diagnosis'] ?? null) ? $data['diagnosis'] : [];
    $differential = is_array($diagIn['differential'] ?? null) ? array_values(array_map('strval', $diagIn['differential'])) : [];
    $diagnosis = [
        'code' => trim((string) ($diagIn['code'] ?? '-')),
        'primary' => trim((string) ($diagIn['primary'] ?? '-')),
        'differential' => $differential,
    ];

    $riskIn = is_array($data['risk_assessment'] ?? null) ? $data['risk_assessment'] : [];
    $severityOptions = ems_ai_psychiatry_severity_options();
    $riskOptions = ems_ai_psychiatry_risk_options();
    $riskAssessment = [
        'severity' => ems_ai_psychiatry_normalize_enum((string) ($riskIn['severity'] ?? ''), $severityOptions, 'Sedang'),
        'suicide_risk' => ems_ai_psychiatry_normalize_enum((string) ($riskIn['suicide_risk'] ?? ''), $riskOptions, 'Rendah'),
        'violence_risk' => ems_ai_psychiatry_normalize_enum((string) ($riskIn['violence_risk'] ?? ''), $riskOptions, 'Rendah'),
        'self_harm_risk' => ems_ai_psychiatry_normalize_enum((string) ($riskIn['self_harm_risk'] ?? ''), $riskOptions, 'Rendah'),
    ];

    $treatmentPlan = is_array($data['treatment_plan'] ?? null) ? array_values(array_map('strval', $data['treatment_plan'])) : [];

    $medicationsIn = is_array($data['medications'] ?? null) ? $data['medications'] : [];
    $medications = [];
    foreach ($medicationsIn as $med) {
        if (!is_array($med)) {
            continue;
        }
        $medications[] = [
            'name' => trim((string) ($med['name'] ?? '-')),
            'dose' => trim((string) ($med['dose'] ?? '-')),
            'frequency' => trim((string) ($med['frequency'] ?? '-')),
            'duration' => trim((string) ($med['duration'] ?? '-')),
            'monitoring' => trim((string) ($med['monitoring'] ?? '-')),
        ];
    }

    return [
        'clinical_impressions' => ems_ai_psychiatry_sanitize_impressions($data['clinical_impressions'] ?? []),
        'mse' => $mse,
        'diagnosis' => $diagnosis,
        'risk_assessment' => $riskAssessment,
        'treatment_plan' => $treatmentPlan,
        'medications' => $medications,
        'clinical_summary' => trim((string) ($data['clinical_summary'] ?? '-')),
    ];
}

function ems_ai_psychiatry_generate_start(PDO $pdo, array $input, ?int $createdBy): array
{
    $result = ems_ai_ds_call_gemini($pdo, ems_ai_psychiatry_start_system_prompt(), ems_ai_psychiatry_build_start_user_prompt($input), 'ai_psychiatry_start', $createdBy);
    if (!$result['ok']) {
        return $result;
    }

    return ['ok' => true, 'data' => ems_ai_psychiatry_sanitize_start_or_next($result['data'], 'first_question')];
}

function ems_ai_psychiatry_generate_next(PDO $pdo, array $input, ?int $createdBy): array
{
    $result = ems_ai_ds_call_gemini($pdo, ems_ai_psychiatry_next_system_prompt(), ems_ai_psychiatry_build_next_user_prompt($input), 'ai_psychiatry_next', $createdBy);
    if (!$result['ok']) {
        return $result;
    }

    return ['ok' => true, 'data' => ems_ai_psychiatry_sanitize_start_or_next($result['data'], 'next_question')];
}

function ems_ai_psychiatry_generate_final(PDO $pdo, array $input, ?int $createdBy): array
{
    $result = ems_ai_ds_call_gemini($pdo, ems_ai_psychiatry_final_system_prompt(), ems_ai_psychiatry_build_final_user_prompt($input), 'ai_psychiatry_final', $createdBy);
    if (!$result['ok']) {
        return $result;
    }

    return ['ok' => true, 'data' => ems_ai_psychiatry_sanitize_final($result['data'])];
}
