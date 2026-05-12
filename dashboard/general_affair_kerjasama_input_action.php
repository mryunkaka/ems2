<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/general_affair_cooperation_helper.php';


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$effectiveUnit = ems_effective_unit($pdo, $user);
$action = trim((string)($_POST['action'] ?? ''));

function gaInputRedirect(string $fallback = 'general_affair_kerjasama_input.php'): void
{
    $redirectTo = trim((string)($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '' || strpos($redirectTo, '://') !== false || str_starts_with($redirectTo, '//')) {
        $redirectTo = $fallback;
    }

    header('Location: ' . $redirectTo);
    exit;
}

function gaInputMarker(): string
{
    return 'ga_cooperation_input';
}

function gaInputTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $cache[$table] = (bool)$stmt->fetchColumn();

    return $cache[$table];
}

function gaInputGenerateCode(int $userId): string
{
    return 'GACOOP-' . date('Ymd-His') . '-' . $userId . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function gaInputCompressImage(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 800,
    int $targetSize = 300000,
    int $minQuality = 50
): bool {
    $info = getimagesize($sourcePath);
    if (!$info) {
        return false;
    }

    $mime = $info['mime'] ?? '';
    if ($mime === 'image/jpeg') {
        $src = imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png') {
        $src = imagecreatefrompng($sourcePath);
    } else {
        return false;
    }

    if (!$src) {
        return false;
    }

    $width = imagesx($src);
    $height = imagesy($src);

    if ($width > $maxWidth) {
        $ratio = $maxWidth / $width;
        $newWidth = $maxWidth;
        $newHeight = (int)($height * $ratio);
        $dst = imagecreatetruecolor($newWidth, $newHeight);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($src);
    } else {
        $dst = $src;
    }

    for ($quality = 90; $quality >= $minQuality; $quality -= 5) {
        imagejpeg($dst, $targetPath, $quality);
        if (is_file($targetPath) && filesize($targetPath) <= $targetSize) {
            break;
        }
    }

    imagedestroy($dst);
    return is_file($targetPath);
}

function gaInputBuildDocumentDateTime(string $documentDate, string $documentTime): string
{
    $documentDate = trim($documentDate);
    $documentTime = trim($documentTime);

    if ($documentDate === '' || $documentTime === '') {
        throw new RuntimeException('Tanggal dan jam dokumen wajib diisi.');
    }

    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $documentDate . ' ' . $documentTime);
    if (!$dateTime) {
        throw new RuntimeException('Format tanggal atau jam dokumen tidak valid.');
    }

    return $dateTime->format('Y-m-d H:i:s');
}

function gaInputFetchCooperationConfig(PDO $pdo, int $cooperationId, string $unitCode): array
{
    $stmt = $pdo->prepare("
        SELECT
            gc.id,
            gc.institution_name,
            gc.period_type,
            gc.notes,
            COUNT(gcm.id) AS total_members
        FROM general_affair_cooperations gc
        LEFT JOIN general_affair_cooperation_members gcm
            ON gcm.cooperation_id = gc.id
           AND gcm.is_active = 1
        WHERE gc.id = :id
          AND gc.unit_code = :unit_code
        GROUP BY gc.id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $cooperationId,
        ':unit_code' => $unitCode,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) {
        throw new RuntimeException('Setting instansi kerja sama tidak ditemukan.');
    }

    $notesMeta = gaCooperationParseNotesMeta((string)($row['notes'] ?? ''));
    $claimScope = (string)($notesMeta['claim_scope'] ?? 'per_person');
    $totalMembers = max(0, (int)($row['total_members'] ?? 0));
    $maxTransactions = $claimScope === 'per_institution' ? 1 : $totalMembers;

    if ($claimScope === 'per_person' && $maxTransactions <= 0) {
        throw new RuntimeException('Setting kerja sama per orang harus memiliki minimal satu anggota aktif.');
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'institution_name' => (string)($row['institution_name'] ?? ''),
        'period_type' => (string)($row['period_type'] ?? 'daily'),
        'claim_scope' => $claimScope,
        'total_members' => $totalMembers,
        'max_transactions' => max(1, $maxTransactions),
    ];
}

