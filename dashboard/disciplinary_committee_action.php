<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

ems_require_division_access(['Disciplinary Committee'], '/dashboard/index.php');

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$redirectTo = trim((string)($_POST['redirect_to'] ?? 'disciplinary_cases.php'));

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    header('Location: /dashboard/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_errors'][] = 'Method tidak valid.';
    header('Location: ' . $redirectTo);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_errors'][] = 'CSRF token tidak valid.';
    header('Location: ' . $redirectTo);
    exit;
}

function disciplinaryRedirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function disciplinaryGenerateCode(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function disciplinaryToleranceSummary(int $tolerableCount, int $nonTolerableCount): string
{
    if ($nonTolerableCount > 0 && $tolerableCount > 0) {
        return 'mixed';
    }

    if ($nonTolerableCount > 0) {
        return 'non_tolerable';
    }

    return 'tolerable';
}

function disciplinaryLetterStatus(string $recommendation): string
{
    return in_array($recommendation, ['written_warning_1', 'written_warning_2', 'final_warning', 'termination_review'], true)
        ? 'pending'
        : 'not_needed';
}

if ($action === 'save_indication') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $defaultPoints = max(0, (int)($_POST['default_points'] ?? 0));
    $toleranceType = trim((string)($_POST['tolerance_type'] ?? 'tolerable'));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $_SESSION['flash_errors'][] = 'Nama indikasi wajib diisi.';
        disciplinaryRedirect($redirectTo);
    }

    if (!array_key_exists($toleranceType, ems_disciplinary_tolerance_options())) {
        $_SESSION['flash_errors'][] = 'Jenis toleransi tidak valid.';
        disciplinaryRedirect($redirectTo);
    }

    $codeBase = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $name), '_'));
    if ($codeBase === '') {
        $codeBase = 'indication';
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE disciplinary_indications
            SET
                name = ?,
                description = ?,
                default_points = ?,
                tolerance_type = ?,
                is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $description !== '' ? $description : null, $defaultPoints, $toleranceType, $isActive, $id]);
        $_SESSION['flash_messages'][] = 'Indikasi berhasil diperbarui.';
    } else {
        $code = $codeBase;
        $suffix = 1;
        while (true) {
            $check = $pdo->prepare("SELECT id FROM disciplinary_indications WHERE code = ? LIMIT 1");
            $check->execute([$code]);
            if (!$check->fetch()) {
                break;
            }
            $suffix++;
            $code = $codeBase . '_' . $suffix;
        }

        $stmt = $pdo->prepare("
            INSERT INTO disciplinary_indications
                (code, name, description, default_points, tolerance_type, is_active)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$code, $name, $description !== '' ? $description : null, $defaultPoints, $toleranceType, $isActive]);
        $_SESSION['flash_messages'][] = 'Indikasi baru berhasil ditambahkan.';
    }

    disciplinaryRedirect($redirectTo);
}

