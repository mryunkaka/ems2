<?php
// =====================================================
// LOGIN PROCESS — FINAL VERSION (ANTI DOUBLE LOGIN)
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

function buildRememberLoginCookieOptions(int $expires): array
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

function loginRateLimitDir(): string
{
    return __DIR__ . '/../storage/cache/login_rate_limit';
}

function loginRateLimitEnsureDir(): void
{
    $dir = loginRateLimitDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function loginRateLimitPath(string $scope, string $identifier): string
{
    return loginRateLimitDir() . '/' . hash('sha256', $scope . '|' . trim(strtolower($identifier))) . '.json';
}

function loginRateLimitRead(string $scope, string $identifier): array
{
    loginRateLimitEnsureDir();
    $path = loginRateLimitPath($scope, $identifier);
    if (!is_file($path)) {
        return ['attempts' => [], 'blocked_until' => 0];
    }

    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return ['attempts' => [], 'blocked_until' => 0];
    }

    return [
        'attempts' => array_values(array_filter(array_map('intval', $data['attempts'] ?? []))),
        'blocked_until' => (int)($data['blocked_until'] ?? 0),
    ];
}

function loginRateLimitWrite(string $scope, string $identifier, array $data): void
{
    loginRateLimitEnsureDir();
    @file_put_contents(
        loginRateLimitPath($scope, $identifier),
        json_encode($data, JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function loginRateLimitPrune(array $attempts, int $windowSeconds, int $now): array
{
    return array_values(array_filter($attempts, static function (int $timestamp) use ($windowSeconds, $now): bool {
        return $timestamp > ($now - $windowSeconds);
    }));
}

function loginRateLimitBlocked(string $scope, string $identifier, int $now): bool
{
    $state = loginRateLimitRead($scope, $identifier);
    if ((int)$state['blocked_until'] > $now) {
        return true;
    }

    $attempts = loginRateLimitPrune($state['attempts'], 900, $now);
    if ($attempts !== $state['attempts']) {
        loginRateLimitWrite($scope, $identifier, [
            'attempts' => $attempts,
            'blocked_until' => 0,
        ]);
    }

    return false;
}

function loginRateLimitRegisterFailure(string $scope, string $identifier, int $now): void
{
    $state = loginRateLimitRead($scope, $identifier);
    $attempts = loginRateLimitPrune($state['attempts'], 900, $now);
    $attempts[] = $now;

    loginRateLimitWrite($scope, $identifier, [
        'attempts' => $attempts,
        'blocked_until' => count($attempts) >= 5 ? ($now + 900) : 0,
    ]);
}

function loginRateLimitClear(string $scope, string $identifier): void
{
    $path = loginRateLimitPath($scope, $identifier);
    if (is_file($path)) {
        @unlink($path);
    }
}

$requestedLoginUnit = ems_normalize_unit_code($_POST['login_unit'] ?? $_GET['unit'] ?? 'roxwood');
$requestedLoginUrl = 'login.php?unit=' . urlencode($requestedLoginUnit);

// =====================================================
// AMBIL INPUT (NORMAL / FORCE LOGIN)
// =====================================================
$force = isset($_POST['force_login']);

// Jika force login, ambil dari session (BUKAN dari input)
if ($force && isset($_SESSION['pending_login'])) {
    $full_name = $_SESSION['pending_login']['full_name'];
    $pin       = $_SESSION['pending_login']['pin'];
    $requestedLoginUnit = ems_normalize_unit_code($_SESSION['pending_login']['login_unit'] ?? $requestedLoginUnit);
    $requestedLoginUrl = 'login.php?unit=' . urlencode($requestedLoginUnit);

    // Bersihkan data sementara
    unset($_SESSION['pending_login']);
} else {
    $full_name = trim($_POST['full_name'] ?? '');
    $pin       = trim($_POST['pin'] ?? '');
}

// Validasi awal
if ($full_name === '' || $pin === '') {
    $_SESSION['error'] = 'Form login tidak valid';
    header('Location: ' . $requestedLoginUrl);
    exit;
}

$remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$now = time();
$nameRateKey = $full_name !== '' ? $full_name : 'unknown';

if (loginRateLimitBlocked('ip', $remoteIp, $now) || loginRateLimitBlocked('name_ip', $remoteIp . '|' . $nameRateKey, $now)) {
    $_SESSION['error'] = 'Terlalu banyak percobaan login. Coba lagi 15 menit lagi.';
    header('Location: ' . $requestedLoginUrl);
    exit;
}

// =====================================================
// CARI USER
// =====================================================
$stmt = $pdo->prepare("SELECT * FROM user_rh WHERE full_name = ? LIMIT 1");
$stmt->execute([$full_name]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($pin, $user['pin'])) {
    loginRateLimitRegisterFailure('ip', $remoteIp, $now);
    loginRateLimitRegisterFailure('name_ip', $remoteIp . '|' . $nameRateKey, $now);
    $_SESSION['error'] = 'Nama atau PIN salah';
    header('Location: ' . $requestedLoginUrl);
    exit;
}

$userLoginUnit = ems_normalize_unit_code($user['unit_code'] ?? $requestedLoginUnit);
$userLoginUrl = 'login.php?unit=' . urlencode($userLoginUnit);

loginRateLimitClear('ip', $remoteIp);
loginRateLimitClear('name_ip', $remoteIp . '|' . $nameRateKey);

// =====================================================
// CEK VERIFIKASI AKUN
// =====================================================
if ((int)$user['is_verified'] === 0) {
    $_SESSION['error'] = 'Akun belum diverifikasi';
    header('Location: ' . $userLoginUrl);
    exit;
}

// =====================================================
// CEK STATUS AKTIF USER (REIGNED/NONAKTIF)
// =====================================================
if ((int)$user['is_active'] === 0) {
    $hasResigned = !empty($user['resigned_at']) || !empty(trim((string)($user['resign_reason'] ?? '')));
    $_SESSION['error'] = $hasResigned
        ? 'Akun Anda sudah dinonaktifkan. Hubungi administrator.'
        : 'Akun Anda belum aktif. Silakan tunggu aktivasi manager.';
    header('Location: ' . $userLoginUrl);
    exit;
}

// =====================================================
// CEK STATUS CUTI (INFO ONLY - TIDAK BLOKIR LOGIN)
// =====================================================
if (is_user_on_cuti($pdo, $user['id'])) {
    // Set info session untuk ditampilkan di dashboard
    $_SESSION['cuti_info'] = [
        'on_cuti' => true,
        'message' => 'Anda sedang dalam masa cuti. Sistem tetap dapat diakses.'
    ];
}

// =====================================================
// CEK LOGIN DI DEVICE LAIN
// =====================================================
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM remember_tokens
    WHERE user_id = ?
      AND expired_at > NOW()
");
$stmt->execute([$user['id']]);
$activeToken = (int)$stmt->fetchColumn();

// Jika masih ada token aktif & belum force login
if ($activeToken > 0 && !$force) {

    // Simpan data login sementara (AMAN, TIDAK DI HTML)
    $_SESSION['pending_login'] = [
        'full_name' => $full_name,
        'pin'       => $pin,
        'login_unit' => $userLoginUnit,
    ];

    header('Location: login.php?confirm=1&unit=' . urlencode($userLoginUnit));
    exit;
}

// =====================================================
// PAKSA LOGOUT DEVICE LAIN (HAPUS SEMUA TOKEN LAMA)
// =====================================================
$pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?")
    ->execute([$user['id']]);

// =====================================================
// SET SESSION LOGIN
// =====================================================
session_regenerate_id(true);

$_SESSION['user_rh'] = [
    'id'       => $user['id'],
    'name'     => $user['full_name'],
    'role'     => $user['role'],
    'position' => ems_normalize_position($user['position'] ?? ''),
    'division' => ems_resolve_user_division($user['division'] ?? '', $user['position'] ?? ''),
    'unit_code' => $userLoginUnit,
    'can_view_all_units' => isset($user['can_view_all_units']) && (int)$user['can_view_all_units'] === 1 ? 1 : 0,
    'tanggal_lahir_ic' => $user['tanggal_lahir_ic'] ?? null,
];
$_SESSION['ems_active_unit'] = $userLoginUnit;

// =====================================================
// SIMPAN REMEMBER TOKEN BARU (1 TAHUN)
// =====================================================
$token = bin2hex(random_bytes(32));
$hash  = password_hash($token, PASSWORD_DEFAULT);
$exp   = date('Y-m-d H:i:s', strtotime('+365 days'));

$stmt = $pdo->prepare("
    INSERT INTO remember_tokens (user_id, token_hash, expired_at)
    VALUES (?, ?, ?)
");
$stmt->execute([$user['id'], $hash, $exp]);

setcookie(
    'remember_login',
    $user['id'] . ':' . $token,
    buildRememberLoginCookieOptions(time() + (86400 * 365))
);

// =====================================================
// REDIRECT BERDASARKAN POSITION
// =====================================================
$position = ems_normalize_position($user['position'] ?? '');

// trainee → dashboard
if ($position === 'trainee') {
    header("Location: /dashboard/index.php");
    exit;
}

// selain trainee → rekap farmasi
$requiresTanggalLahirIc = ems_column_exists($pdo, 'user_rh', 'tanggal_lahir_ic');
if ($requiresTanggalLahirIc && trim((string)($user['tanggal_lahir_ic'] ?? '')) === '') {
    $_SESSION['flash_errors'][] = 'Tanggal lahir IC sesuai KTP wajib diisi dulu sebelum akses jualan farmasi.';
    header("Location: /dashboard/setting_akun.php");
    exit;
}

header("Location: /dashboard/rekap_farmasi.php");
exit;
