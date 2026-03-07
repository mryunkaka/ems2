<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

try {
    $stmt = $pdo->query("
        SELECT
            id,
            activity_type,
            medic_name,
            description,
            created_at
        FROM farmasi_activities
        ORDER BY created_at DESC
        LIMIT 10
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];

    foreach ($rows as $row) {
        $timestamp = strtotime($row['created_at']);
        $diff = time() - $timestamp;

        if ($diff < 60) {
            $timeAgo = 'Baru saja';
        } elseif ($diff < 3600) {
            $timeAgo = floor($diff / 60) . ' menit lalu';
        } elseif ($diff < 86400) {
            $timeAgo = floor($diff / 3600) . ' jam lalu';
        } else {
            $timeAgo = date('d M H:i', $timestamp);
        }

        $result[] = [
            'id' => $row['id'],
            'type' => $row['activity_type'],
            'medic_name' => $row['medic_name'],
            'description' => $row['description'],
            'time_ago' => $timeAgo,
            'timestamp' => $timestamp,
        ];
    }

    ems_json_response($result);
} catch (Throwable $e) {
    error_log('[get_activities] ' . $e->getMessage());
    ems_json_error('Failed to load activities', 503, ['items' => []]);
}
