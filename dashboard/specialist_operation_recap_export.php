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
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ems_require_division_access(['Specialist Medical Authority'], '/dashboard/index.php');
require_not_on_cuti('/dashboard/pengajuan_cuti_resign.php');

$search = trim((string) ($_GET['search'] ?? ''));
$searchNeedle = mb_strtolower($search);
$hasVisibilityScope = ems_column_exists($pdo, 'medical_records', 'visibility_scope');
$hasRecordCode = ems_column_exists($pdo, 'medical_records', 'record_code');
$hasAssistantsTable = ems_table_exists($pdo, 'medical_record_assistants');

function specialistOperationRecapExportRecordCode(array $row, bool $hasRecordCode): string
{
    $recordCode = $hasRecordCode ? trim((string) ($row['record_code'] ?? '')) : '';
    if ($recordCode !== '') {
        return $recordCode;
    }

    return 'MR-' . str_pad((string) ((int) ($row['medical_record_id'] ?? $row['id'] ?? 0)), 6, '0', STR_PAD_LEFT);
}

function specialistOperationRecapExportRoleLabel(string $roleKey): string
{
    return $roleKey === 'dpjp' ? 'DPJP' : 'Asisten';
}

function specialistOperationRecapExportTypeLabel(string $operationType): string
{
    return strtolower($operationType) === 'major' ? 'Mayor' : 'Minor';
}

function specialistOperationRecapExportDate(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') {
        return '-';
    }

    try {
        return (new DateTime($date))->format('d M Y H:i');
    } catch (Throwable $e) {
        return $date;
    }
}

$scopeWhere = $hasVisibilityScope
    ? "COALESCE(r.visibility_scope, 'standard') = 'standard'"
    : '1=1';

$recordStats = [
    'medical_staff' => 0,
    'linked_records' => 0,
    'major' => 0,
    'minor' => 0,
    'role_assignments' => 0,
];
$staffRecap = [];
$detailRows = [];

$doctorSql = "
    SELECT
        r.id AS medical_record_id,
        " . ($hasRecordCode ? "COALESCE(r.record_code, '')" : "''") . " AS record_code,
        r.created_at,
        r.patient_name,
        r.patient_occupation,
        r.operasi_type,
        u.id AS user_id,
        u.full_name,
        u.position,
        'dpjp' AS role_key
    FROM medical_records r
    INNER JOIN user_rh u ON u.id = r.doctor_id
    WHERE {$scopeWhere}
";

