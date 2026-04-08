<?php
date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/helpers.php';
require __DIR__ . '/../config/farmasi_quiz.php';

try {
    farmasi_quiz_finalize_previous_weeks($pdo);
    echo "FARMASI QUIZ WEEKLY FINALIZED\n";
} catch (Throwable $e) {
    error_log('[cron_finalize_farmasi_quiz_weekly] ' . $e->getMessage());
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
