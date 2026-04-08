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
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/../config/ai_settings.php';
require_once __DIR__ . '/../actions/ai_recruitment_service.php';

$user = $_SESSION['user_rh'] ?? [];
$currentUnit = ems_effective_unit($pdo, $user);
$currentHospitalName = ems_unit_hospital_name($currentUnit);
$hrId = (int)($user['id'] ?? 0);

if ($hrId <= 0) {
    exit('Unauthorized');
}

$applicantId = (int)($_GET['id'] ?? $_POST['applicant_id'] ?? 0);
if ($applicantId <= 0) {
    header('Location: candidates.php');
    exit;
}

$saveStatus = (string)($_GET['status'] ?? '');

function candidateInterviewStatusLabel(?string $status): string
{
    $status = (string)($status ?? '');

    return match ($status) {
        'ai_completed' => 'Menunggu Interview',
        'interview' => 'Sedang Interview',
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
        default => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : '-',
    };
}

function candidateInterviewYesNo(?string $value): string
{
    return match (strtolower((string)$value)) {
        'ya' => 'Ya',
        'tidak' => 'Tidak',
        default => '-',
    };
}

$stmt = $pdo->prepare("
    SELECT *
    FROM medical_applicants
    WHERE id = ?
");
$stmt->execute([$applicantId]);
$candidate = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$candidate) {
    exit('Kandidat tidak ditemukan');
}

$recruitmentType = ems_normalize_recruitment_type($candidate['recruitment_type'] ?? 'medical_candidate');
$profile = ems_recruitment_profile($recruitmentType);
$listPage = $recruitmentType === 'assistant_manager' ? 'assistant_manager_candidates.php' : 'candidates.php';

if (!in_array($candidate['status'], ['ai_completed', 'interview'], true)) {
    exit('Status kandidat belum valid untuk interview');
}

