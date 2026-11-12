<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/position_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/duty_hours_api.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ems_require_not_trainee_html('Laporan Absensi');
if (ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []) !== 'roxwood') { http_response_code(403); exit('Laporan absensi ini hanya untuk unit Roxwood.'); }
$type = (string) ($_GET['type'] ?? 'monthly');
if (!in_array($type, ['daily', 'weekly', 'monthly', 'custom'], true)) $type = 'daily';
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$start = trim((string) ($_GET['start'] ?? ($type === 'monthly' ? $monthStart : $today)));
$end = trim((string) ($_GET['end'] ?? ($type === 'monthly' ? $monthEnd : $today)));
$period = match ($type) { 'weekly' => '1_week', default => 'custom_range' };
if (($type === 'daily' && !ems_duty_hours_date($start)) || ($type === 'custom' && (!ems_duty_hours_date($start) || !ems_duty_hours_date($end) || $start > $end))) { http_response_code(400); exit('Rentang tanggal tidak valid.'); }
if ($type === 'daily') $end = $start;
if ($type === 'monthly') { $start = $monthStart; $end = $monthEnd; }
try { $report = ems_duty_hours_request($pdo, $period, $start, $end); } catch (Throwable $e) { http_response_code(502); exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')); }
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Laporan Absensi');
$sheet->mergeCells('A1:G1'); $sheet->setCellValue('A1', 'LAPORAN ABSENSI MEDIS ROXWOOD');
$sheet->mergeCells('A2:G2'); $sheet->setCellValue('A2', 'Periode: ' . (($report['date_range']['from'] ?? '') . ' — ' . ($report['date_range']['to'] ?? '')));
$sheet->mergeCells('A3:G3'); $sheet->setCellValue('A3', 'Jumlah medis: ' . count($report['rows']) . ' | Diekspor: ' . date('d/m/Y H:i'));
$sheet->fromArray(['Rank', 'Nama Medis', 'Citizen ID', 'Jabatan', 'Total Jam', 'Total Sesi', 'Duty Terakhir'], null, 'A5');
$sheet->getStyle('A1:G1')->applyFromArray(['font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '0F766E']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
$sheet->getStyle('A2:G3')->applyFromArray(['font' => ['color' => ['rgb' => '64748B']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
$sheet->getStyle('A5:G5')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D9488']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER], 'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '99F6E4']]]]);
foreach ($report['rows'] as $i => $row) {
    $staff = $row['registered_staff'];
    $rowNumber = $i + 6;
    $sheet->fromArray([[$i + 1, (string) ($staff['full_name'] ?? $row['name'] ?? '-'), (string) ($staff['citizen_id'] ?? '-'), ems_position_label($staff['position'] ?? $row['role'] ?? '-'), (string) ($row['formatted_duty_time'] ?? '0 Jam 0 Menit'), (int) ($row['total_sessions'] ?? 0), (string) ($row['last_duty_at'] ?? '-')]], null, 'A' . $rowNumber);
    if ($i < 3 && (int) ($row['total_seconds'] ?? 0) > 0) {
        $rankColors = ['FEF3C7', 'E2E8F0', 'FFEDD5'];
        $sheet->getStyle('A' . $rowNumber . ':G' . $rowNumber)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rankColors[$i]);
    }
}
$lastRow = count($report['rows']) + 5;
$sheet->getStyle('A5:G' . max(5, $lastRow))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->freezePane('A6'); $sheet->setAutoFilter('A5:G' . max(5, $lastRow));
foreach (['A' => 8, 'B' => 28, 'C' => 16, 'D' => 25, 'E' => 20, 'F' => 14, 'G' => 22] as $column => $width) $sheet->getColumnDimension($column)->setWidth($width);
$filename = 'laporan-absensi-roxwood-' . $type . '-' . date('Ymd-His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
