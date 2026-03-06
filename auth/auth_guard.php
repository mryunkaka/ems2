<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/database.php';

if (isset($_SESSION['user_rh'])) {
    return;
}

if (!empty($_COOKIE['remember_login'])) {

    [$userId, $token] = explode(':', $_COOKIE['remember_login'], 2);

    $stmt = $pdo->prepare("
        SELECT * FROM remember_tokens
        WHERE user_id = ?
          AND expired_at > NOW()
    ");
    $stmt->execute([$userId]);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($tokens as $row) {
        if (password_verify($token, $row['token_hash'])) {

            $stmt = $pdo->prepare("SELECT * FROM user_rh WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $_SESSION['user_rh'] = [
                    'id'       => $user['id'],
                    'name'     => $user['full_name'],
                    'role'     => $user['role'],
                    'position' => $user['position']
                ];
                return;
            }
        }
    }
}

// Cookie invalid → hapus
setcookie('remember_login', '', time() - 3600, '/');
header("Location: /auth/login.php");
exit;
