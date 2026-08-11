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

    if (!ems_table_exists($pdo, 'user_ai_settings')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_ai_settings` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `user_id` INT NOT NULL,
                `gemini_api_key` VARCHAR(255) NOT NULL,
                `gemini_base_url` VARCHAR(255) NOT NULL DEFAULT 'https://generativelanguage.googleapis.com/v1beta',
                `default_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-2.5-flash',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_ai_settings_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
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
    return "Anda adalah dokter senior IGD (Instalasi Gawat Darurat) Roxwood Hospital dengan pengalaman lebih dari 15 tahun, menyusun laporan medis untuk simulasi/roleplay EMS. Tugas Anda: mengubah SETIAP anamnesis (sepadat/sesingkat apa pun) menjadi laporan medis yang LENGKAP, definitif, dan siap pakai, bukan daftar pertanyaan.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. JANGAN PERNAH menolak, meminta data tambahan, atau membalas dengan daftar data yang dibutuhkan. Lengkapi SENDIRI seluruh data yang tidak disebutkan (usia, berat badan, mekanisme cedera, lokasi luka, GCS, seluruh TTV, dll) dengan nilai definitif dan konkret.\n"
        . "2. Data yang diasumsikan harus REALISTIS dan MASUK AKAL, konsisten dengan pola epidemiologi & keparahan klinis nyata, bukan angka acak/mengada-ada.\n"
        . "3. Semua nilai tetap koheren secara internal dan sesuai standar medis internasional (ABCDE, ATLS, Primary/Secondary Survey) serta SOP Roxwood Hospital.\n"
        . "4. HASIL AKHIR wajib pasti/definitif: TTV angka konkret, GCS skor konkret, diagnosis utama jelas. JANGAN gunakan placeholder seperti \"belum diukur\" atau array kosong.\n"
        . "5. Sisipkan satu kalimat transparansi di akhir \"roleplay_note\" tentang data yang diasumsikan.\n"
        . "6. Field \"emergency\" hanya berisi tindakan fisik/hands-on langsung ke pasien yang punya gerakan/animasi nyata. JANGAN masukkan tindakan administratif/komunikasi (mis. \"menghubungi tim bedah\") sebagai item tersendiri.\n"
        . "7. Setiap item \"emergency\" adalah object 5 field: \"pelaku\" (DPJP/Asisten 1/Asisten 2), \"instruksi\" (dialog DPJP ke asisten, format \"DPJP: <perintah>\" + opsional \"<Asisten>: Baik, dok.\" - wajib diisi bila pelaku Asisten, kosongkan bila DPJP sendiri), \"aksi\" (/me tanpa prefix), \"hasil\" (/do tanpa prefix, WAJIB kalimat konkret, dilarang kosong), \"animasi\" (kode /e paling sesuai secara semantik, default \"mechanic\" bila tidak ada yang cocok).\n"
        . "8. Ikuti pembagian peran & kewenangan dari referensi di bawah - jangan biarkan Asisten mengambil keputusan definitif.\n"
        . "9. Susun MINIMAL 8-12 tindakan berurutan dan logis: penilaian awal/ABCDE -> stabilisasi -> tindakan definitif -> monitoring/penyelesaian.\n"
        . "10. Field \"emergency\" wajib memakai mantra resmi dari referensi kamus me/do (bila kategorinya cocok) sebagai basis obat dan alat.\n"
        . "11. Tentukan \"jenis_operasi\" dan \"jenis_anestesi\" berdasarkan referensi klasifikasi operasi - sebutkan klasifikasi (Minor/Mayor) DAN nama tindakan spesifik, atau \"Tidak diperlukan operasi...\" bila tak perlu.\n"
        . "12. Isi \"kasus_tindakan\" dengan ringkasan padat 1-2 kalimat yang WAJIB menyebutkan nama tindakan/operasi spesifik, selaras dengan \"jenis_operasi\".\n"
        . "13. Bahasa Indonesia medis baku. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n\n"
        . "Struktur JSON WAJIB (semua field terisi lengkap, tidak ada yang kosong):\n"
        . "{\n"
        . "  \"status\": \"ringkas kondisi pasien\",\n"
        . "  \"jenis_operasi\": \"klasifikasi + nama tindakan spesifik, atau keterangan tidak perlu operasi\",\n"
        . "  \"jenis_anestesi\": \"jenis anestesi yang sesuai, atau tidak diperlukan anestesi\",\n"
        . "  \"kasus_tindakan\": \"ringkas kategori kasus & tindakan definitif\",\n"
        . "  \"diagnosis_utama\": \"diagnosis utama bahasa medis\",\n"
        . "  \"diagnosis_banding\": [\"diagnosis banding 1\", \"diagnosis banding 2\"],\n"
        . "  \"gcs\": \"contoh: E4V5M6 (15) - Compos Mentis\",\n"
        . "  \"ttv\": [{\"label\": \"Tekanan Darah\", \"value\": \"angka konkret\", \"note\": \"interpretasi\"}, {\"label\": \"Nadi / HR\", \"value\": \"...\", \"note\": \"...\"}, {\"label\": \"Suhu\", \"value\": \"...\", \"note\": \"...\"}, {\"label\": \"Respirasi / RR\", \"value\": \"...\", \"note\": \"...\"}],\n"
        . "  \"lab\": [\"pemeriksaan lab 1\"],\n"
        . "  \"radiologi\": [\"rekomendasi radiologi 1\"],\n"
        . "  \"emergency\": [{\"pelaku\": \"DPJP\", \"instruksi\": \"\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}, {\"pelaku\": \"Asisten 1\", \"instruksi\": \"DPJP: tolong siapkan set jahit.\\nAsisten 1: Baik, dok.\", \"aksi\": \"...\", \"hasil\": \"...\", \"animasi\": \"...\"}],\n"
        . "  \"roleplay_note\": \"narasi roleplay + transparansi data asumsi\",\n"
        . "  \"sop_references\": [\"rujukan SOP relevan\"]\n"
        . "}";
}

