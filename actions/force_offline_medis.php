<?php
session_start();
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$user = $_SESSION['user_rh'] ?? null;

if (!$user || empty($user['id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$targetId = (int)($data['target_user_id'] ?? 0);
$reason   = trim($data['reason'] ?? '');

if ($targetId <= 0 || strlen($reason) < 5) {
    echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
    exit;
}

try {
    // 🔴 Aktifkan exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    /* ======================================================
       1️⃣ SET STATUS OFFLINE
       ====================================================== */
    $stmt = $pdo->prepare("
        UPDATE user_farmasi_status
        SET status = 'offline',
            auto_offline_at = NOW(),
            updated_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([$targetId]);

    /* ======================================================
       2️⃣ TUTUP SESSION AKTIF
       ====================================================== */
    $stmtSession = $pdo->prepare("
        UPDATE user_farmasi_sessions
        SET
            session_end = NOW(),
            duration_seconds = TIMESTAMPDIFF(SECOND, session_start, NOW()),
            end_reason = 'force_offline',
            ended_by_user_id = ?
        WHERE user_id = ?
          AND session_end IS NULL
        ORDER BY session_start DESC
        LIMIT 1
    ");
    $stmtSession->execute([
        $user['id'],   // admin / supervisor
        $targetId
    ]);

    /* ======================================================
       3️⃣ LOG FORCE OFFLINE
       ====================================================== */
    $stmtLog = $pdo->prepare("
        INSERT INTO user_farmasi_force_logs
            (target_user_id, forced_by_user_id, reason, forced_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmtLog->execute([
        $targetId,
        $user['id'],
        $reason
    ]);

    /* ======================================================
       4️⃣ 🔔 KIRIM INBOX KE USER TARGET (INI INTINYA)
       ====================================================== */
    $title = 'Force Offline';
    $nama     = $user['name'] ?? 'Sistem';
    $jabatan  = strtolower($user['position'] ?? 'staff');
    $role     = strtolower($user['role'] ?? '');

    // Tampilkan role HANYA jika jabatan bukan staff
    $identity = ($jabatan !== 'staff' && $role)
        ? sprintf('<strong>%s</strong> (%s / %s)', $nama, ucfirst($jabatan), ucfirst($role))
        : sprintf('<strong>%s</strong> (%s)', $nama, ucfirst($jabatan));

    $message = sprintf(
        "Anda telah di force offline oleh %s.<br><br><strong>Alasan:</strong><br>%s",
        $identity,
        nl2br(htmlspecialchars($reason))
    );

    require_once __DIR__ . '/../config/inbox_helper.php';

    sendInbox(
        $pdo,
        $targetId,
        $title,
        $message,
        'force_offline'
    );

    /* ======================================================
       COMMIT
       ====================================================== */
    /* ======================================================
       COMMIT
       ====================================================== */
    $pdo->commit();

    /* ======================================================
       📌 LOG ACTIVITY (FORCE OFFLINE)
       ====================================================== */
    try {
        // Ambil nama target yang di-force offline
        $targetStmt = $pdo->prepare("
            SELECT full_name 
            FROM user_rh 
            WHERE id = ? 
            LIMIT 1
        ");
        $targetStmt->execute([$targetId]);
        $targetName = $targetStmt->fetchColumn() ?: 'Medis';

        // Nama yang melakukan force
        $forcedByName = $user['name'] ?? 'Admin';

        $description = sprintf(
            'Di-force offline oleh %s - Alasan: %s',
            $forcedByName,
            mb_substr($reason, 0, 50) // batasi 50 karakter
        );

        $logActivity = $pdo->prepare("
            INSERT INTO farmasi_activities 
                (activity_type, medic_user_id, medic_name, description)
            VALUES (?, ?, ?, ?)
        ");

        $logActivity->execute([
            'force_offline',
            $targetId,
            $targetName,
            $description
        ]);
    } catch (Exception $e) {
        error_log('[ACTIVITY LOG ERROR] ' . $e->getMessage());
    }
    /* ====================================================== */

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[FORCE OFFLINE ERROR] ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Gagal force offline (server error)'
    ]);
}
