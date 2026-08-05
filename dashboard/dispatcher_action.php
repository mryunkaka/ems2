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
$isSupervisor = ems_dispatcher_is_supervisor($pdo, $userId, $unitCode);

$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($action === 'toggle_supervisor') {
        if (!$canManage) {
            throw new RuntimeException('Hanya manager ke atas yang bisa menjadi Penanggung Jawab Dispatcher.');
        }
        if ($userId <= 0) {
            throw new RuntimeException('Sesi user tidak valid.');
        }

        if ($isSupervisor) {
            $pdo->prepare("DELETE FROM dispatcher_supervisors WHERE user_id = ? AND unit_code = ?")
                ->execute([$userId, $unitCode]);
            $_SESSION['flash_messages'][] = 'Anda berhenti menjadi Penanggung Jawab Dispatcher.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO dispatcher_supervisors (user_id, unit_code, user_name_snapshot, activated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE activated_at = NOW(), user_name_snapshot = VALUES(user_name_snapshot)
            ");
            $stmt->execute([$userId, $unitCode, $userFullName]);
            $_SESSION['flash_messages'][] = 'Anda sekarang menjadi Penanggung Jawab Dispatcher.';
        }

        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'add_roster_member') {
        if (!$isSupervisor) {
            throw new RuntimeException('Hanya Penanggung Jawab Dispatcher yang bisa mengatur roster.');
        }

        $medicIds = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string)($_POST['medic_ids'] ?? ''))
        ), static fn($id) => $id > 0)));

        if (empty($medicIds)) {
            throw new RuntimeException('Pilih minimal 1 medis untuk ditambahkan ke roster.');
        }

        $placeholders = implode(',', array_fill(0, count($medicIds), '?'));
        $stmtMedics = $pdo->prepare("
            SELECT id, full_name
            FROM user_rh
            WHERE id IN ($placeholders)
              AND is_active = 1
              AND division = 'Medis'
              AND COALESCE(unit_code, 'roxwood') = ?
        ");
        $stmtMedics->execute([...$medicIds, $unitCode]);
        $medics = $stmtMedics->fetchAll(PDO::FETCH_ASSOC);

        if (!$medics) {
            throw new RuntimeException('Medis yang dipilih tidak valid, tidak aktif, atau berbeda unit.');
        }

        $stmtInsert = $pdo->prepare("
            INSERT INTO dispatcher_roster (medic_user_id, unit_code, medic_name_snapshot, added_by, added_by_name_snapshot, added_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE medic_user_id = medic_user_id
        ");
        foreach ($medics as $medic) {
            $stmtInsert->execute([(int)$medic['id'], $unitCode, $medic['full_name'], $userId, $userFullName]);
        }

        $_SESSION['flash_messages'][] = count($medics) . ' medis ditambahkan ke roster dispatcher.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'remove_roster_member') {
        if (!$isSupervisor) {
            throw new RuntimeException('Hanya Penanggung Jawab Dispatcher yang bisa mengatur roster.');
        }

        $medicId = (int)($_POST['medic_id'] ?? 0);
        if ($medicId <= 0) {
            throw new RuntimeException('Data medis tidak valid.');
        }

        $pdo->beginTransaction();

        // Clear tugas aktif medis ini dulu (kalau ada) supaya tidak "menggantung"
        // setelah dikeluarkan dari roster (tidak lagi tampil di papan status).
        $stmtOld = $pdo->prepare("
            SELECT DISTINCT da.id
            FROM dispatcher_assignments da
            JOIN dispatcher_assignment_members dam ON dam.assignment_id = da.id
            WHERE dam.medic_user_id = ? AND da.status = 'active' AND da.unit_code = ?
            FOR UPDATE
        ");
        $stmtOld->execute([$medicId, $unitCode]);
        $oldIds = array_map('intval', $stmtOld->fetchAll(PDO::FETCH_COLUMN));
        if ($oldIds) {
            $ph = implode(',', array_fill(0, count($oldIds), '?'));
            $pdo->prepare("
                UPDATE dispatcher_assignments
                SET status = 'cleared', cleared_at = NOW(), cleared_by = ?, cleared_by_name_snapshot = ?
                WHERE id IN ($ph)
            ")->execute([$userId, $userFullName, ...$oldIds]);
        }

        $pdo->prepare("DELETE FROM dispatcher_roster WHERE medic_user_id = ? AND unit_code = ?")
            ->execute([$medicId, $unitCode]);

        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Medis dikeluarkan dari roster dispatcher.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'generate_assignments') {
        if (!$isSupervisor) {
            throw new RuntimeException('Hanya Penanggung Jawab Dispatcher yang bisa menjalankan Generate.');
        }

        $stmtRoster = $pdo->prepare("
            SELECT dr.medic_user_id, ur.full_name, ur.position
            FROM dispatcher_roster dr
            JOIN user_rh ur ON ur.id = dr.medic_user_id
            WHERE dr.unit_code = ? AND ur.is_active = 1
            ORDER BY dr.added_at ASC
        ");
        $stmtRoster->execute([$unitCode]);
        $rosterRows = $stmtRoster->fetchAll(PDO::FETCH_ASSOC);

        if (!$rosterRows) {
            throw new RuntimeException('Roster masih kosong. Tambahkan medis ke roster dulu sebelum generate.');
        }

        $farmasiOnlineIds = ems_dispatcher_farmasi_online_medic_ids($pdo, $unitCode);
        $rosterIds = array_map(static fn($r) => (int)$r['medic_user_id'], $rosterRows);
        $rosterPlaceholders = implode(',', array_fill(0, count($rosterIds), '?'));

        $stmtActive = $pdo->prepare("
            SELECT dam.medic_user_id, da.status_code
            FROM dispatcher_assignment_members dam
            JOIN dispatcher_assignments da ON da.id = dam.assignment_id
            WHERE dam.medic_user_id IN ($rosterPlaceholders) AND da.status = 'active' AND da.unit_code = ?
        ");
        $stmtActive->execute([...$rosterIds, $unitCode]);
        $activeByMedic = [];
        foreach ($stmtActive->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $activeByMedic[(int)$row['medic_user_id']] = (string)$row['status_code'];
        }

        $lockedManualStatuses = ['off_duty', 'rapat', 'kunjungan', 'lainnya'];

        $eligible = [];
        $excludedFarmasi = 0;
        $excludedManual = 0;
        foreach ($rosterRows as $row) {
            $medicId = (int)$row['medic_user_id'];
            if (in_array($medicId, $farmasiOnlineIds, true)) {
                $excludedFarmasi++;
                continue;
            }
            $currentStatus = $activeByMedic[$medicId] ?? null;
            if ($currentStatus !== null && in_array($currentStatus, $lockedManualStatuses, true)) {
                $excludedManual++;
                continue;
            }
            $eligible[] = [
                'id' => $medicId,
                'name' => $row['full_name'],
                'jabatan' => ems_position_label($row['position'] ?? '-'),
                'position_code' => ems_normalize_position($row['position'] ?? ''),
            ];
        }

        if (!$eligible) {
            throw new RuntimeException('Tidak ada medis di roster yang bisa di-generate (semua sedang jaga farmasi atau berstatus manual).');
        }

        $eligibleIds = array_column($eligible, 'id');
        $lastDutyMap = ems_dispatcher_last_duty_at($pdo, $eligibleIds, $unitCode);
        $plan = ems_dispatcher_build_generate_plan($eligible, $lastDutyMap);

        $pdo->beginTransaction();

        $eligiblePlaceholders = implode(',', array_fill(0, count($eligibleIds), '?'));
        $stmtOldAuto = $pdo->prepare("
            SELECT DISTINCT da.id
            FROM dispatcher_assignments da
            JOIN dispatcher_assignment_members dam ON dam.assignment_id = da.id
            WHERE dam.medic_user_id IN ($eligiblePlaceholders) AND da.status = 'active' AND da.unit_code = ?
            FOR UPDATE
        ");
        $stmtOldAuto->execute([...$eligibleIds, $unitCode]);
        $oldAutoIds = array_map('intval', $stmtOldAuto->fetchAll(PDO::FETCH_COLUMN));
        if ($oldAutoIds) {
            $ph = implode(',', array_fill(0, count($oldAutoIds), '?'));
            $pdo->prepare("
                UPDATE dispatcher_assignments
                SET status = 'cleared', cleared_at = NOW(), cleared_by = ?, cleared_by_name_snapshot = ?
                WHERE id IN ($ph)
            ")->execute([$userId, $userFullName, ...$oldAutoIds]);
        }

        $groupsCreated = ['standby_resepsionis' => 0, 'bantu_igd' => 0, 'respon_lapangan' => 0];
        $notifyQueue = [];

        // Satu timestamp yang sama dipakai untuk SEMUA assignment yang dibuat di run
        // generate ini (bukan NOW() per-baris), supaya di ronde berikutnya seluruh
        // medic yang baru saja di-generate punya last-duty yang identik (satu grup
        // "seri" yang utuh) — ini yang membuat shuffle tie-break di
        // ems_dispatcher_sort_by_fairness() efektif merotasi mereka, bukannya
        // kebagi oleh selisih milidetik antar-insert dalam loop.
        $generatedAt = date('Y-m-d H:i:s');

        foreach (['standby_resepsionis', 'bantu_igd', 'respon_lapangan'] as $statusCode) {
            foreach ($plan[$statusCode] as $team) {
                if (!$team) {
                    continue;
                }

                $assignmentCode = ems_dispatcher_generate_code();
                $note = $statusCode === 'respon_lapangan'
                    ? 'Menunggu penempatan lokasi (auto-generate oleh ' . $userFullName . ').'
                    : 'Digenerate otomatis oleh ' . $userFullName . '.';

                $stmtInsertAssignment = $pdo->prepare("
                    INSERT INTO dispatcher_assignments
                        (assignment_code, status_code, unit_code, status, started_at, note, created_by, created_by_name_snapshot)
                    VALUES (?, ?, ?, 'active', ?, ?, ?, ?)
                ");
                $stmtInsertAssignment->execute([$assignmentCode, $statusCode, $unitCode, $generatedAt, $note, $userId, $userFullName]);
                $assignmentId = (int)$pdo->lastInsertId();

                $stmtInsertMember = $pdo->prepare("
                    INSERT INTO dispatcher_assignment_members
                        (assignment_id, medic_user_id, medic_name_snapshot, medic_jabatan_snapshot, joined_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                foreach ($team as $medic) {
                    $stmtInsertMember->execute([$assignmentId, $medic['id'], $medic['name'], $medic['jabatan']]);
                    $notifyQueue[] = $medic;
                }

                $groupsCreated[$statusCode]++;
            }
        }

        $pdo->commit();

        foreach ($notifyQueue as $medic) {
            sendInbox(
                $pdo,
                (int)$medic['id'],
                'Tugas Dispatcher (Generate)',
                'Status dispatcher Anda diatur otomatis lewat Generate Dispatcher oleh ' . $userFullName . '.',
                'dispatcher'
            );
        }

        $totalGroups = array_sum($groupsCreated);
        $summary = sprintf(
            'Generate selesai: %d grup dibuat (%d Resepsionis, %d Bantu IGD, %d Respon Lapangan)',
            $totalGroups,
            $groupsCreated['standby_resepsionis'],
            $groupsCreated['bantu_igd'],
            $groupsCreated['respon_lapangan']
        );
        if ($excludedFarmasi > 0) {
            $summary .= "; {$excludedFarmasi} orang dilewati (sedang jaga farmasi)";
        }
        if ($excludedManual > 0) {
            $summary .= "; {$excludedManual} orang dilewati (status manual: istirahat/rapat/kunjungan/lainnya)";
        }
        if ($plan['unassigned']) {
            $summary .= '; ' . count($plan['unassigned']) . ' orang tetap Tersedia (tidak kebagian slot)';
        }
        $summary .= '.';

        $_SESSION['flash_messages'][] = $summary;
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'create_assignment') {
        if (!$isSupervisor) {
            throw new RuntimeException('Hanya Penanggung Jawab Dispatcher yang dapat mengatur status dispatcher.');
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

        $farmasiOnlineIds = ems_dispatcher_farmasi_online_medic_ids($pdo, $unitCode);
        $farmasiOnlineSelected = array_intersect($medicIds, $farmasiOnlineIds);
        if (!empty($farmasiOnlineSelected)) {
            throw new RuntimeException('Medis sedang jaga farmasi (Medis Online di Rekap Farmasi) tidak bisa diatur statusnya lewat Dispatcher. Tunggu sampai mereka offline farmasi.');
        }

        $stmtRosterCheck = $pdo->prepare("SELECT medic_user_id FROM dispatcher_roster WHERE unit_code = ? AND medic_user_id IN ($placeholders)");
        $stmtRosterCheck->execute([$unitCode, ...$medicIds]);
        $rosterIdsCheck = array_map('intval', $stmtRosterCheck->fetchAll(PDO::FETCH_COLUMN));
        if (count($rosterIdsCheck) !== count($medicIds)) {
            throw new RuntimeException('Semua medis harus ditambahkan ke roster dispatcher terlebih dahulu sebelum diberi status.');
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
        if (!$isSupervisor) {
            throw new RuntimeException('Hanya Penanggung Jawab Dispatcher yang dapat meng-clear tugas dispatcher.');
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
