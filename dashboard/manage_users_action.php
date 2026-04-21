<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$sessionUser = $_SESSION['user_rh'] ?? [];
$sessionRole = $sessionUser['role'] ?? '';
$effectiveUnit = ems_effective_unit($pdo, $sessionUser);

if (ems_is_staff_role($sessionRole)) {
    $_SESSION['flash_errors'][] = 'Akses ditolak.';
    header('Location: manage_users.php');
    exit;
}

$action = $_POST['action'] ?? '';

function manageUsersActionHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM user_rh LIKE ?");
    $stmt->execute([$column]);
    $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

function manageUsersActionEnsureUpdateHistoryTable(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS account_update_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            target_user_id INT NOT NULL,
            target_name VARCHAR(100) NOT NULL,
            editor_user_id INT DEFAULT NULL,
            editor_name VARCHAR(100) DEFAULT NULL,
            editor_role VARCHAR(100) DEFAULT NULL,
            action_type VARCHAR(50) NOT NULL DEFAULT 'edit',
            summary VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_account_update_logs_target (target_user_id),
            KEY idx_account_update_logs_created_at (created_at),
            KEY idx_account_update_logs_editor (editor_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $initialized = true;
}

function manageUsersActionWriteHistory(
    PDO $pdo,
    array $sessionUser,
    int $targetUserId,
    string $targetName,
    string $actionType,
    ?string $summary = null
): void {
    manageUsersActionEnsureUpdateHistoryTable($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO account_update_logs
            (target_user_id, target_name, editor_user_id, editor_name, editor_role, action_type, summary, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $targetUserId,
        $targetName,
        (int)($sessionUser['id'] ?? 0) ?: null,
        trim((string)($sessionUser['full_name'] ?? '')) ?: 'Sistem',
        ems_normalize_role($sessionUser['role'] ?? ''),
        $actionType,
        $summary !== null && trim($summary) !== '' ? trim($summary) : null,
    ]);
}

$hasDivisionColumn = manageUsersActionHasColumn($pdo, 'division');
$hasUnitCodeColumn = manageUsersActionHasColumn($pdo, 'unit_code');
$hasCanViewAllUnitsColumn = manageUsersActionHasColumn($pdo, 'can_view_all_units');
$hasCitizenIdColumn = manageUsersActionHasColumn($pdo, 'citizen_id');
$hasNoHpIcColumn = manageUsersActionHasColumn($pdo, 'no_hp_ic');
$hasJenisKelaminColumn = manageUsersActionHasColumn($pdo, 'jenis_kelamin');
$hasTanggalMasukColumn = manageUsersActionHasColumn($pdo, 'tanggal_masuk');
$promotionDateColumns = [
    'tanggal_naik_paramedic',
    'tanggal_naik_co_asst',
    'tanggal_naik_dokter',
    'tanggal_naik_dokter_spesialis',
    'tanggal_join_manager',
];
$availablePromotionDateColumns = array_values(array_filter(
    $promotionDateColumns,
    static fn(string $column): bool => manageUsersActionHasColumn($pdo, $column)
));

function manageUsersActionNormalizeDate(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return null;
    }

    return $value;
}

