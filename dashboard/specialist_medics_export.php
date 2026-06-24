<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ems_require_division_access(['Specialist Medical Authority'], '/dashboard/index.php');

$positionFilter = ems_normalize_position($_GET['position'] ?? '');
$allowedPositionFilters = [
    '' => 'Semua',
    'trainee' => 'Trainee',
    'paramedic' => 'Paramedic',
    'co_asst' => 'Co. Asst',
    'general_practitioner' => 'Doctor',
    'specialist' => 'Doctor Specialist',
];

if (!array_key_exists($positionFilter, $allowedPositionFilters)) {
    $positionFilter = '';
}

$sessionUser = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $sessionUser);
$hasUnitCodeColumn = ems_column_exists($pdo, 'user_rh', 'unit_code');

function specialistExportStatusMeta(array $user): array
{
    $cutiPeriodStatus = get_cuti_period_status(
        $user['cuti_start_date'] ?? null,
        $user['cuti_end_date'] ?? null,
        $user['cuti_status'] ?? null
    );

    if ((int)($user['is_active'] ?? 0) !== 1) {
        $hasResigned = !empty($user['resigned_at']) || trim((string)($user['resign_reason'] ?? '')) !== '';
        return [
            'label' => $hasResigned ? 'Resigned' : 'Inactive',
            'tone' => 'danger',
        ];
    }

    if ($cutiPeriodStatus === 'active') {
        return ['label' => 'On Leave', 'tone' => 'warning'];
    }

    if ($cutiPeriodStatus === 'scheduled') {
        return ['label' => 'Scheduled Leave', 'tone' => 'info'];
    }

    return ['label' => 'Available', 'tone' => 'success'];
}

function specialistExportPromotionDate(array $user): ?string
{
    $position = ems_normalize_position($user['position'] ?? '');

    return match ($position) {
        'paramedic' => trim((string)($user['tanggal_naik_paramedic'] ?? '')) ?: trim((string)($user['tanggal_masuk'] ?? '')),
        'co_asst' => trim((string)($user['tanggal_naik_co_asst'] ?? '')) ?: trim((string)($user['tanggal_naik_paramedic'] ?? '')) ?: trim((string)($user['tanggal_masuk'] ?? '')),
        'general_practitioner' => trim((string)($user['tanggal_naik_dokter'] ?? '')) ?: trim((string)($user['tanggal_naik_co_asst'] ?? '')) ?: trim((string)($user['tanggal_masuk'] ?? '')),
        'specialist' => trim((string)($user['tanggal_naik_dokter_spesialis'] ?? '')) ?: trim((string)($user['tanggal_naik_dokter'] ?? '')) ?: trim((string)($user['tanggal_masuk'] ?? '')),
        default => trim((string)($user['tanggal_masuk'] ?? '')),
    };
}

function specialistExportTenureDays(?string $date): int
{
    if (!$date) {
        return 0;
    }

    try {
        $start = new DateTime($date);
        $today = new DateTime('today');
        if ($start > $today) {
            return 0;
        }

        return (int)$start->diff($today)->days;
    } catch (Throwable $e) {
        return 0;
    }
}

function specialistExportDocList(array $user): string
{
    $docs = [
        'KTP' => $user['file_ktp'] ?? null,
        'SKB' => $user['file_skb'] ?? null,
        'SIM' => $user['file_sim'] ?? null,
        'KTA' => $user['file_kta'] ?? null,
        'HELI' => $user['sertifikat_heli'] ?? null,
        'Operasi' => $user['sertifikat_operasi'] ?? null,
        'Operasi Plastik' => $user['sertifikat_operasi_plastik'] ?? null,
        'Operasi Kecil' => $user['sertifikat_operasi_kecil'] ?? null,
        'Operasi Besar' => $user['sertifikat_operasi_besar'] ?? null,
        'Class Paramedic' => $user['sertifikat_class_paramedic'] ?? null,
        'Class Co. Asst' => $user['sertifikat_class_co_asst'] ?? null,
    ];

    $labels = [];
    foreach ($docs as $label => $path) {
        if (trim((string)$path) !== '') {
            $labels[] = $label;
        }
    }

    $academyDocs = ensureAcademyDocIds(parseAcademyDocs($user['dokumen_lainnya'] ?? ''));
    foreach ($academyDocs as $ad) {
        $label = trim((string)($ad['name'] ?? 'File Lainnya'));
        if ($label !== '' && trim((string)($ad['path'] ?? '')) !== '') {
            $labels[] = $label;
        }
    }

    return $labels === [] ? '-' : implode(', ', $labels);
}

