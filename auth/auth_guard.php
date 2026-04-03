<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

function authGuardUserRhHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM user_rh LIKE ?");
    $stmt->execute([$column]);
    $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

if (isset($_SESSION['user_rh'])) {
    $uid = (int)($_SESSION['user_rh']['id'] ?? 0);
    if ($uid > 0) {
        try {
            $hasDivisionColumn = authGuardUserRhHasColumn($pdo, 'division');
            $hasUnitCodeColumn = authGuardUserRhHasColumn($pdo, 'unit_code');
            $hasCanViewAllUnitsColumn = authGuardUserRhHasColumn($pdo, 'can_view_all_units');
            $divisionSelect = $hasDivisionColumn ? ', division' : '';
            $unitSelect = $hasUnitCodeColumn ? ', unit_code' : '';
            $canViewAllUnitsSelect = $hasCanViewAllUnitsColumn ? ', can_view_all_units' : '';
            $stmt = $pdo->prepare("
                SELECT role, position, full_name, cuti_status, cuti_start_date, cuti_end_date{$divisionSelect}{$unitSelect}{$canViewAllUnitsSelect}
                FROM user_rh 
                WHERE id = ? 
                LIMIT 1
            ");
            $stmt->execute([$uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $_SESSION['user_rh']['role'] = $row['role'] ?? ($_SESSION['user_rh']['role'] ?? '');
                $_SESSION['user_rh']['position'] = ems_normalize_position($row['position'] ?? '');
                $_SESSION['user_rh']['cuti_status'] = $row['cuti_status'] ?? null;
                $_SESSION['user_rh']['cuti_start_date'] = $row['cuti_start_date'] ?? null;
                $_SESSION['user_rh']['cuti_end_date'] = $row['cuti_end_date'] ?? null;
                $_SESSION['user_rh']['division'] = ems_resolve_user_division(
                    $row['division'] ?? ($_SESSION['user_rh']['division'] ?? ''),
                    $row['position'] ?? ($_SESSION['user_rh']['position'] ?? '')
                );
                $_SESSION['user_rh']['unit_code'] = ems_normalize_unit_code($row['unit_code'] ?? ($_SESSION['user_rh']['unit_code'] ?? 'roxwood'));
                $_SESSION['user_rh']['can_view_all_units'] = isset($row['can_view_all_units'])
                    ? ((int)$row['can_view_all_units'] === 1 ? 1 : 0)
                    : ($_SESSION['user_rh']['can_view_all_units'] ?? 0);
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

    $currentScript = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    $currentPath = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
    if ($currentScript !== '' && str_contains($currentPath, '/dashboard/')) {
        ems_enforce_dashboard_page_access(
            $_SESSION['user_rh']['division'] ?? '',
            $currentScript,
            '/dashboard/index.php'
        );
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
            'position' => ems_normalize_position($user['position'] ?? ''),
            'division' => ems_resolve_user_division($user['division'] ?? '', $user['position'] ?? ''),
            'unit_code' => ems_normalize_unit_code($user['unit_code'] ?? 'roxwood'),
            'can_view_all_units' => isset($user['can_view_all_units']) && (int)$user['can_view_all_units'] === 1 ? 1 : 0,
            'cuti_status' => $user['cuti_status'] ?? null,
            'cuti_start_date' => $user['cuti_start_date'] ?? null,
            'cuti_end_date' => $user['cuti_end_date'] ?? null
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
