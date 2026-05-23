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
$mode = $medicalRecordMode ?? trim($_GET['mode'] ?? 'standard');
$isForensicPrivate = ($mode === 'forensic_private');
$hasJenisOperasiColumn = ems_column_exists($pdo, 'medical_records', 'jenis_operasi');

if ($isForensicPrivate) {
    ems_require_division_access(['Forensic'], '/dashboard/index.php');
}

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
$saved = $_GET['saved'] ?? 0;
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title"><?= $isForensicPrivate ? 'Rekam Medis Private' : 'Rekam Medis' ?></h1>
        <p class="page-subtitle"><?= $isForensicPrivate ? 'Pencatatan rekam medis private khusus division forensic' : 'Pencatatan rekam medis pasien' ?></p>

        <!-- Flash Messages -->
        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string)$message, 'info', 'Rekam Medis') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string)$error, 'error', 'Rekam Medis', 6800) ?>
        <?php endforeach; ?>

        <form method="POST" action="rekam_medis_action.php" enctype="multipart/form-data" x-data="medicalForm()">
            <?= csrfField() ?>
            <input type="hidden" name="visibility_scope" value="<?= $isForensicPrivate ? 'forensic_private' : 'standard' ?>">
            <input type="hidden" name="redirect_to" value="<?= $isForensicPrivate ? 'forensic_medical_records.php' : 'rekam_medis.php' ?>">
            <input type="hidden" name="mode" value="<?= $isForensicPrivate ? 'forensic_private' : 'standard' ?>">

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

                        <div class="form-group">
                            <label class="form-label">Citizen ID <span class="text-danger">*</span></label>
                            <input type="text" name="patient_citizen_id" class="form-input"
                                placeholder="Nomor identitas / citizen ID pasien" required />
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
                            <label class="form-label"><?= $isForensicPrivate ? 'KTP Pasien' : 'KTP' ?> <span class="text-danger">*</span></label>
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
                                <p class="text-xs text-gray-500 mt-1">Format: JPG/PNG, Max: 1MB per file</p>
                            </div>
                        </div>

                        <!-- FOTO PENDUKUNG -->
                        <div>
                            <label class="form-label">Foto MRI/CT Scan/USG/Dll<?= $isForensicPrivate ? '' : ' (Opsional)' ?><?= $isForensicPrivate ? ' <span class="text-danger">*</span>' : '' ?></label>
                            <div class="file-upload-wrapper">
                                <input type="file" id="supporting_image_files" name="supporting_image_files[]"
                                    accept="image/png,image/jpeg" hidden multiple <?= $isForensicPrivate ? 'required' : '' ?>
                                    @change="previewMultipleImages($event, 'supporting_images_preview')" />
                                <label for="supporting_image_files" class="file-upload-label">
                                    <div class="preview-container min-h-48 p-3 flex items-center justify-center bg-gray-50 rounded border border-gray-200"
                                        id="supporting_images_preview">
                                        <span class="text-gray-400 text-sm">Belum ada file</span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <span class="btn-secondary btn-sm">Pilih Beberapa File / Ambil Foto</span>
                                    </div>
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Format: JPG/PNG, bisa pilih banyak file, max <?= htmlspecialchars(emsUploadLimitLabel(), ENT_QUOTES, 'UTF-8') ?> per file</p>
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
                            <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="doctor" data-autocomplete-required>
                                <input type="text" class="form-input" data-user-autocomplete-input placeholder="Ketik nama dokter..." required>
                                <input type="hidden" name="doctor_id" data-user-autocomplete-hidden>
                                <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                            </div>
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

                        <div class="form-group md:col-span-2">
                            <label class="form-label">Nama / Jenis Operasi</label>
                            <input type="text" name="jenis_operasi" class="form-input"
                                placeholder="Contoh: Open Reduction Internal Fixation (ORIF) Distal Radius-Ulna Sinistra" />
                            <p class="text-xs text-gray-500 mt-1">
                                Dipakai untuk nama tindakan operasi pada laporan.
                                <?= $hasJenisOperasiColumn ? '' : ' Jalankan SQL `docs/sql/36_2026-05-15_medical_records_jenis_operasi.sql` agar field ini ikut tersimpan.' ?>
                            </p>
                        </div>
                    </div>

                    <!-- Asisten (Multiple) -->
                    <div class="mt-4">
                        <label class="form-label">Asisten Operasi <span class="text-danger">*</span></label>
                        <div id="assistants-container">
                            <!-- Default 2 asisten -->
                            <div class="assistant-row grid grid-cols-12 gap-2 mb-2">
                                <div class="col-span-11">
                                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="assistant" data-autocomplete-required>
                                        <input type="text" class="form-input assistant-select" data-user-autocomplete-input placeholder="Ketik nama asisten 1..." required>
                                        <input type="hidden" name="assistant_ids[]" data-user-autocomplete-hidden>
                                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                                    </div>
                                </div>
                                <div class="col-span-1 flex items-center">
                                    <span class="text-gray-400 text-sm">#1</span>
                                </div>
                            </div>
                            <div class="assistant-row grid grid-cols-12 gap-2 mb-2">
                                <div class="col-span-11">
                                    <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="assistant">
                                        <input type="text" class="form-input assistant-select" data-user-autocomplete-input placeholder="Ketik nama asisten 2...">
                                        <input type="hidden" name="assistant_ids[]" data-user-autocomplete-hidden>
                                        <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                                    </div>
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
                        <p class="text-xs text-gray-500 mt-1">Minimal 1 asisten wajib dipilih. Minimal jabatan: Paramedic ke atas.</p>
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
                    <a href="<?= $isForensicPrivate ? 'forensic_medical_records_list.php' : 'rekam_medis_list.php' ?>" class="btn-secondary">Batal</a>
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

