<?php
/**
 * CRON JOB - AUTO UPDATE STATUS CUTI
 *
 * Fungsi:
 * - Cek semua user dengan cuti_status = 'active'
 * - Jika tanggal hari ini > cuti_end_date, maka:
 *   - Update cuti_status = 'inactive'
 *   - Reset field cuti di user_rh (NULL)
 *   - Log ke account_logs
 *
 * Usage:
 *   - Manual run: php cron/update_cuti_status.php
 *   - Cron job (daily at 00:00): 0 0 * * * cd /path/to/ems2 && php cron/update_cuti_status.php
 */

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Load dependencies
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Log file
$logFile = __DIR__ . '/../logs/cuti_update.log';
$logDir = dirname($logFile);

// Buat directory logs jika belum ada
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

/**
 * Fungsi logging ke file
 */
function log_message(string $message): void
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    // Juga print ke stdout untuk monitoring
    echo $logMessage;
}

try {
    log_message("=== CRON JOB: UPDATE CUTI STATUS ===");
    log_message("Mulai proses auto-update status cuti...");

    // Ambil semua user dengan cuti_status = 'active'
    $stmt = $pdo->query("
        SELECT
            id,
            full_name,
            cuti_start_date,
            cuti_end_date,
            cuti_days_total,
            cuti_status
        FROM user_rh
        WHERE cuti_status = 'active'
        AND cuti_end_date IS NOT NULL
    ");
    $usersOnCuti = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$usersOnCuti) {
        log_message("Tidak ada user dengan status cuti aktif.");
        log_message("=== END OF CRON JOB ===\n");
        exit(0);
    }

    log_message("Ditemukan " . count($usersOnCuti) . " user dengan status cuti aktif.");

    $today = new DateTime();
    $updatedCount = 0;

    foreach ($usersOnCuti as $user) {
        $userId = (int)$user['id'];
        $userName = $user['full_name'];
        $cutiEndDate = new DateTime($user['cuti_end_date']);

        // Cek apakah cuti sudah selesai (hari ini > tanggal selesai)
        if ($today > $cutiEndDate) {
            log_message("Processing: {$userName} (ID: {$userId})");
            log_message("  - Cuti end date: {$user['cuti_end_date']}");
            log_message("  - Today: {$today->format('Y-m-d')}");
            log_message("  - Status: Cuti sudah selesai, akan direset...");

            // Update user_cuti data - reset ke NULL/inactive
            $stmt = $pdo->prepare("
                UPDATE user_rh
                SET cuti_status = 'inactive',
                    cuti_start_date = NULL,
                    cuti_end_date = NULL,
                    cuti_days_total = NULL,
                    cuti_approved_by = NULL,
                    cuti_approved_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$userId]);

            // Log ke account_logs
            $stmt = $pdo->prepare("
                INSERT INTO account_logs
                    (user_id, changed_by, action, details, created_at)
                VALUES
                    (?, NULL, 'cuti_expired', ?, NOW())
            ");
            $stmt->execute([
                $userId,
                json_encode([
                    'previous_end_date' => $user['cuti_end_date'],
                    'previous_days_total' => $user['cuti_days_total'],
                    'auto_updated_by' => 'cron_job'
                ])
            ]);

            log_message("  - ✓ User {$userName} berhasil direset ke status aktif.");
            $updatedCount++;
        } else {
            // Hitung sisa hari
            $remainingDays = $today->diff($cutiEndDate)->days + 1;
            log_message("Skipping: {$userName} (ID: {$userId}) - Masih ada {$remainingDays} hari cuti.");
        }
    }

    log_message("Total user yang diupdate: {$updatedCount} dari " . count($usersOnCuti));
    log_message("Proses auto-update selesai.");
    log_message("=== END OF CRON JOB ===\n");

    // Exit dengan kode sukses
    exit(0);

} catch (Throwable $e) {
    $errorMessage = "ERROR: " . $e->getMessage();
    log_message($errorMessage);
    log_message("Stack trace: " . $e->getTraceAsString());
    log_message("=== CRON JOB FAILED ===\n");

    // Exit dengan kode error
    exit(1);
}
