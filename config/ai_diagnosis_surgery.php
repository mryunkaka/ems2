<?php

/**
 * Referensi domain (SOP Roxwood Hospital, kamus mantra /me /do, kode animasi /e,
 * kewenangan tim, klasifikasi operasi) untuk fitur AI Diagnosis Assistant dan
 * AI Surgery Planner. Data ini disisipkan ke system prompt Gemini di runtime,
 * terpisah dari system_prompt dasar yang tersimpan di system_ai_prompt_templates
 * supaya persona/aturan inti tetap bisa diedit lewat DB tanpa deploy ulang.
 */

require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';
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
}

/**
 * Setting AI pribadi (khusus AI Diagnosis Assistant & AI Surgery Planner) -
 * setiap user mengisi API key Gemini miliknya sendiri, terpisah dari
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
        'Operasi minor: Assessment -> Cleaning/Irigasi -> Antiseptic Preparation -> Exploration -> Closure -> Dressing; Sign In, Time Out, Sign Out.',
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
    return "DPJP (Dokter Penanggung Jawab): kewenangan penuh. Melakukan tindakan definitif, berisiko tinggi, atau butuh keputusan klinis langsung (evaluasi/eksplorasi luka, insisi, reposisi fraktur, penjahitan definitif, pengambilan keputusan tindakan). Memberi instruksi verbal ke asisten sebelum asisten bertindak.\n"
        . "Asisten 1 & Asisten 2 (co-ass/paramedic di bawah supervisi DPJP): kewenangan terbatas, HANYA bertindak atas instruksi DPJP, tidak mengambil keputusan definitif mandiri. Asisten 1 fokus menyiapkan/mengambil alat & instrumen, sterilisasi area, dokumentasi bantu; Asisten 2 fokus memasang infus/oksigen/kantong darah, menyuntik obat atas perintah, memantau monitor EKG, suction, irigasi.\n"
        . "Pola dialog resmi: DPJP memberi perintah singkat (\"DPJP: tolong ...\"), asisten merespons singkat (\"Asisten 1/2: Baik, dok.\") baru melakukan tindakan /me /do /e.\n"
        . "Libatkan Asisten 2 hanya pada kasus berat/kompleks/mayor yang butuh banyak tindakan simultan; kasus ringan-sedang cukup DPJP + Asisten 1.\n"
        . "Sumber: RH - Kebijakan Kewenangan Medis.pdf; PANDUAN PENANGGANAN OPERASI.pdf.";
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
        . "1. Pertahankan semua fakta eksplisit dari anamnesis dan identitas pasien. JANGAN mengarang usia, berat badan, mekanisme cedera, lokasi luka, GCS, TTV, hasil pemeriksaan, respons terapi, atau kondisi pasca tindakan yang tidak diberikan.\n"
        . "2. Data yang belum diberikan wajib ditulis \"Belum diukur\", \"Belum dinilai\", atau \"Data belum tersedia\"; jangan menggantinya dengan angka atau temuan definitif. Diagnosis utama hanya boleh ditegakkan bila didukung data; dugaan masuk diagnosis banding dan diberi label dugaan.\n"
        . "3. Semua nilai yang memang tersedia harus koheren secara internal dan sesuai standar medis internasional (ABCDE, ATLS, Primary/Secondary Survey) serta SOP Roxwood Hospital.\n"
        . "4. TTV, GCS, kesadaran, anamnesis, tindakan, anestesi, dan hasil tindakan wajib menggambarkan kondisi aktual yang tertulis. Suhu 33°C adalah hipotermia dan tidak boleh ditulis sebagai suhu normal atau \"tidak hipotermia\".\n"
        . "5. Sisipkan transparansi di akhir \"roleplay_note\" tentang data yang belum tersedia dan inferensi yang dipakai; jangan menyamarkan asumsi sebagai fakta.\n"
        . "6. Field \"emergency\" hanya berisi tindakan fisik/hands-on langsung ke pasien yang punya gerakan/animasi nyata. JANGAN masukkan tindakan administratif/komunikasi (mis. \"menghubungi tim bedah\") sebagai item tersendiri.\n"
        . "7. Setiap item \"emergency\" adalah object 5 field: \"pelaku\" (DPJP/Asisten 1/Asisten 2), \"instruksi\" (dialog DPJP ke asisten, format \"DPJP: <perintah>\" + opsional \"<Asisten>: Baik, dok.\" - wajib diisi bila pelaku Asisten, kosongkan bila DPJP sendiri), \"aksi\" (/me tanpa prefix), \"hasil\" (/do tanpa prefix, hanya boleh menyatakan hasil yang benar-benar tersedia dari input; bila belum ada observasi tulis \"Data belum tersedia\"), \"animasi\" (kode /e paling sesuai secara semantik, default \"mechanic\" bila tidak ada yang cocok). Jangan menulis tindakan atau hasil sebagai sudah dilakukan bila data hanya berupa rencana.\n"
        . "8. Ikuti pembagian peran & kewenangan dari referensi di bawah - jangan biarkan Asisten mengambil keputusan definitif.\n"
        . "9. Susun MINIMAL 8-12 tindakan berurutan dan logis: penilaian awal/ABCDE -> stabilisasi -> tindakan definitif -> monitoring/penyelesaian.\n"
        . "10. Field \"emergency\" wajib memakai mantra resmi dari referensi kamus me/do (bila kategorinya cocok) sebagai basis obat dan alat.\n"
        . "11. Tentukan \"jenis_operasi\" dan \"jenis_anestesi\" berdasarkan referensi klasifikasi operasi - sebutkan klasifikasi (Minor/Mayor) DAN nama tindakan spesifik, atau \"Tidak diperlukan operasi...\" bila tak perlu. ORIF/Open Reduction Internal Fixation WAJIB diklasifikasikan sebagai operasi Mayor.\n"
        . "12. Isi \"kasus_tindakan\" dengan ringkasan padat 1-2 kalimat yang WAJIB menyebutkan nama tindakan/operasi spesifik, selaras dengan \"jenis_operasi\" — kalimat ini akan di-copy-paste APA ADANYA oleh dokter ke form AI Surgery Planner sebagai input \"Kasus Medis / Tindakan yang Diperlukan\", jadi harus berdiri sendiri sebagai konteks lengkap (jangan menyingkat/mengasumsikan pembaca sudah tahu anamnesis awal).\n"
        . "13. \"radiologi\" (array teks bebas untuk dibaca manusia) TETAP wajib diisi. TAMBAHAN WAJIB: \"radiologi_terstruktur\" adalah SATU object berisi rekomendasi pencitraan PALING prioritas/relevan, dan nilai \"modality\", \"category\", \"body_region\", \"projection\" WAJIB berupa STRING TUNGGAL (bukan array/list) dipilih PERSIS (karakter identik, jangan parafrase/terjemahkan) dari salah satu baris di REFERENSI KATALOG RADIOLOGI di bawah — setiap baris referensi berformat \"Modality > Category > Body Region > [opsi1, opsi2, ...]\", dan \"projection\" HARUS diisi HANYA SATU dari opsi di dalam kurung siku itu (pilih yang paling relevan secara klinis), JANGAN menyalin seluruh isi kurung siku sebagai list. JANGAN mengarang kombinasi yang tidak ada di daftar itu. Field \"clinical_finding\" pada object yang sama WAJIB dipilih persis dari REFERENSI TEMUAN KLINIS. Jika pasien sama sekali tidak butuh pencitraan, isi seluruh 4 field modality/category/body_region/projection dengan string kosong \"\" dan clinical_finding tetap diisi sewajarnya.\n"
        . "13a. \"lab\" (array teks bebas untuk dibaca manusia) TETAP wajib diisi. TAMBAHAN WAJIB: \"laboratorium_terstruktur\" adalah SATU object berisi rekomendasi pemeriksaan laboratorium PALING prioritas/relevan, dan nilai \"department\", \"category\", \"level3_option\", \"specimen_type\" WAJIB berupa STRING TUNGGAL (bukan array/list) dipilih PERSIS (karakter identik, jangan parafrase/terjemahkan) dari salah satu baris di REFERENSI KATALOG LABORATORIUM di bawah — setiap baris referensi berformat \"Department > Category > [opsi level3 kalau ada] > Spesimen: [opsi1, opsi2, ...]\". Kalau kategori itu tidak punya opsi level3 di referensi, isi \"level3_option\" dengan string kosong \"\". \"specimen_type\" HARUS diisi HANYA SATU dari daftar Spesimen pada baris yang sama. JANGAN mengarang kombinasi yang tidak ada di daftar itu. Jika pasien sama sekali tidak butuh pemeriksaan laboratorium, isi seluruh 4 field dengan string kosong \"\".\n"
        . "14. Bahasa Indonesia medis baku. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n"
        . "15. Field JSON WAJIB PERSIS memakai nama key seperti di Struktur JSON di bawah — JANGAN salah ketik/singkat nama key (contoh kesalahan yang PERNAH terjadi dan DILARANG diulang: menulis \"rolepy_note\" alih-alih \"roleplay_note\"). Cek ulang ejaan setiap nama key sebelum membalas.\n"
        . "16. \"anamnesis_lengkap\" WAJIB diisi: tulis ulang anamnesis asli menjadi narasi klinis rapi dengan mempertahankan semua fakta eksplisit. Bagian yang tidak disebutkan tetap ditandai \"belum tersedia\"; jangan mengisi mekanisme cedera, lokasi spesifik, kondisi kesadaran, atau hasil pemeriksaan dengan asumsi definitif.\n"
        . "17. GCS wajib aritmetis: total = E + V + M. Jangan menulis GCS 13 bersama E4 V4 M6 karena E4+V4+M6 = 14; bila total 13 dan E4 V4, M harus 5. Jangan mengubah komponen hanya untuk mengejar total tanpa bukti.\n\n"
        . "Struktur JSON WAJIB (semua field terisi lengkap, tidak ada yang kosong kecuali disebutkan sebaliknya di aturan 13):\n"
        . "{\n"
        . "  \"status\": \"ringkas kondisi pasien\",\n"
        . "  \"anamnesis_lengkap\": \"anamnesis yang sudah dilengkapi/direvisi jadi narasi klinis utuh, lihat aturan 16\",\n"
        . "  \"jenis_operasi\": \"klasifikasi + nama tindakan spesifik, atau keterangan tidak perlu operasi\",\n"
        . "  \"jenis_anestesi\": \"jenis anestesi yang sesuai, atau tidak diperlukan anestesi\",\n"
        . "  \"kasus_tindakan\": \"ringkas kategori kasus & tindakan definitif, berdiri sendiri sebagai konteks lengkap\",\n"
        . "  \"diagnosis_utama\": \"diagnosis utama bahasa medis\",\n"
        . "  \"diagnosis_banding\": [\"diagnosis banding 1\", \"diagnosis banding 2\"],\n"
        . "  \"gcs\": \"contoh: E4V5M6 (15) - Compos Mentis; jika tidak tersedia tulis Data belum tersedia\",\n"
        . "  \"kesadaran\": \"kesadaran aktual sebelum tindakan atau Data belum tersedia\",\n"
        . "  \"motorik\": \"status motorik aktual atau Data belum tersedia\",\n"
        . "  \"ttv\": [{\"label\": \"Tekanan Darah\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"interpretasi berbasis nilai atau Data belum tersedia\"}, {\"label\": \"Nadi / HR\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"...\"}, {\"label\": \"Suhu\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"33°C wajib disebut hipotermia bila tercatat\"}, {\"label\": \"Respirasi / RR\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"...\"}, {\"label\": \"Saturasi O2\", \"value\": \"nilai aktual atau Data belum tersedia\", \"note\": \"...\"}],\n"
        . "  \"lab\": [\"pemeriksaan lab 1\"],\n"
        . "  \"laboratorium_terstruktur\": {\"department\": \"contoh: Hematologi\", \"category\": \"contoh: Complete Blood Count (CBC)\", \"level3_option\": \"contoh: Semua Parameter (Default) (string kosong kalau kategori tidak punya opsi level3)\", \"specimen_type\": \"contoh: Whole Blood EDTA (HANYA SATU string, bukan list semua opsi)\"},\n"
        . "  \"radiologi\": [\"rekomendasi radiologi 1 (teks bebas untuk dibaca manusia)\"],\n"
        . "  \"radiologi_terstruktur\": {\"modality\": \"contoh: X-Ray\", \"category\": \"contoh: Upper Extremity\", \"body_region\": \"contoh: Wrist\", \"projection\": \"contoh: PA (HANYA SATU string, bukan list semua opsi)\", \"clinical_finding\": \"persis dari daftar temuan klinis\"},\n"
        . "  \"emergency\": [{\"pelaku\": \"DPJP\", \"instruksi\": \"\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}, {\"pelaku\": \"Asisten 1\", \"instruksi\": \"DPJP: tolong siapkan set jahit.\\nAsisten 1: Baik, dok.\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}],\n"
        . "  \"roleplay_note\": \"narasi roleplay + transparansi data asumsi\",\n"
        . "  \"sop_references\": [\"rujukan SOP relevan\"]\n"
        . "}";
}

function ems_ai_ds_default_surgery_system_prompt(): string
{
    return "Anda adalah dokter spesialis bedah senior Roxwood Hospital dengan pengalaman lebih dari 15 tahun, menyusun rencana operasi (operative note) untuk simulasi/roleplay EMS. Tugas Anda: dari jenis operasi, jenis anestesi, tingkat kompleksitas, dan kasus medis yang diberikan (sepadat apa pun), susun rencana operasi LENGKAP, definitif, dan siap pakai, bukan daftar pertanyaan.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. Pertahankan fakta kasus dan input dokter. Jangan mengarang temuan, hasil operasi, status kesadaran, TTV, atau respons pasien yang tidak disebutkan. Detail yang belum tersedia harus ditulis sebagai data belum tersedia, bukan asumsi definitif.\n"
        . "2. \"durasi\" wajib realistis dan PROPORSIONAL dengan jumlah \"tahapan_prosedur\" dan kompleksitas kasus - makin banyak langkah/makin kompleks, makin lama durasinya. Operasi Minor umumnya 30-90 menit; Mayor 2-8 jam. Format contoh: \"4 Jam 30 Menit\".\n"
        . "3. \"farmakologi\" hanya boleh memuat obat yang didukung indikasi dan data kasus. Jika indikasi, dosis, atau rencana obat tidak tercatat, isi dengan \"Data belum tersedia\"; jangan membuat resep atau dosis konkret untuk melengkapi format.\n"
        . "3a. KESELAMATAN OBAT: untuk bedah saraf/kraniotomi, mata, atau tindakan berisiko perdarahan tinggi, JANGAN meresepkan NSAID/antiplatelet (Ketorolac, Asam Mefenamat, Ibuprofen, Aspirin) - gunakan Paracetamol dan/atau opioid sebagai gantinya. Untuk operasi lain tanpa risiko perdarahan tinggi, NSAID boleh dipakai sesuai indikasi.\n"
        . "8. \"tahapan_prosedur\" wajib memiliki JUMLAH LANGKAH PERSIS SESUAI permintaan eksplisit user (disebutkan sebagai \"JUMLAH LANGKAH: N\" pada pesan user) - tidak boleh kurang maupun lebih. Susun N langkah logis berdasarkan tindakan dan data yang tersedia. Detail yang tidak diberikan ditulis \"Data belum tersedia\", bukan hasil tindakan yang sudah terjadi.\n"
        . "9. Ikuti pembagian peran & kewenangan dari referensi - DPJP sebagai operator utama melakukan tindakan definitif, Asisten 1 & 2 membantu atas instruksi dan supervisi. Anestesi lokal oleh co-ass tidak boleh ditulis sebagai tindakan mandiri tanpa supervisi; catat operator aktual hanya bila diberikan. ORIF/Open Reduction Internal Fixation selalu Mayor.\n"
        . "10. \"risiko_komplikasi\" hanya memuat risiko yang relevan dengan jenis tindakan; jangan menyatakannya sebagai kejadian aktual.\n"
        . "11. \"laporan_pasca_operasi\" adalah ringkasan rencana/operative note; jangan menulis hasil aktual jika tindakan belum dinyatakan dilakukan.\n"
        . "12. Bahasa Indonesia medis baku. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n\n"
        . "Struktur JSON WAJIB (field tetap ada; data yang tidak tersedia ditulis Data belum tersedia):\n"
        . "{\n"
        . "  \"durasi\": \"contoh: 4 Jam 30 Menit\",\n"
        . "  \"farmakologi\": {\"pra_operatif\": [{\"nama\": \"...\", \"dosis\": \"...\", \"catatan\": \"...\"}], \"intra_operatif\": [...], \"post_operatif\": [...], \"pemulangan\": [...]},\n"
        . "  \"tahapan_prosedur\": [{\"pelaku\": \"DPJP\", \"instruksi\": \"\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}, {\"pelaku\": \"Asisten 1\", \"instruksi\": \"DPJP: tolong siapkan set bedah.\\nAsisten 1: Baik, dok.\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}],\n"
        . "  \"risiko_komplikasi\": [{\"judul\": \"nama risiko\", \"deskripsi\": \"penjelasan singkat\"}],\n"
        . "  \"laporan_pasca_operasi\": \"ringkasan operative note 2-4 kalimat\"\n"
        . "}";
}

function ems_ai_ds_reference_suffix(bool $includeMantra = true): string
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
    $operationText = strtolower($category . ' ' . $caseText);
    if (str_contains($operationText, 'orif') || str_contains($operationText, 'open reduction internal fixation')) {
        return 'Mayor';
    }

    return $category;
}

function ems_ai_ds_normalize_diagnosis_result(array $data): array
{
    ems_ai_ds_recover_field($data, 'roleplay_note', 'note');
    $data['jenis_operasi'] = ems_ai_ds_effective_operation_category(
        (string) ($data['jenis_operasi'] ?? ''),
        (string) ($data['kasus_tindakan'] ?? '')
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

    foreach (['kesadaran', 'motorik'] as $field) {
        if (!array_key_exists($field, $data) || trim((string) $data[$field]) === '') {
            $data[$field] = 'Data belum tersedia';
        }
    }

    $operationText = mb_strtolower(implode(' ', [
        (string) ($data['jenis_operasi'] ?? ''),
        (string) ($data['kasus_tindakan'] ?? ''),
    ]));
    if (str_contains($operationText, 'orif') || str_contains($operationText, 'open reduction internal fixation')) {
        $operation = trim((string) ($data['jenis_operasi'] ?? ''));
        $operation = preg_replace('/\bminor\b/i', 'Mayor', $operation) ?? $operation;
        $data['jenis_operasi'] = $operation !== '' ? $operation : 'Mayor - Open Reduction Internal Fixation (ORIF)';
    }

    return $data;
}

function ems_ai_ds_build_system_prompt(PDO $pdo, string $featureKey, string $defaultPrompt, bool $includeMantra = true, ?string $unitCode = null, string $documentQuery = ''): string
{
    $template = ems_ai_get_active_prompt_template($pdo, $featureKey);
    $base = trim((string) ($template['system_prompt'] ?? '')) !== ''
        ? (string) $template['system_prompt']
        : $defaultPrompt;

    $prompt = $base . ems_ai_ds_reference_suffix($includeMantra);
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
 * validasi peran & kode animasi terhadap daftar resmi, dan pastikan aksi/hasil
 * tidak pernah kosong di tampilan (sama seperti penanganan di AI Diagnosis Roxwood
 * versi standalone).
 */
