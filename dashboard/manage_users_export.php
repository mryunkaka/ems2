<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../helpers/user_docs_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$user = $_SESSION['user_rh'] ?? [];
$role = $user['role'] ?? '';
$effectiveUnit = ems_effective_unit($pdo, $user);

// HARD GUARD: staff dilarang
if (ems_is_staff_role($role)) {
    header('Location: setting_akun.php');
    exit;
}

// View mode: per_batch atau all
$viewMode = trim($_GET['view'] ?? '');
if (!in_array($viewMode, ['per_batch', 'all'], true)) {
    $viewMode = 'per_batch';
}

function manageUsersHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM user_rh LIKE ?");
    $stmt->execute([$column]);
    $cache[$column] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

$hasDivisionColumn = manageUsersHasColumn($pdo, 'division');
$hasUnitCodeColumn = manageUsersHasColumn($pdo, 'unit_code');
$hasCitizenIdColumn = manageUsersHasColumn($pdo, 'citizen_id');
$hasNoHpIcColumn = manageUsersHasColumn($pdo, 'no_hp_ic');
$hasJenisKelaminColumn = manageUsersHasColumn($pdo, 'jenis_kelamin');

$divisionSelect = $hasDivisionColumn ? "u.division," : "NULL AS division,";
$unitSelect = $hasUnitCodeColumn ? "u.unit_code," : "'roxwood' AS unit_code,";
$citizenIdSelect = $hasCitizenIdColumn ? "u.citizen_id," : "NULL AS citizen_id,";
$noHpIcSelect = $hasNoHpIcColumn ? "u.no_hp_ic," : "NULL AS no_hp_ic,";
$jenisKelaminSelect = $hasJenisKelaminColumn ? "u.jenis_kelamin," : "NULL AS jenis_kelamin,";

$unitWhere = $hasUnitCodeColumn ? "WHERE COALESCE(u.unit_code, 'roxwood') = :unit_code" : "";

