<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Disciplinary Committee'], '/dashboard/index.php');

$pageTitle = 'Surat Peringatan Komdis';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

function disciplinaryWarningLetterTypeLabel(string $value): string
{
    return match ($value) {
        'verbal_warning' => 'Teguran Lisan',
        'written_warning_1' => 'SP 1 - Peringatan Pertama',
        'written_warning_2' => 'SP 2 - Peringatan Keras',
        'final_warning' => 'SP 3 - Kritis',
        'termination_review' => 'Rekomendasi Pemecatan',
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

$eligibleCases = [];
$letters = [];
$attachmentsMap = [];

try {
    $eligibleCases = $pdo->query("
        SELECT
            dc.id,
            dc.case_code,
            dc.case_name,
            dc.case_date,
            dc.total_points,
            dc.recommended_action,
            dc.letter_status,
            subject.full_name AS subject_name
        FROM disciplinary_cases dc
        INNER JOIN user_rh subject ON subject.id = dc.subject_user_id
        WHERE dc.status IN ('open', 'reviewed', 'escalated')
        ORDER BY dc.case_date DESC, dc.id DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);

    $letters = $pdo->query("
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
            dwl.updated_at,
            subject.full_name AS subject_name,
            dc.case_code,
            dc.case_name,
            creator.full_name AS created_by_name
        FROM disciplinary_warning_letters dwl
        INNER JOIN disciplinary_cases dc ON dc.id = dwl.case_id
        INNER JOIN user_rh subject ON subject.id = dwl.subject_user_id
        INNER JOIN user_rh creator ON creator.id = dwl.created_by
        ORDER BY dwl.issued_date DESC, dwl.id DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);

    $knownCaseIds = [];
    foreach ($eligibleCases as $case) {
        $knownCaseIds[(int)$case['id']] = true;
    }

    foreach ($letters as $letter) {
        $caseId = (int)($letter['case_id'] ?? 0);
        if ($caseId <= 0 || isset($knownCaseIds[$caseId])) {
            continue;
        }

        $eligibleCases[] = [
            'id' => $caseId,
            'case_code' => (string)($letter['case_code'] ?? ''),
            'case_name' => (string)($letter['case_name'] ?? ''),
            'case_date' => null,
            'total_points' => 0,
            'recommended_action' => (string)($letter['letter_type'] ?? 'verbal_warning'),
            'letter_status' => 'issued',
            'subject_name' => (string)($letter['subject_name'] ?? ''),
        ];
        $knownCaseIds[$caseId] = true;
    }

    if ($letters !== [] && ems_table_exists($pdo, 'disciplinary_warning_letter_attachments')) {
        $letterIds = array_map(static fn(array $row): int => (int)$row['id'], $letters);
        $letterIds = array_values(array_filter($letterIds, static fn(int $id): bool => $id > 0));

        if ($letterIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($letterIds), '?'));
            $stmt = $pdo->prepare("
                SELECT id, warning_letter_id, file_name, file_path, created_at
                FROM disciplinary_warning_letter_attachments
                WHERE warning_letter_id IN ({$placeholders})
                ORDER BY id ASC
            ");
            $stmt->execute($letterIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $attachment) {
                $attachmentsMap[(int)$attachment['warning_letter_id']][] = $attachment;
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat surat peringatan: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle">Buat dan kelola surat peringatan berdasarkan kasus Komdis sesuai tahapan SP 1, SP 2, dan SP 3 pada SOP.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div class="card card-section">
                <div class="meta-text-xs">SP 1</div>
                <div class="text-lg font-extrabold text-slate-900">40 - 69 Poin</div>
                <div class="meta-text-xs mt-1">Peringatan pertama dan pencatatan resmi.</div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">SP 2</div>
                <div class="text-lg font-extrabold text-amber-700">70 - 99 Poin</div>
                <div class="meta-text-xs mt-1">Peringatan keras dan masa evaluasi.</div>
            </div>
            <div class="card card-section">
                <div class="meta-text-xs">SP 3</div>
                <div class="text-lg font-extrabold text-rose-700">100+ Poin</div>
                <div class="meta-text-xs mt-1">Tahap kritis, sidang akhir, dan tindakan lanjutan.</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header card-header-actions card-header-flex">
                <div class="card-header-actions-title disciplinary-warning-title">
                    <?= ems_icon('document-text', 'h-4 w-4') ?> Riwayat Surat Peringatan
                </div>
                <button type="button" id="openAddWarningModal" class="btn-success">
                    <?= ems_icon('plus', 'h-4 w-4') ?>
                    <span>Buat Surat</span>
                </button>
            </div>

            <div class="meta-text-xs mb-3">Lampiran pendukung dapat berupa PDF, screenshot, atau bukti gambar yang relevan dengan surat peringatan.</div>

            <div class="table-wrapper disciplinary-warning-table-wrap">
                <table id="disciplinaryLettersTable" class="table-custom disciplinary-warning-table">
                    <thead>
                        <tr>
                            <th>Surat</th>
                            <th>Pegawai</th>
                            <th>Kasus</th>
                            <th>Jenis SP</th>
                            <th>Tanggal</th>
                            <th>Riwayat</th>
                            <th>Lampiran</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($letters as $row): ?>
                            <tr
                                data-id="<?= (int)$row['id'] ?>"
                                data-case-id="<?= (int)$row['case_id'] ?>"
                                data-letter-type="<?= htmlspecialchars((string)$row['letter_type'], ENT_QUOTES, 'UTF-8') ?>"
                                data-issued-date="<?= htmlspecialchars((string)$row['issued_date'], ENT_QUOTES, 'UTF-8') ?>"
                                data-effective-date="<?= htmlspecialchars((string)($row['effective_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                data-title="<?= htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') ?>"
                                data-body-notes="<?= htmlspecialchars((string)($row['body_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <td>
                                    <strong><?= htmlspecialchars((string)$row['letter_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs"><?= htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td><?= htmlspecialchars((string)$row['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars((string)$row['case_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="meta-text-xs"><?= htmlspecialchars((string)$row['case_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td><?= htmlspecialchars(disciplinaryWarningLetterTypeLabel((string)$row['letter_type']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div><?= htmlspecialchars(formatTanggalIndo($row['issued_date']), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($row['effective_date'])): ?>
                                        <div class="meta-text-xs">Efektif: <?= htmlspecialchars(formatTanggalIndo($row['effective_date']), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><strong>Dibuat oleh:</strong> <?= htmlspecialchars((string)$row['created_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="meta-text-xs mb-2"><?= htmlspecialchars(formatTanggalID($row['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div><strong>Terakhir diperbarui:</strong> <?= htmlspecialchars(formatTanggalID($row['updated_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($row['body_notes'])): ?>
                                        <div class="meta-text-xs disciplinary-note-preview" title="<?= htmlspecialchars((string)$row['body_notes'], ENT_QUOTES, 'UTF-8') ?>">Catatan tersedia</div>
                                    <?php endif; ?>
                                </td>
                                <td class="disciplinary-attachments-cell">
                                    <?= disciplinaryAttachmentLinksHtml($attachmentsMap[(int)$row['id']] ?? []) ?>
                                </td>
                                <td class="table-actions">
                                    <div class="action-row-nowrap">
                                        <button type="button" class="btn-secondary action-icon-btn btn-edit-warning" title="Edit surat peringatan" aria-label="Edit surat peringatan">
                                            <?= ems_icon('pencil-square', 'h-4 w-4') ?>
                                        </button>
                                        <form method="POST" action="disciplinary_committee_action.php" class="inline js-delete-warning" data-confirm="Yakin ingin menghapus surat peringatan ini?">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_warning_letter">
                                            <input type="hidden" name="redirect_to" value="disciplinary_warning_letters.php">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn-danger action-icon-btn" title="Hapus surat peringatan" aria-label="Hapus surat peringatan">
                                                <?= ems_icon('trash', 'h-4 w-4') ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$letters): ?>
                    <div class="muted-placeholder p-4">Belum ada surat peringatan.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div id="addWarningModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Buat Surat Peringatan</div>
            <button type="button" class="modal-close-btn btn-add-warning-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="disciplinary_committee_action.php" class="form modal-form" id="warningLetterAddForm" enctype="multipart/form-data">
            <div class="modal-content">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="create_warning_letter">
                <input type="hidden" name="redirect_to" value="disciplinary_warning_letters.php">

                <label for="warningCaseId">Pilih Kasus Komdis</label>
                <select id="warningCaseId" name="case_id" required>
                    <option value="">Pilih kasus</option>
                    <?php foreach ($eligibleCases as $case): ?>
                        <option
                            value="<?= (int)$case['id'] ?>"
                            data-recommendation="<?= htmlspecialchars((string)$case['recommended_action'], ENT_QUOTES, 'UTF-8') ?>"
                            data-subject="<?= htmlspecialchars((string)$case['subject_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-case="<?= htmlspecialchars((string)$case['case_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars((string)$case['case_code'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars((string)$case['subject_name'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars(ems_disciplinary_recommendation_label((string)$case['recommended_action']), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="warningLetterType">Jenis Surat</label>
                <select id="warningLetterType" name="letter_type" required>
                    <option value="verbal_warning">Teguran Lisan</option>
                    <option value="written_warning_1">SP 1 - Peringatan Pertama</option>
                    <option value="written_warning_2">SP 2 - Peringatan Keras</option>
                    <option value="final_warning">SP 3 - Kritis</option>
                    <option value="termination_review">Rekomendasi Pemecatan</option>
                </select>

                <label for="warningIssuedDate">Tanggal Terbit</label>
                <input type="date" id="warningIssuedDate" name="issued_date" value="<?= date('Y-m-d') ?>" required>

                <label for="warningEffectiveDate">Tanggal Berlaku Efektif</label>
                <input type="date" id="warningEffectiveDate" name="effective_date">

                <label for="warningTitle">Judul Surat</label>
                <input type="text" id="warningTitle" name="title" required>

                <label for="warningBodyNotes">Isi Singkat / Catatan Surat</label>
                <textarea id="warningBodyNotes" name="body_notes" rows="4" placeholder="Tuliskan poin utama surat peringatan, arahan evaluasi, atau catatan tambahan."></textarea>

                <label for="warningAttachments">Lampiran Surat (Opsional)</label>
                <input type="file" id="warningAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,.pdf" multiple>
                <div class="meta-text-xs">Format yang diizinkan: JPG, PNG, PDF. Foto akan dicoba dikompres otomatis. Ukuran akhir wajib maksimal <?= htmlspecialchars(emsDisciplinaryAttachmentMaxLabel(), ENT_QUOTES, 'UTF-8') ?> per file. PDF wajib dikompres manual bila masih di atas batas.</div>

                <div class="request-info-box mt-4">
                    <div><strong>Nama Pegawai:</strong> <span id="warningSubjectPreview">-</span></div>
                    <div><strong>Rekomendasi Kasus:</strong> <span id="warningRecommendationPreview">-</span></div>
                </div>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-add-warning-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan Surat</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="editWarningModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div class="modal-title">Edit Surat Peringatan</div>
            <button type="button" class="modal-close-btn btn-edit-warning-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="disciplinary_committee_action.php" class="form modal-form" id="warningLetterEditForm" enctype="multipart/form-data">
            <div class="modal-content">
                <?= csrfField(); ?>
                <input type="hidden" name="action" value="update_warning_letter">
                <input type="hidden" name="redirect_to" value="disciplinary_warning_letters.php">
                <input type="hidden" name="id" id="editWarningId">

                <label for="editWarningCaseId">Pilih Kasus Komdis</label>
                <select id="editWarningCaseId" name="case_id" required>
                    <option value="">Pilih kasus</option>
                    <?php foreach ($eligibleCases as $case): ?>
                        <option
                            value="<?= (int)$case['id'] ?>"
                            data-recommendation="<?= htmlspecialchars((string)$case['recommended_action'], ENT_QUOTES, 'UTF-8') ?>"
                            data-subject="<?= htmlspecialchars((string)$case['subject_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-case="<?= htmlspecialchars((string)$case['case_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars((string)$case['case_code'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars((string)$case['subject_name'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars(ems_disciplinary_recommendation_label((string)$case['recommended_action']), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="editWarningLetterType">Jenis Surat</label>
                <select id="editWarningLetterType" name="letter_type" required>
                    <option value="verbal_warning">Teguran Lisan</option>
                    <option value="written_warning_1">SP 1 - Peringatan Pertama</option>
                    <option value="written_warning_2">SP 2 - Peringatan Keras</option>
                    <option value="final_warning">SP 3 - Kritis</option>
                    <option value="termination_review">Rekomendasi Pemecatan</option>
                </select>

                <label for="editWarningIssuedDate">Tanggal Terbit</label>
                <input type="date" id="editWarningIssuedDate" name="issued_date" required>

                <label for="editWarningEffectiveDate">Tanggal Berlaku Efektif</label>
                <input type="date" id="editWarningEffectiveDate" name="effective_date">

                <label for="editWarningTitle">Judul Surat</label>
                <input type="text" id="editWarningTitle" name="title" required>

                <label for="editWarningBodyNotes">Isi Singkat / Catatan Surat</label>
                <textarea id="editWarningBodyNotes" name="body_notes" rows="4" placeholder="Tuliskan poin utama surat peringatan, arahan evaluasi, atau catatan tambahan."></textarea>

                <label for="editWarningAttachments">Tambah Lampiran Baru (Opsional)</label>
                <input type="file" id="editWarningAttachments" name="attachments[]" accept=".jpg,.jpeg,.png,.pdf" multiple>
                <div class="meta-text-xs">File baru akan ditambahkan ke surat ini tanpa menghapus lampiran lama. Batas file tetap maksimal <?= htmlspecialchars(emsDisciplinaryAttachmentMaxLabel(), ENT_QUOTES, 'UTF-8') ?> per file.</div>

                <div class="request-info-box mt-4">
                    <div><strong>Nama Pegawai:</strong> <span id="editWarningSubjectPreview">-</span></div>
                    <div><strong>Rekomendasi Kasus:</strong> <span id="editWarningRecommendationPreview">-</span></div>
                </div>
            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-edit-warning-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan Perubahan</button>
                </div>
            </div>
        </form>
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
    .disciplinary-warning-title {
        font-size: 13px;
    }

    .disciplinary-warning-table {
        min-width: 1360px;
    }

    .disciplinary-note-preview,
    .disciplinary-attachments-cell {
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
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const datatableLanguageUrl = '<?= htmlspecialchars(ems_asset('/assets/design/js/datatables-id.json'), ENT_QUOTES, 'UTF-8') ?>';
    const filePreviewUrl = '<?= htmlspecialchars(ems_url('/ajax/disciplinary_file_preview.php'), ENT_QUOTES, 'UTF-8') ?>';
    const addModal = document.getElementById('addWarningModal');
    const editModal = document.getElementById('editWarningModal');
    const addForm = document.getElementById('warningLetterAddForm');
    const attachmentPreviewModal = document.getElementById('disciplinaryAttachmentPreviewModal');
    const attachmentPreviewTitle = document.getElementById('disciplinaryAttachmentPreviewTitle');
    const attachmentPreviewBody = document.getElementById('disciplinaryAttachmentPreviewBody');
    const attachmentPreviewMessage = document.getElementById('disciplinaryAttachmentPreviewMessage');
    const attachmentPreviewDownload = document.getElementById('disciplinaryAttachmentPreviewDownload');

    function labelize(value) {
        return String(value || '')
            .replaceAll('_', ' ')
            .replace(/\b\w/g, function(ch) { return ch.toUpperCase(); });
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.classList.add('modal-open');
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.style.display = 'none';
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
        openModal(attachmentPreviewModal);

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

    function syncWarningForm(caseSelect, typeSelect, titleInput, subjectPreview, recommendationPreview, forceTitle, applyRecommendation) {
        const selected = caseSelect.options[caseSelect.selectedIndex];
        if (!selected || !selected.value) {
            subjectPreview.textContent = '-';
            recommendationPreview.textContent = '-';
            return;
        }

        const subject = selected.dataset.subject || '-';
        const recommendation = selected.dataset.recommendation || '';
        const caseName = selected.dataset.case || 'Kasus Komdis';

        subjectPreview.textContent = subject;
        recommendationPreview.textContent = recommendation ? disciplinaryLetterTypeLabel(recommendation) : '-';

        if (applyRecommendation && recommendation) {
            typeSelect.value = recommendation;
        }

        if (forceTitle || !titleInput.value.trim()) {
            titleInput.value = disciplinaryLetterTitle(typeSelect.value, caseName);
        }
    }

    function disciplinaryLetterTypeLabel(value) {
        const map = {
            coaching: 'Pembinaan',
            verbal_warning: 'Teguran Lisan',
            written_warning_1: 'SP 1 - Peringatan Pertama',
            written_warning_2: 'SP 2 - Peringatan Keras',
            final_warning: 'SP 3 - Kritis',
            termination_review: 'Rekomendasi Pemecatan'
        };

        return map[value] || labelize(value);
    }

    function disciplinaryLetterTitle(letterType, caseName) {
        return disciplinaryLetterTypeLabel(letterType) + ' - ' + caseName;
    }

    function bindFormSync(prefix) {
        const caseSelect = document.getElementById(prefix + 'WarningCaseId');
        const typeSelect = document.getElementById(prefix + 'WarningLetterType');
        const titleInput = document.getElementById(prefix + 'WarningTitle');
        const subjectPreview = document.getElementById(prefix + 'WarningSubjectPreview');
        const recommendationPreview = document.getElementById(prefix + 'WarningRecommendationPreview');

        if (!caseSelect || !typeSelect || !titleInput || !subjectPreview || !recommendationPreview) {
            return;
        }

        const shouldApplyRecommendation = prefix !== 'edit';

        caseSelect.addEventListener('change', function() {
            syncWarningForm(caseSelect, typeSelect, titleInput, subjectPreview, recommendationPreview, false, shouldApplyRecommendation);
        });

        typeSelect.addEventListener('change', function() {
            const selected = caseSelect.options[caseSelect.selectedIndex];
            const caseName = selected && selected.value ? (selected.dataset.case || 'Kasus Komdis') : 'Kasus Komdis';
            titleInput.value = disciplinaryLetterTitle(typeSelect.value, caseName);
        });
    }

    bindFormSync('');
    bindFormSync('edit');

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#disciplinaryLettersTable').DataTable({
            pageLength: 10,
            scrollX: true,
            autoWidth: false,
            order: [[4, 'desc']],
            language: { url: datatableLanguageUrl }
        });
    }

    document.getElementById('openAddWarningModal')?.addEventListener('click', function() {
        if (addForm) {
            addForm.reset();
        }
        document.getElementById('warningIssuedDate').value = '<?= date('Y-m-d') ?>';
        document.getElementById('warningSubjectPreview').textContent = '-';
        document.getElementById('warningRecommendationPreview').textContent = '-';
        openModal(addModal);
    });

    document.querySelectorAll('.btn-add-warning-cancel').forEach(function(button) {
        button.addEventListener('click', function() {
            closeModal(addModal);
        });
    });

    document.querySelectorAll('.btn-edit-warning-cancel').forEach(function(button) {
        button.addEventListener('click', function() {
            closeModal(editModal);
        });
    });

    document.querySelectorAll('.btn-edit-warning').forEach(function(button) {
        button.addEventListener('click', function() {
            const row = button.closest('tr');
            if (!row) {
                return;
            }

            document.getElementById('editWarningId').value = row.dataset.id || '';
            document.getElementById('editWarningCaseId').value = row.dataset.caseId || '';
            document.getElementById('editWarningLetterType').value = row.dataset.letterType || 'verbal_warning';
            document.getElementById('editWarningIssuedDate').value = row.dataset.issuedDate || '';
            document.getElementById('editWarningEffectiveDate').value = row.dataset.effectiveDate || '';
            document.getElementById('editWarningTitle').value = row.dataset.title || '';
            document.getElementById('editWarningBodyNotes').value = row.dataset.bodyNotes || '';

            const caseSelect = document.getElementById('editWarningCaseId');
            const typeSelect = document.getElementById('editWarningLetterType');
            const titleInput = document.getElementById('editWarningTitle');
            const subjectPreview = document.getElementById('editWarningSubjectPreview');
            const recommendationPreview = document.getElementById('editWarningRecommendationPreview');
            syncWarningForm(caseSelect, typeSelect, titleInput, subjectPreview, recommendationPreview, false, false);

            openModal(editModal);
        });
    });

    document.querySelectorAll('.js-delete-warning').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            const message = form.dataset.confirm || 'Yakin ingin menghapus data ini?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.addEventListener('click', function(event) {
        const previewLink = event.target.closest('.btn-preview-doc');
        if (previewLink) {
            event.preventDefault();
            openAttachmentPreview(previewLink.dataset.src || '', previewLink.dataset.title || 'Lampiran');
            return;
        }

        if (event.target.closest('.btn-close-attachment-preview')) {
            closeModal(attachmentPreviewModal);
            return;
        }

        if (event.target === attachmentPreviewModal) {
            closeModal(attachmentPreviewModal);
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal(addModal);
            closeModal(editModal);
            closeModal(attachmentPreviewModal);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
