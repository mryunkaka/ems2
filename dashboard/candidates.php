<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/csrf.php';

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

    // 🔒 HARD CHECK JUMLAH HR
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
        // ⛔ STOP TOTAL – TIDAK ADA UPDATE
        header('Location: candidates.php?error=min_hr');
        exit;
    }

    // ✅ BOLEH LANJUT
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
    $decision    = $_POST['ai_decision'] ?? '';

    if ($applicantId <= 0 || !in_array($decision, ['proceed', 'reject'], true)) {
        exit('Invalid request');
    }

    if ($decision === 'proceed') {
        // lanjut ke interview
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
            // 1️⃣ Update status kandidat
            $stmt = $pdo->prepare("
            UPDATE medical_applicants
            SET status = 'rejected',
                rejection_stage = 'ai'
            WHERE id = ?
              AND status = 'ai_completed'
        ");
            $stmt->execute([$applicantId]);

            // 2️⃣ Ambil data AI
            $stmt = $pdo->prepare("
            SELECT score_total
            FROM ai_test_results
            WHERE applicant_id = ?
        ");
            $stmt->execute([$applicantId]);
            $ai = $stmt->fetch(PDO::FETCH_ASSOC);

            $aiScore = (float)($ai['score_total'] ?? 0);

            // 3️⃣ Simpan keputusan akhir OTOMATIS
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

// Ambil kandidat + hasil AI (jika ada)
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
    <div class="page" style="max-width:1100px;margin:auto;">

        <h1 class="gradient-text">Daftar Calon Kandidat</h1>
        <p class="text-muted">Monitoring hasil rekrutmen dan penilaian AI</p>

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
                            $aiScore        = (float)($c['ai_score'] ?? 0);
                            $confidence     = (float)($c['confidence'] ?? 0);

                            $combinedScore = '-';

                            if ((int)($c['interview_locked'] ?? 0) === 1) {
                                $combinedScore = round(
                                    ($interviewScore * 0.6) +
                                        ($aiScore * 0.3) +
                                        ($confidence * 0.1),
                                    2
                                );
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
                                    <div style="font-size:12px;color:#64748b;">
                                        Daftar: <?= date('d M Y', strtotime($c['created_at'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    switch ($c['status']) {
                                        case 'ai_completed':
                                            echo '<span class="status-box pending">
                                                    <span class="icon">⏳</span>
                                                    PENDING
                                                </span>';

                                            break;

                                        case 'interview':
                                            echo '<span class="status-box pending">
                                                    <span class="icon">🎤</span>
                                                    INTERVIEW
                                                </span>';
                                            break;

                                        case 'final_review':
                                            echo '<span class="status-box pending">
                                                    <span class="icon">🧠</span>
                                                    FINAL REVIEW
                                                </span>';
                                            break;

                                        case 'accepted':
                                            echo '<span class="status-box verified">
                                                    <span class="icon">✅</span>
                                                    DITERIMA
                                                </span>';
                                            break;

                                        case 'rejected':
                                            echo '<span class="status-box verified" style="background:rgba(239,68,68,.14);color:#991b1b;border-color:rgba(239,68,68,.4)">
                                                    <span class="icon">❌</span>
                                                    DITOLAK
                                                </span>';
                                            break;

                                        default:
                                            echo '<span class="status-box">
                                                ' . htmlspecialchars(strtoupper($c['status'])) . '
                                            </span>';
                                    }
                                    ?>
                                </td>

                                <td><?= $aiScore ?: '-' ?></td>

                                <td><?= $interviewScore ?: '-' ?></td>

                                <td>
                                    <?= $confidence ? $confidence . '%' : '-' ?>
                                </td>

                                <td>
                                    <strong><?= $combinedScore ?></strong>
                                </td>

                                <td style="font-size:12px;color:#334155;line-height:1.4;">
                                    <?php if ($c['interviewers']): ?>
                                        <?= htmlspecialchars($c['interviewers']) ?>
                                        <?php if ((int)$c['total_hr'] > 1): ?>
                                            <div style="font-size:11px;color:#64748b;">
                                                (<?= (int)$c['total_hr'] ?> Orang)
                                            </div>
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

                                <td style="white-space:nowrap;">

                                    <?php if ($c['status'] === 'ai_completed'): ?>

                                        <!-- AI SELESAI: PILIHAN LANJUT ATAU TOLAK -->
                                        <form method="post" style="display:inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="ai_decision" value="proceed">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit"
                                                class="btn btn-primary"
                                                style="margin-right:4px;"
                                                onclick="return confirm('Lanjutkan ke tahap wawancara?')">
                                                ➡️ Lanjut Wawancara
                                            </button>
                                        </form>

                                        <form method="post" style="display:inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="ai_decision" value="reject">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit"
                                                class="btn btn-danger"
                                                onclick="return confirm('Tolak kandidat tanpa proses wawancara?')">
                                                ❌ Tidak Diterima
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                    <?php if (in_array($c['status'], ['interview'], true)): ?>

                                        <!-- INTERVIEW MULTI-HR -->
                                        <a href="candidate_interview_multi.php?id=<?= (int)$c['id'] ?>"
                                            class="btn btn-primary"
                                            style="margin-right:4px;">
                                            Interview
                                        </a>

                                    <?php endif; ?>

                                    <?php if ($c['status'] === 'interview'): ?>

                                        <!-- SELESAI INTERVIEW -->
                                        <form method="post" style="display:inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="finish_interview" value="1">
                                            <input type="hidden" name="applicant_id" value="<?= (int)$c['id'] ?>">
                                            <button type="submit"
                                                class="btn-warning btn-finish-interview"
                                                style="margin-right:4px;"
                                                data-total-hr="<?= (int)$c['total_hr'] ?>">
                                                Selesai
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                    <?php if ($c['status'] === 'final_review' || in_array($c['status'], ['accepted', 'rejected'], true)): ?>

                                        <!-- DECISION (MANAGER) -->
                                        <a href="candidate_decision.php?id=<?= (int)$c['id'] ?>"
                                            class="btn btn-success"
                                            style="margin-right:4px;">
                                            Decision
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

            if (!button) return; // bukan form "Selesai"

            const totalHr = parseInt(button.dataset.totalHr || '0', 10);

            if (totalHr < 2) {
                e.preventDefault(); // ⛔ STOP TOTAL

                alert(
                    '⛔ Interview belum dapat diselesaikan.\n\n' +
                    'Penilaian baru diberikan oleh ' + totalHr + ' HR.\n' +
                    'Minimal diperlukan 2 HR.\n\n' +
                    'Silakan tunggu HR lain memberikan penilaian.'
                );

                return false;
            }

            // HR sudah cukup → minta konfirmasi
            if (!confirm('Tandai interview selesai?')) {
                e.preventDefault();
                return false;
            }

        }, true); // ⬅️ CAPTURE MODE (PENTING!)
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#candidateTable').DataTable({
                pageLength: 10,
                scrollX: true, // ⬅️ INI PENTING
                autoWidth: false, // ⬅️ BIAR CSS YANG NGATUR
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                }
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>