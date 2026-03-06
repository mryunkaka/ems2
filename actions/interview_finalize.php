<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/interview_hybrid_scoring.php';

/**
 * Finalize & Lock Interview (MANUAL BY MANAGER)
 */
function finalizeInterview(PDO $pdo, int $applicantId): void
{
    $result = calculateHybridInterviewScore($pdo, $applicantId);

    $pdo->beginTransaction();

    try {

        // Hitung jumlah HR yang menilai
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT hr_id)
            FROM applicant_interview_scores
            WHERE applicant_id = ?
        ");
        $stmt->execute([$applicantId]);
        $totalHr = (int)$stmt->fetchColumn();

        if ($totalHr < 2) {
            throw new Exception('Interview harus dinilai minimal oleh 2 HR');
        }

        // Simpan hasil final interview
        $stmt = $pdo->prepare("
            INSERT INTO applicant_interview_results
            (
                applicant_id,
                total_hr,
                average_score,
                final_grade,
                ml_flags,
                ml_confidence,
                is_locked,
                locked_at
            )
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                total_hr = VALUES(total_hr),
                average_score = VALUES(average_score),
                final_grade = VALUES(final_grade),
                ml_flags = VALUES(ml_flags),
                ml_confidence = VALUES(ml_confidence),
                is_locked = 1,
                locked_at = NOW()
        ");

        $stmt->execute([
            $applicantId,
            $totalHr,
            $result['final_score'],
            $result['final_grade'],
            json_encode($result['ml_flags'], JSON_UNESCAPED_UNICODE),
            $result['ml_confidence']
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
