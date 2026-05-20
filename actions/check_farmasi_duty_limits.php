<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

$user = $_SESSION['user_rh'] ?? null;

if (!$user || empty($user['id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int)$user['id'];

try {
    // Ambil settings
    $stmtSettings = $pdo->prepare("SELECT max_duty_minutes, cooldown_minutes FROM farmasi_online_settings LIMIT 1");
    $stmtSettings->execute();
    $settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);

    $maxDutyMinutes = (int)($settings['max_duty_minutes'] ?? 0);
    $cooldownMinutes = (int)($settings['cooldown_minutes'] ?? 0);

    // Check current online duration for this session
    $stmtSession = $pdo->prepare("
        SELECT 
            TIMESTAMPDIFF(SECOND, session_start, NOW()) as current_session_seconds
        FROM user_farmasi_sessions
        WHERE user_id = ?
          AND session_end IS NULL
        LIMIT 1
    ");
    $stmtSession->execute([$userId]);
    $sessionData = $stmtSession->fetch(PDO::FETCH_ASSOC);

    $currentSessionSeconds = (int)($sessionData['current_session_seconds'] ?? 0);
    $currentSessionMinutes = floor($currentSessionSeconds / 60);

    // Check if should auto-offline due to max duty time
    $shouldAutoOffline = false;
    $autoOfflineReason = '';

    if ($maxDutyMinutes > 0 && $currentSessionSeconds >= ($maxDutyMinutes * 60)) {
        $shouldAutoOffline = true;
        $autoOfflineReason = 'max_duty_time';
    }

    // Check cooldown
    $cooldownRemaining = 0;
    if ($cooldownMinutes > 0) {
        $stmtCooldown = $pdo->prepare("
            SELECT 
                TIMESTAMPDIFF(SECOND, session_end, NOW()) as seconds_since_offline
            FROM user_farmasi_sessions
            WHERE user_id = ?
              AND session_end IS NOT NULL
            ORDER BY session_end DESC
            LIMIT 1
        ");
        $stmtCooldown->execute([$userId]);
        $cooldownData = $stmtCooldown->fetch(PDO::FETCH_ASSOC);

        if ($cooldownData) {
            $secondsSinceOffline = (int)($cooldownData['seconds_since_offline'] ?? 0);
            $cooldownSeconds = $cooldownMinutes * 60;

            if ($secondsSinceOffline < $cooldownSeconds) {
                $cooldownRemaining = $cooldownSeconds - $secondsSinceOffline;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'current_session_seconds' => $currentSessionSeconds,
        'current_session_minutes' => $currentSessionMinutes,
        'max_duty_minutes' => $maxDutyMinutes,
        'max_duty_seconds' => $maxDutyMinutes * 60,
        'should_auto_offline' => $shouldAutoOffline,
        'auto_offline_reason' => $autoOfflineReason,
        'cooldown_remaining_seconds' => $cooldownRemaining,
        'cooldown_remaining_minutes' => ceil($cooldownRemaining / 60)
    ]);

} catch (Throwable $e) {
    error_log('[check_farmasi_duty_limits] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error checking limits']);
}
