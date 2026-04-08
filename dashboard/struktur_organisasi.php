<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Struktur Organisasi';
$isPdfPreview = isset($_GET['view']) && $_GET['view'] === 'pdf';
$pdfOrientation = strtoupper((string)($_GET['orientation'] ?? 'L'));
$pdfOrientation = in_array($pdfOrientation, ['L', 'P'], true) ? $pdfOrientation : 'L';

function orgHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];

    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare('SHOW COLUMNS FROM user_rh LIKE ?');
    $stmt->execute([$column]);
    $cache[$column] = (bool) $stmt->fetch(PDO::FETCH_ASSOC);

    return $cache[$column];
}

function orgRoleRank(?string $role): int
{
    return match (ems_normalize_role($role)) {
        'director' => 1,
        'vice director' => 2,
        'head manager' => 3,
        'lead manager' => 4,
        'assisten manager' => 5,
        default => 99,
    };
}

function orgJoinDuration(?string $tanggalMasuk): string
{
    if (empty($tanggalMasuk)) {
        return '-';
    }

    try {
        $start = new DateTime((string) $tanggalMasuk);
        $now = new DateTime();

        if ($start > $now) {
            return '-';
        }

        $diff = $start->diff($now);
        $months = ((int) $diff->y * 12) + (int) $diff->m;

        if ($months >= 1) {
            return $months . ' bulan';
        }

        if ((int) $diff->days >= 1) {
            return $diff->days . ' hari';
        }

        return (((int) $diff->days * 24) + (int) $diff->h) . ' jam';
    } catch (Throwable $e) {
        return '-';
    }
}

function orgDivisionRank(string $division): int
{
    static $order = [
        'Secretary' => 1,
        'Human Capital' => 2,
        'Human Resource' => 3,
        'Disciplinary Committee' => 4,
        'General Affair' => 5,
        'Specialist Medical Authority' => 6,
        'Forensic' => 7,
    ];

    return $order[$division] ?? 99;
}

function orgIsExcludedUser(?string $name): bool
{
    $normalized = strtolower(trim((string) $name));
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?: '';

    return in_array($normalized, ['alta', 'programmer alta', 'programmer roxwood'], true);
}

function orgPdfEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function orgRenderPdfPersonCards(array $people): string
{
    if ($people === []) {
        return '<div class="pdf-empty">Belum ada manager aktif.</div>';
    }

    $html = '<table class="pdf-person-table" cellspacing="0" cellpadding="0">';
    foreach ($people as $person) {
        $html .= '
            <tr>
                <td class="pdf-person-card">
                    <div class="pdf-name">' . orgPdfEscape($person['name'] ?? '') . '</div>
                    <div class="pdf-role-line">' . orgPdfEscape($person['role'] ?? '') . ' | ' . orgPdfEscape($person['division'] ?? '') . '</div>
                    <div class="pdf-meta">' . orgPdfEscape($person['position'] ?? '-') . '</div>
                    <div class="pdf-foot">Masa aktif: ' . orgPdfEscape($person['join_duration'] ?? '-') . '</div>
                </td>
            </tr>
        ';
    }

    $html .= '</table>';

    return $html;
}

