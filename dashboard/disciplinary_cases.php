<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Disciplinary Committee'], '/dashboard/index.php');

$pageTitle = 'Kasus Komdis';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$hasPointReductionTable = false;

function disciplinaryCaseStatusLabel(string $status): string
{
    return match ($status) {
        'open' => 'Menunggu Tinjauan',
        'reviewed' => 'Sudah Ditinjau',
        'escalated' => 'Ditindaklanjuti',
        'closed' => 'Selesai',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function disciplinaryLetterStatusLabel(string $status): string
{
    return match ($status) {
        'not_needed' => 'Tidak Diperlukan',
        'pending' => 'Belum Dibuat',
        'issued' => 'Sudah Dibuat',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function disciplinaryToleranceSummaryLabel(string $value): string
{
    return match ($value) {
        'tolerable' => 'Masih Ditoleransi',
        'mixed' => 'Campuran',
        'non_tolerable' => 'Tidak Ditoleransi',
        default => ucwords(str_replace('_', ' ', $value)),
    };
}

function disciplinaryAttachmentLinksHtml(array $attachments): string
{
    if ($attachments === []) {
        return '<span class="text-muted">-</span>';
    }

    $html = '';
    foreach ($attachments as $attachment) {
        $path = '/' . ltrim((string)($attachment['file_path'] ?? ''), '/');
        $name = trim((string)($attachment['file_name'] ?? 'Lampiran'));
        $html .= '<a href="#" class="doc-badge btn-preview-doc disciplinary-attachment-link" data-src="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '" data-title="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">' . ems_icon('paper-clip', 'h-4 w-4') . '<span>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span></a>';
    }

    return $html;
}

function disciplinaryCurrentRecommendationLabel(int $activePoints, bool $hasNonTolerable): string
{
    return ems_disciplinary_recommendation_label(
        ems_disciplinary_recommendation_from_points($activePoints, $hasNonTolerable)
    );
}

$users = [];
$indications = [];
$medicRows = [];
$caseRows = [];
$reductionRows = [];
$attachmentsMap = [];
$summary = [
    'medics' => 0,
    'cases' => 0,
    'reductions' => 0,
    'active_points' => 0,
];

try {
    $hasPointReductionTable = ems_table_exists($pdo, 'disciplinary_point_reductions');

    $users = $pdo->query("
        SELECT id, full_name, role, position, division
        FROM user_rh
        WHERE is_active = 1
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $indications = $pdo->query("
        SELECT id, name, default_points, tolerance_type
        FROM disciplinary_indications
        WHERE is_active = 1
        ORDER BY default_points DESC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $caseRows = $pdo->query("
        SELECT
            dc.id,
            dc.subject_user_id,
            dc.case_code,
            dc.case_name,
            dc.case_date,
            dc.summary,
            dc.status,
            dc.total_points,
            dc.tolerable_count,
            dc.non_tolerable_count,
            dc.tolerance_summary,
            dc.recommended_action,
            dc.letter_status,
            dc.created_at,
            subject.full_name AS subject_name,
            creator.full_name AS created_by_name,
            (
                SELECT GROUP_CONCAT(dci.indication_name_snapshot ORDER BY dci.id SEPARATOR ', ')
                FROM disciplinary_case_items dci
                WHERE dci.case_id = dc.id
            ) AS indication_names
        FROM disciplinary_cases dc
        INNER JOIN user_rh subject ON subject.id = dc.subject_user_id
        INNER JOIN user_rh creator ON creator.id = dc.created_by
        ORDER BY dc.case_date DESC, dc.id DESC
        LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC);

    $reductionRows = $hasPointReductionTable
        ? $pdo->query("
            SELECT
                dpr.id,
                dpr.subject_user_id,
                dpr.related_case_id,
                dpr.reduction_type,
                dpr.reduction_points,
                dpr.activity_date,
                dpr.notes,
                dpr.created_at,
                subject.full_name AS subject_name,
                creator.full_name AS created_by_name,
                dc.case_code,
                dc.case_name
            FROM disciplinary_point_reductions dpr
            INNER JOIN user_rh subject ON subject.id = dpr.subject_user_id
            INNER JOIN user_rh creator ON creator.id = dpr.created_by
            LEFT JOIN disciplinary_cases dc ON dc.id = dpr.related_case_id
            ORDER BY dpr.activity_date DESC, dpr.id DESC
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC)
        : [];

    if ($caseRows !== [] && ems_table_exists($pdo, 'disciplinary_case_attachments')) {
        $caseIds = array_map(static fn(array $row): int => (int)$row['id'], $caseRows);
        $caseIds = array_values(array_filter($caseIds, static fn(int $id): bool => $id > 0));

        if ($caseIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($caseIds), '?'));
            $stmt = $pdo->prepare("
                SELECT id, case_id, file_name, file_path, created_at
                FROM disciplinary_case_attachments
                WHERE case_id IN ({$placeholders})
                ORDER BY id ASC
            ");
            $stmt->execute($caseIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $attachment) {
                $attachmentsMap[(int)$attachment['case_id']][] = $attachment;
            }
        }
    }

    $caseStats = [];
    foreach ($caseRows as $case) {
        $subjectUserId = (int)($case['subject_user_id'] ?? 0);
        if ($subjectUserId <= 0) {
            continue;
        }

        if (!isset($caseStats[$subjectUserId])) {
            $caseStats[$subjectUserId] = [
                'subject_user_id' => $subjectUserId,
                'subject_name' => (string)($case['subject_name'] ?? '-'),
                'case_count' => 0,
                'case_points' => 0,
                'has_non_tolerable' => false,
                'latest_case_date' => null,
                'latest_letter_status' => 'not_needed',
            ];
        }

        $caseStats[$subjectUserId]['case_count']++;
        $caseStats[$subjectUserId]['case_points'] += (int)($case['total_points'] ?? 0);
        $caseStats[$subjectUserId]['has_non_tolerable'] = $caseStats[$subjectUserId]['has_non_tolerable'] || ((int)($case['non_tolerable_count'] ?? 0) > 0);
        $caseStats[$subjectUserId]['latest_letter_status'] = (string)($case['letter_status'] ?? $caseStats[$subjectUserId]['latest_letter_status']);

        $caseDate = trim((string)($case['case_date'] ?? ''));
        if ($caseDate !== '' && ($caseStats[$subjectUserId]['latest_case_date'] === null || $caseDate > $caseStats[$subjectUserId]['latest_case_date'])) {
            $caseStats[$subjectUserId]['latest_case_date'] = $caseDate;
        }
    }

    $reductionStats = [];
    foreach ($reductionRows as $reduction) {
        $subjectUserId = (int)($reduction['subject_user_id'] ?? 0);
        if ($subjectUserId <= 0) {
            continue;
        }

        if (!isset($reductionStats[$subjectUserId])) {
            $reductionStats[$subjectUserId] = [
                'subject_user_id' => $subjectUserId,
                'reduction_count' => 0,
                'reduction_points' => 0,
            ];
        }

        $reductionStats[$subjectUserId]['reduction_count']++;
        $reductionStats[$subjectUserId]['reduction_points'] += (int)($reduction['reduction_points'] ?? 0);
    }

    $subjectUserIds = array_values(array_unique(array_merge(array_keys($caseStats), array_keys($reductionStats))));
    sort($subjectUserIds);

    foreach ($subjectUserIds as $subjectUserId) {
        $caseStat = $caseStats[$subjectUserId] ?? [
            'subject_name' => '-',
            'case_count' => 0,
            'case_points' => 0,
            'has_non_tolerable' => false,
            'latest_case_date' => null,
            'latest_letter_status' => 'not_needed',
        ];
        $reductionStat = $reductionStats[$subjectUserId] ?? [
            'reduction_count' => 0,
            'reduction_points' => 0,
        ];

        $activePoints = max(0, (int)$caseStat['case_points'] - (int)$reductionStat['reduction_points']);
        $medicRows[] = [
            'subject_user_id' => $subjectUserId,
            'subject_name' => (string)$caseStat['subject_name'],
            'case_count' => (int)$caseStat['case_count'],
            'case_points' => (int)$caseStat['case_points'],
            'reduction_count' => (int)$reductionStat['reduction_count'],
            'reduction_points' => (int)$reductionStat['reduction_points'],
            'active_points' => $activePoints,
            'has_non_tolerable' => (bool)$caseStat['has_non_tolerable'],
            'latest_case_date' => $caseStat['latest_case_date'],
            'latest_letter_status' => (string)$caseStat['latest_letter_status'],
            'current_recommendation' => disciplinaryCurrentRecommendationLabel($activePoints, (bool)$caseStat['has_non_tolerable']),
        ];

        $summary['medics']++;
        $summary['cases'] += (int)$caseStat['case_count'];
        $summary['reductions'] += (int)$reductionStat['reduction_points'];
        $summary['active_points'] += $activePoints;
    }

    usort($medicRows, static function (array $a, array $b): int {
        if ($a['active_points'] === $b['active_points']) {
            return strcmp((string)$a['subject_name'], (string)$b['subject_name']);
        }
        return $b['active_points'] <=> $a['active_points'];
    });
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat data kasus Komdis: ' . $e->getMessage();
}

$casesByUser = [];
foreach ($caseRows as $case) {
    $casesByUser[(int)($case['subject_user_id'] ?? 0)][] = $case;
}

$reductionsByUser = [];
foreach ($reductionRows as $reduction) {
    $reductionsByUser[(int)($reduction['subject_user_id'] ?? 0)][] = $reduction;
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="disciplinary-page-head mb-4">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Halaman depan hanya menampilkan nama medis. Gunakan tombol <strong>Lihat Riwayat</strong> untuk membuka modal detail yang memuat riwayat penambahan poin, riwayat pengurangan poin sesuai SOP, total poin aktif, serta form tindak lanjut.</p>
            </div>
            <div class="disciplinary-page-actions">
                <button type="button" class="btn-success btn-open-create-case">
                    <?= ems_icon('plus', 'h-4 w-4') ?>
                    <span>Tambah Kasus Baru</span>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">Total Medis Terdata</div>
                <div class="text-2xl font-extrabold text-slate-900"><?= number_format((int)$summary['medics'], 0, ',', '.') ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Total Kasus Penambah Poin</div>
                <div class="text-2xl font-extrabold text-amber-700"><?= number_format((int)$summary['cases'], 0, ',', '.') ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Total Poin Pengurangan</div>
                <div class="text-2xl font-extrabold text-success"><?= number_format((int)$summary['reductions'], 0, ',', '.') ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Total Poin Aktif</div>
                <div class="text-2xl font-extrabold text-rose-700"><?= number_format((int)$summary['active_points'], 0, ',', '.') ?></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Alur SOP Komdis</div>
            <div class="disciplinary-sop-grid">
                <div class="disciplinary-sop-card">
                    <div class="disciplinary-sop-step">1</div>
                    <div>
                        <div class="disciplinary-sop-title">Kasus Ditambahkan</div>
                        <div class="meta-text-xs">Setiap pelanggaran medis dicatat sebagai kasus baru dengan satu atau beberapa indikasi pelanggaran.</div>
                    </div>
                </div>
                <div class="disciplinary-sop-card">
                    <div class="disciplinary-sop-step">2</div>
                    <div>
                        <div class="disciplinary-sop-title">Poin Terakumulasi</div>
                        <div class="meta-text-xs">Poin aktif dihitung dari total poin pelanggaran dikurangi aktivitas pengurangan poin yang disetujui Komdis.</div>
                    </div>
                </div>
                <div class="disciplinary-sop-card">
                    <div class="disciplinary-sop-step">3</div>
                    <div>
                        <div class="disciplinary-sop-title">Tindak Lanjut</div>
                        <div class="meta-text-xs">Rekomendasi pembinaan, teguran, atau SP akan mengikuti total poin aktif dan tingkat toleransi pelanggaran.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <?php foreach (ems_disciplinary_point_reduction_options() as $key => $option): ?>
                <div class="card card-section">
                    <div class="meta-text-xs"><?= htmlspecialchars((string)$option['label'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-lg font-extrabold text-primary">-<?= (int)$option['points'] ?> poin</div>
                    <div class="meta-text-xs mt-1">Metode pengurangan poin sesuai SOP Komdis.</div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-header">Daftar Medis Dalam Pemantauan Komdis</div>
            <div class="table-wrapper">
                <table id="disciplinaryMedicTable" class="table-custom disciplinary-medic-table">
                    <thead>
                        <tr>
                            <th>Nama Medis</th>
                            <th>Total Kasus</th>
                            <th>Total Penambahan</th>
                            <th>Total Pengurangan</th>
                            <th>Poin Aktif</th>
                            <th>Rekomendasi Saat Ini</th>
                            <th>Status Surat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medicRows as $row): ?>
                            <tr>
                                <td>
                                    <div class="disciplinary-name-cell">
                                        <div class="disciplinary-name-label">
                                            <?= htmlspecialchars((string)$row['subject_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <?php if (!empty($row['latest_case_date'])): ?>
                                            <div class="meta-text-xs">Kasus terakhir: <?= htmlspecialchars(formatTanggalIndo((string)$row['latest_case_date']), ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= number_format((int)$row['case_count'], 0, ',', '.') ?></td>
                                <td><?= number_format((int)$row['case_points'], 0, ',', '.') ?> poin</td>
                                <td><?= number_format((int)$row['reduction_points'], 0, ',', '.') ?> poin</td>
                                <td><strong><?= number_format((int)$row['active_points'], 0, ',', '.') ?> poin</strong></td>
                                <td><?= htmlspecialchars((string)$row['current_recommendation'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(disciplinaryLetterStatusLabel((string)$row['latest_letter_status']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn-secondary btn-sm btn-open-disciplinary-detail"
                                        data-template-id="disciplinary-detail-<?= (int)$row['subject_user_id'] ?>"
                                        data-modal-title="Detail Komdis Medis"
                                        data-modal-subtitle="<?= htmlspecialchars((string)$row['subject_name'], ENT_QUOTES, 'UTF-8') ?>">
                                        Lihat Riwayat
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($medicRows === []): ?>
                    <div class="muted-placeholder p-4">Belum ada data medis yang memiliki kasus atau pengurangan poin Komdis.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php foreach ($medicRows as $row): ?>
    <?php
    $subjectUserId = (int)$row['subject_user_id'];
    $medicCases = $casesByUser[$subjectUserId] ?? [];
    $medicReductions = $reductionsByUser[$subjectUserId] ?? [];
    ob_start();
    ?>
    <div class="disciplinary-detail-shell">
        <div class="disciplinary-callout">
            <strong>Petunjuk:</strong> Di modal ini Anda bisa melihat seluruh riwayat poin medis, menambah kasus baru yang menambah poin, dan mencatat aktivitas pengurangan poin sesuai SOP Komdis.
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
            <div class="card card-section mb-0">
                <div class="meta-text-xs">Kasus</div>
                <div class="text-lg font-extrabold text-slate-900"><?= number_format((int)$row['case_count'], 0, ',', '.') ?></div>
            </div>
            <div class="card card-section mb-0">
                <div class="meta-text-xs">Poin Pelanggaran</div>
                <div class="text-lg font-extrabold text-amber-700"><?= number_format((int)$row['case_points'], 0, ',', '.') ?></div>
            </div>
            <div class="card card-section mb-0">
                <div class="meta-text-xs">Poin Pengurangan</div>
                <div class="text-lg font-extrabold text-success"><?= number_format((int)$row['reduction_points'], 0, ',', '.') ?></div>
            </div>
            <div class="card card-section mb-0">
                <div class="meta-text-xs">Poin Aktif</div>
                <div class="text-lg font-extrabold text-rose-700"><?= number_format((int)$row['active_points'], 0, ',', '.') ?></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Riwayat Penambahan Poin</div>
            <?php if ($medicCases === []): ?>
                <div class="muted-placeholder">Belum ada kasus untuk medis ini.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table-custom disciplinary-detail-table">
                        <thead>
                            <tr>
                                <th>Kasus</th>
                                <th>Indikasi</th>
                                <th>Poin</th>
                                <th>Toleransi</th>
                                <th>Lampiran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($medicCases as $case): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$case['case_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string)$case['case_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="meta-text-xs"><?= htmlspecialchars(formatTanggalIndo((string)$case['case_date']), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="meta-text-xs">Input: <?= htmlspecialchars((string)$case['created_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($case['summary'])): ?>
                                            <div class="meta-text-xs"><?= htmlspecialchars((string)$case['summary'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)($case['indication_names'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= number_format((int)$case['total_points'], 0, ',', '.') ?> poin</strong>
                                        <div class="meta-text-xs">Toleran: <?= (int)$case['tolerable_count'] ?> | Tidak toleran: <?= (int)$case['non_tolerable_count'] ?></div>
                                    </td>
                                    <td><?= htmlspecialchars(disciplinaryToleranceSummaryLabel((string)$case['tolerance_summary']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= disciplinaryAttachmentLinksHtml($attachmentsMap[(int)$case['id']] ?? []) ?></td>
                                    <td class="table-actions">
                                        <form method="POST" action="disciplinary_committee_action.php" class="inline js-delete-case" data-confirm="Yakin ingin menghapus kasus ini? Surat peringatan terkait akan ikut terhapus, dan relasi pengurangan poin akan dilepas dari kasus ini.">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_case">
                                            <input type="hidden" name="redirect_to" value="disciplinary_cases.php">
                                            <input type="hidden" name="id" value="<?= (int)$case['id'] ?>">
                                            <button type="submit" class="btn-danger action-icon-btn" title="Hapus kasus" aria-label="Hapus kasus">
                                                <?= ems_icon('trash', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card mb-4">
            <div class="card-header">Riwayat Pengurangan Poin</div>
            <?php if ($medicReductions === []): ?>
                <div class="muted-placeholder">Belum ada pengurangan poin untuk medis ini.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table-custom disciplinary-detail-table">
                        <thead>
                            <tr>
                                <th>Tanggal Aktivitas</th>
                                <th>Metode Pengurangan</th>
                                <th>Poin</th>
                                <th>Kasus Terkait</th>
                                <th>Catatan</th>
                                <th>Dicatat Oleh</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($medicReductions as $reduction): ?>
                                <tr>
                                    <td><?= htmlspecialchars(formatTanggalIndo((string)$reduction['activity_date']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(ems_disciplinary_point_reduction_label((string)$reduction['reduction_type']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>-<?= number_format((int)$reduction['reduction_points'], 0, ',', '.') ?> poin</td>
                                    <td>
                                        <?php if (!empty($reduction['case_code'])): ?>
                                            <strong><?= htmlspecialchars((string)$reduction['case_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="meta-text-xs"><?= htmlspecialchars((string)$reduction['case_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)($reduction['notes'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?= htmlspecialchars((string)$reduction['created_by_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <div class="meta-text-xs"><?= htmlspecialchars(formatTanggalID((string)$reduction['created_at']), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td class="table-actions">
                                        <form method="POST" action="disciplinary_committee_action.php" class="inline js-delete-reduction" data-confirm="Yakin ingin menghapus riwayat pengurangan poin ini? Total poin aktif medis akan otomatis kembali bertambah.">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_point_reduction">
                                            <input type="hidden" name="redirect_to" value="disciplinary_cases.php">
                                            <input type="hidden" name="id" value="<?= (int)$reduction['id'] ?>">
                                            <button type="submit" class="btn-danger action-icon-btn" title="Hapus pengurangan poin" aria-label="Hapus pengurangan poin">
                                                <?= ems_icon('trash', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="card mb-0">
                <div class="card-header">Tambah Kasus Baru Untuk Medis Ini</div>
                <form method="POST" action="disciplinary_committee_action.php" class="form disciplinary-case-inline-form" enctype="multipart/form-data">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="create_case">
                    <input type="hidden" name="redirect_to" value="disciplinary_cases.php">
                    <input type="hidden" name="subject_user_id" value="<?= $subjectUserId ?>">

                    <label>Nama Kasus</label>
                    <input type="text" name="case_name" required placeholder="Contoh: Pelanggaran SOP tindakan mayor">

                    <label>Tanggal Kejadian</label>
                    <input type="date" name="case_date" value="<?= date('Y-m-d') ?>" required>

                    <label>Kronologi Singkat</label>
                    <textarea name="summary" rows="3" placeholder="Tuliskan kronologi singkat pelanggaran."></textarea>

                    <div class="card card-subtle mt-4">
                        <div class="card-header">Indikasi Pelanggaran</div>
                        <div class="disciplinary-items-dynamic">
                            <div class="disciplinary-item-row grid grid-cols-1 gap-3 mb-3">
                                <select name="indication_id[]" class="disciplinary-indication-select" required>
                                    <option value="">Pilih indikasi pelanggaran</option>
                                    <?php foreach ($indications as $indication): ?>
                                        <option
                                            value="<?= (int)$indication['id'] ?>"
                                            data-points="<?= (int)$indication['default_points'] ?>"
                                            data-tolerance="<?= htmlspecialchars((string)$indication['tolerance_type'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)$indication['name'], ENT_QUOTES, 'UTF-8') ?> | <?= (int)$indication['default_points'] ?> poin | <?= htmlspecialchars(ems_disciplinary_tolerance_options()[$indication['tolerance_type']] ?? (string)$indication['tolerance_type'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <textarea name="item_notes[]" rows="2" placeholder="Catatan tambahan untuk indikasi ini"></textarea>
                            </div>
                        </div>
                        <button type="button" class="btn-secondary btn-sm btn-add-disciplinary-item" data-target=".disciplinary-items-dynamic">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Tambah Indikasi</span>
                        </button>
                    </div>

                    <label class="mt-4">Lampiran Bukti (Opsional)</label>
                    <input type="file" name="attachments[]" accept=".jpg,.jpeg,.png,.pdf" multiple>
                    <div class="meta-text-xs">Foto akan dicoba dikompres otomatis. Ukuran akhir wajib maksimal <?= htmlspecialchars(emsDisciplinaryAttachmentMaxLabel(), ENT_QUOTES, 'UTF-8') ?> per file. PDF wajib dikompres manual bila masih di atas batas.</div>

                    <div class="request-info-box mt-4">
                        <div><strong>Total Poin:</strong> <span class="disciplinary-total-points">0</span></div>
                        <div><strong>Indikasi Tidak Ditoleransi:</strong> <span class="disciplinary-non-tolerable-count">0</span></div>
                        <div><strong>Rekomendasi Saat Ini:</strong> <span class="disciplinary-recommendation">Pembinaan</span></div>
                    </div>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-success">Simpan Kasus</button>
                    </div>
                </form>
            </div>

            <div class="card mb-0">
                <div class="card-header">Tambah Pengurangan Poin</div>
                <?php if (!$hasPointReductionTable): ?>
                    <div class="disciplinary-callout disciplinary-callout-warning">
                        Tabel pengurangan poin belum tersedia. Jalankan SQL <code>docs/sql/43_2026-05-18_disciplinary_point_reductions.sql</code> terlebih dahulu agar fitur ini aktif.
                    </div>
                <?php else: ?>
                    <form method="POST" action="disciplinary_committee_action.php" class="form">
                        <?= csrfField(); ?>
                        <input type="hidden" name="action" value="save_point_reduction">
                        <input type="hidden" name="redirect_to" value="disciplinary_cases.php">
                        <input type="hidden" name="subject_user_id" value="<?= $subjectUserId ?>">

                        <label>Tanggal Aktivitas Pengurangan</label>
                        <input type="date" name="activity_date" value="<?= date('Y-m-d') ?>" required>

                        <label>Metode Pengurangan Poin</label>
                        <select name="reduction_type" required>
                            <option value="">Pilih metode pengurangan</option>
                            <?php foreach (ems_disciplinary_point_reduction_options() as $value => $option): ?>
                                <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string)$option['label'], ENT_QUOTES, 'UTF-8') ?> | -<?= (int)$option['points'] ?> poin
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label>Kasus Terkait (Opsional)</label>
                        <select name="related_case_id">
                            <option value="">Tidak terkait kasus tertentu</option>
                            <?php foreach ($medicCases as $case): ?>
                                <option value="<?= (int)$case['id'] ?>">
                                    <?= htmlspecialchars((string)$case['case_code'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars((string)$case['case_name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label>Catatan</label>
                        <textarea name="notes" rows="4" placeholder="Tuliskan detail aktivitas pengurangan poin, contoh kegiatan sosial atau tindakan medis yang disetujui Komdis."></textarea>

                        <div class="request-info-box mt-4">
                            <div><strong>Metode SOP:</strong> Pengurangan poin hanya melalui kontribusi aktif yang disetujui Komdis.</div>
                        </div>

                        <div class="modal-actions mt-4">
                            <button type="submit" class="btn-success">Simpan Pengurangan Poin</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    $detailHtml = ob_get_clean();
    ?>
    <template id="disciplinary-detail-<?= $subjectUserId ?>"><?= $detailHtml ?></template>
<?php endforeach; ?>

<div id="disciplinaryDetailModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="forensic-detail-head">
            <div class="min-w-0">
                <div id="disciplinaryDetailTitle" class="forensic-detail-title">Detail Komdis Medis</div>
                <div id="disciplinaryDetailSubtitle" class="forensic-detail-subtitle"></div>
            </div>
            <button type="button" class="modal-close-btn btn-close-disciplinary-detail" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div id="disciplinaryDetailBody" class="forensic-detail-content"></div>
        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary btn-close-disciplinary-detail">Tutup</button>
            </div>
        </div>
    </div>
</div>

<div id="disciplinaryCreateModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="forensic-detail-head">
            <div class="min-w-0">
                <div class="forensic-detail-title">Tambah Kasus Komdis Baru</div>
                <div class="forensic-detail-subtitle">Gunakan form ini untuk membuat kasus awal. Setelah itu, riwayat medis akan dikelola dari modal detail masing-masing medis.</div>
            </div>
            <button type="button" class="modal-close-btn btn-close-create-case" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="forensic-detail-content">
            <form method="POST" action="disciplinary_committee_action.php" class="form" id="disciplinaryCaseFormGlobal" enctype="multipart/form-data">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="create_case">
                <input type="hidden" name="redirect_to" value="disciplinary_cases.php">

                <label for="globalSubjectUserSearch">Pegawai / Staff Terkait</label>
                <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="all" data-autocomplete-required>
                    <input type="text" id="globalSubjectUserSearch" data-user-autocomplete-input placeholder="Ketik nama pegawai..." required>
                    <input type="hidden" id="globalSubjectUserId" name="subject_user_id" data-user-autocomplete-hidden>
                    <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                </div>

                <label for="globalCaseName">Nama Kasus</label>
                <input type="text" id="globalCaseName" name="case_name" required placeholder="Contoh: Pelanggaran absensi minggu ke-2">

                <label for="globalCaseDate">Tanggal Kejadian</label>
                <input type="date" id="globalCaseDate" name="case_date" value="<?= date('Y-m-d') ?>" required>

                <label for="globalCaseSummary">Kronologi Singkat</label>
                <textarea id="globalCaseSummary" name="summary" rows="4" placeholder="Tuliskan kronologi, saksi, dan bukti pendukung."></textarea>

                <div class="card card-subtle mt-4">
                    <div class="card-header">Indikasi Pelanggaran</div>
                    <div id="disciplinaryItemsGlobal">
                        <div class="disciplinary-item-row grid grid-cols-1 gap-3 mb-3">
                            <select name="indication_id[]" class="disciplinary-indication-select" required>
                                <option value="">Pilih indikasi pelanggaran</option>
                                <?php foreach ($indications as $indication): ?>
                                    <option
                                        value="<?= (int)$indication['id'] ?>"
                                        data-points="<?= (int)$indication['default_points'] ?>"
                                        data-tolerance="<?= htmlspecialchars((string)$indication['tolerance_type'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)$indication['name'], ENT_QUOTES, 'UTF-8') ?> | <?= (int)$indication['default_points'] ?> poin | <?= htmlspecialchars(ems_disciplinary_tolerance_options()[$indication['tolerance_type']] ?? (string)$indication['tolerance_type'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <textarea name="item_notes[]" rows="2" placeholder="Catatan tambahan untuk indikasi ini"></textarea>
                        </div>
                    </div>
                    <button type="button" class="btn-secondary btn-sm btn-add-disciplinary-item" data-target="#disciplinaryItemsGlobal">
                        <?= ems_icon('plus', 'h-4 w-4') ?>
                        <span>Tambah Indikasi</span>
                    </button>
                </div>

                <label for="globalCaseAttachments" class="mt-4">Lampiran Bukti (Opsional)</label>
                <input type="file" id="globalCaseAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,.pdf" multiple>
                <div class="meta-text-xs">Foto akan dicoba dikompres otomatis. Ukuran akhir wajib maksimal <?= htmlspecialchars(emsDisciplinaryAttachmentMaxLabel(), ENT_QUOTES, 'UTF-8') ?> per file. PDF wajib dikompres manual bila masih di atas batas.</div>

                <div class="request-info-box mt-4">
                    <div><strong>Total Poin:</strong> <span class="disciplinary-total-points">0</span></div>
                    <div><strong>Indikasi Tidak Ditoleransi:</strong> <span class="disciplinary-non-tolerable-count">0</span></div>
                    <div><strong>Rekomendasi Saat Ini:</strong> <span class="disciplinary-recommendation">Pembinaan</span></div>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <div class="modal-actions justify-end">
                <button type="button" class="btn-secondary btn-close-create-case">Batal</button>
                <button type="submit" form="disciplinaryCaseFormGlobal" class="btn-success">Simpan Kasus</button>
            </div>
        </div>
    </div>
</div>

<div id="disciplinaryAttachmentPreviewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('paper-clip', 'h-5 w-5 text-primary') ?>
                <span id="disciplinaryAttachmentPreviewTitle">Preview Lampiran</span>
            </div>
            <button type="button" class="modal-close-btn btn-close-attachment-preview" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div id="disciplinaryAttachmentPreviewBody"></div>
            <div id="disciplinaryAttachmentPreviewMessage" class="alert alert-warning hidden mt-4"></div>
            <div class="modal-actions mt-4">
                <a href="#" id="disciplinaryAttachmentPreviewDownload" class="btn-secondary hidden" target="_blank" rel="noopener noreferrer">Buka File Asli</a>
                <button type="button" class="btn-secondary btn-close-attachment-preview">Tutup</button>
            </div>
        </div>
    </div>
</div>

<style>
    .disciplinary-page-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .disciplinary-page-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .disciplinary-sop-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
    }

    .disciplinary-sop-card {
        display: grid;
        grid-template-columns: 44px minmax(0, 1fr);
        gap: 0.85rem;
        align-items: start;
        padding: 1rem;
        border-radius: 18px;
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.02), rgba(14, 165, 233, 0.08));
        border: 1px solid rgba(14, 165, 233, 0.14);
    }

    .disciplinary-sop-step {
        display: grid;
        place-items: center;
        width: 44px;
        height: 44px;
        border-radius: 14px;
        background: #0f172a;
        color: #fff;
        font-size: 18px;
        font-weight: 800;
    }

    .disciplinary-sop-title {
        color: #0f172a;
        font-size: 14px;
        font-weight: 800;
        margin-bottom: 4px;
    }

    .disciplinary-medic-table th,
    .disciplinary-medic-table td,
    .disciplinary-detail-table th,
    .disciplinary-detail-table td {
        vertical-align: top;
        white-space: normal;
    }

    .disciplinary-attachment-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin: 0 8px 8px 0;
        padding: 6px 10px;
        border-radius: 999px;
        border: 1px solid rgba(14, 165, 233, 0.24);
        background: rgba(14, 165, 233, 0.08);
        color: #075985;
        font-size: 11px;
        font-weight: 700;
        text-decoration: none;
    }

    .disciplinary-detail-shell {
        display: grid;
        gap: 1rem;
    }

    .disciplinary-name-cell {
        min-width: 220px;
    }

    .disciplinary-name-label {
        color: #0f172a;
        font-weight: 700;
    }

    .disciplinary-callout {
        padding: 14px 16px;
        border-radius: 16px;
        background: rgba(14, 165, 233, 0.08);
        border: 1px solid rgba(14, 165, 233, 0.16);
        color: #0f172a;
        font-size: 13px;
    }

    .disciplinary-callout-warning {
        background: rgba(245, 158, 11, 0.12);
        border-color: rgba(245, 158, 11, 0.24);
        color: #7c2d12;
    }

    .disciplinary-status-form {
        display: grid;
        gap: 8px;
    }

    .file-preview-image {
        width: 100%;
        max-height: 72vh;
        object-fit: contain;
        border-radius: 16px;
        background: #e2e8f0;
    }

    .file-preview-frame {
        width: 100%;
        height: 72vh;
        border: 0;
        border-radius: 16px;
        background: #fff;
    }

    @media (max-width: 768px) {
        .disciplinary-page-head {
            align-items: stretch;
        }

        .disciplinary-page-actions > * {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const indications = <?= json_encode(array_map(static function ($row) {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'points' => (int)$row['default_points'],
            'tolerance' => (string)$row['tolerance_type'],
            'tolerance_label' => (string)(ems_disciplinary_tolerance_options()[$row['tolerance_type']] ?? $row['tolerance_type']),
        ];
    }, $indications), JSON_UNESCAPED_UNICODE) ?>;
    const datatableLanguageUrl = '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
    const filePreviewUrl = '<?= htmlspecialchars(ems_url('/ajax/disciplinary_file_preview.php'), ENT_QUOTES, 'UTF-8') ?>';
    const detailModal = document.getElementById('disciplinaryDetailModal');
    const detailTitle = document.getElementById('disciplinaryDetailTitle');
    const detailSubtitle = document.getElementById('disciplinaryDetailSubtitle');
    const detailBody = document.getElementById('disciplinaryDetailBody');
    const createModal = document.getElementById('disciplinaryCreateModal');
    const attachmentPreviewModal = document.getElementById('disciplinaryAttachmentPreviewModal');
    const attachmentPreviewTitle = document.getElementById('disciplinaryAttachmentPreviewTitle');
    const attachmentPreviewBody = document.getElementById('disciplinaryAttachmentPreviewBody');
    const attachmentPreviewMessage = document.getElementById('disciplinaryAttachmentPreviewMessage');
    const attachmentPreviewDownload = document.getElementById('disciplinaryAttachmentPreviewDownload');

    function recommendationFromPoints(totalPoints) {
        if (totalPoints >= 100) return 'SP 3 - Kritis';
        if (totalPoints >= 70) return 'SP 2 - Peringatan Keras';
        if (totalPoints >= 40) return 'SP 1 - Peringatan Pertama';
        if (totalPoints >= 20) return 'Teguran Lisan';
        return 'Pembinaan';
    }

    function buildSelectOptions() {
        return ['<option value="">Pilih indikasi pelanggaran</option>'].concat(indications.map(function(item) {
            return '<option value="' + item.id + '" data-points="' + item.points + '" data-tolerance="' + item.tolerance + '">' +
                item.name + ' | ' + item.points + ' poin | ' + item.tolerance_label +
                '</option>';
        })).join('');
    }

    function escapeHtml(value) {
        return String(value || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function recalcSummary(root) {
        if (!root) return;

        let total = 0;
        let nonTolerable = 0;

        root.querySelectorAll('.disciplinary-indication-select').forEach(function(select) {
            const selected = select.options[select.selectedIndex];
            if (!selected || !selected.value) {
                return;
            }

            total += parseInt(selected.dataset.points || '0', 10);
            if ((selected.dataset.tolerance || '') === 'non_tolerable') {
                nonTolerable++;
            }
        });

        root.querySelectorAll('.disciplinary-total-points').forEach(function(el) {
            el.textContent = String(total);
        });
        root.querySelectorAll('.disciplinary-non-tolerable-count').forEach(function(el) {
            el.textContent = String(nonTolerable);
        });
        root.querySelectorAll('.disciplinary-recommendation').forEach(function(el) {
            el.textContent = recommendationFromPoints(total);
        });
    }

    function appendIndicationRow(target) {
        if (!target) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'disciplinary-item-row grid grid-cols-1 gap-3 mb-3';
        wrapper.innerHTML = '' +
            '<select name="indication_id[]" class="disciplinary-indication-select" required>' + buildSelectOptions() + '</select>' +
            '<textarea name="item_notes[]" rows="2" placeholder="Catatan tambahan untuk indikasi ini"></textarea>' +
            '<button type="button" class="btn-danger btn-sm disciplinary-remove-item">Hapus Indikasi</button>';
        target.appendChild(wrapper);
    }

    function closeDetailModal() {
        if (!detailModal || !detailBody) return;
        detailModal.classList.add('hidden');
        detailBody.innerHTML = '';
        if ((!createModal || createModal.classList.contains('hidden')) && (!attachmentPreviewModal || attachmentPreviewModal.classList.contains('hidden'))) {
            document.body.classList.remove('modal-open');
        }
    }

    function openCreateModal() {
        if (!createModal) return;
        createModal.classList.remove('hidden');
        document.body.classList.add('modal-open');
    }

    function closeCreateModal() {
        if (!createModal) return;
        createModal.classList.add('hidden');
        if ((!detailModal || detailModal.classList.contains('hidden')) && (!attachmentPreviewModal || attachmentPreviewModal.classList.contains('hidden'))) {
            document.body.classList.remove('modal-open');
        }
    }

    function openPreviewModal() {
        if (!attachmentPreviewModal) return;
        attachmentPreviewModal.classList.remove('hidden');
        document.body.classList.add('modal-open');
    }

    function closePreviewModal() {
        if (!attachmentPreviewModal) return;
        attachmentPreviewModal.classList.add('hidden');
        if ((!detailModal || detailModal.classList.contains('hidden')) && (!createModal || createModal.classList.contains('hidden'))) {
            document.body.classList.remove('modal-open');
        }
    }

    function resetAttachmentPreview() {
        if (attachmentPreviewTitle) {
            attachmentPreviewTitle.textContent = 'Preview Lampiran';
        }
        if (attachmentPreviewBody) {
            attachmentPreviewBody.innerHTML = '';
        }
        if (attachmentPreviewMessage) {
            attachmentPreviewMessage.textContent = '';
            attachmentPreviewMessage.classList.add('hidden');
        }
        if (attachmentPreviewDownload) {
            attachmentPreviewDownload.href = '#';
            attachmentPreviewDownload.classList.add('hidden');
        }
    }

    function showAttachmentPreviewMessage(message, src) {
        if (attachmentPreviewBody) {
            attachmentPreviewBody.innerHTML = '';
        }
        if (attachmentPreviewMessage) {
            attachmentPreviewMessage.textContent = message || 'Preview file tidak tersedia.';
            attachmentPreviewMessage.classList.remove('hidden');
        }
        if (attachmentPreviewDownload && src) {
            attachmentPreviewDownload.href = src;
            attachmentPreviewDownload.classList.remove('hidden');
        }
    }

    function renderAttachmentPreview(payload) {
        resetAttachmentPreview();

        const title = payload && payload.title ? payload.title : 'Preview Lampiran';
        const src = payload && payload.src ? payload.src : '';
        if (attachmentPreviewTitle) {
            attachmentPreviewTitle.textContent = title;
        }
        if (attachmentPreviewDownload && src) {
            attachmentPreviewDownload.href = src;
            attachmentPreviewDownload.classList.remove('hidden');
        }
        if (!attachmentPreviewBody) {
            return;
        }

        if (payload.type === 'image' && src) {
            attachmentPreviewBody.innerHTML = '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(title) + '" class="file-preview-image">';
            return;
        }

        if (payload.type === 'pdf' && src) {
            attachmentPreviewBody.innerHTML = '<iframe src="' + escapeHtml(src) + '#toolbar=0&navpanes=0&scrollbar=1" class="file-preview-frame" loading="lazy"></iframe>';
            return;
        }

        showAttachmentPreviewMessage('Preview file tidak tersedia untuk lampiran ini.', src);
    }

    async function openAttachmentPreview(src, title) {
        if (!src) {
            return;
        }

        resetAttachmentPreview();
        if (attachmentPreviewTitle) {
            attachmentPreviewTitle.textContent = title || 'Preview Lampiran';
        }
        openPreviewModal();

        try {
            const url = new URL(filePreviewUrl, window.location.origin);
            url.searchParams.set('path', src.replace(/^\/+/, ''));
            url.searchParams.set('name', title || 'Lampiran');

            const response = await fetch(url.toString(), { credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                showAttachmentPreviewMessage(payload.message || 'Gagal memuat preview lampiran.', src);
                return;
            }

            renderAttachmentPreview(payload);
        } catch (_) {
            showAttachmentPreviewMessage('Gagal memuat preview lampiran.', src);
        }
    }

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#disciplinaryMedicTable').DataTable({
            pageLength: 10,
            order: [[4, 'desc']],
            scrollX: true,
            autoWidth: false,
            language: { url: datatableLanguageUrl }
        });
    }

    recalcSummary(document);

    document.body.addEventListener('click', function(event) {
        const openTrigger = event.target.closest('.btn-open-disciplinary-detail');
        if (openTrigger && detailModal && detailTitle && detailSubtitle && detailBody) {
            const templateId = openTrigger.getAttribute('data-template-id') || '';
            const template = document.getElementById(templateId);
            if (!template) return;

            detailTitle.textContent = openTrigger.getAttribute('data-modal-title') || 'Detail Komdis Medis';
            detailSubtitle.textContent = openTrigger.getAttribute('data-modal-subtitle') || '';
            detailBody.innerHTML = template.innerHTML;
            detailModal.classList.remove('hidden');
            document.body.classList.add('modal-open');
            recalcSummary(detailBody);
            return;
        }

        if (event.target.closest('.btn-open-create-case')) {
            openCreateModal();
            return;
        }

        const previewLink = event.target.closest('.btn-preview-doc');
        if (previewLink) {
            event.preventDefault();
            openAttachmentPreview(previewLink.dataset.src || '', previewLink.dataset.title || 'Lampiran');
            return;
        }

        if (event.target.closest('.btn-close-disciplinary-detail')) {
            closeDetailModal();
            return;
        }

        if (event.target.closest('.btn-close-create-case')) {
            closeCreateModal();
            return;
        }

        if (event.target.closest('.btn-close-attachment-preview')) {
            closePreviewModal();
            return;
        }

        const deleteCaseForm = event.target.closest('.js-delete-case');
        if (deleteCaseForm) {
            const message = deleteCaseForm.dataset.confirm || 'Yakin ingin menghapus kasus ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
            return;
        }

        const deleteReductionForm = event.target.closest('.js-delete-reduction');
        if (deleteReductionForm) {
            const message = deleteReductionForm.dataset.confirm || 'Yakin ingin menghapus riwayat pengurangan poin ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
            return;
        }

        const addButton = event.target.closest('.btn-add-disciplinary-item');
        if (addButton) {
            const selector = addButton.getAttribute('data-target') || '';
            const base = addButton.closest('.form, .disciplinary-detail-shell, .card, document') || document;
            const target = selector ? base.querySelector(selector) : null;
            appendIndicationRow(target);
            recalcSummary(base);
            return;
        }

        const removeButton = event.target.closest('.disciplinary-remove-item');
        if (removeButton) {
            const root = removeButton.closest('.form, .disciplinary-detail-shell') || document;
            removeButton.closest('.disciplinary-item-row')?.remove();
            recalcSummary(root);
        }

        if (event.target === detailModal) {
            closeDetailModal();
            return;
        }

        if (event.target === createModal) {
            closeCreateModal();
            return;
        }

        if (event.target === attachmentPreviewModal) {
            closePreviewModal();
        }
    });

    document.body.addEventListener('change', function(event) {
        if (event.target.classList.contains('disciplinary-indication-select')) {
            const root = event.target.closest('.form, .disciplinary-detail-shell') || document;
            recalcSummary(root);
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key !== 'Escape') {
            return;
        }

        if (detailModal && !detailModal.classList.contains('hidden')) {
            closeDetailModal();
        }

        if (createModal && !createModal.classList.contains('hidden')) {
            closeCreateModal();
        }

        if (attachmentPreviewModal && !attachmentPreviewModal.classList.contains('hidden')) {
            closePreviewModal();
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
