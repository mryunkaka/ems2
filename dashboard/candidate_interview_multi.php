<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../actions/interview_finalize.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../actions/status_validator.php';      // ← TAMBAH
require_once __DIR__ . '/../config/error_logger.php';           // ← TAMBAH

/* ===============================
   AUTH & ROLE
   =============================== */
$user = $_SESSION['user_rh'] ?? [];
$hrId = (int)($user['id'] ?? 0);

if ($hrId <= 0) {
    exit('Unauthorized');
}

/* ===============================
   VALIDASI ID KANDIDAT
   =============================== */
$applicantId = (int)($_GET['id'] ?? $_POST['applicant_id'] ?? 0);
if ($applicantId <= 0) {
    header('Location: candidates.php');
    exit;
}

/* ===============================
   AMBIL DATA KANDIDAT
   =============================== */
$stmt = $pdo->prepare("SELECT ic_name, status FROM medical_applicants WHERE id = ?");
$stmt->execute([$applicantId]);
$candidate = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$candidate) {
    exit('Kandidat tidak ditemukan');
}

if (!in_array($candidate['status'], ['ai_completed', 'interview'], true)) {
    exit('Status kandidat belum valid untuk interview');
}

/* ===============================
   CEK APAKAH INTERVIEW SUDAH DIKUNCI
   =============================== */
$stmt = $pdo->prepare("
    SELECT is_locked
    FROM applicant_interview_results
    WHERE applicant_id = ?
");
$stmt->execute([$applicantId]);
$isLocked = (int)$stmt->fetchColumn();

if ($isLocked === 1) {
    header('Location: candidates.php?error=interview_locked');
    exit;
}

/* ===============================
   AMBIL KRITERIA INTERVIEW
   =============================== */
$criteria = $pdo->query("
    SELECT id, label, description
    FROM interview_criteria
    WHERE is_active = 1
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   AMBIL NILAI HR INI (JIKA ADA)
   =============================== */
$stmt = $pdo->prepare("
    SELECT criteria_id, score
    FROM applicant_interview_scores
    WHERE applicant_id = ? AND hr_id = ?
");
$stmt->execute([$applicantId, $hrId]);
$existingScores = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

/* ===============================
   SUBMIT NILAI
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    $pdo->beginTransaction();

    try {
        $notes = trim($_POST['notes'] ?? '');

        // VALIDASI SEMUA SCORE DULU
        foreach ($criteria as $c) {
            if (!isset($_POST['score'][$c['id']])) {
                throw new Exception('Skor belum lengkap');
            }

            $score = (int)$_POST['score'][$c['id']];
            if ($score < 1 || $score > 5) {
                throw new Exception('Nilai tidak valid');
            }
        }

        // INSERT/UPDATE SCORES
        foreach ($criteria as $c) {
            $score = (int)$_POST['score'][$c['id']];

            $stmt = $pdo->prepare("
                INSERT INTO applicant_interview_scores
                (applicant_id, hr_id, criteria_id, score, notes)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    score = VALUES(score),
                    notes = VALUES(notes),
                    created_at = CURRENT_TIMESTAMP
            ");

            $stmt->execute([
                $applicantId,
                $hrId,
                $c['id'],
                $score,
                $notes ?: null
            ]);
        }

        // UPDATE STATUS HANYA SEKALI
        // Gunakan raw query dengan WHERE condition untuk avoid error
        $stmt = $pdo->prepare("
            UPDATE medical_applicants
            SET status = 'interview'
            WHERE id = ? AND status IN ('ai_completed', 'interview')
        ");
        $stmt->execute([$applicantId]);

        $pdo->commit();

        header('Location: candidates.php?interview_saved=1');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        logRecruitmentError('INTERVIEW_SUBMIT', $e);

        exit('Gagal menyimpan penilaian: ' . $e->getMessage());
    }
}
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page" style="max-width:900px;margin:auto;">

        <h1 class="gradient-text">Interview Kandidat</h1>

        <div class="card">
            <strong><?= htmlspecialchars($candidate['ic_name']) ?></strong><br>
            <small class="text-muted">
                HR: <?= htmlspecialchars($user['name'] ?? '-') ?>
            </small>
        </div>

        <form method="post" class="card">
            <?php
            echo csrfField();
            ?>
            <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">

            <?php foreach ($criteria as $c): ?>
                <div style="margin-bottom:14px;">
                    <label>
                        <strong><?= htmlspecialchars($c['label']) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($c['description']) ?></small>
                    </label>

                    <select
                        name="score[<?= $c['id'] ?>]"
                        required
                        style="margin-top:6px;">
                        <option value="">-- Pilih Nilai --</option>
                        <?php
                        $options = [
                            1 => 'Sangat Buruk',
                            2 => 'Buruk',
                            3 => 'Sedang',
                            4 => 'Baik',
                            5 => 'Sangat Baik'
                        ];
                        foreach ($options as $v => $label):
                        ?>
                            <option value="<?= $v ?>"
                                <?= (($existingScores[$c['id']] ?? '') == $v) ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>

            <label>Catatan Interview (Opsional)</label>
            <textarea
                name="notes"
                rows="4"
                placeholder="Catatan pribadi HR (tidak dilihat HR lain)"></textarea>

            <button type="submit" class="btn btn-primary" style="margin-top:16px;">
                Simpan Penilaian Interview
            </button>
        </form>

    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>