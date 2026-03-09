<?php
/**
 * ACTION HANDLER - TRACKING CUTI & RESIGN
 *
 * Menangani semua actions terkait tracking cuti & resign:
 * - kembali_cuti: Mengakhiri cuti dan mengembalikan user ke kerja
 */

// Start output buffering
ob_start();

date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Helper function
function sendJsonResponse(array $data, int $statusCode = 200): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function userRhColumnExists(PDO $pdo, string $columnName): bool
{
    static $cache = [];

    if (array_key_exists($columnName, $cache)) {
        return $cache[$columnName];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM user_rh LIKE ?");
    $stmt->execute([$columnName]);
    $cache[$columnName] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$columnName];
}

// Pastikan request adalah POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// Ambil data user dari session
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userRole = $user['role'] ?? '';

if ($userId <= 0) {
    sendJsonResponse(['success' => false, 'error' => 'Session tidak valid'], 401);
}

// Cek apakah user punya akses approval (Manager+)
if (!can_approve_cuti_resign($userRole)) {
    sendJsonResponse(['success' => false, 'error' => 'Anda tidak berwenang melakukan aksi ini'], 403);
}

// Ambil action dari POST
$action = trim($_POST['action'] ?? '');
$csrfToken = $_POST['csrf_token'] ?? '';

try {
    // =====================================================
    // ACTION: KEMBALI CUTI (Early return from cuti)
    // =====================================================
    if ($action === 'kembali_cuti') {
        // Validasi CSRF token
        require_once __DIR__ . '/../auth/csrf.php';
        if (empty($csrfToken) || !validateCsrfToken($csrfToken)) {
            sendJsonResponse(['success' => false, 'error' => 'CSRF token tidak valid'], 403);
        }

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            sendJsonResponse(['success' => false, 'error' => 'User ID tidak valid'], 400);
        }

        // Ambil data cuti user
        $stmt = $pdo->prepare("
            SELECT
                u.id,
                u.full_name,
                u.cuti_start_date,
                u.cuti_end_date,
                u.cuti_days_total,
                u.cuti_status,
                u.cuti_approved_by,
                u.cuti_approved_at,
                approver.full_name as approved_by_name
            FROM user_rh u
            LEFT JOIN user_rh approver ON approver.id = u.cuti_approved_by
            WHERE u.id = ? AND u.cuti_status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$targetUserId]);
        $cutiData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cutiData) {
            sendJsonResponse(['success' => false, 'error' => 'User tidak sedang cuti atau data tidak ditemukan'], 404);
        }

        // Hitung hari cuti yang sudah digunakan
        $today = new DateTime('today');
        $startDate = new DateTime($cutiData['cuti_start_date']);
        $endDate = new DateTime($cutiData['cuti_end_date']);

        if ($today < $startDate) {
            $daysUsed = 0;
        } elseif ($today > $endDate) {
            $daysUsed = $startDate->diff($endDate)->days + 1;
        } else {
            $daysUsed = $startDate->diff($today)->days + 1;
        }

        $daysOriginal = (int)$cutiData['cuti_days_total'];
        $daysCut = max(0, $daysOriginal - $daysUsed); // Hari yang dipotong

        $updateFields = [
            'cuti_status = ?',
            'cuti_end_date = ?',
            'cuti_days_total = ?'
        ];
        $updateParams = [
            'inactive',
            $today->format('Y-m-d'),
            $daysUsed
        ];

        if (userRhColumnExists($pdo, 'cuti_ended_at')) {
            $updateFields[] = 'cuti_ended_at = NOW()';
        }

        if (userRhColumnExists($pdo, 'cuti_ended_by')) {
            $updateFields[] = 'cuti_ended_by = ?';
            $updateParams[] = $userId;
        }

        if (userRhColumnExists($pdo, 'cuti_original_days')) {
            $updateFields[] = 'cuti_original_days = ?';
            $updateParams[] = $daysOriginal;
        }

        $updateParams[] = $targetUserId;

        $stmt = $pdo->prepare("
            UPDATE user_rh
            SET " . implode(",\n                ", $updateFields) . "
            WHERE id = ?
        ");
        $stmt->execute($updateParams);

        // Log ke account_logs (use existing columns)
        $stmt = $pdo->prepare("
            INSERT INTO account_logs
                (user_id, full_name_after, position_after, pin_changed, created_at)
            VALUES
                (?, ?, ?, 0, NOW())
        ");
        $stmt->execute([
            $targetUserId,
            $cutiData['full_name'] ?? '',
            '' // position not needed
        ]);

        // Log ke file
        $logFile = __DIR__ . '/../logs/cuti_early_return.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logEntry = sprintf(
            "[%s] User %s (ID: %d) kembali kerja lebih awal. Original: %d hari, Used: %d hari, Cut: %d hari. By: %s\n",
            $today->format('Y-m-d H:i:s'),
            $cutiData['full_name'],
            $targetUserId,
            $daysOriginal,
            $daysUsed,
            $daysCut,
            $user['full_name'] ?? $user['name'] ?? 'Unknown'
        );
        file_put_contents($logFile, $logEntry, FILE_APPEND);

        sendJsonResponse([
            'success' => true,
            'message' => sprintf(
                '%s berhasil dikembalikan ke kerja. Cuti dipotong dari %d hari menjadi %d hari (dipotong %d hari).',
                $cutiData['full_name'],
                $daysOriginal,
                $daysUsed,
                $daysCut
            )
        ]);
    }

    // Jika action tidak dikenali
    sendJsonResponse(['success' => false, 'error' => 'Action tidak dikenali'], 400);

} catch (Throwable $e) {
    // Log error untuk debugging
    $errorMsg = 'Tracking Cuti/Resign Action Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    error_log($errorMsg);

    // Also log to file for easier debugging
    $logFile = __DIR__ . '/../logs/tracking_action_error.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $errorMsg . PHP_EOL, FILE_APPEND);

    // Clean any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    sendJsonResponse([
        'success' => false,
        'error' => 'Terjadi kesalahan: ' . $e->getMessage()
    ], 500);
}
