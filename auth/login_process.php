<?php
// =====================================================
// LOGIN PROCESS — FINAL VERSION (ANTI DOUBLE LOGIN)
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/../config/database.php';

// =====================================================
// AMBIL INPUT (NORMAL / FORCE LOGIN)
// =====================================================
$force = isset($_POST['force_login']);

// Jika force login, ambil dari session (BUKAN dari input)
if ($force && isset($_SESSION['pending_login'])) {
    $full_name = $_SESSION['pending_login']['full_name'];
    $pin       = $_SESSION['pending_login']['pin'];

    // Bersihkan data sementara
    unset($_SESSION['pending_login']);
} else {
    $full_name = trim($_POST['full_name'] ?? '');
    $pin       = trim($_POST['pin'] ?? '');
}

// Validasi awal
if ($full_name === '' || $pin === '') {
    $_SESSION['error'] = 'Form login tidak valid';
    header("Location: login.php");
    exit;
}

// =====================================================
// CARI USER
// =====================================================
$stmt = $pdo->prepare("SELECT * FROM user_rh WHERE full_name = ? LIMIT 1");
$stmt->execute([$full_name]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($pin, $user['pin'])) {
    $_SESSION['error'] = 'Nama atau PIN salah';
    header("Location: login.php");
    exit;
}

// =====================================================
// CEK VERIFIKASI AKUN
// =====================================================
if ((int)$user['is_verified'] === 0) {
    $_SESSION['error'] = 'Akun belum diverifikasi';
    header("Location: login.php");
    exit;
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
        'pin'       => $pin
    ];

    header("Location: login.php?confirm=1");
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
$_SESSION['user_rh'] = [
    'id'       => $user['id'],
    'name'     => $user['full_name'],
    'role'     => $user['role'],
    'position' => $user['position']
];

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
    time() + (86400 * 365),
    '/',
    '',
    false,
    true // HttpOnly
);

// =====================================================
// REDIRECT BERDASARKAN POSITION
// =====================================================
$position = strtolower(trim($user['position'] ?? ''));

// trainee → dashboard
if ($position === 'trainee') {
    header("Location: /dashboard/index.php");
    exit;
}

// selain trainee → rekap farmasi
header("Location: /dashboard/rekap_farmasi.php");
exit;
