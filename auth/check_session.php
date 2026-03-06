<?php
session_start();
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Jika tidak ada session login → anggap logout
if (!isset($_SESSION['user_rh'], $_COOKIE['remember_login'])) {
    echo json_encode(['valid' => false]);
    exit;
}

[$userId, $token] = explode(':', $_COOKIE['remember_login'], 2);

// Cari token di DB
$stmt = $pdo->prepare("
    SELECT token_hash 
    FROM remember_tokens
    WHERE user_id = ?
      AND expired_at > NOW()
");
$stmt->execute([$userId]);
$tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cocokkan token
foreach ($tokens as $row) {
    if (password_verify($token, $row['token_hash'])) {
        echo json_encode(['valid' => true]);
        exit;
    }
}

// Token tidak ditemukan → SESSION DICABUT
session_destroy();
setcookie('remember_login', '', time() - 3600, '/');

echo json_encode(['valid' => false]);
exit;
