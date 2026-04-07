<?php
require_once __DIR__ . '/../assets/design/ui/icon.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';

$profile = ems_recruitment_profile('assistant_manager');
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
                        <span class="meta-text"><?= htmlspecialchars($profile['subtitle']) ?></span>
                    </div>
                </div>

                <h1 class="public-heading"><?= htmlspecialchars($profile['title']) ?></h1>
                <p class="public-copy">
                    <?= htmlspecialchars($profile['description']) ?>
                </p>

                <div class="alert alert-info mt-5 mb-0 border-white/15 bg-white/10 text-slate-100">
                    Assessment difokuskan pada SOP EMS, integritas, dan pola kerja kandidat.
                </div>

                <div class="public-feature-list">
                    <div class="public-feature-item">
                        <span class="public-feature-title">Tahap 1</span>
                        <?= htmlspecialchars($profile['stage_copy']['Tahap 1'] ?? '') ?>
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Tahap 2</span>
                        <?= htmlspecialchars($profile['stage_copy']['Tahap 2'] ?? '') ?>
                    </div>
                    <div class="public-feature-item">
                        <span class="public-feature-title">Tahap 3</span>
                        <?= htmlspecialchars($profile['stage_copy']['Tahap 3'] ?? '') ?>
                    </div>
                </div>

                <div class="card mt-5 mb-0 border-white/10 bg-white/10 text-white shadow-none">
                    <div class="card-header border-white/10 pb-3 text-white">
                        <?= ems_icon('shield-check', 'h-5 w-5') ?>
                        <span>Catatan Penting</span>
                    </div>
                    <div class="space-y-3 text-sm leading-6 text-slate-200">
                        <p>Form ini khusus jalur calon asisten manager dengan fokus divisi General Affair.</p>
                        <p>Jawaban yang konsisten antar soal akan menjadi bahan evaluasi utama pada tahap screening awal.</p>
                    </div>
                </div>
            </aside>

            <main class="public-panel">
                <div class="public-form-header">
                    <div>
                        <h2 class="public-form-title">Formulir Calon Asisten Manager</h2>
                        <p class="public-form-subtitle">Isi seluruh kolom wajib sebelum melanjutkan ke assessment General Affair.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="clearAssistantManagerDraft" class="btn-secondary px-3 py-2 text-xs">
                            <?= ems_icon('trash', 'h-4 w-4') ?>
                            <span>Clear Draft</span>
                        </button>
                        <div class="badge-muted"><?= htmlspecialchars($profile['badge']) ?></div>
                    </div>
                </div>

                <form action="recruitment_submit.php" method="post" id="assistantManagerRecruitmentForm">
                    <input type="hidden" name="recruitment_type" value="<?= htmlspecialchars($profile['type']) ?>">
                    <input type="hidden" name="target_division" value="General Affair">
                    <input type="hidden" name="verified_user_id" id="verifiedUserId" value="">

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('identification', 'h-5 w-5') ?>
                            <span>Identitas Dasar</span>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="ic_name" class="text-sm font-semibold text-slate-900">Nama IC</label>
                                <input type="text" id="ic_name" name="ic_name" placeholder="Terisi otomatis dari Citizen ID" minlength="3" required readonly>
                            </div>

                            <div class="form-group">
                                <label for="citizen_id" class="text-sm font-semibold text-slate-900">Citizen ID</label>
                                <input type="text" id="citizen_id" name="citizen_id" placeholder="Ketik Citizen ID untuk autocomplete" autocomplete="off" required>
                                <small class="hint-info">Jika Citizen ID sudah terdaftar di EMS, data identitas dan dokumen akan terisi otomatis.</small>
                                <div id="citizenAutocomplete" class="hidden mt-2 rounded-2xl border border-slate-200 bg-white shadow-soft"></div>
                            </div>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="ooc_age" class="text-sm font-semibold text-slate-900">Umur OOC</label>
                                <input type="number" id="ooc_age" name="ooc_age" min="1" placeholder="Masukkan umur OOC" required>
                            </div>

                            <div class="form-group">
                                <label for="jenis_kelamin" class="text-sm font-semibold text-slate-900">Jenis Kelamin</label>
                                <select id="jenis_kelamin" name="jenis_kelamin" required disabled>
                                    <option value="">-- Pilih Jenis Kelamin --</option>
                                    <option value="Laki-laki">Laki-laki</option>
                                    <option value="Perempuan">Perempuan</option>
                                </select>
                                <input type="hidden" id="jenis_kelamin_hidden" name="jenis_kelamin" value="">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="ic_phone" class="text-sm font-semibold text-slate-900">Nomor Telepon IC</label>
                            <input type="text" id="ic_phone" name="ic_phone" placeholder="Terisi otomatis dari Citizen ID" minlength="3" required readonly>
                        </div>

                        <div class="form-group">
                            <label for="batch_display" class="text-sm font-semibold text-slate-900">Batch</label>
                            <input type="text" id="batch_display" name="batch_display" placeholder="Terisi otomatis dari Citizen ID" readonly>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('briefcase', 'h-5 w-5') ?>
                            <span>Pengalaman dan Komitmen</span>
                        </div>

                        <div class="form-group">
                            <label for="medical_experience" class="text-sm font-semibold text-slate-900">Pengalaman Organisasi / Operasional</label>
                            <small class="hint-warning">Sebutkan pengalaman memimpin tim, mengelola fasilitas, atau pekerjaan operasional. Minimal 80 karakter.</small>
                            <textarea id="medical_experience" name="medical_experience" rows="4" minlength="80" placeholder="Tulis pengalaman Anda secara rinci" required data-no-paste></textarea>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="city_duration" class="text-sm font-semibold text-slate-900">Lama di RS</label>
                                <input type="text" id="city_duration" name="city_duration" placeholder="Diambil otomatis dari tanggal join" minlength="3" required readonly>
                            </div>

                            <div class="form-group">
                                <label for="online_schedule" class="text-sm font-semibold text-slate-900">Jam Biasanya Online</label>
                                <textarea id="online_schedule" name="online_schedule" rows="4" placeholder="Diambil otomatis dari histori absensi EMS" minlength="5" required readonly></textarea>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="other_city_responsibility" class="text-sm font-semibold text-slate-900">Tanggung Jawab di Kota / Instansi Lain</label>
                            <small class="hint-warning">Jika ada jabatan, organisasi, atau komitmen lain yang aktif, tulis dengan jelas. Minimal 30 karakter.</small>
                            <textarea id="other_city_responsibility" name="other_city_responsibility" rows="3" minlength="30" placeholder="Tulis tanggung jawab lain yang masih aktif" required data-no-paste></textarea>
                        </div>

                        <div class="row-form-2">
                            <div class="form-group">
                                <label for="academy_ready" class="text-sm font-semibold text-slate-900">Bersedia Mengikuti Masa Probation?</label>
                                <select id="academy_ready" name="academy_ready" required>
                                    <option value="">-- Pilih Jawaban --</option>
                                    <option value="ya">Ya</option>
                                    <option value="tidak">Tidak</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="rule_commitment" class="text-sm font-semibold text-slate-900">Siap Mengikuti SOP dan Aturan Divisi</label>
                                <select id="rule_commitment" name="rule_commitment" required>
                                    <option value="">-- Pilih Jawaban --</option>
                                    <option value="ya">Ya</option>
                                    <option value="tidak">Tidak</option>
                                </select>
                            </div>
                        </div>

                            <div class="form-group">
                                <label for="duty_duration" class="text-sm font-semibold text-slate-900">Perkiraan Waktu Duty / Monitoring</label>
                            <small class="hint-info">Diambil otomatis dari rata-rata total online per hari pada histori absensi EMS.</small>
                            <input type="text" id="duty_duration" name="duty_duration" placeholder="Diambil otomatis dari rata-rata online per hari" minlength="10" required readonly>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('chat-bubble-left-right', 'h-5 w-5') ?>
                            <span>Motivasi</span>
                        </div>

                        <div class="form-group">
                            <label for="motivation" class="text-sm font-semibold text-slate-900">Alasan Ingin Bergabung sebagai Asisten Manager</label>
                            <small class="hint-warning">Minimal 120 karakter. Jawaban terlalu singkat akan ditolak.</small>
                            <textarea id="motivation" name="motivation" rows="5" minlength="120" placeholder="Jelaskan alasan Anda ingin mengambil peran ini" required data-no-paste></textarea>
                        </div>

                        <div class="form-group">
                            <label for="work_principle" class="text-sm font-semibold text-slate-900">Prinsip Kerja Saat Mengelola Tim dan SOP</label>
                            <small class="hint-warning">Minimal 120 karakter. Jelaskan prinsip kerja Anda tanpa copy paste.</small>
                            <textarea id="work_principle" name="work_principle" rows="5" minlength="120" placeholder="Jelaskan prinsip kerja yang Anda pegang" required data-no-paste></textarea>
                        </div>
                    </section>

                    <section class="card">
                        <div class="card-header">
                            <?= ems_icon('paper-clip', 'h-5 w-5') ?>
                            <span>Verifikasi Dokumen</span>
                        </div>

                        <p class="section-intro">Dokumen diambil dari akun EMS berdasarkan Citizen ID. Jika ada dokumen yang belum lengkap, pendaftaran tidak dapat dilanjutkan dan user wajib melengkapi lebih dulu di setting akun.</p>

                        <div id="docVerificationNotice" class="alert alert-warning hidden mb-4">
                            Lengkapi dokumen di <a href="/dashboard/setting_akun.php" class="font-semibold underline">Setting Akun</a> sebelum mendaftar.
                        </div>

                        <div class="grid gap-4 lg:grid-cols-4">
                            <?php foreach ([
                                'KTP' => 'docStatusKtp',
                                'SKB' => 'docStatusSkb',
                                'KTA' => 'docStatusKta',
                                'SIM' => 'docStatusSim',
                            ] as $docLabel => $docId): ?>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                    <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($docLabel) ?></div>
                                    <div id="<?= htmlspecialchars($docId) ?>" class="mt-2 text-sm text-slate-500">Menunggu verifikasi Citizen ID</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <div class="card mb-0 bg-slate-50/80">
                        <div class="card-header">
                            <?= ems_icon('arrow-right-circle', 'h-5 w-5') ?>
                            <span>Finalisasi</span>
                        </div>
                        <p class="helper-note mb-4">
                            Setelah pendaftaran dikirim, Anda akan diarahkan ke assessment untuk jalur calon asisten manager.
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
            const citizenInput = document.getElementById('citizen_id');
            const citizenAutocomplete = document.getElementById('citizenAutocomplete');
            const form = document.getElementById('assistantManagerRecruitmentForm');
            const verifiedUserId = document.getElementById('verifiedUserId');
            const genderSelect = document.getElementById('jenis_kelamin');
            const genderHidden = document.getElementById('jenis_kelamin_hidden');
            const docNotice = document.getElementById('docVerificationNotice');
            const clearDraftButton = document.getElementById('clearAssistantManagerDraft');
            const bypassCitizenId = 'RH39IQLC';
            const STORAGE_KEY = 'assistant_manager_recruitment_form_draft_v1';
            let citizenTimer = null;

            function resetAutoFilledFields() {
                verifiedUserId.value = '';
                document.getElementById('ic_name').value = '';
                document.getElementById('ic_phone').value = '';
                document.getElementById('batch_display').value = '';
                genderSelect.value = '';
                genderHidden.value = '';
                document.getElementById('city_duration').value = '';
                document.getElementById('online_schedule').value = '';
                document.getElementById('duty_duration').value = '';
                document.getElementById('docStatusKtp').textContent = 'Menunggu verifikasi Citizen ID';
                document.getElementById('docStatusSkb').textContent = 'Menunggu verifikasi Citizen ID';
                document.getElementById('docStatusKta').textContent = 'Menunggu verifikasi Citizen ID';
                document.getElementById('docStatusSim').textContent = 'Menunggu verifikasi Citizen ID';
                docNotice.classList.add('hidden');
            }

            function collectDraftData() {
                const data = {};
                form?.querySelectorAll('input, textarea, select').forEach((field) => {
                    const name = field.getAttribute('name');
                    if (!name) return;
                    if (field.type === 'file') return;
                    data[name] = field.value || '';
                });
                return data;
            }

            function saveDraft() {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(collectDraftData()));
                } catch (_) {}
            }

            function applySavedDraft() {
                try {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    if (!raw) return;
                    const draft = JSON.parse(raw);
                    if (!draft || typeof draft !== 'object') return;

                    form?.querySelectorAll('input, textarea, select').forEach((field) => {
                        const name = field.getAttribute('name');
                        if (!name || field.type === 'file') return;
                        if (Object.prototype.hasOwnProperty.call(draft, name)) {
                            field.value = draft[name] || '';
                        }
                    });

                    if (genderHidden.value) {
                        genderSelect.value = genderHidden.value;
                    }
                } catch (_) {}
            }

            function applyTemporaryBypassRules() {
                const normalizedCitizenId = String(citizenInput?.value || '').trim().toUpperCase();
                const shouldBypass = normalizedCitizenId === bypassCitizenId;

                form?.querySelectorAll('[minlength]').forEach((field) => {
                    if (shouldBypass) {
                        if (!field.dataset.originalMinlength) {
                            field.dataset.originalMinlength = field.getAttribute('minlength') || '';
                        }
                        field.removeAttribute('minlength');
                        return;
                    }

                    if (field.dataset.originalMinlength) {
                        field.setAttribute('minlength', field.dataset.originalMinlength);
                    }
                });
            }

            function setDocStatus(elementId, exists, missingLabel) {
                const el = document.getElementById(elementId);
                if (!el) return;
                if (exists) {
                    el.textContent = 'Sudah tersedia di akun EMS';
                    el.className = 'mt-2 text-sm font-medium text-emerald-700';
                    return;
                }
                el.textContent = 'Belum ada, lengkapi di Setting Akun';
                el.className = 'mt-2 text-sm font-medium text-rose-700';
                if (missingLabel) {
                    el.dataset.missingLabel = missingLabel;
                }
            }

            function applyCitizenSelection(item) {
                citizenInput.value = item.citizen_id || '';
                verifiedUserId.value = item.id || '';
                document.getElementById('ic_name').value = item.full_name || '';
                document.getElementById('ic_phone').value = item.no_hp_ic || '';
                document.getElementById('batch_display').value = item.batch || '';
                genderSelect.value = item.jenis_kelamin || '';
                genderHidden.value = item.jenis_kelamin || '';
                document.getElementById('city_duration').value = item.city_duration || '';
                document.getElementById('online_schedule').value = item.online_schedule || '';
                document.getElementById('duty_duration').value = item.duty_duration || '';

                const docs = item.documents || {};
                setDocStatus('docStatusKtp', !!docs.ktp_ic, 'KTP');
                setDocStatus('docStatusSkb', !!docs.skb, 'SKB');
                setDocStatus('docStatusKta', !!docs.kta, 'KTA');
                setDocStatus('docStatusSim', !!docs.sim, 'SIM');

                if (item.documents_complete) {
                    docNotice.classList.add('hidden');
                } else {
                    const missing = Array.isArray(item.missing_documents) ? item.missing_documents.join(', ') : '';
                    docNotice.innerHTML = 'Dokumen akun EMS belum lengkap (' + missing + '). Lengkapi dulu di <a href="' + (item.settings_url || '/dashboard/setting_akun.php') + '" class="font-semibold underline">Setting Akun</a> sebelum mendaftar.';
                    docNotice.classList.remove('hidden');
                }

                applyTemporaryBypassRules();
                saveDraft();
            }

            function renderCitizenResults(items) {
                if (!items.length) {
                    citizenAutocomplete.innerHTML = '';
                    citizenAutocomplete.classList.add('hidden');
                    return;
                }

                citizenAutocomplete.innerHTML = items.map((item, index) => `
                    <button type="button" class="block w-full border-b border-slate-100 px-4 py-3 text-left last:border-b-0 hover:bg-slate-50" data-choice-index="${index}">
                        <div class="text-sm font-semibold text-slate-900">${item.citizen_id}</div>
                        <div class="text-xs text-slate-500">${item.full_name || '-'} | ${item.role || '-'} | ${item.division || '-'}</div>
                    </button>
                `).join('');
                citizenAutocomplete.classList.remove('hidden');

                citizenAutocomplete.querySelectorAll('[data-choice-index]').forEach((button) => {
                    button.addEventListener('click', function() {
                        const item = items[parseInt(this.dataset.choiceIndex || '-1', 10)];
                        if (!item) return;
                        applyCitizenSelection(item);
                        citizenAutocomplete.innerHTML = '';
                        citizenAutocomplete.classList.add('hidden');
                    });
                });
            }

            async function searchCitizen(query) {
                try {
                    const response = await fetch('/ajax/search_recruitment_user.php?q=' + encodeURIComponent(query), {
                        credentials: 'same-origin'
                    });
                    const payload = await response.json();
                    renderCitizenResults(Array.isArray(payload.items) ? payload.items : []);
                } catch (_) {
                    renderCitizenResults([]);
                }
            }

            async function hydrateCitizenFromDraft() {
                const value = String(citizenInput?.value || '').trim();
                if (value.length < 3) return;

                try {
                    const response = await fetch('/ajax/search_recruitment_user.php?q=' + encodeURIComponent(value), {
                        credentials: 'same-origin'
                    });
                    const payload = await response.json();
                    const items = Array.isArray(payload.items) ? payload.items : [];
                    const exactMatch = items.find((item) => String(item.citizen_id || '').toUpperCase() === value.toUpperCase());
                    if (exactMatch) {
                        applyCitizenSelection(exactMatch);
                    }
                } catch (_) {}
            }

            citizenInput?.addEventListener('input', function() {
                const value = String(this.value || '').trim();
                resetAutoFilledFields();
                applyTemporaryBypassRules();
                saveDraft();

                if (citizenTimer) clearTimeout(citizenTimer);
                if (value.length < 3) {
                    renderCitizenResults([]);
                    return;
                }

                citizenTimer = setTimeout(() => searchCitizen(value), 250);
            });

            document.addEventListener('click', function(event) {
                if (!citizenAutocomplete.contains(event.target) && event.target !== citizenInput) {
                    citizenAutocomplete.classList.add('hidden');
                }
            });

            document.querySelectorAll('[data-no-paste]').forEach((field) => {
                ['paste', 'copy', 'cut', 'drop'].forEach((eventName) => {
                    field.addEventListener(eventName, function(event) {
                        event.preventDefault();
                    });
                });
            });

            form?.querySelectorAll('input, textarea, select').forEach((field) => {
                if (field.type === 'file') return;
                const eventName = field.tagName === 'SELECT' ? 'change' : 'input';
                field.addEventListener(eventName, function() {
                    if (field === genderSelect) {
                        genderHidden.value = genderSelect.value || '';
                    }
                    saveDraft();
                });
            });

            form?.addEventListener('submit', function(event) {
                const checks = [
                    { id: 'medical_experience', min: 80, label: 'Pengalaman organisasi / operasional' },
                    { id: 'other_city_responsibility', min: 30, label: 'Tanggung jawab lain' },
                    { id: 'motivation', min: 120, label: 'Alasan bergabung' },
                    { id: 'work_principle', min: 120, label: 'Prinsip kerja' }
                ];
                const normalizedCitizenId = String(citizenInput?.value || '').trim().toUpperCase();

                if (normalizedCitizenId !== bypassCitizenId) {
                    for (const check of checks) {
                        const field = document.getElementById(check.id);
                        const value = field ? String(field.value || '').trim() : '';
                        if (value.length < check.min) {
                            event.preventDefault();
                            alert(check.label + ' minimal ' + check.min + ' karakter.');
                            field?.focus();
                            return false;
                        }
                    }
                }

                if (!verifiedUserId.value || !genderHidden.value) {
                    event.preventDefault();
                    alert('Pilih Citizen ID dari hasil autocomplete agar data akun EMS terverifikasi.');
                    citizenInput?.focus();
                    return false;
                }

                const missingDocs = [];
                if (!String(document.getElementById('docStatusKtp')?.textContent || '').includes('Sudah tersedia')) missingDocs.push('KTP');
                if (!String(document.getElementById('docStatusSkb')?.textContent || '').includes('Sudah tersedia')) missingDocs.push('SKB');
                if (!String(document.getElementById('docStatusKta')?.textContent || '').includes('Sudah tersedia')) missingDocs.push('KTA');
                if (!String(document.getElementById('docStatusSim')?.textContent || '').includes('Sudah tersedia')) missingDocs.push('SIM');

                if (missingDocs.length > 0 && normalizedCitizenId !== bypassCitizenId) {
                    event.preventDefault();
                    alert('Dokumen akun EMS belum lengkap: ' + missingDocs.join(', ') + '. Lengkapi dulu di Setting Akun.');
                    return false;
                }

                try {
                    localStorage.removeItem(STORAGE_KEY);
                } catch (_) {}
            });

            clearDraftButton?.addEventListener('click', function() {
                try {
                    localStorage.removeItem(STORAGE_KEY);
                } catch (_) {}
                form?.reset();
                resetAutoFilledFields();
                renderCitizenResults([]);
                applyTemporaryBypassRules();
                alert('Draft form berhasil dihapus.');
            });

            applySavedDraft();
            hydrateCitizenFromDraft();
            applyTemporaryBypassRules();
        })();
    </script>
</body>

</html>
