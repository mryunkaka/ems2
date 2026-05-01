<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json; charset=UTF-8');

function quickSaveRespond(bool $ok, string $message, array $extra = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function quickSaveMark(string $label): void
{
    global $quickSaveStartedAt, $quickSaveMarks;
    $now = microtime(true);
    $lastTime = $quickSaveStartedAt;

    if (!empty($quickSaveMarks)) {
        $lastEntry = end($quickSaveMarks);
        if (is_array($lastEntry) && isset($lastEntry['at'])) {
            $lastTime = (float)$lastEntry['at'];
        }
    }

    $quickSaveMarks[] = [
        'label' => $label,
        'at' => $now,
        'delta_ms' => (int)round(($now - $lastTime) * 1000),
        'elapsed_ms' => (int)round(($now - $quickSaveStartedAt) * 1000),
    ];
}

function quickSaveColumns(PDO $pdo): array
{
    static $columns = null;
    if (is_array($columns)) {
        return $columns;
    }

    $stmt = $pdo->query('SHOW COLUMNS FROM user_rh');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $columns = [];
    foreach ($rows as $row) {
        $field = strtolower(trim((string)($row['Field'] ?? '')));
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    return $columns;
}

function quickSaveValidPin($pin): bool
{
    return is_string($pin) && preg_match('/^\d{4}$/', $pin);
}

function quickSaveAlphaPos($char)
{
    $char = strtoupper($char);
    if ($char < 'A' || $char > 'Z') {
        return null;
    }
    return ord($char) - 64;
}

function quickSaveTwoDigit($num): string
{
    return str_pad((string)$num, 2, '0', STR_PAD_LEFT);
}

$quickSaveStartedAt = microtime(true);
$quickSaveMarks = [];
quickSaveMark('bootstrap');

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$currentPos = $user['position'] ?? '';
$currentRole = $user['role'] ?? '';
$currentBatch = $user['batch'] ?? null;
$batchFromDb = (int)($currentBatch ?? 0);

if ($userId <= 0) {
    quickSaveRespond(false, 'Session tidak valid. Silakan login ulang.', [], 401);
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$citizenId = strtoupper(str_replace(' ', '', trim((string)($_POST['citizen_id'] ?? ''))));
$jenisKelamin = trim((string)($_POST['jenis_kelamin'] ?? ''));
$noHpIc = trim((string)($_POST['no_hp_ic'] ?? ''));
$oldPin = (string)($_POST['old_pin'] ?? '');
$newPin = (string)($_POST['new_pin'] ?? '');
$confirmPin = (string)($_POST['confirm_pin'] ?? '');
$batch = $batchFromDb > 0 ? $batchFromDb : (int)($_POST['batch'] ?? 0);
$tanggalMasuk = trim((string)($_POST['tanggal_masuk'] ?? ''));

if ($citizenId === '') {
    quickSaveRespond(false, 'Citizen ID wajib diisi.', [], 422);
}
if (!preg_match('/^[A-Z0-9]+$/', $citizenId)) {
    quickSaveRespond(false, 'Citizen ID hanya boleh berisi HURUF BESAR dan ANGKA, tanpa spasi atau karakter khusus.', [], 422);
}
if (strlen($citizenId) < 6) {
    quickSaveRespond(false, 'Citizen ID minimal 6 karakter.', [], 422);
}
if (!preg_match('/[A-Z]/', $citizenId)) {
    quickSaveRespond(false, 'Citizen ID harus mengandung minimal 1 huruf.', [], 422);
}
if (preg_match('/^[0-9]+$/', $citizenId)) {
    quickSaveRespond(false, 'Citizen ID tidak boleh hanya angka saja. Gunakan huruf besar atau kombinasi huruf besar dan angka.', [], 422);
}
if ($fullName === '') {
    quickSaveRespond(false, 'Nama wajib diisi.', [], 422);
}
if ($citizenId === strtoupper(str_replace(' ', '', $fullName))) {
    quickSaveRespond(false, 'Citizen ID tidak boleh sama dengan Nama Medis. Contoh format yang benar: RH39IQLC', [], 422);
}
if ($tanggalMasuk === '') {
    quickSaveRespond(false, 'Tanggal masuk wajib diisi.', [], 422);
}
if ($noHpIc === '') {
    quickSaveRespond(false, 'No HP IC wajib diisi.', [], 422);
}
if (!in_array($jenisKelamin, ['Laki-laki', 'Perempuan'], true)) {
    quickSaveRespond(false, 'Jenis kelamin wajib dipilih.', [], 422);
}
if ($batch <= 0) {
    quickSaveRespond(false, 'Batch tidak valid.', [], 422);
}

$willChangePin = ($oldPin !== '' || $newPin !== '' || $confirmPin !== '');
if ($willChangePin) {
    if ($oldPin === '' || $newPin === '' || $confirmPin === '') {
        quickSaveRespond(false, 'Jika ingin mengganti PIN, semua field PIN harus diisi.', [], 422);
    }
    if (!quickSaveValidPin($oldPin)) {
        quickSaveRespond(false, 'PIN lama harus 4 digit angka.', [], 422);
    }
    if (!quickSaveValidPin($newPin)) {
        quickSaveRespond(false, 'PIN baru harus 4 digit angka.', [], 422);
    }
    if ($newPin !== $confirmPin) {
        quickSaveRespond(false, 'Konfirmasi PIN baru tidak sama.', [], 422);
    }
    if ($oldPin === $newPin) {
        quickSaveRespond(false, 'PIN baru tidak boleh sama dengan PIN lama.', [], 422);
    }
}

$extraDocFields = [
    'sertifikat_operasi_plastik',
    'sertifikat_operasi_kecil',
    'sertifikat_operasi_besar',
    'sertifikat_class_co_asst',
    'sertifikat_class_paramedic',
];
$dateFields = [
    'tanggal_naik_paramedic',
    'tanggal_naik_co_asst',
    'tanggal_naik_dokter',
    'tanggal_naik_dokter_spesialis',
    'tanggal_join_manager',
];
$selectColumns = [
    'kode_nomor_induk_rs',
    'file_ktp',
    'file_sim',
    'file_kta',
    'file_skb',
    'sertifikat_heli',
    'sertifikat_operasi',
    'dokumen_lainnya',
];
$userRhColumns = quickSaveColumns($pdo);
foreach (array_merge($extraDocFields, $dateFields) as $optionalColumn) {
    if (isset($userRhColumns[strtolower($optionalColumn)])) {
        $selectColumns[] = $optionalColumn;
    }
}

$stmt = $pdo->prepare("
    SELECT
        " . implode(",\n        ", $selectColumns) . ",
        pin,
        position
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$userDb = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
quickSaveMark('load_user');

if (empty($userDb)) {
    quickSaveRespond(false, 'User tidak ditemukan.', [], 404);
}

if ($willChangePin && !password_verify($oldPin, (string)($userDb['pin'] ?? ''))) {
    quickSaveRespond(false, 'PIN lama salah.', [], 422);
}

$existingAcademyDocs = ensureAcademyDocIds(parseAcademyDocs($userDb['dokumen_lainnya'] ?? ''));
$existingById = [];
foreach ($existingAcademyDocs as $d) {
    $existingById[(string)$d['id']] = $d;
}

$postedIds = $_POST['academy_doc_id'] ?? [];
$postedNames = $_POST['academy_doc_name'] ?? [];
$max = max(
    is_array($postedIds) ? count($postedIds) : 0,
    is_array($postedNames) ? count($postedNames) : 0
);

$academyFinal = [];
$seen = [];
for ($i = 0; $i < $max; $i++) {
    $id = is_array($postedIds) ? trim((string)($postedIds[$i] ?? '')) : '';
    $name = is_array($postedNames) ? sanitizeAcademyDocName((string)($postedNames[$i] ?? '')) : '';

    if ($id !== '' && isset($existingById[$id])) {
        $doc = $existingById[$id];
        $academyFinal[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : (string)($doc['name'] ?? 'File Lainnya'),
            'path' => (string)($doc['path'] ?? ''),
        ];
        $seen[$id] = true;
    }
}

foreach ($existingAcademyDocs as $d) {
    $id = (string)($d['id'] ?? '');
    if ($id === '' || isset($seen[$id])) {
        continue;
    }
    $academyFinal[] = [
        'id' => $id,
        'name' => (string)($d['name'] ?? 'File Lainnya'),
        'path' => (string)($d['path'] ?? ''),
    ];
}

$academyJson = json_encode($academyFinal, JSON_UNESCAPED_UNICODE);
if ($academyJson === false) {
    quickSaveRespond(false, 'Gagal menyimpan data file lainnya.', [], 500);
}
quickSaveMark('prepare_docs');

ensureUserDokumenLainnyaColumnSupportsJson($pdo);
quickSaveMark('ensure_json_column');

$currentPositionNormalized = ems_normalize_position($currentPos);
$currentRoleNormalized = ems_normalize_role($currentRole);

$visibleDateFields = [];
if ($currentPositionNormalized === 'paramedic' && array_key_exists('tanggal_naik_paramedic', $userDb)) {
    $visibleDateFields[] = 'tanggal_naik_paramedic';
}
if ($currentPositionNormalized === 'co_asst' && array_key_exists('tanggal_naik_co_asst', $userDb)) {
    $visibleDateFields[] = 'tanggal_naik_co_asst';
}
if ($currentPositionNormalized === 'general_practitioner' && array_key_exists('tanggal_naik_dokter', $userDb)) {
    $visibleDateFields[] = 'tanggal_naik_dokter';
}
if ($currentPositionNormalized === 'specialist' && array_key_exists('tanggal_naik_dokter_spesialis', $userDb)) {
    $visibleDateFields[] = 'tanggal_naik_dokter_spesialis';
}
if (ems_is_manager_plus_role($currentRoleNormalized) && array_key_exists('tanggal_join_manager', $userDb)) {
    $visibleDateFields[] = 'tanggal_join_manager';
}

$dateFieldValues = [];
foreach ($dateFields as $dateField) {
    if (!array_key_exists($dateField, $userDb)) {
        continue;
    }

    if (in_array($dateField, $visibleDateFields, true)) {
        $postedValue = trim((string)($_POST[$dateField] ?? ''));
        if ($postedValue === '') {
            quickSaveRespond(false, 'Tanggal kenaikan yang sesuai jabatan wajib diisi.', [], 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedValue)) {
            quickSaveRespond(false, 'Format tanggal tidak valid.', [], 422);
        }
        $dateFieldValues[$dateField] = $postedValue;
        continue;
    }

    $dateFieldValues[$dateField] = $userDb[$dateField] ?? null;
}
quickSaveMark('prepare_dates');

$currentKodeInduk = $userDb['kode_nomor_induk_rs'] ?? null;
$kodeNomorInduk = null;
if (empty($currentKodeInduk)) {
    $batchCode = chr(64 + $batch);
    $idPart = str_pad((string)$userId, 2, '0', STR_PAD_LEFT);
    $nameParts = preg_split('/\s+/', strtoupper($fullName));
    $firstName = $nameParts[0] ?? '';
    $lastName = $nameParts[count($nameParts) - 1] ?? '';
    $letters = substr($firstName, 0, 2) . substr($lastName, 0, 2);
    $nameCodes = [];
    foreach (str_split($letters) as $char) {
        $pos = quickSaveAlphaPos($char);
        if ($pos !== null) {
            $nameCodes[] = quickSaveTwoDigit($pos);
        }
    }
    $kodeNomorInduk = 'RH' . $batchCode . '-' . $idPart . implode('', $nameCodes);
}

$sql = "UPDATE user_rh
        SET
            full_name = ?,
            tanggal_masuk = ?,
            citizen_id = ?,
            jenis_kelamin = ?,
            no_hp_ic = ?,
            dokumen_lainnya = ?";
$params = [
    $fullName,
    $tanggalMasuk,
    $citizenId,
    $jenisKelamin,
    $noHpIc,
    $academyJson,
];

if ($batchFromDb === 0) {
    $sql .= ", batch = ?";
    $params[] = $batch;
}

foreach ($dateFieldValues as $col => $value) {
    $sql .= ", {$col} = ?";
    $params[] = $value;
}

if ($kodeNomorInduk !== null) {
    $sql .= ", kode_nomor_induk_rs = ?";
    $params[] = $kodeNomorInduk;
}

$pinChanged = false;
if ($willChangePin && $newPin !== '') {
    $sql .= ", pin = ?";
    $params[] = password_hash($newPin, PASSWORD_BCRYPT);
    $pinChanged = true;
}

$sql .= " WHERE id = ?";
$params[] = $userId;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        quickSaveRespond(false, 'Nama sudah digunakan oleh user lain.', [], 422);
    }
    quickSaveRespond(false, 'Terjadi kesalahan saat menyimpan data.', [], 500);
}
quickSaveMark('update_user');

$_SESSION['user_rh']['full_name'] = $fullName;
$_SESSION['user_rh']['name'] = $fullName;
$_SESSION['user_rh']['batch'] = $batchFromDb > 0 ? $batchFromDb : $batch;
$_SESSION['user_rh']['tanggal_masuk'] = $tanggalMasuk;
$_SESSION['user_rh']['citizen_id'] = $citizenId;
$_SESSION['user_rh']['no_hp_ic'] = $noHpIc;
$_SESSION['user_rh']['jenis_kelamin'] = $jenisKelamin;
if ($kodeNomorInduk !== null) {
    $_SESSION['user_rh']['kode_nomor_induk_rs'] = $kodeNomorInduk;
}
quickSaveMark('update_session');

$perf = null;
if (ems_current_user_is_programmer_roxwood()) {
    $perf = [
        'total_ms' => (int)round((microtime(true) - $quickSaveStartedAt) * 1000),
        'marks' => array_map(static function (array $mark): array {
            return [
                'label' => $mark['label'],
                'delta_ms' => $mark['delta_ms'],
                'elapsed_ms' => $mark['elapsed_ms'],
            ];
        }, $quickSaveMarks),
    ];
}

quickSaveRespond(true, $pinChanged ? 'Akun dan PIN berhasil diperbarui.' : 'Akun berhasil diperbarui.', [
    'perf' => $perf,
]);
