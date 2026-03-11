<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

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
        return (int)$diff->days . ' hari ' . (int)$diff->h . ' jam ' . (int)$diff->i . ' menit';
    } catch (Throwable $e) {
        return '-';
    }
}

try {
    $stmt = $pdo->query("
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
            ) AS total_transaksi_semua,
            (
                SELECT COUNT(*)
                FROM sales
                WHERE medic_user_id = ufs.user_id
                  AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00') - INTERVAL (WEEKDAY(NOW())) DAY
                  AND created_at < DATE_FORMAT(NOW(), '%Y-%m-%d 23:59:59') + INTERVAL (6 - WEEKDAY(NOW())) DAY
            ) AS weekly_transaksi,
            (
                SELECT COALESCE(
                    SUM(
                        CASE
                            WHEN session_end IS NULL THEN TIMESTAMPDIFF(SECOND, session_start, NOW())
                            ELSE duration_seconds
                        END
                    ),
                    0
                )
                FROM user_farmasi_sessions
                WHERE user_id = ufs.user_id
                  AND session_start >= DATE_FORMAT(NOW(), '%Y-%m-%d 00:00:00') - INTERVAL (WEEKDAY(NOW())) DAY
                  AND session_start < DATE_FORMAT(NOW(), '%Y-%m-%d 23:59:59') + INTERVAL (6 - WEEKDAY(NOW())) DAY
            ) AS weekly_online_seconds
        FROM user_farmasi_status ufs
        JOIN user_rh ur
            ON ur.id = ufs.user_id
        LEFT JOIN sales s
            ON s.medic_user_id = ufs.user_id
           AND DATE(s.created_at) = CURDATE()
        WHERE ufs.status = 'online'
        GROUP BY ufs.user_id, ur.full_name, ur.position, ur.role, ur.division, ur.batch, ur.tanggal_masuk
        ORDER BY total_transaksi ASC, total_pendapatan ASC
    ");

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($data as &$row) {
        $sec = (int)($row['weekly_online_seconds'] ?? 0);
        $jam = floor($sec / 3600);
        $menit = floor(($sec % 3600) / 60);
        $detik = $sec % 60;
        $row['weekly_transaksi'] = (int)($row['weekly_transaksi'] ?? 0);
        $row['weekly_online_seconds'] = $sec;
        $row['weekly_online_text'] = "{$jam}j {$menit}m {$detik}d";
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
