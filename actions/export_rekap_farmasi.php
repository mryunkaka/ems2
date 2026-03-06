<?php

session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/date_range.php';

$user = $_SESSION['user_rh'] ?? [];

$medicName = $user['name'] ?? '';
$export    = $_GET['export'] ?? '';
$showAll   = isset($_GET['show_all']) && $_GET['show_all'] === '1';

if ($export !== 'medic' && $export !== 'all') {
    exit('Export tidak valid');
}

/* =============================
   AMBIL DATA SESUAI FILTER
   ============================= */
$sql = "
    SELECT created_at, consumer_name, medic_name, medic_jabatan, package_name, price
    FROM sales
    WHERE created_at BETWEEN :start AND :end
";

$params = [
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
];

if (!$showAll && $export === 'medic' && $medicName !== '') {
    $sql .= " AND medic_name = :mname";
    $params[':mname'] = $medicName;
}

$sql .= " ORDER BY created_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =============================
   HEADER EXCEL
   ============================= */
$fileName = 'rekap_farmasi_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Pragma: no-cache');
header('Expires: 0');

/* =============================
   OUTPUT TABLE
   ============================= */
echo "<table border='1'>";
echo "<tr>
        <th>Waktu</th>
        <th>Nama Konsumen</th>
        <th>Nama Medis</th>
        <th>Jabatan</th>
        <th>Paket</th>
        <th>Harga</th>
      </tr>";

foreach ($rows as $r) {
    echo "<tr>";
    echo "<td>" . date('d-m-Y H:i', strtotime($r['created_at'])) . "</td>";
    echo "<td>" . htmlspecialchars($r['consumer_name']) . "</td>";
    echo "<td>" . htmlspecialchars($r['medic_name']) . "</td>";
    echo "<td>" . htmlspecialchars($r['medic_jabatan']) . "</td>";
    echo "<td>" . htmlspecialchars($r['package_name']) . "</td>";
    echo "<td>" . (int)$r['price'] . "</td>";
    echo "</tr>";
}

echo "</table>";
exit;
