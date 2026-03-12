<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

function activityTableExists(PDO $pdo, string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$tableName]);

    return $cache[$tableName] = ((int)$stmt->fetchColumn() > 0);
}

function activityColumnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);

    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function addActivityRow(array &$activities, array $row): void
{
    $createdAt = (string)($row['created_at'] ?? '');
    $timestamp = strtotime($createdAt);

    if ($timestamp <= 0) {
        return;
    }

    $source = (string)($row['source'] ?? 'activity');
    $rawId = (string)($row['raw_id'] ?? md5($source . '|' . $createdAt . '|' . ($row['description'] ?? '')));

    $activities[] = [
        'id' => $source . '-' . $rawId,
        'type' => (string)($row['type'] ?? 'generic'),
        'medic_name' => trim((string)($row['medic_name'] ?? 'System')) ?: 'System',
        'description' => trim((string)($row['description'] ?? 'Aktivitas terbaru')),
        'timestamp' => $timestamp,
        'created_at' => $createdAt,
    ];
}

function formatActivityTimeAgo(int $timestamp): string
{
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'Baru saja';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' menit lalu';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam lalu';
    }

    return date('d M H:i', $timestamp);
}

try {
    $maxItems = 30;
    $activities = [];

    if (activityTableExists($pdo, 'farmasi_activities')) {
        $stmt = $pdo->query("
            SELECT
                id AS raw_id,
                'farmasi' AS source,
                activity_type AS type,
                medic_name,
                description,
                created_at
            FROM farmasi_activities
            ORDER BY created_at DESC
            LIMIT 30
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            addActivityRow($activities, $row);
        }
    }

    if (activityTableExists($pdo, 'cuti_requests')) {
        $stmt = $pdo->query("
            SELECT
                cr.id AS raw_id,
                'cuti_request' AS source,
                'leave_request' AS type,
                u.full_name AS medic_name,
                CONCAT(
                    'Mengajukan cuti ',
                    COALESCE(cr.request_code, CONCAT('#', cr.id)),
                    ' (',
                    DATE_FORMAT(cr.start_date, '%d %b'),
                    ' - ',
                    DATE_FORMAT(cr.end_date, '%d %b'),
                    ')'
                ) AS description,
                cr.created_at
            FROM cuti_requests cr
            INNER JOIN user_rh u ON u.id = cr.user_id
            WHERE cr.status = 'pending'
            ORDER BY cr.created_at DESC
            LIMIT 15
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            addActivityRow($activities, $row);
        }

        $stmt = $pdo->query("
            SELECT
                cr.id AS raw_id,
                'cuti_review' AS source,
                CASE
                    WHEN cr.status = 'approved' THEN 'leave_approved'
                    ELSE 'leave_rejected'
                END AS type,
                u.full_name AS medic_name,
                cr.request_code,
                cr.status,
                cr.start_date,
                cr.end_date,
                COALESCE(cr.approved_at, cr.updated_at, cr.created_at) AS created_at
            FROM cuti_requests cr
            INNER JOIN user_rh u ON u.id = cr.user_id
            WHERE cr.status IN ('approved', 'rejected')
            ORDER BY COALESCE(cr.approved_at, cr.updated_at, cr.created_at) DESC
            LIMIT 15
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['description'] = 'Pengajuan cuti ' .
                (($row['status'] ?? '') === 'approved' ? 'disetujui ' : 'ditolak ') .
                ($row['request_code'] ?? ('#' . ($row['raw_id'] ?? ''))) .
                ' (' .
                date('d M', strtotime((string)($row['start_date'] ?? 'now'))) .
                ' - ' .
                date('d M', strtotime((string)($row['end_date'] ?? 'now'))) .
                ')';
            addActivityRow($activities, $row);
        }
    }

    if (
        activityTableExists($pdo, 'user_rh') &&
        activityColumnExists($pdo, 'user_rh', 'cuti_status') &&
        activityColumnExists($pdo, 'user_rh', 'cuti_approved_at')
    ) {
        $stmt = $pdo->query("
            SELECT
                id AS raw_id,
                'user_on_leave' AS source,
                'on_leave' AS type,
                full_name AS medic_name,
                CONCAT(
                    'Sedang cuti',
                    CASE
                        WHEN cuti_start_date IS NOT NULL AND cuti_end_date IS NOT NULL THEN
                            CONCAT(
                                ' ',
                                DATE_FORMAT(cuti_start_date, '%d %b'),
                                ' - ',
                                DATE_FORMAT(cuti_end_date, '%d %b')
                            )
                        ELSE ''
                    END
                ) AS description,
                cuti_approved_at AS created_at
            FROM user_rh
            WHERE cuti_status = 'active'
              AND cuti_approved_at IS NOT NULL
            ORDER BY cuti_approved_at DESC
            LIMIT 15
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            addActivityRow($activities, $row);
        }
    }

    if (activityTableExists($pdo, 'resign_requests')) {
        $stmt = $pdo->query("
            SELECT
                rr.id AS raw_id,
                'resign_request' AS source,
                'resign_request' AS type,
                u.full_name AS medic_name,
                CONCAT(
                    'Mengajukan resign ',
                    COALESCE(rr.request_code, CONCAT('#', rr.id))
                ) AS description,
                rr.created_at
            FROM resign_requests rr
            INNER JOIN user_rh u ON u.id = rr.user_id
            WHERE rr.status = 'pending'
            ORDER BY rr.created_at DESC
            LIMIT 15
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            addActivityRow($activities, $row);
        }

        $stmt = $pdo->query("
            SELECT
                rr.id AS raw_id,
                'resign_approved' AS source,
                'resign' AS type,
                u.full_name AS medic_name,
                CONCAT(
                    'Resign disetujui ',
                    COALESCE(rr.request_code, CONCAT('#', rr.id))
                ) AS description,
                rr.approved_at AS created_at
            FROM resign_requests rr
            INNER JOIN user_rh u ON u.id = rr.user_id
            WHERE rr.status = 'approved'
              AND rr.approved_at IS NOT NULL
            ORDER BY rr.approved_at DESC
            LIMIT 15
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            addActivityRow($activities, $row);
        }
    }

    if (activityTableExists($pdo, 'position_promotion_requests')) {
        $stmt = $pdo->query("
            SELECT
                r.id AS raw_id,
                'promotion_request' AS source,
                'promotion_request' AS type,
                u.full_name AS medic_name,
                r.from_position,
                r.to_position,
                r.submitted_at AS created_at
            FROM position_promotion_requests r
            INNER JOIN user_rh u ON u.id = r.user_id
            WHERE r.status = 'pending'
            ORDER BY r.submitted_at DESC
            LIMIT 15
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['description'] = 'Mengajukan kenaikan jabatan ' .
                ems_position_label($row['from_position'] ?? '') .
                ' -> ' .
                ems_position_label($row['to_position'] ?? '');
            addActivityRow($activities, $row);
        }

        $stmt = $pdo->query("
            SELECT
                r.id AS raw_id,
                'promotion_review' AS source,
                CASE
                    WHEN r.status = 'approved' THEN 'promotion_approved'
                    ELSE 'promotion_rejected'
                END AS type,
                u.full_name AS medic_name,
                r.from_position,
                r.to_position,
                r.status,
                r.reviewed_at AS created_at
            FROM position_promotion_requests r
            INNER JOIN user_rh u ON u.id = r.user_id
            WHERE r.status IN ('approved', 'rejected')
              AND r.reviewed_at IS NOT NULL
            ORDER BY r.reviewed_at DESC
            LIMIT 15
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['description'] = 'Pengajuan jabatan ' .
                ems_position_label($row['from_position'] ?? '') .
                ' -> ' .
                ems_position_label($row['to_position'] ?? '') .
                (($row['status'] ?? '') === 'approved' ? ' disetujui' : ' ditolak');
            addActivityRow($activities, $row);
        }
    }

    if (activityTableExists($pdo, 'medical_records')) {
        $stmt = $pdo->query("
            SELECT
                mr.id AS raw_id,
                'medical_record' AS source,
                'medical_record' AS type,
                COALESCE(cb.full_name, 'Medis') AS medic_name,
                CONCAT(
                    'Input rekam medis pasien ',
                    mr.patient_name,
                    CASE
                        WHEN dpjp.full_name IS NOT NULL THEN CONCAT(' (DPJP: ', dpjp.full_name, ')')
                        ELSE ''
                    END
                ) AS description,
                mr.created_at
            FROM medical_records mr
            LEFT JOIN user_rh cb ON cb.id = mr.created_by
            LEFT JOIN user_rh dpjp ON dpjp.id = mr.doctor_id
            ORDER BY mr.created_at DESC
            LIMIT 15
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            addActivityRow($activities, $row);
        }
    }

    if (activityTableExists($pdo, 'ems_sales')) {
        $stmt = $pdo->query("
            SELECT
                id AS raw_id,
                'ems_service' AS source,
                'medical_service' AS type,
                medic_name,
                CONCAT(
                    'Input layanan ',
                    service_type,
                    CASE
                        WHEN service_detail IS NOT NULL AND service_detail <> '' THEN CONCAT(' - ', service_detail)
                        ELSE ''
                    END,
                    CASE
                        WHEN patient_name IS NOT NULL AND patient_name <> '' THEN CONCAT(' untuk ', patient_name)
                        ELSE ''
                    END
                ) AS description,
                created_at
            FROM ems_sales
            ORDER BY created_at DESC
            LIMIT 20
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            addActivityRow($activities, $row);
        }
    }

    if (activityTableExists($pdo, 'user_rh')) {
        $stmt = $pdo->query("
            SELECT
                id AS raw_id,
                'account_created' AS source,
                'account_created' AS type,
                full_name AS medic_name,
                position,
                role,
                batch,
                created_at
            FROM user_rh
            WHERE created_at IS NOT NULL
            ORDER BY created_at DESC
            LIMIT 20
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parts = ['Akun baru dibuat'];

            $meta = [];
            if (!empty($row['position'])) {
                $meta[] = ems_position_label((string)$row['position']);
            }
            if (!empty($row['role'])) {
                $meta[] = ems_role_label((string)$row['role']);
            }
            if (!empty($row['batch'])) {
                $meta[] = 'Batch ' . (int)$row['batch'];
            }

            if (!empty($meta)) {
                $parts[] = '(' . implode(', ', $meta) . ')';
            }

            $row['description'] = implode(' ', $parts);
            addActivityRow($activities, $row);
        }
    }

    if (activityTableExists($pdo, 'medical_applicants')) {
        $stmt = $pdo->query("
            SELECT
                id AS raw_id,
                'applicant_new' AS source,
                'applicant_new' AS type,
                ic_name AS medic_name,
                'Pendaftar baru mengisi form rekrutmen' AS description,
                created_at
            FROM medical_applicants
            ORDER BY created_at DESC
            LIMIT 20
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            addActivityRow($activities, $row);
        }
    }

    if (
        activityTableExists($pdo, 'applicant_final_decisions') &&
        activityTableExists($pdo, 'medical_applicants')
    ) {
        $stmt = $pdo->query("
            SELECT
                afd.id AS raw_id,
                'applicant_final' AS source,
                CASE
                    WHEN afd.final_result = 'lolos' THEN 'candidate_accepted'
                    ELSE 'candidate_rejected'
                END AS type,
                ma.ic_name AS medic_name,
                CONCAT(
                    'Keputusan akhir kandidat: ',
                    CASE
                        WHEN afd.final_result = 'lolos' THEN 'diterima'
                        ELSE 'ditolak'
                    END
                ) AS description,
                afd.decided_at AS created_at
            FROM applicant_final_decisions afd
            INNER JOIN medical_applicants ma ON ma.id = afd.applicant_id
            WHERE afd.decided_at IS NOT NULL
            ORDER BY afd.decided_at DESC
            LIMIT 20
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            addActivityRow($activities, $row);
        }
    }

    usort($activities, static function (array $a, array $b): int {
        if ($a['timestamp'] === $b['timestamp']) {
            return strcmp($b['id'], $a['id']);
        }
        return $b['timestamp'] <=> $a['timestamp'];
    });

    $selectedActivities = [];
    $overflowActivities = [];
    $sourceCaps = [
        'farmasi' => 10,
        'ems_service' => 6,
    ];
    $sourceCounts = [];

    foreach ($activities as $activity) {
        $source = strstr((string)$activity['id'], '-', true) ?: 'activity';
        $sourceCounts[$source] = $sourceCounts[$source] ?? 0;
        $cap = $sourceCaps[$source] ?? 2;

        if ($sourceCounts[$source] < $cap && count($selectedActivities) < $maxItems) {
            $selectedActivities[] = $activity;
            $sourceCounts[$source]++;
            continue;
        }

        $overflowActivities[] = $activity;
    }

    foreach ($overflowActivities as $activity) {
        if (count($selectedActivities) >= $maxItems) {
            break;
        }
        $selectedActivities[] = $activity;
    }

    $activities = $selectedActivities;

    usort($activities, static function (array $a, array $b): int {
        if ($a['timestamp'] === $b['timestamp']) {
            return strcmp($b['id'], $a['id']);
        }
        return $b['timestamp'] <=> $a['timestamp'];
    });

    foreach ($activities as &$activity) {
        $activity['time_ago'] = formatActivityTimeAgo((int)$activity['timestamp']);
        unset($activity['created_at']);
    }
    unset($activity);

    ems_json_response($activities);
} catch (Throwable $e) {
    error_log('[get_activities] ' . $e->getMessage());
    ems_json_error('Failed to load activities', 503, ['items' => []]);
}
