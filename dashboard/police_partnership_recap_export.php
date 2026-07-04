<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

if (!isset($_GET['range'])) {
    $_GET['range'] = 'week3';
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/date_range.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/police_partnership.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'police_partnership_recap_export.php', '/dashboard/index.php');
policePartnershipEnsureTable($pdo);

$user = $_SESSION['user_rh'] ?? [];
if (!ems_is_manager_plus_role($user['role'] ?? '')) {
    header('Location: police_partnership.php');
    exit;
}

$effectiveUnit = ems_effective_unit($pdo, $user);

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_input,
        COUNT(badge_file_path) AS total_badge,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM police_partnership_records
    WHERE DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) BETWEEN :start AND :end
      AND unit_code = :unit_code
");
$summaryStmt->execute([
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
    ':unit_code' => $effectiveUnit,
]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$rowsStmt = $pdo->prepare("
    SELECT
        COALESCE(input_by_user_id, 0) AS input_by_user_id,
        input_by_name,
        COUNT(*) AS total_input,
        COUNT(badge_file_path) AS total_badges,
        COALESCE(SUM(amount), 0) AS total_amount,
        MIN(payment_status) AS min_payment_status,
        MAX(payment_status) AS max_payment_status,
        MAX(paid_at) AS paid_at,
        MAX(paid_by) AS paid_by,
        MAX(pricing_mode) AS pricing_mode
    FROM police_partnership_records
    WHERE DATE(COALESCE(service_at, CONCAT(service_date, ' 00:00:00'))) BETWEEN :start AND :end
      AND unit_code = :unit_code
    GROUP BY COALESCE(input_by_user_id, 0), input_by_name
    ORDER BY total_amount DESC, total_input DESC, input_by_name ASC
");
$rowsStmt->execute([
    ':start' => $rangeStart,
    ':end' => $rangeEnd,
    ':unit_code' => $effectiveUnit,
]);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Rekap Police');
$sheet->freezePane('A8');
$sheet->getSheetView()->setZoomScale(90);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$sheet->setCellValue('A1', 'REKAP KERJA SAMA POLICE');
$sheet->mergeCells('A1:G1');
$sheet->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '0F766E']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sheet->setCellValue('A2', 'Unit: ' . ems_unit_label($effectiveUnit) . ' | Periode: ' . ($rangeLabel ?? '-'));
$sheet->mergeCells('A2:G2');
$sheet->getStyle('A2')->applyFromArray([
    'font' => ['size' => 11, 'color' => ['rgb' => '64748B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sheet->setCellValue('A4', 'Jumlah Input');
$sheet->setCellValue('B4', (int)($summary['total_input'] ?? 0));
$sheet->setCellValue('D4', 'Total Foto Badge');
$sheet->setCellValue('E4', (int)($summary['total_badge'] ?? 0));
$sheet->setCellValue('F4', 'Total Nilai');
$sheet->setCellValue('G4', (int)($summary['total_amount'] ?? 0));
$sheet->getStyle('A4:G4')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'ECFEFF']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BAE6FD']]],
]);

$headers = ['No', 'Nama Medis', 'Total Input', 'Total Foto Badge', 'Hasil Diterima', 'Status', 'Dibayar Oleh'];
$sheet->fromArray($headers, null, 'A7');
$sheet->getStyle('A7:G7')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D9488']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0F766E']]],
]);

$rowNum = 8;
foreach ($rows as $index => $row) {
    $isPaid = (string)($row['min_payment_status'] ?? '') === 'paid'
        && (string)($row['max_payment_status'] ?? '') === 'paid';
    $sheet->setCellValue('A' . $rowNum, $index + 1);
    $sheet->setCellValue('B' . $rowNum, (string)($row['input_by_name'] ?? ''));
    $sheet->setCellValue('C' . $rowNum, (int)($row['total_input'] ?? 0));
    $sheet->setCellValue('D' . $rowNum, (int)($row['total_badges'] ?? 0));
    $sheet->setCellValue('E' . $rowNum, (int)($row['total_amount'] ?? 0));
    $sheet->setCellValue('F' . $rowNum, $isPaid ? 'Dibayar' : 'Pending');
    $sheet->setCellValue('G' . $rowNum, (string)($row['paid_by'] ?? '-'));
    $rowNum++;
}

$lastRow = max(8, $rowNum - 1);
if ($rowNum === 8) {
    $sheet->setCellValue('A8', 'Tidak ada data pada periode ini.');
    $sheet->mergeCells('A8:G8');
    $sheet->getStyle('A8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

$sheet->getStyle('A8:G' . $lastRow)->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
]);
$sheet->getStyle('A8:A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('G4')->getNumberFormat()->setFormatCode('"$"#,##0');
$sheet->getStyle('E8:E' . $lastRow)->getNumberFormat()->setFormatCode('"$"#,##0');

foreach (range('A', 'G') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}
$sheet->getColumnDimension('B')->setWidth(22);
$sheet->getColumnDimension('E')->setWidth(26);
$sheet->getColumnDimension('G')->setWidth(22);

$filename = 'rekap-police-' . ems_normalize_unit_code($effectiveUnit) . '-' . date('Ymd-His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
