<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$user = $_SESSION['user_rh'] ?? [];
$role = $user['role'] ?? '';

// HARD GUARD
if (strtolower($role) === 'staff') {
    header('Location: dashboard.php');
    exit;
}

$pageTitle = 'Calon Kandidat';

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

$candidates = $pdo->query("
    SELECT
        m.id,
        m.ic_name,
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
    LEFT JOIN ai_test_results r
        ON r.applicant_id = m.id
    LEFT JOIN applicant_interview_results ir
        ON ir.applicant_id = m.id
    LEFT JOIN applicant_final_decisions fd
        ON fd.applicant_id = m.id
    ORDER BY m.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">
        <h1 class="page-title">Daftar Calon Kandidat</h1>
        <p class="page-subtitle">Monitoring hasil rekrutmen dan penilaian AI</p>

        <div class="card">
            <div class="card-header">Calon Kandidat</div>

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
                            <th>Hasil Akhir</th>
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
                                $combinedScore = round(
                                    ($interviewScore * 0.6) +
                                        ($aiScore * 0.3) +
                                        ($confidence * 0.1),
                                    2
                                );
                            }

                            switch ($c['status']) {
                                case 'ai_completed':
                                    $statusBadge = '<span class="badge-warning">Menunggu</span>';
                                    break;
                                case 'interview':
                                    $statusBadge = '<span class="badge-info">Interview</span>';
                                    break;
                                case 'final_review':
                                    $statusBadge = '<span class="badge-info">Final Review</span>';
                                    break;
                                case 'accepted':
                                    $statusBadge = '<span class="badge-success">Diterima</span>';
                                    break;
                                case 'rejected':
                                    $statusBadge = '<span class="badge-danger">Ditolak</span>';
                                    break;
                                default:
                                    $statusBadge = '<span class="badge badge-secondary">' . htmlspecialchars(strtoupper($c['status'])) . '</span>';
                                    break;
                            }
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
                                        <span class="badge badge-<?= $c['final_result'] === 'lolos' ? 'success' : 'danger' ?>">
                                            <?= strtoupper($c['final_result']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">
                                            <?= strtoupper($c['ai_decision'] ?? '-') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <?php if ($c['status'] === 'ai_completed'): ?>
                                        <form method="post" class="inline">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="ai_decision" value="proceed">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-primary" onclick="return confirm('Lanjutkan ke tahap wawancara?')">
                                                <?= ems_icon('arrow-right', 'h-4 w-4') ?>
                                                <span>Lanjut Wawancara</span>
                                            </button>
                                        </form>

                                        <form method="post" class="inline">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="ai_decision" value="reject">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Tolak kandidat tanpa proses wawancara?')">
                                                <?= ems_icon('x-mark', 'h-4 w-4') ?>
                                                <span>Tidak Diterima</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (in_array($c['status'], ['interview'], true)): ?>
                                        <a href="candidate_interview_multi.php?id=<?= (int)$c['id'] ?>" class="btn btn-primary">
                                            <?= ems_icon('microphone', 'h-4 w-4') ?>
                                            <span>Interview</span>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($c['status'] === 'interview'): ?>
                                        <form method="post" class="inline">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="finish_interview" value="1">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn-warning btn-finish-interview" data-total-hr="<?= (int)$c['total_hr'] ?>">
                                                Selesai
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($c['status'] === 'final_review' || in_array($c['status'], ['accepted', 'rejected'], true)): ?>
                                        <a href="candidate_decision.php?id=<?= (int)$c['id'] ?>" class="btn btn-success">
                                            <?= ems_icon('check-badge', 'h-4 w-4') ?>
                                            <span>Keputusan</span>
                                        </a>
                                    <?php endif; ?>
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
