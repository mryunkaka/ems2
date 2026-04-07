<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function recruitmentFormatJoinDuration(?string $tanggalMasuk): string
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
        if ($diff->y > 0) {
            return $diff->y . ' tahun' . ($diff->m > 0 ? ' ' . $diff->m . ' bulan' : '');
        }

        if ($diff->m > 0) {
            return $diff->m . ' bulan' . ($diff->d > 0 ? ' ' . $diff->d . ' hari' : '');
        }

        $days = (int)$diff->days;
        if ($days >= 7) {
            $weeks = intdiv($days, 7);
            $remainingDays = $days % 7;
            return $weeks . ' minggu' . ($remainingDays > 0 ? ' ' . $remainingDays . ' hari' : '');
        }

        if ($days >= 1) {
            return $days . ' hari';
        }

        $hours = ((int)$diff->h) + ($days * 24);
        return max(1, $hours) . ' jam';
    } catch (Throwable $e) {
        return '-';
    }
}

function recruitmentFormatAverageDuration(int $seconds): string
{
    if ($seconds <= 0) {
        return '-';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours <= 0) {
        return 'Rata-rata ' . max(1, $minutes) . ' menit per hari';
    }

    if ($minutes <= 0) {
        return 'Rata-rata ' . $hours . ' jam per hari';
    }

    return 'Rata-rata ' . $hours . ' jam ' . $minutes . ' menit per hari';
}

