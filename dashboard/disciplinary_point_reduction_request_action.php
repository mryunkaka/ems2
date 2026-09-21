<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/inbox_helper.php';

function reductionRequestRedirect(string $to = 'disciplinary_point_reduction_requests.php'): void
{
    $safeTo = basename(parse_url($to, PHP_URL_PATH) ?: 'disciplinary_point_reduction_requests.php');
    $query = parse_url($to, PHP_URL_QUERY);
    header('Location: ' . $safeTo . ($query ? '?' . $query : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_errors'][] = 'Permintaan tidak valid.';
    reductionRequestRedirect();
}

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$redirectTo = trim((string)($_POST['redirect_to'] ?? 'disciplinary_point_reduction_requests.php'));

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session user tidak valid.';
    reductionRequestRedirect($redirectTo);
}

if ($action === 'submit_request' && ems_normalize_division($user['division'] ?? '') !== 'Medis') {
    $_SESSION['flash_errors'][] = 'Pengajuan hanya dapat dilakukan oleh division Medis.';
    reductionRequestRedirect($redirectTo);
}

try {
    if (!ems_table_exists($pdo, 'disciplinary_point_reduction_requests')) {
        throw new RuntimeException('Tabel pengajuan belum tersedia. Jalankan migration 80 terlebih dahulu.');
    }

    if ($action === 'submit_request') {
        $reductionType = trim((string)($_POST['reduction_type'] ?? ''));
        $activityDate = trim((string)($_POST['activity_date'] ?? ''));
        $relatedCaseId = (int)($_POST['related_case_id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $options = ems_disciplinary_point_reduction_options();

        if (!isset($options[$reductionType]) || !DateTime::createFromFormat('Y-m-d', $activityDate)) {
            throw new RuntimeException('Jenis kegiatan dan tanggal wajib valid.');
        }

        $pointsStmt = $pdo->prepare('SELECT COALESCE(SUM(total_points), 0) FROM disciplinary_cases WHERE subject_user_id = ?');
        $pointsStmt->execute([$userId]);
        $casePoints = (int)$pointsStmt->fetchColumn();

        $reductionStmt = $pdo->prepare('SELECT COALESCE(SUM(reduction_points), 0) FROM disciplinary_point_reductions WHERE subject_user_id = ?');
        $reductionStmt->execute([$userId]);
        $approvedReductions = (int)$reductionStmt->fetchColumn();
        $activePoints = max(0, $casePoints - $approvedReductions);

        if ($activePoints <= 0) {
            throw new RuntimeException('Pengajuan hanya tersedia ketika Anda memiliki poin aktif.');
        }

        if ($relatedCaseId > 0) {
            $caseStmt = $pdo->prepare('SELECT subject_user_id FROM disciplinary_cases WHERE id = ? LIMIT 1');
            $caseStmt->execute([$relatedCaseId]);
            if ((int)$caseStmt->fetchColumn() !== $userId) {
                throw new RuntimeException('Kasus terkait tidak sesuai dengan akun Anda.');
            }
        }

        $insert = $pdo->prepare('INSERT INTO disciplinary_point_reduction_requests (subject_user_id, related_case_id, reduction_type, reduction_points, activity_date, notes, submitted_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$userId, $relatedCaseId > 0 ? $relatedCaseId : null, $reductionType, (int)$options[$reductionType]['points'], $activityDate, $notes !== '' ? $notes : null, $userId]);
        $_SESSION['flash_messages'][] = 'Pengajuan pengurangan poin berhasil dikirim ke COMDIS.';
        reductionRequestRedirect();
    }

    ems_require_division_access(['Disciplinary Committee'], '/dashboard/index.php');
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));
    if ($requestId <= 0 || !in_array($action, ['approve_request', 'reject_request'], true)) {
        throw new RuntimeException('Aksi validasi tidak valid.');
    }

    $pdo->beginTransaction();
    $requestStmt = $pdo->prepare('SELECT * FROM disciplinary_point_reduction_requests WHERE id = ? AND status = \'pending\' FOR UPDATE');
    $requestStmt->execute([$requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        throw new RuntimeException('Pengajuan tidak ditemukan atau sudah divalidasi.');
    }

    if ($action === 'approve_request') {
        $insert = $pdo->prepare('INSERT INTO disciplinary_point_reductions (subject_user_id, related_case_id, reduction_type, reduction_points, activity_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([
            (int)$request['subject_user_id'],
            !empty($request['related_case_id']) ? (int)$request['related_case_id'] : null,
            (string)$request['reduction_type'],
            (int)$request['reduction_points'],
            (string)$request['activity_date'],
            $request['notes'] !== null ? (string)$request['notes'] : null,
            $userId,
        ]);
        $newStatus = 'approved';
        $message = 'Pengajuan pengurangan poin disetujui.';
    } else {
        $newStatus = 'rejected';
        $message = 'Pengajuan pengurangan poin ditolak.';
    }

    $update = $pdo->prepare('UPDATE disciplinary_point_reduction_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ? AND status = \'pending\'');
    $update->execute([$newStatus, $userId, $reviewNotes !== '' ? $reviewNotes : null, $requestId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Status pengajuan gagal diperbarui.');
    }
    $pdo->commit();

    $subjectId = (int)$request['subject_user_id'];
    sendInbox($pdo, $subjectId, 'Validasi Pengurangan Poin', $message . '<br><b>Aktivitas:</b> ' . htmlspecialchars(ems_disciplinary_point_reduction_label((string)$request['reduction_type']), ENT_QUOTES, 'UTF-8') . ($reviewNotes !== '' ? '<br><b>Catatan:</b> ' . nl2br(htmlspecialchars($reviewNotes, ENT_QUOTES, 'UTF-8')) : ''), 'disciplinary_reduction_request');
    $_SESSION['flash_messages'][] = $message;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_errors'][] = 'Gagal memproses pengajuan: ' . $e->getMessage();
}

reductionRequestRedirect($redirectTo);
