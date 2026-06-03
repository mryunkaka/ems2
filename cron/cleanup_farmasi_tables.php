<?php

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/farmasi_cleanup.php';

date_default_timezone_set('Asia/Jakarta');

try {
    $result = farmasi_cleanup_old_data($pdo);
    echo sprintf(
        "CLEANUP OK: farmasi_activities=%d, farmasi_quiz_session_questions=%d\n",
        (int)($result['farmasi_activities_deleted'] ?? 0),
        (int)($result['farmasi_quiz_session_questions_deleted'] ?? 0)
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'CLEANUP ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
