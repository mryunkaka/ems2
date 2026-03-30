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
    header('Location: candidates.php?error=interview_locked');
    exit;
}

$criteria = $pdo->query("
    SELECT id, label, description
    FROM interview_criteria
    WHERE is_active = 1
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

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
        $notes = trim($_POST['notes'] ?? '');

        foreach ($criteria as $criterion) {
            if (!isset($_POST['score'][$criterion['id']])) {
                throw new Exception('Skor belum lengkap');
            }

            $score = (int)$_POST['score'][$criterion['id']];
            if ($score < 1 || $score > 5) {
                throw new Exception('Nilai tidak valid');
            }
        }

        foreach ($criteria as $criterion) {
            $score = (int)$_POST['score'][$criterion['id']];

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
                $criterion['id'],
                $score,
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

$submittedFields = [
    ['label' => 'Nama IC', 'value' => $candidate['ic_name'] ?? '-'],
    ['label' => 'Citizen ID', 'value' => $candidate['citizen_id'] ?? '-'],
    ['label' => 'Jenis Kelamin', 'value' => $candidate['jenis_kelamin'] ?? '-'],
    ['label' => 'Nomor Telepon IC', 'value' => $candidate['ic_phone'] ?? '-'],
    ['label' => 'Umur OOC', 'value' => isset($candidate['ooc_age']) ? (string)$candidate['ooc_age'] : '-'],
    ['label' => 'Status Kandidat', 'value' => candidateInterviewStatusLabel($candidate['status'] ?? '')],
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

$aiQuestions = [
    1  => 'Apakah Anda pernah menyesuaikan jawaban agar terlihat lebih baik?',
    2  => 'Apakah Anda merasa sulit fokus jika duty terlalu lama?',
    3  => 'Apakah Anda lebih memilih mengikuti SOP meski situasi menekan?',
    4  => 'Apakah Anda merasa tidak semua orang perlu tahu isi pikiran Anda?',
    5  => 'Apakah Anda pernah menangani kondisi darurat di mana keputusan harus diambil tanpa alat medis lengkap?',
    6  => 'Apakah Anda merasa stabilitas lingkungan kerja memengaruhi performa Anda?',
    7  => 'Apakah Anda sering berubah jam online karena faktor lain di luar pekerjaan ini?',
    8  => 'Apakah Anda percaya adab dan etika kerja sama pentingnya dengan skill?',
    9  => 'Apakah Anda lebih nyaman bekerja tanpa banyak berbicara?',
    10 => 'Apakah Anda pernah meninggalkan tugas karena kewajiban di tempat lain?',
    11 => 'Apakah dalam situasi kritis, keselamatan nyawa lebih utama dibanding prosedur administratif?',
    12 => 'Apakah Anda merasa cepat kehilangan semangat jika hasil tidak langsung terlihat?',
    13 => 'Apakah Anda jarang menunjukkan stres meskipun sedang tertekan?',
    14 => 'Apakah Anda merasa wajar untuk sering berpindah instansi dalam waktu singkat?',
    15 => 'Apakah Anda merasa aturan kerja bisa diabaikan dalam kondisi tertentu?',
    16 => 'Apakah Anda lebih memilih diam saat emosi meningkat?',
    17 => 'Apakah Anda terbiasa menyelesaikan tugas meski waktu duty sudah panjang?',
    18 => 'Apakah Anda merasa jawaban jujur tidak selalu aman?',
    19 => 'Apakah Anda yakin dapat memisahkan tanggung jawab antar instansi secara profesional?',
    20 => 'Apakah Anda pernah menyesal karena melanggar prinsip kerja sendiri?',
    21 => 'Apakah Anda memahami bahwa tidak semua kondisi medis memungkinkan pemeriksaan lengkap sebelum tindakan?',
    22 => 'Apakah Anda lebih memilih mengamati sebelum terlibat aktif?',
    23 => 'Apakah Anda merasa makna pekerjaan lebih penting daripada posisi?',
    24 => 'Apakah Anda cenderung menyimpan emosi daripada mengungkapkannya?',
    25 => 'Apakah Anda jarang meninggalkan tugas saat sudah mulai bertugas?',
    26 => 'Apakah Anda percaya kesan pertama sangat menentukan?',
    27 => 'Apakah Anda merasa sulit membagi fokus jika memiliki tanggung jawab di lebih dari satu instansi?',
    28 => 'Apakah Anda merasa prinsip kerja dapat berubah tergantung situasi?',
    29 => 'Apakah Anda membutuhkan waktu untuk beradaptasi dengan tekanan baru?',
    30 => 'Apakah Anda merasa tidak nyaman jika jadwal kerja terlalu berubah-ubah?',
    31 => 'Apakah pada kondisi pasien sekarat dengan dugaan patah tulang, tindakan stabilisasi lebih diprioritaskan daripada pemeriksaan lanjutan seperti MRI?',
    32 => 'Apakah Anda jarang memulai percakapan lebih dulu dalam tim?',
    33 => 'Apakah Anda merasa jadwal tetap justru membatasi fleksibilitas Anda?',
    34 => 'Apakah Anda pernah bergabung ke instansi hanya karena ajakan lingkungan?',
    35 => 'Apakah Anda merasa stamina kerja memengaruhi kualitas pelayanan?',
    36 => 'Apakah Anda cenderung bertahan lebih lama jika sudah merasa cocok di satu tempat?',
    37 => 'Apakah Anda memiliki kecenderungan memprioritaskan peran lain jika terjadi bentrok jadwal?',
    38 => 'Apakah Anda sering menilai diri sendiri secara diam-diam?',
    39 => 'Apakah Anda merasa sulit berkomitmen jika baru berada di suatu kota dalam waktu singkat?',
    40 => 'Apakah Anda merasa makna pekerjaan lebih penting daripada posisi?',
    41 => 'Apakah Anda lebih nyaman bekerja tanpa banyak arahan?',
    42 => 'Apakah Anda cenderung menghindari konflik langsung?',
    43 => 'Apakah Anda merasa sulit menerima kritik?',
    44 => 'Apakah Anda lebih memilih bekerja sendiri?',
    45 => 'Apakah Anda mudah panik dalam situasi darurat?',
    46 => 'Apakah Anda merasa kelelahan memengaruhi pengambilan keputusan?',
    47 => 'Apakah Anda pernah merasa tidak dihargai dalam tim?',
    48 => 'Apakah Anda cenderung menunda pekerjaan jika tidak diawasi?',
    49 => 'Apakah Anda sering overthinking setelah mengambil keputusan?',
    50 => 'Apakah Anda siap mengikuti arahan senior saat training?',
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

$pageTitle = 'Interview Kandidat';
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell">
        <div class="card">
            <div class="card-header-between">
                <div>
                    <h1 class="page-title">Workspace Interview Kandidat</h1>
                    <p class="page-subtitle">Lihat data formulir, dokumen, dan jawaban AI di satu tempat agar interviewer mudah memverifikasi konsistensi kandidat.</p>
                </div>
                <div class="badge-info"><?= htmlspecialchars(candidateInterviewStatusLabel($candidate['status'] ?? '')) ?></div>
            </div>
        </div>

        <div class="interview-workspace">
            <div class="interview-reference-stack">
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
                            <?php foreach ($aiQuestions as $questionNumber => $questionText): ?>
                                <div class="interview-answer-item">
                                    <div class="interview-answer-question">
                                        <span class="text-slate-500">#<?= (int)$questionNumber ?></span>
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
                        <?php foreach ($interviewScriptSections as $section): ?>
                            <div class="interview-script-section">
                                <div class="interview-script-title"><?= htmlspecialchars($section['title']) ?></div>
                                <?php if ($section['type'] === 'copy'): ?>
                                    <div class="interview-script-copy"><?= htmlspecialchars($section['content']) ?></div>
                                <?php else: ?>
                                    <ul class="interview-script-list">
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

            <div class="interview-panel-stack">
                <form method="post" class="card mb-0">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">

                    <div class="card-header">
                        <?= ems_icon('check-badge', 'h-5 w-5') ?>
                        <span>Card Penilaian</span>
                    </div>

                    <div class="helper-note mb-4">
                        Gunakan panel ini untuk menilai apakah jawaban saat interview konsisten dengan formulir awal, jawaban AI, dan dokumen kandidat.
                    </div>

                    <div class="interview-criteria-list">
                        <?php foreach ($criteria as $criterion): ?>
                            <div class="interview-criteria-item">
                                <div class="interview-criteria-head">
                                    <div class="interview-criteria-title"><?= htmlspecialchars($criterion['label']) ?></div>
                                    <div class="interview-criteria-desc"><?= htmlspecialchars($criterion['description']) ?></div>
                                </div>

                                <select name="score[<?= (int)$criterion['id'] ?>]" class="interview-score-select" required>
                                    <option value="">-- Pilih Nilai --</option>
                                    <?php foreach ($scoreOptions as $scoreValue => $scoreLabel): ?>
                                        <option value="<?= (int)$scoreValue ?>" <?= (($existingScores[(int)$criterion['id']] ?? null) === $scoreValue) ? 'selected' : '' ?>>
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
                            placeholder="Tulis poin penting interview, konsistensi jawaban, red flag, atau catatan lain untuk evaluasi pribadi HR."><?= htmlspecialchars($existingNotes) ?></textarea>
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
