<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

if (!isset($_GET['range'])) {
    $_GET['range'] = 'week4';
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/../config/recruitment_settings.php';
require_once __DIR__ . '/../actions/ai_scoring_engine.php';

$user = $_SESSION['user_rh'] ?? [];
$role = $user['role'] ?? '';
$userDivision = ems_normalize_division($user['division'] ?? '');

// HARD GUARD
if (strtolower($role) === 'staff') {
    header('Location: dashboard.php');
    exit;
}

$listRecruitmentType = 'medical_candidate';
$pageTitle = 'Calon Kandidat';

function candidateCanHardDelete(array $user, string $userDivision): bool
{
    if (in_array($userDivision, ['Human Capital', 'Human Resource', 'Executive'], true)) {
        return true;
    }

    $name = (string)($user['full_name'] ?? $user['name'] ?? '');
    return ems_is_programmer_roxwood_name($name);
}

function candidateCanManageRecruitmentSettings(array $user, string $userDivision): bool
{
    if (in_array($userDivision, ['Human Resource', 'Executive'], true)) {
        return true;
    }

    $name = (string)($user['full_name'] ?? $user['name'] ?? '');
    return ems_is_programmer_roxwood_name($name);
}

function candidateCanEditFinalDecision(array $user, string $userDivision): bool
{
    if (in_array($userDivision, ['Human Resource', 'Human Capital', 'Executive'], true)) {
        return true;
    }

    $name = (string)($user['full_name'] ?? $user['name'] ?? '');
    return ems_is_programmer_roxwood_name($name);
}

function candidateDecisionActorName(array $user): string
{
    $fullName = trim((string)($user['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    $name = trim((string)($user['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return 'Manager';
}

function candidateGenerateMedicalCode(int $userId, string $fullName, int $batch): string
{
    if ($batch < 1 || $batch > 26) {
        throw new Exception('Batch tidak valid untuk generate kode medis.');
    }

    $batchCode = chr(64 + $batch);
    $idPart = str_pad((string)$userId, 2, '0', STR_PAD_LEFT);
    $parts = preg_split('/\s+/', strtoupper(trim($fullName)));
    $firstName = $parts[0] ?? '';
    $lastName = $parts[count($parts) - 1] ?? '';
    $letters = substr($firstName, 0, 2) . substr($lastName, 0, 2);

    $numberPart = '';
    foreach (str_split($letters) as $char) {
        if ($char >= 'A' && $char <= 'Z') {
            $numberPart .= str_pad((string)(ord($char) - 64), 2, '0', STR_PAD_LEFT);
        }
    }

    return 'RH' . $batchCode . '-' . $idPart . $numberPart;
}

function candidateGenerateUserFolder(int $userId, ?string $kodeNomorIndukRs = null): string
{
    $suffix = $kodeNomorIndukRs ? '-' . strtolower($kodeNomorIndukRs) : '';
    return 'user_' . $userId . $suffix;
}

function candidateCopyApplicantDocsToUser(PDO $pdo, int $applicantId, int $userId, ?string $kodeNomorIndukRs = null): array
{
    $stmt = $pdo->prepare("
        SELECT document_type, file_path
        FROM applicant_documents
        WHERE applicant_id = ?
    ");
    $stmt->execute([$applicantId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$documents) {
        return [];
    }

    $folderName = candidateGenerateUserFolder($userId, $kodeNomorIndukRs);
    $baseDir = __DIR__ . '/../storage/user_docs/';
    $uploadDir = $baseDir . $folderName;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('Gagal membuat folder dokumen user.');
    }

    $columnMap = [
        'ktp_ic' => 'file_ktp',
        'skb' => 'file_skb',
        'sim' => 'file_sim',
    ];

    $copied = [];
    foreach ($documents as $document) {
        $type = (string)($document['document_type'] ?? '');
        $relativePath = trim((string)($document['file_path'] ?? ''));
        if ($relativePath === '' || !isset($columnMap[$type])) {
            continue;
        }

        $sourcePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/'));
        if ($sourcePath === false || !is_file($sourcePath)) {
            continue;
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'jpg';
        $destinationName = $columnMap[$type] . '.' . $extension;
        $destinationPath = $uploadDir . '/' . $destinationName;

        if (!copy($sourcePath, $destinationPath)) {
            throw new Exception('Gagal menyalin dokumen pelamar ke user.');
        }

        $copied[$columnMap[$type]] = 'storage/user_docs/' . $folderName . '/' . $destinationName;
    }

    return $copied;
}

function candidateCreateUserFromApplicant(PDO $pdo, array $candidate, string $recommendedPosition, int $batch): int
{
    $fullName = trim((string)($candidate['ic_name'] ?? ''));
    $citizenId = trim((string)($candidate['citizen_id'] ?? ''));
    $jenisKelamin = trim((string)($candidate['jenis_kelamin'] ?? ''));
    if ($fullName === '') {
        throw new Exception('Nama kandidat tidak valid untuk pembuatan user.');
    }

    $check = $pdo->prepare("SELECT id FROM user_rh WHERE full_name = ? LIMIT 1");
    $check->execute([$fullName]);
    if ($check->fetchColumn()) {
        throw new Exception('Akun user_rh dengan nama tersebut sudah ada.');
    }

    if ($citizenId !== '') {
        $checkCitizen = $pdo->prepare("SELECT id FROM user_rh WHERE citizen_id = ? LIMIT 1");
        $checkCitizen->execute([$citizenId]);
        if ($checkCitizen->fetchColumn()) {
            throw new Exception('Akun user_rh dengan citizen ID tersebut sudah ada.');
        }
    }

    if (!in_array($jenisKelamin, ['Laki-laki', 'Perempuan'], true)) {
        $jenisKelamin = null;
    }

    if ($batch < 1 || $batch > 26) {
        throw new Exception('Batch wajib diisi dan harus di antara 1 sampai 26.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_rh (
            full_name,
            citizen_id,
            jenis_kelamin,
            no_hp_ic,
            pin,
            role,
            division,
            position,
            batch,
            tanggal_masuk,
            is_verified,
            is_active
        ) VALUES (?, ?, ?, ?, ?, 'Staff', 'Medis', ?, ?, CURDATE(), 1, 1)
    ");
    $stmt->execute([
        $fullName,
        $citizenId !== '' ? $citizenId : null,
        $jenisKelamin,
        trim((string)($candidate['ic_phone'] ?? '')) ?: null,
        password_hash('0000', PASSWORD_BCRYPT),
        $recommendedPosition,
        $batch,
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    $generatedKode = candidateGenerateMedicalCode($newUserId, $fullName, $batch);
    $pdo->prepare("
        UPDATE user_rh
        SET kode_nomor_induk_rs = ?
        WHERE id = ?
    ")->execute([$generatedKode, $newUserId]);

    return $newUserId;
}

function candidateDeleteLinkedUser(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT file_ktp, file_skb, file_sim
        FROM user_rh
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $linkedUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$linkedUser) {
        return [];
    }

    $userFilePaths = [];
    foreach (['file_ktp', 'file_skb', 'file_sim'] as $column) {
        $userFilePaths[] = (string)($linkedUser[$column] ?? '');
    }

    $deleteStmt = $pdo->prepare("DELETE FROM user_rh WHERE id = ?");
    $deleteStmt->execute([$userId]);

    if ((int)$deleteStmt->rowCount() < 1) {
        throw new Exception('Gagal menghapus user hasil pelolosan kandidat.');
    }

    return $userFilePaths;
}

function candidateResolveLinkedUserId(PDO $pdo, array $candidate, ?int $existingLinkedUserId = null): int
{
    $existingLinkedUserId = (int)($existingLinkedUserId ?? 0);
    if ($existingLinkedUserId > 0) {
        return $existingLinkedUserId;
    }

    $citizenId = ems_normalize_citizen_id((string)($candidate['citizen_id'] ?? ''));
    if ($citizenId !== '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM user_rh
            WHERE citizen_id = ?
            ORDER BY id DESC
            LIMIT 2
        ");
        $stmt->execute([$citizenId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($rows) === 1) {
            return (int)($rows[0]['id'] ?? 0);
        }

        if (count($rows) > 1) {
            throw new Exception('Ditemukan lebih dari satu user_rh dengan citizen ID yang sama. Tentukan user kandidat secara manual.');
        }
    }

    $fullName = trim((string)($candidate['ic_name'] ?? ''));
    if ($fullName !== '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM user_rh
            WHERE full_name = ?
            ORDER BY id DESC
            LIMIT 2
        ");
        $stmt->execute([$fullName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($rows) === 1) {
            return (int)($rows[0]['id'] ?? 0);
        }

        if (count($rows) > 1) {
            throw new Exception('Ditemukan lebih dari satu user_rh dengan nama yang sama. Tentukan user kandidat secara manual.');
        }
    }

    return 0;
}

function candidateDeleteCleanupFilePaths(array $relativePaths): void
{
    $directories = [];

    foreach ($relativePaths as $relativePath) {
        $relativePath = trim((string)$relativePath);
        if ($relativePath === '') {
            continue;
        }

        $absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/'));
        if ($absolutePath === false || !is_file($absolutePath)) {
            continue;
        }

        @unlink($absolutePath);
        $directories[dirname($absolutePath)] = true;
    }

    foreach (array_keys($directories) as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        $items = @scandir($directory);
        if ($items === false) {
            continue;
        }

        $remaining = array_values(array_diff($items, ['.', '..']));
        if ($remaining === []) {
            @rmdir($directory);
        }
    }
}

function candidateDeletePermanently(PDO $pdo, int $applicantId): array
{
    $filePaths = [];
    $linkedUserId = 0;
    $linkedUserFilePaths = [];
    $existingFinalResult = '';

    if (ems_table_exists($pdo, 'applicant_documents')) {
        $stmt = $pdo->prepare("
            SELECT file_path
            FROM applicant_documents
            WHERE applicant_id = ?
        ");
        $stmt->execute([$applicantId]);
        $filePaths = array_map(
            static fn(array $row): string => (string)($row['file_path'] ?? ''),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    if (ems_column_exists($pdo, 'applicant_final_decisions', 'linked_user_id')) {
        $stmt = $pdo->prepare("
            SELECT fd.linked_user_id, fd.final_result, m.citizen_id, m.ic_name
            FROM applicant_final_decisions fd
            INNER JOIN medical_applicants m ON m.id = fd.applicant_id
            WHERE applicant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$applicantId]);
        $decisionRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $linkedUserId = candidateResolveLinkedUserId(
            $pdo,
            [
                'citizen_id' => (string)($decisionRow['citizen_id'] ?? ''),
                'ic_name' => (string)($decisionRow['ic_name'] ?? ''),
            ],
            (int)($decisionRow['linked_user_id'] ?? 0)
        );
        $existingFinalResult = (string)($decisionRow['final_result'] ?? '');
    }

    $pdo->beginTransaction();

    try {
        if ($existingFinalResult === 'lolos' && $linkedUserId <= 0) {
            throw new Exception('User kandidat lolos belum terhubung. Jalankan migration terbaru dan simpan ulang keputusan kandidat ini terlebih dahulu.');
        }

        if ($linkedUserId > 0) {
            $linkedUserFilePaths = candidateDeleteLinkedUser($pdo, $linkedUserId);
        }

        foreach ([
            'applicant_interview_question_responses',
            'applicant_interview_question_packs',
            'applicant_interview_scores',
            'applicant_interview_results',
            'applicant_final_decisions',
            'applicant_documents',
            'ai_test_results',
        ] as $table) {
            if (!ems_table_exists($pdo, $table)) {
                continue;
            }

            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE applicant_id = ?");
            $stmt->execute([$applicantId]);
        }

        $stmt = $pdo->prepare("DELETE FROM medical_applicants WHERE id = ?");
        $stmt->execute([$applicantId]);
        $deletedApplicants = (int)$stmt->rowCount();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    candidateDeleteCleanupFilePaths($filePaths);
    candidateDeleteCleanupFilePaths($linkedUserFilePaths);

    return [
        'deleted_applicants' => $deletedApplicants,
        'deleted_files' => count(array_filter(array_map('trim', array_merge($filePaths, $linkedUserFilePaths)))),
    ];
}

function candidateStatusMeta(string $status): array
{
    return match ($status) {
        'ai_completed' => ['label' => 'Menunggu', 'class' => 'badge-warning'],
        'interview' => ['label' => 'Interview', 'class' => 'badge-info'],
        'final_review' => ['label' => 'Final Review', 'class' => 'badge-info'],
        'accepted' => ['label' => 'Diterima', 'class' => 'badge-success'],
        'rejected' => ['label' => 'Ditolak', 'class' => 'badge-danger'],
        default => [
            'label' => ucwords(str_replace('_', ' ', $status)),
            'class' => 'badge-secondary',
        ],
    };
}

function candidateDecisionMeta(?string $decision): array
{
    $decision = (string)($decision ?? '');

    return match (strtolower($decision)) {
        'recommended' => ['label' => 'Direkomendasikan', 'class' => 'badge-success'],
        'not_recommended' => ['label' => 'Tidak Direkomendasikan', 'class' => 'badge-danger'],
        'follow_up_required' => ['label' => 'Perlu Tindak Lanjut', 'class' => 'badge-warning'],
        'lolos' => ['label' => 'Lolos', 'class' => 'badge-success'],
        'tidak_lolos' => ['label' => 'Tidak Lolos', 'class' => 'badge-danger'],
        'proceed' => ['label' => 'Lanjut Interview', 'class' => 'badge-info'],
        'reject' => ['label' => 'Ditolak Sistem', 'class' => 'badge-danger'],
        '' => ['label' => '-', 'class' => 'badge-secondary'],
        default => [
            'label' => ucwords(str_replace('_', ' ', $decision)),
            'class' => 'badge-secondary',
        ],
    };
}

function candidateRecomputedResult(array $row): array
{
    $answers = json_decode((string)($row['answers_json'] ?? ''), true);
    if (!is_array($answers) || $answers === []) {
        return [
            'ai_score' => (float)($row['ai_score'] ?? 0),
            'ai_decision' => (string)($row['ai_decision'] ?? ''),
        ];
    }

    $recruitmentType = ems_normalize_recruitment_type($row['recruitment_type'] ?? 'medical_candidate');
    $questionIds = array_map('intval', array_keys($answers));
    $traitItems = $recruitmentType === 'assistant_manager'
        ? ems_assistant_manager_trait_items($questionIds)
        : getTraitItems($recruitmentType);

    $scores = [];
    foreach ($traitItems as $trait => $items) {
        $scores[$trait] = calculateTraitScore($answers, $items);
    }

    $biasFlags = detectResponseBias($answers);
    if ($recruitmentType === 'assistant_manager') {
        $biasFlags = array_values(array_unique(array_merge($biasFlags, ems_assistant_manager_trap_flags($answers))));
    }

    $crossFlags = crossValidateWithForm($scores, $row, $recruitmentType);
    $finalDecision = makeFinalDecision($scores, $biasFlags, $crossFlags, (int)($row['duration_seconds'] ?? 0), $recruitmentType);

    return [
        'ai_score' => (float)($finalDecision['composite_score'] ?? $finalDecision['average_score'] ?? ($row['ai_score'] ?? 0)),
        'ai_decision' => (string)($finalDecision['decision'] ?? ($row['ai_decision'] ?? '')),
    ];
}

/* ===============================
   SELESAI INTERVIEW (DARI LIST)
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finish_interview'])) {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    $applicantId = (int)($_POST['applicant_id'] ?? 0);
    if ($applicantId <= 0) {
        exit('Invalid applicant');
    }

    $stmt = $pdo->prepare("
    SELECT COUNT(*)
        FROM (
            SELECT hr_id
            FROM applicant_interview_scores
            WHERE applicant_id = ?
            GROUP BY hr_id
        ) t
    ");
    $stmt->execute([$applicantId]);
    $totalHr = (int)$stmt->fetchColumn();

    if ($totalHr < 2) {
        header('Location: candidates.php?error=min_hr');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE medical_applicants
        SET status = 'final_review'
        WHERE id = ?
          AND status = 'interview'
    ");
    $stmt->execute([$applicantId]);

    header('Location: candidates.php?interview_done=1');
    exit;
}

/* ===============================
   KEPUTUSAN PASCA AI (TANPA INTERVIEW)
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_decision'])) {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    $applicantId = (int)($_POST['applicant_id'] ?? 0);
    $decision = $_POST['ai_decision'] ?? '';

    if ($applicantId <= 0 || !in_array($decision, ['proceed', 'reject'], true)) {
        exit('Invalid request');
    }

    if ($decision === 'proceed') {
        $stmt = $pdo->prepare("
            UPDATE medical_applicants
            SET status = 'interview'
            WHERE id = ?
              AND status = 'ai_completed'
        ");
        $stmt->execute([$applicantId]);
    }

    if ($decision === 'reject') {
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
            UPDATE medical_applicants
            SET status = 'rejected',
                rejection_stage = 'ai'
            WHERE id = ?
              AND status = 'ai_completed'
        ");
            $stmt->execute([$applicantId]);

            $stmt = $pdo->prepare("
            SELECT score_total
            FROM ai_test_results
            WHERE applicant_id = ?
        ");
            $stmt->execute([$applicantId]);
            $ai = $stmt->fetch(PDO::FETCH_ASSOC);

            $aiScore = (float)($ai['score_total'] ?? 0);

            $stmt = $pdo->prepare("
            INSERT INTO applicant_final_decisions
            (
                applicant_id,
                system_result,
                overridden,
                override_reason,
                final_result,
                decided_by
            ) VALUES (?, ?, 0, NULL, ?, ?)
        ");
            $stmt->execute([
                $applicantId,
                'tidak_lolos',
                'tidak_lolos',
                $user['name'] ?? 'System (AI)'
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            exit('Gagal memproses penolakan AI');
        }
    }

    header('Location: candidates.php');
    exit;
}

/* ===============================
   HAPUS PERMANEN KANDIDAT
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_candidate_permanently'])) {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    if (!candidateCanHardDelete($user, $userDivision)) {
        exit('Akses hapus permanen ditolak');
    }

    $applicantId = (int)($_POST['applicant_id'] ?? 0);
    if ($applicantId <= 0) {
        exit('Invalid applicant');
    }

    try {
        candidateDeletePermanently($pdo, $applicantId);
        unset($_SESSION['recruitment_track_map'][(string)$applicantId]);
        header('Location: candidates.php?deleted=1');
        exit;
    } catch (Throwable $e) {
        header('Location: candidates.php?delete_error=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_recruitment_portal_settings'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    if (!candidateCanManageRecruitmentSettings($user, $userDivision)) {
        exit('Akses setting rekrutmen ditolak');
    }

    $portalStatus = strtolower(trim((string)($_POST['portal_status'] ?? 'open')));
    $isOpen = $portalStatus !== 'close';
    $closedMessage = trim((string)($_POST['closed_message'] ?? ''));

    ems_recruitment_save_settings(
        $pdo,
        $isOpen,
        $closedMessage,
        (int)($user['id'] ?? 0)
    );

    header('Location: candidates.php?recruitment_settings_saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['override_rejected_candidate'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    if (!candidateCanEditFinalDecision($user, $userDivision)) {
        exit('Akses edit hasil kandidat ditolak');
    }

    $applicantId = (int)($_POST['applicant_id'] ?? 0);
    $targetFinalResult = trim((string)($_POST['target_final_result'] ?? ''));
    $overrideReason = trim((string)($_POST['override_reason'] ?? ''));
    $recommendedPosition = ems_normalize_position($_POST['recommended_position'] ?? '');
    $recommendedBatch = (int)($_POST['recommended_batch'] ?? 0);

    if ($applicantId <= 0) {
        exit('Invalid applicant');
    }

    if (!in_array($targetFinalResult, ['lolos', 'tidak_lolos'], true)) {
        header('Location: candidates.php?override_error=target');
        exit;
    }

    if ($overrideReason === '') {
        header('Location: candidates.php?override_error=reason');
        exit;
    }

    if ($targetFinalResult === 'lolos' && !ems_is_valid_position($recommendedPosition)) {
        header('Location: candidates.php?override_error=position');
        exit;
    }

    if ($targetFinalResult === 'lolos' && ($recommendedBatch < 1 || $recommendedBatch > 26)) {
        header('Location: candidates.php?override_error=batch');
        exit;
    }

    $hasDecisionLinkedUserId = ems_column_exists($pdo, 'applicant_final_decisions', 'linked_user_id');

    $candidateOverrideColumns = [
        'm.id',
        'm.ic_name',
        'm.ic_phone',
        'm.citizen_id',
        'm.jenis_kelamin',
        'm.status',
        'fd.id AS decision_id',
        'fd.system_result',
        'fd.final_result',
        'fd.recommended_position',
        'fd.recommended_batch',
    ];

    if ($hasDecisionLinkedUserId) {
        $candidateOverrideColumns[] = 'fd.linked_user_id';
    }

    if (ems_column_exists($pdo, 'applicant_final_decisions', 'revised_by')) {
        $candidateOverrideColumns[] = 'fd.revised_by';
    }

    if (ems_column_exists($pdo, 'applicant_final_decisions', 'revised_at')) {
        $candidateOverrideColumns[] = 'fd.revised_at';
    }

    $stmt = $pdo->prepare("
        SELECT " . implode(",\n        ", $candidateOverrideColumns) . "
        FROM medical_applicants m
        INNER JOIN applicant_final_decisions fd ON fd.applicant_id = m.id
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->execute([$applicantId]);
    $candidateOverride = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$candidateOverride) {
        header('Location: candidates.php?override_error=missing');
        exit;
    }

    $currentFinalResult = (string)($candidateOverride['final_result'] ?? '');
    if (!in_array($currentFinalResult, ['lolos', 'tidak_lolos'], true)) {
        header('Location: candidates.php?override_error=state');
        exit;
    }

    if ($currentFinalResult === $targetFinalResult) {
        header('Location: candidates.php?override_error=state');
        exit;
    }

    $pdo->beginTransaction();
    $linkedUserFilePaths = [];

    try {
        $lockDecisionColumns = ['id', 'final_result'];
        if ($hasDecisionLinkedUserId) {
            $lockDecisionColumns[] = 'linked_user_id';
        }

        $stmt = $pdo->prepare("
            SELECT " . implode(', ', $lockDecisionColumns) . "
            FROM applicant_final_decisions
            WHERE applicant_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$applicantId]);
        $lockedDecision = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lockedDecision || (string)($lockedDecision['final_result'] ?? '') !== $currentFinalResult) {
            throw new Exception('Status kandidat sudah berubah. Refresh halaman lalu coba lagi.');
        }

        $actorName = candidateDecisionActorName($user);
        $lockedLinkedUserId = candidateResolveLinkedUserId(
            $pdo,
            $candidateOverride,
            (int)($lockedDecision['linked_user_id'] ?? 0)
        );

        if ($targetFinalResult === 'lolos') {
            $newUserId = candidateCreateUserFromApplicant($pdo, $candidateOverride, $recommendedPosition, $recommendedBatch);
            $copiedDocuments = candidateCopyApplicantDocsToUser($pdo, $applicantId, $newUserId);

            if ($copiedDocuments) {
                $updateUserFields = [];
                $updateUserParams = [];
                foreach ($copiedDocuments as $column => $path) {
                    $updateUserFields[] = "{$column} = ?";
                    $updateUserParams[] = $path;
                }
                $updateUserParams[] = $newUserId;

                $pdo->prepare("
                    UPDATE user_rh
                    SET " . implode(', ', $updateUserFields) . "
                    WHERE id = ?
                ")->execute($updateUserParams);
            }
        } else {
            if ($lockedLinkedUserId <= 0) {
                throw new Exception('User hasil pelolosan kandidat tidak ditemukan. Pastikan kandidat lolos ini memang sudah dibuatkan user_rh.');
            }

            $linkedUserFilePaths = candidateDeleteLinkedUser($pdo, $lockedLinkedUserId);
            $newUserId = null;
            $recommendedPosition = '';
            $recommendedBatch = 0;
        }

        $updateFields = [
            "overridden = 1",
            "override_reason = ?",
            "final_result = ?",
            "recommended_position = ?",
            "recommended_batch = ?",
            "decided_by = ?",
            "decided_at = NOW()",
        ];
        $updateParams = [
            $overrideReason,
            $targetFinalResult,
            $targetFinalResult === 'lolos' ? $recommendedPosition : null,
            $targetFinalResult === 'lolos' ? $recommendedBatch : null,
            $actorName,
        ];

        if ($hasDecisionLinkedUserId) {
            $updateFields[] = "linked_user_id = ?";
            $updateParams[] = $targetFinalResult === 'lolos' ? $newUserId : null;
        }

        if (ems_column_exists($pdo, 'applicant_final_decisions', 'revised_by')) {
            $updateFields[] = "revised_by = ?";
            $updateParams[] = $actorName;
        }

        if (ems_column_exists($pdo, 'applicant_final_decisions', 'revised_at')) {
            $updateFields[] = "revised_at = NOW()";
        }

        $updateParams[] = $applicantId;

        $stmt = $pdo->prepare("
            UPDATE applicant_final_decisions
            SET " . implode(",\n                ", $updateFields) . "
            WHERE applicant_id = ?
        ");
        $stmt->execute($updateParams);

        $stmt = $pdo->prepare("
            UPDATE medical_applicants
            SET status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $targetFinalResult === 'lolos' ? 'accepted' : 'rejected',
            $applicantId
        ]);

        $pdo->commit();
        if ($linkedUserFilePaths !== []) {
            candidateDeleteCleanupFilePaths($linkedUserFilePaths);
        }
        header('Location: candidates.php?override_success=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        header('Location: candidates.php?override_error=' . urlencode($e->getMessage()));
        exit;
    }
}

$candidateSql = "
    SELECT
        m.id,
        m.ic_name,
        m.created_at,
        m.status,
        m.rejection_stage,
        m.rule_commitment,
        m.other_city_responsibility,
        m.motivation,
        m.recruitment_type,
        r.score_total AS ai_score,
        r.decision   AS ai_decision,
        r.answers_json,
        r.duration_seconds,
        ir.average_score   AS interview_score,
        ir.ml_confidence   AS confidence,
        ir.is_locked       AS interview_locked,
        fd.final_result,
        fd.override_reason,
        fd.decided_by,
        fd.decided_at,
        (
            SELECT COUNT(DISTINCT s.hr_id)
            FROM applicant_interview_scores s
            WHERE s.applicant_id = m.id
        ) AS total_hr,
        (
            SELECT GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ')
            FROM applicant_interview_scores s
            JOIN user_rh u ON u.id = s.hr_id
            WHERE s.applicant_id = m.id
        ) AS interviewers
    FROM medical_applicants m
    LEFT JOIN ai_test_results r
        ON r.applicant_id = m.id
    LEFT JOIN applicant_interview_results ir
        ON ir.applicant_id = m.id
    LEFT JOIN applicant_final_decisions fd
        ON fd.applicant_id = m.id
";
$hasDecisionRevisedBy = ems_column_exists($pdo, 'applicant_final_decisions', 'revised_by');
$hasDecisionRevisedAt = ems_column_exists($pdo, 'applicant_final_decisions', 'revised_at');
$hasDecisionLinkedUserId = ems_column_exists($pdo, 'applicant_final_decisions', 'linked_user_id');

if ($hasDecisionRevisedBy) {
    $candidateSql = str_replace('fd.decided_at,', "fd.decided_at,\n        fd.revised_by,\n", $candidateSql);
}

if ($hasDecisionRevisedAt) {
    $candidateSql = str_replace('fd.decided_at,', "fd.decided_at,\n        fd.revised_at,\n", $candidateSql);
}

if ($hasDecisionLinkedUserId) {
    $candidateSql = str_replace('fd.decided_at,', "fd.decided_at,\n        fd.linked_user_id,\n", $candidateSql);
}

$candidateParams = [];
$candidateWhere = [];

if (ems_column_exists($pdo, 'medical_applicants', 'recruitment_type')) {
    $candidateWhere[] = "COALESCE(NULLIF(m.recruitment_type, ''), 'medical_candidate') = ?";
    $candidateParams[] = $listRecruitmentType;
}

$candidateWhere[] = "m.created_at BETWEEN ? AND ?";
$candidateParams[] = $rangeStart;
$candidateParams[] = $rangeEnd;

if ($candidateWhere !== []) {
    $candidateSql .= " WHERE " . implode(' AND ', $candidateWhere);
}

$candidateSql .= " ORDER BY m.created_at DESC";
$stmt = $pdo->prepare($candidateSql);
$stmt->execute($candidateParams);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
$recruitmentPortalSettings = ems_recruitment_get_settings($pdo);
$recruitmentPortalIsOpen = (int)($recruitmentPortalSettings['is_open'] ?? 1) === 1;
$recruitmentPortalClosedMessage = (string)($recruitmentPortalSettings['closed_message'] ?? '');
$canManageRecruitmentSettings = candidateCanManageRecruitmentSettings($user, $userDivision);
$canEditFinalDecision = candidateCanEditFinalDecision($user, $userDivision);
$candidateExportQuery = http_build_query(array_filter([
    'range' => $_GET['range'] ?? 'week4',
    'from' => $_GET['from'] ?? '',
    'to' => $_GET['to'] ?? '',
], static fn($value): bool => $value !== ''));

?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">
            <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="page-title">Daftar Calon Kandidat</h1>
                <p class="page-subtitle">Monitoring hasil rekrutmen dan penilaian AI • <?= htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="flex items-center gap-2">
                <span class="<?= $recruitmentPortalIsOpen ? 'badge-success' : 'badge-danger' ?>">
                    <?= $recruitmentPortalIsOpen ? 'Rekrutmen Open' : 'Rekrutmen Close' ?>
                </span>
                <?php if ($canManageRecruitmentSettings): ?>
                    <button type="button" id="openRecruitmentSettingsModal" class="btn-secondary btn-sm">
                        <?= ems_icon('cog-6-tooth', 'h-4 w-4') ?>
                        <span>Setting Rekrutmen</span>
                    </button>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(ems_url('/dashboard/candidates_export.php') . ($candidateExportQuery !== '' ? '?' . $candidateExportQuery : ''), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary btn-sm">
                    <?= ems_icon('document-arrow-down', 'h-4 w-4') ?>
                    <span>Export Excel</span>
                </a>
                <a href="<?= htmlspecialchars(ems_url('/public/recruitment_form.php')) ?>" target="_blank" rel="noopener" class="btn-primary btn-sm">
                    <?= ems_icon('plus', 'h-4 w-4') ?>
                    <span>Kandidat Baru</span>
                </a>
            </div>
        </div>

        <div class="card card-section mb-4">
            <div class="card-header">Filter Rentang Tanggal</div>
            <div class="card-body">
                <form method="GET" id="candidateFilterForm" class="filter-bar">
                    <div class="filter-group">
                        <label>Rentang</label>
                        <select name="range" id="candidateRangeSelect" class="form-control">
                            <option value="week1" <?= ($_GET['range'] ?? '') === 'week1' ? 'selected' : '' ?>>3 Minggu Lalu</option>
                            <option value="week2" <?= ($_GET['range'] ?? '') === 'week2' ? 'selected' : '' ?>>2 Minggu Lalu</option>
                            <option value="week3" <?= ($_GET['range'] ?? '') === 'week3' ? 'selected' : '' ?>>Minggu Lalu</option>
                            <option value="week4" <?= ($_GET['range'] ?? 'week4') === 'week4' ? 'selected' : '' ?>>Minggu Ini</option>
                            <option value="custom" <?= ($_GET['range'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Awal</label>
                        <input type="date" name="from" value="<?= htmlspecialchars((string)($_GET['from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                    </div>
                    <div class="filter-group filter-custom">
                        <label>Tanggal Akhir</label>
                        <input type="date" name="to" value="<?= htmlspecialchars((string)($_GET['to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="form-control">
                    </div>
                    <div class="filter-group filter-action-end">
                        <button type="submit" class="btn btn-primary">Terapkan</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Calon Kandidat</div>

            <?php if (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>
                <div class="alert alert-success mb-4">
                    Data kandidat berhasil dihapus permanen.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['delete_error']) && $_GET['delete_error'] === '1'): ?>
                <div class="alert alert-danger mb-4">
                    Gagal menghapus permanen data kandidat.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['recruitment_settings_saved']) && $_GET['recruitment_settings_saved'] === '1'): ?>
                <div class="alert alert-success mb-4">
                    Setting open/close rekrutmen berhasil disimpan.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['override_success']) && $_GET['override_success'] === '1'): ?>
                <div class="alert alert-success mb-4">
                    Kandidat berhasil diubah dari tidak lolos menjadi lolos.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['override_error'])): ?>
                <div class="alert alert-danger mb-4">
                    <?php
                    $overrideError = (string)$_GET['override_error'];
                    echo match ($overrideError) {
                        'reason' => 'Alasan perubahan wajib diisi.',
                        'target' => 'Target hasil perubahan tidak valid.',
                        'position' => 'Posisi rekomendasi wajib dipilih.',
                        'batch' => 'Batch wajib diisi dengan angka 1 sampai 26.',
                        'missing' => 'Data keputusan kandidat tidak ditemukan.',
                        'state' => 'Status kandidat tidak valid untuk diubah.',
                        default => htmlspecialchars(urldecode($overrideError), ENT_QUOTES, 'UTF-8'),
                    };
                    ?>
                </div>
            <?php endif; ?>

            <div class="table-wrapper">
                <table id="candidateTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Status</th>
                            <th>Skor Tes</th>
                            <th>Skor Interview HR</th>
                            <th>Confidence</th>
                            <th>Skor Gabungan</th>
                            <th>Interviewer</th>
                            <th>Hasil</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $i => $c): ?>
                            <?php
                            $recomputedResult = candidateRecomputedResult($c);
                            $interviewScore = (float)($c['interview_score'] ?? 0);
                            $aiScore = (float)($recomputedResult['ai_score'] ?? $c['ai_score'] ?? 0);
                            $confidence = (float)($c['confidence'] ?? 0);
                            $combinedScore = '-';

                            if ((int)($c['interview_locked'] ?? 0) === 1) {
                                $combinedScore = round(
                                    ($interviewScore * 0.6) +
                                        ($aiScore * 0.3) +
                                        ($confidence * 0.1),
                                    2
                                );
                            }

                            $statusMeta = candidateStatusMeta((string)$c['status']);
                            $statusBadge = '<span class="' . htmlspecialchars($statusMeta['class']) . '">' . htmlspecialchars($statusMeta['label']) . '</span>';
                            $finalDecisionMeta = candidateDecisionMeta($c['final_result']);
                            $aiDecisionMeta = candidateDecisionMeta($recomputedResult['ai_decision'] ?? $c['ai_decision']);
                            $canHardDelete = candidateCanHardDelete($user, $userDivision);
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong>
                                        <a href="candidate_detail.php?id=<?= (int)$c['id'] ?>">
                                            <?= htmlspecialchars($c['ic_name']) ?>
                                        </a>
                                    </strong>
                                    <div class="meta-text">
                                        Daftar: <?= date('d M Y', strtotime($c['created_at'])) ?>
                                    </div>
                                </td>
                                <td><?= $statusBadge ?></td>
                                <td><?= $aiScore ?: '-' ?></td>
                                <td><?= $interviewScore ?: '-' ?></td>
                                <td><?= $confidence ? $confidence . '%' : '-' ?></td>
                                <td><strong><?= $combinedScore ?></strong></td>
                                <td class="text-sm leading-5 text-slate-700">
                                    <?php if ($c['interviewers']): ?>
                                        <?= htmlspecialchars($c['interviewers']) ?>
                                        <?php if ((int)$c['total_hr'] > 1): ?>
                                            <div class="meta-text">(<?= (int)$c['total_hr'] ?> Orang)</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['final_result']): ?>
                                        <span class="<?= htmlspecialchars($finalDecisionMeta['class']) ?>">
                                            <?= htmlspecialchars($finalDecisionMeta['label']) ?>
                                        </span>
                                        <?php
                                        $revisionActor = (string)($c['revised_by'] ?? '');
                                        $revisionAt = (string)($c['revised_at'] ?? '');
                                        $decisionActor = (string)($c['decided_by'] ?? '');
                                        $decisionAt = (string)($c['decided_at'] ?? '');
                                        ?>
                                        <?php if ($revisionActor !== '' && $revisionAt !== ''): ?>
                                            <div class="meta-text mt-1">
                                                Diubah oleh <?= htmlspecialchars($revisionActor) ?>
                                                pada <?= htmlspecialchars(date('d M Y H:i', strtotime($revisionAt))) ?>
                                            </div>
                                        <?php elseif ($decisionActor !== '' && $decisionAt !== '' && ($c['override_reason'] ?? '') !== '' && $c['final_result'] === 'lolos'): ?>
                                            <div class="meta-text mt-1">
                                                Diputuskan oleh <?= htmlspecialchars($decisionActor) ?>
                                                pada <?= htmlspecialchars(date('d M Y H:i', strtotime($decisionAt))) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="<?= htmlspecialchars($aiDecisionMeta['class']) ?>">
                                            <?= htmlspecialchars($aiDecisionMeta['label']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-cell">
                                    <div class="candidate-action-stack">
                                    <?php if ($c['status'] === 'ai_completed'): ?>
                                        <form method="post">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="ai_decision" value="proceed">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn-primary btn-sm action-icon-btn candidate-action-btn" onclick="return confirm('Lanjutkan ke tahap wawancara?')" title="Lanjut ke wawancara" aria-label="Lanjut ke wawancara">
                                                <?= ems_icon('arrow-right', 'h-4 w-4') ?>
                                            </button>
                                        </form>

                                        <form method="post">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="ai_decision" value="reject">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn-danger btn-sm action-icon-btn candidate-action-btn" onclick="return confirm('Tolak kandidat tanpa proses wawancara?')" title="Tolak kandidat" aria-label="Tolak kandidat">
                                                <?= ems_icon('x-mark', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (in_array($c['status'], ['interview'], true)): ?>
                                        <a href="candidate_interview_multi.php?id=<?= (int)$c['id'] ?>" class="btn-primary btn-sm action-icon-btn candidate-action-btn" title="Interview kandidat" aria-label="Interview kandidat">
                                            <?= ems_icon('microphone', 'h-4 w-4') ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($c['status'] === 'interview'): ?>
                                        <form method="post">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="finish_interview" value="1">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn-warning btn-sm action-icon-btn btn-finish-interview candidate-action-btn" data-total-hr="<?= (int)$c['total_hr'] ?>" title="Selesaikan interview" aria-label="Selesaikan interview">
                                                <?= ems_icon('check-circle', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($c['status'] === 'final_review' || in_array($c['status'], ['accepted', 'rejected'], true)): ?>
                                        <a href="candidate_decision.php?id=<?= (int)$c['id'] ?>" class="btn-success btn-sm action-icon-btn candidate-action-btn" title="Lihat keputusan kandidat" aria-label="Lihat keputusan kandidat">
                                            <?= ems_icon('check-badge', 'h-4 w-4') ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canEditFinalDecision && in_array((string)($c['final_result'] ?? ''), ['lolos', 'tidak_lolos'], true)): ?>
                                        <button
                                            type="button"
                                            class="btn-warning btn-sm action-icon-btn candidate-action-btn open-override-modal"
                                            data-applicant-id="<?= (int)$c['id'] ?>"
                                            data-applicant-name="<?= htmlspecialchars($c['ic_name'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-current-final-result="<?= htmlspecialchars((string)($c['final_result'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-override-reason="<?= htmlspecialchars((string)($c['override_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            title="Edit hasil kandidat"
                                            aria-label="Edit hasil kandidat">
                                            <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canHardDelete): ?>
                                        <form method="post">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="delete_candidate_permanently" value="1">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button
                                                type="submit"
                                                class="btn-danger btn-sm action-icon-btn candidate-action-btn"
                                                onclick="return confirm('Hapus permanen kandidat ini?\n\nSemua data rekrutmen, hasil AI, interview, keputusan final, dan dokumen upload akan dihapus total dan tidak bisa dikembalikan.')"
                                                title="Hapus permanen kandidat"
                                                aria-label="Hapus permanen kandidat">
                                                <?= ems_icon('trash', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var rangeSelect = document.getElementById('candidateRangeSelect');
    var customGroups = document.querySelectorAll('#candidateFilterForm .filter-custom');

    if (!rangeSelect || !customGroups.length) {
        return;
    }

    function syncCandidateRangeFilter() {
        var isCustom = rangeSelect.value === 'custom';
        customGroups.forEach(function (group) {
            group.style.display = isCustom ? '' : 'none';
        });
    }

    rangeSelect.addEventListener('change', syncCandidateRangeFilter);
    syncCandidateRangeFilter();
});
</script>

<div id="overrideRejectedCandidateModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div>
                <div class="modal-title">Ubah Tidak Lolos Menjadi Lolos</div>
                <div class="meta-text mt-1">Tombol edit bisa dipakai dua arah dan akan menyimpan alasan perubahan, siapa yang mengubah, dan waktu perubahan.</div>
            </div>
            <button type="button" class="modal-close-btn" data-close-override-modal aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="post" class="modal-form">
            <?php echo csrfField(); ?>
            <input type="hidden" name="override_rejected_candidate" value="1">
            <input type="hidden" name="applicant_id" id="overrideApplicantId" value="">
            <input type="hidden" name="target_final_result" id="overrideTargetFinalResult" value="lolos">

            <div class="modal-content">
                <div class="space-y-5">
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        Kandidat: <strong id="overrideApplicantName">-</strong>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                        Perubahan hasil: <strong id="overrideChangeDirection">-</strong>
                    </div>

                    <div class="form-group">
                        <label for="override_reason" id="overrideReasonLabel" class="text-sm font-semibold text-slate-900">Alasan Perubahan <span class="required">*</span></label>
                        <textarea id="override_reason" name="override_reason" rows="4" placeholder="Jelaskan alasan perubahan hasil kandidat." required></textarea>
                    </div>

                    <div id="overridePassFields">
                    <div class="form-group">
                        <label for="override_recommended_position" class="text-sm font-semibold text-slate-900">Posisi yang Direkomendasikan <span class="required">*</span></label>
                        <select id="override_recommended_position" name="recommended_position" required>
                            <option value="">-- Pilih Posisi --</option>
                            <?php foreach (ems_position_options() as $positionOption): ?>
                                <option value="<?= htmlspecialchars($positionOption['value']) ?>">
                                    <?= htmlspecialchars($positionOption['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="override_recommended_batch" class="text-sm font-semibold text-slate-900">Batch Pelamar <span class="required">*</span></label>
                        <input type="number" id="override_recommended_batch" name="recommended_batch" min="1" max="26" placeholder="Contoh: 7" required>
                    </div>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" data-close-override-modal>Batal</button>
                    <button type="submit" class="btn-primary">Simpan Perubahan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($canManageRecruitmentSettings): ?>
    <div id="recruitmentSettingsModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-md">
            <div class="modal-head">
                <div>
                    <div class="modal-title">Setting Open / Close Rekrutmen</div>
                    <div class="meta-text mt-1">Status ini akan berlaku untuk semua halaman di folder `public`.</div>
                </div>
                <button type="button" class="modal-close-btn" data-close-recruitment-modal aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>

            <form method="post" class="modal-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="save_recruitment_portal_settings" value="1">

                <div class="modal-content">
                    <div class="space-y-5">
                        <div class="form-group">
                            <label for="portal_status" class="text-sm font-semibold text-slate-900">Status Rekrutmen</label>
                            <select id="portal_status" name="portal_status" class="w-full" required>
                                <option value="open" <?= $recruitmentPortalIsOpen ? 'selected' : '' ?>>Open</option>
                                <option value="close" <?= !$recruitmentPortalIsOpen ? 'selected' : '' ?>>Close</option>
                            </select>
                            <small class="hint-info">Jika `close`, semua halaman publik rekrutmen akan diarahkan ke halaman pemberitahuan.</small>
                        </div>

                        <div class="form-group">
                            <label for="closed_message" class="text-sm font-semibold text-slate-900">Pesan Saat Close</label>
                            <textarea id="closed_message" name="closed_message" rows="5" placeholder="Tulis pesan penutupan rekrutmen"><?= htmlspecialchars($recruitmentPortalClosedMessage) ?></textarea>
                            <small class="hint-info">Contoh: Pendaftaran Medis Roxwood saat ini belum dibuka. Silakan menunggu informasi selanjutnya.</small>
                        </div>
                    </div>
                </div>

                <div class="modal-foot">
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" data-close-recruitment-modal>Batal</button>
                        <button type="submit" class="btn-primary">Simpan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const overrideModal = document.getElementById('overrideRejectedCandidateModal');
        const overrideApplicantId = document.getElementById('overrideApplicantId');
        const overrideTargetFinalResult = document.getElementById('overrideTargetFinalResult');
        const overrideApplicantName = document.getElementById('overrideApplicantName');
        const overrideChangeDirection = document.getElementById('overrideChangeDirection');
        const overrideReasonLabel = document.getElementById('overrideReasonLabel');
        const overrideReason = document.getElementById('override_reason');
        const overridePosition = document.getElementById('override_recommended_position');
        const overrideBatch = document.getElementById('override_recommended_batch');
        const overridePassFields = document.getElementById('overridePassFields');
        const overrideOpenButtons = document.querySelectorAll('.open-override-modal');
        const overrideCloseButtons = document.querySelectorAll('[data-close-override-modal]');

        function openOverrideModal(button) {
            if (!overrideModal) return;

            const currentFinalResult = button.dataset.currentFinalResult || '';
            const targetFinalResult = currentFinalResult === 'lolos' ? 'tidak_lolos' : 'lolos';

            overrideApplicantId.value = button.dataset.applicantId || '';
            overrideTargetFinalResult.value = targetFinalResult;
            overrideApplicantName.textContent = button.dataset.applicantName || '-';
            overrideReason.value = button.dataset.overrideReason || '';
            overrideChangeDirection.textContent = currentFinalResult === 'lolos'
                ? 'Lolos -> Tidak Lolos'
                : 'Tidak Lolos -> Lolos';

            if (targetFinalResult === 'lolos') {
                overrideReasonLabel.innerHTML = 'Alasan Meloloskan <span class="required">*</span>';
                overrideReason.placeholder = 'Jelaskan alasan kenapa kandidat yang sebelumnya tidak lolos sekarang diloloskan.';
                overridePassFields.classList.remove('hidden');
                overridePosition.required = true;
                overrideBatch.required = true;
                overridePosition.value = '';
                overrideBatch.value = '';
            } else {
                overrideReasonLabel.innerHTML = 'Alasan Menggagalkan <span class="required">*</span>';
                overrideReason.placeholder = 'Jelaskan alasan kenapa kandidat yang sebelumnya lolos sekarang dikembalikan menjadi tidak lolos.';
                overridePassFields.classList.add('hidden');
                overridePosition.required = false;
                overrideBatch.required = false;
                overridePosition.value = '';
                overrideBatch.value = '';
            }

            overrideModal.classList.remove('hidden');
            overrideModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        }

        function closeOverrideModal() {
            if (!overrideModal) return;
            overrideModal.classList.add('hidden');
            overrideModal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        overrideOpenButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                openOverrideModal(button);
            });
        });

        overrideCloseButtons.forEach(function(button) {
            button.addEventListener('click', closeOverrideModal);
        });

        overrideModal?.addEventListener('click', function(event) {
            if (event.target === overrideModal) {
                closeOverrideModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && overrideModal && !overrideModal.classList.contains('hidden')) {
                closeOverrideModal();
            }
        });

        document.addEventListener('submit', function(e) {
            const form = e.target;
            const button = form.querySelector('.btn-finish-interview');

            if (!button) return;

            const totalHr = parseInt(button.dataset.totalHr || '0', 10);

            if (totalHr < 2) {
                e.preventDefault();
                alert(
                    'Interview belum dapat diselesaikan.\n\n' +
                    'Penilaian baru diberikan oleh ' + totalHr + ' HR.\n' +
                    'Minimal diperlukan 2 HR.\n\n' +
                    'Silakan tunggu HR lain memberikan penilaian.'
                );
                return false;
            }

            if (!confirm('Tandai interview selesai?')) {
                e.preventDefault();
                return false;
            }
        }, true);
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('recruitmentSettingsModal');
        const openButton = document.getElementById('openRecruitmentSettingsModal');
        const closeButtons = document.querySelectorAll('[data-close-recruitment-modal]');
        const statusField = document.getElementById('portal_status');
        const messageField = document.getElementById('closed_message');

        function toggleMessageFieldState() {
            if (!statusField || !messageField) return;
            const isOpen = statusField.value === 'open';
            messageField.readOnly = isOpen;
            messageField.classList.toggle('opacity-60', isOpen);
        }

        function openModal() {
            if (!modal) return;
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
            toggleMessageFieldState();
        }

        function closeModal() {
            if (!modal) return;
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        openButton?.addEventListener('click', openModal);
        closeButtons.forEach(function(button) {
            button.addEventListener('click', closeModal);
        });

        modal?.addEventListener('click', function(event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });

        statusField?.addEventListener('change', toggleMessageFieldState);
        toggleMessageFieldState();

        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#candidateTable').DataTable({
                pageLength: 10,
                scrollX: true,
                autoWidth: false,
                language: {
                    url: '/assets/design/js/datatables-id.json'
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
