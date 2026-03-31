<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/surat_code_helper.php';

ems_require_division_access(['Secretary'], '/dashboard/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$user = $_SESSION['user_rh'] ?? [];
$userId = (int) ($user['id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

function secretaryRedirect(string $fallback = 'secretary_visit_agenda.php'): void
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '' || strpos($redirectTo, '://') !== false || str_starts_with($redirectTo, '//')) {
        $redirectTo = $fallback;
    }

    header('Location: ' . $redirectTo);
    exit;
}

function secretaryAssertAllowed(string $value, array $allowed, string $message): string
{
    if (!in_array($value, $allowed, true)) {
        throw new Exception($message);
    }

    return $value;
}

function secretaryDate(string $value, string $message): string
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new Exception($message);
    }

    return $value;
}

function secretaryTime(string $value, string $message): string
{
    $time = DateTime::createFromFormat('H:i', $value);
    if (!$time) {
        throw new Exception($message);
    }

    return $time->format('H:i:s');
}

function secretaryGenerateVisitAgendaCode(PDO $pdo, string $visitDate, ?string $originName): string
{
    return surat_generate_formatted_code(
        $pdo,
        'secretary_visit_agendas',
        'agenda_code',
        'visit_date',
        'AGD',
        $visitDate,
        $originName,
        'SR'
    );
}

function secretaryGenerateInternalCoordinationCode(PDO $pdo, string $coordinationDate, ?string $divisionScope): string
{
    return surat_generate_formatted_code(
        $pdo,
        'secretary_internal_coordinations',
        'coordination_code',
        'coordination_date',
        'KOR',
        $coordinationDate,
        $divisionScope,
        'SR'
    );
}

function secretaryGenerateConfidentialLetterCode(PDO $pdo, string $letterDirection, string $letterDate, ?string $counterpartyName): string
{
    $letterType = $letterDirection === 'outgoing' ? 'SKR' : 'SMR';

    return surat_generate_formatted_code(
        $pdo,
        'secretary_confidential_letters',
        'register_code',
        'letter_date',
        $letterType,
        $letterDate,
        $counterpartyName,
        'SR'
    );
}

function secretaryTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $cache[$table] = (bool) $stmt->fetchColumn();

    return $cache[$table];
}

function secretaryNormalizeMultiUpload(array $fileBag): array
{
    if (!isset($fileBag['name']) || !is_array($fileBag['name'])) {
        return [];
    }

    $files = [];
    $count = count($fileBag['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = (int) ($fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $files[] = [
            'name' => $fileBag['name'][$i] ?? '',
            'type' => $fileBag['type'][$i] ?? '',
            'tmp_name' => $fileBag['tmp_name'][$i] ?? '',
            'error' => $error,
            'size' => (int) ($fileBag['size'][$i] ?? 0),
        ];
    }

    return $files;
}

function secretaryAttachmentConfig(string $type): array
{
    return match ($type) {
        'visit_agenda' => [
            'table' => 'secretary_visit_agenda_attachments',
            'foreign_key' => 'agenda_id',
            'folder' => 'secretary/visit_agendas',
            'label' => 'agenda kunjungan',
        ],
        'internal_coordination' => [
            'table' => 'secretary_internal_coordination_attachments',
            'foreign_key' => 'coordination_id',
            'folder' => 'secretary/internal_coordinations',
            'label' => 'koordinasi internal',
        ],
        'confidential_letter' => [
            'table' => 'secretary_confidential_letter_attachments',
            'foreign_key' => 'letter_id',
            'folder' => 'secretary/confidential_letters',
            'label' => 'surat rahasia',
        ],
        default => throw new Exception('Konfigurasi lampiran secretary tidak dikenali.'),
    };
}

