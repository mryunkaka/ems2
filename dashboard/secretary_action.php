<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

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

function secretaryCode(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
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

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    secretaryRedirect();
}

try {
    if ($action === 'save_visit_agenda') {
        $visitorName = trim((string) ($_POST['visitor_name'] ?? ''));
        $originName = trim((string) ($_POST['origin_name'] ?? ''));
        $visitPurpose = trim((string) ($_POST['visit_purpose'] ?? ''));
        $visitDate = secretaryDate(trim((string) ($_POST['visit_date'] ?? '')), 'Tanggal kunjungan tidak valid.');
        $visitTime = secretaryTime(trim((string) ($_POST['visit_time'] ?? '')), 'Jam kunjungan tidak valid.');
        $location = trim((string) ($_POST['location'] ?? ''));
        $picUserId = (int) ($_POST['pic_user_id'] ?? 0);
        $status = secretaryAssertAllowed(trim((string) ($_POST['status'] ?? 'scheduled')), ['scheduled', 'ongoing', 'completed', 'cancelled'], 'Status agenda tidak valid.');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($visitorName === '' || $visitPurpose === '' || $location === '' || $picUserId <= 0) {
            throw new Exception('Data agenda kunjungan wajib lengkap.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO secretary_visit_agendas
                (agenda_code, visitor_name, origin_name, visit_purpose, visit_date, visit_time,
                 location, pic_user_id, status, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            secretaryCode('SVA'),
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
        $title = trim((string) ($_POST['title'] ?? ''));
        $divisionScope = trim((string) ($_POST['division_scope'] ?? ''));
        $hostUserId = (int) ($_POST['host_user_id'] ?? 0);
        $coordinationDate = secretaryDate(trim((string) ($_POST['coordination_date'] ?? '')), 'Tanggal koordinasi tidak valid.');
        $startTime = secretaryTime(trim((string) ($_POST['start_time'] ?? '')), 'Jam koordinasi tidak valid.');
        $status = secretaryAssertAllowed(trim((string) ($_POST['status'] ?? 'draft')), ['draft', 'scheduled', 'done', 'cancelled'], 'Status koordinasi tidak valid.');
        $summaryNotes = trim((string) ($_POST['summary_notes'] ?? ''));
        $followUpNotes = trim((string) ($_POST['follow_up_notes'] ?? ''));

        if ($title === '' || $divisionScope === '' || $hostUserId <= 0) {
            throw new Exception('Data koordinasi internal wajib lengkap.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO secretary_internal_coordinations
                (coordination_code, title, division_scope, host_user_id, coordination_date,
                 start_time, status, summary_notes, follow_up_notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            secretaryCode('SIC'),
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
        $referenceNumber = trim((string) ($_POST['reference_number'] ?? ''));
        $letterDirection = secretaryAssertAllowed(trim((string) ($_POST['letter_direction'] ?? 'incoming')), ['incoming', 'outgoing'], 'Arah surat tidak valid.');
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $counterpartyName = trim((string) ($_POST['counterparty_name'] ?? ''));
        $confidentialityLevel = secretaryAssertAllowed(trim((string) ($_POST['confidentiality_level'] ?? 'confidential')), ['confidential', 'secret', 'top_secret'], 'Level kerahasiaan tidak valid.');
        $letterDate = secretaryDate(trim((string) ($_POST['letter_date'] ?? '')), 'Tanggal surat tidak valid.');
        $status = secretaryAssertAllowed(trim((string) ($_POST['status'] ?? 'logged')), ['logged', 'sealed', 'distributed', 'archived'], 'Status surat rahasia tidak valid.');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($referenceNumber === '' || $subject === '' || $counterpartyName === '') {
            throw new Exception('Data surat rahasia wajib lengkap.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO secretary_confidential_letters
                (register_code, reference_number, letter_direction, subject, counterparty_name,
                 confidentiality_level, letter_date, status, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            secretaryCode('SCL'),
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

    throw new Exception('Aksi secretary tidak dikenali.');
} catch (Throwable $e) {
    $_SESSION['flash_errors'][] = 'Gagal memproses secretary: ' . $e->getMessage();
    secretaryRedirect();
}