$stmtUsers = $pdo->prepare("
    SELECT 
        u.id,
        u.full_name,
        u.position,
        u.role,
        {$divisionSelect}
        {$unitSelect}
        {$citizenIdSelect}
        {$noHpIcSelect}
        {$jenisKelaminSelect}
        u.is_active,
        u.tanggal_masuk,
        u.batch,
        u.kode_nomor_induk_rs,
        u.file_ktp,
        u.file_sim,
        u.file_kta,
        u.file_skb,
        u.sertifikat_heli,
        u.sertifikat_operasi,
        u.dokumen_lainnya,
        u.resign_reason,
        u.resigned_at,
        u.reactivated_at,
        u.reactivated_note
    FROM user_rh u
    {$unitWhere}
    ORDER BY 
        u.is_active DESC,
        u.full_name ASC
");
$stmtUsers->execute($hasUnitCodeColumn ? [':unit_code' => $effectiveUnit] : []);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Process other docs
foreach ($users as &$userRow) {
    $userOtherDocs = ensureAcademyDocIds(parseAcademyDocs($userRow['dokumen_lainnya'] ?? ''));
    $userRow['_other_docs'] = $userOtherDocs;
}
unset($userRow);

// Group by batch if per_batch mode
$usersByBatch = [];
if ($viewMode === 'per_batch') {
    foreach ($users as $u) {
        $batchKey = !empty($u['batch']) ? 'Batch ' . (int)$u['batch'] : 'Tanpa Batch';
        $usersByBatch[$batchKey][] = $u;
    }
    
    // Sort batches
    uksort($usersByBatch, function ($a, $b) {
        if ($a === 'Tanpa Batch') return 1;
        if ($b === 'Tanpa Batch') return -1;
        preg_match('/\d+/', $a, $ma);
        preg_match('/\d+/', $b, $mb);
        return ((int)$ma[0]) <=> ((int)$mb[0]);
    });
}

function formatDurasiMedisExport(?string $tanggalMasuk): string
{
    if (empty($tanggalMasuk)) return '-';

    $start = new DateTime($tanggalMasuk);
    $now   = new DateTime();

    if ($start > $now) return '-';

    $diff = $start->diff($now);

    if ($diff->y > 0) {
        return $diff->y . ' tahun' . ($diff->m > 0 ? ' ' . $diff->m . ' bulan' : '');
    }

    if ($diff->m > 0) {
        return $diff->m . ' bulan';
    }

    $days = $diff->days;

    if ($days >= 7) {
        return floor($days / 7) . ' minggu';
    }

    return $days . ' hari';
}

function getDocumentList(array $u): string
{
    $docs = [];
    
    if (!empty($u['file_ktp'])) $docs[] = 'KTP';
    if (!empty($u['file_sim'])) $docs[] = 'SIM';
    if (!empty($u['file_kta'])) $docs[] = 'KTA';
    if (!empty($u['file_skb'])) $docs[] = 'SKB';
    if (!empty($u['sertifikat_heli'])) $docs[] = 'Sertifikat Heli';
    if (!empty($u['sertifikat_operasi'])) $docs[] = 'Sertifikat Operasi';
    
    $academyDocs = $u['_other_docs'] ?? [];
    foreach ($academyDocs as $ad) {
        $label = trim((string)($ad['name'] ?? ''));
        if ($label) $docs[] = $label;
    }
    
    return empty($docs) ? '-' : implode(', ', $docs);
}

function getStatusLabel(array $u): string
{
    if ((int)($u['is_active'] ?? 0) === 1) {
        return 'Aktif';
    }
    
    if (!empty($u['resigned_at'])) {
        return 'Resign (' . (new DateTime($u['resigned_at']))->format('d M Y') . ')';
    }
    
    return 'Non-Aktif';
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

if ($viewMode === 'per_batch') {
    $sheet->setTitle('User Per Batch');
} else {
    $sheet->setTitle('Semua User');
}

$sheet->freezePane('A4');
$sheet->setSelectedCell('A1');
$sheet->getSheetView()->setZoomScale(85);
$sheet->getSheetView()->setZoomScaleNormal(85);
$spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(false);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$title = $viewMode === 'per_batch' ? 'DAFTAR USER PER BATCH' : 'DAFTAR SEMUA USER';
$sheet->setCellValue('A1', $title);
$sheet->mergeCells('A1:J1');
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

$sheet->setCellValue('A2', 'Unit: ' . ems_unit_label($effectiveUnit));
$sheet->mergeCells('A2:J2');
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
    'Batch',
    'Jabatan',
    'Role',
    'Division',
    'Unit',
    'Tanggal Join',
    'Durasi',
    'Status',
    'Dokumen',
];

$sheet->fromArray($headers, null, 'A4');

$headerRange = 'A4:K4';
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

if ($viewMode === 'per_batch') {
    foreach ($usersByBatch as $batchName => $batchUsers) {
        // Batch header row
        $sheet->setCellValue('A' . $currentRow, $batchName);
        $sheet->mergeCells('A' . $currentRow . ':K' . $currentRow);
        $sheet->getStyle('A' . $currentRow)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => '0F766E'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F0FDFA'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '99F6E4'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension($currentRow)->setRowHeight(24);
        $currentRow++;
        
        // Users in this batch
        foreach ($batchUsers as $i => $u) {
            $sheet->fromArray([
                $i + 1,
                $u['full_name'] ?? '',
                !empty($u['batch']) ? 'Batch ' . (int)$u['batch'] : 'Tanpa Batch',
                ems_position_label($u['position'] ?? ''),
                ems_role_label($u['role'] ?? ''),
                ems_normalize_division($u['division'] ?? '') ?: '-',
                ems_unit_label($u['unit_code'] ?? 'roxwood'),
                !empty($u['tanggal_masuk']) ? (new DateTime($u['tanggal_masuk']))->format('d M Y') : '-',
                formatDurasiMedisExport($u['tanggal_masuk'] ?? null),
                getStatusLabel($u),
                getDocumentList($u),
            ], null, 'A' . $currentRow);

            $rowRange = 'A' . $currentRow . ':K' . $currentRow;
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

            // Color coding based on status
            $isActive = (int)($u['is_active'] ?? 0) === 1;
            if (!$isActive) {
                $sheet->getStyle($rowRange)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'FEE2E2'],
                    ],
                    'font' => [
                        'color' => ['rgb' => '7F1D1D'],
                    ],
                ]);
            }

            $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('C' . $currentRow . ':G' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('H' . $currentRow . ':J' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            $currentRow++;
        }
        
        // Empty row between batches
        $currentRow++;
    }
} else {
    // All users view
    foreach ($users as $i => $u) {
        $sheet->fromArray([
            $i + 1,
            $u['full_name'] ?? '',
            !empty($u['batch']) ? 'Batch ' . (int)$u['batch'] : 'Tanpa Batch',
            ems_position_label($u['position'] ?? ''),
            ems_role_label($u['role'] ?? ''),
            ems_normalize_division($u['division'] ?? '') ?: '-',
            ems_unit_label($u['unit_code'] ?? 'roxwood'),
            !empty($u['tanggal_masuk']) ? (new DateTime($u['tanggal_masuk']))->format('d M Y') : '-',
            formatDurasiMedisExport($u['tanggal_masuk'] ?? null),
            getStatusLabel($u),
            getDocumentList($u),
        ], null, 'A' . $currentRow);

        $rowRange = 'A' . $currentRow . ':K' . $currentRow;
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

        // Color coding based on status
        $isActive = (int)($u['is_active'] ?? 0) === 1;
        if (!$isActive) {
            $sheet->getStyle($rowRange)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FEE2E2'],
                ],
                'font' => [
                    'color' => ['rgb' => '7F1D1D'],
                ],
            ]);
        }

        $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('C' . $currentRow . ':G' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('H' . $currentRow . ':J' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('K' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $currentRow++;
    }
}

// Summary section
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

$activeCount = count(array_filter($users, fn($u) => (int)($u['is_active'] ?? 0) === 1));
$inactiveCount = count(array_filter($users, fn($u) => (int)($u['is_active'] ?? 0) === 0));

$sheet->setCellValue('A' . ($summaryStartRow + 1), 'Total User');
$sheet->setCellValue('B' . ($summaryStartRow + 1), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 1), count($users));

