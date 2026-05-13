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
                    Pastikan dokumen yang diunggah terbaca dengan jelas dan menggunakan format gambar yang didukung.
                </div>

                <div class="alert alert-info mt-3 mb-0 border-white/15 bg-white/10 text-slate-100">
                    File gambar besar akan dikompres otomatis sebelum dikirim agar upload tetap lancar dan hasil di storage tetap tajam saat di-zoom.
                </div>

                <div class="card mt-5 mb-0 border-white/10 bg-white/10 text-white shadow-none">
                    <div class="card-header border-white/10 pb-3 text-white">
                        <?= ems_icon('clipboard-document-list', 'h-5 w-5') ?>
                        <span>Persyaratan Umum</span>
                    </div>
                    <div class="space-y-2 text-sm leading-6 text-slate-200">
                        <p>&#10003; Berusia minimal 17 tahun pada saat mendaftar,</p>
                        <p>&#10003; Tidak memiliki catatan kriminal, dan dibuktikan dengan SKB</p>
                        <p>&#10003; Calon Kandidat dinyatakan sehat dan siap melakukan interview, dengan melampirkan surat kesehatan &amp; surat psikologi</p>
                        <p>&#10003; Tidak sedang bergabung dengan Instansi dan tidak terlibat dengan fraksi manapun</p>
                        <p>&#10003; Jika memiliki kunci sebelumnya baik whitelist maupun fraksi harus mengikuti City Rules ke 21, yaitu harus menunggu masa pemutihan selama 14 hari setelah kunci dilepas</p>
                        <p>&#10003; Apabila pendaftar berasal dari instansi swasta, maka proses administrasi pendaftaran EMS baru tidak diwajibkan menunggu masa 14 (empat belas) hari sebagaimana City Rules, dan dapat diproses sesuai dengan kebijakan yang berlaku.</p>
                        <p>&#10003; Wajib pernah datang ke roxwood hospital untuk mengenal lingkungan &amp; fasilitas rumah sakit.</p>
                    </div>
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

                <form action="recruitment_submit.php" method="post" enctype="multipart/form-data" id="publicRecruitmentForm">
                    <input type="hidden" name="recruitment_type" value="<?= htmlspecialchars($profile['type']) ?>">
                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('identification', 'h-5 w-5') ?>
                            <span>Identitas Dasar</span>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="ic_name" class="text-sm font-semibold text-slate-900">Nama IC</label>
                                <input type="text" id="ic_name" name="ic_name" placeholder="Masukkan nama IC" autocomplete="off" required>
                            </div>

                            <div class="form-group">
                                <label for="citizen_id" class="text-sm font-semibold text-slate-900">Citizen ID</label>
                                <input type="text" id="citizen_id" name="citizen_id" placeholder="Masukkan citizen ID" autocomplete="off" required>
                            </div>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="ooc_age" class="text-sm font-semibold text-slate-900">Umur OOC</label>
                                <input type="number" id="ooc_age" name="ooc_age" min="1" placeholder="Masukkan umur OOC" autocomplete="off" required>
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
                            <input type="text" id="ic_phone" name="ic_phone" placeholder="Contoh: 5242333" autocomplete="off" required>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('briefcase', 'h-5 w-5') ?>
                            <span>Pengalaman dan Komitmen</span>
                        </div>

                        <div class="form-group">
                            <label for="medical_experience" class="text-sm font-semibold text-slate-900">Pengalaman Medis / EMS</label>
                            <small class="hint-warning">Sebutkan pengalaman medis atau EMS yang pernah dijalani. Jika belum ada, tulis "-".</small>
                            <textarea id="medical_experience" name="medical_experience" rows="3" placeholder="Tulis pengalaman medis / EMS Anda" autocomplete="off" required></textarea>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="city_duration" class="text-sm font-semibold text-slate-900">Sudah Berapa Lama di Kota IME</label>
                                <input type="text" id="city_duration" name="city_duration" placeholder="Contoh: 2 minggu" autocomplete="off" required>
                            </div>

                            <div class="form-group">
                                <label for="online_schedule" class="text-sm font-semibold text-slate-900">Jam Biasanya Online</label>
                                <input type="text" id="online_schedule" name="online_schedule" placeholder="Contoh: 19.00 - 23.00 WIB" autocomplete="off" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="other_city_responsibility" class="text-sm font-semibold text-slate-900">Tanggung Jawab di Kota Lain</label>
                            <small class="hint-warning">Contoh: EMS, Government, atau instansi lain. Jika tidak ada, tulis "-".</small>
                            <textarea id="other_city_responsibility" name="other_city_responsibility" rows="2" placeholder="Tulis tanggung jawab lain yang masih aktif" autocomplete="off" required></textarea>
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
                            <input type="text" id="duty_duration" name="duty_duration" placeholder="Tulis estimasi durasi duty" autocomplete="off" required>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('chat-bubble-left-right', 'h-5 w-5') ?>
                            <span>Motivasi</span>
                        </div>

                        <div class="form-group">
                            <label for="motivation" class="text-sm font-semibold text-slate-900">Alasan Bergabung dengan Roxwood Hospital</label>
                            <textarea id="motivation" name="motivation" rows="3" placeholder="Jelaskan alasan Anda ingin bergabung" autocomplete="off" required></textarea>
                        </div>

                        <div class="form-group">
                            <label for="work_principle" class="text-sm font-semibold text-slate-900">Hal Terpenting dalam Bekerja di Rumah Sakit</label>
                            <textarea id="work_principle" name="work_principle" rows="3" placeholder="Jelaskan prinsip kerja yang Anda pegang" autocomplete="off" required></textarea>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('paper-clip', 'h-5 w-5') ?>
                            <span>Lampiran Dokumen</span>
                        </div>

                        <p class="section-intro">Unggah dokumen dalam format JPG, JPEG, PNG, WEBP, GIF, atau BMP dengan gambar yang jelas dan tidak terpotong.</p>

                        <div class="grid gap-4 lg:grid-cols-3">
                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <span class="text-sm font-semibold text-slate-900">KTP IC</span>
                                    <span class="badge-muted-mini">Wajib</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="ktpIc" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>PNG atau JPG</small>
                                        </span>
                                    </label>
                                    <input type="file" id="ktpIc" name="ktp_ic" accept="image/png,image/jpeg" class="sr-only recruitment-file-input" required>
                                    <div class="file-selected-name" data-for="ktpIc"></div>
                                </div>
                            </div>

                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <span class="text-sm font-semibold text-slate-900">SKB</span>
                                    <span class="badge-muted-mini">Wajib</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="skbFile" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>PNG atau JPG</small>
                                        </span>
                                    </label>
                                    <input type="file" id="skbFile" name="skb" accept="image/png,image/jpeg" class="sr-only recruitment-file-input" required>
                                    <div class="file-selected-name" data-for="skbFile"></div>
                                </div>
                            </div>

                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <span class="text-sm font-semibold text-slate-900">SIM</span>
                                    <span class="badge-muted-mini">Opsional</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="simFile" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>PNG atau JPG</small>
                                        </span>
                                    </label>
                                    <input type="file" id="simFile" name="sim" accept="image/png,image/jpeg" class="sr-only recruitment-file-input">
                                    <div class="file-selected-name" data-for="simFile"></div>
                                </div>
                            </div>

                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <span class="text-sm font-semibold text-slate-900">Surat Keterangan Sehat</span>
                                    <span class="badge-muted-mini">Wajib</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="suratSehatFile" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>PNG atau JPG</small>
                                        </span>
                                    </label>
                                    <input type="file" id="suratSehatFile" name="surat_keterangan_sehat" accept="image/png,image/jpeg" class="sr-only recruitment-file-input" required>
                                    <div class="file-selected-name" data-for="suratSehatFile"></div>
                                </div>
                            </div>

                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <span class="text-sm font-semibold text-slate-900">Surat Keterangan Psikolog</span>
                                    <span class="badge-muted-mini">Wajib</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="suratPsikologFile" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>PNG atau JPG</small>
                                        </span>
                                    </label>
                                    <input type="file" id="suratPsikologFile" name="surat_keterangan_psikolog" accept="image/png,image/jpeg" class="sr-only recruitment-file-input" required>
                                    <div class="file-selected-name" data-for="suratPsikologFile"></div>
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
                            <button type="submit" id="publicRecruitmentSubmitButton" class="btn-success w-full justify-center md:w-auto">
                                <?= ems_icon('arrow-right-on-rectangle', 'h-4 w-4') ?>
                                <span>Kirim Pendaftaran</span>
                            </button>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <style>
        .file-selected-name {
            display: none;
            margin-top: 12px;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            background: #f1f5f9;
            padding: 12px 16px;
            width: fit-content;
            max-width: 100%;
        }

        .selected-file-info {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            max-width: 100%;
            color: #475569;
        }

        .selected-file-info strong {
            display: block;
            max-width: min(100%, 240px);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #334155;
            font-size: 13px;
        }

        .selected-file-info small {
            flex-shrink: 0;
            color: #64748b;
            font-size: 13px;
        }
    </style>
    <script>
        const MAX_UPLOAD_BYTES = 1024 * 1024;

        function resetSelectedFileDisplay(inputId) {
            const nameDisplay = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
            if (!nameDisplay) return;
            nameDisplay.innerHTML = '';
            nameDisplay.style.display = 'none';
        }

        document.addEventListener('change', function(e) {
            const input = e.target;
            if (!input || input.tagName !== 'INPUT' || input.type !== 'file') return;

            const nameDisplay = document.querySelector('.file-selected-name[data-for="' + input.id + '"]');
            if (!nameDisplay) return;

            if (input.files && input.files.length > 0) {
                if (input.files[0].size > MAX_UPLOAD_BYTES) {
                    alert('Ukuran file maksimal 1 MB. Silakan pilih foto yang lebih kecil.');
                    input.value = '';
                    resetSelectedFileDisplay(input.id);
                    return;
                }

                const fileName = input.files[0].name;
                const fileSize = (input.files[0].size / 1024).toFixed(1);
                nameDisplay.innerHTML = '<span class="selected-file-info"><strong>' + fileName + '</strong><small>' + fileSize + ' KB</small></span>';
                nameDisplay.style.display = 'flex';
            } else {
                nameDisplay.innerHTML = '';
                nameDisplay.style.display = 'none';
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('publicRecruitmentForm');

            document.querySelectorAll('#publicRecruitmentForm input:not([type="file"]):not([type="hidden"]), #publicRecruitmentForm textarea, #publicRecruitmentForm select').forEach(function(field) {
                ['paste', 'copy', 'cut', 'drop'].forEach(function(eventName) {
                    field.addEventListener(eventName, function(event) {
                        event.preventDefault();
                    });
                });
            });

            if (form) {
                form.addEventListener('submit', function(event) {
                    const oversizedFile = Array.from(form.querySelectorAll('input[type="file"]')).find(function(input) {
                        return input.files && input.files[0] && input.files[0].size > MAX_UPLOAD_BYTES;
                    });

                    if (oversizedFile) {
                        event.preventDefault();
                        alert('Ada file yang melebihi batas 1 MB. Silakan ganti file tersebut sebelum kirim pendaftaran.');
                        oversizedFile.focus();
                    }
                });
            }
        });
    </script>
</body>

</html>


