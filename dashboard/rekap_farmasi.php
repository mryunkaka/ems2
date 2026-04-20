<?php
session_start();
// =======================================
// ERROR LOG CONFIG (PRODUCTION SAFE)
// =======================================
ini_set('log_errors', 1);
ini_set('display_errors', 0); // JANGAN tampilkan ke user
ini_set(
    'error_log',
    __DIR__ . '/../storage/error_log.txt'
);

function formatDuration($seconds)
{
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '0j 0m';

    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    return "{$hours}j {$minutes}m";
}

function formatJoinDurationDetailed(?string $tanggalMasuk): string
{
    if (empty($tanggalMasuk)) {
        return '-';
    }

    try {
        $start = new DateTime((string)$tanggalMasuk);
        $now = new DateTime();

        if ($start > $now) {
            return '-';
        }

        $diff = $start->diff($now);
        $months = ((int)$diff->y * 12) + (int)$diff->m;
        $days = (int)$diff->d;
        $hours = ((int)$diff->h) + (((int)$diff->days) * 24);

        if ($months >= 1) {
            return "{$months} bulan {$days} hari";
        }

        if ((int)$diff->days >= 1) {
            return "{$diff->days} hari";
        }

        return "{$hours} jam";
    } catch (Throwable $e) {
        return '-';
    }
}

// Helper log function
function app_log($message)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    error_log($line, 3, __DIR__ . '/../storage/error_log.txt');
}

function farmasiConsumerNameComparable(string $name): string
{
    return ems_normalize_citizen_id($name);
}

function farmasiConsumerNameDisplay(string $name): string
{
    return farmasiConsumerNameComparable($name);
}

function farmasiConsumerNameKey(string $name): string
{
    return farmasiConsumerNameComparable($name);
}

function farmasiAsciiLower(string $value): string
{
    return strtolower(farmasiConsumerNameComparable($value));
}

function farmasiNormalizeLooseAscii(string $value): string
{
    return farmasiConsumerNameComparable($value);
}

function farmasiTokenDistance(string $left, string $right): int
{
    $left = farmasiNormalizeLooseAscii($left);
    $right = farmasiNormalizeLooseAscii($right);

    if ($left === $right) {
        return 0;
    }

    return levenshtein($left, $right);
}

function farmasiTokenEquivalent(string $left, string $right): bool
{
    $left = farmasiNormalizeLooseAscii($left);
    $right = farmasiNormalizeLooseAscii($right);

    if ($left === '' || $right === '') {
        return false;
    }

    return $left === $right;
}

function farmasiConsumerNamesEquivalent(string $inputName, string $existingName): bool
{
    return farmasiTokenEquivalent($inputName, $existingName);
}

function farmasiFindEquivalentConsumerNames(string $canonicalName, array $existingNames): array
{
    return [];
}

function fetchDistinctConsumerNames(PDO $pdo, string $effectiveUnit, bool $hasUnitCode): array
{
    $sql = "
        SELECT DISTINCT consumer_name
        FROM sales
        WHERE consumer_name IS NOT NULL
          AND consumer_name <> ''
    ";

    $params = [];
    if ($hasUnitCode) {
        $sql .= " AND unit_code = :unit_code";
        $params[':unit_code'] = $effectiveUnit;
    }

    $sql .= " ORDER BY consumer_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $consumerNames = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rawConsumerName) {
        if (!ems_looks_like_citizen_id((string)$rawConsumerName)) {
            continue;
        }

        $displayName = farmasiConsumerNameDisplay((string)$rawConsumerName);
        if ($displayName === '') {
            continue;
        }

        $consumerNames[$displayName] = $displayName;
    }

    return array_values($consumerNames);
}

function farmasiComboPackageModeLabel(array $packages): string
{
    $names = array_values(array_unique(array_filter(array_map(static function ($package) {
        return trim((string)($package['name'] ?? ''));
    }, $packages))));

    if ($names === []) {
        return 'Paket Combo';
    }

    if (count($names) === 1) {
        return $names[0];
    }

    $suffixes = [];
    foreach ($names as $name) {
        if (!preg_match('/^paket\s+(.+)$/iu', $name, $matches)) {
            return implode(' / ', $names);
        }

        $suffixes[] = trim((string)($matches[1] ?? ''));
    }

    return 'Paket ' . implode(' / ', $suffixes);
}

