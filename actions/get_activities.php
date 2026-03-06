<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ===============================
// QUERY AKTIVITAS TERBARU
// ===============================
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

// ===============================
// FORMAT DATA
// ===============================
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
        'timestamp' => $timestamp // ⬅️ TAMBAHKAN INI (UNIX TIMESTAMP)
    ];
}

echo json_encode($result);
