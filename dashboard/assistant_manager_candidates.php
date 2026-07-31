<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/../config/recruitment_settings.php';
require_once __DIR__ . '/../actions/ai_scoring_engine.php';

$user = $_SESSION['user_rh'] ?? [];
$role = $user['role'] ?? '';
$userDivision = ems_normalize_division($user['division'] ?? '');

if (strtolower($role) === 'staff') {
    header('Location: dashboard.php');
    exit;
}

$pageTitle = 'Calon Asisten Manager';

function gaCandidateCanManageRecruitmentSettings(array $user, string $division): bool
{
    if (ems_current_user_is_programmer_roxwood()) {
        return true;
    }

    return in_array($division, ['General Affair', 'Executive', 'Human Resource'], true);
}

function assistantManagerStatusMeta(string $status): array
{
    return match ($status) {
        'ai_completed' => ['label' => 'Menunggu', 'class' => 'badge-warning'],
        'interview' => ['label' => 'Interview', 'class' => 'badge-info'],
        'final_review' => ['label' => 'Final Review', 'class' => 'badge-info'],
        'accepted' => ['label' => 'Diterima', 'class' => 'badge-success'],
        'rejected' => ['label' => 'Ditolak', 'class' => 'badge-danger'],
        default => ['label' => ucwords(str_replace('_', ' ', $status)), 'class' => 'badge-secondary'],
    };
}

function assistantManagerDecisionMeta(?string $decision): array
{
    $decision = (string)($decision ?? '');

    return match (strtolower($decision)) {
        'recommended' => ['label' => 'Direkomendasikan', 'class' => 'badge-success'],
        'not_recommended' => ['label' => 'Tidak Direkomendasikan', 'class' => 'badge-danger'],
        'follow_up_required' => ['label' => 'Perlu Tindak Lanjut', 'class' => 'badge-warning'],
        'lolos' => ['label' => 'Lolos', 'class' => 'badge-success'],
        'tidak_lolos' => ['label' => 'Tidak Lolos', 'class' => 'badge-danger'],
        'proceed' => ['label' => 'Lanjut Interview', 'class' => 'badge-info'],
        'reject' => ['label' => 'Ditolak Sistem', 'class' => 'badge-danger'],
        '' => ['label' => '-', 'class' => 'badge-secondary'],
        default => ['label' => ucwords(str_replace('_', ' ', $decision)), 'class' => 'badge-secondary'],
    };
}

