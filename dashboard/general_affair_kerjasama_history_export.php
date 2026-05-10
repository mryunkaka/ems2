<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/general_affair_cooperation_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ems_require_division_access(['General Affair'], '/dashboard/index.php');

if (!isset($_GET['range'])) {
    $_GET['range'] = 'week4';
}

require_once __DIR__ . '/../config/date_range.php';

$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$selectedInstitutionId = (int)($_GET['institution_id'] ?? 0);

$stmtInstitutions = $pdo->prepare("
    SELECT id, institution_name
    FROM general_affair_cooperations
    WHERE unit_code = :unit_code
    ORDER BY institution_name ASC
");
$stmtInstitutions->execute([':unit_code' => $effectiveUnit]);
$institutions = $stmtInstitutions->fetchAll(PDO::FETCH_ASSOC);

$selectedInstitutionName = 'Semua Instansi';
foreach ($institutions as $institution) {
    if ((int)($institution['id'] ?? 0) === $selectedInstitutionId) {
        $selectedInstitutionName = (string)($institution['institution_name'] ?? 'Semua Instansi');
        break;
    }
}

$rows = gaCooperationFetchFreeClaimRows($pdo, $effectiveUnit, $rangeStart, $rangeEnd, $selectedInstitutionId);
$groupedRows = gaCooperationGroupFreeClaimRows($rows);
$summary = gaCooperationHistorySummary($groupedRows);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('History Paket Gratis');
$sheet->freezePane('A5');
$sheet->setSelectedCell('A1');
$sheet->getSheetView()->setZoomScale(80);
$sheet->getSheetView()->setZoomScaleNormal(80);
$sheet->getDefaultColumnDimension()->setWidth(16);
$spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(false);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$sheet->setCellValue('A1', 'LAPORAN HISTORY PAKET GRATIS KERJASAMA');
$sheet->mergeCells('A1:I1');
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

$sheet->setCellValue('A2', 'Periode: ' . ($rangeLabel ?? '-'));
$sheet->mergeCells('A2:I2');
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

$sheet->setCellValue('A3', 'Instansi: ' . $selectedInstitutionName);
$sheet->mergeCells('A3:I3');
$sheet->getStyle('A3')->applyFromArray([
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
$sheet->getRowDimension(3)->setRowHeight(20);
$sheet->getRowDimension(4)->setRowHeight(8);

$headers = [
    'No',
    'Tanggal',
    'Jam',
    'Instansi',
    'Anggota',
    'Citizen ID',
    'Paket Gratis',
    'Jumlah Paket',
    'Nilai Gratis',
];

$sheet->fromArray($headers, null, 'A5');
$sheet->getStyle('A5:I5')->applyFromArray([
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
$sheet->getRowDimension(5)->setRowHeight(26);

$currentRow = 6;
foreach ($groupedRows as $index => $row) {
    $timestamp = strtotime((string)($row['transaction_at'] ?? '')) ?: 0;

    $sheet->fromArray([
        $index + 1,
        $timestamp > 0 ? date('d M Y', $timestamp) : '-',
        $timestamp > 0 ? date('H:i', $timestamp) : '-',
        $row['institution_name'] ?? '',
        $row['member_name'] ?? '',
        $row['citizen_id'] ?? '',
        $row['package_summary'] ?? '',
        (int)($row['package_count'] ?? 0),
        (float)($row['total_discount_amount'] ?? 0),
    ], null, 'A' . $currentRow);

    $sheet->getStyle('A' . $currentRow . ':I' . $currentRow)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'E2E8F0'],
            ],
        ],
    ]);

    $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $currentRow . ':C' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('H' . $currentRow . ':I' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('I' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');

    if ($index % 2 === 0) {
        $sheet->getStyle('A' . $currentRow . ':I' . $currentRow)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8FAFC'],
            ],
        ]);
    }

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
]);

$sheet->setCellValue('A' . ($summaryStartRow + 1), 'Transaksi Gratis');
$sheet->setCellValue('B' . ($summaryStartRow + 1), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 1), (int)($summary['transactions'] ?? 0));

$sheet->setCellValue('A' . ($summaryStartRow + 2), 'Instansi');
$sheet->setCellValue('B' . ($summaryStartRow + 2), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 2), (int)($summary['institutions'] ?? 0));

$sheet->setCellValue('A' . ($summaryStartRow + 3), 'Anggota Ambil');
$sheet->setCellValue('B' . ($summaryStartRow + 3), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 3), (int)($summary['members'] ?? 0));

$sheet->setCellValue('A' . ($summaryStartRow + 4), 'Total Paket');
$sheet->setCellValue('B' . ($summaryStartRow + 4), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 4), (int)($summary['packages'] ?? 0));

$sheet->setCellValue('A' . ($summaryStartRow + 5), 'TOTAL NILAI GRATIS');
$sheet->setCellValue('B' . ($summaryStartRow + 5), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 5), (float)($summary['discount_total'] ?? 0));
$sheet->getStyle('C' . ($summaryStartRow + 5))->getNumberFormat()->setFormatCode('#,##0');
$sheet->getStyle('A' . ($summaryStartRow + 5) . ':C' . ($summaryStartRow + 5))->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 12,
        'color' => ['rgb' => '047857'],
    ],
]);

$sheet->getStyle('A' . ($summaryStartRow + 1) . ':C' . ($summaryStartRow + 5))->applyFromArray([
    'borders' => [
        'outline' => [
            'borderStyle' => Border::BORDER_MEDIUM,
            'color' => ['rgb' => '0D9488'],
        ],
    ],
]);

$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(14);
$sheet->getColumnDimension('C')->setWidth(10);
$sheet->getColumnDimension('D')->setWidth(28);
$sheet->getColumnDimension('E')->setWidth(22);
$sheet->getColumnDimension('F')->setWidth(18);
$sheet->getColumnDimension('G')->setWidth(40);
$sheet->getColumnDimension('H')->setWidth(14);
$sheet->getColumnDimension('I')->setWidth(16);

$filename = 'history_paket_gratis_kerjasama_' . date('Y-m-d_H-i-s') . '.xlsx';

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
