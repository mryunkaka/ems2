<?php

/**
 * =========================================================
 * AI PSYCHOMETRIC SCORING ENGINE
 * Roxwood Hospital Recruitment System
 * =========================================================
 *
 * DIMENSIONS:
 * - Focus
 * - Social (Extraversion-lite)
 * - Obedience (Conscientiousness/Agreeableness mix)
 * - Consistency
 * - Emotional Stability (Neuroticism reversed)
 * - Honesty–Humility (HEXACO)
 *
 * =========================================================
 */

/* =========================================================
   1. TRAIT ITEM DEFINITIONS
   ========================================================= */
function getTraitItems(string $profile = 'medical_candidate'): array
{
    $profile = function_exists('ems_normalize_recruitment_type')
        ? ems_normalize_recruitment_type($profile)
        : $profile;

    if ($profile === 'assistant_manager') {
        return [
            'focus' => [
                3  => ['direction' => 'normal',  'weight' => 1.0],
                18 => ['direction' => 'normal',  'weight' => 1.0],
                23 => ['direction' => 'normal',  'weight' => 0.9],
                26 => ['direction' => 'normal',  'weight' => 1.0],
                39 => ['direction' => 'normal',  'weight' => 1.0],
                61 => ['direction' => 'normal',  'weight' => 1.0],
            ],
            'social' => [
                8  => ['direction' => 'normal',  'weight' => 1.0],
                24 => ['direction' => 'normal',  'weight' => 0.9],
                37 => ['direction' => 'normal',  'weight' => 1.0],
                44 => ['direction' => 'normal',  'weight' => 1.0],
                50 => ['direction' => 'normal',  'weight' => 1.0],
                59 => ['direction' => 'normal',  'weight' => 1.0],
            ],
            'obedience' => [
                1  => ['direction' => 'normal',  'weight' => 0.9],
                9  => ['direction' => 'normal',  'weight' => 1.0],
                15 => ['direction' => 'reverse', 'weight' => 1.0],
                22 => ['direction' => 'normal',  'weight' => 1.0],
                31 => ['direction' => 'normal',  'weight' => 1.0],
                38 => ['direction' => 'normal',  'weight' => 1.0],
                49 => ['direction' => 'normal',  'weight' => 1.0],
                68 => ['direction' => 'normal',  'weight' => 1.0],
            ],
            'consistency' => [
                5  => ['direction' => 'normal',  'weight' => 1.0],
                10 => ['direction' => 'normal',  'weight' => 1.0],
                14 => ['direction' => 'normal',  'weight' => 0.9],
                21 => ['direction' => 'normal',  'weight' => 0.9],
                30 => ['direction' => 'normal',  'weight' => 1.0],
                33 => ['direction' => 'normal',  'weight' => 1.0],
                41 => ['direction' => 'normal',  'weight' => 0.8],
                42 => ['direction' => 'normal',  'weight' => 1.0],
                46 => ['direction' => 'normal',  'weight' => 1.0],
                48 => ['direction' => 'normal',  'weight' => 0.8],
                58 => ['direction' => 'normal',  'weight' => 0.8],
                62 => ['direction' => 'normal',  'weight' => 0.9],
            ],
            'emotional_stability' => [
                11 => ['direction' => 'reverse', 'weight' => 0.9],
                16 => ['direction' => 'normal',  'weight' => 1.0],
                19 => ['direction' => 'normal',  'weight' => 0.8],
                35 => ['direction' => 'normal',  'weight' => 0.9],
                52 => ['direction' => 'normal',  'weight' => 1.0],
                64 => ['direction' => 'normal',  'weight' => 1.0],
            ],
            'honesty_humility' => [
                2  => ['direction' => 'normal',  'weight' => 0.9],
                25 => ['direction' => 'normal',  'weight' => 1.0],
                27 => ['direction' => 'normal',  'weight' => 0.8],
                36 => ['direction' => 'normal',  'weight' => 1.0],
                45 => ['direction' => 'normal',  'weight' => 1.0],
                55 => ['direction' => 'normal',  'weight' => 0.9],
                57 => ['direction' => 'normal',  'weight' => 1.0],
                69 => ['direction' => 'normal',  'weight' => 1.0],
            ],
        ];
    }

    return [

        /* ===============================
           FOCUS & ATTENTION
           =============================== */
        'focus' => [
            2  => ['direction' => 'reverse', 'weight' => 1.0],
            17 => ['direction' => 'normal',  'weight' => 1.0],
            35 => ['direction' => 'normal',  'weight' => 1.0],
            47 => ['direction' => 'reverse', 'weight' => 1.0],
            6  => ['direction' => 'normal',  'weight' => 0.8],
            29 => ['direction' => 'normal',  'weight' => 0.8],
        ],

        /* ===============================
           SOCIAL / EXTRAVERSION
           =============================== */
        'social' => [
            9  => ['direction' => 'reverse', 'weight' => 1.0],
            22 => ['direction' => 'reverse', 'weight' => 1.0],
            32 => ['direction' => 'reverse', 'weight' => 1.0],
            40 => ['direction' => 'reverse', 'weight' => 1.0],
            4  => ['direction' => 'reverse', 'weight' => 0.7],
        ],

        /* ===============================
           OBEDIENCE / CONSCIENTIOUSNESS
           =============================== */
        'obedience' => [
            3  => ['direction' => 'normal',  'weight' => 1.0],
            8  => ['direction' => 'normal',  'weight' => 1.0],
            15 => ['direction' => 'reverse', 'weight' => 1.0],
            28 => ['direction' => 'reverse', 'weight' => 1.0],
            26 => ['direction' => 'normal',  'weight' => 0.8],
            46 => ['direction' => 'normal',  'weight' => 0.8],
        ],

        /* ===============================
           CONSISTENCY / STABILITY
           =============================== */
        'consistency' => [
            7  => ['direction' => 'reverse', 'weight' => 1.0],
            10 => ['direction' => 'reverse', 'weight' => 1.0],
            14 => ['direction' => 'reverse', 'weight' => 1.0],
            36 => ['direction' => 'normal',  'weight' => 1.0],
            45 => ['direction' => 'normal',  'weight' => 1.0],
            48 => ['direction' => 'normal',  'weight' => 1.0],
            39 => ['direction' => 'reverse', 'weight' => 0.8],
        ],

        /* ===============================
           EMOTIONAL STABILITY
           =============================== */
        'emotional_stability' => [
            13 => ['direction' => 'normal',  'weight' => 1.0],
            16 => ['direction' => 'normal',  'weight' => 1.0],
            24 => ['direction' => 'normal',  'weight' => 1.0],
            50 => ['direction' => 'normal',  'weight' => 1.0],
            12 => ['direction' => 'reverse', 'weight' => 0.9],
        ],

        /* ===============================
           HEXACO: HONESTY–HUMILITY
           ===============================
           - Sincerity
           - Fairness
           - Greed Avoidance
           - Modesty
           =============================== */
        'honesty_humility' => [
            15 => ['direction' => 'reverse', 'weight' => 1.0], // Abaikan aturan
            28 => ['direction' => 'reverse', 'weight' => 1.0], // Prinsip mudah berubah
            4  => ['direction' => 'reverse', 'weight' => 0.8], // Tidak semua perlu tahu
            19 => ['direction' => 'reverse', 'weight' => 0.8], // Kepentingan pribadi
            37 => ['direction' => 'reverse', 'weight' => 0.8], // Cari keuntungan
            44 => ['direction' => 'reverse', 'weight' => 0.8], // Manipulatif sosial
            23 => ['direction' => 'normal',  'weight' => 0.7], // Makna > posisi
            8  => ['direction' => 'normal',  'weight' => 0.7], // Etika penting
        ],
    ];
}

