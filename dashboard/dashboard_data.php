<?php
// =====================================================
// DASHBOARD DATA PROVIDER (FINAL)
// =====================================================

// Timezone WIB
date_default_timezone_set('Asia/Jakarta');

// Session & Security (jika ada auth_guard)
// require_once __DIR__ . '/../auth/auth_guard.php';

// Database
require_once __DIR__ . '/../config/database.php';

// Date range system (LOCKED)
require_once __DIR__ . '/../config/date_range.php';
// menghasilkan: $rangeStart, $rangeEnd, $weeks

require_once __DIR__ . '/../config/helpers.php';

// =====================================================
// NORMALIZE DateTime → STRING (PDO SAFE)
// =====================================================
function dt($value)
{
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d H:i:s');
    }
    return $value;
}

// =====================================================
// HELPER
// =====================================================
function fetchOne(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// =====================================================
// GLOBAL RANGE (ACTIVE)
// =====================================================
$paramsRange = [
    ':start' => dt($rangeStart),
    ':end'   => dt($rangeEnd)
];

// =====================================================
// STATISTIC CARDS (FARMASI + ITEM + PAKET)
// =====================================================
$statFarmasi = fetchOne($pdo, "
    SELECT
        COUNT(DISTINCT medic_name) AS total_medic,
        COUNT(DISTINCT TRIM(UPPER(consumer_name))) AS total_consumer,
        COUNT(id) AS total_transaksi,

        -- Total item
        SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,

        -- Total per item
        SUM(qty_bandage)    AS total_bandage,
        SUM(qty_painkiller) AS total_painkiller,
        SUM(qty_ifaks)      AS total_ifaks,

        -- Total paket terjual
        SUM(package_name = 'Paket A') AS total_paket_a,
        SUM(package_name = 'Paket B') AS total_paket_b,

        -- Keuangan
        SUM(price) AS total_income,
        SUM(price * 0.4) AS total_bonus,
        SUM(price * 0.6) AS company_profit
    FROM sales
    WHERE created_at BETWEEN :start AND :end
", $paramsRange);

// =====================================================
// REKAP MEDIS
// =====================================================
$rekapMedis = fetchOne($pdo, "
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

// =====================================================
// WEEKLY WINNER (WEEK 1 - 4)
// =====================================================
$weeklyWinner = [];
$chartWeekly  = [
    'labels' => [],
    'values' => []
];

foreach ($weeks as $key => $w) {

    $labelTanggal = formatTanggalIndo($w['start']) . ' - ' . formatTanggalIndo($w['end']);

    $weeklyIncome = fetchOne($pdo, "
        SELECT SUM(price) AS total
        FROM sales
        WHERE created_at BETWEEN :ws AND :we
    ", [
        ':ws' => dt($w['start']),
        ':we' => dt($w['end'])
    ]);

    $totalIncomeWeek = (float)($weeklyIncome['total'] ?? 0);

    $winner = fetchOne($pdo, "
        SELECT medic_name, SUM(price) AS total
        FROM sales
        WHERE created_at BETWEEN :ws AND :we
        GROUP BY medic_name
        ORDER BY SUM(price) DESC
        LIMIT 1
    ", [
        ':ws' => dt($w['start']),
        ':we' => dt($w['end'])
    ]);

    $totalSales = (float)($winner['total'] ?? 0);

    $weeklyWinner[$labelTanggal] = [
        'medic'       => $winner['medic_name'] ?? '-',
        'total_sales' => $totalSales,
        'bonus_40'    => $totalSales * 0.4
    ];

    $chartWeekly['labels'][] = $labelTanggal;
    $chartWeekly['values'][] = $totalIncomeWeek;
}

// =====================================================
// MONTHLY WINNER
// =====================================================
$currentMonthStart = date('Y-m-01 00:00:00');
$currentMonthEnd   = date('Y-m-t 23:59:59');

$lastMonthStart = date('Y-m-01 00:00:00', strtotime('-1 month'));
$lastMonthEnd   = date('Y-m-t 23:59:59', strtotime('-1 month'));

$monthlyCurrent = fetchOne($pdo, "
    SELECT medic_name, SUM(price) AS total
    FROM sales
    WHERE created_at BETWEEN :s AND :e
    GROUP BY medic_name
    ORDER BY total DESC
    LIMIT 1
", [
    ':s' => $currentMonthStart,
    ':e' => $currentMonthEnd
]);

