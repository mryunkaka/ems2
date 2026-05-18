<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/inbox_helper.php';

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

function disciplinaryNormalizeMultiUpload(array $fileBag): array
{
    if (!isset($fileBag['name']) || !is_array($fileBag['name'])) {
        return [];
    }

    $files = [];
    $count = count($fileBag['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = (int)($fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $files[] = [
            'name' => $fileBag['name'][$i] ?? '',
            'type' => $fileBag['type'][$i] ?? '',
            'tmp_name' => $fileBag['tmp_name'][$i] ?? '',
            'error' => $error,
            'size' => (int)($fileBag['size'][$i] ?? 0),
        ];
    }

    return $files;
}

function disciplinaryDeleteStoredFiles(array $paths): void
{
    foreach ($paths as $path) {
        $relativePath = trim((string)$path);
        if ($relativePath === '') {
            continue;
        }

        $fullPath = __DIR__ . '/../' . ltrim($relativePath, '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

function disciplinaryStoreAttachments(
    PDO $pdo,
    string $table,
    string $foreignKey,
    int $parentId,
    array $files,
    string $folder
): array {
    if ($parentId <= 0 || $files === []) {
        return [];
    }

    if (!ems_table_exists($pdo, $table)) {
        throw new RuntimeException('Tabel lampiran Komdis belum tersedia. Jalankan SQL `docs/sql/42_2026-05-18_disciplinary_attachments.sql` terlebih dahulu.');
    }

    $insert = $pdo->prepare("
        INSERT INTO {$table}
            ({$foreignKey}, file_name, file_path)
        VALUES
            (?, ?, ?)
    ");

    $storedPaths = [];

    foreach ($files as $file) {
        $path = uploadDisciplinaryAttachmentFile($file, $folder);
        if ($path === null) {
            throw new RuntimeException('Ada lampiran yang gagal diunggah. Foto akan dikompres otomatis bila memungkinkan, namun ukuran akhir wajib maksimal ' . emsDisciplinaryAttachmentMaxLabel() . '. File PDF harus sudah dikompres manual hingga maksimal ' . emsDisciplinaryAttachmentMaxLabel() . '.');
        }

        $originalName = trim((string)($file['name'] ?? ''));
        if ($originalName === '') {
            $originalName = basename($path);
        }

        $insert->execute([$parentId, $originalName, $path]);
        $storedPaths[] = $path;
    }

    return $storedPaths;
}

function disciplinaryUserName(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return 'Pegawai';
    }

    $stmt = $pdo->prepare("SELECT full_name FROM user_rh WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    return trim((string)($stmt->fetchColumn() ?: 'Pegawai'));
}

function disciplinarySendInboxNotice(PDO $pdo, int $targetUserId, string $title, string $message, string $type): void
{
    if ($targetUserId <= 0) {
        return;
    }

    sendInbox($pdo, $targetUserId, $title, $message, $type);
}

function disciplinarySyncCaseLetterStatus(PDO $pdo, int $caseId, int $userId = 0): void
{
    if ($caseId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM disciplinary_warning_letters
        WHERE case_id = ?
    ");
    $stmt->execute([$caseId]);
    $hasLetters = (int)$stmt->fetchColumn() > 0;

    if ($hasLetters) {
        $sql = "
            UPDATE disciplinary_cases
            SET
                letter_status = 'issued',
                status = IF(status = 'open', 'escalated', status)";
        $params = [];

        if ($userId > 0) {
            $sql .= ",
                reviewed_by = ?,
                reviewed_at = NOW()";
            $params[] = $userId;
        }

        $sql .= "
            WHERE id = ?";
        $params[] = $caseId;

        $update = $pdo->prepare($sql);
        $update->execute($params);
        return;
    }

    $update = $pdo->prepare("
        UPDATE disciplinary_cases
        SET letter_status = 'pending'
        WHERE id = ?
    ");
    $update->execute([$caseId]);
}

if ($action === 'save_indication') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $defaultPoints = max(0, (int)($_POST['default_points'] ?? 0));
    $toleranceType = trim((string)($_POST['tolerance_type'] ?? 'tolerable'));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $hasCreatedByColumn = ems_column_exists($pdo, 'disciplinary_indications', 'created_by');
    $hasUpdatedByColumn = ems_column_exists($pdo, 'disciplinary_indications', 'updated_by');

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
        $updateFields = [
            'name = ?',
            'description = ?',
            'default_points = ?',
            'tolerance_type = ?',
            'is_active = ?',
        ];
        $updateValues = [$name, $description !== '' ? $description : null, $defaultPoints, $toleranceType, $isActive];

        if ($hasUpdatedByColumn) {
            $updateFields[] = 'updated_by = ?';
            $updateValues[] = $userId;
        }

        $updateValues[] = $id;

        $stmt = $pdo->prepare("
            UPDATE disciplinary_indications
            SET
                " . implode(",\n                ", $updateFields) . "
            WHERE id = ?
        ");
        $stmt->execute($updateValues);
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

        $insertColumns = ['code', 'name', 'description', 'default_points', 'tolerance_type', 'is_active'];
        $insertValues = [$code, $name, $description !== '' ? $description : null, $defaultPoints, $toleranceType, $isActive];
        $insertPlaceholders = ['?', '?', '?', '?', '?', '?'];

        if ($hasCreatedByColumn) {
            $insertColumns[] = 'created_by';
            $insertValues[] = $userId;
            $insertPlaceholders[] = '?';
        }

        if ($hasUpdatedByColumn) {
            $insertColumns[] = 'updated_by';
            $insertValues[] = $userId;
            $insertPlaceholders[] = '?';
        }

        $stmt = $pdo->prepare("
            INSERT INTO disciplinary_indications
                (" . implode(', ', $insertColumns) . ")
            VALUES
                (" . implode(', ', $insertPlaceholders) . ")
        ");
        $stmt->execute($insertValues);
        $_SESSION['flash_messages'][] = 'Indikasi baru berhasil ditambahkan.';
    }

    disciplinaryRedirect($redirectTo);
}

if ($action === 'delete_indication') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        $_SESSION['flash_errors'][] = 'Data indikasi tidak valid.';
        disciplinaryRedirect($redirectTo);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM disciplinary_case_items
            WHERE indication_id = ?
        ");
        $stmt->execute([$id]);
        $usedCount = (int)$stmt->fetchColumn();

        if ($usedCount > 0) {
            $_SESSION['flash_errors'][] = 'Indikasi tidak bisa dihapus karena sudah dipakai pada case pelanggaran.';
            disciplinaryRedirect($redirectTo);
        }

        $delete = $pdo->prepare("
            DELETE FROM disciplinary_indications
            WHERE id = ?
            LIMIT 1
        ");
        $delete->execute([$id]);

        if ($delete->rowCount() < 1) {
            $_SESSION['flash_errors'][] = 'Indikasi tidak ditemukan.';
            disciplinaryRedirect($redirectTo);
        }

        $_SESSION['flash_messages'][] = 'Indikasi berhasil dihapus.';
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Gagal menghapus indikasi: ' . $e->getMessage();
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
    $attachmentFiles = disciplinaryNormalizeMultiUpload($_FILES['attachments'] ?? []);

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

        $storedAttachmentPaths = disciplinaryStoreAttachments(
            $pdo,
            'disciplinary_case_attachments',
            'case_id',
            $caseId,
            $attachmentFiles,
            'disciplinary/cases'
        );

        $subjectName = disciplinaryUserName($pdo, $subjectUserId);
        $senderName = disciplinaryUserName($pdo, $userId);
        $caseMessage = '<b>Nama Medis:</b> ' . htmlspecialchars($subjectName, ENT_QUOTES, 'UTF-8')
            . '<br><b>Kode Kasus:</b> ' . htmlspecialchars($caseCode, ENT_QUOTES, 'UTF-8')
            . '<br><b>Kasus:</b> ' . htmlspecialchars($caseName, ENT_QUOTES, 'UTF-8')
            . '<br><b>Total Poin:</b> ' . htmlspecialchars((string)$totalPoints, ENT_QUOTES, 'UTF-8')
            . '<br><b>Rekomendasi:</b> ' . htmlspecialchars(ems_disciplinary_recommendation_label($recommendation), ENT_QUOTES, 'UTF-8')
            . '<br><b>Diinput oleh:</b> ' . htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');
        disciplinarySendInboxNotice($pdo, $subjectUserId, 'Kasus Komdis Baru', $caseMessage, 'disciplinary_case');

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Disciplinary case berhasil dibuat.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($storedAttachmentPaths ?? [])) {
            disciplinaryDeleteStoredFiles($storedAttachmentPaths);
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

if ($action === 'save_point_reduction') {
    $subjectUserId = (int)($_POST['subject_user_id'] ?? 0);
    $relatedCaseId = (int)($_POST['related_case_id'] ?? 0);
    $reductionType = trim((string)($_POST['reduction_type'] ?? ''));
    $activityDate = trim((string)($_POST['activity_date'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $reductionOptions = ems_disciplinary_point_reduction_options();

    if ($subjectUserId <= 0 || $activityDate === '' || !isset($reductionOptions[$reductionType])) {
        $_SESSION['flash_errors'][] = 'Data pengurangan poin wajib lengkap.';
        disciplinaryRedirect($redirectTo);
    }

    if ($relatedCaseId > 0) {
        $caseStmt = $pdo->prepare("
            SELECT id, subject_user_id
            FROM disciplinary_cases
            WHERE id = ?
            LIMIT 1
        ");
        $caseStmt->execute([$relatedCaseId]);
        $relatedCase = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$relatedCase) {
            $_SESSION['flash_errors'][] = 'Kasus terkait untuk pengurangan poin tidak ditemukan.';
            disciplinaryRedirect($redirectTo);
        }

        if ((int)$relatedCase['subject_user_id'] !== $subjectUserId) {
            $_SESSION['flash_errors'][] = 'Kasus terkait tidak sesuai dengan pegawai yang dipilih.';
            disciplinaryRedirect($redirectTo);
        }
    }

    $reductionPoints = (int)($reductionOptions[$reductionType]['points'] ?? 0);
    if ($reductionPoints <= 0) {
        $_SESSION['flash_errors'][] = 'Nilai pengurangan poin tidak valid.';
        disciplinaryRedirect($redirectTo);
    }

    try {
        if (!ems_table_exists($pdo, 'disciplinary_point_reductions')) {
            throw new RuntimeException('Tabel pengurangan poin belum tersedia. Jalankan SQL `docs/sql/43_2026-05-18_disciplinary_point_reductions.sql` terlebih dahulu.');
        }

        $insert = $pdo->prepare("
            INSERT INTO disciplinary_point_reductions
                (subject_user_id, related_case_id, reduction_type, reduction_points, activity_date, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $subjectUserId,
            $relatedCaseId > 0 ? $relatedCaseId : null,
            $reductionType,
            $reductionPoints,
            $activityDate,
            $notes !== '' ? $notes : null,
            $userId,
        ]);

        $subjectName = disciplinaryUserName($pdo, $subjectUserId);
        $senderName = disciplinaryUserName($pdo, $userId);
        $reductionLabel = ems_disciplinary_point_reduction_label($reductionType);
        $reductionMessage = '<b>Nama Medis:</b> ' . htmlspecialchars($subjectName, ENT_QUOTES, 'UTF-8')
            . '<br><b>Aktivitas:</b> ' . htmlspecialchars($reductionLabel, ENT_QUOTES, 'UTF-8')
            . '<br><b>Pengurangan Poin:</b> -' . htmlspecialchars((string)$reductionPoints, ENT_QUOTES, 'UTF-8')
            . '<br><b>Tanggal:</b> ' . htmlspecialchars($activityDate, ENT_QUOTES, 'UTF-8')
            . ($notes !== '' ? '<br><b>Catatan:</b> ' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) : '')
            . '<br><b>Dicatat oleh:</b> ' . htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');
        disciplinarySendInboxNotice($pdo, $subjectUserId, 'Pengurangan Poin Komdis', $reductionMessage, 'disciplinary_reduction');

        $_SESSION['flash_messages'][] = 'Pengurangan poin berhasil dicatat.';
    } catch (Throwable $e) {
        $_SESSION['flash_errors'][] = 'Gagal mencatat pengurangan poin: ' . $e->getMessage();
    }

    disciplinaryRedirect($redirectTo);
}

if ($action === 'create_warning_letter') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $letterType = trim((string)($_POST['letter_type'] ?? ''));
    $issuedDate = trim((string)($_POST['issued_date'] ?? ''));
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $bodyNotes = trim((string)($_POST['body_notes'] ?? ''));
    $attachmentFiles = disciplinaryNormalizeMultiUpload($_FILES['attachments'] ?? []);

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

        $warningLetterId = (int)$pdo->lastInsertId();

        $storedAttachmentPaths = disciplinaryStoreAttachments(
            $pdo,
            'disciplinary_warning_letter_attachments',
            'warning_letter_id',
            $warningLetterId,
            $attachmentFiles,
            'disciplinary/warning_letters'
        );

        disciplinarySyncCaseLetterStatus($pdo, $caseId, $userId);

        $subjectUserId = (int)$case['subject_user_id'];
        $subjectName = disciplinaryUserName($pdo, $subjectUserId);
        $senderName = disciplinaryUserName($pdo, $userId);
        $warningMessage = '<b>Nama Medis:</b> ' . htmlspecialchars($subjectName, ENT_QUOTES, 'UTF-8')
            . '<br><b>Kode Surat:</b> ' . htmlspecialchars($letterCode, ENT_QUOTES, 'UTF-8')
            . '<br><b>Jenis Surat:</b> ' . htmlspecialchars(ems_disciplinary_recommendation_label($letterType), ENT_QUOTES, 'UTF-8')
            . '<br><b>Judul:</b> ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '<br><b>Tanggal Terbit:</b> ' . htmlspecialchars($issuedDate, ENT_QUOTES, 'UTF-8')
            . '<br><b>Dibuat oleh:</b> ' . htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8');
        disciplinarySendInboxNotice($pdo, $subjectUserId, 'Surat Peringatan Komdis', $warningMessage, 'disciplinary_warning_letter');

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Surat peringatan berhasil dibuat.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($storedAttachmentPaths ?? [])) {
            disciplinaryDeleteStoredFiles($storedAttachmentPaths);
        }
        $_SESSION['flash_errors'][] = 'Gagal membuat surat peringatan: ' . $e->getMessage();
    }

    disciplinaryRedirect($redirectTo);
}

if ($action === 'update_warning_letter') {
    $letterId = (int)($_POST['id'] ?? 0);
    $caseId = (int)($_POST['case_id'] ?? 0);
    $letterType = trim((string)($_POST['letter_type'] ?? ''));
    $issuedDate = trim((string)($_POST['issued_date'] ?? ''));
    $effectiveDate = trim((string)($_POST['effective_date'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $bodyNotes = trim((string)($_POST['body_notes'] ?? ''));
    $attachmentFiles = disciplinaryNormalizeMultiUpload($_FILES['attachments'] ?? []);

    if ($letterId <= 0 || $caseId <= 0 || $letterType === '' || $issuedDate === '' || $title === '') {
        $_SESSION['flash_errors'][] = 'Data surat peringatan wajib lengkap.';
        disciplinaryRedirect($redirectTo);
    }

    $existingStmt = $pdo->prepare("
        SELECT id, case_id
        FROM disciplinary_warning_letters
        WHERE id = ?
        LIMIT 1
    ");
    $existingStmt->execute([$letterId]);
    $existingLetter = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingLetter) {
        $_SESSION['flash_errors'][] = 'Surat peringatan tidak ditemukan.';
        disciplinaryRedirect($redirectTo);
    }

    $caseStmt = $pdo->prepare("
        SELECT id, subject_user_id
        FROM disciplinary_cases
        WHERE id = ?
        LIMIT 1
    ");
    $caseStmt->execute([$caseId]);
    $case = $caseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$case) {
        $_SESSION['flash_errors'][] = 'Case tidak ditemukan.';
        disciplinaryRedirect($redirectTo);
    }

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare("
            UPDATE disciplinary_warning_letters
            SET
                case_id = ?,
                subject_user_id = ?,
                letter_type = ?,
                issued_date = ?,
                effective_date = ?,
                title = ?,
                body_notes = ?
            WHERE id = ?
        ");
        $update->execute([
            $caseId,
            (int)$case['subject_user_id'],
            $letterType,
            $issuedDate,
            $effectiveDate !== '' ? $effectiveDate : null,
            $title,
            $bodyNotes !== '' ? $bodyNotes : null,
            $letterId,
        ]);

        $storedAttachmentPaths = disciplinaryStoreAttachments(
            $pdo,
            'disciplinary_warning_letter_attachments',
            'warning_letter_id',
            $letterId,
            $attachmentFiles,
            'disciplinary/warning_letters'
        );

        disciplinarySyncCaseLetterStatus($pdo, $caseId, $userId);

        $oldCaseId = (int)$existingLetter['case_id'];
        if ($oldCaseId !== $caseId) {
            disciplinarySyncCaseLetterStatus($pdo, $oldCaseId, $userId);
        }

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Surat peringatan berhasil diperbarui.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($storedAttachmentPaths ?? [])) {
            disciplinaryDeleteStoredFiles($storedAttachmentPaths);
        }
        $_SESSION['flash_errors'][] = 'Gagal memperbarui surat peringatan: ' . $e->getMessage();
    }

    disciplinaryRedirect($redirectTo);
}

if ($action === 'delete_warning_letter') {
    $letterId = (int)($_POST['id'] ?? 0);

    if ($letterId <= 0) {
        $_SESSION['flash_errors'][] = 'Data surat peringatan tidak valid.';
        disciplinaryRedirect($redirectTo);
    }

    $stmt = $pdo->prepare("
        SELECT id, case_id
        FROM disciplinary_warning_letters
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$letterId]);
    $letter = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$letter) {
        $_SESSION['flash_errors'][] = 'Surat peringatan tidak ditemukan.';
        disciplinaryRedirect($redirectTo);
    }

    try {
        $pdo->beginTransaction();

        $attachmentPaths = [];
        if (ems_table_exists($pdo, 'disciplinary_warning_letter_attachments')) {
            $attachmentStmt = $pdo->prepare("
                SELECT file_path
                FROM disciplinary_warning_letter_attachments
                WHERE warning_letter_id = ?
            ");
            $attachmentStmt->execute([$letterId]);
            $attachmentPaths = array_column($attachmentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'file_path');
        }

        $delete = $pdo->prepare("
            DELETE FROM disciplinary_warning_letters
            WHERE id = ?
            LIMIT 1
        ");
        $delete->execute([$letterId]);

        disciplinarySyncCaseLetterStatus($pdo, (int)$letter['case_id'], $userId);

        $pdo->commit();
        disciplinaryDeleteStoredFiles($attachmentPaths);
        $_SESSION['flash_messages'][] = 'Surat peringatan berhasil dihapus.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_errors'][] = 'Gagal menghapus surat peringatan: ' . $e->getMessage();
    }

    disciplinaryRedirect($redirectTo);
}

$_SESSION['flash_errors'][] = 'Action disciplinary tidak dikenali.';
disciplinaryRedirect($redirectTo);