function gaInputCountExistingTransactions(PDO $pdo, int $cooperationId, string $startDateTime, string $endDateTime): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM secretary_file_records
        WHERE file_category = 'cooperation'
          AND COALESCE(keywords, '') LIKE :marker
          AND COALESCE(keywords, '') LIKE :cooperation_marker
          AND document_date BETWEEN :start_date AND :end_date
          AND COALESCE(status, 'draft') <> 'archived'
    ");
    $stmt->execute([
        ':marker' => '%' . gaInputMarker() . '%',
        ':cooperation_marker' => '%cooperation_id:' . $cooperationId . '%',
        ':start_date' => $startDateTime,
        ':end_date' => $endDateTime,
    ]);

    return (int)$stmt->fetchColumn();
}

function gaInputStoreAttachment(PDO $pdo, int $recordId, array $file, string $label, int $sortOrder): string
{
    $tmpPath = (string)($file['tmp_name'] ?? '');
    $info = $tmpPath !== '' ? getimagesize($tmpPath) : false;
    if (!$info || !in_array((string)($info['mime'] ?? ''), ['image/jpeg', 'image/png'], true)) {
        throw new RuntimeException('File ' . $label . ' harus berupa JPG atau PNG.');
    }

    $uploadDir = __DIR__ . '/../storage/general_affair/cooperation_inputs/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Folder upload kerja sama tidak bisa dibuat.');
    }

    $safeLabel = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?: 'file');
    $filename = $safeLabel . '_' . time() . '_' . $recordId . '_' . substr(md5((string)random_int(1000, 999999)), 0, 8) . '.jpg';
    $finalPath = $uploadDir . $filename;

    if (!gaInputCompressImage($tmpPath, $finalPath, 800, 300000, 50)) {
        throw new RuntimeException('File ' . $label . ' gagal diproses.');
    }

    $path = 'storage/general_affair/cooperation_inputs/' . $filename;

    $stmt = $pdo->prepare("
        INSERT INTO secretary_file_record_attachments
            (record_id, file_path, file_name, sort_order)
        VALUES
            (?, ?, ?, ?)
    ");
    $stmt->execute([$recordId, $path, $label, $sortOrder]);

    return $path;
}