function manageUsersActionTargetExists(PDO $pdo, int $userId, bool $hasUnitCodeColumn, string $effectiveUnit): bool
{
    if ($userId <= 0) {
        return false;
    }

    $sql = "SELECT COUNT(*) FROM user_rh WHERE id = ?";
    $params = [$userId];

    if ($hasUnitCodeColumn) {
        $sql .= " AND COALESCE(unit_code, 'roxwood') = ?";
        $params[] = $effectiveUnit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn() > 0;
}

function manageUsersActionNormalizeProtectedName(?string $name): string
{
    $value = strtolower(trim((string)$name));
    $value = preg_replace('/\s+/', ' ', $value) ?: '';
    return $value;
}

function manageUsersActionIsProtectedName(?string $name): bool
{
    return in_array(
        manageUsersActionNormalizeProtectedName($name),
        ['programmer alta', 'programmer roxwood'],
        true
    );
}

function manageUsersActionGetTargetUser(PDO $pdo, int $userId, bool $hasUnitCodeColumn, string $effectiveUnit): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $sql = "SELECT id, full_name FROM user_rh WHERE id = ?";
    $params = [$userId];

    if ($hasUnitCodeColumn) {
        $sql .= " AND COALESCE(unit_code, 'roxwood') = ?";
        $params[] = $effectiveUnit;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function manageUsersActionCanSelfManageProtectedUser(array $sessionUser, array $targetUser): bool
{
    if (!manageUsersActionIsProtectedName($targetUser['full_name'] ?? '')) {
        return true;
    }

    return (int)($sessionUser['id'] ?? 0) > 0
        && (int)($sessionUser['id'] ?? 0) === (int)($targetUser['id'] ?? 0);
}

/* =========================================================
   TAMBAH USER BARU
   ========================================================= */
/* =========================================================
   TAMBAH USER BARU (AUTO KODE MEDIS)
   ========================================================= */
if ($action === 'add_user') {

    $name     = trim($_POST['full_name'] ?? '');
    $position = ems_normalize_position($_POST['position'] ?? '');
    $role     = ems_role_label($_POST['role'] ?? '');
    $division = ems_normalize_division($_POST['division'] ?? '');
    $unitCode = ems_normalize_unit_code($_POST['unit_code'] ?? 'roxwood');
    $canViewAllUnits = !empty($_POST['can_view_all_units']) && ems_is_director_role($sessionRole) ? 1 : null;
    $batch    = (int)($_POST['batch'] ?? 0);

    if ($name === '' || $position === '' || $role === '' || $division === '') {
        $_SESSION['flash_errors'][] = 'Semua field wajib diisi.';
        header('Location: manage_users.php');
        exit;
    }

    if (!ems_is_valid_role($role)) {
        $_SESSION['flash_errors'][] = 'Role tidak valid.';
        header('Location: manage_users.php');
        exit;
    }

    if (!ems_is_valid_division($division)) {
        $_SESSION['flash_errors'][] = 'Division tidak valid.';
        header('Location: manage_users.php');
        exit;
    }

    if (!ems_is_valid_position($position)) {
        $_SESSION['flash_errors'][] = 'Jabatan tidak valid.';
        header('Location: manage_users.php');
        exit;
    }

    // ===============================
    // 🔐 PIN DEFAULT
    // ===============================
    $defaultPin = '0000';

    // ===============================
    // 1️⃣ INSERT USER TANPA KODE MEDIS DULU
    // (karena butuh ID user)
    // ===============================
    $columns = ['full_name', 'position', 'role'];
    $placeholders = ['?', '?', '?'];
    $params = [$name, $position, $role];

    if ($hasDivisionColumn) {
        $columns[] = 'division';
        $placeholders[] = '?';
        $params[] = $division;
    }

    if ($hasUnitCodeColumn) {
        $columns[] = 'unit_code';
        $placeholders[] = '?';
        $params[] = $unitCode;
    }

    if ($hasCanViewAllUnitsColumn) {
        $columns[] = 'can_view_all_units';
        $placeholders[] = '?';
        $params[] = $canViewAllUnits;
    }

    $columns = array_merge($columns, ['pin', 'batch', 'is_active', 'is_verified']);
    $placeholders = array_merge($placeholders, ['?', '?', '1', '1']);
    $params[] = password_hash($defaultPin, PASSWORD_BCRYPT);
    $params[] = $batch > 0 ? $batch : null;

    $stmt = $pdo->prepare("
        INSERT INTO user_rh
            (" . implode(', ', $columns) . ")
        VALUES
            (" . implode(', ', $placeholders) . ")
    ");

    $stmt->execute($params);

    $newUserId = (int)$pdo->lastInsertId();

    // ===============================
    // 2️⃣ GENERATE KODE MEDIS JIKA ADA BATCH
    // (SAMA PERSIS SEPERTI EDIT)
    // ===============================
    if ($batch > 0) {
        try {
            $generatedKode = generateKodeMedis($newUserId, $name, $batch);

            $pdo->prepare("
                UPDATE user_rh
                SET kode_nomor_induk_rs = ?
                WHERE id = ?
            ")->execute([$generatedKode, $newUserId]);
        } catch (Exception $e) {
            $_SESSION['flash_warnings'][] =
                'User dibuat, tetapi kode medis gagal dibuat: ' . $e->getMessage();
        }
    }

    manageUsersActionWriteHistory(
        $pdo,
        $sessionUser,
        $newUserId,
        $name,
        'add_user',
        'Akun baru dibuat.'
    );

    $_SESSION['flash_messages'][] =
        'User baru berhasil ditambahkan. PIN awal: 0000';

    header('Location: manage_users.php');
    exit;
}

/**
 * Generate Kode Medis / Nomor Induk RS
 *
 * FORMAT:
 * RH{BATCH}-{ID(2 digit)}{2 huruf depan nama depan + 2 huruf depan nama belakang}
 *
 * Contoh:
 * Nama   : Michael Moore
 * Batch  : 3 (C)
 * UserID : 1
 * Hasil  : RHC-0113091315
 */
function generateKodeMedis(int $userId, string $fullName, int $batch): string
{
    // ===============================
    // 1. Batch → Huruf (1=A, 2=B, ...)
    // ===============================
    if ($batch < 1 || $batch > 26) {
        throw new Exception('Batch tidak valid');
    }

    $batchCode = chr(64 + $batch); // 3 => C

    // ===============================
    // 2. ID User → 2 digit (01, 02, 10, ...)
    // ===============================
    $idPart = str_pad((string)$userId, 2, '0', STR_PAD_LEFT);

    // ===============================
    // 3. Ambil 2 huruf depan nama depan
    //    + 2 huruf depan nama belakang
    // ===============================
    $parts = preg_split('/\s+/', strtoupper(trim($fullName)));

    $firstName = $parts[0] ?? '';
    $lastName  = $parts[count($parts) - 1] ?? '';

    $letters = substr($firstName, 0, 2) . substr($lastName, 0, 2);

    // ===============================
    // 4. Konversi huruf → angka alfabet
    //    A=01, B=02, ..., Z=26
    // ===============================
    $numberPart = '';

    foreach (str_split($letters) as $char) {
        if ($char >= 'A' && $char <= 'Z') {
            $numberPart .= str_pad((string)(ord($char) - 64), 2, '0', STR_PAD_LEFT);
        }
    }

    // ===============================
    // 5. FINAL FORMAT
    // ===============================
    return 'RH' . $batchCode . '-' . $idPart . $numberPart;
}

if ($action === 'delete_kode_medis') {

    if (!ems_is_director_role($sessionRole)) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
        exit;
    }

    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => 'User tidak valid']);
        exit;
    }

    if (!manageUsersActionTargetExists($pdo, $userId, $hasUnitCodeColumn, $effectiveUnit)) {
        echo json_encode(['success' => false, 'message' => 'User di luar unit aktif tidak dapat diakses']);
        exit;
    }

    $targetUser = manageUsersActionGetTargetUser($pdo, $userId, $hasUnitCodeColumn, $effectiveUnit);
    if (!$targetUser) {
        echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
        exit;
    }

    if (!manageUsersActionCanSelfManageProtectedUser($sessionUser, $targetUser)) {
        echo json_encode(['success' => false, 'message' => 'Akun ini hanya bisa diubah oleh pemilik akun itu sendiri']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE user_rh
        SET kode_nomor_induk_rs = NULL
        WHERE id = ?
        " . ($hasUnitCodeColumn ? " AND COALESCE(unit_code, 'roxwood') = ?" : "") . "
    ");
    $params = [$userId];
    if ($hasUnitCodeColumn) {
        $params[] = $effectiveUnit;
    }
    $stmt->execute($params);

    echo json_encode(['success' => true]);
    exit;
}

/* =========================================================
   PROSES RESIGN (HARUS PALING ATAS)
   ========================================================= */
if ($action === 'resign') {

    $userId = (int)($_POST['user_id'] ?? 0);
    $reason = trim($_POST['resign_reason'] ?? '');

    if ($userId <= 0 || $reason === '') {
        $_SESSION['flash_errors'][] = 'Alasan resign wajib diisi.';
        header('Location: manage_users.php');
        exit;
    }

    if (!manageUsersActionTargetExists($pdo, $userId, $hasUnitCodeColumn, $effectiveUnit)) {
        $_SESSION['flash_errors'][] = 'User di luar unit aktif tidak dapat diakses.';
        header('Location: manage_users.php');
        exit;
    }

    $targetUser = manageUsersActionGetTargetUser($pdo, $userId, $hasUnitCodeColumn, $effectiveUnit);
    if (!$targetUser) {
        $_SESSION['flash_errors'][] = 'User tidak ditemukan.';
        header('Location: manage_users.php');
        exit;
    }

    if (!manageUsersActionCanSelfManageProtectedUser($sessionUser, $targetUser)) {
        $_SESSION['flash_errors'][] = 'User Programmer Alta dan Programmer Roxwood hanya bisa di-resign oleh pemilik akun itu sendiri.';
        header('Location: manage_users.php');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE user_rh
        SET 
            is_active = 0,
            resign_reason = ?,
            resigned_by = ?,
            resigned_at = NOW()
        WHERE id = ?
        " . ($hasUnitCodeColumn ? " AND COALESCE(unit_code, 'roxwood') = ?" : "") . "
    ");
    $params = [
        $reason,
        $sessionUser['id'],
        $userId
    ];
    if ($hasUnitCodeColumn) {
        $params[] = $effectiveUnit;
    }
    $stmt->execute($params);

    manageUsersActionWriteHistory(
        $pdo,
        $sessionUser,
        $userId,
        $targetUser['full_name'] ?? 'User',
        'resign',
        'Akun dinonaktifkan.'
    );

    $_SESSION['flash_messages'][] = 'User berhasil dinonaktifkan.';
    header('Location: manage_users.php');
    exit;
}

/* =========================================================
   RE-ACTIVATE USER (KEMBALI BEKERJA)
   ========================================================= */
if ($action === 'reactivate') {

    $userId = (int)($_POST['user_id'] ?? 0);
    $note   = trim($_POST['reactivate_note'] ?? '');

    if ($userId <= 0) {
        $_SESSION['flash_errors'][] = 'User tidak valid.';
        header('Location: manage_users.php');
        exit;
    }

    if (!manageUsersActionTargetExists($pdo, $userId, $hasUnitCodeColumn, $effectiveUnit)) {
        $_SESSION['flash_errors'][] = 'User di luar unit aktif tidak dapat diakses.';
        header('Location: manage_users.php');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE user_rh  
        SET
            is_active = 1,
            reactivated_at = NOW(),
            reactivated_by = ?,
            reactivated_note = ?
        WHERE id = ?
        " . ($hasUnitCodeColumn ? " AND COALESCE(unit_code, 'roxwood') = ?" : "") . "
    ");
    $params = [
        $sessionUser['id'],
        $note,
        $userId
    ];
    if ($hasUnitCodeColumn) {
        $params[] = $effectiveUnit;
    }
    $stmt->execute($params);

    manageUsersActionWriteHistory(
        $pdo,
        $sessionUser,
        $userId,
        $targetUser['full_name'] ?? 'User',
        'reactivate',
        $note !== '' ? 'Akun diaktifkan kembali. Catatan: ' . $note : 'Akun diaktifkan kembali.'
    );

    $_SESSION['flash_messages'][] = 'User berhasil diaktifkan kembali.';
    header('Location: manage_users.php');
    exit;
}

/* =========================================================
   DELETE USER (PERMANEN)
   ========================================================= */
if ($action === 'delete') {
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        $_SESSION['flash_errors'][] = 'User tidak valid.';
        header('Location: manage_users.php');
        exit;
    }

    if (!manageUsersActionTargetExists($pdo, $userId, $hasUnitCodeColumn, $effectiveUnit)) {
        $_SESSION['flash_errors'][] = 'User di luar unit aktif tidak dapat diakses.';
        header('Location: manage_users.php');
        exit;
    }

    $targetUser = manageUsersActionGetTargetUser($pdo, $userId, $hasUnitCodeColumn, $effectiveUnit);
    if (!$targetUser) {
        $_SESSION['flash_errors'][] = 'User tidak ditemukan.';
        header('Location: manage_users.php');
        exit;
    }

    $isProtectedUser = manageUsersActionIsProtectedName($targetUser['full_name'] ?? '');
    $isSelfDelete = $userId === (int)($sessionUser['id'] ?? 0);

    if ($isProtectedUser && !manageUsersActionCanSelfManageProtectedUser($sessionUser, $targetUser)) {
        $_SESSION['flash_errors'][] = 'User Programmer Alta dan Programmer Roxwood hanya bisa dihapus oleh pemilik akun itu sendiri.';
        header('Location: manage_users.php');
        exit;
    }

    if (!$isProtectedUser && !ems_is_director_role($sessionRole)) {
        $_SESSION['flash_errors'][] = 'Hanya Director dan Vice Director yang dapat menghapus user.';
        header('Location: manage_users.php');
        exit;
    }

    if (!$isProtectedUser && $isSelfDelete) {
        $_SESSION['flash_errors'][] = 'Anda tidak dapat menghapus akun sendiri.';
        header('Location: manage_users.php');
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM user_rh WHERE id = ?" . ($hasUnitCodeColumn ? " AND COALESCE(unit_code, 'roxwood') = ?" : ""));
    $params = [$userId];
    if ($hasUnitCodeColumn) {
        $params[] = $effectiveUnit;
    }
    $stmt->execute($params);

    manageUsersActionWriteHistory(
        $pdo,
        $sessionUser,
        $userId,
        $targetUser['full_name'] ?? 'User',
        'delete',
        'Akun dihapus permanen.'
    );

    $_SESSION['flash_messages'][] = 'User berhasil dihapus permanen.';
    header('Location: manage_users.php');
    exit;
}

/* =========================================================
   PROSES EDIT USER (FIX FINAL)
   ========================================================= */
$userId   = (int)($_POST['user_id'] ?? 0);
$name     = trim($_POST['full_name'] ?? '');
$position = ems_normalize_position($_POST['position'] ?? '');
$newRole  = ems_role_label($_POST['role'] ?? '');
$division = ems_normalize_division($_POST['division'] ?? '');
$unitCode = ems_normalize_unit_code($_POST['unit_code'] ?? 'roxwood');
$canViewAllUnits = !empty($_POST['can_view_all_units']) && ems_is_director_role($sessionRole) ? 1 : null;
$newPin   = $_POST['new_pin'] ?? '';
$batch    = (int)($_POST['batch'] ?? 0);
$citizenId = strtoupper(trim((string)($_POST['citizen_id'] ?? '')));
$noHpIc = trim((string)($_POST['no_hp_ic'] ?? ''));
$jenisKelamin = trim((string)($_POST['jenis_kelamin'] ?? ''));
$tanggalMasukRaw = $_POST['tanggal_masuk'] ?? '';
$tanggalMasuk = manageUsersActionNormalizeDate($tanggalMasukRaw);
$promotionDates = [];
foreach ($availablePromotionDateColumns as $promotionColumn) {
    $promotionDates[$promotionColumn] = manageUsersActionNormalizeDate($_POST[$promotionColumn] ?? '');
}

if (!ems_is_valid_role($newRole)) {
    $_SESSION['flash_errors'][] = 'Role tidak valid.';
    header('Location: manage_users.php');
    exit;
}

if ($userId <= 0 || $name === '' || $position === '' || $newRole === '' || $division === '') {
    $_SESSION['flash_errors'][] = 'Data tidak valid.';
    header('Location: manage_users.php');
    exit;
}

if (!manageUsersActionTargetExists($pdo, $userId, $hasUnitCodeColumn, $effectiveUnit)) {
    $_SESSION['flash_errors'][] = 'User di luar unit aktif tidak dapat diakses.';
    header('Location: manage_users.php');
    exit;
}

$targetUser = manageUsersActionGetTargetUser($pdo, $userId, $hasUnitCodeColumn, $effectiveUnit);
if (!$targetUser) {
    $_SESSION['flash_errors'][] = 'User tidak ditemukan.';
    header('Location: manage_users.php');
    exit;
}

$isProtectedUser = manageUsersActionIsProtectedName($targetUser['full_name'] ?? '');
if ($isProtectedUser && !manageUsersActionCanSelfManageProtectedUser($sessionUser, $targetUser)) {
    $_SESSION['flash_errors'][] = 'User Programmer Alta dan Programmer Roxwood hanya bisa di-edit oleh pemilik akun itu sendiri.';
    header('Location: manage_users.php');
    exit;
}

if ($isProtectedUser && manageUsersActionNormalizeProtectedName($name) !== manageUsersActionNormalizeProtectedName($targetUser['full_name'] ?? '')) {
    $_SESSION['flash_errors'][] = 'Nama user Programmer Alta dan Programmer Roxwood dilindungi dan tidak dapat diubah.';
    header('Location: manage_users.php');
    exit;
}

if (!ems_is_valid_position($position)) {
    $_SESSION['flash_errors'][] = 'Jabatan tidak valid.';
    header('Location: manage_users.php');
    exit;
}

if (!ems_is_valid_division($division)) {
    $_SESSION['flash_errors'][] = 'Division tidak valid.';
    header('Location: manage_users.php');
    exit;
}

if ($hasCitizenIdColumn && $citizenId !== '') {
    if (!preg_match('/^[A-Z0-9]+$/', $citizenId)) {
        $_SESSION['flash_errors'][] = 'Citizen ID hanya boleh huruf besar dan angka tanpa spasi.';
        header('Location: manage_users.php');
        exit;
    }

    if (!preg_match('/[A-Z]/', $citizenId)) {
        $_SESSION['flash_errors'][] = 'Citizen ID harus mengandung minimal 1 huruf.';
        header('Location: manage_users.php');
        exit;
    }

    if (strlen($citizenId) < 6) {
        $_SESSION['flash_errors'][] = 'Citizen ID minimal 6 karakter.';
        header('Location: manage_users.php');
        exit;
    }

    $cleanedName = strtoupper(preg_replace('/\s+/', '', $name));
    if ($cleanedName !== '' && $citizenId === $cleanedName) {
        $_SESSION['flash_errors'][] = 'Citizen ID tidak boleh sama dengan nama medis.';
        header('Location: manage_users.php');
        exit;
    }
}

if ($hasJenisKelaminColumn && $jenisKelamin !== '' && !in_array($jenisKelamin, ['Laki-laki', 'Perempuan'], true)) {
    $_SESSION['flash_errors'][] = 'Jenis kelamin tidak valid.';
    header('Location: manage_users.php');
    exit;
}

if ($hasTanggalMasukColumn && $tanggalMasukRaw !== '' && $tanggalMasuk === null) {
    $_SESSION['flash_errors'][] = 'Tanggal masuk tidak valid.';
    header('Location: manage_users.php');
    exit;
}

foreach ($promotionDates as $promotionColumn => $promotionDateValue) {
    if (trim((string)($_POST[$promotionColumn] ?? '')) !== '' && $promotionDateValue === null) {
        $_SESSION['flash_errors'][] = 'Format tanggal kenaikan tidak valid.';
        header('Location: manage_users.php');
        exit;
    }
}

/* ===============================
   Ambil data lama user
   =============================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM user_rh
    WHERE id = ?" . ($hasUnitCodeColumn ? " AND COALESCE(unit_code, 'roxwood') = ?" : "") . "
    LIMIT 1
");
$selectParams = [$userId];
if ($hasUnitCodeColumn) {
    $selectParams[] = $effectiveUnit;
}
$stmt->execute($selectParams);
$currentUserData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$currentKode = $currentUserData['kode_nomor_induk_rs'] ?? null;

/* ===============================
   Bangun SQL dasar DULU
   =============================== */
$sql = "UPDATE user_rh SET full_name = ?, position = ?, role = ?";
$params = [$name, $position, $newRole];

if ($hasDivisionColumn) {
    $sql .= ", division = ?";
    $params[] = $division;
}

if ($hasUnitCodeColumn) {
    $sql .= ", unit_code = ?";
    $params[] = $unitCode;
}

if ($hasCanViewAllUnitsColumn) {
    $sql .= ", can_view_all_units = ?";
    $params[] = $canViewAllUnits;
}

if ($hasCitizenIdColumn) {
    $sql .= ", citizen_id = ?";
    $params[] = $citizenId !== '' ? $citizenId : null;
}

if ($hasNoHpIcColumn) {
    $sql .= ", no_hp_ic = ?";
    $params[] = $noHpIc !== '' ? $noHpIc : null;
}

if ($hasJenisKelaminColumn) {
    $sql .= ", jenis_kelamin = ?";
    $params[] = $jenisKelamin !== '' ? $jenisKelamin : null;
}

if ($hasTanggalMasukColumn) {
    $sql .= ", tanggal_masuk = ?";
    $params[] = $tanggalMasuk;
}

foreach ($availablePromotionDateColumns as $promotionColumn) {
    $sql .= ", {$promotionColumn} = ?";
    $params[] = $promotionDates[$promotionColumn];
}

/* ===============================
   Update batch SELALU
   =============================== */
$sql .= ", batch = ?";
$params[] = $batch > 0 ? $batch : null;

/* ===============================
   Generate kode medis JIKA NULL
   =============================== */
if (empty($currentKode) && $batch > 0) {
    try {
        $generatedKode = generateKodeMedis($userId, $name, $batch);
        $sql .= ", kode_nomor_induk_rs = ?";
        $params[] = $generatedKode;
    } catch (Exception $e) {
        $_SESSION['flash_errors'][] = 'Gagal generate kode medis: ' . $e->getMessage();
        header('Location: manage_users.php');
        exit;
    }
}


/* ===============================
   Update PIN (opsional)
   =============================== */
if ($newPin !== '') {
    if (!preg_match('/^\d{4}$/', $newPin)) {
        $_SESSION['flash_errors'][] = 'PIN harus 4 digit angka.';
        header('Location: manage_users.php');
        exit;
    }
    $sql .= ", pin = ?";
    $params[] = password_hash($newPin, PASSWORD_BCRYPT);
}

/* ===============================
   WHERE & EXECUTE
   =============================== */
$sql .= " WHERE id = ?";
$params[] = $userId;
if ($hasUnitCodeColumn) {
    $sql .= " AND COALESCE(unit_code, 'roxwood') = ?";
    $params[] = $effectiveUnit;
}

$pdo->prepare($sql)->execute($params);

$summaryParts = [];
if (($currentUserData['full_name'] ?? '') !== $name) {
    $summaryParts[] = 'nama diubah';
}
if (ems_normalize_position($currentUserData['position'] ?? '') !== $position) {
    $summaryParts[] = 'jabatan diubah';
}
if (($currentUserData['role'] ?? '') !== $newRole) {
    $summaryParts[] = 'role diubah';
}
if ($hasDivisionColumn && (($currentUserData['division'] ?? null) !== $division)) {
    $summaryParts[] = 'division diubah';
}
if ($hasUnitCodeColumn && ems_normalize_unit_code($currentUserData['unit_code'] ?? 'roxwood') !== $unitCode) {
    $summaryParts[] = 'unit diubah';
}
if ($hasTanggalMasukColumn && (($currentUserData['tanggal_masuk'] ?? null) !== $tanggalMasuk)) {
    $summaryParts[] = 'tanggal masuk diubah';
}
foreach ($availablePromotionDateColumns as $promotionColumn) {
    if (($currentUserData[$promotionColumn] ?? null) !== $promotionDates[$promotionColumn]) {
        $summaryParts[] = str_replace('_', ' ', $promotionColumn) . ' diubah';
    }
}
if ($newPin !== '') {
    $summaryParts[] = 'PIN diperbarui';
}
if ($batch > 0 ? (int)$batch !== (int)($currentUserData['batch'] ?? 0) : !empty($currentUserData['batch'])) {
    $summaryParts[] = 'batch diubah';
}

manageUsersActionWriteHistory(
    $pdo,
    $sessionUser,
    $userId,
    $name,
    'edit',
    !empty($summaryParts) ? implode(', ', $summaryParts) . '.' : 'Data akun diperbarui.'
);

/* ===============================
   FLASH & REDIRECT
   =============================== */
$_SESSION['flash_messages'][] = 'Data user berhasil diperbarui.';
header('Location: manage_users.php');
exit;
