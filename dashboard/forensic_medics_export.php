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

ems_require_division_access(['Forensic'], '/dashboard/index.php');

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

function forensicExportStatusMeta(array $user): array
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
            'is_resigned' => $hasResigned,
        ];
    }

    if ($cutiPeriodStatus === 'active') {
        return ['label' => 'On Leave', 'is_resigned' => false];
    }

    if ($cutiPeriodStatus === 'scheduled') {
        return ['label' => 'Scheduled Leave', 'is_resigned' => false];
    }

    return ['label' => 'Available', 'is_resigned' => false];
}

function forensicExportPromotionDate(array $user): ?string
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

function forensicExportTenureDays(?string $date): int
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

function forensicExportDocLabel(?string $path, string $requiredLabel = 'Completed', string $missingLabel = 'Not Yet'): string
{
    return trim((string)$path) !== '' ? $requiredLabel : $missingLabel;
}

function forensicExportMedicalClassLabel(array $user): string
{
    $position = ems_normalize_position($user['position'] ?? '');

    if ($position === 'trainee') {
        return 'Not Required';
    }

    if ($position === 'paramedic') {
        return forensicExportDocLabel($user['sertifikat_class_paramedic'] ?? null);
    }

    return forensicExportDocLabel($user['sertifikat_class_co_asst'] ?? null);
}

function forensicExportCertificateRequirements(array $user): array
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

function forensicExportCertificateSummary(array $user): string
{
    $requirements = forensicExportCertificateRequirements($user);
    $missing = [];

    foreach ($requirements as $field => $label) {
        if (trim((string)($user[$field] ?? '')) === '') {
            $missing[] = $label;
        }
    }

    if ($missing === []) {
        return 'Lengkap';
    }

    return 'Belum Lengkap: ' . implode(', ', $missing);
}

$baseColumns = [
    'id',
    'full_name',
    'position',
    'batch',
    'tanggal_masuk',
    'is_active',
    'cuti_status',
    'cuti_start_date',
    'cuti_end_date',
    'resign_reason',
    'resigned_at',
    'file_ktp',
    'file_kta',
    'file_skb',
];

$optionalColumns = [
    'unit_code',
    'sertifikat_operasi_plastik',
    'sertifikat_operasi_kecil',
    'sertifikat_operasi_besar',
    'sertifikat_class_co_asst',
    'sertifikat_class_paramedic',
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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(substr('Forensic Medics', 0, 31));
$sheet->freezePane('A2');
$sheet->setSelectedCell('A1');
$sheet->getSheetView()->setZoomScale(75);
$sheet->getSheetView()->setZoomScaleNormal(75);
$sheet->getDefaultColumnDimension()->setWidth(18);
$spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(false);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$headers = [
    'No',
    'Name',
    'Position',
    'Status',
    'Promotion Date',
    'Tenure (Days)',
    'Plastic Surgery',
    'Minor Surgery',
    'Major Surgery',
    'Medical Class',
    'Certificate Status',
];

$sheet->fromArray($headers, null, 'A1');

$headerRange = 'A1:K1';
$sheet->getStyle($headerRange)->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '0F766E'],
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '0F172A'],
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
]);

$currentRow = 2;
foreach ($rows as $index => $row) {
    $statusMeta = forensicExportStatusMeta($row);
    $promotionDate = forensicExportPromotionDate($row);
    $tenureDays = forensicExportTenureDays($promotionDate);

    $sheet->fromArray([
        $index + 1,
        (string)($row['full_name'] ?? ''),
        ems_position_label((string)($row['position'] ?? '')),
        $statusMeta['label'],
        $promotionDate ? formatTanggalIndo($promotionDate) : '-',
        $tenureDays,
        forensicExportDocLabel($row['sertifikat_operasi_plastik'] ?? null),
        forensicExportDocLabel($row['sertifikat_operasi_kecil'] ?? null),
        forensicExportDocLabel($row['sertifikat_operasi_besar'] ?? null),
        forensicExportMedicalClassLabel($row),
        forensicExportCertificateSummary($row),
    ], null, 'A' . $currentRow);

    $rowRange = 'A' . $currentRow . ':K' . $currentRow;
    $sheet->getStyle($rowRange)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '1E293B'],
            ],
        ],
        'alignment' => [
            'wrapText' => false,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    if (!empty($statusMeta['is_resigned'])) {
        $sheet->getStyle($rowRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEE2E2'],
            ],
            'font' => [
                'color' => ['rgb' => '7F1D1D'],
            ],
        ]);
    } elseif (in_array($statusMeta['label'], ['On Leave', 'Scheduled Leave'], true)) {
        $sheet->getStyle($rowRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEF3C7'],
            ],
            'font' => [
                'color' => ['rgb' => '78350F'],
            ],
        ]);
    }

    $currentRow++;
}

$lastDataRow = max(1, $currentRow - 1);
$sheet->getStyle('A1:K' . $lastDataRow)->getAlignment()->setWrapText(false);
$sheet->getRowDimension(1)->setRowHeight(24);

foreach (range('A', 'K') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$filenameSlug = $positionFilter !== '' ? $positionFilter : 'semua';
$filename = 'forensic_medis_' . $filenameSlug . '_' . date('Y-m-d_H-i-s') . '.xlsx';

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
