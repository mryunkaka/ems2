<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_queue.php';
require_once __DIR__ . '/../actions/ai_guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    ems_require_programmer_roxwood_access();
    if (!ems_table_exists($pdo, 'system_ai_request_queue')) {
        echo json_encode(['ok' => true, 'available' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'available' => true] + ems_ai_queue_monitor($pdo), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Status antrean AI tidak dapat dimuat.'], JSON_UNESCAPED_UNICODE);
}