function specialistExportCertificateRequirements(array $user): array
{
    $position = ems_normalize_position($user['position'] ?? '');

    $requirements = [
        'file_ktp' => 'KTP',
        'file_kta' => 'KTA',
        'file_skb' => 'SKB',
    ];

    if (in_array($position, ['paramedic', 'co_asst', 'general_practitioner', 'specialist'], true)) {
        $requirements['sertifikat_class_paramedic'] = 'Class Paramedic';
    }

    if (in_array($position, ['co_asst', 'general_practitioner', 'specialist'], true)) {
        $requirements['sertifikat_class_co_asst'] = 'Class Co. Asst';
    }

    if (in_array($position, ['general_practitioner', 'specialist'], true)) {
        $requirements['sertifikat_operasi_kecil'] = 'Operasi Kecil';
    }

    if ($position === 'specialist') {
        $requirements['sertifikat_operasi_besar'] = 'Operasi Besar';
        $requirements['sertifikat_operasi_plastik'] = 'Operasi Plastik';
    }

    return $requirements;
}

function specialistExportCertificateSummary(array $user): string
{
    $requirements = specialistExportCertificateRequirements($user);
    $missing = [];

    foreach ($requirements as $field => $label) {
        if (trim((string)($user[$field] ?? '')) === '') {
            $missing[] = $label;
        }
    }

    return $missing === [] ? 'Lengkap' : 'Belum Lengkap: ' . implode(', ', $missing);
}

$baseColumns = [
    'id',
    'full_name',
    'position',
    'batch',
    'tanggal_masuk',
    'kode_nomor_induk_rs',
    'citizen_id',
    'no_hp_ic',
    'jenis_kelamin',
    'is_active',
    'cuti_status',
    'cuti_start_date',
    'cuti_end_date',
    'resign_reason',
    'resigned_at',
    'file_ktp',
    'file_kta',
    'file_skb',
    'file_sim',
    'sertifikat_heli',
    'sertifikat_operasi',
    'dokumen_lainnya',
    'sertifikat_operasi_plastik',
    'sertifikat_operasi_kecil',
    'sertifikat_operasi_besar',
    'sertifikat_class_co_asst',
    'sertifikat_class_paramedic',
];

$optionalColumns = [
    'unit_code',
    'division',
    'tanggal_naik_paramedic',
    'tanggal_naik_co_asst',
    'tanggal_naik_dokter',
    'tanggal_naik_dokter_spesialis',
];

$selectColumns = $baseColumns;
foreach ($optionalColumns as $optionalColumn) {
    if (ems_column_exists($pdo, 'user_rh', $optionalColumn)) {
        $selectColumns[] = $optionalColumn;
    }
}

$whereParts = [
    "position IN ('trainee', 'paramedic', 'co_asst', 'general_practitioner', 'specialist')",
];
$params = [];

if ($hasUnitCodeColumn) {
    $whereParts[] = "COALESCE(unit_code, 'roxwood') = :unit_code";
    $params[':unit_code'] = $effectiveUnit;
}

if ($positionFilter !== '') {
    $whereParts[] = "position = :position";
    $params[':position'] = $positionFilter;
}

