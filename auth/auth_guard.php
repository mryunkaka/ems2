<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if (isset($_SESSION['user_rh'])) {
    $uid = (int)($_SESSION['user_rh']['id'] ?? 0);
    if ($uid > 0) {
        try {
            $stmt = $pdo->prepare("SELECT role, position, full_name FROM user_rh WHERE id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $_SESSION['user_rh']['role'] = $row['role'] ?? ($_SESSION['user_rh']['role'] ?? '');
                $_SESSION['user_rh']['position'] = ems_normalize_position($row['position'] ?? '');
                // Keep backward-compatible name keys in sync
                if (!empty($row['full_name'])) {
                    $_SESSION['user_rh']['name'] = $row['full_name'];
                    $_SESSION['user_rh']['full_name'] = $row['full_name'];
                }
            } else {
                // User deleted
                unset($_SESSION['user_rh']);
            }
        } catch (Throwable $e) {
            // Soft-fail: still normalize whatever is in session
            $_SESSION['user_rh']['position'] = ems_normalize_position($_SESSION['user_rh']['position'] ?? '');
        }
    } else {
        $_SESSION['user_rh']['position'] = ems_normalize_position($_SESSION['user_rh']['position'] ?? '');
    }
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
                    'position' => ems_normalize_position($user['position'] ?? '')
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
