<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_division_access(['Disciplinary Committee'], '/dashboard/index.php');

$pageTitle = 'Disciplinary Cases';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$users = [];
$indications = [];
$cases = [];

try {
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

    $cases = $pdo->query("
        SELECT
            dc.id,
            dc.case_code,
            dc.case_name,
            dc.case_date,
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
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Gagal memuat disciplinary cases: ' . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="page-subtitle">Bangun case dari satu atau banyak indikasi. Total point dan rekomendasi tindakan dihitung otomatis.</p>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string)$message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string)$error) ?></div>
        <?php endforeach; ?>

        <div class="grid grid-cols-1 xl:grid-cols-[420px_minmax(0,1fr)] gap-4">
            <div class="card">
                <div class="card-header">Input Case Baru</div>
                <form method="POST" action="disciplinary_committee_action.php" class="form" id="disciplinaryCaseForm">
                    <?= csrfField(); ?>
                    <input type="hidden" name="action" value="create_case">
                    <input type="hidden" name="redirect_to" value="disciplinary_cases.php">

                    <label for="subjectUserId">User Terkait</label>
                    <select id="subjectUserId" name="subject_user_id" required>
                        <option value="">Pilih user</option>
                        <?php foreach ($users as $subject): ?>
                            <option value="<?= (int)$subject['id'] ?>">
                                <?= htmlspecialchars($subject['full_name']) ?> | <?= htmlspecialchars(ems_role_label($subject['role'])) ?> | <?= htmlspecialchars(ems_normalize_division($subject['division'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="caseName">Nama Case</label>
                    <input type="text" id="caseName" name="case_name" required placeholder="Contoh: Pelanggaran Absensi Januari">

                    <label for="caseDate">Tanggal Kejadian</label>
                    <input type="date" id="caseDate" name="case_date" value="<?= date('Y-m-d') ?>" required>

                    <label for="caseSummary">Ringkasan</label>
                    <textarea id="caseSummary" name="summary" rows="3" placeholder="Ringkasan kronologi case"></textarea>

                    <div class="card card-subtle mt-4">
                        <div class="card-header">Indikasi Case</div>
                        <div id="disciplinaryItems">
                            <div class="disciplinary-item-row grid grid-cols-1 gap-3 mb-3">
                                <select name="indication_id[]" class="disciplinary-indication-select" required>
                                    <option value="">Pilih indikasi</option>
                                    <?php foreach ($indications as $indication): ?>
                                        <option
                                            value="<?= (int)$indication['id'] ?>"
                                            data-points="<?= (int)$indication['default_points'] ?>"
                                            data-tolerance="<?= htmlspecialchars($indication['tolerance_type'], ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($indication['name']) ?> | <?= (int)$indication['default_points'] ?> point | <?= htmlspecialchars($indication['tolerance_type']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <textarea name="item_notes[]" rows="2" placeholder="Catatan item indikasi"></textarea>
                            </div>
                        </div>
                        <button type="button" id="addDisciplinaryItem" class="btn-secondary btn-sm">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Tambah Indikasi</span>
                        </button>
                    </div>

                    <div class="request-info-box mt-4">
                        <div><strong>Total Point:</strong> <span id="disciplinaryTotalPoints">0</span></div>
                        <div><strong>Non Tolerable:</strong> <span id="disciplinaryNonTolerableCount">0</span></div>
                        <div><strong>Rekomendasi:</strong> <span id="disciplinaryRecommendation">Coaching</span></div>
                    </div>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-success">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Simpan Case</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Daftar Disciplinary Cases</div>
                <div class="table-wrapper">
                    <table id="disciplinaryCasesTable" class="table-custom">
                        <thead>
                            <tr>
                                <th>Case</th>
                                <th>User</th>
                                <th>Indikasi</th>
                                <th>Point</th>
                                <th>Toleransi</th>
                                <th>Rekomendasi</th>
                                <th>Status</th>
                                <th>Letter</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cases as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['case_name']) ?></strong>
                                        <div class="meta-text-xs"><?= htmlspecialchars($row['case_code']) ?></div>
                                        <div class="meta-text-xs"><?= htmlspecialchars(formatTanggalIndo($row['case_date'])) ?></div>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['subject_name']) ?></strong>
                                        <div class="meta-text-xs">Input: <?= htmlspecialchars($row['created_by_name']) ?></div>
                                    </td>
                                    <td>
                                        <div class="text-sm"><?= htmlspecialchars((string)($row['indication_names'] ?? '-')) ?></div>
                                    </td>
                                    <td>
                                        <strong><?= (int)$row['total_points'] ?></strong>
                                        <div class="meta-text-xs">Tol: <?= (int)$row['tolerable_count'] ?> | Non: <?= (int)$row['non_tolerable_count'] ?></div>
                                    </td>
                                    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['tolerance_summary']))) ?></td>
                                    <td><?= htmlspecialchars(ems_disciplinary_recommendation_label((string)$row['recommended_action'])) ?></td>
                                    <td>
                                        <form method="POST" action="disciplinary_committee_action.php" class="inline-flex gap-2 items-center">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="action" value="update_case_status">
                                            <input type="hidden" name="redirect_to" value="disciplinary_cases.php">
                                            <input type="hidden" name="case_id" value="<?= (int)$row['id'] ?>">
                                            <select name="status">
                                                <?php foreach (['open', 'reviewed', 'escalated', 'closed'] as $status): ?>
                                                    <option value="<?= $status ?>" <?= ($row['status'] === $status) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars(ucfirst($status)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-secondary btn-sm">Update</button>
                                        </form>
                                    </td>
                                    <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$row['letter_status']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!$cases): ?>
                        <div class="muted-placeholder p-4">Belum ada disciplinary case.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const indications = <?= json_encode(array_map(static function ($row) {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'points' => (int)$row['default_points'],
            'tolerance' => (string)$row['tolerance_type'],
        ];
    }, $indications), JSON_UNESCAPED_UNICODE) ?>;

    const container = document.getElementById('disciplinaryItems');
    const addBtn = document.getElementById('addDisciplinaryItem');
    const totalEl = document.getElementById('disciplinaryTotalPoints');
    const nonTolEl = document.getElementById('disciplinaryNonTolerableCount');
    const recommendationEl = document.getElementById('disciplinaryRecommendation');

    function recommendationFromPoints(totalPoints, hasNonTolerable) {
        if (hasNonTolerable) {
            if (totalPoints >= 80) return 'Termination Review';
            if (totalPoints >= 60) return 'Final Warning';
            if (totalPoints >= 35) return 'Written Warning 2';
            return 'Written Warning 1';
        }

        if (totalPoints >= 100) return 'Termination Review';
        if (totalPoints >= 80) return 'Final Warning';
        if (totalPoints >= 60) return 'Written Warning 2';
        if (totalPoints >= 40) return 'Written Warning 1';
        if (totalPoints >= 20) return 'Verbal Warning';
        return 'Coaching';
    }

    function buildSelectOptions() {
        return ['<option value="">Pilih indikasi</option>'].concat(indications.map(function(item) {
            return '<option value="' + item.id + '" data-points="' + item.points + '" data-tolerance="' + item.tolerance + '">' +
                item.name + ' | ' + item.points + ' point | ' + item.tolerance +
                '</option>';
        })).join('');
    }

    function recalcSummary() {
        let total = 0;
        let nonTolerable = 0;

        container.querySelectorAll('.disciplinary-indication-select').forEach(function(select) {
            const selected = select.options[select.selectedIndex];
            if (!selected || !selected.value) {
                return;
            }

            total += parseInt(selected.dataset.points || '0', 10);
            if ((selected.dataset.tolerance || '') === 'non_tolerable') {
                nonTolerable++;
            }
        });

        totalEl.textContent = String(total);
        nonTolEl.textContent = String(nonTolerable);
        recommendationEl.textContent = recommendationFromPoints(total, nonTolerable > 0);
    }

    addBtn.addEventListener('click', function() {
        const wrapper = document.createElement('div');
        wrapper.className = 'disciplinary-item-row grid grid-cols-1 gap-3 mb-3';
        wrapper.innerHTML = '' +
            '<select name="indication_id[]" class="disciplinary-indication-select" required>' + buildSelectOptions() + '</select>' +
            '<textarea name="item_notes[]" rows="2" placeholder="Catatan item indikasi"></textarea>' +
            '<button type="button" class="btn-danger btn-sm disciplinary-remove-item">Hapus Item</button>';
        container.appendChild(wrapper);
    });

    container.addEventListener('change', function(e) {
        if (e.target.classList.contains('disciplinary-indication-select')) {
            recalcSummary();
        }
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('disciplinary-remove-item')) {
            e.target.closest('.disciplinary-item-row').remove();
            recalcSummary();
        }
    });

    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('#disciplinaryCasesTable').DataTable({
            pageLength: 10,
            order: [[0, 'desc']],
            language: {
                url: '/assets/design/js/datatables-id.json'
            }
        });
    }

    recalcSummary();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
