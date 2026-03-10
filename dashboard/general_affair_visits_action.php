<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

ems_require_division_access(['General Affair'], '/dashboard/index.php');

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
$action = trim((string)($_POST['action'] ?? ''));

function gaVisitsRedirect(string $fallback = 'general_affair_visits.php'): void
{
    $redirectTo = trim((string)($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '' || strpos($redirectTo, '://') !== false || str_starts_with($redirectTo, '//')) {
        $redirectTo = $fallback;
    }

    header('Location: ' . $redirectTo);
    exit;
}

function gaVisitGenerateCode(): string
{
    return 'GAV-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function gaVisitValidateStatus(string $status): bool
{
    return in_array($status, ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled'], true);
}

function gaVisitValidatePayload(PDO $pdo, array $input): array
{
    $visitorName = trim((string)($input['visitor_name'] ?? ''));
    $institutionName = trim((string)($input['institution_name'] ?? ''));
    $visitorPhone = trim((string)($input['visitor_phone'] ?? ''));
    $visitPurpose = trim((string)($input['visit_purpose'] ?? ''));
    $visitDate = trim((string)($input['visit_date'] ?? ''));
    $startTime = trim((string)($input['start_time'] ?? ''));
    $endTime = trim((string)($input['end_time'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    $picUserId = (int)($input['pic_user_id'] ?? 0);
    $notes = trim((string)($input['notes'] ?? ''));

    if ($visitorName === '' || $visitPurpose === '' || $visitDate === '' || $startTime === '' || $location === '' || $picUserId <= 0) {
        throw new Exception('Data visit wajib lengkap.');
    }

    $visitDateObj = DateTime::createFromFormat('Y-m-d', $visitDate);
    if (!$visitDateObj || $visitDateObj->format('Y-m-d') !== $visitDate) {
        throw new Exception('Tanggal visit tidak valid.');
    }

    $startTimeObj = DateTime::createFromFormat('H:i', $startTime);
    if (!$startTimeObj) {
        throw new Exception('Jam mulai tidak valid.');
    }

    $endTimeValue = null;
    if ($endTime !== '') {
        $endTimeObj = DateTime::createFromFormat('H:i', $endTime);
        if (!$endTimeObj) {
            throw new Exception('Jam selesai tidak valid.');
        }

        if ($endTimeObj <= $startTimeObj) {
            throw new Exception('Jam selesai harus lebih besar dari jam mulai.');
        }

        $endTimeValue = $endTimeObj->format('H:i:s');
    }

    $stmt = $pdo->prepare("SELECT id FROM user_rh WHERE id = ? LIMIT 1");
    $stmt->execute([$picUserId]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('PIC internal tidak ditemukan.');
    }

    return [
        'visitor_name' => $visitorName,
        'institution_name' => $institutionName !== '' ? $institutionName : null,
        'visitor_phone' => $visitorPhone !== '' ? $visitorPhone : null,
        'visit_purpose' => $visitPurpose,
        'visit_date' => $visitDate,
        'start_time' => $startTimeObj->format('H:i:s'),
        'end_time' => $endTimeValue,
        'location' => $location,
        'pic_user_id' => $picUserId,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    gaVisitsRedirect();
}

try {
    if ($action === 'create_visit') {
        $payload = gaVisitValidatePayload($pdo, $_POST);

        $stmt = $pdo->prepare("
            INSERT INTO general_affair_visits
                (visit_code, visitor_name, institution_name, visitor_phone, visit_purpose,
                 visit_date, start_time, end_time, location, pic_user_id, status, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, ?)
        ");
        $stmt->execute([
            gaVisitGenerateCode(),
            $payload['visitor_name'],
            $payload['institution_name'],
            $payload['visitor_phone'],
            $payload['visit_purpose'],
            $payload['visit_date'],
            $payload['start_time'],
            $payload['end_time'],
            $payload['location'],
            $payload['pic_user_id'],
            $payload['notes'],
            $userId,
        ]);

        $_SESSION['flash_messages'][] = 'Visit baru berhasil disimpan.';
        gaVisitsRedirect();
    }

    if ($action === 'update_visit') {
        $visitId = (int)($_POST['visit_id'] ?? 0);
        if ($visitId <= 0) {
            throw new Exception('Visit tidak valid.');
        }

        $payload = gaVisitValidatePayload($pdo, $_POST);

        $stmt = $pdo->prepare("
            UPDATE general_affair_visits
            SET
                visitor_name = ?,
                institution_name = ?,
                visitor_phone = ?,
                visit_purpose = ?,
                visit_date = ?,
                start_time = ?,
                end_time = ?,
                location = ?,
                pic_user_id = ?,
                notes = ?,
                updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $payload['visitor_name'],
            $payload['institution_name'],
            $payload['visitor_phone'],
            $payload['visit_purpose'],
            $payload['visit_date'],
            $payload['start_time'],
            $payload['end_time'],
            $payload['location'],
            $payload['pic_user_id'],
            $payload['notes'],
            $userId,
            $visitId,
        ]);

        if ($stmt->rowCount() <= 0) {
            throw new Exception('Visit tidak ditemukan atau tidak ada perubahan.');
        }

        $_SESSION['flash_messages'][] = 'Data visit berhasil diperbarui.';
        gaVisitsRedirect();
    }

    if ($action === 'update_status') {
        $visitId = (int)($_POST['visit_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'scheduled'));

        if ($visitId <= 0 || !gaVisitValidateStatus($status)) {
            throw new Exception('Status visit tidak valid.');
        }

        $stmt = $pdo->prepare("
            UPDATE general_affair_visits
            SET status = ?, updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $userId, $visitId]);

        if ($stmt->rowCount() <= 0) {
            throw new Exception('Visit tidak ditemukan atau status belum berubah.');
        }

        $_SESSION['flash_messages'][] = 'Status visit berhasil diperbarui.';
        gaVisitsRedirect();
    }

    if ($action === 'delete_visit') {
        $visitId = (int)($_POST['visit_id'] ?? 0);
        if ($visitId <= 0) {
            throw new Exception('Visit tidak valid.');
        }

        $stmt = $pdo->prepare("DELETE FROM general_affair_visits WHERE id = ?");
        $stmt->execute([$visitId]);

        if ($stmt->rowCount() <= 0) {
            throw new Exception('Visit tidak ditemukan.');
        }

        $_SESSION['flash_messages'][] = 'Visit berhasil dihapus.';
        gaVisitsRedirect();
    }

    throw new Exception('Aksi tidak dikenali.');
} catch (Throwable $e) {
    $_SESSION['flash_errors'][] = 'Gagal memproses visit: ' . $e->getMessage();
    gaVisitsRedirect();
}
