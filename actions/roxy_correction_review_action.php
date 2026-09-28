<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';

$user = $_SESSION['user_rh'] ?? [];
if (!ems_is_manager_plus_role((string) ($user['role'] ?? ''))) {
    http_response_code(403);
    exit('Hanya manager ke atas yang dapat meninjau koreksi Roxy.');
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || !validateCsrfToken(csrfRequestToken())) {
    http_response_code(403);
    exit('Permintaan tidak valid atau sesi kedaluwarsa.');
}

$correctionId = (int) ($_POST['correction_id'] ?? 0);
$decision = trim((string) ($_POST['decision'] ?? ''));
$note = trim((string) ($_POST['verification_note'] ?? ''));
$unitCode = ems_effective_unit($pdo, $user);
if ($correctionId <= 0 || !in_array($decision, ['verify', 'reject'], true) || mb_strlen($note) < 5 || mb_strlen($note) > 2000) {
    $_SESSION['flash_errors'][] = 'Keputusan review dan alasan minimal 5 karakter wajib diisi.';
    header('Location: /dashboard/ai_assistant_monitoring.php');
    exit;
}

try {
    ems_roxy_ensure_tables($pdo);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM bot_answer_corrections WHERE id = ? AND unit_code = ? AND verification_status = 'pending' FOR UPDATE");
    $stmt->execute([$correctionId, $unitCode]);
    $correction = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$correction) {
        $pdo->rollBack();
        $_SESSION['flash_errors'][] = 'Koreksi tidak ditemukan atau sudah ditinjau.';
        header('Location: /dashboard/ai_assistant_monitoring.php');
        exit;
    }

    $reviewerId = (int) ($user['id'] ?? 0);
    $reviewerName = mb_substr(trim((string) ($user['full_name'] ?? $user['name'] ?? 'Manager')), 0, 150);
    if ($decision === 'verify') {
        $questionHash = hash('sha256', ems_roxy_normalize_question((string) $correction['question_snapshot']));
        $learn = $pdo->prepare("
            INSERT INTO bot_learned_answers
                (unit_code, question_hash, question_text, answer_text, source_correction_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                question_text = VALUES(question_text),
                answer_text = VALUES(answer_text),
                source_correction_id = VALUES(source_correction_id),
                times_reused = 0,
                updated_at = CURRENT_TIMESTAMP
        ");
        $learn->execute([
            $unitCode,
            $questionHash,
            (string) $correction['question_snapshot'],
            (string) $correction['corrected_answer'],
            $correctionId,
        ]);
        $newStatus = 'verified';
        $message = 'Koreksi disetujui dan akan dipakai untuk pertanyaan yang sama.';
    } else {
        $newStatus = 'rejected';
        $message = 'Koreksi ditolak.';
    }

    $update = $pdo->prepare('UPDATE bot_answer_corrections SET verification_status = ?, verification_note = ?, reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW() WHERE id = ? AND verification_status = \'pending\'');
    $update->execute([$newStatus, $note, $reviewerId, $reviewerName, $correctionId]);
    $pdo->commit();
    $_SESSION['flash_messages'][] = $message;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log(sprintf('[Roxy correction review] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    $_SESSION['flash_errors'][] = 'Review koreksi gagal disimpan.';
}

header('Location: /dashboard/ai_assistant_monitoring.php');
exit;
