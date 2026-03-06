<?php
// =====================================================
// LOGOUT — CLEAN & TOTAL
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// -----------------------------------------------------
// Hapus token dari database
// -----------------------------------------------------
if (!empty($_COOKIE['remember_login'])) {
    [$userId] = explode(':', $_COOKIE['remember_login'], 2);

    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
    $stmt->execute([$userId]);
}

// -----------------------------------------------------
// Hapus cookie
// -----------------------------------------------------
setcookie('remember_login', '', time() - 3600, '/');

// -----------------------------------------------------
// Destroy session
// -----------------------------------------------------
session_unset();
session_destroy();

// -----------------------------------------------------
// Redirect
// -----------------------------------------------------
session_start();
$_SESSION['success'] = 'Anda berhasil logout';
header("Location: login.php");
exit;