$doctorRows = $pdo->query($doctorSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($hasAssistantsTable) {
    $assistantSql = "
        SELECT
            r.id AS medical_record_id,
            " . ($hasRecordCode ? "COALESCE(r.record_code, '')" : "''") . " AS record_code,
            r.created_at,
            r.patient_name,
            r.patient_occupation,
            r.operasi_type,
            u.id AS user_id,
            u.full_name,
            u.position,
            'assistant' AS role_key
        FROM medical_record_assistants mra
        INNER JOIN medical_records r ON r.id = mra.medical_record_id
        INNER JOIN user_rh u ON u.id = mra.assistant_user_id
        WHERE {$scopeWhere}
        ORDER BY r.created_at DESC, mra.sort_order ASC
    ";
} else {
    $assistantSql = "
        SELECT
            r.id AS medical_record_id,
            " . ($hasRecordCode ? "COALESCE(r.record_code, '')" : "''") . " AS record_code,
            r.created_at,
            r.patient_name,
            r.patient_occupation,
            r.operasi_type,
            u.id AS user_id,
            u.full_name,
            u.position,
            'assistant' AS role_key
        FROM medical_records r
        INNER JOIN user_rh u ON u.id = r.assistant_id
        WHERE {$scopeWhere}
    ";
}

$assistantRows = $pdo->query($assistantSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$roleAssignments = array_merge($doctorRows, $assistantRows);

foreach ($roleAssignments as $row) {
    $userId = (int) ($row['user_id'] ?? 0);
    $position = (string) ($row['position'] ?? '');

    if ($userId <= 0 || !ems_is_medical_position($position)) {
        continue;
    }

    $recordId = (int) ($row['medical_record_id'] ?? 0);
    $recordCode = specialistOperationRecapExportRecordCode($row, $hasRecordCode);
    $fullName = trim((string) ($row['full_name'] ?? ''));
    $positionLabel = ems_position_label($position);
    $patientName = trim((string) ($row['patient_name'] ?? ''));
    $patientOccupation = trim((string) ($row['patient_occupation'] ?? ''));
    $operationType = strtolower((string) ($row['operasi_type'] ?? 'minor')) === 'major' ? 'major' : 'minor';
    $roleKey = (string) ($row['role_key'] ?? 'assistant');

    $haystack = mb_strtolower(implode(' ', [
        $fullName,
        $positionLabel,
        $patientName,
        $patientOccupation,
        $recordCode,
        specialistOperationRecapExportRoleLabel($roleKey),
        specialistOperationRecapExportTypeLabel($operationType),
    ]));

    if ($searchNeedle !== '' && !str_contains($haystack, $searchNeedle)) {
        continue;
    }

    if (!isset($staffRecap[$userId])) {
        $staffRecap[$userId] = [
            'user_id' => $userId,
            'full_name' => $fullName,
            'position' => $positionLabel,
            'dpjp_major' => 0,
            'dpjp_minor' => 0,
            'assistant_major' => 0,
            'assistant_minor' => 0,
            'records' => [],
            'record_keys' => [],
        ];
    }

    $bucket = $roleKey . '_' . $operationType;
    if (isset($staffRecap[$userId][$bucket])) {
        $staffRecap[$userId][$bucket]++;
    }

    $recordRoleKey = $recordId . ':' . $roleKey;
    if (!isset($staffRecap[$userId]['record_keys'][$recordRoleKey])) {
        $staffRecap[$userId]['record_keys'][$recordRoleKey] = true;
        $staffRecap[$userId]['records'][] = [
            'medical_record_id' => $recordId,
            'record_code' => $recordCode,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'patient_name' => $patientName,
            'patient_occupation' => $patientOccupation,
            'operasi_type' => $operationType,
            'role_key' => $roleKey,
        ];
    }

    $detailRows[] = [
        'staff_name' => $fullName,
        'position' => $positionLabel,
        'role' => specialistOperationRecapExportRoleLabel($roleKey),
        'operation_type' => specialistOperationRecapExportTypeLabel($operationType),
        'record_code' => $recordCode,
        'patient_name' => $patientName,
        'patient_occupation' => $patientOccupation,
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];

    $recordStats['role_assignments']++;
    if ($operationType === 'major') {
        $recordStats['major']++;
    } else {
        $recordStats['minor']++;
    }
}

foreach ($staffRecap as &$staff) {
    usort($staff['records'], static function (array $left, array $right): int {
        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });

    $staff['total_dpjp'] = (int) $staff['dpjp_major'] + (int) $staff['dpjp_minor'];
    $staff['total_assistant'] = (int) $staff['assistant_major'] + (int) $staff['assistant_minor'];
    $staff['total_operations'] = $staff['total_dpjp'] + $staff['total_assistant'];
    $staff['linked_records_count'] = count($staff['records']);
}
unset($staff);

usort($staffRecap, static function (array $left, array $right): int {
    $scoreCompare = ($right['total_operations'] <=> $left['total_operations']);
    if ($scoreCompare !== 0) {
        return $scoreCompare;
    }

    return strcmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
});

usort($detailRows, static function (array $left, array $right): int {
    $dateCompare = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    if ($dateCompare !== 0) {
        return $dateCompare;
    }

    return strcmp((string) ($left['staff_name'] ?? ''), (string) ($right['staff_name'] ?? ''));
});

$recordStats['medical_staff'] = count($staffRecap);
$linkedRecordKeys = [];
foreach ($staffRecap as $staff) {
    foreach ($staff['records'] as $record) {
        $linkedRecordKeys[(string) ($record['medical_record_id'] ?? 0)] = true;
    }
}
$recordStats['linked_records'] = count($linkedRecordKeys);

$spreadsheet = new Spreadsheet();
$spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(false);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Rekap Operasi');
$sheet->freezePane('A5');
$sheet->setSelectedCell('A1');
$sheet->getSheetView()->setZoomScale(85);
$sheet->getSheetView()->setZoomScaleNormal(85);

$sheet->setCellValue('A1', 'REKAP OPERASI MEDIS');
$sheet->mergeCells('A1:K1');
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

$sheet->setCellValue('A2', 'Specialist Medical Authority' . ($search !== '' ? ' | Pencarian: ' . $search : ''));
$sheet->mergeCells('A2:K2');
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
    'Tenaga Medis',
    'Jabatan',
    'DPJP Mayor',
    'DPJP Minor',
    'Asisten Mayor',
    'Asisten Minor',
    'Total DPJP',
    'Total Asisten',
    'Total Operasi',
    'Rekam Medis',
];

$sheet->fromArray($headers, null, 'A4');
$sheet->getStyle('A4:K4')->applyFromArray([
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
$sheet->getRowDimension(4)->setRowHeight(28);

$currentRow = 5;
foreach ($staffRecap as $index => $staff) {
    $sheet->fromArray([
        $index + 1,
        (string) ($staff['full_name'] ?? ''),
        (string) ($staff['position'] ?? ''),
        (int) ($staff['dpjp_major'] ?? 0),
        (int) ($staff['dpjp_minor'] ?? 0),
        (int) ($staff['assistant_major'] ?? 0),
        (int) ($staff['assistant_minor'] ?? 0),
        (int) ($staff['total_dpjp'] ?? 0),
        (int) ($staff['total_assistant'] ?? 0),
        (int) ($staff['total_operations'] ?? 0),
        (int) ($staff['linked_records_count'] ?? 0),
    ], null, 'A' . $currentRow);

    $rowRange = 'A' . $currentRow . ':K' . $currentRow;
    $sheet->getStyle($rowRange)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'E2E8F0'],
            ],
        ],
    ]);

    $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $currentRow . ':C' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('D' . $currentRow . ':K' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    if ((int) ($staff['total_operations'] ?? 0) > 0) {
        $sheet->getStyle('J' . $currentRow)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '075985'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0F2FE'],
            ],
        ]);
    }

    $currentRow++;
}

