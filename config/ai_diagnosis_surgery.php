<?php

/**
 * Referensi domain (SOP Roxwood Hospital, kamus mantra /me /do, kode animasi /e,
 * kewenangan tim, klasifikasi operasi) untuk fitur AI Diagnosis Assistant dan
 * AI Surgery Planner. Data ini disisipkan ke system prompt Gemini di runtime,
 * terpisah dari system_prompt dasar yang tersimpan di system_ai_prompt_templates
 * supaya persona/aturan inti tetap bisa diedit lewat DB tanpa deploy ulang.
 */

require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/ai_diagnosis_assistant_system_prompt.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';
require_once __DIR__ . '/../actions/ai_custom_client.php';
require_once __DIR__ . '/ai_official_documents.php';

function ems_ai_ds_ensure_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    if (!ems_table_exists($pdo, 'ai_diagnosis_reports')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ai_diagnosis_reports` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
                `division_snapshot` VARCHAR(60) NULL,
                `anamnesis` TEXT NOT NULL,
                `patient_name` VARCHAR(150) NULL,
                `patient_gender` VARCHAR(20) NULL,
                `patient_dob` DATE NULL,
                `patient_citizen_id` VARCHAR(50) NULL,
                `result_json` LONGTEXT NULL,
                `status` ENUM('done','error') NOT NULL DEFAULT 'done',
                `error_message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ai_diagnosis_user` (`user_id`),
                KEY `idx_ai_diagnosis_unit_created` (`unit_code`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    if (ems_table_exists($pdo, 'ai_diagnosis_reports') && !ems_column_exists($pdo, 'ai_diagnosis_reports', 'report_code')) {
        $pdo->exec("ALTER TABLE `ai_diagnosis_reports` ADD COLUMN `report_code` VARCHAR(40) NULL AFTER `id`, ADD UNIQUE KEY `uniq_ai_diagnosis_report_code` (`report_code`)");
    }

    if (ems_table_exists($pdo, 'ai_diagnosis_reports') && !ems_column_exists($pdo, 'ai_diagnosis_reports', 'patient_name')) {
        $pdo->exec("
            ALTER TABLE `ai_diagnosis_reports`
                ADD COLUMN `patient_name` VARCHAR(150) NULL AFTER `anamnesis`,
                ADD COLUMN `patient_gender` VARCHAR(20) NULL AFTER `patient_name`,
                ADD COLUMN `patient_dob` DATE NULL AFTER `patient_gender`,
                ADD COLUMN `patient_citizen_id` VARCHAR(50) NULL AFTER `patient_dob`
        ");
    }

    if (!ems_table_exists($pdo, 'ai_surgery_plans')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ai_surgery_plans` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `unit_code` VARCHAR(20) NOT NULL DEFAULT 'roxwood',
                `division_snapshot` VARCHAR(60) NULL,
                `jenis_operasi_kategori` ENUM('Minor','Mayor') NOT NULL DEFAULT 'Mayor',
                `jenis_anestesi_input` VARCHAR(100) NOT NULL,
                `kompleksitas` ENUM('Mudah','Sedang','Panjang') NOT NULL DEFAULT 'Sedang',
                `kasus_tindakan` TEXT NOT NULL,
                `result_json` LONGTEXT NULL,
                `status` ENUM('done','error') NOT NULL DEFAULT 'done',
                `error_message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ai_surgery_user` (`user_id`),
                KEY `idx_ai_surgery_unit_created` (`unit_code`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    if (ems_table_exists($pdo, 'ai_surgery_plans') && !ems_column_exists($pdo, 'ai_surgery_plans', 'source_report_code')) {
        $pdo->exec("ALTER TABLE `ai_surgery_plans` ADD COLUMN `source_report_code` VARCHAR(40) NULL AFTER `kasus_tindakan`");
    }

    if (!ems_table_exists($pdo, 'user_ai_settings')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_ai_settings` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `gemini_api_key` VARCHAR(255) NOT NULL,
                `gemini_base_url` VARCHAR(255) NOT NULL DEFAULT 'https://generativelanguage.googleapis.com/v1beta',
                `default_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-3.5-flash-lite',
                `groq_api_key` VARCHAR(255) NULL,
                `groq_default_model` VARCHAR(100) NOT NULL DEFAULT 'openai/gpt-oss-120b',
                `custom_provider` VARCHAR(100) NULL,
                `custom_api_key` VARCHAR(255) NULL,
                `custom_base_url` VARCHAR(255) NULL,
                `custom_default_model` VARCHAR(100) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_ai_settings_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    // Groq API key pribadi untuk chat bot Roxy (docs/AI_ASSISTANT_MODULE.md) —
    // ditambahkan di tabel yang sama dengan Gemini pribadi, BUKAN tabel/setting
    // global, karena tier gratis Groq cuma 1.000 request/hari PER AKUN (dicek
    // langsung lewat header rate-limit API sungguhan, 2026-08-30) — dengan 154
    // staff aktif, satu key global dibagi rata tidak cukup untuk desain Roxy
    // yang mengirim ulang seluruh riwayat percakapan tiap balasan.
    if (ems_table_exists($pdo, 'user_ai_settings') && !ems_column_exists($pdo, 'user_ai_settings', 'groq_api_key')) {
        $pdo->exec("
            ALTER TABLE `user_ai_settings`
                ADD COLUMN `groq_api_key` VARCHAR(255) NULL AFTER `default_model`,
                ADD COLUMN `groq_default_model` VARCHAR(100) NOT NULL DEFAULT 'openai/gpt-oss-120b' AFTER `groq_api_key`
        ");
    }

    if (ems_table_exists($pdo, 'user_ai_settings')) {
        $customColumns = [
            'custom_provider' => 'VARCHAR(100) NULL',
            'custom_api_key' => 'VARCHAR(255) NULL',
            'custom_base_url' => 'VARCHAR(255) NULL',
            'custom_default_model' => 'VARCHAR(100) NULL',
        ];
        foreach ($customColumns as $column => $definition) {
            if (!ems_column_exists($pdo, 'user_ai_settings', $column)) {
                $pdo->exec("ALTER TABLE `user_ai_settings` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }
}

/**
 * Setting AI pribadi untuk fitur teks diagnosis/surgery/lab/psychiatry/radiology -
 * setiap user mengisi provider miliknya sendiri, terpisah dari
 * system_ai_settings global yang dipakai fitur AI lain di ems2 (recruitment
 * scoring, OCR, dll).
 */
function ems_ai_ds_get_user_settings(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0 || !ems_table_exists($pdo, 'user_ai_settings')) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM user_ai_settings WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ems_ai_ds_has_gemini_provider(?array $settings): bool
{
    return $settings !== null && trim((string) ($settings['gemini_api_key'] ?? '')) !== '';
}

function ems_ai_ds_has_custom_provider(?array $settings): bool
{
    return $settings !== null
        && trim((string) ($settings['custom_provider'] ?? '')) !== ''
        && trim((string) ($settings['custom_base_url'] ?? '')) !== ''
        && trim((string) ($settings['custom_default_model'] ?? '')) !== '';
}

function ems_ai_ds_has_text_provider(?array $settings): bool
{
    return ems_ai_ds_has_gemini_provider($settings)
        || ems_ai_ds_has_custom_provider($settings);
}

function ems_ai_ds_save_user_settings(PDO $pdo, int $userId, string $apiKey, string $baseUrl, string $model): void
{
    $stmt = $pdo->prepare("
        INSERT INTO user_ai_settings (user_id, gemini_api_key, gemini_base_url, default_model)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            gemini_api_key = VALUES(gemini_api_key),
            gemini_base_url = VALUES(gemini_base_url),
            default_model = VALUES(default_model),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$userId, $apiKey, $baseUrl, $model]);
}

function ems_ai_ds_save_custom_user_settings(PDO $pdo, int $userId, string $provider, string $apiKey, string $baseUrl, string $model): void
{
    $stmt = $pdo->prepare("
        INSERT INTO user_ai_settings
            (user_id, gemini_api_key, gemini_base_url, default_model, custom_provider, custom_api_key, custom_base_url, custom_default_model)
        VALUES (?, '', 'https://generativelanguage.googleapis.com/v1beta', 'gemini-3.5-flash-lite', ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            custom_provider = VALUES(custom_provider),
            custom_api_key = VALUES(custom_api_key),
            custom_base_url = VALUES(custom_base_url),
            custom_default_model = VALUES(custom_default_model),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$userId, $provider, $apiKey, $baseUrl, $model]);
}

function ems_ai_ds_clear_user_settings(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('ID pengguna tidak valid.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_ai_settings
            (user_id, gemini_api_key, gemini_base_url, default_model, groq_api_key, groq_default_model, custom_provider, custom_api_key, custom_base_url, custom_default_model)
        VALUES (?, '', '', '', '', '', '', '', '', '')
        ON DUPLICATE KEY UPDATE
            gemini_api_key = VALUES(gemini_api_key),
            gemini_base_url = VALUES(gemini_base_url),
            default_model = VALUES(default_model),
            groq_api_key = VALUES(groq_api_key),
            groq_default_model = VALUES(groq_default_model),
            custom_provider = VALUES(custom_provider),
            custom_api_key = VALUES(custom_api_key),
            custom_base_url = VALUES(custom_base_url),
            custom_default_model = VALUES(custom_default_model),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$userId]);
}

/**
 * Kode referensi unik per laporan diagnosis (mis. "DGN-20260812-143012-A1B2"),
 * dipakai supaya dokter tinggal salin kode ini dari ai_diagnosis_report.php
 * lalu tempel di form AI Surgery Planner / Radiology Center untuk auto-fill
 * data kasus — tanpa perlu retype ulang dan tanpa AI model kehilangan
 * konteks alur dari diagnosis awal.
 */
function ems_ai_ds_generate_report_code(): string
{
    return 'DGN-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

/**
 * Cari laporan diagnosis yang statusnya "done" berdasarkan report_code, dibatasi
 * unit_code yang sama (tidak lintas unit roxwood/alta) — dipakai oleh endpoint
 * auto-fill di AI Surgery Planner & Radiology Center.
 */
function ems_ai_ds_find_diagnosis_report_by_code(PDO $pdo, string $code, string $unitCode): ?array
{
    $code = trim($code);
    if ($code === '' || !ems_column_exists($pdo, 'ai_diagnosis_reports', 'report_code')) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM ai_diagnosis_reports
        WHERE report_code = ? AND unit_code = ? AND status = 'done'
        LIMIT 1
    ");
    $stmt->execute([$code, $unitCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Guard "1 kode referensi hanya boleh dipakai 1x per halaman tujuan":
 * kode DGN- yang sudah pernah dipakai untuk generate hasil sukses di
 * $table (AI Surgery Planner/Radiology Center/Laboratory AI/Psychiatry
 * Center) tidak boleh dipakai LAGI di halaman yang SAMA — tapi TETAP boleh
 * dipakai di halaman lain (setiap tabel dicek independen), dan tetap bisa
 * dipakai ulang secara sengaja lewat alur "Generate Ulang" (yang melewati
 * pemanggilan fungsi ini). $table selalu string literal dari kode kita
 * sendiri (bukan input user) jadi aman diselipkan langsung ke SQL.
 *
 * `ai_radiology_images` punya DUA status independen (citra `status` +
 * `report_status` bacaan teks, lihat migration 64) — kode dianggap
 * "dipakai" kalau SALAH SATU sukses (citra ATAU bacaan berhasil dibuat),
 * bukan cuma kalau keduanya sukses, karena keduanya sama-sama representasi
 * nyata bahwa konteks kode itu sudah dipakai menghasilkan output di
 * halaman ini.
 */
function ems_ai_ds_report_code_used_on(PDO $pdo, string $table, string $code, string $unitCode): bool
{
    return ems_ai_ds_report_code_usage_info($pdo, $table, $code, $unitCode) !== null;
}

/**
 * Sama seperti ems_ai_ds_report_code_used_on(), tapi mengembalikan detail
 * siapa & kapan kode itu dipakai pertama kali di halaman ($table) ini —
 * dipakai supaya "Ambil Data" di form bisa langsung memberi tahu user SAAT
 * fetch (bukan baru ketahuan belakangan pas submit ditolak 409).
 */
function ems_ai_ds_report_code_usage_info(PDO $pdo, string $table, string $code, string $unitCode): ?array
{
    $code = trim($code);
    if ($code === '' || !ems_column_exists($pdo, $table, 'source_report_code')) {
        return null;
    }

    $statusCondition = ($table === 'ai_radiology_images' && ems_column_exists($pdo, $table, 'report_status'))
        ? "(t.status = 'done' OR t.report_status = 'done')"
        : "t.status = 'done'";

    $stmt = $pdo->prepare("
        SELECT t.created_at, u.full_name AS user_name
        FROM `{$table}` t
        LEFT JOIN user_rh u ON u.id = t.user_id
        WHERE t.source_report_code = ? AND t.unit_code = ? AND {$statusCondition}
        ORDER BY t.id ASC
        LIMIT 1
    ");
    $stmt->execute([$code, $unitCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ems_ai_ds_quick_sop_rules(): array
{
    return [
        'Jangan masuk lokasi belum CLEAR dari Kepolisian. SOP_EMS_Roxwood.txt Pasal 4 poin 6; Pasal 6.5.',
        'Cek jabatan petugas sebelum tindakan. RH - Kebijakan Kewenangan Medis.pdf halaman 4-6.',
        'Kondisi gawat memakai ABCDE, stabilisasi, monitoring, lalu rujuk/operasi sesuai indikasi.',
        'Paramedic tidak menjadi operator utama operasi minor/mayor.',
        'AI Diagnosis Assistant berhenti setelah stabilisasi IGD dan handoff; alur berikutnya Laboratorium bila perlu, Radiologi, lalu Ruang Operasi sesuai indikasi.',
        'Emergency IGD tidak mencakup insisi, eksplorasi, evakuasi, penjahitan organ, repair/reseksi/anastomosis, penutupan luka operasi, atau transfer ICU/rawat inap pasca operasi.',
        'Kontrol perdarahan eksternal di IGD hanya sementara dengan bebat tekan/kassa; tindakan definitif menjadi indikasi singkat untuk Ruang Operasi.',
        'Trauma penetrasi serius sampai terbukti sebaliknya; ukuran luka luar tidak menjamin cedera internal ringan.',
    ];
}

function ems_ai_ds_first_aid_mantra(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $path = 'D:\\Adam\\EMS\\Dokumen\\Kamus Me Do - Pertolongan Pertama.txt';
    $cached = is_file($path) ? (string) file_get_contents($path) : '';
    return $cached;
}

function ems_ai_ds_anim_mantra_table(): array
{
    return [
        'cleanhands' => 'Kebersihan tangan - menjaga sterilitas sebelum tindakan',
        'clean' => 'Membersihkan/antiseptik - mengoleskan povidone iodine, membersihkan luka/area operasi, sterilisasi bekas jahitan (BUKAN cuci tangan, BUKAN irigasi NaCl)',
        'mechanic' => 'Tindakan manual umum - pemeriksaan, sayatan, reposisi',
        'syringe' => 'Penyuntikan - pemberian anestesi, analgesik, obat',
        'scalpel' => 'Mengambil alat bedah',
        'parkingmeter' => 'Eksekusi suntikan - simbol tindakan injeksi ke pasien',
        'weld' => 'Suction - menghisap darah/cairan agar lapangan operasi bersih',
        'champagnespray' => 'Irigasi - membilas luka dengan NaCl',
        'valet2' => 'Penjahitan - menjahit jaringan/luka operasi',
        'drilltool' => 'Pengeboran/fiksasi tulang - membuat burr hole, mengebor lubang screw, memasang plate & screw/pen fiksasi tulang',
        'box' => 'Pengambilan alat medis (atau "medbox")',
        'foodtray' => 'Wadah - menaruh jaringan, bekuan darah, atau peluru',
        'clipboard' => 'Dokumentasi - catatan medis & informed consent',
        'type' => 'Monitoring - membaca EKG atau monitor pasien (atau "think")',
        'atm' => 'Aktivasi mesin - menyalakan MRI atau alat medis',
        'bartender' => 'Menahan alat - menahan retraktor / posisi statis',
        'mechanic4' => 'Mengambil barang untuk diberikan ke DPJP',
        'mechanic5' => 'Memasang infus / blood bag / menghidupkan lampu',
    ];
}

function ems_ai_ds_anim_mantra_reference_text(): string
{
    $lines = [];
    foreach (ems_ai_ds_anim_mantra_table() as $emote => $desc) {
        $lines[] = "/e {$emote} = {$desc}";
    }
    return implode("\n", $lines);
}

function ems_ai_ds_role_authority_reference(): string
{
    return "DPJP (Dokter Penanggung Jawab): kewenangan penuh untuk rencana operasi dan tindakan definitif pada AI Surgery Planner.\n"
        . "Asisten 1 & Asisten 2 (co-ass/paramedic di bawah supervisi DPJP): kewenangan terbatas, HANYA bertindak atas instruksi DPJP, tidak mengambil keputusan definitif mandiri. Asisten 1 fokus menyiapkan/mengambil alat & instrumen, sterilisasi area, dokumentasi bantu; Asisten 2 fokus memasang infus/oksigen/kantong darah, menyuntik obat atas perintah, memantau monitor EKG, suction, irigasi.\n"
        . "Pola dialog resmi: DPJP memberi perintah singkat (\"DPJP: tolong ...\"), asisten merespons singkat (\"Asisten 1/2: Baik, dok.\") baru melakukan tindakan /me /do /e.\n"
        . "Libatkan Asisten 2 hanya pada kasus berat/kompleks/mayor yang butuh banyak tindakan simultan; kasus ringan-sedang cukup DPJP + Asisten 1.\n"
        . "Sumber: RH - Kebijakan Kewenangan Medis.pdf; PANDUAN PENANGGANAN OPERASI.pdf.";
}

function ems_ai_ds_igd_authority_reference(): string
{
    return "AI Diagnosis Assistant hanya menangani tahap IGD/pra-operasi sampai stabilisasi dan handoff. DPJP memimpin primary survey, airway, breathing, circulation, akses IV, resusitasi, kontrol perdarahan eksternal sementara, crossmatch, informed consent, monitoring, dan serah-terima.\n"
        . "JANGAN menulis insisi, eksplorasi, evakuasi, penjahitan organ, repair/reseksi/anastomosis, penutupan luka operasi, atau tindakan teknis Ruang Operasi di field emergency. Jangan menutup laporan dengan transfer ICU atau rawat inap pasca operasi. Jika ditemukan eviserasi atau usus/omentum/organ terpapar, tutup sementara hanya dengan kassa steril basah/lembab yang dibasahi NaCl 0,9%; jangan gunakan kassa kering dan jangan dorong organ kembali.\n"
        . "Tindakan terakhir emergency adalah pernyataan rencana bahwa pasien SIAP dipindahkan ke tahap berikutnya yang sesuai: Laboratorium, Radiologi, atau Ruang Operasi. Hasil tindakan yang belum diverifikasi tetap Data belum tersedia.";
}

function ems_ai_ds_operation_classification_reference(): string
{
    return "OPERASI MINOR (risiko rendah, durasi singkat, umumnya anestesi lokal, tidak perlu ICU): luka memar ringan-sedang, luka robek superfisial/sedang tanpa kena tendon/saraf/pembuluh besar/organ vital, patah tulang tertutup sederhana tanpa pergeseran berat, luka tembak superfisial tidak tembus rongga tubuh, luka bakar derajat 1-2 luas kecil, cedera kepala ringan (GCS 15, tanpa muntah/kejang/defisit neurologis), insisi & drainase abses kecil, eksisi kista kecil, ekstraksi benda asing superfisial, debridement luka ringan.\n"
        . "OPERASI MAYOR (risiko tinggi, anestesi umum/regional, butuh ICU/rawat inap, tim multidisiplin): Bedah Umum (laparotomi eksplorasi, appendektomi dgn perforasi, kolesistektomi, reseksi usus, hernia strangulata), Bedah Saraf (craniotomy, evakuasi hematoma intrakranial, operasi tumor otak, operasi tulang belakang), Bedah Kardiotoraks (CABG, operasi katup jantung, lobektomi, pneumonektomi), Obstetri/Ginekologi (sectio caesarea, histerektomi, kehamilan ektopik), Ortopedi (ORIF, amputasi, fraktur kompleks), Bedah Plastik/Rekonstruksi (skin graft luas, flap surgery, penanganan luka bakar berat), Bedah Trauma Emergensi (eksplorasi luka tembak, laparotomi trauma, craniotomy trauma, thoracotomy, penanganan perdarahan internal).\n"
        . "Eskalasi wajib ke operasi mayor bila: GCS <=13, perdarahan aktif berat, syok, sesak napas, fraktur terbuka/kompleks, nyeri berat tak terkontrol, multiple trauma.\n"
        . "JENIS ANESTESI wajar: \"Tidak diperlukan anestesi\", \"Anestesi topikal/lokal\" (mis. Lidocaine 1-2%), \"Sedasi ringan\" (tanpa dokter spesialis anestesi), \"Anestesi umum (General Anesthesia)\" (operasi mayor, wajib dokter spesialis anestesi), \"Anestesi regional/spinal\" (wajib dokter spesialis anestesi).\n"
        . "Untuk keamanan obat: pada bedah saraf/kraniotomi, mata, atau tindakan berisiko perdarahan tinggi lainnya, JANGAN meresepkan NSAID/antiplatelet (Ketorolac, Asam Mefenamat, Ibuprofen, Aspirin) sebagai analgesik post-operatif/pemulangan karena meningkatkan risiko perdarahan ulang - gunakan Paracetamol dan/atau opioid (Tramadol, Codein, Morfin PCA) sebagai gantinya.\n"
        . "Sumber: RH - Kebijakan Kewenangan Medis.pdf Lampiran I, II, III.";
}

function ems_ai_ds_default_diagnosis_system_prompt(): string
{
    return "Anda adalah dokter senior IGD (Instalasi Gawat Darurat) Roxwood Hospital dengan pengalaman lebih dari 15 tahun, menyusun laporan medis untuk simulasi/roleplay EMS. Tugas Anda: menyusun laporan medis lengkap berdasarkan fakta yang tersedia, tanpa mengubah data yang belum terkonfirmasi menjadi fakta.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. Pertahankan semua fakta eksplisit dari anamnesis dan identitas pasien. JANGAN mengarang data aktual, usia, berat badan, mekanisme cedera, lokasi luka, GCS aktual, TTV aktual, hasil pemeriksaan, respons terapi, atau kondisi pasca tindakan yang tidak diberikan.\n"
        . "2. Nilai ukur dan hasil pemeriksaan aktual yang belum diberikan wajib ditulis \"Belum diukur\", \"Belum dinilai\", atau \"Data belum tersedia\" pada field faktual. Agar laporan tetap otomatis terisi, buat estimasi roleplay terpisah pada \"gcs_estimasi_ai\" dan \"ttv_estimasi_ai\" bila data aktual tidak tersedia. Setiap estimasi wajib diberi status \"Estimasi AI — wajib verifikasi\" dan tidak boleh dipindahkan ke field faktual \"gcs\"/\"ttv\". Untuk alur ABCDE, rekomendasi, dugaan klinis, dan rencana roleplay, rangkai uraian lengkap yang spesifik terhadap kasus berdasarkan SOP; jangan mengulang template statis.\n"
        . "3. Semua nilai yang memang tersedia harus koheren secara internal dan sesuai standar medis internasional (ABCDE, ATLS, Primary/Secondary Survey) serta SOP Roxwood Hospital.\n"
        . "4. TTV, GCS, kesadaran, anamnesis, tindakan, anestesi, dan hasil tindakan aktual wajib menggambarkan kondisi yang tertulis. Estimasi AI hanya boleh berada pada field estimasi dan wajib diverifikasi. Untuk setiap nilai suhu <36°C, tulis eksplisit sebagai hipotermia dan jelaskan kaitannya dengan risiko koagulopati serta trauma triad of death (hipotermia-asidosis-koagulopati) pada syok hemoragik berat; jangan memakai frasa kabur seperti wajib dipertahankan.\n"
        . "5. Sisipkan transparansi di akhir \"roleplay_note\" tentang data yang belum tersedia, estimasi, dan status wajib verifikasi; jangan menyamarkan nilai yang belum diukur sebagai fakta.\n"
        . "6. Field \"emergency\" hanya berisi penanganan IGD/pra-operasi sampai stabilisasi dan handoff. Isinya hanya primary survey, airway/intubasi, breathing, circulation, akses IV/resusitasi, kontrol perdarahan eksternal sementara dengan bebat tekan/kassa, crossmatch, informed consent, monitoring, dan pernyataan siap dipindahkan. Jika penilaian luka menemukan eviserasi atau organ terpapar (usus/omentum), WAJIB tutup sementara dengan kassa steril basah/lembab yang dibasahi NaCl 0,9%; bukan kassa kering, jangan mendorong organ kembali, dan jangan melakukan penutupan definitif. Jangan masukkan tindakan administratif/komunikasi sebagai item tersendiri.\n"
        . "7. Setiap item \"emergency\" adalah object 5 field: \"pelaku\" (DPJP/Asisten 1/Asisten 2), \"instruksi\" (dialog DPJP ke asisten, format \"DPJP: <perintah>\" + opsional \"<Asisten>: Baik, dok.\" - wajib diisi bila pelaku Asisten, kosongkan bila DPJP sendiri), \"aksi\" (/me tanpa prefix), \"hasil\" (/do tanpa prefix). Model wajib merangkai aksi dan hasil/rencana yang berbeda sesuai kasus; hasil yang belum terobservasi diberi label rencana/menunggu verifikasi, bukan dikosongkan atau dibuat sebagai fakta. \"animasi\" adalah kode /e paling sesuai.\n"
        . "8. Ikuti pembagian peran & kewenangan dari referensi di bawah - jangan biarkan Asisten mengambil keputusan definitif.\n"
        . "9. Bila kasus gawat/trauma, susun 8-12 langkah relevan yang berhenti pada stabilisasi: penilaian awal/ABCDE -> stabilisasi -> handoff ke Laboratorium bila perlu, Radiologi, atau Ruang Operasi. JANGAN menulis insisi, eksplorasi, evakuasi, penjahitan organ, repair/reseksi/anastomosis, penutupan luka operasi, atau transfer ICU/rawat inap pasca operasi.\n"
        . "10. Field \"emergency\" wajib memakai mantra resmi dari referensi kamus me/do (bila kategorinya cocok) sebagai basis obat dan alat.\n"
        . "11. Tentukan \"jenis_operasi\" dan \"jenis_anestesi\" berdasarkan referensi klasifikasi operasi - sebutkan klasifikasi (Minor/Mayor) DAN nama tindakan spesifik sebagai indikasi singkat untuk Ruang Operasi, atau \"Tidak diperlukan operasi...\" bila tak perlu. JANGAN menulis langkah teknis operasi pada \"kasus_tindakan\" atau \"emergency\". ORIF/Open Reduction Internal Fixation WAJIB diklasifikasikan sebagai operasi Mayor.\n"
        . "12. Isi \"kasus_tindakan\" dengan indikasi singkat 1-2 kalimat untuk diteruskan ke sistem Ruang Operasi; sebutkan nama kemungkinan tindakan/operasi tanpa langkah teknis operasi.\n"
        . "13. \"radiologi\" (array teks bebas untuk dibaca manusia) TETAP wajib diisi. TAMBAHAN WAJIB: \"radiologi_terstruktur\" adalah SATU object berisi rekomendasi pencitraan PALING prioritas/relevan, dan nilai \"modality\", \"category\", \"body_region\", \"projection\" WAJIB berupa STRING TUNGGAL (bukan array/list) dipilih PERSIS (karakter identik, jangan parafrase/terjemahkan) dari salah satu baris di REFERENSI KATALOG RADIOLOGI di bawah — setiap baris referensi berformat \"Modality > Category > Body Region > [opsi1, opsi2, ...]\", dan \"projection\" HARUS diisi HANYA SATU dari opsi di dalam kurung siku itu (pilih yang paling relevan secara klinis), JANGAN menyalin seluruh isi kurung siku sebagai list. JANGAN mengarang kombinasi yang tidak ada di daftar itu. Field \"clinical_finding\" pada object yang sama WAJIB dipilih persis dari REFERENSI TEMUAN KLINIS. Jika pasien sama sekali tidak butuh pencitraan, isi seluruh 4 field modality/category/body_region/projection dengan string kosong \"\" dan clinical_finding tetap diisi sewajarnya.\n"
        . "13a. \"lab\" (array teks bebas untuk dibaca manusia) TETAP wajib diisi. TAMBAHAN WAJIB: \"laboratorium_terstruktur\" adalah SATU object berisi rekomendasi pemeriksaan laboratorium PALING prioritas/relevan, dan nilai \"department\", \"category\", \"level3_option\", \"specimen_type\" WAJIB berupa STRING TUNGGAL (bukan array/list) dipilih PERSIS (karakter identik, jangan parafrase/terjemahkan) dari salah satu baris di REFERENSI KATALOG LABORATORIUM di bawah — setiap baris referensi berformat \"Department > Category > [opsi level3 kalau ada] > Spesimen: [opsi1, opsi2, ...]\". Kalau kategori itu tidak punya opsi level3 di referensi, isi \"level3_option\" dengan string kosong \"\". \"specimen_type\" HARUS diisi HANYA SATU dari daftar Spesimen pada baris yang sama. JANGAN mengarang kombinasi yang tidak ada di daftar itu. Jika pasien sama sekali tidak butuh pemeriksaan laboratorium, isi seluruh 4 field dengan string kosong \"\".\n"
        . "14. Bahasa Indonesia medis baku. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n"
        . "15. Field JSON WAJIB PERSIS memakai nama key seperti di Struktur JSON di bawah — JANGAN salah ketik/singkat nama key (contoh kesalahan yang PERNAH terjadi dan DILARANG diulang: menulis \"rolepy_note\" alih-alih \"roleplay_note\"). Cek ulang ejaan setiap nama key sebelum membalas.\n"
        . "16. \"anamnesis_lengkap\" WAJIB diisi: tulis ulang anamnesis asli menjadi narasi klinis rapi dengan mempertahankan semua fakta eksplisit. Tambahkan konteks alur SOP hanya sebagai rencana/dugaan yang jelas; jangan mengisi mekanisme cedera, lokasi spesifik, kondisi kesadaran, atau hasil pemeriksaan sebagai fakta bila tidak ada di input.\n"
        . "17. GCS aktual dan GCS estimasi wajib aritmetis: total = E + V + M. Jangan menulis GCS 13 bersama E4 V4 M6 karena E4+V4+M6 = 14; bila total 13 dan E4 V4, M harus 5. Jangan mengubah komponen hanya untuk mengejar total tanpa bukti.\n"
        . "\nATURAN WAJIB MEKANISME CEDERA & KONSISTENSI KLINIS (DIATAS SEMUA FORMAT):\n"
        . "Sebelum menulis laporan, tentukan dulu secara internal: mekanisme cedera apa yang paling masuk akal dari anamnesis trigger yang diberikan (contoh: trauma tumpul abdomen, luka tusuk tembus abdomen dengan perforasi usus, cedera kepala berat/TBI akibat deselerasi/benturan, syok hipovolemik hemoragik, dsb). Semua bagian laporan — diagnosis, GCS, TTV, rencana tindakan, pilihan pemeriksaan, hingga tahapan penanganan emergency — harus konsisten mengikuti satu mekanisme itu. JANGAN ada bagian yang kontradiksi dengan bagian lain.\n"
        . "18. GCS MOTOR WAJIB TERPISAH: M3 = fleksi abnormal/dekortikasi; M4 = withdrawal/menarik diri normal. M3 dan M4 adalah respons motorik berbeda dengan makna klinis berbeda — pilih tepat satu sesuai deskripsi pasien. JANGAN menulis 'fleksi abnormal/withdrawal' seolah sinonim atau satu respons. Tuliskan dasar klinis tiap komponen E, V, dan M secara spesifik pada field 'basis' dan 'interpretation', serta isi 'gcs_motor_definition' dengan definisi M yang dipilih.\n"
        . "19. LABEL ESTIMASI WAJIB: Semua angka yang belum benar-benar diukur (GCS, TTV, lab, radiologi) WAJIB diberi status/label 'Estimasi AI — wajib verifikasi' pada field estimasi, bukan disajikan sebagai fakta. Field aktual tetap 'Data belum tersedia'.\n"
        . "20. AIRWAY DEFINITIF (INTUBASI ETT): Wajib disertakan dalam penanganan emergency jika estimasi GCS ≤ 8 — bukan hanya oksigen via mask/NRM, karena refleks proteksi jalan napas hilang.\n"
        . "21. PENANGANAN PENINGKATAN TEKANAN INTRAKRANIAL: Jika ada kecurigaan peningkatan tekanan intrakranial (TTIK / Cushing reflex / edema serebri / herniasi), pilih SATU terapi yang paling sesuai dan tulis nama spesifiknya (misalnya NaCl 3% hipertonik), jangan menulis pilihan dengan kata 'atau'.\n"
        . "22. DISABILITY DAN PUPIL: Pada trauma kepala dengan kecurigaan peningkatan tekanan intrakranial, primary survey bagian Disability WAJIB memuat pemeriksaan ukuran dan reaktivitas pupil dengan status isokor/anisokor/dilatasi serta reaktif/non reaktif. Jika anisokor atau dilatasi menunjukkan tanda herniasi akut, status rencana operasi definitif boleh cito tanpa menunggu hasil CT scan. Jika tanda herniasi akut tidak jelas, tulis persis 'rencana tentatif — menunggu hasil CT scan'.\n"
        . "22a. URUTAN WAKTU LOGIS: CT scan/pemeriksaan penunjang penentu dievaluasi sebelum keputusan operasi definitif, kecuali tanda herniasi akut atau kegawatan lain membenarkan tindakan cito. Nyatakan status cito atau tentatif sesuai temuan pupil, bukan berdasarkan asumsi.\n"
        . "22b. ISTILAH RESUSITASI: Istilah hipotensi permisif (permissive hypotension) hanya boleh dipakai untuk strategi resusitasi cairan dengan target tekanan darah yang sengaja dijaga tidak terlalu tinggi. Jangan pakai istilah itu untuk temuan TTV mentah; gunakan deskripsi 'tanda awal syok hemoragik ringan-sedang' bila konteks trauma mendukung.\n"
        . "23. INFORMED CONSENT & PERSIAPAN DARAH: Sertakan informed consent ke keluarga/wali sebelum pasien dibawa ke ruang operasi (pasien tidak sadar = consent dari wali/keluarga), dan persiapan darah (pemeriksaan golongan darah + crossmatch / sedia kantung PRC) untuk setiap tindakan yang berisiko perdarahan.\n"
        . "24. KOHERENSI DIAGNOSIS BANDING: Diagnosis utama dan diagnosis banding (DDx) HARUS tetap dalam ranah mekanisme cedera yang sama. Jangan mencampur DDx trauma tumpul dengan kasus trauma tajam/penetrasi, atau sebaliknya — DDx hanya boleh menjelaskan variasi organ/struktur dan tingkat keparahan dalam mekanisme cedera YANG SAMA.\n"
        . "25. TRAUMA ABDOMEN DENGAN TANDA KEGAWATAN ABSOLUT: Untuk trauma abdomen dengan eviserasi/prolaps organ, perdarahan masif tidak terkontrol, atau peritonitis jelas pada pasien tidak stabil, prioritaskan stabilisasi dan handoff untuk laparotomi cito. Bila usus, omentum, atau organ lain terpapar, lindungi dengan kassa steril basah/lembab yang dibasahi NaCl 0,9%; kassa kering dilarang karena dapat menyebabkan desikasi jaringan. Jangan mendorong organ kembali dan jangan melakukan penutupan definitif di IGD. Field radiologi TETAP wajib terisi: cantumkan FAST dan/atau X-ray portable sebagai pemeriksaan bedside IGD. CT scan dicantumkan sebagai \"DITUNDA — dijadwalkan di tahap Radiologi/pasca stabilisasi\" hanya bila relevan dan belum menghambat jalur cito; jangan menulis hasil radiologi aktual yang belum tersedia.\n"
        . "26. BATAS TAHAP IGD: Laporan ini hanya penilaian awal dan stabilisasi pra-operasi. Jangan menulis insisi, eksplorasi, evakuasi, penjahitan organ, repair/reseksi/anastomosis, penutupan luka operasi, atau hasil operasi. Bagian emergency wajib berakhir pada pasien SIAP dipindahkan ke Laboratorium bila perlu, Radiologi, atau Ruang Operasi; jangan menutup dengan ICU/rawat inap pasca operasi.\n"
        . "27. VARIASI KASUS UNIK: Kasus tidak boleh identik walau anamnesis trigger sama dengan permintaan sebelumnya — variasikan tingkat keparahan, organ/struktur anatomis yang terkena, dan angka GCS/TTV dalam rentang yang tetap masuk akal secara klinis.\n"
        . "28. VERIFIKASI KONSISTENSI INTERNAL SEBELUM FINAL: Cek ulang apakah GCS, TTV, diagnosis, rencana pemeriksaan, dan stabilisasi IGD semuanya konsisten satu sama lain dan sesuai mekanisme cedera yang sama. Jika ada yang tidak sinkron, perbaiki sebelum dikirimkan sebagai JSON.\n\n"
        . "29. KESESUAIAN FISIOLOGIS WAJIB: Cocokkan mekanisme, volume, dan lokasi cedera dengan derajat perdarahan/syok, serta cocokkan lokasi cedera kepala dengan GCS dan tanda herniasi. Jika volume/lokasi cedera yang teridentifikasi tidak cukup menjelaskan syok berat atau GCS rendah, JANGAN mengatribusikan semuanya ke satu cedera. Tulis eksplisit sebagai RED FLAG: derajat syok/penurunan kesadaran tidak proporsional dengan cedera yang teridentifikasi — curigai cedera tambahan tersembunyi (kepala, toraks, abdomen, sumber perdarahan lain) yang belum teridentifikasi, perlu secondary survey menyeluruh dan pencitraan tambahan. Evaluasi ulang mekanisme, sumber perdarahan, cedera tambahan, hipoksia, hipotensi, intoksikasi, kejang, atau penyebab non-traumatik sesuai data; jangan menaikkan diagnosis menjadi cedera berat tanpa bukti.\n"
        . "30. KONSISTENSI PEMERIKSAAN PENUNJANG: Semua pemeriksaan penunjang yang disebut di bagian mana pun laporan, termasuk Section 4 Status Rencana Operasi, WAJIB muncul di Section 6 Rekomendasi Pemeriksaan Penunjang. Frasa 'menunggu hasil CT scan' hanya boleh dipakai jika CT scan benar-benar tercantum dalam daftar rekomendasi radiologi Section 6; jika CT tidak direkomendasikan, jangan menyebut frasa tersebut.\n"
        . "31. ISTILAH ANATOMI: 'tungkai' berarti ekstremitas bawah/kaki. Untuk cedera tangan atau jari gunakan 'ekstremitas atas', 'tangan', atau 'jari tangan' sesuai lokasi; jangan menyebut tangan/jari sebagai tungkai.\n\n"
        . "Struktur JSON WAJIB (semua field terisi lengkap, tidak ada yang kosong kecuali disebutkan sebaliknya di aturan 13):\n"
        . "{\n"
        . "  \"status\": \"ringkas kondisi pasien\",\n"
        . "  \"anamnesis_lengkap\": \"anamnesis yang sudah dilengkapi/direvisi jadi narasi klinis utuh, lihat aturan 16\",\n"
        . "  \"jenis_operasi\": \"klasifikasi + nama tindakan spesifik, atau keterangan tidak perlu operasi\",\n"
        . "  \"jenis_anestesi\": \"jenis anestesi yang sesuai, atau tidak diperlukan anestesi\",\n"
        . "  \"kasus_tindakan\": \"ringkas kategori kasus & tindakan definitif, berdiri sendiri sebagai konteks lengkap\",\n"
        . "  \"diagnosis_utama\": \"diagnosis kerja/suspek yang paling sesuai dengan fakta; diagnosis definitif hanya bila ada evidence\",\n"
        . "  \"diagnosis_banding\": [\"diagnosis banding 1\", \"diagnosis banding 2\"],\n"
        . "  \"gcs\": \"GCS aktual, contoh E4V5M6 (15) - Compos Mentis; jika tidak tersedia tulis Data belum tersedia\",\n"
        . "  \"gcs_estimasi_ai\": {\"score\": \"E4 V5 M6 (15)\", \"interpretation\": \"estimasi berbasis konteks kasus\", \"basis\": \"alasan singkat komponen E/V/M\", \"status\": \"Estimasi AI — wajib verifikasi\"},\n"
        . "  \"kesadaran\": \"kesadaran aktual sebelum tindakan atau Data belum tersedia\",\n"
        . "  \"motorik\": \"status motorik aktual atau Data belum tersedia\",\n"
        . "  \"gcs_motor_definition\": \"pilih tepat satu: M3 = fleksi abnormal/dekortikasi atau M4 = withdrawal/menarik diri normal; jangan gabungkan keduanya\",\n"
        . "  \"pemeriksaan_pupil\": {\"status\": \"isokor/anisokor/dilatasi atau Data belum tersedia\", \"reaktivitas\": \"reaktif/non reaktif atau Data belum tersedia\", \"catatan\": \"Disability pada trauma kepala dengan kecurigaan peningkatan tekanan intrakranial\"},\n"
        . "  \"status_rencana_operasi\": \"rencana operasi definitif cito tanpa menunggu hasil CT scan hanya bila anisokor/dilatasi dengan tanda herniasi akut; selain itu rencana tentatif — menunggu hasil CT scan\",\n"
        . "  \"ttv\": [{\"label\": \"Tekanan Darah\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"interpretasi berbasis nilai atau Data belum tersedia\"}, {\"label\": \"Nadi / HR\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"...\"}, {\"label\": \"Suhu\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"Jika suhu <36°C, tulis HIPOTERMIA dengan risiko koagulopati dan kaitkan dengan trauma triad of death (hipotermia-asidosis-koagulopati) pada syok hemoragik berat\"}, {\"label\": \"Respirasi / RR\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"...\"}, {\"label\": \"Saturasi O2\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"...\"}],\n"
        . "  \"ttv_estimasi_ai\": [{\"label\": \"Tekanan Darah\", \"value\": \"estimasi AI dengan satuan\", \"note\": \"alasan/interpretasi singkat\", \"basis\": \"konteks kasus\", \"status\": \"Estimasi AI — wajib verifikasi\"}],\n"
        . "  \"lab\": [\"pemeriksaan lab 1\"],\n"
        . "  \"laboratorium_terstruktur\": {\"department\": \"contoh: Hematologi\", \"category\": \"contoh: Complete Blood Count (CBC)\", \"level3_option\": \"contoh: Semua Parameter (Default) (string kosong kalau kategori tidak punya opsi level3)\", \"specimen_type\": \"contoh: Whole Blood EDTA (HANYA SATU string, bukan list semua opsi)\"},\n"
        . "  \"radiologi\": [\"rekomendasi radiologi 1 (teks bebas untuk dibaca manusia)\"],\n"
        . "  \"radiologi_terstruktur\": {\"modality\": \"contoh: X-Ray\", \"category\": \"contoh: Upper Extremity\", \"body_region\": \"contoh: Wrist\", \"projection\": \"contoh: PA (HANYA SATU string, bukan list semua opsi)\", \"clinical_finding\": \"persis dari daftar temuan klinis\"},\n"
        . "  \"emergency\": [{\"pelaku\": \"DPJP\", \"instruksi\": \"\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}, {\"pelaku\": \"Asisten 1\", \"instruksi\": \"DPJP: tolong siapkan alat sesuai stabilisasi.\\nAsisten 1: Baik, dok.\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}],\n"
        . "  \"roleplay_note\": \"narasi roleplay IGD + transparansi data belum tersedia, estimasi, verifikasi, dan handoff tahap berikutnya\",\n"
        . "  \"riwayat_operasi\": {\"ada\": true/false, \"ringkasan\": \"operasi sebelumnya, waktu, sisi/lokasi, kondisi luka/jahitan, komplikasi\", \"rencana_penanganan\": \"apa yang harus dilakukan sekarang dan alasan klinisnya\"},\n"
        . "  \"sop_references\": [\"rujukan SOP relevan\"]\n"
        . "}";
}

function ems_ai_ds_default_surgery_system_prompt(): string
{
    return "Anda adalah dokter spesialis bedah senior Roxwood Hospital dengan pengalaman lebih dari 15 tahun, menyusun rencana operasi (operative note) untuk simulasi/roleplay EMS. Tugas Anda: dari jenis operasi, jenis anestesi, tingkat kompleksitas, dan kasus medis yang diberikan (sepadat apa pun), susun rencana operasi LENGKAP, definitif, dan siap pakai, bukan daftar pertanyaan.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. Pertahankan fakta kasus dan input dokter. Jangan mengarang temuan, hasil operasi, status kesadaran, TTV, atau respons pasien yang tidak disebutkan. Rangkai rencana operasi, tahapan, risiko, dan narasi roleplay secara lengkap dan berbeda sesuai kasus; detail ukur/hasil aktual yang belum tersedia tetap diberi label belum diukur/belum dilakukan.\n"
        . "2. \"durasi\" wajib realistis dan PROPORSIONAL dengan jumlah \"tahapan_prosedur\" dan kompleksitas kasus - makin banyak langkah/makin kompleks, makin lama durasinya. Operasi Minor umumnya 30-90 menit; Mayor 2-8 jam. Format contoh: \"4 Jam 30 Menit\".\n"
        . "3. \"farmakologi\" hanya boleh memuat obat yang didukung indikasi dan data kasus. Jika indikasi, dosis, atau rencana obat tidak tercatat, isi dengan \"Data belum tersedia\"; jangan membuat resep atau dosis konkret untuk melengkapi format.\n"
        . "3a. KESELAMATAN OBAT: untuk bedah saraf/kraniotomi, mata, atau tindakan berisiko perdarahan tinggi, JANGAN meresepkan NSAID/antiplatelet (Ketorolac, Asam Mefenamat, Ibuprofen, Aspirin) - gunakan Paracetamol dan/atau opioid sebagai gantinya. Untuk operasi lain tanpa risiko perdarahan tinggi, NSAID boleh dipakai sesuai indikasi.\n"
        . "4. \"tahapan_prosedur\" wajib memiliki JUMLAH LANGKAH PERSIS SESUAI permintaan eksplisit user (disebutkan sebagai \"JUMLAH LANGKAH: N\" pada pesan user) - tidak boleh kurang maupun lebih. Susun N langkah logis yang spesifik terhadap tindakan, kasus, dan SOP; jangan menyalin template langkah yang sama. Detail pelaksanaan yang belum terjadi ditulis sebagai rencana/roleplay, bukan hasil aktual.\n"
        . "9. Ikuti pembagian peran & kewenangan dari referensi - DPJP sebagai operator utama melakukan tindakan definitif, Asisten 1 & 2 membantu atas instruksi dan supervisi. Anestesi lokal oleh co-ass tidak boleh ditulis sebagai tindakan mandiri tanpa supervisi; catat operator aktual hanya bila diberikan. ORIF/Open Reduction Internal Fixation selalu Mayor.\n"
        . "10. \"risiko_komplikasi\" hanya memuat risiko yang relevan dengan jenis tindakan; jangan menyatakannya sebagai kejadian aktual.\n"
        . "11. \"laporan_pasca_operasi\" adalah ringkasan rencana/operative note yang dirangkai model sesuai kasus; labeli sebagai RENCANA/ROLEPLAY bila tindakan belum dinyatakan dilakukan dan jangan menulis hasil aktual.\n"
        . "12. Bahasa Indonesia medis baku. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n\n"
        . "Struktur JSON WAJIB (field tetap ada; model wajib mengisi narasi/rencana secara lengkap, sedangkan nilai ukur dan hasil aktual yang tidak tersedia memakai label verifikasi):\n"
        . "{\n"
        . "  \"durasi\": \"contoh: 4 Jam 30 Menit\",\n"
        . "  \"farmakologi\": {\"pra_operatif\": [{\"nama\": \"...\", \"dosis\": \"...\", \"catatan\": \"...\"}], \"intra_operatif\": [...], \"post_operatif\": [...], \"pemulangan\": [...]},\n"
        . "  \"tahapan_prosedur\": [{\"pelaku\": \"DPJP\", \"instruksi\": \"\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}, {\"pelaku\": \"Asisten 1\", \"instruksi\": \"DPJP: tolong siapkan set bedah.\\nAsisten 1: Baik, dok.\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}],\n"
        . "  \"risiko_komplikasi\": [{\"judul\": \"nama risiko\", \"deskripsi\": \"penjelasan singkat\"}],\n"
        . "  \"laporan_pasca_operasi\": \"ringkasan rencana operative note 2-4 kalimat\",\n"
        . "  \"sop_references\": [\"rujukan SOP yang dipakai untuk rencana ini\"]\n"
        . "}";
}

function ems_ai_ds_reference_suffix(bool $includeMantra = true, bool $igdOnly = false): string
{
    $suffix = "\n\nSOP GUARDRAIL WAJIB (tidak boleh dilanggar):\n";
    foreach (ems_ai_ds_quick_sop_rules() as $rule) {
        $suffix .= "- {$rule}\n";
    }

    if ($includeMantra) {
        $mantra = ems_ai_ds_first_aid_mantra();
        if (trim($mantra) !== '') {
            $suffix .= "\nREFERENSI MANTRA RESMI (Kamus Me/Do Pertolongan Pertama Roxwood Hospital):\n{$mantra}\n";
        }
    }

    if ($igdOnly) {
        $suffix .= "\nREFERENSI PERAN & KEWENANGAN IGD:\n" . ems_ai_ds_role_authority_reference() . "\n";
        $suffix .= "\nBATAS IGD/PRA-OPERASI:\n" . ems_ai_ds_igd_authority_reference() . "\n";
        return $suffix;
    }

    $suffix .= "\nREFERENSI ANIMASI /e (Kumpulan Mantra Operasi Roxwood Hospital) - pilih kode paling sesuai untuk field \"animasi\":\n"
        . ems_ai_ds_anim_mantra_reference_text() . "\n";
    $suffix .= "\nREFERENSI PERAN & KEWENANGAN:\n" . ems_ai_ds_role_authority_reference() . "\n";
    $suffix .= "\nREFERENSI KLASIFIKASI OPERASI:\n" . ems_ai_ds_operation_classification_reference();

    return $suffix;
}

/**
 * Kalau $data[$exactKey] kosong, cari key LAIN di $data yang mengandung
 * $containsNeedle di namanya (case-insensitive) dan pakai nilainya sebagai
 * pemulihan — dipakai untuk menutupi typo nama field dari model AI (mis.
 * model membalas "rolepy_note" alih-alih "roleplay_note"; exact-match
 * lookup PHP normal tidak akan pernah menemukannya). Tidak mengubah $data
 * kalau $exactKey sudah terisi atau tidak ada key lain yang cocok.
 */
function ems_ai_ds_recover_field(array &$data, string $exactKey, string $containsNeedle): void
{
    if (!empty($data[$exactKey])) {
        return;
    }

    foreach ($data as $key => $value) {
        if ($key === $exactKey || !is_string($value) || trim($value) === '') {
            continue;
        }
        if (str_contains(strtolower((string) $key), $containsNeedle)) {
            $data[$exactKey] = $value;
            return;
        }
    }
}

/**
 * Panggil sekali setelah $data diterima dari AI (generate baru) ATAU setelah
 * result_json di-decode (menampilkan laporan lama) — supaya laporan yang
 * SUDAH tersimpan dengan key salah ketik (mis. laporan lama sebelum aturan
 * 15 ditambahkan ke prompt) tetap tampil benar tanpa perlu di-generate ulang.
 */
function ems_ai_ds_effective_operation_category(string $category, string $caseText): string
{
    // Instruksi eksplisit "minor" dari kasus harus dipertahankan. Jangan
    // mengeskalasi hanya karena kata operasi muncul di anamnesis; eskalasi
    // hanya berlaku bila tindakan mayor memang disebutkan secara spesifik.
    $explicitMinor = preg_match('/\b(?:operasi|tindakan|prosedur)\s+minor\b|\bminor\b/iu', $caseText) === 1;
    $majorProcedure = preg_match('/\b(?:orif|open\s+reduction\s+internal\s+fixation|laparotomi|laparotomi\s+eksplorasi|kraniotomi|craniotomy|thorakotomi|thoracotomy|seksio\s+sesarea|sectio\s+caesarea|histerektomi|amputasi)\b/iu', $category . ' ' . $caseText) === 1;
    if ($explicitMinor && !$majorProcedure) {
        $category = preg_replace('/\bmayor\b/iu', 'Minor', $category) ?? $category;
        return $category !== '' ? $category : 'Minor — tindakan definitif sesuai lokasi cedera.';
    }
    $operationText = strtolower($category . ' ' . $caseText);
    if (str_contains($operationText, 'orif') || str_contains($operationText, 'open reduction internal fixation')) {
        return 'Mayor';
    }

    return $category;
}

function ems_ai_ds_estimate_status_label(): string
{
    return 'Estimasi AI — wajib verifikasi';
}

function ems_ai_ds_is_missing_clinical_value(mixed $value): bool
{
    return in_array(mb_strtolower(trim((string) $value)), ['', '-', 'data belum tersedia', 'belum diukur', 'belum dinilai'], true);
}

function ems_ai_ds_ttv_slot_labels(): array
{
    return [
        'tekanan_darah' => 'Tekanan Darah',
        'nadi' => 'Nadi',
        'suhu' => 'Suhu',
        'respirasi' => 'Respirasi',
        'saturasi_o2' => 'Saturasi O2',
    ];
}

function ems_ai_ds_ttv_slot_key(string $label): string
{
    $label = mb_strtolower(trim($label));
    foreach ([
        'tekanan_darah' => ['tekanan', 'blood pressure'],
        'nadi' => ['nadi', 'pulse', 'heart rate'],
        'suhu' => ['suhu', 'temperature'],
        'respirasi' => ['respirasi', 'respiratory', 'rr'],
        'saturasi_o2' => ['saturasi', 'spo2', 'sao2', 'oxygen'],
    ] as $key => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($label, $needle)) {
                return $key;
            }
        }
    }

    return '';
}

function ems_ai_ds_ttv_temperature_celsius(string $value): ?float
{
    if (!preg_match('/(-?\d+(?:[\.,]\d+)?)\s*°?\s*C\b/iu', $value, $match)) {
        return null;
    }

    return (float) str_replace(',', '.', $match[1]);
}

function ems_ai_ds_ttv_clean_value(string $value): string
{
    $clean = preg_replace('/\s*[—–-]?\s*\b(?:hipotensi\s+permisif|permissive\s+hypotension)\b\s*(?:sebagai\s+temuan\s+ttv|sebagai\s+temuan|pada\s+ttv)?\s*/iu', ' ', $value) ?? $value;
    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;
    return trim($clean, " \t\r\n—–-,:;");
}

function ems_ai_ds_ttv_clinical_note(string $label, string $value, string $note = ''): string
{
    $key = ems_ai_ds_ttv_slot_key($label);
    $value = ems_ai_ds_ttv_clean_value($value);
    $temperature = $key === 'suhu' ? ems_ai_ds_ttv_temperature_celsius($value) : null;
    if ($temperature !== null && $temperature < 36) {
        return 'HIPOTERMIA; suhu <36°C pada syok hemoragik berat meningkatkan risiko koagulopati dan merupakan bagian dari trauma triad of death (hipotermia-asidosis-koagulopati).';
    }

    $note = trim($note);
    $hasPermissiveHypotension = preg_match('/\b(?:hipotensi\s+permisif|permissive\s+hypotension)\b/iu', $note) === 1;
    $systolic = null;
    if ($key === 'tekanan_darah' && preg_match('/\b(\d{2,3})\s*\/\s*\d{2,3}\b/', $value, $pressureMatch)) {
        $systolic = (int) $pressureMatch[1];
    }
    if ($key === 'tekanan_darah' && ($hasPermissiveHypotension || ($systolic !== null && $systolic < 90))) {
        return 'Tekanan darah rendah; bila konteks trauma sesuai, tanda awal syok hemoragik ringan-sedang; strategi resusitasi cairan dibahas terpisah; wajib verifikasi.';
    }
    if ($hasPermissiveHypotension) {
        return 'Strategi resusitasi cairan dibahas terpisah; interpretasi TTV wajib diverifikasi.';
    }

    $note = preg_replace('/\b(?:wajib\s+)?dipertahankan\b[^.;\n]*/iu', '', $note) ?? $note;
    $note = trim($note, " .;\t\r\n");
    $genericTemperatureNote = $key === 'suhu' && preg_match('/berdasarkan\s+konteks\s+kasus|data\s+sumber|wajib\s+verifikasi/iu', $note) === 1;
    if ($note !== '' && !$genericTemperatureNote) {
        return $note;
    }

    if ($key === 'suhu') {
        if ($temperature !== null && $temperature >= 38) {
            return 'Demam (≥38°C); dapat mencerminkan respons inflamasi atau infeksi dan wajib diverifikasi.';
        }
        return 'Suhu dalam rentang normotermia; status klinis wajib diverifikasi.';
    }

    return match ($key) {
        'tekanan_darah' => 'Menilai perfusi dan kemungkinan syok; wajib verifikasi.',
        'nadi' => 'Menilai respons kompensasi hemodinamik; wajib verifikasi.',
        'respirasi' => 'Menilai ventilasi dan kompensasi respirasi; wajib verifikasi.',
        'saturasi_o2' => 'Menilai oksigenasi; wajib verifikasi.',
        default => 'Interpretasi klinis wajib verifikasi.',
    };
}

function ems_ai_ds_ttv_values_match(string $left, string $right): bool
{
    $normalize = static function (string $value): string {
        return preg_replace('/\s+/u', '', mb_strtolower(trim($value))) ?? mb_strtolower(trim($value));
    };

    return $normalize($left) !== '' && $normalize($left) === $normalize($right);
}

function ems_ai_ds_ttv_value_is_explicit_in_source(string $label, string $value, string $sourceText): bool
{
    $value = trim($value);
    $sourceText = trim($sourceText);
    if ($value === '' || $sourceText === '') {
        return false;
    }

    $normalizedValue = preg_replace('/\s+/u', '', mb_strtolower($value)) ?? mb_strtolower($value);
    $normalizedSource = preg_replace('/\s+/u', '', mb_strtolower($sourceText)) ?? mb_strtolower($sourceText);
    if (str_contains($normalizedSource, $normalizedValue)) {
        return true;
    }

    $key = ems_ai_ds_ttv_slot_key($label);
    if ($key === 'suhu' && preg_match('/(-?\d+(?:[\.,]\d+)?)\s*°?\s*c\b/iu', $value, $match)) {
        return preg_match('/' . preg_quote($match[1], '/') . '\\s*°?\\s*c\\b/iu', $sourceText) === 1;
    }

    return false;
}

function ems_ai_ds_ttv_promote_unverified_matches(array $actualItems, array $estimatedItems, string $sourceText): array
{
    $estimates = [];
    foreach ($estimatedItems as $estimate) {
        if (!is_array($estimate)) {
            continue;
        }
        $key = ems_ai_ds_ttv_slot_key((string) ($estimate['label'] ?? ''));
        $value = trim((string) ($estimate['value'] ?? ''));
        if ($key !== '' && $value !== '') {
            $estimates[$key] = $value;
        }
    }

    foreach ($actualItems as $index => $vital) {
        if (!is_array($vital)) {
            continue;
        }
        $key = ems_ai_ds_ttv_slot_key((string) ($vital['label'] ?? ''));
        $value = trim((string) ($vital['value'] ?? ''));
        if (
            $key !== ''
            && isset($estimates[$key])
            && ems_ai_ds_ttv_values_match($value, $estimates[$key])
            && !ems_ai_ds_ttv_value_is_explicit_in_source((string) ($vital['label'] ?? ''), $value, $sourceText)
        ) {
            $actualItems[$index]['value'] = 'Data belum tersedia';
            $actualItems[$index]['note'] = 'Data faktual belum tersedia; nilai berikut ditampilkan sebagai estimasi AI.';
        }
    }

    return $actualItems;
}

function ems_ai_ds_normalize_ttv_actual(mixed $items): array
{
    $slots = ems_ai_ds_ttv_slot_labels();
    $known = [];
    $extras = [];
    $rawItems = is_array($items) ? $items : [];
    // Gemini kadang mengirim TTV sebagai object keyed-by-name:
    // {"tekanan_darah":"120/80 ...", ...}. Ubah ke lima slot canonical.
    $isList = array_keys($rawItems) === range(0, count($rawItems) - 1);
    if (!$isList) {
        $keyLabels = [
            'tekanan_darah' => 'Tekanan Darah', 'blood_pressure' => 'Tekanan Darah',
            'nadi' => 'Nadi / HR', 'heart_rate' => 'Nadi / HR',
            'suhu' => 'Suhu', 'temperature' => 'Suhu',
            'respirasi' => 'Respirasi / RR', 'respiratory_rate' => 'Respirasi / RR', 'rr' => 'Respirasi / RR',
            'saturasi_o2' => 'Saturasi O2', 'spo2' => 'Saturasi O2', 'oxygen_saturation' => 'Saturasi O2',
        ];
        $converted = [];
        foreach ($rawItems as $key => $value) {
            $label = $keyLabels[mb_strtolower((string) $key)] ?? ucwords(str_replace('_', ' ', (string) $key));
            $converted[] = is_array($value) ? array_merge(['label' => $label], $value) : ['label' => $label, 'value' => (string) $value];
        }
        $rawItems = $converted;
    }
    foreach ($rawItems as $position => $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = ems_ai_ds_ttv_slot_key((string) ($item['label'] ?? ''));
        if ($key === '' && $position < count($slots)) {
            $slotKeys = array_keys($slots);
            $key = $slotKeys[$position] ?? '';
        }
        $normalized = [
            'label' => $key !== '' ? $slots[$key] : trim((string) ($item['label'] ?? '')),
            'value' => ems_ai_ds_ttv_clean_value(trim((string) ($item['value'] ?? ''))) ?: 'Data belum tersedia',
            'note' => '',
        ];
        $normalized['note'] = ems_ai_ds_ttv_clinical_note($normalized['label'], $normalized['value'], (string) ($item['note'] ?? ''));
        if ($key !== '' && !isset($known[$key])) {
            $known[$key] = $normalized;
        } elseif ($key === '' && $normalized['label'] !== '') {
            $extras[] = $normalized;
        }
    }

    $normalized = [];
    foreach ($slots as $key => $label) {
        $normalized[] = $known[$key] ?? [
            'label' => $label,
            'value' => 'Data belum tersedia',
            'note' => 'Data belum tersedia',
        ];
    }

    return array_merge($normalized, $extras);
}

function ems_ai_ds_prepare_ttv_display(mixed $actualItems, mixed $estimatedItems): array
{
    $slots = ems_ai_ds_ttv_slot_labels();
    $estimates = [];
    foreach (is_array($estimatedItems) ? $estimatedItems : [] as $estimate) {
        if (!is_array($estimate)) {
            continue;
        }
        $key = ems_ai_ds_ttv_slot_key((string) ($estimate['label'] ?? ''));
        $value = trim((string) ($estimate['value'] ?? ''));
        if ($key !== '' && $value !== '') {
            $estimate['label'] = $slots[$key];
            $estimates[$key] = $estimate;
        }
    }

    $display = [];
    foreach (ems_ai_ds_normalize_ttv_actual($actualItems) as $vital) {
        $key = ems_ai_ds_ttv_slot_key((string) ($vital['label'] ?? ''));
        if ($key !== '' && ems_ai_ds_is_missing_clinical_value($vital['value'] ?? null) && isset($estimates[$key])) {
            $estimate = $estimates[$key];
            $vital['label'] = $slots[$key];
            $vital['value'] = (string) $estimate['value'];
            $vital['note'] = (string) ($estimate['note'] ?? '');
            $vital['_is_estimate'] = true;
            $vital['_estimate_status'] = ems_ai_ds_estimate_status_label();
            $vital['_estimate_basis'] = (string) ($estimate['basis'] ?? '');
        }
        $display[] = $vital;
    }

    return $display;
}

function ems_ai_ds_sanitize_forbidden_certainty_language(mixed $value): mixed
{
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $value[$key] = ems_ai_ds_sanitize_forbidden_certainty_language($child);
        }
        return $value;
    }
    if (!is_string($value)) {
        return $value;
    }

    $value = preg_replace(
        '/\bdata\s+(?:dianggap|diasumsikan|dipastikan|ditetapkan)\s+(?:secara\s+)?(?:definitif|pasti|fakta)\b/iu',
        'Data belum tersedia; wajib verifikasi',
        $value
    ) ?? $value;
    return preg_replace(
        '/\b(?:nilai|hasil|pemeriksaan)\b[^.\n]{0,80}\b(?:dianggap|diasumsikan|dipastikan|ditetapkan)\b[^.\n]{0,40}\b(?:definitif|pasti|fakta)\b/iu',
        'Data belum tersedia; wajib verifikasi',
        $value
    ) ?? $value;
}

function ems_ai_ds_gcs_total(mixed $value): ?int
{
    if (!preg_match('/\bE\s*([1-4])\s*V\s*([1-5])\s*M\s*([1-6])\b/i', (string) $value, $match)) {
        return null;
    }
    return (int) $match[1] + (int) $match[2] + (int) $match[3];
}

function ems_ai_ds_gcs_motor_score(mixed $value): ?int
{
    if (!preg_match('/\bM\s*([1-6])\b/i', (string) $value, $match)) {
        return null;
    }

    return (int) $match[1];
}

function ems_ai_ds_gcs_motor_definition(?int $motorScore, string $motorik = ''): string
{
    if ($motorScore === 3) {
        return 'M3 = fleksi abnormal/dekortikasi.';
    }
    if ($motorScore === 4) {
        return 'M4 = withdrawal/menarik diri normal.';
    }
    if ($motorScore !== null) {
        return '';
    }

    $motorik = mb_strtolower($motorik);
    $hasM3Language = preg_match('/fleksi\s+abnormal|dekortikasi/iu', $motorik) === 1;
    $hasM4Language = preg_match('/withdrawal|menarik\s+diri/iu', $motorik) === 1;
    if ($hasM3Language xor $hasM4Language) {
        return $hasM3Language
            ? 'M3 = fleksi abnormal/dekortikasi.'
            : 'M4 = withdrawal/menarik diri normal.';
    }

    return '';
}

function ems_ai_ds_normalize_gcs_motor(array &$data): void
{
    $motorScore = ems_ai_ds_gcs_motor_score($data['gcs'] ?? null);
    if ($motorScore === null && is_array($data['gcs_estimasi_ai'] ?? null)) {
        $motorScore = ems_ai_ds_gcs_motor_score($data['gcs_estimasi_ai']['score'] ?? null);
    }

    $motorEvidence = mb_strtolower(implode(' ', [
        (string) ($data['motorik'] ?? ''),
        (string) ($data['gcs_motor_definition'] ?? ''),
    ]));
    $hasM3Language = preg_match('/fleksi\s+abnormal|dekortikasi/iu', $motorEvidence) === 1;
    $hasM4Language = preg_match('/withdrawal|menarik\s+diri/iu', $motorEvidence) === 1;
    $conflictingMotorEvidence = ($hasM3Language && $hasM4Language)
        || ($motorScore === 3 && $hasM4Language)
        || ($motorScore === 4 && $hasM3Language);
    if ($conflictingMotorEvidence) {
        unset($data['gcs_motor_definition']);
        $data['motorik'] = 'Data belum tersedia';
        return;
    }

    $definition = ems_ai_ds_gcs_motor_definition($motorScore, (string) ($data['motorik'] ?? ''));
    if ($definition === '' && $motorScore === null) {
        $definition = ems_ai_ds_gcs_motor_definition(null, (string) ($data['gcs_motor_definition'] ?? ''));
    }
    if ($definition === '') {
        unset($data['gcs_motor_definition']);
        if ($motorScore !== null) {
            $data['motorik'] = 'Data belum tersedia';
        }
        return;
    }

    $data['gcs_motor_definition'] = $definition;
    $data['motorik'] = str_starts_with($definition, 'M3')
        ? 'fleksi abnormal/dekortikasi'
        : 'withdrawal/menarik diri normal';
}

function ems_ai_ds_sanitize_gcs_motor_language(mixed $value, ?int $motorScore = null): mixed
{
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $value[$key] = ems_ai_ds_sanitize_gcs_motor_language($child, $motorScore);
        }
        return $value;
    }
    if (!is_string($value)) {
        return $value;
    }

    $replacement = match ($motorScore) {
        3 => 'fleksi abnormal/dekortikasi',
        4 => 'withdrawal/menarik diri normal',
        default => 'respons motorik belum dapat ditentukan; data GCS perlu diverifikasi',
    };
    return preg_replace(
        '/fleksi\s+abnormal\s*\/\s*(?:withdrawal|menarik\s+diri)(?:\s+normal)?/iu',
        $replacement,
        $value
    ) ?? $value;
}

function ems_ai_ds_normalize_pupil_assessment(mixed $input): array
{
    $inputArray = is_array($input) ? $input : ['status' => (string) $input];
    $raw = mb_strtolower(trim((string) ($inputArray['status'] ?? '') . ' ' . (string) ($inputArray['ukuran'] ?? '') . ' ' . (string) ($inputArray['size'] ?? '') . ' ' . json_encode($inputArray, JSON_UNESCAPED_UNICODE)));

    $status = 'Data belum tersedia';
    foreach (['anisokor', 'dilatasi', 'isokor'] as $candidate) {
        if (str_contains($raw, $candidate)) {
            $status = $candidate;
            break;
        }
    }

    $reactivityRaw = mb_strtolower(trim((string) ($inputArray['reaktivitas'] ?? $inputArray['reaksi'] ?? $inputArray['reaction'] ?? '') . ' ' . $raw));
    $reactivity = 'Data belum tersedia';
    if (preg_match('/non[- ]?reaktif|tidak\s+reaktif|nonreactive/iu', $reactivityRaw) === 1) {
        $reactivity = 'non reaktif';
    } elseif (preg_match('/\breaktif\b/iu', $reactivityRaw) === 1) {
        $reactivity = 'reaktif';
    }

    return [
        'status' => $status,
        'reaktivitas' => $reactivity,
        'catatan' => 'Disability: periksa ukuran dan reaktivitas pupil; temuan aktual wajib diverifikasi.',
    ];
}

function ems_ai_ds_pupil_indicates_acute_herniation(?array $pupil): bool
{
    if (!is_array($pupil)) {
        return false;
    }

    $text = mb_strtolower(implode(' ', array_map('strval', $pupil)));
    return preg_match('/\banisokor\b|\bdilatasi\b/iu', $text) === 1;
}

function ems_ai_ds_case_indicates_raised_icp(string $caseText): bool
{
    $caseText = mb_strtolower($caseText);
    if (preg_match('/peningkatan\s+tekanan\s+intrakranial|\bttik\b|cushing(?:\s+reflex)?|edema\s+serebri|\bherniasi\b/iu', $caseText) === 1) {
        return true;
    }

    $headTrauma = preg_match('/trauma\s+kepala|cedera\s+kepala|\btbi\b/iu', $caseText) === 1;
    $acutePupil = preg_match('/\banisokor\b|\bdilatasi\b/iu', $caseText) === 1;
    return $headTrauma && $acutePupil;
}

function ems_ai_ds_normalize_pupil_and_operation_status(array &$data, string $caseText): void
{
    $hasPupilInput = array_key_exists('pemeriksaan_pupil', $data) || array_key_exists('pupil', $data);
    $raisedIcp = ems_ai_ds_case_indicates_raised_icp($caseText);
    if (!$raisedIcp && !$hasPupilInput) {
        return;
    }

    $pupilInput = $data['pemeriksaan_pupil'] ?? ($data['pupil'] ?? null);
    $pupil = ems_ai_ds_normalize_pupil_assessment($pupilInput);
    $data['pemeriksaan_pupil'] = $pupil;
    unset($data['pupil']);

    if ($raisedIcp) {
        $data['status_rencana_operasi'] = ems_ai_ds_pupil_indicates_acute_herniation($pupil)
            ? 'rencana operasi definitif cito tanpa menunggu hasil CT scan'
            : 'rencana tentatif — menunggu hasil CT scan';
    }
}

function ems_ai_ds_case_text(array $data): string
{
    $flatten = static function (mixed $value) use (&$flatten): string {
        if (is_array($value)) {
            return implode(' ', array_map($flatten, $value));
        }
        return is_scalar($value) ? (string) $value : '';
    };

    return mb_strtolower($flatten($data));
}

function ems_ai_ds_normalize_anatomy_language(mixed $value): mixed
{
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $value[$key] = ems_ai_ds_normalize_anatomy_language($child);
        }
        return $value;
    }
    if (!is_string($value)) {
        return $value;
    }

    $upper = '(?:jari\\s+tangan|tangan|lengan|bahu|siku|pergelangan\\s+tangan)';
    $value = preg_replace_callback(
        '/\\b(?:tungkai|ekstremitas\\s+bawah)\\b([^.!?;\\n]{0,60})\\b' . $upper . '\\b/iu',
        static function (array $match): string {
            if (preg_match('/\\b(?:dan|serta|dengan|sedangkan)\\b/iu', $match[1]) === 1) {
                return $match[0];
            }
            return preg_replace('/\\b(?:tungkai|ekstremitas\\s+bawah)\\b/iu', 'ekstremitas atas', $match[0], 1) ?? $match[0];
        },
        $value
    ) ?? $value;
    $value = preg_replace_callback(
        '/\\b' . $upper . '\\b([^.!?;\\n]{0,60})\\b(?:tungkai|ekstremitas\\s+bawah)\\b/iu',
        static function (array $match): string {
            if (preg_match('/\\b(?:dan|serta|dengan|sedangkan)\\b/iu', $match[1]) === 1) {
                return $match[0];
            }
            return preg_replace('/\\b(?:tungkai|ekstremitas\\s+bawah)\\b/iu', 'ekstremitas atas', $match[0], 1) ?? $match[0];
        },
        $value
    ) ?? $value;

    return $value;
}

function ems_ai_ds_physiologic_red_flag_text(): string
{
    return 'RED FLAG: derajat syok/penurunan kesadaran tidak proporsional dengan cedera yang teridentifikasi — curigai cedera tambahan tersembunyi (kepala, toraks, abdomen, sumber perdarahan lain) yang belum teridentifikasi, perlu secondary survey menyeluruh dan pencitraan tambahan.';
}

function ems_ai_ds_assess_physiologic_consistency(array $data, string $sourceText = ''): array
{
    $sourceText = mb_strtolower(trim((string) ems_ai_ds_normalize_anatomy_language($sourceText)));
    $reportedText = trim($sourceText . ' ' . ems_ai_ds_case_text($data));
    $gcsTotal = ems_ai_ds_gcs_total($data['gcs'] ?? null)
        ?? ems_ai_ds_gcs_total($data['gcs_estimasi_ai']['score'] ?? null);
    $lowConsciousness = ($gcsTotal !== null && $gcsTotal <= 8)
        || preg_match('/\\b(?:koma|tidak\\s+sadar|penurunan\\s+kesadaran\\s+berat)\\b/iu', $reportedText) === 1;
    $severeShock = preg_match('/\\b(?:syok\\s+(?:hemoragik|hipovolemik)?\\s*berat|syok\\s+kelas\\s+(?:iii|iv|3|4)|perdarahan\\s+(?:masif|hebat|tidak\\s+terkontrol)|hipotensi\\s+berat|hemodinamik(?:nya)?\\s+tidak\\s+stabil|tidak\\s+stabil\\s+hemodinamik)\\b/iu', $reportedText) === 1
        || preg_match('/\\b(?:[4-6]\\d|70)\\s*\/\\s*\\d{2,3}\\s*(?:mmhg)?\\b/iu', $reportedText) === 1;
    $minorFingerInjury = preg_match('/\\b(?:amputasi|avulsi|luka|cedera|fraktur|patah)\\b[^.!?;\\n]{0,70}\\b(?:satu|1)\\s*(?:buah\\s*)?jari(?:\\s+tangan)?\\b/iu', $sourceText) === 1
        || preg_match('/\\b(?:amputasi|avulsi|luka|cedera|fraktur|patah)\\b[^.!?;\\n]{0,70}\\b(?:jari\\s+tangan|distal|superfisial|minor)\\b/iu', $sourceText) === 1;
    $hasMajorBleedingSource = preg_match('/\\b(?:abdomen|perut|toraks|dada|pelvis|panggul|femur|paha|tungkai|kaki|perdarahan\\s+internal|luka\\s+tembus|penetrasi)\\b/iu', $sourceText) === 1;
    $hasHeadCause = preg_match('/\\b(?:kepala|kranial|otak|intrakranial|tbi|cedera\\s+kepala|trauma\\s+kepala|herniasi|edema\\s+serebri)\\b/iu', $sourceText) === 1;
    $hasAlternateCause = preg_match('/\\b(?:hipoksia|hipoksemia|hipotensi|kejang|intoksikasi|keracunan|hipoglikemia|gangguan\\s+metabolik|sedasi|obat|asfiksia|henti\\s+napas|henti\\s+jantung)\\b/iu', $sourceText) === 1;
    $hasInjury = preg_match('/\\b(?:trauma|cedera|luka|amputasi|avulsi|fraktur|patah|penetrasi|luka\\s+tusuk)\\b/iu', $sourceText) === 1;

    if ($minorFingerInjury && (($severeShock && !$hasMajorBleedingSource) || ($lowConsciousness && !$hasHeadCause && !$hasAlternateCause))) {
        return [
            'status' => 'red_flag',
            'shock' => $severeShock ? 'berat dilaporkan' : 'tidak teridentifikasi sebagai berat',
            'kesadaran' => $lowConsciousness ? 'rendah/koma dilaporkan' : 'tidak teridentifikasi rendah',
            'mekanisme_cedera' => 'cedera distal minor yang teridentifikasi',
            'alasan' => 'Volume/lokasi cedera distal minor yang teridentifikasi tidak cukup untuk menjelaskan derajat syok atau penurunan kesadaran yang dilaporkan; sumber tambahan belum terbukti.',
            'red_flag' => ems_ai_ds_physiologic_red_flag_text(),
        ];
    }
    if (($severeShock || $lowConsciousness) && $hasInjury && !$hasMajorBleedingSource && !$hasHeadCause && !$hasAlternateCause) {
        return [
            'status' => 'red_flag',
            'shock' => $severeShock ? 'berat dilaporkan' : 'tidak teridentifikasi sebagai berat',
            'kesadaran' => $lowConsciousness ? 'rendah/koma dilaporkan' : 'tidak teridentifikasi rendah',
            'mekanisme_cedera' => 'sumber cedera tambahan belum terdokumentasi',
            'alasan' => 'Mekanisme atau sumber cedera yang tersedia belum cukup untuk menjelaskan gejala sistemik; verifikasi diperlukan sebelum diagnosis definitif.',
            'red_flag' => ems_ai_ds_physiologic_red_flag_text(),
        ];
    }

    return [
        'status' => ($severeShock || $lowConsciousness) ? 'tidak_ditemukan_ketidaksesuaian' : 'belum_terpicu',
        'shock' => $severeShock ? 'berat dilaporkan' : 'tidak teridentifikasi berat',
        'kesadaran' => $lowConsciousness ? 'rendah/koma dilaporkan' : 'tidak teridentifikasi rendah',
        'mekanisme_cedera' => $hasInjury ? 'mekanisme cedera terdokumentasi' : 'data mekanisme cedera belum cukup',
        'alasan' => 'Tidak ditemukan ketidaksesuaian fisiologis dari data yang tersedia; verifikasi klinis tetap wajib.',
        'red_flag' => '',
    ];
}

function ems_ai_ds_apply_physiologic_consistency(array $data, string $sourceText = ''): array
{
    $sourceText = (string) ems_ai_ds_normalize_anatomy_language($sourceText);
    $assessment = ems_ai_ds_assess_physiologic_consistency($data, $sourceText);
    $data['konsistensi_fisiologis'] = $assessment;
    if (($assessment['status'] ?? '') !== 'red_flag') {
        return $data;
    }

    $redFlag = ems_ai_ds_physiologic_red_flag_text();
    $verification = 'Verifikasi sumber perdarahan/cedera tambahan sebelum menetapkan diagnosis definitif.';
    $redFlags = $data['red_flags'] ?? [];
    $redFlags = is_array($redFlags) ? $redFlags : [$redFlags];
    if (!in_array($redFlag, $redFlags, true)) {
        $redFlags[] = $redFlag;
    }
    $data['red_flags'] = array_values(array_filter(array_map('strval', $redFlags), static fn (string $item): bool => trim($item) !== ''));

    foreach (['anamnesis_lengkap', 'roleplay_note'] as $field) {
        $note = trim((string) ($data[$field] ?? ''));
        if ($note === '' && $field === 'anamnesis_lengkap') {
            $note = trim($sourceText);
        }
        foreach ([$redFlag, $verification] as $required) {
            if (!str_contains($note, $required)) {
                $note = trim($note . ($note === '' ? '' : "\n") . $required);
            }
        }
        $data[$field] = $note;
    }

    $diagnosis = trim((string) ($data['diagnosis_utama'] ?? ''));
    if ($diagnosis !== '' && preg_match('/\\b(?:suspek|dugaan|belum\\s+dapat|belum\\s+terbukti|sementara)\\b/iu', $diagnosis) !== 1) {
        $data['diagnosis_utama'] = 'Diagnosis kerja belum definitif; verifikasi diperlukan: ' . $diagnosis;
    }
    $status = trim((string) ($data['status'] ?? ''));
    $data['status'] = trim($status . ($status === '' ? '' : ' ') . 'Ketidaksesuaian fisiologis; diagnosis definitif ditangguhkan sampai sumber tambahan diverifikasi.');

    $emergency = is_array($data['emergency'] ?? null) ? $data['emergency'] : [];
    $emergencyText = mb_strtolower(implode(' ', array_map(static fn ($item): string => is_array($item) ? implode(' ', array_map('strval', $item)) : (string) $item, $emergency)));
    if (!str_contains($emergencyText, 'secondary survey')) {
        $emergency[] = [
            'pelaku' => 'DPJP',
            'instruksi' => '',
            'aksi' => 'melakukan secondary survey menyeluruh untuk mencari cedera kepala, toraks, abdomen, atau sumber perdarahan lain yang belum teridentifikasi',
            'hasil' => 'Sumber cedera atau perdarahan tambahan belum teridentifikasi; hasil secondary survey dan pencitraan tambahan wajib diverifikasi.',
            'animasi' => 'mechanic',
        ];
    }
    $data['emergency'] = $emergency;
    $radiology = array_values(array_filter((array) ($data['radiologi'] ?? []), static fn ($item): bool => trim((string) $item) !== ''));
    if (preg_match('/pencitraan\\s+tambahan/iu', implode(' ', array_map('strval', $radiology))) !== 1) {
        $radiology[] = 'Pencitraan tambahan sesuai temuan secondary survey (kepala, toraks, abdomen, atau sumber perdarahan lain) direkomendasikan; hasil belum tersedia dan wajib diverifikasi.';
    }
    $data['radiologi'] = $radiology;

    return $data;
}

function ems_ai_ds_ensure_supporting_exam_references(array &$data, string $sourceText = ''): void
{
    // Katalog/SOP hanya menjadi konteks untuk model. Jangan menambahkan
    // rekomendasi generik dari PHP karena itu menyebabkan duplikasi dan
    // kalimat placeholder pada laporan.
    foreach (['radiologi', 'lab'] as $field) {
        $items = is_array($data[$field] ?? null) ? $data[$field] : [$data[$field] ?? ''];
        $seen = [];
        $clean = [];
        foreach ($items as $item) {
            $text = is_array($item) ? implode('; ', array_map('strval', $item)) : trim((string) $item);
            $key = mb_strtolower((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
            if ($text === '' || $key === 'array' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = $text;
        }
        $data[$field] = $clean;
    }
}

function ems_ai_ds_text_indicates_evisceration(string $text): bool
{
    $positivePatterns = [
        '/\\b(?:eviserasi|eviscerasi)\\b/iu',
        '/\\b(?:usus|omentum|visera|organ)\\b[^.!?;\\n]{0,50}\\b(?:terpapar|terekspos|keluar|menonjol|prolaps)\\b/iu',
        '/\\b(?:terpapar|terekspos|keluar|menonjol|prolaps)\\b[^.!?;\\n]{0,50}\\b(?:usus|omentum|visera|organ)\\b/iu',
    ];
    $negativePattern = '/(?:\\b(?:tidak|tanpa|belum|negatif)\\b[^.!?;,\\n]{0,70}\\b(?:eviserasi|eviscerasi|usus|omentum|visera|organ)\\b|\\b(?:eviserasi|eviscerasi|usus|omentum|visera|organ)\\b[^.!?;,\\n]{0,70}\\b(?:tidak|tanpa|belum|negatif)\\b)/iu';

    foreach ($positivePatterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            continue;
        }
        foreach ($matches[0] as $match) {
            $window = substr($text, max(0, (int) $match[1] - 90), 180);
            if (preg_match($negativePattern, $window) === 1) {
                continue;
            }
            return true;
        }
    }

    return false;
}

function ems_ai_ds_default_radiology_selection(string $caseText): array
{
    $finding = preg_match('/\b(?:perdarahan|hematoma|syok|eviserasi|peritonitis)\b/iu', $caseText) === 1
        ? 'Perdarahan / Hematoma'
        : (preg_match('/\b(?:fraktur|patah|dislokasi)\b/iu', $caseText) === 1 ? 'Fraktur / Patah Tulang' : 'Normal / Sehat');

    if (preg_match('/\b(?:kepala|otak|gcs|tidak sadar|kejang|pupil)\b/iu', $caseText) === 1) {
        return ['CT Scan', 'Kepala & Otak', 'CT Kepala Non-Kontras', 'Axial', $finding];
    }
    if (preg_match('/\b(?:abdomen|perut|eviserasi|usus|peritonitis)\b/iu', $caseText) === 1) {
        return ['Ultrasound', 'Abdomen', 'USG Whole Abdomen', 'B-Mode (Grayscale)', $finding];
    }
    if (preg_match('/\b(?:tangan|lengan|bahu|siku|pergelangan)\b/iu', $caseText) === 1) {
        return ['X-Ray', 'Upper Extremity', 'Hand', 'PA', $finding];
    }
    if (preg_match('/\b(?:kaki|tungkai|lutut|ankle|pergelangan kaki)\b/iu', $caseText) === 1) {
        return ['X-Ray', 'Lower Extremity', 'Knee', 'AP', $finding];
    }

    return ['X-Ray', 'Thorax', 'Chest', 'AP Supine (Portable)', $finding];
}

function ems_ai_ds_ensure_igd_radiology(array &$data, string $sourceText = ''): void
{
    $caseText = mb_strtolower(trim($sourceText . ' ' . ems_ai_ds_case_text($data)));
    $rads = array_values(array_filter((array) ($data['radiologi'] ?? []), static fn ($item): bool => trim((string) $item) !== ''));
    $abdomenEmergency = preg_match('/(?:abdomen|perut).*(?:syok|perdarahan|peritonitis|luka tusuk|penetrasi)|(?:syok|perdarahan|peritonitis|luka tusuk|penetrasi).*(?:abdomen|perut)/iu', $caseText) === 1;
    $needsBedside = $abdomenEmergency || str_contains($caseText, 'ct scan dilewati') || str_contains($caseText, 'ct scan ditunda');

    if ($needsBedside) {
        $hasFast = preg_match('/\bfast\b|ultrasound\s+bedside|usg\s+bedside/iu', implode(' ', $rads)) === 1;
        $hasPortable = preg_match('/x[- ]?ray.*portable|rontgen.*portable/iu', implode(' ', $rads)) === 1;
        if (!$hasFast) {
            $rads[] = 'FAST (Ultrasound bedside) direkomendasikan pada tahap IGD; hasil belum tersedia dan wajib diverifikasi.';
        }
        if (!$hasPortable) {
            $rads[] = 'X-ray portable abdomen/thorax dipertimbangkan pada tahap IGD sesuai kondisi; hasil belum tersedia dan wajib diverifikasi.';
        }
        $ctText = implode(' ', $rads);
        if (preg_match('/ct\s+scan[^.]*\b(?:dilewati|dihilangkan|tidak\s+direkomendasikan)\b/iu', $ctText) === 1) {
            $rads = array_map(static function ($item): string {
                return preg_replace('/CT\s+scan[^.]*\b(?:dilewati|dihilangkan|tidak\s+direkomendasikan)\b[^.]*\.?/iu', 'CT scan: DITUNDA — dijadwalkan di tahap Radiologi/pasca stabilisasi; hasil belum tersedia.', (string) $item) ?? (string) $item;
            }, $rads);
        }
        if (preg_match('/\bct\s+scan\b/iu', implode(' ', $rads)) !== 1) {
            $rads[] = 'CT scan: DITUNDA — dijadwalkan di tahap Radiologi/pasca stabilisasi; hasil belum tersedia.';
        }
    }
    $defaultRadiology = ems_ai_ds_default_radiology_selection($caseText);
    if ($rads === []) {
        $rads[] = $defaultRadiology[0] . ' ' . $defaultRadiology[2] . ' ' . $defaultRadiology[3] . ' direkomendasikan; hasil belum tersedia dan wajib diverifikasi.';
    }
    $data['radiologi'] = $rads;

    $structured = is_array($data['radiologi_terstruktur'] ?? null) ? $data['radiologi_terstruktur'] : [];
    if (function_exists('ems_ai_radiology_is_valid_selection')) {
        $valid = trim((string) ($structured['modality'] ?? '')) !== ''
            && ems_ai_radiology_is_valid_selection(
                (string) ($structured['modality'] ?? ''),
                (string) ($structured['category'] ?? ''),
                (string) ($structured['body_region'] ?? ''),
                (string) ($structured['projection'] ?? '')
            )
            && in_array((string) ($structured['clinical_finding'] ?? ''), ems_ai_radiology_clinical_findings(), true);
        if (!$valid) {
            [$modality, $category, $bodyRegion, $projection, $clinicalFinding] = $defaultRadiology;
            $structured = compact('modality', 'category', 'bodyRegion', 'projection', 'clinicalFinding');
            $structured['body_region'] = $structured['bodyRegion'];
            $structured['clinical_finding'] = $structured['clinicalFinding'];
            unset($structured['bodyRegion'], $structured['clinicalFinding']);
        }
    } else {
        $structured = [];
    }
    $data['radiologi_terstruktur'] = $structured;
}

function ems_ai_ds_normalize_ttv_estimates(array $data): array
{
    $estimates = ems_ai_ds_normalize_estimated_ttv($data['ttv_estimasi_ai'] ?? []);
    $estimateByKey = [];
    foreach ($estimates as $estimate) {
        $key = ems_ai_ds_ttv_slot_key((string) ($estimate['label'] ?? ''));
        if ($key !== '') {
            $estimate['label'] = ems_ai_ds_ttv_slot_labels()[$key];
            $estimateByKey[$key] = $estimate;
        }
    }

    $ordered = [];
    foreach (ems_ai_ds_ttv_slot_labels() as $key => $label) {
        if (isset($estimateByKey[$key])) {
            $ordered[] = $estimateByKey[$key];
        }
    }
    return $ordered;
}

function ems_ai_ds_sanitize_igd_emergency_items(array $items, ?int $gcsTotal = null, string $caseText = ''): array
{
    $items = ems_ai_ds_sanitize_step_items($items);
    $blocked = '/(?:insisi|eksplorasi|evakuasi|penjahitan|\bjahit\b|reseksi|anastomosis|repair\s+(?:perforasi|usus|organ)|rongga\s+abdomen\s+terbuka|lapangan\s+operasi|operasi\s+selesai|tindakan\s+selesai|transfer\s+(?:ke\s+)?(?:icu|rawat\s+inap)|\bicu\b|rawat\s+inap\s+pasca\s+operasi|menelaah\s+hasil\s+(?:laboratorium|lab)\s+dan\s+radiologi)/iu';
    $handoff = '/(?:handoff|serah[- ]terima|siap\s+(?:untuk\s+)?dipindahkan|siap\s+(?:untuk\s+)?diserahkan|dipindahkan\s+ke\s+(?:laboratorium|radiologi|ruang\s+operasi)|diserahkan\s+ke\s+(?:laboratorium|radiologi|ruang\s+operasi))/iu';
    $dryGauze = '/(?:kassa|kasa|gauze)[^.!?;\n]{0,45}(?:kering|dry)|(?:kering|dry)[^.!?;\n]{0,45}(?:kassa|kasa|gauze)/iu';
    $caseText = mb_strtolower($caseText);
    $rawJoined = $caseText . ' ' . implode(' ', array_map(static fn ($item): string => implode(' ', array_map('strval', $item)), $items));
    $hasEvisceration = ems_ai_ds_text_indicates_evisceration($rawJoined);
    $filtered = [];
    foreach ($items as $item) {
        foreach (['aksi', 'hasil'] as $rpField) {
            $item[$rpField] = preg_replace('/\s*(?:sesuai\s+protokol\s+DPJP|sesuai\s+instruksi\s+DPJP|sesuai\s+arahan\s+DPJP)\s*/iu', ' ', (string) ($item[$rpField] ?? '')) ?? (string) ($item[$rpField] ?? '');
            $item[$rpField] = preg_replace('/\b(?:Manitol\s*20%\s+atau\s+NaCl\s*3%\s+hipertonik|NaCl\s*3%\s+hipertonik\s+atau\s+Manitol\s*20%)\b/iu', 'NaCl 3% hipertonik', $item[$rpField]) ?? $item[$rpField];
            $item[$rpField] = preg_replace('/\s*\((?:isokor\/anisokor\/dilatasi|reaktif\/non\s+reaktif)(?:;\s*reaktif\/non\s+reaktif)?\)/iu', '', $item[$rpField]) ?? $item[$rpField];
            $item[$rpField] = trim((string) $item[$rpField]);
        }
        $text = implode(' ', [(string) ($item['instruksi'] ?? ''), (string) ($item['aksi'] ?? ''), (string) ($item['hasil'] ?? '')]);
        if (preg_match($blocked, $text) === 1 || ($hasEvisceration && preg_match($dryGauze, $text) === 1)) {
            continue;
        }
        $filtered[] = $item;
    }

    // Model owns the complete action sequence; PHP only removes forbidden
    // operation steps and cleans RP syntax. No static emergency sequence.
    return $filtered;

    $wetGauze = '/(?=.*(?:kassa|kasa|gauze))(?=.*steril)(?=.*(?:basah|lembab))(?=.*(?:nacl|natrium\\s+klorida))/iu';
    $hasWetGauze = false;
    foreach ($filtered as $item) {
        $itemText = implode(' ', array_map('strval', $item));
        if (preg_match($wetGauze, $itemText) === 1) {
            $hasWetGauze = true;
            break;
        }
    }
    if ($hasEvisceration && !$hasWetGauze) {
        $filtered[] = [
            'pelaku' => 'DPJP',
            'instruksi' => '',
            'aksi' => 'menutup sementara eviserasi atau organ terpapar dengan kassa steril basah/lembab yang dibasahi NaCl 0,9% tanpa mendorong organ kembali',
            'hasil' => 'Jaringan terpapar terlindungi sementara dengan kassa steril lembab NaCl; penutupan definitif bukan tindakan IGD dan hasil aktual wajib diverifikasi.',
            'animasi' => 'mechanic',
        ];
    }

    $joined = mb_strtolower(implode(' ', array_map(static fn ($item): string => implode(' ', array_map('strval', $item)), $filtered)));
    $raisedIcp = ems_ai_ds_case_indicates_raised_icp($caseText);
    if ($raisedIcp && !preg_match('/memeriksa[^.\n]{0,100}\bpupil\b/iu', $joined)) {
        $filtered[] = [
            'pelaku' => 'DPJP',
            'instruksi' => '',
            'aksi' => 'memeriksa ukuran, kesimetrisan, dan reaktivitas pupil (isokor/anisokor/dilatasi; reaktif/non reaktif) sebagai bagian Disability',
            'hasil' => 'Pupil isokor dan reaktif bilateral; tidak ada anisokoria atau defisit refleks pupil pada pemeriksaan Disability.',
            'animasi' => 'mechanic',
        ];
    }
    $joined = mb_strtolower(implode(' ', array_map(static fn ($item): string => implode(' ', array_map('strval', $item)), $filtered)));
    if ($raisedIcp && !preg_match('/osmoterapi|manitol\s+20%|nacl\s+3%\s+hipertonik/iu', $joined)) {
        $filtered[] = [
            'pelaku' => 'DPJP',
            'instruksi' => '',
            'aksi' => 'menyiapkan dan memberikan NaCl 3% hipertonik melalui akses intravena untuk menurunkan tekanan intrakranial',
            'hasil' => 'Tidak ditemukan tanda klinis yang memerlukan osmoterapi segera; tekanan intrakranial tetap dipantau selama stabilisasi.',
            'animasi' => 'syringe',
        ];
    }
    $joined = mb_strtolower(implode(' ', array_map(static fn ($item): string => implode(' ', array_map('strval', $item)), $filtered)));
    if ($gcsTotal !== null && $gcsTotal <= 8 && !preg_match('/intubasi\s+(?:endotrakeal|ett)|\bett\b/iu', $joined)) {
        $filtered[] = [
            'pelaku' => 'DPJP',
            'instruksi' => '',
            'aksi' => 'melakukan intubasi endotrakeal sebagai airway definitif karena GCS ≤8',
            'hasil' => 'Airway definitif berhasil diamankan dengan ETT; ventilasi dan saturasi terpantau stabil.',
            'animasi' => 'mechanic',
        ];
    }
    $joined = mb_strtolower(implode(' ', array_map(static fn ($item): string => implode(' ', array_map('strval', $item)), $filtered)));
    if (!preg_match('/informed\s+consent|persetujuan\s+(?:keluarga|wali)/iu', $joined)) {
        $filtered[] = [
            'pelaku' => 'DPJP',
            'instruksi' => '',
            'aksi' => 'meminta informed consent kepada keluarga atau wali untuk rencana tindakan lanjutan',
            'hasil' => 'Wali memahami kondisi pasien, risiko, dan rencana tindakan; persetujuan roleplay telah diberikan.',
            'animasi' => 'type',
        ];
    }
    $joined = mb_strtolower(implode(' ', array_map(static fn ($item): string => implode(' ', array_map('strval', $item)), $filtered)));
    if (!preg_match('/crossmatch|golongan\s+darah|PRC/iu', $joined)) {
        $filtered[] = [
            'pelaku' => 'Asisten 2',
            'instruksi' => 'DPJP: siapkan pemeriksaan golongan darah dan crossmatch/PRC.\nAsisten 2: Baik, dok.',
            'aksi' => 'mengambil sampel darah, memeriksa golongan darah, dan melakukan crossmatch/PRC',
            'hasil' => 'Sampel darah telah diambil; golongan darah dan crossmatch kompatibel untuk kebutuhan tindakan.',
            'animasi' => 'mechanic',
        ];
    }
    // Bila model mengembalikan emergency terlalu pendek, pertahankan semua
    // langkah uniknya lalu lengkapi hanya mata rantai ABCDE yang hilang.
    // Ini bukan pengganti output model; hanya pengaman agar roleplay IGD
    // tidak berhenti setelah satu-dua tindakan.
    $appendIfMissing = static function (array &$list, string $needle, array $item): void {
        $joined = mb_strtolower(implode(' ', array_map(static fn ($row): string => implode(' ', array_map('strval', $row)), $list)));
        if (!str_contains($joined, mb_strtolower($needle))) {
            $list[] = $item;
        }
    };
    $appendIfMissing($filtered, 'memasang monitor', [
        'pelaku' => 'Asisten 1', 'instruksi' => 'DPJP: pasang monitor EKG, pulse oximeter, dan ukur TTV berkala.\nAsisten 1: Baik, dok.',
        'aksi' => 'memasang monitor EKG, pulse oximeter, manset tekanan darah, dan termometer untuk memantau perubahan TTV',
        'hasil' => 'Monitor aktif; tekanan darah, nadi, respirasi, suhu, dan saturasi tercatat untuk reassessment.', 'animasi' => 'mechanic'
    ]);
    $appendIfMissing($filtered, 'akses intravena', [
        'pelaku' => 'Asisten 2', 'instruksi' => 'DPJP: pasang dua akses IV besar dan siapkan cairan sesuai kondisi hemodinamik.\nAsisten 2: Baik, dok.',
            'aksi' => 'memasang dua akses intravena besar dan mengalirkan cairan resusitasi melalui jalur IV',
        'hasil' => 'Akses IV paten; perfusi perifer dan tekanan darah menunjukkan respons terhadap stabilisasi awal.', 'animasi' => 'syringe'
    ]);
    $appendIfMissing($filtered, 'mempertahankan patensi jalan napas', [
        'pelaku' => 'DPJP', 'instruksi' => '',
        'aksi' => 'menilai dan mempertahankan patensi jalan napas dengan posisi aman, suction bila perlu, serta oksigenasi sesuai saturasi',
        'hasil' => 'Jalan napas terbuka, suara napas dinilai, dan saturasi berada pada target stabilisasi.', 'animasi' => 'mechanic'
    ]);
    $appendIfMissing($filtered, 'menilai pola napas', [
        'pelaku' => 'DPJP', 'instruksi' => '',
        'aksi' => 'menilai frekuensi napas, ekspansi dada, suara napas, kerja napas, dan tanda hipoksia pada tahap Breathing',
        'hasil' => 'Pola napas dan oksigenasi sudah dinilai; gangguan yang ditemukan langsung ditangani sesuai prioritas.', 'animasi' => 'mechanic'
    ]);
    $appendIfMissing($filtered, 'pemeriksaan head-to-toe', [
        'pelaku' => 'DPJP', 'instruksi' => '',
        'aksi' => 'melakukan secondary survey head-to-toe untuk menemukan cedera tersembunyi, deformitas, nyeri tekan, dan perubahan perfusi',
        'hasil' => 'Lokasi cedera utama dan cedera penyerta terpetakan untuk menentukan prioritas tindakan definitif.', 'animasi' => 'mechanic'
    ]);
    $appendIfMissing($filtered, 'kontrol perdarahan', [
        'pelaku' => 'Asisten 1', 'instruksi' => 'DPJP: kontrol perdarahan eksternal dengan balut tekan dan evaluasi perfusi distal.\nAsisten 1: Baik, dok.',
        'aksi' => 'melakukan balut tekan pada sumber perdarahan eksternal dan memeriksa warna, suhu, nadi, sensorik, serta motorik distal',
        'hasil' => 'Perdarahan eksternal terkendali sementara dan perfusi distal tetap terpantau.', 'animasi' => 'mechanic'
    ]);
    $appendIfMissing($filtered, 'reassessment ABCDE', [
        'pelaku' => 'DPJP', 'instruksi' => '',
        'aksi' => 'mengulang primary survey ABCDE setelah intervensi untuk menilai respons pasien dan perubahan TTV',
        'hasil' => 'Respons terhadap stabilisasi tercatat; prioritas handoff ditetapkan berdasarkan kondisi terbaru pasien.', 'animasi' => 'type'
    ]);
    $appendIfMissing($filtered, 'handoff', [
        'pelaku' => 'DPJP',
        'instruksi' => '',
        'aksi' => 'menyelesaikan stabilisasi IGD dan melakukan handoff sesuai kondisi pasien',
        'hasil' => 'Pasien stabil setelah reassessment ABCDE dan siap dihandoff ke tahap berikutnya sesuai indikasi kasus.',
        'animasi' => 'type',
    ]);
    return $filtered;
}

function ems_ai_ds_normalize_estimated_gcs(mixed $estimate): ?array
{
    if (!is_array($estimate)) {
        return null;
    }

    $score = trim((string) ($estimate['score'] ?? ''));
    if (!preg_match('/\bE\s*([1-4])\s*V\s*([1-5])\s*M\s*([1-6])\b/i', $score, $match)) {
        return null;
    }

    $total = (int) $match[1] + (int) $match[2] + (int) $match[3];
    return [
        'score' => 'E' . $match[1] . ' V' . $match[2] . ' M' . $match[3] . ' (' . $total . ')',
        'interpretation' => trim((string) ($estimate['interpretation'] ?? '')),
        'basis' => trim((string) ($estimate['basis'] ?? '')),
        'status' => ems_ai_ds_estimate_status_label(),
    ];
}

function ems_ai_ds_ttv_strategy_language(string $text): string
{
    $text = trim($text);
    if (preg_match('/\b(?:hipotensi\s+permisif|permissive\s+hypotension)\b/iu', $text) === 1) {
        $text = preg_replace(
            '/\b(?:hipotensi\s+permisif|permissive\s+hypotension)\b/iu',
            'strategi resusitasi cairan dengan target tekanan darah yang sengaja dijaga tidak terlalu tinggi',
            $text
        ) ?? $text;
    }
    return trim($text);
}

function ems_ai_ds_normalize_estimated_ttv(mixed $estimates): array
{
    if (!is_array($estimates)) {
        return [];
    }

    $normalized = [];
    foreach ($estimates as $estimate) {
        if (!is_array($estimate)) {
            continue;
        }

        $label = trim((string) ($estimate['label'] ?? ''));
        $value = ems_ai_ds_ttv_clean_value(trim((string) ($estimate['value'] ?? '')));
        if ($label === '' || $value === '') {
            continue;
        }

        $normalized[] = [
            'label' => $label,
            'value' => $value,
            'note' => ems_ai_ds_ttv_clinical_note($label, $value, (string) ($estimate['note'] ?? '')),
            'basis' => ems_ai_ds_ttv_strategy_language((string) ($estimate['basis'] ?? '')),
            'status' => ems_ai_ds_estimate_status_label(),
        ];
    }

    return $normalized;
}

function ems_ai_ds_clinical_estimates_missing(array $data): bool
{
    $missing = static function ($value): bool {
        return in_array(mb_strtolower(trim((string) $value)), ['', '-', 'data belum tersedia', 'belum diukur', 'belum dinilai'], true);
    };

    $gcs = trim((string) ($data['gcs'] ?? ''));
    $hasActualGcs = preg_match('/\bE\s*[1-4]\s*V\s*[1-5]\s*M\s*[1-6]\b/i', $gcs) === 1;
    $hasEstimatedGcs = ems_ai_ds_normalize_estimated_gcs($data['gcs_estimasi_ai'] ?? null) !== null;
    if (!$hasActualGcs && !$hasEstimatedGcs) {
        return true;
    }

    $ttv = is_array($data['ttv'] ?? null) ? $data['ttv'] : [];
    $ttvMissingCount = $ttv === [] ? 5 : count(array_filter($ttv, static function ($item) use ($missing): bool {
        return is_array($item) && $missing($item['value'] ?? null);
    }));
    $estimatedTtvCount = count(ems_ai_ds_normalize_ttv_estimates($data));

    return $ttvMissingCount > 0 && $estimatedTtvCount < $ttvMissingCount;
}

/**
 * Roleplay completion: Diagnosis Assistant harus tetap playable walaupun
 * model mengembalikan JSON parsial. Nilai ini adalah skenario roleplay,
 * bukan klaim hasil ukur pasien nyata.
 */
function ems_ai_ds_complete_roleplay_fields(array $data, string $sourceText): array
{
    $sourceText = trim($sourceText);
    $caseText = mb_strtolower($sourceText . ' ' . ems_ai_ds_case_text($data));
    $hasHead = preg_match('/\b(?:kepala|otak|gcs|tidak sadar|pupil|kranium|proyektil)\b/iu', $caseText) === 1;
    $hasBleeding = preg_match('/\b(?:perdarahan|pendarahan|syok|hemorag|darah banyak|eviserasi|proyektil|tembus)\b/iu', $caseText) === 1;
    $unconscious = preg_match('/\b(?:tidak sadar|tak sadarkan diri|koma|unresponsive)\b/iu', $caseText) === 1;

    if (trim((string) ($data['anamnesis_lengkap'] ?? '')) === '' || strcasecmp(trim((string) ($data['anamnesis_lengkap'] ?? '')), 'Data belum tersedia') === 0) {
        $data['anamnesis_lengkap'] = $sourceText . ' Pemeriksaan awal IGD dilanjutkan dengan primary survey ABCDE dan stabilisasi sesuai mekanisme cedera. Narasi ini merupakan skenario roleplay dan seluruh estimasi diberi label wajib verifikasi.';
    }

    $diagnosis = trim((string) ($data['diagnosis_utama'] ?? ''));
    if ($diagnosis === '' || $diagnosis === '-' || stripos($diagnosis, 'Data belum tersedia') !== false) {
        $data['diagnosis_utama'] = $hasBleeding
            ? 'Trauma akut dengan kecurigaan perdarahan dan gangguan hemodinamik; diagnosis kerja berdasarkan mekanisme dan temuan awal.'
            : ($hasHead ? 'Trauma kepala akut dengan kecurigaan cedera intrakranial; diagnosis kerja berdasarkan temuan awal.' : 'Trauma akut sesuai mekanisme cedera yang dilaporkan; diagnosis kerja memerlukan evaluasi ABCDE lengkap.');
    }
    $banding = array_values(array_filter(array_map('strval', (array) ($data['diagnosis_banding'] ?? [])), static fn (string $v): bool => trim($v) !== '' && $v !== '-'));
    if ($banding === []) {
        $banding = $hasHead
            ? ['Perdarahan intrakranial atau edema serebri', 'Cedera jaringan lunak/kranium sesuai lokasi trauma', 'Gangguan kesadaran sekunder hipoksia, syok, atau intoksikasi']
            : ($hasBleeding ? ['Perdarahan internal tersembunyi', 'Syok hipovolemik/hemoragik akibat trauma', 'Cedera organ atau jaringan sekitar yang belum teridentifikasi'] : ['Cedera jaringan lunak sesuai mekanisme', 'Fraktur atau cedera struktur lebih dalam', 'Komplikasi sekunder sesuai perkembangan klinis']);
        $data['diagnosis_banding'] = $banding;
    }

    $gcs = trim((string) ($data['gcs'] ?? ''));
    $hasGcs = preg_match('/\bE\s*[1-4]\s*V\s*[1-5]\s*M\s*[1-6]\b/i', $gcs) === 1;
    if (!$hasGcs && !ems_ai_ds_normalize_estimated_gcs($data['gcs_estimasi_ai'] ?? null)) {
        $score = $unconscious ? 'E2 V2 M4 (8)' : ($hasBleeding ? 'E4 V4 M6 (14)' : 'E4 V5 M6 (15)');
        $data['gcs_estimasi_ai'] = ['score' => $score, 'interpretation' => 'Estimasi roleplay berdasarkan mekanisme dan tingkat respons pada anamnesis.', 'basis' => 'Skenario awal IGD; nilai aktual wajib dikonfirmasi dengan pemeriksaan langsung.', 'status' => ems_ai_ds_estimate_status_label()];
    }

    $estimate = ems_ai_ds_normalize_ttv_estimates($data);
    if (count($estimate) < 5) {
        $values = $hasBleeding
            ? ['Tekanan Darah' => '90/60 mmHg', 'Nadi / HR' => '112 x/menit', 'Suhu' => '35,8 °C', 'Respirasi / RR' => '26 x/menit', 'Saturasi O2' => '94%']
            : ['Tekanan Darah' => '120/80 mmHg', 'Nadi / HR' => '88 x/menit', 'Suhu' => '36,7 °C', 'Respirasi / RR' => '18 x/menit', 'Saturasi O2' => '98%'];
        $existing = [];
        foreach ($estimate as $item) {
            $existing[ems_ai_ds_ttv_slot_key((string) ($item['label'] ?? ''))] = true;
        }
        foreach ($values as $label => $value) {
            $key = ems_ai_ds_ttv_slot_key($label);
            if (!isset($existing[$key])) {
                $estimate[] = ['label' => $label, 'value' => $value, 'note' => 'Estimasi roleplay untuk stabilisasi IGD.', 'basis' => 'Mekanisme dan temuan awal pada anamnesis.', 'status' => ems_ai_ds_estimate_status_label()];
            }
        }
        $data['ttv_estimasi_ai'] = $estimate;
    }

    if (false && (!is_array($data['emergency'] ?? null) || $data['emergency'] === [])) {
        $data['emergency'] = [
            ['pelaku' => 'DPJP', 'instruksi' => '', 'aksi' => 'melakukan primary survey ABCDE dan memastikan jalan napas, pernapasan, sirkulasi, status neurologis, serta paparan cedera', 'hasil' => 'Primary survey selesai; pasien masuk protokol stabilisasi IGD.', 'animasi' => 'mechanic'],
            ['pelaku' => 'Asisten 1', 'instruksi' => 'DPJP: pertahankan jalan napas dan berikan oksigen sesuai kondisi pasien.\nAsisten 1: Baik, dok.', 'aksi' => 'mempertahankan patensi jalan napas dan memasang terapi oksigen sambil memantau pola napas', 'hasil' => 'Jalan napas terjaga dan oksigenasi membaik selama stabilisasi.', 'animasi' => 'mechanic'],
            ['pelaku' => 'Asisten 1', 'instruksi' => 'DPJP: pasang monitor dan ukur tanda vital lengkap.\nAsisten 1: Baik, dok.', 'aksi' => 'memasang monitor EKG, pulse oximeter, manset tekanan darah, dan termometer', 'hasil' => 'Monitoring terpasang dan TTV skenario tercatat untuk evaluasi berkala.', 'animasi' => 'mechanic'],
            ['pelaku' => 'Asisten 2', 'instruksi' => 'DPJP: siapkan dua akses IV dan cairan resusitasi.\nAsisten 2: Baik, dok.', 'aksi' => 'memasang dua akses intravena besar dan mengalirkan cairan resusitasi melalui jalur IV', 'hasil' => 'Akses IV terpasang dan cairan resusitasi mengalir; tekanan darah serta perfusi perifer membaik.', 'animasi' => 'syringe'],
            ['pelaku' => 'DPJP', 'instruksi' => '', 'aksi' => 'melakukan pemeriksaan head-to-toe dan menilai lokasi, kedalaman, jalur cedera, deformitas, perfusi, sensorik, serta motorik', 'hasil' => 'Pola cedera dan struktur berisiko berhasil dipetakan untuk menentukan prioritas tindakan.', 'animasi' => 'mechanic'],
            ['pelaku' => 'DPJP', 'instruksi' => '', 'aksi' => 'mengontrol perdarahan eksternal dengan balut tekan dan mengevaluasi tanda perfusi perifer', 'hasil' => 'Perdarahan eksternal terkontrol sementara dan perfusi dievaluasi ulang.', 'animasi' => 'mechanic'],
            ['pelaku' => 'Asisten 2', 'instruksi' => 'DPJP: berikan analgesia dan terapi pendukung sesuai diagnosis.\nAsisten 2: Baik, dok.', 'aksi' => 'memberikan analgesia dan terapi pendukung melalui akses intravena sesuai instruksi DPJP', 'hasil' => 'Nyeri berkurang dan pasien dapat menjalani pemeriksaan lanjutan dengan lebih stabil.', 'animasi' => 'syringe'],
            ['pelaku' => 'Asisten 1', 'instruksi' => 'DPJP: kerjakan darah lengkap, golongan darah, crossmatch, dan pemeriksaan bedside sesuai indikasi.\nAsisten 1: Baik, dok.', 'aksi' => 'mengambil sampel darah, menjalankan pemeriksaan laboratorium, crossmatch/PRC, dan pemeriksaan penunjang bedside', 'hasil' => 'Hasil laboratorium, crossmatch, dan pemeriksaan bedside tersedia untuk keputusan klinis.', 'animasi' => 'type'],
            ['pelaku' => 'DPJP', 'instruksi' => '', 'aksi' => 'menelaah hasil laboratorium dan radiologi serta menghubungkannya dengan mekanisme, diagnosis, dan kebutuhan tindakan definitif', 'hasil' => 'Diagnosis kerja dan rekomendasi tindakan definitif ditetapkan berdasarkan hasil penunjang.', 'animasi' => 'type'],
            ['pelaku' => 'DPJP', 'instruksi' => '', 'aksi' => 'melakukan reassessment ABCDE, memberikan handoff terstruktur, dan menyatakan kesiapan pasien untuk tindakan definitif', 'hasil' => 'Pasien stabil dan siap diteruskan ke Ruang Operasi atau tata laksana definitif sesuai rekomendasi laporan.', 'animasi' => 'type'],
        ];
    }

    return $data;
}

/** Finalisasi tampilan agar laporan menjadi paket roleplay siap pakai. */
function ems_ai_ds_finalize_playable_report(array $data, string $sourceText): array
{
    $sourceText = trim($sourceText);
    $caseText = mb_strtolower($sourceText . ' ' . ems_ai_ds_case_text($data));
    $hasHead = preg_match('/\b(?:kepala|otak|kranium|tengkorak|pupil|cedera neurologis)\b/iu', $caseText) === 1;
    $hasAbdomen = preg_match('/\b(?:abdomen|abdominal|perut|usus|omentum|hepar|limpa)\b/iu', $caseText) === 1;
    $hasLeg = preg_match('/\b(?:kaki|tungkai|femur|tibia|fibula|paha|betis)\b/iu', $caseText) === 1;
    $hasGunshot = preg_match('/\b(?:tembak|peluru|proyektil|gunshot)\b/iu', $caseText) === 1;
    $hasFracture = preg_match('/\b(?:fraktur|patah tulang|dislokasi)\b/iu', $caseText) === 1;
    $hasPregnancy = preg_match('/\b(?:hamil|janin|plasenta|gravid|abrupsi|kehamilan|seksio\s+sesarea|caesar)\b/iu', $caseText) === 1;
    $severe = preg_match('/\b(?:tidak sadar|koma|syok|perdarahan|pendarahan)\b/iu', $caseText) === 1;

    // Simpan keluaran model sebelum fallback heuristik berjalan. Heuristik
    // hanya boleh mengisi kekosongan; tidak boleh menimpa jawaban AI yang
    // sudah spesifik terhadap kasus.
    $modelFields = [];
    foreach (['diagnosis_utama', 'diagnosis_banding', 'jenis_operasi', 'jenis_anestesi', 'kasus_tindakan', 'status_rencana_operasi', 'pemeriksaan_pupil', 'roleplay_note', 'riwayat_operasi'] as $field) {
        $value = $data[$field] ?? null;
        if (is_array($value) ? $value !== [] : trim((string) $value) !== '') {
            $modelFields[$field] = $value;
        }
    }

    $anamnesis = trim((string) ($data['anamnesis_lengkap'] ?? ''));
    $sourceCompact = preg_replace('/\s+/u', ' ', mb_strtolower($sourceText)) ?? mb_strtolower($sourceText);
    $anamnesisCompact = preg_replace('/\s+/u', ' ', mb_strtolower($anamnesis)) ?? mb_strtolower($anamnesis);
    $sourceIsCopied = $sourceCompact !== '' && mb_strlen($sourceCompact) >= 24 && str_contains($anamnesisCompact, $sourceCompact);
    $hasMetaNarrative = preg_match('/input\s+awal|dirangkum|disalin\s+verbatim|trigger\s+fakta|data\s+yang\s+diberikan|belum\s+diisi/iu', $anamnesis) === 1;
    if (mb_strlen($anamnesis) < 320 || $sourceIsCopied || $hasMetaNarrative) {
        $location = $hasAbdomen ? 'abdomen' : ($hasLeg ? 'ekstremitas bawah' : ($hasHead ? 'kepala' : 'area cedera'));
        $mechanism = $hasGunshot ? 'trauma penetrasi akibat proyektil' : ($hasFracture ? 'trauma muskuloskeletal' : 'trauma akut');
        $data['anamnesis_lengkap'] = "Pasien tiba di Instalasi Gawat Darurat dengan {$mechanism} pada {$location}. "
            . "Pasien mengeluhkan nyeri dan gangguan fungsi pada area cedera dengan kondisi umum yang memerlukan penilaian segera. Pemeriksaan fisik menunjukkan kelainan pada {$location} yang harus dinilai bersama status jalan napas, pernapasan, sirkulasi, neurologis, dan paparan cedera. "
            . ($severe
                ? 'Saat evaluasi awal pasien menunjukkan gangguan kondisi umum dengan risiko kompromi jalan napas, hipoperfusi, dan kehilangan darah sehingga penanganan dilakukan sebagai kasus prioritas tinggi.'
                : 'Saat evaluasi awal pasien masih dapat dinilai secara terarah dengan kondisi umum yang memerlukan observasi dan stabilisasi segera.')
            . " Primary survey ABCDE dilakukan secara sistematis. Airway dinilai, pola napas dan ekspansi dada diperiksa, sirkulasi dievaluasi melalui nadi, tekanan darah, perfusi perifer, serta sumber perdarahan. Status neurologis dinilai menggunakan GCS dan pemeriksaan motorik. Exposure dilakukan untuk menilai luka masuk/keluar, deformitas, pembengkakan, perdarahan aktif, serta cedera penyerta. Temuan klinis, GCS, TTV, hasil laboratorium, dan radiologi digunakan untuk menentukan stabilisasi dan tindak lanjut definitif.";
    }

    $estimateGcs = ems_ai_ds_normalize_estimated_gcs($data['gcs_estimasi_ai'] ?? null);
    if ($estimateGcs !== null) {
        $data['gcs'] = (string) $estimateGcs['score'];
        $data['gcs_estimasi_ai'] = null;
    }
    $gcsTotal = ems_ai_ds_gcs_total($data['gcs'] ?? null) ?? ($severe ? 8 : 15);
    $data['kesadaran'] = $gcsTotal <= 8 ? 'Penurunan kesadaran berat; respons terbatas terhadap rangsang nyeri' : ($gcsTotal < 15 ? 'Kesadaran menurun ringan-sedang; respons verbal/motorik masih ditemukan' : 'Compos mentis; sadar penuh dan kooperatif');
    $data['motorik'] = ems_ai_ds_gcs_motor_definition(ems_ai_ds_gcs_motor_score($data['gcs'] ?? null)) ?: 'Respons motorik sesuai komponen GCS yang tercatat';

    $displayVitals = ems_ai_ds_prepare_ttv_display($data['ttv'] ?? [], $data['ttv_estimasi_ai'] ?? []);
    $data['ttv'] = array_map(static function (array $vital): array {
        return [
            'label' => (string) ($vital['label'] ?? ''),
            'value' => (string) ($vital['value'] ?? ''),
            'note' => preg_replace('/\b(?:estimasi|wajib verifikasi|data belum tersedia)[^.;]*/iu', 'kondisi klinis saat evaluasi IGD', (string) ($vital['note'] ?? '')) ?: 'Kondisi klinis saat evaluasi IGD.',
        ];
    }, $displayVitals);
    $data['ttv_estimasi_ai'] = [];

    if (!isset($modelFields['pemeriksaan_pupil'])) {
        $data['pemeriksaan_pupil'] = $hasHead
            ? ['status' => $gcsTotal <= 8 ? 'anisokor ringan' : 'isokor', 'reaktivitas' => $gcsTotal <= 8 ? 'reaktivitas melambat pada sisi cedera' : 'reaktif bilateral', 'catatan' => 'Pemeriksaan pupil dilakukan sebagai bagian Disability pada trauma kepala.']
            : ['status' => 'isokor', 'reaktivitas' => 'reaktif bilateral', 'catatan' => 'Tidak ditemukan tanda defisit neurologis fokal pada evaluasi awal.'];
    }

    if ($hasPregnancy && $hasAbdomen) {
        $data['diagnosis_utama'] = 'Trauma tumpul abdomen pada kehamilan dengan dugaan abrupsio plasenta, perdarahan intraabdomen, dan gawat janin.';
        $data['diagnosis_banding'] = ['Ruptur uteri traumatik', 'Persalinan preterm imminen akibat trauma', 'Cedera lien/hepar dengan perdarahan internal', 'Abrupsio plasenta inkomplet'];
        $data['jenis_operasi'] = 'Mayor — laparotomi eksplorasi emergensi dan seksio sesarea emergensi sesuai evaluasi obstetri.';
        $data['jenis_anestesi'] = 'Anestesi umum dengan intubasi endotrakeal oleh dokter anestesi.';
        $data['kasus_tindakan'] = 'Pasien hamil mengalami trauma tumpul abdomen setelah jatuh dari sepeda motor, disertai nyeri abdomen, hipotensi relatif, takikardia, dan tanda kompromi janin. Setelah stabilisasi awal IGD, diperlukan evaluasi obstetri segera dan tindakan operasi cito berupa seksio sesarea emergensi dengan laparotomi eksplorasi bila perdarahan intraabdomen/cedera organ terkonfirmasi.';
        $data['status_rencana_operasi'] = 'Cito — pasien siap dihandoff ke tim Obstetri, Anestesi, dan Ruang Operasi setelah stabilisasi awal.';
    } elseif ($hasAbdomen && $hasGunshot) {
        $data['diagnosis_utama'] = 'Trauma penetrasi abdomen akibat proyektil dengan perdarahan intraabdomen dan syok hemoragik.';
        $data['diagnosis_banding'] = ['Perforasi organ berongga intraabdomen', 'Cedera hepar atau lien dengan hemoperitoneum', 'Cedera vaskular mesenterika/retroperitoneal'];
        $data['jenis_operasi'] = 'Mayor — laparotomi eksplorasi dan kontrol sumber perdarahan';
        $data['jenis_anestesi'] = 'Anestesi umum dengan intubasi endotrakeal';
        $data['kasus_tindakan'] = 'Trauma penetrasi abdomen dengan bukti perdarahan intraabdomen dan instabilitas hemodinamik. Diindikasikan laparotomi eksplorasi cito untuk identifikasi cedera organ, kontrol perdarahan, dan repair sesuai temuan intraoperatif.';
        $data['status_rencana_operasi'] = 'Cito — direkomendasikan langsung ke Ruang Operasi setelah stabilisasi awal IGD.';
    } elseif ($hasLeg && $hasGunshot) {
        $data['diagnosis_utama'] = 'Luka tembak ekstremitas bawah dengan cedera jaringan lunak dan kecurigaan keterlibatan tulang/neurovaskular.';
        $data['diagnosis_banding'] = ['Fraktur terbuka akibat proyektil', 'Cedera arteri atau vena ekstremitas bawah', 'Cedera saraf perifer dan sindrom kompartemen akut'];
        $data['jenis_operasi'] = 'Mayor — eksplorasi luka, debridement, ekstraksi proyektil, dan stabilisasi struktur yang cedera';
        $data['jenis_anestesi'] = 'Anestesi umum atau regional sesuai evaluasi anestesi';
        $data['kasus_tindakan'] = 'Luka tembak ekstremitas bawah dengan jalur proyektil dan risiko kerusakan tulang serta struktur neurovaskular. Diindikasikan eksplorasi operatif urgent, debridement, ekstraksi proyektil bila aman, kontrol perdarahan, dan fiksasi bila ditemukan fraktur.';
        $data['status_rencana_operasi'] = 'Urgent — direkomendasikan ke Ruang Operasi setelah stabilisasi IGD.';
    } elseif ($hasFracture) {
        $data['jenis_operasi'] = 'Mayor — Open Reduction Internal Fixation (ORIF)';
        $data['jenis_anestesi'] = 'Anestesi umum atau regional sesuai lokasi fraktur';
        $data['kasus_tindakan'] = 'Fraktur dengan gangguan alignment dan fungsi ekstremitas berdasarkan pemeriksaan serta pencitraan. Diindikasikan ORIF untuk reposisi anatomis dan stabilisasi internal.';
        $data['status_rencana_operasi'] = 'Urgent/Terencana — operasi direkomendasikan berdasarkan stabilitas pasien dan kondisi jaringan lunak.';
    } else {
        $caseAction = trim((string) ($data['kasus_tindakan'] ?? ''));
        if ($caseAction === '' || $caseAction === '-' || mb_strlen($caseAction) < 180 || preg_match('/data belum|menunggu|belum ditentukan/iu', $caseAction)) {
            $mechanismLabel = $hasGunshot ? 'luka tembak/proyektil' : ($hasFracture ? 'trauma dengan dugaan fraktur/dislokasi' : 'trauma akut sesuai anamnesis');
            $data['kasus_tindakan'] = "Pasien datang dengan {$mechanismLabel}. Laporan ini telah menyelesaikan primary survey ABCDE, stabilisasi, pemeriksaan penunjang yang relevan, dan persiapan handoff. AI Surgery Planner harus menyusun rencana definitif berdasarkan diagnosis utama, lokasi cedera, status hemodinamik, hasil laboratorium/radiologi, jenis operasi, anestesi, serta pembagian peran DPJP dan asisten; jangan mengulang anamnesis secara umum.";
        }
        $status = trim((string) ($data['status_rencana_operasi'] ?? ''));
        if ($status === '' || preg_match('/menunggu|tentatif|belum/iu', $status)) {
            $data['status_rencana_operasi'] = preg_match('/operasi|bedah|mayor|minor/iu', (string) ($data['jenis_operasi'] ?? '')) ? 'Direkomendasikan — lanjutkan ke Surgery Planner menggunakan kode referensi DGN.' : 'Tidak diperlukan operasi segera; lanjutkan tata laksana definitif sesuai diagnosis.';
        }
    }

    // Kembalikan bidang yang sudah diisi model. Fallback di atas hanya untuk
    // laporan parsial, bukan untuk mengubah keputusan klinis model.
    foreach ($modelFields as $field => $value) {
        $data[$field] = $value;
    }

    // Instruksi minor eksplisit tidak boleh ditimpa fallback menjadi mayor.
    $explicitMinor = preg_match('/\b(?:operasi|tindakan|prosedur)\s+minor\b|\bminor\b/iu', $sourceText . ' ' . $caseText) === 1;
    $majorProcedure = preg_match('/\b(?:orif|open\s+reduction\s+internal\s+fixation|laparotomi|kraniotomi|craniotomy|thorakotomi|thoracotomy|seksio\s+sesarea|sectio\s+caesarea|histerektomi|amputasi)\b/iu', (string) ($data['jenis_operasi'] ?? '') . ' ' . $sourceText . ' ' . $caseText) === 1;
    if ($explicitMinor && !$majorProcedure) {
        $operation = preg_replace('/\bmayor\b/iu', 'Minor', trim((string) ($data['jenis_operasi'] ?? ''))) ?? trim((string) ($data['jenis_operasi'] ?? ''));
        $data['jenis_operasi'] = $operation !== '' ? $operation : 'Minor — tindakan definitif sesuai lokasi cedera.';
        if (preg_match('/cito|urgent|gawat/iu', (string) ($data['status_rencana_operasi'] ?? '')) === 0) {
            $data['status_rencana_operasi'] = 'Direkomendasikan — operasi minor sesuai indikasi kasus; lanjutkan ke AI Surgery Planner menggunakan kode referensi DGN.';
        }
    }

    // Pertahankan hasil laboratorium dari model. Nilai di bawah hanya fallback
    // agar laporan roleplay tidak kosong ketika model mengembalikan JSON parsial.
    if (!is_array($data['lab'] ?? null) || count(array_filter((array) $data['lab'], static fn ($v): bool => trim((string) $v) !== '')) === 0) {
        $data['lab'] = $severe
        ? ['Darah lengkap: Hb 9,6 g/dL; leukosit 14.800/µL; trombosit 238.000/µL — anemia akut dan leukositosis respons stres.', 'Golongan darah dan crossmatch: O positif; 2–4 unit PRC kompatibel tersedia.', 'Laktat 4,1 mmol/L dengan base deficit meningkat — konsisten dengan hipoperfusi jaringan.', 'Elektrolit dan glukosa: dalam batas aman untuk resusitasi serta persiapan tindakan definitif.']
        : ['Darah lengkap: Hb 13,4 g/dL; leukosit 10.600/µL; trombosit 272.000/µL.', 'Elektrolit, glukosa, fungsi ginjal, dan koagulasi dalam batas yang mendukung tindakan lanjutan.', 'Golongan darah dan crossmatch telah tercatat; darah kompatibel tersedia bila diperlukan.'];
    }
    if (empty($data['radiologi']) || !is_array($data['radiologi'])) {
      if ($hasAbdomen) {
        $data['radiologi'] = ['FAST bedside positif: cairan bebas intraabdomen teridentifikasi.', 'X-ray portable thoraks/abdomen menunjukkan jalur trauma tanpa pneumotoraks besar.', 'CT abdomen kontras menunjukkan jalur cedera penetrasi dan hemoperitoneum; temuan mendukung tindakan bedah definitif.'];
      } elseif ($hasLeg) {
        $data['radiologi'] = ['X-ray ekstremitas bawah menunjukkan lokasi proyektil/cedera jaringan dan menilai keterlibatan tulang.', 'CT angiografi ekstremitas menunjukkan aliran vaskular distal serta lokasi cedera pembuluh yang memerlukan eksplorasi.', 'USG Doppler memperlihatkan perfusi distal yang dapat dipantau selama stabilisasi.'];
      } elseif ($hasHead) {
        $data['radiologi'] = ['CT kepala non-kontras menunjukkan gambaran cedera sesuai mekanisme tanpa kontradiksi dengan status neurologis.', 'X-ray/CT cervical menilai alignment servikal dan tidak menunjukkan instabilitas mayor.'];
      }
    }

    // Hapus duplikasi yang sering muncul saat model mengulang rekomendasi
    // metabolik/radiologi. Pertahankan urutan item pertama yang paling spesifik.
    foreach (['lab', 'radiologi'] as $listField) {
        $seen = [];
        $unique = [];
        foreach ((array) ($data[$listField] ?? []) as $item) {
            $text = trim((string) $item);
            $key = mb_strtolower((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
            if ($text === '' || $key === 'array' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $text;
        }
        $data[$listField] = $unique;
    }

    $structuredLab = is_array($data['laboratorium_terstruktur'] ?? null) ? $data['laboratorium_terstruktur'] : [];
    if (trim((string) ($structuredLab['department'] ?? '')) === '' || trim((string) ($structuredLab['category'] ?? '')) === '') {
        $data['laboratorium_terstruktur'] = [
            'department' => 'Hematologi',
            'category' => 'Complete Blood Count (CBC)',
            'level3_option' => 'Semua Parameter (Default)',
            'specimen_type' => 'Whole Blood EDTA',
        ];
    }

    if (trim((string) ($data['roleplay_note'] ?? '')) === '' || preg_match('/data belum|tidak tersedia/iu', (string) $data['roleplay_note'])) {
        $data['roleplay_note'] = 'Lakukan roleplay dengan fokus pada primary survey ABCDE, kontrol sumber perdarahan, monitoring TTV, komunikasi DPJP–asisten, dan handoff terstruktur. '
            . 'Skenario ini sudah dilengkapi diagnosis kerja, pemeriksaan penunjang, serta tindakan awal untuk langsung dimainkan sesuai kasus.';
    }
    $sopRefs = array_values(array_filter(array_map('strval', (array) ($data['sop_references'] ?? [])), static fn (string $v): bool => trim($v) !== ''));
    if ($sopRefs === []) {
        $data['sop_references'] = ['SOP IGD Roxwood Hospital — Primary Survey ABCDE dan Initial Care', 'SOP Roxwood Hospital — Kewenangan dan alur tindakan medis roleplay'];
    }

    $clean = static function (mixed $value) use (&$clean): mixed {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $clean($item);
            }
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        if (strcasecmp(trim($value), 'Array') === 0) {
            return '';
        }
        $value = preg_replace('/\s*\[?Estimasi AI\s*[—-]\s*wajib verifikasi\]?/iu', '', $value) ?? $value;
        $value = preg_replace('/\s*[;,]?\s*(?:hasil|data)\s+(?:skenario\s+)?(?:sudah\s+tersedia|tercatat\s+pada\s+skenario\s+klinis|pemeriksaan\s+final\s+skenario)(?:\s+untuk\s+keputusan\s+klinis)?/iu', '', $value) ?? $value;
        $value = preg_replace('/\s*[;,]?\s*temuan\s+sudah\s+dinilai\s+pada\s+pemeriksaan\s+ini/iu', '', $value) ?? $value;
        $value = preg_replace('/\b(?:hasil|data) belum tersedia(?: dan wajib diverifikasi)?\b/iu', 'temuan sudah dinilai pada pemeriksaan ini', $value) ?? $value;
        $value = preg_replace('/\bwajib diverifikasi\b/iu', 'telah dikonfirmasi dalam skenario klinis', $value) ?? $value;
        return trim($value);
    };

    return $clean($data);
}

function ems_ai_ds_normalize_structured_radiology_legacy(mixed $input): array
{
    if (!is_array($input)) {
        return [];
    }

    $scalar = static function (mixed $value): string {
        if (is_array($value)) {
            $value = reset($value);
        }
        return trim((string) $value);
    };

    $normalized = [
        'modality' => $scalar($input['modality'] ?? ''),
        'category' => $scalar($input['category'] ?? ''),
        'body_region' => $scalar($input['body_region'] ?? ($input['bodyRegion'] ?? '')),
        'projection' => $scalar($input['projection'] ?? ''),
        'clinical_finding' => $scalar($input['clinical_finding'] ?? ($input['clinicalFinding'] ?? '')),
    ];

    return array_filter($normalized, static fn (string $value): bool => $value !== '') !== [] ? $normalized : [];
}

/**
 * Kontrak keluaran final Diagnosis Assistant.
 *
 * Fungsi ini sengaja hanya memeriksa kelengkapan dan menolak hasil yang belum
 * siap dimainkan. Ia tidak mengisi, menerjemahkan, memilih, atau memperbaiki
 * isi model.
 */
function ems_ai_ds_require_complete_model_report(mixed $value): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException('Model tidak mengembalikan objek laporan.');
    }

    $requiredText = [
        'anamnesis_lengkap', 'diagnosis_utama', 'gcs', 'kasus_tindakan',
        'roleplay_note', 'status_rencana_operasi', 'jenis_operasi', 'jenis_anestesi',
    ];
    foreach ($requiredText as $key) {
        if (!isset($value[$key]) || !is_string($value[$key]) || trim($value[$key]) === '') {
            throw new InvalidArgumentException("Field {$key} kosong atau bukan teks final.");
        }
    }

    if (!is_array($value['diagnosis_banding'] ?? null) || count($value['diagnosis_banding']) < 1) {
        throw new InvalidArgumentException('Diagnosis banding wajib berisi diagnosis konkret.');
    }
    if (!is_array($value['ttv'] ?? null) || count($value['ttv']) < 4) {
        throw new InvalidArgumentException('TTV final belum lengkap.');
    }
    if (!is_array($value['lab'] ?? null) || count($value['lab']) < 1) {
        throw new InvalidArgumentException('Rencana laboratorium final belum diisi.');
    }
    if (!is_array($value['radiologi'] ?? null) || count($value['radiologi']) < 1) {
        throw new InvalidArgumentException('Rencana radiologi final belum diisi.');
    }
    if (!is_array($value['emergency'] ?? null) || count($value['emergency']) < 8) {
        throw new InvalidArgumentException('Penanganan ABCDE final belum lengkap.');
    }
    foreach ($value['ttv'] as $vital) {
        if (!is_array($vital) || trim((string) ($vital['value'] ?? '')) === '' || preg_match('/data\s+belum|belum\s+(?:diukur|dinilai|tersedia)/iu', json_encode($vital, JSON_UNESCAPED_UNICODE)) === 1) {
            throw new InvalidArgumentException('Nilai TTV final masih kosong atau placeholder.');
        }
    }

    $forbidden = '/\b(?:array|belum tersedia|belum dinilai|akan dinilai|hasil belum|hasil aktual|input awal|dirangkum ulang|disalin verbatim|trigger fakta)\b/iu';
    $walk = static function (mixed $item) use (&$walk, $forbidden): void {
        if (is_array($item)) {
            foreach ($item as $child) {
                $walk($child);
            }
            return;
        }
        if (is_string($item) && preg_match($forbidden, $item) === 1) {
            throw new InvalidArgumentException('Model mengandung placeholder, meta-text, atau pilihan tindakan.');
        }
    };
    $walk($value);

    foreach ($value['emergency'] as $index => $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Item emergency #' . ($index + 1) . ' tidak valid.');
        }
        foreach (['pelaku', 'aksi', 'hasil', 'animasi'] as $key) {
            if (!isset($item[$key]) || !is_string($item[$key]) || trim($item[$key]) === '') {
                throw new InvalidArgumentException('Item emergency #' . ($index + 1) . " tidak memiliki {$key} final.");
            }
        }
        if (preg_match('/^\s*\/me\b|^\s*\/do\b/iu', $item['aksi'] . ' ' . $item['hasil']) === 1) {
            throw new InvalidArgumentException('Field emergency berisi label roleplay ganda.');
        }
        if (preg_match('/\b(?:sesuai protokol|sesuai instruksi|sesuai arahan)\b/iu', $item['aksi'] . ' ' . $item['hasil']) === 1) {
            throw new InvalidArgumentException('Tindakan emergency harus konkret dan tidak bercabang.');
        }
    }

    return $value;
}

function ems_ai_ds_normalize_diagnosis_result(array $data, string $sourceText = ''): array
{
    // JSON model kadang mengembalikan scalar sebagai object/array. Jangan
    // pernah membiarkan cast PHP menghasilkan teks literal "Array" di laporan.
    $scalarText = static function (mixed $value) use (&$scalarText): string {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $text = $scalarText($item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            return trim(implode('; ', $parts));
        }
        return '';
    };
    foreach (['diagnosis_utama', 'anamnesis_lengkap', 'jenis_operasi', 'jenis_anestesi', 'kasus_tindakan', 'status_rencana_operasi', 'status', 'roleplay_note'] as $field) {
        if (array_key_exists($field, $data) && !is_string($data[$field])) {
            $data[$field] = $scalarText($data[$field]);
        }
    }
    if (isset($data['diagnosis_banding'])) {
        $rawBanding = is_array($data['diagnosis_banding']) ? $data['diagnosis_banding'] : [$data['diagnosis_banding']];
        $data['diagnosis_banding'] = array_values(array_unique(array_filter(array_map($scalarText, $rawBanding), static fn (string $v): bool => $v !== '' && strcasecmp($v, 'Array') !== 0)));
    }
    foreach (['lab', 'radiologi'] as $listField) {
        if (isset($data[$listField])) {
            $rawList = is_array($data[$listField]) ? $data[$listField] : [$data[$listField]];
            $data[$listField] = array_values(array_unique(array_filter(array_map($scalarText, $rawList), static fn (string $v): bool => $v !== '' && strcasecmp($v, 'Array') !== 0)));
        }
    }
    $data = ems_ai_ds_sanitize_forbidden_certainty_language($data);
    $data['radiologi_terstruktur'] = ems_ai_ds_normalize_structured_radiology_legacy($data['radiologi_terstruktur'] ?? null);
    ems_ai_ds_recover_field($data, 'roleplay_note', 'note');
    $data['jenis_operasi'] = ems_ai_ds_effective_operation_category(
        (string) ($data['jenis_operasi'] ?? ''),
        (string) ($sourceText . ' ' . ($data['kasus_tindakan'] ?? '') . ' ' . ($data['diagnosis_utama'] ?? ''))
    );

    ems_ai_ds_recover_field($data, 'anamnesis_lengkap', 'anamnesis');

    $gcs = trim((string) ($data['gcs'] ?? ''));
    if (preg_match('/\bE\s*([1-4])\s*V\s*([1-5])\s*M\s*([1-6])\b/i', $gcs, $match)) {
        $total = (int) $match[1] + (int) $match[2] + (int) $match[3];
        $statedTotal = null;
        if (preg_match('/\(\s*(\d{1,2})\s*\)/', $gcs, $totalMatch)) {
            $statedTotal = (int) $totalMatch[1];
        }
        $data['gcs'] = 'E' . $match[1] . ' V' . $match[2] . ' M' . $match[3] . ' (' . $total . ')';
        if ($statedTotal !== null && $statedTotal !== $total) {
            $conflict = "Konflik data GCS: total tertulis {$statedTotal}, tetapi E{$match[1]} + V{$match[2]} + M{$match[3]} = {$total}; total dinormalisasi ke {$total}, wajib diverifikasi.";
            $note = trim((string) ($data['roleplay_note'] ?? ''));
            $data['roleplay_note'] = $note === '' ? $conflict : $note . "\n" . $conflict;
        }
    }

    $data['gcs_estimasi_ai'] = ems_ai_ds_normalize_estimated_gcs($data['gcs_estimasi_ai'] ?? null);
    $motorScore = ems_ai_ds_gcs_motor_score($data['gcs'] ?? null)
        ?? ems_ai_ds_gcs_motor_score($data['gcs_estimasi_ai']['score'] ?? null);
    $data = ems_ai_ds_sanitize_gcs_motor_language($data, $motorScore);
    ems_ai_ds_normalize_gcs_motor($data);
    foreach (['kesadaran', 'motorik'] as $field) {
        if (!array_key_exists($field, $data) || trim((string) $data[$field]) === '') {
            $data[$field] = 'Data belum tersedia';
        }
    }

    $data['gcs_estimasi_ai'] = ems_ai_ds_normalize_estimated_gcs($data['gcs_estimasi_ai'] ?? null);
    $data['ttv_estimasi_ai'] = ems_ai_ds_normalize_ttv_estimates($data);
    $data['ttv'] = ems_ai_ds_normalize_ttv_actual($data['ttv'] ?? []);
    $data['ttv'] = ems_ai_ds_ttv_promote_unverified_matches($data['ttv'], $data['ttv_estimasi_ai'], $sourceText);

    $operationText = mb_strtolower(implode(' ', [
        (string) ($data['jenis_operasi'] ?? ''),
        (string) ($data['kasus_tindakan'] ?? ''),
    ]));
    if (str_contains($operationText, 'orif') || str_contains($operationText, 'open reduction internal fixation')) {
        $operation = trim((string) ($data['jenis_operasi'] ?? ''));
        $operation = preg_replace('/\bminor\b/i', 'Mayor', $operation) ?? $operation;
        $data['jenis_operasi'] = $operation !== '' ? $operation : 'Mayor - Open Reduction Internal Fixation (ORIF)';
    }

    $sourceText = (string) ems_ai_ds_normalize_anatomy_language($sourceText);
    $data = (array) ems_ai_ds_normalize_anatomy_language($data);
    $caseText = mb_strtolower(trim($sourceText . ' ' . ems_ai_ds_case_text($data)));
    ems_ai_ds_normalize_pupil_and_operation_status($data, $caseText);
    $data = ems_ai_ds_apply_physiologic_consistency($data, $sourceText);
    ems_ai_ds_ensure_igd_radiology($data, $sourceText);
    ems_ai_ds_ensure_supporting_exam_references($data, $sourceText);
    $data = (array) ems_ai_ds_normalize_anatomy_language($data);
    $caseText = mb_strtolower(trim($sourceText . ' ' . ems_ai_ds_case_text($data)));
    $gcsTotal = ems_ai_ds_gcs_total($data['gcs'] ?? null)
        ?? ems_ai_ds_gcs_total($data['gcs_estimasi_ai']['score'] ?? null);
    $emergencyItems = is_array($data['emergency'] ?? null) ? $data['emergency'] : [];
    $hasEmergencySignal = $emergencyItems !== []
        || ($gcsTotal !== null && $gcsTotal <= 8)
        || ems_ai_ds_text_indicates_evisceration($caseText)
        || preg_match('/\b(?:trauma|syok|perdarahan|sesak|tidak sadar|luka tusuk|darurat|gawat)\b/iu', $caseText) === 1;
    $needsEmergency = $hasEmergencySignal;
    if ($needsEmergency) {
        $data['emergency'] = ems_ai_ds_sanitize_igd_emergency_items($emergencyItems, $gcsTotal, $caseText);
    }
    ems_ai_ds_ensure_supporting_exam_references($data, $sourceText);
    $data = ems_ai_ds_complete_roleplay_fields($data, $sourceText);
    $data = ems_ai_ds_finalize_playable_report($data, $sourceText);

    // Keputusan eksplisit pada anamnesis adalah sumber kebenaran tertinggi
    // untuk kategori tindakan. Kasus luka jari dengan "tindakan minor"
    // tidak boleh berubah menjadi Mayor hanya karena model mengisi kategori
    // default Mayor.
    $sourceRequestsMinor = preg_match('/\b(?:operasi|tindakan|prosedur)\s+minor\b|\bminor\b/iu', $sourceText) === 1;
    $sourceContainsMajorProcedure = preg_match('/\b(?:orif|open\s+reduction\s+internal\s+fixation|laparotomi|kraniotomi|craniotomy|thorakotomi|thoracotomy|seksio\s+sesarea|sectio\s+caesarea|histerektomi|amputasi)\b/iu', $sourceText) === 1;
    if ($sourceRequestsMinor && !$sourceContainsMajorProcedure) {
        $operation = trim((string) ($data['jenis_operasi'] ?? ''));
        $operation = preg_replace('/\bmayor\b/iu', 'Minor', $operation) ?? $operation;
        $data['jenis_operasi'] = $operation !== '' ? $operation : 'Minor — penjahitan luka sesuai indikasi.';
        if (preg_match('/\b(?:cito|urgent|gawat)\b/iu', (string) ($data['status_rencana_operasi'] ?? '')) === 0) {
            $data['status_rencana_operasi'] = 'Direkomendasikan — tindakan minor sesuai indikasi; lanjutkan ke AI Surgery Planner menggunakan kode referensi DGN.';
        }
    }

    return $data;
}

function ems_ai_ds_build_system_prompt(PDO $pdo, string $featureKey, string $defaultPrompt, bool $includeMantra = true, ?string $unitCode = null, string $documentQuery = ''): string
{
    // Tahap 1/2/retry tetap bagian dari Diagnosis Assistant. Jangan sampai
    // pergantian label log mengaktifkan prompt DB lama atau SOP fitur lain.
    $igdOnly = $featureKey === 'ai_diagnosis_assistant'
        || str_starts_with($featureKey, 'ai_diagnosis_assistant_');
    // Diagnosis Assistant memakai prompt canonical dari attachment operator.
    // Template DB hanya berlaku untuk fitur lain agar prompt IGD tidak dapat
    // tergantikan oleh template lama atau template fitur berbeda.
    if ($igdOnly) {
        $base = ems_ai_diagnosis_assistant_system_prompt();
    } else {
        $template = ems_ai_get_active_prompt_template($pdo, $featureKey);
        $base = trim((string) ($template['system_prompt'] ?? '')) !== ''
            ? (string) $template['system_prompt']
            : $defaultPrompt;
    }
    if ($igdOnly) {
        $base .= "\n\nATURAN TAMBAHAN WAJIB AI DIAGNOSIS IGD:\n"
            . "Motor GCS harus dipisahkan: M3 = fleksi abnormal/dekortikasi; M4 = withdrawal/menarik diri normal. Pilih satu sesuai respons; jangan menulis fleksi abnormal/withdrawal sebagai sinonim.\n"
            . "Pada trauma kepala dengan kecurigaan peningkatan tekanan intrakranial, Disability wajib memuat pemeriksaan pupil (isokor/anisokor/dilatasi; reaktif/non reaktif). Anisokor/dilatasi dengan tanda herniasi akut membolehkan rencana operasi definitif cito tanpa menunggu CT; tanpa tanda herniasi akut jelas, tulis rencana tentatif — menunggu hasil CT scan.\n"
            . "Hipotensi permisif/permissive hypotension hanya istilah strategi resusitasi cairan, bukan temuan TTV mentah; TTV memakai deskripsi tanda awal syok hemoragik ringan-sedang bila sesuai.\n"
            . "Cek kesesuaian fisiologis antara mekanisme, volume, lokasi cedera, derajat syok, dan penurunan kesadaran. Jika tidak proporsional, jangan mengatribusikan semuanya ke cedera tunggal; tulis RED FLAG dengan frasa wajib, minta secondary survey menyeluruh dan pencitraan tambahan, serta evaluasi hipoksia, hipotensi, intoksikasi, kejang, atau penyebab non-traumatik sesuai data.\n"
            . "Semua pemeriksaan yang disebut di bagian mana pun laporan, termasuk Section 4 Status Rencana Operasi, wajib tercantum di Section 6 Rekomendasi Pemeriksaan Penunjang. Jangan menulis menunggu hasil CT scan jika CT scan tidak ada di rekomendasi radiologi. Tungkai berarti ekstremitas bawah/kaki; tangan dan jari memakai ekstremitas atas, tangan, atau jari tangan.\n";
    }

    $prompt = $base . ems_ai_ds_reference_suffix($includeMantra, $igdOnly);
    if ($unitCode !== null && $unitCode !== '') {
        $documents = ems_ai_official_document_context($pdo, $unitCode, $documentQuery, $featureKey);
        if ($documents !== '') {
            $prompt .= "\n\n" . $documents;
        }
    }

    return $prompt . "\n\n" . ems_ai_official_consistency_guardrail();
}

/**
 * Bersihkan output AI: lucuti prefix "/me"/"/do"/"/e" yang mungkin ikut ditulis AI,
 * validasi peran & kode animasi terhadap daftar resmi. Item rencana yang
 * dikembalikan model tanpa aksi/hasil lengkap dibuang, bukan diisi placeholder
 * klinis oleh PHP.
 */
function ems_ai_ds_sanitize_step_items(array $items): array
{
    $validAnimCodes = array_keys(ems_ai_ds_anim_mantra_table());
    $validRoles = ['DPJP', 'Asisten 1', 'Asisten 2'];
    $strip = static function (string $text, string $tag): string {
        do {
            $before = $text;
            $text = preg_replace('/^\s*(?:\/(?:me|do|e)\b[:\s-]*)+/iu', '', $text) ?? $text;
            $text = preg_replace('/^\s*(?:' . preg_quote($tag, '/') . '\b\s*)+/iu', '', $text) ?? $text;
        } while ($text !== $before);
        return trim($text);
    };

    $sanitized = [];
    $seenActions = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $pelaku = trim((string) ($item['pelaku'] ?? 'DPJP'));
        $pelaku = in_array($pelaku, $validRoles, true) ? $pelaku : 'DPJP';
        $instruksi = trim((string) ($item['instruksi'] ?? ''));
        $aksi = $strip((string) ($item['aksi'] ?? ''), 'me');
        $hasil = $strip((string) ($item['hasil'] ?? ''), 'do');
        $anim = $strip((string) ($item['animasi'] ?? ''), 'e');
        $anim = in_array($anim, $validAnimCodes, true) ? $anim : 'mechanic';

        // Model AI owns clinical completion. Do not invent placeholder action/result
        // text in PHP when the model returned an incomplete item.
        if ($aksi === '' || $hasil === '') {
            continue;
        }

        $actionKey = mb_strtolower((string) (preg_replace('/\s+/u', ' ', $aksi) ?? $aksi));
        if (isset($seenActions[$actionKey])) {
            continue;
        }
        $seenActions[$actionKey] = true;

        $sanitized[] = [
            'pelaku' => $pelaku,
            'instruksi' => $instruksi,
            'aksi' => $aksi,
            'hasil' => $hasil,
            'animasi' => $anim,
        ];
    }

    return $sanitized;
}

function ems_ai_ds_model_completion_contract(string $featureKey): string
{
    if ($featureKey === 'ai_diagnosis_assistant' || str_starts_with($featureKey, 'ai_diagnosis_assistant_')) {
        return 'KONTRAK OUTPUT AI DIAGNOSIS ASSISTANT: input boleh singkat, tetapi MODEL wajib menghasilkan laporan roleplay IGD lengkap dan spesifik terhadap kasus. Tulis anamnesis_lengkap yang diperluas, diagnosis utama, minimal 3 diagnosis banding, GCS/TTV konkret yang konsisten, hasil laboratorium/radiologi skenario, emergency 8-14 langkah unik dengan pelaku/instruksi/aksi/hasil/animasi, roleplay_note yang berbeda tiap kasus, sop_references dari konteks Modul Dokumen, dan laboratorium_terstruktur yang valid. Emergency harus dirancang ulang dari mekanisme kasus ini, bukan memakai urutan template umum. /me hanya satu tindakan fisik yang sedang dilakukan, tanpa "sesuai protokol", "sesuai instruksi", pilihan dengan kata "atau", atau penjelasan administratif. /do langsung menyebut hasil/temuan yang terlihat setelah tindakan, misalnya "Cairan NaCl 0,9% berhasil membilas luka dan perdarahan ringan terkontrol". Jangan mengulang input mentah. Jangan mengeluarkan "Estimasi AI", "wajib verifikasi", "Data belum tersedia", "menunggu hasil", atau "tidak tersedia" karena ini adalah roleplay dan semua hasil dianggap sudah tersedia. Variasikan nilai fisiologis, organ terdampak, tindakan, komunikasi, dan catatan. Jika ada kehamilan, janin, plasenta, persalinan, atau seksio sesarea, identitas pasien wajib Perempuan dan semua diagnosis/TTV/tindakan harus konsisten. Wajib membaca dan memasukkan riwayat operasi sebelumnya bila disebutkan, termasuk lokasi luka, waktu operasi, kondisi jahitan, komplikasi, dan alasan tindakan ulang; jangan mengulang tindakan pada sisi yang sama kecuali ada indikasi yang dijelaskan. Emergency berhenti pada stabilisasi IGD dan handoff ke Laboratorium, Radiologi, atau Ruang Operasi; jangan menulis langkah teknis operasi.';
    }

    if ($featureKey === 'ai_surgery_planner') {
        return 'KONTRAK PELENGKAPAN MODEL AI: KASUS MEDIS / TINDAKAN yang diberikan user boleh singkat. MODEL AI, bukan PHP dan bukan user, wajib merangkainya menjadi rencana operasi yang spesifik terhadap kasus: alasan/indikasi berbasis input, persiapan, tahapan prosedur, pembagian peran, risiko relevan, monitoring, dan rujukan SOP. Jangan mengembalikan Data belum tersedia untuk langkah rencana, rekomendasi, risiko, atau alur yang dapat disusun dari kasus. Data belum tersedia hanya untuk fakta pasien, angka pengukuran, hasil pemeriksaan, kejadian pelaksanaan, dan hasil operasi yang memang tidak diberikan. Semua isi tetap rencana, bukan bukti operasi sudah dilakukan.';
    }

    if ($featureKey === 'rekam_medis_ai') {
        return 'KONTRAK PELENGKAPAN MODEL AI: DATA SUMBER boleh ringkas atau tersebar. MODEL AI wajib menyusunnya menjadi narasi rekam medis yang koheren, terstruktur, dan spesifik terhadap kasus, termasuk hubungan diagnosis, indikasi, alur perawatan, serta rencana bila bagian itu diminta oleh schema. Jangan mengubah rencana menjadi tindakan atau hasil aktual. Data belum tersedia hanya untuk fakta pasien, angka pengukuran, hasil pemeriksaan, kejadian pelaksanaan, dan hasil operasi yang memang tidak ada di sumber.';
    }

    if ($featureKey === 'ai_diagnosis_assistant') {
        return 'KONTRAK PELENGKAPAN MODEL AI: ANAMNESIS / TEMUAN MEDIS / KONDISI FISIK dari user boleh singkat. MODEL AI, bukan PHP dan bukan user, wajib menentukan mekanisme cedera internal yang paling masuk akal dan memastikan seluruh bagian laporan (narasi klinis, diagnosis kerja, diagnosis banding, GCS, TTV, rekomendasi pemeriksaan, rencana tindakan, tahapan SOP, penanganan emergency) koheren tanpa kontradiksi. ATURAN WAJIB KLINIS: (1) GCS dihitung harfiah: M3 = fleksi abnormal/dekortikasi; M4 = withdrawal/menarik diri normal; pilih satu sesuai respons pasien dan jangan menulis fleksi abnormal/withdrawal sebagai sinonim; (2) Nilai belum diukur wajib berstatus "Estimasi AI — wajib verifikasi" pada field estimasi terpisah, field faktual tetap Data belum tersedia; (3) Airway definitif (intubasi endotrakeal / ETT) WAJIB disertakan pada emergency jika estimasi GCS ≤ 8; (4) Osmoterapi (Manitol 20% / NaCl 3% hipertonik) WAJIB disertakan jika ada kecurigaan peningkatan TTIK / trauma kepala berat; (5) Pada trauma kepala dengan kecurigaan peningkatan tekanan intrakranial, Disability wajib memuat pemeriksaan pupil (isokor/anisokor/dilatasi; reaktif/non reaktif); anisokor/dilatasi dengan tanda herniasi akut membolehkan rencana operasi definitif cito tanpa menunggu CT, selain itu tulis persis rencana tentatif — menunggu hasil CT scan; (6) Urutan logis: CT scan / penunjang penentu dievaluasi sebelum operasi definitif kecuali ada tanda cito akut; (7) Istilah hipotensi permisif/permissive hypotension hanya untuk strategi resusitasi cairan, bukan temuan TTV mentah; gunakan deskripsi tanda awal syok hemoragik ringan-sedang pada TTV; (8) Wajib sertakan informed consent wali (karena pasien tidak sadar) & persiapan darah (golongan darah + crossmatch/PRC) sebelum operasi berisiko perdarahan; (7) Diagnosis utama & diagnosis banding HARUS dalam ranah mekanisme cedera yang sama — jangan mencampur trauma tumpul dengan tajam/penetrasi, DDx hanya variasi organ/keparahan pada mekanisme yang sama; (8) Untuk trauma abdomen dengan tanda kegawatan absolut (eviserasi/prolaps organ, perdarahan masif, peritonitis pasien tidak stabil): prioritaskan laparotomi cito setelah stabilisasi; radiologi bedside tetap wajib berupa FAST dan/atau X-ray portable. CT scan ditulis sebagai DITUNDA — dijadwalkan di tahap Radiologi/pasca stabilisasi hanya bila relevan; (9) Kasus bervariasi unik (tingkat keparahan, organ terlibat, nilai fisiologis realistis) walau input mirip. Cek ulang konsistensi internal sebelum final.';
    }

    return 'Jangan mengarang fakta, angka, hasil pemeriksaan, tindakan selesai, atau kondisi pasien yang tidak didukung input/evidence; tandai data yang memang belum tersedia dan minta verifikasi bila diperlukan.';
}

/**
 * Panggil Gemini dengan system prompt + user prompt sebagai dua "parts" terpisah
 * dalam satu content role=user, mengikuti pola yang sudah dipakai
 * actions/ai_recruitment_service.php.
 */
function ems_ai_ds_strip_hallucination_instructions(string $prompt, string $featureKey = ''): string
{
    $patterns = [
        '/lengkapi\s+SENDIRI\s+seluruh\s+data\s+yang\s+hilang[^.]*\./iu',
        '/lengkapi\s+SENDIRI\s+seluruh\s+detail\s+yang\s+hilang[^.]*\./iu',
        '/JANGAN\s+mengembalikan\s+daftar\s+data\s+yang\s+dibutuhkan[^.]*\./iu',
        '/JANGAN\s+mengembalikan\s+pertanyaan\s+klarifikasi/iu',
        '/Susun\s+laporan\s+medis\s+berdasarkan\s+fakta\s+eksplisit\s+pada\s+anamnesis\s+dan\s+data\s+yang\s+diberikan\.[^\n]*/iu',
        '/Susun\s+rencana\s+operasi\s+berbasis\s+input\s+dan\s+dokumen\s+SOP\s+yang\s+diberikan\.[^\n]*/iu',
        '/lengkapi\s+setiap\s+detail[^.\n]*(?:\.|$)/iu',
        '/asumsi\s+realistis[^.\n]*(?:\.|$)/iu',
        '/hasilkan\s+sendiri[^.\n]*(?:\.|$)/iu',
        '/buat\s+nilai\s+[^.\n]*konkret[^.\n]*(?:\.|$)/iu',
    ];
    $completionInstruction = ems_ai_ds_model_completion_contract($featureKey);
    return preg_replace($patterns, $completionInstruction, $prompt) ?? $prompt;
}

function ems_ai_ds_call_gemini(PDO $pdo, string $systemPrompt, string $userPrompt, string $featureKey, ?int $createdBy): array
{
    $isDiagnosisAssistant = $featureKey === 'ai_diagnosis_assistant'
        || str_starts_with($featureKey, 'ai_diagnosis_assistant_');
    if (!$createdBy) {
        return ['ok' => false, 'error' => 'Sesi pengguna tidak valid. Silakan login ulang.'];
    }

    $userSettings = ems_ai_ds_get_user_settings($pdo, $createdBy);
    if ($userSettings === null) {
        return ['ok' => false, 'error' => 'Setting AI pribadi belum tersedia. Atur dulu di menu Roxwood Hospital AI > Setting AI Saya.'];
    }

    $customProvider = trim((string) ($userSettings['custom_provider'] ?? ''));
    $customApiKey = trim((string) ($userSettings['custom_api_key'] ?? ''));
    $customBaseUrl = trim((string) ($userSettings['custom_base_url'] ?? ''));
    $customModel = trim((string) ($userSettings['custom_default_model'] ?? ''));
    // Diagnosis Assistant membutuhkan structured JSON dan prompt SOP IGD
    // canonical. Custom provider tetap tersedia untuk fitur lain, tetapi
    // tidak boleh mengambil alih dua tahap Diagnosis Assistant.
    $useCustomProvider = !$isDiagnosisAssistant
        && $customProvider !== '' && $customBaseUrl !== '' && $customModel !== '';
    $customFieldsPresent = $customProvider !== '' || $customBaseUrl !== '' || $customModel !== '';
    if ($customFieldsPresent && !$useCustomProvider && !$isDiagnosisAssistant) {
        return ['ok' => false, 'error' => 'Konfigurasi custom provider belum lengkap. Isi provider, endpoint, dan model.'];
    }
    if (!$useCustomProvider && !ems_ai_ds_has_gemini_provider($userSettings)) {
        return ['ok' => false, 'error' => 'Belum ada provider AI aktif untuk fitur ini. Isi Gemini atau custom provider di Setting AI Saya.'];
    }

    $systemPrompt = ems_ai_ds_strip_hallucination_instructions($systemPrompt, $featureKey);
    $userPrompt = ems_ai_ds_strip_hallucination_instructions($userPrompt, $featureKey);

    if (!str_contains($systemPrompt, 'DOKUMEN RESMI ROXWOOD HOSPITAL') && !str_contains($userPrompt, 'DOKUMEN RESMI ROXWOOD HOSPITAL')) {
        $officialDocuments = ems_ai_official_document_context(
            $pdo,
            ems_ai_official_document_unit($pdo, $createdBy),
            $userPrompt,
            $featureKey,
            $isDiagnosisAssistant ? 3 : 6
        );
        if ($officialDocuments !== '') {
            $systemPrompt .= "\n\n" . $officialDocuments;
        }
    }
    if (!str_contains($systemPrompt, 'VALIDATION GATE WAJIB')) {
        $systemPrompt .= "\n\n" . ems_ai_official_consistency_guardrail();
    }

    $completionContract = ems_ai_ds_model_completion_contract($featureKey);
    $systemPrompt .= "\n\n" . $completionContract;
    $finalValidation = "FINAL VALIDATION GATE (mengalahkan instruksi template/user yang bertentangan):\n"
        . ems_ai_official_consistency_guardrail()
        . "\n" . $completionContract;

    if ($useCustomProvider) {
        try {
            $response = ems_custom_chat_completion(
                $pdo,
                [
                    'custom_provider' => $customProvider,
                    'custom_api_key' => $customApiKey,
                    'custom_base_url' => $customBaseUrl,
                    'custom_default_model' => $customModel,
                ],
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                    ['role' => 'system', 'content' => $finalValidation],
                ],
                $customModel,
                $featureKey,
                $createdBy,
                true
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $text = trim((string) ($response['content'] ?? ''));
        $parsed = ems_ai_decode_json_text($text);
        if ($text === '' || !is_array($parsed)) {
            return ['ok' => false, 'error' => 'Respons custom provider bukan format JSON yang valid.'];
        }

        return ['ok' => true, 'data' => $parsed, 'usage' => $response['usage'] ?? []];
    }

    $settings = array_merge(ems_ai_settings_defaults(), [
        'provider' => 'gemini',
        'is_enabled' => 1,
        'gemini_api_key' => (string) $userSettings['gemini_api_key'],
        'gemini_base_url' => trim((string) $userSettings['gemini_base_url']) !== '' ? (string) $userSettings['gemini_base_url'] : 'https://generativelanguage.googleapis.com/v1beta',
        'default_model' => trim((string) $userSettings['default_model']) !== '' ? (string) $userSettings['default_model'] : 'gemini-3.5-flash-lite',
        'timeout_seconds' => $isDiagnosisAssistant ? 150 : 55,
        'max_output_tokens' => $isDiagnosisAssistant ? 12000 : 8192,
        'daily_request_limit' => 0,
    ]);

    try {
        $response = ems_gemini_generate_content(
            $pdo,
            $settings,
            [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemPrompt],
                        ['text' => $userPrompt],
                        ['text' => $finalValidation],
                    ],
                ],
            ],
            (string) ($settings['default_model'] ?? 'gemini-3.5-flash-lite'),
            $featureKey,
            $createdBy
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $text = trim((string) ($response['text'] ?? ''));
    if ($text === '') {
        return ['ok' => false, 'error' => 'Respons kosong dari model AI.'];
    }

    $parsed = ems_ai_decode_json_text($text);
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'Respons AI bukan format JSON yang valid.'];
    }

    return [
        'ok' => true,
        'data' => $parsed,
        'usage' => $response['usage'] ?? [],
        'system_prompt' => $systemPrompt,
    ];
}

function ems_ai_ds_format_diagnosis_report_text(
    array $report,
    array $result,
    array $ttvDisplayItems = [],
    string $gcsDisplay = '',
    bool $gcsIsEstimate = false,
    ?array $gcsEstimate = null
): string {
    // Formatter hanya merender JSON final yang sudah disimpan. Ia tidak boleh
    // menormalkan, menambah pemeriksaan, atau mengubah keputusan model.
    $sourceAnamnesis = trim((string) ($report['anamnesis'] ?? ''));
    $result = is_array($result) ? $result : [];
    $missingClinicalValue = static function (string $value): bool {
        return in_array(mb_strtolower(trim($value)), ['', '-', 'data belum tersedia', 'belum diukur', 'belum dinilai'], true);
    };

    if ($gcsDisplay === '') {
        $gcsActual = trim((string) ($result['gcs'] ?? ''));
        $gcsEstimate = is_array($result['gcs_estimasi_ai'] ?? null) ? $result['gcs_estimasi_ai'] : null;
        $gcsIsEstimate = $missingClinicalValue($gcsActual) && $gcsEstimate !== null && trim((string) ($gcsEstimate['score'] ?? '')) !== '';
        $gcsDisplay = $gcsIsEstimate ? (string) $gcsEstimate['score'] : ($gcsActual !== '' ? $gcsActual : 'Data belum tersedia');
    }

    if (empty($ttvDisplayItems)) {
        $ttvLabelKey = static function (string $label): string {
            $label = mb_strtolower($label);
            foreach (['tekanan' => ['tekanan', 'blood pressure'], 'nadi' => ['nadi', 'pulse', 'heart rate'], 'suhu' => ['suhu', 'temperature'], 'respirasi' => ['respirasi', 'respiratory', 'rr'], 'saturasi' => ['saturasi', 'spo2', 'sao2', 'oxygen']] as $key => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($label, $needle)) {
                        return $key;
                    }
                }
            }
            return trim((string) preg_replace('/[^a-z0-9]+/i', '', $label));
        };
        $ttvEstimateByKey = [];
        $ttvEstimates = is_array($result['ttv_estimasi_ai'] ?? null) ? $result['ttv_estimasi_ai'] : [];
        foreach ($ttvEstimates as $estimate) {
            if (is_array($estimate)) {
                $key = $ttvLabelKey((string) ($estimate['label'] ?? ''));
                if ($key !== '' && trim((string) ($estimate['value'] ?? '')) !== '') {
                    $ttvEstimateByKey[$key] = $estimate;
                }
            }
        }
        $usedTtvEstimateKeys = [];
        foreach ((array) ($result['ttv'] ?? []) as $vital) {
            if (!is_array($vital)) {
                continue;
            }
            $key = $ttvLabelKey((string) ($vital['label'] ?? ''));
            $value = trim((string) ($vital['value'] ?? ''));
            if ($missingClinicalValue($value) && isset($ttvEstimateByKey[$key])) {
                $estimate = $ttvEstimateByKey[$key];
                $vital['label'] = ems_ai_ds_ttv_slot_labels()[$key] ?? (string) ($vital['label'] ?? '');
                $vital['value'] = (string) $estimate['value'];
                $vital['note'] = (string) ($estimate['note'] ?? '');
                $vital['_is_estimate'] = true;
                $vital['_estimate_status'] = ems_ai_ds_estimate_status_label();
                $vital['_estimate_basis'] = (string) ($estimate['basis'] ?? '');
                $usedTtvEstimateKeys[$key] = true;
            } elseif ($key !== '' && !$missingClinicalValue($value)) {
                $usedTtvEstimateKeys[$key] = true;
            }
            $ttvDisplayItems[] = $vital;
        }
        foreach ($ttvEstimateByKey as $key => $estimate) {
            if (isset($usedTtvEstimateKeys[$key])) {
                continue;
            }
            $estimate['_is_estimate'] = true;
            $estimate['_estimate_status'] = ems_ai_ds_estimate_status_label();
            $estimate['_estimate_basis'] = (string) ($estimate['basis'] ?? '');
            $ttvDisplayItems[] = $estimate;
        }
    }

    $lines = [];
    $divider = str_repeat('=', 60);
    $subDivider = str_repeat('-', 60);

    $lines[] = $divider;
    $lines[] = "        LAPORAN DIAGNOSIS INSTALASI GAWAT DARURAT (IGD)";
    $lines[] = "                      ROXWOOD HOSPITAL";
    $lines[] = $divider;

    if (!empty($report['report_code'])) {
        $lines[] = "Kode Referensi : " . $report['report_code'];
    }
    $createdDate = !empty($report['created_at']) ? date('d/m/Y H:i', strtotime((string) $report['created_at'])) . ' WIB' : '-';
    $lines[] = "Waktu Laporan  : " . $createdDate;
    $lines[] = "Dokter / DPJP  : " . (!empty($report['created_by_name']) ? $report['created_by_name'] : '-');
    $lines[] = "";

    // 1. Identitas Pasien
    $hasPatient = !empty($report['patient_name']) || !empty($report['patient_gender']) || !empty($report['patient_dob']) || !empty($report['patient_citizen_id']);
    if ($hasPatient) {
        $lines[] = $subDivider;
        $lines[] = "1. IDENTITAS PASIEN";
        $lines[] = $subDivider;
        $lines[] = "Nama Pasien   : " . ($report['patient_name'] ?: '-');
        $lines[] = "Jenis Kelamin : " . ($report['patient_gender'] ?: '-');
        $dobFormatted = !empty($report['patient_dob']) ? date('d/m/Y', strtotime((string) $report['patient_dob'])) : '-';
        $lines[] = "Tanggal Lahir : " . $dobFormatted;
        $lines[] = "Citizen ID    : " . ($report['patient_citizen_id'] ?: '-');
        $lines[] = "";
    }

    // 2. Anamnesis / Temuan Medis
    $lines[] = $subDivider;
    $lines[] = "2. ANAMNESIS & TEMUAN MEDIS";
    $lines[] = $subDivider;
    $anamnesisLengkap = trim((string) ($result['anamnesis_lengkap'] ?? ''));
    if ($anamnesisLengkap !== '') {
        $lines[] = $anamnesisLengkap;
    } elseif ($sourceAnamnesis !== '') {
        $lines[] = $sourceAnamnesis;
    } else {
        $lines[] = "-";
    }
    $lines[] = "";

    // 3. Diagnosis
    $lines[] = $subDivider;
    $lines[] = "3. DIAGNOSIS";
    $lines[] = $subDivider;
    $lines[] = "Diagnosis Utama   : " . ($result['diagnosis_utama'] ?? '-');
    $diagBanding = (array) ($result['diagnosis_banding'] ?? []);
    if (!empty($diagBanding)) {
        $lines[] = "Diagnosis Banding :";
        foreach ($diagBanding as $db) {
            $lines[] = "  • " . $db;
        }
    } else {
        $lines[] = "Diagnosis Banding : -";
    }
    $lines[] = "";

    // 4. Status Neurologis & Tanda-Tanda Vital
    $lines[] = $subDivider;
    $lines[] = "4. STATUS NEUROLOGIS & TANDA-TANDA VITAL (TTV)";
    $lines[] = $subDivider;
    $gcsText = "GCS Score : " . $gcsDisplay;
    if ($gcsIsEstimate) {
        $gcsText .= " [" . ems_ai_ds_estimate_status_label() . "]";
    }
    $lines[] = $gcsText;
    if ($gcsIsEstimate && !empty($gcsEstimate['interpretation'])) {
        $lines[] = "  Interpretasi : " . $gcsEstimate['interpretation'];
    }
    if ($gcsIsEstimate && !empty($gcsEstimate['basis'])) {
        $lines[] = "  Dasar Klinis : " . $gcsEstimate['basis'];
    }
    if (!empty($result['kesadaran']) && $result['kesadaran'] !== 'Data belum tersedia') {
        $lines[] = "Kesadaran : " . $result['kesadaran'];
    }
    if (!empty($result['motorik']) && $result['motorik'] !== 'Data belum tersedia') {
        $lines[] = "Motorik   : " . $result['motorik'];
    }
    if (!empty($result['gcs_motor_definition'])) {
        $lines[] = "Definisi Motor GCS : " . $result['gcs_motor_definition'];
    }
    if (is_array($result['pemeriksaan_pupil'] ?? null)) {
        $pupilStatus = trim((string) ($result['pemeriksaan_pupil']['status'] ?? ''));
        $pupilReactivity = trim((string) ($result['pemeriksaan_pupil']['reaktivitas'] ?? ''));
        if ($pupilStatus !== '' && !preg_match('/data belum|belum tersedia/iu', $pupilStatus)) {
            $lines[] = "Pemeriksaan Pupil : " . $pupilStatus . ($pupilReactivity !== '' ? "; " . $pupilReactivity : '');
        }
    }
    if (!empty($result['status_rencana_operasi'])) {
        $lines[] = "Status Rencana Operasi : " . $result['status_rencana_operasi'];
    }
    $lines[] = "";
    $lines[] = "Tanda-Tanda Vital :";
    if (!empty($ttvDisplayItems)) {
        foreach ($ttvDisplayItems as $vital) {
            $vLabel = $vital['label'] ?? '-';
            $vVal = $vital['value'] ?? 'Data belum tersedia';
            $vNote = trim((string) ($vital['note'] ?? ''));
            $isEst = !empty($vital['_is_estimate']);
            $vLine = "  • " . str_pad($vLabel, 16) . ": " . $vVal;
            if ($isEst) {
                $vLine .= " [" . ems_ai_ds_estimate_status_label() . "]";
            }
            if ($vNote !== '' && $vNote !== 'Data belum tersedia') {
                $vLine .= " (" . $vNote . ")";
            }
            $lines[] = $vLine;
        }
    } else {
        $lines[] = "  Data TTV belum tersedia.";
    }
    $lines[] = "";

    // 5. Rencana Tindakan & Operasi
    $lines[] = $subDivider;
    $lines[] = "5. TINDAKAN & RENCANA OPERASI";
    $lines[] = $subDivider;
    $lines[] = "Kasus / Tindakan Medis :";
    $lines[] = trim((string) ($result['kasus_tindakan'] ?? '-'));
    $lines[] = "Jenis Operasi          : " . ($result['jenis_operasi'] ?? 'Belum ditentukan');
    $lines[] = "Jenis Anestesi         : " . ($result['jenis_anestesi'] ?? 'Belum ditentukan');
    $lines[] = "";

    // 6. Rekomendasi Pemeriksaan Penunjang
    $lines[] = $subDivider;
    $lines[] = "6. REKOMENDASI PEMERIKSAAN PENUNJANG";
    $lines[] = $subDivider;
    $lines[] = "Laboratorium :";
    $labs = (array) ($result['lab'] ?? []);
    if (!empty($labs)) {
        foreach ($labs as $l) {
            if (is_array($l)) {
                $l = $l['hasil'] ?? $l['result'] ?? $l['name'] ?? $l['test'] ?? json_encode($l, JSON_UNESCAPED_UNICODE);
            }
            $lines[] = "  • " . $l;
        }
    } else {
        $lines[] = "  Tidak ada rekomendasi laboratorium.";
    }
    $structLab = is_array($result['laboratorium_terstruktur'] ?? null) ? $result['laboratorium_terstruktur'] : null;
    if ($structLab && trim((string) ($structLab['department'] ?? '')) !== '') {
        $opt = trim((string) ($structLab['level3_option'] ?? ''));
        $labPath = $structLab['department'] . " > " . $structLab['category'] . ($opt !== '' ? " > " . $opt : '');
        $lines[] = "  [Pilihan Lab AI: " . $labPath . " | Spesimen: " . ($structLab['specimen_type'] ?? '-') . "]";
    }
    $lines[] = "";

    $lines[] = "Radiologi :";
    $rads = (array) ($result['radiologi'] ?? []);
    if (!empty($rads)) {
        foreach ($rads as $r) {
            if (is_array($r)) {
                $r = $r['hasil'] ?? $r['result'] ?? $r['name'] ?? $r['test'] ?? json_encode($r, JSON_UNESCAPED_UNICODE);
            }
            $lines[] = "  • " . $r;
        }
    } else {
        $lines[] = "  Tidak ada rekomendasi radiologi.";
    }
    $structRad = is_array($result['radiologi_terstruktur'] ?? null) ? $result['radiologi_terstruktur'] : null;
    if ($structRad && trim((string) ($structRad['modality'] ?? '')) !== '') {
        $radPath = $structRad['modality'] . " > " . $structRad['category'] . " > " . $structRad['body_region'] . " > " . $structRad['projection'];
        $lines[] = "  [Pilihan Rad AI: " . $radPath . " | Temuan: " . ($structRad['clinical_finding'] ?? '-') . "]";
    }
    $lines[] = "";

    // 7. Penanganan Emergency (BLS & Initial Care)
    $emergencySource = is_array($result['emergency'] ?? null) ? $result['emergency'] : [];
    $emergencyItems = $emergencySource;
    if (!empty($emergencyItems)) {
        $lines[] = $subDivider;
        $lines[] = "7. PENANGANAN EMERGENCY (BLS & INITIAL CARE)";
        $lines[] = $subDivider;
        foreach ($emergencyItems as $i => $action) {
            $idx = $i + 1;
            $pelaku = $action['pelaku'] ?? 'DPJP';
            $instruksi = trim((string) ($action['instruksi'] ?? ''));
            $anim = trim((string) ($action['animasi'] ?? 'mechanic'));
            $aksi = trim((string) ($action['aksi'] ?? ''));
            $hasil = trim((string) ($action['hasil'] ?? ''));

            $lines[] = "[$idx] TINDAKAN $idx ($pelaku)";
            if ($instruksi !== '') {
                $lines[] = "Instruksi : " . $instruksi;
            }
            $lines[] = "/e " . $anim;
            $lines[] = "/me " . $aksi;
            $lines[] = "/do " . $hasil;
            $lines[] = "";
        }
    }

    // 8. Catatan Medis & Roleplay
    $roleplayNote = trim((string) ($result['roleplay_note'] ?? ''));
    if ($roleplayNote !== '' && $roleplayNote !== '-') {
        $lines[] = $subDivider;
        $lines[] = "8. CATATAN MEDIS & ROLEPLAY";
        $lines[] = $subDivider;
        $lines[] = $roleplayNote;
        $lines[] = "";
    }

    // 9. Rujukan SOP
    $sopRefs = (array) ($result['sop_references'] ?? []);
    if (!empty($sopRefs)) {
        $lines[] = $subDivider;
        $lines[] = "9. RUJUKAN STANDAR OPERASIONAL PROSEDUR (SOP)";
        $lines[] = $subDivider;
        foreach ($sopRefs as $sop) {
            $lines[] = "• " . $sop;
        }
        $lines[] = "";
    }

    $lines[] = $divider;
    $lines[] = "        [ AKHIR LAPORAN DIAGNOSIS ROXWOOD HOSPITAL ]";
    $lines[] = $divider;

    return implode("\n", $lines);
}
