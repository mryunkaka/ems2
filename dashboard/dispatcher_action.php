<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/dispatcher.php';
require_once __DIR__ . '/../config/inbox_helper.php';

$redirectTo = '/dashboard/dispatcher.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

ems_dispatcher_ensure_tables($pdo);

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');
$userFullName = trim((string)($user['full_name'] ?? $user['name'] ?? 'Dispatcher'));
$canManage = ems_is_manager_plus_role($userRole);
$canHardDelete = ems_is_director_role($userRole);
$unitCode = ems_effective_unit($pdo, $user);

$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($action === 'create_assignment') {
        if (!$canManage) {
            throw new RuntimeException('Hanya manager ke atas yang dapat mengatur status dispatcher.');
        }

        $statusCode = trim((string)($_POST['status_code'] ?? ''));
        $statusOptions = ems_dispatcher_status_options();
        if (!isset($statusOptions[$statusCode])) {
            throw new RuntimeException('Status dispatcher tidak valid.');
        }

        $customLabel = trim((string)($_POST['status_label_custom'] ?? ''));
        if ($statusOptions[$statusCode]['requires_custom_label'] && $customLabel === '') {
            throw new RuntimeException('Label status "Lainnya" wajib diisi.');
        }

        $coordinate = trim((string)($_POST['coordinate'] ?? ''));
        if ($statusOptions[$statusCode]['requires_location'] && $coordinate === '') {
            throw new RuntimeException('Koordinat wajib diisi untuk status Respon Lapangan.');
        }

        $locationName = trim((string)($_POST['location_name'] ?? ''));
        $koordinasiNote = trim((string)($_POST['koordinasi_note'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        $medicIds = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string)($_POST['medic_ids'] ?? ''))
        ), static fn($id) => $id > 0)));

        if (empty($medicIds)) {
            throw new RuntimeException('Pilih minimal 1 medis untuk diberi status.');
        }

        $placeholders = implode(',', array_fill(0, count($medicIds), '?'));
        $stmtMedics = $pdo->prepare("
            SELECT id, full_name, position
            FROM user_rh
            WHERE id IN ($placeholders)
              AND is_active = 1
              AND division = 'Medis'
              AND COALESCE(unit_code, 'roxwood') = ?
        ");
        $stmtMedics->execute([...$medicIds, $unitCode]);
        $medics = $stmtMedics->fetchAll(PDO::FETCH_ASSOC);

        if (count($medics) !== count($medicIds)) {
            throw new RuntimeException('Beberapa medis yang dipilih tidak valid, tidak aktif, atau berbeda unit.');
        }

        $pdo->beginTransaction();

        // Kunci & clear assignment aktif sebelumnya milik medis yang akan ditugaskan ulang,
        // supaya invariant "1 medis hanya 1 status aktif" tetap terjaga.
        $stmtOldAssignments = $pdo->prepare("
            SELECT DISTINCT da.id
            FROM dispatcher_assignments da
            JOIN dispatcher_assignment_members dam ON dam.assignment_id = da.id
            WHERE dam.medic_user_id IN ($placeholders)
              AND da.status = 'active'
            FOR UPDATE
        ");
        $stmtOldAssignments->execute($medicIds);
        $oldAssignmentIds = array_map('intval', $stmtOldAssignments->fetchAll(PDO::FETCH_COLUMN));

        if (!empty($oldAssignmentIds)) {
            $oldPlaceholders = implode(',', array_fill(0, count($oldAssignmentIds), '?'));
            $stmtClearOld = $pdo->prepare("
                UPDATE dispatcher_assignments
                SET status = 'cleared', cleared_at = NOW(), cleared_by = ?, cleared_by_name_snapshot = ?
                WHERE id IN ($oldPlaceholders) AND status = 'active'
            ");
            $stmtClearOld->execute([$userId, $userFullName, ...$oldAssignmentIds]);
        }

        $assignmentCode = ems_dispatcher_generate_code();
        $stmtInsert = $pdo->prepare("
            INSERT INTO dispatcher_assignments
                (assignment_code, status_code, status_label_custom, coordinate, location_name,
                 koordinasi_note, note, unit_code, status, started_at, created_by, created_by_name_snapshot)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), ?, ?)
        ");
        $stmtInsert->execute([
            $assignmentCode,
            $statusCode,
            $statusCode === 'lainnya' ? $customLabel : null,
            $coordinate !== '' ? $coordinate : null,
            $locationName !== '' ? $locationName : null,
            $koordinasiNote !== '' ? $koordinasiNote : null,
            $note !== '' ? $note : null,
            $unitCode,
            $userId,
            $userFullName,
        ]);
        $assignmentId = (int)$pdo->lastInsertId();

        $stmtMember = $pdo->prepare("
            INSERT INTO dispatcher_assignment_members
                (assignment_id, medic_user_id, medic_name_snapshot, medic_jabatan_snapshot, joined_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        foreach ($medics as $medic) {
            $stmtMember->execute([
                $assignmentId,
                (int)$medic['id'],
                $medic['full_name'],
                ems_position_label($medic['position'] ?? '-'),
            ]);
        }

        $pdo->commit();

        $statusLabel = ems_dispatcher_status_label($statusCode, $customLabel);
        $memberNames = implode(', ', array_column($medics, 'full_name'));
        $inboxMessage = 'Status dispatcher Anda diubah menjadi "' . $statusLabel . '"';
        if ($coordinate !== '') {
            $inboxMessage .= ' — Koordinat: ' . $coordinate . ($locationName !== '' ? ' (' . $locationName . ')' : '');
        }
        if (count($medics) > 1) {
            $inboxMessage .= '. Rekan bertugas: ' . $memberNames . '.';
        }
        foreach ($medics as $medic) {
            sendInbox($pdo, (int)$medic['id'], 'Tugas Dispatcher', $inboxMessage, 'dispatcher');
        }

        $_SESSION['flash_messages'][] = 'Status dispatcher berhasil disimpan untuk ' . count($medics) . ' medis.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'clear_assignment') {
        if (!$canManage) {
            throw new RuntimeException('Hanya manager ke atas yang dapat meng-clear tugas dispatcher.');
        }

        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            throw new RuntimeException('Data tugas tidak valid.');
        }

        $pdo->beginTransaction();

        $stmtLock = $pdo->prepare("SELECT id FROM dispatcher_assignments WHERE id = ? AND status = 'active' FOR UPDATE");
        $stmtLock->execute([$assignmentId]);
        if (!$stmtLock->fetch()) {
            throw new RuntimeException('Tugas dispatcher ini sudah di-clear atau tidak ditemukan.');
        }

        $stmtMembers = $pdo->prepare("SELECT medic_user_id, medic_name_snapshot FROM dispatcher_assignment_members WHERE assignment_id = ?");
        $stmtMembers->execute([$assignmentId]);
        $members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

        $stmtClear = $pdo->prepare("
            UPDATE dispatcher_assignments
            SET status = 'cleared', cleared_at = NOW(), cleared_by = ?, cleared_by_name_snapshot = ?
            WHERE id = ?
        ");
        $stmtClear->execute([$userId, $userFullName, $assignmentId]);

        $pdo->commit();

        foreach ($members as $member) {
            sendInbox(
                $pdo,
                (int)$member['medic_user_id'],
                'Tugas Dispatcher Selesai',
                'Tugas/status dispatcher Anda telah di-clear oleh ' . $userFullName . '.',
                'dispatcher'
            );
        }

        $_SESSION['flash_messages'][] = 'Tugas dispatcher berhasil di-clear.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'delete_assignment') {
        if (!$canHardDelete) {
            throw new RuntimeException('Hanya Director/Vice Director yang dapat menghapus riwayat dispatcher.');
        }

        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            throw new RuntimeException('Data tugas tidak valid.');
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM dispatcher_assignment_members WHERE assignment_id = ?")->execute([$assignmentId]);
        $pdo->prepare("DELETE FROM dispatcher_assignments WHERE id = ?")->execute([$assignmentId]);
        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Riwayat dispatcher berhasil dihapus.';
        header('Location: ' . $redirectTo);
        exit;
    }

    throw new RuntimeException('Aksi tidak dikenali.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_errors'][] = $e->getMessage();
    header('Location: ' . $redirectTo);
    exit;
}