$monthlyLast = fetchOne($pdo, "
    SELECT medic_name, SUM(price) AS total
    FROM sales
    WHERE created_at BETWEEN :s AND :e
    GROUP BY medic_name
    ORDER BY total DESC
    LIMIT 1
", [
    ':s' => $lastMonthStart,
    ':e' => $lastMonthEnd
]);

// =====================================================
// TOP EARNING MEDIC (BONUS TERBESAR)
// =====================================================
$topEarning = fetchOne($pdo, "
    SELECT medic_name, SUM(price * 0.4) AS bonus
    FROM sales
    WHERE created_at BETWEEN :start AND :end
    GROUP BY medic_name
    ORDER BY bonus DESC
    LIMIT 1
", $paramsRange);

// =====================================================
// FINAL DASHBOARD ARRAY (VIEW ONLY)
// =====================================================

$totalMonthlyCurrent = (float)($monthlyCurrent['total'] ?? 0);
$totalMonthlyLast    = (float)($monthlyLast['total'] ?? 0);

$dashboard = [

    // FARMASI
    'total_medic'     => (int)$statFarmasi['total_medic'],
    'total_consumer'  => (int)$statFarmasi['total_consumer'],
    'total_transaksi' => (int)$statFarmasi['total_transaksi'],
    'total_item'      => (int)$statFarmasi['total_item'],
    'total_income'    => (float)$statFarmasi['total_income'],
    'total_bonus'     => (float)$statFarmasi['total_bonus'],
    'company_profit'  => (float)$statFarmasi['company_profit'],
    'total_bandage'    => (int)$statFarmasi['total_bandage'],
    'total_painkiller' => (int)$statFarmasi['total_painkiller'],
    'total_ifaks'      => (int)$statFarmasi['total_ifaks'],

    'total_paket_a' => (int)$statFarmasi['total_paket_a'],
    'total_paket_b' => (int)$statFarmasi['total_paket_b'],

    // MEDIS
    'rekap_medis' => [
        'p3k'             => (int)($rekapMedis['total_p3k'] ?? 0),
        'bandage'         => (int)($rekapMedis['total_bandage'] ?? 0),
        'gauze'           => (int)($rekapMedis['total_gauze'] ?? 0),
        'iodine'          => (int)($rekapMedis['total_iodine'] ?? 0),
        'syringe'         => (int)($rekapMedis['total_syringe'] ?? 0),
        'operasi_plastik' => (int)($rekapMedis['operasi_plastik'] ?? 0),
        'operasi_ringan'  => (int)($rekapMedis['operasi_ringan'] ?? 0),
        'operasi_berat'   => (int)($rekapMedis['operasi_berat'] ?? 0),
    ],

    // Weekly Ranking
    'weekly_winner' => $weeklyWinner,

    // Monthly Winner
    'monthly_current' => [
        'medic'    => $monthlyCurrent['medic_name'] ?? '-',
        'bonus_40' => $totalMonthlyCurrent * 0.4
    ],

    'monthly_last' => [
        'medic'    => $monthlyLast['medic_name'] ?? '-',
        'bonus_40' => $totalMonthlyLast * 0.4
    ],

    // Top Earning Medic
    'top_earning' => [
        'medic' => $topEarning['medic_name'] ?? '-',
        'bonus' => (float)($topEarning['bonus'] ?? 0)
    ],

    // Charts
    'chart_weekly' => $chartWeekly
];
