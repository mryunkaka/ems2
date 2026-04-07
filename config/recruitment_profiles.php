<?php

function ems_normalize_recruitment_type(?string $type): string
{
    $raw = strtolower(trim((string) $type));

    return match ($raw) {
        'assistant_manager', 'assistant-manager', 'assistant manager', 'assisten_manager', 'assisten manager' => 'assistant_manager',
        default => 'medical_candidate',
    };
}

function ems_recruitment_type_label(?string $type): string
{
    return match (ems_normalize_recruitment_type($type)) {
        'assistant_manager' => 'Calon Asisten Manager',
        default => 'Calon Kandidat',
    };
}

function ems_medical_candidate_questions(): array
{
    return [
        1  => 'Apakah Anda pernah menyesuaikan jawaban agar terlihat lebih baik?',
        2  => 'Apakah Anda merasa sulit fokus jika duty terlalu lama?',
        3  => 'Apakah Anda lebih memilih mengikuti SOP meski situasi menekan?',
        4  => 'Apakah Anda merasa tidak semua orang perlu tahu isi pikiran Anda?',
        5  => 'Apakah Anda pernah menangani kondisi darurat di mana keputusan harus diambil tanpa alat medis lengkap?',
        6  => 'Apakah Anda merasa stabilitas lingkungan kerja memengaruhi performa Anda?',
        7  => 'Apakah Anda sering berubah jam online karena faktor lain di luar pekerjaan ini?',
        8  => 'Apakah Anda percaya adab dan etika kerja sama pentingnya dengan skill?',
        9  => 'Apakah Anda lebih nyaman bekerja tanpa banyak berbicara?',
        10 => 'Apakah Anda pernah meninggalkan tugas karena kewajiban di tempat lain?',
        11 => 'Apakah dalam situasi kritis, keselamatan nyawa lebih utama dibanding prosedur administratif?',
        12 => 'Apakah Anda merasa cepat kehilangan semangat jika hasil tidak langsung terlihat?',
        13 => 'Apakah Anda jarang menunjukkan stres meskipun sedang tertekan?',
        14 => 'Apakah Anda merasa wajar untuk sering berpindah instansi dalam waktu singkat?',
        15 => 'Apakah Anda merasa aturan kerja bisa diabaikan dalam kondisi tertentu?',
        16 => 'Apakah Anda lebih memilih diam saat emosi meningkat?',
        17 => 'Apakah Anda terbiasa menyelesaikan tugas meski waktu duty sudah panjang?',
        18 => 'Apakah Anda merasa jawaban jujur tidak selalu aman?',
        19 => 'Apakah Anda yakin dapat memisahkan tanggung jawab antar instansi secara profesional?',
        20 => 'Apakah Anda pernah menyesal karena melanggar prinsip kerja sendiri?',
        21 => 'Apakah Anda memahami bahwa tidak semua kondisi medis memungkinkan pemeriksaan lengkap sebelum tindakan?',
        22 => 'Apakah Anda lebih memilih mengamati sebelum terlibat aktif?',
        23 => 'Apakah Anda merasa makna pekerjaan lebih penting daripada posisi?',
        24 => 'Apakah Anda cenderung menyimpan emosi daripada mengungkapkannya?',
        25 => 'Apakah Anda jarang meninggalkan tugas saat sudah mulai bertugas?',
        26 => 'Apakah Anda percaya kesan pertama sangat menentukan?',
        27 => 'Apakah Anda merasa sulit membagi fokus jika memiliki tanggung jawab di lebih dari satu instansi?',
        28 => 'Apakah Anda merasa prinsip kerja dapat berubah tergantung situasi?',
        29 => 'Apakah Anda membutuhkan waktu untuk beradaptasi dengan tekanan baru?',
        30 => 'Apakah Anda merasa tidak nyaman jika jadwal kerja terlalu berubah-ubah?',
        31 => 'Apakah pada kondisi pasien sekarat dengan dugaan patah tulang, tindakan stabilisasi lebih diprioritaskan daripada pemeriksaan lanjutan seperti MRI?',
        32 => 'Apakah Anda jarang memulai percakapan lebih dulu dalam tim?',
        33 => 'Apakah Anda merasa jadwal tetap justru membatasi fleksibilitas Anda?',
        34 => 'Apakah Anda pernah bergabung ke instansi hanya karena ajakan lingkungan?',
        35 => 'Apakah Anda merasa stamina kerja memengaruhi kualitas pelayanan?',
        36 => 'Apakah Anda cenderung bertahan lebih lama jika sudah merasa cocok di satu tempat?',
        37 => 'Apakah Anda memiliki kecenderungan memprioritaskan peran lain jika terjadi bentrok jadwal?',
        38 => 'Apakah Anda sering menilai diri sendiri secara diam-diam?',
        39 => 'Apakah Anda merasa sulit berkomitmen jika baru berada di suatu kota dalam waktu singkat?',
        40 => 'Apakah Anda merasa makna pekerjaan lebih penting daripada posisi?',
        41 => 'Apakah Anda lebih nyaman bekerja tanpa banyak arahan?',
        42 => 'Apakah Anda cenderung menghindari konflik langsung?',
        43 => 'Apakah Anda merasa sulit menerima kritik?',
        44 => 'Apakah Anda lebih memilih bekerja sendiri?',
        45 => 'Apakah Anda mudah panik dalam situasi darurat?',
        46 => 'Apakah Anda merasa kelelahan memengaruhi pengambilan keputusan?',
        47 => 'Apakah Anda pernah merasa tidak dihargai dalam tim?',
        48 => 'Apakah Anda cenderung menunda pekerjaan jika tidak diawasi?',
        49 => 'Apakah Anda sering overthinking setelah mengambil keputusan?',
        50 => 'Apakah Anda siap mengikuti arahan senior saat training?',
    ];
}

