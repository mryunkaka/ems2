<?php

/**
 * =========================================================
 * AI TEST SUBMIT — IMPROVED VERSION
 * Roxwood Hospital Recruitment System
 * =========================================================
 * - Validasi lengkap sebelum proses
 * - Scoring yang lebih balanced
 * - Honesty detection yang lebih fair
 * - Proper status management
 * =========================================================
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/../actions/ai_scoring_engine.php';
require_once __DIR__ . '/../actions/status_validator.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* =========================================================
   VALIDASI REQUEST
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit('Forbidden');
}

$applicantId = (int)($_POST['applicant_id'] ?? 0);
if ($applicantId <= 0) {
    http_response_code(400);
    exit('Applicant ID invalid');
}

/* =========================================================
   CEK DOUBLE SUBMIT
   ========================================================= */
$stmt = $pdo->prepare("SELECT id FROM ai_test_results WHERE applicant_id = ?");
$stmt->execute([$applicantId]);
if ($stmt->fetch()) {
    // Sudah pernah submit
    header('Location: recruitment_done.php');
    exit;
}

/* =========================================================
   AMBIL DATA DARI FORM REKRUTMEN
   ========================================================= */
$hasRecruitmentTypeColumn = ems_column_exists($pdo, 'medical_applicants', 'recruitment_type');
$stmt = $pdo->prepare("
    SELECT
        " . ($hasRecruitmentTypeColumn ? "recruitment_type," : "") . "
        medical_experience,
        city_duration,
        online_schedule,
        other_city_responsibility,
        motivation,
        work_principle,
        academy_ready,
        rule_commitment,
        duty_duration
    FROM medical_applicants
    WHERE id = ?
");
$stmt->execute([$applicantId]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$applicant) {
    http_response_code(404);
    exit('Applicant not found');
}

$sessionTrackMap = $_SESSION['recruitment_track_map'] ?? [];
$requestTrack = ems_normalize_recruitment_type($_POST['recruitment_type'] ?? '');
$sessionTrack = ems_normalize_recruitment_type($sessionTrackMap[(string)$applicantId] ?? '');
$dbTrack = ems_normalize_recruitment_type($applicant['recruitment_type'] ?? 'medical_candidate');
$profileType = $hasRecruitmentTypeColumn
    ? $dbTrack
    : ($requestTrack !== 'medical_candidate' ? $requestTrack : ($sessionTrack !== 'medical_candidate' ? $sessionTrack : $dbTrack));
$questionBank = ems_recruitment_profile($profileType);
$selectedQuestions = ems_recruitment_questions_for_applicant($profileType, $applicantId);
$questionIds = array_map('intval', array_keys($selectedQuestions));
$questionCount = count($questionIds);

/* =========================================================
   AMBIL JAWABAN AI TEST
   ========================================================= */
$answers = [];
foreach ($questionIds as $questionId) {
    $answers[$questionId] = $_POST['q' . $questionId] ?? null;
    if ($answers[$questionId] === null) {
        http_response_code(400);
        exit('Pertanyaan tidak lengkap');
    }
}

/* =========================================================
   HITUNG DURASI
   ========================================================= */
$startTime = (int)($_POST['start_time'] ?? 0);
$endTime   = (int)($_POST['end_time'] ?? time());
$duration = (int)($_POST['duration_seconds'] ?? max(0, $endTime - $startTime));

/* =========================================================
   AI PSYCHOMETRIC ENGINE (NEW)
   ========================================================= */

// 1. Hitung skor tiap trait
$traitItems = $profileType === 'assistant_manager'
    ? ems_assistant_manager_trait_items($questionIds)
    : getTraitItems($profileType);
$scores = [];

foreach ($traitItems as $trait => $items) {
    $scores[$trait] = calculateTraitScore($answers, $items);
}

// 2. Deteksi bias respon
$biasFlags = detectResponseBias($answers);
if ($profileType === 'assistant_manager') {
    $biasFlags = array_values(array_unique(array_merge($biasFlags, ems_assistant_manager_trap_flags($answers))));
}

// 3. Cross validation dengan form rekrutmen
$crossFlags = crossValidateWithForm($scores, $applicant, $profileType);

// 4. Final decision
$finalDecision = makeFinalDecision(
    $scores,
    $biasFlags,
    $crossFlags,
    $duration,
    $profileType
);

// 5. Narasi psikologis otomatis (UNTUK HR)
$personalityNarrative = generatePsychologicalNarrative(
    $scores,
    $finalDecision,
    $profileType
);

/* =========================================================
   SIMPAN KE DATABASE (FINAL & CLEAN)
   ========================================================= */
$pdo->beginTransaction();

try {

    // Total score final memakai composite score agar pola jawab terlalu cepat / bias
    // ikut menurunkan skor, bukan hanya memengaruhi keputusan.
    $totalScore = round((float)($finalDecision['composite_score'] ?? $finalDecision['average_score'] ?? 0), 2);

    // INSERT HASIL AI TEST
    $stmt = $pdo->prepare("
        INSERT INTO ai_test_results (
            applicant_id,
            answers_json,
            duration_seconds,
            score_total,

            focus_score,
            consistency_score,
            social_score,
            attitude_score,
            loyalty_score,
            honesty_score,

            risk_flags,
            personality_summary,
            decision
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $applicantId,
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        $duration,
        $totalScore,

        (int)$scores['focus']['score'],
        (int)$scores['consistency']['score'],
        (int)$scores['social']['score'],
        (int)$scores['emotional_stability']['score'], // attitude_score
        (int)$scores['obedience']['score'],           // loyalty_score
        (int)$scores['honesty_humility']['score'],

        json_encode([
            'bias'  => $biasFlags,
            'cross' => $crossFlags
        ], JSON_UNESCAPED_UNICODE),

        $personalityNarrative,
        $finalDecision['decision']
    ]);

    // UPDATE STATUS APPLICANT
    $stmt = $pdo->prepare("
        UPDATE medical_applicants
        SET status = 'ai_completed'
        WHERE id = ?
    ");
    $stmt->execute([$applicantId]);

    // COMMIT SEKALI SAJA
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    require_once __DIR__ . '/../config/error_logger.php';
    logRecruitmentError('AI_TEST_SUBMIT', $e);

    http_response_code(500);
    exit('AI Test save failed');
}


/* =========================================================
   REDIRECT SELESAI
   ========================================================= */
header('Location: recruitment_done.php');
exit;