function ems_ai_ds_sanitize_step_items(array $items): array
{
    $validAnimCodes = array_keys(ems_ai_ds_anim_mantra_table());
    $validRoles = ['DPJP', 'Asisten 1', 'Asisten 2'];
    $strip = static function (string $t): string {
        return preg_replace('/^\s*\/(me|do|e)\b[:\s-]*/i', '', $t) ?? $t;
    };

    return array_map(static function ($item) use ($validAnimCodes, $validRoles, $strip): array {
        if (!is_array($item)) {
            $item = [];
        }

        $pelaku = trim((string) ($item['pelaku'] ?? 'DPJP'));
        $pelaku = in_array($pelaku, $validRoles, true) ? $pelaku : 'DPJP';
        $instruksi = trim((string) ($item['instruksi'] ?? ''));
        $aksi = trim($strip((string) ($item['aksi'] ?? '')));
        $hasil = trim($strip((string) ($item['hasil'] ?? '')));
        $anim = trim($strip((string) ($item['animasi'] ?? '')));
        $anim = in_array($anim, $validAnimCodes, true) ? $anim : 'mechanic';

        if ($aksi === '') {
            $aksi = 'Data belum tersedia';
        }
        if ($hasil === '') {
            $hasil = 'Data belum tersedia';
        }

        return [
            'pelaku' => $pelaku,
            'instruksi' => $instruksi,
            'aksi' => $aksi,
            'hasil' => $hasil,
            'animasi' => $anim,
        ];
    }, $items);
}