function assistantManagerRecomputedResult(array $row): array
{
    $answers = json_decode((string)($row['answers_json'] ?? ''), true);
    if (!is_array($answers) || $answers === []) {
        return [
            'ai_score' => (float)($row['ai_score'] ?? 0),
            'ai_decision' => (string)($row['ai_decision'] ?? ''),
        ];
    }

    $traitItems = ems_assistant_manager_trait_items(array_map('intval', array_keys($answers)));
    $scores = [];
    foreach ($traitItems as $trait => $items) {
        $scores[$trait] = calculateTraitScore($answers, $items);
    }

    $biasFlags = array_values(array_unique(array_merge(
        detectResponseBias($answers, $traitItems),
        ems_assistant_manager_trap_flags($answers)
    )));
    $crossFlags = crossValidateWithForm($scores, $row, 'assistant_manager');
    $finalDecision = makeFinalDecision($scores, $biasFlags, $crossFlags, (int)($row['duration_seconds'] ?? 0), 'assistant_manager');

    return [
        'ai_score' => (float)($finalDecision['composite_score'] ?? $finalDecision['average_score'] ?? 0),
        'ai_decision' => (string)($finalDecision['decision'] ?? ($row['ai_decision'] ?? '')),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finish_interview'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    $applicantId = (int)($_POST['applicant_id'] ?? 0);
    if ($applicantId <= 0) {
        exit('Invalid applicant');
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM (
            SELECT hr_id
            FROM applicant_interview_scores
            WHERE applicant_id = ?
            GROUP BY hr_id
        ) t
    ");
    $stmt->execute([$applicantId]);
    $totalHr = (int)$stmt->fetchColumn();

    if ($totalHr < 2) {
        header('Location: assistant_manager_candidates.php?error=min_hr');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE medical_applicants
        SET status = 'final_review'
        WHERE id = ?
          AND status = 'interview'
    ");
    $stmt->execute([$applicantId]);

    header('Location: assistant_manager_candidates.php?interview_done=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_decision'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    $applicantId = (int)($_POST['applicant_id'] ?? 0);
    $decision = $_POST['ai_decision'] ?? '';

    if ($applicantId <= 0 || !in_array($decision, ['proceed', 'reject'], true)) {
        exit('Invalid request');
    }

    if ($decision === 'proceed') {
        $stmt = $pdo->prepare("
            UPDATE medical_applicants
            SET status = 'interview'
            WHERE id = ?
              AND status = 'ai_completed'
        ");
        $stmt->execute([$applicantId]);
    }

    if ($decision === 'reject') {
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                UPDATE medical_applicants
                SET status = 'rejected',
                    rejection_stage = 'ai'
                WHERE id = ?
                  AND status = 'ai_completed'
            ");
            $stmt->execute([$applicantId]);

            $stmt = $pdo->prepare("
                INSERT INTO applicant_final_decisions
                (
                    applicant_id,
                    system_result,
                    overridden,
                    override_reason,
                    final_result,
                    decided_by
                ) VALUES (?, ?, 0, NULL, ?, ?)
            ");
            $stmt->execute([
                $applicantId,
                'tidak_lolos',
                'tidak_lolos',
                $user['name'] ?? 'System (AI)',
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            exit('Gagal memproses penolakan AI');
        }
    }

    header('Location: assistant_manager_candidates.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ga_recruitment_portal_settings'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    if (!gaCandidateCanManageRecruitmentSettings($user, $userDivision)) {
        exit('Akses setting rekrutmen ditolak');
    }

    $portalStatus = strtolower(trim((string)($_POST['portal_status'] ?? 'close')));
    $isOpen = $portalStatus !== 'close';
    $closedMessage = trim((string)($_POST['closed_message'] ?? ''));
    $currentBatchInput = (int)($_POST['current_batch'] ?? 1);

    ems_recruitment_save_settings(
        $pdo,
        $isOpen,
        $closedMessage,
        (int)($user['id'] ?? 0),
        'assistant_manager',
        $currentBatchInput > 0 ? $currentBatchInput : 1
    );

    header('Location: assistant_manager_candidates.php?recruitment_settings_saved=1');
    exit;
}

$hasGaBatchColumn = ems_column_exists($pdo, 'medical_applicants', 'ga_batch');
$gaBatchSelect = $hasGaBatchColumn ? 'm.ga_batch' : 'NULL';
$recruitmentPortalSettings = ems_recruitment_get_settings($pdo, 'assistant_manager');
$recruitmentPortalCurrentBatch = (int)($recruitmentPortalSettings['current_batch'] ?? 1);

if (!$hasGaBatchColumn) {
    $batchFilter = null;
} elseif (!isset($_GET['batch'])) {
    // Belum ada filter eksplisit di URL -> langsung tampilkan periode Pendaftaran
    // yang sedang aktif/terakhir (bukan "Semua Pendaftaran") begitu halaman dibuka.
    $batchFilter = $recruitmentPortalCurrentBatch;
} elseif ($_GET['batch'] === '') {
    // User eksplisit memilih "Semua Pendaftaran" dari dropdown.
    $batchFilter = null;
} else {
    $batchFilter = (int)$_GET['batch'];
}

$query = "
    SELECT
        m.id,
        m.ic_name,
        m.citizen_id,
        m.created_at,
        m.status,
        m.rejection_stage,
        m.rule_commitment,
        m.other_city_responsibility,
        m.motivation,
        m.recruitment_type,
        r.score_total AS ai_score,
        r.decision   AS ai_decision,
        r.answers_json,
        r.duration_seconds,
        ir.average_score   AS interview_score,
        ir.ml_confidence   AS confidence,
        ir.is_locked       AS interview_locked,
        fd.final_result,
        {$gaBatchSelect} AS ga_batch,
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
    LEFT JOIN ai_test_results r ON r.applicant_id = m.id
    LEFT JOIN applicant_interview_results ir ON ir.applicant_id = m.id
    LEFT JOIN applicant_final_decisions fd ON fd.applicant_id = m.id
";

$queryParams = [];

if (ems_column_exists($pdo, 'medical_applicants', 'recruitment_type')) {
    $query .= "
        INNER JOIN (
            SELECT MAX(id) AS latest_id
            FROM medical_applicants
            WHERE COALESCE(NULLIF(recruitment_type, ''), 'medical_candidate') = 'assistant_manager'
            GROUP BY COALESCE(NULLIF(citizen_id, ''), CONCAT('__assistant_manager__', id))
        ) latest_assistant_manager ON latest_assistant_manager.latest_id = m.id
        WHERE COALESCE(NULLIF(m.recruitment_type, ''), 'medical_candidate') = 'assistant_manager'
    ";
} else {
    $query .= " WHERE 1 = 0";
}

if ($batchFilter !== null && $hasGaBatchColumn) {
    $query .= " AND m.ga_batch = ?";
    $queryParams[] = $batchFilter;
}

$query .= $hasGaBatchColumn ? " ORDER BY m.ga_batch DESC, m.created_at DESC" : " ORDER BY m.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($queryParams);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Daftar nomor Pendaftaran yang tersedia (dari seluruh kandidat GA, bukan hanya hasil filter saat ini)
$availableBatches = [];
if ($hasGaBatchColumn && ems_column_exists($pdo, 'medical_applicants', 'recruitment_type')) {
    $batchListStmt = $pdo->query("
        SELECT DISTINCT m.ga_batch
        FROM medical_applicants m
        INNER JOIN (
            SELECT MAX(id) AS latest_id
            FROM medical_applicants
            WHERE COALESCE(NULLIF(recruitment_type, ''), 'medical_candidate') = 'assistant_manager'
            GROUP BY COALESCE(NULLIF(citizen_id, ''), CONCAT('__assistant_manager__', id))
        ) latest_assistant_manager ON latest_assistant_manager.latest_id = m.id
        WHERE COALESCE(NULLIF(m.recruitment_type, ''), 'medical_candidate') = 'assistant_manager'
          AND m.ga_batch IS NOT NULL
        ORDER BY m.ga_batch DESC
    ");
    $availableBatches = $batchListStmt ? array_map('intval', $batchListStmt->fetchAll(PDO::FETCH_COLUMN)) : [];
}

// Pastikan periode Pendaftaran yang sedang aktif selalu muncul di dropdown,
// walau belum ada kandidat yang mendaftar di periode itu.
if ($hasGaBatchColumn && !in_array($recruitmentPortalCurrentBatch, $availableBatches, true)) {
    array_unshift($availableBatches, $recruitmentPortalCurrentBatch);
    rsort($availableBatches);
}

$recruitmentPortalIsOpen = (int)($recruitmentPortalSettings['is_open'] ?? 0) === 1;
$recruitmentPortalClosedMessage = (string)($recruitmentPortalSettings['closed_message'] ?? '');
$canManageRecruitmentSettings = gaCandidateCanManageRecruitmentSettings($user, $userDivision);
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="page-title">Daftar Calon Asisten Manager</h1>
                <p class="page-subtitle">Monitoring jalur rekrutmen General Affair untuk calon asisten manager, dikelompokkan per periode Pendaftaran</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="<?= $recruitmentPortalIsOpen ? 'badge-success' : 'badge-danger' ?>">
                    <?= $recruitmentPortalIsOpen ? 'Rekrutmen GA Open' : 'Rekrutmen GA Close' ?>
                </span>
                <?php if ($canManageRecruitmentSettings): ?>
                    <button type="button" id="openGaRecruitmentSettingsModal" class="btn-secondary btn-sm">
                        <?= ems_icon('cog-6-tooth', 'h-4 w-4') ?>
                        <span>Setting Rekrutmen</span>
                    </button>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(ems_url('/public/ga_recruitment.php')) ?>" target="_blank" rel="noopener" class="btn-primary btn-sm">
                    <?= ems_icon('plus', 'h-4 w-4') ?>
                    <span>Asisten Manager Baru</span>
                </a>
            </div>
        </div>

        <?php if (isset($_GET['recruitment_settings_saved']) && $_GET['recruitment_settings_saved'] === '1'): ?>
            <?= ems_render_toast_script('Setting rekrutmen asisten manager berhasil disimpan.', 'success', 'Calon Asisten Manager') ?>
        <?php endif; ?>

        <div class="card card-section mb-4">
            <div class="card-header">Filter Pendaftaran</div>
            <div class="card-body">
                <form method="GET" id="gaBatchFilterForm" class="filter-bar">
                    <div class="filter-group">
                        <label>Periode Pendaftaran</label>
                        <select name="batch" id="gaBatchSelect" class="form-control min-w-[200px]">
                            <option value="">Semua Pendaftaran</option>
                            <?php foreach ($availableBatches as $batchNumber): ?>
                                <option value="<?= (int)$batchNumber ?>" <?= $batchFilter === $batchNumber ? 'selected' : '' ?>>
                                    Pendaftaran <?= (int)$batchNumber ?><?= $batchNumber === $recruitmentPortalCurrentBatch ? ' (Aktif)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-secondary btn-sm">Terapkan</button>
                    <?php if ($batchFilter !== null): ?>
                        <a href="assistant_manager_candidates.php" class="btn-secondary btn-sm">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <?= $batchFilter !== null ? 'Calon Asisten Manager — Pendaftaran ' . (int)$batchFilter : 'Calon Asisten Manager — Semua Pendaftaran' ?>
            </div>

            <div class="table-wrapper">
                <table id="assistantManagerCandidateTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Pendaftaran</th>
                            <th>Nama</th>
                            <th>Status</th>
                            <th>Skor Tes</th>
                            <th>Skor Interview HR</th>
                            <th>Confidence</th>
                            <th>Skor Gabungan</th>
                            <th>Interviewer</th>
                            <th>Hasil</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $i => $c): ?>
                            <?php
                            $recomputedResult = assistantManagerRecomputedResult($c);
                            $interviewScore = (float)($c['interview_score'] ?? 0);
                            $aiScore = (float)($recomputedResult['ai_score'] ?? $c['ai_score'] ?? 0);
                            $confidence = (float)($c['confidence'] ?? 0);
                            $combinedScore = '-';

                            if ((int)($c['interview_locked'] ?? 0) === 1) {
                                $combinedScore = round(($interviewScore * 0.6) + ($aiScore * 0.3) + ($confidence * 0.1), 2);
                            }

                            $statusMeta = assistantManagerStatusMeta((string)$c['status']);
                            $finalDecisionMeta = assistantManagerDecisionMeta($c['final_result']);
                            $aiDecisionMeta = assistantManagerDecisionMeta($recomputedResult['ai_decision'] ?? $c['ai_decision']);
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= $c['ga_batch'] !== null ? '<span class="badge-secondary">Pendaftaran ' . (int)$c['ga_batch'] . '</span>' : '<span class="meta-text-xs">-</span>' ?></td>
                                <td>
                                    <strong><a href="candidate_detail.php?id=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['ic_name']) ?></a></strong>
                                    <div class="meta-text">Daftar: <?= date('d M Y', strtotime($c['created_at'])) ?></div>
                                </td>
                                <td><span class="<?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span></td>
                                <td><?= $aiScore ?: '-' ?></td>
                                <td><?= $interviewScore ?: '-' ?></td>
                                <td><?= $confidence ? $confidence . '%' : '-' ?></td>
                                <td><strong><?= $combinedScore ?></strong></td>
                                <td class="text-sm leading-5 text-slate-700">
                                    <?php if ($c['interviewers']): ?>
                                        <?= htmlspecialchars($c['interviewers']) ?>
                                        <?php if ((int)$c['total_hr'] > 1): ?>
                                            <div class="meta-text">(<?= (int)$c['total_hr'] ?> Orang)</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['final_result']): ?>
                                        <span class="<?= htmlspecialchars($finalDecisionMeta['class']) ?>"><?= htmlspecialchars($finalDecisionMeta['label']) ?></span>
                                    <?php else: ?>
                                        <span class="<?= htmlspecialchars($aiDecisionMeta['class']) ?>"><?= htmlspecialchars($aiDecisionMeta['label']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="action-cell">
                                    <div class="candidate-action-stack">
                                        <?php if ($c['status'] === 'ai_completed'): ?>
                                            <form method="post">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="ai_decision" value="proceed">
                                                <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn-primary btn-sm action-icon-btn candidate-action-btn" onclick="return confirm('Lanjutkan ke tahap wawancara?')" title="Lanjut ke wawancara" aria-label="Lanjut ke wawancara">
                                                    <?= ems_icon('arrow-right', 'h-4 w-4') ?>
                                                </button>
                                            </form>

                                            <form method="post">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="ai_decision" value="reject">
                                                <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn-danger btn-sm action-icon-btn candidate-action-btn" onclick="return confirm('Tolak kandidat tanpa proses wawancara?')" title="Tolak kandidat" aria-label="Tolak kandidat">
                                                    <?= ems_icon('x-mark', 'h-4 w-4') ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (in_array($c['status'], ['interview'], true)): ?>
                                            <a href="candidate_interview_multi.php?id=<?= (int)$c['id'] ?>" class="btn-primary btn-sm action-icon-btn candidate-action-btn" title="Interview kandidat" aria-label="Interview kandidat">
                                                <?= ems_icon('microphone', 'h-4 w-4') ?>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($c['status'] === 'interview'): ?>
                                            <form method="post">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="finish_interview" value="1">
                                                <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                                <button type="submit" class="btn-warning btn-sm action-icon-btn btn-finish-interview candidate-action-btn" data-total-hr="<?= (int)$c['total_hr'] ?>" title="Selesaikan interview" aria-label="Selesaikan interview">
                                                    <?= ems_icon('check-circle', 'h-4 w-4') ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($c['status'] === 'final_review' || in_array($c['status'], ['accepted', 'rejected'], true)): ?>
                                            <a href="candidate_decision.php?id=<?= (int)$c['id'] ?>" class="btn-success btn-sm action-icon-btn candidate-action-btn" title="Lihat keputusan kandidat" aria-label="Lihat keputusan kandidat">
                                                <?= ems_icon('check-badge', 'h-4 w-4') ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<?php if ($canManageRecruitmentSettings): ?>
    <div id="gaRecruitmentSettingsModal" class="modal-overlay hidden">
        <div class="modal-box modal-shell modal-frame-md">
            <div class="modal-head">
                <div>
                    <div class="modal-title">Setting Open / Close Rekrutmen Asisten Manager</div>
                    <div class="meta-text mt-1">Status ini terpisah dari rekrutmen medis dan hanya berlaku untuk jalur Calon Asisten Manager.</div>
                </div>
                <button type="button" class="modal-close-btn" data-close-ga-recruitment-modal aria-label="Tutup modal">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>

            <form method="post" class="modal-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="save_ga_recruitment_portal_settings" value="1">

                <div class="modal-content">
                    <div class="space-y-5">
                        <div class="form-group">
                            <label for="ga_portal_status" class="text-sm font-semibold text-slate-900">Status Rekrutmen Asisten Manager</label>
                            <select id="ga_portal_status" name="portal_status" class="w-full" required>
                                <option value="open" <?= $recruitmentPortalIsOpen ? 'selected' : '' ?>>Open</option>
                                <option value="close" <?= !$recruitmentPortalIsOpen ? 'selected' : '' ?>>Close</option>
                            </select>
                            <small class="hint-info">Jika `close`, halaman `/public/ga_recruitment.php` dan form pendaftaran asisten manager akan diarahkan ke halaman pemberitahuan. Rekrutmen medis tidak terpengaruh.</small>
                        </div>

                        <div class="form-group">
                            <label for="ga_current_batch" class="text-sm font-semibold text-slate-900">Nomor Pendaftaran Saat Ini</label>
                            <input type="number" id="ga_current_batch" name="current_batch" min="1" step="1" value="<?= (int)$recruitmentPortalCurrentBatch ?>" required>
                            <small class="hint-info">Setiap pendaftar baru yang masuk selama status Open akan otomatis ditandai dengan nomor ini (contoh: isi <strong>2</strong> untuk membuka "Pendaftaran 2"). Data kandidat lama tidak berubah.</small>
                        </div>

                        <div class="form-group">
                            <label for="ga_closed_message" class="text-sm font-semibold text-slate-900">Pesan Saat Close</label>
                            <textarea id="ga_closed_message" name="closed_message" rows="5" placeholder="Tulis pesan penutupan rekrutmen asisten manager"><?= htmlspecialchars($recruitmentPortalClosedMessage) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-foot">
                    <div class="modal-actions">
                        <button type="button" class="btn-secondary" data-close-ga-recruitment-modal>Batal</button>
                        <button type="submit" class="btn-primary">Simpan</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('submit', function(e) {
            const form = e.target;
            const button = form.querySelector('.btn-finish-interview');
            if (!button) return;

            const totalHr = parseInt(button.dataset.totalHr || '0', 10);
            if (totalHr < 2) {
                e.preventDefault();
                alert('Interview belum dapat diselesaikan. Minimal diperlukan 2 HR.');
                return false;
            }

            if (!confirm('Tandai interview selesai?')) {
                e.preventDefault();
                return false;
            }
        }, true);

        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#assistantManagerCandidateTable').DataTable({
                pageLength: 10,
                scrollX: true,
                autoWidth: false,
                order: [[1, 'desc']],
                language: {
                    url: '/assets/design/js/datatables-id.json'
                }
            });
        }

        const gaModal = document.getElementById('gaRecruitmentSettingsModal');
        const gaOpenButton = document.getElementById('openGaRecruitmentSettingsModal');
        const gaCloseButtons = document.querySelectorAll('[data-close-ga-recruitment-modal]');

        function openGaModal() {
            if (!gaModal) return;
            gaModal.classList.remove('hidden');
            gaModal.style.display = 'flex';
            document.body.classList.add('modal-open');
        }
        function closeGaModal() {
            if (!gaModal) return;
            gaModal.classList.add('hidden');
            gaModal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        gaOpenButton?.addEventListener('click', openGaModal);
        gaCloseButtons.forEach(function(btn) { btn.addEventListener('click', closeGaModal); });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') { closeGaModal(); }
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
