<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

function autoOfflineExpiredMedics(PDO $pdo, string $effectiveUnit, bool $userHasUnitCode): void
{
    $stmtSettings = $pdo->prepare("
        SELECT max_duty_minutes
        FROM farmasi_online_settings
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmtSettings->execute();
    $maxDutyMinutes = max(0, (int)$stmtSettings->fetchColumn());

    if ($maxDutyMinutes <= 0) {
        return;
    }

    $sql = "
        SELECT
            ufs.user_id,
            ur.full_name,
            TIMESTAMPDIFF(SECOND, ufses.session_start, NOW()) AS current_session_seconds
        FROM user_farmasi_status ufs
        JOIN user_farmasi_sessions ufses
            ON ufses.user_id = ufs.user_id
           AND ufses.session_end IS NULL
        JOIN user_rh ur
            ON ur.id = ufs.user_id
        WHERE ufs.status = 'online'
    ";

    $params = [];
    if ($userHasUnitCode) {
        $sql .= " AND COALESCE(ur.unit_code, 'roxwood') = :user_unit_code";
        $params[':user_unit_code'] = $effectiveUnit;
    }

    $stmtExpired = $pdo->prepare($sql);
    $stmtExpired->execute($params);
    $expiredRows = $stmtExpired->fetchAll(PDO::FETCH_ASSOC);

    $expiredUsers = [];
    foreach ($expiredRows as $row) {
        $currentSessionSeconds = (int)($row['current_session_seconds'] ?? 0);
        if ($currentSessionSeconds >= ($maxDutyMinutes * 60)) {
            $expiredUsers[] = [
                'user_id' => (int)($row['user_id'] ?? 0),
                'full_name' => (string)($row['full_name'] ?? 'Medis'),
            ];
        }
    }

    if ($expiredUsers === []) {
        return;
    }

    $pdo->beginTransaction();

    try {
        $updateStatusStmt = $pdo->prepare("
            UPDATE user_farmasi_status
            SET status = 'offline',
                auto_offline_at = NOW(),
                current_session_number = 0,
                updated_at = NOW()
            WHERE user_id = ?
        ");

        $closeSessionStmt = $pdo->prepare("
            UPDATE user_farmasi_sessions
            SET session_end = NOW(),
                duration_seconds = TIMESTAMPDIFF(SECOND, session_start, NOW()),
                end_reason = 'auto_offline'
            WHERE user_id = ?
              AND session_end IS NULL
        ");

        $logActivityStmt = $pdo->prepare("
            INSERT INTO farmasi_activities
                (activity_type, medic_user_id, medic_name, description)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($expiredUsers as $expiredUser) {
            $userId = (int)$expiredUser['user_id'];
            if ($userId <= 0) {
                continue;
            }

            $updateStatusStmt->execute([$userId]);
            $closeSessionStmt->execute([$userId]);
            $logActivityStmt->execute([
                'auto_offline',
                $userId,
                $expiredUser['full_name'],
                'Auto offline: Mencapai batas waktu jaga maksimal'
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function onlineMedicsJoinDurationText(?string $tanggalMasuk): string
{
    if (empty($tanggalMasuk)) {
        return '-';
    }

    try {
        $start = new DateTime((string)$tanggalMasuk);
        $now = new DateTime();
        if ($start > $now) {
            return '-';
        }

        $diff = $start->diff($now);
        $months = ((int)$diff->y * 12) + (int)$diff->m;
        $days = (int)$diff->d;
        $totalDays = (int)$diff->days;
        $hours = ((int)$diff->h) + ($totalDays * 24);

        if ($months >= 1) {
            return $months . ' bulan ' . $days . ' hari';
        }

        if ($totalDays >= 1) {
            return $totalDays . ' hari';
        }

        return $hours . ' jam';
    } catch (Throwable $e) {
        return '-';
    }
}

try {
    $effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
    $salesHasUnitCode = ems_column_exists($pdo, 'sales', 'unit_code');
    $userHasUnitCode = ems_column_exists($pdo, 'user_rh', 'unit_code');

    autoOfflineExpiredMedics($pdo, $effectiveUnit, $userHasUnitCode);

    $sql = "
        SELECT
            ufs.user_id,
            ur.full_name AS medic_name,
            ur.position AS medic_jabatan,
            ur.role AS medic_role,
            ur.division AS medic_division,
            ur.batch AS medic_batch,
            ur.tanggal_masuk,
            COUNT(s.id) AS total_transaksi,
            COALESCE(SUM(s.price), 0) AS total_pendapatan,
            FLOOR(COALESCE(SUM(s.price), 0) * 0.4) AS bonus_40,
            (
                SELECT COUNT(*)
                FROM sales
                WHERE medic_user_id = ufs.user_id
                " . ($salesHasUnitCode ? " AND unit_code = :unit_code_sub_all" : "") . "
            ) AS total_transaksi_semua,
            (
                SELECT COUNT(*)
                FROM sales
                WHERE medic_user_id = ufs.user_id
                  " . ($salesHasUnitCode ? " AND unit_code = :unit_code_sub_week" : "") . "
                  AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00') - INTERVAL (WEEKDAY(NOW())) DAY
                  AND created_at < DATE_FORMAT(NOW(), '%Y-%m-%d 23:59:59') + INTERVAL (6 - WEEKDAY(NOW())) DAY
            ) AS weekly_transaksi,
            (
                SELECT COALESCE(TIMESTAMPDIFF(SECOND, session_start, NOW()), 0)
                FROM user_farmasi_sessions
                WHERE user_id = ufs.user_id
                  AND session_end IS NULL
                ORDER BY session_start DESC
                LIMIT 1
            ) AS current_online_seconds
        FROM user_farmasi_status ufs
        JOIN user_rh ur
            ON ur.id = ufs.user_id
        LEFT JOIN sales s
            ON s.medic_user_id = ufs.user_id
           AND DATE(s.created_at) = CURDATE()
           " . ($salesHasUnitCode ? " AND s.unit_code = :unit_code_join" : "") . "
        WHERE ufs.status = 'online'
          " . ($userHasUnitCode ? " AND COALESCE(ur.unit_code, 'roxwood') = :user_unit_code" : "") . "
        GROUP BY ufs.user_id, ur.full_name, ur.position, ur.role, ur.division, ur.batch, ur.tanggal_masuk
        ORDER BY total_transaksi ASC, total_pendapatan ASC
    ";

    $stmt = $pdo->prepare($sql);
    $params = [];
    if ($salesHasUnitCode) {
        $params[':unit_code_sub_all'] = $effectiveUnit;
        $params[':unit_code_sub_week'] = $effectiveUnit;
        $params[':unit_code_join'] = $effectiveUnit;
    }
    if ($userHasUnitCode) {
        $params[':user_unit_code'] = $effectiveUnit;
    }
    $stmt->execute($params);

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($data as &$row) {
        $sec = (int)($row['current_online_seconds'] ?? 0);
        $jam = floor($sec / 3600);
        $menit = floor(($sec % 3600) / 60);
        $detik = $sec % 60;
        $row['weekly_transaksi'] = (int)($row['weekly_transaksi'] ?? 0);
        $row['current_online_seconds'] = $sec;
        $row['current_online_text'] = "{$jam}j {$menit}m {$detik}d";
        $row['join_duration_text'] = onlineMedicsJoinDurationText($row['tanggal_masuk'] ?? null);
        $row['medic_role_label'] = ems_role_label($row['medic_role'] ?? '');
        $row['medic_division_label'] = ems_normalize_division($row['medic_division'] ?? '') ?: '-';
        $row['medic_position_label'] = ems_position_label($row['medic_jabatan'] ?? '');
    }
    unset($row);

    ems_json_response($data);
} catch (Throwable $e) {
    error_log('[get_online_medics] ' . $e->getMessage());
    ems_json_error('Failed to load online medics', 503, ['items' => []]);
}
