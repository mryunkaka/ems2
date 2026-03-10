<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

function redirect_public_form(array $params = []): void
{
    $target = ems_url('/surat_instansi.php');
    if (!empty($params)) {
        $target .= '?' . http_build_query($params);
    }
    header('Location: ' . $target);
    exit;
}

function generate_incoming_letter_code(): string
{
    return 'SM-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
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

function saveIncomingAttachments(PDO $pdo, int $incomingLetterId, array $files): void
{
    if ($incomingLetterId <= 0 || empty($files)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO incoming_letter_attachments
            (incoming_letter_id, file_path, file_name, sort_order)
        VALUES
            (?, ?, ?, ?)
    ");

    $storedPaths = [];

    try {
        foreach (array_values($files) as $index => $file) {
            $path = uploadAndCompressFile($file, 'letters/incoming', 400000, 5000000);
            if (!$path) {
                throw new Exception('Lampiran surat masuk gagal diproses. Gunakan JPG/PNG maksimal 5MB.');
            }

            $storedPaths[] = $path;
            $stmt->execute([
                $incomingLetterId,
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

$institutionName = trim((string)($_POST['institution_name'] ?? ''));
$senderName = trim((string)($_POST['sender_name'] ?? ''));
$senderPhone = trim((string)($_POST['sender_phone'] ?? ''));
$meetingTopic = trim((string)($_POST['meeting_topic'] ?? ''));
$appointmentDate = trim((string)($_POST['appointment_date'] ?? ''));
$appointmentTime = trim((string)($_POST['appointment_time'] ?? ''));
$targetUserId = (int)($_POST['target_user_id'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));
$attachmentFiles = normalizeMultiUpload($_FILES['attachments'] ?? []);

if (
    $institutionName === '' ||
    $senderName === '' ||
    $senderPhone === '' ||
    $meetingTopic === '' ||
    $appointmentDate === '' ||
    $appointmentTime === '' ||
    $targetUserId <= 0
) {
    redirect_public_form([
        'error' => 'Semua field wajib diisi.',
    ]);
}

$dateObj = DateTime::createFromFormat('Y-m-d', $appointmentDate);
$timeObj = DateTime::createFromFormat('H:i', $appointmentTime);
if (!$dateObj || $dateObj->format('Y-m-d') !== $appointmentDate || !$timeObj) {
    redirect_public_form([
        'error' => 'Format tanggal atau jam tidak valid.',
    ]);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, full_name, role
        FROM user_rh
        WHERE id = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$targetUserId]);
    $recipient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipient || !ems_is_letter_receiver_role($recipient['role'] ?? '')) {
        redirect_public_form([
            'error' => 'Tujuan pertemuan hanya untuk role manager.',
        ]);
    }

    $letterCode = generate_incoming_letter_code();

    $stmt = $pdo->prepare("
        INSERT INTO incoming_letters
            (letter_code, institution_name, sender_name, sender_phone, meeting_topic,
             appointment_date, appointment_time, target_user_id, target_name_snapshot,
             target_role_snapshot, notes, created_ip)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $letterCode,
        $institutionName,
        $senderName,
        $senderPhone,
        $meetingTopic,
        $appointmentDate,
        $timeObj->format('H:i:s'),
        $targetUserId,
        $recipient['full_name'],
        ems_role_label($recipient['role'] ?? ''),
        $notes !== '' ? $notes : null,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
    ]);

    $incomingLetterId = (int)$pdo->lastInsertId();
    saveIncomingAttachments($pdo, $incomingLetterId, $attachmentFiles);

    $pdo->commit();

    redirect_public_form([
        'success' => '1',
        'code' => $letterCode,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_public_form([
        'error' => 'Gagal menyimpan surat. Silakan coba lagi.',
    ]);
}