$lastRecapRow = max(4, $currentRow - 1);
$sheet->setAutoFilter('A4:K' . $lastRecapRow);

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

$summaryRows = [
    ['Tenaga Medis', $recordStats['medical_staff']],
    ['Rekam Medis Terkait', $recordStats['linked_records']],
    ['Operasi Mayor', $recordStats['major']],
    ['Operasi Minor', $recordStats['minor']],
    ['Total Penugasan Peran', $recordStats['role_assignments']],
];

foreach ($summaryRows as $i => $summaryRow) {
    $rowNumber = $summaryStartRow + 1 + $i;
    $sheet->setCellValue('A' . $rowNumber, $summaryRow[0]);
    $sheet->setCellValue('B' . $rowNumber, ':');
    $sheet->setCellValue('C' . $rowNumber, $summaryRow[1]);
}

$summaryRange = 'A' . ($summaryStartRow + 1) . ':C' . ($summaryStartRow + count($summaryRows));
$sheet->getStyle($summaryRange)->applyFromArray([
    'borders' => [
        'outline' => [
            'borderStyle' => Border::BORDER_MEDIUM,
            'color' => ['rgb' => '0D9488'],
        ],
        'inside' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CCFBF1'],
        ],
    ],
]);
$sheet->getStyle('A' . ($summaryStartRow + 1) . ':A' . ($summaryStartRow + count($summaryRows)))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getStyle('B' . ($summaryStartRow + 1) . ':B' . ($summaryStartRow + count($summaryRows)))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('C' . ($summaryStartRow + 1) . ':C' . ($summaryStartRow + count($summaryRows)))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

