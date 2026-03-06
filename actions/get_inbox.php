<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$user = $_SESSION['user_rh'] ?? null;
if (!$user) {
    echo json_encode([
        'unread' => 0,
        'items' => []
    ]);
    exit;
}

$userId = (int)$user['id'];

$stmt = $pdo->prepare("
    SELECT 
        id,
        title,
        message,
        is_read,
        created_at,
        DATE_FORMAT(created_at, '%d %b %Y %H:%i WIB') AS created_at_label
    FROM user_inbox
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 30
");
$stmt->execute([$userId]);

$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$unread = 0;
foreach ($items as $i) {
    if ((int)$i['is_read'] === 0) {
        $unread++;
    }
}

echo json_encode([
    'unread' => $unread,
    'items' => $items
]);
