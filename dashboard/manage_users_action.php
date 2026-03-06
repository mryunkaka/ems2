<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

$sessionUser = $_SESSION['user_rh'] ?? [];
$sessionRole = $sessionUser['role'] ?? '';

if ($sessionRole === 'Staff') {
    $_SESSION['flash_errors'][] = 'Akses ditolak.';
    header('Location: manage_users.php');
    exit;
}

$action = $_POST['action'] ?? '';

/* =========================================================
   TAMBAH USER BARU
   ========================================================= */
/* =========================================================
   TAMBAH USER BARU (AUTO KODE MEDIS)
   ========================================================= */
if ($action === 'add_user') {

    $name     = trim($_POST['full_name'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $role     = trim($_POST['role'] ?? '');
    $batch    = (int)($_POST['batch'] ?? 0);

    if ($name === '' || $position === '' || $role === '') {
        $_SESSION['flash_errors'][] = 'Semua field wajib diisi.';
        header('Location: manage_users.php');
        exit;
    }

    $allowedRoles = [
        'Staff',
        'Staff Manager',
        'Manager',
        'Vice Director',
        'Director'
    ];

    if (!in_array($role, $allowedRoles, true)) {
        $_SESSION['flash_errors'][] = 'Role tidak valid.';
        header('Location: manage_users.php');
        exit;
    }

    // ===============================
    // 🔐 PIN DEFAULT
    // ===============================
    $defaultPin = '0000';

    // ===============================
    // 1️⃣ INSERT USER TANPA KODE MEDIS DULU
    // (karena butuh ID user)
    // ===============================
    $stmt = $pdo->prepare("
        INSERT INTO user_rh
            (full_name, position, role, pin, batch, is_active, is_verified)
        VALUES
            (?, ?, ?, ?, ?, 1, 1)
    ");

    $stmt->execute([
        $name,
        $position,
        $role,
        password_hash($defaultPin, PASSWORD_BCRYPT),
        $batch > 0 ? $batch : null
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    // ===============================
    // 2️⃣ GENERATE KODE MEDIS JIKA ADA BATCH
    // (SAMA PERSIS SEPERTI EDIT)
    // ===============================
    if ($batch > 0) {
        try {
            $generatedKode = generateKodeMedis($newUserId, $name, $batch);

            $pdo->prepare("
                UPDATE user_rh
                SET kode_nomor_induk_rs = ?
                WHERE id = ?
            ")->execute([$generatedKode, $newUserId]);
        } catch (Exception $e) {
            $_SESSION['flash_warnings'][] =
                'User dibuat, tetapi kode medis gagal dibuat: ' . $e->getMessage();
        }
    }

    $_SESSION['flash_messages'][] =
        'User baru berhasil ditambahkan. PIN awal: 0000';

    header('Location: manage_users.php');
    exit;
}

/**
 * Generate Kode Medis / Nomor Induk RS
 *
 * FORMAT:
 * RH{BATCH}-{ID(2 digit)}{2 huruf depan nama depan + 2 huruf depan nama belakang}
 *
 * Contoh:
 * Nama   : Michael Moore
 * Batch  : 3 (C)
 * UserID : 1
 * Hasil  : RHC-0113091315
 */
function generateKodeMedis(int $userId, string $fullName, int $batch): string
{
    // ===============================
    // 1. Batch → Huruf (1=A, 2=B, ...)
    // ===============================
    if ($batch < 1 || $batch > 26) {
        throw new Exception('Batch tidak valid');
    }

    $batchCode = chr(64 + $batch); // 3 => C

    // ===============================
    // 2. ID User → 2 digit (01, 02, 10, ...)
    // ===============================
    $idPart = str_pad((string)$userId, 2, '0', STR_PAD_LEFT);

    // ===============================
    // 3. Ambil 2 huruf depan nama depan
    //    + 2 huruf depan nama belakang
    // ===============================
    $parts = preg_split('/\s+/', strtoupper(trim($fullName)));

    $firstName = $parts[0] ?? '';
    $lastName  = $parts[count($parts) - 1] ?? '';

    $letters = substr($firstName, 0, 2) . substr($lastName, 0, 2);

    // ===============================
    // 4. Konversi huruf → angka alfabet
    //    A=01, B=02, ..., Z=26
    // ===============================
    $numberPart = '';

    foreach (str_split($letters) as $char) {
        if ($char >= 'A' && $char <= 'Z') {
            $numberPart .= str_pad((string)(ord($char) - 64), 2, '0', STR_PAD_LEFT);
        }
    }

    // ===============================
    // 5. FINAL FORMAT
    // ===============================
    return 'RH' . $batchCode . '-' . $idPart . $numberPart;
}

if ($action === 'delete_kode_medis') {

    if (!in_array($sessionRole, ['Director', 'Vice Director'], true)) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
        exit;
    }

    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'User tidak valid']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE user_rh
        SET kode_nomor_induk_rs = NULL
        WHERE id = ?
    ");
    $stmt->execute([$userId]);

    echo json_encode(['success' => true]);
    exit;
}

/* =========================================================
   PROSES RESIGN (HARUS PALING ATAS)
   ========================================================= */
if ($action === 'resign') {

    $userId = (int)($_POST['user_id'] ?? 0);
    $reason = trim($_POST['resign_reason'] ?? '');

    if ($userId <= 0 || $reason === '') {
        $_SESSION['flash_errors'][] = 'Alasan resign wajib diisi.';
        header('Location: manage_users.php');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE user_rh
        SET 
            is_active = 0,
            resign_reason = ?,
            resigned_by = ?,
            resigned_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $reason,
        $sessionUser['id'],
        $userId
    ]);

    $_SESSION['flash_messages'][] = 'User berhasil dinonaktifkan.';
    header('Location: manage_users.php');
    exit;
}

/* =========================================================
   RE-ACTIVATE USER (KEMBALI BEKERJA)
   ========================================================= */
if ($action === 'reactivate') {

    $userId = (int)($_POST['user_id'] ?? 0);
    $note   = trim($_POST['reactivate_note'] ?? '');

    if ($userId <= 0) {
        $_SESSION['flash_errors'][] = 'User tidak valid.';
        header('Location: manage_users.php');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE user_rh  
        SET
            is_active = 1,
            reactivated_at = NOW(),
            reactivated_by = ?,
            reactivated_note = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $sessionUser['id'],
        $note,
        $userId
    ]);

    $_SESSION['flash_messages'][] = 'User berhasil diaktifkan kembali.';
    header('Location: manage_users.php');
    exit;
}

/* =========================================================
   DELETE USER (PERMANEN)
   ========================================================= */
if ($action === 'delete') {

    // Hanya Director & Vice Director
    if (!in_array($sessionRole, ['Director', 'Vice Director'], true)) {
        $_SESSION['flash_errors'][] = 'Hanya Director dan Vice Director yang dapat menghapus user.';
        header('Location: manage_users.php');
        exit;
    }

    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        $_SESSION['flash_errors'][] = 'User tidak valid.';
        header('Location: manage_users.php');
        exit;
    }

    // Proteksi: tidak boleh hapus diri sendiri
    if ($userId === (int)$sessionUser['id']) {
        $_SESSION['flash_errors'][] = 'Anda tidak dapat menghapus akun sendiri.';
        header('Location: manage_users.php');
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM user_rh WHERE id = ?");
    $stmt->execute([$userId]);

    $_SESSION['flash_messages'][] = 'User berhasil dihapus permanen.';
    header('Location: manage_users.php');
    exit;
}

/* =========================================================
   PROSES EDIT USER (FIX FINAL)
   ========================================================= */
$userId   = (int)($_POST['user_id'] ?? 0);
$name     = trim($_POST['full_name'] ?? '');
$position = trim($_POST['position'] ?? '');
$newRole  = trim($_POST['role'] ?? '');
$newPin   = $_POST['new_pin'] ?? '';
$batch    = (int)($_POST['batch'] ?? 0);

$allowedRoles = [
    'Staff',
    'Staff Manager',
    'Manager',
    'Vice Director',
    'Director'
];

if (!in_array($newRole, $allowedRoles, true)) {
    $_SESSION['flash_errors'][] = 'Role tidak valid.';
    header('Location: manage_users.php');
    exit;
}

if ($userId <= 0 || $name === '' || $position === '' || $newRole === '') {
    $_SESSION['flash_errors'][] = 'Data tidak valid.';
    header('Location: manage_users.php');
    exit;
}

/* ===============================
   Ambil kode medis lama
   =============================== */
$stmt = $pdo->prepare("SELECT kode_nomor_induk_rs FROM user_rh WHERE id = ?");
$stmt->execute([$userId]);
$currentKode = $stmt->fetchColumn();

/* ===============================
   Bangun SQL dasar DULU
   =============================== */
$sql = "UPDATE user_rh SET full_name = ?, position = ?, role = ?";
$params = [$name, $position, $newRole];

/* ===============================
   Update batch SELALU
   =============================== */
$sql .= ", batch = ?";
$params[] = $batch > 0 ? $batch : null;

/* ===============================
   Generate kode medis JIKA NULL
   =============================== */
if (empty($currentKode) && $batch > 0) {
    try {
        $generatedKode = generateKodeMedis($userId, $name, $batch);
        $sql .= ", kode_nomor_induk_rs = ?";
        $params[] = $generatedKode;
    } catch (Exception $e) {
        $_SESSION['flash_errors'][] = 'Gagal generate kode medis: ' . $e->getMessage();
        header('Location: manage_users.php');
        exit;
    }
}


/* ===============================
   Update PIN (opsional)
   =============================== */
if ($newPin !== '') {
    if (!preg_match('/^\d{4}$/', $newPin)) {
        $_SESSION['flash_errors'][] = 'PIN harus 4 digit angka.';
        header('Location: manage_users.php');
        exit;
    }
    $sql .= ", pin = ?";
    $params[] = password_hash($newPin, PASSWORD_BCRYPT);
}

/* ===============================
   WHERE & EXECUTE
   =============================== */
$sql .= " WHERE id = ?";
$params[] = $userId;

$pdo->prepare($sql)->execute($params);

/* ===============================
   FLASH & REDIRECT
   =============================== */
$_SESSION['flash_messages'][] = 'Data user berhasil diperbarui.';
header('Location: manage_users.php');
exit;
