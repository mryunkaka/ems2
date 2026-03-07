<?php
// =====================================================
// DASHBOARD DATA PROVIDER (MEDIS ONLY)
// =====================================================

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../config/helpers.php';

function ems_fetch_one(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$paramsRange = [
    ':start' => ($rangeStart instanceof DateTime) ? $rangeStart->format('Y-m-d H:i:s') : $rangeStart,
    ':end'   => ($rangeEnd instanceof DateTime) ? $rangeEnd->format('Y-m-d H:i:s') : $rangeEnd,
];

$rekapMedis = ems_fetch_one($pdo, "
    SELECT
        SUM(UPPER(medicine_usage) LIKE '%P3K%')      AS total_p3k,
        SUM(UPPER(medicine_usage) LIKE '%BANDAGE%')  AS total_bandage,
        SUM(UPPER(medicine_usage) LIKE '%GAUZE%')    AS total_gauze,
        SUM(UPPER(medicine_usage) LIKE '%IODINE%')   AS total_iodine,
        SUM(UPPER(medicine_usage) LIKE '%SYRINGE%')  AS total_syringe,

        SUM(operasi_tingkat = 'plastik') AS operasi_plastik,
        SUM(operasi_tingkat = 'ringan')  AS operasi_ringan,
        SUM(operasi_tingkat = 'berat')   AS operasi_berat
    FROM ems_sales
    WHERE created_at BETWEEN :start AND :end
", $paramsRange);

$dashboard = [
    'rekap_medis' => [
        'p3k'            => (int)($rekapMedis['total_p3k'] ?? 0),
        'bandage'        => (int)($rekapMedis['total_bandage'] ?? 0),
        'gauze'          => (int)($rekapMedis['total_gauze'] ?? 0),
        'iodine'         => (int)($rekapMedis['total_iodine'] ?? 0),
        'syringe'        => (int)($rekapMedis['total_syringe'] ?? 0),
        'operasi_plastik'=> (int)($rekapMedis['operasi_plastik'] ?? 0),
        'operasi_ringan' => (int)($rekapMedis['operasi_ringan'] ?? 0),
        'operasi_berat'  => (int)($rekapMedis['operasi_berat'] ?? 0),
    ],
    // Keep keys for view compatibility if reused elsewhere.
    'chart_weekly' => ['labels' => [], 'values' => []],
    'weekly_winner' => [],
];

