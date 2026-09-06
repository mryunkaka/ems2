<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php'; // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

function importExcelTransactionDate($cell): ?string
{
    $value = $cell->getValue();

    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if (is_numeric($value) && (float)$value > 0) {
        try {
            return ExcelDate::excelToDateTimeObject((float)$value)->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    $value = trim((string)$value);
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

if (!isset($_SESSION['user_rh'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

emsRequireJsonCsrf();

// Validate POST data
if (!isset($_POST['medic_name']) || !isset($_FILES['excel_file'])) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$medicName = trim($_POST['medic_name']);
$medicPosition = trim($_POST['medic_position'] ?? '');
$file = $_FILES['excel_file'];
$salesHasUnitCode = ems_column_exists($pdo, 'sales', 'unit_code');
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);

// Validate file upload
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Upload file gagal']);
    exit;
}

// Validate file extension
$allowedExtensions = ['xlsx', 'xls'];
$fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($fileExtension, $allowedExtensions)) {
    echo json_encode(['success' => false, 'message' => 'Format file tidak didukung']);
    exit;
}

try {
    // Verify medic exists in database
    $stmt = $pdo->prepare("SELECT id, position FROM user_rh WHERE full_name = ? AND is_active = 1");
    $stmt->execute([$medicName]);
    $medic = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$medic) {
        echo json_encode(['success' => false, 'message' => 'Medis tidak ditemukan']);
        exit;
    }

    $medicUserId = $medic['id'];
    $medicJabatan = !empty($medicPosition) ? $medicPosition : $medic['position'];

    // Load Excel file
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $worksheet = $spreadsheet->getActiveSheet();
    $rows = $worksheet->toArray();

    // Expected columns from uploaded Excel:
    // A: Consumer identifier / legacy consumer name
    // B: Package Name (Nama Paket - akan lookup ke tabel packages)
    // C: Citizen ID (optional, will be prioritized for new data)
    // D: Transaction date (Y-m-d, d/m/Y, or d-m-Y)

    $imported = 0;
    $skipped = 0;
    $rowErrors = [];
    $seenRows = [];
    $duplicateSql = "SELECT 1
        FROM sales
        WHERE DATE(created_at) = ?
          AND UPPER(TRIM(medic_name)) = UPPER(?)
          AND UPPER(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(TRIM(consumer_name), ' ', ''),
                            '-',
                            ''
                        ),
                        '.',
                        ''
                    ),
                    '/',
                    ''
                )
          ) = ?";
    if ($salesHasUnitCode) {
        $duplicateSql .= " AND COALESCE(unit_code, 'roxwood') = ?";
    }
    $duplicateSql .= ' LIMIT 1';
    $duplicateStmt = $pdo->prepare($duplicateSql);
    $pdo->beginTransaction();

    // Skip header row (row 0)
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];

        $excelRowNumber = $i + 1;

        $consumerInput = trim($row[0] ?? '');
        $packageName = trim($row[1] ?? '');
        $citizenId = trim($row[2] ?? '');
        $transactionDate = importExcelTransactionDate($worksheet->getCell('D' . $excelRowNumber));

        // Skip empty rows
        if ($consumerInput === '' && $packageName === '' && $citizenId === '' && $transactionDate === null) {
            continue;
        }

        $missingFields = [];
        if ($consumerInput === '' && $citizenId === '') {
            $missingFields[] = 'identitas konsumen';
        }
        if ($packageName === '') {
            $missingFields[] = 'nama paket';
        }
        if ($transactionDate === null) {
            $missingFields[] = 'tanggal transaksi valid';
        }
        if ($missingFields !== []) {
            $skipped++;
            $rowErrors[] = "Baris {$excelRowNumber}: " . implode(', ', $missingFields) . ' wajib diisi.';
            continue;
        }

        $consumerName = ems_normalize_citizen_id($citizenId !== '' ? $citizenId : $consumerInput);
        if ($consumerName === '') {
            $consumerName = trim($consumerInput);
        }

        $duplicateKey = $transactionDate . '|' . strtoupper($medicName) . '|' . strtoupper(preg_replace('/[^A-Z0-9]/', '', $consumerName));
        if (isset($seenRows[$duplicateKey])) {
            $skipped++;
            $rowErrors[] = "Baris {$excelRowNumber}: duplikat dengan baris {$seenRows[$duplicateKey]} untuk Citizen ID, tanggal, dan nama medis yang sama.";
            continue;
        }

        $duplicateParams = [$transactionDate, $medicName, strtoupper(preg_replace('/[^A-Z0-9]/', '', $consumerName))];
        if ($salesHasUnitCode) {
            $duplicateParams[] = $effectiveUnit;
        }
        $duplicateStmt->execute($duplicateParams);
        if ($duplicateStmt->fetchColumn()) {
            $skipped++;
            $rowErrors[] = "Baris {$excelRowNumber}: transaksi sudah ada untuk Citizen ID {$consumerName}, tanggal {$transactionDate}, dan medis {$medicName}.";
            continue;
        }
        $seenRows[$duplicateKey] = $excelRowNumber;

        // Lookup package dari tabel packages
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                name, 
                bandage_qty, 
                ifaks_qty, 
                painkiller_qty, 
                price 
            FROM packages 
            WHERE name = ?
        ");
        $stmt->execute([$packageName]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);

        // Skip jika package tidak ditemukan
        if (!$package) {
            $skipped++;
            $rowErrors[] = "Baris {$excelRowNumber}: paket \"{$packageName}\" tidak ditemukan.";
            continue;
        }

        $packageId = $package['id'];
        $qtyBandage = intval($package['bandage_qty']);
        $qtyIfak = intval($package['ifaks_qty']);
        $qtyPainkiller = intval($package['painkiller_qty']);
        $price = intval($package['price']);

        // Skip if no items
        if ($qtyBandage + $qtyIfak + $qtyPainkiller === 0) {
            $skipped++;
            $rowErrors[] = "Baris {$excelRowNumber}: paket \"{$packageName}\" tidak memiliki item farmasi.";
            continue;
        }

        // Find or create identity_id if citizen_id provided
        $identityId = null;

        $lookupCitizenId = ems_normalize_citizen_id($citizenId !== '' ? $citizenId : $consumerName);
        if ($lookupCitizenId !== '') {
            $stmt = $pdo->prepare("SELECT id FROM identity_master WHERE citizen_id = ?");
            $stmt->execute([$lookupCitizenId]);
            $identity = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($identity) {
                $identityId = $identity['id'];
            }
        }

        // Generate unique tx_hash
        $txHash = hash('sha256', $medicName . $consumerName . $transactionDate . microtime(true) . $i);

        // Insert to sales table
        $stmt = $pdo->prepare("
            INSERT INTO sales (
                consumer_name,
                medic_name,
                medic_user_id,
                medic_jabatan,
                " . ($salesHasUnitCode ? "unit_code," : "") . "
                qty_bandage,
                qty_ifaks,
                qty_painkiller,
                price,
                package_id,
                package_name,
                keterangan,
                identity_id,
                tx_hash,
                created_at
            ) VALUES (?, ?, ?, ?, " . ($salesHasUnitCode ? "?," : "") . " ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $consumerName,
            $medicName,
            $medicUserId,
            $medicJabatan,
            ...($salesHasUnitCode ? [$effectiveUnit] : []),
            $qtyBandage,
            $qtyIfak,
            $qtyPainkiller,
            $price,
            $packageId,
            $packageName,
            '', // keterangan kosong
            $identityId,
            $txHash,
            $transactionDate . ' ' . date('H:i:s')
        ]);

        $imported++;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'row_errors' => $rowErrors,
        'message' => "Import selesai: {$imported} berhasil, {$skipped} dilewati."
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[import_sales_excel] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Import gagal diproses oleh server. Periksa format Excel dan coba lagi.'
    ]);
}
