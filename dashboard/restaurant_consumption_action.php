<?php
/**
 * =====================================================
 * RESTAURANT CONSUMPTION ACTION HANDLER
 * =====================================================
 */

session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

/**
 * =====================================================
 * IMAGE COMPRESSION FUNCTION (TARGET 300KB - AGGRESSIVE)
 * Khusus untuk KTP Restoran: PNG dikonversi ke JPEG
 * =====================================================
 */
function compressImageSmart(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 800,
    int $targetSize = 300000,
    int $minQuality = 50
): bool {
    $info = getimagesize($sourcePath);
    if (!$info) return false;

    $mime = $info['mime'];
    if ($mime === 'image/jpeg') {
        $src = imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png') {
        $src = imagecreatefrompng($sourcePath);
        // Konversi PNG ke JPEG untuk kompresi lebih baik
        $mime = 'image/jpeg';
    } else {
        return false;
    }

    $w = imagesx($src);
    $h = imagesy($src);

    if ($w > $maxWidth) {
        $ratio = $maxWidth / $w;
        $nw = $maxWidth;
        $nh = (int)($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
    } else {
        $dst = $src;
    }

    // Kompresi sebagai JPEG dengan loop sampai target tercapai
    for ($q = 90; $q >= $minQuality; $q -= 5) {
        imagejpeg($dst, $targetPath, $q);
        if (filesize($targetPath) <= $targetSize) break;
    }

    imagedestroy($dst);
    return true;
}

$action = $_GET['action'] ?? '';
$userId = (int)($_SESSION['user_rh']['id'] ?? 0);
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));

if ($userId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

/**
 * =====================================================
 * CREATE - INPUT KONSUMSI BARU
 * =====================================================
 */
if ($action === 'create') {

    $pdo->beginTransaction();

    try {
        $restaurantId = (int)($_POST['restaurant_id'] ?? 0);
        $packetCount = (int)($_POST['packet_count'] ?? 0);
        $deliveryDate = $_POST['delivery_date'] ?? '';
        $deliveryTime = $_POST['delivery_time'] ?? '';
        $notes = $_POST['notes'] ?? '';

        // Ambil data restoran
        $stmt = $pdo->prepare("
            SELECT restaurant_name, price_per_packet, tax_percentage
            FROM restaurant_settings
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$restaurantId]);
        $resto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resto) {
            throw new Exception('Restoran tidak ditemukan');
        }

        $pricePerPacket = (float)$resto['price_per_packet'];
        $taxPercentage = (float)$resto['tax_percentage'];

        // Kalkulasi
        $subtotal = $pricePerPacket * $packetCount;
        $taxAmount = $subtotal * ($taxPercentage / 100);
        $totalAmount = $subtotal + $taxAmount;

        // Handle file upload KTP with compression
        $ktpFile = null;
        if (isset($_FILES['ktp_file']) && $_FILES['ktp_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../storage/restaurant_ktp/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $tmpPath = $_FILES['ktp_file']['tmp_name'];
            $info = getimagesize($tmpPath);

            // Validasi tipe file
            if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
                throw new Exception('File KTP harus berupa JPG atau PNG');
            }

            // Semua file dikonversi ke JPEG untuk kompresi lebih baik
            $filename = 'ktp_' . time() . '_' . $userId . '.jpg';
            $finalPath = $uploadDir . $filename;

            // Kompres gambar dengan fungsi compressImageSmart (target 300KB, agresif)
            if (!compressImageSmart($tmpPath, $finalPath, 800, 300000, 50)) {
                throw new Exception('Gagal memproses file KTP');
            }

            $ktpFile = 'storage/restaurant_ktp/' . $filename;
        }

        // Insert data
        $stmt = $pdo->prepare("
            INSERT INTO restaurant_consumptions
            (consumption_code, restaurant_id, restaurant_name,
             recipient_user_id, recipient_name, delivery_date, delivery_time,
             packet_count, price_per_packet, tax_percentage,
             subtotal, tax_amount, total_amount,
             ktp_file, notes, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");

        $stmt->execute([
            $_POST['consumption_code'],
            $restaurantId,
            $resto['restaurant_name'],
            $userId,
            $_POST['recipient_name'],
            $deliveryDate,
            $deliveryTime,
            $packetCount,
            $pricePerPacket,
            $taxPercentage,
            $subtotal,
            $taxAmount,
            $totalAmount,
            $ktpFile,
            $notes,
            $userId
        ]);

        $pdo->commit();

        $_SESSION['flash_messages'][] = 'Konsumsi berhasil dicatat!';

        echo json_encode([
            'success' => true,
            'message' => 'Konsumsi berhasil dicarat!'
        ]);

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[RESTAURANT CONSUMPTION ERROR] ' . $e->getMessage());

        echo json_encode([
            'success' => false,
            'message' => 'Gagal menyimpan: ' . $e->getMessage()
        ]);
    }
    exit;
}

/**
 * =====================================================
 * APPROVE - SETUJUI KONSUMSI
 * =====================================================
 */
if ($action === 'approve') {

    // Cek permission
    if (in_array($userRole, ['staff', 'manager'], true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Anda tidak memiliki izin untuk menyetujui'
        ]);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ID tidak valid'
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE restaurant_consumptions
            SET status = 'approved',
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$userId, $id]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['flash_messages'][] = 'Konsumsi disetujui!';
            echo json_encode(['success' => true]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Data tidak ditemukan atau sudah diproses'
            ]);
        }
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal: ' . $e->getMessage()
        ]);
    }
    exit;
}

/**
 * =====================================================
 * PAID - TANDAI SUDAH DIBAYAR
 * =====================================================
 */
if ($action === 'paid') {

    // Cek permission
    if (in_array($userRole, ['staff', 'manager'], true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Anda tidak memiliki izin'
        ]);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ID tidak valid'
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE restaurant_consumptions
            SET status = 'paid',
                paid_by = ?,
                paid_at = NOW()
            WHERE id = ? AND status = 'approved'
        ");
        $stmt->execute([$userId, $id]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['flash_messages'][] = 'Konsumsi ditandai LUNAS!';
            echo json_encode(['success' => true]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Data tidak ditemukan atau status tidak valid'
            ]);
        }
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal: ' . $e->getMessage()
        ]);
    }
    exit;
}

/**
 * =====================================================
 * DELETE - HAPUS KONSUMSI (DIRECTOR ONLY)
 * =====================================================
 */
if ($action === 'delete') {

    if (!in_array($userRole, ['vice director', 'director'], true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Hanya Director yang bisa menghapus'
        ]);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'ID tidak valid'
        ]);
        exit;
    }

    try {
        // Ambil info file untuk dihapus
        $stmt = $pdo->prepare("SELECT ktp_file FROM restaurant_consumptions WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['ktp_file'])) {
            $filePath = __DIR__ . '/../' . $row['ktp_file'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Hapus dari database
        $stmt = $pdo->prepare("DELETE FROM restaurant_consumptions WHERE id = ?");
        $stmt->execute([$id]);

        $_SESSION['flash_messages'][] = 'Data berhasil dihapus!';
        echo json_encode(['success' => true]);

    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal menghapus: ' . $e->getMessage()
        ]);
    }
    exit;
}

/**
 * =====================================================
 * UNKNOWN ACTION
 * =====================================================
 */
echo json_encode([
    'success' => false,
    'message' => 'Action tidak dikenali'
]);
