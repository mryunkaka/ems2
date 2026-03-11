<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$applicantId = (int)($_GET['applicant_id'] ?? 0);

if ($applicantId <= 0) {
    header('Location: recruitment_form.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, ic_name, status
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

$questions = [
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
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Pertanyaan - Roxwood Hospital</title>
    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
</head>

<body>
    <div class="public-shell">
        <div class="public-layout">
            <aside class="public-panel public-panel-hero public-sticky">
                <div class="public-brand">
                    <img src="/assets/logo.png" alt="Logo Roxwood Hospital" class="public-brand-logo">
                    <div class="public-brand-text">
                        <span class="public-kicker">Assessment Stage</span>
                        <strong class="text-lg font-bold text-white">Roxwood Hospital</strong>
                        <span class="meta-text">Psychometric Screening</span>
                    </div>
                </div>

                <h1 class="public-heading">Form Pertanyaan AI</h1>
                <p class="public-copy">
                    Halo, <strong><?= htmlspecialchars($applicant['ic_name']) ?></strong>. Jawab seluruh pertanyaan dengan jujur sesuai kebiasaan, sikap kerja, dan kondisi Anda yang sebenarnya.
                </p>

                <div class="alert alert-info mt-5 mb-0 border-white/15 bg-white/10 text-slate-100">
                    Tidak ada jawaban benar atau salah. Sistem menilai konsistensi, kesiapan, dan kecocokan pola kerja.
                </div>

                <div class="public-test-meta">
                    <div class="public-test-stat">
                        <div class="public-test-stat-label">Total Pertanyaan</div>
                        <div class="public-test-stat-value">50 Soal</div>
                    </div>
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

            <main class="public-panel">
                <div class="public-form-header">
                    <div>
                        <h2 class="public-form-title">Assessment Kandidat</h2>
                        <p class="public-form-subtitle">Isi semua pertanyaan berikut sebelum mengirim hasil akhir.</p>
                    </div>
                    <div class="badge-muted">Applicant #<?= (int)$applicantId ?></div>
                </div>

                <form action="ai_test_submit.php" method="post" id="aiTestForm">
                    <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
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
                        <?php foreach ($questions as $no => $question): ?>
                            <section class="question-card">
                                <div class="question-card-head">
                                    <div class="question-number"><?= (int)$no ?></div>
                                    <div class="question-text"><?= htmlspecialchars($question) ?></div>
                                </div>

                                <div class="answer-grid">
                                    <label class="answer-option" data-answer-option>
                                        <input type="radio" name="q<?= (int)$no ?>" value="ya" required>
                                        <span class="answer-option-copy">
                                            <span class="answer-option-title">Ya</span>
                                            <span class="answer-option-desc">Pernyataan ini sesuai dengan saya.</span>
                                        </span>
                                    </label>

                                    <label class="answer-option" data-answer-option>
                                        <input type="radio" name="q<?= (int)$no ?>" value="tidak" required>
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
                for (let i = 1; i <= 50; i++) {
                    if (!data.answers['q' + i]) {
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
