<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';

$user = $_SESSION['user_rh'] ?? [];
$role = $user['role'] ?? '';

if (strtolower($role) === 'staff') {
    header('Location: dashboard.php');
    exit;
}

$pageTitle = 'Calon Asisten Manager';

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

$query = "
    SELECT
        m.id,
        m.ic_name,
        m.citizen_id,
        m.created_at,
        m.status,
        m.rejection_stage,
        r.score_total AS ai_score,
        r.decision   AS ai_decision,
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
    LEFT JOIN ai_test_results r ON r.applicant_id = m.id
    LEFT JOIN applicant_interview_results ir ON ir.applicant_id = m.id
    LEFT JOIN applicant_final_decisions fd ON fd.applicant_id = m.id
";

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

$query .= " ORDER BY m.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute();
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="page-title">Daftar Calon Asisten Manager</h1>
                <p class="page-subtitle">Monitoring jalur rekrutmen General Affair untuk calon asisten manager</p>
            </div>
            <a href="<?= htmlspecialchars(ems_url('/public/recruitment_form_assistant_manager.php')) ?>" target="_blank" rel="noopener" class="btn-primary btn-sm">
                <?= ems_icon('plus', 'h-4 w-4') ?>
                <span>Asisten Manager Baru</span>
            </a>
        </div>

        <div class="card">
            <div class="card-header">Calon Asisten Manager</div>

            <div class="table-wrapper">
                <table id="assistantManagerCandidateTable" class="table-custom">
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
                            $interviewScore = (float)($c['interview_score'] ?? 0);
                            $aiScore = (float)($c['ai_score'] ?? 0);
                            $confidence = (float)($c['confidence'] ?? 0);
                            $combinedScore = '-';

                            if ((int)($c['interview_locked'] ?? 0) === 1) {
                                $combinedScore = round(($interviewScore * 0.6) + ($aiScore * 0.3) + ($confidence * 0.1), 2);
                            }

                            $statusMeta = assistantManagerStatusMeta((string)$c['status']);
                            $finalDecisionMeta = assistantManagerDecisionMeta($c['final_result']);
                            $aiDecisionMeta = assistantManagerDecisionMeta($c['ai_decision']);
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
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
                language: {
                    url: '/assets/design/js/datatables-id.json'
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
