<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php';

$shouldClearForm = !empty($_SESSION['clear_form'] ?? false);
unset($_SESSION['clear_form']);

$user = $_SESSION['user_rh'] ?? [];
$medicName    = $user['name'] ?? '';
$medicJabatan = $user['position'] ?? '';

if (empty($medicName)) {
    $_SESSION['flash_errors'][] = 'Session login tidak valid. Silakan login ulang.';
    header('Location: /auth/logout.php');
    exit;
}

/* ===============================
   HANDLE POST
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $isGunshot = isset($_POST['is_gunshot']) && $_POST['is_gunshot'] == '1';

    $dpjpName = trim($_POST['dpjp_name'] ?? '');
    $teamNames = $_POST['team_names'] ?? []; // array

    $meds = $_POST['meds'] ?? []; // array area luka
    $operasiTingkat = $_POST['operasi_tingkat'] ?? null;
    $serviceType   = $_POST['service_type'] ?? '';
    $serviceDetail = $_POST['service_detail'] ?? '';
    $patientName   = trim($_POST['patient_name'] ?? '');
    $location      = $_POST['location'] ?? null;
    $qty           = (int)($_POST['qty'] ?? 1);
    $paymentType   = $_POST['payment_type'] ?? '';
    $price = 0;
    $total = 0;

    $errors = [];

    // ===============================
    // MEDICINE USAGE (FINAL BUSINESS RULE)
    // ===============================
    $medicineUsage = '';

    $medicineMap = [
        'Head'       => 'Gauze',
        'Body'       => 'Gauze',
        'Left Arm'   => 'Iodine',
        'Right Arm'  => 'Iodine',
        'Left Leg'   => 'Syringe',
        'Right Leg'  => 'Syringe',
        'Left Foot'  => 'Syringe',
        'Right Foot' => 'Syringe',
    ];

    if ($serviceType === 'Operasi' && $dpjpName === '') {
        $errors[] = 'DPJP wajib dipilih';
    }

    if ($serviceType !== 'Plastik' && $serviceDetail === '') {
        $errors[] = 'Detail layanan wajib dipilih';
    }

    switch ($serviceType) {

        case 'Pingsan':
            $priceMap = [
                'RS' => 'PP_RS',
                'Paleto' => 'PP_PALETO',
                'Gunung/Laut' => 'PP_GUNUNG',
                'Zona Perang' => 'PP_PERANG',
                'UFC' => 'PP_UFC',
            ];

            // BASE PRICE
            $price = safeRegulation($pdo, $priceMap[$serviceDetail]);

            // OBAT (SAMA SEPERTI TREATMENT)
            $medPrice = $isGunshot
                ? safeRegulation($pdo, 'BLEEDING_PELURU')
                : safeRegulation($pdo, 'BLEEDING_OBAT');

            $medicineCount = is_array($meds) ? count($meds) : 0;

            $total = $price + ($medicineCount * $medPrice);
            break;

        case 'Treatment':
            $code = $serviceDetail === 'RS' ? 'TR_RS' : 'TR_LUAR';
            $price = safeRegulation($pdo, $code);

            // OBAT
            $medPrice = $isGunshot
                ? safeRegulation($pdo, 'BLEEDING_PELURU')
                : safeRegulation($pdo, 'BLEEDING_OBAT');

            $total = $price + (count($meds) * $medPrice);
            break;

        case 'Surat':
            $code = $serviceDetail === 'Kesehatan' ? 'SK_KES' : 'SK_PSI';
            $price = safeRegulation($pdo, $code);
            $total = $price;
            break;

        case 'Operasi':
            if (!$operasiTingkat) {
                $errors[] = 'Tingkat operasi wajib dipilih';
                break;
            }

            // Ambil regulasi dasar
            $code = $serviceDetail === 'Besar' ? 'OP_BESAR' : 'OP_KECIL';

            $stmt = $pdo->prepare("
                SELECT price_min, price_max
                FROM medical_regulations
                WHERE code = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$code]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reg) {
                $errors[] = 'Regulasi operasi tidak ditemukan';
                break;
            }

            $min = (int)$reg['price_min'];
            $max = (int)$reg['price_max'];

            // Bagi range jadi 3 bagian
            $step = floor(($max - $min) / 3);

            switch ($operasiTingkat) {
                case 'Ringan':
                    $price = random_int($min, $min + $step);
                    break;

                case 'Sedang':
                    $price = random_int(
                        $min + $step + 1,
                        $min + ($step * 2)
                    );
                    break;

                case 'Berat':
                    $price = random_int(
                        $min + ($step * 2) + 1,
                        $max
                    );
                    break;

                default:
                    $errors[] = 'Tingkat operasi tidak valid';
                    break;
            }

            $total = $price;
            break;

        case 'Rawat Inap':
            $code = $serviceDetail === 'Reguler' ? 'RI_REG' : 'RI_VIP';
            $perHari = safeRegulation($pdo, $code);
            $price = $perHari;
            $total = $perHari * $qty;
            break;

        case 'Kematian':
            $code = $serviceDetail === 'Pemakaman' ? 'PEMAKAMAN' : 'KREMASI';
            $price = safeRegulation($pdo, $code);
            $total = $price;
            break;

        case 'Plastik':
            // SUDAH ADA HANDLER KHUSUS DI ATAS
            break;

        default:
            $errors[] = 'Jenis layanan tidak valid';
    }

    // 🔴 DEFAULT WAJIB
    if ($serviceType === 'Treatment') {
        $medicineUsage = 'Bandage 1 pcs';
        if ($isGunshot) {
            $medicineUsage .= ' (Luka Tembak)';
        }
    }

    if ($serviceType === 'Pingsan') {
        $medicineUsage = 'P3K';

        if (!empty($meds)) {
            $medicineUsage .= ' + Obat';
        }

        if ($isGunshot) {
            $medicineUsage .= ' (Luka Tembak)';
        }
    }

    // 🔴 TAMBAHAN DARI CHECKBOX
    if (is_array($meds) && count($meds) > 0) {
        $list = [];

        foreach ($meds as $area) {
            if (isset($medicineMap[$area])) {
                $list[] = $area . ' (' . $medicineMap[$area] . ')';
            }
        }

        if (!empty($list)) {
            $medicineUsage .= ', ' . implode(', ', $list);

            if ($isGunshot) {
                $medicineUsage .= ' [Peluru]';
            }
        }
    }

    if ($serviceType === 'Operasi' && ($operasiTingkat === null || $operasiTingkat === '')) {
        $errors[] = 'Tingkat operasi wajib dipilih';
    }

    // ===============================
    // STOP JIKA ADA ERROR (SEBELUM TRANSAKSI)
    // ===============================
    if (!empty($errors)) {
        $_SESSION['flash_errors'] = $errors;
        header('Location: ems_services.php');
        exit;
    }

    if ($serviceType === 'Operasi') {

        $pdo->beginTransaction();

        // =====================
        // PEMBAGIAN DASAR
        // =====================
        $billing = intdiv($total, 2);
        $cash    = $total - $billing;

        // 50% cash untuk dokter
        $doctorShare = intdiv($cash, 2);

        // 50% cash untuk tim
        $teamPool = $cash - $doctorShare;

        // Bersihkan nama tim kosong
        $teamNames = array_values(array_filter($teamNames));
        $teamCount = count($teamNames);

        // Per tim (TIDAK ADA sisa ke dokter)
        $perTeam = $teamCount > 0
            ? intdiv($teamPool, $teamCount)
            : 0;

        // =====================
        // INSERT DATA
        // =====================
        $stmt = $pdo->prepare("
    INSERT INTO ems_sales
    (service_type, service_detail, operasi_tingkat, patient_name, location,
     qty, payment_type, price, total, medic_name, medic_jabatan, medicine_usage)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
");

        // BILLING → DPJP
        $stmt->execute([
            'Operasi',
            $serviceDetail,
            $operasiTingkat,
            $patientName ?: null,
            $location,
            1,
            'billing',
            $billing,
            $billing,
            $dpjpName,
            'DPJP',
            'Billing Operasi'
        ]);

        // CASH → DOKTER DPJP (PASTI 50%)
        $stmt->execute([
            'Operasi',
            $serviceDetail,
            $operasiTingkat,
            $patientName ?: null,
            $location,
            1,
            'cash',
            $doctorShare,
            $doctorShare,
            $dpjpName,
            'DPJP',
            'Jasa Dokter Operasi'
        ]);

        // CASH → TIM
        foreach ($teamNames as $name) {
            $stmt->execute([
                'Operasi',
                $serviceDetail,
                $operasiTingkat,
                $patientName ?: null,
                $location,
                1,
                'cash',
                $perTeam,
                $perTeam,
                $name,
                'Tim Operasi',
                'Jasa Tim Operasi'
            ]);
        }

        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Operasi berhasil disimpan (Billing + Cash + Tim)';
        $_SESSION['clear_form'] = true;
        header('Location: ems_services.php');
        exit;
    }

    // ===============================
    // KHUSUS OPERASI PLASTIK
    // ===============================
    if ($serviceType === 'Plastik') {

        $priceCash    = 10140;
        $priceBilling = 10140;
        $totalPlastik = $priceCash + $priceBilling;

        $pdo->beginTransaction();

        // CASH
        $stmt = $pdo->prepare("
        INSERT INTO ems_sales
        (service_type, service_detail, operasi_tingkat, patient_name, location, qty,
         payment_type, price, total, medic_name, medic_jabatan)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
        $stmt->execute([
            'Plastik',
            'Operasi Plastik',
            null,
            $patientName ?: null,
            '4017',
            1,
            'cash',
            $priceCash,
            $priceCash,
            $medicName,
            $medicJabatan
        ]);

        // BILLING
        $stmt->execute([
            'Plastik',
            'Operasi Plastik',
            null,
            $patientName ?: null,
            '4017',
            1,
            'billing',
            $priceBilling,
            $priceBilling,
            $medicName,
            $medicJabatan
        ]);

        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Operasi plastik berhasil disimpan (Cash + Billing)';
        $_SESSION['clear_form'] = true;
        header('Location: ems_services.php');
        exit;
    }

    /* ===============================
   VALIDASI WAJIB
   =============================== */
    if ($serviceType === '') {
        $errors[] = 'Jenis layanan wajib diisi';
    }

    if ($serviceDetail === '') {
        $errors[] = 'Detail layanan wajib diisi';
    }

    /* ===============================
   PAYMENT FALLBACK
   =============================== */
    if ($paymentType === '' || $paymentType === null) {
        if (in_array($serviceType, ['Pingsan', 'Treatment', 'Surat'])) {
            $paymentType = 'cash';
        } elseif (in_array($serviceType, ['Operasi', 'Rawat Inap'])) {
            $paymentType = 'billing';
        }
    }

    /* ===============================
   LOCATION FALLBACK
   =============================== */
    if ($location === '' || $location === null) {
        if (in_array($serviceType, ['Pingsan', 'Treatment', 'Surat', 'Operasi', 'Rawat Inap', 'Plastik'])) {
            $location = '4017'; // RS default
        }
    }

    /* ===============================
   QTY FALLBACK
   =============================== */
    if ($qty <= 0) {
        $qty = 1;
    }

    /* ===============================
   TOTAL WAJIB > 0
   =============================== */
    if ($total <= 0) {
        $errors[] = 'Total biaya belum dihitung';
    }

    if (!empty($errors)) {
        $_SESSION['flash_errors'] = $errors;
        header('Location: ems_services.php');
        exit;
    }

    $stmt = $pdo->prepare("
    INSERT INTO ems_sales
    (
        service_type,
        service_detail,
        operasi_tingkat,
        patient_name,
        location,
        qty,
        payment_type,
        price,
        total,
        medic_name,
        medic_jabatan,
        medicine_usage
    )
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
");

    $stmt->execute([
        $serviceType,
        $serviceDetail,
        $operasiTingkat,
        $patientName !== '' ? $patientName : null,
        $location,
        $qty,
        $paymentType,
        $price,
        $total,
        $medicName,
        $medicJabatan,
        $medicineUsage
    ]);

    $_SESSION['flash_messages'][] = 'Data berhasil disimpan';
    $_SESSION['clear_form'] = true;
    header('Location: ems_services.php');
    exit;
}

