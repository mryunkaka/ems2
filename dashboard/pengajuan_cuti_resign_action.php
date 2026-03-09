<?php
/**
 * ACTION HANDLER - PENGAJUAN CUTI DAN RESIGN
 *
 * Menangani semua actions terkait pengajuan cuti dan resign:
 * - submit_cuti: Submit pengajuan cuti baru
 * - submit_resign: Submit pengajuan resign baru
 * - approve_cuti: Setujui pengajuan cuti
 * - reject_cuti: Tolak pengajuan cuti
 * - approve_resign: Setujui pengajuan resign
 * - reject_resign: Tolak pengajuan resign
 */

// Start output buffering to prevent any HTML output before JSON
ob_start();

date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Set JSON header for all responses by default
header('Content-Type: application/json; charset=utf-8');

// Pastikan request adalah POST atau GET (untuk detail)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

// Handle GET request untuk detail
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim($_GET['action'] ?? '');

    if ($action === 'get_cuti_detail') {
        $requestId = (int)($_GET['request_id'] ?? 0);
        if ($requestId <= 0) {
            sendJsonResponse(['success' => false, 'error' => 'Request ID tidak valid'], 400);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    cr.*,
                    u.full_name,
                    u.position,
                    u.batch,
                    u.role,
                    approver.full_name as approved_by_name
                FROM cuti_requests cr
                INNER JOIN user_rh u ON u.id = cr.user_id
                LEFT JOIN user_rh approver ON approver.id = cr.approved_by
                WHERE cr.id = ?
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $detail = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$detail) {
                sendJsonResponse(['success' => false, 'error' => 'Request tidak ditemukan'], 404);
            }

            sendJsonResponse([
                'success' => true,
                'data' => $detail
            ]);
        } catch (Throwable $e) {
            sendJsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    if ($action === 'get_resign_detail') {
        $requestId = (int)($_GET['request_id'] ?? 0);
        if ($requestId <= 0) {
            sendJsonResponse(['success' => false, 'error' => 'Request ID tidak valid'], 400);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    rr.*,
                    u.full_name,
                    u.position,
                    u.batch,
                    u.role,
                    approver.full_name as approved_by_name
                FROM resign_requests rr
                INNER JOIN user_rh u ON u.id = rr.user_id
                LEFT JOIN user_rh approver ON approver.id = rr.approved_by
                WHERE rr.id = ?
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $detail = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$detail) {
                sendJsonResponse(['success' => false, 'error' => 'Request tidak ditemukan'], 404);
            }

            sendJsonResponse([
                'success' => true,
                'data' => $detail
            ]);
        } catch (Throwable $e) {
            sendJsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    sendJsonResponse(['success' => false, 'error' => 'Action tidak dikenali'], 400);
}

// Ambil data user dari session
$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);
$userRole = $user['role'] ?? '';

if ($userId <= 0) {
    sendJsonResponse(['success' => false, 'error' => 'Session tidak valid'], 401);
}

// Ambil action dari POST
$action = trim($_POST['action'] ?? '');
$csrfToken = $_POST['csrf_token'] ?? '';

