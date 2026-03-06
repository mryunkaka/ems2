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

$medicName    = $user['name'] ?? '';
$medicJabatan = $user['position'] ?? '';
$medicRole    = $user['role'] ?? '';

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

// Flag untuk PRG
$redirectAfterPost = false;

// ======================================================
// HANDLE REQUEST POST (SEMUA ACTION FORM DI SINI)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $identityId = (int)($_POST['identity_id'] ?? 0);

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
        if ($medicName === '' || $medicJabatan === '') {
            $errors[] = "Anda harus login sebagai petugas medis untuk menghapus transaksi.";
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
        // Disable auto merge jika identity aktif
        if ($identityId > 0) {
            $autoMerge = false;
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
                    $errors[] = "Identitas konsumen wajib diisi.";
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
                        WHERE identity_id = :identity_id
                        AND DATE(created_at) = :today
                    ");
                    $stmt->execute([
                        ':identity_id' => $identityId,
                        ':today'       => $todayDate,
                    ]);

                    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                        'total_bandage'    => 0,
                        'total_ifaks'      => 0,
                        'total_painkiller' => 0,
                    ];

                    // ===============================
                    // VALIDASI 1 IDENTITAS = 1 TRANSAKSI / HARI
                    // ===============================
                    $totalToday =
                        (int)$totals['total_bandage'] +
                        (int)$totals['total_ifaks'] +
                        (int)$totals['total_painkiller'];

                    if ($totalToday > 0) {
                        $errors[] = "Konsumen ini sudah melakukan transaksi hari ini (berdasarkan identitas).";
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
                // INSERT TRANSAKSI (AMAN)
                // ===============================
                if (empty($errors)) {

                    $now = date('Y-m-d H:i:s');

                    $stmtInsert = $pdo->prepare("
            INSERT INTO sales
            (identity_id, consumer_name, medic_name, medic_jabatan, package_id, package_name,
             price, qty_bandage, qty_ifaks, qty_painkiller, created_at, tx_hash)
            VALUES
            (:identity_id,:cname, :mname, :mjab, :pid, :pname,
             :price, :qb, :qi, :qp, :created, :tx)
        ");

                    try {
                        foreach ($selectedIds as $id) {
                            $p = $packagesSelected[$id];

                            $txHash = hash('sha256', $postedToken . '|' . $id);

                            $stmtInsert->execute([
                                ':identity_id' => $identityId,
                                ':cname'   => $consumerName,
                                ':mname'   => $medicName,
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
    SELECT 
        s.*,
        im.citizen_id
    FROM sales s
    LEFT JOIN identity_master im ON im.id = s.identity_id
    WHERE s.created_at BETWEEN :start AND :end
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
            medic_name,
            medic_jabatan,
            COUNT(*) AS total_transaksi,
            SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
            SUM(price) AS total_rupiah
        FROM sales
        WHERE created_at BETWEEN :start AND :end
          AND medic_name = :mname
        GROUP BY medic_name, medic_jabatan
        LIMIT 1
    ");
    $stmtSingle->execute([
        ':start' => $rangeStart,
        ':end'   => $rangeEnd,
        ':mname' => $medicName,
    ]);
    $singleMedicStats = $stmtSingle->fetch(PDO::FETCH_ASSOC) ?: null;
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
            <!-- Card Input Transaksi -->
            <div class="card">
                <div class="card-header card-header-actions card-header-flex">
                    <div class="card-header-actions-title">
                        Input Transaksi Baru
                    </div>

                    <a
                        href="/dashboard/rekap_farmasi.php"
                        class="btn-secondary btn-sm"
                        title="Buka Rekap Farmasi Versi 1">
                        ⏪ Versi Lama
                    </a>
                </div>

                <?php if ($medicName): ?>
                    <p style="margin:4px 0 8px;">
                        Anda telah login sebagai
                        <strong><?= htmlspecialchars($medicName) ?></strong>
                        (<?= htmlspecialchars($medicJabatan) ?>)
                    </p>

                    <!-- <div id="dailyNotice" style="
                        margin:6px 0 12px;
                        padding:8px 12px;
                        border-left:4px solid #f59e0b;
                        background:#fff7ed;
                        color:#92400e;
                        font-size:13px;
                        border-radius:6px;
                    ">
                        <strong>⚠️ Perhatian:</strong><br>
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

                <?php
                // ===============================
                // IDEMPOTENCY TOKEN (ANTI DOUBLE)
                // ===============================
                if (empty($_SESSION['tx_token'])) {
                    $_SESSION['tx_token'] = bin2hex(random_bytes(32));
                }
                ?>

                <form method="post" id="saleForm">

                    <!-- ===============================
                    HIDDEN SYSTEM FIELDS
                    =============================== -->
                    <input type="hidden" name="action" value="add_sale">
                    <input type="hidden" name="tx_token" value="<?= $_SESSION['tx_token'] ?>">
                    <input type="hidden" name="auto_merge" id="auto_merge" value="0">
                    <input type="hidden" name="merge_targets" id="merge_targets">
                    <input type="hidden" name="force_overlimit" id="force_overlimit" value="0">

                    <!-- 🔑 IDENTITAS (DARI OCR) -->
                    <input type="hidden" name="identity_id" id="identity_id">

                    <!-- ===============================
                    KONSUMEN
                    =============================== -->
                    <div class="row-form-2">
                        <div class="col">

                            <label>Identitas Konsumen</label>

                            <!-- Tombol OCR -->
                            <div style="margin-bottom:6px;">
                                <button type="button"
                                    class="btn-secondary"
                                    onclick="openIdentityScan()">
                                    📷 Scan Identitas (OCR)
                                </button>
                            </div>

                            <!-- Nama Konsumen (AUTO dari OCR) -->
                            <input
                                type="text"
                                name="consumer_name"
                                id="consumerNameInput"
                                placeholder="Scan identitas terlebih dahulu"
                                required
                                readonly
                                style="background:#f9fafb;cursor:not-allowed;">

                            <small style="color:#92400e;">
                                Nama akan terisi otomatis dari hasil scan identitas (KTP / ID).
                                Input manual tidak diperbolehkan.
                            </small>
                        </div>

                        <!-- ===============================
                        PAKET A / B
                        =============================== -->
                        <div class="col">
                            <label>Paket A / B (Combo)</label>
                            <select name="package_main" id="pkg_main">
                                <option value="">-- Tidak Pakai Paket A/B --</option>
                                <?php foreach ($paketAB as $pkg): ?>
                                    <option value="<?= (int)$pkg['id'] ?>">
                                        <?= htmlspecialchars($pkg['name']) ?>
                                        (<?= dollar((int)$pkg['price']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- ===============================
                    PAKET SATUAN
                    =============================== -->
                    <div class="row-form-2">
                        <div class="col">
                            <label>Paket Bandage</label>
                            <select name="package_bandage" id="pkg_bandage">
                                <option value="">-- Tidak pilih paket Bandage --</option>
                                <?php foreach ($bandagePackages as $pkg): ?>
                                    <option value="<?= (int)$pkg['id'] ?>">
                                        <?= htmlspecialchars($pkg['name']) ?>
                                        (<?= dollar((int)$pkg['price']) ?>)
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
                                        <?= htmlspecialchars($pkg['name']) ?>
                                        (<?= dollar((int)$pkg['price']) ?>)
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
                                        <?= htmlspecialchars($pkg['name']) ?>
                                        (<?= dollar((int)$pkg['price']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- ===============================
                    INFO TOTAL
                    =============================== -->
                    <div class="total-item-info">
                        <strong>Total item terpilih:</strong>
                        Bandage (<span id="priceBandage">-</span>/pcs):
                        <span id="totalBandage">0</span>,
                        IFAKS (<span id="priceIfaks">-</span>/pcs):
                        <span id="totalIfaks">0</span>,
                        Painkiller (<span id="pricePainkiller">-</span>/pcs):
                        <span id="totalPainkiller">0</span>,
                        Bonus 40% (estimasi):
                        <span id="totalBonus">0</span>
                    </div>

                    <!-- WARNING LIMIT -->
                    <div id="limitWarning"
                        style="margin-top:6px;font-size:13px;color:#f97316;display:none;">
                    </div>

                    <!-- TOTAL DISPLAY -->
                    <div class="total-display">
                        <div class="total-display-label">Total yang harus dibayar</div>
                        <div class="total-amount" id="totalPriceDisplay">$ 0</div>
                    </div>

                    <!-- ===============================
                    ACTION BUTTONS
                    =============================== -->
                    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button
                            type="button"
                            id="btnSubmit"
                            class="btn-success"
                            onclick="handleSaveClick();">
                            Simpan Transaksi
                        </button>

                        <button
                            type="button"
                            class="btn-secondary"
                            onclick="clearFormInputs();">
                            Clear
                        </button>
                    </div>

                </form>


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
                                    Minggu 1 (<?= $weeks['week1']['start']->format('d M') ?> – <?= $weeks['week1']['end']->format('d M') ?>)
                                </option>

                                <option value="week2" <?= $range === 'week2' ? 'selected' : '' ?>>
                                    Minggu 2 (<?= $weeks['week2']['start']->format('d M') ?> – <?= $weeks['week2']['end']->format('d M') ?>)
                                </option>

                                <option value="week3" <?= $range === 'week3' ? 'selected' : '' ?>>
                                    Minggu 3 (<?= $weeks['week3']['start']->format('d M') ?> – <?= $weeks['week3']['end']->format('d M') ?>)
                                </option>

                                <option value="week4" <?= $range === 'week4' ? 'selected' : '' ?>>
                                    Minggu 4 (<?= $weeks['week4']['start']->format('d M') ?> – <?= $weeks['week4']['end']->format('d M') ?>)
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
                                <?php
                                $bonusSingle = (int)floor((int)$singleMedicStats['total_rupiah'] * 0.4);
                                ?>
                                <tr>
                                    <td>1</td>
                                    <td><?= htmlspecialchars($singleMedicStats['medic_name']) ?></td>
                                    <td><?= htmlspecialchars($singleMedicStats['medic_jabatan']) ?></td>
                                    <td><?= (int)$singleMedicStats['total_transaksi'] ?></td>
                                    <td><?= (int)$singleMedicStats['total_item'] ?></td>
                                    <td><?= dollar((int)$singleMedicStats['total_rupiah']) ?></td>
                                    <td><?= dollar($bonusSingle) ?></td>
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
                                        <th>Citizen ID</th>
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
                                        <th></th> <!-- Paket -->
                                        <th></th> <!-- Bandage -->
                                        <th></th> <!-- IFAKS -->
                                        <th></th> <!-- Painkiller -->
                                        <th></th> <!-- Harga -->
                                        <th></th> <!-- Bonus -->
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
                                            <td><?= formatTanggalID($s['created_at']) ?></td>
                                            <td>
                                                <?php if (!empty($s['citizen_id'])): ?>
                                                    <a href="#"
                                                        class="identity-link"
                                                        data-identity-id="<?= (int)$s['identity_id'] ?>">
                                                        <?= htmlspecialchars($s['citizen_id']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color:#9ca3af;">-</span>
                                                <?php endif; ?>
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

    <script>
        let ALREADY_BOUGHT_TODAY = false;
        const DAILY_TOTALS = <?= json_encode($dailyTotalsJS, JSON_UNESCAPED_UNICODE); ?>;
        const DAILY_DETAIL = <?= json_encode($dailyDetailJS, JSON_UNESCAPED_UNICODE); ?>;
        const CONSUMER_STORAGE_KEY = 'farmasi_ems_consumer';
    </script>

    <script>
        function saveConsumerData(identityId, name) {
            if (!identityId || !name) return;
            try {
                localStorage.setItem(
                    CONSUMER_STORAGE_KEY,
                    JSON.stringify({
                        identity_id: identityId,
                        consumer_name: name
                    })
                );
            } catch (e) {}
        }
    </script>

    <script>
        function restoreConsumerData() {
            const nameInput = document.getElementById('consumerNameInput');
            const identityInput = document.getElementById('identity_id');

            if (!nameInput || !identityInput) return;

            try {
                const raw = localStorage.getItem(CONSUMER_STORAGE_KEY);
                if (!raw) return;

                const data = JSON.parse(raw);
                if (!data.identity_id || !data.consumer_name) return;

                identityInput.value = data.identity_id;
                nameInput.value = data.consumer_name;
                nameInput.readOnly = true;
                nameInput.style.background = '#f0fdf4';
            } catch (e) {}
        }
    </script>

    <script>
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
        // Flag global: apakah pilihan saat ini menyebabkan melewati batas harian
        let IS_OVER_LIMIT = false;

        const STORAGE_KEY = 'farmasi_ems_form';

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
            const identityInput = document.getElementById('identity_id');
            if (!identityInput || !identityInput.value) {
                // Jangan restore nama jika tidak ada identity
                return;
            }

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

        function recalcTotals() {
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
            // ===== Cek transaksi harian (1 konsumen = 1 transaksi / hari) =====
            const consumerInput = document.querySelector('input[name="consumer_name"]');
            const cname = consumerInput ? consumerInput.value.trim() : '';

            const warningBox = document.getElementById('limitWarning');
            const btnSubmit = document.getElementById('btnSubmit');

            let detail = [];
            let alreadyBoughtToday = false;

            if (cname) {
                const key = cname.trim().toLowerCase();
                detail = DAILY_DETAIL[key] || [];
                alreadyBoughtToday = detail.length > 0;
            }

            if (alreadyBoughtToday) {
                let html = '';

                html += '🚫 <strong>' + escapeHtml(cname) + '</strong> ';
                html += 'sudah melakukan <strong>1 transaksi hari ini</strong>.<br>';
                html += 'Transaksi tambahan <strong>tidak diperbolehkan</strong>.<br><br>';

                html += '<strong>Detail pembelian hari ini:</strong>';
                html += '<ul style="margin-top:6px;padding-left:18px;">';

                detail.forEach(function(d) {
                    const waktu = d.time ? d.time.substring(0, 16) : '-';
                    html += '<li>' +
                        '📦 <strong>' + escapeHtml(d.package || '-') + '</strong><br>' +
                        '<small>' +
                        '🕒 ' + escapeHtml(waktu) +
                        ' &nbsp;|&nbsp; 👨‍⚕️ ' + escapeHtml(d.medic || '-') +
                        '</small>' +
                        '</li>';
                });

                html += '</ul>';
                html += '<small style="display:block;margin-top:6px;">';
                html += 'Silakan konfirmasi ke konsumen bahwa pembelian telah dilakukan pada waktu tersebut.<br>';
                // Hitung tanggal transaksi berikutnya (besok jam 00:00)
                const now = new Date();
                const nextDay = new Date(
                    now.getFullYear(),
                    now.getMonth(),
                    now.getDate() + 1,
                    0, 0, 0
                );

                // Format tanggal Indonesia
                const monthsID = [
                    'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
                    'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
                ];

                const nextDateStr =
                    nextDay.getDate() + ' ' +
                    monthsID[nextDay.getMonth()] + ' ' +
                    nextDay.getFullYear() +
                    ' 00:00';

                html += 'Transaksi baru dapat dilakukan kembali pada <strong>' + nextDateStr + '</strong>.';
                html += '</small>';

                warningBox.style.display = 'block';
                warningBox.innerHTML = html;

                // 🔒 Kunci tombol simpan
                btnSubmit.disabled = true;
                btnSubmit.classList.add('btn-disabled');

            } else {
                // Belum ada transaksi hari ini → boleh lanjut
                warningBox.style.display = 'none';
                warningBox.innerHTML = '';

                btnSubmit.disabled = false;
                btnSubmit.classList.remove('btn-disabled');
            }
            ALREADY_BOUGHT_TODAY = alreadyBoughtToday;
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
            try {
                localStorage.removeItem(CONSUMER_STORAGE_KEY);
            } catch (e) {}

            const consumerInput = document.querySelector('input[name="consumer_name"]');
            const identityInput = document.getElementById('identity_id');

            const nameInput = document.getElementById('consumerNameInput');
            if (nameInput) {
                nameInput.value = '';
                nameInput.readOnly = true;
                nameInput.style.background = '#f9fafb';
            }

            // 🔑 RESET IDENTITAS & NAMA
            if (identityInput) {
                identityInput.value = '';
            }

            if (consumerInput) {
                consumerInput.value = '';
                consumerInput.readOnly = true; // kunci kembali
                consumerInput.style.background = '#f9fafb'; // warna default sebelum OCR
            }

            ['pkg_main', 'pkg_bandage', 'pkg_ifaks', 'pkg_painkiller'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) {
                    el.value = '';
                }
            });

            // Hapus state di localStorage supaya benar-benar bersih
            try {
                localStorage.removeItem(STORAGE_KEY);
            } catch (e) {
                // abaikan error
            }

            // Sembunyikan warning limit (kalau ada)
            const warningBox = document.getElementById('limitWarning');
            if (warningBox) {
                warningBox.style.display = 'none';
                warningBox.innerHTML = '';
            }

            const btnSubmit = document.getElementById('btnSubmit');
            if (btnSubmit) {
                btnSubmit.disabled = false;
            }

            // Reset flag over-limit & hidden input override
            IS_OVER_LIMIT = false;
            const forceOver = document.getElementById('force_overlimit');
            if (forceOver) {
                forceOver.value = '0';
            }
            ALREADY_BOUGHT_TODAY = false;

            // Hitung ulang total (akan jadi 0 semua)
            recalcTotals();
        }

        function handleSaveClick() {
            if (ALREADY_BOUGHT_TODAY) {
                alert('🚫 Konsumen ini sudah melakukan transaksi hari ini.');
                return;
            }

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
            restoreConsumerData();

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
                            if (typeof i === 'string') {
                                return i.replace(/[^\d]/g, '') * 1;
                            }
                            return typeof i === 'number' ? i : 0;
                        }

                        let totalBandage = api.column(7, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalIfaks = api.column(8, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalPain = api.column(9, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalPrice = api.column(10, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        let totalBonus = api.column(11, {
                                search: 'applied'
                            }).data()
                            .reduce((a, b) => intVal(a) + intVal(b), 0);

                        // isi footer
                        $(api.column(7).footer()).html(totalBandage);
                        $(api.column(8).footer()).html(totalIfaks);
                        $(api.column(9).footer()).html(totalPain);
                        $(api.column(10).footer()).html(formatDollar(totalPrice));
                        $(api.column(11).footer()).html(formatDollar(totalBonus));
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
    <div id="identityModal" style="
            display:none;
            position:fixed;
            inset:0;
            background:rgba(0,0,0,.6);
            z-index:99999;
            padding:60px 16px 16px;
            overflow:auto;
        ">

        <div style="
                background:#fff;
                max-width:900px;
                width:100%;
                margin:0 auto;
                border-radius:12px;
                box-shadow:0 20px 60px rgba(0,0,0,.3);
                display:flex;
                flex-direction:column;
                max-height:calc(100vh - 120px);
                position:relative;
                z-index:100000;
            ">

            <div style="
                    padding:16px 20px;
                    border-bottom:1px solid #e2e8f0;
                    display:flex;
                    justify-content:space-between;
                    align-items:center;
                    background:#fff;
                    border-radius:12px 12px 0 0;
                    position:relative;
                    z-index:100001;
                ">
                <strong style="font-size:16px;color:#0f172a;">📷 Scan Identitas</strong>
                <button onclick="closeIdentityScan()" style="
                        background:#ef4444;
                        color:#fff;
                        border:none;
                        padding:8px 16px;
                        border-radius:8px;
                        cursor:pointer;
                        font-weight:600;
                        font-size:14px;
                        transition:all 0.2s ease;
                    " onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                    ✖ Tutup
                </button>
            </div>

            <iframe
                src="/dashboard/identity_test.php"
                style="
                width:100%; 
                height:calc(100vh - 180px); 
                border:none; 
                display:block;
                border-radius:0 0 12px 12px;
            ">
            </iframe>
        </div>
    </div>
    <div id="identityViewModal" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.6);
    z-index:99998;
    padding:80px 16px 16px; /* ⬅ ruang header */
    overflow:auto;
">

        <div style="
        background:#fff;
        max-width:900px;
        width:100%;
        margin:0 auto;
        border-radius:12px;
        box-shadow:0 20px 60px rgba(0,0,0,.3);
        display:flex;
        flex-direction:column;
        max-height:calc(100vh - 120px);
        position:relative;
        z-index:99999;
    ">

            <!-- HEADER MODAL -->
            <div style="
            padding:16px 20px;
            border-bottom:1px solid #e2e8f0;
            display:flex;
            justify-content:space-between;
            align-items:center;
            background:#fff;
            border-radius:12px 12px 0 0;
            position:sticky;
            top:0;
            z-index:10;
        ">
                <strong style="font-size:16px;color:#0f172a;">📋 Data Konsumen</strong>

                <button onclick="closeIdentityViewModal()" style="
                background:#ef4444;
                color:#fff;
                border:none;
                padding:8px 16px;
                border-radius:8px;
                cursor:pointer;
                font-weight:600;
                font-size:14px;
            ">
                    ✖ Tutup
                </button>
            </div>

            <!-- BODY -->
            <div id="identityViewContent" style="
            padding:20px;
            overflow:auto;
        ">
                <p style="color:#9ca3af;">Memuat data...</p>
            </div>

        </div>
    </div>
    <!-- IMAGE LIGHTBOX (ZOOM KTP) -->
    <div id="imageLightbox" class="image-lightbox">
        <div class="lightbox-content">
            <button class="lightbox-close" onclick="closeLightbox()">×</button>
            <img class="lightbox-image" src="" alt="Preview KTP">
            <div class="lightbox-caption"></div>
        </div>
    </div>
</section>
<script>
    function openIdentityScan() {
        document.getElementById('identityModal').style.display = 'block';
    }

    function closeIdentityScan() {
        document.getElementById('identityModal').style.display = 'none';
    }

    // DITERIMA DARI identity_test.php
    window.addEventListener('message', function(e) {
        if (!e.data || !e.data.identity_id) return;

        // Isi field otomatis
        document.getElementById('identity_id').value = e.data.identity_id;

        const fullName = (
            (e.data.first_name || '') + ' ' +
            (e.data.last_name || '')
        ).trim();

        const nameInput = document.getElementById('consumerNameInput');
        if (nameInput) {
            nameInput.value = fullName;
            nameInput.readOnly = true;
            nameInput.style.background = '#f0fdf4';

            // 💾 SIMPAN KE LOCALSTORAGE
            saveConsumerData(e.data.identity_id, fullName);
        }

        alert('✅ Identitas berhasil dipindai & dikunci');
        closeIdentityScan();
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
    /* ================================
   VIEW IDENTITY (KLIK CITIZEN ID)
   ================================ */

    document.addEventListener('click', function(e) {
        const link = e.target.closest('.identity-link');
        if (!link) return;

        e.preventDefault();
        const identityId = link.dataset.identityId;
        openIdentityViewModal(identityId);
    });

    function openIdentityViewModal(identityId) {
        const modal = document.getElementById('identityViewModal');
        const content = document.getElementById('identityViewContent');

        if (!modal || !content) return;

        modal.style.display = 'flex';
        content.innerHTML = '<p style="color:#9ca3af;">Memuat data...</p>';

        fetch('/ajax/get_identity_detail.php?id=' + encodeURIComponent(identityId))
            .then(res => res.text())
            .then(html => {
                content.innerHTML = html;
            })
            .catch(err => {
                console.error('Error loading identity:', err);
                content.innerHTML = '<p style="color:#ef4444;">Gagal memuat data.</p>';
            });
    }

    function closeIdentityViewModal() {
        const modal = document.getElementById('identityViewModal');
        if (modal) modal.style.display = 'none';
    }

    /* klik di luar modal */
    document.addEventListener('click', function(e) {
        const modal = document.getElementById('identityViewModal');
        if (e.target === modal) {
            closeIdentityViewModal();
        }
    });

    /* ESC */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeIdentityViewModal();
        }
    });
</script>
<script>
    /* ================================
   IMAGE LIGHTBOX (ZOOM KTP)
   ================================ */

    function openLightbox(src, caption = '') {
        const lightbox = document.getElementById('imageLightbox');
        const img = lightbox.querySelector('.lightbox-image');
        const cap = lightbox.querySelector('.lightbox-caption');

        img.src = src;
        cap.textContent = caption;

        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        const lightbox = document.getElementById('imageLightbox');
        lightbox.classList.remove('active');
        document.body.style.overflow = '';
    }

    /* klik gambar KTP */
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('identity-photo')) {
            e.preventDefault();
            openLightbox(
                e.target.src,
                e.target.alt || 'Foto Identitas'
            );
        }

        /* klik background */
        if (e.target.id === 'imageLightbox') {
            closeLightbox();
        }
    });

    /* ESC */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLightbox();
        }
    });
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