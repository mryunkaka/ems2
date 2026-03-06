<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../actions/interview_finalize.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../actions/status_validator.php';
require_once __DIR__ . '/../config/error_logger.php';

/* ===============================
   ROLE GUARD
   =============================== */
$user = $_SESSION['user_rh'] ?? [];
$role = strtolower($user['role'] ?? '');

if ($role === 'staff') {
    header('Location: dashboard.php');
    exit;
}

/* ===============================
   VALIDASI ID
   =============================== */
$applicantId = (int)($_GET['id'] ?? 0);
if ($applicantId <= 0) {
    header('Location: candidates.php');
    exit;
}

/* ===============================
   DATA KANDIDAT
   =============================== */
$stmt = $pdo->prepare("SELECT * FROM medical_applicants WHERE id = ?");
$stmt->execute([$applicantId]);
$candidate = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$candidate) {
    exit('Kandidat tidak ditemukan');
}

/* ===============================
   HASIL AI (REKOMENDASI)
   =============================== */
$stmt = $pdo->prepare("SELECT * FROM ai_test_results WHERE applicant_id = ?");
$stmt->execute([$applicantId]);
$ai = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ai) {
    exit('AI Test belum tersedia');
}

$aiRecommendation = $ai['decision']; // recommended | consider | not_recommended

/* ===============================
   HASIL INTERVIEW MULTI-HR (HYBRID)
   =============================== */
