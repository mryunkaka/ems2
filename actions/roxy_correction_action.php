<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';

header('Content-Type: application/json; charset=UTF-8');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    emsJsonAbort(405, ['success' => false, 'message' => 'Metode tidak diizinkan.']);
}
emsRequireJsonCsrf('Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.');

$user = $_SESSION['user_rh'] ?? [];
$userId = (int) ($user['id'] ?? 0);
$messageId = (int) ($_POST['original_message_id'] ?? 0);
$correctedAnswer = trim((string) ($_POST['corrected_answer'] ?? ''));
if ($messageId <= 0 || mb_strlen($correctedAnswer) < 10 || mb_strlen($correctedAnswer) > 8000) {
    emsJsonAbort(422, ['success' => false, 'message' => 'Koreksi harus berisi 10 sampai 8.000 karakter.']);
}
emsRequireRateLimit('roxy_correction', emsCurrentRequestIdentifier($userId), 5, 300, 'Terlalu banyak mengirim koreksi. Coba lagi nanti.');

try {
    ems_roxy_ensure_tables($pdo);
    $unitCode = ems_effective_unit($pdo, $user);
    $stmt = $pdo->prepare("
        SELECT c.id AS conversation_id, c.unit_code, bot.id AS bot_message_id,
            bot.content AS wrong_answer, question.content AS question_text
        FROM bot_messages bot
        JOIN bot_conversations c ON c.id = bot.conversation_id
        JOIN bot_messages question ON question.id = bot.reply_to_message_id
        WHERE bot.id = ? AND bot.sender = 'bot' AND question.sender = 'user'
          AND c.user_id = ? AND c.unit_code = ?
        LIMIT 1
    ");
    $stmt->execute([$messageId, $userId, $unitCode]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) {
        emsJsonAbort(404, ['success' => false, 'message' => 'Pesan Roxy tidak ditemukan dalam percakapan Anda.']);
    }

    $duplicate = $pdo->prepare('SELECT id, verification_status FROM bot_answer_corrections WHERE original_message_id = ? AND submitted_by = ? LIMIT 1');
    $duplicate->execute([$messageId, $userId]);
    $existing = $duplicate->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        emsJsonAbort(409, [
            'success' => false,
            'message' => 'Koreksi untuk jawaban ini sudah pernah dikirim dan berstatus ' . (string) $existing['verification_status'] . '.',
        ]);
    }

    $name = trim((string) ($user['full_name'] ?? $user['name'] ?? 'Pengguna'));
    $insert = $pdo->prepare("
        INSERT INTO bot_answer_corrections
            (unit_code, conversation_id, original_message_id, question_snapshot,
             wrong_answer_snapshot, corrected_answer, submitted_by, submitted_by_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $unitCode,
        (int) $source['conversation_id'],
        $messageId,
        (string) $source['question_text'],
        (string) $source['wrong_answer'],
        $correctedAnswer,
        $userId,
        mb_substr($name, 0, 150),
    ]);

    echo json_encode(['success' => true, 'message' => 'Koreksi tersimpan dan menunggu review manager.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log(sprintf('[Roxy correction] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    emsJsonAbort(500, ['success' => false, 'message' => 'Koreksi gagal disimpan. Coba lagi beberapa saat.']);
}