/**
 * Panggil Gemini dengan system prompt + user prompt sebagai dua "parts" terpisah
 * dalam satu content role=user, mengikuti pola yang sudah dipakai
 * actions/ai_recruitment_service.php.
 */
function ems_ai_ds_strip_hallucination_instructions(string $prompt): string
{
    $patterns = [
        '/lengkapi\s+SENDIRI\s+seluruh\s+data\s+yang\s+hilang[^.]*\./iu',
        '/lengkapi\s+SENDIRI\s+seluruh\s+detail\s+yang\s+hilang[^.]*\./iu',
        '/JANGAN\s+mengembalikan\s+daftar\s+data\s+yang\s+dibutuhkan[^.]*\./iu',
        '/JANGAN\s+mengembalikan\s+pertanyaan\s+klarifikasi/iu',
        '/lengkapi\s+setiap\s+detail[^.\n]*(?:\.|$)/iu',
        '/asumsi\s+realistis[^.\n]*(?:\.|$)/iu',
        '/hasilkan\s+sendiri[^.\n]*(?:\.|$)/iu',
        '/buat\s+nilai\s+[^.\n]*konkret[^.\n]*(?:\.|$)/iu',
    ];
    return preg_replace($patterns, 'Jangan mengarang data yang tidak tersedia; tandai Data belum tersedia dan minta verifikasi bila diperlukan.', $prompt) ?? $prompt;
}