// ===============================
// LOAD HARGA OBAT DARI REGULASI
// ===============================
try {
    $priceBleedingNormal = safeRegulation($pdo, 'BLEEDING_OBAT');
    $priceBleedingPeluru = safeRegulation($pdo, 'BLEEDING_PELURU');
} catch (Exception $e) {
    // fallback aman (tidak bikin fatal error UI)
    $priceBleedingNormal = 0;
    $priceBleedingPeluru = 0;
}

/* ===============================
   DATA REKAP
   =============================== */
$sql = "
    SELECT *
    FROM ems_sales
    WHERE medic_name = :medic
      AND created_at BETWEEN :start AND :end
    ORDER BY created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':medic' => $medicName,
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ======================================================
   REKAPAN MEDIS (PEMAKAIAN + KEUANGAN)
   ====================================================== */

$rekapMedis = [
    'bandage'  => 0,
    'p3k'      => 0,
    'gauze'    => 0,
    'iodine'   => 0,
    'syringe'  => 0,
    'billing'  => 0,
    'cash'     => 0,
    'total'    => 0,
];

$stmtRekap = $pdo->prepare("
    SELECT medicine_usage, payment_type, total
    FROM ems_sales
    WHERE medic_name = :medic
      AND created_at BETWEEN :start AND :end
");
$stmtRekap->execute([
    ':medic' => $medicName,
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
]);

while ($r = $stmtRekap->fetch(PDO::FETCH_ASSOC)) {

    $usage = strtolower($r['medicine_usage'] ?? '');

    // ===== HITUNG OBAT =====
    if (str_contains($usage, 'bandage')) $rekapMedis['bandage']++;
    if (str_contains($usage, 'p3k'))     $rekapMedis['p3k']++;
    if (str_contains($usage, 'gauze'))   $rekapMedis['gauze']++;
    if (str_contains($usage, 'iodine'))  $rekapMedis['iodine']++;
    if (str_contains($usage, 'syringe')) $rekapMedis['syringe']++;

    // ===== KEUANGAN =====
    if ($r['payment_type'] === 'billing') {
        $rekapMedis['billing'] += (int)$r['total'];
    }

    if ($r['payment_type'] === 'cash') {
        $rekapMedis['cash'] += (int)$r['total'];
    }

    $rekapMedis['total'] += (int)$r['total'];
}


$messages = $_SESSION['flash_messages'] ?? [];
$errors   = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page" style="max-width:1200px;margin:auto">

        <h1>Input Layanan Medis EMS</h1>
        <p class="text-muted">Sesuai Regulasi Roxwood Hospital</p>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <!-- ================= FORM ================= -->
        <div class="card">
            <div class="card-header">Input Layanan</div>

            <form method="post" id="emsForm" class="form">

                <label>Jenis Layanan</label>
                <select name="service_type" id="serviceType" required>
                    <option value="">-- Pilih --</option>
                    <option value="Pingsan">Pertolongan Pingsan</option>
                    <option value="Treatment">Treatment</option>
                    <option value="Surat">Pembuatan Surat</option>
                    <option value="Operasi">Operasi</option>
                    <option value="Rawat Inap">Rawat Inap</option>
                    <option value="Kematian">Kematian</option>
                    <option value="Plastik">Operasi Plastik</option>
                </select>

                <div id="detailSection">
                    <label>Detail Layanan</label>
                    <select name="service_detail" id="serviceDetail" disabled>
                        <option value="">-- Pilih Jenis Layanan Terlebih Dahulu --</option>
                    </select>
                    <small id="detailHint" class="text-muted">
                        Silahkan pilih jenis layanan terlebih dahulu
                    </small>
                </div>

                <div id="operasiTingkatSection" style="display:none">
                    <label>Tingkat Operasi</label>
                    <select name="operasi_tingkat" id="operasiTingkat">
                        <option value="">-- Pilih Tingkat --</option>
                        <option value="Ringan">Ringan</option>
                        <option value="Sedang">Sedang</option>
                        <option value="Berat">Berat</option>
                    </select>
                </div>

                <!-- ================= OBAT (KHUSUS PINGSAN & TREATMENT) ================= -->
                <div id="medicineSection" style="display:none">

                    <!-- ================= KONDISI LUKA ================= -->
                    <label style="margin-top:6px">
                        <input type="checkbox" id="isGunshot" name="is_gunshot" value="1">
                        Luka tembak / peluru
                    </label>

                    <small class="text-muted">
                        Jika dicentang, biaya obat menjadi
                        <strong>$<?= number_format($priceBleedingPeluru) ?> / item</strong>
                    </small>

                    <hr>

                    <label>Area Luka / Obat Digunakan</label>

                    <div class="row-form-2">
                        <label><input type="checkbox" name="meds[]" class="med-check" data-med="Gauze" value="Head"> Head (Gauze)</label>
                        <label><input type="checkbox" name="meds[]" class="med-check" data-med="Gauze" value="Body"> Body (Gauze)</label>

                        <label><input type="checkbox" name="meds[]" class="med-check" data-med="Iodine" value="Left Arm"> Left Arm (Iodine)</label>
                        <label><input type="checkbox" name="meds[]" class="med-check" data-med="Iodine" value="Right Arm"> Right Arm (Iodine)</label>

                        <label><input type="checkbox" name="meds[]" class="med-check" data-med="Syringe" value="Left Leg"> Left Leg (Syringe)</label>
                        <label><input type="checkbox" name="meds[]" class="med-check" data-med="Syringe" value="Right Leg"> Right Leg (Syringe)</label>

                        <label><input type="checkbox" name="meds[]" class="med-check" data-med="Syringe" value="Left Foot"> Left Foot (Syringe)</label>
                        <label><input type="checkbox" name="meds[]" class="med-check" data-med="Syringe" value="Right Foot"> Right Foot (Syringe)</label>
                    </div>

                    <small class="text-muted">
                        Setiap area menggunakan 1 obat
                        (<strong>$<?= number_format($priceBleedingNormal) ?> / item</strong>)
                    </small>
                </div>

                <div id="patientSection">
                    <label>Nama Pasien</label>
                    <input type="text" name="patient_name">
                </div>

                <div id="dpjpSection" style="display:none">

                    <label>DPJP / Dokter Penanggung Jawab</label>
                    <select name="dpjp_name" id="dpjpName">
                        <option value="">-- Pilih Nama --</option>
                        <?php
                        $doctors = $pdo->query("
                            SELECT full_name 
                            FROM user_rh 
                            ORDER BY full_name
                        ")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($doctors as $d) {
                            echo '<option value="' . htmlspecialchars($d['full_name']) . '">'
                                . htmlspecialchars($d['full_name'])
                                . '</option>';
                        }
                        ?>
                    </select>

                    <div id="teamInputs"></div>

                </div>

                <div id="teamInputs"></div>

                <!-- ================= PEMBAGIAN OPERASI ================= -->
                <div id="splitOperasi" style="display:none" class="card" style="margin-top:12px">
                    <div class="card-header">Pembagian Operasi</div>

                    <div class="row-form-2">
                        <div>
                            <strong>Billing</strong>
                            <div id="billingAmount">$0</div>
                        </div>
                        <div>
                            <strong>Cash</strong>
                            <div id="cashAmount">$0</div>
                        </div>
                    </div>

                    <hr>

                    <div class="row-form-2">
                        <div>
                            <strong>Dokter (50% Cash)</strong>
                            <div id="doctorShare">$0</div>
                        </div>
                        <div>
                            <strong>Tim (50% Cash)</strong>
                            <div id="teamPool">$0</div>
                        </div>
                    </div>

                    <hr>

                    <label>Jumlah Tim (tanpa dokter)</label>
                    <input type="number" id="teamCount" min="1" value="1">

                    <small class="text-muted">
                        Co-ass / Paramedic / lainnya
                    </small>

                    <div style="margin-top:8px">
                        <strong>Per Tim</strong>
                        <div id="teamPerPerson">$0</div>
                    </div>
                </div>

                <div id="locationSection">
                    <label>Kordinat Lokasi</label>
                    <input
                        type="text"
                        name="location"
                        id="location"
                        inputmode="numeric"
                        maxlength="4"
                        pattern="[0-9]{1,4}">
                    <small class="text-muted">
                        Maksimal 4 digit angka
                    </small>
                </div>

                <div id="qtySection">
                    <label>Jumlah / Hari</label>
                    <input type="number" name="qty" id="qty" value="1" min="1">
                </div>

                <div id="paymentSection">
                    <label>Jenis Pembayaran</label>
                    <select name="payment_type" id="paymentType" required>
                        <option value="cash">Cash</option>
                        <option value="billing">Billing</option>
                    </select>
                </div>

                <input type="hidden" name="price" id="price">
                <input type="hidden" name="total" id="total">

                <div class="total-display">
                    <div class="total-display-label">Total Biaya</div>
                    <div class="total-amount" id="totalDisplay">$0</div>
                </div>

                <button type="submit" class="btn-success">Simpan</button>
                <button type="button" class="btn-secondary" onclick="clearEmsForm()">
                    Clear
                </button>

            </form>
        </div>

        <!-- ================= REKAP ================= -->
        <div class="card">
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

                            <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom</option>
                        </select>
                    </div>
                </div>

                <div class="row-form-2 <?= $range !== 'custom' ? 'hidden' : '' ?>" id="customDateRow">
                    <div class="col">
                        <label>Dari tanggal</label>
                        <input type="date" name="from" value="<?= htmlspecialchars($fromDateInput ?? '') ?>">
                    </div>
                    <div class="col">
                        <label>Sampai tanggal</label>
                        <input type="date" name="to" value="<?= htmlspecialchars($toDateInput ?? '') ?>">
                    </div>
                </div>

                <button type="submit" class="btn-secondary" style="margin-top:8px">
                    Terapkan Filter
                </button>
            </form>

            <p class="text-muted" style="font-size:13px;">
                Rentang aktif: <strong><?= htmlspecialchars($rangeLabel) ?></strong>
            </p>
        </div>

        <div class="card">
            <h3 style="font-size:15px;margin:14px 0 6px;">
                Rekapan Medis (Pemakaian & Keuangan)
            </h3>

            <p style="font-size:13px;color:#9ca3af;margin-top:0;margin-bottom:16px;">
                Berdasarkan <strong>petugas medis aktif</strong> dan <strong>rentang tanggal</strong>
            </p>

            <!-- TABEL 1: Item Medis -->
            <h4 style="font-size:13px;font-weight:700;color:#0f172a;margin:12px 0 8px;">
                📋 Item Medis Digunakan
            </h4>

            <div class="table-wrapper table-wrapper-sm">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Bandage</td>
                            <td><?= $rekapMedis['bandage'] ?></td>
                        </tr>
                        <tr>
                            <td>P3K</td>
                            <td><?= $rekapMedis['p3k'] ?></td>
                        </tr>
                        <tr>
                            <td>Gauze</td>
                            <td><?= $rekapMedis['gauze'] ?></td>
                        </tr>
                        <tr>
                            <td>Iodine</td>
                            <td><?= $rekapMedis['iodine'] ?></td>
                        </tr>
                        <tr>
                            <td>Syringe</td>
                            <td><?= $rekapMedis['syringe'] ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- TABEL 2: Keuangan -->
            <h4 style="font-size:13px;font-weight:700;color:#0f172a;margin:20px 0 8px;">
                💰 Ringkasan Keuangan
            </h4>

            <div class="table-wrapper table-wrapper-sm">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Jenis</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Billing</td>
                            <td>$<?= number_format($rekapMedis['billing']) ?></td>
                        </tr>
                        <tr>
                            <td>Cash</td>
                            <td>$<?= number_format($rekapMedis['cash']) ?></td>
                        </tr>
                        <tr style="background:rgba(14,165,233,0.08);font-weight:700;">
                            <td style="color:#0284c7;">Total</td>
                            <td style="color:#0284c7;">$<?= number_format($rekapMedis['total']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header card-header-actions">
                <div class="card-header-actions-title">
                    Rekap Transaksi EMS
                </div>
            </div>

            <form id="bulkDeleteForm"
                method="POST"
                action="rekap_delete_bulk.php">

                <div class="table-wrapper">
                    <table id="rekapTable" class="table-custom">
                        <thead>
                            <tr>
                                <th style="width:32px;text-align:center;">
                                    <input type="checkbox" id="checkAll">
                                </th>
                                <th>Waktu</th>
                                <th>Layanan</th>
                                <th>Detail</th>
                                <th>Pasien</th>
                                <th>Pembayaran</th>
                                <th>Total</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td style="text-align:center;">
                                        <input
                                            type="checkbox"
                                            class="row-check"
                                            name="ids[]"
                                            value="<?= (int)$r['id'] ?>">
                                    </td>
                                    <td><?= formatTanggalID($r['created_at']) ?></td>
                                    <td><?= htmlspecialchars($r['service_type']) ?></td>
                                    <td><?= htmlspecialchars($r['service_detail']) ?></td>
                                    <td><?= htmlspecialchars($r['patient_name'] ?? '-') ?></td>
                                    <td><?= strtoupper(htmlspecialchars($r['payment_type'])) ?></td>
                                    <td data-order="<?= $r['total'] ?>">
                                        $<?= number_format($r['total']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                        <tfoot>
                            <tr>
                                <th colspan="6" style="text-align:right;">
                                    TOTAL (data yang tampil):
                                </th>
                                <th id="rekapTotalFooter">$0</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </form>
            <br>
            <!-- 🔴 TOMBOL DI HEADER (SAMA DENGAN REKAP FARMASI) -->
            <button
                type="submit"
                form="bulkDeleteForm"
                id="btnDeleteSelected"
                class="btn-danger"
                disabled>
                🗑 Hapus Terpilih
            </button>
        </div>

    </div>
</section>

<script>
    /* =====================================================
   SCRIPT 1: CORE FUNCTIONALITY
   ===================================================== */
    let calculate;
    let previewPrice; // ✅ DECLARE DI GLOBAL
    let isRestoring = false; // ✅ FLAG UNTUK PREVENT CONFLICT

    document.addEventListener('DOMContentLoaded', () => {

        /* ========= HELPER ========= */
        const hide = el => el.style.display = 'none';
        const show = el => el.style.display = 'block';

        const detailSection = document.getElementById('detailSection');

        /* ========= ELEMENT ========= */
        const operasiTingkatSection = document.getElementById('operasiTingkatSection');
        const operasiTingkat = document.getElementById('operasiTingkat');

        const serviceType = document.getElementById('serviceType');
        const serviceDetail = document.getElementById('serviceDetail');
        const qtyEl = document.getElementById('qty');
        const paymentType = document.getElementById('paymentType');
        const locationInput = document.getElementById('location');

        const patientSection = document.getElementById('patientSection');
        const locationSection = document.getElementById('locationSection');
        const qtySection = document.getElementById('qtySection');
        const paymentSection = document.getElementById('paymentSection');
        const medicineSection = document.getElementById('medicineSection');
        const detailHint = document.getElementById('detailHint');

        const priceEl = document.getElementById('price');
        const totalEl = document.getElementById('total');
        const totalUI = document.getElementById('totalDisplay');

        const medicineChecks = document.querySelectorAll('.med-check');

        const dpjpSection = document.getElementById('dpjpSection');

        /* ========= VALIDASI KOORDINAT ========= */
        locationInput.addEventListener('input', () => {
            locationInput.value = locationInput.value.replace(/\D/g, '').slice(0, 4);
        });

        // ================= SPLIT OPERASI =================
        const splitBox = document.getElementById('splitOperasi');
        const billingUI = document.getElementById('billingAmount');
        const cashUI = document.getElementById('cashAmount');
        const doctorUI = document.getElementById('doctorShare');
        const teamPoolUI = document.getElementById('teamPool');
        const teamCountEl = document.getElementById('teamCount');
        const teamPerUI = document.getElementById('teamPerPerson');

        function updateSplitOperasi(total) {
            if (!total || total <= 0) {
                hide(splitBox);
                return;
            }

            const billing = Math.floor(total / 2);
            const cash = total - billing;

            const doctor = Math.floor(cash * 0.5);
            const teamPool = cash - doctor;

            const teamCount = Math.max(parseInt(teamCountEl.value) || 1, 1);
            const perTeam = Math.floor(teamPool / teamCount);

            billingUI.textContent = `$${billing.toLocaleString()}`;
            cashUI.textContent = `$${cash.toLocaleString()}`;
            doctorUI.textContent = `$${doctor.toLocaleString()}`;
            teamPoolUI.textContent = `$${teamPool.toLocaleString()}`;
            teamPerUI.textContent = `$${perTeam.toLocaleString()}`;

            show(splitBox);
        }

        teamCountEl.addEventListener('input', () => {
            const wrap = document.getElementById('teamInputs');
            wrap.innerHTML = '';

            const count = Math.max(parseInt(teamCountEl.value) || 1, 1);
            const dpjpSelect = document.getElementById('dpjpName');

            for (let i = 1; i <= count; i++) {
                const select = document.createElement('select');
                select.name = 'team_names[]';
                select.innerHTML = dpjpSelect.innerHTML;

                const label = document.createElement('label');
                label.textContent = `Nama Tim ${i}`;

                wrap.appendChild(label);
                wrap.appendChild(select);
            }

            const total = parseInt(totalEl.value) || 0;
            updateSplitOperasi(total);
        });

        function resetUI() {
            serviceDetail.disabled = true;
            serviceDetail.innerHTML = `<option value="">-- Pilih Jenis Layanan Terlebih Dahulu --</option>`;
            detailHint.style.display = 'block';

            show(detailSection);
            hide(splitBox);
            hide(patientSection);
            hide(locationSection);
            hide(qtySection);
            hide(medicineSection);
            hide(paymentSection);
            hide(operasiTingkatSection);
            hide(dpjpSection);

            paymentType.disabled = false;

            priceEl.value = 0;
            totalEl.value = 0;
            totalUI.textContent = '$0';
        }

        resetUI();

        const DETAIL_OPTIONS = {
            Pingsan: ['RS', 'Paleto', 'Gunung/Laut', 'Zona Perang', 'UFC'],
            Treatment: ['RS', 'Luar'],
            Surat: ['Kesehatan', 'Psikologi'],
            Operasi: ['Besar', 'Kecil'],
            'Rawat Inap': ['Reguler', 'VIP'],
            Kematian: ['Pemakaman', 'Kremasi'],
            Plastik: ['Operasi Plastik']
        };

        /* ========= JENIS LAYANAN ========= */
        serviceType.addEventListener('change', () => {

            resetUI();
            hide(splitBox);
            show(detailSection);

            const currentLocation = locationInput.value;

            // 🔥 JANGAN UNCHECK SAAT RESTORING
            if (!isRestoring) {
                medicineChecks.forEach(cb => cb.checked = false);
            }

            if (!serviceType.value) return;

            serviceDetail.disabled = false;
            detailHint.style.display = 'none';
            show(paymentSection);

            const type = serviceType.value;
            (serviceType.value in DETAIL_OPTIONS ? DETAIL_OPTIONS[type] : []).forEach(v => {
                serviceDetail.add(new Option(v, v));
            });

            if (type === 'Operasi') {
                show(patientSection);
                show(dpjpSection);
                hide(locationSection);
                hide(qtySection);
                show(operasiTingkatSection);

                locationInput.value = currentLocation || '4017';
                paymentType.value = 'billing';
                paymentType.disabled = true;
                return;
            }

            if (type === 'Pingsan') {
                hide(qtySection);
                locationInput.value = currentLocation;
                paymentType.value = 'cash';
                paymentType.disabled = true;
                return;
            }

            if (type === 'Treatment') {
                hide(qtySection);
                locationInput.value = currentLocation;
                paymentType.value = 'cash';
                paymentType.disabled = true;
                return;
            }

            if (type === 'Surat') {
                show(patientSection);
                hide(locationSection);
                hide(qtySection);
                locationInput.value = currentLocation || '4017';
                paymentType.value = 'cash';
                paymentType.disabled = true;
                return;
            }

            if (type === 'Rawat Inap') {
                show(patientSection);
                hide(locationSection);
                show(qtySection);
                locationInput.value = currentLocation || '4017';
                paymentType.value = 'billing';
                paymentType.disabled = true;
                return;
            }

            if (type === 'Kematian') {
                show(patientSection);
                show(locationSection);
                hide(qtySection);
                locationInput.value = currentLocation;
                paymentType.disabled = false;
                return;
            }

            if (type === 'Plastik') {
                show(patientSection);
                hide(detailSection);
                hide(operasiTingkatSection);
                hide(locationSection);
                hide(qtySection);
                hide(paymentSection);

                serviceDetail.disabled = false;
                serviceDetail.value = 'Operasi Plastik';

                locationInput.value = '4017';

                const cash = 10140;
                const billing = 10140;
                const total = cash + billing;

                priceEl.value = total;
                totalEl.value = total;

                totalUI.textContent =
                    `$${cash.toLocaleString()} (Cash) + ` +
                    `$${billing.toLocaleString()} (Billing) = ` +
                    `$${total.toLocaleString()}`;

                return;
            }

        });

        serviceDetail.addEventListener('change', () => {

            const type = serviceType.value;
            const detail = serviceDetail.value;

            if (!detail) {
                hide(medicineSection);
                return;
            }

            // =============================
            // TAMPILKAN OBAT SETELAH DETAIL
            // =============================
            if (type === 'Pingsan' || type === 'Treatment') {
                show(medicineSection);
            } else {
                hide(medicineSection);
            }

            // =============================
            // LOKASI (LOGIC LAMA TETAP)
            // =============================
            const currentLocation = locationInput.value;

            if (type === 'Pingsan' || type === 'Treatment') {
                if (detail === 'RS') {
                    hide(locationSection);
                    locationInput.value = '4017';
                } else {
                    show(locationSection);
                    locationInput.value = currentLocation;
                }
            }

            if (typeof previewPrice === 'function') {
                previewPrice();
            }
        });


        /* ========= PREVIEW PRICE ========= */
        previewPrice = function() {
            const form = document.getElementById('emsForm');
            const formData = new FormData(form);

            fetch('/ajax/ems_preview_price.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(res => {
                    if (res.success) {

                        priceEl.value = res.breakdown.base_price;
                        totalEl.value = res.total;

                        let text = `$${res.total.toLocaleString()}`;

                        if (res.breakdown.medicine.count > 0) {
                            const m = res.breakdown.medicine;
                            text += `<br><small>
                Obat: ${m.count} × $${m.per_item} = $${m.subtotal}
            </small>`;

                            if (m.type === 'PELURU') {
                                text += ' <strong>[Luka Tembak]</strong>';
                            }
                        }

                        totalUI.innerHTML = text;

                        // Update split operasi jika operasi
                        if (serviceType.value === 'Operasi') {
                            updateSplitOperasi(res.total);
                        }
                    } else {
                        totalUI.textContent = res.message || 'Tidak dapat menghitung';
                    }
                })
                .catch(() => {
                    totalUI.textContent = 'Gagal ambil data';
                });
        };

        calculate = previewPrice;

        // ✅ EVENT LISTENERS (HANYA UNTUK NON-CHECKBOX)
        operasiTingkat.addEventListener('change', previewPrice);
        qtyEl.addEventListener('input', previewPrice);

        // ✅ AUTO-HIDE ALERT
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(el => {
                el.style.transition = 'opacity 0.5s';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 500);
            });
        }, 5000);

    });
