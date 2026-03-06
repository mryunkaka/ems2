<?php
session_start();
require __DIR__ . '/../config/database.php';

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

function alphaPos($char)
{
    $char = strtoupper($char);
    if ($char < 'A' || $char > 'Z') return null;
    return ord($char) - 64;
}

function twoDigit($num)
{
    return str_pad($num, 2, '0', STR_PAD_LEFT);
}

$name   = trim($_POST['full_name'] ?? '');
$pin    = trim($_POST['pin'] ?? '');
$citizenId    = trim($_POST['citizen_id'] ?? '');
$noHpIc       = trim($_POST['no_hp_ic'] ?? '');
$jenisKelamin = $_POST['jenis_kelamin'] ?? '';
$batch  = intval($_POST['batch'] ?? 0);
$role   = $_POST['role'] ?? 'Staff';

// DEFAULT
$position = 'Trainee';

if ($name === '' || !preg_match('/^\d{4}$/', $pin)) {
    header("Location: login.php?error=Data registrasi tidak valid");
    exit;
}

if ($batch < 1 || $batch > 26) {
    $_SESSION['error'] = 'Batch tidak valid';
    header("Location: login.php");
    exit;
}

if ($citizenId === '' || $noHpIc === '' || $jenisKelamin === '') {
    $_SESSION['error'] = 'Data pribadi wajib diisi';
    header("Location: login.php");
    exit;
}

if (!in_array($jenisKelamin, ['Laki-laki', 'Perempuan'], true)) {
    $_SESSION['error'] = 'Jenis kelamin tidak valid';
    header("Location: login.php");
    exit;
}

$checkCitizen = $pdo->prepare("SELECT id FROM user_rh WHERE citizen_id = ?");
$checkCitizen->execute([$citizenId]);

if ($checkCitizen->fetch()) {
    $_SESSION['error'] = 'Citizen ID sudah terdaftar';
    header("Location: login.php");
    exit;
}


if (
    empty($_FILES['file_ktp']['tmp_name']) ||
    $_FILES['file_ktp']['error'] !== UPLOAD_ERR_OK
) {
    $_SESSION['error'] = 'KTP wajib diunggah';
    header("Location: login.php");
    exit;
}

$ktpInfo = getimagesize($_FILES['file_ktp']['tmp_name']);
if (!$ktpInfo || !in_array($ktpInfo['mime'], ['image/jpeg', 'image/png'], true)) {
    $_SESSION['error'] = 'File KTP harus JPG atau PNG';
    header('Location: login.php');
    exit;
}

$check = $pdo->prepare("SELECT id FROM user_rh WHERE full_name = ?");
$check->execute([$name]);

if ($check->fetch()) {
    $_SESSION['error'] = 'Nama sudah terdaftar';
    header("Location: login.php");
    exit;
}

$is_verified = ($role === 'Staff') ? 1 : 0;

$stmt = $pdo->prepare("
    INSERT INTO user_rh (
        full_name,
        pin,
        position,
        role,
        batch,
        citizen_id,
        no_hp_ic,
        jenis_kelamin,
        is_verified
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->execute([
    $name,
    password_hash($pin, PASSWORD_DEFAULT),
    $position,
    $role,
    $batch,
    $citizenId,
    $noHpIc,
    $jenisKelamin,
    $is_verified
]);

$userId = $pdo->lastInsertId();

// ===============================
// GENERATE KODE NOMOR INDUK RS
// FORMAT SAMA DENGAN SETTING AKUN
// ===============================
$batchCode = chr(64 + $batch); // 1 = A
$idPart    = str_pad($userId, 2, '0', STR_PAD_LEFT);

$nameParts = preg_split('/\s+/', strtoupper($name));
$firstName = $nameParts[0] ?? '';
$lastName  = $nameParts[count($nameParts) - 1] ?? '';

$letters = substr($firstName, 0, 2) . substr($lastName, 0, 2);

$nameCodes = [];
foreach (str_split($letters) as $char) {
    $pos = alphaPos($char);
    if ($pos !== null) {
        $nameCodes[] = twoDigit($pos);
    }
}

$kodeNomorInduk = 'RH' . $batchCode . '-' . $idPart . implode('', $nameCodes);

$folderName = 'user_' . $userId . '-' . strtolower($kodeNomorInduk);
$baseDir    = __DIR__ . '/../storage/user_docs/';
$uploadDir  = $baseDir . $folderName;

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    $_SESSION['error'] = 'Gagal membuat folder dokumen';
    header('Location: login.php');
    exit;
}

$docFields = ['file_ktp', 'file_sim', 'file_skb'];
$uploadedPaths = [];

foreach ($docFields as $field) {

    if (
        empty($_FILES[$field]['tmp_name']) ||
        $_FILES[$field]['error'] !== UPLOAD_ERR_OK
    ) {
        continue;
    }

    $tmp  = $_FILES[$field]['tmp_name'];
    $info = getimagesize($tmp);

    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        $_SESSION['error'] = "File {$field} harus JPG atau PNG";
        header('Location: login.php');
        exit;
    }

    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $finalPath = $uploadDir . '/' . $field . '.' . $ext;

    if (!compressImageSmart($tmp, $finalPath)) {
        $_SESSION['error'] = "Gagal memproses {$field}";
        header('Location: login.php');
        exit;
    }

    $uploadedPaths[$field] =
        'storage/user_docs/' . $folderName . '/' . $field . '.' . $ext;
}

$sql = "UPDATE user_rh SET kode_nomor_induk_rs = ?";
$params = [$kodeNomorInduk];

foreach ($uploadedPaths as $col => $path) {
    $sql .= ", {$col} = ?";
    $params[] = $path;
}

$sql .= " WHERE id = ?";
$params[] = $userId;

$pdo->prepare($sql)->execute($params);

$_SESSION['success'] = 'Registrasi berhasil';
header("Location: login.php");
exit;
