<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
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
    return 'SK-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
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
        $institutionName = trim((string)($_POST['institution_name'] ?? ''));
        $recipientName = trim((string)($_POST['recipient_name'] ?? ''));
        $recipientContact = trim((string)($_POST['recipient_contact'] ?? ''));
        $subject = trim((string)($_POST['subject'] ?? ''));
        $letterBody = trim((string)($_POST['letter_body'] ?? ''));
        $appointmentDate = trim((string)($_POST['appointment_date'] ?? ''));
        $appointmentTime = trim((string)($_POST['appointment_time'] ?? ''));

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

        $stmt = $pdo->prepare("
            INSERT INTO outgoing_letters
                (outgoing_code, incoming_letter_id, institution_name, recipient_name,
                 recipient_contact, subject, letter_body, appointment_date, appointment_time,
                 created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            generate_outgoing_letter_code(),
            $incomingLetterId > 0 ? $incomingLetterId : null,
            $institutionName,
            $recipientName !== '' ? $recipientName : null,
            $recipientContact !== '' ? $recipientContact : null,
            $subject,
            $letterBody,
            $appointmentDate !== '' ? $appointmentDate : null,
            $timeValue,
            $userId,
        ]);

        $_SESSION['flash_messages'][] = 'Surat keluar berhasil disimpan.';
        surat_redirect();
    }

    if ($action === 'add_meeting_minutes') {
        $incomingLetterId = (int)($_POST['incoming_letter_id'] ?? 0);
        $outgoingLetterId = (int)($_POST['outgoing_letter_id'] ?? 0);
        $meetingTitle = trim((string)($_POST['meeting_title'] ?? ''));
        $meetingDate = trim((string)($_POST['meeting_date'] ?? ''));
        $meetingTime = trim((string)($_POST['meeting_time'] ?? ''));
        $participants = trim((string)($_POST['participants'] ?? ''));
        $summary = trim((string)($_POST['summary'] ?? ''));
        $decisions = trim((string)($_POST['decisions'] ?? ''));
        $followUp = trim((string)($_POST['follow_up'] ?? ''));

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

        $stmt = $pdo->prepare("
            INSERT INTO meeting_minutes
                (incoming_letter_id, outgoing_letter_id, meeting_title, meeting_date,
                 meeting_time, participants, summary, decisions, follow_up, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
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
        ]);

        $_SESSION['flash_messages'][] = 'Notulen pertemuan berhasil disimpan.';
        surat_redirect();
    }

    throw new Exception('Aksi tidak dikenali.');
} catch (Throwable $e) {
    $_SESSION['flash_errors'][] = 'Gagal memproses: ' . $e->getMessage();
    surat_redirect();
}
