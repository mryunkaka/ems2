<?php
session_start();
require __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';

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
$unitCode = ems_normalize_unit_code($_POST['unit_code'] ?? 'roxwood');

// DEFAULT
$position = 'trainee';

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

if (!in_array($unitCode, ['roxwood', 'alta'], true)) {
    $_SESSION['error'] = 'Unit tidak valid';
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

$check = $pdo->prepare("SELECT id FROM user_rh WHERE full_name = ?");
$check->execute([$name]);

if ($check->fetch()) {
    $_SESSION['error'] = 'Nama sudah terdaftar';
    header("Location: login.php");
    exit;
}

$is_verified = ($role === 'Staff') ? 1 : 0;
$is_active = 0;

$insertColumns = [
    'full_name',
    'pin',
    'position',
    'division',
    'role',
    'batch',
    'citizen_id',
    'no_hp_ic',
    'jenis_kelamin',
    'is_verified',
    'is_active',
];
$insertValues = [
    $name,
    password_hash($pin, PASSWORD_DEFAULT),
    $position,
    'Medis',
    $role,
    $batch,
    $citizenId,
    $noHpIc,
    $jenisKelamin,
    $is_verified,
    $is_active,
];

if (ems_column_exists($pdo, 'user_rh', 'unit_code')) {
    $insertColumns[] = 'unit_code';
    $insertValues[] = $unitCode;
}

$placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
$stmt = $pdo->prepare("
    INSERT INTO user_rh (" . implode(', ', $insertColumns) . ")
    VALUES ({$placeholders})
");

$stmt->execute($insertValues);

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

$docFields = [
    'file_ktp',
    'file_skb',
    'file_sim',
    'file_kta',
    'sertifikat_heli',
    'sertifikat_operasi',
];
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
        $_SESSION['error'] = "File sertifikat harus JPG atau PNG";
        header('Location: login.php');
        exit;
    }

    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $finalPath = $uploadDir . '/' . $field . '.' . $ext;

    if (!compressImageSmart($tmp, $finalPath)) {
        $_SESSION['error'] = "Gagal memproses sertifikat";
        header('Location: login.php');
        exit;
    }

    $uploadedPaths[$field] =
        'storage/user_docs/' . $folderName . '/' . $field . '.' . $ext;
}

$academyFinal = [];
$postedIds = $_POST['academy_doc_id'] ?? [];
$postedNames = $_POST['academy_doc_name'] ?? [];
$fileBag = $_FILES['academy_doc_file'] ?? null;
$fileCount = is_array($fileBag) && isset($fileBag['name']) && is_array($fileBag['name']) ? count($fileBag['name']) : 0;
$max = max(
    is_array($postedIds) ? count($postedIds) : 0,
    is_array($postedNames) ? count($postedNames) : 0,
    $fileCount
);

for ($i = 0; $i < $max; $i++) {
    $nameDoc = is_array($postedNames) ? sanitizeAcademyDocName((string)($postedNames[$i] ?? '')) : '';
    $fileErr = $fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    $fileTmp = $fileBag['tmp_name'][$i] ?? '';

    if ($fileErr !== UPLOAD_ERR_OK || $fileTmp === '') {
        continue;
    }

    $info = getimagesize($fileTmp);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        $_SESSION['error'] = 'File lainnya harus JPG atau PNG';
        header('Location: login.php');
        exit;
    }

    $id = bin2hex(random_bytes(8));
    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $finalPath = $uploadDir . '/academy_' . $id . '.' . $ext;

    if (!compressImageSmart($fileTmp, $finalPath)) {
        $_SESSION['error'] = 'Gagal memproses file lainnya';
        header('Location: login.php');
        exit;
    }

    $academyFinal[] = [
        'id' => $id,
        'name' => $nameDoc !== '' ? $nameDoc : 'File Lainnya',
        'path' => 'storage/user_docs/' . $folderName . '/academy_' . $id . '.' . $ext,
    ];
}

ensureUserDokumenLainnyaColumnSupportsJson($pdo);
$academyJson = !empty($academyFinal)
    ? json_encode($academyFinal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    : null;

$sql = "UPDATE user_rh SET kode_nomor_induk_rs = ?";
$params = [$kodeNomorInduk];

foreach ($uploadedPaths as $col => $path) {
    $sql .= ", {$col} = ?";
    $params[] = $path;
}

if ($academyJson !== null) {
    $sql .= ", dokumen_lainnya = ?";
    $params[] = $academyJson;
}

$sql .= " WHERE id = ?";
$params[] = $userId;

$pdo->prepare($sql)->execute($params);

$_SESSION['success'] = 'Registrasi berhasil. Akun menunggu aktivasi manager sebelum bisa login.';
header("Location: login.php");
exit;
