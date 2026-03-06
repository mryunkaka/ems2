<?php
$now = new DateTime('now');

// ===============================
// RANGE VALID
// ===============================
$validRanges = [
    'today',
    'yesterday',
    'last7',
    'week1',
    'week2',
    'week3',
    'week4',
    'custom'
];

// ===============================
// DEFAULT = MINGGU BERJALAN (week4)
// ===============================
$range = $_GET['range'] ?? 'week4';
if (!in_array($range, $validRanges, true)) {
    $range = 'week4';
}

// ===============================
// AMBIL SENIN MINGGU SAAT INI
// ===============================
$mondayThisWeek = clone $now;
$mondayThisWeek->modify('monday this week')->setTime(0, 0, 0);

// ===============================
// HITUNG 4 MINGGU ROLLING
// ===============================
$weeks = [
    'week1' => [
        'start' => (clone $mondayThisWeek)->modify('-3 week'),
        'end'   => (clone $mondayThisWeek)->modify('-3 week +6 days')->setTime(23, 59, 59),
    ],
    'week2' => [
        'start' => (clone $mondayThisWeek)->modify('-2 week'),
        'end'   => (clone $mondayThisWeek)->modify('-2 week +6 days')->setTime(23, 59, 59),
    ],
    'week3' => [
        'start' => (clone $mondayThisWeek)->modify('-1 week'),
        'end'   => (clone $mondayThisWeek)->modify('-1 week +6 days')->setTime(23, 59, 59),
    ],
    'week4' => [
        'start' => (clone $mondayThisWeek),
        'end'   => (clone $mondayThisWeek)->modify('+6 days')->setTime(23, 59, 59),
    ],
];

// ===============================
// SWITCH RANGE
// ===============================
switch ($range) {

    case 'today':
        $startDT = (clone $now)->setTime(0, 0, 0);
        $endDT   = (clone $now)->setTime(23, 59, 59);
        break;

    case 'yesterday':
        $startDT = (clone $now)->modify('-1 day')->setTime(0, 0, 0);
        $endDT   = (clone $now)->modify('-1 day')->setTime(23, 59, 59);
        break;

    case 'last7':
        $startDT = (clone $now)->modify('-6 days')->setTime(0, 0, 0);
        $endDT   = (clone $now)->setTime(23, 59, 59);
        break;

    case 'week1':
    case 'week2':
    case 'week3':
    case 'week4':
        $startDT = $weeks[$range]['start'];
        $endDT   = $weeks[$range]['end'];
        break;

    case 'custom':
        $from = $_GET['from'] ?? '';
        $to   = $_GET['to'] ?? '';
        if ($from && $to) {
            $startDT = new DateTime($from . ' 00:00:00');
            $endDT   = new DateTime($to . ' 23:59:59');
        } else {
            $startDT = $weeks['week4']['start'];
            $endDT   = $weeks['week4']['end'];
        }
        break;
}

// ===============================
// OUTPUT FINAL
// ===============================
$rangeStart = $startDT->format('Y-m-d H:i:s');
$rangeEnd   = $endDT->format('Y-m-d H:i:s');

$rangeLabel = $startDT->format('d M Y') . ' – ' . $endDT->format('d M Y');