if ($action === 'create_case') {
    $subjectUserId = (int)($_POST['subject_user_id'] ?? 0);
    $caseName = trim((string)($_POST['case_name'] ?? ''));
    $caseDate = trim((string)($_POST['case_date'] ?? ''));
    $summary = trim((string)($_POST['summary'] ?? ''));
    $indicationIds = $_POST['indication_id'] ?? [];
    $itemNotes = $_POST['item_notes'] ?? [];

    if ($subjectUserId <= 0 || $caseName === '' || $caseDate === '') {
        $_SESSION['flash_errors'][] = 'Data case wajib lengkap.';
        disciplinaryRedirect($redirectTo);
    }

    if (!is_array($indicationIds) || count(array_filter($indicationIds, static fn($v) => (int)$v > 0)) === 0) {
        $_SESSION['flash_errors'][] = 'Minimal satu indikasi wajib dipilih.';
        disciplinaryRedirect($redirectTo);
    }

    $caseCode = disciplinaryGenerateCode('dc');

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO disciplinary_cases
                (case_code, subject_user_id, case_name, case_date, summary, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$caseCode, $subjectUserId, $caseName, $caseDate, $summary !== '' ? $summary : null, $userId]);
        $caseId = (int)$pdo->lastInsertId();

        $totalPoints = 0;
        $tolerableCount = 0;
        $nonTolerableCount = 0;

        $fetchIndication = $pdo->prepare("
            SELECT id, name, default_points, tolerance_type
            FROM disciplinary_indications
            WHERE id = ?
            LIMIT 1
        ");
        $insertItem = $pdo->prepare("
            INSERT INTO disciplinary_case_items
                (case_id, indication_id, indication_name_snapshot, points_snapshot, tolerance_type_snapshot, notes)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");

        foreach ($indicationIds as $index => $indicationIdRaw) {
            $indicationId = (int)$indicationIdRaw;
            if ($indicationId <= 0) {
                continue;
            }

            $fetchIndication->execute([$indicationId]);
            $indication = $fetchIndication->fetch(PDO::FETCH_ASSOC);
            if (!$indication) {
                continue;
            }

            $points = (int)$indication['default_points'];
            $toleranceType = (string)$indication['tolerance_type'];
            $notes = trim((string)($itemNotes[$index] ?? ''));

            $insertItem->execute([
                $caseId,
                $indicationId,
                $indication['name'],
                $points,
                $toleranceType,
                $notes !== '' ? $notes : null,
            ]);

            $totalPoints += $points;
            if ($toleranceType === 'non_tolerable') {
                $nonTolerableCount++;
            } else {
                $tolerableCount++;
            }
        }

        if ($totalPoints <= 0 && ($tolerableCount + $nonTolerableCount) === 0) {
            throw new RuntimeException('Indikasi case tidak valid.');
        }

        $hasNonTolerable = $nonTolerableCount > 0;
        $recommendation = ems_disciplinary_recommendation_from_points($totalPoints, $hasNonTolerable);
        $toleranceSummary = disciplinaryToleranceSummary($tolerableCount, $nonTolerableCount);
        $letterStatus = disciplinaryLetterStatus($recommendation);

        $updateCase = $pdo->prepare("
            UPDATE disciplinary_cases
            SET
                total_points = ?,
                tolerable_count = ?,
                non_tolerable_count = ?,
                tolerance_summary = ?,
                recommended_action = ?,
                letter_status = ?
            WHERE id = ?
        ");
        $updateCase->execute([
            $totalPoints,
            $tolerableCount,
            $nonTolerableCount,
            $toleranceSummary,
            $recommendation,
            $letterStatus,
            $caseId,
        ]);

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Disciplinary case berhasil dibuat.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_errors'][] = 'Gagal membuat case: ' . $e->getMessage();
    }

    disciplinaryRedirect($redirectTo);
}

if ($action === 'update_case_status') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? 'open'));

    if ($caseId <= 0 || !in_array($status, ['open', 'reviewed', 'escalated', 'closed'], true)) {
        $_SESSION['flash_errors'][] = 'Status case tidak valid.';
        disciplinaryRedirect($redirectTo);
    }

    $stmt = $pdo->prepare("
        UPDATE disciplinary_cases
        SET
            status = ?,
            reviewed_by = ?,
            reviewed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $userId, $caseId]);

    $_SESSION['flash_messages'][] = 'Status case berhasil diperbarui.';
    disciplinaryRedirect($redirectTo);
}

if ($action === 'create_warning_letter') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $letterType = trim((string)($_POST['letter_type'] ?? ''));
    $issuedDate = trim((string)($_POST['issued_date'] ?? ''));
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $bodyNotes = trim((string)($_POST['body_notes'] ?? ''));

    if ($caseId <= 0 || $letterType === '' || $issuedDate === '' || $title === '') {
        $_SESSION['flash_errors'][] = 'Data surat peringatan wajib lengkap.';
        disciplinaryRedirect($redirectTo);
    }

    $stmt = $pdo->prepare("
        SELECT id, subject_user_id
        FROM disciplinary_cases
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$case) {
        $_SESSION['flash_errors'][] = 'Case tidak ditemukan.';
        disciplinaryRedirect($redirectTo);
    }

    $letterCode = disciplinaryGenerateCode('sp');

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare("
            INSERT INTO disciplinary_warning_letters
                (letter_code, case_id, subject_user_id, letter_type, issued_date, effective_date, title, body_notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $letterCode,
            $caseId,
            (int)$case['subject_user_id'],
            $letterType,
            $issuedDate,
            $effectiveDate !== '' ? $effectiveDate : null,
            $title,
            $bodyNotes !== '' ? $bodyNotes : null,
            $userId,
        ]);

        $updateCase = $pdo->prepare("
            UPDATE disciplinary_cases
            SET
                letter_status = 'issued',
                status = IF(status = 'open', 'escalated', status),
                reviewed_by = ?,
                reviewed_at = NOW()
            WHERE id = ?
        ");
        $updateCase->execute([$userId, $caseId]);

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Surat peringatan berhasil dibuat.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_errors'][] = 'Gagal membuat surat peringatan: ' . $e->getMessage();
    }

    disciplinaryRedirect($redirectTo);
}

$_SESSION['flash_errors'][] = 'Action disciplinary tidak dikenali.';
disciplinaryRedirect($redirectTo);
