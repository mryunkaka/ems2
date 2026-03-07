<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

try {
    $userId = (int)($_SESSION['user_rh']['id'] ?? 0);

    date_default_timezone_set('Asia/Jakarta');

    $currentHour = (int)date('H');
    $isAfternoonPeak = ($currentHour >= 15 && $currentHour < 18);
    $isNightPeak = ($currentHour >= 21 || $currentHour < 3);

    if ($isAfternoonPeak || $isNightPeak) {
        ems_json_response([
            'active' => false,
            'reason' => 'peak_hours',
        ]);
    }

    $cooldownSeconds = 60;

    if ($userId <= 0) {
        ems_json_response(['active' => false]);
    }

    $stmt = $pdo->query("
        SELECT medic_user_id, medic_name, created_at
        FROM sales
        WHERE DATE(created_at) = CURDATE()
        ORDER BY created_at DESC
        LIMIT 1
    ");

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        ems_json_response(['active' => false]);
    }

    $lastMedicId = (int)$row['medic_user_id'];
    $lastTime = strtotime($row['created_at']);
    $remain = $cooldownSeconds - (time() - $lastTime);

    if ($remain <= 0 || $lastMedicId !== $userId) {
        ems_json_response(['active' => false]);
    }

    ems_json_response([
        'active' => true,
        'remain' => $remain,
        'last_by' => $row['medic_name'],
    ]);
} catch (Throwable $e) {
    error_log('[get_global_cooldown] ' . $e->getMessage());
    ems_json_error('Failed to load cooldown status', 503, ['active' => false]);
}
