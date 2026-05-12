<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/general_affair_cooperation_helper.php';

ems_require_general_affair_manager_access('/dashboard/index.php');

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
$effectiveUnit = ems_effective_unit($pdo, $user);
$action = trim((string)($_POST['action'] ?? ''));

function gaCooperationRedirect(string $fallback = 'general_affair_kerjasama.php'): void
{
    $redirectTo = trim((string)($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '' || strpos($redirectTo, '://') !== false || str_starts_with($redirectTo, '//')) {
        $redirectTo = $fallback;
    }

    header('Location: ' . $redirectTo);
    exit;
}

function gaCooperationValidatePayload(PDO $pdo, string $unitCode, int $currentId = 0): array
{
    $institutionName = trim((string)($_POST['institution_name'] ?? ''));
    $periodType = trim((string)($_POST['period_type'] ?? 'daily'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $claimScope = trim((string)($_POST['claim_scope'] ?? 'per_person'));
    $medicineQtys = gaCooperationNormalizeMedicineQtys((array)$_POST);
    $members = gaCooperationNormalizeMembersInput((array)($_POST['members'] ?? []));

    if ($institutionName === '') {
        throw new InvalidArgumentException('Nama instansi wajib diisi.');
    }

    if (!isset(gaCooperationPeriodOptions()[$periodType])) {
        throw new InvalidArgumentException('Periode gratis tidak valid.');
    }

    if (!isset(gaCooperationClaimScopeOptions()[$claimScope])) {
        throw new InvalidArgumentException('Mode paket gratis tidak valid.');
    }

    if (!gaCooperationHasConfiguredMedicines($medicineQtys)) {
        throw new InvalidArgumentException('Isi minimal satu jumlah obat gratis.');
    }

    if ($members === []) {
        throw new InvalidArgumentException('Isi minimal satu anggota kerja sama.');
    }

    foreach ($members as $member) {
        $stmtConflict = $pdo->prepare("
            SELECT gc.institution_name
            FROM general_affair_cooperation_members gcm
            INNER JOIN general_affair_cooperations gc
                ON gc.id = gcm.cooperation_id
            WHERE gcm.citizen_id = :citizen_id
              AND gcm.is_active = 1
              AND gc.is_active = 1
              AND gc.unit_code = :unit_code
              AND gc.id <> :current_id
            LIMIT 1
        ");
        $stmtConflict->execute([
            ':citizen_id' => $member['citizen_id'],
            ':unit_code' => $unitCode,
            ':current_id' => $currentId,
        ]);
        $conflictInstitution = $stmtConflict->fetchColumn();

        if ($conflictInstitution) {
            throw new InvalidArgumentException('Citizen ID ' . $member['citizen_id'] . ' sudah terdaftar pada instansi aktif ' . $conflictInstitution . '.');
        }
    }

    return [
        'institution_name' => $institutionName,
        'period_type' => $periodType,
        'claim_scope' => $claimScope,
        'notes' => gaCooperationComposeNotesMeta($notes, $claimScope, $medicineQtys),
        'medicine_qtys' => $medicineQtys,
        'members' => $members,
    ];
}

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Session tidak valid.';
    gaCooperationRedirect();
}

if (!gaCooperationTablesReady($pdo)) {
    $_SESSION['flash_errors'][] = 'Tabel kerja sama instansi belum tersedia. Jalankan SQL modul terlebih dahulu.';
    gaCooperationRedirect();
}

try {
    if ($action === 'create_cooperation') {
        $payload = gaCooperationValidatePayload($pdo, $effectiveUnit);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO general_affair_cooperations
                (unit_code, institution_name, period_type, notes, is_active, created_by, updated_by)
            VALUES
                (?, ?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([
            $effectiveUnit,
            $payload['institution_name'],
            $payload['period_type'],
            $payload['notes'],
            $userId,
            $userId,
        ]);

        $cooperationId = (int)$pdo->lastInsertId();

        $stmtMember = $pdo->prepare("
            INSERT INTO general_affair_cooperation_members
                (cooperation_id, citizen_id, member_name, is_active)
            VALUES
                (?, ?, ?, 1)
        ");
        foreach ($payload['members'] as $member) {
            $stmtMember->execute([
                $cooperationId,
                $member['citizen_id'],
                $member['member_name'],
            ]);
        }

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Kerjasama instansi berhasil disimpan.';
        gaCooperationRedirect();
    }

    if ($action === 'update_cooperation') {
        $cooperationId = (int)($_POST['cooperation_id'] ?? 0);
        if ($cooperationId <= 0) {
            throw new InvalidArgumentException('Kerjasama tidak valid.');
        }

        $payload = gaCooperationValidatePayload($pdo, $effectiveUnit, $cooperationId);

        $stmtCheck = $pdo->prepare("
            SELECT id
            FROM general_affair_cooperations
            WHERE id = ?
              AND unit_code = ?
            LIMIT 1
        ");
        $stmtCheck->execute([$cooperationId, $effectiveUnit]);
        if (!$stmtCheck->fetchColumn()) {
            throw new InvalidArgumentException('Kerjasama tidak ditemukan.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE general_affair_cooperations
            SET institution_name = ?, period_type = ?, notes = ?, updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $payload['institution_name'],
            $payload['period_type'],
            $payload['notes'],
            $userId,
            $cooperationId,
        ]);

        $pdo->prepare("DELETE FROM general_affair_cooperation_packages WHERE cooperation_id = ?")->execute([$cooperationId]);
        $pdo->prepare("DELETE FROM general_affair_cooperation_members WHERE cooperation_id = ?")->execute([$cooperationId]);

        $stmtMember = $pdo->prepare("
            INSERT INTO general_affair_cooperation_members
                (cooperation_id, citizen_id, member_name, is_active)
            VALUES
                (?, ?, ?, 1)
        ");
        foreach ($payload['members'] as $member) {
            $stmtMember->execute([
                $cooperationId,
                $member['citizen_id'],
                $member['member_name'],
            ]);
        }

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Kerjasama instansi berhasil diperbarui.';
        gaCooperationRedirect();
    }

    if ($action === 'toggle_cooperation_status') {
        $cooperationId = (int)($_POST['cooperation_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

        if ($cooperationId <= 0) {
            throw new InvalidArgumentException('Kerjasama tidak valid.');
        }

        $stmt = $pdo->prepare("
            UPDATE general_affair_cooperations
            SET is_active = ?, updated_by = ?
            WHERE id = ?
              AND unit_code = ?
        ");
        $stmt->execute([$isActive, $userId, $cooperationId, $effectiveUnit]);

        if ($stmt->rowCount() <= 0) {
            throw new InvalidArgumentException('Kerjasama tidak ditemukan atau status tidak berubah.');
        }

        $_SESSION['flash_messages'][] = $isActive === 1
            ? 'Kerjasama instansi berhasil diaktifkan.'
            : 'Kerjasama instansi berhasil dinonaktifkan.';
        gaCooperationRedirect();
    }

    if ($action === 'delete_cooperation') {
        $cooperationId = (int)($_POST['cooperation_id'] ?? 0);
        if ($cooperationId <= 0) {
            throw new InvalidArgumentException('Kerjasama tidak valid.');
        }

        $stmt = $pdo->prepare("
            DELETE FROM general_affair_cooperations
            WHERE id = ?
              AND unit_code = ?
        ");
        $stmt->execute([$cooperationId, $effectiveUnit]);

        if ($stmt->rowCount() <= 0) {
            throw new InvalidArgumentException('Kerjasama tidak ditemukan.');
        }

        $_SESSION['flash_messages'][] = 'Kerjasama instansi berhasil dihapus.';
        gaCooperationRedirect();
    }

    throw new InvalidArgumentException('Aksi tidak dikenali.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['flash_errors'][] = 'Gagal memproses kerjasama: ' . $e->getMessage();
    $_SESSION['flash_old'] = [
        'institution_name' => $_POST['institution_name'] ?? '',
        'period_type' => $_POST['period_type'] ?? 'daily',
        'claim_scope' => $_POST['claim_scope'] ?? 'per_person',
        'notes' => $_POST['notes'] ?? '',
        'bandage_qty' => $_POST['bandage_qty'] ?? '',
        'ifaks_qty' => $_POST['ifaks_qty'] ?? '',
        'painkiller_qty' => $_POST['painkiller_qty'] ?? '',
        'members' => (array)($_POST['members'] ?? []),
    ];
    gaCooperationRedirect();
}
