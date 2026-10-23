<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/ai_queue.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$requesterId = (int)($_SESSION['user_rh']['id'] ?? 0);
$csrfValid = validateCsrfToken(csrfRequestToken());
if (!ems_ai_queue_browser_request_allowed((string)($_SERVER['REQUEST_METHOD'] ?? ''), $requesterId, $csrfValid)) {
    $status = $requesterId <= 0 ? 401 : (((string)($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ? 405 : 403);
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $status === 401 ? 'Sesi login telah berakhir.' : ($status === 405 ? 'Metode tidak diizinkan.' : 'Token keamanan kedaluwarsa.')], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();
require_once __DIR__ . '/../config/database.php';
if (!defined('EMS_AI_QUEUE_BROWSER_WORKER')) define('EMS_AI_QUEUE_BROWSER_WORKER', true);
@set_time_limit(120);

try {
    $summary = ems_ai_queue_run_one($pdo);
    echo json_encode(ems_ai_queue_browser_response($summary), JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('AI browser queue worker failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Worker belum dapat memproses antrean.'], JSON_UNESCAPED_UNICODE);
}
