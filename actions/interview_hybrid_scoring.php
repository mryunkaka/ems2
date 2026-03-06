<?php

/**
 * =====================================================
 * HYBRID INTERVIEW SCORING ENGINE (FINAL)
 * =====================================================
 * - Multi HR
 * - Weighted criteria
 * - Consistency penalty
 * - Output score: 0–100
 * =====================================================
 */

function calculateHybridInterviewScore(PDO $pdo, int $applicantId): array
{
    $stmt = $pdo->prepare("
        SELECT 
            s.hr_id,
            s.score,
            c.weight
        FROM applicant_interview_scores s
        JOIN interview_criteria c ON c.id = s.criteria_id
        WHERE s.applicant_id = ?
    ");
    $stmt->execute([$applicantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        throw new Exception('Belum ada data interview');
    }

    /* ===============================
       GROUP PER HR
       =============================== */
    $perHr = [];
    foreach ($rows as $r) {
        $hr = $r['hr_id'];
        $perHr[$hr]['sum']    = ($perHr[$hr]['sum'] ?? 0) + ($r['score'] * $r['weight']);
        $perHr[$hr]['weight'] = ($perHr[$hr]['weight'] ?? 0) + $r['weight'];
    }

    $hrAverages = [];
    foreach ($perHr as $hr => $v) {
        $hrAverages[$hr] = $v['sum'] / $v['weight']; // 1–5
    }

    /* ===============================
       BASE SCORE (1–5)
       =============================== */
    $baseScore = array_sum($hrAverages) / count($hrAverages);

    /* ===============================
       STANDARD DEVIATION
       =============================== */
    $variance = 0;
    foreach ($hrAverages as $avg) {
        $variance += pow($avg - $baseScore, 2);
    }
    $stdDev = sqrt($variance / count($hrAverages));

    /* ===============================
       CONSISTENCY FACTOR
       =============================== */
    $consistencyFactor = max(0.85, min(1.0, 1 - ($stdDev / 3)));

    /* ===============================
       FINAL SCORE (0–100)
       =============================== */
    $finalScore = round(($baseScore * 20) * $consistencyFactor, 2);

    /* ===============================
       FINAL GRADE
       =============================== */
    if ($finalScore >= 85) {
        $grade = 'sangat_baik';
    } elseif ($finalScore >= 70) {
        $grade = 'baik';
    } elseif ($finalScore >= 55) {
        $grade = 'sedang';
    } elseif ($finalScore >= 40) {
        $grade = 'buruk';
    } else {
        $grade = 'sangat_buruk';
    }

    /* ===============================
       ML FLAGS (INSIGHT)
       =============================== */
    $flags = [];
    if ($stdDev > 1.0) {
        $flags['score_variance'] = 'tinggi';
    } elseif ($stdDev > 0.5) {
        $flags['score_variance'] = 'sedang';
    } else {
        $flags['score_variance'] = 'rendah';
    }

    return [
        'base_score'    => round($baseScore, 2), // 1–5 (informasi)
        'final_score'   => $finalScore,          // 0–100 (dipakai sistem)
        'final_grade'   => $grade,
        'std_dev'       => round($stdDev, 2),
        'consistency'   => $consistencyFactor,
        'ml_flags'      => $flags,
        'ml_confidence' => round(100 * $consistencyFactor, 2)
    ];
}
