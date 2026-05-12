<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/../actions/ai_scoring_engine.php';

$user = $_SESSION['user_rh'] ?? [];
$role = $user['role'] ?? '';
$userDivision = ems_normalize_division($user['division'] ?? '');

// HARD GUARD
if (strtolower($role) === 'staff') {
    header('Location: dashboard.php');
    exit;
}

$listRecruitmentType = 'medical_candidate';
$pageTitle = 'Calon Kandidat';

function candidateCanHardDelete(array $user, string $userDivision): bool
{
    if (in_array($userDivision, ['Human Capital', 'Human Resource', 'Executive'], true)) {
        return true;
    }

    $name = (string)($user['full_name'] ?? $user['name'] ?? '');
    return ems_is_programmer_roxwood_name($name);
}

function candidateDeleteCleanupFilePaths(array $relativePaths): void
{
    $directories = [];

    foreach ($relativePaths as $relativePath) {
        $relativePath = trim((string)$relativePath);
        if ($relativePath === '') {
            continue;
        }

        $absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/'));
        if ($absolutePath === false || !is_file($absolutePath)) {
            continue;
        }

        @unlink($absolutePath);
        $directories[dirname($absolutePath)] = true;
    }

    foreach (array_keys($directories) as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        $items = @scandir($directory);
        if ($items === false) {
            continue;
        }

        $remaining = array_values(array_diff($items, ['.', '..']));
        if ($remaining === []) {
            @rmdir($directory);
        }
    }
}

function candidateDeletePermanently(PDO $pdo, int $applicantId): array
{
    $filePaths = [];

    if (ems_table_exists($pdo, 'applicant_documents')) {
        $stmt = $pdo->prepare("
            SELECT file_path
            FROM applicant_documents
            WHERE applicant_id = ?
        ");
        $stmt->execute([$applicantId]);
        $filePaths = array_map(
            static fn(array $row): string => (string)($row['file_path'] ?? ''),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    $pdo->beginTransaction();

    try {
        foreach ([
            'applicant_interview_question_responses',
            'applicant_interview_question_packs',
            'applicant_interview_scores',
            'applicant_interview_results',
            'applicant_final_decisions',
            'applicant_documents',
            'ai_test_results',
        ] as $table) {
            if (!ems_table_exists($pdo, $table)) {
                continue;
            }

            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE applicant_id = ?");
            $stmt->execute([$applicantId]);
        }

        $stmt = $pdo->prepare("DELETE FROM medical_applicants WHERE id = ?");
        $stmt->execute([$applicantId]);
        $deletedApplicants = (int)$stmt->rowCount();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    candidateDeleteCleanupFilePaths($filePaths);

    return [
        'deleted_applicants' => $deletedApplicants,
        'deleted_files' => count(array_filter(array_map('trim', $filePaths))),
    ];
}

function candidateStatusMeta(string $status): array
{
    return match ($status) {
        'ai_completed' => ['label' => 'Menunggu', 'class' => 'badge-warning'],
        'interview' => ['label' => 'Interview', 'class' => 'badge-info'],
        'final_review' => ['label' => 'Final Review', 'class' => 'badge-info'],
        'accepted' => ['label' => 'Diterima', 'class' => 'badge-success'],
        'rejected' => ['label' => 'Ditolak', 'class' => 'badge-danger'],
        default => [
            'label' => ucwords(str_replace('_', ' ', $status)),
            'class' => 'badge-secondary',
        ],
    };
}

function candidateDecisionMeta(?string $decision): array
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
        default => [
            'label' => ucwords(str_replace('_', ' ', $decision)),
            'class' => 'badge-secondary',
        ],
    };
}

function candidateRecomputedResult(array $row): array
{
    $answers = json_decode((string)($row['answers_json'] ?? ''), true);
    if (!is_array($answers) || $answers === []) {
        return [
            'ai_score' => (float)($row['ai_score'] ?? 0),
            'ai_decision' => (string)($row['ai_decision'] ?? ''),
        ];
    }

    $recruitmentType = ems_normalize_recruitment_type($row['recruitment_type'] ?? 'medical_candidate');
    $questionIds = array_map('intval', array_keys($answers));
    $traitItems = $recruitmentType === 'assistant_manager'
        ? ems_assistant_manager_trait_items($questionIds)
        : getTraitItems($recruitmentType);

    $scores = [];
    foreach ($traitItems as $trait => $items) {
        $scores[$trait] = calculateTraitScore($answers, $items);
    }

    $biasFlags = detectResponseBias($answers);
    if ($recruitmentType === 'assistant_manager') {
        $biasFlags = array_values(array_unique(array_merge($biasFlags, ems_assistant_manager_trap_flags($answers))));
    }

    $crossFlags = crossValidateWithForm($scores, $row, $recruitmentType);
    $finalDecision = makeFinalDecision($scores, $biasFlags, $crossFlags, (int)($row['duration_seconds'] ?? 0), $recruitmentType);

    return [
        'ai_score' => (float)($finalDecision['composite_score'] ?? $finalDecision['average_score'] ?? ($row['ai_score'] ?? 0)),
        'ai_decision' => (string)($finalDecision['decision'] ?? ($row['ai_decision'] ?? '')),
    ];
}

/* ===============================
   SELESAI INTERVIEW (DARI LIST)
   =============================== */
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
        header('Location: candidates.php?error=min_hr');
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE medical_applicants
        SET status = 'final_review'
        WHERE id = ?
          AND status = 'interview'
    ");
    $stmt->execute([$applicantId]);

    header('Location: candidates.php?interview_done=1');
    exit;
}

/* ===============================
   KEPUTUSAN PASCA AI (TANPA INTERVIEW)
   =============================== */
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
            SELECT score_total
            FROM ai_test_results
            WHERE applicant_id = ?
        ");
            $stmt->execute([$applicantId]);
            $ai = $stmt->fetch(PDO::FETCH_ASSOC);

            $aiScore = (float)($ai['score_total'] ?? 0);

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
                $user['name'] ?? 'System (AI)'
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            exit('Gagal memproses penolakan AI');
        }
    }

    header('Location: candidates.php');
    exit;
}