function gaInputDeleteStoredFiles(array $paths): void
{
    foreach ($paths as $path) {
        $path = trim((string)$path);
        if ($path === '') {
            continue;
        }

        $fullPath = __DIR__ . '/../' . ltrim($path, '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    gaInputRedirect();
}

if (!gaInputTableExists($pdo, 'secretary_file_records') || !gaInputTableExists($pdo, 'secretary_file_record_attachments')) {
    $_SESSION['flash_errors'][] = 'Tabel input kerja sama belum tersedia. Jalankan SQL `docs/sql/16_2026-04-01_secretary_file_registry.sql` terlebih dahulu.';
    gaInputRedirect();
}

try {
    if ($action === 'create_record') {
        $cooperationId = (int)($_POST['cooperation_id'] ?? 0);
        $documentDate = trim((string)($_POST['document_date'] ?? ''));
        $documentTime = trim((string)($_POST['document_time'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($cooperationId <= 0) {
            throw new RuntimeException('Data input kerja sama wajib lengkap.');
        }

        if (!isset($_FILES['ktp_file'], $_FILES['kta_file'])) {
            throw new RuntimeException('Upload KTP dan KTA wajib diisi.');
        }

        if ((int)($_FILES['ktp_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload KTP wajib diisi.');
        }

        if ((int)($_FILES['kta_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload KTA wajib diisi.');
        }

        if (!gaCooperationTablesReady($pdo)) {
            throw new RuntimeException('Setting kerja sama instansi belum tersedia.');
        }

        $documentDateTime = gaInputBuildDocumentDateTime($documentDate, $documentTime);
        $cooperationConfig = gaInputFetchCooperationConfig($pdo, $cooperationId, $effectiveUnit);
        $periodMeta = gaCooperationBuildPeriodMeta(
            (string)$cooperationConfig['period_type'],
            new DateTimeImmutable($documentDateTime)
        );
        $existingTransactions = gaInputCountExistingTransactions(
            $pdo,
            $cooperationId,
            (string)$periodMeta['start'],
            (string)$periodMeta['end']
        );

        if ($existingTransactions >= (int)$cooperationConfig['max_transactions']) {
            $limitLabel = $cooperationConfig['claim_scope'] === 'per_institution'
                ? '1 transaksi'
                : ((int)$cooperationConfig['max_transactions'] . ' transaksi');
            throw new RuntimeException(
                'Kuota kerja sama untuk periode ' . $periodMeta['label'] . ' sudah penuh. Batas saat ini: ' . $limitLabel . '.'
            );
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO secretary_file_records
                (file_code, file_category, reference_number, title, counterparty_name,
                 document_date, status, keywords, description, created_by)
            VALUES
                (?, 'cooperation', ?, ?, ?, ?, 'draft', ?, ?, ?)
        ");
        $stmt->execute([
            gaInputGenerateCode($userId),
            '-',
            trim((string)($user['full_name'] ?? $user['name'] ?? 'User')),
            (string)$cooperationConfig['institution_name'],
            $documentDateTime,
            gaInputMarker() . ',cooperation_id:' . $cooperationId,
            $notes !== '' ? $notes : null,
            $userId,
        ]);

        $recordId = (int)$pdo->lastInsertId();
        $storedPaths = [];

        try {
            $storedPaths[] = gaInputStoreAttachment($pdo, $recordId, $_FILES['ktp_file'], 'KTP', 1);
            $storedPaths[] = gaInputStoreAttachment($pdo, $recordId, $_FILES['kta_file'], 'KTA', 2);
        } catch (Throwable $attachmentError) {
            gaInputDeleteStoredFiles($storedPaths);
            throw $attachmentError;
        }

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Input kerja sama berhasil disimpan.';
        gaInputRedirect();
    }

    if ($action === 'update_status') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'draft'));
        $allowedStatuses = ['draft', 'review', 'active', 'archived'];

        if ($recordId <= 0 || !in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException('Status input kerja sama tidak valid.');
        }

        $stmt = $pdo->prepare("
            UPDATE secretary_file_records
            SET status = ?, updated_by = ?
            WHERE id = ?
              AND file_category = 'cooperation'
              AND COALESCE(keywords, '') LIKE ?
        ");
        $stmt->execute([$status, $userId, $recordId, '%' . gaInputMarker() . '%']);

        if ($stmt->rowCount() <= 0) {
            throw new RuntimeException('Data kerja sama tidak ditemukan.');
        }

        $_SESSION['flash_messages'][] = 'Status kerja sama berhasil diperbarui.';
        gaInputRedirect();
    }

    if ($action === 'delete_record') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        if ($recordId <= 0) {
            throw new RuntimeException('Data kerja sama tidak valid.');
        }

        $pdo->beginTransaction();

        $stmtCheck = $pdo->prepare("
            SELECT id
            FROM secretary_file_records
            WHERE id = ?
              AND file_category = 'cooperation'
              AND COALESCE(keywords, '') LIKE ?
            LIMIT 1
        ");
        $stmtCheck->execute([$recordId, '%' . gaInputMarker() . '%']);
        if (!$stmtCheck->fetchColumn()) {
            throw new RuntimeException('Data kerja sama tidak ditemukan.');
        }

        $stmtPath = $pdo->prepare("
            SELECT file_path
            FROM secretary_file_record_attachments
            WHERE record_id = ?
        ");
        $stmtPath->execute([$recordId]);
        $paths = $stmtPath->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $stmtDelete = $pdo->prepare("DELETE FROM secretary_file_records WHERE id = ?");
        $stmtDelete->execute([$recordId]);

        $pdo->commit();
        gaInputDeleteStoredFiles($paths);

        $_SESSION['flash_messages'][] = 'Input kerja sama berhasil dihapus.';
        gaInputRedirect();
    }

    throw new RuntimeException('Aksi tidak dikenali.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['flash_errors'][] = 'Gagal memproses input kerja sama: ' . $e->getMessage();
    gaInputRedirect();
}
