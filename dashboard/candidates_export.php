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
$role = $user['role'] ?? '';

if (strtolower((string)$role) === 'staff') {
    header('Location: dashboard.php');
    exit;
}

function candidatesExportStatusLabel(string $status): string
{
    return match ($status) {
        'ai_completed' => 'Menunggu',
        'interview' => 'Interview',
        'final_review' => 'Final Review',
        'accepted' => 'Diterima',
        'rejected' => 'Ditolak',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function candidatesExportDecisionLabel(?string $decision): string
{
    $decision = strtolower(trim((string)$decision));

    return match ($decision) {
        'recommended' => 'Direkomendasikan',
        'not_recommended' => 'Tidak Direkomendasikan',
        'follow_up_required' => 'Perlu Tindak Lanjut',
        'lolos' => 'Lolos',
        'tidak_lolos' => 'Tidak Lolos',
        'proceed' => 'Lanjut Interview',
        'reject' => 'Ditolak Sistem',
        '' => '-',
        default => ucwords(str_replace('_', ' ', $decision)),
    };
}

$listRecruitmentType = 'medical_candidate';
$candidateSql = "
    SELECT
        m.id,
        m.ic_name,
        m.citizen_id,
        m.ic_phone,
        m.jenis_kelamin,
        m.created_at,
        m.status,
        m.rejection_stage,
        r.score_total AS ai_score,
        r.decision AS ai_decision,
        ir.average_score AS interview_score,
        ir.ml_confidence AS confidence,
        ir.is_locked AS interview_locked,
        fd.final_result,
        (
            SELECT COUNT(DISTINCT s.hr_id)
            FROM applicant_interview_scores s
            WHERE s.applicant_id = m.id
        ) AS total_hr,
        (
            SELECT GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ')
            FROM applicant_interview_scores s
            JOIN user_rh u ON u.id = s.hr_id
            WHERE s.applicant_id = m.id
        ) AS interviewers
    FROM medical_applicants m
    LEFT JOIN ai_test_results r
        ON r.applicant_id = m.id
    LEFT JOIN applicant_interview_results ir
        ON ir.applicant_id = m.id
    LEFT JOIN applicant_final_decisions fd
        ON fd.applicant_id = m.id
";
$candidateParams = [];

if (ems_column_exists($pdo, 'medical_applicants', 'recruitment_type')) {
    $candidateSql .= " WHERE COALESCE(NULLIF(m.recruitment_type, ''), 'medical_candidate') = ?";
    $candidateParams[] = $listRecruitmentType;
}

$candidateSql .= " ORDER BY m.created_at DESC";
$stmt = $pdo->prepare($candidateSql);
$stmt->execute($candidateParams);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Calon Kandidat');
$sheet->freezePane('A5');
$sheet->setSelectedCell('A1');
$sheet->getSheetView()->setZoomScale(85);
$sheet->getSheetView()->setZoomScaleNormal(85);
$spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
$spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(false);

$sheet->setCellValue('A1', 'EXPORT CALON KANDIDAT EMS');
$sheet->mergeCells('A1:M1');
$sheet->setCellValue('A2', 'Roxwood Hospital • Generated ' . date('d M Y H:i'));
$sheet->mergeCells('A2:M2');
$sheet->setCellValue('A3', 'Total Kandidat: ' . count($candidates));
$sheet->mergeCells('A3:M3');

$sheet->getStyle('A1:A3')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 11,
    ],
]);
$sheet->getStyle('A1')->applyFromArray([
    'font' => [
        'size' => 16,
        'color' => ['rgb' => '0F766E'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
]);
$sheet->getStyle('A2:A3')->applyFromArray([
    'font' => [
        'color' => ['rgb' => '64748B'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
]);

$sheet->getRowDimension(1)->setRowHeight(28);
$sheet->getRowDimension(2)->setRowHeight(20);
$sheet->getRowDimension(3)->setRowHeight(18);
$sheet->getRowDimension(4)->setRowHeight(8);

$headers = [
    'No',
    'Nama IC',
    'Citizen ID',
    'No. Telepon',
    'Jenis Kelamin',
    'Tanggal Daftar',
    'Status',
    'Skor AI',
    'Keputusan AI',
    'Skor Interview',
    'Confidence',
    'Skor Gabungan',
    'Hasil / Interviewer',
];

$sheet->fromArray($headers, null, 'A5');
$headerRange = 'A5:M5';
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
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '99F6E4'],
        ],
    ],
]);

$rowIndex = 6;
foreach ($candidates as $index => $candidate) {
    $aiScore = (float)($candidate['ai_score'] ?? 0);
    $interviewScore = (float)($candidate['interview_score'] ?? 0);
    $confidence = (float)($candidate['confidence'] ?? 0);
    $isInterviewLocked = (int)($candidate['interview_locked'] ?? 0) === 1;

    $combinedScore = $isInterviewLocked
        ? round(($interviewScore * 0.6) + ($aiScore * 0.3) + ($confidence * 0.1), 2)
        : null;

    $finalResult = candidatesExportDecisionLabel($candidate['final_result'] ?? null);
    $aiDecision = candidatesExportDecisionLabel($candidate['ai_decision'] ?? null);
    $statusLabel = candidatesExportStatusLabel((string)($candidate['status'] ?? ''));
    $interviewerSummary = trim((string)($candidate['interviewers'] ?? ''));
    $resultSummary = $finalResult !== '-' ? $finalResult : $aiDecision;
    if ($interviewerSummary !== '') {
        $resultSummary .= "\n" . $interviewerSummary;
    }

    $sheet->fromArray([[
        $index + 1,
        (string)($candidate['ic_name'] ?? '-'),
        (string)($candidate['citizen_id'] ?? '-'),
        (string)($candidate['ic_phone'] ?? '-'),
        (string)($candidate['jenis_kelamin'] ?? '-'),
        !empty($candidate['created_at']) ? date('d M Y H:i', strtotime((string)$candidate['created_at'])) : '-',
        $statusLabel,
        $aiScore > 0 ? $aiScore : '-',
        $aiDecision,
        $interviewScore > 0 ? $interviewScore : '-',
        $confidence > 0 ? $confidence . '%' : '-',
        $combinedScore !== null ? $combinedScore : '-',
        $resultSummary,
    ]], null, 'A' . $rowIndex);

    $fillColor = $index % 2 === 0 ? 'F8FAFC' : 'FFFFFF';
    $sheet->getStyle('A' . $rowIndex . ':M' . $rowIndex)->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => $fillColor],
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'CBD5E1'],
            ],
        ],
    ]);

    $sheet->getStyle('A' . $rowIndex . ':L' . $rowIndex)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('B' . $rowIndex . ':F' . $rowIndex)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle('M' . $rowIndex)->getAlignment()->setWrapText(true);
    $sheet->getRowDimension($rowIndex)->setRowHeight(36);
    $rowIndex++;
}

foreach ([
    'A' => 6,
    'B' => 24,
    'C' => 18,
    'D' => 16,
    'E' => 16,
    'F' => 20,
    'G' => 18,
    'H' => 12,
    'I' => 20,
    'J' => 14,
    'K' => 14,
    'L' => 14,
    'M' => 38,
] as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

$sheet->setAutoFilter($headerRange);

$lastDataRow = max(5, $rowIndex - 1);
$sheet->getStyle('A5:M' . $lastDataRow)->applyFromArray([
    'borders' => [
        'outline' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '94A3B8'],
        ],
    ],
]);

$filename = 'calon_kandidat_ems_' . date('Y-m-d_H-i-s') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
