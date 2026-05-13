<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';

const EMS_PUBLIC_RECRUITMENT_GATE_SESSION = 'ems_public_recruitment_gate';

function ems_public_recruitment_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function ems_public_recruitment_gate_clear(): void
{
    ems_public_recruitment_start_session();
    unset($_SESSION[EMS_PUBLIC_RECRUITMENT_GATE_SESSION]);
}

function ems_public_recruitment_gate_get(): ?array
{
    ems_public_recruitment_start_session();
    $gate = $_SESSION[EMS_PUBLIC_RECRUITMENT_GATE_SESSION] ?? null;
    return is_array($gate) ? $gate : null;
}

function ems_public_recruitment_gate_set(array $gate): void
{
    ems_public_recruitment_start_session();
    $_SESSION[EMS_PUBLIC_RECRUITMENT_GATE_SESSION] = $gate;
}

function ems_public_recruitment_find_applicant(PDO $pdo, string $citizenId): ?array
{
    $citizenId = ems_normalize_citizen_id($citizenId);
    if ($citizenId === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.citizen_id,
            m.status,
            COALESCE(NULLIF(m.recruitment_type, ''), 'medical_candidate') AS recruitment_type,
            EXISTS(
                SELECT 1
                FROM ai_test_results r
                WHERE r.applicant_id = m.id
            ) AS has_ai_result
        FROM medical_applicants m
        WHERE UPPER(TRIM(m.citizen_id)) = ?
        ORDER BY m.id DESC
        LIMIT 1
    ");
    $stmt->execute([$citizenId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['id'] = (int)($row['id'] ?? 0);
    $row['has_ai_result'] = (int)($row['has_ai_result'] ?? 0) === 1;
    $row['citizen_id'] = ems_normalize_citizen_id($row['citizen_id'] ?? $citizenId);
    $row['status'] = trim((string)($row['status'] ?? ''));
    $row['recruitment_type'] = ems_normalize_recruitment_type($row['recruitment_type'] ?? 'medical_candidate');

    return $row;
}

function ems_public_recruitment_build_gate(PDO $pdo, string $citizenId): array
{
    $citizenId = ems_normalize_citizen_id($citizenId);
    $applicant = ems_public_recruitment_find_applicant($pdo, $citizenId);

    $stage = 'form';
    $applicantId = 0;
    $recruitmentType = 'medical_candidate';

    if ($applicant) {
        $applicantId = (int)$applicant['id'];
        $recruitmentType = $applicant['recruitment_type'];
        $stage = ($applicant['status'] === 'ai_test' && !$applicant['has_ai_result']) ? 'ai_test' : 'done';
    }

    return [
        'citizen_id' => $citizenId,
        'applicant_id' => $applicantId,
        'recruitment_type' => $recruitmentType,
        'stage' => $stage,
        'updated_at' => time(),
    ];
}

function ems_public_recruitment_stage_url(array $gate): string
{
    $stage = (string)($gate['stage'] ?? 'form');
    $recruitmentType = ems_normalize_recruitment_type($gate['recruitment_type'] ?? 'medical_candidate');
    $applicantId = (int)($gate['applicant_id'] ?? 0);

    if ($stage === 'ai_test' && $applicantId > 0) {
        return ems_url('/public/ai_test.php?applicant_id=' . $applicantId . '&track=' . urlencode($recruitmentType));
    }

    if ($stage === 'done') {
        return ems_url('/public/recruitment_done.php');
    }

    return ems_url('/public/recruitment_form.php');
}

function ems_public_recruitment_redirect_for_gate(array $gate): void
{
    header('Location: ' . ems_public_recruitment_stage_url($gate));
    exit;
}

function ems_public_recruitment_require_gate_stage(string $expectedStage): array
{
    global $pdo;

    $gate = ems_public_recruitment_gate_get();
    if (!$gate || empty($gate['citizen_id'])) {
        header('Location: ' . ems_url('/public/index.php'));
        exit;
    }

    $freshGate = ems_public_recruitment_build_gate($pdo, (string)$gate['citizen_id']);
    ems_public_recruitment_gate_set($freshGate);

    if (($freshGate['stage'] ?? '') !== $expectedStage) {
        ems_public_recruitment_redirect_for_gate($freshGate);
    }

    return $freshGate;
}

function ems_public_recruitment_require_ai_test_access(int $applicantId): array
{
    $gate = ems_public_recruitment_require_gate_stage('ai_test');

    if ((int)($gate['applicant_id'] ?? 0) !== $applicantId) {
        header('Location: ' . ems_url('/public/index.php'));
        exit;
    }

    return $gate;
}