</script>

<script>
    /* =====================================================
   SCRIPT 2: FORM PERSISTENCE (FIXED)
   ===================================================== */

    const EMS_STORAGE_KEY = 'ems_services_form_v8';
    const SHOULD_CLEAR_FORM = <?= $shouldClearForm ? 'true' : 'false'; ?>;

    document.addEventListener('DOMContentLoaded', () => {

        const form = document.getElementById('emsForm');
        if (!form) return;

        const serviceType = document.getElementById('serviceType');
        const serviceDetail = document.getElementById('serviceDetail');
        const operasiTingkat = document.getElementById('operasiTingkat');

        /* ===== SAVE FORM ===== */
        function saveForm() {
            // ✅ JANGAN SAVE SAAT RESTORING
            if (isRestoring) return;

            const data = {};

            form.querySelectorAll('input, select, textarea').forEach(el => {
                if (!el.name) return;

                if (el.type === 'checkbox') {
                    if (el.name === 'is_gunshot') {
                        data[el.name] = el.checked ? '1' : '0';
                    } else if (el.name === 'meds[]') {
                        if (!data['meds']) data['meds'] = [];
                        if (el.checked) {
                            data['meds'].push(el.value);
                        }
                    }
                } else {
                    data[el.name] = el.value;
                }
            });

            console.log('💾 Saving:', data);
            localStorage.setItem(EMS_STORAGE_KEY, JSON.stringify(data));
        }

        /* ===== RESTORE FORM ===== */
        function restoreForm() {
            const raw = localStorage.getItem(EMS_STORAGE_KEY);
            if (!raw) {
                console.log('📭 No saved data');
                return;
            }

            isRestoring = true; // ✅ SET FLAG
            const data = JSON.parse(raw);
            console.log('📂 Restoring:', data);

            // 1️⃣ Restore input biasa
            Object.entries(data).forEach(([name, value]) => {
                if (['service_type', 'service_detail', 'operasi_tingkat', 'meds'].includes(name)) {
                    return;
                }

                const fields = form.querySelectorAll(`[name="${name}"]`);
                if (!fields.length) return;

                if (fields[0].type === 'checkbox') {
                    if (name === 'is_gunshot') {
                        fields[0].checked = value === '1';
                        console.log(`✅ Restored is_gunshot: ${value === '1'}`);
                    }
                } else {
                    fields[0].value = value;
                }
            });

            // 2️⃣ Restore service_type
            if (data.service_type) {
                serviceType.value = data.service_type;
                serviceType.dispatchEvent(new Event('change'));

                setTimeout(() => {
                    // 3️⃣ Restore service_detail
                    if (data.service_detail) {
                        serviceDetail.value = data.service_detail;
                        serviceDetail.dispatchEvent(new Event('change'));
                    }

                    // 4️⃣ Restore operasi_tingkat
                    if (data.operasi_tingkat) {
                        operasiTingkat.value = data.operasi_tingkat;
                    }

                    // 5️⃣ Restore checkbox meds[]
                    if (data.meds && Array.isArray(data.meds)) {
                        document.querySelectorAll('.med-check').forEach(cb => {
                            cb.checked = data.meds.includes(cb.value);
                            if (cb.checked) {
                                console.log(`✅ Restored: ${cb.value}`);
                            }
                        });
                    }

                    // 6️⃣ Calculate price
                    setTimeout(() => {
                        if (typeof previewPrice === 'function') {
                            previewPrice();
                        }
                        isRestoring = false; // ✅ UNSET FLAG
                    }, 150);

                }, 350);
            } else {
                isRestoring = false;
            }
        }

        /* ===== ATTACH EVENT LISTENERS ===== */
        function attachEventListeners() {

            // ✅ Checkbox area luka
            document.querySelectorAll('.med-check').forEach(cb => {
                cb.addEventListener('change', function() {
                    console.log(`🔘 ${this.value} = ${this.checked}`);
                    saveForm();
                    if (typeof previewPrice === 'function') {
                        previewPrice();
                    }
                });
            });

            // ✅ Checkbox gunshot
            const isGunshotEl = document.getElementById('isGunshot');
            if (isGunshotEl) {
                isGunshotEl.addEventListener('change', function() {
                    console.log(`🔫 Gunshot = ${this.checked}`);
                    saveForm();
                    if (typeof previewPrice === 'function') {
                        previewPrice();
                    }
                });
            }

            // ✅ Auto-save untuk input/select
            form.addEventListener('input', saveForm);
            form.addEventListener('change', saveForm);
        }

        /* ===== CLEAR FORM ===== */
        window.clearEmsForm = function() {
            console.log('🗑️ Clearing...');
            localStorage.removeItem(EMS_STORAGE_KEY);
            form.reset();

            const totalDisplay = document.getElementById('totalDisplay');
            if (totalDisplay) totalDisplay.textContent = '$0';

            document.getElementById('price').value = 0;
            document.getElementById('total').value = 0;

            document.querySelectorAll('.med-check').forEach(cb => {
                cb.checked = false;
            });

            const isGunshotEl = document.getElementById('isGunshot');
            if (isGunshotEl) isGunshotEl.checked = false;

            if (serviceType) {
                serviceType.value = '';
                serviceType.dispatchEvent(new Event('change'));
            }
        };

        /* ===== INIT ===== */
        if (SHOULD_CLEAR_FORM) {
            localStorage.removeItem(EMS_STORAGE_KEY);
            console.log('🧹 Cleared by server');
        }

        // ✅ TIMING YANG BENAR
        setTimeout(() => {
            if (!SHOULD_CLEAR_FORM) {
                restoreForm();
            }

            // Pasang event SETELAH restore
            setTimeout(() => {
                attachEventListeners();
            }, 600);
        }, 100);

    });
