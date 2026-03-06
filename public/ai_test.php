<?php
// PUBLIC PAGE - FIXED VERSION
require_once __DIR__ . '/../config/database.php';

$applicantId = (int)($_GET['applicant_id'] ?? 0);

// VALIDASI: Cek apakah applicant exist dan status benar
if ($applicantId <= 0) {
    header('Location: recruitment_form.php');
    exit;
}

// CARI bagian ini di ai_test.php (sekitar line 12-16):
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

// TAMBAHKAN validasi status:
if ($applicant['status'] !== 'ai_test') {
    header('Location: recruitment_done.php');
    exit;
}

// VALIDASI: Cek apakah sudah pernah submit AI test
$stmt = $pdo->prepare("SELECT id FROM ai_test_results WHERE applicant_id = ?");
$stmt->execute([$applicantId]);
if ($stmt->fetch()) {
    // Sudah pernah submit, redirect ke done
    header('Location: recruitment_done.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Form Pertanyaan – Roxwood Hospital</title>

    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/login.css">
    <link rel="stylesheet" href="/assets/css/responsive.css">
</head>

<body>

    <div class="login-wrapper">
        <div class="login-card">

            <div class="brand">
                <h2>Form Pertanyaan</h2>
                <p>Halo, <strong><?= htmlspecialchars($applicant['ic_name']) ?></strong></p>
                <p>Silakan jawab pertanyaan sederhana berikut ini</p>
            </div>

            <div class="alert alert-info">
                Jawab sesuai kondisi dan kebiasaan Anda.<br>
                Tidak ada jawaban benar atau salah.
            </div>

            <form action="ai_test_submit.php" method="post" id="aiTestForm">

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
                    40 => 'Apakah Anda jarang memulai interaksi kecuali diperlukan?',

                    41 => 'Apakah menurut Anda pemeriksaan MRI selalu wajib sebelum tindakan medis darurat?',
                    42 => 'Apakah Anda terbiasa menyesuaikan jadwal demi tanggung jawab pekerjaan?',
                    43 => 'Apakah Anda memilih diam saat tidak setuju demi menjaga suasana?',
                    44 => 'Apakah Anda merasa loyalitas perlu dibagi secara seimbang jika memiliki banyak peran?',
                    45 => 'Apakah Anda tetap bertahan meski peran yang dijalani terasa berat?',

                    46 => 'Apakah Anda lebih memilih patuh demi menjaga suasana kerja?',
                    47 => 'Apakah Anda sering menghitung waktu untuk segera menyelesaikan duty?',
                    48 => 'Apakah Anda merasa betah di satu lingkungan kerja setelah waktu tertentu?',
                    49 => 'Apakah Anda menyesuaikan sikap saat berbicara dengan atasan?',
                    50 => 'Apakah Anda merasa menahan emosi adalah bentuk kedewasaan?',
                ];
                foreach ($questions as $no => $text): ?>
                    <label class="ai-question"><?= $no ?>. <?= $text ?></label>

                    <div class="ai-radio-group">
                        <label class="ai-radio">
                            <input type="radio" name="q<?= $no ?>" value="ya" required>
                            <span>Ya</span>
                        </label>

                        <label class="ai-radio">
                            <input type="radio" name="q<?= $no ?>" value="tidak" required>
                            <span>Tidak</span>
                        </label>
                    </div>

                <?php endforeach; ?>

                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:18px;">
                    Kirim Jawaban
                </button>

            </form>

        </div>
    </div>

    <!-- MODAL -->
    <div class="modal-overlay" id="introModal">
        <div class="modal-box">
            <h3>Petunjuk Pengisian</h3>
            <ul>
                <li>Tidak ada jawaban benar atau salah</li>
                <li>Jawablah dengan jujur sesuai kondisi Anda</li>
                <li><strong>Kerjakan dengan tenang, tidak perlu terburu-buru</strong></li>
            </ul>
            <div class="modal-actions">
                <button type="button" class="btn btn-primary" id="btnStartTest">
                    Saya Mengerti & Mulai
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            const STORAGE_KEY = 'ai_test_<?= $applicantId ?>';
            const form = document.getElementById('aiTestForm');
            const modal = document.getElementById('introModal');
            const startBtn = document.getElementById('btnStartTest');

            let data = JSON.parse(localStorage.getItem(STORAGE_KEY)) || {
                startTime: null,
                answers: {}
            };

            /* ===== RESTORE DATA ===== */
            if (data.startTime) {
                document.getElementById('start_time').value = data.startTime;
                modal.style.display = 'none';
            } else {
                document.body.classList.add('modal-open');
            }

            // restore answers
            Object.keys(data.answers).forEach(name => {
                const input = document.querySelector(`input[name="${name}"][value="${data.answers[name]}"]`);
                if (input) input.checked = true;
            });

            /* ===== START TEST ===== */
            startBtn.addEventListener('click', () => {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');

                if (!data.startTime) {
                    data.startTime = Math.floor(Date.now() / 1000);
                    document.getElementById('start_time').value = data.startTime;
                    save();
                }
            });

            /* ===== SAVE ANSWERS ===== */
            form.addEventListener('change', e => {
                if (e.target.name.startsWith('q')) {
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