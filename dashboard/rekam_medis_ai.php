<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pageTitle = 'Rekam Medis AI | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$hasJenisOperasiColumn = ems_column_exists($pdo, 'medical_records', 'jenis_operasi');

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell">
        <h1 class="page-title">Rekam Medis AI</h1>
        <p class="page-subtitle">Rekam medis otomatis dari kode referensi AI Diagnosis Assistant — tarik data AI Surgery Planner, Radiology Center, Laboratory AI, dan Psychiatry Center (opsional) sekaligus. Anda hanya perlu upload KTP pasien, pilih tim medis, dan tempel kode referensi.</p>

        <?php foreach ($messages as $message): ?>
            <?= ems_render_toast_script((string) $message, 'info', 'Rekam Medis AI') ?>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <?= ems_render_toast_script((string) $error, 'error', 'Rekam Medis AI', 6800) ?>
        <?php endforeach; ?>

        <div id="rmaiFormError" class="hidden alert alert-error mb-4"></div>

        <form id="medical-record-form" method="POST" action="rekam_medis_action.php" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="visibility_scope" value="standard">
            <input type="hidden" name="redirect_to" value="rekam_medis_list.php">
            <input type="hidden" name="mode" value="standard">
            <input type="hidden" name="patient_name" id="rmaiPatientName">
            <input type="hidden" name="patient_citizen_id" id="rmaiPatientCitizenId">
            <input type="hidden" name="patient_dob" id="rmaiPatientDob">
            <input type="hidden" name="patient_gender" id="rmaiPatientGender">
            <input type="hidden" name="medical_result_html" id="medical_result_html">

            <!-- CARD 1: KODE REFERENSI -->
            <div class="card mb-4">
                <div class="card-header">Kode Referensi AI Diagnosis Assistant</div>
                <div class="card-section" style="background:#f0f9ff;border-bottom:1px solid #e2e8f0;">
                    <label style="margin-top:0;">Ambil dari Laporan Diagnosis</label>
                    <div class="flex items-center gap-2">
                        <input type="text" id="rmaiCode" placeholder="Tempel kode referensi, mis. DGN-20260812-143012-A1B2" style="flex:1;">
                        <button type="button" id="rmaiFetchBtn" class="btn-secondary btn-sm" style="white-space:nowrap;">
                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                            <span>Ambil Data</span>
                        </button>
                    </div>
                    <p id="rmaiFetchNote" class="page-subtitle" style="margin-top:6px;font-size:12px;">Kode ini muncul di halaman Laporan Diagnosis (ai_diagnosis_report.php) — sistem otomatis menarik data yang berelasi dari AI Surgery Planner, Radiology Center, Laboratory AI, dan Psychiatry Center (opsional) kalau kode itu sudah pernah dipakai di modul-modul tersebut.</p>
                    <div id="rmaiSourceBadges" class="flex flex-wrap gap-2 mt-3"></div>
                </div>
            </div>

            <!-- CARD 2: DATA PASIEN (READ-ONLY, DARI AI) -->
            <div class="card card-section mb-4">
                <div class="card-header">Identitas Pasien (dari AI Diagnosis Assistant)</div>
                <div class="card-body">
                    <div id="rmaiPatientPreview" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <p class="text-sm text-gray-400 md:col-span-4">Ambil data dari kode referensi terlebih dahulu.</p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 pt-4" style="border-top:1px solid #e2e8f0;">
                        <div class="form-group">
                            <label class="form-label">Pekerjaan</label>
                            <input type="text" name="patient_occupation" class="form-input"
                                value="Civilian" placeholder="Pekerjaan pasien" />
                        </div>

                        <div class="form-group">
                            <label class="form-label">No HP</label>
                            <input type="text" name="patient_phone" class="form-input"
                                placeholder="Nomor HP pasien" />
                        </div>

                        <div class="form-group">
                            <label class="form-label">Alamat</label>
                            <input type="text" name="patient_address" class="form-input"
                                value="INDONESIA" placeholder="Alamat pasien" />
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <input type="text" name="patient_status" class="form-input"
                                placeholder="Status pasien (opsional)" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 3: UPLOAD DOKUMEN -->
            <div class="card card-section mb-4">
                <div class="card-header">Upload Dokumen</div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">KTP Pasien <span class="text-danger">*</span></label>
                            <div class="file-upload-wrapper">
                                <input type="file" id="ktp_file" name="ktp_file" accept="image/png,image/jpeg" hidden required>
                                <label for="ktp_file" class="file-upload-label">
                                    <div class="preview-container h-48 flex items-center justify-center bg-gray-50 rounded border border-gray-200" id="ktp_preview">
                                        <span class="text-gray-400 text-sm">Belum ada file</span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <span class="btn-secondary btn-sm">Pilih File / Ambil Foto</span>
                                    </div>
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Format: JPG/PNG, Max: 1MB per file</p>
                            </div>
                        </div>

                        <div>
                            <label class="form-label">Foto MRI/CT Scan/USG/Dll (Opsional)</label>
                            <div class="file-upload-wrapper">
                                <input type="file" id="supporting_image_files" name="supporting_image_files[]" accept="image/png,image/jpeg" hidden multiple>
                                <label for="supporting_image_files" class="file-upload-label">
                                    <div class="preview-container min-h-48 p-3 flex items-center justify-center bg-gray-50 rounded border border-gray-200" id="supporting_images_preview">
                                        <span class="text-gray-400 text-sm">Belum ada file</span>
                                    </div>
                                    <div class="mt-2 text-center">
                                        <span class="btn-secondary btn-sm">Pilih Beberapa File / Ambil Foto</span>
                                    </div>
                                </label>
                                <p class="text-xs text-gray-500 mt-1">Format: JPG/PNG, bisa pilih banyak file, max <?= htmlspecialchars(emsUploadLimitLabel(), ENT_QUOTES, 'UTF-8') ?> per file</p>
                                <p id="rmaiRadiologyAttachNote" class="text-xs mt-1 hidden"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CARD 4: TIM MEDIS & OPERASI -->
            <div class="card card-section mb-4">
                <div class="card-header">Tim Medis &amp; Operasi</div>
                <div class="card-body">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">Dokter DPJP <span class="text-danger">*</span></label>
                            <div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="doctor" data-autocomplete-required>
                                <input type="text" class="form-input" data-user-autocomplete-input placeholder="Ketik nama dokter..." required>
                                <input type="hidden" name="doctor_id" data-user-autocomplete-hidden>
                                <div class="ems-suggestion-box" data-user-autocomplete-list></div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Minimal jabatan: Co.Ast ke atas</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Jenis Operasi <span class="text-danger">*</span></label>
                            <div class="flex gap-4 mt-2">
                                <label class="radio-label flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="operasi_type" id="rmaiOperasiMinor" value="minor" checked class="w-4 h-4 text-primary">
                                    <span>Minor (Kecil)</span>
                                </label>
                                <label class="radio-label flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="operasi_type" id="rmaiOperasiMayor" value="major" class="w-4 h-4 text-primary">
                                    <span>Mayor (Besar)</span>
                                </label>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Otomatis terisi dari data AI Surgery Planner — bisa diubah manual.</p>
                        </div>

                        <div class="form-group md:col-span-2">
                            <label class="form-label">Nama / Jenis Operasi</label>
                            <input type="text" name="jenis_operasi" id="rmaiJenisOperasi" class="form-input" placeholder="Otomatis terisi dari data AI setelah Ambil Data">
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="form-label">Asisten Operasi <span class="text-danger">*</span></label>
                        <div id="assistants-container">
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
                                    <button type="button" onclick="rmaiRemoveAssistant(this)" class="text-red-500 hover:text-red-700" title="Hapus">
                                        <?= ems_icon('trash', 'h-4 w-4') ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" onclick="rmaiAddAssistant()" class="btn-secondary btn-sm mt-2">
                            <?= ems_icon('plus', 'h-4 w-4') ?>
                            <span>Tambah Asisten</span>
                        </button>
                        <p class="text-xs text-gray-500 mt-1">Minimal 1 asisten wajib dipilih. Minimal jabatan: Paramedic ke atas.</p>
                    </div>
                </div>
            </div>

            <!-- CARD 5: PREVIEW DATA AI (PER-FIELD COPYABLE) -->
            <div id="rmaiPreviewCard" class="card card-section mb-4 hidden">
                <div class="card-header">Preview Data AI (per bagian bisa disalin)</div>
                <div class="card-body">
                    <div id="rmaiPreviewSections" class="space-y-4"></div>
                </div>
            </div>

            <!-- CARD 6: HASIL REKAM MEDIS (EDITOR) -->
            <div class="card card-section mb-4">
                <div class="card-header">Hasil Rekam Medis (Draft AI — bisa diedit sebelum simpan)</div>
                <div class="card-body">
                    <p class="text-sm text-gray-600 mb-2">Draf tersusun otomatis dari seluruh data AI di atas setelah "Ambil Data" berhasil. Tinjau &amp; edit seperlunya sebelum menyimpan.</p>
                    <div id="editor-container" class="min-h-[500px]"></div>
                </div>
            </div>

            <div class="flex justify-end items-center mt-6 gap-3">
                <a href="rekam_medis_list.php" class="btn-secondary">Batal</a>
                <button type="submit" id="rmaiSubmitBtn" class="btn-primary" disabled>
                    <?= ems_icon('clipboard-document-check', 'h-4 w-4') ?>
                    <span>Simpan Rekam Medis</span>
                </button>
            </div>
        </form>
    </div>
