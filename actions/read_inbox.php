<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$user = $_SESSION['user_rh'] ?? null;
if (!$user || empty($_POST['id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$id = (int)$_POST['id'];
$userId = (int)$user['id'];

$stmt = $pdo->prepare("
    UPDATE user_inbox
    SET is_read = 1
    WHERE id = ? AND user_id = ?
");
$stmt->execute([$id, $userId]);

echo json_encode(['success' => true]);
