<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$userDivision = ems_normalize_division($_SESSION['user_rh']['division'] ?? '');
if ($userRole === 'staff') {
    http_response_code(403);
    exit('Akses ditolak');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$reviewerId = (int)($_SESSION['user_rh']['id'] ?? 0);
$requestId = (int)($_POST['request_id'] ?? 0);
$action = strtolower(trim($_POST['action'] ?? ''));
$note = trim((string)($_POST['reviewer_note'] ?? ''));
if ($note === '') $note = null;

if ($requestId <= 0 || !in_array($action, ['approve', 'reject', 'delete'], true)) {
    $_SESSION['flash_errors'][] = 'Data tidak valid.';
    header('Location: review_pengajuan_jabatan.php');
    exit;
}

// Delete action - only for Specialist Medical Authority
if ($action === 'delete') {
    if ($userDivision !== 'Specialist Medical Authority') {
        $_SESSION['flash_errors'][] = 'Hanya divisi Specialist Medical Authority yang dapat menghapus pengajuan.';
        header('Location: review_pengajuan_jabatan.php?status=pending&id=' . $requestId);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id FROM position_promotion_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        if (!$stmt->fetch()) {
            throw new Exception('Pengajuan tidak ditemukan.');
        }

        $stmt = $pdo->prepare("DELETE FROM position_promotion_request_operations WHERE request_id = ?");
        $stmt->execute([$requestId]);

        $stmt = $pdo->prepare("DELETE FROM position_promotion_requests WHERE id = ?");
        $stmt->execute([$requestId]);

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Pengajuan berhasil dihapus permanen. User dapat mengajukan ulang.';
        header('Location: review_pengajuan_jabatan.php?status=pending');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_errors'][] = 'Gagal menghapus pengajuan: ' . $e->getMessage();
        header('Location: review_pengajuan_jabatan.php?status=pending&id=' . $requestId);
        exit;
    }
}

$nextMap = [
    'trainee' => ems_next_position('trainee'),
    'paramedic' => ems_next_position('paramedic'),
    'co_asst' => ems_next_position('co_asst'),
];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT *
        FROM position_promotion_requests
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        throw new Exception('Request tidak ditemukan.');
    }

    if (($req['status'] ?? '') !== 'pending') {
        throw new Exception('Request ini sudah diproses.');
    }

    $userId = (int)($req['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception('User pemohon tidak valid.');
    }

    $stmt = $pdo->prepare("
        SELECT id, position
        FROM user_rh
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('User pemohon tidak ditemukan.');
    }

    $from = ems_normalize_position($req['from_position'] ?? '');
    $to = ems_normalize_position($req['to_position'] ?? '');
    $current = ems_normalize_position($user['position'] ?? '');

    if (!ems_is_valid_position($from) || !ems_is_valid_position($to)) {
        throw new Exception('Jalur jabatan pada request tidak valid.');
    }

    if ($current !== $from) {
        throw new Exception('Jabatan user saat ini sudah berubah. Tidak bisa memproses request ini.');
    }

    if (($nextMap[$from] ?? '') !== $to) {
        throw new Exception('Jalur kenaikan tidak sesuai aturan sistem saat ini.');
    }

    if ($action === 'reject') {
        $stmt = $pdo->prepare("
            UPDATE position_promotion_requests
            SET status = 'rejected',
                reviewed_at = NOW(),
                reviewed_by = ?,
                reviewer_note = ?
            WHERE id = ?
        ");
        $stmt->execute([$reviewerId ?: null, $note, $requestId]);

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Pengajuan berhasil di-reject.';
        header('Location: review_pengajuan_jabatan.php?status=pending');
        exit;
    }

    // Approve: update user position + request status
    $stmt = $pdo->prepare("UPDATE user_rh SET position = ? WHERE id = ?");
    $stmt->execute([$to, $userId]);

    $stmt = $pdo->prepare("
        UPDATE position_promotion_requests
        SET status = 'approved',
            reviewed_at = NOW(),
            reviewed_by = ?,
            reviewer_note = ?
        WHERE id = ?
    ");
    $stmt->execute([$reviewerId ?: null, $note, $requestId]);

    $pdo->commit();
    $_SESSION['flash_messages'][] = 'Pengajuan berhasil di-approve dan jabatan user sudah diupdate.';
    header('Location: review_pengajuan_jabatan.php?status=pending');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_errors'][] = 'Gagal memproses: ' . $e->getMessage();
    header('Location: review_pengajuan_jabatan.php?status=pending&id=' . $requestId);
    exit;
}
