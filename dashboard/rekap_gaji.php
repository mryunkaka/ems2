<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../config/helpers.php';

$pageTitle = 'Rekap Gaji Mingguan';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// =======================
// QUERY REKAP GAJI
// =======================
$stmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT medic_name) AS total_medis,
        SUM(total_transaksi) AS total_transaksi,
        SUM(total_item) AS total_item,
        SUM(total_rupiah) AS total_rupiah,
        SUM(bonus_40) AS total_bonus
    FROM salary
    WHERE period_end BETWEEN :start AND :end
");
$stmt->execute([
    ':start' => $rangeStart,
    ':end'   => $rangeEnd,
]);
$rekap = $stmt->fetch(PDO::FETCH_ASSOC);