/* =========================================================
   2. NORMALIZED TRAIT SCORING (0–100)
   ========================================================= */
function calculateTraitScore(array $answers, array $items): array
{
    $raw = 0.0;
    $max = 0.0;
    $used = 0;

    foreach ($items as $q => $cfg) {
        if (!isset($answers[$q])) continue;

        $v = ($answers[$q] === 'ya') ? 1 : 0;
        if ($cfg['direction'] === 'reverse') {
            $v = 1 - $v;
        }

        $raw += $v * $cfg['weight'];
        $max += $cfg['weight'];
        $used++;
    }

    $score = $used > 0 ? ($raw / $max) * 100 : 50;

    return [
        'score'        => round($score, 2),
        'items_used'  => $used,
        'reliability' => reliabilityLevel($used),
    ];
}

/* =========================================================
   3. RELIABILITY ESTIMATION
   ========================================================= */
function reliabilityLevel(int $n): string
{
    if ($n >= 8) return 'good';
    if ($n >= 5) return 'acceptable';
    if ($n >= 3) return 'questionable';
    return 'poor';
}

/* =========================================================
   4. RESPONSE BIAS DETECTION
   ========================================================= */
function detectResponseBias(array $answers): array
{
    $flags = [];

    $counts = array_count_values($answers);
    $ya = $counts['ya'] ?? 0;
    $tidak = $counts['tidak'] ?? 0;
    $total = count($answers);

    if ($total > 0) {
        if ($ya / $total > 0.85) $flags[] = 'acquiescence_bias';
        if ($tidak / $total > 0.85) $flags[] = 'disacquiescence_bias';
    }

    $prev = null;
    $run = 1;
    $maxRun = 1;

    foreach ($answers as $a) {
        if ($a === $prev) {
            $run++;
            $maxRun = max($maxRun, $run);
        } else {
            $run = 1;
        }
        $prev = $a;
    }

    // Assistant-manager items are grouped by trait and polarity, so moderate same-answer
    // runs can happen naturally. Treat pattern answering as a red flag only when the run
    // is both very long and paired with a heavily one-sided answer distribution.
    if ($total > 0 && $maxRun >= 20 && (($ya / $total) > 0.7 || ($tidak / $total) > 0.7)) {
        $flags[] = 'pattern_answering';
    }

    return $flags;
}

