<?php
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/birthday_helper.php';

header('Content-Type: application/json; charset=UTF-8');

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

emsRequireRateLimit('birthday_notices', emsCurrentRequestIdentifier($userId), 20, 60, 'Permintaan notifikasi ulang tahun terlalu sering.');
ems_birthday_ensure_evening_inbox($pdo);

if (ems_birthday_viewer_is_celebrating_today($pdo, $userId)) {
    echo json_encode([
        'success' => true,
        'date_key' => date('Y-m-d'),
        'items' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$celebrants = ems_birthday_fetch_today_celebrants($pdo, $userId);
$items = array_map(static function (array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['full_name'] ?? 'Medis'),
        'position' => ems_position_label((string)($row['position'] ?? '')),
        'division' => ems_normalize_division((string)($row['division'] ?? '')) ?: '-',
        'zodiac' => ems_birthday_zodiac_label($row['tanggal_lahir_ic'] ?? null),
    ];
}, $celebrants);

echo json_encode([
    'success' => true,
    'date_key' => date('Y-m-d'),
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
