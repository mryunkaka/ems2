<?php

/**
 * Radiology Center: generate citra pencitraan medis (X-Ray/CT/MRI/USG) untuk
 * simulasi/roleplay EMS, memakai model Gemini yang mendukung image generation.
 * Memakai provider teks pribadi yang sama dengan AI Diagnosis Assistant & AI
 * Surgery Planner (tabel user_ai_settings) — lihat config/ai_diagnosis_surgery.php.
 * Model image-generation TIDAK bisa dipilih user (beda kontrak response dari
 * model teks biasa), jadi di-hardcode di sini, bukan lewat default_model milik
 * user_ai_settings.
 */

require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/ai_diagnosis_surgery.php';
require_once __DIR__ . '/cloudflare_settings.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';
require_once __DIR__ . '/../actions/cloudflare_client.php';

function ems_ai_radiology_ensure_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    if (!ems_table_exists($pdo, 'ai_radiology_images')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ai_radiology_images` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
                `division_snapshot` VARCHAR(60) NULL,
                `patient_name` VARCHAR(150) NULL,
                `patient_dob` DATE NULL,
                `patient_citizen_id` VARCHAR(50) NULL,
                `doctor_name` VARCHAR(150) NULL,
                `anamnesis` TEXT NULL,
                `modality` VARCHAR(30) NOT NULL,
                `category` VARCHAR(100) NOT NULL,
                `body_region` VARCHAR(100) NOT NULL,
                `projection` VARCHAR(100) NOT NULL,
                `clinical_finding` VARCHAR(100) NOT NULL,
                `prompt_used` TEXT NULL,
                `image_path` VARCHAR(255) NULL,
                `image_source_label` VARCHAR(120) NULL,
                `status` ENUM('done','error') NOT NULL DEFAULT 'done',
                `error_message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ai_radiology_user` (`user_id`),
                KEY `idx_ai_radiology_unit_created` (`unit_code`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    // Laporan bacaan radiologi formal (Findings/Diagnosis/Recommendations +
    // teks laporan terstruktur), migration 64 — kolom terpisah dari
    // status/error_message citra supaya laporan teks & citra independen.
    if (!ems_column_exists($pdo, 'ai_radiology_images', 'report_findings')) {
        $pdo->exec("
            ALTER TABLE `ai_radiology_images`
                ADD COLUMN `report_findings` TEXT NULL AFTER `image_path`,
                ADD COLUMN `report_diagnosis` TEXT NULL AFTER `report_findings`,
                ADD COLUMN `report_recommendations` TEXT NULL AFTER `report_diagnosis`,
                ADD COLUMN `report_text` LONGTEXT NULL AFTER `report_recommendations`,
                ADD COLUMN `report_status` ENUM('done','error') NULL AFTER `report_text`,
                ADD COLUMN `report_error_message` TEXT NULL AFTER `report_status`
        ");
    }

    // Guard "1 kode referensi hanya boleh dipakai 1x per halaman", migration 66.
    if (!ems_column_exists($pdo, 'ai_radiology_images', 'source_report_code')) {
        $pdo->exec("ALTER TABLE `ai_radiology_images` ADD COLUMN `source_report_code` VARCHAR(40) NULL AFTER `anamnesis`");
    }
    if (!ems_column_exists($pdo, 'ai_radiology_images', 'image_source_label')) {
        $pdo->exec("ALTER TABLE `ai_radiology_images` ADD COLUMN `image_source_label` VARCHAR(120) NULL AFTER `image_path`");
        $pdo->exec("UPDATE `ai_radiology_images` SET `image_source_label` = 'Ilustrasi AI generatif lama (simulasi; bukan radiograf tervalidasi)' WHERE `image_source_label` IS NULL AND `image_path` IS NOT NULL");
    }
}

/**
 * Model Gemini image-generation yang dipakai — hardcode (tidak bisa diganti
 * dari halaman Setting AI Saya) karena butuh responseModalities khusus, beda
 * dari model teks/JSON yang dipakai AI Diagnosis/Surgery. Dipakai generasi
 * 3.1 (bukan 2.5) mengikuti temuan 2026-08-12: generasi 2.5 sudah mulai
 * di-deprecate Google untuk API key baru (lihat CLAUDE.md §5 "Roxwood
 * Hospital AI suite"), jadi generasi lebih baru lebih tahan lama dipakai.
 * CATATAN: mengganti model DI SINI tidak akan memperbaiki error kuota 0
 * yang muncul di rekap pengujian 2026-08-12 — itu bukan soal nama model,
 * tapi API key/project Google-nya belum punya kuota image-generation sama
 * sekali (perlu billing aktif di Google AI Studio/Cloud Console).
 */
function ems_ai_radiology_model(): string
{
    return 'gemini-3.1-flash-image';
}

/**
 * Katalog lengkap 4 tingkat: Modality -> Category -> Body Region -> [Projection/Options].
 * Struktur ini yang dipakai untuk cascading select di form (config-1/2/3 pada
 * halaman disabled sampai parent-nya dipilih, persis workstation radiologi
 * sungguhan) — jangan diringkas jadi daftar generik/flat, karena tiap
 * kombinasi modality->category->region punya proyeksi standar sendiri secara
 * klinis dan itu yang membuat halaman ini terasa lengkap.
 */
function ems_ai_radiology_catalog(): array
{
    return [
        'X-Ray' => [
            'Head & Neck' => [
                'Skull' => ['AP', 'Lateral', "Towne's View"],
                'Facial Bone' => ['Waters View', 'Caldwell View', 'Lateral'],
                'Orbit' => ['PA', 'Lateral'],
                'Sinus' => ['Waters View', 'Caldwell View'],
                'Cervical Spine' => ['AP', 'Lateral', 'Open Mouth (Odontoid)'],
            ],
            'Spine' => [
                'Thoracic Spine' => ['AP', 'Lateral'],
                'Lumbar Spine' => ['AP', 'Lateral', 'Oblique', 'Flexion-Extension'],
                'Sacrum & Coccyx' => ['AP', 'Lateral'],
                'Scoliosis Series' => ['Full Spine AP', 'Full Spine Lateral'],
            ],
            'Thorax' => [
                'Chest' => ['PA', 'AP Supine (Portable)', 'Lateral', 'Apical Lordotic'],
                'Ribs' => ['AP', 'Oblique'],
                'Sternum' => ['Lateral', 'RAO'],
            ],
            'Abdomen & Pelvis' => [
                'Abdomen Polos' => ['AP Supine', 'AP Erect', 'Left Lateral Decubitus'],
                'Pelvis' => ['AP'],
                'Hip Joint' => ['AP', 'Frog Leg Lateral'],
            ],
            'Upper Extremity' => [
                'Shoulder' => ['AP', 'Axillary', 'Y-View (Scapular)'],
                'Humerus' => ['AP', 'Lateral', 'AP & Lateral'],
                'Elbow' => ['AP', 'Lateral', 'Oblique', 'AP & Lateral'],
                'Forearm' => ['AP', 'Lateral', 'AP & Lateral'],
                'Wrist' => ['PA', 'Lateral', 'Oblique', 'PA & Lateral'],
                'Hand' => ['PA', 'Oblique', 'Lateral', 'PA, Oblique & Lateral'],
            ],
            'Lower Extremity' => [
                'Femur' => ['AP', 'Lateral', 'AP & Lateral'],
                'Knee' => ['AP', 'Lateral', 'Sunrise / Merchant View', 'AP & Lateral'],
                'Tibia-Fibula' => ['AP', 'Lateral', 'AP & Lateral'],
                'Ankle' => ['AP', 'Lateral', 'Mortise', 'AP & Lateral'],
                'Foot' => ['AP', 'Oblique', 'Lateral', 'AP, Oblique & Lateral'],
            ],
        ],
        'CT Scan' => [
            'Kepala & Otak' => [
                'CT Kepala Non-Kontras' => ['Axial', 'Coronal Reconstruction'],
                'CT Kepala Kontras' => ['Axial', 'Coronal Reconstruction'],
                'CT Angiografi Kepala' => ['Axial', '3D Reconstruction'],
            ],
            'Thorax' => [
                'CT Thorax Non-Kontras' => ['Axial', 'Coronal'],
                'CT Thorax Kontras' => ['Axial', 'Coronal', 'Sagittal'],
                'HRCT Thorax' => ['Axial High-Resolution'],
            ],
            'Abdomen & Pelvis' => [
                'CT Abdomen Non-Kontras' => ['Axial', 'Coronal'],
                'CT Abdomen Kontras (Triple Phase)' => ['Arterial Phase', 'Portal Venous Phase', 'Delayed Phase'],
                'CT Urografi' => ['Axial', 'Coronal', '3D Reconstruction'],
            ],
            'Spine' => [
                'CT Cervical Spine' => ['Axial', 'Sagittal Reconstruction'],
                'CT Thoracolumbar Spine' => ['Axial', 'Sagittal Reconstruction'],
            ],
            'Angiografi CT' => [
                'CT Angiografi Koroner' => ['Axial', '3D Reconstruction'],
                'CT Angiografi Aorta' => ['Axial', '3D Reconstruction'],
                'CT Angiografi Serebral' => ['Axial', '3D Reconstruction'],
            ],
            'Ekstremitas' => [
                'CT Ekstremitas Atas' => ['Axial', '3D Reconstruction'],
                'CT Ekstremitas Bawah' => ['Axial', '3D Reconstruction'],
            ],
        ],
        'MRI' => [
            'Otak & Kepala' => [
                'MRI Kepala Non-Kontras' => ['T1-Weighted', 'T2-Weighted', 'FLAIR', 'DWI'],
                'MRI Kepala Kontras' => ['T1-Weighted Post-Kontras'],
                'MRA (MR Angiografi)' => ['Time-of-Flight', 'Kontras-Enhanced'],
            ],
            'Spine' => [
                'MRI Cervical Spine' => ['T1-Weighted', 'T2-Weighted', 'STIR'],
                'MRI Thoracic Spine' => ['T1-Weighted', 'T2-Weighted'],
                'MRI Lumbar Spine' => ['T1-Weighted', 'T2-Weighted', 'STIR'],
            ],
            'Sendi (Muskuloskeletal)' => [
                'MRI Bahu' => ['T1-Weighted', 'T2-Weighted', 'PD Fat-Sat'],
                'MRI Lutut' => ['T1-Weighted', 'T2-Weighted', 'PD Fat-Sat'],
                'MRI Pergelangan Tangan' => ['T1-Weighted', 'T2-Weighted'],
                'MRI Panggul' => ['T1-Weighted', 'T2-Weighted'],
            ],
            'Abdomen' => [
                'MRI Abdomen Atas (Hepar/Bilier)' => ['T1-Weighted', 'T2-Weighted', 'DWI'],
                'MRI Pelvis' => ['T1-Weighted', 'T2-Weighted'],
                'MRCP' => ['T2-Weighted Heavily'],
            ],
            'Jantung' => [
                'Cardiac MRI Fungsional' => ['Cine SSFP'],
                'Cardiac MRI Perfusi' => ['T1-Weighted Perfusion', 'Late Gadolinium Enhancement'],
            ],
        ],
        'Ultrasound' => [
            'Abdomen' => [
                'USG Abdomen Atas' => ['B-Mode (Grayscale)'],
                'USG Abdomen Bawah' => ['B-Mode (Grayscale)'],
                'USG Whole Abdomen' => ['B-Mode (Grayscale)', 'Color Doppler'],
            ],
            'Obstetri & Ginekologi' => [
                'USG Kehamilan Trimester 1' => ['B-Mode (Grayscale)'],
                'USG Kehamilan Trimester 2-3' => ['B-Mode (Grayscale)', '4D'],
                'USG Transvaginal' => ['B-Mode (Grayscale)'],
            ],
            'Muskuloskeletal' => [
                'USG Bahu' => ['B-Mode (Grayscale)', 'Color Doppler'],
                'USG Lutut' => ['B-Mode (Grayscale)'],
                'USG Jaringan Lunak' => ['B-Mode (Grayscale)'],
            ],
            'Vaskular' => [
                'USG Doppler Vena Ekstremitas' => ['Color Doppler', 'Spectral Doppler'],
                'USG Doppler Arteri Carotis' => ['Color Doppler', 'Spectral Doppler'],
                'USG Doppler Arteri Ekstremitas' => ['Color Doppler', 'Spectral Doppler'],
            ],
            'Thyroid & Leher' => [
                'USG Thyroid' => ['B-Mode (Grayscale)', 'Color Doppler'],
                'USG Kelenjar Getah Bening Leher' => ['B-Mode (Grayscale)'],
            ],
            'Jantung (Echocardiografi)' => [
                'Transthoracic Echo (TTE)' => ['2D', 'Color Doppler', 'M-Mode'],
                'Transesophageal Echo (TEE)' => ['2D', 'Color Doppler'],
            ],
        ],
        'Angiography' => [
            'Angiografi Serebral' => [
                'DSA Serebral' => ['AP', 'Lateral'],
            ],
            'Angiografi Koroner' => [
                'DSA Koroner' => ['RAO', 'LAO'],
            ],
            'Angiografi Perifer' => [
                'DSA Ekstremitas Bawah' => ['AP', 'Lateral'],
                'DSA Ekstremitas Atas' => ['AP', 'Lateral'],
            ],
            'Angiografi Aorta' => [
                'DSA Aorta Abdominalis' => ['AP', 'Lateral'],
                'DSA Aorta Thorakalis' => ['AP', 'Lateral'],
            ],
        ],
        'PET Scan' => [
            'PET-CT Whole Body' => [
                'PET-CT FDG Whole Body' => ['Axial', 'Coronal', 'Fusion Image'],
            ],
            'PET-CT Otak' => [
                'PET-CT Otak FDG' => ['Axial', 'Coronal'],
            ],
            'PET-CT Onkologi Spesifik' => [
                'PET-CT Thorax' => ['Axial', 'Fusion Image'],
                'PET-CT Abdomen-Pelvis' => ['Axial', 'Fusion Image'],
            ],
        ],
        'Mammography' => [
            'Skrining' => [
                'Mammografi Bilateral' => ['CC (Craniocaudal)', 'MLO (Mediolateral Oblique)'],
            ],
            'Diagnostik' => [
                'Mammografi Unilateral' => ['CC', 'MLO', 'Spot Compression'],
                'Mammografi dengan Magnifikasi' => ['Magnification CC', 'Magnification MLO'],
            ],
        ],
        'Fluoroscopy' => [
            'Gastrointestinal' => [
                'Barium Swallow (Esofagografi)' => ['AP', 'Lateral'],
                'Barium Meal (OMD)' => ['AP', 'Oblique'],
                'Barium Enema' => ['AP', 'Lateral'],
            ],
            'Genitourinari' => [
                'Voiding Cystourethrography (VCUG)' => ['AP', 'Oblique'],
                'Hysterosalpingography (HSG)' => ['AP'],
            ],
            'Muskuloskeletal Intervensi' => [
                'Artrografi Sendi' => ['AP', 'Lateral'],
                'Injeksi Sendi Terpandu Fluoroskopi' => ['AP'],
            ],
        ],
        'DEXA Scan' => [
            'Densitometri Tulang' => [
                'DEXA Lumbar Spine' => ['AP'],
                'DEXA Hip' => ['AP'],
                'DEXA Whole Body' => ['AP'],
            ],
        ],
    ];
}

function ems_ai_radiology_modalities(): array
{
    return array_keys(ems_ai_radiology_catalog());
}

function ems_ai_radiology_categories(string $modality): array
{
    return array_keys(ems_ai_radiology_catalog()[$modality] ?? []);
}

function ems_ai_radiology_body_regions_for(string $modality, string $category): array
{
    return array_keys(ems_ai_radiology_catalog()[$modality][$category] ?? []);
}

function ems_ai_radiology_projections_for(string $modality, string $category, string $bodyRegion): array
{
    return ems_ai_radiology_catalog()[$modality][$category][$bodyRegion] ?? [];
}

function ems_ai_radiology_is_valid_selection(string $modality, string $category, string $bodyRegion, string $projection): bool
{
    $projections = ems_ai_radiology_projections_for($modality, $category, $bodyRegion);
    return $projections !== [] && in_array($projection, $projections, true);
}

/**
 * Pilih gambar rujukan dataset hanya untuk kasus fraktur tungkai bawah dengan
 * proyeksi AP. Dataset FracAtlas menandai `leg`, `fractured`, dan `frontal`,
 * tetapi tidak menyimpan tulang/laterality atau AP-vs-PA secara andal. Karena
 * itu gambar ini selalu menjadi kartu referensi terpisah, tidak pernah
 * menggantikan citra simulasi kasus atau dianggap sebagai gambar pasien.
 */
function ems_ai_radiology_reference_match(array $record): ?array
{
    $projection = strtoupper(trim((string) ($record['projection'] ?? '')));
    $finding = mb_strtolower(trim((string) ($record['clinical_finding'] ?? '')), 'UTF-8');
    if (
        ($record['modality'] ?? '') !== 'X-Ray'
        || ($record['category'] ?? '') !== 'Lower Extremity'
        || ($record['body_region'] ?? '') !== 'Tibia-Fibula'
        || $projection !== 'AP'
        || !in_array($finding, ['fraktur / patah tulang', 'fraktur', 'patah tulang'], true)
    ) {
        return null;
    }

    $relativePath = 'assets/radiology/references/fracatlas-leg-fracture-frontal-img0002320.jpg';
    if (!is_file(dirname(__DIR__) . '/' . $relativePath)) {
        return null;
    }

    return [
        'path' => '../' . $relativePath,
        'title' => 'Contoh Fraktur Tungkai Bawah — FracAtlas IMG0002320',
        'match' => 'Label dataset: tungkai bawah, fraktur, proyeksi frontal; satu citra.',
        'limitations' => 'Gambar rujukan dari kasus lain, bukan pasien ini. Metadata FracAtlas tidak memastikan tulang, sisi, lokasi fraktur, kelompok usia, atau apakah frontal ini AP/PA. Gunakan hanya sebagai contoh visual; jangan menyalin temuan ke laporan kasus.',
        'attribution' => 'FracAtlas, IMG0002320.jpg — DOI: 10.6084/m9.figshare.22363012, CC BY 4.0.',
    ];
}

function ems_ai_radiology_clinical_findings(): array
{
    return [
        'Normal / Sehat',
        'Fraktur / Patah Tulang',
        'Perdarahan / Hematoma',
        'Massa / Tumor',
        'Inflamasi / Infeksi',
        'Benda Asing / Peluru',
        'Pasca Operasi / Terpasang Implant',
    ];
}

/**
 * Render katalog Modality->Category->BodyRegion->[Projection] jadi teks
 * padat untuk disisipkan sebagai referensi prompt AI Diagnosis — supaya
 * rekomendasi radiologi yang dihasilkan (field "radiologi_terstruktur")
 * PERSIS cocok dengan salah satu kombinasi valid di Radiology Center,
 * bukan karangan bebas yang tidak bisa dipetakan ke dropdown manapun.
 */
function ems_ai_radiology_catalog_reference_text(): string
{
    $lines = [];
    foreach (ems_ai_radiology_catalog() as $modality => $categories) {
        foreach ($categories as $category => $regions) {
            foreach ($regions as $region => $projections) {
                $lines[] = "{$modality} > {$category} > {$region} > [" . implode(', ', $projections) . ']';
            }
        }
    }

    return implode("\n", $lines);
}

function ems_ai_radiology_clinical_findings_reference_text(): string
{
    return implode(', ', ems_ai_radiology_clinical_findings());
}

/**
 * Cloudflare Workers AI menolak keras prompt > 2048 karakter ("Bad input:
 * Error: Length of '/prompt' must be <= 2048") — ketahuan 2026-08-12 setelah
 * fitur auto-fill anamnesis lengkap (dari AI Diagnosis) bikin anamnesis yang
 * disisipkan ke prompt radiologi jadi jauh lebih panjang dari sebelumnya
 * (anamnesis mentah singkat -> narasi klinis 1 paragraf penuh). Gemini tidak
 * punya batas seketat ini, tapi karena prompt dibangun SATU KALI dipakai
 * kedua provider, batas ini diterapkan universal di sini (aman untuk Gemini
 * juga, cuma memotong anamnesis yang memang sudah sangat panjang).
 */
const EMS_AI_RADIOLOGY_PROMPT_MAX_LENGTH = 1900;

function ems_ai_radiology_build_prompt(array $input): string
{
    $modality = trim((string) ($input['modality'] ?? ''));
    $category = trim((string) ($input['category'] ?? ''));
    $region = trim((string) ($input['body_region'] ?? ''));
    $projection = trim((string) ($input['projection'] ?? ''));
    $finding = trim((string) ($input['clinical_finding'] ?? ''));
    $anamnesis = trim((string) ($input['anamnesis'] ?? ''));

    $prompt = "Create ONE restrained, realistic {$modality} radiograph for a fictional roleplay case. Output exactly the requested study and projection: {$category} > {$region} > {$projection}. Do not add a second view, another limb, or unrelated anatomy.\n"
        . "Finding to depict: {$finding}.\n";

    if ($anamnesis !== '') {
        // Keep a concise anatomic detail in the provider's 2048-byte limit.
        $prompt .= 'Clinical context: ' . mb_strimwidth($anamnesis, 0, 360, '...') . ".\n";
    }

    $prompt .= "Use a clean, centered, anatomically plausible grayscale radiograph with natural contrast and subtle film grain on a plain black background. Keep the image uncluttered: no decorative effects, glow, colored anatomy, extra findings, watermark, interface, or text.";

    if ($modality === 'X-Ray' && preg_match('/fraktur|patah tulang/iu', $finding . ' ' . $anamnesis) === 1) {
        $prompt .= " Depict only the fracture details stated in the case. Preserve the selected body region and projection. Do not imply a fracture level, side, or morphology that the case does not specify.";
    }

    if (strlen($prompt) > EMS_AI_RADIOLOGY_PROMPT_MAX_LENGTH) {
        $prompt = rtrim(mb_strcut($prompt, 0, EMS_AI_RADIOLOGY_PROMPT_MAX_LENGTH - 3, 'UTF-8')) . '...';
    }

    return $prompt;
}

/**
 * Buat skema tulang tungkai bawah dengan lokasi fraktur yang diturunkan
 * langsung dari input eksplisit. Ini ilustrasi terarah untuk roleplay, bukan
 * radiograf atau interpretasi diagnostik. Bila sisi/lokasi/tulang tidak jelas,
 * fungsi mengembalikan null agar tidak mengarang lokasi.
 */
function ems_ai_radiology_build_tibfib_schematic(array $input): ?array
{
    if (
        ($input['modality'] ?? '') !== 'X-Ray'
        || ($input['body_region'] ?? '') !== 'Tibia-Fibula'
        || strtoupper(trim((string) ($input['projection'] ?? ''))) !== 'AP'
        || !extension_loaded('gd')
    ) {
        return null;
    }

    $context = trim((string) ($input['clinical_finding'] ?? '') . ' ' . (string) ($input['anamnesis'] ?? ''));
    if (preg_match('/fraktur|patah tulang/iu', $context) !== 1) {
        return null;
    }

    $siteMatches = [
        'proximal' => preg_match('/(?:1\s*\/\s*3\s*(?:proksimal|atas)|sepertiga\s+(?:proksimal|atas)|proximal\s+third|proximal\s+shaft)/iu', $context) === 1,
        'middle' => preg_match('/(?:1\s*\/\s*3\s*tengah|sepertiga\s+tengah|middle\s*third|midshaft|shaft\s+tengah)/iu', $context) === 1,
        'distal' => preg_match('/(?:1\s*\/\s*3\s*(?:distal|bawah)|sepertiga\s+(?:distal|bawah)|distal\s+third|distal\s+shaft)/iu', $context) === 1,
    ];
    $selectedSites = array_keys(array_filter($siteMatches));
    $hasRight = preg_match('/\b(?:dextra|kanan|right)\b/iu', $context) === 1;
    $hasLeft = preg_match('/\b(?:sinistra|kiri|left)\b/iu', $context) === 1;
    if (count($selectedSites) !== 1 || $hasRight === $hasLeft) {
        return null;
    }
    $site = $selectedSites[0];
    $side = $hasRight ? 'right' : 'left';

    $tibia = preg_match('/\btibia\w*\b/iu', $context) === 1;
    $fibula = preg_match('/\bfibula\w*\b/iu', $context) === 1;
    if (preg_match('/(?:tibia[^.;,\n]{0,32}(?:utuh|tidak\s+(?:patah|fraktur))|(?:utuh|tidak\s+(?:patah|fraktur))[^.;,\n]{0,32}tibia)/iu', $context) === 1) {
        $tibia = false;
    }
    if (preg_match('/(?:fibula[^.;,\n]{0,32}(?:utuh|tidak\s+(?:patah|fraktur))|(?:utuh|tidak\s+(?:patah|fraktur))[^.;,\n]{0,32}fibula)/iu', $context) === 1) {
        $fibula = false;
    }
    if (!$tibia && !$fibula) {
        return null;
    }

    $width = 900;
    $height = 1200;
    $im = imagecreatetruecolor($width, $height);
    if (!$im) {
        return null;
    }
    $black = imagecolorallocate($im, 5, 8, 13);
    $bone = imagecolorallocate($im, 206, 216, 220);
    $boneEdge = imagecolorallocate($im, 235, 240, 238);
    $marrow = imagecolorallocate($im, 74, 87, 92);
    $fracture = imagecolorallocate($im, 5, 8, 13);
    $label = imagecolorallocate($im, 226, 234, 236);
    imagefilledrectangle($im, 0, 0, $width, $height, $black);

    // Simplified AP outline, intentionally schematic rather than photo-like.
    $drawBone = static function (int $cx, int $shaftWidth, int $jointWidth, string $name) use ($im, $bone, $boneEdge, $marrow, $fracture, $label, $site): void {
        $top = [
            $cx - $jointWidth, 185, $cx - (int) ($jointWidth * .72), 235,
            $cx - (int) ($shaftWidth * .65), 330, $cx - (int) ($shaftWidth * .52), 930,
            $cx - (int) ($jointWidth * .72), 1005, $cx - $jointWidth, 1060,
            $cx + $jointWidth, 1060, $cx + (int) ($jointWidth * .72), 1005,
            $cx + (int) ($shaftWidth * .52), 930, $cx + (int) ($shaftWidth * .65), 330,
            $cx + (int) ($jointWidth * .72), 235, $cx + $jointWidth, 185,
        ];
        imagefilledpolygon($im, $top, $bone);
        imagesetthickness($im, 5);
        imagepolygon($im, $top, $boneEdge);
        imagesetthickness($im, 1);
        imagefilledrectangle($im, $cx - 5, 305, $cx + 5, 945, $marrow);

        $fractureY = match ($site) { 'proximal' => 390, 'middle' => 600, default => 820 };
        // A neutral transverse break is schematic only; it does not claim a
        // fracture morphology absent from the source case.
        $half = max(10, (int) ($shaftWidth * .55));
        imagesetthickness($im, 12);
        imageline($im, $cx - $half, $fractureY + 17, $cx + $half, $fractureY - 17, $fracture);
        imagesetthickness($im, 4);
        imageline($im, $cx - $half, $fractureY + 17, $cx + $half, $fractureY - 17, $boneEdge);
        imagesetthickness($im, 1);
        imagestring($im, 5, $cx - 36, 1090, $name, $label);
    };

    if ($tibia) {
        $drawBone(405, 68, 102, 'TIBIA');
    }
    if ($fibula) {
        $drawBone(590, 34, 52, 'FIBULA');
    }

    $title = $side === 'right' ? 'TUNGKAI KANAN' : 'TUNGKAI KIRI';
    imagestring($im, 5, 24, 24, $title . ' - SKEMA AP', $label);
    imagestring($im, 3, 24, 56, 'Ilustrasi lokasi; bukan citra pemeriksaan', $label);

    ob_start();
    $saved = imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);
    if (!$saved || !is_string($bytes) || $bytes === '') {
        return null;
    }

    return ['data' => base64_encode($bytes), 'mime_type' => 'image/png'];
}

/**
 * Prompt sistem untuk laporan bacaan radiologi formal (teks, bukan citra) —
 * dokter Radiolog Sp.Rad senior menuliskan bacaan resmi mendampingi citra
 * yang sudah digenerate, persis pola dokumen radiologi rumah sakit nyata.
 */
function ems_ai_radiology_default_report_system_prompt(): string
{
    return "Anda adalah Dokter Spesialis Radiologi (Sp.Rad) untuk laporan pencitraan final dalam skenario FiveM roleplay Roxwood Hospital. Teks laporan disusun dari konfigurasi pemeriksaan, temuan klinis terpilih, dan konteks kasus; gambar yang tampil adalah ilustrasi roleplay dan tidak dikirim kepada Anda untuk dibaca. Susun laporan akhir yang lengkap, koheren, dan siap dipakai dalam skenario.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. Tulis sebagai hasil final skenario roleplay; jangan gunakan \"citra tidak tersedia\", \"belum dapat dinilai\", \"belum terkonfirmasi\", \"wajib diverifikasi\", \"unggah citra\", atau placeholder sejenis.\n"
        . "2. Gunakan Bahasa Indonesia medis baku, istilah radiologi standar, dan pastikan teknik, temuan, kesan, serta rekomendasi konsisten dengan modalitas, regio, dan proyeksi yang diminta.\n"
        . "3. Wujudkan temuan klinis terpilih menjadi temuan skenario pencitraan konkret yang koheren. Untuk radiograf polos, nilai tulang, alignment, jaringan lunak, dan benda radiopak yang relevan; jangan klaim radiograf mengonfirmasi robekan vaskular atau syok. Bila kasus menyebut perdarahan jaringan lunak, gambarkan pembengkakan jaringan lunak tanpa menyangkal temuan klinis.\n"
        . "4. \"diagnosis\" (Kesan/Impression) wajib menyimpulkan temuan final yang sama dengan \"findings\" dan konteks klinis; jangan memberi daftar kemungkinan yang membingungkan atau meminta pemain menentukan hasil sendiri.\n"
        . "5. Rekomendasi berupa langkah final yang sesuai dengan temuan skenario dan alur kasus; jangan menyatakan pemeriksaan belum dilakukan atau meminta verifikasi/unggah hasil.\n"
        . "6. \"report_text\" wajib memakai persis 4 header huruf besar berikut, berurutan, masing-masing diikuti isi 1 paragraf: \"TECHNIQUE\", \"FINDINGS\", \"IMPRESSION\", \"RECOMMENDATION\".\n"
        . "7. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n\n"
        . "Struktur JSON WAJIB:\n"
        . "{\n"
        . "  \"findings\": [\"poin temuan singkat 1\", \"poin temuan singkat 2\"],\n"
        . "  \"diagnosis\": \"kesan/impression klinis berdasarkan temuan\",\n"
        . "  \"recommendations\": [\"rekomendasi 1\", \"rekomendasi 2\"],\n"
        . "  \"report_text\": \"TECHNIQUE\\n...\\n\\nFINDINGS\\n...\\n\\nIMPRESSION\\n...\\n\\nRECOMMENDATION\\n...\"\n"
        . "}";
}

function ems_ai_radiology_build_report_user_prompt(array $input): string
{
    $modality = trim((string) ($input['modality'] ?? ''));
    $category = trim((string) ($input['category'] ?? ''));
    $region = trim((string) ($input['body_region'] ?? ''));
    $projection = trim((string) ($input['projection'] ?? ''));
    $finding = trim((string) ($input['clinical_finding'] ?? ''));
    $anamnesis = trim((string) ($input['anamnesis'] ?? ''));

    $lines = [
        'Modality: ' . $modality,
        'Category: ' . $category,
        'Body Region / Specific Study: ' . $region,
        'Projection / Sequence / Acoustic Mode: ' . $projection,
        'Temuan Klinis yang Harus Tercermin di Bacaan: ' . $finding,
    ];
    if ($anamnesis !== '') {
        $lines[] = 'Indikasi Klinis / Anamnesis: ' . $anamnesis;
    }

    return implode("\n", $lines);
}

/**
 * Bersihkan hasil laporan radiologi dari AI — pastikan array field selalu
 * array (bukan string tunggal yang lolos validasi longgar), dan report_text
 * tidak pernah kosong.
 */
function ems_ai_radiology_sanitize_report(array $data): array
{
    $toStringArray = static function ($value): array {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $value = trim((string) $value);
        return $value !== '' ? [$value] : [];
    };

    $findings = $toStringArray($data['findings'] ?? []);
    $diagnosis = trim((string) ($data['diagnosis'] ?? ''));
    $recommendations = $toStringArray($data['recommendations'] ?? []);
    $reportText = trim((string) ($data['report_text'] ?? ''));

    return [
        'findings' => $findings,
        'diagnosis' => $diagnosis,
        'recommendations' => $recommendations,
        'report_text' => $reportText,
    ];
}

function ems_ai_radiology_report_quality_issue(array $data): ?string
{
    foreach (['findings', 'recommendations'] as $field) {
        if (!is_array($data[$field] ?? null) || count(array_filter($data[$field], static fn ($line): bool => is_string($line) && trim($line) !== '')) === 0) {
            return 'Bagian ' . $field . ' wajib berisi temuan skenario final.';
        }
    }
    if (!is_string($data['diagnosis'] ?? null) || trim($data['diagnosis']) === '' || !is_string($data['report_text'] ?? null) || trim($data['report_text']) === '') {
        return 'Kesan dan laporan lengkap wajib terisi.';
    }
    $allText = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if (preg_match('/data\s+belum\s+tersedia|belum\s+(?:dapat\s+dinilai|terkonfirmasi|dilakukan|tersedia)|wajib\s+diverifikasi|perlu\s+diverifikasi|unggah\s+(?:citra|gambar)|citra\s+aktual\s+tidak\s+tersedia|hasil\s+pemeriksaan\s+aktual\s+belum/iu', $allText) === 1) {
        return 'Laporan masih memuat frasa hasil belum final atau instruksi verifikasi.';
    }
    $headers = [];
    preg_match_all('/^(TECHNIQUE|FINDINGS|IMPRESSION|RECOMMENDATION)$/m', (string) $data['report_text'], $headers);
    if (($headers[1] ?? []) !== ['TECHNIQUE', 'FINDINGS', 'IMPRESSION', 'RECOMMENDATION']) {
        return 'Laporan lengkap harus memiliki empat header standar secara berurutan.';
    }
    return null;
}

/**
 * Generate laporan bacaan radiologi (teks/JSON, bukan citra) lewat
 * ems_ai_ds_call_gemini() yang sama dipakai AI Diagnosis/Surgery/Laboratory —
 * independen dari ems_ai_radiology_generate_image() (yang bisa lewat
 * Cloudflare) supaya laporan teks tetap bisa berhasil walau generate citra
 * gagal/kena limit, atau sebaliknya.
 */
function ems_ai_radiology_generate_report(PDO $pdo, array $input, ?int $createdBy): array
{
    $systemPrompt = ems_ai_radiology_default_report_system_prompt();
    $userPrompt = ems_ai_radiology_build_report_user_prompt($input);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $result = ems_ai_ds_call_gemini($pdo, $systemPrompt, $userPrompt, 'ai_radiology_report', $createdBy);
        if (!$result['ok']) {
            return $result;
        }
        $data = ems_ai_radiology_sanitize_report($result['data']);
        $issue = ems_ai_radiology_report_quality_issue($data);
        if ($issue === null) {
            return ['ok' => true, 'data' => $data];
        }
        $userPrompt .= "\n\nPERBAIKI JSON LAPORAN ROLEPLAY INI. " . $issue
            . " Hapus semua bahasa belum tersedia/tidak dapat dinilai/verifikasi; pertahankan anatomi, modalitas, regio, proyeksi, dan temuan klinis yang benar. Isi laporan skenario final tanpa klaim bahwa ilustrasi adalah citra pasien. JSON saat ini: "
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return ['ok' => false, 'error' => 'Model tidak melengkapi bacaan radiologi final setelah perbaikan terarah: ' . ($issue ?? 'respons belum lengkap.')];
}

/**
 * Panggil Gemini (model image-generation) dengan API key Gemini pribadi milik
 * user (sama seperti ems_ai_ds_call_gemini di config/ai_diagnosis_surgery.php),
 * tapi pakai model & response-modality khusus gambar.
 */
function ems_ai_radiology_call_gemini_image(PDO $pdo, string $prompt, ?int $createdBy): array
{
    if (!$createdBy) {
        return ['ok' => false, 'error' => 'Sesi pengguna tidak valid. Silakan login ulang.'];
    }

    $userSettings = ems_ai_ds_get_user_settings($pdo, $createdBy);
    if (!ems_ai_ds_has_gemini_provider($userSettings)) {
        return ['ok' => false, 'error' => 'Generate citra Radiology Center tetap membutuhkan API key Gemini karena custom provider hanya mendukung completion teks.'];
    }

    $settings = array_merge(ems_ai_settings_defaults(), [
        'provider' => 'gemini',
        'is_enabled' => 1,
        'gemini_api_key' => (string) $userSettings['gemini_api_key'],
        'gemini_base_url' => trim((string) $userSettings['gemini_base_url']) !== '' ? (string) $userSettings['gemini_base_url'] : 'https://generativelanguage.googleapis.com/v1beta',
        'timeout_seconds' => 120,
        'daily_request_limit' => 0,
    ]);

    try {
        $response = ems_gemini_generate_image(
            $pdo,
            $settings,
            [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            ems_ai_radiology_model(),
            'ai_radiology_center',
            $createdBy
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (empty($response['image']['data'])) {
        return ['ok' => false, 'error' => 'Model AI tidak mengembalikan gambar. Coba ubah deskripsi kasus atau ulangi lagi.'];
    }

    return ['ok' => true, 'image' => $response['image']];
}

/**
 * Titik masuk generate image Radiology Center — otomatis pilih provider:
 * Cloudflare Workers AI kalau Programmer Roxwood sudah mengaktifkannya di
 * dashboard/cloudflare_settings.php (global, dipakai semua user), kalau
 * tidak fallback ke Gemini pribadi milik user seperti sebelumnya. Ini yang
 * dipanggil radiology_center_action.php, bukan langsung ke salah satu provider,
 * supaya gonta-ganti provider tidak perlu ubah kode di action script.
 */
function ems_ai_radiology_generate_image(PDO $pdo, string $prompt, ?int $createdBy): array
{
    $cfSettings = ems_cloudflare_get_settings($pdo);
    $cfEnabled = !empty($cfSettings['is_enabled'])
        && trim((string) ($cfSettings['account_id'] ?? '')) !== ''
        && trim((string) ($cfSettings['api_token'] ?? '')) !== '';

    if ($cfEnabled) {
        try {
            $response = ems_cloudflare_generate_image($pdo, $cfSettings, $prompt, null, 'ai_radiology_center', $createdBy);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Cloudflare: ' . $e->getMessage()];
        }

        if (empty($response['image']['data'])) {
            return ['ok' => false, 'error' => 'Cloudflare tidak mengembalikan gambar. Coba ubah deskripsi kasus atau ulangi lagi.'];
        }

        return ['ok' => true, 'image' => $response['image']];
    }

    return ems_ai_radiology_call_gemini_image($pdo, $prompt, $createdBy);
}

/**
 * Simpan bytes gambar (base64 dari Gemini) ke storage/radiology/, dilayani
 * lewat ajax/secure_file.php seperti file lain di ems2 — tidak pernah diakses
 * langsung.
 */
function ems_ai_radiology_save_image_file(string $base64Data, string $mimeType): ?string
{
    $bytes = base64_decode($base64Data, true);
    if ($bytes === false || $bytes === '') {
        return null;
    }

    $ext = match (true) {
        str_contains($mimeType, 'png') => 'png',
        str_contains($mimeType, 'jpeg'), str_contains($mimeType, 'jpg') => 'jpg',
        str_contains($mimeType, 'webp') => 'webp',
        default => 'png',
    };

    $baseDir = __DIR__ . '/../storage/radiology';
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
        return null;
    }

    $filename = 'rad_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $fullPath = $baseDir . '/' . $filename;

    if (file_put_contents($fullPath, $bytes) === false) {
        return null;
    }

    return 'storage/radiology/' . $filename;
}

/**
 * Hitung usia (tahun) dari tanggal lahir, format "28 th (12/03/1998)" —
 * dipakai untuk label overlay, bukan cuma tanggal lahir mentah.
 */
function ems_ai_radiology_age_label(?string $patientDob): string
{
    $patientDob = trim((string) $patientDob);
    if ($patientDob === '') {
        return '-';
    }

    try {
        $dob = new DateTime($patientDob);
        $age = $dob->diff(new DateTime())->y;
        return $age . ' th (' . $dob->format('d/m/Y') . ')';
    } catch (Throwable $e) {
        return $patientDob;
    }
}

/**
 * Tempel info pasien/dokter/temuan ke pojok gambar pakai GD (bitmap font
 * bawaan GD, TANPA file font eksternal supaya portable Windows<->Linux
 * tanpa masalah — lihat CLAUDE.md soal jebakan path Windows-only lain di
 * codebase ini). Info ini SENGAJA tidak diminta ke model AI image-gen
 * (rentan salah eja/tulisan berantakan pada model diffusi), jadi ditempel
 * manual di sini dengan data yang sudah pasti benar dari database. Modifikasi
 * file gambar in-place (dipanggil setelah ems_ai_radiology_save_image_file()).
 */
function ems_ai_radiology_apply_overlay(string $relativeFilePath, array $info): bool
{
    $fullPath = __DIR__ . '/../' . $relativeFilePath;
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

    $image = match ($ext) {
        'png' => @imagecreatefrompng($fullPath),
        'jpg', 'jpeg' => @imagecreatefromjpeg($fullPath),
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : false,
        default => false,
    };
    if (!$image) {
        return false;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $white = imagecolorallocate($image, 255, 255, 255);
    imagealphablending($image, true);
    $backing = imagecolorallocatealpha($image, 0, 0, 0, 45);

    $font = 5;
    $charW = imagefontwidth($font);
    $charH = imagefontheight($font);
    $pad = 8;
    $lineGap = 4;

    $drawBlock = static function (array $lines, string $corner) use ($image, $width, $height, $white, $backing, $font, $charW, $charH, $pad, $lineGap): void {
        $lines = array_values(array_filter(array_map('trim', $lines), static fn($l) => $l !== ''));
        if (!$lines) {
            return;
        }

        $maxLen = max(array_map('mb_strlen', $lines));
        $blockW = min($width, $maxLen * $charW + $pad * 2);
        $blockH = count($lines) * ($charH + $lineGap) + $pad * 2 - $lineGap;

        [$x, $y] = match ($corner) {
            'top-left' => [0, 0],
            'top-right' => [max(0, $width - $blockW), 0],
            'bottom-left' => [0, max(0, $height - $blockH)],
            default => [max(0, $width - $blockW), max(0, $height - $blockH)],
        };

        imagefilledrectangle($image, $x, $y, $x + $blockW, $y + $blockH, $backing);
        foreach ($lines as $i => $line) {
            imagestring($image, $font, $x + $pad, $y + $pad + $i * ($charH + $lineGap), $line, $white);
        }
    };

    $drawBlock([
        'Nama: ' . (string) ($info['patient_name'] ?: '-'),
        'Usia: ' . (string) ($info['age_label'] ?: '-'),
        'Citizen ID: ' . (string) ($info['patient_citizen_id'] ?: '-'),
    ], 'top-left');

    $drawBlock([
        trim((string) ($info['modality'] ?? '') . ' - ' . (string) ($info['projection'] ?? '')),
        (string) ($info['body_region'] ?? ''),
        'Temuan: ' . (string) ($info['clinical_finding'] ?? '-'),
    ], 'top-right');

    $drawBlock([
        'Dokter: ' . (string) ($info['doctor_name'] ?: '-'),
        'Tanggal: ' . date('d/m/Y H:i'),
    ], 'bottom-left');

    $drawBlock([
        'Roxwood Hospital Medical Center',
    ], 'bottom-right');

    $saved = match ($ext) {
        'png' => imagepng($image, $fullPath),
        'jpg', 'jpeg' => imagejpeg($image, $fullPath, 92),
        'webp' => function_exists('imagewebp') ? imagewebp($image, $fullPath, 92) : false,
        default => false,
    };

    imagedestroy($image);

    return (bool) $saved;
}