function orgRenderPdfNode(array $node): string
{
    $html = '
        <div class="pdf-node">
            <div class="pdf-node-head">
                <div class="pdf-node-title">' . orgPdfEscape($node['division'] ?? '') . '</div>
                <div class="pdf-node-count">' . count((array)($node['people'] ?? [])) . ' manager aktif</div>
            </div>
            <div class="pdf-people-list">' . orgRenderPdfPersonCards((array)($node['people'] ?? [])) . '</div>
    ';

    $children = (array)($node['children'] ?? []);
    if ($children !== []) {
        $html .= '<div class="pdf-child-wrap">';
        foreach ($children as $child) {
            $html .= '
                <div class="pdf-child-node">
                    <div class="pdf-child-head">' . orgPdfEscape($child['division'] ?? '') . ' · ' . count((array)($child['people'] ?? [])) . ' manager</div>
                    <div class="pdf-people-list">' . orgRenderPdfPersonCards((array)($child['people'] ?? [])) . '</div>
                </div>
            ';
        }
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}

function orgRenderPdfDocument(array $stats, array $directors, array $viceDirectors, array $orgTree): string
{
    $divisionNodes = array_values(array_filter($orgTree, static function (array $node): bool {
        return ($node['people'] ?? []) !== [] || ($node['children'] ?? []) !== [];
    }));

    $html = '
    <style>
        body { font-family: dejavusans, sans-serif; color: #0f172a; font-size: 9px; }
        .pdf-page { padding: 2mm; }
        .pdf-title { font-size: 17px; font-weight: bold; margin-bottom: 1mm; color: #0f172a; }
        .pdf-subtitle { font-size: 8px; color: #475569; margin-bottom: 4mm; }
        .pdf-stats { width: 100%; margin-bottom: 4mm; }
        .pdf-stats td { width: 33.33%; padding-right: 2mm; vertical-align: top; }
        .pdf-stat-box { border: 0.2mm solid #cbd5e1; background: #f8fafc; padding: 2mm; }
        .pdf-stat-label { font-size: 6.5px; color: #64748b; text-transform: uppercase; }
        .pdf-stat-value { font-size: 13px; font-weight: bold; margin-top: 0.4mm; }
        .pdf-section { margin-bottom: 4mm; }
        .pdf-section-title { font-size: 10px; font-weight: bold; margin-bottom: 2mm; color: #0f766e; }
        .pdf-grid { width: 100%; }
        .pdf-grid td { width: 50%; vertical-align: top; padding-right: 2mm; padding-bottom: 2mm; }
        .pdf-node { border: 0.2mm solid #dbe7ef; background: #ffffff; padding: 2mm; }
        .pdf-node-head { margin-bottom: 1.5mm; }
        .pdf-node-title { font-size: 9px; font-weight: bold; color: #0f172a; }
        .pdf-node-count { font-size: 7px; color: #64748b; }
        .pdf-people-list { width: 100%; }
        .pdf-person-table { width: 100%; }
        .pdf-person-card { border: 0.2mm solid #e2e8f0; background: #f8fafc; padding: 1.8mm; }
        .pdf-name { font-size: 8.5px; font-weight: bold; color: #0f172a; margin-bottom: 0.4mm; }
        .pdf-role-line { font-size: 7px; color: #0f766e; margin-bottom: 0.4mm; }
        .pdf-meta { font-size: 7px; color: #475569; margin-bottom: 0.4mm; }
        .pdf-foot { font-size: 6.5px; color: #64748b; }
        .pdf-child-wrap { margin-top: 1.5mm; }
        .pdf-child-node { border-top: 0.2mm solid #cbd5e1; padding-top: 1.5mm; margin-top: 1.5mm; }
        .pdf-child-head { font-size: 7.5px; font-weight: bold; color: #0f766e; margin-bottom: 1mm; }
        .pdf-empty { font-size: 7px; color: #64748b; }
    </style>
    <page backtop="6mm" backbottom="6mm" backleft="6mm" backright="6mm">
        <div class="pdf-page">
            <div class="pdf-title">Struktur Organisasi</div>
            <div class="pdf-subtitle">Bagan manager dari level executive sampai divisi operasional. Role staff dan divisi medis tidak ditampilkan.</div>
            <table class="pdf-stats" cellspacing="0" cellpadding="0">
                <tr>
                    <td><div class="pdf-stat-box"><div class="pdf-stat-label">Executive</div><div class="pdf-stat-value">' . (int)$stats['executive'] . '</div></div></td>
                    <td><div class="pdf-stat-box"><div class="pdf-stat-label">Divisi Aktif</div><div class="pdf-stat-value">' . (int)$stats['division'] . '</div></div></td>
                    <td><div class="pdf-stat-box"><div class="pdf-stat-label">Total Manager</div><div class="pdf-stat-value">' . (int)$stats['manager'] . '</div></div></td>
                </tr>
            </table>

            <div class="pdf-section">
                <div class="pdf-section-title">Executive</div>
                <table class="pdf-grid" cellspacing="0" cellpadding="0">
                    <tr>
                        <td>' . orgRenderPdfNode(['division' => 'Director', 'people' => $directors]) . '</td>
                        <td>' . orgRenderPdfNode(['division' => 'Vice Director', 'people' => $viceDirectors]) . '</td>
                    </tr>
                </table>
            </div>

            <div class="pdf-section">
                <div class="pdf-section-title">Divisi Manager</div>
                <table class="pdf-grid" cellspacing="0" cellpadding="0">
    ';

    $chunks = array_chunk($divisionNodes, 2);
    foreach ($chunks as $chunk) {
        $html .= '<tr>';
        foreach ($chunk as $node) {
            $html .= '<td>' . orgRenderPdfNode($node) . '</td>';
        }
        if (count($chunk) === 1) {
            $html .= '<td></td>';
        }
        $html .= '</tr>';
    }

    $html .= '
                </table>
            </div>
        </div>
    </page>';

    return $html;
}

function orgPosterFindNode(array $orgTree, string $division): array
{
    foreach ($orgTree as $node) {
        if (($node['division'] ?? '') === $division) {
            return $node;
        }
    }

    return ['division' => $division, 'people' => [], 'children' => []];
}

function orgPosterBuildBox(string $title, array $people, bool $large = false): string
{
    $body = '';
    foreach ($people as $person) {
        $body .= '
            <div class="poster-name">' . orgPdfEscape($person['name'] ?? '-') . '</div>
            <div class="poster-meta">' . orgPdfEscape($person['position'] ?? '-') . '</div>
        ';
    }

    if ($body === '') {
        $body = '
            <div class="poster-name">Belum Ada</div>
            <div class="poster-meta">Manager aktif belum tersedia</div>
        ';
    }

    return '
        <div class="poster-box' . ($large ? ' is-large' : '') . '">
            <div class="poster-box-title">' . orgPdfEscape(strtoupper($title)) . '</div>
            <div class="poster-box-body">' . $body . '</div>
        </div>
    ';
}

function orgPosterBuildColumn(array $node): string
{
    $html = '
        <td class="poster-col">
            <div class="poster-dept">' . orgPdfEscape(strtoupper((string)($node['division'] ?? ''))) . '</div>
            ' . orgPosterBuildBox((string)($node['division'] ?? ''), (array)($node['people'] ?? [])) . '
    ';

    foreach ((array)($node['children'] ?? []) as $child) {
        $html .= orgPosterBuildBox((string)($child['division'] ?? ''), (array)($child['people'] ?? []));
    }

    $html .= '</td>';

    return $html;
}

function orgRenderPosterPdfDocument(array $stats, array $directors, array $viceDirectors, array $orgTree): string
{
    $ceoPeople = $directors !== [] ? $directors : $viceDirectors;
    $secretaryNode = orgPosterFindNode($orgTree, 'Secretary');
    $humanCapitalNode = orgPosterFindNode($orgTree, 'Human Capital');
    $generalAffairNode = orgPosterFindNode($orgTree, 'General Affair');
    $medicalAuthorityNode = orgPosterFindNode($orgTree, 'Specialist Medical Authority');

    return '
    <style>
        body { font-family: dejavusans, sans-serif; color: #111827; font-size: 9px; }
        .poster-page { padding: 1mm; }
        .poster-brand { text-align: center; font-size: 20px; font-weight: bold; margin-bottom: 1mm; letter-spacing: 0.4px; }
        .poster-title { text-align: center; font-size: 22px; font-weight: bold; margin-bottom: 4mm; }
        .poster-stats { width: 100%; margin-bottom: 3mm; }
        .poster-stats td { width: 33.33%; padding: 0 1.2mm; }
        .poster-stat { border: 0.2mm solid #d1d5db; padding: 1.5mm; text-align: center; }
        .poster-stat-label { font-size: 6.5px; color: #6b7280; text-transform: uppercase; }
        .poster-stat-value { font-size: 12px; font-weight: bold; margin-top: 0.4mm; }
        .poster-center { text-align: center; }
        .poster-box { width: 84%; margin: 0 auto 2mm; background: #d71920; color: #ffffff; padding: 2mm 2.5mm; }
        .poster-box.is-large { width: 42%; }
        .poster-box-title { font-size: 8.5px; font-weight: bold; text-transform: uppercase; margin-bottom: 0.8mm; }
        .poster-box.is-large .poster-box-title { font-size: 11px; }
        .poster-box-body { line-height: 1.35; }
        .poster-name { font-size: 8.3px; font-weight: bold; margin-bottom: 0.5mm; }
        .poster-box.is-large .poster-name { font-size: 10px; }
        .poster-meta { font-size: 6.8px; margin-bottom: 0.7mm; }
        .poster-line-v { width: 0.5mm; height: 7mm; background: #111111; margin: 0 auto; }
        .poster-line-h { height: 0.5mm; background: #111111; margin: 0 8%; }
        .poster-leads { width: 100%; margin-bottom: 1.5mm; }
        .poster-leads td { width: 50%; vertical-align: top; }
        .poster-secretary { width: 30%; margin: 0 auto 2.5mm; }
        .poster-main { width: 100%; }
        .poster-col { width: 33.33%; vertical-align: top; padding: 0 1.4mm; }
        .poster-dept { width: 86%; margin: 0 auto 1.5mm; background: #8f1d1d; color: #ffffff; text-align: center; font-size: 7.6px; font-weight: bold; text-transform: uppercase; padding: 1.2mm 1mm; }
    </style>
    <page backtop="6mm" backbottom="6mm" backleft="7mm" backright="7mm">
        <div class="poster-page">
            <div class="poster-brand">ROXWOOD HOSPITAL</div>
            <div class="poster-title">ORGANIZATIONAL STRUCTURE</div>

            <table class="poster-stats" cellspacing="0" cellpadding="0">
                <tr>
                    <td><div class="poster-stat"><div class="poster-stat-label">Executive</div><div class="poster-stat-value">' . (int)$stats['executive'] . '</div></div></td>
                    <td><div class="poster-stat"><div class="poster-stat-label">Divisi Aktif</div><div class="poster-stat-value">' . (int)$stats['division'] . '</div></div></td>
                    <td><div class="poster-stat"><div class="poster-stat-label">Total Manager</div><div class="poster-stat-value">' . (int)$stats['manager'] . '</div></div></td>
                </tr>
            </table>

            <div class="poster-center">' . orgPosterBuildBox('Chief Executive Officer', $ceoPeople, true) . '</div>
            <div class="poster-center"><div class="poster-line-v"></div></div>

            <table class="poster-leads" cellspacing="0" cellpadding="0">
                <tr>
                    <td class="poster-center">' . orgPosterBuildBox('Director', $directors) . '</td>
                    <td class="poster-center">' . orgPosterBuildBox('Deputy Director', $viceDirectors) . '</td>
                </tr>
            </table>

            <div class="poster-center"><div class="poster-line-v" style="height:5mm;"></div></div>
            <div class="poster-line-h"></div>

            <div class="poster-secretary">' . orgPosterBuildBox('Secretary', (array)($secretaryNode['people'] ?? [])) . '</div>

            <table class="poster-main" cellspacing="0" cellpadding="0">
                <tr>
                    ' . orgPosterBuildColumn($humanCapitalNode) . '
                    ' . orgPosterBuildColumn($generalAffairNode) . '
                    ' . orgPosterBuildColumn($medicalAuthorityNode) . '
                </tr>
            </table>
        </div>
    </page>';
}

function orgPdfBoxHeight(array $people, bool $large = false): float
{
    $base = $large ? 10.5 : 8.8;
    $perPerson = orgPdfEstimatePersonCardHeight($large) + 1.8;

    return $base + (max(1, count($people)) * $perPerson);
}

function orgPdfPeopleText(array $people): string
{
    if ($people === []) {
        return "Belum Ada\nManager aktif belum tersedia";
    }

    $lines = [];
    foreach ($people as $person) {
        $name = trim((string)($person['name'] ?? '-'));
        $position = trim((string)($person['position'] ?? '-'));
        $lines[] = $name;
        $lines[] = $position;
    }

    return implode("\n", $lines);
}

function orgPdfEstimatePersonCardHeight(bool $large = false): float
{
    return $large ? 10.4 : 8.2;
}

function orgPdfEstimatePosterBoxHeight(array $people, bool $large = false): float
{
    $titleArea = $large ? 5.0 : 4.2;
    $cardSpacing = 0.8;

    return $titleArea + (max(1, count($people)) * (orgPdfEstimatePersonCardHeight($large) + $cardSpacing)) + 1.2;
}

function orgPdfEstimateDepartmentColumnHeight(array $node): float
{
    $height = orgPdfEstimatePosterBoxHeight((array)($node['people'] ?? []));
    $children = array_values((array)($node['children'] ?? []));

    if (count($children) === 2) {
        $left = orgPdfEstimatePosterBoxHeight((array)($children[0]['people'] ?? []));
        $right = orgPdfEstimatePosterBoxHeight((array)($children[1]['people'] ?? []));
        $height += max($left, $right) + 4;
    } else {
        foreach ($children as $child) {
            $height += orgPdfEstimatePosterBoxHeight((array)($child['people'] ?? [])) + 4;
        }
    }

    return $height;
}

function orgPdfDrawPersonCard(TCPDF $pdf, float $x, float $y, float $w, array $person, bool $large = false): void
{
    $cardH = orgPdfEstimatePersonCardHeight($large);
    $avatarSize = $large ? 8.4 : 6.8;
    $avatarX = $x + 1.0;
    $avatarY = $y + (($cardH - $avatarSize) / 2);

    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(17, 17, 17);
    $pdf->Rect($x, $y, $w, $cardH, 'DF');

    $pdf->SetFillColor(139, 27, 27);
    $pdf->Rect($avatarX, $avatarY, $avatarSize, $avatarSize, 'F');

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', $large ? 6.1 : 5.2);
    $pdf->SetXY($avatarX, $avatarY + ($large ? 1.9 : 1.4));
    $pdf->Cell($avatarSize, 2.8, strtoupper((string)($person['initials'] ?? '--')), 0, 1, 'C');

    $textX = $avatarX + $avatarSize + 1.2;
    $textW = $w - ($textX - $x) - 1.0;
    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetFont('helvetica', 'B', $large ? 6.0 : 5.0);
    $pdf->SetXY($textX, $y + 1.0);
    $pdf->MultiCell($textW, 2.4, (string)($person['name'] ?? '-'), 0, 'L', false, 1, '', '', true, 0, false, true, 4.8, 'T', false);

    $pdf->SetTextColor(75, 85, 99);
    $pdf->SetFont('helvetica', '', $large ? 5.2 : 4.6);
    $pdf->SetXY($textX, $y + ($large ? 5.0 : 4.1));
    $pdf->MultiCell($textW, 2.2, (string)($person['position'] ?? '-'), 0, 'L', false, 1, '', '', true, 0, false, true, 4.6, 'T', false);
}

function orgPdfDrawPosterBox(TCPDF $pdf, float $x, float $y, float $w, float $h, string $title, array $people, bool $large = false): void
{
    $pdf->SetDrawColor(17, 17, 17);
    $pdf->SetFillColor(215, 25, 32);
    $pdf->Rect($x, $y, $w, $h, 'DF');

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', $large ? 7.1 : 6.0);
    $pdf->SetXY($x + 1.2, $y + 0.9);
    $pdf->MultiCell($w - 2.4, 3.1, strtoupper($title), 0, 'L', false, 1, '', '', true, 0, false, true, 3.5, 'T', false);

    $cardX = $x + 0.9;
    $cardW = $w - 1.8;
    $cursorY = $y + ($large ? 4.9 : 4.2);
    $cards = $people !== [] ? $people : [[
        'name' => 'Belum Ada',
        'position' => 'Manager aktif belum tersedia',
        'initials' => '--',
    ]];

    foreach ($cards as $person) {
        orgPdfDrawPersonCard($pdf, $cardX, $cursorY, $cardW, $person, $large);
        $cursorY += orgPdfEstimatePersonCardHeight($large) + 0.8;
    }
}

function orgPdfDrawDepartmentColumn(TCPDF $pdf, float $x, float $y, float $w, array $node): void
{
    $cursorY = $y;
    $mainPeople = (array)($node['people'] ?? []);
    $mainHeight = orgPdfEstimatePosterBoxHeight($mainPeople);
    orgPdfDrawPosterBox($pdf, $x, $cursorY, $w, $mainHeight, (string)($node['division'] ?? ''), $mainPeople);
    $cursorY += $mainHeight + 4;

    $children = array_values((array)($node['children'] ?? []));
    if (count($children) === 2) {
        $leftPeople = (array)($children[0]['people'] ?? []);
        $rightPeople = (array)($children[1]['people'] ?? []);
        $childGap = 2.2;
        $childW = ($w - $childGap) / 2;
        $leftH = orgPdfEstimatePosterBoxHeight($leftPeople);
        $rightH = orgPdfEstimatePosterBoxHeight($rightPeople);
        $branchY = $y + $mainHeight + 1.8;
        $mainCenterX = $x + ($w / 2);
        $leftCenterX = $x + ($childW / 2);
        $rightCenterX = $x + $childW + $childGap + ($childW / 2);
        $pdf->SetDrawColor(17, 17, 17);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($mainCenterX, $y + $mainHeight, $mainCenterX, $branchY);
        $pdf->Line($leftCenterX, $branchY, $rightCenterX, $branchY);
        $pdf->Line($leftCenterX, $branchY, $leftCenterX, $cursorY);
        $pdf->Line($rightCenterX, $branchY, $rightCenterX, $cursorY);
        orgPdfDrawPosterBox($pdf, $x, $cursorY, $childW, $leftH, (string)($children[0]['division'] ?? ''), $leftPeople);
        orgPdfDrawPosterBox($pdf, $x + $childW + $childGap, $cursorY, $childW, $rightH, (string)($children[1]['division'] ?? ''), $rightPeople);
        $cursorY += max($leftH, $rightH) + 4;
    } else {
        foreach ($children as $child) {
            $childPeople = (array)($child['people'] ?? []);
            $childHeight = orgPdfEstimatePosterBoxHeight($childPeople);
            $branchY = $y + $mainHeight + 1.8;
            $mainCenterX = $x + ($w / 2);
            $childCenterX = $x + ($w / 2);
            $pdf->SetDrawColor(17, 17, 17);
            $pdf->SetLineWidth(0.4);
            $pdf->Line($mainCenterX, $y + $mainHeight, $mainCenterX, $branchY);
            $pdf->Line($childCenterX, $branchY, $childCenterX, $cursorY);
            orgPdfDrawPosterBox($pdf, $x, $cursorY, $w, $childHeight, (string)($child['division'] ?? ''), $childPeople);
            $cursorY += $childHeight + 4;
        }
    }
}

function orgRenderPosterPdfDirect(TCPDF $pdf, array $stats, array $directors, array $viceDirectors, array $orgTree, string $orientation = 'L'): void
{
    $secretaryNode = orgPosterFindNode($orgTree, 'Secretary');
    $humanCapitalNode = orgPosterFindNode($orgTree, 'Human Capital');
    $generalAffairNode = orgPosterFindNode($orgTree, 'General Affair');
    $medicalAuthorityNode = orgPosterFindNode($orgTree, 'Specialist Medical Authority');
    $ceoPeople = $directors !== [] ? $directors : $viceDirectors;

    $pageWidth = $pdf->getPageWidth();
    $centerX = $pageWidth / 2;
    $isPortrait = $orientation === 'P';

    if ($isPortrait) {
        $pdf->SetTextColor(17, 24, 39);
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetXY(0, 4);
        $pdf->Cell($pageWidth, 5, 'ROXWOOD HOSPITAL', 0, 1, 'C');

        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->SetXY(0, 9);
        $pdf->Cell($pageWidth, 6, 'ORGANIZATIONAL STRUCTURE', 0, 1, 'C');

        $ceoW = 92;
        $ceoH = orgPdfEstimatePosterBoxHeight($ceoPeople, true);
        $ceoX = $centerX - ($ceoW / 2);
        $ceoY = 18;
        orgPdfDrawPosterBox($pdf, $ceoX, $ceoY, $ceoW, $ceoH, 'Chief Executive Officer', $ceoPeople, true);

        $dirW = 72;
        $dirY = $ceoY + $ceoH + 7;
        $directorH = orgPdfEstimatePosterBoxHeight($directors);
        $deputyH = orgPdfEstimatePosterBoxHeight($viceDirectors);
        orgPdfDrawPosterBox($pdf, $centerX - ($dirW / 2), $dirY, $dirW, $directorH, 'Director', $directors);

        $deputyW = 72;
        $deputyY = $dirY + $directorH + 7;
        orgPdfDrawPosterBox($pdf, $centerX - ($deputyW / 2), $deputyY, $deputyW, $deputyH, 'Deputy Director', $viceDirectors);

        $secW = 72;
        $secretaryH = orgPdfEstimatePosterBoxHeight((array)($secretaryNode['people'] ?? []));
        $secY = $deputyY + $deputyH + 7;
        orgPdfDrawPosterBox($pdf, $centerX - ($secW / 2), $secY, $secW, $secretaryH, 'Secretary', (array)($secretaryNode['people'] ?? []));

        $pdf->SetDrawColor(17, 17, 17);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($centerX, $ceoY + $ceoH, $centerX, $dirY);
        $pdf->Line($centerX, $dirY + $directorH, $centerX, $deputyY);
        $pdf->Line($centerX, $deputyY + $deputyH, $centerX, $secY);

        $colY = $secY + $secretaryH + 8;
        orgPdfDrawDepartmentColumn($pdf, 12, $colY, $pageWidth - 24, $humanCapitalNode);
        orgPdfDrawDepartmentColumn($pdf, 12, $colY + 58, $pageWidth - 24, $generalAffairNode);
        $pdf->AddPage();
        orgPdfDrawDepartmentColumn($pdf, 12, 12, $pageWidth - 24, $medicalAuthorityNode);
        return;
    }

    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetXY(0, 4);
    $pdf->Cell($pageWidth, 5, 'ROXWOOD HOSPITAL', 0, 1, 'C');

    $pdf->SetFont('helvetica', 'B', 15.5);
    $pdf->SetXY(0, 9);
    $pdf->Cell($pageWidth, 6, 'ORGANIZATIONAL STRUCTURE', 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 5.9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(0, 15);
    $pdf->Cell($pageWidth, 4, 'Executive: ' . (int)$stats['executive'] . '   |   Divisi Aktif: ' . (int)$stats['division'] . '   |   Total Manager: ' . (int)$stats['manager'], 0, 1, 'C');

    $ceoW = 76;
    $ceoH = orgPdfEstimatePosterBoxHeight($ceoPeople, true);
    $ceoX = $centerX - ($ceoW / 2);
    $ceoY = 16;
    orgPdfDrawPosterBox($pdf, $ceoX, $ceoY, $ceoW, $ceoH, 'Chief Executive Officer', $ceoPeople, true);

    $leadW = 54;
    $leadY = $ceoY + $ceoH + 5;
    $leadHLeft = orgPdfEstimatePosterBoxHeight($directors);
    $leadHRight = orgPdfEstimatePosterBoxHeight($viceDirectors);
    $leadX = $centerX - ($leadW / 2);
    orgPdfDrawPosterBox($pdf, $leadX, $leadY, $leadW, $leadHLeft, 'Director', $directors);

    $deputyW = 54;
    $deputyY = $leadY + $leadHLeft + 5;
    $deputyX = $centerX - ($deputyW / 2);
    orgPdfDrawPosterBox($pdf, $deputyX, $deputyY, $deputyW, $leadHRight, 'Deputy Director', $viceDirectors);

    $pdf->SetDrawColor(17, 17, 17);
    $pdf->SetLineWidth(0.4);
    $pdf->Line($centerX, $ceoY + $ceoH, $centerX, $leadY);
    $pdf->Line($centerX, $leadY + $leadHLeft, $centerX, $deputyY);

    $secretaryPeople = (array)($secretaryNode['people'] ?? []);
    $secretaryH = orgPdfEstimatePosterBoxHeight($secretaryPeople);
    $secretaryW = 48;
    $secretaryX = $centerX - ($secretaryW / 2);
    $secretaryY = $deputyY + $leadHRight + 5;
    $pdf->Line($centerX, $deputyY + $leadHRight, $centerX, $secretaryY);
    orgPdfDrawPosterBox($pdf, $secretaryX, $secretaryY, $secretaryW, $secretaryH, 'Secretary', $secretaryPeople);

    $deptTopY = $secretaryY + $secretaryH + 6;
    $columnW = 72;
    $columnGap = 4;
    $startX = ($pageWidth - (($columnW * 3) + ($columnGap * 2))) / 2;
    $x1 = $startX;
    $x2 = $x1 + $columnW + $columnGap;
    $x3 = $x2 + $columnW + $columnGap;

    orgPdfDrawDepartmentColumn($pdf, $x1, $deptTopY, $columnW, $humanCapitalNode);
    orgPdfDrawDepartmentColumn($pdf, $x2, $deptTopY, $columnW, $generalAffairNode);
    orgPdfDrawDepartmentColumn($pdf, $x3, $deptTopY, $columnW, $medicalAuthorityNode);

    $branchDeptY = $deptTopY - 4;
    $pdf->SetDrawColor(17, 17, 17);
    $pdf->SetLineWidth(0.5);
    $pdf->Line($centerX, $secretaryY + $secretaryH, $centerX, $branchDeptY);
    $pdf->Line($x1 + ($columnW / 2), $branchDeptY, $x3 + ($columnW / 2), $branchDeptY);
    $pdf->Line($x1 + ($columnW / 2), $branchDeptY, $x1 + ($columnW / 2), $deptTopY);
    $pdf->Line($x2 + ($columnW / 2), $branchDeptY, $x2 + ($columnW / 2), $deptTopY);
    $pdf->Line($x3 + ($columnW / 2), $branchDeptY, $x3 + ($columnW / 2), $deptTopY);
}

$errors = [];
$directors = [];
$viceDirectors = [];
$secretaries = [];
$divisionManagers = [];
$orgTree = [];
$stats = [
    'executive' => 0,
    'division' => 0,
    'manager' => 0,
];

try {
    if (!orgHasColumn($pdo, 'division')) {
        throw new RuntimeException('Kolom division belum tersedia pada tabel user_rh.');
    }

    $rows = $pdo->query("
        SELECT
            id,
            full_name,
            position,
            role,
            division,
            is_active,
            tanggal_masuk
        FROM user_rh
        WHERE is_active = 1
          AND role IS NOT NULL
          AND role <> ''
          AND id <> 5
          AND LOWER(TRIM(full_name)) <> 'admin'
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if (orgIsExcludedUser($row['full_name'] ?? '')) {
            continue;
        }

        $role = ems_normalize_role($row['role'] ?? '');
        $division = ems_normalize_division($row['division'] ?? '');

        if (!ems_is_manager_plus_role($role)) {
            continue;
        }

        if ($division === '' || $division === 'Medis') {
            continue;
        }

        $item = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => trim((string) ($row['full_name'] ?? 'Tanpa Nama')),
            'position' => ems_position_label($row['position'] ?? ''),
            'role' => ems_role_label($role),
            'division' => $division,
            'initials' => initialsFromName((string) ($row['full_name'] ?? '')),
            'avatar_color' => avatarColorFromName((string) ($row['full_name'] ?? '')),
            'join_duration' => orgJoinDuration($row['tanggal_masuk'] ?? null),
        ];

        if ($division === 'Executive' && $role === 'director') {
            $directors[] = $item;
            continue;
        }

        if ($division === 'Executive' && $role === 'vice director') {
            $viceDirectors[] = $item;
            continue;
        }

        if ($division === 'Secretary') {
            $secretaries[] = $item;
            continue;
        }

        $divisionManagers[$division][] = $item;
    }

    $sortPeople = static function (array &$people): void {
        usort($people, static function (array $left, array $right): int {
            $roleCompare = orgRoleRank($left['role'] ?? '') <=> orgRoleRank($right['role'] ?? '');
            if ($roleCompare !== 0) {
                return $roleCompare;
            }

            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });
    };

    $sortPeople($directors);
    $sortPeople($viceDirectors);
    $sortPeople($secretaries);

    foreach ($divisionManagers as $division => &$people) {
        $sortPeople($people);
    }
    unset($people);

    uksort($divisionManagers, static function (string $left, string $right): int {
        $rankCompare = orgDivisionRank($left) <=> orgDivisionRank($right);
        if ($rankCompare !== 0) {
            return $rankCompare;
        }

        return strcmp($left, $right);
    });

    $orgTree = [
        [
            'type' => 'single',
            'division' => 'Secretary',
            'people' => $secretaries,
        ],
        [
            'type' => 'branch',
            'division' => 'Human Capital',
            'people' => $divisionManagers['Human Capital'] ?? [],
            'children' => [
                [
                    'division' => 'Human Resource',
                    'people' => $divisionManagers['Human Resource'] ?? [],
                ],
                [
                    'division' => 'Disciplinary Committee',
                    'people' => $divisionManagers['Disciplinary Committee'] ?? [],
                ],
            ],
        ],
        [
            'type' => 'single',
            'division' => 'General Affair',
            'people' => $divisionManagers['General Affair'] ?? [],
        ],
        [
            'type' => 'branch',
            'division' => 'Specialist Medical Authority',
            'people' => $divisionManagers['Specialist Medical Authority'] ?? [],
            'children' => [
                [
                    'division' => 'Forensic',
                    'people' => $divisionManagers['Forensic'] ?? [],
                ],
            ],
        ],
    ];

    $visibleDivisionCount = 0;
    foreach ($orgTree as $node) {
        if (($node['people'] ?? []) !== []) {
            $visibleDivisionCount++;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($child['people'] ?? []) !== []) {
                $visibleDivisionCount++;
            }
        }
    }

    $stats['executive'] = count($directors) + count($viceDirectors);
    $stats['division'] = $visibleDivisionCount;
    $stats['manager'] = $stats['executive']
        + count($secretaries)
        + array_sum(array_map(static fn(array $people): int => count($people), $divisionManagers));
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat struktur organisasi: ' . $e->getMessage();
}

if ($isPdfPreview) {
    require_once __DIR__ . '/../vendor/autoload.php';

    try {
        $pdf = new TCPDF($pdfOrientation, 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('EMS');
        $pdf->SetAuthor('EMS');
        $pdf->SetTitle('Struktur Organisasi');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(7, 6, 7);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();
        orgRenderPosterPdfDirect($pdf, $stats, $directors, $viceDirectors, $orgTree, $pdfOrientation);
        $pdf->Output('struktur-organisasi.pdf', 'I');
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Gagal membuat PDF struktur organisasi: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
    exit;
}

if (!$isPdfPreview) {
    include __DIR__ . '/../partials/header.php';
    include __DIR__ . '/../partials/sidebar.php';
}
?>
<section class="content">
    <div class="page page-shell org-page">
        <h1 class="page-title">Struktur Organisasi</h1>
        <p class="page-subtitle">Bagan manager dari level executive sampai divisi operasional. Role staff dan divisi medis tidak ditampilkan.</p>

        <div class="org-action-row">
            <a href="struktur_organisasi.php?view=pdf&orientation=L" target="_blank" rel="noopener" class="btn-primary">
                <?= ems_icon('printer', 'h-4 w-4') ?>
                <span>Preview PDF Landscape</span>
            </a>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="org-hero">
            <div>
                <div class="org-kicker">Landscape View</div>
                <h2 class="org-hero-title">Struktur organisasi manager dengan urutan komando yang tetap.</h2>
                <p class="org-hero-copy">Susunan dibaca dari Director, turun ke Vice Director, lalu Secretary, kemudian bercabang ke Human Capital, General Affair, dan Specialist Medical Authority beserta turunan divisinya.</p>
            </div>
            <div class="org-stats">
                <article class="org-stat-card">
                    <span class="org-stat-label">Executive</span>
                    <strong class="org-stat-value"><?= (int) $stats['executive'] ?></strong>
                </article>
                <article class="org-stat-card">
                    <span class="org-stat-label">Divisi Aktif</span>
                    <strong class="org-stat-value"><?= (int) $stats['division'] ?></strong>
                </article>
                <article class="org-stat-card">
                    <span class="org-stat-label">Total Manager</span>
                    <strong class="org-stat-value"><?= (int) $stats['manager'] ?></strong>
                </article>
            </div>
        </div>

        <div class="org-board-shell">
            <div class="org-board">
                <section class="org-executive-section">
                    <div class="org-section-heading">
                        <span class="org-section-pill"><?= ems_icon('user-group', 'h-4 w-4') ?> Executive</span>
                    </div>

                    <?php if ($directors !== [] || $viceDirectors !== []): ?>
                        <div class="org-command-flow">
                            <div class="org-command-row is-director">
                                <?php foreach ($directors as $leader): ?>
                                    <article class="org-person-card is-executive is-top-level">
                                        <div class="org-person-topline">
                                            <span class="org-role-badge"><?= htmlspecialchars($leader['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="org-division-chip"><?= htmlspecialchars($leader['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="org-person-main">
                                            <div class="org-avatar" style="--avatar: <?= htmlspecialchars($leader['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                <?= htmlspecialchars($leader['initials'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div>
                                                <h3 class="org-person-name"><?= htmlspecialchars($leader['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                <p class="org-person-meta"><?= htmlspecialchars($leader['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                            </div>
                                        </div>
                                        <div class="org-person-foot">
                                            <span>Masa aktif <?= htmlspecialchars($leader['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <div class="org-connector-vertical"><span></span></div>

                            <div class="org-command-row is-vice-director">
                                <?php foreach ($viceDirectors as $leader): ?>
                                    <article class="org-person-card is-executive">
                                        <div class="org-person-topline">
                                            <span class="org-role-badge"><?= htmlspecialchars($leader['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="org-division-chip"><?= htmlspecialchars($leader['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="org-person-main">
                                            <div class="org-avatar" style="--avatar: <?= htmlspecialchars($leader['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                <?= htmlspecialchars($leader['initials'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div>
                                                <h3 class="org-person-name"><?= htmlspecialchars($leader['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                <p class="org-person-meta"><?= htmlspecialchars($leader['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                            </div>
                                        </div>
                                        <div class="org-person-foot">
                                            <span>Masa aktif <?= htmlspecialchars($leader['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <p class="meta-text">Belum ada data executive aktif.</p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="org-divisions-section">
                    <?php if ($orgTree !== []): ?>
                        <div class="org-tree-layout">
                            <div class="org-secretary-row">
                                <?php foreach ($orgTree as $node): ?>
                                    <?php if (($node['division'] ?? '') !== 'Secretary') continue; ?>
                                    <article class="org-tree-node is-secretary">
                                        <div class="org-division-connector" aria-hidden="true"></div>
                                        <header class="org-division-head">
                                            <span class="org-division-kicker">Divisi</span>
                                            <h3><?= htmlspecialchars($node['division'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <p><?= count($node['people']) ?> manager aktif</p>
                                        </header>
                                        <div class="org-person-stack">
                                            <?php foreach ($node['people'] as $person): ?>
                                                <article class="org-person-card">
                                                    <div class="org-person-topline">
                                                        <span class="org-role-badge"><?= htmlspecialchars($person['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <span class="org-division-chip"><?= htmlspecialchars($person['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                    <div class="org-person-main">
                                                        <div class="org-avatar" style="--avatar: <?= htmlspecialchars($person['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                            <?= htmlspecialchars($person['initials'], ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                        <div>
                                                            <h4 class="org-person-name"><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                            <p class="org-person-meta"><?= htmlspecialchars($person['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="org-person-foot">
                                                        <span>Masa aktif <?= htmlspecialchars($person['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <div class="org-connector-hub" aria-hidden="true">
                                <span></span>
                            </div>

                            <div class="org-parent-row">
                                <?php foreach ($orgTree as $node): ?>
                                    <?php if (($node['division'] ?? '') === 'Secretary') continue; ?>
                                    <article class="org-tree-node <?= ($node['type'] ?? '') === 'branch' ? 'has-children' : 'is-single' ?>">
                                        <div class="org-division-connector" aria-hidden="true"></div>
                                        <header class="org-division-head">
                                            <span class="org-division-kicker">Divisi</span>
                                            <h3><?= htmlspecialchars($node['division'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <p><?= count($node['people']) ?> manager aktif</p>
                                        </header>

                                        <div class="org-person-stack">
                                            <?php foreach ($node['people'] as $person): ?>
                                                <article class="org-person-card">
                                                    <div class="org-person-topline">
                                                        <span class="org-role-badge"><?= htmlspecialchars($person['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <span class="org-division-chip"><?= htmlspecialchars($person['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                    <div class="org-person-main">
                                                        <div class="org-avatar" style="--avatar: <?= htmlspecialchars($person['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                            <?= htmlspecialchars($person['initials'], ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                        <div>
                                                            <h4 class="org-person-name"><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                            <p class="org-person-meta"><?= htmlspecialchars($person['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="org-person-foot">
                                                        <span>Masa aktif <?= htmlspecialchars($person['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if (($node['children'] ?? []) !== []): ?>

                                            <div class="org-child-grid child-count-<?= min(3, max(1, count($node['children']))) ?>">
                                                <?php foreach ($node['children'] as $child): ?>
                                                    <section class="org-child-node">
                                                        <header class="org-division-head is-child">
                                                            <span class="org-division-kicker">Sub Divisi</span>
                                                            <h3><?= htmlspecialchars($child['division'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                            <p><?= count($child['people']) ?> manager aktif</p>
                                                        </header>
                                                        <div class="org-person-stack">
                                                            <?php foreach ($child['people'] as $person): ?>
                                                                <article class="org-person-card is-child-card">
                                                                    <div class="org-person-topline">
                                                                        <span class="org-role-badge"><?= htmlspecialchars($person['role'], ENT_QUOTES, 'UTF-8') ?></span>
                                                                        <span class="org-division-chip"><?= htmlspecialchars($person['division'], ENT_QUOTES, 'UTF-8') ?></span>
                                                                    </div>
                                                                    <div class="org-person-main">
                                                                        <div class="org-avatar" style="--avatar: <?= htmlspecialchars($person['avatar_color'], ENT_QUOTES, 'UTF-8') ?>;">
                                                                            <?= htmlspecialchars($person['initials'], ENT_QUOTES, 'UTF-8') ?>
                                                                        </div>
                                                                        <div>
                                                                            <h4 class="org-person-name"><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                                            <p class="org-person-meta"><?= htmlspecialchars($person['position'], ENT_QUOTES, 'UTF-8') ?></p>
                                                                        </div>
                                                                    </div>
                                                                    <div class="org-person-foot">
                                                                        <span>Masa aktif <?= htmlspecialchars($person['join_duration'], ENT_QUOTES, 'UTF-8') ?></span>
                                                                    </div>
                                                                </article>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </section>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <p class="meta-text">Belum ada manager aktif di luar divisi medis.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</section>

<style>
    .org-page {
        display: grid;
        gap: 1.5rem;
    }

    .org-action-row {
        display: flex;
        justify-content: flex-end;
    }

    .org-hero {
        position: relative;
        overflow: hidden;
        display: grid;
        grid-template-columns: minmax(0, 1.8fr) minmax(320px, 1fr);
        gap: 1.25rem;
        padding: 1.5rem;
        border: 1px solid rgba(14, 165, 233, 0.16);
        border-radius: 1.5rem;
        background:
            radial-gradient(circle at top left, rgba(14, 165, 233, 0.18), transparent 38%),
            linear-gradient(135deg, #f8fcff 0%, #eef7fb 48%, #f8fbf6 100%);
        box-shadow: 0 22px 48px rgba(15, 23, 42, 0.08);
    }

    .org-kicker,
    .org-division-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #0f766e;
    }

    .org-hero-title {
        margin: 0.45rem 0 0.55rem;
        font-size: clamp(1.5rem, 2vw, 2.2rem);
        line-height: 1.1;
        color: #0f172a;
    }

    .org-hero-copy {
        max-width: 54rem;
        margin: 0;
        color: #475569;
    }

    .org-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.85rem;
        align-self: start;
    }

    .org-stat-card {
        padding: 1rem;
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 1.1rem;
        background: rgba(255, 255, 255, 0.86);
        backdrop-filter: blur(12px);
    }

    .org-stat-label {
        display: block;
        font-size: 0.78rem;
        color: #64748b;
    }

    .org-stat-value {
        display: block;
        margin-top: 0.35rem;
        font-size: 1.8rem;
        color: #0f172a;
    }

    .org-board-shell {
        overflow-x: auto;
        padding-bottom: 0.5rem;
    }

    .org-board {
        min-width: 1180px;
        display: grid;
        gap: 1rem;
        padding: 1.25rem;
        border-radius: 1.5rem;
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98)),
            linear-gradient(135deg, rgba(15, 23, 42, 0.03), rgba(14, 165, 233, 0.03));
        border: 1px solid #dbe7ef;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
    }

    .org-section-heading {
        display: flex;
        justify-content: center;
        margin-bottom: 1rem;
    }

    .org-section-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.55rem 0.9rem;
        border-radius: 999px;
        background: #0f172a;
        color: #f8fafc;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .org-command-flow {
        display: grid;
        gap: 0.8rem;
    }

    .org-command-row {
        display: flex;
        justify-content: center;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .org-command-row.is-director .org-person-card {
        width: min(100%, 360px);
    }

    .org-command-row.is-vice-director .org-person-card {
        width: min(100%, 340px);
    }

    .org-connector-vertical {
        display: flex;
        justify-content: center;
        height: 1.6rem;
    }

    .org-connector-vertical span {
        display: inline-block;
        width: 4px;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(180deg, #0ea5e9, #14b8a6);
    }

    .org-connector-hub {
        display: flex;
        justify-content: center;
        height: 2.6rem;
    }

    .org-connector-hub span {
        position: relative;
        display: inline-block;
        width: min(100%, 780px);
        height: 100%;
    }

    .org-connector-hub span::before,
    .org-connector-hub span::after {
        content: "";
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        background: linear-gradient(180deg, #0ea5e9, #14b8a6);
        border-radius: 999px;
    }

    .org-connector-hub span::before {
        top: 0;
        width: 4px;
        height: 1.1rem;
    }

    .org-connector-hub span::after {
        top: 1.05rem;
        width: 100%;
        height: 4px;
    }

    .org-tree-layout {
        display: grid;
        gap: 1rem;
    }

    .org-secretary-row {
        display: flex;
        justify-content: center;
    }

    .org-parent-row {
        display: grid;
        grid-template-columns: minmax(320px, 1.1fr) minmax(260px, 0.82fr) minmax(320px, 1.1fr);
        gap: 1rem;
        align-items: start;
        justify-content: center;
    }

    .org-tree-node {
        position: relative;
        display: grid;
        gap: 0.9rem;
        max-width: 360px;
        width: 100%;
        margin: 0 auto;
    }

    .org-division-connector {
        display: flex;
        justify-content: center;
        height: 1.4rem;
    }

    .org-division-connector::before {
        content: "";
        width: 4px;
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(180deg, #0ea5e9, #14b8a6);
    }

    .org-division-head {
        padding: 0.9rem 0.95rem 0.88rem;
        border-radius: 1.25rem;
        background: linear-gradient(135deg, #dff6fb, #c7ecf7 56%, #eef7ff);
        color: #0f172a;
        border: 1px solid rgba(14, 116, 144, 0.16);
        box-shadow: 0 14px 34px rgba(15, 23, 42, 0.16);
    }

    .org-division-head h3 {
        margin: 0.25rem 0 0.25rem;
        font-size: 1.02rem;
        color: #0f172a;
    }

    .org-division-head p {
        margin: 0;
        color: #334155;
        font-size: 0.84rem;
        font-weight: 600;
    }

    .org-division-head.is-child {
        background: linear-gradient(135deg, #f5fbff, #e4f2fb);
        border-style: dashed;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }

    .org-person-stack {
        display: grid;
        gap: 0.85rem;
    }

    .org-person-card {
        display: grid;
        gap: 0.9rem;
        padding: 0.9rem;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 1.25rem;
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98)),
            radial-gradient(circle at top right, rgba(20, 184, 166, 0.12), transparent 42%);
        box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
    }

    .org-person-card.is-executive {
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(241, 245, 249, 0.98)),
            radial-gradient(circle at top right, rgba(14, 165, 233, 0.18), transparent 40%);
        border-color: rgba(14, 165, 233, 0.2);
    }

    .org-person-card.is-top-level {
        box-shadow: 0 20px 42px rgba(2, 132, 199, 0.16);
    }

    .org-person-topline,
    .org-person-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .org-person-main {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        gap: 0.9rem;
        align-items: center;
    }

    .org-avatar {
        --avatar: #0ea5e9;
        width: 2.8rem;
        height: 2.8rem;
        border-radius: 1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        color: #fff;
        background: linear-gradient(135deg, var(--avatar), #0f172a);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.18);
    }

    .org-role-badge,
    .org-division-chip {
        display: inline-flex;
        align-items: center;
        padding: 0.28rem 0.58rem;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 700;
    }

    .org-role-badge {
        background: #0f172a;
        color: #f8fafc;
    }

    .org-division-chip {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .org-person-name {
        margin: 0;
        font-size: 0.92rem;
        color: #0f172a;
    }

    .org-person-meta,
    .org-person-foot {
        margin: 0;
        color: #475569;
        font-size: 0.76rem;
    }

.org-child-connector {
    display: flex;
    justify-content: center;
    height: 3.8rem;
    margin-top: 0.85rem;
    margin-bottom: 0.9rem;
}

    .org-child-connector span {
        position: relative;
        width: 100%;
        height: 100%;
    }

    .org-child-connector span::before,
    .org-child-connector span::after {
        content: "";
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        border-radius: 999px;
        background: linear-gradient(180deg, #22c55e, #0ea5e9);
    }

    .org-child-connector span::before {
        top: 0;
        width: 4px;
        height: 1.05rem;
    }

    .org-child-connector span::after {
        top: 1rem;
        width: calc(100% - 4.5rem);
        height: 4px;
    }

.org-child-grid {
    display: grid;
    gap: 0.9rem;
    align-items: start;
    justify-items: center;
    padding-top: 0.65rem;
}

    .org-child-grid.child-count-1 {
        grid-template-columns: 1fr;
    }

    .org-child-grid.child-count-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .org-child-grid.child-count-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .org-child-node {
        position: relative;
        display: grid;
        gap: 0.8rem;
        width: 100%;
        max-width: 320px;
    }

.org-child-node::before {
    content: "";
    position: absolute;
    top: -1.45rem;
    left: 50%;
    transform: translateX(-50%);
    width: 4px;
    height: 1.25rem;
    border-radius: 999px;
    background: linear-gradient(180deg, #22c55e, #0ea5e9);
}

    .org-child-node .org-person-stack {
        gap: 0.75rem;
    }

    .org-person-card.is-child-card {
        background:
            linear-gradient(180deg, rgba(255, 255, 255, 1), rgba(248, 250, 252, 1)),
            radial-gradient(circle at top right, rgba(56, 189, 248, 0.1), transparent 40%);
    }

    @media (max-width: 1100px) {
        .org-hero {
            grid-template-columns: 1fr;
        }

        .org-stats {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .org-parent-row {
            grid-template-columns: 1fr;
        }

        .org-board {
            min-width: 0;
        }

        .org-child-grid.child-count-2,
        .org-child-grid.child-count-3 {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 720px) {
        .org-stats {
            grid-template-columns: 1fr;
        }
    }

    @media print {
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        .topbar,
        .sidebar,
        .sidebar-overlay {
            display: none !important;
        }

        .main-content,
        .content,
        .org-board-shell {
            overflow: visible !important;
        }

        .org-board {
            min-width: 0;
            box-shadow: none;
        }
    }
</style>
