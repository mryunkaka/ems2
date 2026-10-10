<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/forensic_private_access.php';

function secureFileAbort(int $statusCode = 404, string $message = 'File tidak ditemukan.'): void
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}

function secureFileNormalizedPath(string $path): string
{
    return ltrim(str_replace('\\', '/', trim($path)), '/');
}

function secureFileResolvedPath(string $relativePath): ?string
{
    $fullPath = realpath(__DIR__ . '/../' . $relativePath);
    if ($fullPath === false || !is_file($fullPath)) {
        return null;
    }

    return $fullPath;
}

function secureFileIsInside(string $fullPath, string $basePath): bool
{
    return str_starts_with(str_replace('\\', '/', $fullPath), str_replace('\\', '/', $basePath));
}

function secureFileUserRhHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM user_rh LIKE ?");
    $stmt->execute([$column]);
    $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

function secureFileTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    }
    return $cache[$table];
}

$relativePath = secureFileNormalizedPath((string)($_GET['path'] ?? ''));
if ($relativePath === '' || !str_starts_with($relativePath, 'storage/')) {
    secureFileAbort(400, 'Path file tidak valid.');
}

$fullPath = secureFileResolvedPath($relativePath);
if ($fullPath === null) {
    secureFileAbort(404);
}

$storageRoot = realpath(__DIR__ . '/../storage');
if ($storageRoot === false || !secureFileIsInside($fullPath, $storageRoot)) {
    secureFileAbort(403, 'Akses file tidak diizinkan.');
}

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userRole = strtolower(trim((string)($user['role'] ?? '')));
$userDivision = ems_normalize_division($user['division'] ?? '');
if ($userId <= 0) {
    secureFileAbort(401, 'Session user tidak valid.');
}
$canViewSecretaryProtectedFiles = ems_is_valid_division($userDivision);

