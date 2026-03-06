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

// Helper log function
function app_log($message)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    error_log($line, 3, __DIR__ . '/../storage/error_log.txt');
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php'; // hasilkan $rangeStart, $rangeEnd, $rangeLabel

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
    header('Location: /auth/login.php?error=session_expired');
    exit;
}

$medicName    = $user['name'] ?? '';
$medicJabatan = $user['position'] ?? '';
$medicRole    = $user['role'] ?? '';

// ===============================
// 🔐 VALIDASI AKSES REKAP FARMASI
// ===============================
// aturan:
// - trainee ❌ tidak boleh
// - selain trainee ✅ boleh

$position = strtolower(trim($medicJabatan));

if ($position === 'trainee') {
    http_response_code(403);
    include __DIR__ . '/../partials/header.php';
?>
    <div class="card" style="max-width:600px;margin:80px auto;text-align:center;">
        <h3 style="margin-bottom:10px;">🚫 Akses Ditolak</h3>
        <p style="color:#6b7280;font-size:14px;">
            Akun dengan posisi <strong>Trainee</strong>
            tidak diperbolehkan mengakses
            <strong>Rekap Farmasi</strong>.
        </p>
        <a href="/dashboard/index.php" class="btn-secondary" style="margin-top:12px;">
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

$shouldClearForm = !empty($_SESSION['clear_form'] ?? false);
unset($_SESSION['clear_form']);

// Flag sementara untuk request POST saat ini
$clearFormNextLoad = false;

// Tanggal lokal hari ini (WITA)
$todayDate = date('Y-m-d');

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
    ");
    $stmtWeekly->execute([
        ':uid'   => $_SESSION['user_rh']['id'],
        ':start' => $weekStart,
        ':end'   => $weekEnd,
    ]);

    $weeklyTxCount = (int)$stmtWeekly->fetchColumn();
}

// Flag untuk PRG
$redirectAfterPost = false;

