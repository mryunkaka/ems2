<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../actions/interview_finalize.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../actions/status_validator.php';
require_once __DIR__ . '/../config/error_logger.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

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

function candidateDecisionLabel(?string $value): string
{
    $value = (string)($value ?? '');

    return match (strtolower($value)) {
        'recommended' => 'Direkomendasikan',
        'not_recommended' => 'Tidak Direkomendasikan',
        'follow_up_required' => 'Perlu Tindak Lanjut',
        'sangat_baik' => 'Sangat Baik',
        'baik' => 'Baik',
        'sedang' => 'Sedang',
        'buruk' => 'Buruk',
        'sangat_buruk' => 'Sangat Buruk',
        'lolos' => 'Lolos',
        'tidak_lolos' => 'Tidak Lolos',
        '' => '-',
        default => ucwords(str_replace('_', ' ', $value)),
    };
}

function candidateDecisionBadgeClass(?string $value): string
{
    return match (strtolower((string)($value ?? ''))) {
        'recommended', 'lolos' => 'badge-success',
        'not_recommended', 'tidak_lolos' => 'badge-danger',
        'follow_up_required' => 'badge-warning',
        default => 'badge-secondary',
    };
}

function candidateGenerateUserFolder(int $userId, ?string $kodeNomorIndukRs = null): string
{
    $suffix = $kodeNomorIndukRs ? '-' . strtolower($kodeNomorIndukRs) : '';
    return 'user_' . $userId . $suffix;
}

