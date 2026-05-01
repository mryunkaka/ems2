<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__ . '/../config/date_range.php';

$startDate = $_GET['from'] ?? '';
$endDate   = $_GET['to'] ?? '';
$range     = $_GET['range'] ?? 'week4';

$sql = "
    SELECT
        rc.*,
        u1.full_name AS created_by_name,
        u2.full_name AS approved_by_name,
        u3.full_name AS paid_by_name
    FROM restaurant_consumptions rc
    LEFT JOIN user_rh u1 ON u1.id = rc.created_by
    LEFT JOIN user_rh u2 ON u2.id = rc.approved_by
    LEFT JOIN user_rh u3 ON u3.id = rc.paid_by
    WHERE 1=1
";

$params = [];

if ($range !== 'custom') {
    $sql .= " AND DATE(rc.delivery_date) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $rangeStart;
    $params[':end_date']   = $rangeEnd;
} elseif ($startDate && $endDate) {
    $sql .= " AND DATE(rc.delivery_date) BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $startDate;
    $params[':end_date']   = $endDate;
}

$sql .= " ORDER BY rc.delivery_date DESC, rc.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sqlTotal = "
    SELECT
        COUNT(*) AS total_record,
        SUM(packet_count) AS total_packets,
        SUM(subtotal) AS total_subtotal,
        SUM(tax_amount) AS total_tax,
        SUM(total_amount) AS total_grand
    FROM restaurant_consumptions rc
    WHERE 1=1
";

$paramsTotal = [];

if ($range !== 'custom') {
    $sqlTotal .= " AND DATE(rc.delivery_date) BETWEEN :start_date AND :end_date";
    $paramsTotal[':start_date'] = $rangeStart;
    $paramsTotal[':end_date']   = $rangeEnd;
} elseif ($startDate && $endDate) {
    $sqlTotal .= " AND DATE(rc.delivery_date) BETWEEN :start_date AND :end_date";
    $paramsTotal[':start_date'] = $startDate;
    $paramsTotal[':end_date']   = $endDate;
}

$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute($paramsTotal);
$stats = $stmtTotal->fetch(PDO::FETCH_ASSOC);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Konsumsi Restoran');
$sheet->freezePane('A4');
$sheet->setSelectedCell('A1');
$sheet->getSheetView()->setZoomScale(80);
$sheet->getSheetView()->setZoomScaleNormal(80);
$sheet->getDefaultColumnDimension()->setWidth(16);
$spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(false);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$dateRangeLabel = $rangeLabel ?? '-';
if ($range === 'custom' && $startDate && $endDate) {
    $dateRangeLabel = date('d M Y', strtotime($startDate)) . ' - ' . date('d M Y', strtotime($endDate));
}