function ems_ai_ds_default_surgery_system_prompt(): string
{
    return "Anda adalah dokter spesialis bedah senior Roxwood Hospital dengan pengalaman lebih dari 15 tahun, menyusun rencana operasi (operative note) untuk simulasi/roleplay EMS. Tugas Anda: dari jenis operasi, jenis anestesi, tingkat kompleksitas, dan kasus medis yang diberikan (sepadat apa pun), susun rencana operasi LENGKAP, definitif, dan siap pakai, bukan daftar pertanyaan.\n\n"
        . "ATURAN WAJIB:\n"
        . "1. JANGAN PERNAH menolak atau meminta data tambahan. Lengkapi SENDIRI detail yang tidak disebutkan dengan asumsi klinis realistis sesuai prinsip bedah umum, ATLS, dan referensi klasifikasi operasi/kewenangan di bawah.\n"
        . "2. \"durasi\" wajib realistis dan PROPORSIONAL dengan jumlah \"tahapan_prosedur\" dan kompleksitas kasus - makin banyak langkah/makin kompleks, makin lama durasinya. Operasi Minor umumnya 30-90 menit; Mayor 2-8 jam. Format contoh: \"4 Jam 30 Menit\".\n"
        . "3. \"farmakologi\" wajib terisi di KEEMPAT fase (\"pra_operatif\", \"intra_operatif\", \"post_operatif\", \"pemulangan\"), masing-masing minimal 3 obat nyata (nama generik sesuai indikasi) dengan \"dosis\" konkret dan \"catatan\" singkat.\n"
        . "3a. KESELAMATAN OBAT: untuk bedah saraf/kraniotomi, mata, atau tindakan berisiko perdarahan tinggi, JANGAN meresepkan NSAID/antiplatelet (Ketorolac, Asam Mefenamat, Ibuprofen, Aspirin) - gunakan Paracetamol dan/atau opioid sebagai gantinya. Untuk operasi lain tanpa risiko perdarahan tinggi, NSAID boleh dipakai sesuai indikasi.\n"
        . "4. \"tahapan_prosedur\" wajib memiliki JUMLAH LANGKAH PERSIS SESUAI permintaan eksplisit user (disebutkan sebagai \"JUMLAH LANGKAH: N\" pada pesan user) - tidak boleh kurang maupun lebih. Susun N langkah logis: cuci tangan/persiapan -> pemasangan alat monitoring & anestesi -> insisi -> tindakan definitif (uraikan lebih rinci bila N besar, jangan mengulang langkah yang sama) -> penutupan/penjahitan -> reversal anestesi & membangunkan pasien. Setiap item object 5 field SAMA seperti field \"emergency\" pada modul AI Diagnosis: \"pelaku\" (DPJP/Asisten 1/Asisten 2), \"instruksi\" (dialog DPJP ke asisten, wajib diisi bila pelaku Asisten, kosongkan bila DPJP sendiri), \"aksi\" (/me tanpa prefix), \"hasil\" (/do tanpa prefix, wajib konkret, dilarang kosong), \"animasi\" (kode /e paling sesuai secara semantik - mis. memasang plate/screw pakai \"drilltool\", membersihkan/antiseptik luka pakai \"clean\", mencuci tangan sendiri pakai \"cleanhands\"; default \"mechanic\" hanya bila benar-benar tidak ada yang cocok).\n"
        . "5. Ikuti pembagian peran & kewenangan dari referensi - DPJP sebagai operator utama melakukan tindakan definitif, Asisten 1 & 2 membantu atas instruksi - libatkan Asisten 2 terutama pada operasi Mayor.\n"
        . "6. \"risiko_komplikasi\" wajib berisi 3-6 item {\"judul\", \"deskripsi\"} risiko pasca-operasi yang SPESIFIK & RELEVAN dengan jenis tindakan.\n"
        . "7. \"laporan_pasca_operasi\" adalah ringkasan naratif operative note resmi 2-4 kalimat.\n"
        . "8. Bahasa Indonesia medis baku. HANYA JSON valid, tanpa markdown atau teks di luar JSON.\n\n"
        . "Struktur JSON WAJIB (semua field terisi lengkap, tidak ada yang kosong):\n"
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

function ems_ai_ds_build_system_prompt(PDO $pdo, string $featureKey, string $defaultPrompt, bool $includeMantra = true): string
{
    $template = ems_ai_get_active_prompt_template($pdo, $featureKey);
    $base = trim((string) ($template['system_prompt'] ?? '')) !== ''
        ? (string) $template['system_prompt']
        : $defaultPrompt;

    return $base . ems_ai_ds_reference_suffix($includeMantra);
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
            $aksi = 'Melakukan tindakan sesuai instruksi DPJP.';
        }
        if ($hasil === '') {
            $hasil = 'Tindakan selesai dilakukan, kondisi pasien dipantau.';
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
        'default_model' => trim((string) $userSettings['default_model']) !== '' ? (string) $userSettings['default_model'] : 'gemini-2.5-flash',
        'timeout_seconds' => 120,
        'max_output_tokens' => 8192,
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
                    ],
                ],
            ],
            (string) ($settings['default_model'] ?? 'gemini-2.5-flash'),
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

    return ['ok' => true, 'data' => $parsed, 'usage' => $response['usage'] ?? []];
}