function ems_assistant_manager_question_bank(): array
{
    static $bank = null;
    if ($bank !== null) {
        return $bank;
    }

    $criteriaDefinitions = [
        'obedience' => [
            'label' => 'Kepatuhan SOP',
            'direction' => 'normal',
            'weight' => 1.0,
            'statements' => [
                'Saya tetap menjalankan SOP EMS meskipun situasi lapangan sedang menekan.',
                'Saya merasa prosedur kerja wajib dipatuhi walau tidak ada atasan yang mengawasi.',
                'Saya lebih memilih tindakan yang sesuai aturan meskipun hasilnya tidak instan.',
                'Saya terbiasa memastikan tim memahami SOP sebelum mulai bertugas.',
                'Saya melihat kepatuhan SOP sebagai dasar profesionalisme anggota EMS.',
                'Saya merasa aturan medis tidak boleh ditawar hanya demi kenyamanan.',
                'Saya akan menghentikan tindakan tim bila prosedurnya jelas menyimpang.',
                'Saya menilai disiplin prosedur lebih penting daripada kesan cepat selesai.',
                'Saya tetap memegang prosedur resmi walau pihak lain mendorong jalan pintas.',
                'Saya menegur pelanggaran SOP walau pelakunya orang yang sudah dekat dengan saya.',
            ],
            'variants' => [
                'Saat respon emergency berlangsung.',
                'Saat duty sedang ramai dan tekanan meningkat.',
                'Walau keputusan itu membuat pekerjaan terasa lebih berat.',
                'Meski tidak semua anggota tim setuju.',
                'Meski kondisi di lapangan tidak ideal.',
            ],
        ],
        'consistency' => [
            'label' => 'Konsistensi & Komitmen',
            'direction' => 'normal',
            'weight' => 1.0,
            'statements' => [
                'Saya dapat menjaga standar kerja yang sama di shift sibuk maupun sepi.',
                'Saya terbiasa menyelesaikan tindak lanjut sampai benar-benar tuntas.',
                'Saya memegang komitmen duty walau kondisi pribadi sedang tidak nyaman.',
                'Saya tetap menjaga ritme kerja meski hasilnya belum langsung terlihat.',
                'Saya menilai konsistensi tindakan lebih penting daripada janji lisan.',
                'Saya tidak mudah berpindah fokus hanya karena ada distraksi kecil.',
                'Saya tetap bisa menjaga etika kerja dari awal sampai akhir duty.',
                'Saya berusaha hadir stabil sesuai tanggung jawab yang sudah disepakati.',
                'Saya tidak mudah menurunkan standar hanya karena lingkungan mulai longgar.',
                'Saya dapat mempertahankan disiplin kerja tanpa harus terus diingatkan.',
            ],
            'variants' => [
                'Dalam periode kerja beberapa hari berturut-turut.',
                'Saat tidak ada apresiasi langsung dari atasan.',
                'Walau tim sedang kekurangan personel.',
                'Saat jadwal berubah dalam waktu singkat.',
                'Ketika ada tugas tambahan di luar rutinitas.',
            ],
        ],
        'focus' => [
            'label' => 'Fokus & Ketelitian',
            'direction' => 'normal',
            'weight' => 0.9,
            'statements' => [
                'Saya terbiasa memeriksa ulang detail kecil sebelum pekerjaan dianggap selesai.',
                'Saya tetap fokus walau harus menangani beberapa prioritas sekaligus.',
                'Saya mengecek ulang laporan sebelum meneruskannya ke atasan.',
                'Saya tidak nyaman meninggalkan detail penting tanpa verifikasi.',
                'Saya biasa menyusun prioritas kerja sebelum mulai bertugas.',
                'Saya memeriksa kelengkapan perlengkapan sebelum tim bergerak ke lapangan.',
                'Saya lebih suka memastikan data akurat daripada bergerak tergesa-gesa.',
                'Saya menilai kesalahan kecil dapat berkembang menjadi masalah besar.',
                'Saya mampu menjaga fokus walau banyak komunikasi masuk bersamaan.',
                'Saya terbiasa memastikan dokumen atau catatan kerja tidak ada yang terlewat.',
            ],
            'variants' => [
                'Saat harus menyiapkan koordinasi lintas jabatan.',
                'Ketika laporan perlu dikirim pada hari yang sama.',
                'Saat tugas administratif dan lapangan berjalan bersamaan.',
                'Meski ada tekanan waktu dari situasi sekitar.',
                'Walau orang lain merasa detail itu tidak terlalu penting.',
            ],
        ],
        'social' => [
            'label' => 'Koordinasi & Komunikasi',
            'direction' => 'normal',
            'weight' => 0.9,
            'statements' => [
                'Saya mampu menjelaskan instruksi kerja dengan bahasa yang jelas kepada tim.',
                'Saya tetap menjaga nada bicara profesional saat berdiskusi dengan pihak lain.',
                'Saya terbiasa menjadi penghubung antara kebutuhan pimpinan dan tim lapangan.',
                'Saya bisa mendengar komplain tanpa langsung terpancing emosi.',
                'Saya berusaha memastikan pesan yang saya sampaikan dipahami dengan benar.',
                'Saya nyaman melakukan koordinasi cepat dengan instansi lain bila diperlukan.',
                'Saya menganggap komunikasi yang rapi sebagai bagian dari pelayanan EMS.',
                'Saya tidak keberatan memberi penjelasan ulang jika tim masih belum paham.',
                'Saya dapat menyampaikan koreksi tanpa menjatuhkan harga diri anggota tim.',
                'Saya menilai briefing singkat penting untuk mencegah miskomunikasi saat duty.',
            ],
            'variants' => [
                'Dalam situasi penuh tekanan.',
                'Saat ada perbedaan pendapat di lapangan.',
                'Ketika harus memberi arahan kepada anggota yang baru bergabung.',
                'Saat ada kritik dari warga atau pasien.',
                'Dalam koordinasi sebelum maupun sesudah respon panggilan.',
            ],
        ],
        'emotional_stability' => [
            'label' => 'Kontrol Emosi',
            'direction' => 'normal',
            'weight' => 0.9,
            'statements' => [
                'Saya tetap bisa berpikir jernih saat situasi kerja memanas.',
                'Saya tidak mudah panik ketika keputusan harus dibuat cepat.',
                'Saya mampu memisahkan emosi pribadi dari keputusan kerja.',
                'Saya tetap dapat bersikap tenang saat menerima tekanan dari banyak arah.',
                'Saya tidak mudah terbawa emosi ketika mendapat penolakan atau komplain.',
                'Saya masih bisa bersikap profesional meski sedang lelah.',
                'Saya cenderung menyelesaikan masalah lebih dulu sebelum bereaksi secara emosional.',
                'Saya tetap dapat menjaga sikap saat menghadapi anggota tim yang sulit diarahkan.',
                'Saya tidak mudah kehilangan kontrol ketika rencana berubah mendadak.',
                'Saya terbiasa menjaga ekspresi dan nada bicara saat kondisi tidak nyaman.',
            ],
            'variants' => [
                'Ketika harus mengambil keputusan di depan tim.',
                'Saat mendapat kritik mendadak dari atasan.',
                'Ketika masalah terjadi berulang dalam hari yang sama.',
                'Saat lokasi respon tidak kondusif.',
                'Walau ada tekanan waktu untuk segera bergerak.',
            ],
        ],
        'honesty_humility' => [
            'label' => 'Integritas & Kejujuran',
            'direction' => 'normal',
            'weight' => 1.0,
            'statements' => [
                'Saya lebih memilih laporan yang jujur walau hasilnya terlihat kurang baik.',
                'Saya mengakui kesalahan kerja saya tanpa menunggu orang lain menemukannya.',
                'Saya tidak nyaman mengambil keuntungan pribadi dari fasilitas EMS.',
                'Saya menjaga informasi internal sesuai kebutuhan tugas, bukan demi kepentingan pribadi.',
                'Saya menilai kepercayaan tim dibangun dari keterbukaan dan akuntabilitas.',
                'Saya tidak akan memanipulasi data agar terlihat lebih rapi dari kenyataan.',
                'Saya merasa integritas lebih penting daripada pencitraan jabatan.',
                'Saya tetap melaporkan kondisi sebenarnya walau berisiko mendapat evaluasi.',
                'Saya tidak menggunakan wewenang EMS untuk membantu kepentingan pihak tertentu.',
                'Saya memandang kejujuran dalam administrasi sama pentingnya dengan kerja lapangan.',
            ],
            'variants' => [
                'Dalam urusan dokumen maupun laporan.',
                'Saat atasan meminta update cepat.',
                'Meski ada tekanan agar hasil terlihat bagus.',
                'Saat tidak semua pihak mengetahui kondisi yang sebenarnya.',
                'Dalam situasi yang menyangkut nama baik tim.',
            ],
        ],
        'trap' => [
            'label' => 'Pertanyaan Jebakan',
            'direction' => 'normal',
            'weight' => 0.7,
            'trap' => true,
            'risky_answer' => 'ya',
            'statements' => [
                'Saya merasa menggunakan kendaraan dinas untuk urusan pribadi masih wajar selama sebentar.',
                'Saya menilai respon di area yang belum dinyatakan aman tetap bisa dilakukan jika tim sedang terburu-buru.',
                'Saya menganggap ketidakhadiran 1 bulan tanpa kabar masih dapat dimaklumi tanpa proses administrasi.',
                'Saya merasa alat medis rumah sakit dapat dipinjamkan ke pihak luar selama nanti dikembalikan.',
                'Saya menganggap afiliasi dengan pihak kriminal tidak masalah selama tidak terlihat publik.',
                'Saya merasa laporan administrasi bisa disesuaikan agar atasan lebih mudah menerima.',
                'Saya menilai pelanggaran kecil terhadap SOP tidak perlu dibahas selama hasil akhirnya baik.',
                'Saya menganggap anggota baru boleh off duty tanpa izin atasan bila alasannya mendesak.',
                'Saya merasa panggilan di zona terlarang dapat langsung direspon tanpa koordinasi polisi jika tim yakin mampu.',
                'Saya menilai bantuan medis boleh ditunda jika pasien terlihat menyulitkan petugas.',
            ],
            'variants' => [
                'Menurut penilaian pribadi saya.',
                'Jika situasi dianggap sangat mendesak.',
                'Selama tidak ada pihak lain yang mengetahui.',
                'Bila dilakukan satu kali saja.',
                'Jika itu dianggap membantu pekerjaan.',
            ],
        ],
    ];

    $bank = [];
    $id = 1001;
    foreach ($criteriaDefinitions as $trait => $definition) {
        foreach ($definition['statements'] as $statement) {
            foreach ($definition['variants'] as $variant) {
                $bank[$id] = [
                    'id' => $id,
                    'text' => $statement . ' ' . $variant,
                    'trait' => $trait,
                    'criteria' => $definition['label'],
                    'direction' => $definition['direction'],
                    'weight' => $definition['weight'],
                    'trap' => !empty($definition['trap']),
                    'risky_answer' => $definition['risky_answer'] ?? null,
                ];
                $id++;
            }
        }
    }

    return $bank;
}

