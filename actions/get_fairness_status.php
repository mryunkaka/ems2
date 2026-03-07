<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

try {
    $userId = (int)($_SESSION['user_rh']['id'] ?? 0);
    $threshold = 10;

    if ($userId <= 0) {
        ems_json_response([
            'blocked' => false,
            'selisih' => 0,
            'threshold' => $threshold,
            'user_status' => 'offline',
        ]);
    }

    $stmtSelf = $pdo->prepare("
        SELECT status
        FROM user_farmasi_status
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmtSelf->execute([$userId]);
    $selfStatus = $stmtSelf->fetchColumn() ?: 'offline';

    $stmt = $pdo->query("
        SELECT
            ufs.user_id,
            ur.full_name AS medic_name,
            ur.position AS medic_jabatan,
            COUNT(s.id) AS total_transaksi
        FROM user_farmasi_status ufs
        JOIN user_rh ur ON ur.id = ufs.user_id
        LEFT JOIN sales s
            ON s.medic_user_id = ufs.user_id
           AND DATE(s.created_at) = CURDATE()
        GROUP BY ufs.user_id, ur.full_name, ur.position
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $onlineRows = [];

    foreach ($rows as $row) {
        $stmtStatus = $pdo->prepare("
            SELECT status
            FROM user_farmasi_status
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmtStatus->execute([$row['user_id']]);

        if ($stmtStatus->fetchColumn() === 'online') {
            $onlineRows[] = $row;
        }
    }

    if (count($onlineRows) < 2) {
        ems_json_response([
            'blocked' => false,
            'selisih' => 0,
            'threshold' => $threshold,
            'user_status' => $selfStatus,
        ]);
    }

    usort($onlineRows, function ($left, $right) {
        return (int)$left['total_transaksi'] <=> (int)$right['total_transaksi'];
    });

    $lowestOnline = $onlineRows[0];
    $current = null;

    foreach ($onlineRows as $row) {
        if ((int)$row['user_id'] === $userId) {
            $current = $row;
            break;
        }
    }

    if (!$current) {
        ems_json_response([
            'blocked' => false,
            'selisih' => 0,
            'threshold' => $threshold,
            'user_status' => $selfStatus,
        ]);
    }

    $diff = (int)$current['total_transaksi'] - (int)$lowestOnline['total_transaksi'];
    $response = [
        'blocked' => false,
        'selisih' => max(0, $diff),
        'threshold' => $threshold,
        'user_status' => $selfStatus,
    ];

    if ($diff >= $threshold && (int)$lowestOnline['user_id'] !== $userId) {
        $response['blocked'] = true;
        $response['medic_name'] = $lowestOnline['medic_name'];
        $response['medic_jabatan'] = $lowestOnline['medic_jabatan'];
        $response['total_transaksi'] = (int)$lowestOnline['total_transaksi'];
    }

    ems_json_response($response);
} catch (Throwable $e) {
    error_log('[get_fairness_status] ' . $e->getMessage());
    ems_json_error('Failed to load fairness status', 503, [
        'blocked' => false,
        'selisih' => 0,
        'threshold' => 10,
        'user_status' => 'offline',
    ]);
}
