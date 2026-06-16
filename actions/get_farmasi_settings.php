<?php
session_start();
require_once __DIR__ . '/../config/runtime.php';
emsApplyProductionPhpIni(emsRuntimeLogPath('app_runtime.log'));
require_once __DIR__ . '/../auth/auth_guard.php';
require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

function canAccessFarmasiSettingsByDivision(?string $division): bool
{
    $normalized = strtolower(trim((string)$division));
    $compact = str_replace([' ', '_', '-'], '', $normalized);

    $allowed = [
        'generalaffair',
        'executive',
        'comitedisiplin',
        'disciplinarycommittee',
    ];

    return in_array($compact, $allowed, true);
}

header('Content-Type: application/json');

$user = $_SESSION['user_rh'] ?? null;

if (!$user || empty($user['id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Hanya divisi tertentu yang boleh mengedit settings
$canAccessSettings = canAccessFarmasiSettingsByDivision($user['division'] ?? '');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

try {
    $stmt = $pdo->prepare("SELECT max_online_medics, max_duty_minutes, cooldown_minutes, updated_at FROM farmasi_online_settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $settings = [
            'max_online_medics' => 0,
            'max_duty_minutes' => 0,
            'cooldown_minutes' => 0,
            'updated_at' => null
        ];
    }

    $maxDutyMinutes = (int)($settings['max_duty_minutes'] ?? 0);
    $hours = intdiv($maxDutyMinutes, 60);
    $minutes = $maxDutyMinutes % 60;
    $settings['max_duty_duration'] = $maxDutyMinutes > 0
        ? sprintf('%02d:%02d:00', $hours, $minutes)
        : '';

    echo json_encode([
        'success' => true,
        'settings' => $settings,
        'can_edit' => $canAccessSettings
    ]);
} catch (Throwable $e) {
    error_log('[get_farmasi_settings] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal memuat settings']);
}
