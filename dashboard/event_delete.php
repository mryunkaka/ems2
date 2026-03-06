<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

// ===============================
// ROLE GUARD (MANAGER ONLY)
// ===============================
$role = strtolower($_SESSION['user_rh']['role'] ?? '');
if ($role === 'staff') {
    header('Location: events.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: event_manage.php');
    exit;
}

$eventId = (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) {
    $_SESSION['flash_errors'][] = 'ID event tidak valid.';
    header('Location: event_manage.php');
    exit;
}

try {
    // Pastikan event ada (buat pesan lebih jelas)
    $stmt = $pdo->prepare("SELECT nama_event FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $namaEvent = $stmt->fetchColumn();

    if (!$namaEvent) {
        $_SESSION['flash_errors'][] = 'Event tidak ditemukan atau sudah dihapus.';
        header('Location: event_manage.php');
        exit;
    }

    $pdo->beginTransaction();

    // Hapus group members -> groups (jika ada)
    $stmt = $pdo->prepare("SELECT id FROM event_groups WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $groupIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($groupIds)) {
        $in = implode(',', array_fill(0, count($groupIds), '?'));
        $pdo->prepare("
            DELETE FROM event_group_members
            WHERE event_group_id IN ($in)
        ")->execute($groupIds);

        $pdo->prepare("
            DELETE FROM event_groups
            WHERE id IN ($in)
        ")->execute($groupIds);
    }

    // Hapus peserta
    $pdo->prepare("DELETE FROM event_participants WHERE event_id = ?")->execute([$eventId]);

    // Hapus event
    $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$eventId]);

    $pdo->commit();

    $_SESSION['flash_messages'][] = 'Event "' . $namaEvent . '" berhasil dihapus permanen.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_errors'][] = 'Gagal menghapus event.';
}

header('Location: event_manage.php');
exit;