/* =========================================================
   5. CROSS VALIDATION WITH FORM
   ========================================================= */
function crossValidateWithForm(array $scores, array $applicant, string $profile = 'medical_candidate'): array
{
    $flags = [];
    $profile = function_exists('ems_normalize_recruitment_type')
        ? ems_normalize_recruitment_type($profile)
        : $profile;

    if ($profile === 'assistant_manager') {
        if (($scores['obedience']['score'] ?? 0) < 55) {
            $flags[] = 'sop_alignment_risk';
        }

        if (($scores['consistency']['score'] ?? 0) < 60) {
            $flags[] = 'consistency_risk';
        }

        if (($scores['social']['score'] ?? 0) < 45) {
            $flags[] = 'coordination_risk';
        }

        return $flags;
    }

    if (
        ($applicant['rule_commitment'] ?? '') === 'ya' &&
        ($scores['obedience']['score'] ?? 0) < 40
    ) {
        $flags[] = 'rule_commitment_mismatch';
    }

    if (
        trim($applicant['other_city_responsibility'] ?? '-') !== '-' &&
        ($scores['consistency']['score'] ?? 0) < 50
    ) {
        $flags[] = 'multi_responsibility_risk';
    }

    if (
        stripos($applicant['motivation'] ?? '', 'jangka panjang') !== false &&
        ($scores['consistency']['score'] ?? 0) < 50
    ) {
        $flags[] = 'motivation_behavior_mismatch';
    }

    return $flags;
}

/* =========================================================
   6. COMPOSITE SCORE PENALTY ENGINE
   ========================================================= */
