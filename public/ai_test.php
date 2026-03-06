<?php
// PUBLIC PAGE - FIXED VERSION
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$applicantId = (int)($_GET['applicant_id'] ?? 0);

// VALIDASI: Cek apakah applicant exist dan status benar
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

// VALIDASI: Cek apakah sudah pernah submit AI test
$stmt = $pdo->prepare("SELECT id FROM ai_test_results WHERE applicant_id = ?");
$stmt->execute([$applicantId]);
if ($stmt->fetch()) {
    header('Location: recruitment_done.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Pertanyaan - Roxwood Hospital</title>

    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-sky-900">

    <div class="min-h-screen px-4 py-10">
        <div class="mx-auto flex w-full max-w-5xl items-start justify-center">
            <div class="w-full max-w-[920px]">
                <div class="rounded-3xl border border-white/60 bg-white/85 p-6 shadow-modal backdrop-blur">

                    <div class="mb-6">
                        <div class="text-center">
                            <div class="text-xl font-bold text-slate-900">Form Pertanyaan</div>
                            <div class="mt-1 text-sm text-slate-600">
                                Halo, <strong><?= htmlspecialchars($applicant['ic_name']) ?></strong>. Silakan jawab pertanyaan berikut.
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        Jawab sesuai kondisi dan kebiasaan Anda. Tidak ada jawaban benar atau salah.
                    </div>

                    <form action="ai_test_submit.php" method="post" id="aiTestForm" class="space-y-3">
                        <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
                        <input type="hidden" name="start_time" id="start_time">
                        <input type="hidden" name="end_time" id="end_time">
                        <input type="hidden" name="duration_seconds" id="duration_seconds">

                        <?php
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

                        <?php foreach ($questions as $no => $q): ?>
                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="text-sm font-semibold text-slate-900">
                                        <span class="text-slate-500">#<?= (int)$no ?></span> <?= htmlspecialchars($q) ?>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border-2 border-slate-200 bg-white px-3 py-2 transition hover:bg-sky-50">
                                        <input type="radio" name="q<?= (int)$no ?>" value="ya" required>
                                        <span class="text-sm font-semibold">Ya</span>
                                    </label>

                                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border-2 border-slate-200 bg-white px-3 py-2 transition hover:bg-sky-50">
                                        <input type="radio" name="q<?= (int)$no ?>" value="tidak" required>
                                        <span class="text-sm font-semibold">Tidak</span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="pt-2">
                            <button type="submit" class="btn-success w-full justify-center">
                                <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                                <span>Kirim Jawaban</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL -->
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

            /* ===== RESTORE DATA ===== */
            if (data.startTime) {
                document.getElementById('start_time').value = data.startTime;
                hideModal();
            } else {
                showModal();
            }

            closeBtn.addEventListener('click', () => {
                // Do not allow closing if not started, keep it simple: start is required.
                if (!data.startTime) return;
                hideModal();
            });

            modal.addEventListener('click', (e) => {
                if (e.target === modal && data.startTime) hideModal();
            });

            // restore answers
            Object.keys(data.answers).forEach(name => {
                const input = document.querySelector(`input[name="${name}"][value="${data.answers[name]}"]`);
                if (input) input.checked = true;
            });

            /* ===== START TEST ===== */
            startBtn.addEventListener('click', () => {
                hideModal();

                if (!data.startTime) {
                    data.startTime = Math.floor(Date.now() / 1000);
                    document.getElementById('start_time').value = data.startTime;
                    save();
                }
            });

            /* ===== SAVE ANSWERS ===== */
            form.addEventListener('change', e => {
                if (e.target.name && e.target.name.startsWith('q')) {
                    data.answers[e.target.name] = e.target.value;
                    save();
                }
            });

            /* ===== SUBMIT ===== */
            form.addEventListener('submit', e => {
                // Validasi semua pertanyaan sudah dijawab
                let allAnswered = true;
                for (let i = 1; i <= 50; i++) {
                    if (!data.answers['q' + i]) {
                        allAnswered = false;
                        break;
                    }
                }

                if (!allAnswered) {
                    e.preventDefault();
                    alert('Mohon jawab semua pertanyaan sebelum mengirim.');
                    return false;
                }

                const end = Math.floor(Date.now() / 1000);

                document.getElementById('end_time').value = end;
                document.getElementById('duration_seconds').value =
                    data.startTime ? (end - data.startTime) : 0;

                localStorage.removeItem(STORAGE_KEY);
            });

            function save() {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
            }

        });
    </script>
</body>

</html>

