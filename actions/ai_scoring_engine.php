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
function getTraitItems(): array
{
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

    if ($maxRun >= 10) {
        $flags[] = 'pattern_answering';
    }

    return $flags;
}

/* =========================================================
   5. CROSS VALIDATION WITH FORM
   ========================================================= */
function crossValidateWithForm(array $scores, array $applicant): array
{
    $flags = [];

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
   6. FINAL DECISION ENGINE (HEXACO INCLUDED)
   ========================================================= */
function makeFinalDecision(
    array $scores,
    array $biasFlags,
    array $crossFlags,
    int $durationSeconds
): array {
    $avg = array_sum(array_column($scores, 'score')) / count($scores);

    $decision = 'consider';
    $confidence = 'medium';
    $reasons = [];

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

    if (!$reasons) {
        $reasons[] = 'Perlu evaluasi lanjutan oleh HR';
    }

    return [
        'decision'        => $decision,
        'confidence'      => $confidence,
        'average_score'   => round($avg, 2),
        'honesty_score'   => $scores['honesty_humility']['score'] ?? null,
        'bias_flags'      => $biasFlags,
        'cross_flags'     => $crossFlags,
        'duration_minute' => round($durationSeconds / 60, 1),
    ];
}

function generatePsychologicalNarrative(array $scores, array $finalDecision): string
{
    $lines = [];

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
    if ($scores['focus']['score'] >= 65 && $scores['consistency']['score'] >= 65) {
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
    if ($scores['social']['score'] >= 65) {
        $lines[] = 'Memiliki kecenderungan komunikatif dan relatif mudah berinteraksi dengan tim.';
    } else {
        $lines[] = 'Cenderung bekerja dengan gaya observatif dan tidak terlalu ekspresif secara sosial.';
    }

    /* =========================================================
       FINAL TONE
       ========================================================= */
    if ($finalDecision['decision'] === 'recommended') {
        $lines[] = 'Secara keseluruhan, profil psikologis mendukung untuk dipertimbangkan pada peran yang membutuhkan tanggung jawab dan kepercayaan.';
    } elseif ($finalDecision['decision'] === 'not_recommended') {
        $lines[] = 'Secara keseluruhan, profil psikologis menunjukkan beberapa risiko yang perlu dipertimbangkan secara serius.';
    } else {
        $lines[] = 'Profil psikologis menunjukkan kombinasi kekuatan dan area pengembangan yang perlu dievaluasi lebih lanjut.';
    }

    return implode(' ', $lines);
}
