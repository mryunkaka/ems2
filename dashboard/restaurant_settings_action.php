<?php
/**
 * =====================================================
 * RESTAURANT SETTINGS ACTION HANDLER
 * =====================================================
 */

session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));

// Permission check - hanya selain staff/manager
if (in_array($userRole, ['staff', 'manager'], true)) {
    if ($action === 'delete' || $action === 'toggle' || $action === 'create' || $action === 'update') {
        header('Location: /dashboard/restaurant_settings.php');
        $_SESSION['flash_errors'][] = 'Anda tidak memiliki izin untuk melakukan aksi ini';
        exit;
    }
}

if ($userId <= 0) {
    $_SESSION['flash_errors'][] = 'Unauthorized';
    header('Location: /dashboard/restaurant_settings.php');
    exit;
}

/**
 * =====================================================
 * CREATE - TAMBAH RESTORAN BARU
 * =====================================================
 */
if ($action === 'create') {

    $restaurantName = trim($_POST['restaurant_name'] ?? '');
    $pricePerPacket = (float)($_POST['price_per_packet'] ?? 0);
    $taxPercentage = (float)($_POST['tax_percentage'] ?? 5);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if (empty($restaurantName)) {
        $_SESSION['flash_errors'][] = 'Nama restoran wajib diisi';
        header('Location: /dashboard/restaurant_settings.php');
        exit;
    }

    if ($pricePerPacket < 0) {
        $_SESSION['flash_errors'][] = 'Harga tidak valid';
        header('Location: /dashboard/restaurant_settings.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO restaurant_settings
            (restaurant_name, price_per_packet, tax_percentage, is_active)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                price_per_packet = VALUES(price_per_packet),
                tax_percentage = VALUES(tax_percentage),
                is_active = VALUES(is_active)
        ");
        $stmt->execute([$restaurantName, $pricePerPacket, $taxPercentage, $isActive]);

        $_SESSION['flash_messages'][] = 'Restoran berhasil ditambahkan!';
        header('Location: /dashboard/restaurant_settings.php');
        exit;

    } catch (Throwable $e) {
        error_log('[RESTAURANT SETTINGS ERROR] ' . $e->getMessage());
        $_SESSION['flash_errors'][] = 'Gagal menambahkan restoran';
        header('Location: /dashboard/restaurant_settings.php');
        exit;
    }
}

/**
 * =====================================================
 * UPDATE - EDIT RESTORAN
 * =====================================================
 */
if ($action === 'update') {

    $id = (int)($_POST['id'] ?? 0);
    $restaurantName = trim($_POST['restaurant_name'] ?? '');
    $pricePerPacket = (float)($_POST['price_per_packet'] ?? 0);
    $taxPercentage = (float)($_POST['tax_percentage'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($id <= 0) {
        $_SESSION['flash_errors'][] = 'ID tidak valid';
        header('Location: /dashboard/restaurant_settings.php');
        exit;
    }

    if (empty($restaurantName)) {
        $_SESSION['flash_errors'][] = 'Nama restoran wajib diisi';
        header('Location: /dashboard/restaurant_settings.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE restaurant_settings
            SET restaurant_name = ?,
                price_per_packet = ?,
                tax_percentage = ?,
                is_active = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$restaurantName, $pricePerPacket, $taxPercentage, $isActive, $id]);

        $_SESSION['flash_messages'][] = 'Restoran berhasil diperbarui!';
        header('Location: /dashboard/restaurant_settings.php');
        exit;

    } catch (Throwable $e) {
        error_log('[RESTAURANT SETTINGS ERROR] ' . $e->getMessage());
        $_SESSION['flash_errors'][] = 'Gagal memperbarui restoran';
        header('Location: /dashboard/restaurant_settings.php');
        exit;
    }
}

/**
 * =====================================================
 * TOGGLE - AKTIFKAN/NONAKTIFKAN RESTORAN
 * =====================================================
 */
if ($action === 'toggle') {

    $id = (int)($_POST['id'] ?? 0);
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE restaurant_settings
            SET is_active = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$isActive, $id]);

        echo json_encode(['success' => true]);
        exit;

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/**
 * =====================================================
 * DELETE - HAPUS RESTORAN
 * =====================================================
 */
if ($action === 'delete') {

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
        exit;
    }

    // Cek apakah ada data konsumsi yang terkait
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM restaurant_consumptions WHERE restaurant_id = ?");
        $stmt->execute([$id]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Tidak bisa menghapus! Masih ada ' . $count . ' data konsumsi yang terkait.'
            ]);
            exit;
        }

        // Hapus restoran
        $stmt = $pdo->prepare("DELETE FROM restaurant_settings WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
        exit;

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/**
 * =====================================================
 * UNKNOWN ACTION
 * =====================================================
 */
$_SESSION['flash_errors'][] = 'Action tidak dikenali';
header('Location: /dashboard/restaurant_settings.php');
exit;
