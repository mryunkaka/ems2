<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';

$sessionUser = $_SESSION['user_rh'] ?? [];
$sessionRole = strtolower(trim((string)($sessionUser['role'] ?? '')));

if (ems_is_staff_role($sessionRole)) {
    $_SESSION['flash_errors'][] = 'Akses ditolak.';
    header('Location: /dashboard/input_dokumen_medis.php');
    exit;
}

function compressManagerDocImage(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 1200,
    int $targetSize = 300000,
    int $minQuality = 70
): bool {
    $info = getimagesize($sourcePath);
    if (!$info) {
        return false;
    }

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
            if (filesize($targetPath) <= $targetSize) {
                break;
            }
        }
    }

    imagedestroy($dst);
    return true;
}

function deleteManagerDocIfExists(?string $dbPath): void
{
    $dbPath = trim((string)$dbPath);
    if ($dbPath === '') {
        return;
    }

    $fullPath = __DIR__ . '/../' . $dbPath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function managerDocNormalizeName(?string $name): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', (string)$name)));
}

$targetUserId = (int)($_POST['medic_user_id'] ?? 0);
if ($targetUserId <= 0) {
    $_SESSION['flash_errors'][] = 'Pilih medis aktif terlebih dahulu.';
    header('Location: /dashboard/input_dokumen_medis.php');
    exit;
}

$stmtTarget = $pdo->prepare("
    SELECT
        id,
        full_name,
        kode_nomor_induk_rs,
        is_active,
        file_ktp,
        file_sim,
        file_kta,
        file_skb,
        sertifikat_heli,
        sertifikat_operasi,
        dokumen_lainnya
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
$stmtTarget->execute([$targetUserId]);
$targetUser = $stmtTarget->fetch(PDO::FETCH_ASSOC);

if (!$targetUser || (int)($targetUser['is_active'] ?? 0) !== 1) {
    $_SESSION['flash_errors'][] = 'Medis aktif tidak ditemukan atau sudah tidak aktif.';
    header('Location: /dashboard/input_dokumen_medis.php');
    exit;
}

$docFields = [
    'file_ktp',
    'file_sim',
    'file_kta',
    'file_skb',
    'sertifikat_heli',
    'sertifikat_operasi',
];

$hasAnyUpload = false;
foreach ($docFields as $field) {
    if (!empty($_FILES[$field]['tmp_name']) && (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $hasAnyUpload = true;
        break;
    }
}

$academyFileBag = $_FILES['academy_doc_file'] ?? null;
if (!$hasAnyUpload && is_array($academyFileBag) && isset($academyFileBag['error']) && is_array($academyFileBag['error'])) {
    foreach ($academyFileBag['error'] as $academyErr) {
        if ((int)$academyErr === UPLOAD_ERR_OK) {
            $hasAnyUpload = true;
            break;
        }
    }
}

if (!$hasAnyUpload) {
    $_SESSION['flash_errors'][] = 'Tidak ada dokumen yang dipilih untuk diunggah.';
    header('Location: /dashboard/input_dokumen_medis.php?medic_id=' . $targetUserId);
    exit;
}

$kodeMedis = trim((string)($targetUser['kode_nomor_induk_rs'] ?? ''));
$folderName = 'user_' . $targetUserId . '-' . strtolower($kodeMedis !== '' ? $kodeMedis : 'no-kode');
$baseDir = __DIR__ . '/../storage/user_docs/';
$uploadDir = $baseDir . $folderName;

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    $_SESSION['flash_errors'][] = 'Gagal membuat folder dokumen medis.';
    header('Location: /dashboard/input_dokumen_medis.php?medic_id=' . $targetUserId);
    exit;
}

$uploadedPaths = [];
foreach ($docFields as $field) {
    if (empty($_FILES[$field]['tmp_name']) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        continue;
    }

    deleteManagerDocIfExists($targetUser[$field] ?? null);

    $tmp = $_FILES[$field]['tmp_name'];
    $info = getimagesize($tmp);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        $_SESSION['flash_errors'][] = "File {$field} harus JPG atau PNG.";
        header('Location: /dashboard/input_dokumen_medis.php?medic_id=' . $targetUserId);
        exit;
    }

    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $finalPath = $uploadDir . '/' . $field . '.' . $ext;
    if (!compressManagerDocImage($tmp, $finalPath)) {
        $_SESSION['flash_errors'][] = "Gagal memproses {$field}.";
        header('Location: /dashboard/input_dokumen_medis.php?medic_id=' . $targetUserId);
        exit;
    }

    $uploadedPaths[$field] = 'storage/user_docs/' . $folderName . '/' . $field . '.' . $ext;
}

