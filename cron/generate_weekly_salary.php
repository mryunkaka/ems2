<?php
require __DIR__ . '/../config/database.php';
date_default_timezone_set('Asia/Jakarta');

/**
 * Ambil periode minggu (Senin - Minggu) dari tanggal tertentu
 */
function getWeekPeriod(DateTime $date): array
{
    $start = clone $date;
    $start->modify('monday this week');

    $end = clone $start;
    $end->modify('+6 days');

    return [
        'start' => $start->format('Y-m-d'),
        'end'   => $end->format('Y-m-d'),
    ];
}

/**
 * Ambil tanggal sales paling awal
 */
$firstSale = $pdo->query("
    SELECT MIN(DATE(created_at)) 
    FROM sales
")->fetchColumn();

if (!$firstSale) {
    echo "Tidak ada data sales sama sekali\n";
    exit;
}

$startDate = new DateTime($firstSale);
$startDate->modify('monday this week');

$today = new DateTime();
$today->modify('monday this week');

echo "Backfill salary dari {$startDate->format('Y-m-d')} sampai {$today->format('Y-m-d')}\n\n";

// ===============================
// LOOP SETIAP MINGGU
// ===============================
$now = new DateTime(); // waktu saat ini (real sekarang)

while ($startDate <= $today) {

    $period = getWeekPeriod($startDate);
    $periodStart = $period['start'];
    $periodEnd   = $period['end'];

    $periodEndDate = new DateTime($periodEnd);

    // ⛔️ JIKA MINGGU BELUM SELESAI → SKIP
    if ($periodEndDate >= $now) {
        echo "⏭️  SKIP {$periodStart} - {$periodEnd} (minggu belum selesai)\n";
        $startDate->modify('+7 days');
        continue;
    }

    // === CEK APAKAH SALARY SUDAH ADA
    $check = $pdo->prepare("
        SELECT COUNT(*) 
        FROM salary
        WHERE period_start = ? AND period_end = ?
    ");
    $check->execute([$periodStart, $periodEnd]);

    if ($check->fetchColumn() > 0) {
        echo "⏭️  SKIP {$periodStart} - {$periodEnd} (sudah ada)\n";
        $startDate->modify('+7 days');
        continue;
    }

    // === AMBIL DATA SALES
    $stmt = $pdo->prepare("
        SELECT
            medic_name,
            MAX(medic_jabatan) AS medic_jabatan,
            COUNT(*) AS total_transaksi,
            SUM(qty_bandage + qty_ifaks + qty_painkiller) AS total_item,
            SUM(price) AS total_rupiah
        FROM sales
        WHERE DATE(created_at) BETWEEN :start AND :end
        GROUP BY medic_name
    ");
    $stmt->execute([
        ':start' => $periodStart,
        ':end'   => $periodEnd
    ]);

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$data) {
        echo "⚠️  TIDAK ADA SALES {$periodStart} - {$periodEnd}\n";
        $startDate->modify('+7 days');
        continue;
    }

    // === INSERT SALARY
    $insert = $pdo->prepare("
        INSERT INTO salary
        (medic_name, medic_jabatan, period_start, period_end,
         total_transaksi, total_item, total_rupiah, bonus_40)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($data as $row) {
        $insert->execute([
            $row['medic_name'],
            $row['medic_jabatan'],
            $periodStart,
            $periodEnd,
            $row['total_transaksi'],
            $row['total_item'],
            $row['total_rupiah'],
            floor($row['total_rupiah'] * 0.4)
        ]);
    }

    echo "✅ CREATED {$periodStart} - {$periodEnd}\n";

    $startDate->modify('+7 days');
}

echo "\nSELESAI BACKFILL SALARY\n";
