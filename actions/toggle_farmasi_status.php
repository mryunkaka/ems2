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

    /* =====================================================
       UPDATE STATUS FARMASI (KODE LAMA - TETAP)
       ===================================================== */
    $stmt = $pdo->prepare("
        INSERT INTO user_farmasi_status
            (user_id, status, last_activity_at, last_confirm_at, auto_offline_at)
        VALUES
            (?, ?, NOW(), NOW(), NULL)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            last_confirm_at = NOW(),
            auto_offline_at = NULL,
            updated_at = NOW()
    ");
    $stmt->execute([$userId, $status]);

    /* =====================================================
       🔵 JIKA ONLINE → BUAT SESSION BARU
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

            $insertSession = $pdo->prepare("
                INSERT INTO user_farmasi_sessions
                    (user_id, medic_name, medic_jabatan, session_start)
                VALUES
                    (?, ?, ?, NOW())
            ");
            $insertSession->execute([
                $userId,
                $user['full_name'],
                $user['position']
            ]);
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
        'message' => 'Gagal update status'
    ]);
}