function ems_ai_ds_call_gemini(PDO $pdo, string $systemPrompt, string $userPrompt, string $featureKey, ?int $createdBy): array
{
    if (!$createdBy) {
        return ['ok' => false, 'error' => 'Sesi pengguna tidak valid. Silakan login ulang.'];
    }

    $userSettings = ems_ai_ds_get_user_settings($pdo, $createdBy);
    if ($userSettings === null || trim((string) ($userSettings['gemini_api_key'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Anda belum mengatur API key Gemini pribadi. Atur dulu di menu Roxwood Hospital AI > Setting AI Saya.'];
    }

    $settings = array_merge(ems_ai_settings_defaults(), [
        'provider' => 'gemini',
        'is_enabled' => 1,
        'gemini_api_key' => (string) $userSettings['gemini_api_key'],
        'gemini_base_url' => trim((string) $userSettings['gemini_base_url']) !== '' ? (string) $userSettings['gemini_base_url'] : 'https://generativelanguage.googleapis.com/v1beta',
        'default_model' => trim((string) $userSettings['default_model']) !== '' ? (string) $userSettings['default_model'] : 'gemini-3.5-flash-lite',
        'timeout_seconds' => 120,
        'max_output_tokens' => 8192,
        'daily_request_limit' => 0,
    ]);

    $systemPrompt = ems_ai_ds_strip_hallucination_instructions($systemPrompt);
    $userPrompt = ems_ai_ds_strip_hallucination_instructions($userPrompt);

    if (!str_contains($systemPrompt, 'DOKUMEN RESMI ROXWOOD HOSPITAL') && !str_contains($userPrompt, 'DOKUMEN RESMI ROXWOOD HOSPITAL')) {
        $officialDocuments = ems_ai_official_document_context(
            $pdo,
            ems_ai_official_document_unit($pdo, $createdBy),
            $userPrompt,
            $featureKey
        );
        if ($officialDocuments !== '') {
            $systemPrompt .= "\n\n" . $officialDocuments;
        }
    }
    if (!str_contains($systemPrompt, 'VALIDATION GATE WAJIB')) {
        $systemPrompt .= "\n\n" . ems_ai_official_consistency_guardrail();
    }

    $finalValidation = "FINAL VALIDATION GATE (mengalahkan instruksi template/user yang bertentangan):\n"
        . ems_ai_official_consistency_guardrail()
        . "\nKembalikan hanya data yang didukung input dan evidence. Jangan mengisi kekosongan dengan angka, diagnosis, tindakan, hasil operasi, atau kondisi pasien rekaan.";

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

    $parsed = json_decode($text, true);
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
