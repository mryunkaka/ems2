<?php
/**
 * =========================================================
 * CHECK FARMASI ONLINE — PRODUCTION MODE
 * =========================================================
 */

require __DIR__ . '/../config/database.php';
date_default_timezone_set('Asia/Jakarta');

/* =========================================================
   0️⃣ USER MELEWATI BATAS MAX DUTY → AUTO OFFLINE
   ========================================================= */
$stmtSettings = $pdo->prepare("
    SELECT max_duty_minutes
    FROM farmasi_online_settings
    ORDER BY id ASC
    LIMIT 1
");
$stmtSettings->execute();
$maxDutyMinutes = max(0, (int)$stmtSettings->fetchColumn());

$usersMaxDutyExpired = [];
if ($maxDutyMinutes > 0) {
    $stmtMaxDutyExpired = $pdo->prepare("
        SELECT
            ufs.user_id,
            ur.full_name,
            TIMESTAMPDIFF(SECOND, s.session_start, NOW()) AS current_session_seconds
        FROM user_farmasi_status ufs
        JOIN user_farmasi_sessions s
            ON s.user_id = ufs.user_id
           AND s.session_end IS NULL
        JOIN user_rh ur
            ON ur.id = ufs.user_id
        WHERE ufs.status = 'online'
          AND TIMESTAMPDIFF(SECOND, s.session_start, NOW()) >= ?
    ");
    $stmtMaxDutyExpired->execute([$maxDutyMinutes * 60]);
    $usersMaxDutyExpired = $stmtMaxDutyExpired->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================================
   1️⃣ USER IDLE ≥ 15 MENIT → KIRIM WARNING
   ========================================================= */
$usersIdle = $pdo->query("
    SELECT user_id
    FROM user_farmasi_status
    WHERE status = 'online'
      AND last_activity_at <= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
      AND auto_offline_at IS NULL
")->fetchAll(PDO::FETCH_COLUMN);

$pdo->beginTransaction();

try {
    /* ===============================
       AUTO OFFLINE MAX DUTY
       =============================== */
    if (!empty($usersMaxDutyExpired)) {
        $setOfflineMaxDuty = $pdo->prepare("
            UPDATE user_farmasi_status
            SET status = 'offline',
                auto_offline_at = NOW(),
                current_session_number = 0,
                updated_at = NOW()
            WHERE user_id = ?
              AND status = 'online'
        ");

        $closeSessionMaxDuty = $pdo->prepare("
            UPDATE user_farmasi_sessions
            SET
                session_end = NOW(),
                duration_seconds = TIMESTAMPDIFF(SECOND, session_start, NOW()),
                end_reason = 'auto_offline',
                ended_by_user_id = user_id
            WHERE user_id = ?
              AND session_end IS NULL
        ");

        $insertActivityMaxDuty = $pdo->prepare("
            INSERT INTO farmasi_activities
                (activity_type, medic_user_id, medic_name, description)
            VALUES
                ('auto_offline', ?, ?, ?)
        ");

        $insertInboxMaxDuty = $pdo->prepare("
            INSERT INTO user_inbox
                (user_id, title, message, type, is_read, created_at)
            VALUES
                (?, ?, ?, 'system', 0, NOW())
        ");

        foreach ($usersMaxDutyExpired as $userMaxDuty) {
            $uid = (int)($userMaxDuty['user_id'] ?? 0);
            $fullName = (string)($userMaxDuty['full_name'] ?? 'Medis');

            if ($uid <= 0) {
                continue;
            }

            $setOfflineMaxDuty->execute([$uid]);
            $closeSessionMaxDuty->execute([$uid]);
            $insertActivityMaxDuty->execute([
                $uid,
                $fullName,
                'Auto OFFLINE oleh sistem (maksimal waktu jaga tercapai)'
            ]);
            $insertInboxMaxDuty->execute([
                $uid,
                'Status Anda OFFLINE',
                "Sistem mengubah status Anda menjadi OFFLINE karena batas maksimal waktu jaga telah tercapai.\n\nSilakan ONLINE kembali jika masih bertugas dan cooldown sudah selesai."
            ]);
        }
    }

    /* ===============================
       SET DEADLINE + NOTIF
       =============================== */
    if (!empty($usersIdle)) {

        $setDeadline = $pdo->prepare("
            UPDATE user_farmasi_status
            SET auto_offline_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE)
            WHERE user_id = ?
        ");

        $insertNotif = $pdo->prepare("
            INSERT INTO user_farmasi_notifications
                (user_id, type, message, created_at)
            VALUES
                (?, 'check_online', '⏳ Apakah Anda masih online dan siap melayani farmasi?', NOW())
        ");

        foreach ($usersIdle as $uid) {
            $setDeadline->execute([$uid]);
            $insertNotif->execute([$uid]);
        }
    }

    /* =========================================================
       2️⃣ USER DEADLINE HABIS → AUTO OFFLINE
       ========================================================= */
    $usersAutoOffline = $pdo->query("
        SELECT ufs.user_id, ur.full_name
        FROM user_farmasi_status ufs
        JOIN user_rh ur ON ur.id = ufs.user_id
        WHERE ufs.status = 'online'
          AND ufs.auto_offline_at IS NOT NULL
          AND ufs.auto_offline_at <= NOW()
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($usersAutoOffline)) {

        /* ===============================
           SET OFFLINE
           =============================== */
        $pdo->exec("
            UPDATE user_farmasi_status
            SET status = 'offline',
                updated_at = NOW()
            WHERE status = 'online'
              AND auto_offline_at IS NOT NULL
              AND auto_offline_at <= NOW()
        ");

        /* ===============================
           LOG ACTIVITY + INBOX
           =============================== */
        $insertActivity = $pdo->prepare("
            INSERT INTO farmasi_activities
                (activity_type, medic_user_id, medic_name, description)
            VALUES
                ('offline', ?, ?, ?)
        ");

        $insertInbox = $pdo->prepare("
            INSERT INTO user_inbox
                (user_id, title, message, type, is_read, created_at)
            VALUES
                (?, ?, ?, 'system', 0, NOW())
        ");

        foreach ($usersAutoOffline as $u) {

            $insertActivity->execute([
                (int)$u['user_id'],
                $u['full_name'],
                'Auto OFFLINE oleh sistem (idle)'
            ]);

            $insertInbox->execute([
                (int)$u['user_id'],
                'Status Anda OFFLINE',
                "Sistem mengubah status Anda menjadi OFFLINE karena tidak ada aktivitas.\n\nSilakan ONLINE kembali jika masih bertugas."
            ]);
        }

        /* ===============================
           TUTUP SESSION AKTIF
           =============================== */
        $pdo->exec("
            UPDATE user_farmasi_sessions s
            JOIN user_farmasi_status u ON u.user_id = s.user_id
            SET
                s.session_end = NOW(),
                s.duration_seconds = TIMESTAMPDIFF(SECOND, s.session_start, NOW()),
                s.end_reason = 'auto_offline',
                s.ended_by_user_id = s.user_id
            WHERE s.session_end IS NULL
              AND u.status = 'offline'
        ");

        /* ===============================
           HAPUS NOTIF CHECK ONLINE
           =============================== */
        $pdo->exec("
            DELETE n
            FROM user_farmasi_notifications n
            JOIN user_farmasi_status s ON s.user_id = n.user_id
            WHERE s.status = 'offline'
        ");
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

/* =========================================================
   3️⃣ PUSH NOTIFICATION (SETELAH COMMIT)
   ========================================================= */

/* ---------- PUSH WARNING ---------- */
if (!empty($usersIdle)) {

    $stmt = $pdo->prepare("
        SELECT id AS user_id, full_name
        FROM user_rh
        WHERE id = ?
    ");

    $PUSH_USERS = [];

    foreach ($usersIdle as $uid) {
        $stmt->execute([$uid]);
        if ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $PUSH_USERS[] = $u;
        }
    }

    if ($PUSH_USERS) {
        $PUSH_TYPE = 'idle_warning';
        require __DIR__ . '/../actions/push_send.php';
    }
}

/* ---------- PUSH OFFLINE ---------- */
if (!empty($usersAutoOffline) || !empty($usersMaxDutyExpired)) {
    $PUSH_USERS = array_values(array_map(static function ($user) {
        return [
            'user_id' => (int)($user['user_id'] ?? 0),
            'full_name' => (string)($user['full_name'] ?? 'Medis'),
        ];
    }, array_merge($usersAutoOffline, $usersMaxDutyExpired)));
    $PUSH_TYPE  = 'offline';
    require __DIR__ . '/../actions/push_send.php';
}

echo 'CRON OK';
