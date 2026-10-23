<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('EMS_AI_QUEUE_WORKER', true);
@set_time_limit(0);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_queue.php';
try {
    $summary = ems_ai_queue_run($pdo, 50);
    fwrite(STDOUT, sprintf("AI queue: %d job diproses.\n", $summary['processed']));
} catch (Throwable $error) {
    error_log('AI queue worker failed: ' . $error->getMessage());
    fwrite(STDERR, "AI queue worker gagal. Periksa log aplikasi.\n");
    exit(1);
}