</section>

<div id="rmaiGenOverlay" class="global-upload-overlay hidden" aria-hidden="true">
    <div class="global-upload-overlay-box">
        <div class="global-upload-spinner" aria-hidden="true"></div>
        <div class="global-upload-title">Menyusun rekam medis lengkap...</div>
        <div id="rmaiGenMessage" class="global-upload-copy">Menyiapkan permintaan...</div>
        <div id="rmaiGenBarWrap" style="margin-top:14px;height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;">
            <div id="rmaiGenBar" style="height:100%;width:0%;border-radius:999px;background:#0ea5e9;transition:width .3s ease;"></div>
        </div>
        <div id="rmaiGenPct" style="margin-top:6px;font-size:12px;font-weight:800;color:#0284c7;">0%</div>
        <div id="rmaiGenErrorBox" class="hidden alert alert-error" style="margin-top:12px;text-align:left;"></div>
        <button type="button" id="rmaiGenRetryBtn" class="btn-secondary hidden" style="margin-top:10px;">Tutup</button>
    </div>
</div>

<style>
#editor-container .ql-editor { min-height: 480px; line-height: 1.75; color: #0f172a; }
#editor-container .ql-editor h1 { margin: 0 0 1.75rem; text-align: center; font-size: 2rem; font-weight: 800; }
#editor-container .ql-editor h2 { margin: 2.4rem 0 0.9rem; font-size: 1.35rem; font-weight: 800; letter-spacing: 0.01em; }
#editor-container .ql-editor p { margin: 0.45rem 0; }
#editor-container .ql-editor ul, #editor-container .ql-editor ol { margin: 0.8rem 0 1rem; padding-left: 1.5rem; }
.rmai-field { display:flex; align-items:flex-start; justify-content:space-between; gap:0.75rem; border:1px solid #e2e8f0; border-radius:0.6rem; padding:0.6rem 0.8rem; background:#f8fafc; }
.rmai-field__label { font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; display:block; margin-bottom:0.2rem; }
.rmai-field__value { font-size:0.85rem; color:#0f172a; white-space:pre-line; }
.rmai-section { border:1px solid #e2e8f0; border-radius:0.75rem; padding:1rem 1.1rem; background:#fff; }
.rmai-section-title { display:flex; align-items:center; gap:0.6rem; font-size:0.95rem; font-weight:800; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:0.6rem; margin-bottom:0.75rem; }
.rmai-section-number { flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; width:1.6rem; height:1.6rem; border-radius:999px; background:#0ea5e9; color:#fff; font-size:0.8rem; font-weight:800; }
.rmai-section-note { margin-top:0.6rem; font-size:0.72rem; color:#94a3b8; font-style:italic; }
.rmai-copy-btn { flex-shrink:0; border:1px solid #cbd5e1; background:#fff; border-radius:0.4rem; padding:0.25rem 0.55rem; font-size:0.7rem; font-weight:700; color:#334155; cursor:pointer; }
.rmai-copy-btn:hover { background:#f1f5f9; }
.rmai-badge { display:inline-flex; align-items:center; gap:0.3rem; font-size:0.7rem; font-weight:700; padding:0.25rem 0.6rem; border-radius:999px; }
.rmai-badge--ok { background:#dcfce7; color:#166534; border:1px solid #86efac; }
.rmai-badge--missing { background:#f1f5f9; color:#94a3b8; border:1px solid #e2e8f0; }
</style>

<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">

<script>
(function () {
    var CSRF_TOKEN = <?= json_encode(generateCsrfToken(), JSON_UNESCAPED_UNICODE) ?>;
    var lastData = null;
    var autoAttachedRadiologyFile = null;

    var fetchBtn = document.getElementById('rmaiFetchBtn');
    var codeInput = document.getElementById('rmaiCode');
    var fetchNote = document.getElementById('rmaiFetchNote');
    var badgesEl = document.getElementById('rmaiSourceBadges');
    var patientPreview = document.getElementById('rmaiPatientPreview');
    var previewCard = document.getElementById('rmaiPreviewCard');
    var previewSections = document.getElementById('rmaiPreviewSections');
    var formError = document.getElementById('rmaiFormError');

    window.quill = null;
    document.addEventListener('DOMContentLoaded', function () {
        window.quill = new Quill('#editor-container', {
            theme: 'snow',
            placeholder: 'Tempel kode referensi lalu klik "Ambil Data" untuk membuat draf otomatis...',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, 4, 5, 6, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    [{ 'align': [] }],
                    [{ 'color': [] }, { 'background': [] }],
                    ['link'], ['clean']
                ]
            }
        });
        window.quill.on('text-change', function () {
            document.getElementById('medical_result_html').value = window.quill.root.innerHTML;
        });
        if (window.emsInitUserAutocomplete) window.emsInitUserAutocomplete(document);
    });

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text === null || text === undefined ? '' : String(text);
        return d.innerHTML;
    }

    function showFormError(msg) {
        formError.textContent = msg;
        formError.classList.remove('hidden');
    }
    function clearFormError() {
        formError.classList.add('hidden');
    }

    // ===== Ambil Data =====
    fetchBtn.addEventListener('click', function () {
        var code = (codeInput.value || '').trim();
        if (!code) {
            fetchNote.textContent = 'Masukkan kode referensi terlebih dahulu.';
            fetchNote.style.color = '#dc2626';
            return;
        }
        clearFormError();
        fetchBtn.disabled = true;
        fetchNote.textContent = 'Mengambil data dari seluruh modul AI...';
        fetchNote.style.color = '';

        fetch('rekam_medis_ai_lookup.php?code=' + encodeURIComponent(code), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                fetchBtn.disabled = false;
                if (!result.ok || !result.data.ok) {
                    fetchNote.textContent = (result.data && result.data.message) || 'Kode referensi tidak ditemukan.';
                    fetchNote.style.color = '#dc2626';
                    return;
                }
                lastData = result.data;
                renderBadges(lastData);
                renderPatient(lastData.diagnosis);
                renderPreview(lastData);
                applyOperationDefaults(lastData);
                attachRadiologyImage(lastData.radiology);

                var missing = [];
                if (!lastData.diagnosis.patient_name) missing.push('Nama Pasien');
                if (!lastData.diagnosis.patient_citizen_id) missing.push('Citizen ID');
                if (!lastData.diagnosis.patient_dob) missing.push('Tanggal Lahir');
                if (!lastData.diagnosis.patient_gender) missing.push('Jenis Kelamin');

                if (missing.length) {
                    document.getElementById('rmaiSubmitBtn').disabled = true;
                    fetchNote.textContent = 'Data berhasil diambil, TAPI identitas pasien di laporan diagnosis ' + lastData.diagnosis.report_code + ' belum lengkap (' + missing.join(', ') + '). Lengkapi dulu identitas pasien itu di AI Diagnosis Assistant, lalu klik "Ambil Data" lagi — rekam medis tidak bisa disimpan tanpa identitas pasien lengkap.';
                    fetchNote.style.color = '#dc2626';
                    return;
                }

                fetchNote.textContent = 'Data berhasil diambil dari laporan ' + lastData.diagnosis.report_code + '. Menyusun draf rekam medis lengkap dengan AI...';
                fetchNote.style.color = '#059669';
                generateFullRecord(code);
            })
            .catch(function () {
                fetchBtn.disabled = false;
                fetchNote.textContent = 'Gagal menghubungi server. Coba lagi.';
                fetchNote.style.color = '#dc2626';
            });
    });

    // ===== Susun narasi rekam medis lengkap via Gemini =====
    var genOverlay = document.getElementById('rmaiGenOverlay');
    var genSpinner = genOverlay.querySelector('.global-upload-spinner');
    var genMessage = document.getElementById('rmaiGenMessage');
    var genBar = document.getElementById('rmaiGenBar');
    var genPct = document.getElementById('rmaiGenPct');
    var genErrorBox = document.getElementById('rmaiGenErrorBox');
    var genRetryBtn = document.getElementById('rmaiGenRetryBtn');
    var genTarget = 0, genShown = 0, genCreepTimer = null, genStageTimers = [];

    var GEN_STAGES = [
        { at: 0, pct: 10, text: 'Mengumpulkan data Diagnosis/Surgery/Radiology/Laboratory/Psychiatry...' },
        { at: 3000, pct: 25, text: 'Mengirim konteks kasus ke model AI...' },
        { at: 10000, pct: 45, text: 'Model AI menyusun anamnesis & status klinis...' },
        { at: 25000, pct: 65, text: 'Model AI menyusun laporan tindakan & status pasca operasi...' },
        { at: 45000, pct: 82, text: 'Menyelesaikan narasi rekam medis...' },
        { at: 70000, pct: 92, text: 'Respons sebelumnya kurang sesuai, mencoba ulang otomatis...' },
    ];

    function genRenderProgress() {
        genBar.style.width = genShown + '%';
        genPct.textContent = Math.round(genShown) + '%';
    }
    function genStartCreep() {
        genStopCreep();
        genCreepTimer = setInterval(function () {
            if (genShown < genTarget) {
                genShown = Math.min(genTarget, genShown + Math.max(0.3, (genTarget - genShown) / 6));
                genRenderProgress();
            }
        }, 150);
    }
    function genStopCreep() { if (genCreepTimer) { clearInterval(genCreepTimer); genCreepTimer = null; } }
    function genClearStages() { genStageTimers.forEach(function (t) { clearTimeout(t); }); genStageTimers = []; }
    function genScheduleStages() {
        genClearStages();
        GEN_STAGES.forEach(function (s) {
            genStageTimers.push(setTimeout(function () {
                genTarget = Math.max(genTarget, Math.min(96, s.pct));
                genMessage.textContent = s.text;
            }, s.at));
        });
    }
    function genReset() {
        genSpinner.classList.remove('hidden');
        genErrorBox.classList.add('hidden');
        genRetryBtn.classList.add('hidden');
        genBar.style.background = '#0ea5e9';
        genTarget = 0; genShown = 0;
        genMessage.textContent = 'Menyiapkan permintaan...';
        genRenderProgress();
    }
    function genShowError(msg) {
        genStopCreep(); genClearStages();
        genSpinner.classList.add('hidden');
        genBar.style.background = '#e11d48';
        genMessage.textContent = 'Gagal';
        genErrorBox.textContent = msg;
        genErrorBox.classList.remove('hidden');
        genRetryBtn.classList.remove('hidden');
    }
    genRetryBtn.addEventListener('click', function () {
        genOverlay.classList.add('hidden');
        genOverlay.setAttribute('aria-hidden', 'true');
        fetchBtn.disabled = false;
    });

    function generateFullRecord(code) {
        fetchBtn.disabled = true;
        genReset();
        genOverlay.classList.remove('hidden');
        genOverlay.setAttribute('aria-hidden', 'false');
        genStartCreep();
        genScheduleStages();

        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('code', code);

        fetch('rekam_medis_ai_generate.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                fetchBtn.disabled = false;
                if (!result.ok || !result.data.ok || !result.data.medical_result_html) {
                    genShowError((result.data && result.data.message) || 'Gagal menyusun rekam medis. Coba klik "Ambil Data" lagi.');
                    return;
                }
                genStopCreep(); genClearStages();
                genTarget = 100; genShown = 100; genRenderProgress();
                genMessage.textContent = 'Selesai.';
                window.quill.clipboard.dangerouslyPasteHTML(result.data.medical_result_html);
                document.getElementById('medical_result_html').value = window.quill.root.innerHTML;
                document.getElementById('rmaiSubmitBtn').disabled = false;
                fetchNote.textContent = 'Draf rekam medis lengkap berhasil disusun AI dari laporan ' + lastData.diagnosis.report_code + '. Tinjau isi editor di bawah sebelum menyimpan.';
                fetchNote.style.color = '#059669';
                setTimeout(function () {
                    genOverlay.classList.add('hidden');
                    genOverlay.setAttribute('aria-hidden', 'true');
                }, 400);
            })
            .catch(function () {
                fetchBtn.disabled = false;
                genShowError('Tidak dapat menghubungi server (koneksi terputus atau timeout). Cek koneksi lalu coba lagi.');
            });
    }

    function renderBadges(data) {
        var items = [
            { label: 'AI Diagnosis Assistant', ok: true },
            { label: 'AI Surgery Planner', ok: !!data.surgery },
            { label: 'Radiology Center', ok: !!data.radiology },
            { label: 'Laboratory AI', ok: !!data.laboratory },
            { label: 'Psychiatry Center (Opsional)', ok: !!data.psychiatry },
        ];
        badgesEl.innerHTML = items.map(function (item) {
            return '<span class="rmai-badge ' + (item.ok ? 'rmai-badge--ok' : 'rmai-badge--missing') + '">' +
                (item.ok ? '✓ ' : '— ') + esc(item.label) + '</span>';
        }).join('');
    }

    function renderPatient(d) {
        var fields = [
            ['Nama Pasien', d.patient_name || '-'],
            ['Citizen ID', d.patient_citizen_id || '-'],
            ['Tanggal Lahir', d.patient_dob || '-'],
            ['Jenis Kelamin', d.patient_gender || '-'],
        ];
        patientPreview.innerHTML = fields.map(function (f) {
            return '<div><div class="rmai-field__label">' + esc(f[0]) + '</div><div class="rmai-field__value">' + esc(f[1]) + '</div></div>';
        }).join('');

        document.getElementById('rmaiPatientName').value = d.patient_name || '';
        document.getElementById('rmaiPatientCitizenId').value = d.patient_citizen_id || '';
        document.getElementById('rmaiPatientDob').value = d.patient_dob || '';
        document.getElementById('rmaiPatientGender').value = d.patient_gender || '';
    }

    function applyOperationDefaults(data) {
        var jenisOperasiInput = document.getElementById('rmaiJenisOperasi');
        var opName = (data.surgery && data.surgery.kasus_tindakan) || data.diagnosis.kasus_tindakan || data.diagnosis.diagnosis_utama || '';
        if (opName) jenisOperasiInput.value = opName;

        var kategori = data.surgery ? data.surgery.jenis_operasi_kategori : '';
        var isMinor = kategori ? kategori.toLowerCase() === 'minor' : (data.diagnosis.jenis_operasi || '').toLowerCase().indexOf('minor') !== -1;
        if (isMinor) {
            document.getElementById('rmaiOperasiMinor').checked = true;
        } else {
            document.getElementById('rmaiOperasiMayor').checked = true;
        }
    }

    function fieldRow(label, value, copyValue) {
        if (value === null || value === undefined || String(value).trim() === '' || String(value).trim() === '-') return '';
        var text = String(value);
        var copyText = (copyValue === null || copyValue === undefined || String(copyValue).trim() === '') ? text : String(copyValue);
        return '<div class="rmai-field">' +
            '<div><div class="rmai-field__label">' + esc(label) + '</div><div class="rmai-field__value">' + esc(text) + '</div></div>' +
            '<button type="button" class="rmai-copy-btn" data-copy="' + esc(copyText) + '">Salin</button>' +
            '</div>';
    }

    // Backend simpan Tanggal Lahir format yyyy-mm-dd (ISO). Form/field
    // tanggal di sistem LAIN (mis. medicalcenterime.my.id) bisa mengharap
    // format berbeda (mm/dd/yyyy, dd/mm/yyyy, dll) tergantung field itu
    // native <input type="date"> atau widget/masked-input custom — dan kita
    // tidak bisa memastikan dari sini format mana yang sebenarnya diterima.
    // Solusinya: sediakan beberapa tombol format sekaligus supaya user bisa
    // coba satu-satu, bukan menebak satu format saja.
    function formatDobVariants(iso) {
        var parts = String(iso || '').split('-');
        if (parts.length !== 3) return [];
        var y = parts[0], m = parts[1], d = parts[2];
        return [
            { label: m + '/' + d + '/' + y, title: 'Format mm/dd/yyyy' },
            { label: d + '/' + m + '/' + y, title: 'Format dd/mm/yyyy' },
            { label: y + '-' + m + '-' + d, title: 'Format yyyy-mm-dd (ISO)' },
            { label: d + '-' + m + '-' + y, title: 'Format dd-mm-yyyy' },
        ];
    }

    function dobFieldRow(label, iso) {
        if (!iso) return '';
        var variants = formatDobVariants(iso);
        if (!variants.length) return fieldRow(label, iso);
        var buttons = variants.map(function (v) {
            return '<button type="button" class="rmai-copy-btn" title="' + esc(v.title) + '" data-copy="' + esc(v.label) + '">' + esc(v.label) + '</button>';
        }).join('');
        return '<div class="rmai-field">' +
            '<div><div class="rmai-field__label">' + esc(label) + '</div><div class="rmai-field__value">' + esc(iso) + '</div></div>' +
            '<div style="display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;">' + buttons + '</div>' +
            '</div>';
    }

    function listText(arr) {
        if (!Array.isArray(arr) || !arr.length) return '';
        return arr.map(function (x) { return typeof x === 'string' ? x : JSON.stringify(x); }).join('\n');
    }

    function ttvValue(ttv, keyword) {
        var found = (ttv || []).filter(function (v) {
            return (v.label || '').toLowerCase().indexOf(keyword) !== -1;
        })[0];
        if (!found) return '';
        return found.value + (found.note ? ' (' + found.note + ')' : '');
    }

    function renderPreview(data) {
        var d = data.diagnosis, s = data.surgery, r = data.radiology, l = data.laboratory, p = data.psychiatry;
        var sections = [];

        // 1. Klasifikasi Operasi & Waktu
        sections.push(sectionBlock(1, 'Klasifikasi Operasi & Waktu', [
            fieldRow('Jenis Operasi', s ? s.jenis_operasi_kategori : d.jenis_operasi),
            fieldRow('Nama Tindakan / Operasi', (s && s.kasus_tindakan) || d.kasus_tindakan),
            fieldRow('Durasi Operasi', s ? s.durasi : ''),
        ], 'Tanggal &amp; Waktu Operasi serta Lokasi / Ruang OK diisi manual saat penginputan — tidak tersedia dari data AI.'));

        // 2. Identitas Pasien
        sections.push(sectionBlock(2, 'Identitas Pasien', [
            fieldRow('Nama Pasien', d.patient_name),
            dobFieldRow('Tanggal Lahir (DOB)', d.patient_dob),
            fieldRow('Jenis Kelamin', d.patient_gender),
            fieldRow('Citizen ID / KTP Pasien', d.patient_citizen_id),
        ], 'Golongan Darah dan No HP/Telepon tidak tersedia dari data AI — isi manual bila diperlukan.'));

        // 3. Anggota Medis & Tim Operasi
        sections.push(sectionBlock(3, 'Anggota Medis & Tim Operasi', [], 'Dokter DPJP dan Asisten Operasi dipilih langsung lewat form "Tim Medis & Operasi" di atas, bukan dari data AI.'));

        // 4. Anamnesis & Riwayat Kesehatan
        sections.push(sectionBlock(4, 'Anamnesis & Riwayat Kesehatan', [
            fieldRow('Anamnesis Utama', d.anamnesis_lengkap || d.anamnesis),
            fieldRow('Diagnosis Banding', listText(d.diagnosis_banding)),
        ], 'Riwayat Penyakit Dahulu/Keluarga, Riwayat Alergi, Riwayat Pengobatan, dan Data Obstetri/Kebidanan tidak tersedia dari data AI — isi manual bila diperlukan.'));

        // 5. Pemeriksaan Fisik & TTV
        sections.push(sectionBlock(5, 'Pemeriksaan Fisik & TTV', [
            fieldRow('Keadaan Umum', d.status),
            fieldRow('Kesadaran (GCS)', d.gcs),
            fieldRow('Tekanan Darah', ttvValue(d.ttv, 'tekanan')),
            fieldRow('Nadi', ttvValue(d.ttv, 'nadi')),
            fieldRow('Respirasi (RR)', ttvValue(d.ttv, 'respirasi')),
            fieldRow('Suhu Body', ttvValue(d.ttv, 'suhu')),
            fieldRow('Saturasi O2', ttvValue(d.ttv, 'saturasi') || ttvValue(d.ttv, 'o2')),
        ]));

        // 6. Tindakan & Prosedur Operasi
        if (s) {
            sections.push(sectionBlock(6, 'Tindakan & Prosedur Operasi', [
                fieldRow('Nama Tindakan', s.kasus_tindakan),
                fieldRow('Langkah-Langkah Tindakan Operasi', (s.tahapan_prosedur || []).map(function (step, i) { return (i + 1) + '. [' + step.pelaku + '] ' + step.aksi + ' -> ' + step.hasil; }).join('\n')),
                fieldRow('Hasil Operasi & Intraoperatif', s.laporan_pasca_operasi),
            ]));
        }

        // 7. Manajemen Anestesi & Table Score
        if (s) {
            var farm = s.farmakologi || {};
            var praOp = (farm.pra_operatif || []).concat(farm.intra_operatif || []);
            sections.push(sectionBlock(7, 'Manajemen Anestesi & Table Score', [
                fieldRow('Jenis Anestesi', s.jenis_anestesi_input),
                fieldRow('Obat Pra-Operasi (Induksi, Inhalasi, Analgesik, Relaksan)', praOp.map(function (m) { return m.nama + ' ' + m.dosis; }).join('\n')),
                fieldRow('Obat Pasca-Operasi (Antidote, Anti Mual, Analgesik)', (farm.post_operatif || []).map(function (m) { return m.nama + ' ' + m.dosis; }).join('\n')),
            ], 'Status Lokalis & Score Pemulihan Pasca Anestesi (Kesadaran/Respon Mual/Pernapasan/Aktivitas Motorik/Tekanan Darah/Warna Kulit Pasca) tidak tersedia dari data AI — isi manual saat pemulihan pasien.'));
        }

        // 8. Pemeriksaan Penunjang & Saran
        var penunjangRows = [
            fieldRow('Hasil Laboratorium', l ? (l.results || []).map(function (res) { return res.parameter + ': ' + res.result + ' ' + res.unit + ' [' + res.flag + ']'; }).join('\n') + (l.interpretation ? '\n\nInterpretasi: ' + l.interpretation : '') : ''),
            fieldRow('Hasil Radiologi / X-Ray', r ? [r.modality + ' - ' + r.category + ' - ' + r.body_region, r.report_findings, r.report_diagnosis].filter(Boolean).join('\n') : ''),
            fieldRow('Obat-obatan Pulang / Rawat Inap', s ? ((s.farmakologi || {}).pemulangan || []).map(function (m) { return m.nama + ' ' + m.dosis; }).join('\n') : ''),
            fieldRow('Saran dan Anjuran Pasca Operasi', [l ? listText(l.recommendations) : '', r ? r.report_recommendations : ''].filter(Boolean).join('\n')),
            fieldRow('Catatan Tambahan', d.roleplay_note),
        ];
        if (p) {
            var diag = p.diagnosis || {}, risk = p.risk_assessment || {};
            penunjangRows.push(fieldRow('Asesmen Psikiatri (Opsional)', 'Diagnosis: [' + (diag.code || '-') + '] ' + (diag.primary || '-') + '\nRisiko — Severity: ' + (risk.severity || '-') + ', Suicide: ' + (risk.suicide_risk || '-') + ', Violence: ' + (risk.violence_risk || '-') + ', Self Harm: ' + (risk.self_harm_risk || '-') + '\n' + (p.clinical_summary || '')));
        }
        sections.push(sectionBlock(8, 'Pemeriksaan Penunjang & Saran', penunjangRows));

        previewSections.innerHTML = sections.join('');
        previewCard.classList.remove('hidden');
    }

    function sectionBlock(number, title, rowsHtml, note) {
        var rows = rowsHtml.filter(function (r) { return r !== ''; });
        if (!rows.length && !note) return '';
        var body = rows.length
            ? '<div class="space-y-2">' + rows.join('') + '</div>'
            : '<p class="text-sm text-gray-400">Tidak ada data AI untuk bagian ini.</p>';
        var noteHtml = note ? '<p class="rmai-section-note">' + note + '</p>' : '';
        return '<div class="rmai-section">' +
            '<div class="rmai-section-title"><span class="rmai-section-number">' + number + '</span>' + esc(title) + '</div>' +
            body + noteHtml +
            '</div>';
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.rmai-copy-btn');
        if (!btn) return;
        var text = btn.getAttribute('data-copy') || '';
        var original = btn.textContent;
        function done(ok) { btn.textContent = ok ? 'Tersalin' : 'Gagal'; setTimeout(function () { btn.textContent = original; }, 1200); }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { done(true); }).catch(function () { done(false); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.focus(); ta.select();
            try { document.execCommand('copy'); done(true); } catch (err) { done(false); }
            document.body.removeChild(ta);
        }
    });

    // ===== Asisten add/remove =====
    var assistantCount = 2;
    window.rmaiAddAssistant = function () {
        assistantCount++;
        var container = document.getElementById('assistants-container');
        var row = document.createElement('div');
        row.className = 'assistant-row grid grid-cols-12 gap-2 mb-2';
        row.innerHTML =
            '<div class="col-span-11">' +
            '<div class="ems-form-group relative" data-user-autocomplete data-autocomplete-scope="assistant">' +
            '<input type="text" class="form-input assistant-select" data-user-autocomplete-input placeholder="Ketik nama asisten ' + assistantCount + '...">' +
            '<input type="hidden" name="assistant_ids[]" data-user-autocomplete-hidden>' +
            '<div class="ems-suggestion-box" data-user-autocomplete-list></div></div></div>' +
            '<div class="col-span-1 flex items-center">' +
            '<button type="button" onclick="rmaiRemoveAssistant(this)" class="text-red-500 hover:text-red-700" title="Hapus">' + <?= json_encode(ems_icon('trash', 'h-4 w-4'), JSON_UNESCAPED_UNICODE) ?> + '</button></div>';
        container.appendChild(row);
        if (window.emsInitUserAutocomplete) window.emsInitUserAutocomplete(row);
    };
    window.rmaiRemoveAssistant = function (button) {
        button.closest('.assistant-row').remove();
        assistantCount--;
    };

    // ===== Preview file KTP & pendukung =====
    function bindPreview(inputId, previewId, multiple) {
        var input = document.getElementById(inputId);
        var preview = document.getElementById(previewId);
        input.addEventListener('change', function () {
            var files = Array.from(input.files || []);
            if (!files.length) {
                preview.innerHTML = '<span class="text-gray-400 text-sm">Belum ada file</span>';
                return;
            }
            if (!multiple) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" class="max-h-full max-w-full rounded object-contain">';
                };
                reader.readAsDataURL(files[0]);
                return;
            }
            preview.innerHTML = '';
            var grid = document.createElement('div');
            grid.className = 'grid grid-cols-2 md:grid-cols-3 gap-3 w-full';
            files.forEach(function (file) {
                var item = document.createElement('div');
                item.className = 'rounded border border-gray-200 bg-white p-2';
                var img = document.createElement('img');
                img.className = 'h-28 w-full rounded object-cover';
                var caption = document.createElement('div');
                caption.className = 'mt-2 text-xs text-slate-600 break-words';
                caption.textContent = file.name;
                item.appendChild(img); item.appendChild(caption); grid.appendChild(item);
                var reader = new FileReader();
                reader.onload = function (e) { img.src = String(e.target.result || ''); };
                reader.readAsDataURL(file);
            });
            preview.appendChild(grid);
        });
    }
    bindPreview('ktp_file', 'ktp_preview', false);
    bindPreview('supporting_image_files', 'supporting_images_preview', true);

    // ===== Lampirkan otomatis citra Radiology Center (kalau ada) sebagai foto pendukung =====
    // image_url dari rekam_medis_ai_lookup.php melewati ajax/secure_file.php (butuh sesi
    // login yang sama — credentials: 'same-origin' sudah cukup, tidak perlu token tambahan).
    // File hasil fetch disisipkan ke input#supporting_image_files lewat DataTransfer supaya
    // ikut ter-upload persis seperti file yang dipilih manual oleh user, dan supaya preview
    // thumbnail yang sudah ada (bindPreview di atas) otomatis menampilkannya juga.
    function attachRadiologyImage(radiology) {
        var note = document.getElementById('rmaiRadiologyAttachNote');
        var input = document.getElementById('supporting_image_files');

        function removeStaleAttachment() {
            if (!autoAttachedRadiologyFile) return;
            var dt = new DataTransfer();
            Array.from(input.files || []).forEach(function (f) {
                if (f !== autoAttachedRadiologyFile) dt.items.add(f);
            });
            input.files = dt.files;
            autoAttachedRadiologyFile = null;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (!radiology || !radiology.image_url) {
            removeStaleAttachment();
            if (note) note.classList.add('hidden');
            return;
        }

        if (note) {
            note.textContent = 'Mengambil citra Radiology Center...';
            note.style.color = '#0284c7';
            note.classList.remove('hidden');
        }

        fetch(radiology.image_url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.blob();
            })
            .then(function (blob) {
                var ext = blob.type === 'image/png' ? 'png' : (blob.type === 'image/webp' ? 'webp' : 'jpg');
                var label = [radiology.modality, radiology.body_region].filter(Boolean).join('_').replace(/[^a-zA-Z0-9_]+/g, '_') || 'scan';
                var file = new File([blob], 'radiology_' + label + '.' + ext, { type: blob.type || 'image/jpeg' });

                var dt = new DataTransfer();
                Array.from(input.files || []).forEach(function (f) {
                    if (f !== autoAttachedRadiologyFile) dt.items.add(f);
                });
                dt.items.add(file);
                input.files = dt.files;
                autoAttachedRadiologyFile = file;
                input.dispatchEvent(new Event('change', { bubbles: true }));

                if (note) {
                    note.textContent = 'Citra Radiology Center (' + [radiology.modality, radiology.body_region].filter(Boolean).join(' - ') + ') otomatis dilampirkan sebagai foto pendukung.';
                    note.style.color = '#059669';
                }
            })
            .catch(function () {
                removeStaleAttachment();
                if (note) {
                    note.textContent = 'Gagal mengambil citra Radiology Center secara otomatis (berkas mungkin sudah dihapus/gagal dibuat). Lampirkan manual dari Radiology Center bila diperlukan.';
                    note.style.color = '#dc2626';
                }
            });
    }

    // ===== Validasi sebelum submit =====
    document.getElementById('medical-record-form').addEventListener('submit', function (e) {
        if (!lastData) {
            e.preventDefault();
            showFormError('Ambil data dari kode referensi terlebih dahulu sebelum menyimpan.');
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        if (!lastData.diagnosis.patient_name || !lastData.diagnosis.patient_citizen_id || !lastData.diagnosis.patient_dob || !lastData.diagnosis.patient_gender) {
            e.preventDefault();
            showFormError('Identitas pasien di laporan diagnosis ini belum lengkap. Lengkapi dulu di AI Diagnosis Assistant, lalu klik "Ambil Data" lagi.');
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }
        document.getElementById('medical_result_html').value = window.quill.root.innerHTML;
    });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