$stmt = $pdo->prepare("
    SELECT
        " . implode(",\n        ", $selectColumns) . "
    FROM user_rh
    WHERE " . implode(' AND ', $whereParts) . "
    ORDER BY is_active DESC, full_name ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summary = [
    'total' => count($rows),
    'available' => 0,
    'leave' => 0,
    'inactive' => 0,
    'complete' => 0,
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(substr('Specialist Medics', 0, 31));
$sheet->freezePane('A5');
$sheet->setSelectedCell('A1');
$sheet->getSheetView()->setZoomScale(85);
$sheet->getSheetView()->setZoomScaleNormal(85);
$spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(false);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$sheet->setCellValue('A1', 'DAFTAR MEDIS SPECIALIST MEDICAL AUTHORITY');
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

$sheet->setCellValue('A2', 'Unit: ' . ems_unit_label($effectiveUnit) . ' | Filter Jabatan: ' . $allowedPositionFilters[$positionFilter]);
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
    'Nama',
    'Nomor Induk RS',
    'NIK',
    'No. HP',
    'Jenis Kelamin',
    'Jabatan',
    'Batch',
    'Status',
    'Tanggal Masuk/Naik',
    'Tenure (Hari)',
    'Kelengkapan Syarat',
    'Dokumen Tersedia',
];

$sheet->fromArray($headers, null, 'A4');
$sheet->getStyle('A4:M4')->applyFromArray([
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
foreach ($rows as $index => $row) {
    $statusMeta = specialistExportStatusMeta($row);
    $promotionDate = specialistExportPromotionDate($row);
    $tenureDays = specialistExportTenureDays($promotionDate);
    $certificateSummary = specialistExportCertificateSummary($row);

    if ($statusMeta['label'] === 'Available') {
        $summary['available']++;
    } elseif (in_array($statusMeta['label'], ['On Leave', 'Scheduled Leave'], true)) {
        $summary['leave']++;
    } else {
        $summary['inactive']++;
    }

    if ($certificateSummary === 'Lengkap') {
        $summary['complete']++;
    }

    $sheet->fromArray([
        $index + 1,
        (string)($row['full_name'] ?? ''),
        trim((string)($row['kode_nomor_induk_rs'] ?? '')) !== '' ? (string)$row['kode_nomor_induk_rs'] : '-',
        trim((string)($row['citizen_id'] ?? '')) !== '' ? (string)$row['citizen_id'] : '-',
        trim((string)($row['no_hp_ic'] ?? '')) !== '' ? (string)$row['no_hp_ic'] : '-',
        trim((string)($row['jenis_kelamin'] ?? '')) !== '' ? (string)$row['jenis_kelamin'] : '-',
        ems_position_label((string)($row['position'] ?? '')),
        !empty($row['batch']) ? 'Batch ' . (int)$row['batch'] : 'Tanpa Batch',
        $statusMeta['label'],
        $promotionDate ? formatTanggalIndo($promotionDate) : '-',
        $tenureDays,
        $certificateSummary,
        specialistExportDocList($row),
    ], null, 'A' . $currentRow);
    $sheet->setCellValueExplicit('C' . $currentRow, trim((string)($row['kode_nomor_induk_rs'] ?? '')) !== '' ? (string)$row['kode_nomor_induk_rs'] : '-', DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('D' . $currentRow, trim((string)($row['citizen_id'] ?? '')) !== '' ? (string)$row['citizen_id'] : '-', DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('E' . $currentRow, trim((string)($row['no_hp_ic'] ?? '')) !== '' ? (string)$row['no_hp_ic'] : '-', DataType::TYPE_STRING);

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

    if ($statusMeta['tone'] === 'danger') {
        $sheet->getStyle($rowRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEE2E2'],
            ],
            'font' => [
                'color' => ['rgb' => '7F1D1D'],
            ],
        ]);
    } elseif ($statusMeta['tone'] === 'warning') {
        $sheet->getStyle($rowRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEF3C7'],
            ],
            'font' => [
                'color' => ['rgb' => '78350F'],
            ],
        ]);
    } elseif ($statusMeta['tone'] === 'info') {
        $sheet->getStyle($rowRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0F2FE'],
            ],
            'font' => [
                'color' => ['rgb' => '075985'],
            ],
        ]);
    }

    if ($certificateSummary !== 'Lengkap') {
        $sheet->getStyle('L' . $currentRow)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFEDD5'],
            ],
            'font' => [
                'color' => ['rgb' => '9A3412'],
            ],
        ]);
    }

    $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('C' . $currentRow . ':K' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('L' . $currentRow . ':M' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);

    $sheet->getRowDimension($currentRow)->setRowHeight(-1);
    $currentRow++;
}

$lastDataRow = max(4, $currentRow - 1);
$sheet->setAutoFilter('A4:M' . $lastDataRow);

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
    ['Total Medis', $summary['total']],
    ['Available', $summary['available']],
    ['Cuti / Scheduled Leave', $summary['leave']],
    ['Inactive / Resigned', $summary['inactive']],
    ['Syarat Lengkap', $summary['complete']],
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

$columnWidths = [
    'A' => 6,
    'B' => 28,
    'C' => 18,
    'D' => 20,
    'E' => 18,
    'F' => 16,
    'G' => 22,
    'H' => 14,
    'I' => 18,
    'J' => 18,
    'K' => 13,
    'L' => 34,
    'M' => 42,
];

foreach ($columnWidths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

$sheet->getStyle('A4:M' . $lastDataRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
if ($lastDataRow >= 5) {
    $sheet->getStyle('C5:E' . $lastDataRow)->getNumberFormat()->setFormatCode('@');
}

$filenameSlug = $positionFilter !== '' ? $positionFilter : 'semua';
$filename = 'specialist_medis_' . $filenameSlug . '_' . date('Y-m-d_H-i-s') . '.xlsx';

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