// Fungsi redirect untuk non-AJAX request
function redirect_back(string $fallback = 'pengajuan_cuti_resign.php'): void
{
    $redirectTo = trim((string)($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '' || strpos($redirectTo, '://') !== false || str_starts_with($redirectTo, '//')) {
        $redirectTo = $fallback;
    }
    header('Location: ' . $redirectTo);
    exit;
}

// Helper function to send JSON response for AJAX requests
function sendJsonResponse(array $data, int $statusCode = 200): void
{
    // Clean all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    
    // Send JSON response
    echo json_encode($data);
    exit;
}

try {
    // =====================================================
    // ACTION: SUBMIT CUTI
    // =====================================================
    if ($action === 'submit_cuti') {
        // Validasi CSRF untuk non-AJAX
        require_once __DIR__ . '/../auth/csrf.php';
        if (empty($csrfToken) || !validateCsrfToken($csrfToken)) {
            $_SESSION['flash_errors'][] = 'CSRF token tidak valid';
            redirect_back();
        }

        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $reasonIC = trim($_POST['reason_ic'] ?? '');
        $reasonOOC = trim($_POST['reason_ooc'] ?? '');

        // Validasi input
        if (empty($startDate) || empty($endDate) || empty($reasonIC) || empty($reasonOOC)) {
            throw new Exception('Semua field harus diisi');
        }

        // Validasi tanggal
        $start = DateTime::createFromFormat('Y-m-d', $startDate);
        $end = DateTime::createFromFormat('Y-m-d', $endDate);

        if (!$start || !$end) {
            throw new Exception('Format tanggal tidak valid');
        }

        if ($start > $end) {
            throw new Exception('Tanggal mulai tidak boleh setelah tanggal selesai');
        }

        if ($start < new DateTime('today')) {
            throw new Exception('Tanggal mulai tidak boleh di masa lalu');
        }

        // Hitung total hari
        $daysTotal = $start->diff($end)->days + 1;

        // Generate request code
        $requestCode = generate_request_code('cuti');

        // Insert ke database
        $stmt = $pdo->prepare("
            INSERT INTO cuti_requests
                (user_id, request_code, start_date, end_date, days_total, reason_ic, reason_ooc, status)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $userId,
            $requestCode,
            $startDate,
            $endDate,
            $daysTotal,
            $reasonIC,
            $reasonOOC
        ]);

        $_SESSION['flash_messages'][] = "Pengajuan cuti berhasil dikirim dengan kode {$requestCode}. Menunggu approval.";

        // Cek jika request AJAX
        if (!empty($_POST['ajax'])) {
            sendJsonResponse([
                'success' => true,
                'message' => "Pengajuan cuti berhasil dikirim dengan kode {$requestCode}"
            ]);
        }

        redirect_back();
    }

    // =====================================================
    // ACTION: SUBMIT RESIGN
    // =====================================================
    if ($action === 'submit_resign') {
        // Validasi CSRF untuk non-AJAX
        require_once __DIR__ . '/../auth/csrf.php';
        if (empty($csrfToken) || !validateCsrfToken($csrfToken)) {
            $_SESSION['flash_errors'][] = 'CSRF token tidak valid';
            redirect_back();
        }

        $reasonIC = trim($_POST['reason_ic'] ?? '');
        $reasonOOC = trim($_POST['reason_ooc'] ?? '');

        // Validasi input
        if (empty($reasonIC) || empty($reasonOOC)) {
            throw new Exception('Semua field harus diisi');
        }

        // Generate request code
        $requestCode = generate_request_code('resign');

        // Insert ke database
        $stmt = $pdo->prepare("
            INSERT INTO resign_requests
                (user_id, request_code, reason_ic, reason_ooc, status)
            VALUES
                (?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $userId,
            $requestCode,
            $reasonIC,
            $reasonOOC
        ]);

        $_SESSION['flash_messages'][] = "Pengajuan resign berhasil dikirim dengan kode {$requestCode}. Menunggu approval.";

        // Cek jika request AJAX
        if (!empty($_POST['ajax'])) {
            sendJsonResponse([
                'success' => true,
                'message' => "Pengajuan resign berhasil dikirim dengan kode {$requestCode}"
            ]);
        }

        redirect_back();
    }

    // =====================================================
    // ACTIONS YANG MEMBUTUHKAN APPROVAL ROLE
    // =====================================================
    $approvalActions = ['approve_cuti', 'reject_cuti', 'approve_resign', 'reject_resign'];

    if (in_array($action, $approvalActions)) {
        // Cek apakah user punya akses approval
        if (!can_approve_cuti_resign($userRole)) {
            sendJsonResponse(['success' => false, 'error' => 'Anda tidak berwenang melakukan approval'], 403);
            exit;
        }

        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId <= 0) {
            sendJsonResponse(['success' => false, 'error' => 'Request ID tidak valid'], 400);
            exit;
        }

        // -------------------------------------------------
        // ACTION: APPROVE CUTI
        // -------------------------------------------------
        if ($action === 'approve_cuti') {
            // Ambil data request
            $stmt = $pdo->prepare("
                SELECT cr.*, u.full_name, u.position
                FROM cuti_requests cr
                INNER JOIN user_rh u ON u.id = cr.user_id
                WHERE cr.id = ? AND cr.status = 'pending'
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $cutiRequest = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cutiRequest) {
                sendJsonResponse(['success' => false, 'error' => 'Request tidak ditemukan atau sudah diproses'], 404);
                exit;
            }

            // Update request status
            $stmt = $pdo->prepare("
                UPDATE cuti_requests
                SET status = 'approved',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$userId, $requestId]);

            // Update user_cuti data di tabel user_rh
            $stmt = $pdo->prepare("
                UPDATE user_rh
                SET cuti_start_date = ?,
                    cuti_end_date = ?,
                    cuti_days_total = ?,
                    cuti_status = 'active',
                    cuti_approved_by = ?,
                    cuti_approved_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $cutiRequest['start_date'],
                $cutiRequest['end_date'],
                $cutiRequest['days_total'],
                $userId,
                $cutiRequest['user_id']
            ]);

            // Log ke account_logs
            $stmt = $pdo->prepare("
                INSERT INTO account_logs
                    (user_id, full_name_after, position_after, pin_changed, created_at)
                VALUES
                    (?, ?, ?, 0, NOW())
            ");
            $stmt->execute([
                $cutiRequest['user_id'],
                $cutiRequest['full_name'] ?? '',
                $cutiRequest['position'] ?? '',
            ]);
            
            // Also log to cuti_requests table via details in JSON (stored in separate log file)
            $logFile = __DIR__ . '/../logs/cuti_approved.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logEntry = sprintf(
                "[%s] Cuti approved: %s (User ID: %d) by User ID: %d. Days: %d\n",
                date('Y-m-d H:i:s'),
                $cutiRequest['request_code'],
                $cutiRequest['user_id'],
                $userId,
                $cutiRequest['days_total']
            );
            file_put_contents($logFile, $logEntry, FILE_APPEND);

            sendJsonResponse([
                'success' => true,
                'message' => "Pengajuan cuti {$cutiRequest['request_code']} berhasil disetujui"
            ]);
        }

        // -------------------------------------------------
        // ACTION: REJECT CUTI
        // -------------------------------------------------
        if ($action === 'reject_cuti') {
            $rejectionReason = trim($_POST['rejection_reason'] ?? '');

            if (empty($rejectionReason)) {
                sendJsonResponse(['success' => false, 'error' => 'Alasan penolakan harus diisi'], 400);
                exit;
            }

            // Ambil data request
            $stmt = $pdo->prepare("
                SELECT *
                FROM cuti_requests
                WHERE id = ? AND status = 'pending'
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $cutiRequest = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cutiRequest) {
                sendJsonResponse(['success' => false, 'error' => 'Request tidak ditemukan atau sudah diproses'], 404);
                exit;
            }

            // Update request status
            $stmt = $pdo->prepare("
                UPDATE cuti_requests
                SET status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $rejectionReason, $requestId]);

            // Log ke account_logs
            $stmt = $pdo->prepare("
                INSERT INTO account_logs
                    (user_id, full_name_after, position_after, pin_changed, created_at)
                VALUES
                    (?, ?, ?, 0, NOW())
            ");
            $stmt->execute([
                $cutiRequest['user_id'],
                $cutiRequest['full_name'] ?? '',
                $cutiRequest['position'] ?? '',
            ]);
            
            // Also log to file
            $logFile = __DIR__ . '/../logs/cuti_rejected.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logEntry = sprintf(
                "[%s] Cuti rejected: %s (User ID: %d) by User ID: %d. Reason: %s\n",
                date('Y-m-d H:i:s'),
                $cutiRequest['request_code'],
                $cutiRequest['user_id'],
                $userId,
                $rejectionReason
            );
            file_put_contents($logFile, $logEntry, FILE_APPEND);

            sendJsonResponse([
                'success' => true,
                'message' => "Pengajuan cuti {$cutiRequest['request_code']} berhasil ditolak"
            ]);
        }

        // -------------------------------------------------
        // ACTION: APPROVE RESIGN
        // -------------------------------------------------
        if ($action === 'approve_resign') {
            // Ambil data request
            $stmt = $pdo->prepare("
                SELECT rr.*, u.full_name, u.position
                FROM resign_requests rr
                INNER JOIN user_rh u ON u.id = rr.user_id
                WHERE rr.id = ? AND rr.status = 'pending'
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $resignRequest = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$resignRequest) {
                sendJsonResponse(['success' => false, 'error' => 'Request tidak ditemukan atau sudah diproses'], 404);
                exit;
            }

            // Update request status
            $stmt = $pdo->prepare("
                UPDATE resign_requests
                SET status = 'approved',
                    approved_by = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$userId, $requestId]);

            // Update user data di tabel user_rh (nonaktifkan user)
            $stmt = $pdo->prepare("
                UPDATE user_rh
                SET is_active = 0,
                    resign_reason = ?,
                    resigned_by = ?,
                    resigned_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                "IC: {$resignRequest['reason_ic']}\nOOC: {$resignRequest['reason_ooc']}",
                $userId,
                $resignRequest['user_id']
            ]);

            // Hapus semua remember tokens (force logout dari semua device)
            $stmt = $pdo->prepare("
                DELETE FROM remember_tokens
                WHERE user_id = ?
            ");
            $stmt->execute([$resignRequest['user_id']]);

            // Log ke account_logs
            $stmt = $pdo->prepare("
                INSERT INTO account_logs
                    (user_id, full_name_after, position_after, pin_changed, created_at)
                VALUES
                    (?, ?, ?, 0, NOW())
            ");
            $stmt->execute([
                $resignRequest['user_id'],
                $resignRequest['full_name'] ?? '',
                $resignRequest['position'] ?? '',
            ]);
            
            // Also log to file
            $logFile = __DIR__ . '/../logs/resign_approved.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logEntry = sprintf(
                "[%s] Resign approved: %s (User ID: %d, Name: %s) by User ID: %d\n",
                date('Y-m-d H:i:s'),
                $resignRequest['request_code'],
                $resignRequest['user_id'],
                $resignRequest['full_name'],
                $userId
            );
            file_put_contents($logFile, $logEntry, FILE_APPEND);

            sendJsonResponse([
                'success' => true,
                'message' => "Pengajuan resign {$resignRequest['request_code']} berhasil disetujui. User {$resignRequest['full_name']} telah dinonaktifkan."
            ]);
        }

        // -------------------------------------------------
        // ACTION: REJECT RESIGN
        // -------------------------------------------------
        if ($action === 'reject_resign') {
            $rejectionReason = trim($_POST['rejection_reason'] ?? '');

            if (empty($rejectionReason)) {
                sendJsonResponse(['success' => false, 'error' => 'Alasan penolakan harus diisi'], 400);
                exit;
            }

            // Ambil data request
            $stmt = $pdo->prepare("
                SELECT *
                FROM resign_requests
                WHERE id = ? AND status = 'pending'
                LIMIT 1
            ");
            $stmt->execute([$requestId]);
            $resignRequest = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$resignRequest) {
                sendJsonResponse(['success' => false, 'error' => 'Request tidak ditemukan atau sudah diproses'], 404);
                exit;
            }

            // Update request status
            $stmt = $pdo->prepare("
                UPDATE resign_requests
                SET status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $rejectionReason, $requestId]);

            // Log ke account_logs
            $stmt = $pdo->prepare("
                INSERT INTO account_logs
                    (user_id, full_name_after, position_after, pin_changed, created_at)
                VALUES
                    (?, ?, ?, 0, NOW())
            ");
            $stmt->execute([
                $resignRequest['user_id'],
                $resignRequest['full_name'] ?? '',
                $resignRequest['position'] ?? '',
            ]);
            
            // Also log to file
            $logFile = __DIR__ . '/../logs/resign_rejected.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logEntry = sprintf(
                "[%s] Resign rejected: %s (User ID: %d) by User ID: %d. Reason: %s\n",
                date('Y-m-d H:i:s'),
                $resignRequest['request_code'],
                $resignRequest['user_id'],
                $userId,
                $rejectionReason
            );
            file_put_contents($logFile, $logEntry, FILE_APPEND);

            sendJsonResponse([
                'success' => true,
                'message' => "Pengajuan resign {$resignRequest['request_code']} berhasil ditolak"
            ]);
        }
    }

    // Jika action tidak dikenali
    sendJsonResponse(['success' => false, 'error' => 'Action tidak dikenali'], 400);

} catch (Throwable $e) {
    // Log error untuk debugging
    $errorMsg = 'Cuti/Resign Action Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    error_log($errorMsg);
    
    // Also log to file for easier debugging
    $logFile = __DIR__ . '/../logs/cuti_action_error.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $errorMsg . PHP_EOL, FILE_APPEND);

    // Clean any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Always return JSON for API actions (approve/reject)
    $approvalActions = ['approve_cuti', 'reject_cuti', 'approve_resign', 'reject_resign'];
    $isAjaxAction = in_array($action, $approvalActions) ||
                    !empty($_POST['ajax']) ||
                    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

    if ($isAjaxAction) {
        sendJsonResponse([
            'success' => false,
            'error' => 'Terjadi kesalahan: ' . $e->getMessage()
        ], 500);
    } else {
        // Non-AJAX request
        $_SESSION['flash_errors'][] = 'Gagal memproses: ' . $e->getMessage();
        redirect_back();
    }
}
