<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('EMS_AI_QUEUE_WORKER', true);
@set_time_limit(0);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_queue.php';

function ems_ai_queue_recent_medical_records(PDO $pdo): int
{
    if (!ems_table_exists($pdo, 'medical_record_validation_findings')
        || !ems_column_exists($pdo, 'medical_record_validation_findings', 'severity')
        || !ems_table_exists($pdo, 'system_ai_request_queue')
        || !ems_ai_global_provider_ready($pdo)) {
        return 0;
    }

    $requesterId = (int) $pdo->query("SELECT id FROM user_rh WHERE is_active=1 AND LOWER(REPLACE(division,' ',''))='medicalaffair' ORDER BY id LIMIT 1")->fetchColumn();
    if ($requesterId <= 0) {
        return 0;
    }

    $settings = ems_ai_queue_settings($pdo);
    if (empty($settings['is_enabled'])) {
        return 0;
    }
    $queued = (int) $pdo->query("SELECT COUNT(*) FROM system_ai_request_queue WHERE status='queued'")->fetchColumn();
    $available = max(0, (int) $settings['ai_max_queue_size'] - $queued);
    if ($available === 0) {
        return 0;
    }

    $hasScope = ems_column_exists($pdo, 'medical_records', 'visibility_scope');
    $sql = "SELECT r.id FROM medical_records r WHERE r.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) "
        . ($hasScope ? "AND COALESCE(r.visibility_scope,'standard')='standard' " : '')
        . "AND NOT EXISTS (SELECT 1 FROM medical_record_validation_findings f WHERE f.medical_record_id=r.id AND f.source='ai' AND f.rule_key LIKE 'ai_v2_%') "
        // shortcut: each record gets one automatic retry; investigate repeated failures in AI monitoring.
        . "AND (SELECT COUNT(*) FROM system_ai_request_queue q WHERE q.job_type='medical_record_validation' AND q.dedupe_key=SHA2(CONCAT('medical-record-validation-v2:',r.id),256)) < 2 "
        . 'ORDER BY r.created_at DESC LIMIT ' . min(100, $available);

    $enqueued = 0;
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $recordId) {
        try {
            ems_ai_queue_enqueue($pdo, 'medical_record_validation', $requesterId, 'Validasi awal rekam medis', ['requester_id' => $requesterId, 'record_id' => (int) $recordId], 'medical-record-validation-v2:' . (int) $recordId);
            $enqueued++;
        } catch (Throwable $error) {
            error_log('Medical record audit auto-queue: ' . $error->getMessage());
            break;
        }
    }

    return $enqueued;
}

try {
    $autoQueued = ems_ai_queue_recent_medical_records($pdo);
    $summary = ems_ai_queue_run($pdo, 50);
    fwrite(STDOUT, sprintf("AI queue: %d audit rekam medis baru diantrekan; %d job diproses.\n", $autoQueued, $summary['processed']));
} catch (Throwable $error) {
    error_log('AI queue worker failed: ' . $error->getMessage());
    fwrite(STDERR, "AI queue worker gagal. Periksa log aplikasi.\n");
    exit(1);
}
