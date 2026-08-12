<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/ai_laboratory.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'laboratory_ai.php', '/dashboard/index.php');
ems_ai_laboratory_ensure_tables($pdo);
ems_ai_ds_ensure_tables($pdo);

$pageTitle = 'Laboratory AI | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$recentStmt = $pdo->prepare("
    SELECT l.id, l.report_code, l.patient_name, l.department, l.category, l.status, l.created_at, l.source_report_code, u.full_name AS created_by_name
    FROM ai_laboratory_results l
    LEFT JOIN user_rh u ON u.id = l.user_id
    WHERE l.unit_code = ?
    ORDER BY l.id DESC
    LIMIT 15
");
$recentStmt->execute([$effectiveUnit]);
$recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$hasOwnApiKey = trim((string) (ems_ai_ds_get_user_settings($pdo, (int) ($user['id'] ?? 0))['gemini_api_key'] ?? '')) !== '';
$canDelete = ems_is_manager_plus_role($user['role'] ?? '');

$catalog = ems_ai_laboratory_catalog();
$catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE);
$customTriggerJson = json_encode(ems_ai_laboratory_custom_trigger_options(), JSON_UNESCAPED_UNICODE);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between mb-4">
            <div>
                <h1 class="page-title">Laboratory AI</h1>
                <p class="page-subtitle">Susun konfigurasi pemeriksaan laboratorium, AI akan menghasilkan hasil parameter, rentang rujukan, dan interpretasi klinis secara otomatis.</p>
            </div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-info"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <?php if (!$hasOwnApiKey): ?>
            <div class="alert alert-warning">
                Anda belum mengatur API key Gemini pribadi. <a href="ai_settings_personal.php" class="font-bold underline">Atur sekarang di Setting AI Saya</a> sebelum membuat hasil laboratorium.
            </div>
        <?php endif; ?>

        <div id="aiLabFormError" class="hidden alert alert-error"></div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
            <div class="card">
                <div class="card-header">Konfigurasi Pemeriksaan</div>
                <div class="card-section" style="background:#f0f9ff;border-bottom:1px solid #e2e8f0;">
                    <label style="margin-top:0;">Ambil dari Laporan Diagnosis (opsional)</label>
                    <div class="flex items-center gap-2">
                        <input type="text" id="aiLabLookupCode" placeholder="Tempel kode referensi, mis. DGN-20260812-143012-A1B2" style="flex:1;">
                        <button type="button" id="aiLabLookupBtn" class="btn-secondary btn-sm" style="white-space:nowrap;">
                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                            <span>Ambil Data</span>
                        </button>
                    </div>
                    <p id="aiLabLookupNote" class="page-subtitle" style="margin-top:6px;font-size:12px;">Kode ini muncul di halaman Laporan Diagnosis (ai_diagnosis_report.php) — kalau laporan itu punya rekomendasi laboratorium terstruktur, Departemen/Kategori/Spesimen di bawah akan terisi otomatis.</p>
                </div>
                <form method="POST" action="laboratory_ai_action.php" class="form" id="aiLabForm">
                    <?= csrfField(); ?>
                    <input type="hidden" name="diagnosis_code" id="aiLabDiagCodeHidden" value="">

                    <label>Departemen</label>
                    <select name="department" id="aiLabDepartment" required>
                        <option value="" disabled selected>-- Pilih Departemen --</option>
                        <?php foreach (ems_ai_laboratory_departments() as $dept): ?>
                            <option value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p id="aiLabDeptHint" class="page-subtitle" style="margin-top:4px;font-size:12px;"></p>

                    <label class="mt-3">Kategori Pemeriksaan</label>
                    <select name="category" id="aiLabCategory" required disabled>
                        <option value="" selected>-- Pilih Departemen dulu --</option>
                    </select>

                    <div id="aiLabLevel3Wrap" class="hidden">
                        <label class="mt-3">Pilihan Spesifik</label>
                        <select name="level3_option" id="aiLabLevel3" disabled>
                            <option value="" selected>-- Pilih Kategori dulu --</option>
                        </select>
                    </div>

                    <div id="aiLabCustomWrap" class="hidden">
                        <label class="mt-3">Parameter Kustom</label>
                        <div id="aiLabCustomGrid" class="grid grid-cols-2 sm:grid-cols-3 gap-2" style="margin-top:6px;"></div>
                    </div>

                    <label class="mt-3">Jenis Spesimen</label>
                    <select name="specimen_type" id="aiLabSpecimen" required disabled>
                        <option value="" selected>-- Pilih Departemen dulu --</option>
                    </select>

                    <label class="mt-4">Identitas Pasien</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <input type="text" name="patient_name" id="aiLabPatientName" placeholder="Nama pasien" value="<?= htmlspecialchars($_POST['patient_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <input type="text" name="patient_citizen_id" id="aiLabPatientCitizenId" placeholder="Citizen ID (RWX-...)" value="<?= htmlspecialchars($_POST['patient_citizen_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                        <div>
                            <input type="date" name="patient_dob" id="aiLabPatientDob" value="<?= htmlspecialchars($_POST['patient_dob'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <input type="text" name="doctor_name" id="aiLabDoctorName" placeholder="Dokter Pemeriksa" value="<?= htmlspecialchars($_POST['doctor_name'] ?? ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <label class="mt-4">Info Klinis / Anamnesis / Diagnosis Pasien</label>
                    <textarea name="clinical_info" id="aiLabClinicalInfo" rows="6" required placeholder="Contoh: pasien mengeluh demam tinggi 3 hari, nyeri sendi, riwayat gigitan nyamuk..."><?= htmlspecialchars($_POST['clinical_info'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary" id="aiLabSubmitBtn">
                            <?= ems_icon('sparkles', 'h-4 w-4') ?>
                            <span>Analisis Laboratorium</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Riwayat Terbaru</div>
                <div class="table-wrapper">
                    <table id="aiLabHistoryTable" class="table-custom" data-auto-datatable="true" data-dt-order='[[0,"desc"]]'>
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Pasien</th>
                                <th>Pemeriksaan</th>
                                <th>Oleh</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRows as $row): ?>
                                <tr>
                                    <td class="whitespace-nowrap" data-order="<?= (int) strtotime((string) $row['created_at']) ?>"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $row['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['patient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $row['department'] . ' - ' . (string) $row['category'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($row['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="<?= $row['status'] === 'done' ? 'badge-success' : 'badge-danger' ?>">
                                            <?= $row['status'] === 'done' ? 'Selesai' : 'Gagal' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="laboratory_ai_report.php?id=<?= (int) $row['id'] ?>" class="btn-secondary btn-sm">
                                                <?= ems_icon('eye', 'h-4 w-4') ?>
                                                <span>Lihat</span>
                                            </a>
                                            <?php if (!empty($row['source_report_code'])): ?>
                                                <button type="button" class="btn-secondary btn-sm lab-regenerate-btn" data-id="<?= (int) $row['id'] ?>" title="Generate ulang pakai kode referensi &amp; input yang sama">
                                                    <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                                                    <span>Generate Ulang</span>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                                <form method="POST" action="laboratory_ai_report.php?id=<?= (int) $row['id'] ?>" onsubmit="return confirm('Hapus hasil laboratorium #<?= (int) $row['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="btn-danger btn-sm">
                                                        <?= ems_icon('trash', 'h-4 w-4') ?>
                                                        <span>Hapus</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recentRows)): ?>
                                <tr><td colspan="6" class="text-center text-slate-400 py-6">Belum ada riwayat hasil laboratorium.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<div id="aiLabLoadingOverlay" class="global-upload-overlay hidden" aria-hidden="true">
    <div class="global-upload-overlay-box">
        <div class="global-upload-spinner" aria-hidden="true"></div>
        <div class="global-upload-title">Menganalisis hasil laboratorium...</div>
        <div id="aiLabLoadingMessage" class="global-upload-copy">Menyiapkan permintaan...</div>
        <div id="aiLabLoadingBarWrap" style="margin-top:14px;height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;">
            <div id="aiLabLoadingBar" style="height:100%;width:0%;border-radius:999px;background:#0ea5e9;transition:width .3s ease;"></div>
        </div>
        <div id="aiLabLoadingPct" style="margin-top:6px;font-size:12px;font-weight:800;color:#0284c7;">0%</div>
        <div id="aiLabLoadingErrorBox" class="hidden alert alert-error" style="margin-top:12px;text-align:left;"></div>
        <button type="button" id="aiLabLoadingRetryBtn" class="btn-secondary hidden" style="margin-top:10px;">Tutup & Coba Lagi</button>
    </div>
</div>

<script>
(function () {
    var CSRF_TOKEN = <?= json_encode(generateCsrfToken(), JSON_UNESCAPED_UNICODE) ?>;
    var catalog = <?= $catalogJson ?: '{}' ?>;
    var customTriggers = <?= $customTriggerJson ?: '{}' ?>;

    var deptSelect = document.getElementById('aiLabDepartment');
    var deptHint = document.getElementById('aiLabDeptHint');
    var catSelect = document.getElementById('aiLabCategory');
    var level3Wrap = document.getElementById('aiLabLevel3Wrap');
    var level3Select = document.getElementById('aiLabLevel3');
    var customWrap = document.getElementById('aiLabCustomWrap');
    var customGrid = document.getElementById('aiLabCustomGrid');
    var specimenSelect = document.getElementById('aiLabSpecimen');

    function fillSelect(select, options, placeholder) {
        select.innerHTML = '';
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        opt0.selected = true;
        opt0.disabled = true;
        select.appendChild(opt0);
        options.forEach(function (val) {
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val;
            select.appendChild(opt);
        });
    }

    function resolveSpecimens(deptName, catName) {
        var dept = catalog[deptName];
        if (!dept) return [];
        if (catName) {
            var cat = dept.categories[catName];
            if (cat && cat.specimens && cat.specimens.length) return cat.specimens;
        }
        return dept.specimens || [];
    }

    function updateSpecimens(deptName, catName) {
        var specimens = resolveSpecimens(deptName, catName);
        if (specimens.length) {
            fillSelect(specimenSelect, specimens, '-- Pilih Jenis Spesimen --');
            specimenSelect.disabled = false;
        } else {
            fillSelect(specimenSelect, [], '-- Tidak ada spesimen terdaftar --');
            specimenSelect.disabled = true;
        }
    }

    function resetLevel3() {
        level3Wrap.classList.add('hidden');
        level3Select.disabled = true;
        level3Select.required = false;
        fillSelect(level3Select, [], '-- Pilih Kategori dulu --');
    }

    function resetCustom() {
        customWrap.classList.add('hidden');
        customGrid.innerHTML = '';
    }

    function renderCustomParams(params) {
        customGrid.innerHTML = '';
        params.forEach(function (p) {
            var label = document.createElement('label');
            label.className = 'custom-checkbox-item';
            label.style.cssText = 'display:flex;align-items:center;gap:6px;font-weight:400;font-size:13px;';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'custom_parameters[]';
            cb.value = p;
            label.appendChild(cb);
            label.appendChild(document.createTextNode(p));
            customGrid.appendChild(label);
        });
        customWrap.classList.remove('hidden');
    }

    function evaluateCustomVisibility(catName, level3Value) {
        var triggers = customTriggers[catName] || [];
        if (level3Value && triggers.indexOf(level3Value) !== -1) {
            var dept = catalog[deptSelect.value];
            var cat = dept ? dept.categories[catName] : null;
            renderCustomParams((cat && cat.custom) || []);
        } else {
            resetCustom();
        }
    }

    deptSelect.addEventListener('change', function () {
        var deptName = this.value;
        var dept = catalog[deptName];
        deptHint.textContent = dept ? (dept.hint || '') : '';

        resetLevel3();
        resetCustom();

        if (!dept) {
            fillSelect(catSelect, [], '-- Pilih Departemen dulu --');
            catSelect.disabled = true;
            fillSelect(specimenSelect, [], '-- Pilih Departemen dulu --');
            specimenSelect.disabled = true;
            return;
        }

        fillSelect(catSelect, Object.keys(dept.categories), '-- Pilih Kategori --');
        catSelect.disabled = false;
        updateSpecimens(deptName, null);
    });

    catSelect.addEventListener('change', function () {
        var deptName = deptSelect.value;
        var catName = this.value;
        var dept = catalog[deptName];
        var cat = dept ? dept.categories[catName] : null;

        resetCustom();

        if (cat && cat.type === 'select') {
            fillSelect(level3Select, cat.options || [], '-- Pilih Opsi --');
            level3Select.disabled = false;
            level3Select.required = true;
            level3Wrap.classList.remove('hidden');
        } else {
            resetLevel3();
        }

        updateSpecimens(deptName, catName);
    });

    level3Select.addEventListener('change', function () {
        evaluateCustomVisibility(catSelect.value, this.value);
    });

    // Terapkan pilihan Department > Category > Level3 > Spesimen dari kode
    // laporan Diagnosis (kalau ada rekomendasi laboratorium terstruktur),
    // sama pola dengan applyCascadeSelection() di Radiology Center.
    function applyLabCascadeSelection(deptName, catName, level3Value, specimenValue) {
        var dept = catalog[deptName];
        if (!dept) { return false; }
        deptSelect.value = deptName;
        deptHint.textContent = dept.hint || '';

        var categories = Object.keys(dept.categories);
        fillSelect(catSelect, categories, '-- Pilih Kategori --');
        catSelect.disabled = false;
        if (!catName || categories.indexOf(catName) === -1) { return false; }
        catSelect.value = catName;

        var cat = dept.categories[catName];
        resetCustom();
        if (cat && cat.type === 'select') {
            fillSelect(level3Select, cat.options || [], '-- Pilih Opsi --');
            level3Select.disabled = false;
            level3Select.required = true;
            level3Wrap.classList.remove('hidden');
            if (!level3Value || (cat.options || []).indexOf(level3Value) === -1) {
                updateSpecimens(deptName, catName);
                return false;
            }
            level3Select.value = level3Value;
            evaluateCustomVisibility(catName, level3Value);
        } else {
            resetLevel3();
        }

        updateSpecimens(deptName, catName);
        var specimens = resolveSpecimens(deptName, catName);
        if (!specimenValue || specimens.indexOf(specimenValue) === -1) { return false; }
        specimenSelect.value = specimenValue;

        return true;
    }

    // ===== Ambil dari Laporan Diagnosis (auto-fill via kode referensi) =====
    var lookupBtn = document.getElementById('aiLabLookupBtn');
    var lookupInput = document.getElementById('aiLabLookupCode');
    var lookupNote = document.getElementById('aiLabLookupNote');
    var diagCodeHidden = document.getElementById('aiLabDiagCodeHidden');

    function showLookupMsg(text, isError, color) {
        lookupNote.textContent = text;
        lookupNote.style.color = color || (isError ? '#dc2626' : '#16a34a');
    }

    function formatUsedOnTargetWarning(data) {
        if (!data.used_on_target) return null;
        var who = data.used_on_target.user_name || 'pengguna lain';
        var when = data.used_on_target.created_at ? ' pada ' + data.used_on_target.created_at : '';
        return 'Kode referensi ini SUDAH PERNAH dipakai di halaman ini oleh ' + who + when + '. Data tetap diisi untuk ditinjau, tapi generate baru akan DITOLAK — gunakan tombol "Generate Ulang" di riwayat kalau ingin hasil baru dengan kode ini.';
    }

    // Kode di field tersembunyi hanya valid untuk kode yang BARU SAJA
    // berhasil di-fetch — kalau user mengetik ulang tanpa fetch lagi,
    // jangan ikut kirim kode lama.
    lookupInput.addEventListener('input', function () {
        diagCodeHidden.value = '';
    });

    lookupBtn && lookupBtn.addEventListener('click', function () {
        var code = (lookupInput.value || '').trim();
        if (!code) {
            showLookupMsg('Masukkan kode referensi terlebih dahulu.', true);
            return;
        }
        lookupBtn.disabled = true;
        showLookupMsg('Mengambil data laporan diagnosis...', false);
        lookupNote.style.color = '';
        fetch('ai_diagnosis_report_lookup.php?code=' + encodeURIComponent(code) + '&target=ai_laboratory_results', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                lookupBtn.disabled = false;
                if (!data.ok) {
                    showLookupMsg(data.message || 'Kode laporan tidak ditemukan.', true);
                    return;
                }
                if (data.patient_name) document.getElementById('aiLabPatientName').value = data.patient_name;
                if (data.patient_dob) document.getElementById('aiLabPatientDob').value = data.patient_dob;
                if (data.patient_citizen_id) document.getElementById('aiLabPatientCitizenId').value = data.patient_citizen_id;

                var clinicalParts = [];
                if (data.diagnosis_utama) clinicalParts.push('Diagnosis: ' + data.diagnosis_utama);
                if (data.anamnesis) clinicalParts.push('Anamnesis: ' + data.anamnesis);
                if (clinicalParts.length) {
                    document.getElementById('aiLabClinicalInfo').value = clinicalParts.join('\n\n');
                }

                diagCodeHidden.value = data.report_code || '';

                var applied = false;
                if (data.laboratorium_terstruktur) {
                    var lab = data.laboratorium_terstruktur;
                    applied = applyLabCascadeSelection(lab.department, lab.category, lab.level3_option, lab.specimen_type);
                }

                var usedWarning = formatUsedOnTargetWarning(data);
                if (usedWarning) {
                    showLookupMsg(usedWarning, false, '#d97706');
                } else if (data.laboratorium_terstruktur) {
                    showLookupMsg(
                        applied
                            ? 'Data dari laporan ' + data.report_code + ' berhasil diambil (identitas pasien + rekomendasi Departemen/Kategori/Spesimen).'
                            : 'Identitas pasien & info klinis berhasil diambil, tapi rekomendasi laboratorium laporan ini tidak cocok dengan katalog saat ini — isi manual.',
                        !applied
                    );
                } else {
                    showLookupMsg('Data berhasil diambil dari laporan ' + data.report_code + ', tapi laporan ini tidak punya rekomendasi laboratorium terstruktur — pilih Departemen/Kategori/Spesimen manual.', true);
                }
            })
            .catch(function () {
                lookupBtn.disabled = false;
                showLookupMsg('Gagal menghubungi server. Coba lagi.', true);
            });
    });

    // ---- Submit form via fetch + overlay progres ----
    var form = document.getElementById('aiLabForm');
    var overlay = document.getElementById('aiLabLoadingOverlay');
    var spinner = overlay.querySelector('.global-upload-spinner');
    var messageEl = document.getElementById('aiLabLoadingMessage');
    var bar = document.getElementById('aiLabLoadingBar');
    var pct = document.getElementById('aiLabLoadingPct');
    var errorBox = document.getElementById('aiLabLoadingErrorBox');
    var retryBtn = document.getElementById('aiLabLoadingRetryBtn');
    var submitBtn = document.getElementById('aiLabSubmitBtn');

    var target = 0, shown = 0, creepTimer = null, stageTimers = [];

    var STAGES = [
        { at: 0, pct: 8, text: 'Menyiapkan & memvalidasi konfigurasi...' },
        { at: 1500, pct: 20, text: 'Menyusun permintaan pemeriksaan...' },
        { at: 5000, pct: 35, text: 'Mengirim permintaan ke model AI...' },
        { at: 15000, pct: 55, text: 'Model AI menyusun hasil laboratorium...' },
        { at: 40000, pct: 75, text: 'Masih menganalisis, mohon tunggu...' },
        { at: 80000, pct: 88, text: 'Menyusun laporan hasil...' },
        { at: 120000, pct: 93, text: 'Respons sebelumnya kurang sesuai, mencoba ulang otomatis...' },
        { at: 180000, pct: 96, text: 'Percobaan ulang sedang diproses...' },
    ];

    function renderProgress() {
        bar.style.width = shown + '%';
        pct.textContent = Math.round(shown) + '%';
    }

    function startCreep() {
        stopCreep();
        creepTimer = setInterval(function () {
            if (shown < target) {
                shown = Math.min(target, shown + Math.max(0.3, (target - shown) / 6));
                renderProgress();
            }
        }, 150);
    }
    function stopCreep() { if (creepTimer) { clearInterval(creepTimer); creepTimer = null; } }
    function clearStages() { stageTimers.forEach(function (t) { clearTimeout(t); }); stageTimers = []; }
    function scheduleStages() {
        clearStages();
        STAGES.forEach(function (s) {
            stageTimers.push(setTimeout(function () {
                target = Math.max(target, Math.min(96, s.pct));
                messageEl.textContent = s.text;
            }, s.at));
        });
    }

    function resetOverlay() {
        spinner.classList.remove('hidden');
        errorBox.classList.add('hidden');
        retryBtn.classList.add('hidden');
        bar.style.background = '#0ea5e9';
        target = 0; shown = 0;
        messageEl.textContent = 'Menyiapkan permintaan...';
        renderProgress();
    }

    function showError(msg) {
        stopCreep(); clearStages();
        spinner.classList.add('hidden');
        bar.style.background = '#e11d48';
        messageEl.textContent = 'Gagal';
        errorBox.textContent = msg;
        errorBox.classList.remove('hidden');
        retryBtn.classList.remove('hidden');
        submitBtn.disabled = false;
    }

    function finishSuccess(reportId) {
        stopCreep(); clearStages();
        target = 100; shown = 100; renderProgress();
        messageEl.textContent = 'Selesai, membuka laporan...';
        window.location.href = 'laboratory_ai_report.php?id=' + encodeURIComponent(reportId);
    }

    retryBtn.addEventListener('click', function () {
        overlay.classList.add('hidden');
        overlay.setAttribute('aria-hidden', 'true');
        submitBtn.disabled = false;
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        submitBtn.disabled = true;
        resetOverlay();
        overlay.classList.remove('hidden');
        overlay.setAttribute('aria-hidden', 'false');
        startCreep();
        scheduleStages();

        fetch('laboratory_ai_action.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                if (!result.ok || !result.data.ok || !result.data.report_id) {
                    showError((result.data && result.data.message) || 'Gagal memproses hasil laboratorium.');
                    return;
                }
                finishSuccess(result.data.report_id);
            })
            .catch(function () {
                showError('Tidak dapat menghubungi server (koneksi terputus atau timeout). Cek koneksi lalu coba lagi.');
            });
    });

    // ===== Generate Ulang (dari riwayat, pakai ulang input + kode referensi asal) =====
    document.querySelectorAll('.lab-regenerate-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Generate ulang hasil laboratorium ini? Input & kode referensi yang sama akan dipakai lagi untuk minta hasil AI yang baru.')) {
                return;
            }
            btn.disabled = true;
            resetOverlay();
            overlay.classList.remove('hidden');
            overlay.setAttribute('aria-hidden', 'false');
            startCreep();
            scheduleStages();

            var fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('regenerate_of', btn.getAttribute('data-id'));

            fetch('laboratory_ai_action.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
                .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
                .then(function (result) {
                    btn.disabled = false;
                    if (!result.ok || !result.data.ok || !result.data.report_id) {
                        showError((result.data && result.data.message) || 'Gagal generate ulang.');
                        return;
                    }
                    finishSuccess(result.data.report_id);
                })
                .catch(function () {
                    btn.disabled = false;
                    showError('Tidak dapat menghubungi server (koneksi terputus atau timeout). Cek koneksi lalu coba lagi.');
                });
        });
    });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