</script>

<script>
    /* =====================================================
   SCRIPT 3: DATATABLE & FILTER
   ===================================================== */

    document.addEventListener('DOMContentLoaded', function() {
        if (!(window.jQuery && jQuery.fn.DataTable)) return;

        jQuery('#rekapTable').DataTable({
            order: [
                [1, 'desc']
            ],
            pageLength: 10,
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
            },
            footerCallback: function() {
                let api = this.api();
                let total = api
                    .column(6, {
                        page: 'current'
                    })
                    .nodes()
                    .reduce((sum, td) => {
                        return sum + parseInt(td.getAttribute('data-order') || 0, 10);
                    }, 0);

                document.getElementById('rekapTotalFooter').innerHTML =
                    '$' + total.toLocaleString();
            }
        });

        const dateFilter = document.getElementById('dateFilter');
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        const btnApply = document.getElementById('applyCustomDate');

        dateFilter.value = 'today';

        dateFilter.addEventListener('change', function() {
            const isCustom = this.value === 'custom';

            dateFrom.style.display = isCustom ? 'block' : 'none';
            dateTo.style.display = isCustom ? 'block' : 'none';
            btnApply.style.display = isCustom ? 'block' : 'none';

            if (!isCustom) {
                table.draw();
            }
        });

        btnApply.addEventListener('click', function() {
            table.draw();
        });

        table.draw();

    });
</script>

<script>
    document.getElementById('rangeSelect')?.addEventListener('change', function() {
        document
            .getElementById('customDateRow')
            ?.classList.toggle('hidden', this.value !== 'custom');
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const checkAll = document.getElementById('checkAll');
        const deleteBtn = document.getElementById('btnDeleteSelected');

        function updateButton() {
            const checked = document.querySelectorAll('.row-check:checked').length;
            deleteBtn.disabled = checked === 0;
        }

        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.row-check').forEach(cb => {
                cb.checked = checkAll.checked;
            });
            updateButton();
        });

        document.addEventListener('change', e => {
            if (!e.target.classList.contains('row-check')) return;

            const total = document.querySelectorAll('.row-check').length;
            const checked = document.querySelectorAll('.row-check:checked').length;

            checkAll.checked = total > 0 && checked === total;
            updateButton();
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>