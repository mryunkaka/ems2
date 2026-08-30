<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';

header('Content-Type: application/json; charset=UTF-8');

emsRequireRateLimit('roxy_conversations', emsCurrentRequestIdentifier((int) ($_SESSION['user_rh']['id'] ?? 0)), 60, 60, 'Terlalu sering. Coba lagi nanti.');

ems_roxy_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$userId = (int) ($user['id'] ?? 0);
if ($userId <= 0) {
    emsJsonAbort(401, ['success' => false, 'message' => 'Sesi tidak valid.']);
}

$action = trim((string) ($_GET['action'] ?? 'list'));

if ($action === 'list') {
    $stmt = $pdo->prepare("
        SELECT id, title, last_message_at
        FROM bot_conversations
        WHERE user_id = ? AND status = 'active'
        ORDER BY last_message_at DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);

    echo json_encode(['success' => true, 'conversations' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'messages') {
    $conversationId = (int) ($_GET['conversation_id'] ?? 0);
    $isManagerPlus = ems_is_manager_plus_role((string) ($user['role'] ?? ''));

    // Kepemilikan dicek eksplisit di WHERE — bukan cuma di query terpisah —
    // supaya user biasa tidak bisa baca riwayat percakapan orang lain lewat
    // conversation_id sembarangan (isolasi per-user, docs/AI_ASSISTANT_MODULE.md §4a).
    // Manager-plus dikecualikan dari filter user_id — mereka memang berhak
    // lihat riwayat SEMUA medis lewat halaman monitoring (§4c).
    if ($isManagerPlus) {
        $stmt = $pdo->prepare("SELECT id FROM bot_conversations WHERE id = ? LIMIT 1");
        $stmt->execute([$conversationId]);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM bot_conversations WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$conversationId, $userId]);
    }
    if (!$stmt->fetchColumn()) {
        emsJsonAbort(404, ['success' => false, 'message' => 'Percakapan tidak ditemukan.']);
    }

    $messages = ems_roxy_get_conversation_messages($pdo, $conversationId);

    echo json_encode(['success' => true, 'messages' => $messages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

emsJsonAbort(400, ['success' => false, 'message' => 'Action tidak dikenali.']);
