<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/general_affair_cooperation_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/date_range.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function gaInputExportTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $cache[$table] = (bool)$stmt->fetchColumn();
    return $cache[$table];
}

function gaInputExportColumnsReady(PDO $pdo): array
{
    return [
        'document_time' => ems_column_exists($pdo, 'secretary_file_records', 'document_time'),
        'paid_by' => ems_column_exists($pdo, 'secretary_file_records', 'paid_by'),
        'paid_at' => ems_column_exists($pdo, 'secretary_file_records', 'paid_at'),
    ];
}

function gaInputExportParseKeywordMeta(?string $keywords): array
{
    $meta = ['cooperation_id' => 0];
    $parts = array_filter(array_map('trim', explode(',', (string)$keywords)));
    foreach ($parts as $part) {
        if (str_starts_with($part, 'cooperation_id:')) {
            $meta['cooperation_id'] = (int)trim(substr($part, 15));
        }
    }
    return $meta;
}

function gaInputExportCountActiveMembers(PDO $pdo, int $cooperationId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM general_affair_cooperation_members
        WHERE cooperation_id = ?
          AND is_active = 1
    ");
    $stmt->execute([$cooperationId]);
    return (int)$stmt->fetchColumn();
}

function gaInputExportCompactMedicineLines(array $effectiveQtys): string
{
    $labels = [
        'bandage_qty' => 'Bandage',
        'ifaks_qty' => 'Ifaks',
        'painkiller_qty' => 'Painkiller',
    ];

    $parts = [];
    foreach ($labels as $field => $label) {
        $qty = max(0, (int)($effectiveQtys[$field] ?? 0));
        if ($qty > 0) {
            $parts[] = $label . ' = ' . number_format($qty, 0, ',', '.');
        }
    }

    return implode("\n", $parts);
}

function gaInputExportStatusLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'review' => 'VERIFIKASI',
        'active' => 'AKTIF',
        'paid' => 'PAID',
        'archived' => 'ARSIP',
        default => 'PENDING',
    };
}

if (!gaInputExportTableExists($pdo, 'secretary_file_records')) {
    exit('Tabel input kerja sama belum tersedia.');
}

$columnReady = gaInputExportColumnsReady($pdo);
$effectiveUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
$pricePerPcs = gaCooperationRegulationPricePerPcs($pdo, $effectiveUnit);
$startDate = $_GET['from'] ?? '';
$endDate = $_GET['to'] ?? '';
$range = $_GET['range'] ?? 'week4';

$cooperationSettingsMap = [];
if (gaCooperationTablesReady($pdo)) {
    $stmtCoop = $pdo->prepare("
        SELECT id, institution_name, period_type, notes, is_active
        FROM general_affair_cooperations
        WHERE unit_code = :unit_code
        ORDER BY is_active DESC, institution_name ASC, id DESC
    ");
    $stmtCoop->execute([':unit_code' => $effectiveUnit]);
    foreach ($stmtCoop->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cooperationRow) {
        $cooperationId = (int)($cooperationRow['id'] ?? 0);
        if ($cooperationId <= 0) {
            continue;
        }

        $notesMeta = gaCooperationParseNotesMeta((string)($cooperationRow['notes'] ?? ''));
        $medicineQtys = gaCooperationNormalizeMedicineQtys($notesMeta);
        $memberCount = gaInputExportCountActiveMembers($pdo, $cooperationId);
        $calculationMode = (string)($notesMeta['calculation_mode'] ?? 'manual');
        $summary = gaCooperationSummarizeMedicines($medicineQtys, $pricePerPcs, $calculationMode, $memberCount);

        $cooperationSettingsMap[$cooperationId] = [
            'institution_name' => (string)($cooperationRow['institution_name'] ?? ''),
            'period_label' => gaCooperationPeriodLabel((string)($cooperationRow['period_type'] ?? '')),
            'claim_scope_label' => gaCooperationClaimScopeLabel((string)($notesMeta['claim_scope'] ?? 'per_person')),
            'calculation_mode_label' => gaCooperationCalculationModeLabel($calculationMode),
            'medicine_summary' => gaInputExportCompactMedicineLines((array)($summary['qtys'] ?? [])),
            'medicine_total_price' => (int)($summary['total_price'] ?? 0),
        ];
    }
}

$sql = "
    SELECT
        sfr.*,
        creator.full_name AS created_by_name" . ($columnReady['paid_by'] ? ",
        paid_user.full_name AS paid_by_name" : "") . "
    FROM secretary_file_records sfr
    LEFT JOIN user_rh creator ON creator.id = sfr.created_by" . ($columnReady['paid_by'] ? "
    LEFT JOIN user_rh paid_user ON paid_user.id = sfr.paid_by" : "") . "
    WHERE sfr.file_category = 'cooperation'
      AND COALESCE(sfr.keywords, '') LIKE :marker
";
$params = [':marker' => '%ga_cooperation_input%'];

if ($range !== 'custom') {
    $sql .= " AND sfr.document_date BETWEEN :start_date AND :end_date";
    $params[':start_date'] = substr((string)$rangeStart, 0, 10);
    $params[':end_date'] = substr((string)$rangeEnd, 0, 10);
} elseif ($startDate && $endDate) {
    $sql .= " AND sfr.document_date BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $startDate;
    $params[':end_date'] = $endDate;
}

$sql .= " ORDER BY sfr.document_date ASC, " . ($columnReady['document_time'] ? "sfr.document_time ASC, " : "") . "sfr.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = [
    'total_record' => count($rows),
    'review_count' => 0,
    'grand_total' => 0,
];