function calculateCompositeScore(
    array $scores,
    array $biasFlags,
    array $crossFlags,
    int $durationSeconds,
    string $profile = 'medical_candidate'
): float {
    $profile = function_exists('ems_normalize_recruitment_type')
        ? ems_normalize_recruitment_type($profile)
        : $profile;

    $avg = array_sum(array_column($scores, 'score')) / max(1, count($scores));
    $penalty = 0.0;

    $biasPenaltyMap = [
        'acquiescence_bias' => 6,
        'disacquiescence_bias' => 6,
        'pattern_answering' => 6,
        'trap_answering' => 5,
        'high_risk_trap_answering' => 8,
    ];

    foreach ($biasFlags as $flag) {
        $penalty += (float) ($biasPenaltyMap[$flag] ?? 4);
    }

    if ($profile === 'assistant_manager') {
        if ($durationSeconds < 180) {
            $penalty += 14;
        } elseif ($durationSeconds < 240) {
            $penalty += 12;
        } elseif ($durationSeconds < 300) {
            $penalty += 8;
        }

        $penalty += min(8, count($crossFlags) * 4);
        $penalty = min($penalty, 38);
    } else {
        if ($durationSeconds < 120) {
            $penalty += 12;
        } elseif ($durationSeconds < 180) {
            $penalty += 8;
        } elseif ($durationSeconds < 240) {
            $penalty += 5;
        }

        $penalty += min(6, count($crossFlags) * 3);
        $penalty = min($penalty, 30);
    }

    return round(max(0, min(100, $avg - $penalty)), 2);
}

/* =========================================================
   7. FINAL DECISION ENGINE (HEXACO INCLUDED)
   ========================================================= */
function makeFinalDecision(
    array $scores,
    array $biasFlags,
    array $crossFlags,
    int $durationSeconds,
    string $profile = 'medical_candidate'
): array {
    $profile = function_exists('ems_normalize_recruitment_type')
        ? ems_normalize_recruitment_type($profile)
        : $profile;

    $avg = array_sum(array_column($scores, 'score')) / count($scores);
    $compositeScore = calculateCompositeScore($scores, $biasFlags, $crossFlags, $durationSeconds, $profile);

    $decision = 'consider';
    $confidence = 'medium';
    $reasons = [];

    if ($profile === 'assistant_manager') {
        if (
            $avg >= 70 &&
            ($scores['honesty_humility']['score'] ?? 0) >= 65 &&
            ($scores['obedience']['score'] ?? 0) >= 65 &&
            ($scores['consistency']['score'] ?? 0) >= 65 &&
            count($biasFlags) === 0 &&
            $durationSeconds >= 420 &&
            $durationSeconds <= 5400
        ) {
            $decision = 'recommended';
            $confidence = 'high';
            $reasons[] = 'Kesiapan SOP, koordinasi, dan akuntabilitas dinilai kuat';
        }

        if (
            $avg < 50 ||
            ($scores['honesty_humility']['score'] ?? 0) < 45 ||
            ($scores['obedience']['score'] ?? 0) < 45 ||
            count($biasFlags) >= 2 ||
            count($crossFlags) >= 2 ||
            $durationSeconds < 240
        ) {
            $decision = 'not_recommended';
            $confidence = 'high';
            $reasons[] = 'Risiko konsistensi, kepatuhan SOP, atau kualitas respon';
        }
    } else {
        if (
            $avg >= 65 &&
            ($scores['honesty_humility']['score'] ?? 0) >= 60 &&
            count($biasFlags) === 0 &&
            $durationSeconds >= 300 &&
            $durationSeconds <= 3600
        ) {
            $decision = 'recommended';
            $confidence = 'high';
            $reasons[] = 'Profil psikologis seimbang & integritas baik';
        }

        if (
            $avg < 40 ||
            ($scores['honesty_humility']['score'] ?? 0) < 40 ||
            count($biasFlags) >= 2 ||
            $durationSeconds < 180
        ) {
            $decision = 'not_recommended';
            $confidence = 'high';
            $reasons[] = 'Risiko integritas atau kualitas respon';
        }
    }

    if (!$reasons) {
        $reasons[] = 'Perlu evaluasi lanjutan oleh HR';
    }

    return [
        'decision'        => $decision,
        'confidence'      => $confidence,
        'average_score'   => round($avg, 2),
        'composite_score' => $compositeScore,
        'honesty_score'   => $scores['honesty_humility']['score'] ?? null,
        'bias_flags'      => $biasFlags,
        'cross_flags'     => $crossFlags,
        'duration_minute' => round($durationSeconds / 60, 1),
    ];
}

