<?php
session_start();
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_rh']['id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$data   = json_decode(file_get_contents('php://input'), true);
$status = $data['status'] ?? '';

if (!in_array($status, ['online', 'offline'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Status tidak valid'
    ]);
    exit;
}

$userId = (int)$_SESSION['user_rh']['id'];

$pdo->beginTransaction();

try {
    $settingsStmt = $pdo->prepare("
        SELECT max_online_medics, cooldown_minutes
        FROM farmasi_online_settings
        ORDER BY id ASC
        LIMIT 1
    ");
    $settingsStmt->execute();
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $maxOnlineMedics = max(0, (int)($settings['max_online_medics'] ?? 0));
    $cooldownMinutes = max(0, (int)($settings['cooldown_minutes'] ?? 0));

    if ($status === 'online') {
        if ($maxOnlineMedics > 0) {
            $onlineCountStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM user_farmasi_status
                WHERE status = 'online'
                  AND user_id <> ?
            ");
            $onlineCountStmt->execute([$userId]);
            $otherOnlineCount = (int)$onlineCountStmt->fetchColumn();

            if ($otherOnlineCount >= $maxOnlineMedics) {
                throw new RuntimeException('Maksimal medis online sudah tercapai.');
            }
        }

        if ($cooldownMinutes > 0) {
            $cooldownStmt = $pdo->prepare("
                SELECT TIMESTAMPDIFF(SECOND, session_end, NOW()) AS seconds_since_offline
                FROM user_farmasi_sessions
                WHERE user_id = ?
                  AND session_end IS NOT NULL
                ORDER BY session_end DESC
                LIMIT 1
            ");
            $cooldownStmt->execute([$userId]);
            $cooldownRow = $cooldownStmt->fetch(PDO::FETCH_ASSOC);

            if ($cooldownRow) {
                $secondsSinceOffline = max(0, (int)($cooldownRow['seconds_since_offline'] ?? 0));
                $cooldownSeconds = $cooldownMinutes * 60;

                if ($secondsSinceOffline < $cooldownSeconds) {
                    $remainingMinutes = (int)ceil(($cooldownSeconds - $secondsSinceOffline) / 60);
                    throw new RuntimeException('Cooldown masih aktif. Coba online lagi dalam ' . $remainingMinutes . ' menit.');
                }
            }
        }
    }

    /* =====================================================
       UPDATE STATUS FARMASI (KODE LAMA - TETAP)
       ===================================================== */
    $stmt = $pdo->prepare("
        INSERT INTO user_farmasi_status
            (user_id, status, last_activity_at, last_confirm_at, auto_offline_at, current_session_number)
        VALUES
            (?, ?, NOW(), NOW(), NULL, 0)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            last_confirm_at = NOW(),
            auto_offline_at = NULL,
            current_session_number = CASE
                WHEN VALUES(status) = 'offline' THEN 0
                ELSE current_session_number
            END,
            updated_at = NOW()
    ");
    $stmt->execute([$userId, $status]);

    /* =====================================================
       🔵 JIKA ONLINE → BUAT SESSION BARU DENGAN SESSION NUMBER
       ===================================================== */
    if ($status === 'online') {

        $check = $pdo->prepare("
            SELECT id
            FROM user_farmasi_sessions
            WHERE user_id = ?
            AND session_end IS NULL
            LIMIT 1
        ");
        $check->execute([$userId]);

        if (!$check->fetch()) {

            // 🔴 FIX DI SINI
            $u = $pdo->prepare("
                SELECT full_name, position
                FROM user_rh
                WHERE id = ?
                LIMIT 1
            ");
            $u->execute([$userId]);
            $user = $u->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('User tidak ditemukan');
            }

            // Get current session number for this user
            $getSessionNumber = $pdo->prepare("
                SELECT COALESCE(MAX(session_number), 0) + 1 as new_session_number
                FROM user_farmasi_sessions
                WHERE user_id = ?
            ");
            $getSessionNumber->execute([$userId]);
            $sessionNumber = $getSessionNumber->fetchColumn();

            $insertSession = $pdo->prepare("
                INSERT INTO user_farmasi_sessions
                    (user_id, medic_name, medic_jabatan, session_start, session_number)
                VALUES
                    (?, ?, ?, NOW(), ?)
            ");
            $insertSession->execute([
                $userId,
                $user['full_name'],
                $user['position'],
                $sessionNumber
            ]);

            // Update current session number in user_farmasi_status
            $updateSessionNumber = $pdo->prepare("
                INSERT INTO user_farmasi_status
                    (user_id, status, last_activity_at, last_confirm_at, auto_offline_at, current_session_number)
                VALUES
                    (?, 'online', NOW(), NOW(), NULL, ?)
                ON DUPLICATE KEY UPDATE
                    status = 'online',
                    last_confirm_at = NOW(),
                    auto_offline_at = NULL,
                    current_session_number = VALUES(current_session_number),
                    updated_at = NOW()
            ");
            $updateSessionNumber->execute([$userId, $sessionNumber]);
        }
    }


    /* =====================================================
       🔴 JIKA OFFLINE → TUTUP SESSION AKTIF
       ===================================================== */
    if ($status === 'offline') {

        $close = $pdo->prepare("
            UPDATE user_farmasi_sessions
            SET
                session_end = NOW(),
                duration_seconds = TIMESTAMPDIFF(SECOND, session_start, NOW()),
                end_reason = 'manual_offline',
                ended_by_user_id = ?
            WHERE user_id = ?
              AND session_end IS NULL
        ");
        $close->execute([$userId, $userId]);
    }

    $pdo->commit();

    /* =====================================================
       📌 LOG ACTIVITY (ONLINE / OFFLINE)
       ===================================================== */
    try {
        // Ambil nama lengkap dari session atau query
        $fullName = $_SESSION['user_rh']['name'] ?? 'Unknown';

        if (empty($fullName) || $fullName === 'Unknown') {
            $userStmt = $pdo->prepare("
                SELECT full_name 
                FROM user_rh 
                WHERE id = ? 
                LIMIT 1
            ");
            $userStmt->execute([$userId]);
            $fullName = $userStmt->fetchColumn() ?: 'Medis';
        }

        $activityType = $status === 'online' ? 'online' : 'offline';
        $description = $status === 'online'
            ? 'Mulai bertugas di farmasi'
            : 'Selesai bertugas';

        $logActivity = $pdo->prepare("
            INSERT INTO farmasi_activities 
                (activity_type, medic_user_id, medic_name, description)
            VALUES (?, ?, ?, ?)
        ");

        $logActivity->execute([
            $activityType,
            $userId,
            $fullName,
            $description
        ]);
    } catch (Exception $e) {
        // Log error tapi jangan ganggu response utama
        error_log('[ACTIVITY LOG ERROR] ' . $e->getMessage());
    }
    /* ===================================================== */

    echo json_encode([
        'success' => true,
        'status'  => $status
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();

    error_log($e->getMessage()); // 🔍 PENTING

    echo json_encode([
        'success' => false,
        'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Gagal update status'
    ]);
}
