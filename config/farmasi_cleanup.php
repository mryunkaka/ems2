<?php

function farmasi_cleanup_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $cache[$table] = (bool)$stmt->fetchColumn();

    return $cache[$table];
}

function farmasi_cleanup_old_data(PDO $pdo): array
{
    $result = [
        'farmasi_activities_deleted' => 0,
        'farmasi_quiz_session_questions_deleted' => 0,
        'skipped' => false,
    ];

    $lockPath = dirname(__DIR__) . '/cron/farmasi_cleanup.lock';
    $lockHandle = fopen($lockPath, 'c');
    if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        $result['skipped'] = true;
        return $result;
    }

    try {
        if (farmasi_cleanup_table_exists($pdo, 'farmasi_activities')) {
            $stmt = $pdo->prepare("
                DELETE FROM farmasi_activities
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
            ");
            $stmt->execute();
            $result['farmasi_activities_deleted'] = $stmt->rowCount();
        }

        if (
            farmasi_cleanup_table_exists($pdo, 'farmasi_quiz_session_questions') &&
            farmasi_cleanup_table_exists($pdo, 'farmasi_quiz_sessions')
        ) {
            $stmt = $pdo->prepare("
                DELETE sq
                FROM farmasi_quiz_session_questions sq
                INNER JOIN farmasi_quiz_sessions s ON s.id = sq.session_id
                WHERE s.completed_at IS NOT NULL
                  AND s.pass_status IN ('passed', 'failed')
            ");
            $stmt->execute();
            $result['farmasi_quiz_session_questions_deleted'] = $stmt->rowCount();
        }
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    return $result;
}
