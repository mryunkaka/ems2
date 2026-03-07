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

$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
if ($userId <= 0) {
    header('Location: /auth/login.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, full_name, position, batch, tanggal_masuk
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$userDb = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$userDb) {
    http_response_code(404);
    exit('User not found');
}

$fromPosition = ems_normalize_position($userDb['position'] ?? '');
$expectedTo = ems_next_position($fromPosition);
$toPosition = ems_normalize_position($_POST['to_position'] ?? '');

if ($expectedTo === '' || $toPosition !== $expectedTo) {
    $_SESSION['flash_errors'][] = 'Tujuan kenaikan jabatan tidak valid.';
    header('Location: pengajuan_jabatan.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT from_position, to_position, min_days_since_join, min_operations, notes
    FROM position_promotion_requirements
    WHERE from_position = ?
      AND to_position = ?
      AND is_active = 1
    LIMIT 1
");
$stmt->execute([$fromPosition, $toPosition]);
$req = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$minDays = array_key_exists('min_days_since_join', $req) ? (int)$req['min_days_since_join'] : null;
$minOps = array_key_exists('min_operations', $req) ? (int)$req['min_operations'] : null;
$notes = (string)($req['notes'] ?? '');

// Prevent duplicate pending
$stmt = $pdo->prepare("
    SELECT id
    FROM position_promotion_requests
    WHERE user_id = ?
      AND from_position = ?
      AND to_position = ?
      AND status = 'pending'
    LIMIT 1
");
$stmt->execute([$userId, $fromPosition, $toPosition]);
if ($stmt->fetchColumn()) {
    $_SESSION['flash_warnings'][] = 'Masih ada pengajuan pending untuk jalur ini.';
    header('Location: pengajuan_jabatan.php');
    exit;
}

// Validation: join date (trainee -> paramedic)
$joinDateRaw = $userDb['tanggal_masuk'] ?? null;
$joinDate = $joinDateRaw ? (new DateTime($joinDateRaw)) : null;
if ($minDays !== null) {
    if (!$joinDate) {
        $_SESSION['flash_errors'][] = 'Tanggal masuk belum terdata.';
        header('Location: pengajuan_jabatan.php');
        exit;
    }
    $days = (int)$joinDate->diff(new DateTime('today'))->days;
    if ($days < $minDays) {
        $_SESSION['flash_errors'][] = "Belum memenuhi syarat join minimal {$minDays} hari.";
        header('Location: pengajuan_jabatan.php');
        exit;
    }
}

// Validation: operations
$ops = [];
if (in_array($fromPosition, ['paramedic', 'co_asst'], true)) {
    $patients = $_POST['ops_patient_name'] ?? [];
    $procedures = $_POST['ops_procedure_name'] ?? [];
    $dpjps = $_POST['ops_dpjp'] ?? [];
    $roles = $_POST['ops_role'] ?? [];
    $levels = $_POST['ops_level'] ?? [];

    if (!is_array($patients) || !is_array($procedures) || !is_array($dpjps) || !is_array($roles) || !is_array($levels)) {
        $_SESSION['flash_errors'][] = 'Format riwayat operasi tidak valid.';
        header('Location: pengajuan_jabatan.php');
        exit;
    }

    $count = min(count($patients), count($procedures), count($dpjps), count($roles), count($levels));
    for ($i = 0; $i < $count; $i++) {
        $p = trim((string)$patients[$i]);
        $t = trim((string)$procedures[$i]);
        $d = trim((string)$dpjps[$i]);
        $r = trim((string)$roles[$i]);
        $l = trim((string)$levels[$i]);
        if ($p === '' && $t === '' && $d === '') {
            continue;
        }
        if ($p === '' || $t === '' || $d === '' || $r === '' || $l === '') {
            $_SESSION['flash_errors'][] = 'Setiap riwayat operasi wajib mengisi: Nama Pasien, Tindakan Operasi, DPJP, Peran, Tingkat.';
            header('Location: pengajuan_jabatan.php');
            exit;
        }

        if (!in_array($r, ['assistant', 'dpjp'], true) || !in_array($l, ['minor', 'major'], true)) {
            $_SESSION['flash_errors'][] = 'Peran/Tingkat operasi tidak valid.';
            header('Location: pengajuan_jabatan.php');
            exit;
        }

        // Transition-specific validation based on role/level
        if ($fromPosition === 'paramedic') {
            // Paramedic -> Co. Asst : Asisten operasi Minor atau Mayor
            if ($r !== 'assistant') {
                $_SESSION['flash_errors'][] = 'Untuk kenaikan Paramedic → Co. Asst, peran operasi harus sebagai Asisten.';
                header('Location: pengajuan_jabatan.php');
                exit;
            }
        }

        if ($fromPosition === 'co_asst') {
            // Co. Asst -> Dokter Umum : DPJP Minor ATAU Asisten Mayor
            $ok = ($r === 'dpjp' && $l === 'minor') || ($r === 'assistant' && $l === 'major');
            if (!$ok) {
                $_SESSION['flash_errors'][] = 'Untuk kenaikan Co. Asst → Dokter Umum, setiap entri harus DPJP (Minor) atau Asisten (Mayor).';
                header('Location: pengajuan_jabatan.php');
                exit;
            }
        }

        $ops[] = ['patient_name' => $p, 'procedure_name' => $t, 'dpjp' => $d, 'operation_role' => $r, 'operation_level' => $l];
    }

    $requiredOps = max(0, (int)($minOps ?? 0));
    if ($requiredOps > 0 && count($ops) < $requiredOps) {
        $_SESSION['flash_errors'][] = "Minimal riwayat operasi: {$requiredOps} entri.";
        header('Location: pengajuan_jabatan.php');
        exit;
    }
}

// Validation: case report (co_asst -> dokter umum)
$caseTitle = null;
$caseSubject = null;
if ($fromPosition === 'co_asst') {
    $caseTitle = trim((string)($_POST['case_title'] ?? ''));
    $caseSubject = trim((string)($_POST['case_subject'] ?? ''));
    if ($caseTitle === '' || $caseSubject === '') {
        $_SESSION['flash_errors'][] = 'Laporan kasus wajib diisi.';
        header('Location: pengajuan_jabatan.php');
        exit;
    }
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO position_promotion_requests
            (user_id, from_position, to_position,
             requirement_notes_snapshot, min_days_since_join_snapshot, min_operations_snapshot,
             join_date_snapshot, batch_snapshot,
             duty_note_snapshot,
             case_title, case_subject)
        VALUES
            (?, ?, ?,
             ?, ?, ?,
             ?, ?,
             ?,
             ?, ?)
    ");

    $dutyNoteSnapshot = ($fromPosition === 'trainee') ? $notes : null;

    $stmt->execute([
        $userId,
        $fromPosition,
        $toPosition,
        ($notes !== '' ? $notes : null),
        $minDays,
        $minOps,
        $joinDate ? $joinDate->format('Y-m-d') : null,
        ((int)($userDb['batch'] ?? 0)) ?: null,
        $dutyNoteSnapshot,
        $caseTitle !== '' ? $caseTitle : null,
        $caseSubject !== '' ? $caseSubject : null,
    ]);

    $requestId = (int)$pdo->lastInsertId();

    if ($ops) {
        $stmtOp = $pdo->prepare("
            INSERT INTO position_promotion_request_operations
                (request_id, sort_order, patient_name, procedure_name, dpjp, operation_role, operation_level)
            VALUES
                (?, ?, ?, ?, ?, ?, ?)
        ");
        $order = 1;
        foreach ($ops as $op) {
            $stmtOp->execute([
                $requestId,
                $order++,
                $op['patient_name'],
                $op['procedure_name'],
                $op['dpjp'],
                $op['operation_role'] ?? null,
                $op['operation_level'] ?? null,
            ]);
        }
    }

    $pdo->commit();
    $_SESSION['flash_messages'][] = 'Pengajuan berhasil dibuat dan menunggu verifikasi manager.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_errors'][] = 'Gagal membuat pengajuan: ' . $e->getMessage();
}

header('Location: pengajuan_jabatan.php');
exit;
