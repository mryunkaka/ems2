<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/surat_code_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

function surat_redirect(string $fallback = 'surat_menyurat.php'): void
{
    $redirectTo = trim((string)($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '' || strpos($redirectTo, '://') !== false || str_starts_with($redirectTo, '//')) {
        $redirectTo = $fallback;
    }
    header('Location: ' . $redirectTo);
    exit;
}

function generate_outgoing_letter_code(): string
{
    $createdAt = new DateTimeImmutable('now');

    return surat_generate_formatted_code(
        $GLOBALS['pdo'],
        'outgoing_letters',
        'outgoing_code',
        'created_at',
        'SK',
        $createdAt->format('Y-m-d H:i:s'),
        $_POST['institution_name'] ?? '',
        'SR'
    );
}

function generate_meeting_minutes_code(PDO $pdo, int $incomingLetterId, int $outgoingLetterId, string $meetingDate): string
{
    return surat_generate_formatted_code(
        $pdo,
        'meeting_minutes',
        'minutes_code',
        'meeting_date',
        'NOT',
        $meetingDate,
        surat_resolve_minutes_institution($pdo, $incomingLetterId, $outgoingLetterId),
        'SR'
    );
}

function surat_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $stmt->execute([$column]);
    $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$key];
}

function surat_normalize_division_scope(?string $value): string
{
    $scope = ems_normalize_division_scope($value);
    if ($scope === '') {
        throw new Exception('Divisi surat tidak valid.');
    }

    return $scope;
}

function surat_is_global_hospital_minutes_title(?string $meetingTitle): bool
{
    $value = strtolower(trim((string)$meetingTitle));
    $value = preg_replace('/\s+/', ' ', $value) ?: '';

    return $value === 'notulen rapat internal/external 10-1 rumah sakit';
}

function surat_revision_label(int $count): ?string
{
    if ($count <= 0) {
        return null;
    }

    return sprintf('revisi-%02d', $count);
}

function normalizeMultiUpload(array $fileBag): array
{
    if (!isset($fileBag['name']) || !is_array($fileBag['name'])) {
        return [];
    }

    $files = [];
    $count = count($fileBag['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = (int)($fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $files[] = [
            'name' => $fileBag['name'][$i] ?? '',
            'type' => $fileBag['type'][$i] ?? '',
            'tmp_name' => $fileBag['tmp_name'][$i] ?? '',
            'error' => $error,
            'size' => (int)($fileBag['size'][$i] ?? 0),
        ];
    }

    return $files;
}

