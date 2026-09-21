<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';

header('Content-Type: application/json; charset=UTF-8');

function settingAkunDeleteRespond(bool $ok, string $message, int $status = 200): never
{
    http_response_code($status);
    echo json_encode([
        'ok' => $ok,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function settingAkunDeleteStorageFile(string $dbPath): void
{
    $relativePath = ltrim(str_replace('\\', '/', trim($dbPath)), '/');
    if (!str_starts_with($relativePath, 'storage/')) {
        throw new RuntimeException('Lokasi file tidak valid.');
    }

    $storageRoot = realpath(__DIR__ . '/../storage');
    $fullPath = realpath(__DIR__ . '/../' . $relativePath);
    if ($storageRoot === false || $fullPath === false) {
        return;
    }

    $storageRoot = rtrim(str_replace('\\', '/', $storageRoot), '/');
    $normalizedFullPath = str_replace('\\', '/', $fullPath);
    if (!str_starts_with($normalizedFullPath, $storageRoot . '/') || !is_file($fullPath)) {
        throw new RuntimeException('Lokasi file tidak valid.');
    }

    if (!@unlink($fullPath) && is_file($fullPath)) {
        throw new RuntimeException('File gagal dihapus dari server.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    settingAkunDeleteRespond(false, 'Metode permintaan tidak valid.', 405);
}

if (!validateCsrfToken(csrfRequestToken())) {
    settingAkunDeleteRespond(false, 'Token keamanan tidak valid atau sudah kedaluwarsa.', 419);
}

$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
if ($userId <= 0) {
    settingAkunDeleteRespond(false, 'Session tidak valid. Silakan login ulang.', 401);
}

$docField = trim((string)($_POST['doc_field'] ?? ''));
$academyDocId = trim((string)($_POST['academy_doc_id'] ?? ''));

$primaryDocFields = [
    'file_ktp',
    'file_sim',
    'file_kta',
    'file_skb',
    'sertifikat_heli',
    'sertifikat_operasi',
    'sertifikat_pelatihan',
    'file_visum',
    'file_kontrak_kerja',
    'sertifikat_operasi_plastik',
    'sertifikat_operasi_kecil',
    'sertifikat_operasi_besar',
    'sertifikat_class_co_asst',
    'sertifikat_class_paramedic',
];

try {
    if ($academyDocId !== '') {
        if ($docField !== 'dokumen_lainnya') {
            settingAkunDeleteRespond(false, 'Target dokumen tambahan tidak valid.', 422);
        }

        $stmt = $pdo->prepare('SELECT dokumen_lainnya FROM user_rh WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            settingAkunDeleteRespond(false, 'User tidak ditemukan.', 404);
        }

        $docs = ensureAcademyDocIds(parseAcademyDocs($user['dokumen_lainnya'] ?? ''));
        $remainingDocs = [];
        $targetPath = null;
        $found = false;
        foreach ($docs as $doc) {
            if ((string)($doc['id'] ?? '') === $academyDocId) {
                $targetPath = trim((string)($doc['path'] ?? ''));
                $found = true;
                continue;
            }
            $remainingDocs[] = $doc;
        }

        if (!$found) {
            settingAkunDeleteRespond(false, 'Dokumen tidak ditemukan.', 404);
        }

        $newJson = json_encode($remainingDocs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($newJson === false) {
            throw new RuntimeException('Data dokumen gagal diproses.');
        }

        $update = $pdo->prepare('UPDATE user_rh SET dokumen_lainnya = ? WHERE id = ?');
        $update->execute([$newJson, $userId]);
        try {
            if ($targetPath !== '') {
                settingAkunDeleteStorageFile($targetPath);
            }
        } catch (Throwable $fileError) {
            $restore = $pdo->prepare('UPDATE user_rh SET dokumen_lainnya = ? WHERE id = ?');
            $restore->execute([(string)$user['dokumen_lainnya'], $userId]);
            throw $fileError;
        }

        settingAkunDeleteRespond(true, 'Dokumen berhasil dihapus permanen.');
    }

    if (!in_array($docField, $primaryDocFields, true) || !ems_column_exists($pdo, 'user_rh', $docField)) {
        settingAkunDeleteRespond(false, 'Jenis dokumen tidak valid.', 422);
    }

    $select = $pdo->prepare("SELECT `{$docField}` FROM user_rh WHERE id = ? LIMIT 1");
    $select->execute([$userId]);
    $oldPath = $select->fetchColumn();
    $oldPath = trim((string)$oldPath);
    if ($oldPath === '') {
        settingAkunDeleteRespond(false, 'Dokumen belum tersedia.', 404);
    }

    $dateFieldMap = [
        'sertifikat_heli' => 'tanggal_dikeluarkan_sertifikat_heli',
        'sertifikat_operasi' => 'tanggal_dikeluarkan_sertifikat_operasi',
        'sertifikat_operasi_plastik' => 'tanggal_dikeluarkan_sertifikat_operasi_plastik',
        'sertifikat_operasi_kecil' => 'tanggal_dikeluarkan_sertifikat_operasi_kecil',
        'sertifikat_operasi_besar' => 'tanggal_dikeluarkan_sertifikat_operasi_besar',
        'sertifikat_class_co_asst' => 'tanggal_dikeluarkan_sertifikat_class_co_asst',
        'sertifikat_class_paramedic' => 'tanggal_dikeluarkan_sertifikat_class_paramedic',
    ];
    $dateField = $dateFieldMap[$docField] ?? null;
    $hasDateColumn = $dateField !== null && ems_column_exists($pdo, 'user_rh', $dateField);
    $oldDateValue = null;
    if ($hasDateColumn) {
        $dateSelect = $pdo->prepare("SELECT `{$dateField}` FROM user_rh WHERE id = ? LIMIT 1");
        $dateSelect->execute([$userId]);
        $oldDateValue = $dateSelect->fetchColumn();
    }

    $setParts = ["`{$docField}` = NULL"];
    if ($hasDateColumn) {
        $setParts[] = "`{$dateField}` = NULL";
    }

    $pdo->beginTransaction();
    $update = $pdo->prepare('UPDATE user_rh SET ' . implode(', ', $setParts) . ' WHERE id = ? AND `' . $docField . '` = ?');
    $update->execute([$userId, $oldPath]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Dokumen berubah sebelum dihapus. Muat ulang halaman.');
    }
    $pdo->commit();

    try {
        settingAkunDeleteStorageFile($oldPath);
    } catch (Throwable $fileError) {
        $restoreParts = ["`{$docField}` = ?"];
        $restoreParams = [$oldPath];
        if ($hasDateColumn) {
            $restoreParts[] = "`{$dateField}` = ?";
            $restoreParams[] = $oldDateValue;
        }
        $restoreParams[] = $userId;
        $restore = $pdo->prepare('UPDATE user_rh SET ' . implode(', ', $restoreParts) . ' WHERE id = ?');
        $restore->execute($restoreParams);
        throw $fileError;
    }

    settingAkunDeleteRespond(true, 'Dokumen berhasil dihapus permanen.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[SETTING AKUN DELETE DOCUMENT] ' . $e->getMessage());
    settingAkunDeleteRespond(false, 'Dokumen gagal dihapus: ' . $e->getMessage(), 500);
}
