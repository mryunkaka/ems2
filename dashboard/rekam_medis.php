<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Rekam Medis | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];

// Get doctors (DPJP - min co_asst ke atas)
$doctors = $pdo->query("
    SELECT id, full_name, position 
    FROM user_rh 
    WHERE position IN ('co_asst', 'general_practitioner', 'specialist', '(Co.Ast)', 'Dokter Umum', 'Dokter Spesialis')
    AND is_active = 1
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get assistants (min paramedic ke atas)
$assistants = $pdo->query("
    SELECT id, full_name, position 
    FROM user_rh 
    WHERE position IN ('paramedic', 'co_asst', 'general_practitioner', 'specialist')
    AND is_active = 1
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
$saved = $_GET['saved'] ?? 0;
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Rekam Medis</h1>
        <p class="page-subtitle">Pencatatan rekam medis pasien</p>

        <!-- Flash Messages -->
        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="rekam_medis_action.php" enctype="multipart/form-data" x-data="medicalForm()">
            <?= csrfField() ?>

            <!-- CARD 1: DATA PASIEN -->
            <div class="card card-section mb-4">
                <div class="card-header">Data Pasien</div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Nama -->
                        <div class="form-group">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" name="patient_name" class="form-input"
                                placeholder="Nama lengkap pasien" required />
                        </div>

                        <!-- Pekerjaan -->
                        <div class="form-group">
                            <label class="form-label">Pekerjaan</label>
                            <input type="text" name="patient_occupation" class="form-input"
                                value="Civilian" placeholder="Pekerjaan pasien" />
                        </div>

                        <!-- Tanggal Lahir -->
                        <div class="form-group">
                            <label class="form-label">Tanggal Lahir <span class="text-danger">*</span></label>
                            <input type="date" name="patient_dob" class="form-input"
                                max="<?= date('Y-m-d') ?>" required />
                        </div>

                        <!-- No HP -->
                        <div class="form-group">
                            <label class="form-label">No HP</label>
                            <input type="text" name="patient_phone" class="form-input"
                                placeholder="Nomor HP pasien" />
                        </div>

                        <!-- Jenis Kelamin -->
                        <div class="form-group">
                            <label class="form-label">Jenis Kelamin <span class="text-danger">*</span></label>
                            <select name="patient_gender" class="form-input" required>
                                <option value="">Pilih</option>
                                <option value="Laki-laki">Laki-laki</option>
                                <option value="Perempuan">Perempuan</option>
                            </select>
                        </div>

                        <!-- Alamat -->
                        <div class="form-group">
                            <label class="form-label">Alamat</label>
                            <input type="text" name="patient_address" class="form-input"
                                value="INDONESIA" placeholder="Alamat pasien" />
                        </div>

                        <!-- Status -->
                        <div class="form-group md:col-span-2">
                            <label class="form-label">Status</label>
                            <input type="text" name="patient_status" class="form-input"
                                placeholder="Status pasien (opsional)" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 2: UPLOAD DOKUMEN -->
            <div class="card card-section mb-4">
                <div class="card-header">Upload Dokumen</div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- KTP (WAJIB) -->
                        <div>
                            <label class="form-label">KTP <span class="text-danger">*</span></label>
                            <div class="file-upload-wrapper">
                                <input type="file" id="ktp_file" name="ktp_file"
                                    accept="image/png,image/jpeg" hidden required
                                    @change="previewImage($event, 'ktp_preview')" />
                                <label for="ktp_file" class="file-upload-label">
                                    <div class="preview-container h-48 flex items-center justify-center bg-gray-50 rounded border border-gray-200"
                                        id="ktp_preview">
                                        <span class="text-gray-400 text-sm">Belum ada file</span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <span class="btn-secondary btn-sm">Pilih File / Ambil Foto</span>
                                    </div>
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Format: JPG/PNG, Max: 5MB (auto compress)</p>
                            </div>
                        </div>

                        <!-- MRI (OPSIONAL) -->
                        <div>
                            <label class="form-label">Foto MRI (Opsional)</label>
                            <div class="file-upload-wrapper">
                                <input type="file" id="mri_file" name="mri_file"
                                    accept="image/png,image/jpeg" hidden
                                    @change="previewImage($event, 'mri_preview')" />
                                <label for="mri_file" class="file-upload-label">
                                    <div class="preview-container h-48 flex items-center justify-center bg-gray-50 rounded border border-gray-200"
                                        id="mri_preview">
                                        <span class="text-gray-400 text-sm">Belum ada file</span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <span class="btn-secondary btn-sm">Pilih File / Ambil Foto</span>
                                    </div>
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Format: JPG/PNG, Max: 5MB (auto compress)</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 3: HASIL REKAM MEDIS (HTML EDITOR) -->
            <div class="card card-section mb-4">
                <div class="card-header">Hasil Rekam Medis</div>
                <div class="card-body">
                    <p class="text-sm text-gray-600 mb-2">
                        Edit hasil rekam medis di bawah. Template sudah tersedia, tinggal edit bagian yang bertanda <code>[...]</code>.
                    </p>
                    <div id="editor-container" class="min-h-[500px]"></div>
                    <textarea name="medical_result_html" id="medical_result_html" hidden></textarea>
                </div>
            </div>

            <!-- CARD 4: TIM MEDIS & OPERASI -->
            <div class="card card-section mb-4">
                <div class="card-header">Tim Medis & Operasi</div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Dokter DPJP -->
                        <div class="form-group">
                            <label class="form-label">Dokter DPJP <span class="text-danger">*</span></label>
                            <select name="doctor_id" class="form-input" required>
                                <option value="">Pilih Dokter</option>
                                <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?= $doctor['id'] ?>">
                                        <?= htmlspecialchars($doctor['full_name']) ?>
                                        (<?= htmlspecialchars($doctor['position']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Minimal jabatan: Co.Ast ke atas</p>
                        </div>

                        <!-- Jenis Operasi -->
                        <div class="form-group">
                            <label class="form-label">Jenis Operasi <span class="text-danger">*</span></label>
                            <div class="flex gap-4 mt-2">
                                <label class="radio-label flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="operasi_type" value="minor" checked
                                        class="w-4 h-4 text-primary" />
                                    <span>Minor (Kecil)</span>
                                </label>
                                <label class="radio-label flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="operasi_type" value="major"
                                        class="w-4 h-4 text-primary" />
                                    <span>Mayor (Besar)</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Asisten (Multiple) -->
                    <div class="mt-4">
                        <label class="form-label">Asisten Operasi</label>
                        <div id="assistants-container">
                            <!-- Default 2 asisten -->
                            <div class="assistant-row grid grid-cols-12 gap-2 mb-2">
                                <div class="col-span-11">
                                    <select name="assistant_ids[]" class="form-input assistant-select">
                                        <option value="">Pilih Asisten 1</option>
                                        <?php foreach ($assistants as $assistant): ?>
                                            <option value="<?= $assistant['id'] ?>">
                                                <?= htmlspecialchars($assistant['full_name']) ?>
                                                (<?= htmlspecialchars($assistant['position']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-span-1 flex items-center">
                                    <span class="text-gray-400 text-sm">#1</span>
                                </div>
                            </div>
                            <div class="assistant-row grid grid-cols-12 gap-2 mb-2">
                                <div class="col-span-11">
                                    <select name="assistant_ids[]" class="form-input assistant-select">
                                        <option value="">Pilih Asisten 2</option>
                                        <?php foreach ($assistants as $assistant): ?>
                                            <option value="<?= $assistant['id'] ?>">
                                                <?= htmlspecialchars($assistant['full_name']) ?>
                                                (<?= htmlspecialchars($assistant['position']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-span-1 flex items-center">
                                    <button type="button" onclick="removeAssistant(this)" class="text-red-500 hover:text-red-700" title="Hapus">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" onclick="addAssistant()" class="btn-secondary btn-sm mt-2">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Tambah Asisten
                        </button>
                        <p class="text-xs text-gray-500 mt-1">Minimal jabatan: Paramedic ke atas</p>
                    </div>
                </div>
            </div>

            <!-- ACTION BUTTONS -->
            <div class="flex justify-between items-center mt-6">
                <button type="button" onclick="clearLocalStorageManual()" class="btn-error">
                    <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Hapus Draft Tersimpan
                </button>
                <div class="flex gap-3">
                    <a href="rekam_medis_list.php" class="btn-secondary">Batal</a>
                    <button type="submit" class="btn-primary">
                        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                        </svg>
                        Simpan Rekam Medis
                    </button>
                </div>
            </div>
        </form>
    </div>
</section>

<!-- Quill.js CDN -->
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<script>
    // Medical template - langsung ditampilkan di editor
    const medicalTemplate = `
<h1 style="text-align: center;"><strong>LAPORAN REKAM MEDIS</strong></h1>

<h2><strong>INFORMASI WAKTU</strong></h2>

<p><strong>RUANG PERAWATAN:</strong> [ISI RUANG, contoh: IGD → Ruang Operasi → ICU]</p>

<h2><strong>DIAGNOSIS</strong></h2>
<ul>
    <li>[Diagnosis 1 - contoh: Penetrating Traumatic Brain Injury akibat luka tembak pada kepala]</li>
    <li>[Diagnosis 2 - contoh: Intracranial Hemorrhage (perdarahan intrakranial)]</li>
    <li>[Diagnosis 3 - contoh: Retained intracranial bullet (proyektil peluru masih tertanam pada jaringan otak)]</li>
    <li>[Diagnosis 4 - contoh: Edema serebri berat]</li>
    <li>[Diagnosis 5 - contoh: Peningkatan tekanan intrakranial]</li>
</ul>

<h2><strong>INDIKASI OPERASI</strong></h2>
<ul>
    <li>[Indikasi 1 - contoh: Ditemukan proyektil peluru yang masih tertanam pada jaringan otak berdasarkan CT-Scan dan MRI]</li>
    <li>[Indikasi 2 - contoh: Terdapat perdarahan intrakranial aktif]</li>
    <li>[Indikasi 3 - contoh: Terjadi peningkatan tekanan intrakranial]</li>
    <li>[Indikasi 4 - contoh: Ditemukan edema serebri luas yang menekan jaringan otak]</li>
</ul>

<p>Berdasarkan kondisi tersebut diputuskan untuk melakukan tindakan bedah darurat.</p>

<h2><strong>JENIS OPERASI</strong></h2>
<p><strong>[NAMA OPERASI, contoh: Emergency Craniotomy with Hematoma Evacuation and Bullet Extraction]</strong></p>
<p><em>([Deskripsi singkat operasi, contoh: Tindakan pembukaan tulang kranium untuk mengakses jaringan otak, melakukan evakuasi hematoma, serta ekstraksi proyektil peluru])</em></p>

<h2><strong>JENIS ANESTESI</strong></h2>
<p>General Anesthesia / Local Anesthesia / Regional Anesthesia</p>
<p><strong>Obat yang digunakan:</strong></p>
<ul>
    <li>[Obat 1, contoh: Propofol]</li>
    <li>[Obat 2, contoh: Fentanyl]</li>
    <li>[Obat 3, contoh: Rocuronium]</li>
    <li>[Obat 4, contoh: Sevoflurane]</li>
</ul>

<h2><strong>ANAMNESIS SINGKAT</strong></h2>
<p>[Ceritakan riwayat pasien, keluhan utama, dan pemeriksaan awal. Contoh: Pasien datang ke Instalasi Gawat Darurat dengan kondisi luka tembak pada kepala. Pasien ditemukan dengan penurunan kesadaran, perdarahan pada area kepala, serta tanda trauma penetrasi kranium.]</p>

<h2><strong>STATUS LOKALIS PRA OPERASI</strong></h2>
<p><strong>Kepala:</strong></p>
<ul>
    <li>[Temuan 1, contoh: Luka penetrasi pada regio temporoparietal]</li>
    <li>[Temuan 2, contoh: Perdarahan lokal]</li>
    <li>[Temuan 3, contoh: Pembengkakan jaringan sekitar luka]</li>
    <li>[Temuan 4, contoh: Nyeri tekan pada area trauma]</li>
</ul>

<p><strong>Status Neurologis:</strong></p>
<ul>
    <li>[Temuan neurologis 1, contoh: Penurunan kesadaran]</li>
    <li>[Temuan neurologis 2, contoh: Respon motorik terbatas]</li>
    <li>[Temuan neurologis 3, contoh: Refleks pupil melambat namun masih reaktif]</li>
</ul>

<h2><strong>TANDA TANDA VITAL (TTV) PRA OPERASI</strong></h2>
<table border="1" cellpadding="5" style="width: 100%; border-collapse: collapse;">
    <tr style="background-color: #f0f0f0;">
        <td><strong>Tekanan Darah</strong></td>
        <td>___ / ___ mmHg (Normal: 120/80 mmHg)</td>
    </tr>
    <tr>
        <td><strong>Nadi</strong></td>
        <td>___ x / menit (Normal: 60-100 x/menit)</td>
    </tr>
    <tr>
        <td><strong>Respirasi</strong></td>
        <td>___ x / menit (Normal: 12-20 x/menit)</td>
    </tr>
    <tr>
        <td><strong>Suhu Tubuh</strong></td>
        <td>___ °C (Normal: 36.5-37.5°C)</td>
    </tr>
    <tr>
        <td><strong>Saturasi O₂</strong></td>
        <td>___ % (Normal: 95-100%)</td>
    </tr>
    <tr>
        <td><strong>Tinggi Badan</strong></td>
        <td>___ cm</td>
    </tr>
    <tr>
        <td><strong>Berat Badan</strong></td>
        <td>___ kg</td>
    </tr>
</table>

<h2><strong>STATUS NEUROLOGIS</strong></h2>
<p><strong>GCS (Glasgow Coma Scale):</strong> ___ (E___ V___ M___)</p>
<ul>
    <li><strong>E (Eye Opening):</strong> E___ - [Contoh: E2 = Membuka mata terhadap nyeri]</li>
    <li><strong>V (Verbal Response):</strong> V___ - [Contoh: V2 = Suara tidak jelas]</li>
    <li><strong>M (Motor Response):</strong> M___ - [Contoh: M3 = Fleksi abnormal terhadap nyeri]</li>
</ul>

<h2><strong>LAPORAN TINDAKAN OPERASI</strong></h2>

<p><strong>A. Tahap Persiapan</strong></p>
<p>[Deskripsikan persiapan pasien. Contoh: Pasien diposisikan supine dengan kepala difiksasi menggunakan Mayfield head fixation system. Dilakukan pencukuran area kepala, antisepsis menggunakan povidone iodine dan chlorhexidine, pemasangan draping steril.]</p>

<p><strong>B. Tahap Operasi</strong></p>
<p>[Deskripsikan langkah-langkah operasi secara detail. Contoh: Dilakukan insisi kulit pada regio temporoparietal mengikuti jalur trauma proyektil. Lapisan yang dibuka: kulit kepala, subkutis, galea aponeurotica, periosteum. Dilakukan pembuatan burr hole menggunakan drill bedah saraf. Burr hole dihubungkan menggunakan craniotome hingga terbentuk bone flap yang kemudian diangkat untuk membuka akses ke dura mater.]</p>

<p><strong>C. Hemostasis</strong></p>
<p>[Deskripsikan kontrol perdarahan. Contoh: Dilakukan kontrol perdarahan menggunakan bipolar cautery, agen hemostatik (Surgicel dan Gelfoam).]</p>

<p><strong>D. Penutupan Operasi</strong></p>
<p>[Deskripsikan penutupan luka operasi. Contoh: Setelah upaya hemostasis maksimal, dura mater dijahit kembali, bone flap dipasang kembali, tulang difiksasi menggunakan plate dan screw, jaringan lunak dan kulit dijahit bertahap. Luka operasi kemudian ditutup dengan balutan steril.]</p>

<h2><strong>HASIL OPERASI</strong></h2>
<ul>
    <li>[Hasil 1, contoh: Hematoma intrakranial berhasil dievakuasi]</li>
    <li>[Hasil 2, contoh: Proyektil peluru berhasil diangkat dari jaringan otak]</li>
    <li>[Hasil 3, contoh: Ditemukan kerusakan jaringan otak luas akibat trauma penetrasi]</li>
    <li>[Hasil 4, contoh: Pasien meninggal dunia akibat pendarahan massive]</li>
</ul>

<h2><strong>STATUS PASCA OPERASI (IMMEDIATE POST OP)</strong></h2>
<p><strong>Status Umum:</strong> [Baik / Cukup / Kritis / Meninggal]</p>

<h2><strong>TANDA TANDA VITAL PASCA OPERASI</strong></h2>
<table border="1" cellpadding="5" style="width: 100%; border-collapse: collapse;">
    <tr style="background-color: #f0f0f0;">
        <td><strong>Tekanan Darah</strong></td>
        <td>___ / ___ mmHg</td>
    </tr>
    <tr>
        <td><strong>Nadi</strong></td>
        <td>___ x / menit</td>
    </tr>
    <tr>
        <td><strong>Respirasi</strong></td>
        <td>___ x / menit</td>
    </tr>
    <tr>
        <td><strong>Suhu Tubuh</strong></td>
        <td>___ °C</td>
    </tr>
    <tr>
        <td><strong>Saturasi O₂</strong></td>
        <td>___ %</td>
    </tr>
</table>

<h2><strong>PROGNOSIS</strong></h2>
<p>[Prognosis: Dubia ad bonam / Dubia ad malam / Infaust]</p>
`;

    // Alpine.js component untuk form handling - MUST be global scope
    window.medicalForm = function() {
        return {
            init() {
                // Initialize file upload previews
                console.log('Medical form initialized');
            },

            previewImage(event, previewId) {
                const file = event.target.files[0];
                if (file) {
                    // Validate file type
                    if (!file.type.startsWith('image/')) {
                        alert('File harus berupa gambar (JPG/PNG)');
                        event.target.value = '';
                        return;
                    }

                    // Validate file size (5MB max)
                    if (file.size > 5 * 1024 * 1024) {
                        alert('Ukuran file maksimal 5MB');
                        event.target.value = '';
                        return;
                    }

                    // Show preview
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const previewEl = document.getElementById(previewId);
                        previewEl.innerHTML =
                            `<img src="${e.target.result}" class="max-h-full max-w-full rounded object-contain" />`;
                    };
                    reader.readAsDataURL(file);
                }
            }
        }
    };

    // Assistant counter
    let assistantCount = 2;

    // Add assistant row
    function addAssistant() {
        assistantCount++;
        const container = document.getElementById('assistants-container');
        const newRow = document.createElement('div');
        newRow.className = 'assistant-row grid grid-cols-12 gap-2 mb-2';
        newRow.innerHTML = `
        <div class="col-span-11">
            <select name="assistant_ids[]" class="form-input assistant-select">
                <option value="">Pilih Asisten ${assistantCount}</option>
                <?php foreach ($assistants as $assistant): ?>
                    <option value="<?= $assistant['id'] ?>">
                        <?= htmlspecialchars($assistant['full_name']) ?> 
                        (<?= htmlspecialchars($assistant['position']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-span-1 flex items-center">
            <button type="button" onclick="removeAssistant(this)" class="text-red-500 hover:text-red-700" title="Hapus">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
            </button>
        </div>
    `;
        container.appendChild(newRow);
    }

    // Remove assistant row
    function removeAssistant(button) {
        const row = button.closest('.assistant-row');
        row.remove();
        assistantCount--;
    }

    // Global quill variable
    window.quill = null;

    // Local Storage Auto-Save
    const STORAGE_KEY = 'rekam_medis_draft';
    const AUTO_SAVE_INTERVAL = 10000; // 10 detik

    // Save form to localStorage
    function saveToLocalStorage() {
        const formData = {
            patient_name: document.querySelector('[name="patient_name"]')?.value || '',
            patient_occupation: document.querySelector('[name="patient_occupation"]')?.value || '',
            patient_dob: document.querySelector('[name="patient_dob"]')?.value || '',
            patient_phone: document.querySelector('[name="patient_phone"]')?.value || '',
            patient_gender: document.querySelector('[name="patient_gender"]')?.value || '',
            patient_address: document.querySelector('[name="patient_address"]')?.value || '',
            patient_status: document.querySelector('[name="patient_status"]')?.value || '',
            medical_result_html: window.quill ? window.quill.root.innerHTML : '',
            doctor_id: document.querySelector('[name="doctor_id"]')?.value || '',
            operasi_type: document.querySelector('[name="operasi_type"]:checked')?.value || '',
            assistant_ids: Array.from(document.querySelectorAll('[name="assistant_ids[]"]:checked')).map(el => el.value),
            saved_at: new Date().toISOString()
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(formData));
        console.log('Form auto-saved at', new Date().toLocaleString());
    }

    // Load form from localStorage
    function loadFromLocalStorage() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (!saved) return false;

        try {
            const formData = JSON.parse(saved);

            // Check if saved data is too old (more than 7 days)
            const savedAt = new Date(formData.saved_at);
            const daysOld = (new Date() - savedAt) / (1000 * 60 * 60 * 24);
            if (daysOld > 7) {
                localStorage.removeItem(STORAGE_KEY);
                return false;
            }

            // Fill form fields
            if (formData.patient_name && document.querySelector('[name="patient_name"]')) {
                document.querySelector('[name="patient_name"]').value = formData.patient_name;
            }
            if (formData.patient_occupation && document.querySelector('[name="patient_occupation"]')) {
                document.querySelector('[name="patient_occupation"]').value = formData.patient_occupation;
            }
            if (formData.patient_dob && document.querySelector('[name="patient_dob"]')) {
                document.querySelector('[name="patient_dob"]').value = formData.patient_dob;
            }
            if (formData.patient_phone && document.querySelector('[name="patient_phone"]')) {
                document.querySelector('[name="patient_phone"]').value = formData.patient_phone;
            }
            if (formData.patient_gender && document.querySelector('[name="patient_gender"]')) {
                document.querySelector('[name="patient_gender"]').value = formData.patient_gender;
            }
            if (formData.patient_address && document.querySelector('[name="patient_address"]')) {
                document.querySelector('[name="patient_address"]').value = formData.patient_address;
            }
            if (formData.patient_status && document.querySelector('[name="patient_status"]')) {
                document.querySelector('[name="patient_status"]').value = formData.patient_status;
            }
            if (formData.medical_result_html && window.quill) {
                window.quill.root.innerHTML = formData.medical_result_html;
            }
            if (formData.doctor_id && document.querySelector('[name="doctor_id"]')) {
                document.querySelector('[name="doctor_id"]').value = formData.doctor_id;
            }
            if (formData.operasi_type && document.querySelector(`[name="operasi_type"][value="${formData.operasi_type}"]`)) {
                document.querySelector(`[name="operasi_type"][value="${formData.operasi_type}"]`).checked = true;
            }

            console.log('Form loaded from localStorage');
            showSavedStatus();
            return true;
        } catch (e) {
            console.error('Error loading from localStorage:', e);
            return false;
        }
    }

    // Show saved status indicator
    function showSavedStatus() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            const formData = JSON.parse(saved);
            const savedAt = new Date(formData.saved_at);
            const statusDiv = document.createElement('div');
            statusDiv.id = 'saved-status';
            statusDiv.className = 'fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded shadow-lg text-sm';
            statusDiv.innerHTML = '✓ Data tersimpan: ' + savedAt.toLocaleString('id-ID');

            // Remove existing status if any
            const existing = document.getElementById('saved-status');
            if (existing) existing.remove();

            document.body.appendChild(statusDiv);

            // Auto-hide after 5 seconds
            setTimeout(() => {
                if (statusDiv.parentNode) statusDiv.remove();
            }, 5000);
        }
    }

    // Clear localStorage manually
    function clearLocalStorageManual() {
        if (confirm('Hapus data draft yang tersimpan?')) {
            localStorage.removeItem(STORAGE_KEY);
            console.log('LocalStorage cleared manually');

            // Clear form fields manually
            document.querySelector('[name="patient_name"]').value = '';
            document.querySelector('[name="patient_occupation"]').value = 'Civilian';
            document.querySelector('[name="patient_dob"]').value = '';
            document.querySelector('[name="patient_phone"]').value = '';
            document.querySelector('[name="patient_gender"]').value = '';
            document.querySelector('[name="patient_address"]').value = 'INDONESIA';
            document.querySelector('[name="patient_status"]').value = '';
            document.querySelector('[name="doctor_id"]').value = '';

            // Reset radio buttons
            document.querySelector('[name="operasi_type"][value="minor"]').checked = true;

            // Reset Quill editor to template
            if (window.quill) {
                window.quill.clipboard.dangerouslyPasteHTML(medicalTemplate);
            }

            // Clear assistant selections
            document.querySelectorAll('[name="assistant_ids[]"]').forEach(select => {
                select.value = '';
            });

            // Show success message
            alert('✓ Data draft berhasil dihapus. Form sudah dikosongkan.');
        }
    }

    // Initialize Quill Editor when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // Check if this is after successful save - clear localStorage immediately
        <?php if ($saved): ?>
            localStorage.removeItem(STORAGE_KEY);
            console.log('LocalStorage cleared after successful save');
        <?php endif; ?>

        // Initialize Quill with template as default content
        window.quill = new Quill('#editor-container', {
            theme: 'snow',
            placeholder: 'Tulis hasil rekam medis di sini...',
            modules: {
                toolbar: [
                    [{
                        'header': [1, 2, 3, 4, 5, 6, false]
                    }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{
                        'list': 'ordered'
                    }, {
                        'list': 'bullet'
                    }],
                    [{
                        'align': []
                    }],
                    [{
                        'color': []
                    }, {
                        'background': []
                    }],
                    ['link'],
                    ['clean']
                ]
            }
        });

        // Set template as default content
        window.quill.clipboard.dangerouslyPasteHTML(medicalTemplate);

        // Auto-load from localStorage (no confirm dialog)
        loadFromLocalStorage();

        // Auto-save interval
        setInterval(saveToLocalStorage, AUTO_SAVE_INTERVAL);

        // Save on input change
        document.querySelector('form').addEventListener('input', function() {
            saveToLocalStorage();
        });

        // Save quill content on change
        window.quill.on('text-change', function() {
            saveToLocalStorage();
        });

        // Sync content to textarea before form submit
        document.querySelector('form').addEventListener('submit', function() {
            const htmlContent = window.quill.root.innerHTML;
            document.getElementById('medical_result_html').value = htmlContent;

            // Validate not empty
            if (htmlContent === '<p><br></p>' || htmlContent.trim() === '') {
                alert('Hasil rekam medis wajib diisi!');
                event.preventDefault();
                return false;
            }

            // Clear localStorage BEFORE submit to prevent re-save
            clearLocalStorage();
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>