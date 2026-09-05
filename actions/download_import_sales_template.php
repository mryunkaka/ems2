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

$user = $_SESSION['user_rh'] ?? [];
if (strtolower(trim((string)($user['role'] ?? ''))) === 'staff') {
    http_response_code(403);
    exit('Akses ditolak.');
}

try {
    $effectiveUnit = ems_effective_unit($pdo, $user);
    $packagesHasUnitCode = ems_column_exists($pdo, 'packages', 'unit_code');
    $packageSql = 'SELECT name FROM packages WHERE (bandage_qty + ifaks_qty + painkiller_qty) > 0';
    if ($packagesHasUnitCode) {
        $packageSql .= " AND COALESCE(unit_code, 'roxwood') = :unit_code";
    }
    $packageSql .= ' ORDER BY name ASC';

    $stmtPackages = $pdo->prepare($packageSql);
    $stmtPackages->execute($packagesHasUnitCode ? [':unit_code' => $effectiveUnit] : []);
    $packageNames = array_values(array_filter(array_map(static function (array $row): string {
        return trim((string)($row['name'] ?? ''));
    }, $stmtPackages->fetchAll(PDO::FETCH_ASSOC))));

    $spreadsheet = new Spreadsheet();
    $importSheet = $spreadsheet->getActiveSheet();
    $importSheet->setTitle('Data Import');
    $importSheet->fromArray([
        [
            'Consumer Identifier / Legacy Consumer Name',
            'Package Name',
            'Citizen ID (Optional)',
        ],
    ], null, 'A1');
    $importSheet->freezePane('A2');
    $importSheet->setAutoFilter('A1:C1');
    $importSheet->getColumnDimension('A')->setWidth(42);
    $importSheet->getColumnDimension('B')->setWidth(32);
    $importSheet->getColumnDimension('C')->setWidth(24);
    $importSheet->getRowDimension(1)->setRowHeight(30);

    $instructionsSheet = $spreadsheet->createSheet();
    $instructionsSheet->setTitle('Petunjuk');
    $instructionsSheet->fromArray([
        ['PETUNJUK IMPORT DATA KONSUMEN'],
        ['File ini mengikuti format importer Data Konsumen EMS2.'],
        [''],
        ['Kolom', 'Isi', 'Keterangan'],
        ['A', 'Consumer Identifier / Legacy Consumer Name', 'Isi Citizen ID konsumen atau nama konsumen lama.'],
        ['B', 'Package Name', 'Wajib. Salin nama paket persis dari sheet Referensi Paket.'],
        ['C', 'Citizen ID (Optional)', 'Opsional. Jika diisi, nilai ini diprioritaskan sebagai identitas konsumen baru.'],
        [''],
        ['CONTOH NILAI (JANGAN SALIN BARIS INI KE SHEET DATA IMPORT)', '', ''],
        ['RHCONTOH001', $packageNames[0] ?? 'Nama paket dari Referensi Paket', 'RHCONTOH001'],
        [''],
        ['Catatan penting', '', ''],
        ['1', 'Nama medis dan tanggal transaksi diisi pada modal Import Excel.', ''],
        ['2', 'Satu baris berisi satu transaksi untuk satu paket.', ''],
        ['3', 'Jangan mengubah urutan tiga kolom pada sheet Data Import.', ''],
        ['4', 'Jangan menambahkan judul atau baris contoh sebelum header pada sheet Data Import.', ''],
        ['5', 'Baris kosong akan dilewati. Baris dengan nama paket yang tidak persis sama akan dilewati importer.', ''],
        ['6', 'Sheet Data Import sengaja hanya berisi header agar tidak ada transaksi contoh yang ikut tersimpan.', ''],
    ], null, 'A1');
    $instructionsSheet->mergeCells('A1:C1');
    $instructionsSheet->mergeCells('A2:C2');
    $instructionsSheet->getColumnDimension('A')->setWidth(16);
    $instructionsSheet->getColumnDimension('B')->setWidth(64);
    $instructionsSheet->getColumnDimension('C')->setWidth(68);
    $instructionsSheet->getRowDimension(1)->setRowHeight(28);
    $instructionsSheet->getStyle('A1:C1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $instructionsSheet->getStyle('A1:C1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    $instructionsSheet->getStyle('A2:C2')->getAlignment()->setWrapText(true);
    $instructionsSheet->getStyle('A4:C4')->getFont()->setBold(true);
    $instructionsSheet->getStyle('A4:C4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0EA5E9');
    $instructionsSheet->getStyle('A4:C4')->getFont()->getColor()->setARGB('FFFFFFFF');
    $instructionsSheet->getStyle('A9:C9')->getFont()->setBold(true);
    $instructionsSheet->getStyle('A9:C9')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF3CD');
    $instructionsSheet->getStyle('A12:C12')->getFont()->setBold(true);
    $instructionsSheet->getStyle('A1:C18')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    $instructionsSheet->getStyle('A1:C18')->getAlignment()->setWrapText(true);
    $instructionsSheet->getStyle('A4:C18')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');
    $instructionsSheet->freezePane('A4');

    $referenceSheet = $spreadsheet->createSheet();
    $referenceSheet->setTitle('Referensi Paket');
    $referenceSheet->fromArray([['Package Name']], null, 'A1');
    foreach ($packageNames as $index => $packageName) {
        $referenceSheet->setCellValue('A' . ($index + 2), $packageName);
    }
    $referenceSheet->getColumnDimension('A')->setWidth(42);
    $referenceSheet->freezePane('A2');
    if ($packageNames !== []) {
        $referenceSheet->setAutoFilter('A1:A' . (count($packageNames) + 1));
    }

    foreach ([$importSheet, $instructionsSheet, $referenceSheet] as $sheet) {
        $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['argb' => 'FFFFFFFF'],
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['argb' => 'FF0EA5E9'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FFE2E8F0'],
            ],
        ],
    ];
    $importSheet->getStyle('A1:C1')->applyFromArray($headerStyle);
    $referenceSheet->getStyle('A1')->applyFromArray($headerStyle);

    $exampleSheet = $spreadsheet->createSheet();
    $exampleSheet->setTitle('Contoh Import');
    $exampleSheet->fromArray([
        ['Consumer Identifier / Legacy Consumer Name', 'Package Name', 'Citizen ID (Optional)'],
        ['RHCONTOH001', $packageNames[0] ?? 'Nama paket dari Referensi Paket', 'RHCONTOH001'],
    ], null, 'A1');
    $exampleSheet->getColumnDimension('A')->setWidth(42);
    $exampleSheet->getColumnDimension('B')->setWidth(32);
    $exampleSheet->getColumnDimension('C')->setWidth(24);
    $exampleSheet->getRowDimension(1)->setRowHeight(30);
    $exampleSheet->getStyle('A1:C1')->applyFromArray($headerStyle);
    $exampleSheet->getStyle('A1:C2')->getAlignment()->setWrapText(true);
    $exampleSheet->getStyle('A1:C2')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE2E8F0');
    $exampleSheet->getStyle('A2:C2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF3CD');
    $exampleSheet->freezePane('A2');

    $spreadsheet->setIndexByName('Contoh Import', 1);
    $spreadsheet->setIndexByName('Petunjuk', 2);
    $spreadsheet->setIndexByName('Referensi Paket', 3);
    $spreadsheet->setActiveSheetIndex(0);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'template-import-data-konsumen-' . date('Ymd') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');

    (new Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
} catch (Throwable $e) {
    error_log('[download_import_sales_template] ' . $e->getMessage());
    http_response_code(500);
    exit('Template Excel gagal dibuat.');
}