function ems_assistant_manager_selected_question_ids(int $applicantId, int $count = 70): array
{
    $bank = ems_assistant_manager_question_bank();
    $scored = [];

    foreach (array_keys($bank) as $questionId) {
        $score = sha1($applicantId . '|' . $questionId . '|assistant-manager-bank');
        $scored[] = [
            'id' => $questionId,
            'score' => $score,
        ];
    }

    usort($scored, static function (array $a, array $b): int {
        return strcmp($a['score'], $b['score']);
    });

    $selected = array_slice(array_column($scored, 'id'), 0, $count);
    sort($selected);

    return $selected;
}

function ems_assistant_manager_selected_questions(int $applicantId, int $count = 70): array
{
    $bank = ems_assistant_manager_question_bank();
    $selected = [];

    foreach (ems_assistant_manager_selected_question_ids($applicantId, $count) as $questionId) {
        if (isset($bank[$questionId])) {
            $selected[$questionId] = $bank[$questionId];
        }
    }

    return $selected;
}

function ems_assistant_manager_trait_items(array $questionIds): array
{
    $bank = ems_assistant_manager_question_bank();
    $traitItems = [
        'focus' => [],
        'social' => [],
        'obedience' => [],
        'consistency' => [],
        'emotional_stability' => [],
        'honesty_humility' => [],
    ];

    foreach ($questionIds as $questionId) {
        $questionId = (int) $questionId;
        $meta = $bank[$questionId] ?? null;
        if (!$meta) {
            continue;
        }

        if ($meta['trait'] === 'trap') {
            continue;
        }

        if (!isset($traitItems[$meta['trait']])) {
            continue;
        }

        $traitItems[$meta['trait']][$questionId] = [
            'direction' => $meta['direction'],
            'weight' => $meta['weight'],
        ];
    }

    return $traitItems;
}

