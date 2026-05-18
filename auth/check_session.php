<?php
session_start();
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

function checkSessionRememberLoginCookieOptions(int $expires): array
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

$hasSession = isset($_SESSION['user_rh']);
$rememberCookie = $_COOKIE['remember_login'] ?? '';

if (!$hasSession && $rememberCookie === '') {
    echo json_encode(['valid' => false]);
    exit;
}

if ($rememberCookie === '') {
    echo json_encode(['valid' => true]);
    exit;
}

$cookieParts = explode(':', $rememberCookie, 2);
if (count($cookieParts) !== 2 || $cookieParts[0] === '' || $cookieParts[1] === '') {
    echo json_encode(['valid' => $hasSession]);
    exit;
}

[$userId, $token] = $cookieParts;

$stmt = $pdo->prepare("
    SELECT token_hash
    FROM remember_tokens
    WHERE user_id = ?
      AND expired_at > NOW()
");
$stmt->execute([$userId]);
$tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($tokens as $row) {
    if (password_verify($token, $row['token_hash'])) {
        echo json_encode(['valid' => true]);
        exit;
    }
}

session_destroy();
setcookie('remember_login', '', checkSessionRememberLoginCookieOptions(time() - 3600));

echo json_encode(['valid' => false]);
exit;