function generatePsychologicalNarrative(array $scores, array $finalDecision, string $profile = 'medical_candidate'): string
{
    $lines = [];
    $profile = function_exists('ems_normalize_recruitment_type')
        ? ems_normalize_recruitment_type($profile)
        : $profile;

    /* =========================================================
       INTEGRITAS (HEXACO)
       ========================================================= */
    $honesty = $scores['honesty_humility']['score'] ?? null;

    if ($honesty !== null) {
        if ($honesty >= 75) {
            $lines[] = 'Menunjukkan tingkat integritas pribadi yang tinggi, cenderung jujur, tidak manipulatif, dan menjaga etika kerja.';
        } elseif ($honesty >= 55) {
            $lines[] = 'Menunjukkan integritas kerja yang cukup baik, meskipun masih dipengaruhi oleh situasi tertentu.';
        } else {
            $lines[] = 'Menunjukkan indikasi risiko integritas, sehingga perlu pengawasan dan sistem kerja yang jelas.';
        }
    }

    /* =========================================================
       FOKUS & KETAHANAN KERJA
       ========================================================= */
    if ($profile === 'assistant_manager') {
        if ($scores['focus']['score'] >= 65 && $scores['consistency']['score'] >= 65) {
            $lines[] = 'Memiliki kecenderungan menjaga detail operasional dan konsistensi tindak lanjut, sesuai untuk peran pengawasan General Affair.';
        } elseif ($scores['focus']['score'] < 50) {
            $lines[] = 'Perlu dukungan sistem kerja yang rapi agar detail operasional dan tindak lanjut tidak mudah terlewat.';
        }
    } elseif ($scores['focus']['score'] >= 65 && $scores['consistency']['score'] >= 65) {
        $lines[] = 'Memiliki fokus dan daya tahan kerja yang baik, cocok untuk tugas dengan durasi panjang dan tekanan operasional.';
    } elseif ($scores['focus']['score'] < 50) {
        $lines[] = 'Perlu dukungan strategi kerja untuk menjaga fokus dalam tugas jangka panjang.';
    }

    /* =========================================================
       EMOSI
       ========================================================= */
    if ($scores['emotional_stability']['score'] >= 65) {
        $lines[] = 'Cenderung stabil secara emosional dan mampu mengelola tekanan kerja dengan cukup baik.';
    } elseif ($scores['emotional_stability']['score'] < 50) {
        $lines[] = 'Memerlukan lingkungan kerja yang suportif untuk menjaga kestabilan emosi.';
    }

    /* =========================================================
       GAYA SOSIAL
       ========================================================= */
    if ($profile === 'assistant_manager') {
        if ($scores['social']['score'] >= 65) {
            $lines[] = 'Memiliki modal komunikasi dan koordinasi yang baik untuk menjembatani kebutuhan pimpinan dan tim lapangan.';
        } else {
            $lines[] = 'Cenderung berhati-hati dalam komunikasi sehingga perlu penguatan saat memimpin koordinasi lintas pihak.';
        }
    } elseif ($scores['social']['score'] >= 65) {
        $lines[] = 'Memiliki kecenderungan komunikatif dan relatif mudah berinteraksi dengan tim.';
    } else {
        $lines[] = 'Cenderung bekerja dengan gaya observatif dan tidak terlalu ekspresif secara sosial.';
    }

    /* =========================================================
       FINAL TONE
       ========================================================= */
    if ($finalDecision['decision'] === 'recommended') {
        $lines[] = $profile === 'assistant_manager'
            ? 'Secara keseluruhan, profil psikologis mendukung untuk dipertimbangkan pada peran General Affair yang menuntut SOP, koordinasi, dan akuntabilitas.'
            : 'Secara keseluruhan, profil psikologis mendukung untuk dipertimbangkan pada peran yang membutuhkan tanggung jawab dan kepercayaan.';
    } elseif ($finalDecision['decision'] === 'not_recommended') {
        $lines[] = $profile === 'assistant_manager'
            ? 'Secara keseluruhan, profil psikologis menunjukkan risiko yang perlu dipertimbangkan serius sebelum diberi peran kepemimpinan operasional.'
            : 'Secara keseluruhan, profil psikologis menunjukkan beberapa risiko yang perlu dipertimbangkan secara serius.';
    } else {
        $lines[] = 'Profil psikologis menunjukkan kombinasi kekuatan dan area pengembangan yang perlu dievaluasi lebih lanjut.';
    }

    return implode(' ', $lines);
}