function ems_assistant_manager_trap_flags(array $answers): array
{
    $bank = ems_assistant_manager_question_bank();
    $flags = [];
    $trapHits = 0;

    foreach ($answers as $questionId => $answer) {
        $meta = $bank[(int) $questionId] ?? null;
        if (!$meta || empty($meta['trap'])) {
            continue;
        }

        if (($meta['risky_answer'] ?? '') === (string) $answer) {
            $trapHits++;
        }
    }

    if ($trapHits >= 2) {
        $flags[] = 'trap_answering';
    }
    if ($trapHits >= 4) {
        $flags[] = 'high_risk_trap_answering';
    }

    return $flags;
}

function ems_recruitment_questions_for_applicant(string $type, int $applicantId): array
{
    $type = ems_normalize_recruitment_type($type);

    if ($type === 'assistant_manager') {
        $questions = [];
        foreach (ems_assistant_manager_selected_questions($applicantId) as $questionId => $meta) {
            $questions[$questionId] = $meta['text'];
        }
        return $questions;
    }

    return ems_medical_candidate_questions();
}

function ems_recruitment_question_pool_count(string $type): int
{
    return ems_normalize_recruitment_type($type) === 'assistant_manager'
        ? count(ems_assistant_manager_question_bank())
        : count(ems_medical_candidate_questions());
}