function saveOutgoingAttachments(PDO $pdo, int $outgoingLetterId, array $files): void
{
    if ($outgoingLetterId <= 0 || empty($files)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO outgoing_letter_attachments
            (outgoing_letter_id, file_path, file_name, sort_order)
        VALUES
            (?, ?, ?, ?)
    ");

    $storedPaths = [];

    try {
        foreach (array_values($files) as $index => $file) {
            $path = uploadAndCompressFile($file, 'letters/outgoing', 400000, 5000000);
            if (!$path) {
                throw new Exception('Lampiran surat keluar gagal diproses. Gunakan JPG/PNG maksimal 5MB.');
            }

            $storedPaths[] = $path;
            $stmt->execute([
                $outgoingLetterId,
                $path,
                trim((string)($file['name'] ?? '')) ?: null,
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

function deleteStoredFiles(array $paths): void
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

function surat_require_revision_columns(PDO $pdo, string $table): void
{
    $requiredColumns = ['revision_count', 'revision_label', 'updated_at', 'updated_by'];

    foreach ($requiredColumns as $column) {
        if (!surat_table_has_column($pdo, $table, $column)) {
            throw new Exception('Kolom revisi belum tersedia. Jalankan file SQL terbaru di docs/sql terlebih dahulu.');
        }
    }
}

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userRole = $user['role'] ?? '';
$action = trim((string)($_POST['action'] ?? ''));

if (!ems_is_letter_receiver_role($userRole)) {
    $_SESSION['flash_errors'][] = 'Akses surat hanya untuk role manager.';
    surat_redirect('../dashboard/rekap_farmasi.php');
}

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    surat_redirect();
}

try {
    if ($action === 'mark_incoming_read') {
        $letterId = (int)($_POST['letter_id'] ?? 0);
        if ($letterId <= 0) {
            throw new Exception('Surat masuk tidak valid.');
        }

        $stmt = $pdo->prepare("
            SELECT id, status, target_user_id
            FROM incoming_letters
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$letterId]);
        $letter = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$letter) {
            throw new Exception('Surat masuk tidak ditemukan.');
        }

        $isTargetUser = (int)($letter['target_user_id'] ?? 0) === $userId;
        if (!ems_is_letter_receiver_role($userRole) && !$isTargetUser) {
            throw new Exception('Anda tidak berwenang menerima surat ini.');
        }

        if (($letter['status'] ?? '') === 'unread') {
            $stmt = $pdo->prepare("
                UPDATE incoming_letters
                SET status = 'read',
                    read_by = ?,
                    read_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$userId, $letterId]);
        }

        $_SESSION['flash_messages'][] = 'Surat masuk sudah ditandai dibaca.';
        surat_redirect();
    }

    if ($action === 'add_outgoing_letter') {
        $incomingLetterId = (int)($_POST['incoming_letter_id'] ?? 0);
        $requestedOutgoingCode = trim((string)($_POST['outgoing_code'] ?? ''));
        $institutionName = trim((string)($_POST['institution_name'] ?? ''));
        $recipientName = trim((string)($_POST['recipient_name'] ?? ''));
        $recipientContact = trim((string)($_POST['recipient_contact'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $letterBody = trim((string)($_POST['letter_body'] ?? ''));
        $appointmentDate = trim((string)($_POST['appointment_date'] ?? ''));
        $appointmentTime = trim((string)($_POST['appointment_time'] ?? ''));
        $divisionScope = surat_normalize_division_scope($_POST['division_scope'] ?? '');
        $attachmentFiles = normalizeMultiUpload($_FILES['attachments'] ?? []);

        if ($institutionName === '' || $subject === '' || $letterBody === '') {
            throw new Exception('Data surat keluar belum lengkap.');
        }

        $timeValue = null;
        if ($appointmentTime !== '') {
            $timeObj = DateTime::createFromFormat('H:i', $appointmentTime);
            if (!$timeObj) {
                throw new Exception('Jam surat keluar tidak valid.');
            }
            $timeValue = $timeObj->format('H:i:s');
        }

        $pdo->beginTransaction();

        $insertColumns = [
            'outgoing_code',
            'incoming_letter_id',
            'institution_name',
            'recipient_name',
            'recipient_contact',
            'subject',
            'letter_body',
            'appointment_date',
            'appointment_time',
            'created_by',
        ];
        $insertValues = [
            surat_resolve_requested_code(
                $pdo,
                'outgoing_letters',
                'outgoing_code',
                $requestedOutgoingCode,
                static fn(): string => generate_outgoing_letter_code()
            ),
            $incomingLetterId > 0 ? $incomingLetterId : null,
            $institutionName,
            $recipientName !== '' ? $recipientName : null,
            $recipientContact !== '' ? $recipientContact : null,
            $subject,
            $letterBody,
            $appointmentDate !== '' ? $appointmentDate : null,
            $timeValue,
            $userId,
        ];

        if (surat_table_has_column($pdo, 'outgoing_letters', 'division_scope')) {
            $insertColumns[] = 'division_scope';
            $insertValues[] = $divisionScope;
        }

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $stmt = $pdo->prepare("
            INSERT INTO outgoing_letters
                (" . implode(', ', $insertColumns) . ")
            VALUES
                ($placeholders)
        ");
        $stmt->execute($insertValues);

        $outgoingLetterId = (int)$pdo->lastInsertId();
        saveOutgoingAttachments($pdo, $outgoingLetterId, $attachmentFiles);

        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Surat keluar berhasil disimpan.';
        surat_redirect();
    }

    if ($action === 'edit_outgoing_letter') {
        surat_require_revision_columns($pdo, 'outgoing_letters');

        $letterId = (int)($_POST['letter_id'] ?? 0);
        $requestedOutgoingCode = trim((string)($_POST['outgoing_code'] ?? ''));
        $incomingLetterId = (int)($_POST['incoming_letter_id'] ?? 0);
        $institutionName = trim((string)($_POST['institution_name'] ?? ''));
        $recipientName = trim((string)($_POST['recipient_name'] ?? ''));
        $recipientContact = trim((string)($_POST['recipient_contact'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $letterBody = trim((string)($_POST['letter_body'] ?? ''));
        $appointmentDate = trim((string)($_POST['appointment_date'] ?? ''));
        $appointmentTime = trim((string)($_POST['appointment_time'] ?? ''));
        $divisionScope = surat_normalize_division_scope($_POST['division_scope'] ?? '');

        if ($letterId <= 0 || $institutionName === '' || $subject === '' || $letterBody === '') {
            throw new Exception('Data edit surat keluar belum lengkap.');
        }

        $timeValue = null;
        if ($appointmentTime !== '') {
            $timeObj = DateTime::createFromFormat('H:i', $appointmentTime);
            if (!$timeObj) {
                throw new Exception('Jam surat keluar tidak valid.');
            }
            $timeValue = $timeObj->format('H:i:s');
        }

        $stmt = $pdo->prepare("
            SELECT id, revision_count
            FROM outgoing_letters
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$letterId]);
        $letter = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$letter) {
            throw new Exception('Surat keluar tidak ditemukan.');
        }

        $revisionCount = (int)($letter['revision_count'] ?? 0) + 1;
        $revisionLabel = surat_revision_label($revisionCount);

        $assignments = [
            'outgoing_code = ?',
            'incoming_letter_id = ?',
            'institution_name = ?',
            'recipient_name = ?',
            'recipient_contact = ?',
            'subject = ?',
            'letter_body = ?',
            'appointment_date = ?',
            'appointment_time = ?',
            'revision_count = ?',
            'revision_label = ?',
            'updated_at = NOW()',
            'updated_by = ?',
        ];
        $params = [
            surat_resolve_requested_code(
                $pdo,
                'outgoing_letters',
                'outgoing_code',
                $requestedOutgoingCode,
                static fn(): string => generate_outgoing_letter_code(),
                $letterId
            ),
            $incomingLetterId > 0 ? $incomingLetterId : null,
            $institutionName,
            $recipientName !== '' ? $recipientName : null,
            $recipientContact !== '' ? $recipientContact : null,
            $subject,
            $letterBody,
            $appointmentDate !== '' ? $appointmentDate : null,
            $timeValue,
            $revisionCount,
            $revisionLabel,
            $userId,
        ];

        if (surat_table_has_column($pdo, 'outgoing_letters', 'division_scope')) {
            $assignments[] = 'division_scope = ?';
            $params[] = $divisionScope;
        }

        $params[] = $letterId;
        $stmt = $pdo->prepare("
            UPDATE outgoing_letters
            SET " . implode(",\n                ", $assignments) . "
            WHERE id = ?
        ");
        $stmt->execute($params);

        $_SESSION['flash_messages'][] = 'Surat keluar berhasil diubah. ' . $revisionLabel . ' tersimpan.';
        surat_redirect();
    }

    if ($action === 'add_meeting_minutes') {
        if (!surat_table_has_column($pdo, 'meeting_minutes', 'minutes_code')) {
            throw new Exception('Kolom kode notulen belum tersedia. Jalankan SQL terbaru di docs/sql terlebih dahulu.');
        }

        $incomingLetterId = (int)($_POST['incoming_letter_id'] ?? 0);
        $outgoingLetterId = (int)($_POST['outgoing_letter_id'] ?? 0);
        $requestedMinutesCode = trim((string)($_POST['minutes_code'] ?? ''));
        $meetingTitle = trim((string)($_POST['meeting_title'] ?? ''));
        $meetingDate = trim((string)($_POST['meeting_date'] ?? ''));
        $meetingTime = trim((string)($_POST['meeting_time'] ?? ''));
        $participants = trim((string)($_POST['participants'] ?? ''));
        $summary = trim((string)($_POST['summary'] ?? ''));
        $decisions = trim((string)($_POST['decisions'] ?? ''));
        $followUp = trim((string)($_POST['follow_up'] ?? ''));
        $divisionScope = surat_is_global_hospital_minutes_title($meetingTitle)
            ? ems_all_division_scope_value()
            : surat_normalize_division_scope($_POST['division_scope'] ?? '');

        if (
            $meetingTitle === '' ||
            $meetingDate === '' ||
            $meetingTime === '' ||
            $participants === '' ||
            $summary === ''
        ) {
            throw new Exception('Data notulen belum lengkap.');
        }

        $timeObj = DateTime::createFromFormat('H:i', $meetingTime);
        if (!$timeObj) {
            throw new Exception('Jam notulen tidak valid.');
        }

        $insertColumns = [
            'minutes_code',
            'incoming_letter_id',
            'outgoing_letter_id',
            'meeting_title',
            'meeting_date',
            'meeting_time',
            'participants',
            'summary',
            'decisions',
            'follow_up',
            'created_by',
        ];
        $insertValues = [
            surat_resolve_requested_code(
                $pdo,
                'meeting_minutes',
                'minutes_code',
                $requestedMinutesCode,
                static fn(): string => generate_meeting_minutes_code($pdo, $incomingLetterId, $outgoingLetterId, $meetingDate)
            ),
            $incomingLetterId > 0 ? $incomingLetterId : null,
            $outgoingLetterId > 0 ? $outgoingLetterId : null,
            $meetingTitle,
            $meetingDate,
            $timeObj->format('H:i:s'),
            $participants,
            $summary,
            $decisions !== '' ? $decisions : null,
            $followUp !== '' ? $followUp : null,
            $userId,
        ];

        if (surat_table_has_column($pdo, 'meeting_minutes', 'division_scope')) {
            $insertColumns[] = 'division_scope';
            $insertValues[] = $divisionScope;
        }

        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $stmt = $pdo->prepare("
            INSERT INTO meeting_minutes
                (" . implode(', ', $insertColumns) . ")
            VALUES
                ($placeholders)
        ");
        $stmt->execute($insertValues);

        $_SESSION['flash_messages'][] = 'Notulen pertemuan berhasil disimpan.';
        surat_redirect();
    }

    if ($action === 'edit_meeting_minutes') {
        surat_require_revision_columns($pdo, 'meeting_minutes');

        $minutesId = (int)($_POST['minutes_id'] ?? 0);
        $incomingLetterId = (int)($_POST['incoming_letter_id'] ?? 0);
        $outgoingLetterId = (int)($_POST['outgoing_letter_id'] ?? 0);
        $requestedMinutesCode = trim((string)($_POST['minutes_code'] ?? ''));
        $meetingTitle = trim((string)($_POST['meeting_title'] ?? ''));
        $meetingDate = trim((string)($_POST['meeting_date'] ?? ''));
        $meetingTime = trim((string)($_POST['meeting_time'] ?? ''));
        $participants = trim((string)($_POST['participants'] ?? ''));
        $summary = trim((string)($_POST['summary'] ?? ''));
        $decisions = trim((string)($_POST['decisions'] ?? ''));
        $followUp = trim((string)($_POST['follow_up'] ?? ''));
        $divisionScope = surat_is_global_hospital_minutes_title($meetingTitle)
            ? ems_all_division_scope_value()
            : surat_normalize_division_scope($_POST['division_scope'] ?? '');

        if (
            $minutesId <= 0 ||
            $meetingTitle === '' ||
            $meetingDate === '' ||
            $meetingTime === '' ||
            $participants === '' ||
            $summary === ''
        ) {
            throw new Exception('Data edit notulen belum lengkap.');
        }

        $timeObj = DateTime::createFromFormat('H:i', $meetingTime);
        if (!$timeObj) {
            throw new Exception('Jam notulen tidak valid.');
        }

        $stmt = $pdo->prepare("
            SELECT id, revision_count
            FROM meeting_minutes
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$minutesId]);
        $minutes = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$minutes) {
            throw new Exception('Notulen tidak ditemukan.');
        }

        $revisionCount = (int)($minutes['revision_count'] ?? 0) + 1;
        $revisionLabel = surat_revision_label($revisionCount);

        $assignments = [
            'minutes_code = ?',
            'incoming_letter_id = ?',
            'outgoing_letter_id = ?',
            'meeting_title = ?',
            'meeting_date = ?',
            'meeting_time = ?',
            'participants = ?',
            'summary = ?',
            'decisions = ?',
            'follow_up = ?',
            'revision_count = ?',
            'revision_label = ?',
            'updated_at = NOW()',
            'updated_by = ?',
        ];
        $params = [
            surat_resolve_requested_code(
                $pdo,
                'meeting_minutes',
                'minutes_code',
                $requestedMinutesCode,
                static fn(): string => generate_meeting_minutes_code($pdo, $incomingLetterId, $outgoingLetterId, $meetingDate),
                $minutesId
            ),
            $incomingLetterId > 0 ? $incomingLetterId : null,
            $outgoingLetterId > 0 ? $outgoingLetterId : null,
            $meetingTitle,
            $meetingDate,
            $timeObj->format('H:i:s'),
            $participants,
            $summary,
            $decisions !== '' ? $decisions : null,
            $followUp !== '' ? $followUp : null,
            $revisionCount,
            $revisionLabel,
            $userId,
        ];

        if (surat_table_has_column($pdo, 'meeting_minutes', 'division_scope')) {
            $assignments[] = 'division_scope = ?';
            $params[] = $divisionScope;
        }

        $params[] = $minutesId;
        $stmt = $pdo->prepare("
            UPDATE meeting_minutes
            SET " . implode(",\n                ", $assignments) . "
            WHERE id = ?
        ");
        $stmt->execute($params);

        $_SESSION['flash_messages'][] = 'Notulen berhasil diubah. ' . $revisionLabel . ' tersimpan.';
        surat_redirect();
    }

    if ($action === 'delete_incoming_letter') {
        $letterId = (int)($_POST['letter_id'] ?? 0);
        if ($letterId <= 0) {
            throw new Exception('Surat masuk tidak valid.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id FROM incoming_letters WHERE id = ? LIMIT 1");
        $stmt->execute([$letterId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('Surat masuk tidak ditemukan.');
        }

        $stmt = $pdo->prepare("SELECT file_path FROM incoming_letter_attachments WHERE incoming_letter_id = ?");
        $stmt->execute([$letterId]);
        $paths = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $stmt = $pdo->prepare("UPDATE outgoing_letters SET incoming_letter_id = NULL WHERE incoming_letter_id = ?");
        $stmt->execute([$letterId]);

        $stmt = $pdo->prepare("UPDATE meeting_minutes SET incoming_letter_id = NULL WHERE incoming_letter_id = ?");
        $stmt->execute([$letterId]);

        $stmt = $pdo->prepare("DELETE FROM incoming_letters WHERE id = ?");
        $stmt->execute([$letterId]);

        $pdo->commit();
        deleteStoredFiles($paths);
        $_SESSION['flash_messages'][] = 'Surat masuk berhasil dihapus.';
        surat_redirect();
    }

    if ($action === 'delete_outgoing_letter') {
        $letterId = (int)($_POST['letter_id'] ?? 0);
        if ($letterId <= 0) {
            throw new Exception('Surat keluar tidak valid.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id FROM outgoing_letters WHERE id = ? LIMIT 1");
        $stmt->execute([$letterId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('Surat keluar tidak ditemukan.');
        }

        $stmt = $pdo->prepare("SELECT file_path FROM outgoing_letter_attachments WHERE outgoing_letter_id = ?");
        $stmt->execute([$letterId]);
        $paths = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $stmt = $pdo->prepare("UPDATE meeting_minutes SET outgoing_letter_id = NULL WHERE outgoing_letter_id = ?");
        $stmt->execute([$letterId]);

        $stmt = $pdo->prepare("DELETE FROM outgoing_letters WHERE id = ?");
        $stmt->execute([$letterId]);

        $pdo->commit();
        deleteStoredFiles($paths);
        $_SESSION['flash_messages'][] = 'Surat keluar berhasil dihapus.';
        surat_redirect();
    }

    if ($action === 'delete_meeting_minutes') {
        $minutesId = (int)($_POST['minutes_id'] ?? 0);
        if ($minutesId <= 0) {
            throw new Exception('Notulen tidak valid.');
        }

        $stmt = $pdo->prepare("DELETE FROM meeting_minutes WHERE id = ?");
        $stmt->execute([$minutesId]);

        if ($stmt->rowCount() <= 0) {
            throw new Exception('Notulen tidak ditemukan.');
        }

        $_SESSION['flash_messages'][] = 'Notulen berhasil dihapus.';
        surat_redirect();
    }

    throw new Exception('Aksi tidak dikenali.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_errors'][] = 'Gagal memproses: ' . $e->getMessage();
    surat_redirect();
}
