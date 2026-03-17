<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

function inboxStateTableExists(PDO $pdo): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $stmt = $pdo->query("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'user_inbox_state'
        LIMIT 1
    ");
    $exists = (bool)$stmt->fetchColumn();

    return $exists;
}

function inboxTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();

    return $cache[$key];
}

$user = $_SESSION['user_rh'] ?? null;
if (!$user) {
    echo json_encode(['success' => false]);
    exit;
}

$itemId = (int)($_POST['item_id'] ?? $_POST['id'] ?? 0);
$sourceType = trim((string)($_POST['source_type'] ?? 'user_inbox'));
$bulkAction = trim((string)($_POST['bulk_action'] ?? ''));
$userId = (int)($user['id'] ?? 0);
$userDivision = ems_normalize_division($user['division'] ?? '');

if ($userId <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

if ($bulkAction === 'delete_all') {
    $stmt = $pdo->prepare("
        DELETE FROM user_inbox
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);

    if (inboxStateTableExists($pdo)) {
        $hasIncomingDivisionScope = inboxTableHasColumn($pdo, 'incoming_letters', 'division_scope');
        $hasMinutesDivisionScope = inboxTableHasColumn($pdo, 'meeting_minutes', 'division_scope');

        $incomingWhere = $hasIncomingDivisionScope
            ? "((l.division_scope = 'All Divisi') OR l.division_scope = ?)"
            : "l.target_user_id = ?";
        $incomingParam = $hasIncomingDivisionScope
            ? ($userDivision !== '' ? $userDivision : 'All Divisi')
            : $userId;

        $stmt = $pdo->prepare("
            INSERT INTO user_inbox_state (user_id, item_type, item_id, is_read, is_deleted, read_at, deleted_at)
            SELECT ?, 'incoming_letter', l.id, 1, 1, NOW(), NOW()
            FROM incoming_letters l
            WHERE {$incomingWhere}
            ON DUPLICATE KEY UPDATE
                is_read = 1,
                is_deleted = 1,
                read_at = COALESCE(read_at, NOW()),
                deleted_at = NOW()
        ");
        $stmt->execute([$userId, $incomingParam]);

        if ($hasMinutesDivisionScope) {
            $stmt = $pdo->prepare("
                INSERT INTO user_inbox_state (user_id, item_type, item_id, is_read, is_deleted, read_at, deleted_at)
                SELECT ?, 'meeting_minutes', m.id, 1, 1, NOW(), NOW()
                FROM meeting_minutes m
                WHERE (m.division_scope = 'All Divisi' OR m.division_scope = ?)
                ON DUPLICATE KEY UPDATE
                    is_read = 1,
                    is_deleted = 1,
                    read_at = COALESCE(read_at, NOW()),
                    deleted_at = NOW()
            ");
            $stmt->execute([$userId, $userDivision !== '' ? $userDivision : 'All Divisi']);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($itemId <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

if ($sourceType === 'user_inbox') {
    $stmt = $pdo->prepare("
        DELETE FROM user_inbox
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$itemId, $userId]);

    echo json_encode(['success' => true]);
    exit;
}

if (!inboxStateTableExists($pdo)) {
    echo json_encode(['success' => false, 'message' => 'Tabel user_inbox_state belum tersedia.']);
    exit;
}

$allowedTypes = ['incoming_letter', 'meeting_minutes'];
if (!in_array($sourceType, $allowedTypes, true)) {
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO user_inbox_state (user_id, item_type, item_id, is_read, is_deleted, read_at, deleted_at)
    VALUES (?, ?, ?, 1, 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        is_read = 1,
        is_deleted = 1,
        read_at = COALESCE(read_at, NOW()),
        deleted_at = NOW()
");
$stmt->execute([$userId, $sourceType, $itemId]);

echo json_encode(['success' => true]);
