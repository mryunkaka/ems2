<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Monitoring Point Pelanggaran Saya';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$currentUser = $_SESSION['user_rh'] ?? [];
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserName = trim((string)($currentUser['full_name'] ?? $currentUser['name'] ?? 'User'));

if ($currentUserId <= 0) {
    $_SESSION['flash_errors'][] = 'Session user tidak valid.';
    header('Location: /dashboard/index.php');
    exit;
}

function personalDisciplinaryLetterStatusLabel(string $status): string
{
    return match ($status) {
        'not_needed' => 'Tidak Diperlukan',
        'pending' => 'Belum Dibuat',
        'issued' => 'Sudah Dibuat',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function personalDisciplinaryToleranceLabel(string $value): string
{
    return match ($value) {
        'tolerable' => 'Masih Ditoleransi',
        'mixed' => 'Campuran',
        'non_tolerable' => 'Tidak Ditoleransi',
        default => ucwords(str_replace('_', ' ', $value)),
    };
}

function personalDisciplinaryAttachmentLinksHtml(array $attachments): string
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

$summary = [
    'case_count' => 0,
    'case_points' => 0,
    'reduction_points' => 0,
    'active_points' => 0,
    'warning_letters' => 0,
];
$hasPointReductionTable = false;
$caseRows = [];
$reductionRows = [];
$warningLetterRows = [];
$caseAttachmentsMap = [];
$warningAttachmentsMap = [];
$caseItemsMap = [];

try {
    $hasPointReductionTable = ems_table_exists($pdo, 'disciplinary_point_reductions');

    $stmt = $pdo->prepare("
        SELECT
            dc.id,
            dc.case_code,
            dc.case_name,
            dc.case_date,
            dc.summary,
            dc.total_points,
            dc.tolerable_count,
            dc.non_tolerable_count,
            dc.tolerance_summary,
            dc.recommended_action,
            dc.letter_status,
            dc.created_at,
            creator.full_name AS created_by_name,
            (
                SELECT GROUP_CONCAT(dci.indication_name_snapshot ORDER BY dci.id SEPARATOR ', ')
                FROM disciplinary_case_items dci
                WHERE dci.case_id = dc.id
            ) AS indication_names
        FROM disciplinary_cases dc
        INNER JOIN user_rh creator ON creator.id = dc.created_by
        WHERE dc.subject_user_id = ?
        ORDER BY dc.case_date DESC, dc.id DESC
        LIMIT 500
    ");
    $stmt->execute([$currentUserId]);
    $caseRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($hasPointReductionTable) {
        $stmt = $pdo->prepare("
            SELECT
                dpr.id,
                dpr.related_case_id,
                dpr.reduction_type,
                dpr.reduction_points,
                dpr.activity_date,
                dpr.notes,
                dpr.created_at,
                creator.full_name AS created_by_name,
                dc.case_code,
                dc.case_name
            FROM disciplinary_point_reductions dpr
            INNER JOIN user_rh creator ON creator.id = dpr.created_by
            LEFT JOIN disciplinary_cases dc ON dc.id = dpr.related_case_id
            WHERE dpr.subject_user_id = ?
            ORDER BY dpr.activity_date DESC, dpr.id DESC
            LIMIT 500
        ");
        $stmt->execute([$currentUserId]);
        $reductionRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (ems_table_exists($pdo, 'disciplinary_warning_letters')) {
        $stmt = $pdo->prepare("
            SELECT
                dwl.id,
                dwl.letter_code,
                dwl.case_id,
                dwl.letter_type,
                dwl.issued_date,
                dwl.effective_date,
                dwl.title,
                dwl.body_notes,
                dwl.created_at,
                creator.full_name AS created_by_name,
                dc.case_code,
                dc.case_name
            FROM disciplinary_warning_letters dwl
            INNER JOIN user_rh creator ON creator.id = dwl.created_by
            INNER JOIN disciplinary_cases dc ON dc.id = dwl.case_id
            WHERE dwl.subject_user_id = ?
            ORDER BY dwl.issued_date DESC, dwl.id DESC
            LIMIT 500
        ");
        $stmt->execute([$currentUserId]);
        $warningLetterRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $summary['case_count'] = count($caseRows);
    foreach ($caseRows as $case) {
        $summary['case_points'] += (int)($case['total_points'] ?? 0);
    }

    foreach ($reductionRows as $reduction) {
        $summary['reduction_points'] += (int)($reduction['reduction_points'] ?? 0);
    }

    $summary['warning_letters'] = count($warningLetterRows);
    $summary['active_points'] = max(0, $summary['case_points'] - $summary['reduction_points']);

    $caseIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $caseRows)));
    if ($caseIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($caseIds), '?'));
        $stmt = $pdo->prepare("
            SELECT
                dci.case_id,
                dci.indication_name_snapshot,
                dci.points_snapshot,
                dci.tolerance_type_snapshot,
                dci.notes,
                di.description AS indication_description
            FROM disciplinary_case_items dci
            LEFT JOIN disciplinary_indications di ON di.id = dci.indication_id
            WHERE dci.case_id IN ({$placeholders})
            ORDER BY dci.case_id ASC, dci.id ASC
        ");
        $stmt->execute($caseIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
            $caseItemsMap[(int)$item['case_id']][] = $item;
        }
    }

    if ($caseIds !== [] && ems_table_exists($pdo, 'disciplinary_case_attachments')) {
        $placeholders = implode(', ', array_fill(0, count($caseIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, case_id, file_name, file_path
            FROM disciplinary_case_attachments
            WHERE case_id IN ({$placeholders})
            ORDER BY id ASC
        ");
        $stmt->execute($caseIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $attachment) {
            $caseAttachmentsMap[(int)$attachment['case_id']][] = $attachment;
        }
    }

    $warningIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $warningLetterRows)));
    if ($warningIds !== [] && ems_table_exists($pdo, 'disciplinary_warning_letter_attachments')) {
        $placeholders = implode(', ', array_fill(0, count($warningIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, warning_letter_id, file_name, file_path
            FROM disciplinary_warning_letter_attachments
            WHERE warning_letter_id IN ({$placeholders})
            ORDER BY id ASC
        ");
        $stmt->execute($warningIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $attachment) {
            $warningAttachmentsMap[(int)$attachment['warning_letter_id']][] = $attachment;
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat monitoring point pelanggaran: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="disciplinary-personal-hero">
            <div>
                <div class="disciplinary-personal-kicker">Transparansi Komdis Personal</div>
                <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="page-subtitle">Halaman ini hanya menampilkan riwayat pelanggaran, pengurangan poin, dan surat peringatan milik <strong><?= htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8') ?></strong>. Data ini tidak menampilkan medis lain.</p>
            </div>
            <div class="disciplinary-personal-scope">
                <div class="meta-text-xs">Akses Data</div>
                <div class="disciplinary-personal-name"><?= htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="meta-text-xs mt-1">Hanya akun Anda yang dapat melihat ringkasan ini.</div>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">Total Kasus</div>
                <div class="text-2xl font-extrabold text-slate-900"><?= number_format((int)$summary['case_count'], 0, ',', '.') ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Total Poin Pelanggaran</div>
                <div class="text-2xl font-extrabold text-amber-700"><?= number_format((int)$summary['case_points'], 0, ',', '.') ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Total Pengurangan Poin</div>
                <div class="text-2xl font-extrabold text-success"><?= number_format((int)$summary['reduction_points'], 0, ',', '.') ?></div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">Poin Aktif Saat Ini</div>
                <div class="text-2xl font-extrabold text-rose-700"><?= number_format((int)$summary['active_points'], 0, ',', '.') ?></div>
                <div class="meta-text-xs mt-1"><?= htmlspecialchars(ems_disciplinary_recommendation_label(ems_disciplinary_recommendation_from_points((int)$summary['active_points'], false)), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Riwayat Kasus Penambah Poin</div>
            <?php if ($caseRows === []): ?>
                <div class="muted-placeholder p-4">Belum ada kasus Komdis yang tercatat untuk akun Anda.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table id="personalDisciplinaryCaseTable" class="table-custom personal-disciplinary-table">
                        <thead>
                            <tr>
                                <th>Kasus</th>
                                <th>Pasal / Indikasi Yang Dilanggar</th>
                                <th>Poin</th>
                                <th>Toleransi</th>
                                <th>Surat</th>
                                <th>Lampiran</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($caseRows as $case): ?>
                                <?php $caseItems = $caseItemsMap[(int)$case['id']] ?? []; ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$case['case_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars((string)$case['case_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="meta-text-xs"><?= htmlspecialchars(formatTanggalIndo((string)$case['case_date']), ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="meta-text-xs">Diinput oleh: <?= htmlspecialchars((string)$case['created_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($case['summary'])): ?>
                                            <div class="meta-text-xs"><?= htmlspecialchars((string)$case['summary'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($caseItems === []): ?>
                                            <span class="text-muted">-</span>
                                        <?php else: ?>
                                            <?php foreach ($caseItems as $item): ?>
                                                <div class="disciplinary-item-detail">
                                                    <strong><?= htmlspecialchars((string)$item['indication_name_snapshot'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <div class="meta-text-xs"><?= htmlspecialchars((string)($item['indication_description'] ?? 'Deskripsi pasal belum tersedia.'), ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="meta-text-xs">Poin pasal: <?= number_format((int)$item['points_snapshot'], 0, ',', '.') ?> | <?= htmlspecialchars(personalDisciplinaryToleranceLabel((string)$item['tolerance_type_snapshot']), ENT_QUOTES, 'UTF-8') ?></div>
                                                    <?php if (!empty($item['notes'])): ?>
                                                        <div class="meta-text-xs">Catatan: <?= htmlspecialchars((string)$item['notes'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= number_format((int)$case['total_points'], 0, ',', '.') ?> poin</strong>
                                        <div class="meta-text-xs">Toleran: <?= (int)$case['tolerable_count'] ?> | Tidak toleran: <?= (int)$case['non_tolerable_count'] ?></div>
                                    </td>
                                    <td><?= htmlspecialchars(personalDisciplinaryToleranceLabel((string)$case['tolerance_summary']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(personalDisciplinaryLetterStatusLabel((string)$case['letter_status']), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= personalDisciplinaryAttachmentLinksHtml($caseAttachmentsMap[(int)$case['id']] ?? []) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="card mb-0">
                <div class="card-header">Riwayat Pengurangan Poin</div>
                <?php if (!$hasPointReductionTable): ?>
                    <div class="muted-placeholder p-4">Fitur pengurangan poin belum diaktifkan sistem.</div>
                <?php elseif ($reductionRows === []): ?>
                    <div class="muted-placeholder p-4">Belum ada pengurangan poin untuk akun Anda.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="table-custom personal-disciplinary-table">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Aktivitas</th>
                                    <th>Poin</th>
                                    <th>Kasus Terkait</th>
                                    <th>Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reductionRows as $reduction): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars(formatTanggalIndo((string)$reduction['activity_date']), ENT_QUOTES, 'UTF-8') ?>
                                            <div class="meta-text-xs">Dicatat: <?= htmlspecialchars(formatTanggalID((string)$reduction['created_at']), ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(ems_disciplinary_point_reduction_label((string)$reduction['reduction_type']), ENT_QUOTES, 'UTF-8') ?>
                                            <div class="meta-text-xs">Oleh: <?= htmlspecialchars((string)$reduction['created_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
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
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card mb-0">
                <div class="card-header">Riwayat Surat Peringatan</div>
                <?php if ($warningLetterRows === []): ?>
                    <div class="muted-placeholder p-4">Belum ada surat peringatan untuk akun Anda.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="table-custom personal-disciplinary-table">
                            <thead>
                                <tr>
                                    <th>Surat</th>
                                    <th>Kasus</th>
                                    <th>Tanggal</th>
                                    <th>Catatan</th>
                                    <th>Lampiran</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($warningLetterRows as $letter): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$letter['letter_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="meta-text-xs"><?= htmlspecialchars(ems_disciplinary_recommendation_label((string)$letter['letter_type']), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="meta-text-xs"><?= htmlspecialchars((string)$letter['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars((string)$letter['case_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="meta-text-xs"><?= htmlspecialchars((string)$letter['case_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars(formatTanggalIndo((string)$letter['issued_date']), ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($letter['effective_date'])): ?>
                                                <div class="meta-text-xs">Efektif: <?= htmlspecialchars(formatTanggalIndo((string)$letter['effective_date']), ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                            <div class="meta-text-xs">Dibuat: <?= htmlspecialchars((string)$letter['created_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td><?= htmlspecialchars((string)($letter['body_notes'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= personalDisciplinaryAttachmentLinksHtml($warningAttachmentsMap[(int)$letter['id']] ?? []) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div id="personalDisciplinaryAttachmentPreviewModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-lg">
        <div class="modal-head">
            <div class="modal-title inline-flex items-center gap-2">
                <?= ems_icon('paper-clip', 'h-5 w-5 text-primary') ?>
                <span id="personalDisciplinaryAttachmentPreviewTitle">Preview Lampiran</span>
            </div>
            <button type="button" class="modal-close-btn btn-close-personal-attachment-preview" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>
        <div class="modal-content">
            <div id="personalDisciplinaryAttachmentPreviewBody"></div>
            <div id="personalDisciplinaryAttachmentPreviewMessage" class="alert alert-warning hidden mt-4"></div>
            <div class="modal-actions mt-4">
                <a href="#" id="personalDisciplinaryAttachmentPreviewDownload" class="btn-secondary hidden" target="_blank" rel="noopener noreferrer">Buka File Asli</a>
                <button type="button" class="btn-secondary btn-close-personal-attachment-preview">Tutup</button>
            </div>
        </div>
    </div>
</div>

<style>
    .disciplinary-personal-hero {
        display: grid;
        grid-template-columns: minmax(0, 1.8fr) minmax(280px, .9fr);
        gap: 1rem;
        padding: 1.5rem;
        margin-bottom: 1rem;
        border-radius: 28px;
        background: linear-gradient(135deg, #eff8ff 0%, #ffffff 52%, #fff7ed 100%);
        border: 1px solid rgba(148, 163, 184, .22);
        box-shadow: 0 24px 48px rgba(15, 23, 42, .08);
    }

    .disciplinary-personal-kicker {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        margin-bottom: .6rem;
        padding: .35rem .75rem;
        border-radius: 999px;
        background: #083344;
        color: #ecfeff;
        font-size: .75rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .disciplinary-personal-scope {
        padding: 1.1rem 1.15rem;
        border-radius: 24px;
        background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
        color: #e2e8f0;
    }

    .disciplinary-personal-name {
        margin-top: .35rem;
        font-size: 1.2rem;
        font-weight: 800;
        color: #fff;
    }

    .personal-disciplinary-table th,
    .personal-disciplinary-table td {
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

    .disciplinary-item-detail {
        padding: 10px 0;
        border-bottom: 1px dashed rgba(148, 163, 184, .35);
    }

    .disciplinary-item-detail:last-child {
        padding-bottom: 0;
        border-bottom: 0;
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

    @media (max-width: 1200px) {
        .disciplinary-personal-hero {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const datatableLanguageUrl = '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
    const filePreviewUrl = '<?= htmlspecialchars(ems_url('/ajax/disciplinary_file_preview.php'), ENT_QUOTES, 'UTF-8') ?>';
    const previewModal = document.getElementById('personalDisciplinaryAttachmentPreviewModal');
    const previewTitle = document.getElementById('personalDisciplinaryAttachmentPreviewTitle');
    const previewBody = document.getElementById('personalDisciplinaryAttachmentPreviewBody');
    const previewMessage = document.getElementById('personalDisciplinaryAttachmentPreviewMessage');
    const previewDownload = document.getElementById('personalDisciplinaryAttachmentPreviewDownload');

    function openModal(modal) {
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('modal-open');
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    function escapeHtml(value) {
        return String(value || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function resetAttachmentPreview() {
        if (previewTitle) previewTitle.textContent = 'Preview Lampiran';
        if (previewBody) previewBody.innerHTML = '';
        if (previewMessage) {
            previewMessage.textContent = '';
            previewMessage.classList.add('hidden');
        }
        if (previewDownload) {
            previewDownload.href = '#';
            previewDownload.classList.add('hidden');
        }
    }

    function showAttachmentPreviewMessage(message, src) {
        if (previewBody) previewBody.innerHTML = '';
        if (previewMessage) {
            previewMessage.textContent = message || 'Preview file tidak tersedia.';
            previewMessage.classList.remove('hidden');
        }
        if (previewDownload && src) {
            previewDownload.href = src;
            previewDownload.classList.remove('hidden');
        }
    }

    function renderAttachmentPreview(payload) {
        resetAttachmentPreview();
        const title = payload && payload.title ? payload.title : 'Preview Lampiran';
        const src = payload && payload.src ? payload.src : '';

        if (previewTitle) previewTitle.textContent = title;
        if (previewDownload && src) {
            previewDownload.href = src;
            previewDownload.classList.remove('hidden');
        }
        if (!previewBody) return;

        if (payload.type === 'image' && src) {
            previewBody.innerHTML = '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(title) + '" class="file-preview-image">';
            return;
        }

        if (payload.type === 'pdf' && src) {
            previewBody.innerHTML = '<iframe src="' + escapeHtml(src) + '#toolbar=0&navpanes=0&scrollbar=1" class="file-preview-frame" loading="lazy"></iframe>';
            return;
        }

        showAttachmentPreviewMessage('Preview file tidak tersedia untuk lampiran ini.', src);
    }

    async function openAttachmentPreview(src, title) {
        if (!src) return;

        resetAttachmentPreview();
        if (previewTitle) previewTitle.textContent = title || 'Preview Lampiran';
        openModal(previewModal);

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
        jQuery('#personalDisciplinaryCaseTable').DataTable({
            pageLength: 10,
            scrollX: true,
            autoWidth: false,
            order: [[0, 'desc']],
            language: { url: datatableLanguageUrl }
        });
    }

    document.addEventListener('click', function(event) {
        const previewLink = event.target.closest('.btn-preview-doc');
        if (previewLink) {
            event.preventDefault();
            openAttachmentPreview(previewLink.dataset.src || '', previewLink.dataset.title || 'Lampiran');
            return;
        }

        if (event.target.closest('.btn-close-personal-attachment-preview')) {
            closeModal(previewModal);
            return;
        }

        if (event.target === previewModal) {
            closeModal(previewModal);
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal(previewModal);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