$sheet->setCellValue('A' . ($summaryStartRow + 2), 'User Aktif');
$sheet->setCellValue('B' . ($summaryStartRow + 2), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 2), $activeCount);

$sheet->setCellValue('A' . ($summaryStartRow + 3), 'User Non-Aktif/Resign');
$sheet->setCellValue('B' . ($summaryStartRow + 3), ':');
$sheet->setCellValue('C' . ($summaryStartRow + 3), $inactiveCount);

$sheet->setCellValue('A' . ($summaryStartRow + 4), 'Jumlah Batch');
$sheet->setCellValue('B' . ($summaryStartRow + 4), ':');
$batchCount = count($usersByBatch);
if ($batchCount === 0) {
    $batches = [];
    foreach ($users as $u) {
        $batches[] = $u['batch'] ?? 0;
    }
    $batchCount = count(array_unique($batches));
}
$sheet->setCellValue('C' . ($summaryStartRow + 4), $batchCount);

$summaryRange = 'A' . ($summaryStartRow + 1) . ':C' . ($summaryStartRow + 4);
$sheet->getStyle($summaryRange)->applyFromArray([
    'borders' => [
        'outline' => [
            'borderStyle' => Border::BORDER_MEDIUM,
            'color' => ['rgb' => '0D9488'],
        ],
    ],
]);

$sheet->getStyle('A' . ($summaryStartRow + 1) . ':A' . ($summaryStartRow + 4))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getStyle('B' . ($summaryStartRow + 1) . ':B' . ($summaryStartRow + 4))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('C' . ($summaryStartRow + 1) . ':C' . ($summaryStartRow + 4))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Set column widths
$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(28);
$sheet->getColumnDimension('C')->setWidth(14);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(16);
$sheet->getColumnDimension('F')->setWidth(16);
$sheet->getColumnDimension('G')->setWidth(14);
$sheet->getColumnDimension('H')->setWidth(14);
$sheet->getColumnDimension('I')->setWidth(12);
$sheet->getColumnDimension('J')->setWidth(22);
$sheet->getColumnDimension('K')->setWidth(35);

$viewModeSlug = $viewMode === 'per_batch' ? 'per_batch' : 'semua';
$filename = 'manage_users_' . $viewModeSlug . '_' . date('Y-m-d_H-i-s') . '.xlsx';

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
