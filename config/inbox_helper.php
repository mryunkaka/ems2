<?php
function sendInbox(PDO $pdo, int $userId, string $title, string $message, string $type = 'system')
{
    $stmt = $pdo->prepare("
        INSERT INTO user_inbox (user_id, title, message, type)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $title, $message, $type]);
}