/* ===============================
   HAPUS PERMANEN KANDIDAT
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_candidate_permanently'])) {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    if (!candidateCanHardDelete($user, $userDivision)) {
        exit('Akses hapus permanen ditolak');
    }

    $applicantId = (int)($_POST['applicant_id'] ?? 0);
    if ($applicantId <= 0) {
        exit('Invalid applicant');
    }

    try {
        candidateDeletePermanently($pdo, $applicantId);
        unset($_SESSION['recruitment_track_map'][(string)$applicantId]);
        header('Location: candidates.php?deleted=1');
        exit;
    } catch (Throwable $e) {
        header('Location: candidates.php?delete_error=1');
        exit;
    }
}

$candidateSql = "
    SELECT
        m.id,
        m.ic_name,
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
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="page-title">Daftar Calon Kandidat</h1>
                <p class="page-subtitle">Monitoring hasil rekrutmen dan penilaian AI</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="<?= htmlspecialchars(ems_url('/dashboard/candidates_export.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary btn-sm">
                    <?= ems_icon('document-arrow-down', 'h-4 w-4') ?>
                    <span>Export Excel</span>
                </a>
                <a href="<?= htmlspecialchars(ems_url('/public/recruitment_form.php')) ?>" target="_blank" rel="noopener" class="btn-primary btn-sm">
                    <?= ems_icon('plus', 'h-4 w-4') ?>
                    <span>Kandidat Baru</span>
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Calon Kandidat</div>

            <?php if (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>
                <div class="alert alert-success mb-4">
                    Data kandidat berhasil dihapus permanen.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['delete_error']) && $_GET['delete_error'] === '1'): ?>
                <div class="alert alert-danger mb-4">
                    Gagal menghapus permanen data kandidat.
                </div>
            <?php endif; ?>

            <div class="table-wrapper">
                <table id="candidateTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
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
                            $recomputedResult = candidateRecomputedResult($c);
                            $interviewScore = (float)($c['interview_score'] ?? 0);
                            $aiScore = (float)($recomputedResult['ai_score'] ?? $c['ai_score'] ?? 0);
                            $confidence = (float)($c['confidence'] ?? 0);
                            $combinedScore = '-';

                            if ((int)($c['interview_locked'] ?? 0) === 1) {
                                $combinedScore = round(
                                    ($interviewScore * 0.6) +
                                        ($aiScore * 0.3) +
                                        ($confidence * 0.1),
                                    2
                                );
                            }

                            $statusMeta = candidateStatusMeta((string)$c['status']);
                            $statusBadge = '<span class="' . htmlspecialchars($statusMeta['class']) . '">' . htmlspecialchars($statusMeta['label']) . '</span>';
                            $finalDecisionMeta = candidateDecisionMeta($c['final_result']);
                            $aiDecisionMeta = candidateDecisionMeta($recomputedResult['ai_decision'] ?? $c['ai_decision']);
                            $canHardDelete = candidateCanHardDelete($user, $userDivision);
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong>
                                        <a href="candidate_detail.php?id=<?= (int)$c['id'] ?>">
                                            <?= htmlspecialchars($c['ic_name']) ?>
                                        </a>
                                    </strong>
                                    <div class="meta-text">
                                        Daftar: <?= date('d M Y', strtotime($c['created_at'])) ?>
                                    </div>
                                </td>
                                <td><?= $statusBadge ?></td>
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
                                        <span class="<?= htmlspecialchars($finalDecisionMeta['class']) ?>">
                                            <?= htmlspecialchars($finalDecisionMeta['label']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="<?= htmlspecialchars($aiDecisionMeta['class']) ?>">
                                            <?= htmlspecialchars($aiDecisionMeta['label']) ?>
                                        </span>
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

                                    <?php if ($canHardDelete): ?>
                                        <form method="post">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="delete_candidate_permanently" value="1">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button
                                                type="submit"
                                                class="btn-danger btn-sm action-icon-btn candidate-action-btn"
                                                onclick="return confirm('Hapus permanen kandidat ini?\n\nSemua data rekrutmen, hasil AI, interview, keputusan final, dan dokumen upload akan dihapus total dan tidak bisa dikembalikan.')"
                                                title="Hapus permanen kandidat"
                                                aria-label="Hapus permanen kandidat">
                                                <?= ems_icon('trash', 'h-4 w-4') ?>
                                            </button>
                                        </form>
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

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('submit', function(e) {
            const form = e.target;
            const button = form.querySelector('.btn-finish-interview');

            if (!button) return;

            const totalHr = parseInt(button.dataset.totalHr || '0', 10);

            if (totalHr < 2) {
                e.preventDefault();
                alert(
                    'Interview belum dapat diselesaikan.\n\n' +
                    'Penilaian baru diberikan oleh ' + totalHr + ' HR.\n' +
                    'Minimal diperlukan 2 HR.\n\n' +
                    'Silakan tunggu HR lain memberikan penilaian.'
                );
                return false;
            }

            if (!confirm('Tandai interview selesai?')) {
                e.preventDefault();
                return false;
            }
        }, true);
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#candidateTable').DataTable({
                pageLength: 10,
                scrollX: true,
                autoWidth: false,
                language: {
                    url: '/assets/design/js/datatables-id.json'
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