foreach ($rows as $row) {
    $meta = gaInputExportParseKeywordMeta((string)($row['keywords'] ?? ''));
    $setting = $cooperationSettingsMap[(int)($meta['cooperation_id'] ?? 0)] ?? null;
    $stats['grand_total'] += (int)($setting['medicine_total_price'] ?? 0);
    if (($row['status'] ?? '') === 'review') {
        $stats['review_count']++;
    }
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Input Kerja Sama');
$sheet->freezePane('A4');
$sheet->setSelectedCell('A1');
$sheet->getSheetView()->setZoomScale(80);
$sheet->getSheetView()->setZoomScaleNormal(80);
$sheet->getDefaultColumnDimension()->setWidth(16);
$spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(true);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$dateRangeLabel = $rangeLabel ?? '-';
if ($range === 'custom' && $startDate && $endDate) {
    $dateRangeLabel = date('d M Y', strtotime($startDate)) . ' - ' . date('d M Y', strtotime($endDate));
}

$sheet->setCellValue('A1', 'LAPORAN INPUT KERJA SAMA');
$sheet->mergeCells('A1:L1');
$sheet->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '0F766E']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);

$sheet->setCellValue('A2', 'Periode: ' . $dateRangeLabel);
$sheet->mergeCells('A2:L2');
$sheet->getStyle('A2')->applyFromArray([
    'font' => ['size' => 11, 'color' => ['rgb' => '64748B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);

$headers = ['No', 'Kode', 'Tanggal', 'Jam', 'Instansi', 'Setting', 'Mode', 'Input Oleh', 'Obat Gratis', 'Total Regulasi', 'Status', 'Keterangan Bayar'];
$sheet->fromArray($headers, null, 'A4');
$sheet->getStyle('A4:L4')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D9488']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0F766E']]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);

$currentRow = 5;
foreach ($rows as $index => $row) {
    $meta = gaInputExportParseKeywordMeta((string)($row['keywords'] ?? ''));
    $setting = $cooperationSettingsMap[(int)($meta['cooperation_id'] ?? 0)] ?? [];
    $docDate = (string)($row['document_date'] ?? '');
    $docTime = $columnReady['document_time'] ? (string)($row['document_time'] ?? '00:00:00') : '00:00:00';
    $paidInfo = '-';
    if (($row['status'] ?? '') === 'paid' && !empty($row['paid_by_name'])) {
        $paidInfo = (string)$row['paid_by_name'];
        if ($columnReady['paid_at'] && !empty($row['paid_at'])) {
            $paidInfo .= "\n" . date('d M Y H:i', strtotime((string)$row['paid_at']));
        }
    }

    $sheet->fromArray([
        $index + 1,
        (string)($row['file_code'] ?? ''),
        $docDate !== '' ? date('d M Y', strtotime($docDate)) : '-',
        $columnReady['document_time'] ? date('H:i', strtotime($docTime)) : '-',
        (string)($row['counterparty_name'] ?? ''),
        (string)($setting['institution_name'] ?? '-'),
        trim((string)($setting['claim_scope_label'] ?? '-')) . ' | ' . trim((string)($setting['calculation_mode_label'] ?? '-')),
        (string)($row['created_by_name'] ?? $row['title'] ?? '-'),
        (string)($setting['medicine_summary'] ?? '-'),
        (int)($setting['medicine_total_price'] ?? 0),
        gaInputExportStatusLabel((string)($row['status'] ?? 'draft')),
        $paidInfo,
    ], null, 'A' . $currentRow);

    $rowRange = 'A' . $currentRow . ':L' . $currentRow;
    $sheet->getStyle($rowRange)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);

    $status = strtolower((string)($row['status'] ?? 'draft'));
    if ($status === 'paid') {
        $sheet->getStyle($rowRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D1FAE5');
    } elseif ($status === 'active') {
        $sheet->getStyle($rowRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DBEAFE');
    } elseif ($status === 'review') {
        $sheet->getStyle($rowRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
    }

    $sheet->getStyle('A' . $currentRow . ':D' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('J' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyle('J' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('K' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $currentRow++;
}

$summaryStartRow = $currentRow + 1;
$sheet->setCellValue('A' . $summaryStartRow, 'RINGKASAN');
$sheet->mergeCells('A' . $summaryStartRow . ':C' . $summaryStartRow);
$sheet->getStyle('A' . $summaryStartRow)->applyFromArray([
    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '0F766E']],
]);

$sheet->setCellValue('A' . ($summaryStartRow + 1), 'Total Record');
$sheet->setCellValue('B' . ($summaryStartRow + 1), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 1), $stats['total_record']);
$sheet->setCellValue('A' . ($summaryStartRow + 2), 'Verifikasi');
$sheet->setCellValue('B' . ($summaryStartRow + 2), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 2), $stats['review_count']);
$sheet->setCellValue('A' . ($summaryStartRow + 3), 'Grand Total Regulasi');
$sheet->setCellValue('B' . ($summaryStartRow + 3), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 3), $stats['grand_total']);
$sheet->getStyle('C' . ($summaryStartRow + 3))->getNumberFormat()->setFormatCode('#,##0');

$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(18);
$sheet->getColumnDimension('C')->setWidth(14);
$sheet->getColumnDimension('D')->setWidth(8);
$sheet->getColumnDimension('E')->setWidth(20);
$sheet->getColumnDimension('F')->setWidth(20);
$sheet->getColumnDimension('G')->setWidth(22);
$sheet->getColumnDimension('H')->setWidth(20);
$sheet->getColumnDimension('I')->setWidth(28);
$sheet->getColumnDimension('J')->setWidth(14);
$sheet->getColumnDimension('K')->setWidth(12);
$sheet->getColumnDimension('L')->setWidth(24);

$filename = 'input_kerja_sama_' . date('Y-m-d_H-i-s') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