if (str_starts_with($relativePath, 'storage/work_anniversary/')) {
    if (!preg_match('#^storage/work_anniversary/\d+_\d{4}\.(png|jpg)$#', $relativePath)) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/identity/')) {
    // Identity wajib lewat endpoint terproteksi, session login sudah cukup untuk akses internal.
} elseif (str_starts_with($relativePath, 'storage/reimbursements/')) {
    $stmt = $pdo->prepare("SELECT 1 FROM reimbursements WHERE receipt_file = ? LIMIT 1");
    $stmt->execute([$relativePath]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/disciplinary/cases/')) {
    $canViewAll = $userDivision === 'Disciplinary Committee';
    $stmt = $pdo->prepare("
        SELECT 1
        FROM disciplinary_case_attachments dca
        INNER JOIN disciplinary_cases dc ON dc.id = dca.case_id
        WHERE dca.file_path = ?
          AND (? = 1 OR dc.subject_user_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$relativePath, $canViewAll ? 1 : 0, $userId]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/disciplinary/warning_letters/')) {
    $canViewAll = $userDivision === 'Disciplinary Committee';
    $stmt = $pdo->prepare("
        SELECT 1
        FROM disciplinary_warning_letter_attachments dwla
        INNER JOIN disciplinary_warning_letters dwl ON dwl.id = dwla.warning_letter_id
        WHERE dwla.file_path = ?
          AND (? = 1 OR dwl.subject_user_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$relativePath, $canViewAll ? 1 : 0, $userId]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/secretary/file_records/')) {
    if (!$canViewSecretaryProtectedFiles) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }

    $stmt = $pdo->prepare("
        SELECT sfr.owner_division
        FROM secretary_file_record_attachments sfra
        INNER JOIN secretary_file_records sfr ON sfr.id = sfra.record_id
        WHERE sfra.file_path = ?
        LIMIT 1
    ");
    $stmt->execute([$relativePath]);
    if (ems_normalize_division((string) $stmt->fetchColumn()) !== $userDivision) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/secretary/visit_agendas/')) {
    if (!$canViewSecretaryProtectedFiles) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }

    $stmt = $pdo->prepare("
        SELECT sva.owner_division
        FROM secretary_visit_agenda_attachments svaatt
        INNER JOIN secretary_visit_agendas sva ON sva.id = svaatt.agenda_id
        WHERE svaatt.file_path = ?
        LIMIT 1
    ");
    $stmt->execute([$relativePath]);
    if (ems_normalize_division((string) $stmt->fetchColumn()) !== $userDivision) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/secretary/internal_coordinations/')) {
    if (!$canViewSecretaryProtectedFiles) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
    $stmt = $pdo->prepare("
        SELECT sic.owner_division
        FROM secretary_internal_coordination_attachments sica
        INNER JOIN secretary_internal_coordinations sic ON sic.id = sica.coordination_id
        WHERE sica.file_path = ?
        LIMIT 1
    ");
    $stmt->execute([$relativePath]);
    if (ems_normalize_division((string) $stmt->fetchColumn()) !== $userDivision) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/secretary/confidential_letters/')) {
    if (!$canViewSecretaryProtectedFiles) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }

    $stmt = $pdo->prepare("
        SELECT scl.owner_division
        FROM secretary_confidential_letter_attachments sclatt
        INNER JOIN secretary_confidential_letters scl ON scl.id = sclatt.letter_id
        WHERE sclatt.file_path = ?
        LIMIT 1
    ");
    $stmt->execute([$relativePath]);
    if (ems_normalize_division((string) $stmt->fetchColumn()) !== $userDivision) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/user_docs/')) {
    $userDocColumns = [
        'file_ktp',
        'file_sim',
        'file_kta',
        'file_skb',
        'file_kontrak_kerja',
        'sertifikat_heli',
        'sertifikat_operasi',
        'sertifikat_operasi_plastik',
        'sertifikat_operasi_kecil',
        'sertifikat_operasi_besar',
        'sertifikat_class_co_asst',
        'sertifikat_class_paramedic',
    ];
    $userDocConditions = [];
    $userDocParams = [];
    foreach ($userDocColumns as $column) {
        if (!secureFileUserRhHasColumn($pdo, $column)) {
            continue;
        }

        $userDocConditions[] = "{$column} = ?";
        $userDocParams[] = $relativePath;
    }
    $userDocConditions[] = "COALESCE(dokumen_lainnya, '') LIKE ?";
    $userDocParams[] = '%' . $relativePath . '%';

    $stmt = $pdo->prepare("
        SELECT id
        FROM user_rh
        WHERE " . implode("\n           OR ", $userDocConditions) . "
        LIMIT 1
    ");
    $stmt->execute($userDocParams);
    $ownerId = (int)$stmt->fetchColumn();
    if ($ownerId <= 0 || ($ownerId !== $userId && ems_is_staff_role($userRole))) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/applicants/')) {
    $stmt = $pdo->prepare("SELECT 1 FROM applicant_documents WHERE file_path = ? LIMIT 1");
    $stmt->execute([$relativePath]);
    if (!(bool)$stmt->fetchColumn() || ems_is_staff_role($userRole)) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/restaurant_ktp/')) {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM restaurant_consumptions
        WHERE ktp_file = ?
          AND (recipient_user_id = ? OR created_by = ? OR ? = 1)
        LIMIT 1
    ");
    $stmt->execute([$relativePath, $userId, $userId, ems_is_staff_role($userRole) ? 0 : 1]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/letters/')) {
    if (!ems_is_valid_division($userDivision)) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }

    $letterScopeQueries = ["
        SELECT il.division_scope
        FROM incoming_letter_attachments ila
        INNER JOIN incoming_letters il ON il.id = ila.incoming_letter_id
        WHERE ila.file_path = ?
    ", "
        SELECT ol.division_scope
        FROM outgoing_letter_attachments ola
        INNER JOIN outgoing_letters ol ON ol.id = ola.outgoing_letter_id
        WHERE ola.file_path = ?
    "];
    if (secureFileTableExists($pdo, 'meeting_minutes_attachments')) {
        $letterScopeQueries[] = "
            SELECT mm.division_scope
            FROM meeting_minutes_attachments mma
            INNER JOIN meeting_minutes mm ON mm.id = mma.meeting_minutes_id
            WHERE mma.file_path = ?
        ";
    }
    $stmt = $pdo->prepare(implode("\nUNION ALL\n", $letterScopeQueries));
    $stmt->execute(array_fill(0, count($letterScopeQueries), $relativePath));
    $hasOwnDivisionAttachment = false;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $divisionScope) {
        if (ems_normalize_division_scope((string) $divisionScope) === $userDivision) {
            $hasOwnDivisionAttachment = true;
            break;
        }
    }
    if (!$hasOwnDivisionAttachment) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/general_affair/cooperation_inputs/')) {
    if (!in_array($userDivision, ['General Affair', 'Executive', 'Secretary'], true)) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/police_badges/')) {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM police_partnership_records
        WHERE badge_file_path = ?
        LIMIT 1
    ");
    $stmt->execute([$relativePath]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/medical_records/')) {
    // Otorisasi untuk KTP/MRI/lampiran/surat visum rekam medis: file standard
    // bebas diakses (matching aturan lama), file forensic_private butuh akses
    // native (division Forensic dst) ATAU izin granular dari
    // forensic_private_access_grants (lihat semua, atau lihat punya sendiri
    // dan file itu memang milik record yang dia buat) — lihat
    // config/forensic_private_access.php.
    $allowed = false;
    $forensicRowChecked = false;
    $forensicRowAllowed = false;

    $checkForensicRow = function (array $record) use ($pdo, $user, $userId, &$forensicRowChecked, &$forensicRowAllowed): bool {
        if (!$forensicRowChecked) {
            ems_forensic_private_ensure_tables($pdo);
            $perms = ems_forensic_private_effective_permissions($pdo, $user);
            $forensicRowAllowed = ems_forensic_private_can_view_row($perms, $record, $userId);
            $forensicRowChecked = true;
        }

        return $forensicRowAllowed;
    };

    $hasVisumColumn = ems_column_exists($pdo, 'medical_records', 'visum_letter_file_path');
    $visumSelect = $hasVisumColumn ? ', mr.visum_letter_file_path' : '';
    $visumWhere = $hasVisumColumn ? ' OR mr.visum_letter_file_path = ?' : '';
    $visumParam = $hasVisumColumn ? [$relativePath] : [];

    $stmt = $pdo->prepare("
        SELECT COALESCE(mr.visibility_scope, 'standard') AS visibility_scope, mr.created_by{$visumSelect}
        FROM medical_records mr
        WHERE (mr.ktp_file_path = ? OR mr.mri_file_path = ?{$visumWhere})
        LIMIT 1
    ");
    $stmt->execute(array_merge([$relativePath, $relativePath], $visumParam));
    $recordRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($recordRow) {
        $scope = (string) ($recordRow['visibility_scope'] ?? 'standard');
        $allowed = $scope === 'standard' || ($scope === 'forensic_private' && $checkForensicRow($recordRow));
    }

    if (!$allowed && ems_table_exists($pdo, 'medical_record_supporting_images')) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(mr.visibility_scope, 'standard') AS visibility_scope, mr.created_by
            FROM medical_record_supporting_images si
            INNER JOIN medical_records mr ON mr.id = si.medical_record_id
            WHERE si.file_path = ?
            LIMIT 1
        ");
        $stmt->execute([$relativePath]);
        $recordRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($recordRow) {
            $scope = (string) ($recordRow['visibility_scope'] ?? 'standard');
            $allowed = $scope === 'standard' || ($scope === 'forensic_private' && $checkForensicRow($recordRow));
        }
    }
    if (!$allowed) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} elseif (str_starts_with($relativePath, 'storage/documents/')) {
    // Document Library ("Dokumen"): basis pengetahuan bersama, semua user
    // login boleh baca file apa pun di sini (keputusan §11 poin 3
    // docs/modules/document-library.md) — cukup pastikan path ini memang
    // baris dokumen yang valid, bukan tebakan path.
    $stmt = $pdo->prepare("SELECT 1 FROM document_files WHERE file_path = ? LIMIT 1");
    $stmt->execute([$relativePath]);
    if (!(bool)$stmt->fetchColumn()) {
        secureFileAbort(403, 'Akses file tidak diizinkan.');
    }
} else {
    secureFileAbort(403, 'Akses file tidak diizinkan.');
}

$mime = (string)(@mime_content_type($fullPath) ?: 'application/octet-stream');
$filename = basename($fullPath);
clearstatcache(true, $fullPath);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
readfile($fullPath);
exit;