$stmt = $pdo->prepare("
    SELECT
        average_score,
        final_grade,
        ml_flags,
        ml_confidence,
        calculated_at,
        is_locked
    FROM applicant_interview_results
    WHERE applicant_id = ?
");
$stmt->execute([$applicantId]);
$interviewResult = $stmt->fetch(PDO::FETCH_ASSOC);

/* ===============================
   KEPUTUSAN SISTEM (AUTO FINAL)
   =============================== */
$systemResult = 'tidak_lolos';

if ($interviewResult && (int)$interviewResult['is_locked'] === 1) {

    $interviewScore = (float)$interviewResult['average_score'];   // 0–100
    $aiScore        = (float)$ai['score_total'];                  // 0–100
    $confidence     = (float)$interviewResult['ml_confidence'];   // 0–100

    // COMBINED SCORE (FINAL)
    $combinedScore = round(
        ($interviewScore * 0.6) +
            ($aiScore * 0.3) +
            ($confidence * 0.1),
        2
    );

    if (
        $combinedScore >= 70 &&
        $aiRecommendation !== 'not_recommended'
    ) {
        $systemResult = 'lolos';
    }
}

$mlFlags = json_decode($interviewResult['ml_flags'] ?? '[]', true);

/* ===============================
   HANDLE LOCK INTERVIEW (MANAGER)
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock_interview'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }
    if (!$interviewResult || (int)$interviewResult['is_locked'] !== 1) {
        finalizeInterview($pdo, $applicantId);
    }

    header('Location: candidate_decision.php?id=' . $applicantId);
    exit;
}

/* ===============================
   CEK SUDAH DIPUTUSKAN
   =============================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM applicant_final_decisions
    WHERE applicant_id = ?
");
$stmt->execute([$applicantId]);
$existingDecision = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existingDecision && $candidate['rejection_stage'] === 'ai') {
    // ini auto decision → tidak perlu form
}

/* ===============================
   SUBMIT KEPUTUSAN FINAL
   =============================== */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['submit_decision'])
    && !$existingDecision
) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    if (!$interviewResult || (int)$interviewResult['is_locked'] !== 1) {
        exit('Interview harus dikunci sebelum keputusan akhir.');
    }

    $systemResultPost = $_POST['system_result'] ?? '';
    $override         = isset($_POST['override']) ? 1 : 0;
    $reason           = trim($_POST['override_reason'] ?? '');

    if (!in_array($systemResultPost, ['lolos', 'tidak_lolos'], true)) {
        exit('System result tidak valid');
    }

    if ($override) {
        if ($reason === '') {
            exit('Alasan override wajib diisi');
        }
        $finalResult = ($systemResultPost === 'lolos') ? 'tidak_lolos' : 'lolos';
    } else {
        $finalResult = $systemResultPost;
        $reason = null;
    }

    $pdo->beginTransaction();

    try {
        // Lock check dengan FOR UPDATE (DALAM transaction)
        $stmt = $pdo->prepare("
            SELECT id FROM applicant_final_decisions 
            WHERE applicant_id = ? 
            FOR UPDATE
        ");
        $stmt->execute([$applicantId]);
        if ($stmt->fetch()) {
            throw new Exception('Keputusan sudah dibuat oleh user lain. Refresh halaman.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO applicant_final_decisions
            (
                applicant_id,
                system_result,
                overridden,
                override_reason,
                final_result,
                decided_by
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $applicantId,
            $systemResultPost,
            $override,
            $reason,
            $finalResult,
            $user['name'] ?? 'Manager'
        ]);

        $newStatus = $finalResult === 'lolos' ? 'accepted' : 'rejected';
        updateApplicantStatus($pdo, $applicantId, $newStatus);

        $pdo->commit();

        header('Location: candidate_detail.php?id=' . $applicantId);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        logRecruitmentError('FINAL_DECISION', $e);

        exit('Gagal menyimpan keputusan akhir: ' . $e->getMessage());
    }
}
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page" style="max-width:1000px;margin:auto;">

        <h1 class="gradient-text">Keputusan Akhir Kandidat</h1>

        <?php
        // ===============================
        // HITUNG SKOR GABUNGAN (UNTUK DISPLAY)
        // ===============================
        $interviewScore = (float)($interviewResult['average_score'] ?? 0);
        $aiScore        = (float)($ai['score_total'] ?? 0);
        $confidence     = (float)($interviewResult['ml_confidence'] ?? 0);

        $combinedScore = round(
            ($interviewScore * 0.6) +
                ($aiScore * 0.3) +
                ($confidence * 0.1),
            2
        );
        ?>

        <div class="card">
            <strong><?= htmlspecialchars($candidate['ic_name']) ?></strong>

            <div style="font-size:13px;color:#64748b;line-height:1.7;margin-top:6px;">

                <strong>Test (Psychotest)</strong><br>
                Skor: <strong><?= $aiScore ?></strong>
                <span style="color:#94a3b8;">(Bobot 30%)</span><br>
                Rekomendasi: <strong><?= strtoupper($aiRecommendation) ?></strong>

                <br><br>

                <strong>Interview HR & Recruitment</strong><br>
                Nilai Akhir: <strong><?= $interviewScore ?></strong>
                <span style="color:#94a3b8;">(Bobot 60%)</span><br>
                Grade: <?= strtoupper(str_replace('_', ' ', $interviewResult['final_grade'] ?? '-')) ?><br>
                Confidence:
                <strong><?= $confidence ?>%</strong>

                <span class="ui-tooltip">?
                    <span class="ui-tooltip-text">
                        Confidence menunjukkan seberapa konsisten
                        penilaian antar HR terhadap kandidat ini.
                        <br><br>
                        Nilai tinggi berarti HR sepakat,
                        bukan berarti kandidat lebih percaya diri.
                    </span>
                </span>

                <span style="color:#94a3b8;">(Bobot 10%)</span>

                <hr style="margin:12px 0;border:none;border-top:1px dashed #e5e7eb;">

                <strong>Skor Gabungan Sistem</strong><br>
                <span style="font-size:18px;font-weight:800;color:#0f172a;">
                    <?= $combinedScore ?>
                </span>
                <span style="font-size:12px;color:#94a3b8;">/ 100</span>

                <div style="margin-top:4px;font-size:12px;color:#64748b;">
                    (Interview 60% + Test 30% + Confidence 10%)
                </div>

            </div>
        </div>

        <?php if (!empty($mlFlags)): ?>
            <div class="card">
                <h3>Catatan Sistem (ML Insight)</h3>
                <ul>
                    <?php foreach ($mlFlags as $key => $val): ?>
                        <li><?= ucfirst(str_replace('_', ' ', $key)) ?> :
                            <strong><?= htmlspecialchars($val) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($existingDecision): ?>

            <div class="card">
                <h3>Keputusan Telah Ditentukan</h3>

                <p>
                    <strong>Hasil Akhir:</strong>
                    <span class="badge badge-<?= $existingDecision['final_result'] === 'lolos' ? 'success' : 'danger' ?>">
                        <?= strtoupper($existingDecision['final_result']) ?>
                    </span>
                </p>

                <?php if ((int)$existingDecision['overridden'] === 1): ?>
                    <div style="margin-top:10px;padding:10px;background:#fff7ed;border-left:4px solid #f97316;border-radius:6px;">
                        <strong>Override Keputusan Sistem</strong><br>
                        <?= nl2br(htmlspecialchars($existingDecision['override_reason'])) ?>
                    </div>
                <?php endif; ?>

                <small class="text-muted">
                    Diputuskan oleh <?= htmlspecialchars($existingDecision['decided_by']) ?>
                    pada <?= date('d M Y H:i', strtotime($existingDecision['decided_at'])) ?>
                </small>
            </div>

        <?php else: ?>

            <?php if (!$interviewResult || (int)$interviewResult['is_locked'] !== 1): ?>
                <form method="post" class="card">
                    <?php echo csrfField(); ?>
                    <button name="lock_interview"
                        class="btn btn-warning"
                        onclick="return confirm('Kunci interview? Nilai HR tidak dapat diubah.')">
                        🔒 Kunci Interview
                    </button>
                </form>
            <?php else: ?>

                <form method="post" class="card">
                    <?php echo csrfField(); ?>
                    <h3>Form Keputusan Akhir</h3>

                    <label>Keputusan Sistem (Otomatis)</label>
                    <div style="margin-bottom:12px;">
                        <span class="badge badge-<?= $systemResult === 'lolos' ? 'success' : 'danger' ?>">
                            <?= strtoupper($systemResult) ?>
                        </span>
                    </div>

                    <input type="hidden" name="system_result" value="<?= $systemResult ?>">

                    <label>
                        <input type="checkbox" name="override" id="overrideToggle">
                        Override Keputusan Sistem
                    </label>

                    <div id="overrideBox" style="display:none;margin-top:8px;">
                        <label>Alasan Override <span style="color:red">*</span></label>
                        <textarea name="override_reason" rows="3"></textarea>
                    </div>

                    <button type="submit" name="submit_decision" class="btn btn-primary" style="margin-top:14px;">
                        Simpan Keputusan Final
                    </button>
                </form>

                <script>
                    document.getElementById('overrideToggle')?.addEventListener('change', function() {
                        document.getElementById('overrideBox').style.display =
                            this.checked ? 'block' : 'none';
                    });
                </script>

            <?php endif; ?>

        <?php endif; ?>

    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>