$existingOtherDocs = ensureAcademyDocIds(parseAcademyDocs($targetUser['dokumen_lainnya'] ?? ''));
$existingByName = [];
foreach ($existingOtherDocs as $existingDoc) {
    $docNameKey = managerDocNormalizeName($existingDoc['name'] ?? '');
    if ($docNameKey !== '') {
        $existingByName[$docNameKey] = $existingDoc;
    }
}

$postedNames = $_POST['academy_doc_name'] ?? [];
$fileBag = $_FILES['academy_doc_file'] ?? null;
$fileCount = is_array($fileBag) && isset($fileBag['name']) && is_array($fileBag['name']) ? count($fileBag['name']) : 0;
$maxRows = max(is_array($postedNames) ? count($postedNames) : 0, $fileCount);

$otherDocsFinal = $existingOtherDocs;

for ($i = 0; $i < $maxRows; $i++) {
    $name = is_array($postedNames) ? sanitizeAcademyDocName((string)($postedNames[$i] ?? '')) : '';
    $fileErr = $fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    $fileTmp = $fileBag['tmp_name'][$i] ?? '';

    if ($name === '' && (int)$fileErr !== UPLOAD_ERR_OK) {
        continue;
    }

    if ((int)$fileErr !== UPLOAD_ERR_OK || $fileTmp === '') {
        continue;
    }

    $info = getimagesize($fileTmp);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        $_SESSION['flash_errors'][] = 'File lainnya harus JPG atau PNG.';
        header('Location: /dashboard/input_dokumen_medis.php?medic_id=' . $targetUserId);
        exit;
    }

    $normalizedName = managerDocNormalizeName($name);
    $matchedDoc = ($normalizedName !== '' && isset($existingByName[$normalizedName])) ? $existingByName[$normalizedName] : null;
    $docId = $matchedDoc ? (string)($matchedDoc['id'] ?? '') : '';
    if ($matchedDoc) {
        deleteManagerDocIfExists($matchedDoc['path'] ?? null);
    }
    if ($docId === '') {
        $docId = bin2hex(random_bytes(8));
    }

    $ext = $info['mime'] === 'image/png' ? 'png' : 'jpg';
    $finalPath = $uploadDir . '/academy_' . $docId . '.' . $ext;
    if (!compressManagerDocImage($fileTmp, $finalPath)) {
        $_SESSION['flash_errors'][] = 'Gagal memproses file lainnya.';
        header('Location: /dashboard/input_dokumen_medis.php?medic_id=' . $targetUserId);
        exit;
    }

    $savedPath = 'storage/user_docs/' . $folderName . '/academy_' . $docId . '.' . $ext;
    $finalName = $name !== '' ? $name : ($matchedDoc['name'] ?? 'File Lainnya');

    $replaced = false;
    foreach ($otherDocsFinal as &$existingFinalDoc) {
        if ((string)($existingFinalDoc['id'] ?? '') === $docId) {
            $existingFinalDoc['name'] = $finalName !== '' ? $finalName : 'File Lainnya';
            $existingFinalDoc['path'] = $savedPath;
            $replaced = true;
            break;
        }
    }
    unset($existingFinalDoc);

    if (!$replaced) {
        $otherDocsFinal[] = [
            'id' => $docId,
            'name' => $finalName !== '' ? $finalName : 'File Lainnya',
            'path' => $savedPath,
        ];
    }
}

$otherDocsJson = json_encode(array_values($otherDocsFinal), JSON_UNESCAPED_UNICODE);
if ($otherDocsJson === false) {
    $_SESSION['flash_errors'][] = 'Gagal menyimpan data file lainnya.';
    header('Location: /dashboard/input_dokumen_medis.php?medic_id=' . $targetUserId);
    exit;
}

ensureUserDokumenLainnyaColumnSupportsJson($pdo);

$sql = "UPDATE user_rh SET dokumen_lainnya = ?";
$params = [$otherDocsJson];
foreach ($uploadedPaths as $column => $path) {
    $sql .= ", {$column} = ?";
    $params[] = $path;
}
$sql .= " WHERE id = ?";
$params[] = $targetUserId;

try {
    $stmtUpdate = $pdo->prepare($sql);
    $stmtUpdate->execute($params);
    $_SESSION['flash_messages'][] = 'Dokumen medis untuk ' . ($targetUser['full_name'] ?? 'user') . ' berhasil diunggah.';
    header('Location: /dashboard/input_dokumen_medis.php?medic_id=' . $targetUserId);
    exit;
} catch (Throwable $e) {
    error_log('[INPUT DOKUMEN MEDIS] ' . $e->getMessage());
    $_SESSION['flash_errors'][] = 'Gagal menyimpan dokumen medis: ' . $e->getMessage();
    header('Location: /dashboard/input_dokumen_medis.php?medic_id=' . $targetUserId);
    exit;
}