$stmt = $pdo->prepare("
    SELECT is_locked
    FROM applicant_interview_results
    WHERE applicant_id = ?
");
$stmt->execute([$applicantId]);
$isLocked = (int)$stmt->fetchColumn();

if ($isLocked === 1) {
    header('Location: ' . $listPage . '?error=interview_locked');
    exit;
}

$criteriaSql = "
    SELECT id, code, label, description
    FROM interview_criteria
    WHERE is_active = 1
";
$criteriaParams = [];

if (ems_column_exists($pdo, 'interview_criteria', 'recruitment_type')) {
    $criteriaSql .= " AND recruitment_type IN ('all', ?)";
    $criteriaParams[] = $recruitmentType;
}

$criteriaSql .= " ORDER BY id ASC";
$stmt = $pdo->prepare($criteriaSql);
$stmt->execute($criteriaParams);
$criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

$criteriaById = [];
$criteriaByCode = [];
$criteriaLabelMap = [];
foreach ($criteria as $criterion) {
    $criterionId = (int)($criterion['id'] ?? 0);
    $criterionCode = trim((string)($criterion['code'] ?? ''));
    if ($criterionId > 0) {
        $criteriaById[$criterionId] = $criterion;
    }
    if ($criterionCode !== '') {
        $criteriaByCode[$criterionCode] = $criterion;
        $criteriaLabelMap[$criterionCode] = (string)($criterion['label'] ?? $criterionCode);
    }
}

$stmt = $pdo->prepare("
    SELECT criteria_id, score, notes
    FROM applicant_interview_scores
    WHERE applicant_id = ? AND hr_id = ?
");
$stmt->execute([$applicantId, $hrId]);
$scoreRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$existingScores = [];
$existingNotes = '';
foreach ($scoreRows as $row) {
    $existingScores[(int)$row['criteria_id']] = (int)$row['score'];
    if ($existingNotes === '' && trim((string)($row['notes'] ?? '')) !== '') {
        $existingNotes = trim((string)$row['notes']);
    }
}

$stmt = $pdo->prepare("
    SELECT *
    FROM ai_test_results
    WHERE applicant_id = ?
");
$stmt->execute([$applicantId]);
$aiResult = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$answers = json_decode((string)($aiResult['answers_json'] ?? ''), true) ?? [];

$aiQuestions = ems_recruitment_questions_for_applicant($recruitmentType, $applicantId);
$aiSettings = ems_ai_get_settings($pdo);
$generatedInterviewPack = ems_ai_get_or_create_interview_question_pack(
    $pdo,
    $aiSettings,
    $candidate,
    $aiResult,
    $answers,
    $aiQuestions,
    $applicantId,
    $hrId,
    $recruitmentType
);
$medicalInterviewQuestions = array_values((array)($generatedInterviewPack['medical_questions'] ?? []));
$personalInterviewQuestions = array_values((array)($generatedInterviewPack['personal_questions'] ?? []));
$flattenedInterviewQuestions = ems_ai_flatten_interview_question_pack($generatedInterviewPack);
ems_ai_ensure_interview_question_responses_table($pdo);

$stmt = $pdo->prepare("
    SELECT question_key, score
    FROM applicant_interview_question_responses
    WHERE applicant_id = ? AND hr_id = ?
");
$stmt->execute([$applicantId, $hrId]);
$questionScoreRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$existingQuestionScores = [];
foreach ($questionScoreRows as $row) {
    $questionKey = trim((string)($row['question_key'] ?? ''));
    if ($questionKey !== '') {
        $existingQuestionScores[$questionKey] = (int)($row['score'] ?? 0);
    }
}

$stmt = $pdo->prepare("
    SELECT document_type, file_path, is_valid, validation_notes
    FROM applicant_documents
    WHERE applicant_id = ?
");
$stmt->execute([$applicantId]);
$documentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$documents = [];
foreach ($documentRows as $documentRow) {
    $documents[$documentRow['document_type']] = $documentRow;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        exit('Invalid CSRF token');
    }

    $pdo->beginTransaction();

    try {
        if ($flattenedInterviewQuestions === []) {
            throw new Exception('Paket pertanyaan interview belum tersedia');
        }

        $submittedQuestionScores = $_POST['question_score'] ?? [];
        if (!is_array($submittedQuestionScores)) {
            throw new Exception('Format nilai pertanyaan tidak valid');
        }
        $notes = trim((string)($_POST['notes'] ?? ''));

        $questionInsertStmt = $pdo->prepare("
            INSERT INTO applicant_interview_question_responses
            (applicant_id, hr_id, question_key, question_category, criterion_code, question_text, good_answer_guide, bad_answer_guide, score)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                question_category = VALUES(question_category),
                criterion_code = VALUES(criterion_code),
                question_text = VALUES(question_text),
                good_answer_guide = VALUES(good_answer_guide),
                bad_answer_guide = VALUES(bad_answer_guide),
                score = VALUES(score),
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt = $pdo->prepare("
            DELETE FROM applicant_interview_question_responses
            WHERE applicant_id = ? AND hr_id = ?
        ");
        $stmt->execute([$applicantId, $hrId]);

        $stmt = $pdo->prepare("
            DELETE FROM applicant_interview_scores
            WHERE applicant_id = ? AND hr_id = ?
        ");
        $stmt->execute([$applicantId, $hrId]);

        $aggregatedCriteriaScores = [];
        $normalizedQuestionScores = [];

        foreach ($flattenedInterviewQuestions as $questionRow) {
            $questionKey = trim((string)($questionRow['question_key'] ?? ''));
            if ($questionKey === '') {
                continue;
            }

            $rawScore = $submittedQuestionScores[$questionKey] ?? '';
            if ($rawScore === '' || $rawScore === null) {
                continue;
            }

            $score = (int)$rawScore;
            if ($score < 1 || $score > 5) {
                throw new Exception('Nilai pertanyaan interview harus 1 sampai 5');
            }

            $normalizedQuestionScores[$questionKey] = $score;
            $criterionCode = trim((string)($questionRow['criterion_code'] ?? ''));
            if ($criterionCode !== '' && isset($criteriaByCode[$criterionCode])) {
                $aggregatedCriteriaScores[$criterionCode][] = $score;
            }

            $questionInsertStmt->execute([
                $applicantId,
                $hrId,
                $questionKey,
                (string)($questionRow['category'] ?? ''),
                $criterionCode !== '' ? $criterionCode : null,
                (string)($questionRow['text'] ?? ''),
                (string)($questionRow['good_answer'] ?? ''),
                (string)($questionRow['bad_answer'] ?? ''),
                $score,
            ]);
        }

        if ($normalizedQuestionScores === []) {
            throw new Exception('Minimal isi satu nilai interview sebelum disimpan');
        }

        foreach ($aggregatedCriteriaScores as $criterionCode => $criterionScoreItems) {
            $criterion = $criteriaByCode[$criterionCode] ?? null;
            if (!$criterion) {
                continue;
            }

            $averagedScore = (int)round(array_sum($criterionScoreItems) / max(1, count($criterionScoreItems)));
            $averagedScore = max(1, min(5, $averagedScore));

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
                (int)$criterion['id'],
                $averagedScore,
                $notes !== '' ? $notes : null,
            ]);
        }

        $stmt = $pdo->prepare("
            UPDATE medical_applicants
            SET status = 'interview'
            WHERE id = ? AND status IN ('ai_completed', 'interview')
        ");
        $stmt->execute([$applicantId]);

        $pdo->commit();

        header('Location: candidate_interview_multi.php?id=' . $applicantId . '&status=scores_saved');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        logRecruitmentError('INTERVIEW_SUBMIT', $e);
        exit('Gagal menyimpan penilaian: ' . $e->getMessage());
    }
}

$submittedFields = [
    ['label' => 'Nama IC', 'value' => $candidate['ic_name'] ?? '-'],
    ['label' => 'Citizen ID', 'value' => $candidate['citizen_id'] ?? '-'],
    ['label' => 'Jenis Kelamin', 'value' => $candidate['jenis_kelamin'] ?? '-'],
    ['label' => 'Nomor Telepon IC', 'value' => $candidate['ic_phone'] ?? '-'],
    ['label' => 'Umur OOC', 'value' => isset($candidate['ooc_age']) ? (string)$candidate['ooc_age'] : '-'],
    ['label' => 'Status Kandidat', 'value' => candidateInterviewStatusLabel($candidate['status'] ?? '')],
    ['label' => 'Tipe Rekrutmen', 'value' => ems_recruitment_type_label($recruitmentType)],
    ['label' => 'Pengalaman Medis', 'value' => $candidate['medical_experience'] ?? '-'],
    ['label' => 'Lama di Kota IME', 'value' => $candidate['city_duration'] ?? '-'],
    ['label' => 'Jam Online', 'value' => $candidate['online_schedule'] ?? '-'],
    ['label' => 'Tanggung Jawab Lain', 'value' => $candidate['other_city_responsibility'] ?? '-'],
    ['label' => 'Bersedia Academy', 'value' => candidateInterviewYesNo($candidate['academy_ready'] ?? '-')],
    ['label' => 'Komitmen Aturan', 'value' => candidateInterviewYesNo($candidate['rule_commitment'] ?? '-')],
    ['label' => 'Estimasi Duty', 'value' => $candidate['duty_duration'] ?? '-'],
    ['label' => 'Motivasi Bergabung', 'value' => $candidate['motivation'] ?? '-'],
    ['label' => 'Prinsip Kerja', 'value' => $candidate['work_principle'] ?? '-'],
];

$interviewScriptSections = [
    [
        'title' => '1. Pembukaan',
        'type' => 'copy',
        'content' => "“Selamat (pagi/siang/malam). Perkenalkan, nama saya (Nama Anda) sebagai (Jabatan Anda) di {$currentHospitalName}.\nPada kesempatan ini saya akan melakukan sesi interview kepada (Nama Calon Anggota) terkait pendaftaran sebagai petugas EMS di rumah sakit ini.”\n\n“Baik, sebelum kita mulai, saya persilahkan Anda untuk memperkenalkan diri serta menceritakan sedikit tentang karakter IC Anda.”",
    ],
    [
        'title' => '2. Informasi OOC',
        'type' => 'list',
        'items' => [
            'Boleh diinformasikan usia dan jenis kelamin Anda secara OOC?',
            'Apakah sebelumnya Anda pernah mencoba bergabung atau menjadi bagian dari EMS di server lain? (Masukkan di sheet interview jika ada/tidak ada).',
        ],
    ],
    [
        'title' => '3. Pengetahuan Tentang EMS',
        'type' => 'list',
        'items' => [
            'Menurut Anda, apa peran utama dari EMS atau petugas medis dalam sebuah kota RP?',
            'Sejauh yang Anda ketahui, apa saja tugas yang biasanya dilakukan oleh petugas EMS?',
            'Apakah Anda memiliki pengalaman sebagai EMS sebelumnya?',
            'Jika iya: di server atau kota mana Anda pernah bertugas, posisi apa yang Anda pegang, dan berapa lama Anda menjalankan peran tersebut?',
            'Selain EMS, apakah Anda pernah bergabung dengan instansi atau organisasi lain di kota IME atau kota lain? Jika tidak ada pengalaman medis, jangan tambahkan kata selain EMS.',
        ],
    ],
    [
        'title' => '4. Pengalaman Kerja & Kerja Sama Tim',
        'type' => 'list',
        'items' => [
            'Dalam pekerjaan medis, kerja sama tim sangat penting. Apakah Anda memiliki pengalaman bekerja dalam tim?',
            'Bagaimana biasanya Anda berperan atau berkontribusi dalam sebuah tim?',
            'Jika pernah menjadi Co-Ass atau posisi medis lainnya, pewawancara dapat memberikan contoh kasus medis sederhana untuk melihat pemahaman RP medis calon anggota.',
        ],
    ],
    [
        'title' => '5. Pengambilan Keputusan',
        'type' => 'list',
        'items' => [
            'Ceritakan pengalaman ketika Anda harus membuat keputusan penting dalam waktu singkat. Bagaimana Anda menghadapinya?',
        ],
    ],
    [
        'title' => '6. Motivasi Bergabung',
        'type' => 'list',
        'items' => [
            'Apa yang membuat Anda tertarik untuk bergabung sebagai petugas medis di ' . $currentHospitalName . '?',
            'Menurut Anda, nilai atau kualitas apa yang membuat Anda layak menjadi bagian dari tim medis di rumah sakit ini?',
            'Apa kelebihan yang Anda miliki yang dapat membantu pekerjaan Anda sebagai EMS?',
            'Apa hal yang masih menjadi kekurangan Anda dan ingin Anda perbaiki?',
            'Jika Anda diterima, apa tujuan atau rencana Anda dalam mengembangkan karir di ' . $currentHospitalName . '?',
            'Pada bagian ini interviewer juga bisa menjelaskan mengenai jenjang karir EMS di rumah sakit.',
        ],
    ],
    [
        'title' => '7. Komitmen Waktu',
        'type' => 'list',
        'items' => [
            'Apakah Anda bersedia mengikuti jadwal duty yang telah ditentukan oleh rumah sakit?',
            'Tanyakan weekday: jam berapa kandidat biasa tersedia.',
            'Tanyakan weekend: jam berapa kandidat biasa tersedia.',
            'Tanyakan apakah ada hari tertentu yang punya keterbatasan waktu.',
            'Note: jangan lupa isi di sheet.',
            'Bagaimana cara Anda memastikan tetap aktif dan konsisten selama menjadi bagian dari EMS?',
        ],
    ],
    [
        'title' => '8. Sikap Profesional',
        'type' => 'list',
        'items' => [
            'Bagaimana sikap Anda jika menghadapi warga yang berbicara tidak sopan kepada petugas medis?',
            'Jika Anda menerima kritik dari warga terkait pelayanan EMS, bagaimana Anda menyikapinya?',
            'Bagaimana Anda menanggapi masukan atau evaluasi dari atasan maupun rekan kerja?',
            'Apabila terjadi perbedaan pendapat dengan rekan kerja saat bertugas, bagaimana cara Anda menyelesaikannya?',
        ],
    ],
    [
        'title' => '9. Kesiapan Menjalankan Tugas',
        'type' => 'copy',
        'content' => "“Menjadi petugas EMS berarti harus siap menghadapi situasi darurat, bekerja di bawah tekanan, serta berinteraksi dengan berbagai macam karakter masyarakat. Apakah Anda siap menjalankan tanggung jawab tersebut sebagai bagian dari {$currentHospitalName}?”",
    ],
    [
        'title' => '10. Penutup',
        'type' => 'copy',
        'content' => "“Baik, terima kasih atas jawaban yang telah Anda berikan. Tim kami akan melakukan evaluasi terlebih dahulu terhadap hasil interview ini.”\n\n“Silakan menunggu informasi selanjutnya mengenai hasil interview Anda di {$currentHospitalName}. Anda dapat melanjutkan aktivitas kembali dan terima kasih atas waktunya.”",
    ],
];

$interviewScriptSections = $recruitmentType === 'assistant_manager'
    ? [
        [
            'title' => '1. Pembukaan',
            'type' => 'copy',
            'content' => "Selamat (pagi/siang/malam). Saya akan melakukan interview terkait pendaftaran Anda sebagai calon asisten manager pada divisi General Affair di {$currentHospitalName}. Mohon jawab dengan jelas sesuai pengalaman Anda.",
        ],
        [
            'title' => '2. Pengalaman Operasional',
            'type' => 'list',
            'items' => [
                'Jelaskan pengalaman Anda memimpin tim, mengelola fasilitas, atau menangani kebutuhan operasional.',
                'Ceritakan situasi saat Anda harus memastikan SOP tetap berjalan walau kondisi lapangan tidak ideal.',
                'Bagaimana Anda membagi prioritas ketika beberapa kebutuhan operasional muncul bersamaan?',
            ],
        ],
        [
            'title' => '3. SOP dan Disiplin',
            'type' => 'list',
            'items' => [
                'Bagaimana Anda menegur anggota tim yang melanggar SOP secara berulang?',
                'Apa yang Anda lakukan jika ada pihak yang meminta jalan pintas di luar prosedur?',
                'Mengapa dokumentasi dan laporan tertulis penting dalam pekerjaan General Affair?',
            ],
        ],
        [
            'title' => '4. Koordinasi dan Komplain',
            'type' => 'list',
            'items' => [
                'Bagaimana Anda menghadapi komplain keras dari divisi lain atau warga?',
                'Bagaimana Anda memastikan instruksi dari pimpinan dipahami oleh tim lapangan?',
                'Ceritakan pengalaman saat Anda harus menjadi penghubung antara kebutuhan pimpinan dan pelaksanaan teknis.',
            ],
        ],
        [
            'title' => '5. Penutup',
            'type' => 'copy',
            'content' => 'Terima kasih. Tim akan mengevaluasi konsistensi jawaban Anda antara formulir awal, assessment, dan sesi interview ini sebelum menentukan tahap berikutnya.',
        ],
    ]
    : $interviewScriptSections;

$scoreOptions = [
    1 => 'Sangat Buruk',
    2 => 'Buruk',
    3 => 'Sedang',
    4 => 'Baik',
    5 => 'Sangat Baik',
];

$documentLabels = [
    'ktp_ic' => 'KTP IC',
    'skb' => 'SKB',
    'sim' => 'SIM',
];

$pageTitle = 'Interview ' . ems_recruitment_type_label($recruitmentType);
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<style>
    @media (min-width: 1024px) {
        .interview-split-shell {
            min-height: calc(100vh - 180px);
        }

        .interview-split-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(380px, .9fr);
            gap: 1rem;
            align-items: stretch;
        }

        .interview-split-panel {
            max-height: calc(100vh - 280px);
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
            padding-right: 4px;
        }
    }
</style>

<section class="content">
    <div class="page page-shell interview-split-shell">
        <div class="card">
            <div class="card-header-between">
                <div>
                    <h1 class="page-title">Workspace Interview <?= htmlspecialchars(ems_recruitment_type_label($recruitmentType)) ?></h1>
                    <p class="page-subtitle">Lihat data formulir, dokumen, dan jawaban assessment di satu tempat agar interviewer mudah memverifikasi konsistensi kandidat.</p>
                </div>
                <div class="badge-info"><?= htmlspecialchars(candidateInterviewStatusLabel($candidate['status'] ?? '')) ?></div>
            </div>
        </div>

        <div class="interview-workspace interview-split-grid">
            <div class="interview-reference-stack interview-split-panel">
                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('user-group', 'h-5 w-5') ?>
                        <span>Ringkasan Kandidat</span>
                    </div>

                    <div class="interview-summary-grid">
                        <div class="interview-summary-item">
                            <div class="interview-summary-label">Nama Kandidat</div>
                            <div class="interview-summary-value"><?= htmlspecialchars($candidate['ic_name'] ?? '-') ?></div>
                        </div>
                        <div class="interview-summary-item">
                            <div class="interview-summary-label">Interviewer</div>
                            <div class="interview-summary-value"><?= htmlspecialchars($user['name'] ?? '-') ?></div>
                        </div>
                        <?php if (!empty($aiResult)): ?>
                            <div class="interview-summary-item">
                                <div class="interview-summary-label">Skor AI</div>
                                <div class="interview-summary-value"><?= htmlspecialchars((string)($aiResult['score_total'] ?? '-')) ?></div>
                            </div>
                            <div class="interview-summary-item">
                                <div class="interview-summary-label">Keputusan Sistem</div>
                                <div class="interview-summary-value"><?= htmlspecialchars(candidateInterviewStatusLabel($aiResult['decision'] ?? '-')) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('identification', 'h-5 w-5') ?>
                        <span>Data Formulir Awal</span>
                    </div>

                    <div class="interview-summary-grid">
                        <?php foreach ($submittedFields as $field): ?>
                            <div class="interview-summary-item">
                                <div class="interview-summary-label"><?= htmlspecialchars($field['label']) ?></div>
                                <div class="interview-summary-value"><?= nl2br(htmlspecialchars((string)($field['value'] ?: '-'))) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('paper-clip', 'h-5 w-5') ?>
                        <span>Dokumen Pelamar</span>
                    </div>

                    <div class="interview-doc-grid">
                        <?php foreach ($documentLabels as $documentType => $documentLabel): ?>
                            <?php $document = $documents[$documentType] ?? null; ?>
                            <div class="interview-doc-card">
                                <div class="card-header-between">
                                    <div class="interview-criteria-title"><?= htmlspecialchars($documentLabel) ?></div>
                                    <?php if ($document && $document['is_valid'] === '1'): ?>
                                        <span class="badge-success-mini">Valid</span>
                                    <?php elseif ($document && $document['is_valid'] === '0'): ?>
                                        <span class="badge-muted-mini">Belum Valid</span>
                                    <?php else: ?>
                                        <span class="badge-muted-mini">Belum Ada</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($document): ?>
                                    <?php $documentUrl = '../' . $document['file_path']; ?>
                                    <a href="<?= htmlspecialchars($documentUrl) ?>"
                                        target="_blank"
                                        class="doc-badge btn-preview-doc"
                                        data-src="<?= htmlspecialchars($documentUrl) ?>"
                                        data-title="<?= htmlspecialchars($documentLabel) ?>">
                                        <?= ems_icon('document-text', 'h-4 w-4') ?>
                                        <span>Lihat Dokumen</span>
                                    </a>
                                    <img src="<?= htmlspecialchars($documentUrl) ?>"
                                        alt="<?= htmlspecialchars($documentLabel) ?>"
                                        class="identity-photo interview-doc-preview">

                                    <?php if (!empty($document['validation_notes'])): ?>
                                        <div class="mt-2 text-xs text-rose-600">
                                            <?= htmlspecialchars($document['validation_notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="helper-note">Dokumen belum diunggah.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('clipboard-document-list', 'h-5 w-5') ?>
                        <span>Jawaban Kandidat</span>
                    </div>

                    <?php if (!empty($answers)): ?>
                        <div class="interview-answer-list">
                            <?php $displayQuestionNumber = 1; ?>
                            <?php foreach ($aiQuestions as $questionNumber => $questionText): ?>
                                <div class="interview-answer-item">
                                    <div class="interview-answer-question">
                                        <span class="text-slate-500">#<?= $displayQuestionNumber++ ?></span>
                                        <?= htmlspecialchars($questionText) ?>
                                    </div>
                                    <div class="interview-answer-meta">
                                        <?php $answerValue = $answers[$questionNumber] ?? '-'; ?>
                                        <?php if ($answerValue === 'ya'): ?>
                                            <span class="badge-success">Ya</span>
                                        <?php elseif ($answerValue === 'tidak'): ?>
                                            <span class="badge-danger">Tidak</span>
                                        <?php else: ?>
                                            <span class="badge-secondary">Belum Ada</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="helper-note">Jawaban AI belum tersedia untuk kandidat ini.</div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <?= ems_icon('chat-bubble-left-right', 'h-5 w-5') ?>
                        <span>Pertanyaan Interview</span>
                    </div>

                    <div class="space-y-4">
                        <div class="interview-script-section">
                            <div class="interview-script-title">Script Dasar Interview</div>
                            <?php foreach ($interviewScriptSections as $section): ?>
                                <div class="mt-3">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars($section['title']) ?></div>
                                    <?php if ($section['type'] === 'copy'): ?>
                                        <div class="interview-script-copy mt-2"><?= htmlspecialchars($section['content']) ?></div>
                                    <?php else: ?>
                                        <ul class="interview-script-list mt-2">
                                            <?php foreach ($section['items'] as $item): ?>
                                                <li><?= htmlspecialchars($item) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="interview-panel-stack interview-split-panel">
                <form method="post" class="card mb-0">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">

                    <div class="card-header">
                        <?= ems_icon('check-badge', 'h-5 w-5') ?>
                        <span>Card Penilaian</span>
                    </div>

                    <?php if ($saveStatus === 'scores_saved'): ?>
                        <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                            Nilai interview berhasil disimpan.
                        </div>
                    <?php endif; ?>

                    <div class="helper-note mb-4">
                        Anda tidak wajib mengisi semua pertanyaan. Nilai parsial tetap disimpan per interviewer, dan catatan interview ditulis manual oleh interviewer masing-masing.
                    </div>

                    <div class="interview-criteria-list">
                        <?php foreach ($flattenedInterviewQuestions as $index => $question): ?>
                            <div class="interview-criteria-item">
                                <div class="interview-criteria-head">
                                    <div class="interview-criteria-title">
                                        Pertanyaan <?= (int)($index + 1) ?>
                                        <?php
                                        $criterionCode = trim((string)($question['criterion_code'] ?? ''));
                                        $criterionLabel = $criteriaLabelMap[$criterionCode] ?? $criterionCode;
                                        ?>
                                        <?php if ($criterionLabel !== ''): ?>
                                            <span class="text-slate-400">· <?= htmlspecialchars($criterionLabel) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="interview-criteria-desc"><?= htmlspecialchars((string)($question['text'] ?? '')) ?></div>
                                    <div class="mt-2 text-sm text-emerald-700">
                                        <strong>Jawaban bagus:</strong>
                                        <?= htmlspecialchars((string)($question['good_answer'] ?? '-')) ?>
                                    </div>
                                    <div class="mt-1 text-sm text-rose-700">
                                        <strong>Jawaban buruk:</strong>
                                        <?= htmlspecialchars((string)($question['bad_answer'] ?? '-')) ?>
                                    </div>
                                </div>

                                <select name="question_score[<?= htmlspecialchars((string)($question['question_key'] ?? '')) ?>]" class="interview-score-select">
                                    <option value="">-- Pilih Nilai --</option>
                                    <?php foreach ($scoreOptions as $scoreValue => $scoreLabel): ?>
                                        <option value="<?= (int)$scoreValue ?>" <?= (($existingQuestionScores[(string)($question['question_key'] ?? '')] ?? null) === $scoreValue) ? 'selected' : '' ?>>
                                            <?= (int)$scoreValue ?> - <?= htmlspecialchars($scoreLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-group mt-4">
                        <label for="notes" class="text-sm font-semibold text-slate-900">Catatan Interview</label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="6"
                            placeholder="Tulis kesimpulan singkat, red flag, poin kuat, atau catatan interviewer lainnya."><?= htmlspecialchars($existingNotes) ?></textarea>
                        <div class="helper-note mt-2">
                            Catatan ini ditulis manual dan hanya mewakili penilaian interviewer yang sedang login.
                        </div>
                    </div>

                    <div class="form-submit-wrapper">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('check-badge', 'h-4 w-4') ?>
                            <span>Simpan Penilaian Interview</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
