<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Disciplinary Committee'], '/dashboard/index.php');

$pageTitle = 'Surat Peringatan';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$eligibleCases = [];
$letters = [];

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
            dwl.letter_type,
            dwl.issued_date,
            dwl.effective_date,
            dwl.title,
            dwl.body_notes,
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
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat surat peringatan: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="page-subtitle">Buat surat peringatan dari disciplinary case yang sudah tercatat agar histori tindakan tetap rapi.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error) ?></div>
        <?php endforeach; ?>

        <div class="grid grid-cols-1 xl:grid-cols-[400px_minmax(0,1fr)] gap-4">
            <div class="card">
                <div class="card-header">Buat Surat Peringatan</div>
                <form method="POST" action="disciplinary_committee_action.php" class="form" id="warningLetterForm">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="create_warning_letter">
                    <input type="hidden" name="redirect_to" value="disciplinary_warning_letters.php">

                    <label for="warningCaseId">Pilih Case</label>
                    <select id="warningCaseId" name="case_id" required>
                        <option value="">Pilih disciplinary case</option>
                        <?php foreach ($eligibleCases as $case): ?>
                            <option
                                value="<?= (int)$case['id'] ?>"
                                data-recommendation="<?= htmlspecialchars((string)$case['recommended_action'], ENT_QUOTES) ?>"
                                data-subject="<?= htmlspecialchars((string)$case['subject_name'], ENT_QUOTES) ?>"
                                data-case="<?= htmlspecialchars((string)$case['case_name'], ENT_QUOTES) ?>">
                                <?= htmlspecialchars($case['case_code']) ?> | <?= htmlspecialchars($case['subject_name']) ?> | <?= htmlspecialchars(ems_disciplinary_recommendation_label((string)$case['recommended_action'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="warningLetterType">Jenis Surat</label>
                    <select id="warningLetterType" name="letter_type" required>
                        <option value="verbal_warning">Verbal Warning</option>
                        <option value="written_warning_1">Written Warning 1</option>
                        <option value="written_warning_2">Written Warning 2</option>
                        <option value="final_warning">Final Warning</option>
                        <option value="termination_review">Termination Review</option>
                    </select>

                    <label for="warningIssuedDate">Tanggal Terbit</label>
                    <input type="date" id="warningIssuedDate" name="issued_date" value="<?= date('Y-m-d') ?>" required>

                    <label for="warningEffectiveDate">Tanggal Efektif</label>
                    <input type="date" id="warningEffectiveDate" name="effective_date">

                    <label for="warningTitle">Judul Surat</label>
                    <input type="text" id="warningTitle" name="title" required>

                    <label for="warningBodyNotes">Catatan Isi Surat</label>
                    <textarea id="warningBodyNotes" name="body_notes" rows="4" placeholder="Isi ringkas atau catatan tambahan surat"></textarea>

                    <div class="request-info-box mt-4">
                        <div><strong>Preview Subject:</strong> <span id="warningSubjectPreview">-</span></div>
                        <div><strong>Rekomendasi Case:</strong> <span id="warningRecommendationPreview">-</span></div>
                    </div>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-success">
                            <?= ems_icon('document-text', 'h-4 w-4') ?>
                            <span>Buat Surat</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Riwayat Surat Peringatan</div>
                <div class="table-wrapper">
                    <table id="disciplinaryLettersTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Surat</th>
                                <th>User</th>
                                <th>Case</th>
                                <th>Jenis</th>
                                <th>Tanggal</th>
                                <th>Dibuat Oleh</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($letters as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['letter_code']) ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars($row['title']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($row['subject_name']) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['case_code']) ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars($row['case_name']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars(ems_disciplinary_recommendation_label((string)$row['letter_type'])) ?></td>
                                    <td>
                                        <?= htmlspecialchars(formatTanggalIndo($row['issued_date'])) ?>
                                        <?php if (!empty($row['effective_date'])): ?>
                                            <div class="meta-text-xs">Efektif: <?= htmlspecialchars(formatTanggalIndo($row['effective_date'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($row['created_by_name']) ?>
                                        <?php if (!empty($row['body_notes'])): ?>
                                            <div class="meta-text-xs" title="<?= htmlspecialchars((string)$row['body_notes']) ?>">Catatan tersedia</div>
                                        <?php endif; ?>
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
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const caseSelect = document.getElementById('warningCaseId');
    const typeSelect = document.getElementById('warningLetterType');
    const titleInput = document.getElementById('warningTitle');
    const subjectPreview = document.getElementById('warningSubjectPreview');
    const recommendationPreview = document.getElementById('warningRecommendationPreview');

    function labelize(value) {
        return String(value || '')
            .replaceAll('_', ' ')
            .replace(/\b\w/g, function(ch) { return ch.toUpperCase(); });
    }

    function syncWarningForm() {
        const selected = caseSelect.options[caseSelect.selectedIndex];
        if (!selected || !selected.value) {
            subjectPreview.textContent = '-';
            recommendationPreview.textContent = '-';
            return;
        }

        const subject = selected.dataset.subject || '-';
        const recommendation = selected.dataset.recommendation || '';
        const caseName = selected.dataset.case || 'Disciplinary Case';

        subjectPreview.textContent = subject;
        recommendationPreview.textContent = labelize(recommendation);

        if (!titleInput.value.trim()) {
            titleInput.value = labelize(typeSelect.value) + ' - ' + caseName;
        }

        if (recommendation) {
            typeSelect.value = recommendation;
        }
    }

    caseSelect.addEventListener('change', syncWarningForm);
    typeSelect.addEventListener('change', function() {
        const selected = caseSelect.options[caseSelect.selectedIndex];
        const caseName = selected && selected.value ? (selected.dataset.case || 'Disciplinary Case') : 'Disciplinary Case';
        titleInput.value = labelize(typeSelect.value) + ' - ' + caseName;
    });

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#disciplinaryLettersTable').DataTable({
            pageLength: 10,
            order: [[4, 'desc']],
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