<style>
#editor-container .ql-editor {
    min-height: 520px;
    line-height: 1.75;
    color: #0f172a;
}

#editor-container .ql-editor h1 {
    margin: 0 0 1.75rem;
    text-align: center;
    font-size: 2rem;
    font-weight: 800;
}

#editor-container .ql-editor h2 {
    margin: 2.4rem 0 0.9rem;
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: 0.01em;
}

#editor-container .ql-editor p {
    margin: 0.45rem 0;
}

#editor-container .ql-editor p + p {
    margin-top: 0.8rem;
}

#editor-container .ql-editor ul,
#editor-container .ql-editor ol {
    margin: 0.8rem 0 1rem;
    padding-left: 1.5rem;
}

#editor-container .ql-editor li + li {
    margin-top: 0.35rem;
}
</style>

<!-- Quill.js CDN -->
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<script>
    // Medical template - langsung ditampilkan di editor
    const medicalTemplate = `
<h1 style="text-align: center;"><strong>REKAM MEDIS : [NAMA JENIS OPERASI]</strong></h1>

<h2><strong>INFORMASI WAKTU</strong></h2>
<p><strong>RUANG PERAWATAN:</strong> [ISI RUANG, contoh: IGD → Radiologi → Ruang Operasi Orthopedi → Recovery Room]</p>

<p><br></p>

<h2><strong>DIAGNOSIS</strong></h2>
<ul>
    <li>[Diagnosis 1]</li>
    <li>[Diagnosis 2]</li>
    <li>[Diagnosis 3]</li>
    <li>[Diagnosis 4]</li>
    <li>[Diagnosis 5]</li>
</ul>

<p><br></p>

<h2><strong>INDIKASI OPERASI</strong></h2>
<ul>
    <li>[Indikasi 1]</li>
    <li>[Indikasi 2]</li>
    <li>[Indikasi 3]</li>
    <li>[Indikasi 4]</li>
</ul>
<p>Berdasarkan kondisi tersebut diputuskan untuk melakukan tindakan operasi [minor/mayor] dengan supervisi dokter jaga.</p>

<p><br></p>

<h2><strong>JENIS OPERASI</strong></h2>
<p><strong>[NAMA OPERASI]</strong></p>
<p>([Deskripsi singkat tindakan operasi])</p>

<p><br></p>

<h2><strong>JENIS ANESTESI</strong></h2>
<p>[General Anesthesia / Local Anesthesia / Regional Anesthesia]</p>
<p><strong>Obat yang digunakan:</strong></p>
<ul>
    <li>[Obat 1]</li>
    <li>[Obat 2]</li>
    <li>[Obat 3]</li>
    <li>[Obat 4]</li>
</ul>

<p><br></p>

<h2><strong>ANAMNESIS SINGKAT</strong></h2>
<p>[Ceritakan riwayat pasien, keluhan utama, dan pemeriksaan awal.]</p>

<p><br></p>

<h2><strong>STATUS LOKALIS PRA OPERASI</strong></h2>
<p><strong>[Area Pemeriksaan Lokal]</strong></p>
<ul>
    <li>[Temuan 1]</li>
    <li>[Temuan 2]</li>
    <li>[Temuan 3]</li>
    <li>[Temuan 4]</li>
</ul>

<p><strong>Status Neurovaskular / Neurologis:</strong></p>
<ul>
    <li>[Temuan 1]</li>
    <li>[Temuan 2]</li>
    <li>[Temuan 3]</li>
</ul>

<p><br></p>

<h2><strong>TANDA TANDA VITAL (TTV) PRA OPERASI</strong></h2>
<p><strong>Tekanan Darah:</strong> ___ / ___ mmHg</p>
<p><strong>Nadi:</strong> ___ x/menit</p>
<p><strong>Respirasi:</strong> ___ x/menit</p>
<p><strong>Suhu Tubuh:</strong> ___°C</p>
<p><strong>Saturasi O₂:</strong> ___%</p>
<p><strong>Tinggi Badan:</strong> ___ cm</p>
<p><strong>Berat Badan:</strong> ___ kg</p>

<p><br></p>

<h2><strong>STATUS NEUROLOGIS</strong></h2>
<p><strong>GCS (Glasgow Coma Scale):</strong> ___ (E___ V___ M___)</p>
<ul>
    <li><strong>E:</strong> [Keterangan]</li>
    <li><strong>V:</strong> [Keterangan]</li>
    <li><strong>M:</strong> [Keterangan]</li>
</ul>

<p><br></p>

<h2><strong>LAPORAN TINDAKAN OPERASI</strong></h2>
<p><strong>A. Tahap Persiapan</strong></p>
<p>[Deskripsikan persiapan pasien.]</p>

<p><strong>B. Tahap Operasi</strong></p>
<p>[Deskripsikan langkah-langkah operasi secara detail.]</p>

<p><strong>C. Hemostasis</strong></p>
<p>[Deskripsikan kontrol perdarahan.]</p>

<p><strong>D. Penutupan Operasi</strong></p>
<p>[Deskripsikan penutupan luka operasi.]</p>

<p><br></p>

<h2><strong>HASIL OPERASI</strong></h2>
<ul>
    <li>[Hasil 1]</li>
    <li>[Hasil 2]</li>
    <li>[Hasil 3]</li>
    <li>[Hasil 4]</li>
</ul>

<p><br></p>

<h2><strong>STATUS PASCA OPERASI (IMMEDIATE POST OP)</strong></h2>
<p><strong>Status Umum:</strong> [Baik / Cukup / Kritis / Meninggal]</p>

<p><br></p>

<h2><strong>TANDA TANDA VITAL PASCA OPERASI</strong></h2>
<p><strong>Tekanan Darah:</strong> ___ / ___ mmHg</p>
<p><strong>Nadi:</strong> ___ x/menit</p>
<p><strong>Respirasi:</strong> ___ x/menit</p>
<p><strong>Suhu Tubuh:</strong> ___°C</p>
<p><strong>Saturasi O₂:</strong> ___%</p>

<p><br></p>

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

                    // Validate file size (1MB max)
                    if (file.size > 5 * 1024 * 1024) {
                        alert('Ukuran file maksimal 1MB');
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
            },

            previewMultipleImages(event, previewId) {
                const files = Array.from(event.target.files || []);
                const previewEl = document.getElementById(previewId);
                if (!previewEl) {
                    return;
                }

                if (files.length === 0) {
                    previewEl.innerHTML = '<span class="text-gray-400 text-sm">Belum ada file</span>';
                    return;
                }

                for (const file of files) {
                    if (!file.type.startsWith('image/')) {
                        alert('Semua file harus berupa gambar (JPG/PNG)');
                        event.target.value = '';
                        previewEl.innerHTML = '<span class="text-gray-400 text-sm">Belum ada file</span>';
                        return;
                    }

                    if (file.size > 5 * 1024 * 1024) {
                        alert('Ukuran file maksimal 1MB');
                        event.target.value = '';
                        previewEl.innerHTML = '<span class="text-gray-400 text-sm">Belum ada file</span>';
                        return;
                    }
                }

                previewEl.innerHTML = '';
                const grid = document.createElement('div');
                grid.className = 'grid grid-cols-2 md:grid-cols-3 gap-3 w-full';

                files.forEach((file) => {
                    const item = document.createElement('div');
                    item.className = 'rounded border border-gray-200 bg-white p-2';

                    const image = document.createElement('img');
                    image.className = 'h-28 w-full rounded object-cover';

                    const caption = document.createElement('div');
                    caption.className = 'mt-2 text-xs text-slate-600 break-words';
                    caption.textContent = file.name;

                    item.appendChild(image);
                    item.appendChild(caption);
                    grid.appendChild(item);

                    const reader = new FileReader();
                    reader.onload = (e) => {
                        image.src = String(e.target.result || '');
                    };
                    reader.readAsDataURL(file);
                });

                previewEl.appendChild(grid);
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
            <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="assistant">
                <input type="text" class="form-input assistant-select" data-user-autocomplete-input placeholder="Ketik nama asisten ${assistantCount}...">
                <input type="hidden" name="assistant_ids[]" data-user-autocomplete-hidden>
                <div class="ems-suggestion-box" data-user-autocomplete-list></div>
            </div>
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
        if (window.emsInitUserAutocomplete) {
            window.emsInitUserAutocomplete(newRow);
        }
    }

    function syncMedicalOperationTitle() {
        const input = document.querySelector('[name="jenis_operasi"]');
        if (!input || !window.quill) {
            return;
        }

        const headingStrong = window.quill.root.querySelector('h1 strong');
        if (!headingStrong) {
            return;
        }

        const operationName = (input.value || '').trim();
        headingStrong.textContent = operationName !== ''
            ? `REKAM MEDIS : ${operationName}`
            : 'REKAM MEDIS : [NAMA JENIS OPERASI]';
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
            patient_citizen_id: document.querySelector('[name="patient_citizen_id"]')?.value || '',
            patient_occupation: document.querySelector('[name="patient_occupation"]')?.value || '',
            patient_dob: document.querySelector('[name="patient_dob"]')?.value || '',
            patient_phone: document.querySelector('[name="patient_phone"]')?.value || '',
            patient_gender: document.querySelector('[name="patient_gender"]')?.value || '',
            patient_address: document.querySelector('[name="patient_address"]')?.value || '',
            patient_status: document.querySelector('[name="patient_status"]')?.value || '',
            medical_result_html: window.quill ? window.quill.root.innerHTML : '',
            doctor_id: document.querySelector('[name="doctor_id"]')?.value || '',
            doctor_name: document.querySelector('[data-user-autocomplete-input][placeholder*="dokter"]')?.value || '',
            operasi_type: document.querySelector('[name="operasi_type"]:checked')?.value || '',
            jenis_operasi: document.querySelector('[name="jenis_operasi"]')?.value || '',
            assistant_ids: Array.from(document.querySelectorAll('[name="assistant_ids[]"]')).map(el => el.value || ''),
            assistant_names: Array.from(document.querySelectorAll('.assistant-select')).map(el => el.value || ''),
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
            if (formData.patient_citizen_id && document.querySelector('[name="patient_citizen_id"]')) {
                document.querySelector('[name="patient_citizen_id"]').value = formData.patient_citizen_id;
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
            if (formData.doctor_name && document.querySelector('[data-user-autocomplete-input][placeholder*="dokter"]')) {
                document.querySelector('[data-user-autocomplete-input][placeholder*="dokter"]').value = formData.doctor_name;
            }
            if (typeof formData.jenis_operasi === 'string' && document.querySelector('[name="jenis_operasi"]')) {
                document.querySelector('[name="jenis_operasi"]').value = formData.jenis_operasi;
            }
            if (Array.isArray(formData.assistant_ids)) {
                document.querySelectorAll('[name="assistant_ids[]"]').forEach((input, index) => {
                    input.value = formData.assistant_ids[index] || '';
                });
            }
            if (Array.isArray(formData.assistant_names)) {
                document.querySelectorAll('.assistant-select').forEach((input, index) => {
                    input.value = formData.assistant_names[index] || '';
                });
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

    function clearLocalStorage() {
        localStorage.removeItem(STORAGE_KEY);
    }

    // Clear localStorage manually
    function clearLocalStorageManual() {
        if (confirm('Hapus data draft yang tersimpan?')) {
            localStorage.removeItem(STORAGE_KEY);
            console.log('LocalStorage cleared manually');

            // Clear form fields manually
            document.querySelector('[name="patient_name"]').value = '';
            document.querySelector('[name="patient_citizen_id"]').value = '';
            document.querySelector('[name="patient_occupation"]').value = 'Civilian';
            document.querySelector('[name="patient_dob"]').value = '';
            document.querySelector('[name="patient_phone"]').value = '';
            document.querySelector('[name="patient_gender"]').value = '';
            document.querySelector('[name="patient_address"]').value = 'INDONESIA';
            document.querySelector('[name="patient_status"]').value = '';
            document.querySelector('[name="doctor_id"]').value = '';
            document.querySelector('[name="jenis_operasi"]').value = '';

            // Reset radio buttons
            document.querySelector('[name="operasi_type"][value="minor"]').checked = true;

            // Reset Quill editor to template
            if (window.quill) {
                window.quill.clipboard.dangerouslyPasteHTML(medicalTemplate);
                syncMedicalOperationTitle();
            }

            // Clear assistant selections
            document.querySelectorAll('[name="assistant_ids[]"]').forEach(select => {
                select.value = '';
            });
            document.querySelectorAll('.assistant-select').forEach(input => {
                input.value = '';
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
        if (window.emsInitUserAutocomplete) {
            window.emsInitUserAutocomplete(document);
        }

        // Auto-load from localStorage (no confirm dialog)
        loadFromLocalStorage();
        syncMedicalOperationTitle();

        const jenisOperasiInput = document.querySelector('[name="jenis_operasi"]');
        if (jenisOperasiInput) {
            jenisOperasiInput.addEventListener('input', function() {
                syncMedicalOperationTitle();
                saveToLocalStorage();
            });
        }

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
        document.querySelector('form').addEventListener('submit', function(event) {
            const csrfInput = this.querySelector('input[name="csrf_token"]');
            if (csrfInput && window.EMS_CSRF_TOKEN) {
                csrfInput.value = String(window.EMS_CSRF_TOKEN);
            }

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
