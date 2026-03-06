<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

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

$answers = json_decode($result['answers_json'], true) ?? [];

/* ===============================
   DAFTAR PERTANYAAN AI TEST
   =============================== */
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

$pageTitle = 'Detail Kandidat';
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page" style="max-width:1100px;margin:auto;">

        <h1 class="gradient-text">Detail Kandidat</h1>

        <div class="card">
            <strong><?= htmlspecialchars($candidate['ic_name']) ?></strong>
            <div style="color:#64748b;font-size:13px;">
                Status: <?= $candidate['status'] ?> |
                Skor: <?= $result['score_total'] ?> |
                Keputusan: <?= strtoupper($result['decision']) ?>
            </div>
        </div>

        <!-- GRID ATAS -->
        <div class="candidate-grid">

            <!-- CARD: GRAFIK -->
            <div class="card">
                <h3>Grafik Profil Kemampuan</h3>
                <div style="height:260px;">
                    <canvas id="radarChart"></canvas>
                </div>
                <div style="margin-top:10px;font-size:13px;color:#64748b;">
                    Grafik ini menunjukkan profil kemampuan kerja kandidat berdasarkan hasil AI assessment.
                </div>
            </div>

            <!-- CARD: JAWABAN -->
            <div class="card">
                <h3>Jawaban Kandidat</h3>
                <div class="table-wrapper candidate-answers">

                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th style="width:50px;">No</th>
                                <th>Pertanyaan</th>
                                <th style="width:90px;">Jawaban</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $no => $question): ?>
                                <tr>
                                    <td><?= $no ?></td>
                                    <td><?= htmlspecialchars($question) ?></td>
                                    <td>
                                        <?php
                                        $ans = $answers[$no] ?? '-';
                                        if ($ans === 'ya') {
                                            echo '<span style="color:#16a34a;font-weight:600;">YA</span>';
                                        } elseif ($ans === 'tidak') {
                                            echo '<span style="color:#dc2626;font-weight:600;">TIDAK</span>';
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
            </div>

        </div>

        <!-- CARD BAWAH (FULL WIDTH) -->
        <div class="card" style="margin-top:16px;">
            <h3>Ringkasan Calon Medis</h3>

            <div style="
        font-size:15px;
        line-height:1.7;
        color:#334155;
        background:#f8fafc;
        padding:16px;
        border-radius:10px;
        border-left:4px solid #2563eb;
    ">
                <?= nl2br(htmlspecialchars($personalityNarrative)) ?>
            </div>

            <div style="margin-top:10px;font-size:12px;color:#64748b;">
                Catatan: Ringkasan ini dihasilkan otomatis sebagai alat bantu HR dan
                <strong>bukan diagnosis psikologis</strong>.
            </div>
        </div>

        <!-- CARD: DOKUMEN PELAMAR (PINDAH KE SINI) -->
        <div class="card" style="margin-top:16px;">
            <h3>Dokumen Pelamar</h3>

            <table class="table-custom">
                <tbody>
                    <?php
                    $documents = [
                        'KTP' => 'ktp_ic',
                        'SKB' => 'skb',
                        'SIM' => 'sim',
                    ];

                    $uploadBase = '../'; // karena path sudah storage/...
                    ?>

                    <?php foreach ($documents as $label => $type): ?>
                        <?php
                        $stmt = $pdo->prepare("
                    SELECT file_path, is_valid, validation_notes
                    FROM applicant_documents
                    WHERE applicant_id = ? AND document_type = ?
                    LIMIT 1
                ");
                        $stmt->execute([$id, $type]);
                        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <tr>
                            <td style="width:220px;"><strong><?= $label ?></strong></td>
                            <td>
                                <?php if ($doc): ?>
                                    <a href="<?= htmlspecialchars($uploadBase . $doc['file_path']) ?>"
                                        target="_blank"
                                        class="btn btn-sm btn-primary">
                                        📄 Lihat Dokumen
                                    </a>

                                    <?php if ($doc['is_valid'] === '0'): ?>
                                        <span class="badge badge-danger">TIDAK VALID</span>
                                    <?php elseif ($doc['is_valid'] === '1'): ?>
                                        <span class="badge badge-success">VALID</span>
                                    <?php endif; ?>

                                    <?php if ($doc['validation_notes']): ?>
                                        <div style="font-size:12px;color:#dc2626;margin-top:4px;">
                                            <?= htmlspecialchars($doc['validation_notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:13px;">Tidak tersedia</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:10px;font-size:12px;color:#64748b;">
                Dokumen ditampilkan sesuai file yang diunggah pelamar.
            </div>
        </div>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                    <?= (int)$result['focus_score'] ?>,
                    <?= (int)$result['consistency_score'] ?>,
                    <?= (int)$result['social_score'] ?>,
                    <?= (int)$result['attitude_score'] ?>,
                    <?= (int)$result['loyalty_score'] ?>,
                    <?= (int)$result['honesty_score'] ?>
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