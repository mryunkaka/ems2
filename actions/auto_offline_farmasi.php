<?php
session_start();
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

$user = $_SESSION['user_rh'] ?? null;

if (!$user || empty($user['id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int)$user['id'];
$fullName = $user['name'] ?? 'Unknown';

try {
    $pdo->beginTransaction();

    // Set status offline
    $stmt = $pdo->prepare("
        UPDATE user_farmasi_status
        SET status = 'offline',
            auto_offline_at = NOW(),
            current_session_number = 0,
            updated_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);

    // Close active session
    $stmtSession = $pdo->prepare("
        UPDATE user_farmasi_sessions
        SET
            session_end = NOW(),
            duration_seconds = TIMESTAMPDIFF(SECOND, session_start, NOW()),
            end_reason = 'auto_offline'
        WHERE user_id = ?
          AND session_end IS NULL
    ");
    $stmtSession->execute([$userId]);

    $pdo->commit();

    // Log activity
    try {
        $logActivity = $pdo->prepare("
            INSERT INTO farmasi_activities 
                (activity_type, medic_user_id, medic_name, description)
            VALUES (?, ?, ?, ?)
        ");
        $logActivity->execute([
            'auto_offline',
            $userId,
            $fullName,
            'Auto offline: Mencapai batas waktu jaga maksimal'
        ]);
    } catch (Exception $e) {
        error_log('[AUTO OFFLINE LOG ERROR] ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Auto offline berhasil',
        'status' => 'offline'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[auto_offline_farmasi] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal auto offline']);
}
