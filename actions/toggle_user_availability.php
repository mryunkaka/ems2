<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/training_groups.php';

header('Content-Type: application/json');

emsRequireJsonCsrf();

if (empty($_SESSION['user_rh']['id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$status = trim((string)($payload['status'] ?? ''));
if (!in_array($status, ['online', 'offline'], true)) {
    echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
    exit;
}

$userId = (int)$_SESSION['user_rh']['id'];
$unitCode = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);

if (!ems_training_availability_tables_ready($pdo)) {
    echo json_encode(['success' => false, 'message' => 'Jalankan SQL docs/sql/40_2026-05-16_training_user_availability.sql terlebih dahulu.']);
    exit;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        INSERT INTO training_user_availability
            (user_id, status, last_activity_at, last_confirm_at, current_session_number)
        VALUES
            (?, ?, NOW(), NOW(), 0)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            last_activity_at = NOW(),
            last_confirm_at = NOW(),
            current_session_number = CASE
                WHEN VALUES(status) = 'offline' THEN 0
                ELSE current_session_number
            END,
            updated_at = NOW()
    ");
    $stmt->execute([$userId, $status]);

    if ($status === 'online') {
        $userStmt = $pdo->prepare("
            SELECT full_name, position
            FROM user_rh
            WHERE id = ?
            LIMIT 1
        ");
        $userStmt->execute([$userId]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            throw new RuntimeException('User tidak ditemukan.');
        }

        $sessionCheck = $pdo->prepare("
            SELECT id
            FROM training_user_availability_sessions
            WHERE user_id = ?
              AND session_end IS NULL
            LIMIT 1
        ");
        $sessionCheck->execute([$userId]);

        if (!$sessionCheck->fetchColumn()) {
            $nextSessionStmt = $pdo->prepare("
                SELECT COALESCE(MAX(session_number), 0) + 1
                FROM training_user_availability_sessions
                WHERE user_id = ?
            ");
            $nextSessionStmt->execute([$userId]);
            $sessionNumber = (int)$nextSessionStmt->fetchColumn();

            $insertSession = $pdo->prepare("
                INSERT INTO training_user_availability_sessions
                    (user_id, session_start, session_number)
                VALUES
                    (?, NOW(), ?)
            ");
            $insertSession->execute([$userId, $sessionNumber]);

            $pdo->prepare("
                UPDATE training_user_availability
                SET current_session_number = ?
                WHERE user_id = ?
            ")->execute([$sessionNumber, $userId]);
        }
    } else {
        $closeStmt = $pdo->prepare("
            UPDATE training_user_availability_sessions
            SET
                session_end = NOW(),
                duration_seconds = TIMESTAMPDIFF(SECOND, session_start, NOW()),
                end_reason = 'manual_offline'
            WHERE user_id = ?
              AND session_end IS NULL
        ");
        $closeStmt->execute([$userId]);
    }

    if ($status === 'online') {
        ems_training_auto_fill_groups($pdo, $unitCode, null);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'status' => $status,
        'assignments' => ems_training_fetch_user_active_assignments($pdo, $userId),
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Gagal memperbarui availability.',
    ]);
}
