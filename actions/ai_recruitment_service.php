<?php

require_once __DIR__ . '/../config/ai_settings.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';

function ems_ai_ensure_interview_question_packs_table(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS applicant_interview_question_packs (
            id INT NOT NULL AUTO_INCREMENT,
            applicant_id INT NOT NULL,
            hr_id INT NOT NULL,
            recruitment_type VARCHAR(50) NOT NULL DEFAULT 'medical_candidate',
            provider VARCHAR(50) NOT NULL DEFAULT 'system',
            model_name VARCHAR(100) DEFAULT NULL,
            question_pack_json LONGTEXT NOT NULL,
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_applicant_hr_question_pack (applicant_id, hr_id),
            KEY idx_interview_question_packs_applicant (applicant_id),
            KEY idx_interview_question_packs_hr (hr_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ensured = true;
}

function ems_ai_ensure_interview_question_responses_table(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS applicant_interview_question_responses (
            id INT NOT NULL AUTO_INCREMENT,
            applicant_id INT NOT NULL,
            hr_id INT NOT NULL,
            question_key VARCHAR(100) NOT NULL,
            question_category VARCHAR(50) NOT NULL,
            criterion_code VARCHAR(100) DEFAULT NULL,
            question_text TEXT NOT NULL,
            good_answer_guide TEXT NULL,
            bad_answer_guide TEXT NULL,
            score TINYINT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_interview_question_response (applicant_id, hr_id, question_key),
            KEY idx_iqr_applicant (applicant_id),
            KEY idx_iqr_hr (hr_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ensured = true;
}

function ems_ai_recruitment_feature_key(string $recruitmentType): string
{
    return $recruitmentType === 'assistant_manager'
        ? 'candidate_summary_assistant_manager'
        : 'candidate_summary_medical_candidate';
}

function ems_ai_candidate_summary_cache_key(string $recruitmentType, int $applicantId): string
{
    return ems_ai_recruitment_feature_key($recruitmentType) . '_applicant_' . $applicantId;
}

function ems_ai_candidate_summary_payload(array $candidate, array $result, array $chartScoreMap, array $questionEntries, string $durationText, int $yesCount, int $noCount): array
{
    $riskFlags = json_decode((string)($result['risk_flags'] ?? ''), true);
    $riskFlags = is_array($riskFlags) ? $riskFlags : ['bias' => [], 'cross' => []];

    $traitScores = [];
    foreach ($chartScoreMap as $trait => $meta) {
        $traitScores[$trait] = [
            'score' => round((float)($meta['score'] ?? 0), 2),
            'items_used' => (int)($meta['items_used'] ?? 0),
            'reliability' => (string)($meta['reliability'] ?? ''),
        ];
    }

    return [
        'candidate' => [
            'id' => (int)($candidate['id'] ?? 0),
            'name' => (string)($candidate['ic_name'] ?? ''),
            'recruitment_type' => (string)($candidate['recruitment_type'] ?? ''),
            'gender' => (string)($candidate['jenis_kelamin'] ?? ''),
            'ooc_age' => (string)($candidate['ooc_age'] ?? ''),
            'city_duration' => (string)($candidate['city_duration'] ?? ''),
            'online_schedule' => (string)($candidate['online_schedule'] ?? ''),
            'medical_experience' => (string)($candidate['medical_experience'] ?? ''),
            'other_city_responsibility' => (string)($candidate['other_city_responsibility'] ?? ''),
            'motivation' => (string)($candidate['motivation'] ?? ''),
            'work_principle' => (string)($candidate['work_principle'] ?? ''),
            'academy_ready' => (string)($candidate['academy_ready'] ?? ''),
            'rule_commitment' => (string)($candidate['rule_commitment'] ?? ''),
            'duty_duration' => (string)($candidate['duty_duration'] ?? ''),
        ],
        'assessment' => [
            'score_total' => (int)($result['score_total'] ?? 0),
            'decision' => (string)($result['decision'] ?? ''),
            'duration_seconds' => (int)($result['duration_seconds'] ?? 0),
            'duration_text' => $durationText,
            'yes_count' => $yesCount,
            'no_count' => $noCount,
            'trait_scores' => $traitScores,
            'risk_flags' => [
                'bias' => array_values((array)($riskFlags['bias'] ?? [])),
                'cross' => array_values((array)($riskFlags['cross'] ?? [])),
            ],
        ],
        'answers' => array_map(static function (array $entry): array {
            return [
                'question' => (string)($entry['question'] ?? ''),
                'answer' => (string)($entry['answer'] ?? ''),
            ];
        }, $questionEntries),
    ];
}

function ems_ai_has_successful_summary_log(PDO $pdo, string $featureKey): bool
{
    if (!ems_ai_request_logs_table_exists($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM system_ai_request_logs
        WHERE feature_key = ?
          AND success_flag = 1
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$featureKey]);

    return (bool)$stmt->fetchColumn();
}

function ems_ai_render_summary_text(array $decoded): string
{
    $lines = [];

    $summary = trim((string)($decoded['summary'] ?? ''));
    $summaryShort = trim((string)($decoded['summary_short'] ?? ''));
    $summaryFull = trim((string)($decoded['summary_full'] ?? ''));
    $strengths = array_values(array_filter((array)($decoded['strengths'] ?? []), 'is_string'));
    $risks = array_values(array_filter((array)($decoded['risks'] ?? []), 'is_string'));
    $followUp = array_values(array_filter((array)($decoded['follow_up_points'] ?? []), 'is_string'));

    if ($summary !== '') {
        $lines[] = $summary;
    }

    if ($summaryShort !== '') {
        $lines[] = $summaryShort;
    }

    if ($summaryFull !== '') {
        $lines[] = $summaryFull;
    }

    if ($strengths) {
        $lines[] = 'Kekuatan utama: ' . implode(', ', $strengths) . '.';
    }

    if ($risks) {
        $lines[] = 'Area risiko / verifikasi: ' . implode(', ', $risks) . '.';
    }

    if ($followUp) {
        $lines[] = 'Poin pendalaman interview: ' . implode(', ', $followUp) . '.';
    }

    return trim(implode("\n\n", array_filter($lines, static fn($line) => trim((string)$line) !== '')));
}

function ems_ai_extract_json_like_field(string $rawText, string $field): string
{
    $pattern = '/"' . preg_quote($field, '/') . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/u';
    if (!preg_match($pattern, $rawText, $matches)) {
        return '';
    }

    $value = stripcslashes((string)($matches[1] ?? ''));
    return trim($value);
}

function ems_ai_clean_interview_question_text(string $text): string
{
    $text = preg_replace('/\s*\(assessment\s+id[^)]*\)/iu', '', $text);
    $text = preg_replace('/\s{2,}/u', ' ', (string)$text);
    return trim((string)$text);
}

function ems_ai_render_summary_from_raw_text(string $rawText): string
{
    $summary = ems_ai_extract_json_like_field($rawText, 'summary');
    $summaryShort = ems_ai_extract_json_like_field($rawText, 'summary_short');
    $summaryFull = ems_ai_extract_json_like_field($rawText, 'summary_full');

    $lines = array_values(array_filter([$summary, $summaryShort, $summaryFull], static fn($line) => trim((string)$line) !== ''));
    if ($lines !== []) {
        return implode("\n\n", $lines);
    }

    $cleaned = preg_replace('/^\s*\{\s*/u', '', $rawText);
    $cleaned = preg_replace('/\s*\}\s*$/u', '', (string)$cleaned);
    $cleaned = preg_replace('/"\s*summary_(short|full)"\s*:\s*/u', '', (string)$cleaned);
    $cleaned = preg_replace('/"\s*summary"\s*:\s*/u', '', (string)$cleaned);
    $cleaned = preg_replace('/,\s*$/u', '', (string)$cleaned);

    return trim((string)$cleaned);
}

function ems_ai_generate_candidate_summary(PDO $pdo, array $settings, array $candidate, array $result, array $chartScoreMap, array $questionEntries, string $durationText, int $yesCount, int $noCount, ?int $createdBy = null): ?string
{
    if (empty($settings['is_enabled']) || trim((string)($settings['gemini_api_key'] ?? '')) === '') {
        return null;
    }

    $recruitmentType = ems_normalize_recruitment_type($candidate['recruitment_type'] ?? 'medical_candidate');
    $template = ems_ai_get_active_prompt_template($pdo, ems_ai_recruitment_feature_key($recruitmentType));
    if (!$template) {
        return null;
    }

    $payload = ems_ai_candidate_summary_payload($candidate, $result, $chartScoreMap, $questionEntries, $durationText, $yesCount, $noCount);
    $featureKey = ems_ai_candidate_summary_cache_key($recruitmentType, (int)($candidate['id'] ?? 0));
    $userPromptTemplate = (string)($template['user_prompt_template'] ?? '');
    $userPrompt = str_replace('{{candidate_payload}}', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $userPromptTemplate);

    $response = ems_gemini_generate_content(
        $pdo,
        $settings,
        [
            [
                'role' => 'user',
                'parts' => [
                    [
                        'text' => trim((string)($template['system_prompt'] ?? '')),
                    ],
                    [
                        'text' => $userPrompt,
                    ],
                ],
            ],
        ],
        (string)($settings['summary_model'] ?? $settings['default_model'] ?? 'gemini-2.5-flash'),
        $featureKey,
        $createdBy
    );

    $rawText = trim((string)($response['text'] ?? ''));
    if ($rawText === '') {
        return null;
    }

    $decoded = json_decode($rawText, true);
    if (!is_array($decoded)) {
        return ems_ai_render_summary_from_raw_text($rawText);
    }

    return ems_ai_render_summary_text($decoded);
}

function ems_ai_interview_question_seed(array $candidate, int $applicantId, int $hrId): int
{
    return abs(crc32(
        $applicantId . '|' .
        $hrId . '|' .
        strtolower((string)($candidate['ic_name'] ?? '')) . '|' .
        strtolower((string)($candidate['recruitment_type'] ?? 'medical_candidate'))
    ));
}

function ems_ai_select_seeded_items(array $items, int $limit, int $seed): array
{
    $scored = [];
    foreach ($items as $index => $item) {
        $score = sha1($seed . '|' . $index . '|' . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $scored[] = ['score' => $score, 'item' => $item];
    }

    usort($scored, static function (array $left, array $right): int {
        return strcmp($left['score'], $right['score']);
    });

    return array_slice(array_map(static fn(array $row) => $row['item'], $scored), 0, $limit);
}

function ems_ai_interview_answer_guidance(string $intent, string $type, string $recruitmentType): string
{
    $goodGuidance = [
        'discipline_leadership' => 'Menjelaskan cara menegakkan disiplin dengan tegas, adil, dan tetap menjaga martabat anggota tim.',
        'sop_under_pressure' => 'Memberi contoh nyata tetap patuh SOP saat ditekan, lalu menjelaskan alasan risiko jika prosedur dilanggar.',
        'courtesy_correction' => 'Menunjukkan cara menegur dengan sopan, jelas, tidak mempermalukan, dan tetap menuntut perbaikan perilaku.',
        'sop_judgement' => 'Bisa membedakan fleksibilitas teknis dengan pelanggaran SOP inti, disertai alasan yang masuk akal.',
        'operational_priority' => 'Menjelaskan urutan prioritas yang rapi, tenang, dan berorientasi pada dampak terbesar bagi pelayanan.',
        'leadership_fatigue' => 'Menunjukkan gaya memimpin yang stabil, memberi contoh, dan mampu menjaga semangat tim saat lelah.',
        'professional_communication' => 'Menjelaskan cara tetap sopan, tenang, dan fokus pada solusi meski menghadapi nada tinggi atau komplain keras.',
        'fairness_sop' => 'Menegaskan bahwa aturan berlaku sama untuk semua orang, termasuk teman dekat atau bawahan yang disukai.',
        'briefing_validation' => 'Menyebut indikator konkret seperti repeat back, cek pemahaman, dan tindak lanjut setelah briefing.',
        'follow_through' => 'Menjelaskan kebiasaan monitoring, follow up, dan memastikan instruksi benar-benar selesai dieksekusi.',
        'documentation_accountability' => 'Memahami bahwa dokumentasi penting untuk akuntabilitas, evaluasi, dan mencegah kesalahan berulang.',
        'speed_vs_procedure' => 'Bisa menyeimbangkan kecepatan dan keamanan, dengan tetap menempatkan prosedur kritis sebagai prioritas.',
        'results_vs_attitude' => 'Menilai hasil kerja dan attitude secara seimbang, serta paham bahwa perilaku buruk bisa merusak tim.',
        'clarify_instruction' => 'Menjelaskan cara klarifikasi instruksi tanpa menambah kekacauan dan tetap menjaga alur kerja tertib.',
        'cross_validation_probe' => 'Memberi klarifikasi runtut, terbuka, dan tidak panik saat ada perbedaan data antar tahap.',
        'shadow_under_pressure' => 'Jujur menyebut sisi buruk saat tertekan dan menjelaskan kontrol diri yang biasa dilakukan.',
        'strength_weakness_balance' => 'Bisa menyebut kekuatan dan kelemahan secara seimbang, tidak pencitraan, dan ada usaha perbaikan nyata.',
        'respectful_disagreement' => 'Menunjukkan cara menyampaikan beda pendapat dengan sopan, data yang jelas, dan tanpa menyerang atasan.',
        'criticism_response' => 'Mengakui kritik yang sulit diterima namun tetap menunjukkan kemauan mendengar dan memperbaiki diri.',
        'fatigue_self_control' => 'Mampu mengidentifikasi risiko perilakunya saat lelah lalu menjelaskan langkah konkret untuk mengendalikannya.',
        'failure_learning' => 'Menjelaskan kegagalan secara jujur, tanggung jawabnya di mana, dan perubahan nyata setelah kejadian itu.',
        'ego_trigger' => 'Mengenali pemicu ego pribadi dan menunjukkan upaya sadar untuk tetap profesional saat tersinggung.',
        'self_discipline_risk' => 'Jujur menyebut area disiplin yang rawan turun tanpa pengawasan dan punya cara menjaga konsistensi.',
        'patience_truth' => 'Menunjukkan kesabaran yang realistis, tidak pura-pura sempurna, dan mampu mengelola emosi terhadap orang yang lambat.',
        'unfinished_bad_habit' => 'Bisa menyebut kebiasaan buruk yang masih ada tanpa berkelit serta menunjukkan proses perbaikannya.',
        'small_error_coverup' => 'Menunjukkan kejujuran, paham risiko menutup kesalahan kecil, dan memilih transparansi saat ada kekeliruan.',
        'credit_humility' => 'Menjelaskan bahwa kredit kerja dibagi adil dan tidak mengambil hasil orang lain untuk diri sendiri.',
        'core_fear' => 'Mampu menjelaskan ketakutan utamanya secara jujur dan bagaimana itu memengaruhi keputusan saat bekerja.',
        'anger_pattern' => 'Mengenali pola marahnya sendiri dan menjelaskan cara mencegahnya melukai tim atau keputusan kerja.',
        'response_honesty_probe' => 'Terbuka menjelaskan proses menjawab assessment tanpa defensif dan tidak berusaha terlihat sempurna.',
        'assessment_consistency' => 'Bisa menjelaskan alasan jawaban assessment dengan konsisten dan tetap logis saat didalami lebih jauh.',
    ];

    $badGuidance = [
        'discipline_leadership' => 'Jawaban kabur, terlalu keras tanpa empati, atau justru terlalu lembek sehingga disiplin tidak benar-benar ditegakkan.',
        'sop_under_pressure' => 'Mudah membenarkan jalan pintas, tidak paham risiko pelanggaran SOP, atau tidak punya contoh nyata.',
        'courtesy_correction' => 'Cenderung mempermalukan orang, menghindari teguran, atau tidak bisa menjaga sopan santun saat menegur.',
        'sop_judgement' => 'Tidak bisa membedakan fleksibilitas dengan pelanggaran, atau terlalu gampang mengorbankan prosedur demi cepat selesai.',
        'operational_priority' => 'Jawaban melompat-lompat, tidak punya urutan prioritas, atau hanya mengandalkan insting tanpa pertimbangan dampak.',
        'leadership_fatigue' => 'Gaya memimpin cenderung emosional, pasif, atau hanya menyuruh tanpa memberi arah dan contoh.',
        'professional_communication' => 'Mudah terpancing emosi, membalas dengan nada keras, atau fokus menyalahkan lawan bicara.',
        'fairness_sop' => 'Terlihat pilih kasih, memberi toleransi khusus untuk orang dekat, atau tidak konsisten menegakkan aturan.',
        'briefing_validation' => 'Menganggap briefing cukup selesai saat sudah bicara, tanpa cek pemahaman atau tindak lanjut ke tim.',
        'follow_through' => 'Hanya memberi instruksi awal lalu lepas tangan tanpa monitoring sampai pekerjaan benar-benar selesai.',
        'documentation_accountability' => 'Meremehkan laporan dan dokumentasi, atau menganggap pencatatan tidak penting selama pekerjaan selesai.',
        'speed_vs_procedure' => 'Terlalu condong ke kecepatan walau prosedur berisiko dilanggar, atau tidak bisa menjelaskan batas amannya.',
        'results_vs_attitude' => 'Terlalu menoleransi attitude buruk hanya karena hasil kerja bagus, atau tidak paham dampaknya ke tim.',
        'clarify_instruction' => 'Menjalankan arahan yang belum jelas tanpa verifikasi, atau malah menambah bingung anggota tim.',
        'cross_validation_probe' => 'Jawaban berubah-ubah, menolak klarifikasi, atau langsung defensif saat ditanya soal ketidakkonsistenan data.',
        'shadow_under_pressure' => 'Mengaku tidak punya sisi buruk, terlalu defensif, atau tidak sadar bagaimana tekanan memengaruhi dirinya.',
        'strength_weakness_balance' => 'Jawaban terlalu pencitraan, kelemahan terdengar dibuat-buat, atau tidak ada usaha memperbaiki diri.',
        'respectful_disagreement' => 'Cenderung melawan secara frontal, memendam diam-diam, atau tidak bisa menyampaikan keberatan dengan dewasa.',
        'criticism_response' => 'Langsung tersinggung, menyalahkan orang lain, atau menolak kritik tanpa refleksi.',
        'fatigue_self_control' => 'Tidak sadar risikonya saat lelah, atau menganggap perubahan emosi saat capek adalah hal biasa.',
        'failure_learning' => 'Ceritanya kabur, tanggung jawab dilempar ke orang lain, atau tidak ada perubahan nyata setelah gagal.',
        'ego_trigger' => 'Tidak mengenali pemicu ego atau justru membenarkan ledakan emosinya saat merasa diremehkan.',
        'self_discipline_risk' => 'Mengaku selalu disiplin tanpa cela, atau tidak punya cara konkret menjaga ritme kerja saat tidak diawasi.',
        'patience_truth' => 'Menunjukkan kecenderungan mudah merendahkan, cepat kesal, atau tidak sabar pada anggota yang lemah.',
        'unfinished_bad_habit' => 'Tidak mau mengakui kebiasaan buruk, atau menganggap kekurangan pribadi tidak perlu diperbaiki.',
        'small_error_coverup' => 'Membenarkan menutup kesalahan kecil, meremehkan dampak, atau terlalu fokus menyelamatkan citra diri.',
        'credit_humility' => 'Cenderung mengambil pujian sendiri, mengecilkan kontribusi orang lain, atau tidak nyaman berbagi kredit.',
        'core_fear' => 'Tidak jujur soal ketakutannya, atau jawabannya terlalu dibuat aman sehingga tidak memberi gambaran kepribadian nyata.',
        'anger_pattern' => 'Membenarkan ledakan marah, menyimpan dendam diam-diam, atau tidak punya kontrol saat emosi naik.',
        'response_honesty_probe' => 'Terlalu defensif, menyalahkan bentuk soal, atau berusaha terlihat sempurna tanpa penjelasan yang jujur.',
        'assessment_consistency' => 'Jawaban berputar, berubah saat ditekan, atau terlihat hanya menyesuaikan dengan apa yang dianggap aman.',
    ];

    $fallbackGood = $recruitmentType === 'assistant_manager'
        ? 'Jawaban konkret, konsisten, dan menunjukkan tanggung jawab serta kedewasaan kerja.'
        : 'Jawaban konkret, tenang, dan menunjukkan etika kerja yang baik.';
    $fallbackBad = $recruitmentType === 'assistant_manager'
        ? 'Jawaban normatif, defensif, tidak konsisten, atau tidak menyentuh perilaku kerja nyata.'
        : 'Jawaban mengambang, defensif, atau tidak menunjukkan perilaku profesional yang jelas.';

    return $type === 'good'
        ? ($goodGuidance[$intent] ?? $fallbackGood)
        : ($badGuidance[$intent] ?? $fallbackBad);
}

function ems_ai_question_based_answer_guidance(string $questionText, string $intent, string $type, string $recruitmentType): string
{
    $normalized = strtolower(trim($questionText));

    $rules = [
        [
            'patterns' => ['kerja tim', 'tim anda', 'anggota tim', 'bekerja dalam tim'],
            'good' => 'Menunjukkan cara bekerja sama, membagi peran, mendengar masukan, dan menjaga tujuan tim tetap berjalan bersama.',
            'bad' => 'Terlalu menonjolkan diri, menyalahkan anggota lain, atau tidak menunjukkan cara nyata menjaga kerja sama tim.',
        ],
        [
            'patterns' => ['sop', 'prosedur'],
            'good' => 'Memberi contoh nyata tetap patuh SOP, menjelaskan batas fleksibilitas, dan memahami risiko jika prosedur dilanggar.',
            'bad' => 'Mudah membenarkan jalan pintas, tidak paham risiko pelanggaran SOP, atau tidak punya contoh perilaku nyata.',
        ],
        [
            'patterns' => ['kritik', 'masukan', 'ditegur'],
            'good' => 'Mampu menerima kritik tanpa defensif, menjelaskan proses evaluasi diri, lalu menunjukkan perbaikan yang pernah dilakukan.',
            'bad' => 'Langsung tersinggung, menyalahkan orang lain, atau tidak bisa menunjukkan contoh perbaikan setelah menerima kritik.',
        ],
        [
            'patterns' => ['prioritas', 'bersamaan', 'mendesak'],
            'good' => 'Menjelaskan urutan prioritas yang jelas, memakai pertimbangan dampak, dan tetap menjaga pekerjaan paling kritis selesai dulu.',
            'bad' => 'Jawaban lompat-lompat, tanpa dasar prioritas yang jelas, atau terlalu bergantung pada panik dan insting sesaat.',
        ],
        [
            'patterns' => ['dokumentasi', 'laporan', 'jejak keputusan', 'tertulis'],
            'good' => 'Memahami bahwa dokumentasi penting untuk akuntabilitas, tindak lanjut, evaluasi, dan mencegah kesalahan berulang.',
            'bad' => 'Meremehkan pencatatan, menganggap laporan tidak penting, atau tidak bisa menjelaskan fungsi dokumentasi dalam kerja nyata.',
        ],
        [
            'patterns' => ['briefing', 'instruksi', 'dipahami tim'],
            'good' => 'Menyebut cara memastikan instruksi dipahami, misalnya konfirmasi ulang, repeat back, dan cek hasil eksekusi setelah briefing.',
            'bad' => 'Menganggap instruksi selesai saat sudah disampaikan, tanpa verifikasi pemahaman atau tindak lanjut ke tim.',
        ],
        [
            'patterns' => ['komplain', 'nada bicara', 'tetap sopan'],
            'good' => 'Menunjukkan komunikasi yang tenang, sopan, fokus pada solusi, dan tidak terpancing emosi walau lawan bicara keras.',
            'bad' => 'Mudah terpancing emosi, membalas dengan nada serupa, atau lebih fokus menyerang balik daripada menyelesaikan masalah.',
        ],
        [
            'patterns' => ['ego', 'tersinggung', 'diremehkan'],
            'good' => 'Mampu mengenali pemicu ego pribadi, menjelaskan kontrol diri yang dipakai, dan tetap menjaga profesionalitas saat tersinggung.',
            'bad' => 'Tidak sadar pemicu emosinya, membenarkan ledakan ego, atau tidak bisa menjelaskan cara mengendalikan diri.',
        ],
        [
            'patterns' => ['sisi buruk', 'kelemahan', 'kebiasaan buruk'],
            'good' => 'Jujur menyebut kelemahan atau kebiasaan buruk yang nyata, lalu menjelaskan langkah konkret yang sedang dilakukan untuk memperbaikinya.',
            'bad' => 'Jawaban terlalu aman, kelemahan dibuat-buat, atau tidak ada proses perbaikan yang jelas dalam perilaku kerja sehari-hari.',
        ],
        [
            'patterns' => ['marah', 'tertekan', 'mood buruk', 'lelah'],
            'good' => 'Mampu mengenali perubahan perilaku saat emosi naik, lalu menjelaskan langkah konkret untuk menahan dampaknya ke tim dan pekerjaan.',
            'bad' => 'Menganggap ledakan emosi hal biasa, tidak sadar dampaknya ke kerja tim, atau tidak punya cara mengendalikan diri.',
        ],
        [
            'patterns' => ['keputusan atasan', 'menyampaikan keberatan', 'tidak terlihat melawan'],
            'good' => 'Bisa menyampaikan perbedaan pendapat dengan sopan, berbasis alasan yang jelas, dan tetap menjaga hormat pada atasan.',
            'bad' => 'Cenderung melawan frontal, memendam diam-diam, atau tidak bisa menjelaskan cara menyampaikan keberatan secara dewasa.',
        ],
        [
            'patterns' => ['assessment anda menjawab', 'jawaban anda berubah', 'apa adanya', 'paling aman'],
            'good' => 'Menjelaskan alasan jawabannya secara jujur, konsisten, dan tetap masuk akal saat didalami lebih lanjut.',
            'bad' => 'Jawaban berubah-ubah, defensif, atau terasa hanya menyesuaikan dengan jawaban yang dianggap aman.',
        ],
        [
            'patterns' => ['waktu', 'jadwal', 'deadline', 'time management'],
            'good' => 'Menunjukkan cara mengatur waktu, menjaga tenggat, dan membagi fokus kerja tanpa mengorbankan kualitas hasil.',
            'bad' => 'Tidak punya metode pengaturan waktu, sering mengandalkan kebiasaan spontan, atau tidak bisa memberi contoh menjaga deadline.',
        ],
        [
            'patterns' => ['detail', 'teliti', 'attention to detail'],
            'good' => 'Menjelaskan kebiasaan mengecek detail, verifikasi ulang, dan mencegah kesalahan kecil sebelum berdampak besar.',
            'bad' => 'Meremehkan detail, terlalu umum, atau tidak punya cara kerja yang menunjukkan ketelitian nyata.',
        ],
        [
            'patterns' => ['integritas', 'jujur', 'menutupi kesalahan', 'kejujuran'],
            'good' => 'Menunjukkan kejujuran, transparansi, dan keberanian mengakui kesalahan meski tidak menguntungkan dirinya sendiri.',
            'bad' => 'Membenarkan menutupi kesalahan, terlalu fokus menjaga citra diri, atau tidak tegas soal batas integritas.',
        ],
        [
            'patterns' => ['inisiatif', 'initiative', 'bergerak duluan'],
            'good' => 'Menunjukkan inisiatif yang terarah, tahu kapan harus bergerak mandiri, dan tetap menjaga koordinasi dengan tim atau atasan.',
            'bad' => 'Pasif menunggu arahan, atau justru bergerak sendiri tanpa koordinasi dan tanpa pertimbangan dampak.',
        ],
        [
            'patterns' => ['adaptasi', 'adaptability', 'lingkungan baru', 'perubahan'],
            'good' => 'Menjelaskan cara cepat menyesuaikan diri, belajar aturan baru, dan tetap stabil saat kondisi kerja berubah.',
            'bad' => 'Kaku terhadap perubahan, terlalu lama beradaptasi, atau tidak punya contoh nyata menghadapi lingkungan baru.',
        ],
        [
            'patterns' => ['logical', 'logika', 'analisa', 'analisis'],
            'good' => 'Menunjukkan alur pikir runtut, sebab-akibat yang jelas, dan keputusan yang disusun dari informasi yang relevan.',
            'bad' => 'Penjelasan meloncat, tidak runtut, atau kesimpulan diambil tanpa dasar logika yang jelas.',
        ],
        [
            'patterns' => ['decision', 'keputusan', 'memutuskan'],
            'good' => 'Menjelaskan proses mengambil keputusan dengan pertimbangan risiko, prioritas, dan tanggung jawab atas hasilnya.',
            'bad' => 'Keputusan terasa impulsif, terlalu bergantung pada orang lain, atau tidak menunjukkan pertimbangan risiko yang matang.',
        ],
    ];

    foreach ($rules as $rule) {
        foreach ($rule['patterns'] as $pattern) {
            if (str_contains($normalized, strtolower($pattern))) {
                return $type === 'good' ? $rule['good'] : $rule['bad'];
            }
        }
    }

    return ems_ai_interview_answer_guidance($intent, $type, $recruitmentType);
}

function ems_ai_question_answer_guidance_bundle(string $questionText, string $intent, string $recruitmentType): array
{
    return [
        'good' => ems_ai_question_based_answer_guidance($questionText, $intent, 'good', $recruitmentType),
        'bad' => ems_ai_question_based_answer_guidance($questionText, $intent, 'bad', $recruitmentType),
    ];
}

function ems_ai_interview_guidance_looks_generic(string $text, string $recruitmentType): bool
{
    $normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    if ($normalized === '') {
        return true;
    }

    $genericPhrases = [
        'jawaban konkret, konsisten',
        'menunjukkan tanggung jawab serta kedewasaan kerja',
        'jawaban normatif, defensif',
        'tidak menyentuh perilaku kerja nyata',
        'jawaban konkret, tenang',
        'menunjukkan etika kerja yang baik',
        'jawaban mengambang, defensif',
        'tidak menunjukkan perilaku profesional yang jelas',
    ];

    foreach ($genericPhrases as $phrase) {
        if (str_contains($normalized, $phrase)) {
            return true;
        }
    }

    $tokenCount = count(array_values(array_filter(preg_split('/\s+/u', $normalized) ?: [])));
    return $tokenCount <= 7;
}

function ems_ai_interview_focus_metadata(string $questionText, string $intent, string $recruitmentType, string $criterionCode = ''): array
{
    $normalized = strtolower(trim($questionText));

    $textRules = [
        [
            'patterns' => ['sop', 'standard operating procedure', 'prosedur'],
            'label' => 'Kepatuhan SOP',
            'description' => 'Kemampuan menjaga prosedur tetap dipatuhi meski dalam tekanan kerja.',
        ],
        [
            'patterns' => ['kerja tim', 'tim anda', 'anggota tim', 'bekerja dalam tim'],
            'label' => 'Kerja Tim',
            'description' => 'Kemampuan bekerja dalam tim, membagi peran, dan menjaga kolaborasi.',
        ],
        [
            'patterns' => ['kritik', 'masukan', 'ditegur'],
            'label' => 'Respons terhadap Kritik',
            'description' => 'Kematangan menerima kritik dan mengubahnya menjadi perbaikan nyata.',
        ],
        [
            'patterns' => ['detail', 'teliti', 'attention to detail'],
            'label' => 'Attention to Detail',
            'description' => 'Ketelitian melihat detail, data, dan potensi kesalahan kecil.',
        ],
        [
            'patterns' => ['integritas', 'jujur', 'menutupi kesalahan', 'kejujuran'],
            'label' => 'Integrity',
            'description' => 'Kejujuran, konsistensi nilai, dan keberanian menjaga amanah kerja.',
        ],
        [
            'patterns' => ['inisiatif', 'initiative', 'bergerak duluan'],
            'label' => 'Initiative',
            'description' => 'Kemauan mengambil langkah kerja yang tepat tanpa harus selalu menunggu arahan.',
        ],
        [
            'patterns' => ['adaptasi', 'adaptability', 'lingkungan baru', 'perubahan'],
            'label' => 'Adaptability',
            'description' => 'Kemampuan menyesuaikan diri dengan perubahan situasi, aturan, dan ritme kerja.',
        ],
        [
            'patterns' => ['waktu', 'jadwal', 'deadline', 'time management'],
            'label' => 'Time Management',
            'description' => 'Kemampuan mengatur waktu, prioritas, dan penyelesaian pekerjaan sesuai tenggat.',
        ],
        [
            'patterns' => ['logical', 'logika', 'analisa', 'analisis'],
            'label' => 'Logical Thinking',
            'description' => 'Kemampuan berpikir runtut, logis, dan menyusun alasan yang masuk akal.',
        ],
        [
            'patterns' => ['decision', 'keputusan', 'memutuskan'],
            'label' => 'Decision Making',
            'description' => 'Kemampuan mengambil keputusan yang tepat dan bertanggung jawab.',
        ],
        [
            'patterns' => ['briefing', 'instruksi'],
            'label' => 'Koordinasi',
            'description' => 'Kemampuan memastikan instruksi dipahami dan dijalankan dengan benar.',
        ],
        [
            'patterns' => ['komplain', 'tetap sopan', 'nada bicara'],
            'label' => 'Komunikasi Profesional',
            'description' => 'Kemampuan menjaga komunikasi tetap sopan, tenang, dan fokus pada solusi.',
        ],
        [
            'patterns' => ['ego', 'tersinggung', 'diremehkan'],
            'label' => 'Kontrol Ego',
            'description' => 'Kemampuan mengenali pemicu ego dan tetap profesional saat emosi terpancing.',
        ],
        [
            'patterns' => ['dokumen', 'dokumentasi', 'laporan'],
            'label' => 'Akuntabilitas',
            'description' => 'Kemampuan menjaga dokumentasi, pelaporan, dan pertanggungjawaban kerja.',
        ],
    ];

    foreach ($textRules as $rule) {
        foreach ($rule['patterns'] as $pattern) {
            if (str_contains($normalized, strtolower($pattern))) {
                return [
                    'label' => $rule['label'],
                    'description' => $rule['description'],
                ];
            }
        }
    }

    $criterionFallback = [
        'teamwork' => ['label' => 'Kerja Tim', 'description' => 'Kemampuan bekerja dalam tim.'],
        'communication' => ['label' => 'Komunikasi', 'description' => 'Kemampuan menyampaikan dan menerima pesan kerja dengan tepat.'],
        'discipline' => ['label' => 'Disiplin', 'description' => 'Konsistensi menjaga aturan, ritme kerja, dan tanggung jawab.'],
        'leadership' => ['label' => 'Leadership', 'description' => 'Kemampuan mengarahkan, memengaruhi, dan menjaga tim tetap berjalan.'],
        'loyalty' => ['label' => 'Loyalitas', 'description' => 'Konsistensi menjaga amanah, integritas, dan kepentingan institusi.'],
        'stress_control' => ['label' => 'Kontrol Tekanan', 'description' => 'Kemampuan mengelola emosi dan perilaku saat tertekan.'],
        'responsibility' => ['label' => 'Tanggung Jawab', 'description' => 'Kemampuan menuntaskan tugas dan bertanggung jawab atas hasil kerja.'],
        'attitude' => ['label' => 'Attitude', 'description' => 'Sikap kerja, etika, dan kedewasaan dalam berinteraksi.'],
        'sop_compliance' => ['label' => 'Kepatuhan SOP', 'description' => 'Kemampuan menjaga prosedur tetap dipatuhi secara konsisten.'],
        'ga_coordination' => ['label' => 'Koordinasi General Affair', 'description' => 'Kemampuan koordinasi lintas kebutuhan operasional dan tim.'],
        'operational_control' => ['label' => 'Kontrol Operasional', 'description' => 'Kemampuan mengontrol prioritas, tindak lanjut, dan stabilitas operasional.'],
        'logical_thinking' => ['label' => 'Logical Thinking', 'description' => 'Kemampuan berpikir runtut dan logis saat menganalisis situasi kerja.'],
        'decision_making' => ['label' => 'Decision Making', 'description' => 'Kemampuan mengambil keputusan yang tepat, cepat, dan bertanggung jawab.'],
        'attention_to_detail' => ['label' => 'Attention to Detail', 'description' => 'Ketelitian dalam melihat detail, data, prosedur, dan potensi kesalahan kecil.'],
        'integrity' => ['label' => 'Integrity', 'description' => 'Kejujuran, konsistensi nilai, dan kemampuan menjaga amanah dalam bekerja.'],
        'initiative' => ['label' => 'Initiative', 'description' => 'Kemauan bergerak, mencari solusi, dan mengambil langkah kerja tanpa harus selalu menunggu perintah.'],
        'adaptability' => ['label' => 'Adaptability', 'description' => 'Kemampuan menyesuaikan diri dengan perubahan situasi, ritme kerja, dan kebutuhan tim.'],
        'time_management' => ['label' => 'Time Management', 'description' => 'Kemampuan mengatur waktu, prioritas kerja, dan penyelesaian tugas sesuai tenggat.'],
    ];

    if ($criterionCode !== '' && isset($criterionFallback[$criterionCode])) {
        return $criterionFallback[$criterionCode];
    }

    if ($intent === 'assessment_consistency') {
        return [
            'label' => 'Konsistensi Jawaban',
            'description' => 'Kesesuaian jawaban assessment dengan penjelasan kandidat saat interview.',
        ];
    }

    return [
        'label' => $recruitmentType === 'assistant_manager' ? 'Evaluasi Peran Asisten Manager' : 'Evaluasi Kandidat',
        'description' => 'Pendalaman perilaku kerja, cara berpikir, dan kesiapan menjalankan peran.',
    ];
}

function ems_ai_supported_interview_intents(): array
{
    return [
        'discipline_leadership',
        'sop_under_pressure',
        'courtesy_correction',
        'sop_judgement',
        'operational_priority',
        'leadership_fatigue',
        'professional_communication',
        'fairness_sop',
        'briefing_validation',
        'follow_through',
        'documentation_accountability',
        'speed_vs_procedure',
        'results_vs_attitude',
        'clarify_instruction',
        'cross_validation_probe',
        'shadow_under_pressure',
        'strength_weakness_balance',
        'respectful_disagreement',
        'criticism_response',
        'fatigue_self_control',
        'failure_learning',
        'ego_trigger',
        'self_discipline_risk',
        'patience_truth',
        'unfinished_bad_habit',
        'small_error_coverup',
        'credit_humility',
        'core_fear',
        'anger_pattern',
        'response_honesty_probe',
        'assessment_consistency',
    ];
}

function ems_ai_infer_interview_intent(string $text, string $category, string $recruitmentType): string
{
    $normalized = strtolower(trim($text));
    if ($normalized === '') {
        return '';
    }

    $rules = [
        'discipline_leadership' => ['disiplin hadir', 'disiplin kerja', 'menegakkan disiplin'],
        'sop_under_pressure' => ['tetap mematuhi sop', 'tekanan untuk mempercepat', 'situasi darurat', 'tekanan tinggi'],
        'courtesy_correction' => ['sopan santun', 'menegur', 'membina', 'dijatuhkan'],
        'sop_judgement' => ['boleh fleksibel', 'tanpa kompromi', 'mengikuti sop'],
        'operational_priority' => ['prioritas kerja', 'muncul bersamaan', 'pelayanan tidak kacau'],
        'leadership_fatigue' => ['tim yang sedang lelah', 'menurun disiplin', 'bentuk kepemimpinan'],
        'professional_communication' => ['komplain keras', 'nada bicara yang tinggi', 'tetap sopan'],
        'fairness_sop' => ['teman dekat', 'berbeda dibanding', 'anggota lain', 'aturan berlaku sama'],
        'briefing_validation' => ['briefing', 'instruksi kerja', 'dipahami tim'],
        'follow_through' => ['menindaklanjuti pekerjaan', 'sampai selesai', 'memberi instruksi di awal'],
        'documentation_accountability' => ['dokumentasi', 'laporan tertulis', 'jejak keputusan'],
        'speed_vs_procedure' => ['cepat selesai', 'prosedur rapi', 'aman'],
        'results_vs_attitude' => ['hasil kerjanya bagus', 'attitude', 'sopan santunnya buruk'],
        'clarify_instruction' => ['arahan mendadak', 'membingungkan tim', 'eksekusi tetap tertib'],
        'cross_validation_probe' => ['tidak konsisten antar tahap', 'diverifikasi ulang', 'klarifikasi'],
        'shadow_under_pressure' => ['saat sedang tertekan', 'sisi buruk anda'],
        'strength_weakness_balance' => ['sisi baik', 'sisi buruk', 'membantu tim'],
        'respectful_disagreement' => ['keputusan atasan kurang tepat', 'menyampaikan keberatan'],
        'criticism_response' => ['jenis kritik', 'sulit anda terima', 'reaksi pertama'],
        'fatigue_self_control' => ['dalam kondisi lelah', 'mood buruk', 'mengendalikannya'],
        'failure_learning' => ['kegagalan pribadi', 'merasa malu', 'anda ubah'],
        'ego_trigger' => ['memancing ego', 'ego anda'],
        'self_discipline_risk' => ['tidak ada yang mengawasi', 'rawan turun'],
        'patience_truth' => ['lebih lemah', 'lebih lambat', 'sabar atau cepat kesal'],
        'unfinished_bad_habit' => ['kebiasaan buruk', 'belum benar-benar selesai'],
        'small_error_coverup' => ['menutupi kesalahan kecil', 'dampaknya tidak besar'],
        'credit_humility' => ['kontribusi orang lain', 'dipuji', 'pujian'],
        'core_fear' => ['lebih anda takutkan', 'tidak kompeten', 'tidak disukai', 'kehilangan kontrol'],
        'anger_pattern' => ['sedang marah', 'bicara langsung', 'memilih diam'],
        'response_honesty_probe' => ['jawaban assessment anda terlihat', 'menjawab apa adanya', 'jawaban paling aman'],
        'assessment_consistency' => ['di assessment anda menjawab', 'situasi apa yang paling mungkin membuat jawaban anda berubah'],
    ];

    foreach ($rules as $intent => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($normalized, strtolower($keyword))) {
                return $intent;
            }
        }
    }

    return $category === 'medis_sop'
        ? ($recruitmentType === 'assistant_manager' ? 'sop_under_pressure' : 'discipline_leadership')
        : 'strength_weakness_balance';
}

function ems_ai_interview_question_banks(string $recruitmentType, array $candidate, array $result, array $answers, array $aiQuestions): array
{
    $name = (string)($candidate['ic_name'] ?? 'kandidat');
    $hospitalRole = $recruitmentType === 'assistant_manager' ? 'General Affair / Asisten Manager' : 'EMS / medis';
    $badFlags = json_decode((string)($result['risk_flags'] ?? ''), true);
    $badFlags = is_array($badFlags) ? $badFlags : ['bias' => [], 'cross' => []];
    $biasFlags = array_values((array)($badFlags['bias'] ?? []));
    $crossFlags = array_values((array)($badFlags['cross'] ?? []));

    $medical = [
        ['text' => "Dalam peran {$hospitalRole}, bagaimana Anda memastikan disiplin hadir dan disiplin kerja tim tetap terjaga tanpa membuat anggota merasa dijatuhkan?", 'intent' => 'discipline_leadership'],
        ['text' => 'Ceritakan contoh ketika Anda harus tetap mematuhi SOP walau ada tekanan untuk mempercepat pekerjaan.', 'intent' => 'sop_under_pressure'],
        ['text' => 'Jika ada rekan kerja yang sopan santunnya buruk kepada warga atau divisi lain, bagaimana Anda menegur dan membinanya?', 'intent' => 'courtesy_correction'],
        ['text' => 'Bagaimana Anda membedakan situasi yang boleh fleksibel dengan situasi yang tetap harus mengikuti SOP tanpa kompromi?', 'intent' => 'sop_judgement'],
        ['text' => 'Saat dua kebutuhan penting muncul bersamaan, bagaimana Anda menentukan prioritas kerja agar pelayanan tidak kacau?', 'intent' => 'operational_priority'],
        ['text' => 'Apa bentuk kepemimpinan yang menurut Anda paling efektif untuk tim yang sedang lelah atau mulai menurun disiplin kerjanya?', 'intent' => 'leadership_fatigue'],
        ['text' => 'Bagaimana cara Anda menjaga komunikasi tetap sopan ketika menerima komplain keras atau nada bicara yang tinggi?', 'intent' => 'professional_communication'],
        ['text' => 'Jika bawahan atau rekan dekat Anda melanggar SOP, apakah pendekatan Anda akan berbeda dibanding ke anggota lain? Jelaskan.', 'intent' => 'fairness_sop'],
        ['text' => 'Apa indikator bahwa sebuah briefing atau instruksi kerja benar-benar sudah dipahami tim, bukan hanya didengar?', 'intent' => 'briefing_validation'],
        ['text' => 'Ceritakan bagaimana Anda menindaklanjuti pekerjaan sampai selesai, bukan hanya memberi instruksi di awal.', 'intent' => 'follow_through'],
        ['text' => 'Menurut Anda, kenapa dokumentasi, laporan tertulis, dan jejak keputusan penting dalam pekerjaan yang menyangkut pelayanan?', 'intent' => 'documentation_accountability'],
        ['text' => 'Kalau Anda harus memilih antara cepat selesai atau prosedur rapi dan aman, kapan Anda akan condong ke salah satu dan kenapa?', 'intent' => 'speed_vs_procedure'],
        ['text' => 'Bagaimana Anda menilai anggota tim yang hasil kerjanya bagus tetapi attitude dan sopan santunnya buruk?', 'intent' => 'results_vs_attitude'],
        ['text' => 'Jika pimpinan memberi arahan mendadak yang berpotensi membingungkan tim, apa yang Anda lakukan agar eksekusi tetap tertib?', 'intent' => 'clarify_instruction'],
    ];

    $personal = [
        ['text' => 'Saat sedang tertekan, sisi buruk Anda biasanya muncul dalam bentuk apa: diam, emosional, defensif, atau hal lain?', 'intent' => 'shadow_under_pressure'],
        ['text' => 'Apa sisi baik Anda yang paling membantu tim, dan apa sisi buruk Anda yang paling sering menimbulkan masalah bila tidak dikontrol?', 'intent' => 'strength_weakness_balance'],
        ['text' => 'Kalau Anda merasa keputusan atasan kurang tepat, bagaimana Anda menyampaikan keberatan tanpa terlihat melawan?', 'intent' => 'respectful_disagreement'],
        ['text' => 'Apa jenis kritik yang paling sulit Anda terima, dan biasanya reaksi pertama Anda seperti apa?', 'intent' => 'criticism_response'],
        ['text' => 'Dalam kondisi lelah atau mood buruk, apa risiko perilaku Anda terhadap tim dan bagaimana Anda mengendalikannya?', 'intent' => 'fatigue_self_control'],
        ['text' => 'Ceritakan kegagalan pribadi yang pernah membuat Anda merasa malu, lalu apa yang benar-benar Anda ubah setelah itu.', 'intent' => 'failure_learning'],
        ['text' => 'Apa hal yang paling mudah memancing ego Anda saat bekerja bersama orang lain?', 'intent' => 'ego_trigger'],
        ['text' => 'Jika tidak ada yang mengawasi Anda, bagian mana dari disiplin kerja yang paling rawan turun?', 'intent' => 'self_discipline_risk'],
        ['text' => 'Saat menghadapi orang yang lebih lemah, lebih lambat, atau kurang paham, apakah Anda cenderung sabar atau cepat kesal? Jelaskan jujur.', 'intent' => 'patience_truth'],
        ['text' => 'Apa kebiasaan buruk yang sampai sekarang masih Anda bawa dan belum benar-benar selesai Anda perbaiki?', 'intent' => 'unfinished_bad_habit'],
        ['text' => 'Pertanyaan jujur: pernahkah Anda menutupi kesalahan kecil karena merasa dampaknya tidak besar? Jika pernah, apa konteksnya?', 'intent' => 'small_error_coverup'],
        ['text' => 'Jika hasil kerja Anda dipuji tetapi sebenarnya ada kontribusi orang lain yang besar, apa yang biasanya Anda lakukan?', 'intent' => 'credit_humility'],
        ['text' => 'Apa yang biasanya lebih Anda takutkan: dinilai tidak kompeten, tidak disukai, atau kehilangan kontrol? Mengapa?', 'intent' => 'core_fear'],
        ['text' => 'Kalau sedang marah, apakah Anda lebih berbahaya ketika bicara langsung atau ketika memilih diam? Kenapa?', 'intent' => 'anger_pattern'],
    ];

    $contradictions = [];
    $questionTexts = [];
    foreach ($aiQuestions as $questionId => $questionText) {
        $questionTexts[(int)$questionId] = (string)$questionText;
    }

    foreach ($answers as $questionId => $answer) {
        $questionId = (int)$questionId;
        $answer = (string)$answer;
        $questionText = trim((string)($questionTexts[$questionId] ?? ''));
        if ($questionText === '') {
            continue;
        }

        if ($answer === 'ya') {
            $contradictions[] = [
                'text' => "Di assessment Anda menjawab `Ya` pada pernyataan: \"{$questionText}\". Ceritakan contoh nyata yang mendukung jawaban itu, lalu kapan dalam praktiknya Anda justru bisa bersikap sebaliknya?",
                'intent' => 'assessment_consistency',
            ];
        } elseif ($answer === 'tidak') {
            $contradictions[] = [
                'text' => "Di assessment Anda menjawab `Tidak` pada pernyataan: \"{$questionText}\". Bisa jelaskan alasan Anda memilih jawaban itu dan situasi apa yang paling mungkin membuat jawaban Anda berubah?",
                'intent' => 'assessment_consistency',
            ];
        }
    }

    if ($biasFlags) {
        $personal[] = [
            'text' => 'Beberapa jawaban assessment Anda terlihat sangat tegas ke satu arah. Saat menjawab tadi, apakah Anda benar-benar menjawab apa adanya atau lebih memilih jawaban yang menurut Anda paling aman?',
            'intent' => 'response_honesty_probe',
            'criterion_code' => 'responsibility',
            'good_answer' => 'Mengakui proses berpikirnya dengan jujur, tidak defensif, dan bisa menjelaskan alasan jawaban secara terbuka.',
            'bad_answer' => 'Menghindar, terlalu defensif, atau terus menyalahkan bentuk soal tanpa mau menjelaskan isi jawabannya.',
        ];
    }

    if ($crossFlags) {
        $medical[] = [
            'text' => 'Ada area pada formulir awal dan assessment yang perlu diverifikasi ulang. Jika kami menemukan jawaban Anda tidak konsisten antar tahap, bagaimana Anda menjelaskan hal itu?',
            'intent' => 'cross_validation_probe',
            'criterion_code' => $recruitmentType === 'assistant_manager' ? 'operational_control' : 'responsibility',
            'good_answer' => 'Memberi klarifikasi runtut, tidak panik, dan bersedia mengakui bagian yang memang berubah atau keliru.',
            'bad_answer' => 'Jawaban berputar, menyangkal semua hal tanpa penjelasan, atau berubah-ubah saat ditekan.',
        ];
    }

    $decorate = static function (array $items, string $defaultCriterion) use ($recruitmentType): array {
        return array_map(static function (array $item) use ($defaultCriterion, $recruitmentType): array {
            $intent = (string)($item['intent'] ?? '');
            return [
                'text' => (string)($item['text'] ?? ''),
                'intent' => $intent,
                'criterion_code' => (string)($item['criterion_code'] ?? $defaultCriterion),
                'good_answer' => (string)($item['good_answer'] ?? ems_ai_interview_answer_guidance($intent, 'good', $recruitmentType)),
                'bad_answer' => (string)($item['bad_answer'] ?? ems_ai_interview_answer_guidance($intent, 'bad', $recruitmentType)),
            ];
        }, $items);
    };

    $medical = array_map(static function (array $item) use ($recruitmentType): array {
        $criterion = $recruitmentType === 'assistant_manager' ? 'sop_compliance' : 'discipline';
        $text = (string)($item['text'] ?? '');
        $intent = (string)($item['intent'] ?? '');

        $criterionMap = [
            'discipline_leadership' => $recruitmentType === 'assistant_manager' ? 'leadership' : 'discipline',
            'sop_under_pressure' => $recruitmentType === 'assistant_manager' ? 'sop_compliance' : 'discipline',
            'courtesy_correction' => 'attitude',
            'sop_judgement' => $recruitmentType === 'assistant_manager' ? 'sop_compliance' : 'responsibility',
            'operational_priority' => $recruitmentType === 'assistant_manager' ? 'operational_control' : 'responsibility',
            'leadership_fatigue' => 'leadership',
            'professional_communication' => 'communication',
            'fairness_sop' => $recruitmentType === 'assistant_manager' ? 'sop_compliance' : 'discipline',
            'briefing_validation' => $recruitmentType === 'assistant_manager' ? 'ga_coordination' : 'communication',
            'follow_through' => $recruitmentType === 'assistant_manager' ? 'operational_control' : 'responsibility',
            'documentation_accountability' => $recruitmentType === 'assistant_manager' ? 'operational_control' : 'responsibility',
            'speed_vs_procedure' => $recruitmentType === 'assistant_manager' ? 'sop_compliance' : 'discipline',
            'results_vs_attitude' => 'attitude',
            'clarify_instruction' => $recruitmentType === 'assistant_manager' ? 'ga_coordination' : 'communication',
            'cross_validation_probe' => $recruitmentType === 'assistant_manager' ? 'operational_control' : 'responsibility',
        ];

        return [
            'text' => $text,
            'intent' => $intent,
            'criterion_code' => $criterionMap[$intent] ?? $criterion,
            'good_answer' => ems_ai_interview_answer_guidance($intent, 'good', $recruitmentType),
            'bad_answer' => ems_ai_interview_answer_guidance($intent, 'bad', $recruitmentType),
        ];
    }, $medical);

    $personal = array_map(static function (array $item) use ($recruitmentType): array {
        $intent = (string)($item['intent'] ?? '');
        $criterionMap = [
            'shadow_under_pressure' => 'stress_control',
            'strength_weakness_balance' => 'attitude',
            'respectful_disagreement' => 'communication',
            'criticism_response' => 'attitude',
            'fatigue_self_control' => 'stress_control',
            'failure_learning' => 'responsibility',
            'ego_trigger' => 'leadership',
            'self_discipline_risk' => 'discipline',
            'patience_truth' => 'teamwork',
            'unfinished_bad_habit' => 'responsibility',
            'small_error_coverup' => 'loyalty',
            'credit_humility' => 'teamwork',
            'core_fear' => 'stress_control',
            'anger_pattern' => 'stress_control',
            'response_honesty_probe' => 'loyalty',
            'assessment_consistency' => $recruitmentType === 'assistant_manager' ? 'ga_coordination' : 'communication',
        ];

        return [
            'text' => (string)($item['text'] ?? ''),
            'intent' => $intent,
            'criterion_code' => $criterionMap[$intent] ?? 'attitude',
            'good_answer' => ems_ai_interview_answer_guidance($intent, 'good', $recruitmentType),
            'bad_answer' => ems_ai_interview_answer_guidance($intent, 'bad', $recruitmentType),
        ];
    }, $personal);

    $contradictions = array_map(static function (array $item) use ($recruitmentType): array {
        return [
            'text' => (string)($item['text'] ?? ''),
            'intent' => (string)($item['intent'] ?? 'assessment_consistency'),
            'criterion_code' => $recruitmentType === 'assistant_manager' ? 'ga_coordination' : 'communication',
            'good_answer' => ems_ai_interview_answer_guidance((string)($item['intent'] ?? 'assessment_consistency'), 'good', $recruitmentType),
            'bad_answer' => ems_ai_interview_answer_guidance((string)($item['intent'] ?? 'assessment_consistency'), 'bad', $recruitmentType),
        ];
    }, $contradictions);

    return ['medical' => $medical, 'personal' => $personal, 'contradictions' => $contradictions];
}

function ems_ai_generate_interview_question_pack_fallback(array $candidate, array $result, array $answers, array $aiQuestions, int $applicantId, int $hrId, string $recruitmentType): array
{
    $seed = ems_ai_interview_question_seed($candidate, $applicantId, $hrId);
    $banks = ems_ai_interview_question_banks($recruitmentType, $candidate, $result, $answers, $aiQuestions);

    $medicalQuestions = ems_ai_select_seeded_items($banks['medical'], 10, $seed + 11);
    $personalQuestions = ems_ai_select_seeded_items($banks['personal'], 6, $seed + 29);
    $contradictionQuestions = ems_ai_select_seeded_items($banks['contradictions'], 4, $seed + 47);

    $picked = [];
    $addQuestion = static function (array $item, string $category) use (&$picked): void {
        $picked[] = [
            'category' => $category,
            'text' => (string)($item['text'] ?? ''),
            'intent' => (string)($item['intent'] ?? ''),
            'criterion_code' => (string)($item['criterion_code'] ?? ''),
            'good_answer' => (string)($item['good_answer'] ?? ''),
            'bad_answer' => (string)($item['bad_answer'] ?? ''),
        ];
    };

    foreach ($medicalQuestions as $item) {
        $addQuestion($item, 'medis_sop');
    }
    foreach ($personalQuestions as $item) {
        $addQuestion($item, 'non_medis_kepribadian');
    }
    foreach ($contradictionQuestions as $item) {
        $addQuestion($item, 'verifikasi_assessment');
    }

    $medicalRows = array_slice(array_values(array_filter($picked, static fn(array $q) => $q['category'] === 'medis_sop')), 0, 10);
    $personalRows = array_slice(array_values(array_filter($picked, static fn(array $q) => $q['category'] !== 'medis_sop')), 0, 10);

    return [
        'medical_questions' => $medicalRows,
        'personal_questions' => $personalRows,
    ];
}

function ems_ai_interview_question_pack_payload(array $candidate, array $result, array $answers, array $aiQuestions, string $recruitmentType): array
{
    $riskFlags = json_decode((string)($result['risk_flags'] ?? ''), true);
    $riskFlags = is_array($riskFlags) ? $riskFlags : ['bias' => [], 'cross' => []];

    $answerRows = [];
    foreach ($aiQuestions as $questionId => $questionText) {
        $answerRows[] = [
            'question_id' => (int)$questionId,
            'question' => (string)$questionText,
            'answer' => (string)($answers[$questionId] ?? ''),
        ];
    }

    return [
        'candidate' => [
            'name' => (string)($candidate['ic_name'] ?? ''),
            'recruitment_type' => $recruitmentType,
            'motivation' => (string)($candidate['motivation'] ?? ''),
            'medical_experience' => (string)($candidate['medical_experience'] ?? ''),
            'rule_commitment' => (string)($candidate['rule_commitment'] ?? ''),
            'other_city_responsibility' => (string)($candidate['other_city_responsibility'] ?? ''),
            'work_principle' => (string)($candidate['work_principle'] ?? ''),
            'duty_duration' => (string)($candidate['duty_duration'] ?? ''),
        ],
        'assessment' => [
            'score_total' => (int)($result['score_total'] ?? 0),
            'decision' => (string)($result['decision'] ?? ''),
            'duration_seconds' => (int)($result['duration_seconds'] ?? 0),
            'risk_flags' => $riskFlags,
            'answers' => $answerRows,
        ],
    ];
}

function ems_ai_parse_question_pack_response(string $rawText): ?array
{
    $decoded = json_decode($rawText, true);
    if (!is_array($decoded)) {
        return null;
    }

    $normalize = static function (array $items, string $category): array {
        $rows = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $rows[] = [
                    'category' => $category,
                    'text' => ems_ai_clean_interview_question_text(trim($item)),
                    'intent' => '',
                    'criterion_code' => '',
                    'good_answer' => '',
                    'bad_answer' => '',
                ];
                continue;
            }

            if (is_array($item)) {
                $text = ems_ai_clean_interview_question_text(trim((string)($item['text'] ?? '')));
                if ($text === '') {
                    continue;
                }
                $intent = trim((string)($item['intent'] ?? ''));
                if ($intent === '' || !in_array($intent, ems_ai_supported_interview_intents(), true)) {
                    $intent = ems_ai_infer_interview_intent($text, $category, 'assistant_manager');
                }
                $goodAnswer = trim((string)($item['good_answer'] ?? $item['good_answer_guide'] ?? $item['good'] ?? ''));
                $badAnswer = trim((string)($item['bad_answer'] ?? $item['bad_answer_guide'] ?? $item['bad'] ?? ''));

                $rows[] = [
                    'category' => $category,
                    'text' => $text,
                    'intent' => $intent,
                    'criterion_code' => trim((string)($item['criterion_code'] ?? '')),
                    'good_answer' => $goodAnswer,
                    'bad_answer' => $badAnswer,
                ];
            }
        }
        return $rows;
    };

    $medical = $normalize((array)($decoded['medical_questions'] ?? []), 'medis_sop');
    $personal = $normalize((array)($decoded['personal_questions'] ?? []), 'non_medis_kepribadian');

    if ($medical === [] && $personal === []) {
        return null;
    }

    return [
        'medical_questions' => array_slice($medical, 0, 10),
        'personal_questions' => array_slice($personal, 0, 10),
    ];
}

function ems_ai_flatten_interview_question_pack(array $pack): array
{
    $rows = [];

    $normalizeQuestion = static function (array $question, string $questionKey, string $category, string $defaultCriterion): array {
        $recruitmentType = (string)($question['recruitment_type'] ?? 'assistant_manager');
        $intent = trim((string)($question['intent'] ?? ''));
        $cleanText = ems_ai_clean_interview_question_text((string)($question['text'] ?? ''));
        if ($intent === '' || !in_array($intent, ems_ai_supported_interview_intents(), true)) {
            $intent = ems_ai_infer_interview_intent($cleanText, $category, $recruitmentType);
        }
        $criterionCode = (string)($question['criterion_code'] ?? $defaultCriterion);
        $guidance = ems_ai_question_answer_guidance_bundle($cleanText, $intent, $recruitmentType);
        $focusMeta = ems_ai_interview_focus_metadata($cleanText, $intent, $recruitmentType, $criterionCode);
        $goodAnswer = trim((string)($question['good_answer'] ?? ''));
        $badAnswer = trim((string)($question['bad_answer'] ?? ''));

        if (ems_ai_interview_guidance_looks_generic($goodAnswer, $recruitmentType)) {
            $goodAnswer = $guidance['good'];
        }
        if (ems_ai_interview_guidance_looks_generic($badAnswer, $recruitmentType)) {
            $badAnswer = $guidance['bad'];
        }

        return [
            'question_key' => $questionKey,
            'category' => $category,
            'text' => $cleanText,
            'intent' => $intent,
            'criterion_code' => $criterionCode,
            'focus_label' => (string)($focusMeta['label'] ?? ''),
            'focus_description' => (string)($focusMeta['description'] ?? ''),
            'good_answer' => $goodAnswer,
            'bad_answer' => $badAnswer,
        ];
    };

    foreach ((array)($pack['medical_questions'] ?? []) as $index => $question) {
        $question['recruitment_type'] = (string)($pack['recruitment_type'] ?? 'assistant_manager');
        $rows[] = $normalizeQuestion($question, 'medical_' . ($index + 1), 'medis_sop', 'sop_compliance');
    }

    foreach ((array)($pack['personal_questions'] ?? []) as $index => $question) {
        $question['recruitment_type'] = (string)($pack['recruitment_type'] ?? 'assistant_manager');
        $rows[] = $normalizeQuestion($question, 'personal_' . ($index + 1), 'non_medis_kepribadian', 'attitude');
    }

    return $rows;
}

function ems_ai_generate_interview_note(array $questions, array $scores, array $criteriaLabelMap, string $aiSummary = ''): string
{
    $strong = [];
    $weak = [];
    $criterionScores = [];
    $answeredCount = 0;
    $totalQuestions = count($questions);

    foreach ($questions as $question) {
        $key = (string)($question['question_key'] ?? '');
        $score = (int)($scores[$key] ?? 0);
        if ($key === '' || $score < 1 || $score > 5) {
            continue;
        }

        $answeredCount++;

        $criterionCode = (string)($question['criterion_code'] ?? '');
        if ($criterionCode !== '') {
            $criterionScores[$criterionCode][] = $score;
        }

        $label = (string)($question['text'] ?? '');
        if ($score >= 4) {
            $strong[] = $label;
        } elseif ($score <= 2) {
            $weak[] = $label;
        }
    }

    $criterionSummary = [];
    foreach ($criterionScores as $criterionCode => $items) {
        $avg = array_sum($items) / max(1, count($items));
        $label = $criteriaLabelMap[$criterionCode] ?? $criterionCode;
        if ($avg >= 4) {
            $criterionSummary[] = $label . ' kuat';
        } elseif ($avg <= 2) {
            $criterionSummary[] = $label . ' lemah';
        } else {
            $criterionSummary[] = $label . ' perlu pendalaman';
        }
    }

    $cleanAiSummary = trim(preg_replace('/\s+/u', ' ', $aiSummary));
    if ($cleanAiSummary !== '' && mb_strlen($cleanAiSummary) > 180) {
        $cleanAiSummary = rtrim(mb_substr($cleanAiSummary, 0, 180)) . '...';
    }

    $parts = [];
    if ($answeredCount > 0) {
        $parts[] = 'Kesimpulan: penilaian interviewer ini baru mencakup ' . $answeredCount . ' dari ' . $totalQuestions . ' pertanyaan.';
    }
    if ($criterionSummary) {
        $parts[] = 'Area utama: ' . implode(', ', array_slice($criterionSummary, 0, 4)) . '.';
    }
    if ($strong) {
        $parts[] = 'Kekuatan terlihat pada: ' . implode('; ', array_slice($strong, 0, 2)) . '.';
    }
    if ($weak) {
        $parts[] = 'Perlu pendalaman pada: ' . implode('; ', array_slice($weak, 0, 2)) . '.';
    }
    if ($cleanAiSummary !== '') {
        $parts[] = 'Konteks AI: ' . $cleanAiSummary;
    }

    return trim(implode("\n", $parts));
}

function ems_ai_get_or_create_interview_question_pack(PDO $pdo, array $settings, array $candidate, array $result, array $answers, array $aiQuestions, int $applicantId, int $hrId, string $recruitmentType): array
{
    ems_ai_ensure_interview_question_packs_table($pdo);

    $stmt = $pdo->prepare("
        SELECT question_pack_json
        FROM applicant_interview_question_packs
        WHERE applicant_id = ? AND hr_id = ?
        LIMIT 1
    ");
    $stmt->execute([$applicantId, $hrId]);
    $existingJson = $stmt->fetchColumn();
    if (is_string($existingJson) && trim($existingJson) !== '') {
        $decoded = json_decode($existingJson, true);
        if (is_array($decoded)) {
            $decoded['recruitment_type'] = $recruitmentType;
            $flattenedExisting = ems_ai_flatten_interview_question_pack($decoded);
            if (count($flattenedExisting) === 20) {
                $hasMissingGuidance = false;
                foreach ($flattenedExisting as $row) {
                    if (trim((string)($row['good_answer'] ?? '')) === '' || trim((string)($row['bad_answer'] ?? '')) === '') {
                        $hasMissingGuidance = true;
                        break;
                    }
                }

                if (!$hasMissingGuidance) {
                    return $decoded;
                }

                $medical = [];
                $personal = [];
                foreach ($flattenedExisting as $row) {
                    $normalized = [
                        'text' => (string)($row['text'] ?? ''),
                        'intent' => (string)($row['intent'] ?? ''),
                        'criterion_code' => (string)($row['criterion_code'] ?? ''),
                        'good_answer' => (string)($row['good_answer'] ?? ''),
                        'bad_answer' => (string)($row['bad_answer'] ?? ''),
                    ];
                    if (($row['category'] ?? '') === 'medis_sop') {
                        $medical[] = $normalized;
                    } else {
                        $personal[] = $normalized;
                    }
                }

                $decoded = [
                    'recruitment_type' => $recruitmentType,
                    'medical_questions' => $medical,
                    'personal_questions' => $personal,
                ];
                $pack = $decoded;
                $provider = 'system';
                $modelName = null;

                $stmt = $pdo->prepare("
                    UPDATE applicant_interview_question_packs
                    SET question_pack_json = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE applicant_id = ? AND hr_id = ?
                ");
                $stmt->execute([
                    json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $applicantId,
                    $hrId,
                ]);

                return $decoded;
            }
        }
    }

    $provider = 'system';
    $modelName = null;
    $pack = null;

    if (!empty($settings['is_enabled']) && trim((string)($settings['gemini_api_key'] ?? '')) !== '') {
        $template = ems_ai_get_active_prompt_template($pdo, 'interview_question_pack');
        if ($template) {
            $payload = ems_ai_interview_question_pack_payload($candidate, $result, $answers, $aiQuestions, $recruitmentType);
            $prompt = str_replace('{{candidate_payload}}', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)($template['user_prompt_template'] ?? ''));

            try {
                $response = ems_gemini_generate_content(
                    $pdo,
                    $settings,
                    [[
                        'role' => 'user',
                        'parts' => [
                            ['text' => trim((string)($template['system_prompt'] ?? ''))],
                            ['text' => $prompt . "\nTambahan aturan: buat tepat 10 pertanyaan kategori medis_sop dan tepat 10 pertanyaan kategori non_medis_kepribadian. Setiap item WAJIB berbentuk object dengan field text, intent, criterion_code, good_answer, bad_answer. good_answer dan bad_answer harus spesifik menjawab isi pertanyaan itu sendiri, bukan template umum, bukan kalimat generik, dan bukan pengulangan antar pertanyaan. Tulis 1-2 kalimat ringkas yang memberi gambaran jawaban kuat dan jawaban lemah untuk pertanyaan tersebut. Pastikan ada pertanyaan sisi baik, sisi buruk, jebakan, serta verifikasi jawaban assessment yang tampak berpotensi bertentangan. Jangan halu dan jangan keluar konteks kandidat."],
                        ],
                    ]],
                    (string)($settings['interview_question_model'] ?? $settings['default_model'] ?? 'gemini-2.5-flash'),
                    'interview_question_pack_applicant_' . $applicantId . '_hr_' . $hrId,
                    $hrId
                );

                $rawText = trim((string)($response['text'] ?? ''));
                if ($rawText !== '') {
                    $parsed = ems_ai_parse_question_pack_response($rawText);
                    if (is_array($parsed) && count(ems_ai_flatten_interview_question_pack($parsed)) === 20) {
                        $pack = $parsed;
                        $provider = 'gemini';
                        $modelName = (string)($response['model'] ?? '');
                    }
                }
            } catch (Throwable $e) {
                $pack = null;
            }
        }
    }

    if (!is_array($pack)) {
        $pack = ems_ai_generate_interview_question_pack_fallback($candidate, $result, $answers, $aiQuestions, $applicantId, $hrId, $recruitmentType);
    }

    $stmt = $pdo->prepare("
        INSERT INTO applicant_interview_question_packs
        (applicant_id, hr_id, recruitment_type, provider, model_name, question_pack_json)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            recruitment_type = VALUES(recruitment_type),
            provider = VALUES(provider),
            model_name = VALUES(model_name),
            question_pack_json = VALUES(question_pack_json),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $applicantId,
        $hrId,
        $recruitmentType,
        $provider,
        $modelName,
        json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return $pack;
}
