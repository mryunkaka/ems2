<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

function authGuardWantsJsonResponse(): bool
{
    $requestUri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($requestUri, '/actions/')
        || str_contains($requestUri, '/ajax/')
        || str_contains($accept, 'application/json')
        || $requestedWith === 'xmlhttprequest';
}

function authGuardAbortUnauthorized(): void
{
    if (authGuardWantsJsonResponse()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function authGuardRememberLoginCookieOptions(int $expires): array
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

function authGuardRequiresTanggalLahirIc(string $scriptName): bool
{
    return in_array($scriptName, [
        'rekap_farmasi.php',
        'rekap_farmasi_v2.php',
        'konsumen.php',
        'ems_services.php',
    ], true);
}

function authGuardRedirectTanggalLahirIcRequired(): void
{
    $_SESSION['flash_errors'][] = 'Tanggal lahir IC sesuai KTP wajib diisi dulu sebelum akses jualan farmasi.';
    header('Location: /dashboard/setting_akun.php');
    exit;
}

if (isset($_SESSION['user_rh'])) {
    $uid = (int)($_SESSION['user_rh']['id'] ?? 0);
    if ($uid > 0) {
        try {
            $hasDivisionColumn = authGuardUserRhHasColumn($pdo, 'division');
            $hasUnitCodeColumn = authGuardUserRhHasColumn($pdo, 'unit_code');
            $hasCanViewAllUnitsColumn = authGuardUserRhHasColumn($pdo, 'can_view_all_units');
            $hasTanggalLahirIcColumn = authGuardUserRhHasColumn($pdo, 'tanggal_lahir_ic');
            $divisionSelect = $hasDivisionColumn ? ', division' : '';
            $unitSelect = $hasUnitCodeColumn ? ', unit_code' : '';
            $canViewAllUnitsSelect = $hasCanViewAllUnitsColumn ? ', can_view_all_units' : '';
            $tanggalLahirIcSelect = $hasTanggalLahirIcColumn ? ', tanggal_lahir_ic' : '';
            $stmt = $pdo->prepare("
                SELECT role, position, full_name, cuti_status, cuti_start_date, cuti_end_date{$divisionSelect}{$unitSelect}{$canViewAllUnitsSelect}{$tanggalLahirIcSelect}
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
                if (array_key_exists('tanggal_lahir_ic', $row)) {
                    $_SESSION['user_rh']['tanggal_lahir_ic'] = $row['tanggal_lahir_ic'] ?? null;
                }
                if (!empty($row['full_name'])) {
                    $_SESSION['user_rh']['name'] = $row['full_name'];
                    $_SESSION['user_rh']['full_name'] = $row['full_name'];
                }
            } else {
                unset($_SESSION['user_rh']);
            }
        } catch (Throwable $e) {
            $_SESSION['user_rh']['position'] = ems_normalize_position($_SESSION['user_rh']['position'] ?? '');
        }
    } else {
        $_SESSION['user_rh']['position'] = ems_normalize_position($_SESSION['user_rh']['position'] ?? '');
    }

    $currentScript = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    $currentPath = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
    if ($currentScript !== '' && str_contains($currentPath, '/dashboard/')) {
        if (authGuardRequiresTanggalLahirIc($currentScript)) {
            $tanggalLahirIc = trim((string)($_SESSION['user_rh']['tanggal_lahir_ic'] ?? ''));
            if ($tanggalLahirIc === '') {
                authGuardRedirectTanggalLahirIcRequired();
            }
        }

        if ($currentScript !== 'sertifikat_heli_pendaftaran.php' && $currentScript !== 'sertifikat_heli_action.php') {
            ems_enforce_dashboard_page_access(
                $_SESSION['user_rh']['division'] ?? '',
                $currentScript,
                '/dashboard/index.php'
            );
        }
    }

    return;
}

if (!empty($_COOKIE['remember_login'])) {
    $cookieParts = explode(':', $_COOKIE['remember_login'], 2);

    if (count($cookieParts) === 2 && $cookieParts[0] !== '' && $cookieParts[1] !== '') {
        [$userId, $token] = $cookieParts;

        $stmt = $pdo->prepare("
            SELECT *
            FROM remember_tokens
            WHERE user_id = ?
              AND expired_at > NOW()
        ");
        $stmt->execute([$userId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tokens as $row) {
            if (!password_verify($token, $row['token_hash'])) {
                continue;
            }

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
                    'cuti_end_date' => $user['cuti_end_date'] ?? null,
                    'tanggal_lahir_ic' => $user['tanggal_lahir_ic'] ?? null,
                ];
                return;
            }
        }
    }
}

setcookie('remember_login', '', authGuardRememberLoginCookieOptions(time() - 3600));
authGuardAbortUnauthorized();
header("Location: /auth/login.php");
exit;
