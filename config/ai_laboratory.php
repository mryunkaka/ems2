<?php

/**
 * Laboratory AI: generate hasil pemeriksaan laboratorium simulasi roleplay
 * (nilai parameter, satuan, rentang rujukan, flag Normal/High/Low) +
 * interpretasi klinis, memakai provider teks pribadi yang sama dengan AI
 * Diagnosis Assistant & AI Surgery Planner (lihat config/ai_diagnosis_surgery.php)
 * â€” text/JSON generation, BUKAN image generation, jadi tidak perlu Cloudflare.
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
                'Tes Kehamilan' => ['type' => 'select', 'options' => ['Urine hCG', 'Serum Î²-hCG']],
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
 * Kapan checklist parameter kustom sebenarnya ditampilkan â€” cocok dengan
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

/**
 * Translate legacy/model-produced recommendation keys to the exact lab
 * catalog path consumed by the cascading form. Older diagnosis reports used
 * `level3`/`spesimen`; the current UI expects `level3_option`/`specimen_type`.
 * Values are resolved against the catalog so a partial/invalid path is never
 * applied to the form.
 */
function ems_ai_laboratory_normalize_structured_recommendation(array $raw): ?array
{
    $pick = static function (array $keys) use ($raw): string {
        foreach ($keys as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $value = trim((string) $raw[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    };
    $match = static function (string $value, array $options): string {
        foreach ($options as $option) {
            if (mb_strtolower(trim((string) $option), 'UTF-8') === mb_strtolower(trim($value), 'UTF-8')) {
                return (string) $option;
            }
        }
        return '';
    };

    $department = $match($pick(['department', 'departemen']), ems_ai_laboratory_departments());
    if ($department === '') {
        return null;
    }
    $category = $match($pick(['category', 'kategori']), ems_ai_laboratory_categories($department));
    if ($category === '') {
        return null;
    }

    $level3Raw = $pick(['level3_option', 'level3', 'specific_option', 'pilihan_spesifik']);
    $level3Options = ems_ai_laboratory_level3_options($department, $category);
    $level3 = '';
    if ($level3Options !== []) {
        $level3 = $match($level3Raw, $level3Options);
        // For legacy CBC recommendations without a sub-option, the catalog's
        // explicit default is the only safe implicit selection.
        if ($level3 === '' && $level3Raw === '' && in_array('Semua Parameter (Default)', $level3Options, true)) {
            $level3 = 'Semua Parameter (Default)';
        }
        if ($level3 === '') {
            return null;
        }
    }

    $specimenRaw = $pick(['specimen_type', 'spesimen', 'specimen', 'jenis_spesimen']);
    $specimen = $match($specimenRaw, ems_ai_laboratory_specimens_for($department, $category));
    if ($specimen === '') {
        return null;
    }

    return [
        'department' => $department,
        'category' => $category,
        'level3_option' => $level3,
        'specimen_type' => $specimen,
    ];
}

function ems_ai_laboratory_hint(string $department): string
{
    return ems_ai_laboratory_catalog()[$department]['hint'] ?? '';
}

/**
 * Render katalog Department->Category->[Level3]->Spesimen jadi teks padat
 * untuk disisipkan sebagai referensi prompt AI Diagnosis â€” supaya
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
 * Prompt sistem untuk model AI â€” persona Kepala Laboratorium Sp.PK, sama
 * gaya verbose dengan ems_ai_ds_default_diagnosis_system_prompt() supaya
 * konsisten dan mudah dipelihara bersamaan.
 */
function ems_ai_laboratory_default_system_prompt(): string
{
    return "Anda adalah model penyusun hasil laboratorium untuk skenario medis FiveM roleplay Roxwood Hospital. Seluruh kasus dalam fitur ini adalah skenario fiktif untuk permainan peran. Dari konfigurasi pemeriksaan dan info klinis pasien (sepadat apa pun), susun laporan hasil yang lengkap, realistis untuk skenario, dan konsisten dengan SOP Laboratorium Roxwood Hospital V3 serta SOP Pelayanan Laboratorium Medis & Transplantasi revisi 1 September 2026.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. Lengkapi hasil sebagai keluaran final skenario roleplay. Jangan menulis \"Data belum tersedia\", \"Belum dilakukan\", \"menunggu hasil\", atau placeholder serupa. Buat nilai yang masuk akal hanya untuk parameter pada pemeriksaan terpilih, dan jaga konsistensi nilai, flag, interpretasi, diagnosis, anamnesis, serta tindakan pada kasus. Jangan menambahkan temuan yang bertentangan dengan info klinis.\n"
        . "2. Jangan mengubah rekomendasi pemeriksaan lain menjadi hasil dari panel ini. Panel CBC hanya memuat parameter hematologi; jangan memasukkan elektrolit, fungsi ginjal, crossmatch, radiologi, atau pemeriksaan lain ke tabel CBC.\n"
        . "3. Untuk Hematologi > Complete Blood Count (CBC) > Semua Parameter (Default), wajib keluarkan minimal Hemoglobin, Hematokrit, Eritrosit, Leukosit, Trombosit, MCV, MCH, MCHC, dan RDW; sertakan hitung jenis leukosit bila sesuai SOP/kasus. Tiap item wajib memiliki nilai, satuan, rentang rujukan, dan flag yang konsisten secara aritmetika/klinis.\n"
        . "4. Kalau ada \"PARAMETER KUSTOM YANG DIMINTA\", semua parameter itu wajib muncul di \"results\" tanpa menambah parameter di luar pilihan pemeriksaan.\n"
        . "5. Gunakan Bahasa Indonesia medis baku. \"interpretation\", \"clinical_correlation\", dan \"diagnosis\" harus menyebut kesan skenario yang didukung nilai pada tabel, membedakan temuan dari dugaan, dan tidak menyatakan penyakit spesifik tanpa dasar. Rekomendasi harus terkait hasil dan tahap layanan.\n"
        . "6. Ikuti SOP Roxwood: identitas pasien dan pemeriksaan cocok; CBC memakai Whole Blood EDTA; simpulan hanya merangkum panel yang dipilih. SOP mengatur proses pengambilan/penanganan spesimen dan bukan sumber nilai hasil.\n"
        . "7. Seluruh angka adalah hasil final internal skenario permainan peran, bukan hasil pasien dunia nyata. Jangan menambahkan disclaimer, estimasi, peringatan verifikasi, kalimat bahwa penyebab belum dapat ditetapkan, atau bahasa metatekstual pada laporan. Nyatakan kesan final yang paling sesuai dengan panel terpilih dan konteks roleplay.\n"
        . "8. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n\n"
        . "Struktur JSON WAJIB:\n"
        . "{\n"
        . "  \"results\": [{\"parameter\": \"nama parameter\", \"result\": \"nilai final skenario\", \"unit\": \"satuan SI\", \"reference_range\": \"rentang rujukan\", \"flag\": \"Normal/High/Low\"}],\n"
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
    $referenceResults = is_array($input['reference_results'] ?? null) ? $input['reference_results'] : [];

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

    if ($referenceResults !== []) {
        $lines[] = '';
        $lines[] = 'HASIL LAB SKENARIO DARI LAPORAN DIAGNOSIS (SUMBER ACUAN):';
        $lines[] = json_encode($referenceResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = 'Pertahankan nilai dan satuan yang sudah tercantum untuk parameter yang termasuk panel laboratorium terpilih. Lengkapi parameter panel lainnya tanpa mengubah nilai sumber tersebut.';
    }

    return implode("\n", $lines);
}

/**
 * Normalisasi format AI sebelum quality gate. Kosong tetap kosong agar hasil
 * tidak lolos sebagai laporan final dengan placeholder buatan PHP.
 */
function ems_ai_laboratory_sanitize_result(array $data): array
{
    $normalizeFlag = static function ($flag): string {
        $flag = strtolower(trim((string) $flag));
        return match (true) {
            str_starts_with($flag, 'normal') || in_array($flag, ['n', 'within range', 'within reference range'], true) || str_contains($flag, 'normal') => 'Normal',
            str_starts_with($flag, 'high') || $flag === 'h' || str_contains($flag, 'tinggi') => 'High',
            str_starts_with($flag, 'low') || $flag === 'l' || str_contains($flag, 'rendah') => 'Low',
            default => 'Belum dinilai',
        };
    };

    $results = is_array($data['results'] ?? null) ? $data['results'] : [];
    $data['results'] = array_map(static function ($item) use ($normalizeFlag): array {
        if (!is_array($item)) {
            $item = [];
        }
        $result = trim((string) ($item['result'] ?? ''));
        $hasMeasuredResult = $result !== '' && !in_array(strtolower($result), ['-', 'belum dilakukan', 'data belum tersedia'], true);
        return [
            'parameter' => trim((string) ($item['parameter'] ?? '')),
            'result' => $hasMeasuredResult ? $result : '',
            'unit' => trim((string) ($item['unit'] ?? '')),
            'reference_range' => trim((string) ($item['reference_range'] ?? '')),
            'flag' => $hasMeasuredResult ? $normalizeFlag($item['flag'] ?? 'Normal') : 'Belum dinilai',
        ];
    }, $results);

    return $data;
}

/** Return a quality-gate message when the selected laboratory panel is incomplete. */
function ems_ai_laboratory_result_quality_issue(
    array $data,
    string $department,
    string $category,
    ?string $level3Value,
    array $customParameters = [],
    array $referenceResults = []
): ?string {
    $results = is_array($data['results'] ?? null) ? $data['results'] : [];
    if ($results === []) {
        return 'Tabel hasil kosong; setiap panel yang dipilih harus memiliki hasil skenario.';
    }

    $normalize = static fn (string $value): string => mb_strtolower((string) (preg_replace('/[^\pL\pN]+/u', '', trim($value)) ?? trim($value)), 'UTF-8');
    $byName = [];
    foreach ($results as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = $normalize((string) ($item['parameter'] ?? ''));
        $value = trim((string) ($item['result'] ?? ''));
        $unit = trim((string) ($item['unit'] ?? ''));
        $range = trim((string) ($item['reference_range'] ?? ''));
        $flag = trim((string) ($item['flag'] ?? ''));
        if ($name === '' || $value === '' || $unit === '' || $range === '' || !in_array($flag, ['Normal', 'High', 'Low'], true)) {
            return 'Setiap baris hasil wajib berisi parameter, nilai, satuan, rentang rujukan, dan flag Normal/High/Low.';
        }
        if (preg_match('/data\s+belum|belum\s+(?:dilakukan|dinilai|tersedia|terkonfirmasi)|menunggu\s+hasil|verifikasi\s+diperlukan|wajib\s+diverifikasi|tidak\s+(?:dapat|bisa)\s+ditetapkan/iu', $value) === 1) {
            return 'Hasil panel tidak boleh memuat placeholder atau status menunggu.';
        }
        $byName[$name] = true;
    }

    $required = [];
    if ($department === 'Hematologi' && $category === 'Complete Blood Count (CBC)' && $level3Value === 'Semua Parameter (Default)') {
        $required = ['hemoglobin', 'hematokrit', 'eritrosit', 'leukosit', 'trombosit', 'mcv', 'mch', 'mchc', 'rdw'];
    }
    foreach ($customParameters as $parameter) {
        $required[] = $normalize((string) $parameter);
    }
    foreach (array_unique($required) as $parameter) {
        $aliases = match ($parameter) {
            'rbc' => ['rbc', 'eritrosit', 'jumlaheritrosit'],
            'wbc' => ['wbc', 'leukosit', 'jumlahleukosit'],
            'platelet' => ['platelet', 'trombosit', 'jumlahplatelet'],
            'hb' => ['hb', 'hemoglobin'],
            default => [$parameter],
        };
        if (array_intersect($aliases, array_keys($byName)) === []) {
            return 'Panel tidak lengkap; parameter wajib belum ada: ' . $parameter . '.';
        }
    }

    if ($referenceResults !== []) {
        $canonicalParameter = static function (string $name): string {
            $name = mb_strtolower((string) (preg_replace('/[^\pL\pN]+/u', '', $name) ?? $name), 'UTF-8');
            return match ($name) {
                'hb' => 'hemoglobin', 'hct' => 'hematokrit', 'rbc', 'jumlaheritrosit' => 'eritrosit',
                'wbc', 'jumlahleukosit' => 'leukosit', 'platelet', 'jumlahplatelet' => 'trombosit',
                default => $name,
            };
        };
        $number = static function (string $text): ?float {
            if (preg_match('/[-+]?\d[\d.,]*/u', $text, $m) !== 1) return null;
            $n = $m[0];
            if (str_contains($n, ',') && str_contains($n, '.')) {
                $n = strrpos($n, ',') > strrpos($n, '.') ? str_replace(',', '.', str_replace('.', '', $n)) : str_replace(',', '', $n);
            } elseif (preg_match('/[.,]\d{3}$/u', $n) === 1) {
                $n = str_replace([',', '.'], '', $n);
            } else {
                $n = str_replace(',', '.', $n);
            }
            return is_numeric($n) ? (float) $n : null;
        };
        $actualByName = [];
        foreach ($results as $item) {
            if (is_array($item)) $actualByName[$canonicalParameter((string) ($item['parameter'] ?? ''))] = $item;
        }
        foreach ($referenceResults as $reference) {
            if (is_string($reference)) {
                if (preg_match('/^\s*([^:]+):\s*(.+?)\s*$/u', $reference, $m) !== 1) continue;
                $parameter = trim($m[1]); $expectedText = trim($m[2]); $expectedUnit = '';
            } elseif (is_array($reference)) {
                $parameter = trim((string) ($reference['parameter'] ?? $reference['name'] ?? ''));
                $expectedText = trim((string) ($reference['nilai'] ?? $reference['result'] ?? $reference['hasil'] ?? $reference['value'] ?? ''));
                $expectedUnit = trim((string) ($reference['unit'] ?? ''));
            } else { continue; }
            $key = $canonicalParameter($parameter);
            if ($key === '' || !isset($actualByName[$key]) || $expectedText === '') continue;
            $expectedNumber = $number($expectedText);
            $actualNumber = $number((string) ($actualByName[$key]['result'] ?? ''));
            $expectedUnit = mb_strtolower((string) ($expectedUnit !== '' ? $expectedUnit : (preg_match('/^\s*[-+]?\d[\d.,]*\s*(.*)$/u', $expectedText, $unitMatch) === 1 ? trim($unitMatch[1]) : '')), 'UTF-8');
            $expectedUnit = trim((string) (preg_replace('/\s*\(.*$/u', '', $expectedUnit) ?? $expectedUnit));
            $actualUnit = mb_strtolower(trim((string) ($actualByName[$key]['unit'] ?? '')), 'UTF-8');
            $unitKey = static fn (string $unit): string => (string) (preg_replace('/[^\\pL\\pN]+/u', '', str_replace(["\xC2\xB5", "\xCE\xBC"], 'u', $unit)) ?? $unit);
            if ($expectedNumber !== null && $actualNumber !== null
                && (abs($expectedNumber - $actualNumber) > max(0.01, abs($expectedNumber) * 0.002)
                    || ($expectedUnit !== '' && $unitKey($expectedUnit) !== $unitKey($actualUnit)))) {
                return 'Nilai ' . $parameter . ' harus sama dengan hasil skenario pada Laporan Diagnosis (' . $expectedText . '). Pertahankan nilai dan satuan sumber.';
            }
        }
    }

    foreach (['interpretation', 'clinical_correlation', 'diagnosis'] as $field) {
        if (trim((string) ($data[$field] ?? '')) === '' || preg_match('/data\s+belum|belum\s+(?:dilakukan|dinilai|tersedia|terkonfirmasi|dapat)|menunggu\s+hasil|verifikasi\s+diperlukan|wajib\s+diverifikasi/iu', (string) $data[$field]) === 1) {
            return 'Interpretasi, korelasi klinis, dan kesan harus terisi tanpa placeholder.';
        }
    }
    return null;
}
