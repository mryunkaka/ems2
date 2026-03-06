<?php

/**
 * Validasi transisi status kandidat
 */
function validateStatusTransition(string $currentStatus, string $newStatus): bool
{
    // Allow staying in same status (re-processing)
    if ($currentStatus === $newStatus) {
        return true;
    }

    $allowedTransitions = [
        'submitted'     => ['ai_completed'],
        'ai_completed'  => ['interview'],
        'interview'     => ['final_review'],
        'final_review'  => ['accepted', 'rejected'],
        'accepted'      => [],
        'rejected'      => [],
    ];

    return in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true);
}

/**
 * Update status dengan validasi
 */
function updateApplicantStatus(PDO $pdo, int $applicantId, string $newStatus): bool
{
    $stmt = $pdo->prepare("SELECT status FROM medical_applicants WHERE id = ?");
    $stmt->execute([$applicantId]);
    $currentStatus = $stmt->fetchColumn();

    if (!$currentStatus) {
        throw new Exception('Applicant not found');
    }

    if (!validateStatusTransition($currentStatus, $newStatus)) {
        throw new Exception("Invalid status transition: {$currentStatus} -> {$newStatus}");
    }

    $stmt = $pdo->prepare("UPDATE medical_applicants SET status = ? WHERE id = ?");
    return $stmt->execute([$newStatus, $applicantId]);
}
