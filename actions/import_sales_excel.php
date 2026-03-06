<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php'; // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['user_rh'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Validate POST data
if (!isset($_POST['medic_name']) || !isset($_POST['transaction_date']) || !isset($_FILES['excel_file'])) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$medicName = trim($_POST['medic_name']);
$medicPosition = trim($_POST['medic_position'] ?? '');
$transactionDate = $_POST['transaction_date'];
$file = $_FILES['excel_file'];

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
    // A: Consumer Name (Nama Konsumen)
    // B: Package Name (Nama Paket - akan lookup ke tabel packages)
    // C: Citizen ID (optional)

    $imported = 0;
    $pdo->beginTransaction();

    // Skip header row (row 0)
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];

        // Skip empty rows
        if (empty(trim($row[0] ?? '')) || empty(trim($row[1] ?? ''))) {
            continue;
        }

        $consumerName = trim($row[0] ?? '');
        $packageName = trim($row[1] ?? '');
        $citizenId = trim($row[2] ?? '');

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
            continue;
        }

        $packageId = $package['id'];
        $qtyBandage = intval($package['bandage_qty']);
        $qtyIfak = intval($package['ifaks_qty']);
        $qtyPainkiller = intval($package['painkiller_qty']);
        $price = intval($package['price']);

        // Skip if no items
        if ($qtyBandage + $qtyIfak + $qtyPainkiller === 0) {
            continue;
        }

        // Find or create identity_id if citizen_id provided
        $identityId = null;

        if (!empty($citizenId)) {
            $stmt = $pdo->prepare("SELECT id FROM identity_master WHERE citizen_id = ?");
            $stmt->execute([$citizenId]);
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
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $consumerName,
            $medicName,
            $medicUserId,
            $medicJabatan,
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
        'message' => "Berhasil import $imported transaksi"
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
