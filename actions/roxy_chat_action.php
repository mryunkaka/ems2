<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if ($lastError === null || !in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log(sprintf(
        '[Roxy][FATAL] %s in %s:%d',
        $lastError['message'],
        $lastError['file'],
        $lastError['line']
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Roxy mengalami gangguan pada server. Detail sudah dicatat untuk pemeriksaan.',
            'error_code' => 'roxy_fatal_error',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    emsJsonAbort(405, ['success' => false, 'message' => 'Metode tidak diizinkan.']);
}

emsRequireJsonCsrf('Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.');

$user = $_SESSION['user_rh'] ?? [];
$userId = (int) ($user['id'] ?? 0);
if ($userId <= 0) {
    emsJsonAbort(401, ['success' => false, 'message' => 'Sesi tidak valid, silakan login ulang.']);
}

// Batas wajar per user — ini cuma jaring pengaman lonjakan/abuse di sisi
// aplikasi, terpisah dari jatah harian Groq itu sendiri (1.000
// request/hari per akun, lihat docs/AI_ASSISTANT_MODULE.md §3).
emsRequireRateLimit('roxy_chat', emsCurrentRequestIdentifier($userId), 20, 60, 'Terlalu banyak pesan ke Roxy dalam waktu singkat. Tunggu sebentar lalu coba lagi.');

$conversationIdInput = (int) ($_POST['conversation_id'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));

if ($message === '') {
    emsJsonAbort(422, ['success' => false, 'message' => 'Pesan tidak boleh kosong.']);
}
if (mb_strlen($message) > 4000) {
    emsJsonAbort(422, ['success' => false, 'message' => 'Pesan terlalu panjang (maksimal 4000 karakter).']);
}

try {
    ems_roxy_ensure_tables($pdo);
    $unitCode = ems_effective_unit($pdo, $user);

    $conversationId = ems_roxy_get_or_create_conversation(
        $pdo,
        $userId,
        $unitCode,
        $conversationIdInput > 0 ? $conversationIdInput : null,
        $message
    );

    // Riwayat SEBELUM pesan baru ini disimpan — ems_roxy_ask() yang
    // menambahkan pesan user saat ini ke daftar messages yang dikirim ke
    // Groq, supaya tidak dobel.
    $historyBefore = ems_roxy_get_conversation_messages($pdo, $conversationId);

    $userMessageId = ems_roxy_save_message($pdo, $conversationId, $userId, 'user', $message);

    $result = ems_roxy_ask($pdo, $user, $unitCode, $historyBefore, $message);

    if (!$result['ok']) {
        emsJsonAbort(502, [
            'success' => false,
            'message' => (string) ($result['message'] ?? 'Roxy gagal menjawab, coba lagi.'),
            'error_code' => (string) ($result['error_code'] ?? 'unknown'),
            'conversation_id' => $conversationId,
        ]);
    }

    // AI call can exceed hosting wait_timeout (30s). Reconnect before writing
    // the bot answer, otherwise PDO may reuse a closed MariaDB connection.
    ems_reconnect_database_if_needed($pdo);

    try {
        $botMessageId = ems_roxy_save_message(
            $pdo,
            $conversationId,
            $userId,
            'bot',
            $result['answer'],
            $result['answer_source'],
            $result['expression'],
            $userMessageId
        );
    } catch (Throwable $saveError) {
        if (!preg_match('/(?:SQLSTATE\[HY000\].*2006|server has gone away|mysql server has gone away)/i', $saveError->getMessage())) {
            throw $saveError;
        }

        ems_reconnect_database_if_needed($pdo);
        $botMessageId = ems_roxy_save_message(
            $pdo,
            $conversationId,
            $userId,
            'bot',
            $result['answer'],
            $result['answer_source'],
            $result['expression'],
            $userMessageId
        );
    }

    echo json_encode([
        'success' => true,
        'conversation_id' => $conversationId,
        'message_id' => $botMessageId,
        'answer' => $result['answer'],
        'expression' => $result['expression'],
        'answer_source' => $result['answer_source'],
        'used_deep_research' => $result['used_deep_research'],
        'gemini_key_missing' => $result['gemini_key_missing'],
        'personal_provider' => $result['personal_provider'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log(sprintf(
        '[Roxy] request failed: %s in %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    emsJsonAbort(500, [
        'success' => false,
        'message' => 'Roxy sedang mengalami gangguan koneksi sementara. Pesan Anda belum dapat diproses. Coba ulangi beberapa saat lagi.',
        'error_code' => 'roxy_temporary_failure',
    ]);
}
