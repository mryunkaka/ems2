<?php
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';

$profile = ems_recruitment_profile('medical_candidate');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($profile['title']) ?> - Roxwood Hospital</title>

    <link rel="stylesheet" href="/assets/vendor/photoswipe/photoswipe.css">
    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
</head>

<body>
    <div class="public-shell">
        <div class="public-layout">
            <aside class="public-panel public-panel-hero public-sticky">
                <div class="public-brand">
                    <img src="/assets/logo.png" alt="Logo Roxwood Hospital" class="public-brand-logo">
                    <div class="public-brand-text">
                        <span class="public-kicker">Recruitment Portal</span>
                        <strong class="text-lg font-bold text-white">Roxwood Hospital</strong>
                        <span class="meta-text">Emergency Medical System</span>
                    </div>
                </div>

                <h1 class="public-heading"><?= htmlspecialchars($profile['title']) ?></h1>
                <p class="public-copy">
                    <?= htmlspecialchars($profile['description']) ?>
                </p>

                <div class="alert alert-info mt-5 mb-0 border-white/15 bg-white/10 text-slate-100">
                    Pastikan dokumen yang diunggah terbaca dengan jelas dan menggunakan format JPG.
                </div>

                <div class="public-feature-list">
                    <div class="public-feature-item">
                        <span class="public-feature-title">Tahap 1</span>
                        Isi identitas, pengalaman, dan komitmen duty.
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Tahap 2</span>
                        Unggah dokumen pendukung untuk verifikasi awal.
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Tahap 3</span>
                        Lanjut ke form pertanyaan setelah data tersimpan.
                    </div>
                </div>

                <div class="card mt-5 mb-0 border-white/10 bg-white/10 text-white shadow-none">
                    <div class="card-header border-white/10 pb-3 text-white">
                        <?= ems_icon('shield-check', 'h-5 w-5') ?>
                        <span>Catatan Penting</span>
                    </div>
                    <div class="space-y-3 text-sm leading-6 text-slate-200">
                        <p>Data yang Anda kirim digunakan hanya untuk proses seleksi internal tim rekrutmen.</p>
                        <p>Jika belum memiliki pengalaman medis, tulis apa adanya. Yang dinilai bukan hanya pengalaman, tetapi juga komitmen dan kesiapan belajar.</p>
                    </div>
                </div>
            </aside>

            <main class="public-panel">
                <div class="public-form-header">
                    <div>
                        <h2 class="public-form-title">Formulir Kandidat Baru</h2>
                        <p class="public-form-subtitle">Isi seluruh kolom wajib sebelum mengirim pendaftaran.</p>
                    </div>
                    <div class="badge-muted"><?= htmlspecialchars($profile['badge']) ?></div>
                </div>

                <form action="recruitment_submit.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="recruitment_type" value="<?= htmlspecialchars($profile['type']) ?>">
                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('identification', 'h-5 w-5') ?>
                            <span>Identitas Dasar</span>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="ic_name" class="text-sm font-semibold text-slate-900">Nama IC</label>
                                <input type="text" id="ic_name" name="ic_name" placeholder="Masukkan nama IC" required>
                            </div>

                            <div class="form-group">
                                <label for="citizen_id" class="text-sm font-semibold text-slate-900">Citizen ID</label>
                                <input type="text" id="citizen_id" name="citizen_id" placeholder="Masukkan citizen ID" required>
                            </div>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="ooc_age" class="text-sm font-semibold text-slate-900">Umur OOC</label>
                                <input type="number" id="ooc_age" name="ooc_age" min="1" placeholder="Masukkan umur OOC" required>
                            </div>

                            <div class="form-group">
                                <label for="jenis_kelamin" class="text-sm font-semibold text-slate-900">Jenis Kelamin</label>
                                <select id="jenis_kelamin" name="jenis_kelamin" required>
                                    <option value="">-- Pilih Jenis Kelamin --</option>
                                    <option value="Laki-laki">Laki-laki</option>
                                    <option value="Perempuan">Perempuan</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="ic_phone" class="text-sm font-semibold text-slate-900">Nomor Telepon IC</label>
                            <input type="text" id="ic_phone" name="ic_phone" placeholder="Contoh: 5242333" required>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('briefcase', 'h-5 w-5') ?>
                            <span>Pengalaman dan Komitmen</span>
                        </div>

                        <div class="form-group">
                            <label for="medical_experience" class="text-sm font-semibold text-slate-900">Pengalaman Medis di Server Lain</label>
                            <small class="hint-warning">Sebutkan server dan posisi terakhir. Jika belum ada, tulis "-".</small>
                            <textarea id="medical_experience" name="medical_experience" rows="3" placeholder="Tulis pengalaman medis Anda" required></textarea>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="city_duration" class="text-sm font-semibold text-slate-900">Sudah Berapa Lama di Kota IME</label>
                                <input type="text" id="city_duration" name="city_duration" placeholder="Contoh: 2 minggu" required>
                            </div>

                            <div class="form-group">
                                <label for="online_schedule" class="text-sm font-semibold text-slate-900">Jam Biasanya Online</label>
                                <input type="text" id="online_schedule" name="online_schedule" placeholder="Contoh: 19.00 - 23.00 WIB" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="other_city_responsibility" class="text-sm font-semibold text-slate-900">Tanggung Jawab di Kota Lain</label>
                            <small class="hint-warning">Contoh: EMS, Government, atau instansi lain. Jika tidak ada, tulis "-".</small>
                            <textarea id="other_city_responsibility" name="other_city_responsibility" rows="2" placeholder="Tulis tanggung jawab lain yang masih aktif" required></textarea>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="academy_ready" class="text-sm font-semibold text-slate-900">Bersedia Mengikuti Medical Academy?</label>
                                <select id="academy_ready" name="academy_ready" required>
                                    <option value="">-- Pilih Jawaban --</option>
                                    <option value="ya">Ya</option>
                                    <option value="tidak">Tidak</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="rule_commitment" class="text-sm font-semibold text-slate-900">Siap Mengikuti Aturan dan Etika</label>
                                <select id="rule_commitment" name="rule_commitment" required>
                                    <option value="">-- Pilih Jawaban --</option>
                                    <option value="ya">Ya</option>
                                    <option value="tidak">Tidak</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="duty_duration" class="text-sm font-semibold text-slate-900">Perkiraan Waktu Duty di Roxwood Hospital</label>
                            <small class="hint-info">Contoh: 2-4 jam per hari, fleksibel, atau jadwal tertentu.</small>
                            <input type="text" id="duty_duration" name="duty_duration" placeholder="Tulis estimasi durasi duty" required>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('chat-bubble-left-right', 'h-5 w-5') ?>
                            <span>Motivasi</span>
                        </div>

                        <div class="form-group">
                            <label for="motivation" class="text-sm font-semibold text-slate-900">Alasan Bergabung dengan Roxwood Hospital</label>
                            <textarea id="motivation" name="motivation" rows="3" placeholder="Jelaskan alasan Anda ingin bergabung" required></textarea>
                        </div>

                        <div class="form-group">
                            <label for="work_principle" class="text-sm font-semibold text-slate-900">Hal Terpenting dalam Bekerja di Rumah Sakit</label>
                            <textarea id="work_principle" name="work_principle" rows="3" placeholder="Jelaskan prinsip kerja yang Anda pegang" required></textarea>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('paper-clip', 'h-5 w-5') ?>
                            <span>Lampiran Dokumen</span>
                        </div>

                        <p class="section-intro">Unggah dokumen dalam format JPG dengan gambar yang jelas dan tidak terpotong.</p>

                        <div class="grid gap-4 lg:grid-cols-3">
                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <label class="text-sm font-semibold text-slate-900">KTP IC</label>
                                    <span class="badge-muted-mini">Wajib</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="ktpIc" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>JPG / JPEG</small>
                                        </span>
                                    </label>
                                    <input type="file" id="ktpIc" name="ktp_ic" accept=".jpg,.jpeg,image/jpeg" class="sr-only" required>
                                    <div class="file-selected-name" data-for="ktpIc"></div>
                                    <img id="thumbKtpIc" class="hidden mt-3 h-28 w-full rounded-2xl border border-slate-200 object-cover identity-photo cursor-zoom-in" alt="Pratinjau KTP IC">
                                </div>
                            </div>

                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <label class="text-sm font-semibold text-slate-900">SKB</label>
                                    <span class="badge-muted-mini">Wajib</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="skbFile" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>JPG / JPEG</small>
                                        </span>
                                    </label>
                                    <input type="file" id="skbFile" name="skb" accept=".jpg,.jpeg,image/jpeg" class="sr-only" required>
                                    <div class="file-selected-name" data-for="skbFile"></div>
                                    <img id="thumbSkb" class="hidden mt-3 h-28 w-full rounded-2xl border border-slate-200 object-cover identity-photo cursor-zoom-in" alt="Pratinjau SKB">
                                </div>
                            </div>

                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <label class="text-sm font-semibold text-slate-900">SIM</label>
                                    <span class="badge-muted-mini">Opsional</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="simFile" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>JPG / JPEG</small>
                                        </span>
                                    </label>
                                    <input type="file" id="simFile" name="sim" accept=".jpg,.jpeg,image/jpeg" class="sr-only">
                                    <div class="file-selected-name" data-for="simFile"></div>
                                    <img id="thumbSim" class="hidden mt-3 h-28 w-full rounded-2xl border border-slate-200 object-cover identity-photo cursor-zoom-in" alt="Pratinjau SIM">
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="card mb-0 bg-slate-50/80">
                        <div class="card-header">
                            <?= ems_icon('arrow-right-circle', 'h-5 w-5') ?>
                            <span>Finalisasi</span>
                        </div>
                        <p class="helper-note mb-4">
                            Setelah pendaftaran dikirim, Anda akan diarahkan ke tahapan pertanyaan lanjutan. Pastikan semua jawaban dan dokumen sudah benar.
                        </p>
                        <div class="form-submit-wrapper">
                            <button type="submit" class="btn-success w-full justify-center md:w-auto">
                                <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                                <span>Kirim Pendaftaran</span>
                            </button>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script src="/assets/vendor/photoswipe/photoswipe.umd.min.js"></script>
    <script src="/assets/vendor/photoswipe/photoswipe-lightbox.umd.min.js"></script>
    <script src="/assets/design/js/photoswipe-init.js"></script>

    <script>
        (function() {
            function setSelectedName(inputId) {
                const input = document.getElementById(inputId);
                if (!input) return;

                const nameBox = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
                if (!nameBox) return;

                input.addEventListener('change', function() {
                    const file = this.files && this.files[0] ? this.files[0] : null;
                    if (!file) {
                        nameBox.textContent = '';
                        nameBox.classList.add('hidden');
                        return;
                    }

                    nameBox.textContent = file.name || 'File dipilih';
                    nameBox.classList.remove('hidden');
                });
            }

            function setupThumb(inputId, imgId) {
                const input = document.getElementById(inputId);
                const img = document.getElementById(imgId);
                if (!input || !img) return;

                let lastUrl = '';
                input.addEventListener('change', function() {
                    const file = this.files && this.files[0] ? this.files[0] : null;
                    if (lastUrl) {
                        try {
                            URL.revokeObjectURL(lastUrl);
                        } catch (_) {}
                        lastUrl = '';
                    }

                    const mime = (file && file.type ? String(file.type) : '').toLowerCase();
                    if (!file || !mime.startsWith('image/')) {
                        img.classList.add('hidden');
                        img.removeAttribute('src');
                        return;
                    }

                    lastUrl = URL.createObjectURL(file);
                    img.src = lastUrl;
                    img.classList.remove('hidden');
                });
            }

            setSelectedName('ktpIc');
            setSelectedName('skbFile');
            setSelectedName('simFile');

            setupThumb('ktpIc', 'thumbKtpIc');
            setupThumb('skbFile', 'thumbSkb');
            setupThumb('simFile', 'thumbSim');

            document.querySelectorAll('label.file-upload-label[for]').forEach((label) => {
                label.addEventListener('click', function(event) {
                    try {
                        event.preventDefault();
                    } catch (_) {}

                    const id = label.getAttribute('for');
                    const input = id ? document.getElementById(id) : null;
                    if (!input) return;

                    try {
                        input.click();
                    } catch (_) {}
                });
            });
        })();
    </script>
</body>

</html>