$sheet->setCellValue('A1', 'LAPORAN KONSUMSI RESTORAN');
$sheet->mergeCells('A1:M1');
$sheet->getStyle('A1')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 16,
        'color' => ['rgb' => '0F766E'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->setCellValue('A2', 'Periode: ' . $dateRangeLabel);
$sheet->mergeCells('A2:M2');
$sheet->getStyle('A2')->applyFromArray([
    'font' => [
        'size' => 11,
        'color' => ['rgb' => '64748B'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->getRowDimension(1)->setRowHeight(28);
$sheet->getRowDimension(2)->setRowHeight(20);
$sheet->getRowDimension(3)->setRowHeight(8);

$headers = [
    'No',
    'Kode',
    'Tanggal',
    'Jam',
    'Restoran',
    'Penerima',
    'Paket',
    'Harga/Paket',
    'Subtotal',
    'Pajak (%)',
    'Pajak ($)',
    'Total',
    'Status',
];

$sheet->fromArray($headers, null, 'A4');

$headerRange = 'A4:M4';
$sheet->getStyle($headerRange)->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '0D9488'],
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '0F766E'],
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);
$sheet->getRowDimension(4)->setRowHeight(26);

$currentRow = 5;
$daysIndonesian = [
    'Monday'    => 'Senin',
    'Tuesday'   => 'Selasa',
    'Wednesday' => 'Rabu',
    'Thursday'  => 'Kamis',
    'Friday'    => 'Jumat',
    'Saturday'  => 'Sabtu',
    'Sunday'    => 'Minggu',
];

foreach ($rows as $index => $r) {
    $dayEnglish = date('l', strtotime($r['delivery_date']));
    $dayIndo = $daysIndonesian[$dayEnglish] ?? $dayEnglish;
    $dateFormatted = date('d M Y', strtotime($r['delivery_date']));

    $sheet->fromArray([
        $index + 1,
        $r['consumption_code'] ?? '',
        $dayIndo . ', ' . $dateFormatted,
        date('H:i', strtotime($r['delivery_time'] ?? '00:00')),
        $r['restaurant_name'] ?? '',
        $r['recipient_name'] ?? '',
        (int)($r['packet_count'] ?? 0),
        (float)($r['price_per_packet'] ?? 0),
        (float)($r['subtotal'] ?? 0),
        (float)($r['tax_percentage'] ?? 0),
        (float)($r['tax_amount'] ?? 0),
        (float)($r['total_amount'] ?? 0),
        strtoupper($r['status'] ?? 'PENDING'),
    ], null, 'A' . $currentRow);

    $rowRange = 'A' . $currentRow . ':M' . $currentRow;
    $sheet->getStyle($rowRange)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'E2E8F0'],
            ],
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    $status = strtolower($r['status'] ?? 'pending');
    if ($status === 'paid') {
        $sheet->getStyle($rowRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D1FAE5'],
            ],
        ]);
    } elseif ($status === 'approved') {
        $sheet->getStyle($rowRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'DBEAFE'],
            ],
        ]);
    } elseif ($status === 'pending') {
        $sheet->getStyle($rowRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEF3C7'],
            ],
        ]);
    }

    $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('C' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('D' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('E' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('F' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('G' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('H' . $currentRow . ':L' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('H' . $currentRow . ':L' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('M' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $currentRow++;
}

$summaryStartRow = $currentRow + 1;

$sheet->setCellValue('A' . $summaryStartRow, 'RINGKASAN');
$sheet->mergeCells('A' . $summaryStartRow . ':C' . $summaryStartRow);
$sheet->getStyle('A' . $summaryStartRow)->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 12,
        'color' => ['rgb' => '0F766E'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_LEFT,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$sheet->setCellValue('A' . ($summaryStartRow + 1), 'Total Record');
$sheet->setCellValue('B' . ($summaryStartRow + 1), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 1), (int)($stats['total_record'] ?? 0));

$sheet->setCellValue('A' . ($summaryStartRow + 2), 'Total Paket');
$sheet->setCellValue('B' . ($summaryStartRow + 2), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 2), (int)($stats['total_packets'] ?? 0));

$sheet->setCellValue('A' . ($summaryStartRow + 3), 'Subtotal');
$sheet->setCellValue('B' . ($summaryStartRow + 3), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 3), (float)($stats['total_subtotal'] ?? 0));
$sheet->getStyle('C' . ($summaryStartRow + 3))->getNumberFormat()->setFormatCode('#,##0');

$sheet->setCellValue('A' . ($summaryStartRow + 4), 'Total Pajak');
$sheet->setCellValue('B' . ($summaryStartRow + 4), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 4), (float)($stats['total_tax'] ?? 0));
$sheet->getStyle('C' . ($summaryStartRow + 4))->getNumberFormat()->setFormatCode('#,##0');

$sheet->setCellValue('A' . ($summaryStartRow + 5), 'GRAND TOTAL');
$sheet->setCellValue('B' . ($summaryStartRow + 5), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 5), (float)($stats['total_grand'] ?? 0));
$sheet->getStyle('A' . ($summaryStartRow + 5) . ':C' . ($summaryStartRow + 5))->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 12,
        'color' => ['rgb' => '047857'],
    ],
]);
$sheet->getStyle('C' . ($summaryStartRow + 5))->getNumberFormat()->setFormatCode('#,##0');

$summaryRange = 'A' . ($summaryStartRow + 1) . ':C' . ($summaryStartRow + 5);
$sheet->getStyle($summaryRange)->applyFromArray([
    'borders' => [
        'outline' => [
            'borderStyle' => Border::BORDER_MEDIUM,
            'color' => ['rgb' => '0D9488'],
        ],
    ],
]);

$sheet->getStyle('A' . ($summaryStartRow + 1) . ':A' . ($summaryStartRow + 5))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getStyle('B' . ($summaryStartRow + 1) . ':B' . ($summaryStartRow + 5))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('C' . ($summaryStartRow + 1) . ':C' . ($summaryStartRow + 5))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(18);
$sheet->getColumnDimension('C')->setWidth(22);
$sheet->getColumnDimension('D')->setWidth(8);
$sheet->getColumnDimension('E')->setWidth(20);
$sheet->getColumnDimension('F')->setWidth(20);
$sheet->getColumnDimension('G')->setWidth(10);
$sheet->getColumnDimension('H')->setWidth(14);
$sheet->getColumnDimension('I')->setWidth(14);
$sheet->getColumnDimension('J')->setWidth(12);
$sheet->getColumnDimension('K')->setWidth(14);
$sheet->getColumnDimension('L')->setWidth(14);
$sheet->getColumnDimension('M')->setWidth(12);

$filename = 'konsumsi_restoran_' . date('Y-m-d_H-i-s') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