// ======================================================
// HANDLE REQUEST POST (SEMUA ACTION FORM DI SINI)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ===============================
    // AUTO MERGE KONSUMEN (WAJIB ADA)
    // ===============================
    $autoMerge = (
        isset($_POST['auto_merge']) &&
        $_POST['auto_merge'] === '1' &&
        isset($_POST['merge_targets']) &&
        $_POST['merge_targets'] !== ''
    );

    $mergeTargets = [];

    if ($autoMerge) {
        $decoded = json_decode($_POST['merge_targets'], true);
        if (is_array($decoded) && count($decoded) > 0) {
            $mergeTargets = $decoded;
        } else {
            // kalau JSON rusak → MATIKAN auto merge
            $autoMerge = false;
        }
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

                    $stmtDel = $pdo->prepare("
                        DELETE FROM sales
                        WHERE id IN ($placeholders)
                          AND medic_name = ?
                    ");
                    $stmtDel->execute($params);
                    $deleted = $stmtDel->rowCount();

                    if ($deleted > 0) {
                        $messages[] = "{$deleted} transaksi berhasil dihapus (sesuai hak akses Anda).";

                        /* =====================================================
                           📌 LOG ACTIVITY (HAPUS TRANSAKSI)
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
        // ⏱️ COOLDOWN ANTI-SPAM (SERVER - SESSION BASED)
        // ===============================
        $nowTs = time();

        // Inisialisasi jika belum ada
        if (!isset($_SESSION['last_tx_ts'])) {
            $_SESSION['last_tx_ts'] = 0;
        }

        // Hitung selisih detik
        $diffSeconds = $nowTs - (int)$_SESSION['last_tx_ts'];

        // Cooldown FIXED 10 detik (ANTI SPAM KLIK)
        // ⛔ ini BUKAN fairness dan BUKAN limit harian
        if ($diffSeconds < 10) {
            $remain = 10 - $diffSeconds;
            $errors[] = "⏳ Mohon tunggu {$remain} detik sebelum input transaksi berikutnya.";
        }

        // ⛔ BLOK JIKA MEDIS OFFLINE
        $stmtStatus = $pdo->prepare("
            SELECT status
            FROM user_farmasi_status
            WHERE user_id = ?
        ");
        $stmtStatus->execute([$_SESSION['user_rh']['id']]);
        $currentStatus = $stmtStatus->fetchColumn() ?: 'offline';

        if ($currentStatus !== 'online') {
            $errors[] = "Anda berstatus OFFLINE. Tidak diperbolehkan melakukan transaksi.";
        }

        $postedToken = $_POST['tx_token'] ?? '';

        if (
            empty($postedToken) ||
            empty($_SESSION['tx_token']) ||
            !hash_equals($_SESSION['tx_token'], $postedToken)
        ) {
            $errors[] = '⚠️ Permintaan tidak valid atau sudah diproses.';
        } else {

            unset($_SESSION['tx_token']);

            if ($medicName === '' || $medicJabatan === '') {
                $errors[] = "Set dulu nama petugas medis sebelum input transaksi.";
            } else {
                // ===============================
                // AMBIL INPUT + NORMALISASI NAMA
                // ===============================
                $consumerName = ucwords(strtolower(trim($_POST['consumer_name'] ?? '')));


                $pkgMainId    = (int)($_POST['package_main'] ?? 0);
                $pkgBandageId = (int)($_POST['package_bandage'] ?? 0);
                $pkgIfaksId   = (int)($_POST['package_ifaks'] ?? 0);
                $pkgPainId   = (int)($_POST['package_painkiller'] ?? 0);

                $forceOverLimit = isset($_POST['force_overlimit']) && $_POST['force_overlimit'] === '1';

                if ($consumerName === '') {
                    $errors[] = "Nama konsumen wajib diisi.";
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
                    $stmtPkg = $pdo->prepare("SELECT * FROM packages WHERE id IN ($placeholders)");
                    $stmtPkg->execute($selectedIds);
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

                // ===============================
                // HITUNG TOTAL ITEM BARU
                // ===============================
                if (empty($errors)) {
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
                        WHERE LOWER(consumer_name) = LOWER(:name)
                        AND DATE(created_at) = :today
                    ");
                    $stmt->execute([
                        ':name'  => $consumerName,
                        ':today' => $todayDate,
                    ]);
                    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                        'total_bandage' => 0,
                        'total_ifaks' => 0,
                        'total_painkiller' => 0,
                    ];

                    // ===============================
                    // VALIDASI 1 KONSUMEN = 1 TRANSAKSI / HARI (SERVER)
                    // ===============================
                    $totalToday =
                        (int)$totals['total_bandage'] +
                        (int)$totals['total_ifaks'] +
                        (int)$totals['total_painkiller'];

                    if ($totalToday > 0) {
                        $errors[] = "Konsumen ini sudah melakukan transaksi hari ini.";
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
                        $warnings[] = "⚠️ {$consumerName} melebihi batas BANDAGE ({$newBandage}/{$maxBandage}).";
                        $overLimit = true;
                    }
                    if ($newIfaks > $maxIfaks) {
                        $warnings[] = "⚠️ {$consumerName} melebihi batas IFAKS ({$newIfaks}/{$maxIfaks}).";
                        $overLimit = true;
                    }
                    if ($newPain > $maxPain) {
                        $warnings[] = "⚠️ {$consumerName} melebihi batas PAINKILLER ({$newPain}/{$maxPain}).";
                        $overLimit = true;
                    }

                    if ($overLimit && !$forceOverLimit) {
                        $errors[] = "Transaksi dibatalkan karena melebihi batas harian.";
                    }
                }

                // ===============================
                // AUTO MERGE KONSUMEN
                // ===============================
                if (empty($errors) && $autoMerge && !empty($mergeTargets)) {

                    $stmtMerge = $pdo->prepare("
            UPDATE sales 
            SET consumer_name = :new
            WHERE consumer_name = :old
        ");

                    $merged = 0;

                    foreach ($mergeTargets as $old) {
                        if (!is_string($old)) continue;
                        $old = trim($old);
                        if ($old === '' || strcasecmp($old, $consumerName) === 0) continue;

                        $stmtMerge->execute([
                            ':new' => $consumerName,
                            ':old' => $old
                        ]);

                        $merged += $stmtMerge->rowCount();
                    }

                    if ($merged > 0) {
                        $warnings[] = "🔁 {$merged} transaksi lama digabung ke {$consumerName}.";
                    }
                }

                // ===============================
                // SERVER SIDE FAIRNESS LOCK
                // ===============================
                if (empty($errors)) {

                    $stmtFair = $pdo->query("
                        SELECT
                            ufs.user_id AS user_id,
                            COALESCE(COUNT(s.id), 0) AS total
                        FROM user_farmasi_status ufs
                        LEFT JOIN sales s
                            ON s.medic_user_id = ufs.user_id
                        AND DATE(s.created_at) = CURDATE()
                        WHERE ufs.status = 'online'
                        GROUP BY ufs.user_id
                        ORDER BY total ASC
                    ");

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
                                '⚠️ Distribusi transaksi tidak seimbang. ' .
                                'Pertimbangkan mengarahkan konsumen ke petugas medis lain.';
                        }
                    }
                }

                // ===============================
                // INSERT TRANSAKSI (AMAN + SESSION FIX)
                // ===============================
                if (empty($errors)) {

                    $now    = date('Y-m-d H:i:s');
                    $userId = (int)($_SESSION['user_rh']['id'] ?? 0);

                    $stmtInsert = $pdo->prepare("
                        INSERT INTO sales
                        (
                            consumer_name,
                            medic_name,
                            medic_user_id,
                            medic_jabatan,
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
                        // INSERT SALES (MULTI PAKET)
                        // ===============================
                        foreach ($selectedIds as $id) {
                            $p = $packagesSelected[$id];

                            $txHash = hash('sha256', $postedToken . '|' . $id);

                            $stmtInsert->execute([
                                ':cname'   => $consumerName,
                                ':mname'   => $medicName,
                                ':muid'    => $userId,
                                ':mjab'    => $medicJabatan,
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

                        /* =====================================================
                           📌 LOG ACTIVITY (TRANSAKSI BARU)
                           ===================================================== */
                        try {
                            // ✅ HITUNG TOTAL DARI DATA YANG SUDAH ADA
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
                        if ($userId > 0) {
                            $stmtStatus = $pdo->prepare("
                                INSERT INTO user_farmasi_status
                                    (user_id, status, last_activity_at, last_confirm_at, auto_offline_at)
                                VALUES
                                    (?, 'online', NOW(), NOW(), NULL)
                                ON DUPLICATE KEY UPDATE
                                    status = 'online',
                                    last_activity_at = NOW(),
                                    last_confirm_at = NOW(),
                                    auto_offline_at = NULL,
                                    updated_at = NOW()
                            ");
                            $stmtStatus->execute([$userId]);

                            // ======================================================
                            // 🔥 FIX UTAMA: PASTIKAN SESSION FARMASI AKTIF
                            // ======================================================
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
                                // BUAT SESSION BARU (PERTAMA KALI AKTIVITAS)
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

                        // ===============================
                        // ⏱️ UPDATE COOLDOWN TIMESTAMP
                        // ===============================
                        $_SESSION['last_tx_ts'] = time();

                        $messages[] = "Transaksi {$consumerName} berhasil disimpan (" . count($selectedIds) . " paket).";
                        $clearFormNextLoad = true;
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') {
                            $warnings[] = '⚠️ Transaksi ini sudah pernah diproses.';
                        } else {
                            app_log($e->getMessage());
                            $errors[] = 'Terjadi kesalahan sistem saat menyimpan transaksi.';
                        }
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
$packages = $pdo->query("SELECT * FROM packages ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

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

    if (strpos($name, 'PAKET A') === 0 || strpos($name, 'PAKET B') === 0) {
        $paketAB[] = $p;
    } elseif ($p['bandage_qty'] > 0 && $p['ifaks_qty'] == 0 && $p['painkiller_qty'] == 0) {
        $bandagePackages[] = $p;
    } elseif ($p['ifaks_qty'] > 0 && $p['bandage_qty'] == 0 && $p['painkiller_qty'] == 0) {
        $ifaksPackages[] = $p;
    } elseif ($p['painkiller_qty'] > 0 && $p['bandage_qty'] == 0 && $p['ifaks_qty'] == 0) {
        $painkillerPackages[] = $p;
    }
}

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

// Ambil nama konsumen unik untuk datalist
$consumerNames = $pdo->query("
    SELECT DISTINCT consumer_name 
    FROM sales 
    ORDER BY consumer_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

$stmtOnlineMedics = $pdo->query("
    SELECT
        ufs.user_id,
        ur.full_name AS medic_name,
        ur.position  AS medic_jabatan,
        COUNT(s.id)  AS total_transaksi,
        COALESCE(SUM(s.price),0) AS total_pendapatan,
        FLOOR(COALESCE(SUM(s.price),0) * 0.4) AS bonus_40,
        
        -- ➕ TAMBAHAN: HITUNG TRANSAKSI MINGGU INI
        (SELECT COUNT(*)
         FROM sales
         WHERE medic_user_id = ufs.user_id
           AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00') - INTERVAL (WEEKDAY(NOW())) DAY
           AND created_at <  DATE_FORMAT(NOW(), '%Y-%m-%d 23:59:59') + INTERVAL (6 - WEEKDAY(NOW())) DAY
        ) AS weekly_transaksi,
        
        -- ➕ TAMBAHAN: HITUNG JAM ONLINE MINGGU INI
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
    WHERE ufs.status = 'online'
    GROUP BY ufs.user_id, ur.full_name, ur.position
    ORDER BY total_transaksi ASC, total_pendapatan ASC
");

$onlineMedics = $stmtOnlineMedics->fetchAll(PDO::FETCH_ASSOC);

// ➕ FORMAT DURASI UNTUK TAMPILAN
foreach ($onlineMedics as &$m) {
    $seconds = (int)($m['weekly_online_seconds'] ?? 0);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    $m['weekly_online_text'] = "{$hours}j {$minutes}m {$secs}d";
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

    // 🔒 VALIDASI KETAT
    if (
        $currentMedic &&
        $lowestMedic &&
        (int)$currentMedic['user_id'] !== (int)$lowestMedic['user_id']
    ) {
        $diff = (int)$currentMedic['total_transaksi']
            - (int)$lowestMedic['total_transaksi'];

        // 🔑 HARUS BENAR-BENAR ≥ 5
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
    GROUP BY consumer_name
");
$stmtDailyTotals->execute([':today' => $todayDate]);
$dailyTotalsRows = $stmtDailyTotals->fetchAll(PDO::FETCH_ASSOC);

$dailyTotalsJS = [];
foreach ($dailyTotalsRows as $row) {
    $key = mb_strtolower(trim($row['consumer_name']));
    $dailyTotalsJS[$key] = [
        'bandage'    => (int)$row['total_bandage'],
        'ifaks'      => (int)$row['total_ifaks'],
        'painkiller' => (int)$row['total_painkiller'],
    ];
}

// Detail transaksi harian per konsumen (untuk ditampilkan di warning JS)
$stmtDailyDetail = $pdo->prepare("
    SELECT consumer_name, medic_name, package_name, created_at,
           qty_bandage, qty_ifaks, qty_painkiller
    FROM sales
    WHERE DATE(created_at) = :today
    ORDER BY created_at ASC
");
$stmtDailyDetail->execute([':today' => $todayDate]);
$detailRows = $stmtDailyDetail->fetchAll(PDO::FETCH_ASSOC);

$dailyDetailJS = [];
foreach ($detailRows as $row) {
    $key = mb_strtolower(trim($row['consumer_name']));
    if (!isset($dailyDetailJS[$key])) {
        $dailyDetailJS[$key] = [];
    }
    $dailyDetailJS[$key][] = [
        'medic'      => $row['medic_name'],
        'package'    => $row['package_name'],
        'time'       => formatTanggalID($row['created_at']),
        'bandage'    => (int)$row['qty_bandage'],
        'ifaks'      => (int)$row['qty_ifaks'],
        'painkiller' => (int)$row['qty_painkiller'],
    ];
}

// Ambil data transaksi sesuai filter tanggal.
// Default: hanya transaksi milik medis aktif (session).
// Jika ?show_all=1 → tampilkan semua medis.
$sqlSales = "
    SELECT * FROM sales
    WHERE created_at >= :start
      AND created_at <  DATE_ADD(:end, INTERVAL 1 SECOND)
";

$paramsSales = [
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
];

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
            medic_name,
            medic_jabatan,
            COUNT(*) AS total_transaksi,
            SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
            SUM(price) AS total_harga,
            FLOOR(SUM(price) * 0.4) AS bonus_40
        FROM sales
        WHERE created_at BETWEEN :start AND :end
        AND medic_user_id = :uid
        GROUP BY medic_user_id, medic_name, medic_jabatan
        LIMIT 1
    ");
    $stmtSingle->execute([
        ':start' => $rangeStart,
        ':end'   => $rangeEnd,
        ':uid'   => $_SESSION['user_rh']['id'],
    ]);
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
    ");
    $stmtToday->execute([
        ':uid' => $_SESSION['user_rh']['id'],
    ]);

    $todayStats = $stmtToday->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Untuk form custom date supaya tetap isi
$fromDateInput = ($range === 'custom' && $startDT instanceof DateTime)
    ? $startDT->format('Y-m-d')
    : '';

$toDateInput = ($range === 'custom' && $endDT instanceof DateTime)
    ? $endDT->format('Y-m-d')
    : '';

?>
<?php
$pageTitle = 'Rekap Farmasi EMS';
include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <!-- ===== CONTENT ===== -->
    <div class="page" style="max-width:1200px;margin:auto;">

        <!-- NOTIFIKASI MENGAMBANG -->
        <div class="activity-feed-container">

            <audio id="activitySound" preload="auto">
                <source src="/assets/sound/activity.mp3" type="audio/mpeg">
            </audio>

            <div class="activity-feed-card">

                <!-- HEADER -->
                <div class="activity-feed-header">
                    <span class="activity-feed-title">📌 Activity</span>

                    <!-- 🔊 TOMBOL MUTE (CLASS BARU, TIDAK GANGGU) -->
                    <button
                        id="btnToggleActivitySound"
                        class="activity-sound-btn"
                        title="Matikan suara activity"
                        aria-label="Toggle suara activity">
                        🔊
                    </button>

                    <!-- ❌ TOMBOL CLOSE (ASLI, TIDAK DIUBAH) -->
                    <button
                        id="btnCloseActivity"
                        class="activity-feed-close"
                        title="Tutup Activity"
                        aria-label="Tutup Activity">
                        ✖
                    </button>
                </div>

                <!-- LIST -->
                <div class="activity-feed-list" id="activityFeedList"></div>

            </div>
        </div>

        <h1>Rekap Farmasi EMS</h1>

        <div id="localClock" style="font-size:13px;color:#9ca3af;margin-bottom:6px;"></div>

        <p style="font-size:13px;color:#9ca3af;margin-bottom:16px;">
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

        <!-- Card Petugas Medis (hanya muncul jika BELUM ada petugas) -->

        <?php if ($medicName): ?>
            <div class="card card-online-medics">
                <div class="card-header">
                    👨‍⚕️ Medis Online Hari Ini
                    <span id="totalMedicsBadge"
                        style="
                            margin-left:8px;
                            padding:2px 8px;
                            border-radius:999px;
                            background:#dcfce7;
                            color:#166534;
                            font-size:12px;
                            font-weight:700;
                        ">
                        0 orang
                    </span>
                    <small style="display:block;font-weight:500;color:#64748b;margin-top:4px;">
                        (prioritas penjualan paling sedikit di sortir paling atas)
                    </small>
                </div>

                <div class="online-medics-list" id="onlineMedicsContainer">

                    <?php if (empty($onlineMedics)): ?>

                        <p style="font-size:13px;color:#64748b;">
                            Tidak ada medis yang sedang online.
                        </p>

                    <?php else: ?>

                        <?php foreach ($onlineMedics as $m): ?>
                            <div class="online-medic-row">
                                <div class="medic-main">
                                    <strong><?= htmlspecialchars($m['medic_name']) ?></strong>

                                    <span class="weekly-badge">
                                        Minggu ini: <?= (int)$m['weekly_transaksi'] ?> trx
                                    </span>

                                    <span class="weekly-online"
                                        data-seconds="<?= (int)($m['weekly_online_seconds'] ?? 0) ?>"
                                        data-user-id="<?= (int)$m['user_id'] ?>">
                                        ⏱️ Online: <?= htmlspecialchars($m['weekly_online_text'] ?? '0j 0m') ?>
                                    </span>

                                    <div class="medic-role">
                                        <?= htmlspecialchars($m['medic_jabatan']) ?>
                                    </div>

                                    <!-- 🔴 FORCE OFFLINE BUTTON -->
                                    <button
                                        class="btn-force-offline"
                                        data-user-id="<?= (int)$m['user_id'] ?>"
                                        data-name="<?= htmlspecialchars($m['medic_name']) ?>"
                                        data-jabatan="<?= htmlspecialchars($m['medic_jabatan']) ?>">
                                        🛑 Force Offline
                                    </button>
                                </div>

                                <div class="medic-stats">
                                    <div class="tx"><?= (int)$m['total_transaksi'] ?> trx</div>
                                    <div class="amount"><?= dollar((int)$m['total_pendapatan']) ?></div>
                                    <div class="bonus" style="font-size:12px;color:#16a34a;">
                                        Bonus: <?= dollar((int)$m['bonus_40']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

            </div>

            <!-- Card Input Transaksi -->
            <div class="card">
                <div class="card-header card-header-actions card-header-flex">
                    <div class="card-header-actions-title">
                        Input Transaksi Baru
                    </div>
                </div>

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
                            <span class="medic-role">(<?= htmlspecialchars($medicJabatan) ?>)</span>
                        </div>

                        <div class="medic-status">
                            <span id="farmasiStatusBadge"
                                data-status="<?= $isOnline ? 'online' : 'offline' ?>"
                                class="status-badge <?= $isOnline ? 'status-online' : 'status-offline' ?>"
                                style="cursor:pointer;"
                                title="Klik untuk ubah status">
                                <span class="dot"></span>
                                <span id="farmasiStatusText">
                                    <?= $isOnline ? 'ONLINE' : 'OFFLINE' ?>
                                </span>
                            </span>
                        </div>
                    </div>

                    <!-- <div id="dailyNotice" class="info-notice">
                        <strong>ℹ️ Informasi:</strong><br>
                        <strong>1 konsumen / pasien hanya diperbolehkan melakukan 1 transaksi dalam 1 hari.</strong><br>
                        Jika pasien menyatakan belum pernah membeli hari ini,
                        <em>kemungkinan nama pasien telah digunakan oleh temannya atau orang lain</em>.
                        Mohon lakukan konfirmasi berdasarkan
                        <a href="/dashboard/konsumen.php"
                            target="_blank"
                            style="color:#b45309;font-weight:600;text-decoration:underline;">
                            riwayat transaksi
                        </a>
                        yang ditampilkan oleh sistem.
                    </div> -->
                <?php endif; ?>

                <!-- ⏱️ NOTICE COOLDOWN GLOBAL (REALTIME) -->
                <div id="cooldownNotice"
                    style="
                        display:none;
                        margin:10px 0;
                        padding:12px;
                        border-radius:10px;
                        background:#eff6ff;
                        border:1px solid #93c5fd;
                        font-size:14px;
                        color:#1e3a8a;
                    ">
                </div>

                <!-- 🔴 NOTICE FAIRNESS (GLOBAL, TIDAK TERPENGARUH INPUT) -->
                <div id="fairnessNotice"
                    style="
                        display:none;
                        margin:10px 0;
                        padding:12px;
                        border-radius:10px;
                        background:#fff7ed;
                        border:1px solid #fdba74;
                        font-size:14px;
                        color:#9a3412;
                    ">
                </div>

                <!-- 🚫 NOTICE KONSUMEN (LOKAL, BERDASARKAN INPUT NAMA) -->
                <div id="consumerNotice"
                    style="
                        display:none;
                        margin:10px 0;
                        padding:12px;
                        border-radius:10px;
                        background:#fef2f2;
                        border:1px solid #fecaca;
                        font-size:14px;
                        color:#7f1d1d;
                    ">
                </div>

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
                    <div class="row-form-2">
                        <div class="col">
                            <label>Nama Konsumen</label>
                            <!-- <input type="text" name="consumer_name" list="consumer-list" required> -->
                            <input type="text" name="consumer_name" id="consumerNameInput" list="consumer-list" required>
                            <div id="similarConsumerBox"
                                style="display:none;margin-top:6px;
                                background:#fff7ed;
                                border:1px solid #fdba74;
                                border-radius:10px;
                                padding:10px;
                                font-size:13px;">
                            </div>
                            <datalist id="consumer-list">
                                <?php foreach ($consumerNames as $cn): ?>
                                    <option value="<?= htmlspecialchars($cn) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small>
                                Ketik nama, kalau sudah pernah beli akan muncul dan bisa diklik, mohon ketik nama sesuai KTP
                            </small>
                        </div>
                        <div class="col">
                            <label>Paket A / B (Combo)</label>
                            <select name="package_main" id="pkg_main">
                                <option value="">-- Tidak Pakai Paket A/B --</option>
                                <?php foreach ($paketAB as $pkg): ?>
                                    <option value="<?= (int)$pkg['id'] ?>">
                                        <?= htmlspecialchars($pkg['name']) ?> (<?= (int)$pkg['price'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row-form-2">
                        <div class="col">
                            <label>Paket Bandage</label>
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
                            <label>Paket IFAKS</label>
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
                            <label>Paket Painkiller</label>
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

                    <div class="total-item-info">
                        <strong>Total item terpilih:</strong>
                        Bandage (<span id="priceBandage">-</span>/pcs):
                        <span id="totalBandage">0</span>,
                        IFAKS (<span id="priceIfaks">-</span>/pcs):
                        <span id="totalIfaks">0</span>,
                        Painkiller (<span id="pricePainkiller">-</span>/pcs):
                        <span id="totalPainkiller">0</span>,
                        Bonus 40% (estimasi): <span id="totalBonus">0</span>
                    </div>

                    <!-- DISPLAY KASIR: Total Harga besar -->
                    <div class="total-display">
                        <div class="total-display-label">Total yang harus dibayar</div>
                        <div class="total-amount" id="totalPriceDisplay">$ 0</div>
                    </div>

                    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="button" id="btnSubmit" class="btn-success" onclick="handleSaveClick();">
                            Simpan Transaksi
                        </button>

                        <!-- Tombol CLEAR untuk menghapus inputan yang lengket -->
                        <button type="button" class="btn-secondary" onclick="clearFormInputs();">
                            Clear
                        </button>
                    </div>
                </form>

            </div>

            <!-- TOTAL TRANSAKSI HARI INI -->
            <div class="card">
                <h3 style="font-size:15px;margin:8px 0;">
                    📊 Total Transaksi Hari Ini
                </h3>

                <p style="font-size:13px;color:#9ca3af;margin-top:0;margin-bottom:10px;">
                    Rekap otomatis <strong>khusus hari ini</strong> (reset setiap pergantian tanggal).
                </p>

                <?php if ($todayStats && $todayStats['total_transaksi'] > 0): ?>
                    <div class="table-wrapper table-wrapper-sm">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Total Transaksi</th>
                                    <th>Total Harga</th>
                                    <th>Bonus (40%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?= (int)$todayStats['total_transaksi'] ?></td>
                                    <td><?= dollar((int)$todayStats['total_harga']) ?></td>
                                    <td><?= dollar((int)$todayStats['bonus_40']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="font-size:13px;color:#9ca3af;">
                        Belum ada transaksi hari ini.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Card Filter & Transaksi -->
            <div class="card">
                <div class="card-header">Filter Tanggal & Transaksi</div>

                <!-- Form Filter (GET) -->
                <form method="get" style="margin-bottom:10px;">
                    <div class="row-form-2">
                        <div class="col">
                            <label>Rentang Tanggal</label>
                            <select name="range" id="rangeSelect">
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
                    </div>
                    <div class="row-form-2 hidden" id="customDateRow">
                        <div class="col">
                            <label>Dari tanggal</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($fromDateInput) ?>">
                        </div>
                        <div class="col">
                            <label>Sampai tanggal</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($toDateInput) ?>">
                        </div>
                    </div>

                    <?php if ($showAll): ?>
                        <!-- Kalau lagi mode "tampilkan semua data", pertahankan saat ganti filter -->
                        <input type="hidden" name="show_all" value="1">
                    <?php endif; ?>

                    <div style="margin-top:8px;">
                        <button type="submit" class="btn-secondary">Terapkan Filter</button>
                    </div>
                </form>

                <p style="font-size:13px;color:#9ca3af;margin-top:0;">
                    Rentang aktif: <strong><?= htmlspecialchars($rangeLabel) ?></strong>
                </p>
            </div>

            <!-- Rekapan Bonus Medis (berdasarkan filter tanggal) -->
            <div class="card">
                <h3 style="font-size:15px;margin:8px 0;">Rekapan Bonus Medis (berdasarkan filter tanggal)</h3>
                <p style="font-size:13px;color:#9ca3af;margin-top:0;margin-bottom:8px;">
                    Ditampilkan berdasarkan <strong>petugas medis yang sedang aktif</strong> pada rentang tanggal aktif.
                </p>

                <?php if ($singleMedicStats): ?>
                    <div class="table-wrapper table-wrapper-sm" style="margin-bottom:12px;">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Nama Medis</th>
                                    <th>Jabatan</th>
                                    <th>Total Transaksi</th>
                                    <th>Total Item</th>
                                    <th>Total Harga</th>
                                    <th>Bonus (40%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>1</td>
                                    <td><?= htmlspecialchars($singleMedicStats['medic_name']) ?></td>
                                    <td><?= htmlspecialchars($singleMedicStats['medic_jabatan']) ?></td>
                                    <td><?= (int)$singleMedicStats['total_transaksi'] ?></td>
                                    <td><?= (int)$singleMedicStats['total_item'] ?></td>
                                    <td><?= dollar((int)$singleMedicStats['total_harga']) ?></td>
                                    <td><?= dollar((int)$singleMedicStats['bonus_40']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="font-size:13px;color:#9ca3af;margin-bottom:12px;">
                        Belum ada data untuk petugas medis aktif pada rentang tanggal yang dipilih.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Tabel Transaksi dengan DataTables + checkbox -->
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin:8px 0;">
                    <div>
                        <h3 style="font-size:15px;margin:0;">Transaksi (sesuai filter)</h3>
                        <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                            <?php if ($showAll): ?>
                                Mode: <strong>Semua medis</strong>
                            <?php else: ?>
                                Mode: <strong>Medis aktif (<?= htmlspecialchars($medicName) ?>)</strong>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="get" style="margin:0;display:flex;gap:6px;align-items:center;">
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
                </div>

                <?php if (!$filteredSales): ?>
                    <p style="font-size:13px;color:#9ca3af;">Belum ada transaksi pada rentang ini.</p>
                <?php else: ?>
                    <form method="post" id="bulkDeleteForm" onsubmit="return confirmBulkDelete();">
                        <input type="hidden" name="action" value="delete_selected">
                        <div class="table-wrapper">
                            <table id="salesTable">
                                <thead>
                                    <tr>
                                        <th style="width:32px;text-align:center;">
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th>Waktu</th>
                                        <th>Nama Konsumen</th>
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
                                        <th colspan="6" style="text-align:right;">TOTAL</th>
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
                                        <tr>
                                            <td style="text-align:center;">
                                                <?php if ($medicName && $s['medic_name'] === $medicName): ?>
                                                    <input type="checkbox"
                                                        class="row-check"
                                                        name="sale_ids[]"
                                                        value="<?= (int)$s['id'] ?>">
                                                <?php else: ?>
                                                    <span style="font-size:11px;color:#6b7280;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-order="<?= strtotime($s['created_at']) ?>">
                                                <?= formatTanggalID($s['created_at']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($s['consumer_name']) ?></td>
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

                        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <button type="submit" class="btn-danger" id="btnBulkDelete" disabled>
                                Hapus Data Terpilih
                            </button>
                            <small style="color:#9ca3af;">
                                Checklist baris yang ingin dihapus. Hanya transaksi milik Anda yang akan dihapus.
                            </small>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Kalau tidak ada petugas, info kecil saja -->
            <p style="font-size:13px;color:#9ca3af;">
                Silakan set <strong>Petugas Medis Aktif</strong> terlebih dahulu untuk dapat input transaksi dan melihat rekap.
            </p>
        <?php endif; ?>

    </div>

    <!-- =========================
     MODAL FORCE OFFLINE (EMS)
     ========================= -->
    <div id="emsForceModal" class="ems-modal-overlay" style="display:none;">
        <div class="ems-modal-card">
            <h4>🚫 Force Offline Medis</h4>

            <p id="emsForceDesc">
                Anda akan memaksa petugas medis menjadi <strong>OFFLINE</strong>.
            </p>

            <div style="text-align:left;margin-bottom:18px;">
                <label for="emsForceReason" style="font-size:13px;font-weight:700;">
                    Alasan Force Offline
                </label>
                <textarea id="emsForceReason"
                    placeholder="Contoh: sudah tidak duty / tidak berada di kota"
                    style="
                    width:100%;
                    min-height:80px;
                    margin-top:6px;
                    padding:10px 12px;
                    border-radius:12px;
                    border:1px solid #cbd5e1;
                    font-size:14px;
                    resize:vertical;
                "></textarea>

                <small style="display:block;margin-top:4px;color:#94a3b8;">
                    Minimal 5 karakter
                </small>
            </div>

            <div class="modal-actions force-offline-actions">
                <button type="button" class="ems-btn-cancel">Batal</button>
                <button type="button" class="ems-btn-confirm">Force Offline</button>
            </div>
        </div>
    </div>

    <script>
        //Global
        function normalizeName(str) {
            return (str || '')
                .toLowerCase()
                .replace(/[^a-z\s]/g, '') // hapus simbol
                .replace(/\s+/g, ' ') // rapikan spasi
                .trim();
        }

        function findSimilarConsumers(input, consumers) {
            const keyword = normalizeName(input);
            if (!keyword || keyword.length < 3) return [];

            const tokens = keyword.split(' ');

            return consumers.filter(name => {
                const normalized = normalizeName(name);

                // aturan DataTables-like:
                // 1. keyword ada di nama
                if (normalized.includes(keyword)) return true;

                // 2. semua token ada di nama
                return tokens.every(t => normalized.includes(t));
            });
        }

        const EXISTING_CONSUMERS = <?= json_encode($consumerNames, JSON_UNESCAPED_UNICODE); ?>;
        // Flag dari PHP: apakah form perlu dikosongkan setelah transaksi sukses?
        const SHOULD_CLEAR_FORM = <?= $shouldClearForm ? 'true' : 'false'; ?>;

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
        // Flag global: apakah pilihan saat ini menyebabkan melewati batas harian
        let IS_OVER_LIMIT = false;
        let PRIORITY_LOCK = false;
        let LAST_CONSUMER_NAME = '';
        let CONSUMER_LOCK = false;
        let NOTICE_STATE = 'NONE';

        const STORAGE_KEY = 'farmasi_ems_form';

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

        function getBaseTotalsForConsumer(name) {
            if (!name) {
                return {
                    bandage: 0,
                    ifaks: 0,
                    painkiller: 0
                };
            }
            const key = name.trim().toLowerCase();
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
            const data = {
                consumer_name: consumerInput ? consumerInput.value : '',
                pkg_main: document.getElementById('pkg_main')?.value || '',
                pkg_bandage: document.getElementById('pkg_bandage')?.value || '',
                pkg_ifaks: document.getElementById('pkg_ifaks')?.value || '',
                pkg_painkiller: document.getElementById('pkg_painkiller')?.value || '',
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
                    consumerInput.value = data.consumer_name;
                }
                ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    if (data[id]) {
                        el.value = data[id];
                    }
                });
            } catch (e) {
                // abaikan
            }
        }

        function formatConsumerName(name) {
            if (!name) return '';

            return name
                .toLowerCase()
                .replace(/\s+/g, ' ')
                .trim()
                .split(' ')
                .map(w => w.charAt(0).toUpperCase() + w.slice(1))
                .join(' ');
        }

        function showFairnessNotice(html) {
            const box = document.getElementById('fairnessNotice');
            const btn = document.getElementById('btnSubmit');
            if (!box) return;

            FAIRNESS_STATE.locked = false;

            box.innerHTML = html;
            box.style.display = 'block';

            // if (btn) {
            //     btn.disabled = true;
            //     btn.classList.add('btn-disabled');
            // }
        }

        function clearFairnessNotice() {
            const box = document.getElementById('fairnessNotice');
            const btn = document.getElementById('btnSubmit');

            FAIRNESS_STATE.locked = false;

            if (box) {
                box.style.display = 'none';
                box.innerHTML = '';
            }

            if (btn) {
                btn.disabled = false;
                btn.classList.remove('btn-disabled');
            }
        }

        // ===============================
        // 🚫 CONSUMER NOTICE (LOKAL)
        // ===============================
        function showConsumerNotice(html) {
            const box = document.getElementById('consumerNotice');
            if (!box) return;

            CONSUMER_LOCK = true;

            box.innerHTML = html;
            box.style.display = 'block';
        }

        function clearConsumerNotice() {
            const box = document.getElementById('consumerNotice');
            if (!box) return;

            CONSUMER_LOCK = false;

            box.style.display = 'none';
            box.innerHTML = '';
        }

        function recalcTotals() {
            // ⛔ Fairness hanya mengunci submit, bukan logic input
            const fairnessLocked = FAIRNESS_STATE.locked;

            // Kumpulkan ID paket yang dipilih
            const ids = [];
            ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
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

            // ===== Cek limit harian berdasarkan nama konsumen =====
            const consumerInput = document.querySelector('input[name="consumer_name"]');
            const cname = consumerInput ? consumerInput.value.trim() : '';
            const btnSubmit = document.getElementById('btnSubmit');

            if (cname !== LAST_CONSUMER_NAME) {
                clearConsumerNotice();
                LAST_CONSUMER_NAME = cname;
            }

            if (btnSubmit) {
                btnSubmit.disabled = false;
            }

            let detail = [];
            let alreadyBoughtToday = false;

            if (!cname || cname.length < 3) {
                clearConsumerNotice(); // 🔥 HANYA consumer
                IS_OVER_LIMIT = false;
                return;
            } else {
                const key = cname.trim().toLowerCase();
                detail = DAILY_DETAIL[key] || [];
                alreadyBoughtToday = detail.length > 0;
            }

            // PRIORITY 2: 1 KONSUMEN = 1 TRANSAKSI/HARI (hanya kalau nama sudah diisi)
            if (alreadyBoughtToday) {
                IS_OVER_LIMIT = false;
                CONSUMER_LOCK = true;

                let html = '';

                html += '🚫 <strong>' + escapeHtml(cname) + '</strong> ';
                html += 'sudah melakukan <strong>1 transaksi hari ini</strong>.<br>';
                html += 'Transaksi tambahan <strong>tidak diperbolehkan</strong>.<br><br>';

                // ===============================
                // DETAIL TRANSAKSI HARI INI
                // ===============================
                html += '<strong>Detail pembelian hari ini:</strong>';
                html += '<ul style="margin-top:6px;padding-left:18px;">';

                detail.forEach(function(d) {
                    const waktu = d.time ? d.time : '-';

                    html += '<li style="margin-bottom:6px;">' +
                        '📦 <strong>' + escapeHtml(d.package || '-') + '</strong><br>' +
                        '<small>' +
                        '🕒 ' + escapeHtml(waktu) +
                        ' &nbsp;|&nbsp; 👨‍⚕️ ' + escapeHtml(d.medic || '-') +
                        '</small>' +
                        '</li>';
                });

                html += '</ul>';

                // ===============================
                // INFO TAMBAHAN & ATURAN SISTEM
                // ===============================
                html += '<small style="display:block;margin-top:6px;">';
                html += 'Silakan konfirmasi ke konsumen bahwa pembelian telah dilakukan ';
                html += 'pada waktu dan petugas medis yang tercantum di atas.<br><br>';

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

                html += 'Transaksi baru dapat dilakukan kembali pada ';
                html += '<strong>' + nextDateStr + '</strong>.<br><br>';

                html += '<strong>Ketentuan Sistem:</strong><br>';
                html += 'Perhitungan batas transaksi didasarkan pada ';
                html += '<strong>tanggal kalender</strong>, ';
                html += 'bukan durasi 24 jam sejak transaksi terakhir. ';
                html += 'Transaksi setelah pergantian hari (pukul 00:00) ';
                html += 'dianggap sebagai transaksi hari berikutnya.';
                html += '</small>';

                // ===============================
                // TAMPILKAN NOTICE KONSUMEN
                // ===============================
                showConsumerNotice(html);
                return; // ⛔ STOP — tidak lanjut ke proses lain
            }
        }

        function onPackageChange() {
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

            ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
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
                btnSubmit.disabled = false;
                btnSubmit.classList.remove('btn-disabled');
            }

            IS_OVER_LIMIT = false;
            const forceOver = document.getElementById('force_overlimit');
            if (forceOver) forceOver.value = '0';

            recalcTotals();
        }

        function handleSaveClick() {
            const btnSubmit = document.getElementById('btnSubmit');
            const form = document.getElementById('saleForm');
            const consumerInput = document.getElementById('consumerNameInput');

            const cname = formatConsumerName(consumerInput.value);
            consumerInput.value = cname;

            const baseTotals = getBaseTotalsForConsumer(cname);
            const hasPrevious = (baseTotals.bandage + baseTotals.ifaks + baseTotals.painkiller) > 0;

            const totalPriceDisplay = document.getElementById('totalPriceDisplay');
            const totalText = totalPriceDisplay ? totalPriceDisplay.textContent : '$ 0';

            const forceOverInput = document.getElementById('force_overlimit');

            // if (FAIRNESS_STATE.locked) {
            //     alert('⚠️ Transaksi diblokir oleh sistem fairness.');
            //     return;
            // }

            // ===============================
            // ⏱️ COOLDOWN CLIENT (UX SAJA)
            // ===============================
            if (window.__lastSubmitAt) {
                const now = Date.now();
                const diff = Math.floor((now - window.__lastSubmitAt) / 1000);

                if (diff < 60) {
                    alert(`⏳ Mohon tunggu ${60 - diff} detik sebelum transaksi berikutnya.`);
                    return;
                }
            }

            if (CONSUMER_LOCK) {
                alert('🚫 Konsumen ini sudah melakukan transaksi hari ini.');
                return;
            }

            if (forceOverInput) {
                // default: tidak override
                forceOverInput.value = '0';
            }

            let msg = "";

            if (IS_OVER_LIMIT) {
                // Kasus: kalau disimpan, dia akan MELEBIHI batas harian
                msg += "⚠️ Orang ini telah mencapai / akan melewati batas maksimal pembelian harian.\n\n";

                if (cname) {
                    msg += "Nama konsumen: " + cname + "\n\n" +
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
                    msg += "Nama konsumen: " + cname + "\n\n";

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
                window.__lastSubmitAt = Date.now();
                form.submit();
            }
        }

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

        // ===== JAM & TANGGAL REALTIME (BERDASARKAN WAKTU LOKAL PERANGKAT) =====
        function getIndonesiaTimeZoneName(date) {
            // getTimezoneOffset = selisih terhadap UTC dalam menit (WIB = -420, WITA = -480, WIT = -540)
            const offsetMinutes = -date.getTimezoneOffset();

            if (offsetMinutes === 7 * 60) return 'WIB (UTC+7)';
            if (offsetMinutes === 8 * 60) return 'WITA (UTC+8)';
            if (offsetMinutes === 9 * 60) return 'WIT (UTC+9)';

            // Jika di luar Indonesia, fallback ke label umum
            return 'Zona waktu lokal';
        }

        function updateLocalClock() {
            const el = document.getElementById('localClock');
            if (!el) return;

            const now = new Date();
            const tzName = getIndonesiaTimeZoneName(now);

            const tanggal = now.toLocaleDateString('id-ID', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            const jam = now.toLocaleTimeString('id-ID', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            });

            el.textContent = `${tanggal} • ${jam} (${tzName})`;
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
                        saveFormState();
                        recalcTotals();
                    });
                });
            }

            const rangeSel = document.getElementById('rangeSelect');
            if (rangeSel) {
                rangeSel.addEventListener('change', updateCustomDateVisibility);
            }

            updateCustomDateVisibility();
            recalcTotals(); // hitung awal berdasarkan form yang dipulihkan
            renderPricePerPcs();

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
            if (window.jQuery && jQuery.fn.DataTable) {
                const table = jQuery('#salesTable').DataTable({
                    pageLength: 10,
                    order: [
                        [1, 'desc']
                    ],
                    language: {
                        url: "https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json"
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

                const $selectAll = jQuery('#selectAll');
                const $bulkBtn = jQuery('#btnBulkDelete');

                function updateBulkButton() {
                    const anyChecked = jQuery('.row-check:checked').length > 0;
                    $bulkBtn.prop('disabled', !anyChecked);
                }

                // Select/Deselect all (hanya yang ada checkboxnya)
                $selectAll.on('click', function() {
                    const checked = this.checked;
                    jQuery('#salesTable tbody .row-check').prop('checked', checked);
                    updateBulkButton();
                });

                // Per row checkbox
                jQuery(document).on('change', '.row-check', function() {
                    const total = jQuery('#salesTable tbody .row-check').length;
                    const checked = jQuery('#salesTable tbody .row-check:checked').length;

                    if (!this.checked) {
                        $selectAll.prop('checked', false);
                    } else if (checked === total && total > 0) {
                        $selectAll.prop('checked', true);
                    }

                    updateBulkButton();
                });
            }
        });
    </script>
    <script>
        (function() {
            const container = document.getElementById('onlineMedicsContainer');
            const totalBadge = document.getElementById('totalMedicsBadge');
            if (!container || !totalBadge) return;

            // ⏱️ STATE GLOBAL (JANGAN RESET SETIAP RENDER)
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
                    seconds: m.weekly_online_seconds
                })));

                // ⬇️ SKIP RENDER KALAU DATA SAMA
                if (dataHash === lastDataHash) return;
                lastDataHash = dataHash;

                // ⬇️ RESET baseTimestamp HANYA KALAU RENDER ULANG
                baseTimestamp = {};

                container.innerHTML = '';
                updateTotal(data.length);

                if (!data.length) {
                    container.innerHTML = '<p style="font-size:13px;color:#64748b;">Tidak ada medis yang sedang online.</p>';
                    return;
                }

                data.forEach(m => {
                    const row = document.createElement('div');
                    row.className = 'online-medic-row';
                    row.innerHTML = `
                <div class="medic-main">
                    <strong>${escapeHtml(m.medic_name)}</strong>
                    <span class="weekly-badge">Minggu ini: ${m.weekly_transaksi} trx</span>
                    <span class="weekly-online" data-seconds="${m.weekly_online_seconds}" data-user-id="${m.user_id}">
                        ⏱️ Online: ${m.weekly_online_text}
                    </span>
                    <div class="medic-role">${escapeHtml(m.medic_jabatan)}</div>
                    <button class="btn-force-offline"
                        data-user-id="${m.user_id}"
                        data-name="${escapeHtml(m.medic_name)}"
                        data-jabatan="${escapeHtml(m.medic_jabatan)}">
                        🛑 Force Offline
                    </button>
                </div>
                <div class="medic-stats">
                    <div class="tx">${m.total_transaksi} trx</div>
                    <div class="amount">$ ${Number(m.total_pendapatan).toLocaleString()}</div>
                    <div class="bonus" style="font-size:12px;color:#16a34a;">
                        Bonus: $ ${Number(m.bonus_40).toLocaleString()}
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

                    span.textContent = `⏱️ Online: ${hours}j ${minutes}m ${seconds}d`;
                });
            }

            // =============================================
            // FETCH DATA DARI SERVER (SETIAP 1 DETIK)
            // =============================================
            async function fetchMedics() {
                try {
                    const res = await fetch('/actions/get_online_medics.php', {
                        cache: 'no-store'
                    });
                    const data = await res.json();
                    renderMedics(data); // ⬅️ RENDER HANYA KALAU DATA BERUBAH
                } catch (e) {
                    console.error('Realtime medis gagal', e);
                }
            }

            // ⏱️ TIMER UPDATE (SETIAP 1 DETIK, INDEPENDEN)
            setInterval(updateOnlineDurations, 1000);

            // ⏱️ FETCH DATA (SETIAP 1 DETIK)
            fetchMedics();
            setInterval(fetchMedics, 1000);
        })();
    </script>

    <script>
        (function() {
            const container = document.getElementById('activityFeedList');
            if (!container) return;

            const ACTIVITY_CLOSED_KEY = 'farmasi_activity_closed';

            const MAX_ITEMS = 10;
            let lastActivityHash = '';

            let lastActivityId = null;
            let isFirstLoad = true;

            const sound = document.getElementById('activitySound');
            if (sound && !sound.muted) {
                sound.currentTime = 0;
                sound.play().catch(() => {});
            }

            // ===============================
            // ICON MAPPING
            // ===============================
            const ACTIVITY_ICONS = {
                'transaction': '💰',
                'online': '🟢',
                'offline': '⚫',
                'force_offline': '🛑',
                'delete': '🗑️'
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
                ${ACTIVITY_ICONS[data.type] || '📌'}
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
                if (!newActivities.length) return;

                const newestId = newActivities[0].id;

                // 🔔 BUNYI HANYA JIKA ADA ACTIVITY BARU
                if (!isFirstLoad && lastActivityId !== null && newestId > lastActivityId) {
                    playSound();
                }

                lastActivityId = newestId;
                isFirstLoad = false;

                // ===== LOGIC LAMA TETAP =====
                const newHash = JSON.stringify(newActivities.map(a => a.id));
                if (newHash === lastActivityHash) return;
                lastActivityHash = newHash;

                container.innerHTML = '';

                newActivities.forEach(activity => {
                    container.appendChild(renderActivity(activity));
                });
            }

            // ⛔ Jika user sudah menutup activity (session-based)
            if (sessionStorage.getItem(ACTIVITY_CLOSED_KEY) === '1') {
                const wrapper = document.querySelector('.activity-feed-container');
                if (wrapper) wrapper.style.display = 'none';
                return; // 🔥 STOP: tidak fetch, tidak render, tidak bunyi
            }

            // ===============================
            // FETCH DARI SERVER
            // ===============================
            async function fetchActivities() {
                try {
                    const res = await fetch('/actions/get_activities.php', {
                        cache: 'no-store'
                    });

                    if (!res.ok) return;

                    const data = await res.json();
                    updateList(data);

                } catch (e) {
                    console.error('Activity feed error:', e);
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
            fetchActivities(); // Fetch pertama kali
            setInterval(fetchActivities, 3000); // Fetch data baru setiap 3 detik

            // ⏱️ UPDATE TIME SETIAP 10 DETIK
            setInterval(updateAllTimes, 10000);

            const btnClose = document.getElementById('btnCloseActivity');
            const wrapper = document.querySelector('.activity-feed-container');

            if (btnClose && wrapper) {
                btnClose.addEventListener('click', function() {
                    // simpan status close (session only)
                    sessionStorage.setItem(ACTIVITY_CLOSED_KEY, '1');

                    // sembunyikan langsung
                    wrapper.style.display = 'none';
                });
            }


        })();
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const audio = document.getElementById('activitySound');
            const btn = document.getElementById('btnToggleActivitySound');

            if (!audio || !btn) return;

            let muted = localStorage.getItem('activity_sound_muted') === '1';

            function syncUI() {
                audio.muted = muted;
                btn.textContent = muted ? '🔇' : '🔊';
                btn.classList.toggle('is-muted', muted);
                btn.title = muted ? 'Aktifkan suara activity' : 'Matikan suara activity';
            }

            syncUI();

            btn.addEventListener('click', () => {
                muted = !muted;
                localStorage.setItem('activity_sound_muted', muted ? '1' : '0');
                syncUI();
            });
        });
    </script>

</section>
<script>
    function koreksiNamaKonsumen(oldName, newName) {
        const msg =
            `Yakin ingin mengoreksi nama konsumen?\n\n` +
            `DARI : ${oldName}\n` +
            `KE   : ${newName}\n\n` +
            `Semua transaksi lama akan ikut diperbaiki.\n\n` +
            `Klik OK untuk lanjut.`;

        if (!confirm(msg)) return;

        fetch('/actions/koreksi_nama_konsumen.php', {
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
                    alert(`✅ Berhasil!\n${count} transaksi diperbaiki.`);
                    location.reload();
                } else {
                    alert('❌ Gagal:\n' + text);
                }
            })
            .catch(err => {
                alert('❌ Error koneksi');
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

        async function checkStatus() {
            try {
                const res = await fetch('/actions/get_farmasi_status.php', {
                    cache: 'no-store'
                });
                const json = await res.json();
                updateUI(json.status);
            } catch (e) {
                console.error('Status check failed', e);
            }
        }

        // ⏱️ Cek setiap 5 detik (aman & ringan)
        checkStatus();
        setInterval(checkStatus, 5000);
    })();
</script>

<script>
    (function() {

        let lastState = null;

        async function checkFairness() {
            try {
                const res = await fetch('/actions/get_fairness_status.php', {
                    cache: 'no-store'
                });

                if (!res.ok) return;

                const data = await res.json();

                const selisih = parseInt(data.selisih || 0, 10);
                const blocked = !!data.blocked;
                const threshold = parseInt(data.threshold || 10, 10);

                // 🔒 STATE KEY HARUS TERMASUK STATUS USER
                const stateKey = blocked + ':' + selisih + ':' + data.user_status;
                if (stateKey === lastState) return;
                lastState = stateKey;

                // ===============================
                // ⛔ USER OFFLINE → TAMPILKAN NOTICE
                // ===============================
                if (data.user_status === 'offline') {

                    showFairnessNotice(`
                        🚫 <strong>Status Anda OFFLINE</strong><br><br>
                        Anda tidak dapat melakukan transaksi selama status OFFLINE.<br>
                        Silakan Klik Tombol <strong>OFFLINE</strong> untuk merubah status menjadi <strong>ONLINE</strong> untuk melanjutkan.
                    `);

                    return;
                }

                // ===============================
                // ✅ USER ONLINE → PASTIKAN NOTICE OFFLINE HILANG
                // ===============================
                clearFairnessNotice();

                // ===============================
                // 🔴 HARD LOCK (FAIRNESS BLOCK)
                // ===============================
                if (blocked) {
                    //     showFairnessNotice(`
                    //     ⚠️ <strong>Distribusi transaksi tidak seimbang</strong><br><br>
                    //     Anda memiliki <strong>${selisih}</strong> transaksi lebih banyak.<br><br>
                    //     👨‍⚕️ <strong>Silakan arahkan konsumen ke:</strong><br>
                    //     <strong>${escapeHtml(data.medic_name || '-')}</strong><br>
                    //     <small>
                    //         ${escapeHtml(data.medic_jabatan || '-')}
                    //         • ${parseInt(data.total_transaksi || 0, 10)} trx
                    //     </small>
                    // `);
                    //     return;
                }

                // // ===============================
                // // 🟡 EARLY WARNING (BELUM LOCK)
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
                //         ⚠️ <strong>Distribusi transaksi tidak seimbang</strong><br>
                //         Selisih transaksi Anda saat ini:
                //         <strong>${selisih}</strong>.<br><br>
                //         👨‍⚕️ Medis dengan transaksi paling sedikit:<br>
                //         <strong>${escapeHtml(data.medic_name || '-')}</strong><br>
                //         <small>${escapeHtml(data.medic_jabatan || '-')}</small>
                //     `;
                //     box.style.display = 'block';
                //     return;
                // }

                // ===============================
                // 🟢 AMAN TOTAL (SELISIH = 0)
                // ===============================
                clearFairnessNotice();

            } catch (e) {
                console.error('Fairness error:', e);
            }
        }

        // Jalankan pertama kali
        checkFairness();

        // Cek ulang setiap 3 detik
        setInterval(checkFairness, 3000);

    })();
</script>

<script>
    (function() {
        const box = document.getElementById('cooldownNotice');
        const btn = document.getElementById('btnSubmit');
        if (!box || !btn) return;

        let timer = null;

        async function checkCooldown() {
            try {
                const res = await fetch('/actions/get_global_cooldown.php', {
                    cache: 'no-store'
                });
                const data = await res.json();

                // ❌ Tidak aktif → pastikan bersih
                if (!data.active) {
                    box.style.display = 'none';
                    box.innerHTML = '';
                    btn.disabled = false;
                    btn.classList.remove('btn-disabled');

                    if (timer) {
                        clearInterval(timer);
                        timer = null;
                    }
                    return;
                }

                // ✅ Aktif → hanya untuk USER INI
                btn.disabled = true;
                btn.classList.add('btn-disabled');

                const remain = parseInt(data.remain || 0, 10);

                box.innerHTML = `
                ⏳ <strong>Cooldown transaksi</strong><br>
                Anda baru saja menyimpan transaksi.<br>
                Silakan tunggu <strong>${remain} detik</strong>
                sebelum transaksi berikutnya.
            `;
                box.style.display = 'block';

            } catch (e) {
                console.error('Cooldown error', e);
            }
        }

        checkCooldown();
        setInterval(checkCooldown, 1000);
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
                "⚠️ Apakah Anda yakin ingin OFFLINE?\n\nAnda tidak akan menerima transaksi farmasi." :
                "✅ Apakah Anda yakin ingin ONLINE?\n\nAnda akan mulai menerima transaksi farmasi.";

            if (!confirm(message)) {
                return; // ❌ batal
            }

            isBusy = true;

            try {
                const res = await fetch('/actions/toggle_farmasi_status.php', {
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
                alert('❌ Koneksi ke server gagal');
                console.error(e);
            }

            isBusy = false;
        });
    })();
</script>

<script>
    (function() {
        let targetUserId = null;
        let targetName = null;
        let targetJabatan = null; // ➕ TAMBAHAN (TIDAK MENGGANGGU YANG LAIN)

        const modal = document.getElementById('emsForceModal');
        const desc = document.getElementById('emsForceDesc');
        const reasonInput = document.getElementById('emsForceReason');
        const btnCancel = modal.querySelector('.ems-btn-cancel');
        const btnConfirm = modal.querySelector('.ems-btn-confirm');

        function openModal(userId, name, jabatan) {
            targetUserId = userId;
            targetName = name || '-';
            targetJabatan = jabatan;

            desc.innerHTML =
                `Nama Medis: <strong>${name}</strong><br>` +
                `Jabatan: <strong>${jabatan}</strong><br>` +
                `Status akan diubah menjadi <strong style="color:#dc2626;">OFFLINE</strong>.`;

            reasonInput.value = '';
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');

            setTimeout(() => reasonInput.focus(), 50);
        }

        function closeModal() {
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
            targetUserId = null;
            targetName = null;
            targetJabatan = null;
        }

        // Klik tombol Force Offline (delegation)
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-force-offline');
            if (!btn) return;

            openModal(
                btn.dataset.userId,
                btn.dataset.name,
                btn.dataset.jabatan // ➕ AMBIL JABATAN
            );
        });

        // Batal
        btnCancel.addEventListener('click', closeModal);

        // Klik overlay untuk tutup
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });

        // Konfirmasi Force Offline
        btnConfirm.addEventListener('click', async function() {
            const reason = reasonInput.value.trim();

            if (reason.length < 5) {
                alert('❌ Alasan wajib diisi (min. 5 karakter)');
                reasonInput.focus();
                return;
            }

            btnConfirm.textContent = 'Memproses...';
            btnConfirm.style.pointerEvents = 'none';

            try {
                const res = await fetch('/actions/force_offline_medis.php', {
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
                alert(`✅ berhasil di-FORCE OFFLINE`);

            } catch (err) {
                console.error(err);
                alert('❌ Koneksi server gagal');
            }

            btnConfirm.textContent = 'Force Offline';
            btnConfirm.style.pointerEvents = 'auto';
        });

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