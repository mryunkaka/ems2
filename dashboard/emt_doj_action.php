<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$redirectTo = '/dashboard/emt_doj.php';

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
$userRole = $user['role'] ?? '';
$userDivision = ems_normalize_division($user['division'] ?? '');
$userFullName = trim((string)($user['full_name'] ?? $user['name'] ?? ''));

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    header('Location: ' . $redirectTo);
    exit;
}

if (!ems_column_exists($pdo, 'emt_doj', 'id') || !ems_column_exists($pdo, 'emt_doj_deliveries', 'id')) {
    $_SESSION['flash_errors'][] = 'Tabel EMT DOJ belum tersedia. Jalankan SQL `docs/sql/17_2026-04-06_emt_doj_module.sql` terlebih dahulu.';
    header('Location: ' . $redirectTo);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($action === 'create_emt') {
        if (!ems_is_manager_plus_role($userRole)) {
            throw new RuntimeException('Hanya manager yang dapat menambah data EMT DOJ.');
        }

        $fullName = preg_replace('/\s+/u', ' ', trim((string)($_POST['full_name'] ?? ''))) ?: '';
        $cid = ems_normalize_citizen_id($_POST['cid'] ?? '');
        $targetPatients = (int)($_POST['target_patients'] ?? 0);

        if ($fullName === '') {
            throw new RuntimeException('Nama lengkap EMT DOJ wajib diisi.');
        }

        if (!ems_looks_like_citizen_id($cid)) {
            throw new RuntimeException('CID EMT DOJ tidak valid.');
        }

        if ($targetPatients <= 0) {
            throw new RuntimeException('Jumlah pasien wajib lebih dari 0.');
        }

        $stmtDuplicate = $pdo->prepare("
            SELECT id
            FROM emt_doj
            WHERE cid = ?
            LIMIT 1
        ");
        $stmtDuplicate->execute([$cid]);
        if ($stmtDuplicate->fetchColumn()) {
            throw new RuntimeException('CID tersebut sudah terdaftar pada EMT DOJ.');
        }

        $stmtInsert = $pdo->prepare("
            INSERT INTO emt_doj (full_name, cid, target_patients, created_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmtInsert->execute([$fullName, $cid, $targetPatients, $userId]);

        $_SESSION['flash_messages'][] = 'Data EMT DOJ berhasil ditambahkan.';
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'create_delivery') {
        if ($userDivision !== 'Medis' && !ems_is_manager_plus_role($userRole)) {
            throw new RuntimeException('Hanya division Medis atau manager yang dapat menginput pengantaran pasien.');
        }

        $emtId = (int)($_POST['emt_id'] ?? 0);
        if ($emtId <= 0) {
            throw new RuntimeException('Nama EMT DOJ wajib dipilih.');
        }

        $unitCode = ems_current_user_unit($pdo, $user);

        $pdo->beginTransaction();

        $stmtEmt = $pdo->prepare("
            SELECT id, full_name, target_patients, is_active
            FROM emt_doj
            WHERE id = ?
            FOR UPDATE
        ");
        $stmtEmt->execute([$emtId]);
        $emt = $stmtEmt->fetch(PDO::FETCH_ASSOC);

        if (!$emt) {
            throw new RuntimeException('Data EMT DOJ tidak ditemukan.');
        }

        if ((int)($emt['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Data EMT DOJ ini sudah tidak aktif.');
        }

        $stmtCount = $pdo->prepare("
            SELECT COUNT(*) 
            FROM emt_doj_deliveries
            WHERE emt_id = ?
        ");
        $stmtCount->execute([$emtId]);
        $deliveredCount = (int)$stmtCount->fetchColumn();

        if ($deliveredCount >= (int)$emt['target_patients']) {
            throw new RuntimeException('Target pengantaran pasien untuk EMT DOJ ini sudah terpenuhi.');
        }

        $stmtInsertDelivery = $pdo->prepare("
            INSERT INTO emt_doj_deliveries (emt_id, unit_code, input_by_user_id, input_by_name_snapshot, delivered_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmtInsertDelivery->execute([
            $emtId,
            $unitCode,
            $userId,
            $userFullName !== '' ? $userFullName : 'Medis',
        ]);

        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Riwayat pengantaran pasien berhasil disimpan.';
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
