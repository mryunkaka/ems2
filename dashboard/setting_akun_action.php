<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

/*
|--------------------------------------------------------------------------
| HARD GUARD
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';

function compressImageSmart(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 1200,
    int $targetSize = 300000,
    int $minQuality = 70
): bool {
    $info = getimagesize($sourcePath);
    if (!$info) return false;

    $mime = $info['mime'];
    if ($mime === 'image/jpeg') {
        $src = imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png') {
        $src = imagecreatefrompng($sourcePath);
    } else {
        return false;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    if ($w > $maxWidth) {
        $ratio = $maxWidth / $w;
        $nw = $maxWidth;
        $nh = (int)($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
    } else {
        $dst = $src;
    }

    if ($mime === 'image/png') {
        imagepng($dst, $targetPath, 7);
    } else {
        for ($q = 90; $q >= $minQuality; $q -= 5) {
            imagejpeg($dst, $targetPath, $q);
            if (filesize($targetPath) <= $targetSize) break;
        }
    }

    imagedestroy($dst);
    return true;
}

function slugFolder(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9\s\-]/', '', $name);
    return preg_replace('/\s+/', '-', $name);
}

/*
|--------------------------------------------------------------------------
| AMBIL USER SESSION (SISTEM LAMA)
|--------------------------------------------------------------------------
*/
$user = $_SESSION['user_rh'] ?? [];

$userId        = $user['id'] ?? 0;
$currentName   = $user['full_name'] ?? '';
$currentPos    = $user['position'] ?? '';
$currentBatch = $user['batch'] ?? null;

// ===============================
// FIX BATCH: JANGAN OVERWRITE JIKA SUDAH ADA
// ===============================
$batchFromDb = (int)($currentBatch ?? 0);

// Batch final: database adalah sumber utama
if ($batchFromDb > 0) {
    $batch = $batchFromDb;
} else {
    $batch = isset($_POST['batch']) ? (int)$_POST['batch'] : 0;
}


/*
|--------------------------------------------------------------------------
| AMBIL INPUT FORM
|--------------------------------------------------------------------------
*/
$fullName   = trim($_POST['full_name'] ?? '');
$position   = trim($_POST['position'] ?? '');
$citizenId    = trim($_POST['citizen_id'] ?? '');
$jenisKelamin = trim($_POST['jenis_kelamin'] ?? '');
$noHpIc = trim($_POST['no_hp_ic'] ?? '');

// ===============================
// VALIDASI CITIZEN ID (SERVER SIDE)
// ===============================
if ($citizenId === '') {
    $_SESSION['flash_errors'][] = 'Citizen ID wajib diisi.';
    header('Location: setting_akun.php');
    exit;
}

// Hapus spasi (jika ada yang bypass client-side)
$citizenId = str_replace(' ', '', $citizenId);

// Convert ke uppercase
$citizenId = strtoupper($citizenId);

// Validasi: hanya boleh huruf besar dan angka
if (!preg_match('/^[A-Z0-9]+$/', $citizenId)) {
    $_SESSION['flash_errors'][] = 'Citizen ID hanya boleh berisi HURUF BESAR dan ANGKA, tanpa spasi atau karakter khusus.';
    header('Location: setting_akun.php');
    exit;
}

// Validasi: minimal 6 karakter
if (strlen($citizenId) < 6) {
    $_SESSION['flash_errors'][] = 'Citizen ID minimal 6 karakter.';
    header('Location: setting_akun.php');
    exit;
}

// Validasi: harus ada minimal 1 angka
if (!preg_match('/\d/', $citizenId)) {
    $_SESSION['flash_errors'][] = 'Citizen ID harus mengandung minimal 1 angka.';
    header('Location: setting_akun.php');
    exit;
}

// Validasi: harus ada minimal 1 huruf
if (!preg_match('/[A-Z]/', $citizenId)) {
    $_SESSION['flash_errors'][] = 'Citizen ID harus mengandung minimal 1 huruf.';
    header('Location: setting_akun.php');
    exit;
}

