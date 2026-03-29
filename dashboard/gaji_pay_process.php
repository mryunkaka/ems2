<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: application/json');

ems_require_not_trainee_json('Pembayaran Gaji');

// Cek apakah user memiliki akses
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$salaryHasUnitCode = ems_column_exists($pdo, 'salary', 'unit_code');
if ($userRole === 'staff') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

// Parse JSON input
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

$salaryId = (int)($input['salary_id'] ?? 0);
$payMethod = $input['pay_method'] ?? 'direct';
$titipTo = (int)($input['titip_to'] ?? 0);

if ($salaryId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID gaji tidak valid']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Ambil data salary untuk mendapatkan medic_name
    $stmt = $pdo->prepare("SELECT medic_name, bonus_40 FROM salary WHERE id = ?" . ($salaryHasUnitCode ? " AND unit_code = ?" : ""));
    $salaryParams = [$salaryId];
    if ($salaryHasUnitCode) {
        $salaryParams[] = $effectiveUnit;
    }
    $stmt->execute($salaryParams);
    $salaryData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$salaryData) {
        throw new Exception('Data gaji tidak ditemukan');
    }

    $paidBy = $_SESSION['user_rh']['name'] ?? 'System';

    if ($payMethod === 'direct') {
        // Langsung dibayar - update biasa
        $stmt = $pdo->prepare("
            UPDATE salary
            SET status='paid', paid_at=NOW(), paid_by=?
            WHERE id=?
            " . ($salaryHasUnitCode ? " AND unit_code = ?" : "") . "
        ");
        $updateParams = [$paidBy, $salaryId];
        if ($salaryHasUnitCode) {
            $updateParams[] = $effectiveUnit;
        }
        $stmt->execute($updateParams);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Gaji berhasil dibayarkan langsung ke ' . $salaryData['medic_name']]);
    } else {
        // Titip ke orang lain - ambil nama user tujuan
        $stmt = $pdo->prepare("SELECT full_name FROM user_rh WHERE id = ?");
        $stmt->execute([$titipTo]);
        $titipUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$titipUser) {
            throw new Exception('User tujuan tidak ditemukan');
        }

        // Update paid_by menjadi format "Titip ke: [Nama User] (oleh [Pelaksana])"
        $paidByText = "Titip ke: {$titipUser['full_name']} (oleh {$paidBy})";

        $stmt = $pdo->prepare("
            UPDATE salary
            SET status='paid', paid_at=NOW(), paid_by=?
            WHERE id=?
            " . ($salaryHasUnitCode ? " AND unit_code = ?" : "") . "
        ");
        $updateParams = [$paidByText, $salaryId];
        if ($salaryHasUnitCode) {
            $updateParams[] = $effectiveUnit;
        }
        $stmt->execute($updateParams);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Gaji berhasil dititipkan ke ' . $titipUser['full_name']]);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
}
