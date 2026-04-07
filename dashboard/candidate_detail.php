<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/../actions/ai_scoring_engine.php';

$user = $_SESSION['user_rh'] ?? [];
if (strtolower($user['role'] ?? '') === 'staff') {
    header('Location: dashboard.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: candidates.php');
    exit;
}

// Kandidat
$stmt = $pdo->prepare("SELECT * FROM medical_applicants WHERE id = ?");
$stmt->execute([$id]);
$candidate = $stmt->fetch(PDO::FETCH_ASSOC);

// Hasil AI
$stmt = $pdo->prepare("SELECT * FROM ai_test_results WHERE applicant_id = ?");
$stmt->execute([$id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$personalityNarrative = $result['personality_summary'] ?? '-';

if (!$candidate || !$result) {
    exit('Data kandidat tidak lengkap');
}

/* ===============================
   DAFTAR PERTANYAAN AI TEST
   =============================== */
$recruitmentType = ems_normalize_recruitment_type($candidate['recruitment_type'] ?? 'medical_candidate');
$profile = ems_recruitment_profile($recruitmentType);
$questions = ems_recruitment_questions_for_applicant($recruitmentType, $id);

$answers = json_decode($result['answers_json'], true) ?? [];
$answers = is_array($answers) ? $answers : [];
$yesCount = 0;
$noCount = 0;
foreach ($answers as $answerValue) {
    if ($answerValue === 'ya') {
        $yesCount++;
    } elseif ($answerValue === 'tidak') {
        $noCount++;
    }
}
$questionIds = array_map('intval', array_keys($questions));
$traitItems = $recruitmentType === 'assistant_manager'
    ? ems_assistant_manager_trait_items($questionIds)
    : getTraitItems($recruitmentType);
$chartScoreMap = [];
foreach ($traitItems as $trait => $items) {
    $chartScoreMap[$trait] = calculateTraitScore($answers, $items);
}
$chartScores = [
    (int) round((float) ($chartScoreMap['focus']['score'] ?? 0)),
    (int) round((float) ($chartScoreMap['consistency']['score'] ?? 0)),
    (int) round((float) ($chartScoreMap['social']['score'] ?? 0)),
    (int) round((float) ($chartScoreMap['emotional_stability']['score'] ?? 0)),
    (int) round((float) ($chartScoreMap['obedience']['score'] ?? 0)),
    (int) round((float) ($chartScoreMap['honesty_humility']['score'] ?? 0)),
];

$durationSeconds = (int)($result['duration_seconds'] ?? 0);
$durationHours = intdiv($durationSeconds, 3600);
$durationMinutes = intdiv($durationSeconds % 3600, 60);
$durationRemainSeconds = $durationSeconds % 60;
$durationText = $durationSeconds > 0
    ? sprintf('%02d:%02d:%02d', $durationHours, $durationMinutes, $durationRemainSeconds)
    : '-';
$questionEntries = [];
foreach ($questions as $questionId => $questionText) {
    $questionEntries[] = [
        'id' => $questionId,
        'question' => $questionText,
        'answer' => $answers[$questionId] ?? '-',
    ];
}
$answerChunkSize = (int)ceil(max(1, count($questionEntries)) / 3);
$answerChunks = array_chunk($questionEntries, max(1, $answerChunkSize));

$pageTitle = 'Detail ' . ems_recruitment_type_label($candidate['recruitment_type'] ?? 'medical_candidate');

$userDocuments = null;
$candidateCitizenId = trim((string)($candidate['citizen_id'] ?? ''));
if ($candidateCitizenId !== '') {
    $stmt = $pdo->prepare("
        SELECT file_ktp, file_skb, file_kta, file_sim
        FROM user_rh
        WHERE citizen_id = ?
        LIMIT 1
    ");
    $stmt->execute([$candidateCitizenId]);
    $userDocuments = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function candidateDisplayLabel(?string $value): string
{
    $value = (string)($value ?? '');

    return match (strtolower($value)) {
        'ai_completed' => 'Menunggu',
        'interview' => 'Interview',
        'final_review' => 'Final Review',
        'accepted' => 'Diterima',
        'rejected' => 'Ditolak',
        'recommended' => 'Direkomendasikan',
        'not_recommended' => 'Tidak Direkomendasikan',
        'follow_up_required' => 'Perlu Tindak Lanjut',
        'proceed' => 'Lanjut Interview',
        'reject' => 'Ditolak Sistem',
        'lolos' => 'Lolos',
        'tidak_lolos' => 'Tidak Lolos',
        '' => '-',
        default => ucwords(str_replace('_', ' ', $value)),
    };
}
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">

        <h1 class="page-title">Detail <?= htmlspecialchars(ems_recruitment_type_label($candidate['recruitment_type'] ?? 'medical_candidate')) ?></h1>

        <div class="card">
            <strong><?= htmlspecialchars($candidate['ic_name']) ?></strong>
            <div class="meta-text">
                Status: <?= htmlspecialchars(candidateDisplayLabel($candidate['status'])) ?> |
                Skor: <?= $result['score_total'] ?> |
                Keputusan: <?= htmlspecialchars(candidateDisplayLabel($result['decision'])) ?>
            </div>
        </div>

        <div class="candidate-grid mb-4">
            <div class="card">
                <h3>Identitas Dasar</h3>
                <div class="space-y-2 text-sm text-slate-700">
                    <div><strong>Nama IC:</strong> <?= htmlspecialchars((string)($candidate['ic_name'] ?? '-')) ?></div>
                    <div><strong>Citizen ID:</strong> <?= htmlspecialchars((string)($candidate['citizen_id'] ?? '-')) ?></div>
                    <div><strong>Jenis Kelamin:</strong> <?= htmlspecialchars((string)($candidate['jenis_kelamin'] ?? '-')) ?></div>
                    <div><strong>Umur OOC:</strong> <?= htmlspecialchars((string)($candidate['ooc_age'] ?? '-')) ?></div>
                    <div><strong>Nomor Telepon IC:</strong> <?= htmlspecialchars((string)($candidate['ic_phone'] ?? '-')) ?></div>
                    <div><strong>Lama di RS:</strong> <?= htmlspecialchars((string)($candidate['city_duration'] ?? '-')) ?></div>
                    <div><strong>Jam Biasanya Online:</strong><br><span class="whitespace-pre-line"><?= htmlspecialchars((string)($candidate['online_schedule'] ?? '-')) ?></span></div>
                </div>
            </div>

            <div class="card">
                <h3>Pengalaman dan Komitmen</h3>
                <div class="space-y-3 text-sm text-slate-700">
                    <div>
                        <strong>Pengalaman Organisasi / Operasional</strong>
                        <div class="mt-1 whitespace-pre-line rounded-xl bg-slate-50 p-3"><?= htmlspecialchars((string)($candidate['medical_experience'] ?? '-')) ?></div>
                    </div>
                    <div>
                        <strong>Tanggung Jawab di Kota / Instansi Lain</strong>
                        <div class="mt-1 whitespace-pre-line rounded-xl bg-slate-50 p-3"><?= htmlspecialchars((string)($candidate['other_city_responsibility'] ?? '-')) ?></div>
                    </div>
                    <div><strong>Bersedia Probation:</strong> <?= htmlspecialchars(candidateDisplayLabel((string)($candidate['academy_ready'] ?? '-'))) ?></div>
                    <div><strong>Komitmen SOP / Aturan:</strong> <?= htmlspecialchars(candidateDisplayLabel((string)($candidate['rule_commitment'] ?? '-'))) ?></div>
                    <div><strong>Perkiraan Duty / Monitoring:</strong> <?= htmlspecialchars((string)($candidate['duty_duration'] ?? '-')) ?></div>
                </div>
            </div>

            <div class="card">
                <h3>Motivasi</h3>
                <div class="space-y-3 text-sm text-slate-700">
                    <div>
                        <strong>Alasan Ingin Bergabung</strong>
                        <div class="mt-1 whitespace-pre-line rounded-xl bg-slate-50 p-3"><?= htmlspecialchars((string)($candidate['motivation'] ?? '-')) ?></div>
                    </div>
                    <div>
                        <strong>Prinsip Kerja</strong>
                        <div class="mt-1 whitespace-pre-line rounded-xl bg-slate-50 p-3"><?= htmlspecialchars((string)($candidate['work_principle'] ?? '-')) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="candidate-grid mb-4">
            <div class="card">
                <h3>Lama Menjawab Soal</h3>
                <div class="mt-2 text-2xl font-bold text-slate-900"><?= htmlspecialchars($durationText) ?></div>
                <div class="mt-2 text-sm text-slate-500">Durasi total pengerjaan assessment.</div>
            </div>

            <div class="card">
                <h3>Jumlah Jawaban Ya</h3>
                <div class="mt-2 text-2xl font-bold text-emerald-700"><?= (int)$yesCount ?></div>
                <div class="mt-2 text-sm text-slate-500">Total jawaban `Ya` pada assessment.</div>
            </div>

            <div class="card">
                <h3>Jumlah Jawaban Tidak</h3>
                <div class="mt-2 text-2xl font-bold text-rose-700"><?= (int)$noCount ?></div>
                <div class="mt-2 text-sm text-slate-500">Total jawaban `Tidak` pada assessment.</div>
            </div>
        </div>

        <!-- GRID ATAS -->
        <div class="candidate-grid">

            <!-- CARD: GRAFIK -->
            <div class="card">
                <h3>Grafik Profil Kemampuan</h3>
                <div class="h-[260px]">
                    <canvas id="radarChart"></canvas>
                </div>
                <div class="mt-2 text-sm text-slate-500">
                    Grafik ini menunjukkan profil kemampuan kerja kandidat berdasarkan hasil AI assessment.
                </div>
            </div>

            <div class="card">
                <h3>Dokumen Pelamar</h3>

            <div class="table-wrapper">
                <table class="table-custom">
                    <tbody>
                        <?php
                        $documents = [
                            'KTP' => $userDocuments['file_ktp'] ?? '',
                            'SKB' => $userDocuments['file_skb'] ?? '',
                            'KTA' => $userDocuments['file_kta'] ?? '',
                            'SIM' => $userDocuments['file_sim'] ?? '',
                        ];
                        $uploadBase = '../';
                        ?>

                        <?php foreach ($documents as $label => $filePath): ?>
                            <?php
                            $docUrl = trim((string)$filePath) !== '' ? $uploadBase . ltrim((string)$filePath, '/') : '';
                            ?>
	                            <tr>
	                                <td class="w-56"><strong><?= $label ?></strong></td>
	                                <td>
	                                    <?php if ($docUrl !== ''): ?>
	                                        <a href="<?= htmlspecialchars($docUrl) ?>"
	                                            target="_blank"
	                                            class="doc-badge btn-preview-doc"
	                                            data-src="<?= htmlspecialchars($docUrl) ?>"
	                                            data-title="<?= htmlspecialchars($label) ?>"
	                                            title="Lihat <?= htmlspecialchars($label) ?>">
	                                            <?= ems_icon('document-text', 'h-4 w-4') ?>
	                                            <span>Lihat Dokumen</span>
	                                        </a>
                                    <?php else: ?>
                                        <span class="muted-placeholder text-sm">Tidak tersedia</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-2 text-xs text-slate-500">
                Dokumen ditampilkan dari data akun `user_rh` berdasarkan `Citizen ID`.
            </div>
        </div>

        </div>

        <div class="card mt-4">
            <h3>Ringkasan <?= htmlspecialchars(ems_recruitment_type_label($candidate['recruitment_type'] ?? 'medical_candidate')) ?></h3>

            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-[15px] leading-relaxed text-slate-700 shadow-soft border-l-4 border-l-primary">
                <?= nl2br(htmlspecialchars($personalityNarrative)) ?>
            </div>

            <div class="mt-2 text-xs text-slate-500">
                Catatan: Ringkasan ini dihasilkan otomatis sebagai alat bantu HR dan
                <strong>bukan diagnosis psikologis</strong>.
            </div>
        </div>

        <div class="card mt-4">
            <h3>Jawaban Kandidat</h3>
            <div class="candidate-grid">
                <?php foreach ($answerChunks as $chunkIndex => $chunk): ?>
                    <div class="table-wrapper candidate-answers">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th class="w-14">No</th>
                                    <th>Pertanyaan</th>
                                    <th class="w-24">Jawaban</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($chunk as $rowIndex => $entry): ?>
                                    <?php $displayNumber = ($chunkIndex * $answerChunkSize) + $rowIndex + 1; ?>
                                    <tr>
                                        <td><?= $displayNumber ?></td>
                                        <td><?= htmlspecialchars($entry['question']) ?></td>
                                        <td>
                                            <?php
                                            $ans = $entry['answer'];
                                            if ($ans === 'ya') {
                                                echo '<span class="badge-success">YA</span>';
                                            } elseif ($ans === 'tidak') {
                                                echo '<span class="badge-danger">TIDAK</span>';
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<script src="/assets/vendor/chartjs/chart.umd.js"></script>
<script>
    const ctx = document.getElementById('radarChart');

    new Chart(ctx, {
        type: 'radar',
        data: {
            labels: [
                'Focus',
                'Consistency',
                'Social',
                'Emotional',
                'Obedience',
                'Honesty'
            ],
            datasets: [{
                label: 'Profil Kandidat',
                data: [
                    <?= (int)$chartScores[0] ?>,
                    <?= (int)$chartScores[1] ?>,
                    <?= (int)$chartScores[2] ?>,
                    <?= (int)$chartScores[3] ?>,
                    <?= (int)$chartScores[4] ?>,
                    <?= (int)$chartScores[5] ?>
                ],
                backgroundColor: 'rgba(37, 99, 235, 0.2)',
                borderColor: 'rgba(37, 99, 235, 1)'
            }]
        },
        options: {
            scales: {
                r: {
                    suggestedMin: 0,
                    suggestedMax: 100
                }
            }
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
