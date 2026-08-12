<?php

/**
 * Laboratory AI: generate hasil pemeriksaan laboratorium simulasi roleplay
 * (nilai parameter, satuan, rentang rujukan, flag Normal/High/Low) +
 * interpretasi klinis, memakai API key Gemini pribadi yang sama dengan AI
 * Diagnosis Assistant & AI Surgery Planner (lihat config/ai_diagnosis_surgery.php)
 * — text/JSON generation, BUKAN image generation, jadi tidak perlu Cloudflare.
 */

require_once __DIR__ . '/ai_diagnosis_surgery.php';

function ems_ai_laboratory_ensure_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    if (!ems_table_exists($pdo, 'ai_laboratory_results')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ai_laboratory_results` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `report_code` VARCHAR(40) NULL,
                `user_id` INT NOT NULL,
                `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
                `division_snapshot` VARCHAR(60) NULL,
                `patient_name` VARCHAR(150) NULL,
                `patient_dob` DATE NULL,
                `patient_citizen_id` VARCHAR(50) NULL,
                `doctor_name` VARCHAR(150) NULL,
                `department` VARCHAR(100) NOT NULL,
                `category` VARCHAR(100) NOT NULL,
                `level3_option` VARCHAR(100) NULL,
                `custom_parameters` TEXT NULL,
                `specimen_type` VARCHAR(150) NOT NULL,
                `clinical_info` TEXT NOT NULL,
                `result_json` LONGTEXT NULL,
                `status` ENUM('done','error') NOT NULL DEFAULT 'done',
                `error_message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_ai_laboratory_report_code` (`report_code`),
                KEY `idx_ai_laboratory_user` (`user_id`),
                KEY `idx_ai_laboratory_unit_created` (`unit_code`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    // Guard "1 kode referensi hanya boleh dipakai 1x per halaman", migration 66.
    if (!ems_column_exists($pdo, 'ai_laboratory_results', 'source_report_code')) {
        $pdo->exec("ALTER TABLE `ai_laboratory_results` ADD COLUMN `source_report_code` VARCHAR(40) NULL AFTER `clinical_info`");
    }
}

/**
 * Katalog 13 departemen laboratorium. Struktur per departemen:
 * - hint: teks bantuan ditampilkan di form
 * - specimens: daftar default jenis spesimen departemen (dipakai kalau
 *   kategori yang dipilih tidak override specimens-nya sendiri)
 * - categories: {nama kategori => {
 *     type: 'select' (ada sub-opsi Level 3) | 'none' (tidak ada),
 *     options: [...] (kalau type=select),
 *     custom: [...] (checklist parameter kustom, kalau kategori/opsi tertentu memicunya),
 *     specimens: [...] (override specimens khusus kategori ini, kalau ada)
 *   }}
 */
function ems_ai_laboratory_catalog(): array
{
    return [
        'Hematologi' => [
            'hint' => 'Sediaan untuk pemeriksaan darah rutin lengkap & morfologi sel darah.',
            'specimens' => ['Whole Blood EDTA', 'Darah Kapiler (Microtainer)'],
            'categories' => [
                'Complete Blood Count (CBC)' => [
                    'type' => 'select',
                    'options' => ['Semua Parameter (Default)', 'Custom Parameter'],
                    'custom' => ['Hemoglobin', 'Hematokrit', 'RBC', 'WBC', 'Platelet', 'MCV', 'MCH', 'MCHC', 'RDW'],
                ],
                'Hitung Jenis Leukosit' => ['type' => 'none'],
                'Laju Endap Darah (LED)' => ['type' => 'none'],
                'Retikulosit' => ['type' => 'none'],
                'Apusan Darah Tepi' => ['type' => 'none'],
                'Golongan Darah & Rh' => ['type' => 'none'],
            ],
        ],
        'Kimia Klinik' => [
            'hint' => 'Pemeriksaan fungsi organ metabolik dan kimiawi tubuh.',
            'specimens' => ['Serum', 'Plasma Heparin', 'Urine 24 Jam', 'Cairan Tubuh (Pleura/Ascites)'],
            'categories' => [
                'Glukosa' => ['type' => 'none'],
                'Fungsi Ginjal' => ['type' => 'select', 'options' => ['Ureum', 'Kreatinin', 'eGFR', 'BUN']],
                'Fungsi Hati' => ['type' => 'none'],
                'Profil Lipid' => ['type' => 'none'],
                'Elektrolit' => ['type' => 'none'],
                'Asam Urat' => ['type' => 'none'],
                'Enzim Jantung' => ['type' => 'none'],
                'Pankreas' => ['type' => 'none'],
            ],
        ],
        'Urinalisis' => [
            'hint' => 'Pemeriksaan kondisi ginjal, ISK, dan saringan metabolisme urin.',
            'specimens' => ['Urine Porsi Tengah (Midstream)', 'Urine Pagi', 'Urine 24 Jam', 'Urine Kateter'],
            'categories' => [
                'Urinalisis Lengkap' => [
                    'type' => 'select',
                    'options' => ['Lengkap (Default)', 'Makroskopis', 'Kimia', 'Sedimen'],
                    'custom' => ['Protein', 'Glukosa', 'Nitrit', 'Leukosit', 'Keton', 'Bilirubin', 'Darah', 'pH', 'Berat Jenis'],
                ],
                'Tes Kehamilan' => ['type' => 'select', 'options' => ['Urine hCG', 'Serum β-hCG']],
                'Drug Screening Urine' => [
                    'type' => 'select',
                    'options' => ['5 Panel', '10 Panel', '15 Panel', 'Custom'],
                    'custom' => ['THC', 'Amphetamine', 'Methamphetamine', 'Cocaine', 'Morphine', 'Heroin', 'Fentanyl', 'MDMA', 'Ketamine', 'Benzodiazepine', 'Methadone', 'Barbiturat', 'Tramadol'],
                ],
                'Protein Urine' => ['type' => 'none'],
                'Mikroalbumin' => ['type' => 'none'],
                'Sedimen Urine' => ['type' => 'none'],
            ],
        ],
        'Imunologi & Serologi' => [
            'hint' => 'Analisis antibodi, antigen infeksius, dan biomarker imunologis.',
            'specimens' => ['Serum', 'Plasma EDTA', 'Plasma Heparin'],
            'categories' => [
                'Demam' => ['type' => 'select', 'options' => ['Dengue NS1', 'IgM Dengue', 'IgG Dengue', 'Typhidot', 'Widal', 'Malaria', 'Leptospira']],
                'Hepatitis' => ['type' => 'none'],
                'HIV' => ['type' => 'none'],
                'Autoimun' => ['type' => 'none'],
                'TORCH' => ['type' => 'none'],
                'COVID-19' => ['type' => 'none'],
            ],
        ],
        'Mikrobiologi' => [
            'hint' => 'Pembiakan kuman patogen dan uji sensitivitas antibiotika.',
            'specimens' => ['Darah (Kultur Aerobic/Anaerobic)', 'Urine Porsi Tengah', 'Feses Segar', 'Swab Tenggorokan/Hidung', 'Sputum', 'Pus / Eksudat Luka', 'Cairan Tubuh'],
            'categories' => [
                'Kultur Darah' => ['type' => 'none'],
                'Kultur Urine' => ['type' => 'select', 'options' => ['Kultur', 'Gram Stain', 'Antibiotic Sensitivity']],
                'Kultur Luka' => ['type' => 'none'],
                'Kultur Sputum' => ['type' => 'none'],
                'Kultur Feses' => ['type' => 'none'],
                'Swab Tenggorokan' => ['type' => 'none'],
            ],
        ],
        'Patologi Anatomi' => [
            'hint' => 'Pemeriksaan sitologi dan histopatologi sampel jaringan seluler.',
            'specimens' => ['Jaringan Biopsi (dalam Formalin)', 'Cairan Aspirasi / FNAB', 'Smear / Hapusan (Pap Smear)'],
            'categories' => [
                'Histopatologi' => ['type' => 'select', 'options' => ['Kulit', 'Payudara', 'Serviks', 'Kolon', 'Lambung', 'Paru', 'Hati', 'Ginjal', 'Otak']],
                'Sitologi' => ['type' => 'none'],
                'FNAB' => ['type' => 'none'],
                'Pap Smear' => ['type' => 'none'],
            ],
        ],
        'Patologi Klinik' => [
            'hint' => 'Analisis biomarker spesifik tumor, hormon metabolik rutin, dan sejenisnya.',
            'specimens' => ['Serum', 'Plasma', 'Urine', 'Cairan Pleura', 'Cairan Serebrospinal (CSF)'],
            'categories' => [
                'Biomarker Tumor' => ['type' => 'none'],
                'Pemeriksaan Hormon' => ['type' => 'select', 'options' => ['TSH', 'FT4', 'FSH', 'LH', 'Estradiol', 'Progesteron', 'Testosteron', 'Prolaktin']],
                'Analisis Cairan Tubuh' => ['type' => 'none'],
                'Elektroforesis' => ['type' => 'none'],
                'Imunofiksasi' => ['type' => 'none'],
            ],
        ],
        'Toksikologi' => [
            'hint' => 'Pengujian tingkat racun, penyalahgunaan obat, logam berat, dan paparan zat kimia berbahaya.',
            'categories' => [
                'Poison Screening' => ['type' => 'none', 'specimens' => ['Isi Lambung / Bilasan (Gastric Lavage)', 'Whole Blood EDTA (Darah Lengkap)', 'Urine Porsi Tengah']],
                'Drug Screening' => [
                    'type' => 'select',
                    'options' => ['5 Panel', '10 Panel', 'Custom'],
                    'custom' => ['THC', 'Amphetamine', 'Methamphetamine', 'Cocaine', 'Morphine', 'Heroin', 'Fentanyl', 'MDMA', 'Ketamine', 'Benzodiazepine', 'Methadone', 'Barbiturat', 'Tramadol'],
                    'specimens' => ['Urine Porsi Tengah', 'Whole Blood EDTA', 'Rambut (Hair Sample)'],
                ],
                'Chemical Analysis' => ['type' => 'none', 'specimens' => ['Serum', 'Plasma Heparin', 'Urine 24 Jam']],
                'Heavy Metal' => ['type' => 'none', 'specimens' => ['Whole Blood Heparin (Bebas Logam)', 'Rambut (Hair Sample)', 'Urine 24 Jam']],
                'Food Toxicology' => ['type' => 'none', 'specimens' => ['Sampel Muntahan (Vomitus)', 'Sisa Sampel Makanan / Minuman', 'Whole Blood EDTA']],
            ],
        ],
        'Bank Darah' => [
            'hint' => 'Layanan tipe darah dan kecocokan sediaan transfusi plasma.',
            'specimens' => ['Whole Blood EDTA', 'Serum (Non-aktif)'],
            'categories' => [
                'Golongan Darah' => ['type' => 'none'],
                'Crossmatch' => ['type' => 'none'],
                'Antibody Screening' => ['type' => 'none'],
            ],
        ],
        'Koagulasi' => [
            'hint' => 'Pengukuran waktu respons bekuan sirkulasi plasma darah.',
            'specimens' => ['Plasma Sitrat (Tabung Biru)'],
            'categories' => [
                'PT' => ['type' => 'none'],
                'aPTT' => ['type' => 'none'],
                'INR' => ['type' => 'none'],
                'D-Dimer' => ['type' => 'none'],
                'Fibrinogen' => ['type' => 'none'],
            ],
        ],
        'Molekuler (PCR)' => [
            'hint' => 'Analisis sekuensing replikasi rantai DNA/RNA patogen dengan presisi.',
            'specimens' => ['Swab Nasofaring / Orofaring (VTM)', 'Serum', 'Plasma', 'Sputum', 'Cairan Serebrospinal (CSF)'],
            'categories' => [
                'COVID PCR' => ['type' => 'none'],
                'HIV Viral Load' => ['type' => 'none'],
                'HBV DNA' => ['type' => 'none'],
                'HCV RNA' => ['type' => 'none'],
                'HPV DNA' => ['type' => 'none'],
                'TB PCR' => ['type' => 'none'],
            ],
        ],
        'Parasitologi' => [
            'hint' => 'Identifikasi langsung mikroskopis dan serologi parasit patogen.',
            'specimens' => ['Feses Segar', 'Whole Blood EDTA', 'Urine'],
            'categories' => [
                'Malaria' => ['type' => 'none'],
                'Cacing' => ['type' => 'none'],
                'Protozoa' => ['type' => 'none'],
                'Parasit Darah' => ['type' => 'none'],
            ],
        ],
        'Analisis Feses' => [
            'hint' => 'Pemeriksaan makroskopis, mikroskopis, sisa pencernaan feses lengkap.',
            'specimens' => ['Feses Segar', 'Swab Rektal'],
            'categories' => [
                'Feses Lengkap' => ['type' => 'none'],
                'FOBT' => ['type' => 'none'],
                'Parasit' => ['type' => 'none'],
                'Kultur Feses' => ['type' => 'none'],
            ],
        ],
    ];
}

function ems_ai_laboratory_departments(): array
{
    return array_keys(ems_ai_laboratory_catalog());
}

function ems_ai_laboratory_categories(string $department): array
{
    return array_keys(ems_ai_laboratory_catalog()[$department]['categories'] ?? []);
}

function ems_ai_laboratory_category_info(string $department, string $category): ?array
{
    return ems_ai_laboratory_catalog()[$department]['categories'][$category] ?? null;
}

function ems_ai_laboratory_level3_options(string $department, string $category): array
{
    $info = ems_ai_laboratory_category_info($department, $category);
    return ($info && ($info['type'] ?? '') === 'select') ? ($info['options'] ?? []) : [];
}

function ems_ai_laboratory_custom_params(string $department, string $category): array
{
    $info = ems_ai_laboratory_category_info($department, $category);
    return $info['custom'] ?? [];
}

/**
 * Kapan checklist parameter kustom sebenarnya ditampilkan — cocok dengan
 * logika di reference: hanya untuk kombinasi kategori+opsi level-3 tertentu
 * (bukan setiap kategori yang punya daftar 'custom').
 */
function ems_ai_laboratory_custom_trigger_options(): array
{
    return [
        'Complete Blood Count (CBC)' => ['Custom Parameter'],
        'Urinalisis Lengkap' => ['Kimia'],
        'Drug Screening Urine' => ['Custom'],
        'Drug Screening' => ['Custom'],
    ];
}

function ems_ai_laboratory_should_show_custom_params(string $category, string $level3Value): bool
{
    $triggers = ems_ai_laboratory_custom_trigger_options();
    return in_array($level3Value, $triggers[$category] ?? [], true);
}

/**
 * Resolusi jenis spesimen: kategori bisa override specimens departemen.
 */
function ems_ai_laboratory_specimens_for(string $department, ?string $category = null): array
{
    $dept = ems_ai_laboratory_catalog()[$department] ?? null;
    if (!$dept) {
        return [];
    }

    if ($category !== null) {
        $catInfo = $dept['categories'][$category] ?? null;
        if ($catInfo && !empty($catInfo['specimens'])) {
            return $catInfo['specimens'];
        }
    }

    return $dept['specimens'] ?? [];
}

function ems_ai_laboratory_hint(string $department): string
{
    return ems_ai_laboratory_catalog()[$department]['hint'] ?? '';
}

/**
 * Render katalog Department->Category->[Level3]->Spesimen jadi teks padat
 * untuk disisipkan sebagai referensi prompt AI Diagnosis — supaya
 * rekomendasi laboratorium yang dihasilkan (field "laboratorium_terstruktur")
 * PERSIS cocok dengan salah satu kombinasi valid di Laboratory AI, bukan
 * karangan bebas yang tidak bisa dipetakan ke dropdown manapun. Sama pola
 * dengan ems_ai_radiology_catalog_reference_text().
 */
function ems_ai_laboratory_catalog_reference_text(): string
{
    $lines = [];
    foreach (ems_ai_laboratory_catalog() as $department => $deptInfo) {
        foreach ($deptInfo['categories'] as $category => $catInfo) {
            $specimens = ems_ai_laboratory_specimens_for($department, $category);
            $specimenText = 'Spesimen: [' . implode(', ', $specimens) . ']';

            if (($catInfo['type'] ?? '') === 'select') {
                $options = $catInfo['options'] ?? [];
                $lines[] = "{$department} > {$category} > [" . implode(', ', $options) . "] > {$specimenText}";
            } else {
                $lines[] = "{$department} > {$category} > (tanpa opsi level3) > {$specimenText}";
            }
        }
    }

    return implode("\n", $lines);
}

function ems_ai_laboratory_is_valid_selection(string $department, string $category, ?string $level3Value, string $specimen): bool
{
    $catInfo = ems_ai_laboratory_category_info($department, $category);
    if ($catInfo === null) {
        return false;
    }

    if (($catInfo['type'] ?? '') === 'select') {
        if ($level3Value === null || $level3Value === '' || !in_array($level3Value, $catInfo['options'] ?? [], true)) {
            return false;
        }
    }

    return in_array($specimen, ems_ai_laboratory_specimens_for($department, $category), true);
}

/**
 * Kode referensi unik per hasil laboratorium, sama pola dengan
 * ems_ai_ds_generate_report_code() di config/ai_diagnosis_surgery.php.
 */
function ems_ai_laboratory_generate_report_code(): string
{
    return 'LAB-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function ems_ai_laboratory_find_result_by_code(PDO $pdo, string $code, string $unitCode): ?array
{
    $code = trim($code);
    if ($code === '' || !ems_column_exists($pdo, 'ai_laboratory_results', 'report_code')) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT * FROM ai_laboratory_results
        WHERE report_code = ? AND unit_code = ? AND status = 'done'
        LIMIT 1
    ");
    $stmt->execute([$code, $unitCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Prompt sistem untuk model AI — persona Kepala Laboratorium Sp.PK, sama
 * gaya verbose dengan ems_ai_ds_default_diagnosis_system_prompt() supaya
 * konsisten dan mudah dipelihara bersamaan.
 */
function ems_ai_laboratory_default_system_prompt(): string
{
    return "Anda adalah Kepala Laboratorium Roxwood Hospital bergelar Dokter Spesialis Patologi Klinik (Sp.PK) dengan pengalaman lebih dari 15 tahun, menyusun hasil pemeriksaan laboratorium untuk simulasi/roleplay EMS. Tugas Anda: dari konfigurasi pemeriksaan dan info klinis pasien (sepadat apa pun), susun hasil laboratorium yang LENGKAP, realistis, dan konsisten secara medis.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. JANGAN PERNAH menolak atau meminta data tambahan. Hasilkan SENDIRI nilai parameter, satuan (SI units baku), dan rentang rujukan (reference range) yang akurat sesuai standar medis internasional untuk pemeriksaan yang diminta.\n"
        . "2. Field \"flag\" pada setiap parameter WAJIB PERSIS salah satu dari tiga string: \"Normal\", \"High\", atau \"Low\" (bukan variasi lain, bukan bahasa Indonesia, huruf besar-kecil sesuai contoh).\n"
        . "3. Hasil parameter WAJIB konsisten dengan info klinis pasien — kalau ada indikasi penyakit tertentu, berikan flag \"High\"/\"Low\" pada parameter yang secara medis relevan menunjang kondisi tersebut, sisanya \"Normal\". JANGAN membuat seluruh parameter normal jika info klinis jelas menunjukkan patologi.\n"
        . "4. Kalau ada \"PARAMETER KUSTOM YANG DIMINTA\" pada instruksi user, SEMUA parameter itu WAJIB muncul di \"results\" — jangan ada yang terlewat, jangan menambah parameter di luar yang diminta kalau daftar kustom itu ada.\n"
        . "5. \"interpretation\", \"clinical_correlation\", \"diagnosis\" WAJIB bahasa Indonesia medis baku, profesional, dan terstruktur — bukan daftar poin, tapi kalimat/paragraf padat.\n"
        . "6. \"diagnosis\" adalah kesan/kesimpulan patologi (suspek klinis) berdasarkan hasil lab, bukan sekadar mengulang info klinis yang diberikan user.\n"
        . "7. \"recommendations\" berisi 2-5 rekomendasi tindak lanjut konkret (mis. pemeriksaan lanjutan, kontrol, terapi awal) yang logis mengikuti hasil dan diagnosis.\n"
        . "8. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n\n"
        . "Struktur JSON WAJIB:\n"
        . "{\n"
        . "  \"results\": [{\"parameter\": \"nama parameter\", \"result\": \"nilai konkret\", \"unit\": \"satuan SI\", \"reference_range\": \"rentang rujukan\", \"flag\": \"Normal atau High atau Low\"}],\n"
        . "  \"interpretation\": \"interpretasi hasil laboratorium\",\n"
        . "  \"clinical_correlation\": \"korelasi dengan kondisi klinis pasien\",\n"
        . "  \"diagnosis\": \"kesan/kesimpulan patologi\",\n"
        . "  \"recommendations\": [\"rekomendasi 1\", \"rekomendasi 2\"]\n"
        . "}";
}

function ems_ai_laboratory_build_user_prompt(array $input): string
{
    $department = trim((string) ($input['department'] ?? ''));
    $category = trim((string) ($input['category'] ?? ''));
    $level3 = trim((string) ($input['level3_option'] ?? ''));
    $customParams = is_array($input['custom_parameters'] ?? null) ? $input['custom_parameters'] : [];
    $specimen = trim((string) ($input['specimen_type'] ?? ''));
    $clinicalInfo = trim((string) ($input['clinical_info'] ?? ''));

    $lines = [
        'Departemen: ' . $department,
        'Kategori Pemeriksaan: ' . $category . ($level3 !== '' ? ' (Pilihan spesifik: ' . $level3 . ')' : ''),
        'Jenis Spesimen: ' . $specimen,
    ];

    if ($customParams !== []) {
        $lines[] = 'PARAMETER KUSTOM YANG DIMINTA (wajib semua muncul di hasil): ' . implode(', ', $customParams);
    }

    $lines[] = '';
    $lines[] = 'Info Klinis / Anamnesis / Diagnosis Pasien: ' . ($clinicalInfo !== '' ? $clinicalInfo : '(tidak ada info tambahan)');

    return implode("\n", $lines);
}

/**
 * Validasi & bersihkan hasil AI sebelum disimpan — terutama field "flag"
 * yang WAJIB persis Normal/High/Low untuk keperluan styling tampilan;
 * kalau model membalas variasi lain (mis. "Tinggi", "H"), normalisasi ke
 * bentuk baku alih-alih menyimpan nilai yang tidak dikenali.
 */
function ems_ai_laboratory_sanitize_result(array $data): array
{
    $normalizeFlag = static function ($flag): string {
        $flag = strtolower(trim((string) $flag));
        return match (true) {
            str_starts_with($flag, 'high') || $flag === 'h' || str_contains($flag, 'tinggi') => 'High',
            str_starts_with($flag, 'low') || $flag === 'l' || str_contains($flag, 'rendah') => 'Low',
            default => 'Normal',
        };
    };

    $results = is_array($data['results'] ?? null) ? $data['results'] : [];
    $data['results'] = array_map(static function ($item) use ($normalizeFlag): array {
        if (!is_array($item)) {
            $item = [];
        }
        return [
            'parameter' => trim((string) ($item['parameter'] ?? '-')),
            'result' => trim((string) ($item['result'] ?? '-')),
            'unit' => trim((string) ($item['unit'] ?? '')),
            'reference_range' => trim((string) ($item['reference_range'] ?? '-')),
            'flag' => $normalizeFlag($item['flag'] ?? 'Normal'),
        ];
    }, $results);

    return $data;
}
