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
    <div id="publicUploadOverlay" class="global-upload-overlay hidden" aria-hidden="true">
        <div class="global-upload-overlay-box">
            <div class="global-upload-spinner" aria-hidden="true"></div>
            <div class="global-upload-title">Upload sedang diproses</div>
            <div class="global-upload-copy">Mohon tunggu. File besar mungkin memerlukan waktu lebih lama untuk diproses dan dikirim.</div>
        </div>
    </div>
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
                        <p>✔ Berusia minimal 17 tahun pada saat mendaftar,</p>
                        <p>✔ Tidak memiliki catatan kriminal, dan dibuktikan dengan SKB</p>
                        <p>✔ Calon Kandidat dinyatakan sehat dan siap melakukan interview, dengan melampirkan surat kesehatan &amp; surat psikologi</p>
                        <p>✔ Tidak sedang bergabung dengan Instansi dan tidak terlibat dengan fraksi manapun</p>
                        <p>✔ Jika memiliki kunci sebelumnya baik whitelist maupun fraksi harus mengikuti City Rules ke 21, yaitu harus menunggu masa pemutihan selama 14 hari setelah kunci dilepas</p>
                        <p>✔ Apabila pendaftar berasal dari instansi swasta, maka proses administrasi pendaftaran EMS baru tidak diwajibkan menunggu masa 14 (empat belas) hari sebagaimana City Rules, dan dapat diproses sesuai dengan kebijakan yang berlaku.</p>
                        <p>✔ Wajib pernah datang ke roxwood hospital untuk mengenal lingkungan &amp; fasilitas rumah sakit.</p>
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
                            <label for="medical_experience" class="text-sm font-semibold text-slate-900">Pengalaman Medis / EMS</label>
                            <small class="hint-warning">Sebutkan pengalaman medis atau EMS yang pernah dijalani. Jika belum ada, tulis "-".</small>
                            <textarea id="medical_experience" name="medical_experience" rows="3" placeholder="Tulis pengalaman medis / EMS Anda" required></textarea>
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

                        <p class="section-intro">Unggah dokumen dalam format JPG, JPEG, PNG, WEBP, GIF, atau BMP dengan gambar yang jelas dan tidak terpotong.</p>

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
                                            <small>JPG / JPEG / PNG / WEBP / GIF / BMP</small>
                                        </span>
                                    </label>
                                    <input type="file" id="ktpIc" name="ktp_ic" accept=".jpg,.jpeg,.png,.webp,.gif,.bmp,image/jpeg,image/png,image/webp,image/gif,image/bmp" class="sr-only" required>
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
                                            <small>JPG / JPEG / PNG / WEBP / GIF / BMP</small>
                                        </span>
                                    </label>
                                    <input type="file" id="skbFile" name="skb" accept=".jpg,.jpeg,.png,.webp,.gif,.bmp,image/jpeg,image/png,image/webp,image/gif,image/bmp" class="sr-only" required>
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
                                            <small>JPG / JPEG / PNG / WEBP / GIF / BMP</small>
                                        </span>
                                    </label>
                                    <input type="file" id="simFile" name="sim" accept=".jpg,.jpeg,.png,.webp,.gif,.bmp,image/jpeg,image/png,image/webp,image/gif,image/bmp" class="sr-only">
                                    <div class="file-selected-name" data-for="simFile"></div>
                                    <img id="thumbSim" class="hidden mt-3 h-28 w-full rounded-2xl border border-slate-200 object-cover identity-photo cursor-zoom-in" alt="Pratinjau SIM">
                                </div>
                            </div>

                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <label class="text-sm font-semibold text-slate-900">Surat Keterangan Sehat</label>
                                    <span class="badge-muted-mini">Wajib</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="suratSehatFile" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>JPG / JPEG / PNG / WEBP / GIF / BMP</small>
                                        </span>
                                    </label>
                                    <input type="file" id="suratSehatFile" name="surat_keterangan_sehat" accept=".jpg,.jpeg,.png,.webp,.gif,.bmp,image/jpeg,image/png,image/webp,image/gif,image/bmp" class="sr-only" required>
                                    <div class="file-selected-name" data-for="suratSehatFile"></div>
                                    <img id="thumbSuratSehat" class="hidden mt-3 h-28 w-full rounded-2xl border border-slate-200 object-cover identity-photo cursor-zoom-in" alt="Pratinjau Surat Keterangan Sehat">
                                </div>
                            </div>

                            <div class="doc-upload-wrapper m-0">
                                <div class="doc-upload-header">
                                    <label class="text-sm font-semibold text-slate-900">Surat Keterangan Psikolog</label>
                                    <span class="badge-muted-mini">Wajib</span>
                                </div>
                                <div class="doc-upload-input">
                                    <label for="suratPsikologFile" class="file-upload-label">
                                        <span class="file-icon"><?= ems_icon('document-text', 'h-5 w-5') ?></span>
                                        <span class="file-text">
                                            <strong>Pilih file</strong>
                                            <small>JPG / JPEG / PNG / WEBP / GIF / BMP</small>
                                        </span>
                                    </label>
                                    <input type="file" id="suratPsikologFile" name="surat_keterangan_psikolog" accept=".jpg,.jpeg,.png,.webp,.gif,.bmp,image/jpeg,image/png,image/webp,image/gif,image/bmp" class="sr-only" required>
                                    <div class="file-selected-name" data-for="suratPsikologFile"></div>
                                    <img id="thumbSuratPsikolog" class="hidden mt-3 h-28 w-full rounded-2xl border border-slate-200 object-cover identity-photo cursor-zoom-in" alt="Pratinjau Surat Keterangan Psikolog">
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="card mb-0 bg-slate-50/80">
                        <div class="card-header">
                            <?= ems_icon('arrow-right-circle', 'h-5 w-5') ?>
                            <span>Finalisasi</span>
                        </div>
                        <div id="uploadProcessingNotice" class="alert alert-info mb-4 hidden">
                            Gambar sedang diproses dan dikompres. Mohon tunggu sebentar.
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

    <script src="/assets/vendor/photoswipe/photoswipe.umd.min.js"></script>
    <script src="/assets/vendor/photoswipe/photoswipe-lightbox.umd.min.js"></script>
    <script src="/assets/design/js/photoswipe-init.js"></script>

    <style>
        .global-upload-overlay {
            position: fixed;
            inset: 0;
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, 0.72);
            backdrop-filter: blur(6px);
        }

        .global-upload-overlay.hidden {
            display: none;
        }

        .global-upload-overlay-box {
            width: min(100%, 420px);
            border-radius: 24px;
            background: #ffffff;
            padding: 24px;
            text-align: center;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.25);
        }

        .global-upload-spinner {
            width: 52px;
            height: 52px;
            margin: 0 auto 16px;
            border-radius: 999px;
            border: 4px solid #dbeafe;
            border-top-color: #0284c7;
            animation: ems-upload-spin 0.9s linear infinite;
        }

        .global-upload-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
        }

        .global-upload-copy {
            margin-top: 8px;
            font-size: 13px;
            line-height: 1.6;
            color: #475569;
        }

        @keyframes ems-upload-spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>

    <script>
        (function() {
            const form = document.getElementById('publicRecruitmentForm');
            const submitButton = document.getElementById('publicRecruitmentSubmitButton');
            const processingNotice = document.getElementById('uploadProcessingNotice');
            const overlay = document.getElementById('publicUploadOverlay');
            const compressionState = new Map();
            const FLOW_KEY = 'ems_medical_recruitment_flow_v1';
            const STORAGE_KEY = 'public_recruitment_form_draft_v1';
            const RECRUITMENT_TRACK = 'medical_candidate';
            const DONE_URL = <?= json_encode(ems_url('/public/recruitment_done.php'), JSON_UNESCAPED_UNICODE) ?>;
            const AI_TEST_BASE_URL = <?= json_encode(ems_url('/public/ai_test.php'), JSON_UNESCAPED_UNICODE) ?>;
            const IMAGE_INPUT_IDS = [
                'ktpIc',
                'skbFile',
                'simFile',
                'suratSehatFile',
                'suratPsikologFile'
            ];

            function formatFileSize(bytes) {
                if (!Number.isFinite(bytes) || bytes <= 0) return '0 KB';
                if (bytes >= 1024 * 1024) {
                    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
                }
                return Math.max(1, Math.round(bytes / 1024)) + ' KB';
            }

            function setProcessingState(isProcessing) {
                if (processingNotice) {
                    processingNotice.classList.toggle('hidden', !isProcessing);
                }
                if (submitButton) {
                    submitButton.disabled = isProcessing;
                }
            }

            function showOverlay() {
                if (!overlay) return;
                overlay.classList.remove('hidden');
                overlay.setAttribute('aria-hidden', 'false');
            }

            function hideOverlay() {
                if (!overlay) return;
                overlay.classList.add('hidden');
                overlay.setAttribute('aria-hidden', 'true');
            }

            function collectDraftData() {
                const data = {};
                form?.querySelectorAll('input, textarea, select').forEach((field) => {
                    const name = field.getAttribute('name');
                    if (!name || field.type === 'file') {
                        return;
                    }
                    data[name] = field.value || '';
                });
                return data;
            }

            function normalizeCitizenId(value) {
                return String(value || '').trim().toUpperCase();
            }

            function readJson(key, fallback) {
                try {
                    const raw = localStorage.getItem(key);
                    return raw ? JSON.parse(raw) : fallback;
                } catch (_) {
                    return fallback;
                }
            }

            function getFlowState() {
                return readJson(FLOW_KEY, null);
            }

            function setFlowState(patch) {
                try {
                    const current = getFlowState();
                    const next = Object.assign({}, current && typeof current === 'object' ? current : {}, patch, {
                        updatedAt: Date.now()
                    });
                    localStorage.setItem(FLOW_KEY, JSON.stringify(next));
                } catch (_) {}
            }

            function syncFormFlowState() {
                const citizenId = normalizeCitizenId(form?.querySelector('[name="citizen_id"]')?.value);
                const current = getFlowState();

                if (!citizenId) {
                    if (current && current.phase === 'form' && current.track === RECRUITMENT_TRACK && !current.applicantId) {
                        try {
                            localStorage.removeItem(FLOW_KEY);
                        } catch (_) {}
                    }
                    return;
                }

                setFlowState({
                    track: RECRUITMENT_TRACK,
                    phase: 'form',
                    citizenId,
                    formDraftKey: STORAGE_KEY
                });
            }

            function redirectFromFlowState() {
                const current = getFlowState();
                if (!current || current.track !== RECRUITMENT_TRACK) {
                    return false;
                }

                if (current.phase === 'ai_test' && current.applicantId) {
                    window.location.replace(AI_TEST_BASE_URL + '?applicant_id=' + encodeURIComponent(current.applicantId) + '&track=' + encodeURIComponent(RECRUITMENT_TRACK));
                    return true;
                }

                if (current.phase === 'done') {
                    window.location.replace(DONE_URL);
                    return true;
                }

                return false;
            }

            const resetFlowRequested = new URLSearchParams(window.location.search).get('reset_device_flow') === '1';
            if (resetFlowRequested) {
                try {
                    const current = getFlowState();
                    if (current && current.aiTestStorageKey) {
                        localStorage.removeItem(String(current.aiTestStorageKey));
                    }
                    localStorage.removeItem(FLOW_KEY);
                    localStorage.removeItem(STORAGE_KEY);
                } catch (_) {}
            }

            function saveDraft() {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(collectDraftData()));
                } catch (_) {}
                syncFormFlowState();
            }

            function applySavedDraft() {
                try {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    if (!raw) {
                        return;
                    }

                    const draft = JSON.parse(raw);
                    if (!draft || typeof draft !== 'object') {
                        return;
                    }

                    form?.querySelectorAll('input, textarea, select').forEach((field) => {
                        const name = field.getAttribute('name');
                        if (!name || field.type === 'file') {
                            return;
                        }

                        if (Object.prototype.hasOwnProperty.call(draft, name)) {
                            field.value = draft[name] || '';
                        }
                    });
                } catch (_) {}
            }

            function installNoPasteProtection() {
                form?.querySelectorAll('input:not([type="file"]):not([type="hidden"]), textarea').forEach((field) => {
                    ['paste', 'copy', 'cut', 'drop'].forEach((eventName) => {
                        field.addEventListener(eventName, function(event) {
                            event.preventDefault();
                        });
                    });
                });
            }

            function isCompressionRunning() {
                for (const state of compressionState.values()) {
                    if (state && state.status === 'processing') {
                        return true;
                    }
                }
                return false;
            }

            function waitForAllCompression() {
                const pending = [];
                for (const state of compressionState.values()) {
                    if (state && state.promise) {
                        pending.push(state.promise.catch(() => null));
                    }
                }
                return Promise.all(pending);
            }

            function updateSelectedName(inputId, file, originalFile) {
                const nameBox = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
                if (!nameBox) return;

                if (!file) {
                    nameBox.textContent = '';
                    nameBox.classList.add('hidden');
                    return;
                }

                const sizeText = formatFileSize(file.size);
                const originalSizeText = originalFile ? formatFileSize(originalFile.size) : '';
                const compressedNote = originalFile && originalFile.size !== file.size
                    ? ' • hasil kompresi dari ' + originalSizeText
                    : '';

                nameBox.textContent = (file.name || 'File dipilih') + ' • ' + sizeText + compressedNote;
                nameBox.classList.remove('hidden');
            }

            function replaceInputFile(input, file) {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
            }

            function blobFromCanvas(canvas, quality) {
                return new Promise((resolve) => {
                    canvas.toBlob(resolve, 'image/jpeg', quality);
                });
            }

            function loadImageFromFile(file) {
                return new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onerror = () => reject(new Error('File tidak dapat dibaca.'));
                    reader.onload = () => {
                        const image = new Image();
                        image.onerror = () => reject(new Error('Gambar tidak dapat diproses.'));
                        image.onload = () => resolve(image);
                        image.src = String(reader.result || '');
                    };
                    reader.readAsDataURL(file);
                });
            }

            async function compressImageFile(file) {
                const image = await loadImageFromFile(file);
                const maxLongEdge = 2400;
                const width = image.naturalWidth || image.width;
                const height = image.naturalHeight || image.height;
                const longestEdge = Math.max(width, height, 1);
                const scale = longestEdge > maxLongEdge ? (maxLongEdge / longestEdge) : 1;
                const targetWidth = Math.max(1, Math.round(width * scale));
                const targetHeight = Math.max(1, Math.round(height * scale));

                const canvas = document.createElement('canvas');
                canvas.width = targetWidth;
                canvas.height = targetHeight;

                const context = canvas.getContext('2d', {
                    alpha: false,
                    willReadFrequently: false
                });
                if (!context) {
                    throw new Error('Canvas browser tidak tersedia.');
                }

                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, targetWidth, targetHeight);
                context.drawImage(image, 0, 0, targetWidth, targetHeight);

                const targetMinBytes = 220 * 1024;
                const targetMaxBytes = 500 * 1024;
                const qualitySteps = [0.92, 0.88, 0.84, 0.80, 0.76, 0.72, 0.68];
                let selectedBlob = null;

                for (const quality of qualitySteps) {
                    const blob = await blobFromCanvas(canvas, quality);
                    if (!blob) {
                        continue;
                    }

                    selectedBlob = blob;
                    if (blob.size <= targetMaxBytes) {
                        if (blob.size < targetMinBytes) {
                            break;
                        }
                        break;
                    }
                }

                if (!selectedBlob) {
                    throw new Error('Gagal membuat file hasil kompresi.');
                }

                const originalBaseName = (file.name || 'upload').replace(/\.[^.]+$/, '');
                return new File(
                    [selectedBlob],
                    originalBaseName + '.jpg',
                    {
                        type: 'image/jpeg',
                        lastModified: Date.now()
                    }
                );
            }

            function setSelectedName(inputId) {
                const input = document.getElementById(inputId);
                if (!input) return;

                const nameBox = document.querySelector('.file-selected-name[data-for="' + inputId + '"]');
                if (!nameBox) return;

                input.addEventListener('change', async function() {
                    const file = this.files && this.files[0] ? this.files[0] : null;
                    if (!file) {
                        compressionState.delete(inputId);
                        updateSelectedName(inputId, null);
                        setProcessingState(isCompressionRunning());
                        return;
                    }

                    updateSelectedName(inputId, file);

                    const compressionPromise = (async () => {
                        const mime = String(file.type || '').toLowerCase();
                        if (!mime.startsWith('image/')) {
                            return file;
                        }

                        if (file.size <= 450 * 1024) {
                            return file;
                        }

                        return compressImageFile(file);
                    })();

                    compressionState.set(inputId, {
                        status: 'processing',
                        promise: compressionPromise
                    });
                    setProcessingState(true);

                    try {
                        const processedFile = await compressionPromise;
                        if (!processedFile) {
                            throw new Error('Kompresi file gagal.');
                        }

                        replaceInputFile(this, processedFile);
                        updateSelectedName(inputId, processedFile, file);
                        compressionState.set(inputId, {
                            status: 'done',
                            promise: Promise.resolve(processedFile)
                        });
                    } catch (error) {
                        compressionState.delete(inputId);
                        alert((error && error.message) ? error.message : 'Gagal memproses gambar.');
                        this.value = '';
                        updateSelectedName(inputId, null);
                    } finally {
                        setProcessingState(isCompressionRunning());
                    }
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
            setSelectedName('suratSehatFile');
            setSelectedName('suratPsikologFile');

            setupThumb('ktpIc', 'thumbKtpIc');
            setupThumb('skbFile', 'thumbSkb');
            setupThumb('simFile', 'thumbSim');
            setupThumb('suratSehatFile', 'thumbSuratSehat');
            setupThumb('suratPsikologFile', 'thumbSuratPsikolog');

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

            form?.querySelectorAll('input, textarea, select').forEach((field) => {
                if (field.type === 'file') {
                    return;
                }

                const eventName = field.tagName === 'SELECT' ? 'change' : 'input';
                field.addEventListener(eventName, saveDraft);
            });

            form?.addEventListener('submit', async function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    form.reportValidity();
                    return false;
                }

                if (!isCompressionRunning()) {
                    return;
                }

                event.preventDefault();
                setProcessingState(true);
                await waitForAllCompression();
                setProcessingState(false);

                if (isCompressionRunning()) {
                    alert('Beberapa file masih diproses. Mohon tunggu sebentar lalu kirim kembali.');
                    return false;
                }

                showOverlay();
                form.submit();
                return true;
            });

            window.addEventListener('pageshow', hideOverlay);
            if (redirectFromFlowState()) {
                return;
            }
            applySavedDraft();
            syncFormFlowState();
            installNoPasteProtection();
        })();
    </script>
</body>

</html>