function recruitmentBuildOnlineSummary(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT
            session_start,
            COALESCE(session_end, DATE_ADD(session_start, INTERVAL duration_seconds SECOND)) AS effective_end,
            CASE
                WHEN session_end IS NULL THEN TIMESTAMPDIFF(SECOND, session_start, NOW())
                ELSE duration_seconds
            END AS effective_duration_seconds
        FROM user_farmasi_sessions
        WHERE user_id = ?
        ORDER BY session_start DESC
        LIMIT 30
    ");
    $stmt->execute([$userId]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($sessions === []) {
        return [
            'online_schedule' => '-',
            'duty_duration' => '-',
            'active_day_count' => 0,
        ];
    }

    $dailyTotals = [];
    $dailyBuckets = [];
    $bucketRanges = [];
    $buckets = [
        'siang' => ['label' => 'Siang', 'start' => 11 * 60, 'end' => 15 * 60],
        'sore' => ['label' => 'Sore', 'start' => 15 * 60, 'end' => 18 * 60],
        'malam' => ['label' => 'Malam', 'start' => 18 * 60, 'end' => 24 * 60],
        'subuh' => ['label' => 'Subuh', 'start' => 0, 'end' => 5 * 60],
        'pagi' => ['label' => 'Pagi', 'start' => 5 * 60, 'end' => 11 * 60],
    ];
    $formatClock = static function (int $minute): string {
        $minute = (($minute % 1440) + 1440) % 1440;
        return str_pad((string)intdiv($minute, 60), 2, '0', STR_PAD_LEFT)
            . ':'
            . str_pad((string)($minute % 60), 2, '0', STR_PAD_LEFT);
    };

    foreach ($sessions as $session) {
        $startRaw = (string)($session['session_start'] ?? '');
        $endRaw = (string)($session['effective_end'] ?? '');
        $duration = (int)($session['effective_duration_seconds'] ?? 0);
        if ($startRaw === '' || $endRaw === '' || $duration <= 0) {
            continue;
        }

        try {
            $start = new DateTime($startRaw);
            $end = new DateTime($endRaw);
        } catch (Throwable $e) {
            continue;
        }

        if ($end <= $start) {
            continue;
        }

        $cursor = clone $start;
        while ($cursor < $end) {
            $dayKey = $cursor->format('Y-m-d');
            $nextDay = (clone $cursor)->setTime(0, 0, 0)->modify('+1 day');
            $segmentEnd = $end < $nextDay ? clone $end : $nextDay;
            $segmentSeconds = max(0, $segmentEnd->getTimestamp() - $cursor->getTimestamp());
            if ($segmentSeconds > 0) {
                $dailyTotals[$dayKey] = ($dailyTotals[$dayKey] ?? 0) + $segmentSeconds;
            }

            foreach ($buckets as $bucketKey => $bucket) {
                $bucketStart = (clone $cursor)->setTime(0, 0, 0)->modify('+' . $bucket['start'] . ' minutes');
                $bucketEnd = (clone $cursor)->setTime(0, 0, 0)->modify('+' . $bucket['end'] . ' minutes');
                $overlapStart = max($cursor->getTimestamp(), $bucketStart->getTimestamp());
                $overlapEnd = min($segmentEnd->getTimestamp(), $bucketEnd->getTimestamp());
                if ($overlapEnd > $overlapStart) {
                    $dailyBuckets[$dayKey][$bucketKey] = ($dailyBuckets[$dayKey][$bucketKey] ?? 0) + ($overlapEnd - $overlapStart);
                    $overlapStartMinute = ((int)date('H', $overlapStart) * 60) + (int)date('i', $overlapStart);
                    $overlapEndMinute = ((int)date('H', $overlapEnd) * 60) + (int)date('i', $overlapEnd);
                    if (!isset($bucketRanges[$bucketKey][$dayKey])) {
                        $bucketRanges[$bucketKey][$dayKey] = [
                            'start' => $overlapStartMinute,
                            'end' => $overlapEndMinute,
                        ];
                    } else {
                        $bucketRanges[$bucketKey][$dayKey]['start'] = min($bucketRanges[$bucketKey][$dayKey]['start'], $overlapStartMinute);
                        $bucketRanges[$bucketKey][$dayKey]['end'] = max($bucketRanges[$bucketKey][$dayKey]['end'], $overlapEndMinute);
                    }
                }
            }

            $cursor = $segmentEnd;
        }
    }

    $activeDayCount = count($dailyTotals);
    if ($activeDayCount <= 0) {
        return [
            'online_schedule' => '-',
            'duty_duration' => '-',
            'active_day_count' => 0,
        ];
    }

    $bucketLines = [];
    foreach ($buckets as $bucketKey => $bucket) {
        $rangeRows = $bucketRanges[$bucketKey] ?? [];
        if ($rangeRows === []) {
            $bucketLines[] = $bucket['label'] . ' jam -';
            continue;
        }

        $startSum = 0;
        $endSum = 0;
        $rangeCount = 0;
        foreach ($rangeRows as $range) {
            $startSum += (int)$range['start'];
            $endSum += (int)$range['end'];
            $rangeCount++;
        }

        if ($rangeCount <= 0) {
            $bucketLines[] = $bucket['label'] . ' jam -';
            continue;
        }

        $averageStart = (int)round($startSum / $rangeCount);
        $averageEnd = (int)round($endSum / $rangeCount);
        if ($averageEnd <= $averageStart) {
            $bucketLines[] = $bucket['label'] . ' jam -';
            continue;
        }

        $bucketLines[] = $bucket['label'] . ' jam ' . $formatClock($averageStart) . '-' . $formatClock($averageEnd);
    }

    $totalDuration = array_sum($dailyTotals);

    return [
        'online_schedule' => implode("\n", $bucketLines),
        'duty_duration' => recruitmentFormatAverageDuration((int)round($totalDuration / $activeDayCount)),
        'active_day_count' => $activeDayCount,
    ];
}

$query = strtoupper(trim((string)($_GET['q'] ?? '')));
if ($query === '') {
    echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        citizen_id,
        no_hp_ic,
        jenis_kelamin,
        division,
        role,
        position,
        batch,
        tanggal_masuk,
        file_ktp,
        file_skb,
        file_sim,
        file_kta
    FROM user_rh
    WHERE citizen_id LIKE ?
    ORDER BY citizen_id ASC
    LIMIT 10
");
$stmt->execute([$query . '%']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$items = [];
foreach ($rows as $row) {
    $documents = [
        'ktp_ic' => (string)($row['file_ktp'] ?? ''),
        'skb' => (string)($row['file_skb'] ?? ''),
        'sim' => (string)($row['file_sim'] ?? ''),
        'kta' => (string)($row['file_kta'] ?? ''),
    ];
    $missingDocuments = [];
    foreach ([
        'ktp_ic' => 'KTP',
        'skb' => 'SKB',
        'kta' => 'KTA',
        'sim' => 'SIM',
    ] as $key => $label) {
        if (trim($documents[$key]) === '') {
            $missingDocuments[] = $label;
        }
    }

    $onlineSummary = recruitmentBuildOnlineSummary($pdo, (int)($row['id'] ?? 0));

    $items[] = [
        'id' => (int)($row['id'] ?? 0),
        'citizen_id' => (string)($row['citizen_id'] ?? ''),
        'full_name' => (string)($row['full_name'] ?? ''),
        'no_hp_ic' => (string)($row['no_hp_ic'] ?? ''),
        'jenis_kelamin' => (string)($row['jenis_kelamin'] ?? ''),
        'division' => (string)($row['division'] ?? ''),
        'role' => (string)($row['role'] ?? ''),
        'position' => (string)($row['position'] ?? ''),
        'batch' => isset($row['batch']) && $row['batch'] !== null && $row['batch'] !== '' ? 'Batch ' . (int)$row['batch'] : 'Tanpa Batch',
        'tanggal_masuk' => (string)($row['tanggal_masuk'] ?? ''),
        'city_duration' => recruitmentFormatJoinDuration($row['tanggal_masuk'] ?? null),
        'online_schedule' => $onlineSummary['online_schedule'],
        'duty_duration' => $onlineSummary['duty_duration'],
        'active_day_count' => (int)$onlineSummary['active_day_count'],
        'documents' => $documents,
        'missing_documents' => $missingDocuments,
        'documents_complete' => $missingDocuments === [],
        'settings_url' => '/dashboard/setting_akun.php',
    ];
}

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