function ems_recruitment_profile(string $type): array
{
    $type = ems_normalize_recruitment_type($type);

    if ($type === 'assistant_manager') {
        return [
            'type' => 'assistant_manager',
            'title' => 'Pendaftaran Calon Asisten Manager',
            'subtitle' => 'General Affair Recruitment Track',
            'description' => 'Lengkapi data awal untuk seleksi calon asisten manager. Form tahap awal menekankan identitas, rekam jejak kerja, dan kepatuhan pada SOP EMS.',
            'badge' => 'Assistant Manager Recruitment',
            'test_title' => 'Assessment Calon Asisten Manager',
            'test_subtitle' => 'Sistem menilai kepatuhan SOP, integritas, dan pola kerja kandidat.',
            'done_title' => 'Proses Seleksi Asisten Manager',
            'question_count' => 70,
            'question_pool_count' => 500,
            'questions' => [],
            'stage_copy' => [
                'Tahap 1' => 'Isi identitas, pengalaman kerja, riwayat kepatuhan SOP, dan kesiapan memimpin operasional berdasarkan standar EMS.',
                'Tahap 2' => 'Verifikasi identitas dan kelengkapan dokumen akun EMS melalui Citizen ID.',
                'Tahap 3' => 'Kerjakan assessment untuk membaca pola kerja dan kepatuhan kandidat.',
            ],
        ];
    }

    return [
        'type' => 'medical_candidate',
        'title' => 'Pendaftaran Calon Medis',
        'subtitle' => 'Medical Recruitment Track',
        'description' => 'Lengkapi data dengan jujur dan jelas. Setelah formulir ini dikirim, Anda akan diarahkan ke tahap pertanyaan lanjutan oleh sistem rekrutmen.',
        'badge' => 'Public Recruitment Form',
        'test_title' => 'Assessment Kandidat',
        'test_subtitle' => 'Isi semua pertanyaan berikut sebelum mengirim hasil akhir.',
        'done_title' => 'Proses Seleksi Kandidat',
        'question_count' => 50,
        'question_pool_count' => 50,
        'questions' => ems_medical_candidate_questions(),
        'stage_copy' => [
            'Tahap 1' => 'Isi identitas, pengalaman, dan komitmen duty.',
            'Tahap 2' => 'Unggah dokumen pendukung untuk verifikasi awal.',
            'Tahap 3' => 'Lanjut ke form pertanyaan setelah data tersimpan.',
        ],
    ];
}