$recapColumnWidths = [
    'A' => 6,
    'B' => 30,
    'C' => 22,
    'D' => 14,
    'E' => 14,
    'F' => 16,
    'G' => 16,
    'H' => 13,
    'I' => 15,
    'J' => 15,
    'K' => 14,
];
foreach ($recapColumnWidths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

$detailSheet = $spreadsheet->createSheet();
$detailSheet->setTitle('Detail Rekam Medis');
$detailSheet->freezePane('A5');
$detailSheet->getSheetView()->setZoomScale(85);
$detailSheet->getSheetView()->setZoomScaleNormal(85);

$detailSheet->setCellValue('A1', 'DETAIL PENUGASAN OPERASI MEDIS');
$detailSheet->mergeCells('A1:H1');
$detailSheet->getStyle('A1')->applyFromArray([
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

$detailSheet->setCellValue('A2', 'Setiap baris menunjukkan satu peran tenaga medis pada satu rekam medis operasi.');
$detailSheet->mergeCells('A2:H2');
$detailSheet->getStyle('A2')->applyFromArray([
    'font' => [
        'size' => 11,
        'color' => ['rgb' => '64748B'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);
$detailSheet->getRowDimension(1)->setRowHeight(28);
$detailSheet->getRowDimension(2)->setRowHeight(20);
$detailSheet->getRowDimension(3)->setRowHeight(8);

$detailHeaders = [
    'No',
    'Tanggal Operasi',
    'No. Rekam Medis',
    'Tenaga Medis',
    'Jabatan',
    'Peran',
    'Jenis Operasi',
    'Pasien / Pekerjaan',
];
$detailSheet->fromArray($detailHeaders, null, 'A4');
$detailSheet->getStyle('A4:H4')->applyFromArray([
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
$detailSheet->getRowDimension(4)->setRowHeight(28);

$detailRowNumber = 5;
foreach ($detailRows as $index => $row) {
    $patientDetail = trim((string) ($row['patient_name'] ?? ''));
    $occupation = trim((string) ($row['patient_occupation'] ?? ''));
    if ($occupation !== '') {
        $patientDetail .= ' / ' . $occupation;
    }

    $detailSheet->fromArray([
        $index + 1,
        specialistOperationRecapExportDate($row['created_at'] ?? ''),
        (string) ($row['record_code'] ?? ''),
        (string) ($row['staff_name'] ?? ''),
        (string) ($row['position'] ?? ''),
        (string) ($row['role'] ?? ''),
        (string) ($row['operation_type'] ?? ''),
        $patientDetail !== '' ? $patientDetail : '-',
    ], null, 'A' . $detailRowNumber);

    $rowRange = 'A' . $detailRowNumber . ':H' . $detailRowNumber;
    $detailSheet->getStyle($rowRange)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'E2E8F0'],
            ],
        ],
    ]);

    if (($row['operation_type'] ?? '') === 'Mayor') {
        $detailSheet->getStyle('G' . $detailRowNumber)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '7F1D1D'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEE2E2'],
            ],
        ]);
    } else {
        $detailSheet->getStyle('G' . $detailRowNumber)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '78350F'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEF3C7'],
            ],
        ]);
    }

    $detailSheet->getStyle('A' . $detailRowNumber . ':C' . $detailRowNumber)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $detailSheet->getStyle('D' . $detailRowNumber . ':E' . $detailRowNumber)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $detailSheet->getStyle('F' . $detailRowNumber . ':G' . $detailRowNumber)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $detailSheet->getStyle('H' . $detailRowNumber)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);

    $detailRowNumber++;
}

$lastDetailRow = max(4, $detailRowNumber - 1);
$detailSheet->setAutoFilter('A4:H' . $lastDetailRow);

$detailColumnWidths = [
    'A' => 6,
    'B' => 18,
    'C' => 18,
    'D' => 30,
    'E' => 22,
    'F' => 12,
    'G' => 14,
    'H' => 36,
];
foreach ($detailColumnWidths as $column => $width) {
    $detailSheet->getColumnDimension($column)->setWidth($width);
}

$spreadsheet->setActiveSheetIndex(0);

$filename = 'rekap_operasi_medis_' . date('Y-m-d_H-i-s') . '.xlsx';

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