// Validasi: tidak boleh hanya angka atau hanya huruf
if (preg_match('/^[A-Z]+$/', $citizenId)) {
    $_SESSION['flash_errors'][] = 'Citizen ID tidak boleh hanya huruf saja. Harus kombinasi huruf dan angka.';
    header('Location: setting_akun.php');
    exit;
}

if (preg_match('/^[0-9]+$/', $citizenId)) {
    $_SESSION['flash_errors'][] = 'Citizen ID tidak boleh hanya angka saja. Harus kombinasi huruf dan angka.';
    header('Location: setting_akun.php');
    exit;
}

// Validasi: tidak boleh sama dengan nama (tanpa spasi)
$fullNameClean = strtoupper(str_replace(' ', '', $fullName));
if ($citizenId === $fullNameClean) {
    $_SESSION['flash_errors'][] = 'Citizen ID tidak boleh sama dengan Nama Medis. Contoh format yang benar: RH39IQLC';
    header('Location: setting_akun.php');
    exit;
}

// ===============================
// LANJUT KE VALIDASI BERIKUTNYA
// ===============================
$oldPin     = $_POST['old_pin'] ?? '';
$newPin     = $_POST['new_pin'] ?? '';
$confirmPin = $_POST['confirm_pin'] ?? '';
$batch = intval($_POST['batch'] ?? 0);
$tanggalMasuk = $_POST['tanggal_masuk'] ?? null;

