<?php
// =====================================================
// LOGOUT — CLEAN & TOTAL
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

function logoutRememberLoginCookieOptions(int $expires): array
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

$logoutUnit = $_SESSION['ems_active_unit'] ?? ($_SESSION['user_rh']['unit_code'] ?? 'roxwood');
$logoutUserId = isset($_SESSION['user_rh']['id']) ? (int) $_SESSION['user_rh']['id'] : null;

// -----------------------------------------------------
// Hapus token dari database
// -----------------------------------------------------
if ($logoutUserId !== null && $logoutUserId > 0) {
    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
    $stmt->execute([$logoutUserId]);
} elseif (!empty($_COOKIE['remember_login'])) {
    $cookieParts = explode(':', (string) $_COOKIE['remember_login'], 2);

    if (count($cookieParts) === 2 && ctype_digit($cookieParts[0])) {
        $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->execute([(int) $cookieParts[0]]);
    }
}

// -----------------------------------------------------
// Hapus cookie
// -----------------------------------------------------
setcookie('remember_login', '', logoutRememberLoginCookieOptions(time() - 3600));

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
header('Location: login.php?unit=' . urlencode((string)$logoutUnit));
exit;
