<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/interview_hybrid_scoring.php';

function ems_candidate_can_manage_interviews(array $user): bool
{
    $name = (string)($user['full_name'] ?? $user['name'] ?? '');
    if (ems_is_programmer_roxwood_name($name)) {
        return true;
    }

    return ems_normalize_role((string)($user['role'] ?? '')) === 'head manager'
        && ems_normalize_division((string)($user['division'] ?? '')) === 'Human Resource';
}

function ems_candidate_interview_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-z_]+$/i', $table)) {
        return false;
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    return ems_table_exists($pdo, $table);
}

function ems_candidate_interview_count(PDO $pdo, int $applicantId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT hr_id) FROM applicant_interview_scores WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    return (int)$stmt->fetchColumn();
}

function ems_candidate_recalculate_interview_result(PDO $pdo, int $applicantId): array
{
    $totalHr = ems_candidate_interview_count($pdo, $applicantId);
    if ($totalHr < 1) {
        throw new RuntimeException('Tidak ada sesi interview untuk dihitung ulang.');
    }

    $calculation = calculateHybridInterviewScore($pdo, $applicantId);
    $stmt = $pdo->prepare('SELECT id FROM applicant_interview_results WHERE applicant_id = ? LIMIT 1');
    $stmt->execute([$applicantId]);
    $existingId = $stmt->fetchColumn();

    if ($existingId !== false) {
        $stmt = $pdo->prepare('
            UPDATE applicant_interview_results
            SET total_hr = ?, average_score = ?, final_grade = ?, ml_flags = ?, ml_confidence = ?,
                is_locked = 0, locked_at = NULL, calculated_at = CURRENT_TIMESTAMP
            WHERE applicant_id = ?
        ');
        $stmt->execute([
            $totalHr,
            $calculation['final_score'],
            $calculation['final_grade'],
            json_encode($calculation['ml_flags'], JSON_UNESCAPED_UNICODE),
            $calculation['ml_confidence'],
            $applicantId,
        ]);
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO applicant_interview_results
                (applicant_id, total_hr, average_score, final_grade, ml_flags, ml_confidence, is_locked, locked_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, NULL)
        ');
        $stmt->execute([
            $applicantId,
            $totalHr,
            $calculation['final_score'],
            $calculation['final_grade'],
            json_encode($calculation['ml_flags'], JSON_UNESCAPED_UNICODE),
            $calculation['ml_confidence'],
        ]);
    }

    return [
        'total_hr' => $totalHr,
        'average_score' => $calculation['final_score'],
        'final_grade' => $calculation['final_grade'],
    ];
}

/**
 * Recalculate a single session, or delete one selected session when multiple
 * interviewers exist, then recalculate and reopen the interview stage.
 */
function ems_candidate_refresh_interview(PDO $pdo, int $applicantId, ?int $interviewerId = null): array
{
    if ($applicantId <= 0) {
        throw new InvalidArgumentException('Kandidat tidak valid.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare('SELECT status FROM medical_applicants WHERE id = ?' . $lockSuffix);
        $stmt->execute([$applicantId]);
        $status = $stmt->fetchColumn();
        if ($status === false || !in_array((string)$status, ['interview', 'final_review'], true)) {
            throw new RuntimeException('Kandidat tidak berada pada tahap interview yang dapat dihitung ulang.');
        }

        $totalHr = ems_candidate_interview_count($pdo, $applicantId);
        if ($totalHr < 1) {
            throw new RuntimeException('Tidak ada sesi interview untuk dihitung ulang.');
        }

        $deleted = false;
        $reset = false;
        if ($totalHr > 1) {
            if (($interviewerId ?? 0) <= 0) {
                throw new InvalidArgumentException('Pilih interviewer yang sesi interview-nya akan dihapus.');
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM applicant_interview_scores WHERE applicant_id = ? AND hr_id = ?');
            $stmt->execute([$applicantId, $interviewerId]);
            if ((int)$stmt->fetchColumn() < 1) {
                throw new RuntimeException('Sesi interviewer tidak ditemukan. Muat ulang halaman dan coba lagi.');
            }

            foreach (['applicant_interview_question_responses', 'applicant_interview_question_packs'] as $table) {
                if (ems_candidate_interview_table_exists($pdo, $table)) {
                    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE applicant_id = ? AND hr_id = ?");
                    $stmt->execute([$applicantId, $interviewerId]);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM applicant_interview_scores WHERE applicant_id = ? AND hr_id = ?');
            $stmt->execute([$applicantId, $interviewerId]);
            $deleted = true;
        } else {
            $stmt = $pdo->prepare('SELECT hr_id FROM applicant_interview_scores WHERE applicant_id = ? LIMIT 1');
            $stmt->execute([$applicantId]);
            $singleInterviewerId = (int)$stmt->fetchColumn();
            if ($singleInterviewerId <= 0) {
                throw new RuntimeException('Sesi interviewer tidak ditemukan. Muat ulang halaman dan coba lagi.');
            }

            if (ems_candidate_interview_table_exists($pdo, 'applicant_interview_question_responses')) {
                $stmt = $pdo->prepare('DELETE FROM applicant_interview_question_responses WHERE applicant_id = ? AND hr_id = ?');
                $stmt->execute([$applicantId, $singleInterviewerId]);
            }

            $stmt = $pdo->prepare('DELETE FROM applicant_interview_scores WHERE applicant_id = ? AND hr_id = ?');
            $stmt->execute([$applicantId, $singleInterviewerId]);
            $stmt = $pdo->prepare('DELETE FROM applicant_interview_results WHERE applicant_id = ?');
            $stmt->execute([$applicantId]);
            $reset = true;
        }

        $result = $reset ? [] : ems_candidate_recalculate_interview_result($pdo, $applicantId);
        $stmt = $pdo->prepare("UPDATE medical_applicants SET status = 'interview' WHERE id = ?");
        $stmt->execute([$applicantId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return $result + ['deleted' => $deleted, 'reset' => $reset];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
