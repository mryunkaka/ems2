<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';

$eventId = (int)($_POST['event_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
$redirect = '/dashboard/events.php' . ($eventId > 0 ? '?id=' . $eventId : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $eventId <= 0 || $userId <= 0 || !in_array($action, ['register', 'cancel'], true)) {
    $_SESSION['flash_errors'][] = 'Permintaan pendaftaran event tidak valid.';
    header('Location: ' . ($eventId > 0 ? $redirect : '/dashboard/events.php'));
    exit;
}

if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['flash_errors'][] = 'Token keamanan tidak valid. Silakan coba lagi.';
    header('Location: ' . $redirect);
    exit;
}

$reason = trim((string)($_POST['reason'] ?? ''));
if (mb_strlen($reason, 'UTF-8') > 1000) {
    $_SESSION['flash_errors'][] = 'Alasan maksimal 1000 karakter.';
    header('Location: ' . $redirect);
    exit;
}

try {
    $eventStmt = $pdo->prepare('SELECT id FROM events WHERE id = ? AND is_active = 1 LIMIT 1');
    $eventStmt->execute([$eventId]);
    if (!$eventStmt->fetchColumn()) {
        $_SESSION['flash_errors'][] = 'Event tidak ditemukan atau sudah tidak aktif.';
        header('Location: /dashboard/events.php');
        exit;
    }

    $activeStmt = $pdo->prepare('SELECT id FROM event_participants WHERE event_id = ? AND user_id = ? LIMIT 1');
    $activeStmt->execute([$eventId, $userId]);
    $activeParticipantId = (int)($activeStmt->fetchColumn() ?: 0);

    $historyStmt = $pdo->prepare('SELECT action FROM event_registration_history WHERE event_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1');
    $historyStmt->execute([$eventId, $userId]);
    $lastAction = $historyStmt->fetchColumn();

    if ($action === 'register' && $activeParticipantId > 0) {
        $_SESSION['flash_errors'][] = 'Anda sudah terdaftar pada event ini.';
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'register' && $lastAction === 'cancelled' && $reason === '') {
        $_SESSION['flash_errors'][] = 'Alasan daftar ulang wajib diisi.';
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'cancel' && $activeParticipantId <= 0) {
        $_SESSION['flash_errors'][] = 'Anda belum terdaftar pada event ini.';
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'cancel' && $reason === '') {
        $_SESSION['flash_errors'][] = 'Alasan pembatalan wajib diisi.';
        header('Location: ' . $redirect);
        exit;
    }

    $successMessage = '';
    $pdo->beginTransaction();
    if ($action === 'register') {
        $insertParticipant = $pdo->prepare('INSERT INTO event_participants (event_id, user_id) VALUES (?, ?)');
        $insertParticipant->execute([$eventId, $userId]);

        $historyReason = $lastAction === 'cancelled' ? $reason : null;
        $insertHistory = $pdo->prepare("INSERT INTO event_registration_history (event_id, user_id, action, reason) VALUES (?, ?, 'registered', ?)");
        $insertHistory->execute([$eventId, $userId, $historyReason]);
        $successMessage = $lastAction === 'cancelled'
            ? 'Pendaftaran ulang event berhasil.'
            : 'Pendaftaran event berhasil.';
    } else {
        $deleteParticipant = $pdo->prepare('DELETE FROM event_participants WHERE id = ?');
        $deleteParticipant->execute([$activeParticipantId]);
        if ($deleteParticipant->rowCount() !== 1) {
            throw new RuntimeException('Pendaftaran aktif berubah sebelum pembatalan disimpan.');
        }

        $insertHistory = $pdo->prepare("INSERT INTO event_registration_history (event_id, user_id, action, reason) VALUES (?, ?, 'cancelled', ?)");
        $insertHistory->execute([$eventId, $userId, $reason]);

        $deleteGroupMembers = $pdo->prepare('
            DELETE gm
            FROM event_group_members gm
            INNER JOIN event_groups eg ON eg.id = gm.event_group_id
            WHERE eg.event_id = ? AND gm.user_id = ?
        ');
        $deleteGroupMembers->execute([$eventId, $userId]);
        $successMessage = 'Pendaftaran event berhasil dibatalkan.';
    }
    $pdo->commit();
    $_SESSION['flash_messages'][] = $successMessage;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Event registration] ' . $e->getMessage());
    $_SESSION['flash_errors'][] = 'Perubahan pendaftaran gagal disimpan. Silakan coba lagi.';
}

header('Location: ' . $redirect);
exit;
