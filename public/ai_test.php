<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$applicantId = (int)($_GET['applicant_id'] ?? 0);

if ($applicantId <= 0) {
    header('Location: recruitment_form.php');
    exit;
}

$hasRecruitmentTypeColumn = ems_column_exists($pdo, 'medical_applicants', 'recruitment_type');
$stmt = $pdo->prepare("
    SELECT id, ic_name, status" . ($hasRecruitmentTypeColumn ? ", recruitment_type" : "") . "
    FROM medical_applicants
    WHERE id = ?
");
$stmt->execute([$applicantId]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$applicant) {
    header('Location: recruitment_form.php');
    exit;
}

if ($applicant['status'] !== 'ai_test') {
    header('Location: recruitment_done.php');
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM ai_test_results WHERE applicant_id = ?");
$stmt->execute([$applicantId]);
if ($stmt->fetch()) {
    header('Location: recruitment_done.php');
    exit;
}

$sessionTrackMap = $_SESSION['recruitment_track_map'] ?? [];
$requestTrack = ems_normalize_recruitment_type($_GET['track'] ?? '');
$sessionTrack = ems_normalize_recruitment_type($sessionTrackMap[(string)$applicantId] ?? '');
$dbTrack = ems_normalize_recruitment_type($applicant['recruitment_type'] ?? 'medical_candidate');
$recruitmentType = $hasRecruitmentTypeColumn
    ? $dbTrack
    : ($requestTrack !== 'medical_candidate' ? $requestTrack : ($sessionTrack !== 'medical_candidate' ? $sessionTrack : $dbTrack));
$profile = ems_recruitment_profile($recruitmentType);
$questions = ems_recruitment_questions_for_applicant($recruitmentType, $applicantId);
$questionCount = count($questions);
$questionPoolCount = (int)($profile['question_pool_count'] ?? $questionCount);
$hideQuestionCounts = $recruitmentType === 'assistant_manager';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($profile['test_title']) ?> - Roxwood Hospital</title>
    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
    <style>
        @media (min-width: 1024px) {
            body.ai-test-scroll-split {
                overflow: hidden;
            }

            .ai-test-scroll-shell {
                height: 100vh;
                padding: 16px;
            }

            .ai-test-scroll-layout {
                height: calc(100vh - 32px);
                align-items: stretch;
            }

            .ai-test-scroll-panel {
                height: calc(100vh - 32px);
                overflow-y: auto;
                overscroll-behavior: contain;
                scrollbar-gutter: stable;
            }
        }
    </style>
</head>

<body class="ai-test-scroll-split">
    <div class="public-shell ai-test-scroll-shell">
        <div class="public-layout ai-test-scroll-layout">
            <aside class="public-panel public-panel-hero public-sticky ai-test-scroll-panel">
                <div class="public-brand">
                    <img src="/assets/logo.png" alt="Logo Roxwood Hospital" class="public-brand-logo">
                    <div class="public-brand-text">
                        <span class="public-kicker">Assessment Stage</span>
                        <strong class="text-lg font-bold text-white">Roxwood Hospital</strong>
                        <span class="meta-text">Psychometric Screening</span>
                    </div>
                </div>

                <h1 class="public-heading"><?= htmlspecialchars($profile['test_title']) ?></h1>
                <p class="public-copy">
                    Halo, <strong><?= htmlspecialchars($applicant['ic_name']) ?></strong>. Jawab seluruh pertanyaan dengan jujur sesuai kebiasaan, sikap kerja, dan kondisi Anda yang sebenarnya.
                </p>

                <div class="alert alert-info mt-5 mb-0 border-white/15 bg-white/10 text-slate-100">
                    Tidak ada jawaban benar atau salah. Sistem menilai konsistensi, kesiapan, dan kecocokan pola kerja.
                </div>

                <div class="public-test-meta">
                    <?php if (!$hideQuestionCounts): ?>
                    <div class="public-test-stat">
                        <div class="public-test-stat-label">Total Pertanyaan</div>
                        <div class="public-test-stat-value"><?= $questionCount . ' Soal' ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="public-test-stat">
                        <div class="public-test-stat-label">Format Jawaban</div>
                        <div class="public-test-stat-value">Ya / Tidak</div>
                    </div>
                    <div class="public-test-stat">
                        <div class="public-test-stat-label">Saran Pengerjaan</div>
                        <div class="public-test-stat-value">Tenang dan jujur</div>
                    </div>
                </div>

                <div class="card mt-5 mb-0 border-white/10 bg-white/10 text-white shadow-none">
                    <div class="card-header border-white/10 pb-3 text-white">
                        <?= ems_icon('clipboard-document-list', 'h-5 w-5') ?>
                        <span>Petunjuk Singkat</span>
                    </div>
                    <div class="space-y-3 text-sm leading-6 text-slate-200">
                        <p>Seluruh soal wajib dijawab sebelum dikirim.</p>
                        <p>Progress jawaban akan tetap tersimpan di browser selama halaman belum dikirim.</p>
                        <p>Setelah submit, jawaban tidak bisa diubah kembali.</p>
                    </div>
                </div>
            </aside>

            <main class="public-panel ai-test-scroll-panel">
                <div class="public-form-header">
                    <div>
                        <h2 class="public-form-title"><?= htmlspecialchars($profile['test_title']) ?></h2>
                        <p class="public-form-subtitle"><?= htmlspecialchars($profile['test_subtitle']) ?></p>
                    </div>
                    <div class="badge-muted">Applicant #<?= (int)$applicantId ?></div>
                </div>

                <form action="ai_test_submit.php" method="post" id="aiTestForm">
                    <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
                    <input type="hidden" name="recruitment_type" value="<?= htmlspecialchars($recruitmentType) ?>">
                    <input type="hidden" name="start_time" id="start_time">
                    <input type="hidden" name="end_time" id="end_time">
                    <input type="hidden" name="duration_seconds" id="duration_seconds">

                    <div class="card">
                        <div class="card-header">
                            <?= ems_icon('document-text', 'h-5 w-5') ?>
                            <span>Instruksi Pengisian</span>
                        </div>
                        <div class="helper-note">
                            Pilih satu jawaban pada setiap soal. Gunakan jawaban yang paling menggambarkan diri Anda, bukan jawaban yang menurut Anda paling aman.
                        </div>
                    </div>

                    <div class="space-y-4">
                        <?php $displayNumber = 1; ?>
                        <?php foreach ($questions as $questionId => $question): ?>
                            <section class="question-card">
                                <div class="question-card-head">
                                    <div class="question-number"><?= $displayNumber++ ?></div>
                                    <div class="question-text"><?= htmlspecialchars($question) ?></div>
                                </div>

                                <div class="answer-grid">
                                    <label class="answer-option" data-answer-option>
                                        <input type="radio" name="q<?= (int)$questionId ?>" value="ya" required>
                                        <span class="answer-option-copy">
                                            <span class="answer-option-title">Ya</span>
                                            <span class="answer-option-desc">Pernyataan ini sesuai dengan saya.</span>
                                        </span>
                                    </label>

                                    <label class="answer-option" data-answer-option>
                                        <input type="radio" name="q<?= (int)$questionId ?>" value="tidak" required>
                                        <span class="answer-option-copy">
                                            <span class="answer-option-title">Tidak</span>
                                            <span class="answer-option-desc">Pernyataan ini tidak sesuai dengan saya.</span>
                                        </span>
                                    </label>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>

                    <div class="card mb-0 mt-5 bg-slate-50/80">
                        <div class="card-header">
                            <?= ems_icon('arrow-right-circle', 'h-5 w-5') ?>
                            <span>Finalisasi Jawaban</span>
                        </div>
                        <p class="helper-note mb-4">
                            Pastikan semua soal sudah terjawab. Setelah menekan tombol kirim, hasil akan langsung diproses oleh sistem.
                        </p>
                        <div class="form-submit-wrapper">
                            <button type="submit" class="btn-success w-full justify-center md:w-auto">
                                <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                                <span>Kirim Jawaban</span>
                            </button>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <div class="modal-overlay" id="introModal">
        <div class="modal-box modal-shell modal-frame-md">
            <div class="modal-head">
                <div class="modal-title inline-flex items-center gap-2">
                    <?= ems_icon('document-text', 'h-5 w-5') ?>
                    <span>Petunjuk Pengisian</span>
                </div>
                <button type="button" class="modal-close-btn" id="btnCloseIntro" aria-label="Tutup">
                    <?= ems_icon('x-mark', 'h-5 w-5') ?>
                </button>
            </div>
            <div class="modal-content">
                <ul class="list-disc pl-5 text-sm text-slate-700">
                    <li>Tidak ada jawaban benar atau salah.</li>
                    <li>Jawablah dengan jujur sesuai kondisi Anda.</li>
                    <li>Kerjakan dengan tenang, tidak perlu terburu-buru.</li>
                </ul>
            </div>
            <div class="modal-foot">
                <div class="modal-actions justify-end">
                    <button type="button" class="btn-success" id="btnStartTest">Saya Mengerti dan Mulai</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const STORAGE_KEY = 'ai_test_<?= $applicantId ?>';
            const form = document.getElementById('aiTestForm');
            const modal = document.getElementById('introModal');
            const startBtn = document.getElementById('btnStartTest');
            const closeBtn = document.getElementById('btnCloseIntro');

            let data = JSON.parse(localStorage.getItem(STORAGE_KEY)) || {
                startTime: null,
                answers: {}
            };

            function hideModal() {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }

            function showModal() {
                modal.style.display = 'flex';
                document.body.classList.add('modal-open');
            }

            function refreshAnswerStyles() {
                document.querySelectorAll('[data-answer-option]').forEach((option) => {
                    const input = option.querySelector('input[type="radio"]');
                    option.classList.toggle('is-selected', !!(input && input.checked));
                });
            }

            if (data.startTime) {
                document.getElementById('start_time').value = data.startTime;
                hideModal();
            } else {
                showModal();
            }

            closeBtn.addEventListener('click', () => {
                if (!data.startTime) return;
                hideModal();
            });

            modal.addEventListener('click', (event) => {
                if (event.target === modal && data.startTime) {
                    hideModal();
                }
            });

            Object.keys(data.answers).forEach((name) => {
                const input = document.querySelector(`input[name="${name}"][value="${data.answers[name]}"]`);
                if (input) {
                    input.checked = true;
                }
            });
            refreshAnswerStyles();

            startBtn.addEventListener('click', () => {
                hideModal();

                if (!data.startTime) {
                    data.startTime = Math.floor(Date.now() / 1000);
                    document.getElementById('start_time').value = data.startTime;
                    save();
                }
            });

            form.addEventListener('change', (event) => {
                if (event.target.name && event.target.name.startsWith('q')) {
                    data.answers[event.target.name] = event.target.value;
                    refreshAnswerStyles();
                    save();
                }
            });

            form.addEventListener('submit', (event) => {
                let allAnswered = true;
                const requiredQuestions = <?= json_encode(array_map(static fn($id) => 'q' . $id, array_keys($questions))) ?>;
                for (const questionKey of requiredQuestions) {
                    if (!data.answers[questionKey]) {
                        allAnswered = false;
                        break;
                    }
                }

                if (!allAnswered) {
                    event.preventDefault();
                    alert('Mohon jawab semua pertanyaan sebelum mengirim.');
                    return false;
                }

                const end = Math.floor(Date.now() / 1000);
                document.getElementById('end_time').value = end;
                document.getElementById('duration_seconds').value = data.startTime ? (end - data.startTime) : 0;

                localStorage.removeItem(STORAGE_KEY);
            });

            function save() {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
            }
        });
    </script>
</body>

</html>