function secretarySaveAttachments(PDO $pdo, string $type, int $recordId, array $files): void
{
    if ($recordId <= 0 || empty($files)) {
        return;
    }

    $config = secretaryAttachmentConfig($type);
    if (!secretaryTableExists($pdo, $config['table'])) {
        throw new Exception('Tabel lampiran Secretary belum tersedia. Jalankan SQL `docs/sql/15_2026-03-31_secretary_attachments.sql` terlebih dahulu.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO {$config['table']}
            ({$config['foreign_key']}, file_path, file_name, sort_order)
        VALUES
            (?, ?, ?, ?)
    ");

    $storedPaths = [];

    try {
        foreach (array_values($files) as $index => $file) {
            $path = uploadAndCompressFile($file, $config['folder'], 400000, 5000000);
            if (!$path) {
                throw new Exception('Lampiran ' . $config['label'] . ' gagal diproses. Gunakan JPG/PNG maksimal 5MB.');
            }

            $storedPaths[] = $path;
            $stmt->execute([
                $recordId,
                $path,
                trim((string) ($file['name'] ?? '')) ?: null,
                $index + 1,
            ]);
        }
    } catch (Throwable $e) {
        foreach ($storedPaths as $path) {
            $fullPath = __DIR__ . '/../' . ltrim($path, '/');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        throw $e;
    }
}

function secretaryFetchAttachmentPaths(PDO $pdo, string $type, int $recordId): array
{
    if ($recordId <= 0) {
        return [];
    }

    $config = secretaryAttachmentConfig($type);
    if (!secretaryTableExists($pdo, $config['table'])) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT file_path
        FROM {$config['table']}
        WHERE {$config['foreign_key']} = ?
    ");
    $stmt->execute([$recordId]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function secretaryDeleteStoredFiles(array $paths): void
{
    foreach ($paths as $path) {
        $path = trim((string) $path);
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
    secretaryRedirect();
}

try {
    if ($action === 'save_visit_agenda') {
        $requestedAgendaCode = trim((string) ($_POST['agenda_code'] ?? ''));
        $visitorName = trim((string) ($_POST['visitor_name'] ?? ''));
        $originName = trim((string) ($_POST['origin_name'] ?? ''));
        $visitPurpose = trim((string) ($_POST['visit_purpose'] ?? ''));
        $visitDate = secretaryDate(trim((string) ($_POST['visit_date'] ?? '')), 'Tanggal kunjungan tidak valid.');
        $visitTime = secretaryTime(trim((string) ($_POST['visit_time'] ?? '')), 'Jam kunjungan tidak valid.');
        $location = trim((string) ($_POST['location'] ?? ''));
        $picUserId = (int) ($_POST['pic_user_id'] ?? 0);
        $status = secretaryAssertAllowed(trim((string) ($_POST['status'] ?? 'scheduled')), ['scheduled', 'ongoing', 'completed', 'cancelled'], 'Status agenda tidak valid.');
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $attachmentFiles = secretaryNormalizeMultiUpload($_FILES['attachments'] ?? []);

        if ($visitorName === '' || $visitPurpose === '' || $location === '' || $picUserId <= 0) {
            throw new Exception('Data agenda kunjungan wajib lengkap.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO secretary_visit_agendas
                (agenda_code, visitor_name, origin_name, visit_purpose, visit_date, visit_time,
                 location, pic_user_id, status, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            surat_resolve_requested_code(
                $pdo,
                'secretary_visit_agendas',
                'agenda_code',
                $requestedAgendaCode,
                static fn(): string => secretaryGenerateVisitAgendaCode($pdo, $visitDate, $originName)
            ),
            $visitorName,
            $originName !== '' ? $originName : null,
            $visitPurpose,
            $visitDate,
            $visitTime,
            $location,
            $picUserId,
            $status,
            $notes !== '' ? $notes : null,
            $userId,
        ]);

        secretarySaveAttachments($pdo, 'visit_agenda', (int) $pdo->lastInsertId(), $attachmentFiles);
        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Agenda kunjungan berhasil disimpan.';
        secretaryRedirect('secretary_visit_agenda.php');
    }

    if ($action === 'update_visit_status') {
        $agendaId = (int) ($_POST['agenda_id'] ?? 0);
        $status = secretaryAssertAllowed(trim((string) ($_POST['status'] ?? 'scheduled')), ['scheduled', 'ongoing', 'completed', 'cancelled'], 'Status agenda tidak valid.');
        if ($agendaId <= 0) {
            throw new Exception('Agenda kunjungan tidak valid.');
        }

        $stmt = $pdo->prepare("UPDATE secretary_visit_agendas SET status = ?, updated_by = ? WHERE id = ?");
        $stmt->execute([$status, $userId, $agendaId]);

        $_SESSION['flash_messages'][] = 'Status agenda kunjungan diperbarui.';
        secretaryRedirect('secretary_visit_agenda.php');
    }

    if ($action === 'save_internal_coordination') {
        $requestedCoordinationCode = trim((string) ($_POST['coordination_code'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $divisionScope = trim((string) ($_POST['division_scope'] ?? ''));
        $hostUserId = (int) ($_POST['host_user_id'] ?? 0);
        $coordinationDate = secretaryDate(trim((string) ($_POST['coordination_date'] ?? '')), 'Tanggal koordinasi tidak valid.');
        $startTime = secretaryTime(trim((string) ($_POST['start_time'] ?? '')), 'Jam koordinasi tidak valid.');
        $status = secretaryAssertAllowed(trim((string) ($_POST['status'] ?? 'draft')), ['draft', 'scheduled', 'done', 'cancelled'], 'Status koordinasi tidak valid.');
        $summaryNotes = trim((string) ($_POST['summary_notes'] ?? ''));
        $followUpNotes = trim((string) ($_POST['follow_up_notes'] ?? ''));
        $attachmentFiles = secretaryNormalizeMultiUpload($_FILES['attachments'] ?? []);

        if ($title === '' || $divisionScope === '' || $hostUserId <= 0) {
            throw new Exception('Data koordinasi internal wajib lengkap.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO secretary_internal_coordinations
                (coordination_code, title, division_scope, host_user_id, coordination_date,
                 start_time, status, summary_notes, follow_up_notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            surat_resolve_requested_code(
                $pdo,
                'secretary_internal_coordinations',
                'coordination_code',
                $requestedCoordinationCode,
                static fn(): string => secretaryGenerateInternalCoordinationCode($pdo, $coordinationDate, $divisionScope)
            ),
            $title,
            $divisionScope,
            $hostUserId,
            $coordinationDate,
            $startTime,
            $status,
            $summaryNotes !== '' ? $summaryNotes : null,
            $followUpNotes !== '' ? $followUpNotes : null,
            $userId,
        ]);

        secretarySaveAttachments($pdo, 'internal_coordination', (int) $pdo->lastInsertId(), $attachmentFiles);
        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Koordinasi internal berhasil disimpan.';
        secretaryRedirect('secretary_internal_coordination.php');
    }

    if ($action === 'update_coordination_status') {
        $coordinationId = (int) ($_POST['coordination_id'] ?? 0);
        $status = secretaryAssertAllowed(trim((string) ($_POST['status'] ?? 'draft')), ['draft', 'scheduled', 'done', 'cancelled'], 'Status koordinasi tidak valid.');
        if ($coordinationId <= 0) {
            throw new Exception('Koordinasi internal tidak valid.');
        }

        $stmt = $pdo->prepare("UPDATE secretary_internal_coordinations SET status = ?, updated_by = ? WHERE id = ?");
        $stmt->execute([$status, $userId, $coordinationId]);

        $_SESSION['flash_messages'][] = 'Status koordinasi internal diperbarui.';
        secretaryRedirect('secretary_internal_coordination.php');
    }

    if ($action === 'save_confidential_letter') {
        $requestedRegisterCode = trim((string) ($_POST['register_code'] ?? ''));
        $referenceNumber = trim((string) ($_POST['reference_number'] ?? ''));
        $letterDirection = secretaryAssertAllowed(trim((string) ($_POST['letter_direction'] ?? 'incoming')), ['incoming', 'outgoing'], 'Arah surat tidak valid.');
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $counterpartyName = trim((string) ($_POST['counterparty_name'] ?? ''));
        $confidentialityLevel = secretaryAssertAllowed(trim((string) ($_POST['confidentiality_level'] ?? 'confidential')), ['confidential', 'secret', 'top_secret'], 'Level kerahasiaan tidak valid.');
        $letterDate = secretaryDate(trim((string) ($_POST['letter_date'] ?? '')), 'Tanggal surat tidak valid.');
        $status = secretaryAssertAllowed(trim((string) ($_POST['status'] ?? 'logged')), ['logged', 'sealed', 'distributed', 'archived'], 'Status surat rahasia tidak valid.');
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $attachmentFiles = secretaryNormalizeMultiUpload($_FILES['attachments'] ?? []);

        if ($referenceNumber === '' || $subject === '' || $counterpartyName === '') {
            throw new Exception('Data surat rahasia wajib lengkap.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO secretary_confidential_letters
                (register_code, reference_number, letter_direction, subject, counterparty_name,
                 confidentiality_level, letter_date, status, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            surat_resolve_requested_code(
                $pdo,
                'secretary_confidential_letters',
                'register_code',
                $requestedRegisterCode,
                static fn(): string => secretaryGenerateConfidentialLetterCode($pdo, $letterDirection, $letterDate, $counterpartyName)
            ),
            $referenceNumber,
            $letterDirection,
            $subject,
            $counterpartyName,
            $confidentialityLevel,
            $letterDate,
            $status,
            $notes !== '' ? $notes : null,
            $userId,
        ]);

        secretarySaveAttachments($pdo, 'confidential_letter', (int) $pdo->lastInsertId(), $attachmentFiles);
        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Register surat rahasia berhasil disimpan.';
        secretaryRedirect('secretary_confidential_letters.php');
    }

    if ($action === 'update_confidential_status') {
        $letterId = (int) ($_POST['letter_id'] ?? 0);
        $status = secretaryAssertAllowed(trim((string) ($_POST['status'] ?? 'logged')), ['logged', 'sealed', 'distributed', 'archived'], 'Status surat rahasia tidak valid.');
        if ($letterId <= 0) {
            throw new Exception('Register surat rahasia tidak valid.');
        }

        $stmt = $pdo->prepare("UPDATE secretary_confidential_letters SET status = ?, updated_by = ? WHERE id = ?");
        $stmt->execute([$status, $userId, $letterId]);

        $_SESSION['flash_messages'][] = 'Status surat rahasia diperbarui.';
        secretaryRedirect('secretary_confidential_letters.php');
    }

    if ($action === 'delete_confidential_letter') {
        $letterId = (int) ($_POST['letter_id'] ?? 0);
        if ($letterId <= 0) {
            throw new Exception('Register surat rahasia tidak valid.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id FROM secretary_confidential_letters WHERE id = ? LIMIT 1");
        $stmt->execute([$letterId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('Register surat rahasia tidak ditemukan.');
        }

        $paths = secretaryFetchAttachmentPaths($pdo, 'confidential_letter', $letterId);

        $stmt = $pdo->prepare("DELETE FROM secretary_confidential_letters WHERE id = ?");
        $stmt->execute([$letterId]);

        $pdo->commit();
        secretaryDeleteStoredFiles($paths);

        $_SESSION['flash_messages'][] = 'Register surat rahasia berhasil dihapus permanen.';
        secretaryRedirect('secretary_confidential_letters.php');
    }

    throw new Exception('Aksi secretary tidak dikenali.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_errors'][] = 'Gagal memproses secretary: ' . $e->getMessage();
    secretaryRedirect();
}
