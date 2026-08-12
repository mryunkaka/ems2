<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/ai_radiology.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'radiology_center.php', '/dashboard/index.php');
ems_ai_radiology_ensure_tables($pdo);

$pageTitle = 'Radiology Center | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$recentStmt = $pdo->prepare("
    SELECT r.id, r.patient_name, r.modality, r.body_region, r.clinical_finding, r.image_path, r.status, r.created_at, u.full_name AS created_by_name
    FROM ai_radiology_images r
    LEFT JOIN user_rh u ON u.id = r.user_id
    WHERE r.unit_code = ?
    ORDER BY r.id DESC
    LIMIT 15
");
$recentStmt->execute([$effectiveUnit]);
$recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$hasOwnApiKey = trim((string) (ems_ai_ds_get_user_settings($pdo, (int) ($user['id'] ?? 0))['gemini_api_key'] ?? '')) !== '';
$canDelete = ems_is_manager_plus_role($user['role'] ?? '');

$catalog = ems_ai_radiology_catalog();
$modalities = ems_ai_radiology_modalities();
$clinicalFindings = ems_ai_radiology_clinical_findings();

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between mb-4">
            <div>
                <h1 class="page-title">Radiology Center</h1>
                <p class="page-subtitle">Generate citra pencitraan medis (X-Ray, CT Scan, MRI, Ultrasound, Angiography, PET Scan, Mammography, Fluoroscopy, DEXA Scan) simulasi untuk kebutuhan roleplay memakai model AI Gemini.</p>
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
                Anda belum mengatur API key Gemini pribadi. <a href="ai_settings_personal.php" class="font-bold underline">Atur sekarang di Setting AI Saya</a> sebelum generate citra.
            </div>
        <?php endif; ?>

        <div id="radFormError" class="hidden alert alert-error"></div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
            <div class="card">
                <div class="card-header">Konfigurasi Pencitraan</div>
                <div class="card-section" style="background:#f0f9ff;border-bottom:1px solid #e2e8f0;">
                    <label style="margin-top:0;">Ambil dari Laporan Diagnosis (opsional)</label>
                    <div class="flex items-center gap-2">
                        <input type="text" id="radDiagCode" placeholder="Tempel kode referensi, mis. DGN-20260812-143012-A1B2" style="flex:1;">
                        <button type="button" id="radDiagFetchBtn" class="btn-secondary btn-sm" style="white-space:nowrap;">
                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                            <span>Ambil Data</span>
                        </button>
                    </div>
                    <p id="radDiagFetchNote" class="page-subtitle" style="margin-top:6px;font-size:12px;">Kode ini muncul di halaman Laporan Diagnosis (ai_diagnosis_report.php) — kalau laporan itu punya rekomendasi radiologi terstruktur, Modality/Category/Body Region/Projection/Temuan Klinis di bawah akan terisi otomatis.</p>
                </div>
                <form method="POST" action="radiology_center_action.php" class="form" id="radForm">
                    <?= csrfField(); ?>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label>Modality</label>
                            <select name="modality" id="radModality" required>
                                <option value="" disabled selected>-- Pilih Modality --</option>
                                <?php foreach ($modalities as $modality): ?>
                                    <option value="<?= htmlspecialchars($modality, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($modality, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Category</label>
                            <select name="category" id="radCategory" required disabled>
                                <option value="" disabled selected>-- Pilih Modality dulu --</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label>Body Region</label>
                            <select name="body_region" id="radBodyRegion" required disabled>
                                <option value="" disabled selected>-- Pilih Category dulu --</option>
                            </select>
                        </div>
                        <div>
                            <label>Projection / Options</label>
                            <select name="projection" id="radProjection" required disabled>
                                <option value="" disabled selected>-- Pilih Body Region dulu --</option>
                            </select>
                        </div>
                    </div>

                    <label class="mt-4">Temuan Klinis</label>
                    <select name="clinical_finding" id="radClinicalFinding" required>
                        <option value="" disabled selected>-- Pilih Temuan --</option>
                        <?php foreach ($clinicalFindings as $finding): ?>
                            <option value="<?= htmlspecialchars($finding, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($finding, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="mt-4">Anamnesis / Kasus (opsional, memperkaya konteks gambar)</label>
                    <textarea name="anamnesis" id="radAnamnesis" rows="3" placeholder="Contoh: pasien jatuh dari motor, nyeri hebat pada tungkai kanan bawah..."><?= htmlspecialchars($_POST['anamnesis'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label>Nama Pasien</label>
                            <input type="text" name="patient_name" id="radPatientName" placeholder="Nama pasien">
                        </div>
                        <div>
                            <label>Tanggal Lahir</label>
                            <input type="date" name="patient_dob" id="radPatientDob">
                        </div>
                        <div>
                            <label>Citizen ID</label>
                            <input type="text" name="patient_citizen_id" id="radPatientCitizenId" placeholder="RWX-...">
                        </div>
                    </div>

                    <label class="mt-4">Dokter Pemeriksa</label>
                    <input type="text" name="doctor_name" id="radDoctorName" placeholder="Nama dokter" value="<?= htmlspecialchars((string) ($user['full_name'] ?? $user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary" id="radSubmitBtn">
                            <?= ems_icon('sparkles', 'h-4 w-4') ?>
                            <span>Generate Imaging</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Riwayat Terbaru</div>
                <div class="table-wrapper">
                    <table id="radHistoryTable" class="table-custom" data-auto-datatable="true">
                        <thead>
                            <tr>
                                <th>Preview</th>
                                <th>Tanggal</th>
                                <th>Pasien</th>
                                <th>Modality / Region</th>
                                <th>Oleh</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRows as $row): ?>
                                <tr>
                                    <td>
                                        <?php if ($row['status'] === 'done' && !empty($row['image_path'])): ?>
                                            <img src="<?= htmlspecialchars(ems_secure_file_url((string) $row['image_path']), ENT_QUOTES, 'UTF-8') ?>" alt="Preview" style="width:52px;height:52px;object-fit:cover;border-radius:8px;background:#000;">
                                        <?php else: ?>
                                            <span class="meta-text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $row['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['patient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars((string) $row['modality'], ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string) $row['body_region'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($row['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="<?= $row['status'] === 'done' ? 'badge-success' : 'badge-danger' ?>">
                                            <?= $row['status'] === 'done' ? 'Selesai' : 'Gagal' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="radiology_report.php?id=<?= (int) $row['id'] ?>" class="btn-secondary btn-sm">
                                                <?= ems_icon('eye', 'h-4 w-4') ?>
                                                <span>Lihat</span>
                                            </a>
                                            <?php if ($canDelete): ?>
                                                <form method="POST" action="radiology_report.php?id=<?= (int) $row['id'] ?>" onsubmit="return confirm('Hapus citra radiologi #<?= (int) $row['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
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
                                <tr><td colspan="7" class="text-center text-slate-400 py-6">Belum ada riwayat citra radiologi.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<div id="radLoadingOverlay" class="global-upload-overlay hidden" aria-hidden="true">
    <div class="global-upload-overlay-box">
        <div class="global-upload-spinner" aria-hidden="true"></div>
        <div class="global-upload-title">Generate citra pencitraan...</div>
        <div id="radLoadingMessage" class="global-upload-copy">Menyiapkan permintaan...</div>
        <div id="radLoadingBarWrap" style="margin-top:14px;height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;">
            <div id="radLoadingBar" style="height:100%;width:0%;border-radius:999px;background:#0ea5e9;transition:width .3s ease;"></div>
        </div>
        <div id="radLoadingPct" style="margin-top:6px;font-size:12px;font-weight:800;color:#0284c7;">0%</div>
        <div id="radLoadingErrorBox" class="hidden alert alert-error" style="margin-top:12px;text-align:left;"></div>
        <button type="button" id="radLoadingRetryBtn" class="btn-secondary hidden" style="margin-top:10px;">Tutup & Coba Lagi</button>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('radForm');
    if (!form) return;

    var CATALOG = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>;
    var modalitySelect = document.getElementById('radModality');
    var categorySelect = document.getElementById('radCategory');
    var bodyRegionSelect = document.getElementById('radBodyRegion');
    var projectionSelect = document.getElementById('radProjection');

    function fillSelect(select, options, placeholderText) {
        select.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = true;
        placeholder.textContent = placeholderText;
        select.appendChild(placeholder);

        if (!options.length) {
            select.disabled = true;
            return;
        }

        select.disabled = false;
        options.forEach(function (opt) {
            var el = document.createElement('option');
            el.value = opt;
            el.textContent = opt;
            select.appendChild(el);
        });
    }

    function resetDownstream(fromLevel) {
        if (fromLevel <= 1) { fillSelect(categorySelect, [], '-- Pilih Modality dulu --'); }
        if (fromLevel <= 2) { fillSelect(bodyRegionSelect, [], '-- Pilih Category dulu --'); }
        if (fromLevel <= 3) { fillSelect(projectionSelect, [], '-- Pilih Body Region dulu --'); }
    }

    modalitySelect.addEventListener('change', function () {
        var categories = Object.keys(CATALOG[modalitySelect.value] || {});
        fillSelect(categorySelect, categories, '-- Pilih Category --');
        resetDownstream(2);
    });

    categorySelect.addEventListener('change', function () {
        var regions = Object.keys((CATALOG[modalitySelect.value] || {})[categorySelect.value] || {});
        fillSelect(bodyRegionSelect, regions, '-- Pilih Body Region --');
        resetDownstream(3);
    });

    bodyRegionSelect.addEventListener('change', function () {
        var projections = ((CATALOG[modalitySelect.value] || {})[categorySelect.value] || {})[bodyRegionSelect.value] || [];
        fillSelect(projectionSelect, projections, '-- Pilih Projection --');
    });

    // Isi 4 dropdown cascading sekaligus secara terprogram (dipakai auto-fill dari
    // kode referensi diagnosis) — jalankan cascade yang sama persis seperti kalau
    // user memilih manual satu-satu, supaya options di tiap level ikut terisi benar.
    function applyCascadeSelection(modality, category, bodyRegion, projection) {
        if (!modality || !(modality in CATALOG)) { return false; }
        modalitySelect.value = modality;

        var categories = Object.keys(CATALOG[modality] || {});
        fillSelect(categorySelect, categories, '-- Pilih Category --');
        if (!category || categories.indexOf(category) === -1) { return false; }
        categorySelect.value = category;

        var regions = Object.keys((CATALOG[modality] || {})[category] || {});
        fillSelect(bodyRegionSelect, regions, '-- Pilih Body Region --');
        if (!bodyRegion || regions.indexOf(bodyRegion) === -1) { return false; }
        bodyRegionSelect.value = bodyRegion;

        var projections = ((CATALOG[modality] || {})[category] || {})[bodyRegion] || [];
        fillSelect(projectionSelect, projections, '-- Pilih Projection --');
        if (!projection || projections.indexOf(projection) === -1) { return false; }
        projectionSelect.value = projection;

        return true;
    }

    // ===== Ambil dari Laporan Diagnosis (auto-fill via kode referensi) =====
    var diagCodeInput = document.getElementById('radDiagCode');
    var diagFetchBtn = document.getElementById('radDiagFetchBtn');
    var diagFetchNote = document.getElementById('radDiagFetchNote');
    var clinicalFindingSelect = document.getElementById('radClinicalFinding');
    var anamnesisTextarea = document.getElementById('radAnamnesis');
    var patientNameInput = document.getElementById('radPatientName');
    var patientDobInput = document.getElementById('radPatientDob');
    var patientCitizenIdInput = document.getElementById('radPatientCitizenId');

    diagFetchBtn && diagFetchBtn.addEventListener('click', function () {
        var code = (diagCodeInput.value || '').trim();
        if (!code) {
            diagFetchNote.textContent = 'Masukkan kode referensi terlebih dahulu.';
            diagFetchNote.style.color = '#dc2626';
            return;
        }

        diagFetchBtn.disabled = true;
        diagFetchNote.textContent = 'Mengambil data laporan diagnosis...';
        diagFetchNote.style.color = '';

        fetch('ai_diagnosis_report_lookup.php?code=' + encodeURIComponent(code), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                diagFetchBtn.disabled = false;
                if (!result.ok || !result.data.ok) {
                    diagFetchNote.textContent = (result.data && result.data.message) || 'Laporan diagnosis tidak ditemukan.';
                    diagFetchNote.style.color = '#dc2626';
                    return;
                }

                var data = result.data;
                if (data.anamnesis) {
                    anamnesisTextarea.value = data.anamnesis;
                }
                if (data.patient_name) {
                    patientNameInput.value = data.patient_name;
                }
                if (data.patient_dob) {
                    patientDobInput.value = data.patient_dob;
                }
                if (data.patient_citizen_id) {
                    patientCitizenIdInput.value = data.patient_citizen_id;
                }

                var rad = data.radiologi_terstruktur;
                if (rad && rad.modality) {
                    var applied = applyCascadeSelection(rad.modality, rad.category, rad.body_region, rad.projection);
                    if (rad.clinical_finding) {
                        clinicalFindingSelect.value = rad.clinical_finding;
                    }
                    diagFetchNote.textContent = applied
                        ? 'Data dari laporan #' + data.report_id + ' berhasil diambil (identitas pasien + rekomendasi Modality/Category/Body Region/Projection).'
                        : 'Identitas pasien & anamnesis berhasil diambil, tapi rekomendasi radiologi laporan ini tidak cocok dengan katalog saat ini — isi manual.';
                    diagFetchNote.style.color = applied ? '#059669' : '#d97706';
                } else {
                    diagFetchNote.textContent = 'Identitas pasien & anamnesis berhasil diambil dari laporan #' + data.report_id + ', tapi laporan ini tidak punya rekomendasi radiologi terstruktur — pilih Modality/Category/Body Region/Projection manual.';
                    diagFetchNote.style.color = '#d97706';
                }
            })
            .catch(function () {
                diagFetchBtn.disabled = false;
                diagFetchNote.textContent = 'Gagal menghubungi server. Coba lagi.';
                diagFetchNote.style.color = '#dc2626';
            });
    });

    var overlay = document.getElementById('radLoadingOverlay');
    var spinner = overlay.querySelector('.global-upload-spinner');
    var messageEl = document.getElementById('radLoadingMessage');
    var bar = document.getElementById('radLoadingBar');
    var pct = document.getElementById('radLoadingPct');
    var errorBox = document.getElementById('radLoadingErrorBox');
    var retryBtn = document.getElementById('radLoadingRetryBtn');
    var submitBtn = document.getElementById('radSubmitBtn');

    var target = 0, shown = 0, creepTimer = null, stageTimers = [];

    var STAGES = [
        { at: 0, pct: 10, text: 'Menyiapkan & memvalidasi konfigurasi pencitraan...' },
        { at: 1500, pct: 24, text: 'Menyusun prompt citra medis...' },
        { at: 4000, pct: 38, text: 'Mengirim permintaan ke model AI Gemini...' },
        { at: 12000, pct: 60, text: 'Model AI sedang menggambar citra...' },
        { at: 30000, pct: 78, text: 'Masih memproses, mohon tunggu...' },
        { at: 60000, pct: 90, text: 'Proses lebih lama dari biasanya, tetap menunggu respons...' }
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
    function finishSuccess(imageId) {
        stopCreep(); clearStages();
        target = 100; shown = 100; renderProgress();
        messageEl.textContent = 'Selesai, membuka hasil citra...';
        window.location.href = 'radiology_report.php?id=' + encodeURIComponent(imageId);
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

        fetch('radiology_center_action.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                if (!result.ok || !result.data.ok || !result.data.image_id) {
                    showError((result.data && result.data.message) || 'Gagal generate citra radiologi.');
                    return;
                }
                finishSuccess(result.data.image_id);
            })
            .catch(function () {
                showError('Tidak dapat menghubungi server (koneksi terputus atau timeout). Cek koneksi lalu coba lagi.');
            });
    });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