function candidateCopyApplicantDocsToUser(PDO $pdo, int $applicantId, int $userId, ?string $kodeNomorIndukRs = null): array
{
    $stmt = $pdo->prepare("
        SELECT document_type, file_path
        FROM applicant_documents
        WHERE applicant_id = ?
    ");
    $stmt->execute([$applicantId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$documents) {
        return [];
    }

    $folderName = candidateGenerateUserFolder($userId, $kodeNomorIndukRs);
    $baseDir = __DIR__ . '/../storage/user_docs/';
    $uploadDir = $baseDir . $folderName;

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception('Gagal membuat folder dokumen user.');
    }

    $columnMap = [
        'ktp_ic' => 'file_ktp',
        'skb' => 'file_skb',
        'sim' => 'file_sim',
    ];

    $copied = [];
    foreach ($documents as $document) {
        $type = (string)($document['document_type'] ?? '');
        $relativePath = trim((string)($document['file_path'] ?? ''));
        if ($relativePath === '' || !isset($columnMap[$type])) {
            continue;
        }

        $sourcePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/'));
        if ($sourcePath === false || !is_file($sourcePath)) {
            continue;
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'jpg';
        $destinationName = $columnMap[$type] . '.' . $extension;
        $destinationPath = $uploadDir . '/' . $destinationName;

        if (!copy($sourcePath, $destinationPath)) {
            throw new Exception('Gagal menyalin dokumen pelamar ke user.');
        }

        $copied[$columnMap[$type]] = 'storage/user_docs/' . $folderName . '/' . $destinationName;
    }

    return $copied;
}

function candidateCreateUserFromApplicant(PDO $pdo, array $candidate, string $recommendedPosition, int $batch): int
{
    $fullName = trim((string)($candidate['ic_name'] ?? ''));
    $citizenId = trim((string)($candidate['citizen_id'] ?? ''));
    $jenisKelamin = trim((string)($candidate['jenis_kelamin'] ?? ''));
    if ($fullName === '') {
        throw new Exception('Nama kandidat tidak valid untuk pembuatan user.');
    }

    $check = $pdo->prepare("SELECT id FROM user_rh WHERE full_name = ? LIMIT 1");
    $check->execute([$fullName]);
    if ($check->fetchColumn()) {
        throw new Exception('Akun user_rh dengan nama tersebut sudah ada.');
    }

    if ($citizenId !== '') {
        $checkCitizen = $pdo->prepare("SELECT id FROM user_rh WHERE citizen_id = ? LIMIT 1");
        $checkCitizen->execute([$citizenId]);
        if ($checkCitizen->fetchColumn()) {
            throw new Exception('Akun user_rh dengan citizen ID tersebut sudah ada.');
        }
    }

    if (!in_array($jenisKelamin, ['Laki-laki', 'Perempuan'], true)) {
        $jenisKelamin = null;
    }

    if ($batch < 1 || $batch > 26) {
        throw new Exception('Batch wajib diisi dan harus di antara 1 sampai 26.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_rh (
            full_name,
            citizen_id,
            jenis_kelamin,
            no_hp_ic,
            pin,
            role,
            division,
            position,
            batch,
            tanggal_masuk,
            is_verified,
            is_active
        ) VALUES (?, ?, ?, ?, ?, 'Staff', 'Medis', ?, ?, CURDATE(), 1, 1)
    ");
    $stmt->execute([
        $fullName,
        $citizenId !== '' ? $citizenId : null,
        $jenisKelamin,
        trim((string)($candidate['ic_phone'] ?? '')) ?: null,
        password_hash('0000', PASSWORD_BCRYPT),
        $recommendedPosition,
        $batch,
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    $generatedKode = candidateGenerateMedicalCode($newUserId, $fullName, $batch);
    $pdo->prepare("
        UPDATE user_rh
        SET kode_nomor_induk_rs = ?
        WHERE id = ?
    ")->execute([$generatedKode, $newUserId]);

    return $newUserId;
}

function candidateGenerateMedicalCode(int $userId, string $fullName, int $batch): string
{
    if ($batch < 1 || $batch > 26) {
        throw new Exception('Batch tidak valid untuk generate kode medis.');
    }

    $batchCode = chr(64 + $batch);
    $idPart = str_pad((string)$userId, 2, '0', STR_PAD_LEFT);
    $parts = preg_split('/\s+/', strtoupper(trim($fullName)));
    $firstName = $parts[0] ?? '';
    $lastName = $parts[count($parts) - 1] ?? '';
    $letters = substr($firstName, 0, 2) . substr($lastName, 0, 2);

    $numberPart = '';
    foreach (str_split($letters) as $char) {
        if ($char >= 'A' && $char <= 'Z') {
            $numberPart .= str_pad((string)(ord($char) - 64), 2, '0', STR_PAD_LEFT);
        }
    }

    return 'RH' . $batchCode . '-' . $idPart . $numberPart;
}

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

$interviewPreview = null;
try {
    $interviewPreview = calculateHybridInterviewScore($pdo, $applicantId);
} catch (Throwable $e) {
    $interviewPreview = null;
}

if ((!$interviewResult || (int)($interviewResult['is_locked'] ?? 0) !== 1) && $interviewPreview) {
    $interviewResult = [
        'average_score' => $interviewPreview['final_score'],
        'final_grade' => $interviewPreview['final_grade'],
        'ml_flags' => json_encode($interviewPreview['ml_flags'], JSON_UNESCAPED_UNICODE),
        'ml_confidence' => $interviewPreview['ml_confidence'],
        'calculated_at' => null,
        'is_locked' => 0,
    ];
}

$stmt = $pdo->prepare("
    SELECT
        s.hr_id,
        u.full_name,
        COUNT(*) AS total_scores,
        MAX(NULLIF(TRIM(COALESCE(s.notes, '')), '')) AS interview_note
    FROM applicant_interview_scores s
    JOIN user_rh u ON u.id = s.hr_id
    WHERE s.applicant_id = ?
    GROUP BY s.hr_id, u.full_name
    ORDER BY u.full_name ASC
");
$stmt->execute([$applicantId]);
$interviewers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   KEPUTUSAN SISTEM (AUTO FINAL)
   =============================== */
$systemResult = 'tidak_lolos';

if ($interviewResult && (int)$interviewResult['is_locked'] === 1) {

    $interviewScore = (float)$interviewResult['average_score'];   // 0-100
    $aiScore        = (float)$ai['score_total'];                  // 0-100
    $confidence     = (float)$interviewResult['ml_confidence'];   // 0-100

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
    // ini auto decision -> tidak perlu form
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
    $recommendedPosition = ems_normalize_position($_POST['recommended_position'] ?? '');
    $recommendedBatch = (int)($_POST['recommended_batch'] ?? 0);

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

    if ($finalResult === 'lolos' && !ems_is_valid_position($recommendedPosition)) {
        exit('Posisi yang direkomendasikan wajib dipilih untuk kandidat yang diloloskan.');
    }

    if ($finalResult === 'lolos' && ($recommendedBatch < 1 || $recommendedBatch > 26)) {
        exit('Batch wajib diisi untuk kandidat yang diloloskan. Gunakan angka 1 sampai 26.');
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

        if ($finalResult === 'lolos') {
            $newUserId = candidateCreateUserFromApplicant($pdo, $candidate, $recommendedPosition, $recommendedBatch);
            $copiedDocuments = candidateCopyApplicantDocsToUser($pdo, $applicantId, $newUserId);

            if ($copiedDocuments) {
                $updateFields = [];
                $updateParams = [];
                foreach ($copiedDocuments as $column => $path) {
                    $updateFields[] = "{$column} = ?";
                    $updateParams[] = $path;
                }
                $updateParams[] = $newUserId;

                $pdo->prepare("
                    UPDATE user_rh
                    SET " . implode(', ', $updateFields) . "
                    WHERE id = ?
                ")->execute($updateParams);
            }
        }

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
    <div class="page page-shell-md">
        <div class="card">
            <div class="card-header-between">
                <div>
                    <h1 class="page-title">Keputusan Akhir Kandidat</h1>
                    <p class="page-subtitle">Ringkasan hasil AI, preview interview, konsistensi antar HR, dan panel finalisasi keputusan.</p>
                </div>
                <div class="badge-info"><?= htmlspecialchars($candidate['ic_name']) ?></div>
            </div>
        </div>

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
        <div class="decision-layout">
            <div class="decision-main-stack">
                <div class="decision-summary-grid">
                    <div class="decision-score-card">
                        <div class="decision-score-title">Test Psychotest</div>
                        <div class="decision-score-meta">Bobot 30%</div>
                        <div class="decision-score-value"><?= $aiScore ?></div>
                        <div class="decision-score-support">
                            Rekomendasi:
                            <span class="<?= htmlspecialchars(candidateDecisionBadgeClass($aiRecommendation)) ?>">
                                <?= htmlspecialchars(candidateDecisionLabel($aiRecommendation)) ?>
                            </span>
                        </div>
                    </div>

                    <div class="decision-score-card">
                        <div class="decision-score-title">Interview HR & Recruitment</div>
                        <div class="decision-score-meta">Bobot 60% + konsistensi 10%</div>
                        <div class="decision-score-value"><?= $interviewScore ?></div>
                        <div class="decision-score-support">
                            Grade: <strong><?= htmlspecialchars(candidateDecisionLabel($interviewResult['final_grade'] ?? '-')) ?></strong><br>
                            Konsistensi: <strong><?= $confidence ?>%</strong>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('chart-bar', 'h-5 w-5') ?>
                        <span>Skor Gabungan Sistem</span>
                    </div>
                    <div class="decision-score-value"><?= $combinedScore ?><span class="text-base font-semibold text-slate-400"> / 100</span></div>
                    <div class="decision-score-support">(Interview 60% + Test 30% + Konsistensi 10%)</div>

                    <?php if ((int)($interviewResult['is_locked'] ?? 0) !== 1 && $interviewPreview): ?>
                        <div class="decision-note decision-note-warning mt-4">
                            Nilai interview di atas adalah preview dari skor HR yang sudah masuk. Nilai final tetap mengikuti hasil saat interview dikunci.
                        </div>
                    <?php endif; ?>

                    <div class="decision-note decision-note-info mt-4">
                        Confidence menunjukkan seberapa konsisten penilaian antar HR terhadap kandidat ini. Nilai tinggi berarti HR lebih sepakat, bukan berarti kandidat lebih percaya diri.
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('user-group', 'h-5 w-5') ?>
                        <span>Interviewer yang Sudah Menilai</span>
                    </div>

                    <?php if (!empty($interviewers)): ?>
                        <div class="decision-interviewer-list">
                            <?php foreach ($interviewers as $interviewer): ?>
                                <div class="decision-interviewer-card">
                                    <div class="decision-interviewer-name"><?= htmlspecialchars($interviewer['full_name']) ?></div>
                                    <div class="decision-interviewer-meta">Mengisi <?= (int)$interviewer['total_scores'] ?> penilaian</div>
                                    <div class="decision-interviewer-note">
                                        <?php if (!empty($interviewer['interview_note'])): ?>
                                            Catatan: <?= nl2br(htmlspecialchars($interviewer['interview_note'])) ?>
                                        <?php else: ?>
                                            Catatan: Belum menulis catatan.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="helper-note">Belum ada HR yang mengisi penilaian.</div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($mlFlags)): ?>
                    <div class="card">
                        <div class="card-header">
                            <?= ems_icon('clipboard-document-list', 'h-5 w-5') ?>
                            <span>Catatan Sistem</span>
                        </div>
                        <div class="decision-ml-list">
                            <?php foreach ($mlFlags as $key => $val): ?>
                                <div>
                                    <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) ?>:
                                    <strong><?= htmlspecialchars($val) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="decision-side-stack">
                <?php if ($existingDecision): ?>
                    <div class="card mb-0">
                        <div class="card-header">
                            <?= ems_icon('check-badge', 'h-5 w-5') ?>
                            <span>Keputusan Telah Ditentukan</span>
                        </div>

                        <div class="decision-form-box">
                            <div class="decision-score-meta">Hasil Akhir</div>
                            <div class="mt-2">
                                <span class="<?= htmlspecialchars(candidateDecisionBadgeClass($existingDecision['final_result'])) ?>">
                                    <?= htmlspecialchars(candidateDecisionLabel($existingDecision['final_result'])) ?>
                                </span>
                            </div>
                        </div>

                        <?php if ((int)$existingDecision['overridden'] === 1): ?>
                            <div class="decision-note decision-note-warning">
                                <strong>Override Keputusan Sistem</strong><br>
                                <?= nl2br(htmlspecialchars($existingDecision['override_reason'])) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (($existingDecision['final_result'] ?? '') === 'lolos'): ?>
                            <div class="decision-note decision-note-info">
                                <strong>Data user yang akan dipakai saat pelolosan</strong><br>
                                Citizen ID: <strong><?= htmlspecialchars($candidate['citizen_id'] ?: '-') ?></strong><br>
                                Jenis Kelamin: <strong><?= htmlspecialchars($candidate['jenis_kelamin'] ?: '-') ?></strong>
                            </div>
                        <?php endif; ?>

                        <div class="helper-note">
                            Diputuskan oleh <?= htmlspecialchars($existingDecision['decided_by']) ?>
                            pada <?= date('d M Y H:i', strtotime($existingDecision['decided_at'])) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if (!$interviewResult || (int)$interviewResult['is_locked'] !== 1): ?>
                        <form method="post" class="card mb-0">
                            <?php echo csrfField(); ?>
                            <div class="card-header">
                                <?= ems_icon('lock-closed', 'h-5 w-5') ?>
                                <span>Kunci Interview</span>
                            </div>
                            <div class="helper-note mb-4">
                                Kunci interview setelah seluruh HR yang dibutuhkan selesai memberi nilai. Setelah dikunci, sistem menyimpan hasil hybrid final.
                            </div>
                            <button
                                name="lock_interview"
                                class="btn-warning"
                                onclick="return confirm('Kunci interview? Nilai HR tidak dapat diubah.')">
                                <?= ems_icon('lock-closed', 'h-4 w-4') ?>
                                <span>Kunci Interview</span>
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="card mb-0">
                            <?php echo csrfField(); ?>
                            <div class="card-header">
                                <?= ems_icon('check-badge', 'h-5 w-5') ?>
                                <span>Form Keputusan Akhir</span>
                            </div>

                            <div class="decision-form-box">
                                <div class="decision-score-meta">Keputusan Sistem Otomatis</div>
                                <div class="mt-2">
                                    <span class="<?= htmlspecialchars(candidateDecisionBadgeClass($systemResult)) ?>">
                                        <?= htmlspecialchars(candidateDecisionLabel($systemResult)) ?>
                                    </span>
                                </div>
                                <div class="mt-3 text-sm text-slate-600">
                                    Citizen ID: <strong><?= htmlspecialchars($candidate['citizen_id'] ?: '-') ?></strong><br>
                                    Jenis Kelamin: <strong><?= htmlspecialchars($candidate['jenis_kelamin'] ?: '-') ?></strong>
                                </div>
                            </div>

                            <input type="hidden" name="system_result" value="<?= $systemResult ?>">

                            <div class="form-group mt-4">
                                <label for="recommended_position" class="text-sm font-semibold text-slate-900">Posisi yang Direkomendasikan</label>
                                <select id="recommended_position" name="recommended_position">
                                    <option value="">-- Pilih Posisi --</option>
                                    <?php foreach (ems_position_options() as $positionOption): ?>
                                        <option value="<?= htmlspecialchars($positionOption['value']) ?>">
                                            <?= htmlspecialchars($positionOption['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="recommendedPositionHelp" class="helper-note">
                                    Posisi ini akan dipakai saat sistem membuat akun `user_rh` otomatis jika hasil final kandidat adalah lolos.
                                </div>
                            </div>

                            <div class="form-group mt-4">
                                <label for="recommended_batch" class="text-sm font-semibold text-slate-900">Batch Pelamar</label>
                                <input type="number" id="recommended_batch" name="recommended_batch" min="1" max="26" placeholder="Contoh: 7">
                                <div id="recommendedBatchHelp" class="helper-note">
                                    Batch ini akan dipakai saat sistem membuat akun `user_rh` otomatis jika hasil final kandidat adalah lolos.
                                </div>
                            </div>

                            <?php
                            $overrideToggleLabel = $systemResult === 'lolos'
                                ? 'Tidak diloloskan karena alasan tertentu'
                                : 'Loloskan dengan catatan';
                            $overrideReasonLabel = $systemResult === 'lolos'
                                ? 'Alasan Tidak Diloloskan'
                                : 'Catatan Pelolosan';
                            $overrideReasonPlaceholder = $systemResult === 'lolos'
                                ? 'Jelaskan alasan mengapa kandidat tidak diloloskan meskipun sistem merekomendasikan lolos.'
                                : 'Jelaskan alasan dan catatan mengapa kandidat tetap diloloskan meskipun sistem tidak meloloskan.';
                            ?>

                            <label class="checkbox-label checkbox-pill">
                                <input type="checkbox" name="override" id="overrideToggle">
                                <span><?= htmlspecialchars($overrideToggleLabel) ?></span>
                            </label>

                            <div id="overrideBox" class="hidden mt-3">
                                <label for="override_reason" class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($overrideReasonLabel) ?> <span class="required">*</span></label>
                                <textarea id="override_reason" name="override_reason" rows="4" placeholder="<?= htmlspecialchars($overrideReasonPlaceholder) ?>"></textarea>
                            </div>

                            <div class="decision-action-stack">
                                <button type="submit" name="submit_decision" class="btn-primary">
                                    <?= ems_icon('check-badge', 'h-4 w-4') ?>
                                    <span>Simpan Keputusan Final</span>
                                </button>
                            </div>
                        </form>

                        <script>
                            document.getElementById('overrideToggle')?.addEventListener('change', function() {
                                const box = document.getElementById('overrideBox');
                                if (!box) return;
                                box.classList.toggle('hidden', !this.checked);
                            });

                            (function() {
                                const toggle = document.getElementById('overrideToggle');
                                const recommendedPosition = document.getElementById('recommended_position');
                                const recommendedBatch = document.getElementById('recommended_batch');
                                const help = document.getElementById('recommendedPositionHelp');
                                const batchHelp = document.getElementById('recommendedBatchHelp');
                                const systemResult = <?= json_encode($systemResult) ?>;

                                function refreshRecommendedFieldsRequirement() {
                                    if (!recommendedPosition) return;

                                    const willPass = toggle
                                        ? ((systemResult === 'lolos' && !toggle.checked) || (systemResult === 'tidak_lolos' && toggle.checked))
                                        : (systemResult === 'lolos');

                                    recommendedPosition.required = willPass;
                                    if (recommendedBatch) {
                                        recommendedBatch.required = willPass;
                                    }

                                    if (help) {
                                        help.textContent = willPass
                                            ? 'Wajib dipilih karena hasil final kandidat akan diloloskan dan akun user_rh akan dibuat otomatis.'
                                            : 'Tidak wajib dipilih jika hasil final kandidat tidak diloloskan.';
                                    }

                                    if (batchHelp) {
                                        batchHelp.textContent = willPass
                                            ? 'Wajib diisi karena hasil final kandidat akan diloloskan dan batch akan dipakai untuk akun user_rh baru.'
                                            : 'Tidak wajib diisi jika hasil final kandidat tidak diloloskan.';
                                    }
                                }

                                toggle?.addEventListener('change', refreshRecommendedFieldsRequirement);
                                refreshRecommendedFieldsRequirement();
                            })();
                        </script>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