if (empty($tanggalMasuk)) {
    $_SESSION['flash_errors'][] = 'Tanggal masuk wajib diisi.';
    header('Location: setting_akun.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDATOR PIN (TEPAT 4 DIGIT ANGKA)
|--------------------------------------------------------------------------
*/
function isValidPin($pin)
{
    return is_string($pin) && preg_match('/^\d{4}$/', $pin);
}

function alphaPos($char)
{
    $char = strtoupper($char);
    if ($char < 'A' || $char > 'Z') {
        return null;
    }
    return ord($char) - 64;
}

function twoDigit($num)
{
    return str_pad($num, 2, '0', STR_PAD_LEFT);
}

/*
|--------------------------------------------------------------------------
| VALIDASI DASAR
|--------------------------------------------------------------------------
*/
if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid. Silakan login ulang.';
    header('Location: setting_akun.php');
    exit;
}

if ($noHpIc === '') {
    $_SESSION['flash_errors'][] = 'No HP IC wajib diisi.';
    header('Location: setting_akun.php');
    exit;
}

if ($fullName === '' || $position === '') {
    $_SESSION['flash_errors'][] = 'Nama dan Jabatan wajib diisi.';
    header('Location: setting_akun.php');
    exit;
}

if (!in_array($jenisKelamin, ['Laki-laki', 'Perempuan'], true)) {
    $_SESSION['flash_errors'][] = 'Jenis kelamin wajib dipilih.';
    header('Location: setting_akun.php');
    exit;
}

if ($batch <= 0) {
    $_SESSION['flash_errors'][] = 'Batch tidak valid.';
    header('Location: setting_akun.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDASI PIN (HANYA JIKA USER INGIN MENGGANTI)
|--------------------------------------------------------------------------
*/
$willChangePin = ($oldPin !== '' || $newPin !== '' || $confirmPin !== '');

if ($willChangePin) {
    // Jika salah satu field PIN diisi, semua harus diisi
    if ($oldPin === '' || $newPin === '' || $confirmPin === '') {
        $_SESSION['flash_errors'][] = 'Jika ingin mengganti PIN, semua field PIN harus diisi.';
        header('Location: setting_akun.php');
        exit;
    }

    // Validasi format PIN
    if (!isValidPin($oldPin)) {
        $_SESSION['flash_errors'][] = 'PIN lama harus 4 digit angka.';
        header('Location: setting_akun.php');
        exit;
    }

    if (!isValidPin($newPin)) {
        $_SESSION['flash_errors'][] = 'PIN baru harus 4 digit angka.';
        header('Location: setting_akun.php');
        exit;
    }

    if ($newPin !== $confirmPin) {
        $_SESSION['flash_errors'][] = 'Konfirmasi PIN baru tidak sama.';
        header('Location: setting_akun.php');
        exit;
    }

    if ($oldPin === $newPin) {
        $_SESSION['flash_errors'][] = 'PIN baru tidak boleh sama dengan PIN lama.';
        header('Location: setting_akun.php');
        exit;
    }

    // Verifikasi PIN lama dari database
    $stmt = $pdo->prepare("SELECT pin FROM user_rh WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dbUser || !password_verify($oldPin, $dbUser['pin'])) {
        $_SESSION['flash_errors'][] = 'PIN lama salah.';
        header('Location: setting_akun.php');
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT 
        sertifikat_heli,
        sertifikat_operasi,
        dokumen_lainnya
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$userDb = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$stmt = $pdo->prepare("
    SELECT kode_nomor_induk_rs 
    FROM user_rh 
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$currentKodeInduk = $stmt->fetchColumn();

// ===============================
// GENERATE KODE NOMOR INDUK RS
// RH{BATCH}-{ID(2 DIGIT)}{MI+MO}
// ===============================
$kodeNomorInduk = null;

if (empty($currentKodeInduk)) {

    // Batch → Huruf
    $batchCode = chr(64 + $batch); // 1=A

    // ID user → 2 digit
    $idPart = str_pad($userId, 2, '0', STR_PAD_LEFT);

    // Nama → 2 huruf depan nama depan + 2 huruf depan nama belakang
    $nameParts = preg_split('/\s+/', strtoupper($fullName));

    $firstName  = $nameParts[0] ?? '';
    $lastName   = $nameParts[count($nameParts) - 1] ?? '';

    $letters = substr($firstName, 0, 2) . substr($lastName, 0, 2);

    $nameCodes = [];
    foreach (str_split($letters) as $char) {
        $pos = alphaPos($char);
        if ($pos !== null) {
            $nameCodes[] = twoDigit($pos);
        }
    }

    // FINAL FORMAT
    $kodeNomorInduk = 'RH' . $batchCode . '-' . $idPart . implode('', $nameCodes);
}

// ===============================
// BUAT FOLDER DOKUMEN USER
// ===============================
$kodeMedis = $currentKodeInduk ?? $kodeNomorInduk ?? 'no-kode';
$folderName = 'user_' . $userId . '-' . strtolower($kodeMedis);

$baseDir   = __DIR__ . '/../storage/user_docs/';
$uploadDir = $baseDir . $folderName;

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    $_SESSION['flash_errors'][] = 'Gagal membuat folder dokumen.';
    header('Location: setting_akun.php');
    exit;
}

function deleteOldFileIfExists($dbPath)
{
    if (!$dbPath) return;

    $fullPath = __DIR__ . '/../' . $dbPath;
    if (file_exists($fullPath)) {
        @unlink($fullPath);
    }
}

$docFields = [
    'sertifikat_heli',
    'sertifikat_operasi',
];

$uploadedPaths = [];

foreach ($docFields as $field) {

    // Tidak upload → skip
    if (
        empty($_FILES[$field]['tmp_name']) ||
        $_FILES[$field]['error'] !== UPLOAD_ERR_OK
    ) {
        continue;
    }

    // 🔴 HAPUS FILE LAMA JIKA ADA
    if (!empty($userDb[$field])) {
        deleteOldFileIfExists($userDb[$field]);
    }

    $tmp  = $_FILES[$field]['tmp_name'];
    $info = getimagesize($tmp);

    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        $_SESSION['flash_errors'][] = "File {$field} harus JPG atau PNG.";
        header('Location: setting_akun.php');
        exit;
    }

    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $finalPath = $uploadDir . '/' . $field . '.' . $ext;

    if (!compressImageSmart($tmp, $finalPath)) {
        $_SESSION['flash_errors'][] = "Gagal memproses {$field}.";
        header('Location: setting_akun.php');
        exit;
    }

    $uploadedPaths[$field] =
        'storage/user_docs/' . $folderName . '/' . $field . '.' . $ext;
}

// ===============================
// SERTIFIKAT MEDICAL ACADEMY (MULTI)
// Disimpan sebagai JSON di kolom dokumen_lainnya
// ===============================
$existingAcademyDocs = ensureAcademyDocIds(parseAcademyDocs($userDb['dokumen_lainnya'] ?? ''));
$existingById = [];
foreach ($existingAcademyDocs as $d) {
    $existingById[(string)$d['id']] = $d;
}

$postedIds = $_POST['academy_doc_id'] ?? [];
$postedNames = $_POST['academy_doc_name'] ?? [];

$fileBag = $_FILES['academy_doc_file'] ?? null;
$fileCount = is_array($fileBag) && isset($fileBag['name']) && is_array($fileBag['name']) ? count($fileBag['name']) : 0;

$max = max(
    is_array($postedIds) ? count($postedIds) : 0,
    is_array($postedNames) ? count($postedNames) : 0,
    $fileCount
);

$academyFinal = [];
$seen = [];

for ($i = 0; $i < $max; $i++) {
    $id = is_array($postedIds) ? trim((string)($postedIds[$i] ?? '')) : '';
    $name = is_array($postedNames) ? sanitizeAcademyDocName((string)($postedNames[$i] ?? '')) : '';

    $hasFile = false;
    $fileTmp = null;
    $fileErr = null;

    if ($fileCount > 0) {
        $fileErr = $fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($fileErr === UPLOAD_ERR_OK && !empty($fileBag['tmp_name'][$i])) {
            $hasFile = true;
            $fileTmp = $fileBag['tmp_name'][$i];
        }
    }

    // Skip baris kosong
    if ($id === '' && !$hasFile && $name === '') {
        continue;
    }

    // Existing doc
    if ($id !== '' && isset($existingById[$id])) {
        $doc = $existingById[$id];
        $path = (string)($doc['path'] ?? '');
        $finalName = $name !== '' ? $name : (string)($doc['name'] ?? 'Sertifikat Academy');

        if ($hasFile) {
            if ($path !== '') deleteOldFileIfExists($path);

            $info = getimagesize($fileTmp);
            if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
                $_SESSION['flash_errors'][] = "Sertifikat Academy harus JPG atau PNG.";
                header('Location: setting_akun.php');
                exit;
            }

            $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
            $finalPath = $uploadDir . '/academy_' . $id . '.' . $ext;

            if (!compressImageSmart($fileTmp, $finalPath)) {
                $_SESSION['flash_errors'][] = "Gagal memproses Sertifikat Academy.";
                header('Location: setting_akun.php');
                exit;
            }

            $path = 'storage/user_docs/' . $folderName . '/academy_' . $id . '.' . $ext;
        }

        $academyFinal[] = [
            'id' => $id,
            'name' => $finalName !== '' ? $finalName : 'Sertifikat Academy',
            'path' => $path,
        ];
        $seen[$id] = true;
        continue;
    }

    // New doc (wajib ada file)
    if (!$hasFile) {
        continue;
    }

    if ($id === '') {
        $id = bin2hex(random_bytes(8));
    } else {
        $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
        if ($id === '') $id = bin2hex(random_bytes(8));
    }

    $info = getimagesize($fileTmp);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        $_SESSION['flash_errors'][] = "Sertifikat Academy harus JPG atau PNG.";
        header('Location: setting_akun.php');
        exit;
    }

    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $finalPath = $uploadDir . '/academy_' . $id . '.' . $ext;

    if (!compressImageSmart($fileTmp, $finalPath)) {
        $_SESSION['flash_errors'][] = "Gagal memproses Sertifikat Academy.";
        header('Location: setting_akun.php');
        exit;
    }

    $academyFinal[] = [
        'id' => $id,
        'name' => ($name !== '' ? $name : 'Sertifikat Academy'),
        'path' => 'storage/user_docs/' . $folderName . '/academy_' . $id . '.' . $ext,
    ];
    $seen[$id] = true;
}

// Keep existing yang tidak ikut terkirim (safety)
foreach ($existingAcademyDocs as $d) {
    $id = (string)($d['id'] ?? '');
    if ($id === '' || isset($seen[$id])) continue;
    $academyFinal[] = [
        'id' => $id,
        'name' => (string)($d['name'] ?? 'Sertifikat Academy'),
        'path' => (string)($d['path'] ?? ''),
    ];
}

$academyJson = json_encode($academyFinal, JSON_UNESCAPED_UNICODE);
if ($academyJson === false) {
    $_SESSION['flash_errors'][] = 'Gagal menyimpan data Sertifikat Academy.';
    header('Location: setting_akun.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| UPDATE DATA USER
|--------------------------------------------------------------------------
*/
$sql = "UPDATE user_rh 
        SET 
            full_name = ?,
            position = ?,
            tanggal_masuk = ?,
            citizen_id = ?,
            jenis_kelamin = ?,
            no_hp_ic = ?";
$params = [
    $fullName,
    $position,
    $tanggalMasuk,
    $citizenId,
    $jenisKelamin,
    $noHpIc
];

// Dokumen Academy (JSON)
$sql .= ", dokumen_lainnya = ?";
$params[] = $academyJson;

// Update batch HANYA jika sebelumnya kosong
if ($batchFromDb === 0) {
    $sql      .= ", batch = ?";
    $params[]  = $batch;
}

foreach ($uploadedPaths as $col => $path) {
    $sql      .= ", {$col} = ?";
    $params[]  = $path;
}

if ($kodeNomorInduk !== null) {
    $sql      .= ", kode_nomor_induk_rs = ?";
    $params[]  = $kodeNomorInduk;
    $_SESSION['user_rh']['kode_nomor_induk_rs'] = $kodeNomorInduk;
}

$pinChanged = 0;

if ($willChangePin && $newPin !== '') {
    $sql       .= ", pin = ?";
    $params[]   = password_hash($newPin, PASSWORD_BCRYPT);
    $pinChanged = 1;
}

$sql      .= " WHERE id = ?";
$params[]  = $userId;

// ===============================
// EKSEKUSI UPDATE + ERROR HANDLING
// ===============================
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // ✅ CEK APAKAH ADA ROW YANG BERUBAH
    $rowsAffected = $stmt->rowCount();

    if ($rowsAffected === 0) {
        // Data sama atau ada error
        // Cek apakah nama duplikat
        $checkName = $pdo->prepare("
            SELECT id FROM user_rh 
            WHERE full_name = ? AND id != ?
        ");
        $checkName->execute([$fullName, $userId]);

        if ($checkName->fetchColumn()) {
            $_SESSION['flash_errors'][] = 'Nama sudah digunakan oleh user lain. Silakan gunakan nama yang berbeda.';
            header('Location: setting_akun.php');
            exit;
        }
    }
} catch (PDOException $e) {
    // Log error untuk debugging
    error_log('UPDATE ERROR: ' . $e->getMessage());

    // Cek apakah error duplicate entry
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        $_SESSION['flash_errors'][] = 'Nama sudah digunakan oleh user lain.';
    } else {
        $_SESSION['flash_errors'][] = 'Terjadi kesalahan saat menyimpan data.';
    }

    header('Location: setting_akun.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| UPDATE SESSION (IKUT SISTEM LAMA — name & position)
|--------------------------------------------------------------------------
*/
// 🔐 FORCE RELOAD SESSION SETELAH PERUBAHAN KRITIS
forceReloadUserSession($pdo, $userId);

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE (SISTEM EMS / REKAP FARMASI)
|--------------------------------------------------------------------------
*/
if ($pinChanged) {
    $_SESSION['flash_messages'][] = 'Akun dan PIN berhasil diperbarui.';
} else {
    $_SESSION['flash_messages'][] = 'Akun berhasil diperbarui.';
}

/*
|--------------------------------------------------------------------------
| REDIRECT (PRG PATTERN)
|--------------------------------------------------------------------------
*/
header('Location: setting_akun.php');
exit;
