<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../auth/csrf.php';

$redirectTo = ems_url('/dashboard/sertifikat_heli.php');
ems_require_general_affair_manager_access($redirectTo);

function heliUpdateRedirect(string $message, bool $error = false): never
{
    $_SESSION[$error ? 'flash_errors' : 'flash_messages'][] = $message;
    header('Location: ' . ems_url('/dashboard/sertifikat_heli.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    heliUpdateRedirect('Permintaan tidak valid atau sesi formulir telah berakhir.', true);
}

$userId = (int)($_POST['user_id'] ?? 0);
$validFrom = trim((string)($_POST['sertifikat_heli_valid_from'] ?? ''));
$validUntil = trim((string)($_POST['sertifikat_heli_valid_until'] ?? ''));
if ($userId < 1) heliUpdateRedirect('Data user tidak valid.', true);

foreach ([$validFrom, $validUntil] as $date) {
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)))) {
        heliUpdateRedirect('Tanggal sertifikat tidak valid.', true);
    }
}
if (($validFrom === '') !== ($validUntil === '') || ($validFrom !== '' && $validUntil < $validFrom)) {
    heliUpdateRedirect('Tanggal expired harus sama dengan atau setelah tanggal mulai berlaku.', true);
}

$userStmt = $pdo->prepare('SELECT sertifikat_heli FROM user_rh WHERE id = ? LIMIT 1');
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) heliUpdateRedirect('User tidak ditemukan.', true);
$oldPath = (string)($user['sertifikat_heli'] ?? '');

$file = $_FILES['sertifikat_heli'] ?? ['error' => UPLOAD_ERR_NO_FILE];
$hasUpload = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
$newPath = null;
if ($hasUpload) {
    if ((int)$file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        heliUpdateRedirect('Upload dokumen gagal. Silakan pilih ulang file JPG atau PNG.', true);
    }
    if (emsUploadedFileExceedsLimit($file)) {
        heliUpdateRedirect('Ukuran dokumen maksimal ' . emsUploadLimitLabel() . '.', true);
    }
    if ($validFrom === '' || $validUntil === '') {
        heliUpdateRedirect('Tanggal mulai dan expired wajib diisi saat mengganti dokumen.', true);
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo || !in_array($imageInfo['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
        heliUpdateRedirect('Dokumen harus berupa gambar JPG atau PNG.', true);
    }
    $extension = $imageInfo['mime'] === 'image/png' ? 'png' : 'jpg';
    $newPath = 'storage/user_docs/heli_certificates/heli_' . $userId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    if (!compressImageSmart($file['tmp_name'], __DIR__ . '/../' . $newPath)) {
        @unlink(__DIR__ . '/../' . $newPath);
        heliUpdateRedirect('Gagal memproses gambar sertifikat.', true);
    }
}

$deleteOld = !$hasUpload && (($_POST['delete_certificate'] ?? '') === '1');
try {
    $sql = 'UPDATE user_rh SET sertifikat_heli_valid_from = ?, sertifikat_heli_valid_until = ?';
    $params = [$validFrom !== '' ? $validFrom : null, $validUntil !== '' ? $validUntil : null];
    if ($hasUpload) {
        $sql .= ', sertifikat_heli = ?';
        $params[] = $newPath;
    } elseif ($deleteOld) {
        $sql .= ', sertifikat_heli = NULL';
    }
    $sql .= ' WHERE id = ?';
    $params[] = $userId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (Throwable $e) {
    if ($newPath !== null) @unlink(__DIR__ . '/../' . $newPath);
    error_log('Heli certificate update failed: ' . $e->getMessage());
    heliUpdateRedirect('Gagal menyimpan perubahan sertifikat.', true);
}

if (($hasUpload || $deleteOld) && $oldPath !== '' && $oldPath !== $newPath) {
    $storageRoot = realpath(__DIR__ . '/../storage/user_docs');
    $oldFile = realpath(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $oldPath), '/'));
    if ($storageRoot && $oldFile && str_starts_with($oldFile, $storageRoot . DIRECTORY_SEPARATOR) && is_file($oldFile)) {
        @unlink($oldFile);
    }
}

heliUpdateRedirect($hasUpload ? 'Dokumen dan tanggal sertifikat berhasil diperbarui.' : ($deleteOld ? 'Dokumen sertifikat berhasil dihapus.' : 'Tanggal sertifikat berhasil diperbarui.'));
