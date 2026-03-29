<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/helpers.php';

date_default_timezone_set('Asia/Jakarta');

function getWeekPeriod(DateTime $date): array
{
    $start = clone $date;
    $start->modify('monday this week');

    $end = clone $start;
    $end->modify('+6 days');

    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
    ];
}

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function salaryUniqueIncludesUnit(PDO $pdo): bool
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'salary'
          AND INDEX_NAME = 'uniq_salary'
        ORDER BY SEQ_IN_INDEX
    ");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return in_array('unit_code', $columns, true);
}

$salesHasUnitCode = ems_column_exists($pdo, 'sales', 'unit_code');
$salaryHasUnitCode = ems_column_exists($pdo, 'salary', 'unit_code');
$salaryUniqueHasUnitCode = $salaryHasUnitCode ? salaryUniqueIncludesUnit($pdo) : false;

if ($salaryHasUnitCode && !$salaryUniqueHasUnitCode) {
    out("ERROR: unique key salary masih versi lama dan belum memasukkan unit_code.");
    out("Jalankan dulu SQL terbaru di docs/sql/13_2026-03-29_unit_code_and_user_visibility.sql");
    exit(1);
}

$units = $salesHasUnitCode ? ['roxwood', 'alta'] : ['roxwood'];
$today = new DateTime();
$today->modify('monday this week');
$now = new DateTime();

$lockPath = __DIR__ . '/salary_cli.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    out('Proses generate salary sedang berjalan.');
    exit(1);
}

foreach ($units as $unitCode) {
    $firstSaleSql = "SELECT MIN(DATE(created_at)) FROM sales";
    $firstSaleParams = [];

    if ($salesHasUnitCode) {
        $firstSaleSql .= " WHERE COALESCE(unit_code, 'roxwood') = ?";
        $firstSaleParams[] = $unitCode;
    }

    $stmtFirstSale = $pdo->prepare($firstSaleSql);
    $stmtFirstSale->execute($firstSaleParams);
    $firstSale = $stmtFirstSale->fetchColumn();

    if (!$firstSale) {
        out("Tidak ada data sales untuk unit " . strtoupper($unitCode));
        out('');
        continue;
    }

    $startDate = new DateTime($firstSale);
    $startDate->modify('monday this week');

    out("Backfill salary unit " . strtoupper($unitCode) . " dari {$startDate->format('Y-m-d')} sampai {$today->format('Y-m-d')}");
    out('');

    while ($startDate <= $today) {
        $period = getWeekPeriod($startDate);
        $periodStart = $period['start'];
        $periodEnd = $period['end'];
        $periodEndDate = new DateTime($periodEnd);

        if ($periodEndDate >= $now) {
            out("⏭️  SKIP {$periodStart} - {$periodEnd} [" . strtoupper($unitCode) . "] (minggu belum selesai)");
            $startDate->modify('+7 days');
            continue;
        }

        $checkSql = "
            SELECT COUNT(*)
            FROM salary
            WHERE period_start = ? AND period_end = ?
        ";
        $checkParams = [$periodStart, $periodEnd];

        if ($salaryHasUnitCode) {
            $checkSql .= " AND COALESCE(unit_code, 'roxwood') = ?";
            $checkParams[] = $unitCode;
        }

        $stmtCheck = $pdo->prepare($checkSql);
        $stmtCheck->execute($checkParams);

        if ((int)$stmtCheck->fetchColumn() > 0) {
            out("⏭️  SKIP {$periodStart} - {$periodEnd} [" . strtoupper($unitCode) . "] (sudah ada)");
            $startDate->modify('+7 days');
            continue;
        }

        $salesSql = "
            SELECT
                medic_user_id,
                MAX(medic_name) AS medic_name,
                MAX(medic_jabatan) AS medic_jabatan,
                COUNT(*) AS total_transaksi,
                SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
                SUM(price) AS total_rupiah
            FROM sales
            WHERE DATE(created_at) BETWEEN :start AND :end
        ";
        $salesParams = [
            ':start' => $periodStart,
            ':end' => $periodEnd,
        ];

        if ($salesHasUnitCode) {
            $salesSql .= " AND COALESCE(unit_code, 'roxwood') = :unit_code";
            $salesParams[':unit_code'] = $unitCode;
        }

        $salesSql .= " GROUP BY medic_user_id";

        $stmtSales = $pdo->prepare($salesSql);
        $stmtSales->execute($salesParams);
        $rows = $stmtSales->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            out("⚠️  TIDAK ADA SALES {$periodStart} - {$periodEnd} [" . strtoupper($unitCode) . "]");
            $startDate->modify('+7 days');
            continue;
        }

        $salaryColumns = 'medic_user_id, medic_name, medic_jabatan, period_start, period_end, total_transaksi, total_item, total_rupiah, bonus_40';
        $salaryPlaceholders = '?, ?, ?, ?, ?, ?, ?, ?, ?';
        if ($salaryHasUnitCode) {
            $salaryColumns .= ', unit_code';
            $salaryPlaceholders .= ', ?';
        }

        $stmtInsert = $pdo->prepare("
            INSERT INTO salary ({$salaryColumns})
            VALUES ({$salaryPlaceholders})
        ");

        foreach ($rows as $row) {
            $insertParams = [
                $row['medic_user_id'],
                $row['medic_name'],
                $row['medic_jabatan'],
                $periodStart,
                $periodEnd,
                (int)$row['total_transaksi'],
                (int)$row['total_item'],
                (int)$row['total_rupiah'],
                (int)floor(((int)$row['total_rupiah']) * 0.4),
            ];

            if ($salaryHasUnitCode) {
                $insertParams[] = $unitCode;
            }

            $stmtInsert->execute($insertParams);
        }

        out("✅ CREATED {$periodStart} - {$periodEnd} [" . strtoupper($unitCode) . "]");
        $startDate->modify('+7 days');
    }

    out('');
}

out('SELESAI BACKFILL SALARY');

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