function ensureFarmasiOnline(PDO $pdo, int $userId, string $medicName, string $medicJabatan, bool $confirmActivity = false): void
{
    if ($userId <= 0) {
        return;
    }

    $lastConfirmSql = $confirmActivity ? 'last_confirm_at = NOW(),' : '';

    $stmtStatus = $pdo->prepare("
        INSERT INTO user_farmasi_status
            (user_id, status, last_activity_at, last_confirm_at, auto_offline_at)
        VALUES
            (?, 'online', NOW(), " . ($confirmActivity ? "NOW()" : "NULL") . ", NULL)
        ON DUPLICATE KEY UPDATE
            status = 'online',
            last_activity_at = NOW(),
            {$lastConfirmSql}
            auto_offline_at = NULL,
            updated_at = NOW()
    ");
    $stmtStatus->execute([$userId]);

    $stmtCheckSession = $pdo->prepare("
        SELECT id
        FROM user_farmasi_sessions
        WHERE user_id = ?
          AND session_end IS NULL
        LIMIT 1
    ");
    $stmtCheckSession->execute([$userId]);
    $activeSessionId = $stmtCheckSession->fetchColumn();

    if (!$activeSessionId) {
        $stmtCreateSession = $pdo->prepare("
            INSERT INTO user_farmasi_sessions
                (user_id, medic_name, medic_jabatan, session_start)
            VALUES
                (?, ?, ?, NOW())
        ");
        $stmtCreateSession->execute([
            $userId,
            $medicName,
            $medicJabatan
        ]);
    }
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php'; // hasilkan $rangeStart, $rangeEnd, $rangeLabel
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../assets/design/ui/component.php';

// Block access for users on cuti
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

// ===============================
// HARD GUARD date_range (WAJIB DI HOSTING)
// ===============================
$range       = $range       ?? 'today';
$rangeLabel  = $rangeLabel  ?? 'Hari ini';
$rangeStart  = $rangeStart  ?? date('Y-m-d 00:00:00');
$rangeEnd    = $rangeEnd    ?? date('Y-m-d 23:59:59');
$weeks       = $weeks       ?? [];
$startDT     = $startDT     ?? new DateTime($rangeStart);
$endDT       = $endDT       ?? new DateTime($rangeEnd);

$user = $_SESSION['user_rh'] ?? [];

if (empty($user['name']) || empty($user['position'])) {
    // Redirect ke login jika session invalid
    header('Location: ' . ems_url('/auth/login.php?error=session_expired'));
    exit;
}

$medicName    = $user['name'] ?? '';
$medicJabatan = ems_position_label($user['position'] ?? '');
$medicRole    = $user['role'] ?? '';
$medicRoleLabel = ems_role_label($medicRole);
$medicDivisionLabel = ems_resolve_user_division($user['division'] ?? '', $user['position'] ?? '') ?: '-';
$userId       = (int)($user['id'] ?? 0);
$effectiveUnit = ems_effective_unit($pdo, $user);
$salesHasUnitCode = ems_column_exists($pdo, 'sales', 'unit_code');
$packagesHasUnitCode = ems_column_exists($pdo, 'packages', 'unit_code');

$stmtCurrentUser = $pdo->prepare("
    SELECT tanggal_masuk, citizen_id, batch
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
$stmtCurrentUser->execute([$userId]);
$currentUserProfile = $stmtCurrentUser->fetch(PDO::FETCH_ASSOC) ?: [];

$missingProfileFields = [];
if (empty($currentUserProfile['tanggal_masuk'])) {
    $missingProfileFields[] = 'Tanggal join ke ' . ems_unit_label($effectiveUnit);
}
if (empty($currentUserProfile['citizen_id'])) {
    $missingProfileFields[] = 'Citizen ID';
}
if (empty($currentUserProfile['batch'])) {
    $missingProfileFields[] = 'Batch';
}
$profileIncompleteForFarmasi = !empty($missingProfileFields);

// ===============================
// VALIDASI AKSES REKAP FARMASI
// ===============================
// aturan:
// - trainee tidak boleh
// - selain trainee boleh

$position = strtolower(trim($medicJabatan));

if ($position === 'trainee') {
    http_response_code(403);
    include __DIR__ . '/../partials/header.php';
?>
    <div class="card access-card">
        <h3 class="access-title">Akses Ditolak</h3>
        <p class="access-copy">
            Akun dengan posisi <strong>Trainee</strong>
            tidak diperbolehkan mengakses
            <strong>Rekap Farmasi</strong>.
        </p>
        <a href="/dashboard/index.php" class="btn-secondary top-spaced-button">
            Kembali ke Dashboard
        </a>
    </div>
<?php
    include __DIR__ . '/../partials/footer.php';
    exit;
}

$avatarInitials = initialsFromName($medicName);
$avatarColor    = avatarColorFromName($medicName);

$messages = $_SESSION['flash_messages'] ?? [];
$warnings = $_SESSION['flash_warnings'] ?? [];
$errors   = $_SESSION['flash_errors']   ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_warnings'], $_SESSION['flash_errors']);

// Jika halaman ini berhasil diakses, jangan tampilkan flash error guard division yang tersisa
// dari redirect halaman lain karena itu menyesatkan pengguna.
$errors = array_values(array_filter($errors, static function ($error) {
    return trim((string)$error) !== 'Akses halaman ditolak untuk division Anda.';
}));

$shouldClearForm = !empty($_SESSION['clear_form'] ?? false);
unset($_SESSION['clear_form']);

// Flag sementara untuk request POST saat ini
$clearFormNextLoad = false;

// Tanggal lokal hari ini (WITA)
$todayDate = date('Y-m-d');
$consumerIdentifierLabel = ems_consumer_identifier_label();
$consumerNames = fetchDistinctConsumerNames($pdo, $effectiveUnit, $salesHasUnitCode);
$allConsumerSummaryJS = [];
$consumerBlacklistTableReady = ems_table_exists($pdo, 'consumer_blacklist');
$consumerBlacklistMap = [];

try {
    $consumerSummarySql = "
        SELECT
            consumer_name,
            COUNT(*) AS total_transactions,
            MAX(created_at) AS last_transaction_at
        FROM sales
        WHERE consumer_name IS NOT NULL
          AND consumer_name <> ''
    ";
    $consumerSummaryParams = [];
    if ($salesHasUnitCode) {
        $consumerSummarySql .= " AND unit_code = :unit_code";
        $consumerSummaryParams[':unit_code'] = $effectiveUnit;
    }
    $consumerSummarySql .= " GROUP BY consumer_name";

    $stmtConsumerSummary = $pdo->prepare($consumerSummarySql);
    $stmtConsumerSummary->execute($consumerSummaryParams);
    $consumerSummaryRows = $stmtConsumerSummary->fetchAll(PDO::FETCH_ASSOC);

    foreach ($consumerSummaryRows as $summaryRow) {
        $rawConsumerName = (string) ($summaryRow['consumer_name'] ?? '');
        if (!ems_looks_like_citizen_id($rawConsumerName)) {
            continue;
        }

        $displayName = farmasiConsumerNameDisplay($rawConsumerName);
        if ($displayName === '') {
            continue;
        }

        $existing = $allConsumerSummaryJS[$displayName] ?? [
            'transactions' => 0,
            'last_transaction_at' => null,
            'last_transaction_label' => '-',
        ];

        $existing['transactions'] += (int) ($summaryRow['total_transactions'] ?? 0);
        $lastAt = (string) ($summaryRow['last_transaction_at'] ?? '');
        if ($lastAt !== '' && ($existing['last_transaction_at'] === null || strtotime($lastAt) > strtotime((string) $existing['last_transaction_at']))) {
            $existing['last_transaction_at'] = $lastAt;
            $existing['last_transaction_label'] = date('d M Y H:i', strtotime($lastAt));
        }

        $allConsumerSummaryJS[$displayName] = $existing;
    }
} catch (Throwable $e) {
    $allConsumerSummaryJS = [];
}

if ($consumerBlacklistTableReady) {
    try {
        $stmtBlacklist = $pdo->prepare("
            SELECT consumer_name, consumer_name_key, note, unit_code
            FROM consumer_blacklist
            WHERE is_active = 1
            ORDER BY updated_at DESC, id DESC
        ");
        $stmtBlacklist->execute();
        foreach ($stmtBlacklist->fetchAll(PDO::FETCH_ASSOC) as $blacklistRow) {
            $blacklistName = (string)($blacklistRow['consumer_name'] ?? '');
            $blacklistKey = trim((string)($blacklistRow['consumer_name_key'] ?? ''));
            $derivedBlacklistKey = farmasiConsumerNameKey($blacklistName);
            $targetKeys = array_values(array_unique(array_filter([$blacklistKey, $derivedBlacklistKey])));

            if (empty($targetKeys)) {
                continue;
            }

            foreach ($targetKeys as $targetKey) {
                if (isset($consumerBlacklistMap[$targetKey])) {
                    continue;
                }

                $consumerBlacklistMap[$targetKey] = [
                    'name' => $blacklistName,
                    'note' => trim((string)($blacklistRow['note'] ?? '')),
                    'unit_code' => (string)($blacklistRow['unit_code'] ?? 'roxwood'),
                ];
            }
        }
    } catch (Throwable $e) {
        app_log('[FARMASI BLACKLIST LOAD ERROR] ' . $e->getMessage());
    }
}

// ===============================
// TOTAL TRANSAKSI MINGGU BERJALAN (SENIN–MINGGU)
// ===============================
$weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
$weekEnd   = date('Y-m-d 23:59:59', strtotime('sunday this week'));

$weeklyTxCount = 0;

if (!empty($_SESSION['user_rh']['id'])) {
    $stmtWeekly = $pdo->prepare("
        SELECT COUNT(*) 
        FROM sales
        WHERE medic_user_id = :uid
          AND created_at BETWEEN :start AND :end
          " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
    ");
    $weeklyParams = [
        ':uid'   => $_SESSION['user_rh']['id'],
        ':start' => $weekStart,
        ':end'   => $weekEnd,
    ];
    if ($salesHasUnitCode) {
        $weeklyParams[':unit_code'] = $effectiveUnit;
    }
    $stmtWeekly->execute($weeklyParams);

    $weeklyTxCount = (int)$stmtWeekly->fetchColumn();
}

// Flag untuk PRG
$redirectAfterPost = false;

// ======================================================
// HANDLE REQUEST POST (SEMUA ACTION FORM DI SINI)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'merge_consumer_names') {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Fitur merge nama dinonaktifkan karena farmasi sekarang memakai Citizen ID Konsumen.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2.6) Hapus banyak transaksi (checkbox + bulk delete)
    if ($action === 'delete_selected') {
        // Auto-set dari session jika kosong
        if ($medicName === '' || $medicJabatan === '') {
            if (!empty($_SESSION['user_rh']['name']) && !empty($_SESSION['user_rh']['position'])) {
                $medicName = $_SESSION['user_rh']['name'];
                $medicJabatan = $_SESSION['user_rh']['position'];
                $medicRole = $_SESSION['user_rh']['role'] ?? '';
            } else {
                $errors[] = "Session login tidak valid. Silakan login ulang.";
            }
        }

        // Lanjutkan validasi setelah auto-set
        if (empty($errors) && ($medicName === '' || $medicJabatan === '')) {
            $errors[] = "Set dulu nama petugas medis sebelum input transaksi.";
        } else {
            $ids = $_POST['sale_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                $errors[] = "Tidak ada transaksi yang dipilih untuk dihapus.";
            } else {
                // Sanitasi -> integer > 0
                $cleanIds = [];
                foreach ($ids as $id) {
                    $id = (int)$id;
                    if ($id > 0) {
                        $cleanIds[] = $id;
                    }
                }

                if (empty($cleanIds)) {
                    $errors[] = "ID transaksi tidak valid.";
                } else {
                    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    $params       = $cleanIds;
                    $params[]     = $medicName;
                    if ($salesHasUnitCode) {
                        $params[] = $effectiveUnit;
                    }

                    $stmtDel = $pdo->prepare("
                        DELETE FROM sales
                        WHERE id IN ($placeholders)
                          AND medic_name = ?
                          " . ($salesHasUnitCode ? " AND unit_code = ?" : "") . "
                    ");
                    $stmtDel->execute($params);
                    $deleted = $stmtDel->rowCount();

                    if ($deleted > 0) {
                        $messages[] = "{$deleted} transaksi berhasil dihapus (sesuai hak akses Anda).";

                        /* =====================================================
                           LOG ACTIVITY (HAPUS TRANSAKSI)
                           ===================================================== */
                        try {
                            $description = "Menghapus {$deleted} transaksi";

                            $logActivity = $pdo->prepare("
                                INSERT INTO farmasi_activities 
                                    (activity_type, medic_user_id, medic_name, description)
                                VALUES (?, ?, ?, ?)
                            ");

                            $logActivity->execute([
                                'delete',
                                $_SESSION['user_rh']['id'] ?? 0,
                                $medicName,
                                $description
                            ]);
                        } catch (Exception $e) {
                            error_log('[ACTIVITY LOG ERROR] ' . $e->getMessage());
                        }
                        /* ===================================================== */
                    } else {
                        $errors[] = "Tidak ada transaksi yang dapat dihapus. Pastikan transaksi milik Anda.";
                    }
                }
            }
        }

        $redirectAfterPost = true;
    }

    // 3) Tambah transaksi penjualan (bisa beberapa paket sekaligus)
    if ($action === 'add_sale') {
        // ===============================
        // COOLDOWN ANTI-SPAM (SERVER - SESSION BASED)
        // ===============================
        $nowTs = time();

        // Inisialisasi jika belum ada
        if (!isset($_SESSION['last_tx_ts'])) {
            $_SESSION['last_tx_ts'] = 0;
        }

        // Hitung selisih detik
        $diffSeconds = $nowTs - (int)$_SESSION['last_tx_ts'];

        // Cooldown FIXED 10 detik (ANTI SPAM KLIK)
        // ini BUKAN fairness dan BUKAN limit harian
        if ($diffSeconds < 10) {
            $remain = 10 - $diffSeconds;
            $errors[] = "Mohon tunggu {$remain} detik sebelum input transaksi berikutnya.";
        }

        $postedToken = $_POST['tx_token'] ?? '';

        if (
            empty($postedToken) ||
            empty($_SESSION['tx_token']) ||
            !hash_equals($_SESSION['tx_token'], $postedToken)
        ) {
            $errors[] = 'Permintaan tidak valid atau sudah diproses.';
        } elseif ($profileIncompleteForFarmasi) {
            $errors[] = 'Transaksi tidak dapat disimpan. Lengkapi dulu: ' . implode(', ', $missingProfileFields) . '.';
        } else {

            unset($_SESSION['tx_token']);

            if ($medicName === '' || $medicJabatan === '') {
                $errors[] = "Set dulu nama petugas medis sebelum input transaksi.";
            } else {
                try {
                    // Sinkronkan status lebih awal agar tombol simpan tidak terblokir
                    // hanya karena status sebelumnya masih OFFLINE.
                    ensureFarmasiOnline($pdo, $userId, $medicName, $medicJabatan, false);
                } catch (Throwable $e) {
                    app_log('[FARMASI AUTO-ONLINE ERROR] user_id=' . $userId . ' | error=' . $e->getMessage());
                }

                // ===============================
                // AMBIL INPUT + NORMALISASI CITIZEN ID
                // ===============================
                $rawConsumerName = trim((string)($_POST['consumer_name'] ?? ''));
                $consumerName = farmasiConsumerNameDisplay($rawConsumerName);
                $submittedIdentityId = (int)($_POST['identity_id'] ?? 0);
                $mergeTargets = [];


                $packageMainRaw = trim((string)($_POST['package_main'] ?? ''));
                $isCustomPackage = $packageMainRaw === 'custom';
                $pkgMainId    = $isCustomPackage ? 0 : (int)$packageMainRaw;
                $pkgBandageId = (int)($_POST['package_bandage'] ?? 0);
                $pkgIfaksId   = (int)($_POST['package_ifaks'] ?? 0);
                $pkgPainId    = (int)($_POST['package_painkiller'] ?? 0);

                $forceOverLimit = isset($_POST['force_overlimit']) && $_POST['force_overlimit'] === '1';

                if ($consumerName === '') {
                    $errors[] = "{$consumerIdentifierLabel} wajib diisi.";
                } elseif (!ems_looks_like_citizen_id($rawConsumerName)) {
                    $errors[] = "{$consumerIdentifierLabel} tidak valid. Gunakan format Citizen ID huruf besar atau kombinasi huruf besar dan angka, bukan nama konsumen.";
                } else {
                    if ($consumerName !== strtoupper(trim($rawConsumerName))) {
                        $warnings[] = "{$consumerIdentifierLabel} dinormalisasi menjadi {$consumerName}.";
                    }

                    $consumerBlacklistKey = farmasiConsumerNameKey($consumerName);
                    $blacklistInfo = null;
                    if ($consumerBlacklistKey !== '' && isset($consumerBlacklistMap[$consumerBlacklistKey])) {
                        $blacklistInfo = $consumerBlacklistMap[$consumerBlacklistKey];
                    } elseif ($consumerBlacklistTableReady && $consumerBlacklistKey !== '') {
                        $stmtBlacklistCheck = $pdo->prepare("
                            SELECT consumer_name, note, unit_code
                            FROM consumer_blacklist
                            WHERE consumer_name_key = ?
                              AND is_active = 1
                            LIMIT 1
                        ");
                        $stmtBlacklistCheck->execute([$consumerBlacklistKey]);
                        $blacklistInfo = $stmtBlacklistCheck->fetch(PDO::FETCH_ASSOC) ?: null;
                    }

                    if ($blacklistInfo) {
                        $blacklistNote = trim((string)($blacklistInfo['note'] ?? ''));
                        $errors[] = $blacklistNote !== ''
                            ? "{$consumerIdentifierLabel} {$consumerName} telah di-blacklist. Lihat note blacklist: {$blacklistNote}."
                            : "{$consumerIdentifierLabel} {$consumerName} telah di-blacklist dan transaksi tidak dapat disimpan.";
                    }

                    $autoMerge = (
                        isset($_POST['auto_merge']) &&
                        $_POST['auto_merge'] === '1' &&
                        isset($_POST['merge_targets']) &&
                        $_POST['merge_targets'] !== ''
                    );
                    if ($autoMerge) {
                        $decoded = json_decode($_POST['merge_targets'], true);
                        if (is_array($decoded)) {
                            $mergeTargets = array_values(array_unique(array_filter($decoded, static function ($item) use ($consumerName) {
                                return is_string($item) && trim($item) !== '' && strcasecmp(trim($item), $consumerName) !== 0;
                            })));
                        }
                    }
                }

                // ===============================
                // KUMPULKAN PAKET DIPILIH
                // ===============================
                $selectedIds = [];
                if ($pkgMainId > 0)    $selectedIds[] = $pkgMainId;
                if ($pkgBandageId > 0) $selectedIds[] = $pkgBandageId;
                if ($pkgIfaksId > 0)   $selectedIds[] = $pkgIfaksId;
                if ($pkgPainId > 0)    $selectedIds[] = $pkgPainId;

                if (empty($selectedIds) && empty($errors)) {
                    $errors[] = "Pilih minimal satu paket.";
                }

                // ===============================
                // AMBIL DETAIL PAKET DARI DB
                // ===============================
                if (empty($errors)) {
                    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                    $stmtPkg = $pdo->prepare("SELECT * FROM packages WHERE id IN ($placeholders)" . ($packagesHasUnitCode ? " AND COALESCE(unit_code, 'roxwood') = ?" : ""));
                    $pkgParams = $selectedIds;
                    if ($packagesHasUnitCode) {
                        $pkgParams[] = $effectiveUnit;
                    }
                    $stmtPkg->execute($pkgParams);
                    $rows = $stmtPkg->fetchAll(PDO::FETCH_ASSOC);

                    $packagesSelected = [];
                    foreach ($rows as $r) {
                        $packagesSelected[(int)$r['id']] = $r;
                    }

                    foreach ($selectedIds as $id) {
                        if (!isset($packagesSelected[$id])) {
                            $errors[] = "Ada paket yang tidak ditemukan di database.";
                            break;
                        }
                    }
                }

                $transactionStarted = false;

                // ===============================
                // HITUNG TOTAL ITEM BARU
                // ===============================
                if (empty($errors)) {
                    $pdo->beginTransaction();
                    $transactionStarted = true;

                    $addBandage = 0;
                    $addIfaks   = 0;
                    $addPain    = 0;

                    foreach ($selectedIds as $id) {
                        $p = $packagesSelected[$id];
                        $addBandage += (int)$p['bandage_qty'];
                        $addIfaks   += (int)$p['ifaks_qty'];
                        $addPain    += (int)$p['painkiller_qty'];
                    }

                    // ===============================
                    // TOTAL HARI INI (DB)
                    // ===============================
                    $stmt = $pdo->prepare("
                        SELECT 
                            COALESCE(SUM(qty_bandage),0)    AS total_bandage,
                            COALESCE(SUM(qty_ifaks),0)      AS total_ifaks,
                            COALESCE(SUM(qty_painkiller),0) AS total_painkiller
                        FROM sales
                        WHERE DATE(created_at) = :today
                          " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
                          AND UPPER(
                              REPLACE(
                                  REPLACE(
                                      REPLACE(
                                          REPLACE(TRIM(consumer_name), ' ', ''),
                                          '-', ''
                                      ),
                                      '.',
                                      ''
                                  ),
                                  '/',
                                  ''
                              )
                          ) = :name
                    ");
                    $totalsParams = [
                        ':name'  => $consumerName,
                        ':today' => $todayDate,
                    ];
                    if ($salesHasUnitCode) {
                        $totalsParams[':unit_code'] = $effectiveUnit;
                    }
                    $stmt->execute($totalsParams);
                    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                        'total_bandage' => 0,
                        'total_ifaks' => 0,
                        'total_painkiller' => 0,
                    ];

                    // ===============================
                    // VALIDASI 1 CITIZEN ID = 1 TRANSAKSI / HARI (SERVER)
                    // ===============================
                    $totalToday =
                        (int)$totals['total_bandage'] +
                        (int)$totals['total_ifaks'] +
                        (int)$totals['total_painkiller'];

                    if ($totalToday > 0) {
                        $errors[] = "{$consumerIdentifierLabel} {$consumerName} sudah punya transaksi hari ini. 1 Citizen ID hanya boleh 1 transaksi per hari.";
                    }

                    $newBandage = $totals['total_bandage'] + $addBandage;
                    $newIfaks   = $totals['total_ifaks'] + $addIfaks;
                    $newPain    = $totals['total_painkiller'] + $addPain;

                    // ===============================
                    // BATAS HARIAN
                    // ===============================
                    $maxBandage = 30;
                    $maxIfaks   = 10;
                    $maxPain    = 10;

                    $overLimit = false;

                    if ($newBandage > $maxBandage) {
                        $warnings[] = "{$consumerIdentifierLabel} {$consumerName} melebihi batas BANDAGE ({$newBandage}/{$maxBandage}).";
                        $overLimit = true;
                    }
                    if ($newIfaks > $maxIfaks) {
                        $warnings[] = "{$consumerIdentifierLabel} {$consumerName} melebihi batas IFAKS ({$newIfaks}/{$maxIfaks}).";
                        $overLimit = true;
                    }
                    if ($newPain > $maxPain) {
                        $warnings[] = "{$consumerIdentifierLabel} {$consumerName} melebihi batas PAINKILLER ({$newPain}/{$maxPain}).";
                        $overLimit = true;
                    }

                    if ($overLimit && !$forceOverLimit) {
                        $errors[] = "Transaksi dibatalkan karena melebihi batas harian.";
                    }
                }

                // ===============================
                // SERVER SIDE FAIRNESS LOCK
                // ===============================
                if (empty($errors)) {

                    $stmtFair = $pdo->prepare("
                        SELECT
                            ufs.user_id AS user_id,
                            COALESCE(COUNT(s.id), 0) AS total
                        FROM user_farmasi_status ufs
                        LEFT JOIN sales s
                            ON s.medic_user_id = ufs.user_id
                        AND DATE(s.created_at) = CURDATE()
                        " . ($salesHasUnitCode ? " AND s.unit_code = :unit_code" : "") . "
                        WHERE ufs.status = 'online'
                        GROUP BY ufs.user_id
                        ORDER BY total ASC
                    ");
                    $stmtFair->execute($salesHasUnitCode ? [':unit_code' => $effectiveUnit] : []);
                    $rows = $stmtFair->fetchAll(PDO::FETCH_ASSOC);
                    $lowest = $rows[0] ?? null;

                    if ($lowest && (int)$lowest['user_id'] !== (int)$_SESSION['user_rh']['id']) {

                        $current = null;
                        foreach ($rows as $r) {
                            if ((int)$r['user_id'] === (int)$_SESSION['user_rh']['id']) {
                                $current = $r;
                                break;
                            }
                        }

                        if ($current && ((int)$current['total'] - (int)$lowest['total']) >= 10) {
                            $warnings[] =
                                'Distribusi transaksi tidak seimbang. ' .
                                'Pertimbangkan mengarahkan konsumen ke petugas medis lain.';
                        }
                    }
                }

                // ===============================
                // INSERT TRANSAKSI (AMAN + SESSION FIX)
                // ===============================
                if (empty($errors)) {

                    $now    = date('Y-m-d H:i:s');

                    $stmtInsert = $pdo->prepare("
                        INSERT INTO sales
                        (
                            consumer_name,
                            medic_name,
                            medic_user_id,
                            medic_jabatan,
                            " . ($salesHasUnitCode ? "unit_code," : "") . "
                            package_id,
                            package_name,
                            price,
                            qty_bandage,
                            qty_ifaks,
                            qty_painkiller,
                            created_at,
                            tx_hash
                        )
                        VALUES
                        (
                            :cname,
                            :mname,
                            :muid,
                            :mjab,
                            " . ($salesHasUnitCode ? ":unit_code," : "") . "
                            :pid,
                            :pname,
                            :price,
                            :qb,
                            :qi,
                            :qp,
                            :created,
                            :tx
                        )
                    ");

                    try {
                        // ===============================
                        // INSERT SALES
                        // custom => 1 baris "Paket Custom"
                        // selain custom => tetap per paket
                        // ===============================
                        if ($isCustomPackage) {
                            $customPrice = 0;
                            $customBandage = 0;
                            $customIfaks = 0;
                            $customPain = 0;
                            $customPackageId = (int)($selectedIds[0] ?? 0);

                            foreach ($selectedIds as $id) {
                                $p = $packagesSelected[$id];
                                $customPrice += (int)$p['price'];
                                $customBandage += (int)$p['bandage_qty'];
                                $customIfaks += (int)$p['ifaks_qty'];
                                $customPain += (int)$p['painkiller_qty'];
                            }

                            $txHash = hash('sha256', $postedToken . '|custom');

                            $stmtInsert->execute([
                                ':cname'   => $consumerName,
                                ':mname'   => $medicName,
                                ':muid'    => $userId,
                                ':mjab'    => $medicJabatan,
                                ...($salesHasUnitCode ? [':unit_code' => $effectiveUnit] : []),
                                ':pid'     => $customPackageId,
                                ':pname'   => 'Paket Custom',
                                ':price'   => $customPrice,
                                ':qb'      => $customBandage,
                                ':qi'      => $customIfaks,
                                ':qp'      => $customPain,
                                ':created' => $now,
                                ':tx'      => $txHash,
                            ]);
                        } else {
                            foreach ($selectedIds as $id) {
                                $p = $packagesSelected[$id];

                                $txHash = hash('sha256', $postedToken . '|' . $id);

                                $stmtInsert->execute([
                                    ':cname'   => $consumerName,
                                    ':mname'   => $medicName,
                                    ':muid'    => $userId,
                                    ':mjab'    => $medicJabatan,
                                    ...($salesHasUnitCode ? [':unit_code' => $effectiveUnit] : []),
                                    ':pid'     => (int)$p['id'],
                                    ':pname'   => $p['name'],
                                    ':price'   => (int)$p['price'],
                                    ':qb'      => (int)$p['bandage_qty'],
                                    ':qi'      => (int)$p['ifaks_qty'],
                                    ':qp'      => (int)$p['painkiller_qty'],
                                    ':created' => $now,
                                    ':tx'      => $txHash,
                                ]);
                            }
                        }

                        /* =====================================================
                           LOG ACTIVITY (TRANSAKSI BARU)
                           ===================================================== */
                        try {
                            // Hitung total dari data yang sudah ada
                            $logTotalBandage = 0;
                            $logTotalIfaks = 0;
                            $logTotalPain = 0;
                            $logTotalPrice = 0;

                            foreach ($selectedIds as $id) {
                                $p = $packagesSelected[$id];
                                $logTotalBandage += (int)$p['bandage_qty'];
                                $logTotalIfaks   += (int)$p['ifaks_qty'];
                                $logTotalPain    += (int)$p['painkiller_qty'];
                                $logTotalPrice   += (int)$p['price'];
                            }

                            // Buat deskripsi singkat
                            $itemsText = [];
                            if ($logTotalBandage > 0) $itemsText[] = "{$logTotalBandage} Bandage";
                            if ($logTotalIfaks > 0) $itemsText[] = "{$logTotalIfaks} IFAKS";
                            if ($logTotalPain > 0) $itemsText[] = "{$logTotalPain} Painkiller";

                            $description = sprintf(
                                'Transaksi: %s - %s (%s)',
                                $consumerName,
                                implode(', ', $itemsText),
                                dollar($logTotalPrice)
                            );

                            if ($submittedIdentityId > 0) {
                                $description .= ' [otomatis foto]';
                            }

                            $logActivity = $pdo->prepare("
                                INSERT INTO farmasi_activities 
                                    (activity_type, medic_user_id, medic_name, description)
                                VALUES (?, ?, ?, ?)
                            ");

                            $logActivity->execute([
                                'transaction',
                                $userId,
                                $medicName,
                                $description
                            ]);
                        } catch (Exception $e) {
                            error_log('[ACTIVITY LOG ERROR] ' . $e->getMessage());
                        }
                        /* ===================================================== */

                        // ======================================================
                        // UPDATE STATUS FARMASI → ONLINE (AKTIVITAS VALID)
                        // ======================================================
                        ensureFarmasiOnline($pdo, $userId, $medicName, $medicJabatan, true);

                        // ===============================
                        // UPDATE COOLDOWN TIMESTAMP
                        // ===============================
                        $_SESSION['last_tx_ts'] = time();

                        $messages[] = "Transaksi {$consumerName} berhasil disimpan (" . count($selectedIds) . " paket).";
                        $clearFormNextLoad = true;
                    } catch (PDOException $e) {
                        if ($transactionStarted && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $dbMessage = $e->getMessage();
                        if (
                            $e->getCode() === '23000' &&
                            (
                                stripos($dbMessage, 'uniq_tx_hash') !== false ||
                                stripos($dbMessage, 'tx_hash') !== false
                            )
                        ) {
                            $warnings[] = 'Transaksi ini sudah pernah diproses.';
                        } else {
                            app_log(
                                '[FARMASI INSERT ERROR] ' .
                                    'consumer=' . $consumerName .
                                    ' | user_id=' . $userId .
                                    ' | package_main=' . $packageMainRaw .
                                    ' | selected_ids=' . json_encode($selectedIds) .
                                    ' | error=' . $dbMessage
                            );
                            $errors[] = 'Terjadi kesalahan sistem saat menyimpan transaksi.';
                        }
                    } catch (Throwable $e) {
                        if ($transactionStarted && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        app_log('[FARMASI TRANSACTION ERROR] ' . $e->getMessage());
                        $errors[] = 'Terjadi kesalahan sistem saat memproses transaksi.';
                    }
                }

                if ($transactionStarted && $pdo->inTransaction()) {
                    if (empty($errors)) {
                        $pdo->commit();
                    } else {
                        $pdo->rollBack();
                    }
                }
            }
        }

        $redirectAfterPost = true;
    }
}

// ======================================================
// POST-REDIRECT-GET: lakukan redirect setelah POST
// ======================================================
if ($redirectAfterPost) {
    // simpan pesan ke session agar bisa ditampilkan setelah redirect
    $_SESSION['flash_messages'] = $messages;
    $_SESSION['flash_warnings'] = $warnings;
    $_SESSION['flash_errors']   = $errors;

    // kalau transaksi barusan sukses, beri tanda untuk kosongkan form setelah redirect
    if ($clearFormNextLoad) {
        $_SESSION['clear_form'] = true;
    }

    // redirect ke URL yang sama tapi dengan GET (tanpa resubmit)
    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '#');
    header('Location: ' . $redirectUrl);
    exit;
}


// -------------------------------------------
// Data untuk tampilan: paket & konsumen
// -------------------------------------------

// Ambil semua paket
$stmtPackages = $pdo->prepare("SELECT * FROM packages" . ($packagesHasUnitCode ? " WHERE COALESCE(unit_code, 'roxwood') = :unit_code" : "") . " ORDER BY name ASC");
$stmtPackages->execute($packagesHasUnitCode ? [':unit_code' => $effectiveUnit] : []);
$packages = $stmtPackages->fetchAll(PDO::FETCH_ASSOC);

// Kelompokkan paket menjadi 4 kategori
$paketAB            = []; // Paket A / B (combo)
$bandagePackages    = [];
$ifaksPackages      = [];
$painkillerPackages = [];

$packagesById = [];

foreach ($packages as $p) {
    $id   = (int)$p['id'];
    $name = strtoupper($p['name']);

    $packagesById[$id] = [
        'name'       => $p['name'],
        'price'      => (int)$p['price'],
        'bandage'    => (int)$p['bandage_qty'],
        'ifaks'      => (int)$p['ifaks_qty'],
        'painkiller' => (int)$p['painkiller_qty'],
    ];

    if (preg_match('/^PAKET\s+[A-Z]+(?:\s|$)/', $name)) {
        $paketAB[] = $p;
    } elseif ($p['bandage_qty'] > 0 && $p['ifaks_qty'] == 0 && $p['painkiller_qty'] == 0) {
        $bandagePackages[] = $p;
    } elseif ($p['ifaks_qty'] > 0 && $p['bandage_qty'] == 0 && $p['painkiller_qty'] == 0) {
        $ifaksPackages[] = $p;
    } elseif ($p['painkiller_qty'] > 0 && $p['bandage_qty'] == 0 && $p['ifaks_qty'] == 0) {
        $painkillerPackages[] = $p;
    }
}

$comboPackageModeLabel = farmasiComboPackageModeLabel($paketAB);

// ===============================
// HITUNG HARGA REAL PER PCS DARI DB
// ===============================
$pricePerPcs = [
    'bandage'    => 0,
    'ifaks'      => 0,
    'painkiller' => 0,
];

foreach ($packages as $p) {
    if ($p['bandage_qty'] > 0 && $p['ifaks_qty'] == 0 && $p['painkiller_qty'] == 0) {
        $pricePerPcs['bandage'] = (int)($p['price'] / max(1, $p['bandage_qty']));
    }
    if ($p['ifaks_qty'] > 0 && $p['bandage_qty'] == 0 && $p['painkiller_qty'] == 0) {
        $pricePerPcs['ifaks'] = (int)($p['price'] / max(1, $p['ifaks_qty']));
    }
    if ($p['painkiller_qty'] > 0 && $p['bandage_qty'] == 0 && $p['ifaks_qty'] == 0) {
        $pricePerPcs['painkiller'] = (int)($p['price'] / max(1, $p['painkiller_qty']));
    }
}

$stmtOnlineMedics = $pdo->prepare("
    SELECT
        ufs.user_id,
        ur.full_name AS medic_name,
        ur.position  AS medic_jabatan,
        ur.role AS medic_role,
        ur.division AS medic_division,
        ur.batch AS medic_batch,
        ur.tanggal_masuk,
        COUNT(s.id)  AS total_transaksi,
        COALESCE(SUM(s.price),0) AS total_pendapatan,
        FLOOR(COALESCE(SUM(s.price),0) * 0.4) AS bonus_40,
        (SELECT COUNT(*) FROM sales WHERE medic_user_id = ufs.user_id" . ($salesHasUnitCode ? " AND unit_code = :unit_code_sub_all" : "") . ") AS total_transaksi_semua,
        
        -- TAMBAHAN: HITUNG TRANSAKSI MINGGU INI
        (SELECT COUNT(*)
         FROM sales
         WHERE medic_user_id = ufs.user_id
           " . ($salesHasUnitCode ? " AND unit_code = :unit_code_sub_week" : "") . "
           AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00') - INTERVAL (WEEKDAY(NOW())) DAY
           AND created_at <  DATE_FORMAT(NOW(), '%Y-%m-%d 23:59:59') + INTERVAL (6 - WEEKDAY(NOW())) DAY
        ) AS weekly_transaksi,
        
        -- TAMBAHAN: HITUNG JAM ONLINE MINGGU INI
        (SELECT COALESCE(SUM(duration_seconds), 0)
         FROM user_farmasi_sessions
         WHERE user_id = ufs.user_id
           AND session_start >= DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00') - INTERVAL (WEEKDAY(NOW())) DAY
           AND session_start <  DATE_FORMAT(NOW(), '%Y-%m-%d 23:59:59') + INTERVAL (6 - WEEKDAY(NOW())) DAY
        ) AS weekly_online_seconds
        
    FROM user_farmasi_status ufs
    JOIN user_rh ur
        ON ur.id = ufs.user_id
    LEFT JOIN sales s
        ON s.medic_user_id = ufs.user_id
       AND DATE(s.created_at) = CURDATE()
       " . ($salesHasUnitCode ? " AND s.unit_code = :unit_code_join" : "") . "
    WHERE ufs.status = 'online'
      " . (ems_column_exists($pdo, 'user_rh', 'unit_code') ? " AND COALESCE(ur.unit_code, 'roxwood') = :user_unit_code" : "") . "
    GROUP BY ufs.user_id, ur.full_name, ur.position, ur.role, ur.division, ur.batch, ur.tanggal_masuk
    ORDER BY total_transaksi ASC, total_pendapatan ASC
");
$onlineMedicsParams = [];
if ($salesHasUnitCode) {
    $onlineMedicsParams = [
        ':unit_code_sub_all' => $effectiveUnit,
        ':unit_code_sub_week' => $effectiveUnit,
        ':unit_code_join' => $effectiveUnit,
    ];
}
if (ems_column_exists($pdo, 'user_rh', 'unit_code')) {
    $onlineMedicsParams[':user_unit_code'] = $effectiveUnit;
}
$stmtOnlineMedics->execute($onlineMedicsParams);
$onlineMedics = $stmtOnlineMedics->fetchAll(PDO::FETCH_ASSOC);

// FORMAT DURASI UNTUK TAMPILAN
foreach ($onlineMedics as &$m) {
    $seconds = (int)($m['weekly_online_seconds'] ?? 0);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    $m['weekly_online_text'] = "{$hours}j {$minutes}m {$secs}d";
    $m['join_duration_text'] = formatJoinDurationDetailed($m['tanggal_masuk'] ?? null);
    $m['medic_role_label'] = ems_role_label($m['medic_role'] ?? '');
    $m['medic_division_label'] = ems_normalize_division($m['medic_division'] ?? '') ?: '-';
    $m['medic_position_label'] = ems_position_label($m['medic_jabatan'] ?? '');
}
unset($m);

// ======================================================
// FAIRNESS MEDIS (SELISIH >= 5 TRANSAKSI)
// ======================================================
$FAIRNESS_REDIRECT = null;

if (!empty($onlineMedics) && !empty($_SESSION['user_rh']['id'])) {
    $activeUserId = (int)$_SESSION['user_rh']['id'];

    $lowestMedic  = $onlineMedics[0]; // paling sedikit
    $currentMedic = null;

    foreach ($onlineMedics as $m) {
        if ((int)$m['user_id'] === $activeUserId) {
            $currentMedic = $m;
            break;
        }
    }

    // VALIDASI KETAT
    if (
        $currentMedic &&
        $lowestMedic &&
        (int)$currentMedic['user_id'] !== (int)$lowestMedic['user_id']
    ) {
        $diff = (int)$currentMedic['total_transaksi']
            - (int)$lowestMedic['total_transaksi'];

        // HARUS BENAR-BENAR >= 5
        // if ($diff >= 15) {
        //     $FAIRNESS_REDIRECT = [
        //         'medic_name'       => $lowestMedic['medic_name'],
        //         'medic_jabatan'    => $lowestMedic['medic_jabatan'],
        //         'total_transaksi'  => (int)$lowestMedic['total_transaksi'],
        //         'selisih'          => $diff
        //     ];
        // }
    }
}

// Totals harian per konsumen untuk limit-check di JS
$stmtDailyTotals = $pdo->prepare("
    SELECT consumer_name,
           COALESCE(SUM(qty_bandage),0)    AS total_bandage,
           COALESCE(SUM(qty_ifaks),0)      AS total_ifaks,
           COALESCE(SUM(qty_painkiller),0) AS total_painkiller
    FROM sales
    WHERE DATE(created_at) = :today
      " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
    GROUP BY consumer_name
");
$dailyTotalsParams = [':today' => $todayDate];
if ($salesHasUnitCode) {
    $dailyTotalsParams[':unit_code'] = $effectiveUnit;
}
$stmtDailyTotals->execute($dailyTotalsParams);
$dailyTotalsRows = $stmtDailyTotals->fetchAll(PDO::FETCH_ASSOC);

$dailyTotalsJS = [];
foreach ($dailyTotalsRows as $row) {
    $key = farmasiConsumerNameKey((string)$row['consumer_name']);
    if ($key === '') {
        continue;
    }
    if (!isset($dailyTotalsJS[$key])) {
        $dailyTotalsJS[$key] = [
            'bandage'    => 0,
            'ifaks'      => 0,
            'painkiller' => 0,
        ];
    }
    $dailyTotalsJS[$key]['bandage'] += (int)$row['total_bandage'];
    $dailyTotalsJS[$key]['ifaks'] += (int)$row['total_ifaks'];
    $dailyTotalsJS[$key]['painkiller'] += (int)$row['total_painkiller'];
}

// Detail transaksi harian per konsumen (untuk ditampilkan di warning JS)
$stmtDailyDetail = $pdo->prepare("
    SELECT consumer_name, medic_name, package_name, created_at,
           qty_bandage, qty_ifaks, qty_painkiller
    FROM sales
    WHERE DATE(created_at) = :today
      " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
    ORDER BY created_at ASC
");
$dailyDetailParams = [':today' => $todayDate];
if ($salesHasUnitCode) {
    $dailyDetailParams[':unit_code'] = $effectiveUnit;
}
$stmtDailyDetail->execute($dailyDetailParams);
$detailRows = $stmtDailyDetail->fetchAll(PDO::FETCH_ASSOC);

$dailyDetailJS = [];
$dailyDetailByNameJS = [];
$todayConsumerNamesJS = [];
foreach ($detailRows as $row) {
    $key = farmasiConsumerNameKey((string)$row['consumer_name']);
    if ($key === '') {
        continue;
    }
    $displayName = trim((string)$row['consumer_name']);
    if (!isset($dailyDetailJS[$key])) {
        $dailyDetailJS[$key] = [];
    }
    $dailyDetailJS[$key][] = [
        'consumer_name' => $displayName,
        'medic'      => $row['medic_name'],
        'package'    => $row['package_name'],
        'time'       => formatTanggalID($row['created_at']),
        'bandage'    => (int)$row['qty_bandage'],
        'ifaks'      => (int)$row['qty_ifaks'],
        'painkiller' => (int)$row['qty_painkiller'],
    ];

    if ($displayName !== '') {
        if (!isset($dailyDetailByNameJS[$displayName])) {
            $dailyDetailByNameJS[$displayName] = [];
        }
        $dailyDetailByNameJS[$displayName][] = [
            'consumer_name' => $displayName,
            'medic'      => $row['medic_name'],
            'package'    => $row['package_name'],
            'time'       => formatTanggalID($row['created_at']),
            'bandage'    => (int)$row['qty_bandage'],
            'ifaks'      => (int)$row['qty_ifaks'],
            'painkiller' => (int)$row['qty_painkiller'],
        ];
        $todayConsumerNamesJS[$displayName] = $displayName;
    }
}

// Ambil data transaksi sesuai filter tanggal.
// Default: hanya transaksi milik medis aktif (session).
// Jika ?show_all=1 → tampilkan semua medis.
$sqlSales = "
    SELECT * FROM sales
    WHERE created_at >= :start
      AND created_at <  DATE_ADD(:end, INTERVAL 1 SECOND)
      " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
";

$paramsSales = [
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
];
if ($salesHasUnitCode) {
    $paramsSales[':unit_code'] = $effectiveUnit;
}

$showAll = isset($_GET['show_all']) && $_GET['show_all'] === '1';

// Kalau TIDAK show_all dan ada medis aktif → filter berdasarkan medic_name
if (!$showAll && $medicName !== '') {
    $sqlSales .= " AND medic_name = :mname";
    $paramsSales[':mname'] = $medicName;
}

$sqlSales .= " ORDER BY created_at DESC";

$stmtSales = $pdo->prepare($sqlSales);
$stmtSales->execute($paramsSales);
$filteredSales = $stmtSales->fetchAll(PDO::FETCH_ASSOC);

$consumerIdentityPhotoMap = [];
$identityMasterReady = ems_table_exists($pdo, 'identity_master');

if ($identityMasterReady && !empty($filteredSales)) {
    $citizenIdsForLookup = [];

    foreach ($filteredSales as $saleRow) {
        $rawConsumerName = trim((string)($saleRow['consumer_name'] ?? ''));
        if (!ems_looks_like_citizen_id($rawConsumerName)) {
            continue;
        }

        $normalizedCitizenId = ems_normalize_citizen_id($rawConsumerName);
        if ($normalizedCitizenId !== '') {
            $citizenIdsForLookup[$normalizedCitizenId] = $normalizedCitizenId;
        }
    }

    if ($citizenIdsForLookup !== []) {
        $placeholders = implode(',', array_fill(0, count($citizenIdsForLookup), '?'));
        $stmtIdentityLookup = $pdo->prepare("
            SELECT id, citizen_id, image_path
            FROM identity_master
            WHERE citizen_id IN ($placeholders)
        ");
        $stmtIdentityLookup->execute(array_values($citizenIdsForLookup));

        foreach ($stmtIdentityLookup->fetchAll(PDO::FETCH_ASSOC) as $identityRow) {
            $normalizedCitizenId = ems_normalize_citizen_id((string)($identityRow['citizen_id'] ?? ''));
            if ($normalizedCitizenId === '') {
                continue;
            }

            $consumerIdentityPhotoMap[$normalizedCitizenId] = [
                'identity_id' => (int)($identityRow['id'] ?? 0),
                'has_photo' => trim((string)($identityRow['image_path'] ?? '')) !== '',
            ];
        }
    }
}

// ======================================================
// EXPORT EXCEL (FINAL - SATU KALI SAJA)
// ======================================================

// =====================
// SET NAMA FILE EXPORT
// =====================
$fileName = 'rekap_farmasi_' . date('Ymd_His') . '.xls';

// =====================
// SUMBER DATA EXPORT
// =====================
$rowsExport = $filteredSales;

// Rekapan bonus: selalu berdasarkan medis aktif (session)
$singleMedicStats = null;

if ($medicName !== '') {
    $stmtSingle = $pdo->prepare("
        SELECT
            medic_user_id,
            MAX(medic_name) AS medic_name,
            MAX(medic_jabatan) AS medic_jabatan,
            COUNT(*) AS total_transaksi,
            SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
            SUM(price) AS total_harga,
            FLOOR(SUM(price) * 0.4) AS bonus_40
        FROM sales
        WHERE created_at BETWEEN :start AND :end
        " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
        AND medic_user_id = :uid
        GROUP BY medic_user_id
        LIMIT 1
    ");
    $singleParams = [
        ':start' => $rangeStart,
        ':end'   => $rangeEnd,
        ':uid'   => $_SESSION['user_rh']['id'],
    ];
    if ($salesHasUnitCode) {
        $singleParams[':unit_code'] = $effectiveUnit;
    }
    $stmtSingle->execute($singleParams);
    $singleMedicStats = $stmtSingle->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ======================================================
// TOTAL TRANSAKSI HARI INI (KHUSUS TODAY, TIDAK TERPENGARUH FILTER)
// ======================================================
$todayStats = null;

if (!empty($_SESSION['user_rh']['id'])) {
    $stmtToday = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_transaksi,
            SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
            COALESCE(SUM(price),0) AS total_harga,
            FLOOR(COALESCE(SUM(price),0) * 0.4) AS bonus_40
        FROM sales
        WHERE DATE(created_at) = CURDATE()
          AND medic_user_id = :uid
          " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
    ");
    $todayParams = [':uid' => $_SESSION['user_rh']['id']];
    if ($salesHasUnitCode) {
        $todayParams[':unit_code'] = $effectiveUnit;
    }
    $stmtToday->execute($todayParams);

    $todayStats = $stmtToday->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ======================================================
// KONSUMEN TERATAS (MINGGUAN / BULANAN / 3 BULAN)
// ======================================================
$topConsumerPeriods = [
    'weekly' => [
        'label' => 'Mingguan',
        'sql' => "YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)",
    ],
    'monthly' => [
        'label' => 'Bulanan',
        'sql' => "YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())",
    ],
    'quarter' => [
        'label' => '3 Bulan',
        'sql' => "created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)",
    ],
];

$topConsumerStats = [];
foreach ($topConsumerPeriods as $periodKey => $period) {
    $sql = "
        SELECT
            consumer_name,
            COUNT(*) AS total_transaksi,
            COALESCE(SUM(price), 0) AS total_belanja,
            COALESCE(SUM(qty_bandage), 0) AS total_bandage,
            COALESCE(SUM(qty_ifaks), 0) AS total_ifaks,
            COALESCE(SUM(qty_painkiller), 0) AS total_painkiller,
            MAX(created_at) AS last_purchase_at
        FROM sales
        WHERE consumer_name IS NOT NULL
          AND consumer_name <> ''
          AND {$period['sql']}
          " . ($salesHasUnitCode ? " AND unit_code = :unit_code" : "") . "
        GROUP BY consumer_name
        ORDER BY total_transaksi DESC, total_belanja DESC, last_purchase_at DESC
        LIMIT 1
    ";

    $stmtTopConsumer = $pdo->prepare($sql);
    $paramsTopConsumer = [];
    if ($salesHasUnitCode) {
        $paramsTopConsumer[':unit_code'] = $effectiveUnit;
    }
    $stmtTopConsumer->execute($paramsTopConsumer);
    $rowTopConsumer = $stmtTopConsumer->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($rowTopConsumer) {
        $displayName = trim((string)($rowTopConsumer['consumer_name'] ?? ''));
        if (ems_looks_like_citizen_id($displayName)) {
            $displayName = ems_normalize_citizen_id($displayName);
        }

        $topConsumerStats[] = [
            'label' => $period['label'],
            'consumer_name' => $displayName !== '' ? $displayName : '-',
            'total_transaksi' => (int)($rowTopConsumer['total_transaksi'] ?? 0),
            'total_belanja' => (int)($rowTopConsumer['total_belanja'] ?? 0),
            'total_bandage' => (int)($rowTopConsumer['total_bandage'] ?? 0),
            'total_ifaks' => (int)($rowTopConsumer['total_ifaks'] ?? 0),
            'total_painkiller' => (int)($rowTopConsumer['total_painkiller'] ?? 0),
            'last_purchase_at' => (string)($rowTopConsumer['last_purchase_at'] ?? ''),
        ];
    } else {
        $topConsumerStats[] = [
            'label' => $period['label'],
            'consumer_name' => '-',
            'total_transaksi' => 0,
            'total_belanja' => 0,
            'total_bandage' => 0,
            'total_ifaks' => 0,
            'total_painkiller' => 0,
            'last_purchase_at' => '',
        ];
    }
}

// Untuk form custom date supaya tetap isi
$fromDateInput = ($range === 'custom' && $startDT instanceof DateTime)
    ? $startDT->format('Y-m-d')
    : '';

$toDateInput = ($range === 'custom' && $endDT instanceof DateTime)
    ? $endDT->format('Y-m-d')
    : '';

$canAcknowledgeIncomingLetter = ems_is_letter_receiver_role($medicRole);
$incomingLetterAlerts = [];
$incomingLetterAlertCount = 0;

try {
    $stmtIncomingLetters = $pdo->query("
        SELECT
            id,
            letter_code,
            institution_name,
            sender_name,
            sender_phone,
            meeting_topic,
            appointment_date,
            appointment_time,
            target_user_id,
            target_name_snapshot,
            target_role_snapshot,
            submitted_at
        FROM incoming_letters
        WHERE status = 'unread'
        ORDER BY submitted_at DESC
        LIMIT 5
    ");
    $incomingLetterAlerts = $stmtIncomingLetters->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $incomingLetterAlertCount = (int)$pdo->query("
        SELECT COUNT(*)
        FROM incoming_letters
        WHERE status = 'unread'
    ")->fetchColumn();
} catch (Throwable $e) {
    $incomingLetterAlerts = [];
    $incomingLetterAlertCount = 0;
}

?>
<?php
$pageTitle = 'Rekap Farmasi EMS';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<style>
    .farmasi-quiz-card {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(14, 165, 233, 0.18);
        background:
            radial-gradient(circle at top right, rgba(14, 165, 233, 0.14), transparent 36%),
            radial-gradient(circle at bottom left, rgba(16, 185, 129, 0.12), transparent 28%),
            #ffffff;
    }

    .farmasi-quiz-layout {
        display: grid;
        gap: 16px;
        grid-template-columns: minmax(0, 1.55fr) minmax(280px, 0.95fr);
    }

    .farmasi-quiz-panel,
    .farmasi-quiz-side-card {
        border: 1px solid rgba(148, 163, 184, 0.24);
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.92);
        padding: 16px;
    }

    .farmasi-quiz-header-row,
    .farmasi-quiz-status-row,
    .farmasi-quiz-action-row,
    .farmasi-quiz-summary-grid,
    .farmasi-quiz-ranking-item,
    .farmasi-quiz-history-item {
        display: flex;
        gap: 12px;
    }

    .farmasi-quiz-header-row,
    .farmasi-quiz-status-row,
    .farmasi-quiz-action-row,
    .farmasi-quiz-ranking-item,
    .farmasi-quiz-history-item {
        align-items: center;
        justify-content: space-between;
    }

    .farmasi-quiz-status-row,
    .farmasi-quiz-summary-grid,
    .farmasi-quiz-answer-list,
    .farmasi-quiz-side-stack {
        display: grid;
        gap: 12px;
    }

    .farmasi-quiz-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border-radius: 999px;
        padding: 8px 12px;
        background: rgba(15, 23, 42, 0.05);
        color: #0f172a;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .farmasi-quiz-badge.quiz-live {
        background: rgba(14, 165, 233, 0.14);
        color: #0369a1;
    }

    .farmasi-quiz-badge.quiz-pass {
        background: rgba(16, 185, 129, 0.16);
        color: #047857;
    }

    .farmasi-quiz-badge.quiz-fail {
        background: rgba(239, 68, 68, 0.14);
        color: #b91c1c;
    }

    .farmasi-quiz-meta {
        color: #64748b;
        font-size: 12px;
        line-height: 1.6;
    }

    .farmasi-quiz-question {
        margin-top: 12px;
        margin-bottom: 14px;
        color: #0f172a;
        font-size: 18px;
        font-weight: 700;
        line-height: 1.45;
    }

    .farmasi-quiz-answer-btn {
        width: 100%;
        min-height: 88px;
        border: 1px solid rgba(148, 163, 184, 0.3);
        border-radius: 16px;
        padding: 14px 16px;
        text-align: left;
        background: #fff;
        color: #0f172a;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
    }

    .farmasi-quiz-answer-btn:hover {
        transform: translateY(-1px);
        border-color: rgba(14, 165, 233, 0.44);
        box-shadow: 0 12px 24px rgba(14, 165, 233, 0.08);
    }

    .farmasi-quiz-answer-btn.is-locked {
        cursor: default;
        pointer-events: none;
    }

    .farmasi-quiz-answer-btn.is-correct {
        border-color: rgba(22, 163, 74, 0.45);
        background: rgba(220, 252, 231, 0.92);
        color: #166534;
    }

    .farmasi-quiz-answer-btn.is-wrong {
        border-color: rgba(239, 68, 68, 0.42);
        background: rgba(254, 226, 226, 0.96);
        color: #991b1b;
    }

    .farmasi-quiz-answer-head {
        display: flex;
        align-items: flex-start;
        justify-content: flex-start;
        gap: 12px;
        width: 100%;
        text-align: left;
    }

    .farmasi-quiz-answer-list {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        grid-template-rows: repeat(2, minmax(0, auto));
        grid-auto-flow: column;
    }

    .farmasi-quiz-answer-text {
        flex: 1 1 auto;
        min-width: 0;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
        line-height: 1.5;
        text-align: left;
    }

    .farmasi-quiz-answer-letter {
        flex: 0 0 auto;
        width: 34px;
        height: 34px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, 0.08);
        font-weight: 800;
        text-transform: uppercase;
    }

    .farmasi-quiz-feedback {
        margin-top: 14px;
        border-radius: 16px;
        padding: 14px 16px;
        font-size: 13px;
        line-height: 1.6;
    }

    .farmasi-quiz-feedback.is-correct {
        background: rgba(220, 252, 231, 0.9);
        color: #166534;
    }

    .farmasi-quiz-feedback.is-wrong {
        background: rgba(254, 226, 226, 0.95);
        color: #991b1b;
    }

    .farmasi-quiz-summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-top: 16px;
    }

    .farmasi-quiz-summary-box {
        border-radius: 18px;
        padding: 14px;
        background: rgba(248, 250, 252, 0.95);
        border: 1px solid rgba(148, 163, 184, 0.24);
    }

    .farmasi-quiz-summary-box strong {
        display: block;
        margin-top: 8px;
        font-size: 22px;
        color: #0f172a;
    }

    .farmasi-quiz-ranking-list,
    .farmasi-quiz-history-list {
        display: grid;
        gap: 10px;
        margin-top: 12px;
        max-height: 440px;
        overflow-y: auto;
        padding-right: 6px;
    }

    .farmasi-quiz-ranking-list::-webkit-scrollbar,
    .farmasi-quiz-history-list::-webkit-scrollbar {
        width: 8px;
    }

    .farmasi-quiz-ranking-list::-webkit-scrollbar-thumb,
    .farmasi-quiz-history-list::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.45);
        border-radius: 999px;
    }

    .farmasi-quiz-ranking-list::-webkit-scrollbar-track,
    .farmasi-quiz-history-list::-webkit-scrollbar-track {
        background: rgba(226, 232, 240, 0.45);
        border-radius: 999px;
    }

    .farmasi-quiz-ranking-item,
    .farmasi-quiz-history-item {
        padding: 12px 14px;
        border-radius: 16px;
        background: rgba(248, 250, 252, 0.95);
        border: 1px solid rgba(148, 163, 184, 0.2);
    }

    .farmasi-quiz-rank-bullet {
        width: 34px;
        height: 34px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(14, 165, 233, 0.12);
        color: #0369a1;
        font-weight: 800;
        flex: 0 0 auto;
    }

    .farmasi-quiz-empty {
        border-radius: 16px;
        padding: 16px;
        background: rgba(248, 250, 252, 0.9);
        color: #64748b;
        font-size: 13px;
        text-align: center;
    }

    .farmasi-quiz-fireworks {
        pointer-events: none;
        position: absolute;
        inset: 0;
        overflow: hidden;
    }

    .farmasi-quiz-fireworks span {
        position: absolute;
        width: 10px;
        height: 10px;
        border-radius: 999px;
        opacity: 0;
        animation: quizFirework 1.8s ease-out forwards;
    }

    @keyframes quizFirework {
        0% {
            transform: translate3d(0, 0, 0) scale(0.4);
            opacity: 0;
        }

        14% {
            opacity: 1;
        }

        100% {
            transform: translate3d(var(--tx), var(--ty), 0) scale(1.2);
            opacity: 0;
        }
    }

    @media (max-width: 1120px) {
        .farmasi-quiz-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .farmasi-quiz-answer-list {
            grid-template-columns: 1fr;
            grid-template-rows: none;
            grid-auto-flow: row;
        }

        .farmasi-quiz-card .farmasi-card-header,
        .farmasi-quiz-header-row,
        .farmasi-quiz-status-row,
        .farmasi-quiz-action-row,
        .farmasi-quiz-ranking-item,
        .farmasi-quiz-history-item {
            align-items: flex-start;
            flex-direction: column;
        }

        .farmasi-quiz-summary-grid {
            grid-template-columns: 1fr;
        }

        .farmasi-quiz-question {
            font-size: 16px;
        }
    }
</style>
<section class="content">
    <!-- ===== CONTENT ===== -->
    <div class="page page-shell">

        <h1 class="page-title">Rekap Farmasi EMS </h1>

        <p class="section-intro">
            Input penjualan Bandage / IFAKS / Painkiller dengan batas harian per konsumen.
        </p>

        <!-- NOTIFIKASI -->
        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>
        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($w) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <?php if (!empty($incomingLetterAlerts)): ?>
            <div class="card card-section">
                <div class="card-header-between">
                    <div>
                        <div class="card-header">Surat Masuk Belum Dibaca</div>
                        <p class="muted-copy-tight">
                            Ada <strong><?= (int)$incomingLetterAlertCount ?></strong> surat masuk. Medis lain bisa bantu menginfokan ke manager.
                        </p>
                    </div>
                    <?php if ($canAcknowledgeIncomingLetter): ?>
                        <a href="surat_menyurat.php" class="btn-secondary">
                            <?= ems_icon('document-text', 'h-4 w-4') ?> <span>Lihat Semua</span>
                        </a>
                    <?php else: ?>
                        <span class="badge-counter">Info Manager</span>
                    <?php endif; ?>
                </div>

                <div class="space-y-2 mt-3" style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($incomingLetterAlerts as $letter): ?>
                        <div class="rounded-xl border border-amber-500 bg-amber-50 px-4 py-3.5">
                            <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <strong class="text-amber-700"><?= htmlspecialchars((string)$letter['institution_name']) ?></strong>
                                        <span class="meta-text-xs"><?= htmlspecialchars((string)$letter['letter_code']) ?></span>
                                    </div>
                                    <div class="text-sm text-amber-900 mt-1"><?= htmlspecialchars((string)$letter['meeting_topic']) ?></div>
                                    <div class="meta-text-xs mt-1">
                                        Pengirim: <strong><?= htmlspecialchars((string)$letter['sender_name']) ?></strong> · <?= htmlspecialchars((string)$letter['sender_phone']) ?>
                                    </div>
                                    <div class="meta-text-xs">
                                        Jadwal: <strong><?= htmlspecialchars((string)$letter['appointment_date']) ?></strong> <?= htmlspecialchars(substr((string)$letter['appointment_time'], 0, 5)) ?> WIB ·
                                        Tujuan: <strong><?= htmlspecialchars((string)$letter['target_name_snapshot']) ?></strong> (<?= htmlspecialchars((string)$letter['target_role_snapshot']) ?>)
                                    </div>
                                    <div class="meta-text-xs">
                                        Tanggal Dibuat: <?= htmlspecialchars((string)$letter['submitted_at']) ?>
                                    </div>
                                </div>

                                <?php if ($canAcknowledgeIncomingLetter): ?>
                                    <div class="action-row-nowrap md:justify-end">
                                        <form method="POST" action="surat_menyurat_action.php" class="inline">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="mark_incoming_read">
                                            <input type="hidden" name="letter_id" value="<?= (int)$letter['id'] ?>">
                                            <input type="hidden" name="redirect_to" value="rekap_farmasi.php">
                                            <button type="submit" class="btn-success action-icon-btn" title="Tandai surat sebagai dibaca" aria-label="Tandai surat sebagai dibaca">
                                                <?= ems_icon('check-circle', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Card Petugas Medis (hanya muncul jika BELUM ada petugas) -->

        <?php if ($medicName): ?>
            <div id="farmasiQuizTopAnchor"></div>
            <div class="card farmasi-card farmasi-quiz-card" id="farmasiQuizCard" style="display: none;">
                <div class="farmasi-card-header">
                    <div class="farmasi-quiz-header-row">
                        <div>
                            <h2 class="farmasi-card-title">Quiz EMS Mingguan</h2>
                            <p class="farmasi-card-subtitle">10 soal per sesi, 2 sesi per hari pada jam 06:00 dan 18:00 WIB, ranking reset mingguan seperti gaji farmasi.</p>
                        </div>
                        <div class="farmasi-quiz-badge" id="farmasiQuizWeekLabel">Memuat season...</div>
                    </div>
                </div>
                <div class="farmasi-card-content">
                    <div class="farmasi-quiz-layout">
                        <div class="farmasi-quiz-panel">
                            <div class="farmasi-quiz-status-row">
                                <div class="farmasi-quiz-badge quiz-live" id="farmasiQuizStatusBadge">Menyiapkan quiz...</div>
                                <div class="farmasi-quiz-meta" id="farmasiQuizTimerText">Memuat timer quiz...</div>
                            </div>
                            <div id="farmasiQuizStage" class="mt-4"></div>
                        </div>
                        <div class="farmasi-quiz-side-stack">
                            <div class="farmasi-quiz-side-card">
                                <div class="farmasi-quiz-header-row">
                                    <strong class="text-slate-900">Ranking Mingguan</strong>
                                    <span class="farmasi-quiz-meta">Visible untuk semua</span>
                                </div>
                                <div id="farmasiQuizRanking" class="farmasi-quiz-ranking-list"></div>
                            </div>
                            <div class="farmasi-quiz-side-card">
                                <div class="farmasi-quiz-header-row">
                                    <strong class="text-slate-900">History Season</strong>
                                    <span class="farmasi-quiz-meta">Pemenang tersimpan</span>
                                </div>
                                <div id="farmasiQuizHistory" class="farmasi-quiz-history-list"></div>
                            </div>
                        </div>
                    </div>
                    <div id="farmasiQuizFireworks" class="farmasi-quiz-fireworks" aria-hidden="true"></div>
                </div>
            </div>

            <!-- Card Input Transaksi -->
            <div class="card farmasi-card" id="farmasiInputCard">
                <div class="farmasi-card-header">
                    <h2 class="farmasi-card-title">Input Transaksi</h2>
                    <p class="farmasi-card-subtitle">Form transaksi farmasi aktif.</p>
                </div>
                <div class="farmasi-card-content">

                    <?php if ($medicName): ?>
                        <?php
                        // Ambil status farmasi (aman, fallback offline)
                        $stmt = $pdo->prepare("
                        SELECT status 
                        FROM user_farmasi_status 
                        WHERE user_id = ?
                    ");
                        $stmt->execute([$_SESSION['user_rh']['id']]);
                        $statusFarmasi = $stmt->fetchColumn() ?: 'offline';
                        $isOnline = $statusFarmasi === 'online';
                        ?>

                        <div class="medic-info">
                            <div class="medic-name">
                                Anda telah login sebagai
                                <strong><?= htmlspecialchars($medicName) ?></strong>
                                <span class="medic-role">• <?= htmlspecialchars($medicRoleLabel) ?></span>
                                <span class="medic-role">• <?= htmlspecialchars($medicDivisionLabel) ?></span>
                                <span class="medic-role">• <?= htmlspecialchars($medicJabatan) ?></span>
                            </div>

                            <div class="medic-status">
                                <span id="farmasiStatusBadge"
                                    data-status="<?= $isOnline ? 'online' : 'offline' ?>"
                                    class="status-badge <?= $isOnline ? 'status-online' : 'status-offline' ?> status-clickable"
                                    title="Klik untuk ubah status">
                                    <span class="dot"></span>
                                    <span id="farmasiStatusText">
                                        <?= $isOnline ? ' ONLINE' : ' OFFLINE' ?>
                                    </span>
                                </span>
                            </div>
                        </div>

                        <!-- <div id="dailyNotice" class="info-notice">
                        <strong>Informasi:</strong><br>
                        <strong>1 konsumen / pasien hanya diperbolehkan melakukan 1 transaksi dalam 1 hari.</strong><br>
                        Jika pasien menyatakan belum pernah membeli hari ini,
                        <em>kemungkinan nama pasien telah digunakan oleh temannya atau orang lain</em>.
                        Mohon lakukan konfirmasi berdasarkan
                        <a href="/dashboard/konsumen.php"
                            target="_blank"
                            class="info-link">
                            riwayat transaksi
                        </a>
                        yang ditampilkan oleh sistem.
                    </div> -->
                    <?php endif; ?>

                    <!-- NOTICE COOLDOWN GLOBAL (REALTIME) -->
                    <div id="cooldownNotice" class="notice-box notice-info">
                    </div>

                    <?php if ($profileIncompleteForFarmasi): ?>
                        <div id="profileRequirementNotice" class="notice-box notice-danger" style="display: block;">
                            <strong>Data profil belum lengkap</strong><br><br>
                            Transaksi farmasi belum bisa disimpan karena data berikut belum diisi:
                            <strong><?= htmlspecialchars(implode(', ', $missingProfileFields)) ?></strong>.<br>
                            Silakan lengkapi data tersebut di <strong>Setting Akun</strong> terlebih dahulu.
                        </div>
                    <?php endif; ?>

                    <!-- NOTICE FAIRNESS (GLOBAL, TIDAK TERPENGARUH INPUT) -->
                    <div id="fairnessNotice" class="notice-box notice-warning">
                    </div>

                    <!-- NOTICE KONSUMEN (LOKAL, BERDASARKAN INPUT CITIZEN ID) -->
                    <?php
                    ems_component('ui/consumer-merge-notice', [
                        'id' => 'consumerNotice',
                        'variant' => 'danger',
                    ]);
                    ?>
                    <div id="blacklistNotice" class="notice-box notice-danger" style="display:none;"></div>

                    <?php
                    // ===============================
                    // IDEMPOTENCY TOKEN (ANTI DOUBLE)
                    // ===============================
                    if (empty($_SESSION['tx_token'])) {
                        $_SESSION['tx_token'] = bin2hex(random_bytes(32));
                    }
                    ?>

                    <form method="post" id="saleForm">
                        <input type="hidden" name="auto_merge" id="auto_merge" value="0">
                        <input type="hidden" name="merge_targets" id="merge_targets">
                        <input type="hidden" name="action" value="add_sale">
                        <input type="hidden" name="tx_token" value="<?= $_SESSION['tx_token'] ?>">
                        <!-- Tambahan: flag untuk override batas harian -->
                        <input type="hidden" name="force_overlimit" id="force_overlimit" value="0">
                        <input type="hidden" name="identity_id" id="identity_id" value="">
                        <input type="hidden" id="ocr_citizen_id" value="">
                        <input type="hidden" id="ocr_first_name" value="">
                        <input type="hidden" id="ocr_last_name" value="">
                        <div class="row-form-2">
                            <div class="col">
                                <label for="consumerNameInput"><?= htmlspecialchars($consumerIdentifierLabel) ?></label>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input
                                        type="text"
                                        name="consumer_name"
                                        id="consumerNameInput"
                                        list="consumer-list"
                                        placeholder="RH39IQLC"
                                        autocomplete="off"
                                        autocapitalize="characters"
                                        spellcheck="false"
                                        style="text-transform: uppercase;flex:1;"
                                        required>
                                    <button
                                        type="button"
                                        class="btn-secondary"
                                        onclick="openIdentityScan()"
                                        title="Foto KTP untuk deteksi otomatis">
                                        <?= ems_icon('camera', 'h-4 w-4') ?>
                                    </button>
                                </div>
                                <div id="ocrIdentityInfo" class="notice-box notice-info" style="display:none;margin-top:8px;">
                                    <strong>Hasil scan KTP</strong><br>
                                    <span id="ocrIdentityName">-</span><br>
                                    <span id="ocrIdentityCitizenId">-</span>
                                </div>
                                <div id="similarConsumerBox" class="consumer-similar-box">
                                </div>
                                <datalist id="consumer-list">
                                    <?php foreach ($consumerNames as $cn): ?>
                                        <option value="<?= htmlspecialchars($cn) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <small>
                                    Tombol kamera bersifat opsional. Bisa scan/foto KTP untuk isi otomatis, atau tetap ketik manual Citizen ID.
                                </small>
                                <small>
                                    Input wajib memakai Citizen ID Konsumen, contoh <strong>RH39IQLC</strong>. Sistem tidak lagi memakai nama konsumen untuk Alta maupun Roxwood.
                                </small>
                            </div>
                            <div class="col">
                                <label for="pkg_main">Pilihan Paket</label>
                                <select name="package_main" id="pkg_main">
                                    <option value="">-- Tidak Pakai Paket --</option>
                                    <?php foreach ($paketAB as $pkg): ?>
                                        <option value="<?= (int)$pkg['id'] ?>">
                                            <?= htmlspecialchars($pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="custom">Paket Custom</option>
                                </select>
                                <small>Pilih paket combo <?= htmlspecialchars($comboPackageModeLabel) ?> atau gunakan Paket Custom untuk memilih item satu per satu.</small>
                            </div>
                        </div>

                        <div id="consumerMergeModal" class="modal-overlay hidden">
                            <div class="modal-box modal-shell max-w-2xl">
                                <div class="modal-head px-5 py-4">
                                    <div class="modal-title inline-flex items-center gap-2">
                                        <?= ems_icon('users', 'h-5 w-5') ?> <span>Informasi Citizen ID</span>
                                    </div>
                                    <button type="button" id="closeConsumerMergeModal" class="btn-danger btn-compact">
                                        <?= ems_icon('x-mark', 'h-4 w-4') ?> <span>Tutup</span>
                                    </button>
                                </div>
                                <div class="modal-content p-5">
                                    <p class="meta-text mb-3">
                                        Fitur merge nama sudah dimatikan karena transaksi farmasi sekarang memakai Citizen ID Konsumen.
                                    </p>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 mb-4">
                                        <div class="meta-text-xs"><?= htmlspecialchars($consumerIdentifierLabel) ?> yang akan dipakai</div>
                                        <div id="mergeConsumerTargetName" class="text-base font-semibold text-slate-800">-</div>
                                    </div>
                                    <div id="mergeConsumerCandidateList" class="flex flex-wrap gap-2"></div>
                                    <div class="modal-actions mt-5">
                                        <button type="button" id="cancelConsumerMergeBtn" class="btn-secondary">Batal</button>
                                        <button type="button" id="confirmConsumerMergeBtn" class="btn-success">Lanjut Simpan</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row-form-2 hidden" id="customPackageRow">
                            <div class="col">
                                <label for="pkg_bandage">Paket Bandage</label>
                                <select name="package_bandage" id="pkg_bandage">
                                    <option value="">-- Tidak pilih paket Bandage --</option>
                                    <?php foreach ($bandagePackages as $pkg): ?>
                                        <option value="<?= (int)$pkg['id'] ?>">
                                            <?= htmlspecialchars($pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col">
                                <label for="pkg_ifaks">Paket IFAKS</label>
                                <select name="package_ifaks" id="pkg_ifaks">
                                    <option value="">-- Tidak pilih paket IFAKS --</option>
                                    <?php foreach ($ifaksPackages as $pkg): ?>
                                        <option value="<?= (int)$pkg['id'] ?>">
                                            <?= htmlspecialchars($pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col">
                                <label for="pkg_painkiller">Paket Painkiller</label>
                                <select name="package_painkiller" id="pkg_painkiller">
                                    <option value="">-- Tidak pilih paket Painkiller --</option>
                                    <?php foreach ($painkillerPackages as $pkg): ?>
                                        <option value="<?= (int)$pkg['id'] ?>">
                                            <?= htmlspecialchars($pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="total-item-info bg-white">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Ringkasan Item</div>
                                    <div class="meta-text-xs">Jumlah item dan bonus akan berubah otomatis sesuai mode paket aktif.</div>
                                </div>
                                <span class="badge-counter">Bonus 40%: <span id="totalBonus">0</span></span>
                            </div>
                            <div class="grid gap-2 mt-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                                    <div class="meta-text-xs">Bandage</div>
                                    <div class="font-semibold text-slate-900"><span id="totalBandage">0</span> pcs</div>
                                    <div class="meta-text-xs">Harga satuan: <span id="priceBandage">-</span>/pcs</div>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                                    <div class="meta-text-xs">IFAKS</div>
                                    <div class="font-semibold text-slate-900"><span id="totalIfaks">0</span> pcs</div>
                                    <div class="meta-text-xs">Harga satuan: <span id="priceIfaks">-</span>/pcs</div>
                                </div>
                                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                                    <div class="meta-text-xs">Painkiller</div>
                                    <div class="font-semibold text-slate-900"><span id="totalPainkiller">0</span> pcs</div>
                                    <div class="meta-text-xs">Harga satuan: <span id="pricePainkiller">-</span>/pcs</div>
                                </div>
                                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-3">
                                    <div class="meta-text-xs text-emerald-700">Mode Paket Aktif</div>
                                    <div class="font-semibold text-emerald-700" id="activePackageLabel"><?= htmlspecialchars($comboPackageModeLabel) ?></div>
                                    <div class="meta-text-xs text-emerald-700">Gunakan Paket Custom untuk memilih item satu per satu.</div>
                                </div>
                            </div>
                        </div>

                        <!-- DISPLAY KASIR: Total Harga besar -->
                        <div class="total-display border border-emerald-200 bg-emerald-50 text-emerald-700">
                            <div class="flex flex-wrap items-end justify-between gap-3">
                                <div>
                                    <div class="total-display-label text-emerald-700">Total yang harus dibayar</div>
                                    <div class="meta-text-xs text-emerald-700">Nominal akhir transaksi yang siap dikonfirmasi.</div>
                                </div>
                                <div class="total-amount mt-0 text-emerald-700" id="totalPriceDisplay">$ 0</div>
                            </div>
                        </div>

                </div>

                <div class="farmasi-card-footer">
                    <div class="action-row-wrap">
                        <button type="button" id="btnSubmit" class="btn-success<?= $profileIncompleteForFarmasi ? ' btn-disabled' : '' ?>" onclick="handleSaveClick();" <?= $profileIncompleteForFarmasi ? 'disabled' : '' ?>>
                            Simpan Transaksi
                        </button>

                        <button type="button" class="btn-secondary" onclick="clearFormInputs();">
                            Bersihkan
                        </button>
                    </div>
                </div>
                </form>

            </div>
            <div id="farmasiQuizBottomAnchor"></div>

            <!-- Aktivitas + Medis Online (fokus setelah input) -->
            <div class="farmasi-side-grid">
                <div class="activity-feed-container">

                    <div class="activity-feed-card farmasi-card">
                        <div class="farmasi-card-header">
                            <h3 class="farmasi-card-title">Aktivitas</h3>
                        </div>
                        <div class="farmasi-card-content">
                            <div class="activity-feed-list" id="activityFeedList"></div>
                        </div>
                    </div>
                </div>

                <div class="card card-online-medics farmasi-card">
                    <div class="farmasi-card-header">
                        <div class="card-header-between">
                            <h3 class="farmasi-card-title">Medis Online</h3>
                            <span id="totalMedicsBadge" class="badge-counter">0 orang</span>
                        </div>
                        <p class="farmasi-card-subtitle">Prioritas penjualan paling sedikit di urutan atas.</p>
                    </div>

                    <div class="farmasi-card-content">
                        <div class="online-medics-list" id="onlineMedicsContainer">

                            <?php if (empty($onlineMedics)): ?>

                                <p class="meta-text">
                                    Tidak ada medis yang sedang online.
                                </p>

                            <?php else: ?>

                                <?php foreach ($onlineMedics as $m): ?>
                                    <div class="online-medic-row">
                                        <div class="online-medic-main">
                                            <div class="online-medic-head">
                                                <div class="online-medic-identity">
                                                    <strong><?= htmlspecialchars($m['medic_name']) ?></strong>
                                                    <div class="online-medic-subtitle">
                                                        <?= htmlspecialchars($m['medic_position_label']) ?> •
                                                        <?= htmlspecialchars($m['medic_role_label']) ?> •
                                                        <?= htmlspecialchars($m['medic_division_label']) ?>
                                                    </div>
                                                </div>
                                                <div class="online-medic-badges">
                                                    <span class="weekly-badge">Minggu ini: <?= (int)$m['weekly_transaksi'] ?> trx</span>
                                                    <span class="weekly-badge weekly-badge-muted">Batch <?= !empty($m['medic_batch']) ? (int)$m['medic_batch'] : '-' ?></span>
                                                </div>
                                            </div>

                                            <div class="online-medic-inline-meta">
                                                <span><strong>Join:</strong> <?= htmlspecialchars($m['join_duration_text']) ?></span>
                                                <span><strong>Online:</strong>
                                                    <strong class="weekly-online"
                                                        data-seconds="<?= (int)($m['weekly_online_seconds'] ?? 0) ?>"
                                                        data-user-id="<?= (int)$m['user_id'] ?>">
                                                        <?= htmlspecialchars($m['weekly_online_text'] ?? '0j 0m') ?>
                                                    </strong>
                                                </span>
                                            </div>

                                            <button
                                                type="button"
                                                class="btn-force-offline"
                                                data-user-id="<?= (int)$m['user_id'] ?>"
                                                data-name="<?= htmlspecialchars($m['medic_name']) ?>"
                                                data-jabatan="<?= htmlspecialchars($m['medic_position_label']) ?>">
                                                <?= ems_icon('exclamation-triangle', 'h-4 w-4') ?> Force Offline
                                            </button>
                                        </div>

                                        <div class="online-medic-stats">
                                            <div class="online-medic-stat-card">
                                                <span class="online-medic-stat-label">Transaksi Hari Ini</span>
                                                <strong class="tx"><?= (int)$m['total_transaksi'] ?> trx</strong>
                                            </div>
                                            <div class="online-medic-stat-card">
                                                <span class="online-medic-stat-label">Total Seluruh Transaksi</span>
                                                <strong><?= (int)($m['total_transaksi_semua'] ?? 0) ?> trx</strong>
                                            </div>
                                            <div class="online-medic-stat-card">
                                                <span class="online-medic-stat-label">Pendapatan Hari Ini</span>
                                                <strong class="amount"><?= dollar((int)$m['total_pendapatan']) ?></strong>
                                            </div>
                                            <div class="online-medic-stat-card online-medic-stat-card-success">
                                                <span class="online-medic-stat-label">Bonus Hari Ini</span>
                                                <strong class="bonus text-success-xs"><?= dollar((int)$m['bonus_40']) ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                            <?php endif; ?>

                        </div>
                    </div>

                </div>
            </div>

            <div class="farmasi-summary-grid">
                <!-- TOTAL TRANSAKSI HARI INI -->
                <div class="card mb-0 farmasi-card">
                    <div class="farmasi-card-header">
                        <h3 class="farmasi-card-title">Rekap Hari Ini</h3>
                        <p class="farmasi-card-subtitle">Ringkasan transaksi hari ini.</p>
                    </div>
                    <div class="farmasi-card-content">
                        <?php if ($todayStats && $todayStats['total_transaksi'] > 0): ?>
                            <div class="farmasi-stat-list">
                                <div class="farmasi-stat-row">
                                    <div class="farmasi-stat-label">Transaksi</div>
                                    <div class="farmasi-stat-value">
                                        <div class="farmasi-stat-title"><?= (int)$todayStats['total_transaksi'] ?> transaksi</div>
                                    </div>
                                </div>
                                <div class="farmasi-stat-row">
                                    <div class="farmasi-stat-label">Total Penjualan</div>
                                    <div class="farmasi-stat-value">
                                        <div class="farmasi-stat-title"><?= dollar((int)$todayStats['total_harga']) ?></div>
                                    </div>
                                </div>
                                <div class="farmasi-stat-row">
                                    <div class="farmasi-stat-label">Bonus 40%</div>
                                    <div class="farmasi-stat-value">
                                        <div class="farmasi-stat-title"><?= dollar((int)$todayStats['bonus_40']) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="empty-copy">
                                Belum ada transaksi hari ini.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Card Filter & Transaksi -->
                <div class="card mb-0 farmasi-card">
                    <div class="farmasi-card-header">
                        <h3 class="farmasi-card-title">Filter Tanggal</h3>
                        <p class="farmasi-card-subtitle">Pilih rentang data transaksi.</p>
                    </div>

                    <!-- Form Filter (GET) -->
                    <div class="farmasi-card-content">
                        <form method="get" class="mb-2.5 filter-transaction-form">
                            <div class="farmasi-filter-field">
                                <label for="rangeSelect">Rentang Tanggal</label>
                                <select name="range" id="rangeSelect" class="farmasi-filter-control" style="display:block;width:100%!important;min-width:100%!important;max-width:none!important;box-sizing:border-box;">
                                    <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Hari ini</option>
                                    <option value="yesterday" <?= $range === 'yesterday' ? 'selected' : '' ?>>Kemarin</option>
                                    <option value="last7" <?= $range === 'last7' ? 'selected' : '' ?>>7 hari terakhir</option>

                                    <option value="week1" <?= $range === 'week1' ? 'selected' : '' ?>>
                                        <?= $weeks['week1']['start']->format('d M') ?> – <?= $weeks['week1']['end']->format('d M') ?>
                                    </option>

                                    <option value="week2" <?= $range === 'week2' ? 'selected' : '' ?>>
                                        <?= $weeks['week2']['start']->format('d M') ?> – <?= $weeks['week2']['end']->format('d M') ?>
                                    </option>

                                    <option value="week3" <?= $range === 'week3' ? 'selected' : '' ?>>
                                        <?= $weeks['week3']['start']->format('d M') ?> – <?= $weeks['week3']['end']->format('d M') ?>
                                    </option>

                                    <option value="week4" <?= $range === 'week4' ? 'selected' : '' ?>>
                                        <?= $weeks['week4']['start']->format('d M') ?> – <?= $weeks['week4']['end']->format('d M') ?>
                                    </option>

                                    <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom (pilih tanggal)</option>
                                </select>
                            </div>
                            <div class="row-form-2 hidden" id="customDateRow">
                                <div class="col">
                                    <label for="filterFromDate">Dari tanggal</label>
                                    <input type="date" id="filterFromDate" name="from" value="<?= htmlspecialchars($fromDateInput) ?>">
                                </div>
                                <div class="col">
                                    <label for="filterToDate">Sampai tanggal</label>
                                    <input type="date" id="filterToDate" name="to" value="<?= htmlspecialchars($toDateInput) ?>">
                                </div>
                            </div>

                            <?php if ($showAll): ?>
                                <input type="hidden" name="show_all" value="1">
                            <?php endif; ?>

                            <div class="mt-2">
                                <button type="submit" class="btn-secondary farmasi-filter-button">Terapkan Filter</button>
                            </div>
                        </form>

                        <p class="muted-copy-tight">
                            Rentang aktif: <strong><?= htmlspecialchars($rangeLabel) ?></strong>
                        </p>
                    </div>
                </div>

                <!-- Rekapan Bonus Medis (berdasarkan filter tanggal) -->
                <div class="card mb-0 farmasi-card">
                    <div class="farmasi-card-header">
                        <h3 class="farmasi-card-title">Rekap Bonus</h3>
                        <p class="farmasi-card-subtitle">Ringkasan bonus pada rentang aktif.</p>
                    </div>
                    <div class="farmasi-card-content">
                        <?php if ($singleMedicStats): ?>
                            <div class="farmasi-stat-list">
                                <div class="farmasi-stat-row">
                                    <div class="farmasi-stat-label">Nama Medis</div>
                                    <div class="farmasi-stat-value">
                                        <div class="farmasi-stat-title"><?= htmlspecialchars($singleMedicStats['medic_name']) ?></div>
                                        <div class="farmasi-stat-meta"><?= htmlspecialchars($singleMedicStats['medic_jabatan']) ?></div>
                                    </div>
                                </div>
                                <div class="farmasi-stat-row">
                                    <div class="farmasi-stat-label">Transaksi</div>
                                    <div class="farmasi-stat-value">
                                        <div class="farmasi-stat-title"><?= (int)$singleMedicStats['total_transaksi'] ?> transaksi</div>
                                        <div class="farmasi-stat-meta"><?= (int)$singleMedicStats['total_item'] ?> item</div>
                                    </div>
                                </div>
                                <div class="farmasi-stat-row">
                                    <div class="farmasi-stat-label">Total Penjualan</div>
                                    <div class="farmasi-stat-value">
                                        <div class="farmasi-stat-title"><?= dollar((int)$singleMedicStats['total_harga']) ?></div>
                                    </div>
                                </div>
                                <div class="farmasi-stat-row">
                                    <div class="farmasi-stat-label">Bonus 40%</div>
                                    <div class="farmasi-stat-value">
                                        <div class="farmasi-stat-title"><?= dollar((int)$singleMedicStats['bonus_40']) ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="empty-copy mb-3">
                                Belum ada data untuk petugas medis aktif pada rentang tanggal yang dipilih.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-0 farmasi-card">
                    <div class="farmasi-card-header">
                        <h3 class="farmasi-card-title">Konsumen Teratas</h3>
                        <p class="farmasi-card-subtitle">Pembelian terbanyak per periode.</p>
                    </div>
                    <div class="farmasi-card-content">
                        <div class="farmasi-stat-list">
                            <?php foreach ($topConsumerStats as $consumerStat): ?>
                                <div class="farmasi-stat-row">
                                    <div class="farmasi-stat-label"><?= htmlspecialchars($consumerStat['label']) ?></div>
                                    <div class="farmasi-stat-value">
                                        <div class="farmasi-stat-title"><?= htmlspecialchars($consumerStat['consumer_name']) ?></div>
                                        <div class="farmasi-stat-meta">
                                            <?= (int)$consumerStat['total_transaksi'] ?> transaksi · <?= dollar((int)$consumerStat['total_belanja']) ?>
                                        </div>
                                        <div class="farmasi-stat-meta">
                                            BD <?= (int)$consumerStat['total_bandage'] ?> · IF <?= (int)$consumerStat['total_ifaks'] ?> · PK <?= (int)$consumerStat['total_painkiller'] ?>
                                        </div>
                                        <div class="farmasi-stat-meta">
                                            Terakhir: <?= $consumerStat['last_purchase_at'] !== '' ? htmlspecialchars(formatTanggalID($consumerStat['last_purchase_at'])) : '-' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabel Transaksi dengan DataTables + checkbox -->
            <div class="card mt-4 farmasi-card">
                <div class="farmasi-card-header">
                    <div class="switcher-bar">
                        <div>
                            <h3 class="farmasi-card-title">Riwayat Transaksi</h3>
                            <div class="farmasi-card-subtitle">
                                <?php if ($showAll): ?>
                                    Mode: <strong>Semua medis</strong>
                                <?php else: ?>
                                    Mode: <strong>Medis aktif (<?= htmlspecialchars($medicName) ?>)</strong>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="switcher-actions">
                            <form method="get" class="switcher-form">
                                <!-- bawa filter range yang sedang aktif -->
                                <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
                                <?php if ($range === 'custom'): ?>
                                    <input type="hidden" name="from" value="<?= htmlspecialchars($fromDateInput) ?>">
                                    <input type="hidden" name="to" value="<?= htmlspecialchars($toDateInput) ?>">
                                <?php endif; ?>

                                <?php if ($showAll): ?>
                                    <!-- Sedang mode "tampilkan semua data" → tombol kembali ke hanya medis aktif -->
                                    <button type="submit" class="btn-secondary">
                                        Kembali (Hanya Medis Aktif)
                                    </button>
                                <?php else: ?>
                                    <!-- Sedang mode hanya medis aktif → tombol untuk tampilkan semua data -->
                                    <input type="hidden" name="show_all" value="1">
                                    <button type="submit" class="btn-secondary">
                                        Tampilkan Semua Data
                                    </button>
                                <?php endif; ?>
                            </form>

                            <div class="hidden" id="bulkSelectionControls">
                                <strong id="bulkSelectionCount">0 data terpilih</strong>
                                <button type="button" class="btn-secondary" id="btnSelectAllRows">
                                    Select all
                                </button>
                                <button type="button" class="btn-secondary" id="btnDeselectAllRows">
                                    Deselect all
                                </button>
                                <button type="submit" class="btn-danger" id="btnBulkDelete" form="bulkDeleteForm" disabled>
                                    Hapus Data Terpilih
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="farmasi-card-content">
                    <?php if (!$filteredSales): ?>
                        <p class="empty-copy">Belum ada transaksi pada rentang ini.</p>
                    <?php else: ?>
                        <form method="post" id="bulkDeleteForm" onsubmit="return confirmBulkDelete();">
                            <input type="hidden" name="action" value="delete_selected">
                            <div class="table-wrapper-sm">
                                <table id="salesTable" class="table-custom">
                                    <thead>
                                        <tr>
                                            <th class="checkbox-col">
                                                <input type="checkbox" id="selectAll">
                                            </th>
                                            <th>Waktu</th>
                                            <th><?= htmlspecialchars($consumerIdentifierLabel) ?></th>
                                            <th>Nama Medis</th>
                                            <th>Jabatan</th>
                                            <th>Paket</th>
                                            <th>Bandage</th>
                                            <th>IFAKS</th>
                                            <th>Painkiller</th>
                                            <th>Harga</th>
                                            <th>Bonus (40%)</th>
                                        </tr>
                                    </thead>
                                    <tfoot>
                                        <tr>
                                            <th colspan="6" class="table-align-right">TOTAL</th>
                                            <th></th>
                                            <th></th>
                                            <th></th>
                                            <th></th>
                                            <th></th>
                                        </tr>
                                    </tfoot>
                                    <tbody>
                                        <?php foreach ($filteredSales as $s): ?>
                                            <?php $bonus = (int)floor(((int)$s['price']) * 0.4); ?>
                                            <?php
                                            $consumerDisplayName = ems_looks_like_citizen_id((string)$s['consumer_name'])
                                                ? ems_normalize_citizen_id((string)$s['consumer_name'])
                                                : trim((string)$s['consumer_name']);
                                            $consumerIdentityMeta = $consumerIdentityPhotoMap[$consumerDisplayName] ?? null;
                                            ?>
                                            <tr>
                                                <td class="table-align-center">
                                                    <?php if ($medicName && $s['medic_name'] === $medicName): ?>
                                                        <input type="checkbox"
                                                            class="row-check"
                                                            name="sale_ids[]"
                                                            value="<?= (int)$s['id'] ?>">
                                                    <?php else: ?>
                                                        <span class="switcher-caption">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-order="<?= strtotime($s['created_at']) ?>">
                                                    <?= formatTanggalID($s['created_at']) ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($consumerIdentityMeta['has_photo']) && !empty($consumerIdentityMeta['identity_id'])): ?>
                                                        <button
                                                            type="button"
                                                            class="btn-link"
                                                            onclick="openIdentityViewModal(<?= (int)$consumerIdentityMeta['identity_id'] ?>)"
                                                            title="Lihat foto KTP konsumen">
                                                            <?= htmlspecialchars($consumerDisplayName) ?>
                                                        </button>
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($consumerDisplayName) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($s['medic_name']) ?></td>
                                                <td><?= htmlspecialchars($s['medic_jabatan']) ?></td>
                                                <td><?= htmlspecialchars($s['package_name']) ?></td>
                                                <td><?= (int)$s['qty_bandage'] ?></td>
                                                <td><?= (int)$s['qty_ifaks'] ?></td>
                                                <td><?= (int)$s['qty_painkiller'] ?></td>
                                                <td><?= dollar((int)$s['price']) ?></td>
                                                <td><?= dollar($bonus) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- Kalau tidak ada petugas, info kecil saja -->
            <p class="empty-copy">
                Silakan set <strong>Petugas Medis Aktif</strong> terlebih dahulu untuk dapat input transaksi dan melihat rekap.
            </p>
        <?php endif; ?>

    </div>

    <!-- =========================
     MODAL FORCE OFFLINE (EMS)
     ========================= -->
    <div id="emsForceModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-md">
            <div class="modal-head">
                <div class="modal-title">Force Offline Medis</div>
                <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>

            <div class="modal-content">
                <p id="emsForceDesc">
                    Anda akan memaksa petugas medis menjadi <strong>OFFLINE</strong>.
                </p>

                <div class="force-offline-body">
                    <label for="emsForceReason" class="force-offline-label">
                        Alasan Force Offline
                    </label>
                    <textarea id="emsForceReason"
                        placeholder="Contoh: sudah tidak duty / tidak berada di kota"
                        class="force-offline-textarea"></textarea>

                    <small class="force-offline-hint">
                        Minimal 5 karakter
                    </small>
                </div>
            </div>

            <div class="modal-foot">
                <div class="modal-actions force-offline-actions">
                    <button type="button" class="btn-secondary ems-btn-cancel">Batal</button>
                    <button type="button" class="btn-danger ems-btn-confirm">Force Offline</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        //Global
        function normalizeName(str) {
            return (str || '')
                .toUpperCase()
                .replace(/[^A-Z0-9]/g, '')
                .trim();
        }

        function normalizeLooseName(str) {
            return normalizeName(str);
        }

        function getConsumerIdentityKey(str) {
            const normalized = normalizeLooseName(str);
            if (!normalized) return '';
            return normalized;
        }

        function looksLikeCitizenId(str) {
            const normalized = normalizeName(str);
            if (!normalized) return false;
            if (/^\d+$/.test(normalized)) return false;
            return /^[A-Z0-9]{6,20}$/.test(normalized);
        }

        function levenshteinDistance(a, b) {
            a = normalizeLooseName(a);
            b = normalizeLooseName(b);

            if (a === b) return 0;
            if (!a.length) return b.length;
            if (!b.length) return a.length;

            const matrix = Array.from({
                length: b.length + 1
            }, () => []);

            for (let i = 0; i <= b.length; i++) matrix[i][0] = i;
            for (let j = 0; j <= a.length; j++) matrix[0][j] = j;

            for (let i = 1; i <= b.length; i++) {
                for (let j = 1; j <= a.length; j++) {
                    const cost = b.charAt(i - 1) === a.charAt(j - 1) ? 0 : 1;
                    matrix[i][j] = Math.min(
                        matrix[i - 1][j] + 1,
                        matrix[i][j - 1] + 1,
                        matrix[i - 1][j - 1] + cost
                    );
                }
            }

            return matrix[b.length][a.length];
        }

        function tokenEquivalent(left, right) {
            const a = normalizeLooseName(left);
            const b = normalizeLooseName(right);
            if (!a || !b) return false;
            return a === b;
        }

        function namesEquivalent(left, right) {
            return tokenEquivalent(left, right);
        }

        function findSimilarConsumers(input, consumers) {
            return [];
        }

        let EXISTING_CONSUMERS = <?= json_encode($consumerNames, JSON_UNESCAPED_UNICODE); ?>;
        const CSRF_TOKEN = <?= json_encode(generateCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;
        // Flag dari PHP: apakah form perlu dikosongkan setelah transaksi sukses?
        const SHOULD_CLEAR_FORM = <?= $shouldClearForm ? 'true' : 'false'; ?>;
        const PROFILE_INCOMPLETE = <?= $profileIncompleteForFarmasi ? 'true' : 'false'; ?>;
        const PROFILE_NOTICE_TEXT = <?= json_encode(
                                        $profileIncompleteForFarmasi
                                            ? 'Transaksi belum bisa disimpan. Lengkapi dulu: ' . implode(', ', $missingProfileFields) . '.'
                                            : ''
                                    ) ?>;

        // Konstanta batas harian
        const MAX_BANDAGE = 30;
        const MAX_IFAKS = 10;
        const MAX_PAINKILLER = 10;

        // Data paket dari PHP untuk perhitungan realtime
        const PACKAGES = <?= json_encode($packagesById, JSON_UNESCAPED_UNICODE); ?>;
        const PRICE_PER_PCS = <?= json_encode($pricePerPcs, JSON_NUMERIC_CHECK); ?>;
        // Total harian per konsumen dari PHP (key = nama kecil trim)
        const DAILY_TOTALS = <?= json_encode($dailyTotalsJS, JSON_UNESCAPED_UNICODE); ?>;
        const DAILY_DETAIL = <?= json_encode($dailyDetailJS, JSON_UNESCAPED_UNICODE); ?>;
        const DAILY_DETAIL_BY_NAME = <?= json_encode($dailyDetailByNameJS, JSON_UNESCAPED_UNICODE); ?>;
        const CONSUMER_HISTORY_SUMMARY = <?= json_encode($allConsumerSummaryJS, JSON_UNESCAPED_UNICODE); ?>;
        const CONSUMER_BLACKLIST = <?= json_encode($consumerBlacklistMap, JSON_UNESCAPED_UNICODE); ?>;
        const TODAY_CONSUMER_NAMES = <?= json_encode(array_values($todayConsumerNamesJS), JSON_UNESCAPED_UNICODE); ?>;
        // Flag global: apakah pilihan saat ini menyebabkan melewati batas harian
        let IS_OVER_LIMIT = false;
        let PRIORITY_LOCK = false;
        let LAST_CONSUMER_NAME = '';
        let CONSUMER_LOCK = false;
        let BLACKLIST_LOCK = false;
        let NOTICE_STATE = 'NONE';
        let ACTIVE_MERGE_CANDIDATES = [];
        let ACTIVE_MERGE_SOURCE_NAME = '';
        let ACTIVE_MERGE_ENABLED = false;

        const STORAGE_KEY = 'farmasi_ems_form';
        const DEFAULT_PACKAGE_MODE = 'combo';
        const DEFAULT_PACKAGE_LABEL = <?= json_encode($comboPackageModeLabel, JSON_UNESCAPED_UNICODE); ?>;

        const FAIRNESS_STATE = {
            locked: false,
            data: null
        };

        function escapeHtml(str) {
            return (str || '').replace(/[&<>"']/g, function(c) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                })[c] || c;
            });
        }

        function formatDollar(num) {
            num = parseInt(num || 0, 10);
            if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
                return '$ ' + new Intl.NumberFormat('en-US').format(num);
            }
            return '$ ' + num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        function applyProfileRequirementLock() {
            const btnSubmit = document.getElementById('btnSubmit');
            if (!PROFILE_INCOMPLETE || !btnSubmit) return;

            btnSubmit.disabled = true;
            btnSubmit.classList.add('btn-disabled');
        }

        function getBaseTotalsForConsumer(name) {
            if (!name) {
                return {
                    bandage: 0,
                    ifaks: 0,
                    painkiller: 0
                };
            }
            const key = getConsumerIdentityKey(name);
            const data = DAILY_TOTALS[key];
            if (!data) {
                return {
                    bandage: 0,
                    ifaks: 0,
                    painkiller: 0
                };
            }
            return {
                bandage: parseInt(data.bandage || 0, 10),
                ifaks: parseInt(data.ifaks || 0, 10),
                painkiller: parseInt(data.painkiller || 0, 10),
            };
        }

        function saveFormState() {
            const consumerInput = document.querySelector('input[name="consumer_name"]');

            function getValue(id) {
                const el = document.getElementById(id);
                return el ? (el.value || '') : '';
            }
            const data = {
                consumer_name: consumerInput ? consumerInput.value : '',
                pkg_main: getValue('pkg_main'),
                pkg_bandage: getValue('pkg_bandage'),
                pkg_ifaks: getValue('pkg_ifaks'),
                pkg_painkiller: getValue('pkg_painkiller'),
                identity_id: getValue('identity_id'),
                ocr_citizen_id: getValue('ocr_citizen_id'),
                ocr_first_name: getValue('ocr_first_name'),
                ocr_last_name: getValue('ocr_last_name'),
            };
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
            } catch (e) {
                // abaikan
            }
        }

        function restoreFormState() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                if (!raw) return;
                const data = JSON.parse(raw);
                const consumerInput = document.querySelector('input[name="consumer_name"]');
                if (consumerInput && data.consumer_name) {
                    consumerInput.value = formatConsumerName(data.consumer_name);
                }
                ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    if (typeof data[id] !== 'undefined') {
                        el.value = data[id];
                    }
                });
                ['identity_id', 'ocr_citizen_id', 'ocr_first_name', 'ocr_last_name'].forEach(function(id) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    if (typeof data[id] !== 'undefined') {
                        el.value = data[id];
                    }
                });
                updateOcrIdentityInfo();
            } catch (e) {
                // abaikan
            }
        }

        function updateOcrIdentityInfo() {
            const infoBox = document.getElementById('ocrIdentityInfo');
            const nameEl = document.getElementById('ocrIdentityName');
            const citizenEl = document.getElementById('ocrIdentityCitizenId');
            const firstName = (document.getElementById('ocr_first_name') || {}).value || '';
            const lastName = (document.getElementById('ocr_last_name') || {}).value || '';
            const citizenId = (document.getElementById('ocr_citizen_id') || {}).value || '';
            const fullName = (firstName + ' ' + lastName).trim();

            if (!infoBox || !nameEl || !citizenEl) {
                return;
            }

            if (!fullName && !citizenId) {
                infoBox.style.display = 'none';
                nameEl.textContent = '-';
                citizenEl.textContent = '-';
                return;
            }

            nameEl.textContent = fullName || 'Nama tidak terdeteksi';
            citizenEl.textContent = citizenId ? ('Citizen ID: ' + citizenId) : 'Citizen ID tidak terdeteksi';
            infoBox.style.display = 'block';
        }

        function setOcrIdentityPayload(payload) {
            const identityIdInput = document.getElementById('identity_id');
            const citizenIdInput = document.getElementById('ocr_citizen_id');
            const firstNameInput = document.getElementById('ocr_first_name');
            const lastNameInput = document.getElementById('ocr_last_name');
            const consumerInput = document.getElementById('consumerNameInput');

            if (identityIdInput) identityIdInput.value = payload.identity_id || '';
            if (citizenIdInput) citizenIdInput.value = formatConsumerName(payload.citizen_id || '');
            if (firstNameInput) firstNameInput.value = payload.first_name || '';
            if (lastNameInput) lastNameInput.value = payload.last_name || '';
            if (consumerInput && payload.citizen_id) {
                consumerInput.value = formatConsumerName(payload.citizen_id);
            }

            updateOcrIdentityInfo();
            updateSimilarConsumerBox();
            saveFormState();
            recalcTotals();
        }

        function clearOcrIdentityPayload() {
            ['identity_id', 'ocr_citizen_id', 'ocr_first_name', 'ocr_last_name'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            updateOcrIdentityInfo();
        }

        function getSelectedPackageMode() {
            const mainEl = document.getElementById('pkg_main');
            return mainEl && mainEl.value === 'custom' ? 'custom' : DEFAULT_PACKAGE_MODE;
        }

        function getSelectedComboPackageLabel() {
            const mainEl = document.getElementById('pkg_main');
            if (!mainEl || !mainEl.value || mainEl.value === 'custom') {
                return DEFAULT_PACKAGE_LABEL;
            }

            const selectedOption = mainEl.options[mainEl.selectedIndex];
            const selectedText = selectedOption ? String(selectedOption.textContent || '').trim() : '';
            if (!selectedText || selectedText.startsWith('--')) {
                return DEFAULT_PACKAGE_LABEL;
            }

            return selectedText.replace(/\s*\(\d+\)\s*$/, '').trim() || DEFAULT_PACKAGE_LABEL;
        }

        function inferPackageMode() {
            const customIds = ['pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'];
            const hasCustomSelection = customIds.some(function(id) {
                const el = document.getElementById(id);
                return el && el.value;
            });
            if (hasCustomSelection) {
                return 'custom';
            }

            const mainEl = document.getElementById('pkg_main');
            if (mainEl && mainEl.value === 'custom') {
                return 'custom';
            }
            if (mainEl && mainEl.value) {
                return DEFAULT_PACKAGE_MODE;
            }

            return DEFAULT_PACKAGE_MODE;
        }

        function applyPackageMode(mode, options) {
            const settings = options || {};
            const preserveSelections = !!settings.preserveSelections;
            const normalizedMode = mode === 'custom' ? 'custom' : DEFAULT_PACKAGE_MODE;
            const mainEl = document.getElementById('pkg_main');
            const customRow = document.getElementById('customPackageRow');
            const activeLabel = document.getElementById('activePackageLabel');

            if (customRow) {
                customRow.classList.toggle('hidden', normalizedMode !== 'custom');
            }
            if (activeLabel) {
                activeLabel.textContent = normalizedMode === 'custom' ? 'Custom' : getSelectedComboPackageLabel();
            }

            if (!preserveSelections) {
                if (normalizedMode === 'custom') {
                    if (mainEl) {
                        mainEl.value = 'custom';
                    }
                } else {
                    ['pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
                        const el = document.getElementById(id);
                        if (el) el.value = '';
                    });
                    if (mainEl && mainEl.value === 'custom') {
                        mainEl.value = '';
                    }
                }
            }
        }

        function formatConsumerName(name) {
            return normalizeName(name);
        }

        function showFairnessNotice(html) {
            const box = document.getElementById('fairnessNotice');
            if (!box) return;

            FAIRNESS_STATE.locked = false;

            box.innerHTML = html;
            box.style.display = 'block';
        }

        function clearFairnessNotice() {
            const box = document.getElementById('fairnessNotice');

            FAIRNESS_STATE.locked = false;

            if (box) {
                box.style.display = 'none';
                box.innerHTML = '';
            }
        }

        // ===============================
        // Consumer notice (lokal)
        // ===============================
        function showConsumerNotice(payload) {
            const box = document.getElementById('consumerNotice');
            if (!box) return;

            const titleEl = document.getElementById('consumerNoticeTitle');
            const textEl = document.getElementById('consumerNoticeText');
            const bodyEl = document.getElementById('consumerNoticeBody');
            const actionsEl = document.getElementById('consumerNoticeActions');
            const footEl = document.getElementById('consumerNoticeFoot');

            CONSUMER_LOCK = !!(payload && payload.locked);

            if (titleEl) titleEl.innerHTML = payload && payload.title ? payload.title : '';
            if (textEl) textEl.innerHTML = payload && payload.text ? payload.text : '';
            if (bodyEl) bodyEl.innerHTML = payload && payload.body ? payload.body : '';
            if (actionsEl) actionsEl.innerHTML = payload && payload.actions ? payload.actions : '';
            if (footEl) footEl.innerHTML = payload && payload.foot ? payload.foot : '';
            box.style.display = 'block';
        }

        function clearConsumerNotice() {
            const box = document.getElementById('consumerNotice');
            if (!box) return;

            const titleEl = document.getElementById('consumerNoticeTitle');
            const textEl = document.getElementById('consumerNoticeText');
            const bodyEl = document.getElementById('consumerNoticeBody');
            const actionsEl = document.getElementById('consumerNoticeActions');
            const footEl = document.getElementById('consumerNoticeFoot');

            CONSUMER_LOCK = false;

            box.style.display = 'none';
            if (titleEl) titleEl.innerHTML = '';
            if (textEl) textEl.innerHTML = '';
            if (bodyEl) bodyEl.innerHTML = '';
            if (actionsEl) actionsEl.innerHTML = '';
            if (footEl) footEl.innerHTML = '';
        }

        function getBlacklistInfo(name) {
            const key = getConsumerIdentityKey(name);
            if (!key) return null;
            return CONSUMER_BLACKLIST[key] || null;
        }

        function showBlacklistNotice(name, info) {
            const box = document.getElementById('blacklistNotice');
            if (!box) return;

            const note = info && info.note ? String(info.note) : '';
            const displayName = info && info.name ? info.name : name;
            const sourceUnit = info && info.unit_code ? String(info.unit_code) : '';
            const sourceUnitLabel = sourceUnit === 'alta' ? 'Alta' : (sourceUnit === 'roxwood' ? 'Roxwood' : sourceUnit);

            box.innerHTML =
                '<strong>Citizen ID telah di-blacklist.</strong><br>' +
                'Citizen ID <strong>' + escapeHtml(displayName) + '</strong> tidak dapat disimpan pada rekap farmasi mana pun.' +
                (sourceUnitLabel ? '<br><strong>Sumber blacklist:</strong> ' + escapeHtml(sourceUnitLabel) : '') +
                (note ? '<br><br><strong>Note blacklist:</strong> ' + escapeHtml(note) : '');
            box.style.display = 'block';
            BLACKLIST_LOCK = true;
        }

        function clearBlacklistNotice() {
            const box = document.getElementById('blacklistNotice');
            BLACKLIST_LOCK = false;
            if (!box) return;
            box.innerHTML = '';
            box.style.display = 'none';
        }

        function renderConsumerSummaryCards(names) {
            if (!Array.isArray(names) || names.length === 0) {
                return '';
            }

            const cards = names.map(function(name) {
                const summary = CONSUMER_HISTORY_SUMMARY[name] || null;
                const totalTransactions = summary ? parseInt(summary.transactions || 0, 10) : 0;
                const lastLabel = summary && summary.last_transaction_label ? summary.last_transaction_label : '-';

                return `<article class="consumer-merge-notice__summary-card">
                    <div class="consumer-merge-notice__summary-name">${escapeHtml(name)}</div>
                    <div class="consumer-merge-notice__summary-meta">Total transaksi: <strong>${totalTransactions}</strong></div>
                    <div class="consumer-merge-notice__summary-meta">Terakhir tercatat: <strong>${escapeHtml(lastLabel)}</strong></div>
                </article>`;
            }).join('');

            return `<div class="consumer-merge-notice__summary-grid">${cards}</div>`;
        }

        async function executeImmediateNameMerge() {
            alert('Fitur merge nama dinonaktifkan karena transaksi farmasi sekarang memakai Citizen ID Konsumen.');
        }

        function updateSimilarConsumerBox() {
            const input = document.getElementById('consumerNameInput');
            const box = document.getElementById('similarConsumerBox');

            if (!input || !box) {
                return;
            }

            const currentName = formatConsumerName(input.value || '');
            ACTIVE_MERGE_SOURCE_NAME = currentName;
            ACTIVE_MERGE_CANDIDATES = [];
            ACTIVE_MERGE_ENABLED = false;
            syncMergeHiddenFields();
            box.innerHTML = '';
        }

        function syncMergeHiddenFields() {
            const autoMergeInput = document.getElementById('auto_merge');
            const mergeTargetsInput = document.getElementById('merge_targets');
            if (!autoMergeInput || !mergeTargetsInput) {
                return;
            }

            autoMergeInput.value = ACTIVE_MERGE_ENABLED && ACTIVE_MERGE_CANDIDATES.length > 0 ? '1' : '0';
            mergeTargetsInput.value = ACTIVE_MERGE_ENABLED && ACTIVE_MERGE_CANDIDATES.length > 0 ?
                JSON.stringify(ACTIVE_MERGE_CANDIDATES) :
                '';
        }

        function recalcTotals() {
            // Fairness hanya mengunci submit, bukan logic input
            const fairnessLocked = FAIRNESS_STATE.locked;

            // Kumpulkan ID paket yang dipilih
            const ids = [];
            const activeMode = getSelectedPackageMode();
            const activeIds = activeMode === 'custom' ? ['pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'] : ['pkg_main'];

            activeIds.forEach(function(id) {
                const el = document.getElementById(id);
                if (el && el.value) {
                    ids.push(el.value);
                }
            });

            let totalBandage = 0;
            let totalIfaks = 0;
            let totalPain = 0;
            let totalPrice = 0;

            ids.forEach(function(id) {
                const pkg = PACKAGES[id];
                if (!pkg) return;

                totalBandage += parseInt(pkg.bandage || 0, 10);
                totalIfaks += parseInt(pkg.ifaks || 0, 10);
                totalPain += parseInt(pkg.painkiller || 0, 10);
                totalPrice += parseInt(pkg.price || 0, 10);
            });

            // ===============================
            // FLAG OVER LIMIT (UNTUK CONFIRM)
            // ===============================
            IS_OVER_LIMIT =
                totalBandage > MAX_BANDAGE ||
                totalIfaks > MAX_IFAKS ||
                totalPain > MAX_PAINKILLER;

            const bonus = Math.floor(totalPrice * 0.4);

            // Update teks "Total item terpilih"
            document.getElementById('totalBandage').textContent = totalBandage;
            document.getElementById('totalIfaks').textContent = totalIfaks;
            document.getElementById('totalPainkiller').textContent = totalPain;

            // Update display kasir besar
            const totalPriceDisplay = document.getElementById('totalPriceDisplay');
            if (totalPriceDisplay) {
                totalPriceDisplay.textContent = formatDollar(totalPrice);
            }

            // Update bonus 40%
            const bonusEl = document.getElementById('totalBonus');
            if (bonusEl) {
                bonusEl.textContent = formatDollar(bonus);
            }

            // ===== Cek limit harian berdasarkan citizen ID =====
            const consumerInput = document.querySelector('input[name="consumer_name"]');
            const cname = consumerInput ? consumerInput.value.trim() : '';
            const btnSubmit = document.getElementById('btnSubmit');

            if (cname !== LAST_CONSUMER_NAME) {
                clearConsumerNotice();
                LAST_CONSUMER_NAME = cname;
            }

            if (btnSubmit) {
                if (PROFILE_INCOMPLETE) {
                    applyProfileRequirementLock();
                } else {
                    btnSubmit.disabled = false;
                    btnSubmit.classList.remove('btn-disabled');
                }
            }

            clearBlacklistNotice();

            let detail = [];
            let exactBoughtToday = false;
            let similarHistoricalNames = [];

            if (!cname || cname.length < 6 || !looksLikeCitizenId(cname)) {
                clearConsumerNotice(); // HANYA consumer
                IS_OVER_LIMIT = false;
                return;
            } else {
                const blacklistInfo = getBlacklistInfo(cname);
                if (blacklistInfo) {
                    clearConsumerNotice();
                    showBlacklistNotice(cname, blacklistInfo);
                    if (btnSubmit) {
                        btnSubmit.disabled = true;
                        btnSubmit.classList.add('btn-disabled');
                    }
                    IS_OVER_LIMIT = false;
                    CONSUMER_LOCK = false;
                    return;
                }

                const key = getConsumerIdentityKey(cname);
                detail = DAILY_DETAIL[key] || [];
                exactBoughtToday = TODAY_CONSUMER_NAMES.some(function(name) {
                    return normalizeName(name) === normalizeName(cname);
                });
                similarHistoricalNames = findSimilarConsumers(cname, EXISTING_CONSUMERS)
                    .filter(function(name) {
                        return normalizeName(name) !== normalizeName(cname);
                    })
                    .slice(0, 6);
            }

            if (exactBoughtToday) {
                IS_OVER_LIMIT = false;
                CONSUMER_LOCK = true;

                let bodyHtml = '';
                bodyHtml += '<div class="consumer-merge-notice__section-label">Detail pembelian hari ini</div>';
                bodyHtml += '<ul class="notice-detail-list">';

                detail.forEach(function(d) {
                    const waktu = d.time ? d.time : '-';

                    bodyHtml += '<li class="notice-detail-item">' +
                        '<strong>' + escapeHtml(d.package || '-') + '</strong><br>' +
                        '<small>' +
                        'Waktu: ' + escapeHtml(waktu) +
                        ' &nbsp;|&nbsp; Medis: ' + escapeHtml(d.medic || '-') +
                        '</small>' +
                        '</li>';
                });

                bodyHtml += '</ul>';

                // ===============================
                // INFO TAMBAHAN & ATURAN SISTEM
                // ===============================
                let footHtml = '';
                footHtml += 'Silakan konfirmasi ke konsumen bahwa pembelian telah dilakukan ';
                footHtml += 'pada waktu dan petugas medis yang tercantum di atas.<br><br>';

                // Hitung jam reset (00:00 besok)
                const now = new Date();
                const nextDay = new Date(
                    now.getFullYear(),
                    now.getMonth(),
                    now.getDate() + 1,
                    0, 0, 0
                );

                const monthsID = [
                    'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
                    'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
                ];

                const nextDateStr =
                    nextDay.getDate() + ' ' +
                    monthsID[nextDay.getMonth()] + ' ' +
                    nextDay.getFullYear() +
                    ' 00:00';

                const remainingMinutes = Math.max(1, Math.ceil((nextDay.getTime() - now.getTime()) / 60000));
                const remainingHours = Math.floor(remainingMinutes / 60);
                const remainingOnlyMinutes = remainingMinutes % 60;
                let remainingLabel = '';

                if (remainingHours > 0 && remainingOnlyMinutes > 0) {
                    remainingLabel = remainingHours + ' jam ' + remainingOnlyMinutes + ' menit lagi';
                } else if (remainingHours > 0) {
                    remainingLabel = remainingHours + ' jam lagi';
                } else {
                    remainingLabel = remainingOnlyMinutes + ' menit lagi';
                }

                footHtml += 'Transaksi baru dapat dilakukan kembali pada ';
                footHtml += '<strong>' + nextDateStr + '</strong>';
                footHtml += ' atau sekitar <strong>' + remainingLabel + '</strong>.<br><br>';

                footHtml += '<strong>Ketentuan Sistem:</strong><br>';
                footHtml += 'Perhitungan batas transaksi didasarkan pada ';
                footHtml += '<strong>tanggal kalender</strong>, ';
                footHtml += 'bukan durasi 24 jam sejak transaksi terakhir. ';
                footHtml += 'Transaksi setelah pergantian hari (pukul 00:00) ';
                footHtml += 'dianggap sebagai transaksi hari berikutnya.';

                // ===============================
                // TAMPILKAN NOTICE KONSUMEN
                // ===============================
                showConsumerNotice({
                    title: '<strong>' + escapeHtml(cname) + '</strong> sudah melakukan <strong>1 transaksi hari ini</strong>.',
                    text: '1 Citizen ID hanya boleh 1 transaksi per hari. Transaksi tambahan tidak diperbolehkan sampai pergantian hari.',
                    body: bodyHtml,
                    actions: '',
                    foot: footHtml,
                    locked: true
                });
                return; // STOP: tidak lanjut ke proses lain
            }

            if (!exactBoughtToday && similarHistoricalNames.length > 0) {
                IS_OVER_LIMIT = false;
                CONSUMER_LOCK = false;

                const selectedNames = ACTIVE_MERGE_ENABLED ? ACTIVE_MERGE_CANDIDATES.slice() : [];
                let bodyHtml = '';
                bodyHtml += '<div><div class="consumer-merge-notice__section-label">Kandidat nama mirip dari seluruh riwayat transaksi</div>';
                bodyHtml += '<div class="consumer-merge-notice__chip-row">';
                bodyHtml += similarHistoricalNames.map(function(name) {
                    const excluded = !ACTIVE_MERGE_CANDIDATES.includes(name);
                    return '<span class="consumer-similar-chip ' + (excluded ? 'is-excluded' : '') + '">' +
                        '<span class="btn-secondary btn-sm">' + escapeHtml(name) + '</span>' +
                        '<button type="button" class="btn-error btn-sm" data-notice-remove-merge="' + escapeHtml(name) + '">x</button>' +
                        '</span>';
                }).join('');
                bodyHtml += '</div></div>';

                if (ACTIVE_MERGE_ENABLED && selectedNames.length > 0) {
                    bodyHtml += '<div><div class="consumer-merge-notice__section-label">Ringkasan nama yang akan digabung</div>';
                    bodyHtml += renderConsumerSummaryCards(selectedNames);
                    bodyHtml += '</div>';
                }

                let actionsHtml = '<button type="button" class="btn-primary btn-sm" data-enable-name-merge="1">Merge Nama</button>';
                if (ACTIVE_MERGE_ENABLED) {
                    actionsHtml += '<button type="button" class="btn-secondary btn-sm" data-disable-name-merge="1">Jangan Merge</button>';
                }

                let footHtml = '';
                if (ACTIVE_MERGE_ENABLED) {
                    footHtml += '<strong>Mode merge aktif.</strong> Jika disimpan, semua nama yang dipilih akan diarahkan ke <strong>' + escapeHtml(cname) + '</strong>.';
                } else {
                    footHtml += '<div class="consumer-merge-notice__hint">Transaksi belum diblokir. Klik <strong>Merge Nama</strong> hanya jika nama mirip ini memang orang yang sama.</div>';
                }

                showConsumerNotice({
                    title: '<strong>' + escapeHtml(cname) + '</strong> memiliki nama yang mirip dengan riwayat transaksi lain.',
                    text: 'Periksa dulu apakah kandidat di bawah ini memang orang yang sama agar typo atau salah penulisan bisa diperbaiki ke semua hari.',
                    body: bodyHtml,
                    actions: actionsHtml,
                    foot: footHtml,
                    locked: false
                });
                return;
            }
        }

        function onPackageChange() {
            applyPackageMode(getSelectedPackageMode(), {
                preserveSelections: true
            });
            saveFormState();
            recalcTotals();
        }

        function updateCustomDateVisibility() {
            const rangeSel = document.getElementById('rangeSelect');
            const customRow = document.getElementById('customDateRow');
            if (!rangeSel || !customRow) return;

            customRow.classList.toggle('hidden', rangeSel.value !== 'custom');
        }

        function clearFormInputs() {
            const consumerInput = document.querySelector('input[name="consumer_name"]');
            const btnSubmit = document.getElementById('btnSubmit');

            if (consumerInput) consumerInput.value = '';
            clearOcrIdentityPayload();

            ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            applyPackageMode(DEFAULT_PACKAGE_MODE, {
                preserveSelections: true
            });

            // clear localStorage
            try {
                localStorage.removeItem(STORAGE_KEY);
            } catch (e) {}

            // Jangan hapus notice kalau fairness sedang aktif
            if (!FAIRNESS_STATE.locked) {
                clearConsumerNotice();
            }

            if (btnSubmit) {
                if (PROFILE_INCOMPLETE) {
                    applyProfileRequirementLock();
                } else {
                    btnSubmit.disabled = false;
                    btnSubmit.classList.remove('btn-disabled');
                }
            }

            IS_OVER_LIMIT = false;
            clearBlacklistNotice();
            const forceOver = document.getElementById('force_overlimit');
            if (forceOver) forceOver.value = '0';

            recalcTotals();
        }

        function handleSaveClick() {
            const btnSubmit = document.getElementById('btnSubmit');
            const form = document.getElementById('saleForm');
            const consumerInput = document.getElementById('consumerNameInput');

            if (PROFILE_INCOMPLETE) {
                alert(PROFILE_NOTICE_TEXT || 'Lengkapi data profil terlebih dahulu.');
                applyProfileRequirementLock();
                return;
            }

            const cname = formatConsumerName(consumerInput.value);
            consumerInput.value = cname;
            updateSimilarConsumerBox();

            if (!looksLikeCitizenId(cname)) {
                alert('Citizen ID Konsumen tidak valid. Gunakan huruf besar atau kombinasi huruf besar dan angka, contoh RH39IQLC.');
                return;
            }

            const baseTotals = getBaseTotalsForConsumer(cname);
            const hasPrevious = (baseTotals.bandage + baseTotals.ifaks + baseTotals.painkiller) > 0;

            const totalPriceDisplay = document.getElementById('totalPriceDisplay');
            const totalText = totalPriceDisplay ? totalPriceDisplay.textContent : '$ 0';

            const forceOverInput = document.getElementById('force_overlimit');

            // if (FAIRNESS_STATE.locked) {
            //     alert('Transaksi diblokir oleh sistem fairness.');
            //     return;
            // }

            // ===============================
            // COOLDOWN CLIENT (UX SAJA)
            // ===============================
            if (typeof window.getFarmasiCooldownRemaining === 'function') {
                const remain = window.getFarmasiCooldownRemaining();
                if (remain > 0) {
                    alert(`Mohon tunggu ${remain} detik sebelum transaksi berikutnya.`);
                    return;
                }
            }

            if (CONSUMER_LOCK) {
                alert('Citizen ID ini sudah punya transaksi hari ini. 1 Citizen ID hanya boleh 1 transaksi per hari.');
                return;
            }

            if (BLACKLIST_LOCK) {
                const blacklistInfo = getBlacklistInfo(cname);
                const blacklistNote = blacklistInfo && blacklistInfo.note ? '\n\nNote blacklist: ' + blacklistInfo.note : '';
                alert('Citizen ID ini telah di-blacklist dan transaksi tidak dapat disimpan.' + blacklistNote);
                return;
            }

            if (forceOverInput) {
                // default: tidak override
                forceOverInput.value = '0';
            }

            let msg = "";

            if (IS_OVER_LIMIT) {
                // Kasus: kalau disimpan, dia akan MELEBIHI batas harian
                msg += "Orang ini telah mencapai atau akan melewati batas maksimal pembelian harian.\n\n";

                if (cname) {
                    msg += "Citizen ID Konsumen: " + cname + "\n\n" +
                        "Total SEBELUM transaksi ini (data di database):\n" +
                        "- Bandage   : " + baseTotals.bandage + "/" + MAX_BANDAGE + "\n" +
                        "- IFAKS     : " + baseTotals.ifaks + "/" + MAX_IFAKS + "\n" +
                        "- Painkiller: " + baseTotals.painkiller + "/" + MAX_PAINKILLER + "\n\n";
                }

                msg +=
                    "Yakin ingin TETAP memasukkan transaksi ini ke database " +
                    "walaupun batas maksimal satu hari sudah tercapai?\n\n" +
                    "Total saat ini: " + totalText + "\n\n" +
                    "Pilih OK (Yes) untuk menyimpan ke database, atau Cancel untuk membatalkan.";

                const ok = confirm(msg);
                if (!ok) {
                    return;
                }

                // User setuju override → beritahu server
                if (forceOverInput) {
                    forceOverInput.value = '1';
                }
            } else {
                // Tidak melewati batas, tapi mungkin sudah pernah beli
                msg += "Yakin ingin menyimpan transaksi ke database?\n\n";

                if (cname) {
                    msg += "Citizen ID Konsumen: " + cname + "\n\n";

                    if (ACTIVE_MERGE_ENABLED && ACTIVE_MERGE_CANDIDATES.length > 0) {
                        msg +=
                            "Nama yang akan digabung ke nama ini:\n- " +
                            ACTIVE_MERGE_CANDIDATES.join("\n- ") +
                            "\n\n";
                    }

                    if (hasPrevious) {
                        msg +=
                            "Catatan: orang ini sudah pernah melakukan pembelian hari ini.\n" +
                            "Total SEBELUM transaksi ini (data di database):\n" +
                            "- Bandage   : " + baseTotals.bandage + "/" + MAX_BANDAGE + "\n" +
                            "- IFAKS     : " + baseTotals.ifaks + "/" + MAX_IFAKS + "\n" +
                            "- Painkiller: " + baseTotals.painkiller + "/" + MAX_PAINKILLER + "\n\n";
                    }
                }

                msg +=
                    "Total saat ini: " + totalText + "\n\n" +
                    "Pilih OK (Yes) untuk menyimpan, atau Cancel untuk kembali mengecek.";

                const ok = confirm(msg);
                if (!ok) {
                    return;
                }
            }

            // Lindungi dari double submit / klik cepat
            if (btnSubmit) {
                btnSubmit.disabled = true;
            }

            if (form) {
                if (typeof window.setFarmasiCooldown === 'function') {
                    window.setFarmasiCooldown(60);
                }
                form.submit();
            }
        }

        applyProfileRequirementLock();

        function confirmBulkDelete() {
            const checked = document.querySelectorAll('.row-check:checked').length;
            if (!checked) {
                alert('Tidak ada transaksi yang dipilih untuk dihapus.');
                return false;
            }
            return confirm(
                'Yakin ingin menghapus ' + checked + ' transaksi terpilih?\n' +
                'Hanya transaksi milik Anda yang akan dihapus di server.'
            );
        }

        // Fallback: tetap hitung TOTAL footer meski DataTables gagal ter-load
        function updateSalesTableFooterTotalsFallback() {
            const table = document.getElementById('salesTable');
            if (!table) return;

            const tfootRow = table.querySelector('tfoot tr');
            if (!tfootRow || !tfootRow.cells || tfootRow.cells.length < 6) return;

            function intVal(text) {
                if (text == null) return 0;
                if (typeof text === 'number') return text;
                return String(text).replace(/[^\d]/g, '') * 1 || 0;
            }

            let totalBandage = 0;
            let totalIfaks = 0;
            let totalPain = 0;
            let totalPrice = 0;
            let totalBonus = 0;

            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(function(row) {
                const cells = row.cells;
                if (!cells || cells.length < 11) return;
                totalBandage += intVal(cells[6].textContent);
                totalIfaks += intVal(cells[7].textContent);
                totalPain += intVal(cells[8].textContent);
                totalPrice += intVal(cells[9].textContent);
                totalBonus += intVal(cells[10].textContent);
            });

            // Struktur footer: [TOTAL(colspan=6), bandage, ifaks, pain, price, bonus]
            tfootRow.cells[1].textContent = totalBandage;
            tfootRow.cells[2].textContent = totalIfaks;
            tfootRow.cells[3].textContent = totalPain;
            tfootRow.cells[4].textContent = formatDollar(totalPrice);
            tfootRow.cells[5].textContent = formatDollar(totalBonus);
        }

        // ===== JAM & TANGGAL REALTIME (BERDASARKAN WAKTU LOKAL PERANGKAT) =====
        function getIndonesiaTimeZoneName(date) {
            // getTimezoneOffset = selisih terhadap UTC dalam menit (WIB = -420, WITA = -480, WIT = -540)
            const offsetMinutes = -date.getTimezoneOffset();

            if (offsetMinutes === 7 * 60) return 'WIB (UTC+7)';
            if (offsetMinutes === 8 * 60) return 'WIB (UTC+7)';
            if (offsetMinutes === 9 * 60) return 'WIB (UTC+7)';

            // Jika di luar Indonesia, fallback ke label umum
            return 'Zona waktu lokal';
        }

        function updateLocalClock() {
            const el = document.getElementById('localClock');
            if (!el) return;
            try {
                const now = new Date();
                const tzName = getIndonesiaTimeZoneName(now);

                let tanggal = '';
                let jam = '';

                if (now.toLocaleDateString) {
                    try {
                        tanggal = now.toLocaleDateString('id-ID', {
                            weekday: 'long',
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                    } catch (e) {
                        tanggal = now.toLocaleDateString();
                    }
                }

                if (now.toLocaleTimeString) {
                    try {
                        jam = now.toLocaleTimeString('id-ID', {
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit',
                            hour12: false
                        });
                    } catch (e) {
                        jam = now.toLocaleTimeString();
                    }
                }

                if (!tanggal && !jam) {
                    el.textContent = now.toString();
                    return;
                }

                el.textContent = (tanggal || '') + ' • ' + (jam || '') + ' (' + tzName + ')';
            } catch (e) {
                // Jangan sampai error jam mematikan fitur lain (total/Datatables)
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // ===== Inisialisasi jam & tanggal realtime =====
            updateLocalClock();
            setInterval(updateLocalClock, 1000);

            function renderPricePerPcs() {
                if (document.getElementById('priceBandage')) {
                    document.getElementById('priceBandage').textContent =
                        formatDollar(PRICE_PER_PCS.bandage || 0);
                }
                if (document.getElementById('priceIfaks')) {
                    document.getElementById('priceIfaks').textContent =
                        formatDollar(PRICE_PER_PCS.ifaks || 0);
                }
                if (document.getElementById('pricePainkiller')) {
                    document.getElementById('pricePainkiller').textContent =
                        formatDollar(PRICE_PER_PCS.painkiller || 0);
                }
            }

            // Bersihkan atau restore form state tergantung flag dari server
            if (SHOULD_CLEAR_FORM) {
                try {
                    localStorage.removeItem(STORAGE_KEY);
                } catch (e) {
                    // abaikan error localStorage
                }
                // form dibiarkan kosong (tidak di-restore)
            } else {
                // Kalau transaksi sebelumnya gagal / batal, tetap restore agar user bisa koreksi
                restoreFormState();
            }
            applyPackageMode(inferPackageMode(), {
                preserveSelections: true
            });

            // Listener perubahan paket
            ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('change', onPackageChange);
                }
            });
            // Listener nama konsumen → cek limit + save state
            const consumerInput = document.querySelector('input[name="consumer_name"]');
            if (consumerInput) {
                ['input', 'change', 'blur'].forEach(function(evt) {
                    consumerInput.addEventListener(evt, function() {
                        const formattedValue = formatConsumerName(consumerInput.value);
                        if (consumerInput.value !== formattedValue) {
                            consumerInput.value = formattedValue;
                        }
                        const ocrCitizenIdInput = document.getElementById('ocr_citizen_id');
                        if (ocrCitizenIdInput && ocrCitizenIdInput.value && consumerInput.value !== ocrCitizenIdInput.value) {
                            clearOcrIdentityPayload();
                        }
                        saveFormState();
                        updateSimilarConsumerBox();
                        recalcTotals();
                    });
                });
            }

            const similarConsumerBox = document.getElementById('similarConsumerBox');
            if (similarConsumerBox) {
                similarConsumerBox.addEventListener('click', function(event) {
                    const removeBtn = event.target.closest('[data-remove-merge]');
                    if (removeBtn) {
                        const nameToRemove = removeBtn.getAttribute('data-remove-merge') || '';
                        ACTIVE_MERGE_CANDIDATES = ACTIVE_MERGE_CANDIDATES.filter(function(name) {
                            return name !== nameToRemove;
                        });
                        syncMergeHiddenFields();
                        updateSimilarConsumerBox();
                        recalcTotals();
                        return;
                    }

                    const btn = event.target.closest('[data-consumer-name]');
                    if (!btn) return;

                    const consumerInput = document.getElementById('consumerNameInput');
                    if (!consumerInput) return;

                    consumerInput.value = formatConsumerName(btn.getAttribute('data-consumer-name') || '');
                    ACTIVE_MERGE_CANDIDATES = [];
                    ACTIVE_MERGE_ENABLED = false;
                    saveFormState();
                    syncMergeHiddenFields();
                    updateSimilarConsumerBox();
                    recalcTotals();
                });
            }

            const consumerNotice = document.getElementById('consumerNotice');
            if (consumerNotice) {
                consumerNotice.addEventListener('click', async function(event) {
                    const removeBtn = event.target.closest('[data-notice-remove-merge]');
                    if (removeBtn) {
                        const nameToRemove = removeBtn.getAttribute('data-notice-remove-merge') || '';
                        ACTIVE_MERGE_CANDIDATES = ACTIVE_MERGE_CANDIDATES.filter(function(name) {
                            return name !== nameToRemove;
                        });
                        syncMergeHiddenFields();
                        updateSimilarConsumerBox();
                        recalcTotals();
                        return;
                    }

                    const enableBtn = event.target.closest('[data-enable-name-merge]');
                    if (enableBtn) {
                        await executeImmediateNameMerge();
                        return;
                    }

                    const disableBtn = event.target.closest('[data-disable-name-merge]');
                    if (disableBtn) {
                        ACTIVE_MERGE_ENABLED = false;
                        syncMergeHiddenFields();
                        recalcTotals();
                    }
                });
            }

            const rangeSel = document.getElementById('rangeSelect');
            if (rangeSel) {
                rangeSel.addEventListener('change', updateCustomDateVisibility);
            }

            updateCustomDateVisibility();
            updateSimilarConsumerBox();
            syncMergeHiddenFields();
            recalcTotals(); // hitung awal berdasarkan form yang dipulihkan
            renderPricePerPcs();
            updateSalesTableFooterTotalsFallback();

            // ===== Auto hide alert setelah 5 detik =====
            setTimeout(function() {
                document.querySelectorAll('.alert-warning, .alert-error, .alert-info').forEach(function(el) {
                    el.style.transition = 'opacity 0.5s';
                    el.style.opacity = '0';
                    setTimeout(function() {
                        if (el.parentNode) {
                            el.parentNode.removeChild(el);
                        }
                    }, 600);
                });
            }, 5000);

            // ===== Inisialisasi DataTables untuk tabel transaksi =====
            if (!window.jQuery) {
                console.warn('jQuery tidak tersedia: tabel transaksi tanpa DataTables.');
                return;
            }

            let table = null;
            if (jQuery.fn.DataTable) {
                table = jQuery('#salesTable').DataTable({
                    pageLength: 10,
                    scrollX: true,
                    autoWidth: false,
                    order: [
                        [1, 'desc']
                    ],
                    columnDefs: [{
                        targets: 0,
                        orderable: false,
                        searchable: false
                    }],
                    language: {
                        url: "<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>"
                    },
                    footerCallback: function(row, data, start, end, display) {
                        let api = this.api();

                        function intVal(i) {
                            return typeof i === 'string' ?
                                i.replace(/[^\d]/g, '') * 1 :
                                typeof i === 'number' ?
                                i :
                                0;
                        }

                        let totalBandage = api.column(6, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalIfaks = api.column(7, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalPain = api.column(8, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalPrice = api.column(9, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalBonus = api.column(10, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        // ⬇️ INI KUNCI UTAMANYA
                        jQuery(api.column(6).footer()).html(totalBandage);
                        jQuery(api.column(7).footer()).html(totalIfaks);
                        jQuery(api.column(8).footer()).html(totalPain);
                        jQuery(api.column(9).footer()).html(formatDollar(totalPrice));
                        jQuery(api.column(10).footer()).html(formatDollar(totalBonus));
                    }
                });
            } else {
                console.warn('DataTables tidak tersedia: fallback ke tabel biasa.');
            }

            const $selectAll = jQuery('#selectAll');
            const $bulkBtn = jQuery('#btnBulkDelete');
            const $selectAllRowsBtn = jQuery('#btnSelectAllRows');
            const $deselectAllRowsBtn = jQuery('#btnDeselectAllRows');
            const $bulkControls = jQuery('#bulkSelectionControls');
            const $bulkSelectionCount = jQuery('#bulkSelectionCount');

            function getRowCheckboxes() {
                if (table) {
                    return jQuery(table.rows({
                        search: 'applied'
                    }).nodes()).find('.row-check');
                }

                return jQuery('#salesTable tbody .row-check');
            }

            function syncSelectAllState() {
                const $checkboxes = getRowCheckboxes();
                const total = $checkboxes.length;
                const checked = $checkboxes.filter(':checked').length;

                $selectAll.prop('checked', total > 0 && checked === total);
                $selectAll.prop('indeterminate', checked > 0 && checked < total);
            }

            function updateBulkButton() {
                const checkedCount = getRowCheckboxes().filter(':checked').length;
                const anyChecked = checkedCount > 0;
                $bulkBtn.prop('disabled', !anyChecked);
                $bulkControls.toggleClass('hidden', !anyChecked);
                $bulkSelectionCount.text(checkedCount + ' data terpilih');
                syncSelectAllState();
            }

            function setAllRowsChecked(checked) {
                getRowCheckboxes().prop('checked', checked);
                $selectAll.prop('checked', checked);
                $selectAll.prop('indeterminate', false);
                updateBulkButton();
            }

            // Select/Deselect all (semua baris sesuai filter DataTables)
            $selectAll.on('click', function() {
                setAllRowsChecked(this.checked);
            });

            $selectAllRowsBtn.on('click', function() {
                setAllRowsChecked(true);
            });

            $deselectAllRowsBtn.on('click', function() {
                setAllRowsChecked(false);
            });

            // Per row checkbox
            jQuery(document).on('change', '.row-check', function() {
                updateBulkButton();
            });

            if (table) {
                table.on('draw', function() {
                    updateBulkButton();
                });
            }

            updateBulkButton();
        });
    </script>
    <script>
        (function() {
            if (window.EMSRealtime) {
                return;
            }

            const loggedErrors = {};

            function logOnce(key, message, detail) {
                if (loggedErrors[key]) {
                    return;
                }
                loggedErrors[key] = true;
                if (typeof detail === 'undefined') {
                    console.warn(message);
                    return;
                }
                console.warn(message, detail);
            }

            function previewText(value) {
                return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 140);
            }

            async function safeFetchJSON(url, fetchOptions, runtimeOptions) {
                const options = fetchOptions || {};
                const settings = runtimeOptions || {};
                const timeoutMs = settings.timeoutMs || 8000;
                const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
                const requestOptions = Object.assign({
                    cache: 'no-store',
                    credentials: 'same-origin'
                }, options);

                let timeoutId = null;
                if (controller) {
                    requestOptions.signal = controller.signal;
                    timeoutId = setTimeout(function() {
                        controller.abort();
                    }, timeoutMs);
                }

                try {
                    const res = await fetch(url, requestOptions);
                    const contentType = String(res.headers.get('content-type') || '').toLowerCase();

                    if (!res.ok) {
                        let bodyPreview = '';
                        try {
                            bodyPreview = previewText(await res.text());
                        } catch (e) {}

                        return {
                            ok: false,
                            url: url,
                            status: res.status,
                            reason: 'http',
                            contentType: contentType,
                            bodyPreview: bodyPreview
                        };
                    }

                    if (contentType.indexOf('application/json') === -1 && contentType.indexOf('text/json') === -1) {
                        let bodyPreview = '';
                        try {
                            bodyPreview = previewText(await res.text());
                        } catch (e) {}

                        return {
                            ok: false,
                            url: url,
                            status: res.status,
                            reason: 'invalid_content_type',
                            contentType: contentType,
                            bodyPreview: bodyPreview
                        };
                    }

                    try {
                        return {
                            ok: true,
                            url: url,
                            status: res.status,
                            data: await res.json()
                        };
                    } catch (e) {
                        return {
                            ok: false,
                            url: url,
                            status: res.status,
                            reason: 'invalid_json',
                            error: e.message || String(e)
                        };
                    }
                } catch (e) {
                    return {
                        ok: false,
                        url: url,
                        status: 0,
                        reason: e && e.name === 'AbortError' ? 'timeout' : 'network',
                        error: e && e.message ? e.message : String(e)
                    };
                } finally {
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                    }
                }
            }

            function createPollingTask(config) {
                const interval = config.interval || 3000;
                const maxInterval = config.maxInterval || 30000;
                const runWhenHidden = !!config.runWhenHidden;
                const serverErrorPauseMs = config.serverErrorPauseMs || 300000;
                let stopped = false;
                let timer = null;
                let inFlight = false;
                let failCount = 0;
                let lastFailureStatus = 0;
                let pauseUntil = 0;

                function canPollNow() {
                    return runWhenHidden || !document.hidden;
                }

                function getPauseRemaining() {
                    return Math.max(0, pauseUntil - Date.now());
                }

                function nextDelay() {
                    const pauseRemaining = getPauseRemaining();
                    if (pauseRemaining > 0) {
                        return pauseRemaining;
                    }

                    if (failCount <= 0) {
                        return interval;
                    }

                    const multiplier = (lastFailureStatus === 507 || lastFailureStatus >= 500) ? 4 : 2;
                    return Math.min(maxInterval, interval * multiplier * Math.pow(2, Math.min(failCount - 1, 3)));
                }

                function schedule(delay) {
                    if (stopped) {
                        return;
                    }
                    if (timer) {
                        clearTimeout(timer);
                    }
                    timer = setTimeout(run, Math.max(250, delay));
                }

                async function run() {
                    if (stopped || inFlight) {
                        return;
                    }

                    const pauseRemaining = getPauseRemaining();
                    if (pauseRemaining > 0) {
                        schedule(pauseRemaining);
                        return;
                    }

                    if (!canPollNow()) {
                        schedule(interval);
                        return;
                    }

                    inFlight = true;

                    try {
                        const result = await safeFetchJSON(config.url, config.fetchOptions || {}, {
                            timeoutMs: config.timeoutMs || 8000
                        });

                        if (result.ok) {
                            failCount = 0;
                            lastFailureStatus = 0;
                            if (config.onSuccess) {
                                config.onSuccess(result.data, result);
                            }
                        } else {
                            failCount += 1;
                            lastFailureStatus = parseInt(result.status || 0, 10);
                            if (lastFailureStatus === 507 || lastFailureStatus >= 500) {
                                pauseUntil = Date.now() + serverErrorPauseMs;
                            }
                            if (config.onError) {
                                config.onError(result, failCount);
                            } else {
                                logOnce(
                                    'poll:' + config.url + ':' + result.reason,
                                    'Realtime request failed: ' + config.url,
                                    result
                                );
                            }
                        }
                    } finally {
                        inFlight = false;
                        schedule(nextDelay());
                    }
                }

                return {
                    start: function() {
                        document.addEventListener('visibilitychange', handleVisibilityChange);
                        schedule(0);
                    },
                    stop: function() {
                        stopped = true;
                        if (timer) {
                            clearTimeout(timer);
                        }
                        document.removeEventListener('visibilitychange', handleVisibilityChange);
                    },
                    trigger: function() {
                        schedule(0);
                    }
                };

                function handleVisibilityChange() {
                    if (stopped) {
                        return;
                    }

                    if (canPollNow()) {
                        schedule(getPauseRemaining() || 1000);
                        return;
                    }

                    if (timer) {
                        clearTimeout(timer);
                        timer = null;
                    }
                }
            }

            window.EMSRealtime = {
                createPollingTask: createPollingTask,
                logOnce: logOnce,
                safeFetchJSON: safeFetchJSON
            };
        })();
    </script>
    <script>
        (function() {
            const container = document.getElementById('onlineMedicsContainer');
            const totalBadge = document.getElementById('totalMedicsBadge');
            if (!container || !totalBadge) return;

            // STATE GLOBAL (JANGAN RESET SETIAP RENDER)
            let baseTimestamp = {};
            let lastDataHash = '';

            function updateTotal(count) {
                totalBadge.textContent = count + ' orang';
                totalBadge.style.background = count === 0 ? '#fee2e2' : '#dcfce7';
                totalBadge.style.color = count === 0 ? '#991b1b' : '#166534';
            }

            function escapeHtml(str) {
                return (str || '').replace(/[&<>"']/g, c =>
                    ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#39;'
                    } [c])
                );
            }

            // =============================================
            // RENDER MEDIS (HANYA KALAU DATA BERUBAH)
            // =============================================
            function renderMedics(data) {
                const dataHash = JSON.stringify(data.map(m => ({
                    id: m.user_id,
                    name: m.medic_name,
                    tx: m.total_transaksi,
                    seconds: m.weekly_online_seconds,
                    allTx: m.total_transaksi_semua,
                    batch: m.medic_batch,
                    role: m.medic_role_label,
                    division: m.medic_division_label
                })));

                // ⬇️ SKIP RENDER KALAU DATA SAMA
                if (dataHash === lastDataHash) return;
                lastDataHash = dataHash;

                // ⬇️ RESET baseTimestamp HANYA KALAU RENDER ULANG
                baseTimestamp = {};

                container.innerHTML = '';
                updateTotal(data.length);

                if (!data.length) {
                    container.innerHTML = '<p class="meta-text">Tidak ada medis yang sedang online.</p>';
                    return;
                }

                data.forEach(m => {
                    const row = document.createElement('div');
                    row.className = 'online-medic-row';
                    row.innerHTML = `
                <div class="online-medic-main">
                    <div class="online-medic-head">
                        <div class="online-medic-identity">
                            <strong>${escapeHtml(m.medic_name)}</strong>
                            <div class="online-medic-subtitle">
                                ${escapeHtml(m.medic_position_label || m.medic_jabatan || '-')} •
                                ${escapeHtml(m.medic_role_label || '-')} •
                                ${escapeHtml(m.medic_division_label || '-')}
                            </div>
                        </div>
                        <div class="online-medic-badges">
                            <span class="weekly-badge">Minggu ini: ${m.weekly_transaksi} trx</span>
                            <span class="weekly-badge weekly-badge-muted">Batch ${m.medic_batch ? escapeHtml(String(m.medic_batch)) : '-'}</span>
                        </div>
                    </div>
                    <div class="online-medic-inline-meta">
                        <span><strong>Join:</strong> ${escapeHtml(m.join_duration_text || '-')}</span>
                        <span><strong>Online:</strong> <strong class="weekly-online" data-seconds="${m.weekly_online_seconds}" data-user-id="${m.user_id}">${escapeHtml(m.weekly_online_text)}</strong></span>
                    </div>
                    <button type="button" class="btn-force-offline"
                        data-user-id="${m.user_id}"
                        data-name="${escapeHtml(m.medic_name)}"
                        data-jabatan="${escapeHtml(m.medic_position_label || m.medic_jabatan || '-')}">
                        Force Offline
                    </button>
                </div>
                <div class="online-medic-stats">
                    <div class="online-medic-stat-card">
                        <span class="online-medic-stat-label">Transaksi Hari Ini</span>
                        <strong class="tx">${m.total_transaksi} trx</strong>
                    </div>
                    <div class="online-medic-stat-card">
                        <span class="online-medic-stat-label">Total Seluruh Transaksi</span>
                        <strong>${m.total_transaksi_semua} trx</strong>
                    </div>
                    <div class="online-medic-stat-card">
                        <span class="online-medic-stat-label">Pendapatan Hari Ini</span>
                        <strong class="amount">$ ${Number(m.total_pendapatan).toLocaleString()}</strong>
                    </div>
                    <div class="online-medic-stat-card online-medic-stat-card-success">
                        <span class="online-medic-stat-label">Bonus Hari Ini</span>
                        <strong class="bonus text-success-xs">$ ${Number(m.bonus_40).toLocaleString()}</strong>
                    </div>
                </div>
            `;
                    container.appendChild(row);
                });
            }

            // =============================================
            // UPDATE TIMER (JALAN TERUS TANPA RENDER ULANG)
            // =============================================
            function updateOnlineDurations() {
                const spans = document.querySelectorAll('.weekly-online');

                spans.forEach(span => {
                    const baseSeconds = parseInt(span.dataset.seconds || 0);
                    const userId = span.dataset.userId || 'unknown';

                    // ⬇️ SIMPAN TIMESTAMP PERTAMA KALI SAJA
                    if (!baseTimestamp[userId]) {
                        baseTimestamp[userId] = {
                            start: Date.now(),
                            base: baseSeconds
                        };
                    }

                    // ⬇️ HITUNG ELAPSED (DETIK SEJAK PERTAMA KALI KETEMU)
                    const elapsed = Math.floor((Date.now() - baseTimestamp[userId].start) / 1000);
                    const totalSeconds = baseTimestamp[userId].base + elapsed;

                    const hours = Math.floor(totalSeconds / 3600);
                    const minutes = Math.floor((totalSeconds % 3600) / 60);
                    const seconds = totalSeconds % 60;

                    span.textContent = `${hours}j ${minutes}m ${seconds}d`;
                });
            }

            function handleMedicsError(result, failCount) {
                if (!lastDataHash) {
                    container.innerHTML = '<p class="meta-text">Data realtime medis sementara tidak tersedia.</p>';
                    updateTotal(0);
                }

                if (failCount === 1) {
                    window.EMSRealtime.logOnce(
                        'online-medics:' + result.reason,
                        'Realtime medis sementara gagal dimuat.',
                        result
                    );
                }
            }

            const medicsPoller = window.EMSRealtime.createPollingTask({
                url: window.emsUrl('/actions/get_online_medics.php'),
                interval: 15000,
                maxInterval: 180000,
                timeoutMs: 6000,
                onSuccess: function(data) {
                    if (!Array.isArray(data)) {
                        handleMedicsError({
                            reason: 'invalid_payload'
                        }, 1);
                        return;
                    }

                    renderMedics(data);
                },
                onError: handleMedicsError
            });

            setInterval(updateOnlineDurations, 1000);
            medicsPoller.start();
        })();
    </script>

    <script>
        (function() {
            const container = document.getElementById('activityFeedList');
            if (!container) return;

            const MAX_ITEMS = 30;
            let lastActivityHash = '';

            let lastActivityKey = null;
            let isFirstLoad = true;

            function playSound() {
                return;
            }

            // ===============================
            // ICON MAPPING
            // ===============================
            const ACTIVITY_ICONS = {
                'transaction': 'TRX',
                'online': 'ON',
                'offline': 'OFF',
                'force_offline': 'FORCE',
                'delete': 'DEL',
                'leave_request': 'CUTI',
                'leave_approved': 'OK',
                'leave_rejected': 'NO',
                'on_leave': 'IZIN',
                'resign_request': 'RESIGN',
                'resign': 'OUT',
                'promotion_request': 'JBTN',
                'promotion_approved': 'UP',
                'promotion_rejected': 'NO',
                'medical_record': 'RM',
                'medical_service': 'EMS',
                'account_created': 'NEW',
                'applicant_new': 'DAFTAR',
                'candidate_accepted': 'OK',
                'candidate_rejected': 'FAIL'
            };

            // ===============================
            // FORMAT RELATIVE TIME (REALTIME)
            // ===============================
            function getRelativeTime(timestamp) {
                const now = Math.floor(Date.now() / 1000);
                const diff = now - timestamp;

                if (diff < 60) return 'Baru saja';
                if (diff < 3600) {
                    const mins = Math.floor(diff / 60);
                    return `${mins} menit lalu`;
                }
                if (diff < 86400) {
                    const hours = Math.floor(diff / 3600);
                    return `${hours} jam lalu`;
                }

                // Lebih dari 1 hari, tampilkan tanggal
                const date = new Date(timestamp * 1000);
                const day = String(date.getDate()).padStart(2, '0');
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                const month = months[date.getMonth()];
                const hours = String(date.getHours()).padStart(2, '0');
                const mins = String(date.getMinutes()).padStart(2, '0');

                return `${day} ${month} ${hours}:${mins}`;
            }

            // ===============================
            // RENDER ITEM
            // ===============================
            function renderActivity(data) {
                const item = document.createElement('div');
                item.className = 'activity-feed-item';
                item.dataset.id = data.id;
                item.dataset.timestamp = data.timestamp; // ⬅️ SIMPAN TIMESTAMP

                const iconClass = `activity-icon type-${data.type}`;

                item.innerHTML = `
            <div class="${iconClass}">
                ${ACTIVITY_ICONS[data.type] || 'LOG'}
            </div>
            <div class="activity-content">
                <div class="activity-medic">${escapeHtml(data.medic_name)}</div>
                <div class="activity-description">${escapeHtml(data.description)}</div>
                <div class="activity-time" data-timestamp="${data.timestamp}">
                    ${getRelativeTime(data.timestamp)}
                </div>
            </div>
        `;

                return item;
            }

            // ===============================
            // UPDATE TIME (SEMUA ITEM)
            // ===============================
            function updateAllTimes() {
                const timeElements = container.querySelectorAll('.activity-time');

                timeElements.forEach(el => {
                    const timestamp = parseInt(el.dataset.timestamp);
                    if (!timestamp) return;

                    el.textContent = getRelativeTime(timestamp);
                });
            }

            // ===============================
            // UPDATE LIST
            // ===============================
            function updateList(newActivities) {
                if (!newActivities.length) {
                    if (!lastActivityHash) {
                        container.innerHTML = '<p class="meta-text">Belum ada activity terbaru.</p>';
                    }
                    return;
                }

                const sortedActivities = newActivities.slice().sort(function(a, b) {
                    const left = parseInt(a.timestamp || 0, 10);
                    const right = parseInt(b.timestamp || 0, 10);

                    if (left === right) {
                        return String(b.id || '').localeCompare(String(a.id || ''));
                    }

                    return right - left;
                });

                const newestKey = `${sortedActivities[0].id}:${sortedActivities[0].timestamp}`;

                // BUNYI HANYA JIKA ADA ACTIVITY BARU
                lastActivityKey = newestKey;
                isFirstLoad = false;

                // ===== LOGIC LAMA TETAP =====
                const trimmedActivities = sortedActivities.slice(0, MAX_ITEMS);
                const newHash = JSON.stringify(trimmedActivities.map(function(activity) {
                    return `${activity.id}:${activity.timestamp}`;
                }));
                if (newHash === lastActivityHash) return;
                lastActivityHash = newHash;

                container.innerHTML = '';

                trimmedActivities.forEach(activity => {
                    container.appendChild(renderActivity(activity));
                });
            }

            // ===============================
            // FETCH DARI SERVER
            // ===============================
            function handleActivityError(result, failCount) {
                if (!lastActivityHash) {
                    container.innerHTML = '<p class="meta-text">Activity feed sementara tidak tersedia.</p>';
                }

                if (failCount === 1) {
                    window.EMSRealtime.logOnce(
                        'activities:' + result.reason,
                        'Activity feed sementara gagal dimuat.',
                        result
                    );
                }
            }

            // ===============================
            // ESCAPE HTML
            // ===============================
            function escapeHtml(str) {
                return (str || '').replace(/[&<>"']/g, c =>
                    ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#39;'
                    } [c])
                );
            }

            // ===============================
            // INIT & POLLING
            // ===============================
            const activitiesPoller = window.EMSRealtime.createPollingTask({
                url: window.emsUrl('/actions/get_activities.php'),
                interval: 20000,
                maxInterval: 180000,
                timeoutMs: 6000,
                onSuccess: function(data) {
                    if (!Array.isArray(data)) {
                        handleActivityError({
                            reason: 'invalid_payload'
                        }, 1);
                        return;
                    }

                    updateList(data);
                },
                onError: handleActivityError
            });

            activitiesPoller.start();

            // UPDATE TIME SETIAP 10 DETIK
            setInterval(updateAllTimes, 10000);

        })();
    </script>
    <div id="identityModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-lg p-0">
            <div class="modal-head px-5 py-4">
                <div class="modal-title inline-flex items-center gap-2">
                    <?= ems_icon('document-text', 'h-5 w-5') ?> <span>Scan Identitas</span>
                </div>
                <button type="button" onclick="closeIdentityScan()" class="btn-danger btn-compact">
                    <?= ems_icon('x-mark', 'h-4 w-4') ?> <span>Tutup</span>
                </button>
            </div>
            <div class="modal-content p-0">
                <iframe src="/dashboard/identity_test.php" class="block h-[calc(100vh-180px)] w-full border-0"></iframe>
            </div>
        </div>
    </div>

    <div id="identityViewModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-lg p-0">
            <div class="modal-head sticky top-0 rounded-t-3xl bg-white px-5 py-4">
                <div class="modal-title inline-flex items-center gap-2">
                    <?= ems_icon('clipboard-document-list', 'h-5 w-5') ?> <span>Data Konsumen</span>
                </div>
                <button type="button" onclick="closeIdentityViewModal()" class="btn-danger btn-compact">
                    <?= ems_icon('x-mark', 'h-4 w-4') ?> <span>Tutup</span>
                </button>
            </div>
            <div class="modal-content p-0">
                <div id="identityViewContent" class="max-h-[calc(100vh-220px)] overflow-auto p-5">
                    <p class="text-sm text-slate-500">Memuat data...</p>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
    function openIdentityViewModal(identityId) {
        const modal = document.getElementById('identityViewModal');
        const content = document.getElementById('identityViewContent');

        if (!modal || !content || !identityId) return;

        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
        content.innerHTML = '<p class="text-sm text-slate-500">Memuat data...</p>';

        fetch(window.emsUrl('/ajax/get_identity_detail.php?id=') + encodeURIComponent(identityId))
            .then(res => res.text())
            .then(html => {
                content.innerHTML = html;
            })
            .catch(err => {
                console.error('Error loading identity:', err);
                content.innerHTML = '<p class="text-sm text-rose-600">Gagal memuat data.</p>';
            });
    }

    function closeIdentityViewModal() {
        const modal = document.getElementById('identityViewModal');
        if (!modal) return;

        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeIdentityViewModal();
        }
    });
</script>

<script>
    function koreksiNamaKonsumen(oldName, newName) {
        const msg =
            `Yakin ingin mengoreksi nama konsumen?\n\n` +
            `DARI : ${oldName}\n` +
            `KE   : ${newName}\n\n` +
            `Semua transaksi lama akan ikut diperbaiki.\n\n` +
            `Klik OK untuk lanjut.`;

        if (!confirm(msg)) return;

        fetch(window.emsUrl('/actions/koreksi_nama_konsumen.php'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'old_name=' + encodeURIComponent(oldName) +
                    '&new_name=' + encodeURIComponent(newName)
            })
            .then(res => res.text())
            .then(text => {
                if (text.startsWith('OK|')) {
                    const count = text.split('|')[1];
                    alert(`Berhasil.\n${count} transaksi diperbaiki.`);
                    location.reload();
                } else {
                    alert('Gagal:\n' + text);
                }
            })
            .catch(err => {
                alert('Error koneksi');
                console.error(err);
            });
    }
</script>

<script>
    (function() {
        const notice = document.getElementById('dailyNotice');
        if (!notice) return;

        // Tampilkan hanya saat reload / first load
        notice.style.display = 'block';

        // Hilang otomatis setelah 10 detik
        setTimeout(function() {
            notice.style.transition = 'opacity 0.6s ease';
            notice.style.opacity = '0';

            setTimeout(function() {
                notice.style.display = 'none';
            }, 600);
        }, 10000);
    })();
</script>

<script>
    (function() {
        const badge = document.getElementById('farmasiStatusBadge');
        const text = document.getElementById('farmasiStatusText');
        if (!badge || !text) return;

        let lastStatus = null;

        function updateUI(status) {
            if (status === lastStatus) return;
            lastStatus = status;

            badge.classList.remove('status-online', 'status-offline');

            if (status === 'online') {
                badge.classList.add('status-online');
                text.textContent = 'ONLINE';
            } else {
                badge.classList.add('status-offline');
                text.textContent = 'OFFLINE';
            }
        }

        const statusPoller = window.EMSRealtime.createPollingTask({
            url: window.emsUrl('/actions/get_farmasi_status.php'),
            interval: 15000,
            maxInterval: 120000,
            timeoutMs: 5000,
            onSuccess: function(data) {
                if (!data || typeof data.status !== 'string') {
                    return;
                }
                badge.title = '';
                updateUI(data.status);
            },
            onError: function(result, failCount) {
                badge.title = 'Status realtime sementara tidak tersedia.';
                if (failCount === 1) {
                    window.EMSRealtime.logOnce(
                        'farmasi-status:' + result.reason,
                        'Status farmasi sementara gagal dimuat.',
                        result
                    );
                }
            }
        });

        statusPoller.start();
    })();
</script>

<script>
    (function() {

        let lastState = null;

        function applyFairnessData(data) {
            const selisih = parseInt(data.selisih || 0, 10);
            const blocked = !!data.blocked;

            const stateKey = blocked + ':' + selisih + ':' + data.user_status;
            if (stateKey === lastState) return;
            lastState = stateKey;

            clearFairnessNotice();

            // ===============================
            // HARD LOCK (FAIRNESS BLOCK)
            // ===============================
            if (blocked) {
                //     showFairnessNotice(`
                //     <strong>Distribusi transaksi tidak seimbang</strong><br><br>
                //     Anda memiliki <strong>${selisih}</strong> transaksi lebih banyak.<br><br>
                //     [Dokter] <strong>Silakan arahkan konsumen ke:</strong><br>
                //     <strong>${escapeHtml(data.medic_name || '-')}</strong><br>
                //     <small>
                //         ${escapeHtml(data.medic_jabatan || '-')}
                //         • ${parseInt(data.total_transaksi || 0, 10)} trx
                //     </small>
                // `);
                //     return;
            }

            // // ===============================
            // // EARLY WARNING (BELUM LOCK)
            // // ===============================
            // if (!blocked && selisih > 0) {

            //     clearFairnessNotice();

            //     const box = document.getElementById('fairnessNotice');
            //     if (!box) return;

            //     box.innerHTML = `
            //     ℹ️ <strong>Monitoring distribusi transaksi</strong><br>
            //     Selisih transaksi Anda saat ini:
            //     <strong>${selisih}</strong>.<br>
            //     Sistem akan <strong>mengunci otomatis</strong>
            //     jika selisih mencapai
            //     <strong>${threshold}</strong>.
            // `;
            //     box.style.display = 'block';
            //     return;
            // }

            // if (selisih >= threshold) {
            //     const box = document.getElementById('fairnessNotice');
            //     box.innerHTML = `
            //         <strong>Distribusi transaksi tidak seimbang</strong><br>
            //         Selisih transaksi Anda saat ini:
            //         <strong>${selisih}</strong>.<br><br>
            //         [Dokter] Medis dengan transaksi paling sedikit:<br>
            //         <strong>${escapeHtml(data.medic_name || '-')}</strong><br>
            //         <small>${escapeHtml(data.medic_jabatan || '-')}</small>
            //     `;
            //     box.style.display = 'block';
            //     return;
            // }

            // ===============================
            // AMAN TOTAL (SELISIH = 0)
            // ===============================
            clearFairnessNotice();
        }

        const fairnessPoller = window.EMSRealtime.createPollingTask({
            url: window.emsUrl('/actions/get_fairness_status.php'),
            interval: 20000,
            maxInterval: 120000,
            timeoutMs: 5000,
            onSuccess: function(data) {
                if (!data || typeof data.user_status !== 'string') {
                    return;
                }
                applyFairnessData(data);
            },
            onError: function(result, failCount) {
                clearFairnessNotice();
                if (failCount === 1) {
                    window.EMSRealtime.logOnce(
                        'fairness:' + result.reason,
                        'Fairness check sementara gagal dimuat.',
                        result
                    );
                }
            }
        });

        fairnessPoller.start();

    })();
</script>

<script>
    (function() {
        const box = document.getElementById('cooldownNotice');
        const btn = document.getElementById('btnSubmit');
        if (!box || !btn) return;

        let timer = null;
        let clockOffsetMs = 0;
        let cooldownUntilMs = 0;

        function getSyncedNowMs() {
            return Date.now() + clockOffsetMs;
        }

        function getCooldownRemainingSeconds() {
            if (!cooldownUntilMs) {
                return 0;
            }

            return Math.max(0, Math.ceil((cooldownUntilMs - getSyncedNowMs()) / 1000));
        }

        function clearCooldownUI() {
            box.style.display = 'none';
            box.innerHTML = '';
            btn.disabled = false;
            btn.classList.remove('btn-disabled');
            cooldownUntilMs = 0;
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
        }

        function renderCooldownUI() {
            const remain = getCooldownRemainingSeconds();
            if (remain <= 0) {
                clearCooldownUI();
                return;
            }

            btn.disabled = true;
            btn.classList.add('btn-disabled');
            box.innerHTML = `
                <strong>Cooldown transaksi</strong><br>
                Anda baru saja menyimpan transaksi.<br>
                Silakan tunggu <strong>${remain} detik</strong>
                sebelum transaksi berikutnya.
            `;
            box.style.display = 'block';
        }

        function startCooldownTimer() {
            renderCooldownUI();
            if (timer) {
                clearInterval(timer);
            }

            timer = setInterval(function() {
                renderCooldownUI();
            }, 250);
        }

        function applyCooldownData(data) {
            if (!data || !data.active) {
                clearCooldownUI();
                return;
            }

            if (typeof data.server_now === 'number') {
                clockOffsetMs = (data.server_now * 1000) - Date.now();
            }

            if (typeof data.cooldown_until === 'number') {
                cooldownUntilMs = data.cooldown_until * 1000;
            } else {
                const remain = parseInt(data.remain || 0, 10);
                cooldownUntilMs = getSyncedNowMs() + (Math.max(0, remain) * 1000);
            }

            startCooldownTimer();
        }

        window.getFarmasiCooldownRemaining = getCooldownRemainingSeconds;
        window.setFarmasiCooldown = function(seconds) {
            const remain = Math.max(0, parseInt(seconds || 0, 10));
            if (remain <= 0) {
                clearCooldownUI();
                return;
            }

            cooldownUntilMs = getSyncedNowMs() + (remain * 1000);
            startCooldownTimer();
        };

        fetch(window.emsUrl('/actions/get_global_cooldown.php'), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                applyCooldownData(data);
            })
            .catch(function(error) {
                clearCooldownUI();
                window.emsLogOnce(
                    'cooldown:init',
                    'Cooldown status sementara gagal dimuat.',
                    error
                );
            });
    })();
</script>


<script>
    (function() {
        const badge = document.getElementById('farmasiStatusBadge');
        const text = document.getElementById('farmasiStatusText');
        if (!badge || !text) return;

        let isBusy = false;

        badge.addEventListener('click', async function() {
            if (isBusy) return;

            const current = badge.dataset.status; // online / offline
            const next = current === 'online' ? 'offline' : 'online';

            // ==========================
            // KONFIRMASI USER
            // ==========================
            const message =
                next === 'offline' ?
                "Apakah Anda yakin ingin OFFLINE?\n\nAnda tidak akan menerima transaksi farmasi." :
                "Apakah Anda yakin ingin ONLINE?\n\nAnda akan mulai menerima transaksi farmasi.";

            if (!confirm(message)) {
                return; // batal
            }

            isBusy = true;

            try {
                const res = await fetch(window.emsUrl('/actions/toggle_farmasi_status.php'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        status: next
                    })
                });

                const json = await res.json();
                if (!json.success) {
                    alert(json.message || 'Gagal mengubah status');
                    isBusy = false;
                    return;
                }

                // ==========================
                // UPDATE UI LANGSUNG
                // ==========================
                badge.dataset.status = next;
                badge.classList.remove('status-online', 'status-offline');

                if (next === 'online') {
                    badge.classList.add('status-online');
                    text.textContent = 'ONLINE';
                } else {
                    badge.classList.add('status-offline');
                    text.textContent = 'OFFLINE';
                }

            } catch (e) {
                alert('Koneksi ke server gagal');
                console.error(e);
            }

            isBusy = false;
        });
    })();
</script>

<script>
    function openIdentityScan() {
        const modal = document.getElementById('identityModal');
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
    }

    function closeIdentityScan() {
        const modal = document.getElementById('identityModal');
        if (!modal) return;
        modal.style.display = 'none';
        modal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    window.addEventListener('message', function(e) {
        if (e.data && e.data.action === 'close_modal') {
            closeIdentityScan();
            return;
        }

        if (!e.data || !e.data.citizen_id) return;

        setOcrIdentityPayload({
            identity_id: e.data.identity_id || '',
            citizen_id: e.data.citizen_id || '',
            first_name: e.data.first_name || '',
            last_name: e.data.last_name || ''
        });

        alert('Scan KTP berhasil. Citizen ID terisi otomatis, silakan cek kembali sebelum simpan.');
        closeIdentityScan();
    });
</script>

<script>
    (function() {
        let targetUserId = null;
        let targetName = null;
        let targetJabatan = null; // TAMBAHAN (TIDAK MENGGANGGU YANG LAIN)

        const modal = document.getElementById('emsForceModal');
        const desc = document.getElementById('emsForceDesc');
        const reasonInput = document.getElementById('emsForceReason');
        if (!modal || !desc || !reasonInput) {
            return;
        }
        const btnConfirm = modal.querySelector('.ems-btn-confirm');
        const closeButtons = modal.querySelectorAll('.ems-btn-cancel, .modal-close-btn');
        if (!btnConfirm || !closeButtons.length) {
            return;
        }

        function openModal(userId, name, jabatan) {
            targetUserId = userId;
            targetName = name || '-';
            targetJabatan = jabatan;

            desc.innerHTML =
                `Nama Medis: <strong>${name}</strong><br>` +
                `Jabatan: <strong>${jabatan}</strong><br>` +
                `Status akan diubah menjadi <strong class="text-danger-strong">OFFLINE</strong>.`;

            reasonInput.value = '';
            modal.classList.remove('hidden');
            document.body.classList.add('modal-open');

            setTimeout(() => reasonInput.focus(), 50);
        }

        function closeModal() {
            modal.classList.add('hidden');
            document.body.classList.remove('modal-open');
            targetUserId = null;
            targetName = null;
            targetJabatan = null;
        }

        // Klik tombol Force Offline (delegation)
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-force-offline');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            openModal(
                btn.dataset.userId,
                btn.dataset.name,
                btn.dataset.jabatan // AMBIL JABATAN
            );
        });

        // Batal
        closeButtons.forEach(function(button) {
            button.addEventListener('click', closeModal);
        });

        // Konfirmasi Force Offline
        btnConfirm.addEventListener('click', async function() {
            const reason = reasonInput.value.trim();

            if (reason.length < 5) {
                alert('Alasan wajib diisi (min. 5 karakter)');
                reasonInput.focus();
                return;
            }

            btnConfirm.textContent = 'Memproses...';
            btnConfirm.style.pointerEvents = 'none';

            try {
                const res = await fetch(window.emsUrl('/actions/force_offline_medis.php'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        target_user_id: targetUserId,
                        reason: reason
                    })
                });

                const json = await res.json();

                if (!json.success) {
                    alert(json.message || 'Gagal force offline');
                    btnConfirm.textContent = 'Force Offline';
                    btnConfirm.style.pointerEvents = 'auto';
                    return;
                }

                closeModal();
                alert('Berhasil di-force offline');

            } catch (err) {
                console.error(err);
                alert('Koneksi server gagal');
            }

            btnConfirm.textContent = 'Force Offline';
            btnConfirm.style.pointerEvents = 'auto';
        });

    })();
</script>

<script>
    (function() {
        const stageEl = document.getElementById('farmasiQuizStage');
        const rankingEl = document.getElementById('farmasiQuizRanking');
        const historyEl = document.getElementById('farmasiQuizHistory');
        const weekLabelEl = document.getElementById('farmasiQuizWeekLabel');
        const statusBadgeEl = document.getElementById('farmasiQuizStatusBadge');
        const timerEl = document.getElementById('farmasiQuizTimerText');
        const fireworksEl = document.getElementById('farmasiQuizFireworks');
        const quizCardEl = document.getElementById('farmasiQuizCard');
        const quizTopAnchorEl = document.getElementById('farmasiQuizTopAnchor');
        const quizBottomAnchorEl = document.getElementById('farmasiQuizBottomAnchor');

        if (!stageEl || !rankingEl || !historyEl || !weekLabelEl || !statusBadgeEl || !timerEl || !fireworksEl) {
            return;
        }

        const stateUrl = window.emsUrl('/actions/get_farmasi_quiz_state.php');
        const submitUrl = window.emsUrl('/actions/submit_farmasi_quiz_answer.php');
        const finishUrl = window.emsUrl('/actions/finish_farmasi_quiz_session.php');
        const winAudio = new Audio(window.emsUrl('/assets/sound/notification.mp3'));
        const loseAudio = new Audio(window.emsUrl('/assets/sound/activity.mp3'));
        winAudio.preload = 'auto';
        loseAudio.preload = 'auto';
        winAudio.volume = 0.95;
        loseAudio.volume = 0.7;

        let quizState = null;
        let countdownTimer = null;
        let reviewQuestionId = null;
        let submitBusy = false;
        let completionFingerprint = '';
        let stateRefreshPending = false;

        function stopCountdown() {
            if (countdownTimer) {
                clearInterval(countdownTimer);
                countdownTimer = null;
            }
        }

        function formatDuration(seconds) {
            const total = Math.max(0, parseInt(seconds || 0, 10));
            const hours = Math.floor(total / 3600);
            const minutes = Math.floor((total % 3600) / 60);
            const secs = total % 60;
            return [hours, minutes, secs].map(function(value) {
                return String(value).padStart(2, '0');
            }).join(':');
        }

        function formatAccuracy(correctAnswers, wrongAnswers) {
            const correct = Math.max(0, parseInt(correctAnswers || 0, 10));
            const wrong = Math.max(0, parseInt(wrongAnswers || 0, 10));
            const total = correct + wrong;

            if (total <= 0) {
                return '0% akurasi';
            }

            const percent = Math.round((correct / total) * 100);
            return percent + '% akurasi';
        }

        function getSecondsUntil(target) {
            if (!target) return 0;
            const targetTime = new Date(target).getTime();
            if (Number.isNaN(targetTime)) return 0;
            return Math.max(0, Math.floor((targetTime - Date.now()) / 1000));
        }

        function setStatusBadge(text, variant) {
            statusBadgeEl.textContent = text;
            statusBadgeEl.className = 'farmasi-quiz-badge';
            if (variant) {
                statusBadgeEl.classList.add(variant);
            }
        }

        function syncQuizCardPosition(state) {
            if (!quizCardEl || !quizTopAnchorEl || !quizBottomAnchorEl) {
                return;
            }

            const cooldown = state && state.cooldown ? state.cooldown : null;
            const summary = cooldown && cooldown.last_summary ? cooldown.last_summary : null;
            const shouldMoveBelowInput = Boolean(
                state &&
                !state.has_active_session &&
                summary &&
                summary.completed_at
            );

            const targetAnchor = shouldMoveBelowInput ? quizBottomAnchorEl : quizTopAnchorEl;
            if (targetAnchor && quizCardEl.previousElementSibling !== targetAnchor) {
                targetAnchor.insertAdjacentElement('afterend', quizCardEl);
            }
        }

        function renderRanking(items) {
            if (!Array.isArray(items) || items.length === 0) {
                rankingEl.innerHTML = '<div class="farmasi-quiz-empty">Belum ada skor minggu ini. Sesi pertama yang selesai akan langsung masuk ranking.</div>';
                return;
            }

            rankingEl.innerHTML = items.map(function(item) {
                return '' +
                    '<div class="farmasi-quiz-ranking-item">' +
                    '  <div class="flex items-center gap-3">' +
                    '    <span class="farmasi-quiz-rank-bullet">#' + item.rank + '</span>' +
                    '    <div>' +
                    '      <div class="font-semibold text-slate-900">' + escapeHtml(item.display_name || '-') + '</div>' +
                    '      <div class="farmasi-quiz-meta">' + formatAccuracy(item.correct_answers, item.wrong_answers) + '</div>' +
                    '    </div>' +
                    '  </div>' +
                    '  <div class="text-right">' +
                    '    <div class="font-extrabold text-sky-700">' + (item.points || 0) + ' pts</div>' +
                    '    <div class="farmasi-quiz-meta">' + (item.completed_sessions || 0) + ' sesi</div>' +
                    '  </div>' +
                    '</div>';
            }).join('');
        }

        function renderHistory(items) {
            if (!Array.isArray(items) || items.length === 0) {
                historyEl.innerHTML = '<div class="farmasi-quiz-empty">History season akan muncul otomatis setelah minggu berganti.</div>';
                return;
            }

            historyEl.innerHTML = items.map(function(item) {
                return '' +
                    '<div class="farmasi-quiz-history-item">' +
                    '  <div>' +
                    '    <div class="font-semibold text-slate-900">' + escapeHtml(item.season_label || '-') + '</div>' +
                    '    <div class="farmasi-quiz-meta">Pemenang: ' + escapeHtml(item.winner_name || '-') + '</div>' +
                    '  </div>' +
                    '  <div class="text-right">' +
                    '    <div class="font-extrabold text-emerald-700">' + (item.points || 0) + ' pts</div>' +
                    '    <div class="farmasi-quiz-meta">' + formatAccuracy(item.correct_answers, item.wrong_answers) + '</div>' +
                    '  </div>' +
                    '</div>';
            }).join('');
        }

        function buildSummaryHtml(summary, personal, cooldownText) {
            const passed = String(summary.pass_status || '') === 'passed';
            return '' +
                '<div>' +
                '  <div class="farmasi-quiz-badge ' + (passed ? 'quiz-pass' : 'quiz-fail') + '">' + (passed ? 'Lulus Quiz' : 'Belum Lulus') + '</div>' +
                '  <div class="farmasi-quiz-question">' + (passed ? 'Sesi quiz selesai. Anda menjawab minimal 7 soal dengan benar.' : 'Sesi quiz selesai. Nilai belum mencapai batas lulus 7 soal benar.') + '</div>' +
                '  <div class="farmasi-quiz-meta">' + escapeHtml(cooldownText) + '</div>' +
                '  <div class="farmasi-quiz-summary-grid">' +
                '    <div class="farmasi-quiz-summary-box"><span class="farmasi-quiz-meta">Benar sesi ini</span><strong>' + (summary.score_correct || 0) + '</strong></div>' +
                '    <div class="farmasi-quiz-summary-box"><span class="farmasi-quiz-meta">Salah sesi ini</span><strong>' + (summary.score_wrong || 0) + '</strong></div>' +
                '    <div class="farmasi-quiz-summary-box"><span class="farmasi-quiz-meta">Point minggu ini</span><strong>' + (personal.points || 0) + '</strong></div>' +
                '  </div>' +
                '  <div class="mt-4 farmasi-quiz-meta">Akumulasi pribadi minggu ini: ' + (personal.correct_answers || 0) + ' benar • ' + (personal.wrong_answers || 0) + ' salah • ' + (personal.completed_sessions || 0) + ' sesi selesai.</div>' +
                '</div>';
        }

        function renderCooldownView(cooldown, personal) {
            const slot = quizState && quizState.slot ? quizState.slot : null;
            const nextSeconds = getSecondsUntil(cooldown.next_available_at);
            const cooldownText = cooldown.active ?
                ('Sesi berikutnya akan tersedia dalam ' + formatDuration(nextSeconds) + '.') :
                'Quiz baru akan otomatis dibuat saat tersedia.';
            const scheduleText = slot && slot.schedule_text ? slot.schedule_text : 'Slot quiz tersedia setiap hari pada 06:00 dan 18:00 WIB.';

            if (cooldown.last_summary) {
                stageEl.innerHTML = buildSummaryHtml(cooldown.last_summary, personal || {}, cooldownText + ' ' + scheduleText);
                return;
            }

            stageEl.innerHTML = '' +
                '<div class="farmasi-quiz-empty">' +
                '  <div class="font-semibold text-slate-900 mb-2">Quiz sedang menunggu jadwal sesi berikutnya.</div>' +
                '  <div>' + escapeHtml(cooldownText) + '</div>' +
                '  <div class="mt-2">' + escapeHtml(scheduleText) + '</div>' +
                '</div>';
        }

        function makeFireworks() {
            fireworksEl.innerHTML = '';
            const colors = ['#38bdf8', '#22c55e', '#f97316', '#eab308', '#f43f5e', '#818cf8', '#10b981'];
            for (let i = 0; i < 36; i++) {
                const particle = document.createElement('span');
                const x = Math.round((Math.random() * 220) - 110) + 'px';
                const y = Math.round((Math.random() * 220) - 150) + 'px';
                particle.style.left = (35 + Math.random() * 30) + '%';
                particle.style.top = (18 + Math.random() * 26) + '%';
                particle.style.background = colors[i % colors.length];
                particle.style.setProperty('--tx', x);
                particle.style.setProperty('--ty', y);
                particle.style.animationDelay = (Math.random() * 0.35) + 's';
                fireworksEl.appendChild(particle);
            }

            setTimeout(function() {
                fireworksEl.innerHTML = '';
            }, 2400);
        }

        function maybeCelebrate(cooldown, personal) {
            if (!cooldown || !cooldown.last_summary) return;
            const summary = cooldown.last_summary;
            if (!summary.completed_at) return;

            const fingerprint = [summary.completed_at, summary.score_correct, summary.score_wrong, personal.points].join('|');
            if (fingerprint === completionFingerprint) {
                return;
            }

            completionFingerprint = fingerprint;

            if (String(summary.pass_status) === 'passed') {
                makeFireworks();
                winAudio.currentTime = 0;
                winAudio.play().catch(function() {});
            } else {
                loseAudio.currentTime = 0;
                loseAudio.play().catch(function() {});
            }
        }

        function getRenderableQuestion(session) {
            if (!session || !Array.isArray(session.questions) || session.questions.length === 0) {
                return null;
            }

            if (reviewQuestionId) {
                const reviewed = session.questions.find(function(question) {
                    return question.session_question_id === reviewQuestionId;
                });
                if (reviewed) {
                    return reviewed;
                }
            }

            return session.questions[session.active_index || 0] || session.questions[0];
        }

        function renderQuestionView(session, personal) {
            const question = getRenderableQuestion(session);
            if (!question) {
                stageEl.innerHTML = '<div class="farmasi-quiz-empty">Belum ada soal pada sesi aktif.</div>';
                return;
            }

            const answered = question.selected_option !== null;
            const answeredCount = session.answered_count || 0;
            const totalQuestions = session.total_questions || 10;

            const options = ['a', 'b', 'c', 'd', 'e'].map(function(letter) {
                const text = question['option_' + letter] || '';
                const classes = ['farmasi-quiz-answer-btn'];

                if (answered) {
                    classes.push('is-locked');
                    if (question.correct_option === letter) {
                        classes.push('is-correct');
                    } else if (question.selected_option === letter && question.correct_option !== letter) {
                        classes.push('is-wrong');
                    }
                }

                return '' +
                    '<button type="button" class="' + classes.join(' ') + '" data-option="' + letter + '" data-question-id="' + question.session_question_id + '">' +
                    '  <span class="farmasi-quiz-answer-head">' +
                    '    <span class="farmasi-quiz-answer-letter">' + letter.toUpperCase() + '</span>' +
                    '    <span class="farmasi-quiz-answer-text">' + escapeHtml(text) + '</span>' +
                    '  </span>' +
                    '</button>';
            }).join('');

            let feedbackHtml = '';
            if (answered) {
                const feedbackClass = question.is_correct ? 'is-correct' : 'is-wrong';
                feedbackHtml = '' +
                    '<div class="farmasi-quiz-feedback ' + feedbackClass + '">' +
                    '  <strong>' + (question.is_correct ? 'Jawaban benar.' : 'Jawaban Anda salah.') + '</strong><br>' +
                    '  Jawaban benar: <strong>' + String(question.correct_option || '').toUpperCase() + '</strong>.<br>' +
                    '  ' + escapeHtml(question.explanation || '') +
                    '</div>';
            }

            const nextButtonHtml = answered ?
                '<button type="button" class="btn-success" id="farmasiQuizNextBtn">' +
                (answeredCount >= totalQuestions ? 'Lihat Hasil' : 'Next Soal') +
                '</button>' :
                '<div class="flex flex-wrap items-center justify-between gap-3 w-full">' +
                '  <div class="farmasi-quiz-meta">Pilih salah satu jawaban. Sistem akan langsung memberi tahu jawaban benar, lalu tombol next muncul.</div>' +
                '  <button type="button" class="btn-danger" id="farmasiQuizFinishBtn">Akhiri Quiz</button>' +
                '</div>';

            stageEl.innerHTML = '' +
                '<div>' +
                '  <div class="farmasi-quiz-status-row">' +
                '    <div class="farmasi-quiz-meta">Soal ' + (question.order || 1) + ' dari ' + totalQuestions + '</div>' +
                '    <div class="farmasi-quiz-meta">Pribadi minggu ini: ' + (personal.correct_answers || 0) + ' benar • ' + (personal.wrong_answers || 0) + ' salah</div>' +
                '  </div>' +
                '  <div class="farmasi-quiz-question">' + escapeHtml(question.prompt || '') + '</div>' +
                '  <div class="farmasi-quiz-answer-list">' + options + '</div>' +
                feedbackHtml +
                '  <div class="farmasi-quiz-action-row mt-4">' +
                '    <div class="farmasi-quiz-meta">Kategori: ' + escapeHtml(question.category || '-') + '</div>' +
                nextButtonHtml +
                '  </div>' +
                '</div>';

            stageEl.querySelectorAll('.farmasi-quiz-answer-btn').forEach(function(button) {
                if (answered) {
                    return;
                }

                button.addEventListener('click', function() {
                    if (submitBusy) return;
                    submitBusy = true;

                    fetch(submitUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                session_question_id: question.session_question_id,
                                selected_option: button.dataset.option || ''
                            })
                        })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(payload) {
                            if (!payload || payload.success !== true) {
                                throw new Error((payload && payload.error) || 'Gagal menyimpan jawaban quiz.');
                            }

                            reviewQuestionId = question.session_question_id;
                            quizState = payload.data.state || null;
                            renderAll();
                        })
                        .catch(function(error) {
                            alert(error.message || 'Gagal menyimpan jawaban quiz.');
                        })
                        .finally(function() {
                            submitBusy = false;
                        });
                });
            });

            const nextButton = document.getElementById('farmasiQuizNextBtn');
            if (nextButton) {
                nextButton.addEventListener('click', function() {
                    reviewQuestionId = null;
                    renderAll();
                });
            }

            const finishButton = document.getElementById('farmasiQuizFinishBtn');
            if (finishButton) {
                finishButton.addEventListener('click', function() {
                    if (submitBusy) return;

                    const confirmed = window.confirm('Akhiri quiz sekarang? Soal yang belum dijawab akan dihitung selesai dan sesi langsung ditutup.');
                    if (!confirmed) {
                        return;
                    }

                    submitBusy = true;
                    fetch(finishUrl, {
                            method: 'POST',
                            credentials: 'same-origin'
                        })
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(payload) {
                            if (!payload || payload.success !== true) {
                                throw new Error((payload && payload.error) || 'Gagal mengakhiri quiz.');
                            }

                            reviewQuestionId = null;
                            quizState = payload.data.state || null;
                            renderAll();
                        })
                        .catch(function(error) {
                            alert(error.message || 'Gagal mengakhiri quiz.');
                        })
                        .finally(function() {
                            submitBusy = false;
                        });
                });
            }
        }

        function renderAll() {
            if (!quizState) {
                syncQuizCardPosition(null);
                stageEl.innerHTML = '<div class="farmasi-quiz-empty">Memuat quiz farmasi...</div>';
                return;
            }

            const week = quizState.week || {};
            const cooldown = quizState.cooldown || {
                active: false,
                next_available_at: null,
                last_summary: null
            };
            const slot = quizState.slot || null;
            const personal = quizState.personal || {};

            weekLabelEl.textContent = week.label || 'Season Quiz';
            syncQuizCardPosition(quizState);
            renderRanking(quizState.ranking || []);
            renderHistory(quizState.history || []);

            stopCountdown();
            countdownTimer = setInterval(function() {
                const nextSeconds = getSecondsUntil(cooldown.next_available_at || (quizState.session && quizState.session.expires_at));
                if (quizState.has_active_session && quizState.session) {
                    setStatusBadge('Sesi Quiz Aktif', 'quiz-live');
                    timerEl.textContent = 'Slot ' + ((slot && slot.label) || 'aktif') + ' berakhir dalam ' + formatDuration(nextSeconds) + '.';
                } else if (cooldown.active) {
                    const passed = cooldown.last_summary && String(cooldown.last_summary.pass_status) === 'passed';
                    setStatusBadge(passed ? 'Sesi Selesai - Lulus' : 'Sesi Selesai - Belum Lulus', passed ? 'quiz-pass' : 'quiz-fail');
                    timerEl.textContent = 'Slot berikutnya terbuka dalam ' + formatDuration(nextSeconds) + '.';
                } else {
                    setStatusBadge('Menyiapkan Sesi Baru', 'quiz-live');
                    timerEl.textContent = 'Menunggu slot 06:00 atau 18:00 WIB.';
                }

                if (nextSeconds <= 0 && !stateRefreshPending && !submitBusy) {
                    stateRefreshPending = true;
                    fetchState();
                }
            }, 1000);

            if (quizState.has_active_session && quizState.session) {
                renderQuestionView(quizState.session, personal);
            } else {
                renderCooldownView(cooldown, personal);
                maybeCelebrate(cooldown, personal);
            }
        }

        function fetchState() {
            fetch(stateUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store'
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(payload) {
                    if (!payload || payload.success !== true) {
                        throw new Error((payload && payload.error) || 'Gagal memuat state quiz.');
                    }
                    quizState = payload.data || null;
                    stateRefreshPending = false;
                    if (quizState && quizState.has_active_session) {
                        completionFingerprint = '';
                    }
                    renderAll();
                })
                .catch(function(error) {
                    stateRefreshPending = false;
                    stageEl.innerHTML = '<div class="farmasi-quiz-empty">' + escapeHtml(error.message || 'Gagal memuat quiz farmasi.') + '</div>';
                });
        }

        fetchState();
    })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
<?php
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
        app_log('FATAL ERROR');
        app_log(print_r($error, true));
        app_log('--------------------------------');
    }
});
?>
