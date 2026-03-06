<?php
// TIDAK ADA SESSION
// TIDAK ADA AUTH
// HANYA HTML + FORM
require_once __DIR__ . '/../assets/design/ui/icon.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Calon Medis - Roxwood Hospital</title>

    <link rel="stylesheet" href="/assets/vendor/photoswipe/photoswipe.css">
    <link rel="stylesheet" href="/assets/design/tailwind/build.css">
</head>

<body class="min-h-screen bg-gradient-to-br from-slate-950 via-slate-900 to-sky-900">

    <div class="min-h-screen px-4 py-10">
        <div class="mx-auto flex w-full max-w-5xl items-start justify-center">
            <div class="w-full max-w-[920px]">
                <div class="rounded-3xl border border-white/60 bg-white/85 p-6 shadow-modal backdrop-blur">

                    <div class="mb-6 flex flex-col items-center gap-3 text-center">
                        <img src="/assets/logo.png" alt="Logo Roxwood Hospital" class="h-14 w-14 rounded-2xl bg-white object-contain p-2.5 shadow-soft">
                        <div>
                            <div class="text-sm font-extrabold tracking-wide text-slate-900">Roxwood Hospital</div>
                            <div class="text-xs font-semibold text-slate-600">Pendaftaran Calon Medis</div>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        Lengkapi data dengan jujur. Setelah mengirim, Anda akan diarahkan ke form pertanyaan.
                    </div>

                    <form action="recruitment_submit.php" method="post" enctype="multipart/form-data" class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="form-group">
                                <label class="text-sm font-semibold text-slate-900">Nama IC</label>
                                <input type="text" name="ic_name" required>
                            </div>

                            <div class="form-group">
                                <label class="text-sm font-semibold text-slate-900">Umur OOC</label>
                                <input type="number" name="ooc_age" required>
                            </div>

                            <div class="form-group md:col-span-2">
                                <label class="text-sm font-semibold text-slate-900">Nomor Telepon IC</label>
                                <input type="text" name="ic_phone" required>
                            </div>
                        </div>

                        <div class="section-form-title">Pengalaman dan Komitmen</div>

                        <div class="form-group">
                            <label class="text-sm font-semibold text-slate-900">Pengalaman Medis di Server Lain</label>
                            <small class="hint-warning">Sebutkan server dan posisi terakhir Anda. Jika tidak ada, tulis "-".</small>
                            <textarea name="medical_experience" rows="3" required></textarea>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="form-group">
                                <label class="text-sm font-semibold text-slate-900">Sudah Berapa Lama di Kota IME</label>
                                <input type="text" name="city_duration" required>
                            </div>

                            <div class="form-group">
                                <label class="text-sm font-semibold text-slate-900">Jam Biasanya Online</label>
                                <input type="text" name="online_schedule" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="text-sm font-semibold text-slate-900">Tanggung Jawab di Kota Lain</label>
                            <small class="hint-warning">Contoh: EMS, Government, atau instansi lain. Jika tidak ada, tulis "-".</small>
                            <textarea name="other_city_responsibility" rows="2" required></textarea>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="form-group">
                                <label class="text-sm font-semibold text-slate-900">Bersedia Mengikuti Medical Academy?</label>
                                <select name="academy_ready" required>
                                    <option value="">-- Pilih Jawaban --</option>
                                    <option value="ya">Ya</option>
                                    <option value="tidak">Tidak</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="text-sm font-semibold text-slate-900">Siap Mengikuti Aturan dan Etika</label>
                                <select name="rule_commitment" required>
                                    <option value="">-- Pilih Jawaban --</option>
                                    <option value="ya">Ya</option>
                                    <option value="tidak">Tidak</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="text-sm font-semibold text-slate-900">Di kisaran berapa lama Anda dapat duty di Roxwood Hospital</label>
                            <small class="hint-info">Contoh: 2-4 jam per hari, fleksibel, atau jadwal tertentu.</small>
                            <input type="text" name="duty_duration" required>
                        </div>

                        <div class="section-form-title">Motivasi</div>

                        <div class="form-group">
                            <label class="text-sm font-semibold text-slate-900">Alasan Bergabung dengan Roxwood Hospital</label>
                            <textarea name="motivation" rows="3" required></textarea>
                        </div>

                        <div class="form-group">
                            <label class="text-sm font-semibold text-slate-900">Hal Terpenting dalam Bekerja di Rumah Sakit</label>
                            <textarea name="work_principle" rows="3" required></textarea>
                        </div>

                        <div class="section-form-title">Lampiran</div>

                        <div class="grid gap-4 md:grid-cols-3">
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
	                                            <small>JPG</small>
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
	                                            <small>JPG</small>
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
	                                            <small>JPG</small>
	                                        </span>
	                                    </label>
	                                    <input type="file" id="simFile" name="sim" accept=".jpg,.jpeg,image/jpeg" class="sr-only">
	                                    <div class="file-selected-name" data-for="simFile"></div>
	                                    <img id="thumbSim" class="hidden mt-3 h-28 w-full rounded-2xl border border-slate-200 object-cover identity-photo cursor-zoom-in" alt="Pratinjau SIM">
	                                </div>
	                            </div>
                        </div>

                        <div class="form-submit-wrapper">
                            <button type="submit" class="btn-success w-full justify-center">
                                <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                                <span>Kirim Pendaftaran</span>
                            </button>
                        </div>
                    </form>

                    <small class="mt-3 block text-center text-xs text-slate-600">
                        Setelah mendaftar, silakan menunggu informasi lanjutan dari tim rekrutmen.
                    </small>
                </div>
            </div>
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
                const nameBox = document.querySelector('.file-selected-name[data-for=\"' + inputId + '\"]');
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
	                        try { URL.revokeObjectURL(lastUrl); } catch (_) {}
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

	            // Fallback for mobile browsers: ensure clicking the styled label triggers file picker.
	            document.querySelectorAll('label.file-upload-label[for]').forEach(lbl => {
	                lbl.addEventListener('click', (e) => {
	                    // Prevent double-open: default <label for="..."> already triggers the input click in most browsers.
	                    // We cancel the default and trigger it ourselves once for consistent behavior.
	                    try { e.preventDefault(); } catch (_) {}
	                    const id = lbl.getAttribute('for');
	                    const input = id ? document.getElementById(id) : null;
	                    if (!input) return;
	                    try { input.click(); } catch (_) {}
	                });
	            });
	        })();
	    </script>
</body>

</html>
