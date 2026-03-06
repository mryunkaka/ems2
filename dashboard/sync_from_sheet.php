<?php
// =====================================================
// SYNC GOOGLE SHEETS (CSV) → DATABASE (FINAL + LOG)
// =====================================================

date_default_timezone_set('Asia/Jakarta');

// -----------------------------------------------------
// LOG FILE (KHUSUS CRON)
// -----------------------------------------------------
$logFile = __DIR__ . '/../storage/cron_sync.log';

function cronLog($msg)
{
    global $logFile;
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

cronLog('CRON SYNC START');

// -----------------------------------------------------
// Load koneksi database
// -----------------------------------------------------
require_once __DIR__ . '/../config/database.php';

// -----------------------------------------------------
// Spreadsheet config
// -----------------------------------------------------
$configFile = __DIR__ . '/sheet_config.json';

$sheetConfig = [
    'spreadsheet_id' => '',
    'sheet_gid'      => '',
];

if (file_exists($configFile)) {
    $data = json_decode(file_get_contents($configFile), true);
    if (is_array($data)) {
        $sheetConfig = array_merge($sheetConfig, $data);
    }
}

// Validasi config
if (!$sheetConfig['spreadsheet_id'] || !$sheetConfig['sheet_gid']) {
    cronLog('ERROR: Spreadsheet ID / GID belum diset');
    exit(1);
}

// -----------------------------------------------------
// Build CSV URL
// -----------------------------------------------------
$csvUrl = sprintf(
    'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s',
    $sheetConfig['spreadsheet_id'],
    $sheetConfig['sheet_gid']
);

cronLog("Fetch CSV: {$csvUrl}");

$csvContent = @file_get_contents($csvUrl);
if ($csvContent === false) {
    cronLog('ERROR: Gagal ambil CSV Google Sheets');
    exit(1);
}

$lines = preg_split("/\r\n|\n|\r/", trim($csvContent));

// -----------------------------------------------------
// Prepare statements
// -----------------------------------------------------
$stmtPkg = $pdo->prepare("
    SELECT id, name, price, bandage_qty, ifaks_qty, painkiller_qty
    FROM packages
    WHERE name = :name
    LIMIT 1
");

$stmtCheck = $pdo->prepare("
    SELECT 1 FROM sales
    WHERE tx_hash = :tx_hash
    LIMIT 1
");

$stmtInsert = $pdo->prepare("
    INSERT INTO sales
    (consumer_name, medic_name, medic_jabatan,
     package_id, package_name, price,
     qty_bandage, qty_ifaks, qty_painkiller,
     tx_hash, created_at)
    VALUES
    (:cname, :mname, :mjab,
     :pid, :pname, :price,
     :qb, :qi, :qp,
     :tx_hash, :created_at)
");

// -----------------------------------------------------
// LOOP CSV
// -----------------------------------------------------
$lineNum = 0;
$imported = 0;
$skipped = 0;
$duplicate = 0;

$maxRow = 900; // batas aman

foreach ($lines as $line) {
    $lineNum++;

    if ($lineNum < 3 || $lineNum > $maxRow) continue;
    if (trim($line) === '') {
        $skipped++;
        continue;
    }

    $cols = str_getcsv($line);
    if (count($cols) < 4) {
        $skipped++;
        continue;
    }

    [$buyerName, $medicName, $jabatan, $jenisPaket, $totalHarga] = array_map('trim', $cols + ['', '', '', '', '']);

    if (!$buyerName || !$medicName || !$jabatan || !$jenisPaket) {
        $skipped++;
        continue;
    }

    // Cari paket
    $stmtPkg->execute([':name' => $jenisPaket]);
    $pkg = $stmtPkg->fetch(PDO::FETCH_ASSOC);

    if (!$pkg) {
        $skipped++;
        continue;
    }

    $price = (int)$pkg['price'];
    if ($totalHarga !== '') {
        $norm = str_replace(['.', ','], '', $totalHarga);
        if (is_numeric($norm)) $price = (int)$norm;
    }

    $norm = function ($v) {
        return preg_replace('/\s+/', ' ', strtolower(trim($v)));
    };

    $txHash = hash(
        'sha256',
        $norm($buyerName) . '|' .
            $norm($medicName) . '|' .
            $norm($jabatan) . '|' .
            $norm($pkg['name']) . '|' .
            $price
    );

    // Cek duplikat
    $stmtCheck->execute([
        ':tx_hash' => $txHash
    ]);

    if ($stmtCheck->fetchColumn() > 0) {
        $duplicate++;
        continue;
    }

    // Insert
    $stmtInsert->execute([
        ':cname'      => $buyerName,
        ':mname'      => $medicName,
        ':mjab'       => $jabatan,
        ':pid'        => (int)$pkg['id'],
        ':pname'      => $pkg['name'],
        ':price'      => $price,
        ':qb'         => (int)$pkg['bandage_qty'],
        ':qi'         => (int)$pkg['ifaks_qty'],
        ':qp'         => (int)$pkg['painkiller_qty'],
        ':tx_hash'    => $txHash,
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    $imported++;
}

// -----------------------------------------------------
// RINGKASAN
// -----------------------------------------------------
cronLog("DONE | Rows: {$lineNum} | Imported: {$imported} | Skipped: {$skipped} | Duplicate: {$duplicate}");
cronLog("CRON SYNC END\n");

exit(0);
