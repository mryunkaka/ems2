<?php
session_start();
require_once __DIR__ . '/../config/runtime.php';
emsApplyProductionPhpIni(emsRuntimeLogPath('app_runtime.log'));
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
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

emsRequireJsonCsrf();

$user = $_SESSION['user_rh'] ?? null;

if (!$user || empty($user['id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Hanya divisi tertentu yang boleh menyimpan settings
$canEditSettings = canAccessFarmasiSettingsByDivision($user['division'] ?? '');

if (!$canEditSettings) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$maxOnlineMedics = isset($data['max_online_medics']) ? (int)$data['max_online_medics'] : 0;
$cooldownMinutes = isset($data['cooldown_minutes']) ? (int)$data['cooldown_minutes'] : 0;
$rawMaxDutyDuration = trim((string)($data['max_duty_duration'] ?? ''));
$maxDutyMinutes = isset($data['max_duty_minutes']) ? (int)$data['max_duty_minutes'] : 0;

if ($rawMaxDutyDuration !== '' && $rawMaxDutyDuration !== '0') {
    if (!preg_match('/^(?:(\d+):)?([0-5]?\d):([0-5]?\d)$/', $rawMaxDutyDuration, $matches)) {
        echo json_encode(['success' => false, 'message' => 'Format maksimal waktu jaga harus HH:MM:SS atau H:MM:SS']);
        exit;
    }

    $hours = isset($matches[1]) && $matches[1] !== '' ? (int)$matches[1] : 0;
    $minutes = (int)$matches[2];
    $seconds = (int)$matches[3];
    $totalSeconds = ($hours * 3600) + ($minutes * 60) + $seconds;
    $maxDutyMinutes = (int)ceil($totalSeconds / 60);
}

// Validasi nilai (tidak boleh negatif)
$maxOnlineMedics = max(0, $maxOnlineMedics);
$maxDutyMinutes = max(0, $maxDutyMinutes);
$cooldownMinutes = max(0, $cooldownMinutes);

try {
    $stmt = $pdo->prepare("
        INSERT INTO farmasi_online_settings (id, max_online_medics, max_duty_minutes, cooldown_minutes, updated_by)
        VALUES (1, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            max_online_medics = VALUES(max_online_medics),
            max_duty_minutes = VALUES(max_duty_minutes),
            cooldown_minutes = VALUES(cooldown_minutes),
            updated_by = VALUES(updated_by),
            updated_at = NOW()
    ");
    $stmt->execute([$maxOnlineMedics, $maxDutyMinutes, $cooldownMinutes, $user['id']]);

    // Log activity
    try {
        $description = sprintf(
            'Update setting Medis Online - Max Medis: %d, Max Waktu: %d menit, Cooldown: %d menit',
            $maxOnlineMedics,
            $maxDutyMinutes,
            $cooldownMinutes
        );

        $logActivity = $pdo->prepare("
            INSERT INTO farmasi_activities 
                (activity_type, medic_user_id, medic_name, description)
            VALUES (?, ?, ?, ?)
        ");
        $logActivity->execute([
            'settings_update',
            $user['id'],
            $user['name'] ?? 'Admin',
            $description
        ]);
    } catch (Exception $e) {
        error_log('[SETTINGS LOG ERROR] ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Settings berhasil disimpan',
        'settings' => [
            'max_online_medics' => $maxOnlineMedics,
            'max_duty_minutes' => $maxDutyMinutes,
            'cooldown_minutes' => $cooldownMinutes
        ]
    ]);
} catch (Throwable $e) {
    error_log('[save_farmasi_settings] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan settings']);
}
