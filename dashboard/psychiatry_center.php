<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/ai_psychiatry.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'psychiatry_center.php', '/dashboard/index.php');
ems_ai_psychiatry_ensure_tables($pdo);
ems_ai_ds_ensure_tables($pdo);

$pageTitle = 'Psychiatry Center | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$recentStmt = $pdo->prepare("
    SELECT p.id, p.report_code, p.patient_name, p.department, p.status, p.created_at, p.result_json, p.source_report_code, u.full_name AS created_by_name
    FROM ai_psychiatry_assessments p
    LEFT JOIN user_rh u ON u.id = p.user_id
    WHERE p.unit_code = ?
    ORDER BY p.id DESC
    LIMIT 15
");
$recentStmt->execute([$effectiveUnit]);
$recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$hasOwnApiKey = trim((string) (ems_ai_ds_get_user_settings($pdo, (int) ($user['id'] ?? 0))['gemini_api_key'] ?? '')) !== '';
$canDelete = ems_is_manager_plus_role($user['role'] ?? '');
$totalTurns = ems_ai_psychiatry_total_turns();

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between mb-4">
            <div>
                <h1 class="page-title">Psychiatry Center</h1>
                <p class="page-subtitle">Asesmen psikiatri interaktif — AI mengajukan wawancara klinis dinamis, lalu menyusun diagnosis DSM-5/ICD-10, Mental Status Examination, penilaian risiko, dan rencana terapi.</p>
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
                Anda belum mengatur API key Gemini pribadi. <a href="ai_settings_personal.php" class="font-bold underline">Atur sekarang di Setting AI Saya</a> sebelum memulai asesmen.
            </div>
        <?php endif; ?>

        <div id="psyFormError" class="hidden alert alert-error"></div>

        <div class="card mb-4">
            <div class="card-header">Konfigurasi Asesmen</div>
            <div class="card-section" style="background:#f0f9ff;border-bottom:1px solid #e2e8f0;">
                <label style="margin-top:0;">Ambil dari Laporan Diagnosis (opsional)</label>
                <div class="flex items-center gap-2">
                    <input type="text" id="psyLookupCode" placeholder="Tempel kode referensi, mis. DGN-20260812-143012-A1B2" style="flex:1;">
                    <button type="button" id="psyLookupBtn" class="btn-secondary btn-sm" style="white-space:nowrap;">
                        <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                        <span>Ambil Data</span>
                    </button>
                </div>
                <p id="psyLookupNote" class="page-subtitle" style="margin-top:6px;font-size:12px;">Kode ini muncul di halaman Laporan Diagnosis (ai_diagnosis_report.php) — identitas pasien dan keluhan/anamnesis akan terisi otomatis.</p>
                <input type="hidden" id="psyDiagCodeHidden" value="">
            </div>

            <div class="card-section">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div>
                        <label style="margin-top:0;">Departemen</label>
                        <select id="psyDepartment">
                            <?php foreach (ems_ai_psychiatry_departments() as $dept): ?>
                                <option value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="margin-top:0;">Tipe Asesmen</label>
                        <select id="psyAssessmentType">
                            <?php foreach (ems_ai_psychiatry_assessment_types() as $type): ?>
                                <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="margin-top:0;">Prioritas</label>
                        <select id="psyPriority">
                            <?php foreach (ems_ai_psychiatry_priorities() as $prio): ?>
                                <option value="<?= htmlspecialchars($prio, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($prio, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="margin-top:0;">Dokter Pemeriksa</label>
                        <input type="text" id="psyDoctorName" placeholder="Nama dokter pemeriksa" value="<?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                    <div>
                        <label>Nama Pasien</label>
                        <input type="text" id="psyPatientName" placeholder="Nama lengkap pasien">
                    </div>
                    <div>
                        <label>Tanggal Lahir</label>
                        <input type="date" id="psyPatientDob">
                    </div>
                    <div>
                        <label>Citizen ID</label>
                        <input type="text" id="psyPatientCitizenId" placeholder="Citizen ID (RWX-...)">
                    </div>
                </div>

                <label class="mt-4">Keluhan Utama</label>
                <input type="text" id="psyChiefComplaint" placeholder="Contoh: Sulit tidur, sering sedih, cemas berlebih...">

                <label class="mt-3">Anamnesis / Temuan Klinis / Diagnosis Kerja</label>
                <textarea id="psyAnamnesis" rows="4" placeholder="Tulis riwayat keluhan, durasi, intensitas, faktor stressor, serta indikasi klinis awal..."></textarea>

                <div class="modal-actions mt-4">
                    <button type="button" class="btn-primary" id="psyStartBtn" disabled>
                        <?= ems_icon('sparkles', 'h-4 w-4') ?>
                        <span>Mulai Assessment</span>
                    </button>
                </div>
                <p id="psyValidationHint" class="page-subtitle" style="margin-top:8px;font-size:12px;color:#dc2626;">Lengkapi Keluhan Utama dan Anamnesis untuk memulai asesmen.</p>
            </div>
        </div>

        <div id="psyResultsArea" class="hidden mb-4">
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="xl:col-span-2 card mb-0" style="display:flex;flex-direction:column;height:560px;">
                    <div class="card-header flex items-center justify-between">
                        <span>Wawancara Klinis (AI)</span>
                        <span id="psyProgressBadge" class="badge-success">Inisialisasi...</span>
                    </div>
                    <div id="psyChatWindow" class="card-section" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:10px;"></div>
                    <div class="card-section" style="border-top:1px solid #e2e8f0;">
                        <div class="flex gap-2 mb-2">
                            <button type="button" class="btn-secondary btn-sm" onclick="psyQuickReply('Ya')">Ya</button>
                            <button type="button" class="btn-secondary btn-sm" onclick="psyQuickReply('Tidak')">Tidak</button>
                            <button type="button" class="btn-secondary btn-sm" onclick="psyQuickReply('Tidak yakin')">Tidak yakin</button>
                        </div>
                        <textarea id="psyPatientReply" rows="2" placeholder="Tulis atau pilih respons pasien di sini..." disabled></textarea>
                        <div class="flex items-center justify-between gap-2 mt-2">
                            <button type="button" id="psySkipBtn" class="btn-secondary btn-sm" disabled>Selesaikan &amp; Diagnosis Sekarang</button>
                            <button type="button" id="psyNextBtn" class="btn-primary btn-sm" disabled>
                                <span>Lanjutkan Pertanyaan</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card mb-0">
                    <div class="card-header">Clinical Impression Progress</div>
                    <div id="psyImpressions" class="card-section" style="min-height:200px;">
                        <p class="page-subtitle" style="font-size:12px;">Impression akan diperbarui setelah interview berjalan.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Riwayat Terbaru</div>
            <div class="table-wrapper">
                <table id="psyHistoryTable" class="table-custom" data-auto-datatable="true" data-dt-order='[[0,"desc"]]'>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Pasien</th>
                            <th>Departemen</th>
                            <th>Diagnosis</th>
                            <th>Oleh</th>
                            <th>Status</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentRows as $row): ?>
                            <?php
                                $diagLabel = '-';
                                if ($row['status'] === 'done' && !empty($row['result_json'])) {
                                    $decoded = json_decode((string) $row['result_json'], true);
                                    if (is_array($decoded)) {
                                        $diagLabel = (string) ($decoded['diagnosis']['primary'] ?? '-');
                                    }
                                }
                            ?>
                            <tr>
                                <td class="whitespace-nowrap" data-order="<?= (int) strtotime((string) $row['created_at']) ?>"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $row['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($row['patient_name'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['department'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(mb_strimwidth($diagLabel, 0, 60, '...'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($row['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="<?= $row['status'] === 'done' ? 'badge-success' : 'badge-danger' ?>">
                                        <?= $row['status'] === 'done' ? 'Selesai' : 'Gagal' ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <a href="psychiatry_report.php?id=<?= (int) $row['id'] ?>" class="btn-secondary btn-sm">
                                            <?= ems_icon('eye', 'h-4 w-4') ?>
                                            <span>Lihat</span>
                                        </a>
                                        <?php if (!empty($row['source_report_code'])): ?>
                                            <button type="button" class="btn-secondary btn-sm psy-regenerate-btn" data-id="<?= (int) $row['id'] ?>" title="Generate ulang laporan akhir pakai transcript &amp; kode referensi yang sama">
                                                <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                                                <span>Generate Ulang</span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canDelete): ?>
                                            <form method="POST" action="psychiatry_report.php?id=<?= (int) $row['id'] ?>" onsubmit="return confirm('Hapus asesmen psikiatri #<?= (int) $row['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
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
                            <tr><td colspan="7" class="text-center text-slate-400 py-6">Belum ada riwayat asesmen psikiatri.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<div id="psyLoadingOverlay" class="global-upload-overlay hidden" aria-hidden="true">
    <div class="global-upload-overlay-box">
        <div class="global-upload-spinner" aria-hidden="true"></div>
        <div id="psyLoadingTitle" class="global-upload-title">Memproses...</div>
        <div id="psyLoadingMessage" class="global-upload-copy">Mohon tunggu...</div>
        <div id="psyLoadingErrorBox" class="hidden alert alert-error" style="margin-top:12px;text-align:left;"></div>
        <button type="button" id="psyLoadingRetryBtn" class="btn-secondary hidden" style="margin-top:10px;">Tutup</button>
    </div>
</div>

<script>
(function () {
    var CSRF_TOKEN = <?= json_encode(generateCsrfToken(), JSON_UNESCAPED_UNICODE) ?>;
    var TOTAL_TURNS = <?= (int) $totalTurns ?>;

    var els = {
        department: document.getElementById('psyDepartment'),
        assessmentType: document.getElementById('psyAssessmentType'),
        priority: document.getElementById('psyPriority'),
        doctorName: document.getElementById('psyDoctorName'),
        patientName: document.getElementById('psyPatientName'),
        patientDob: document.getElementById('psyPatientDob'),
        patientCitizenId: document.getElementById('psyPatientCitizenId'),
        chiefComplaint: document.getElementById('psyChiefComplaint'),
        anamnesis: document.getElementById('psyAnamnesis'),
        startBtn: document.getElementById('psyStartBtn'),
        validationHint: document.getElementById('psyValidationHint'),
        resultsArea: document.getElementById('psyResultsArea'),
        chatWindow: document.getElementById('psyChatWindow'),
        progressBadge: document.getElementById('psyProgressBadge'),
        impressions: document.getElementById('psyImpressions'),
        patientReply: document.getElementById('psyPatientReply'),
        nextBtn: document.getElementById('psyNextBtn'),
        skipBtn: document.getElementById('psySkipBtn'),
        formError: document.getElementById('psyFormError'),
    };

    var overlay = document.getElementById('psyLoadingOverlay');
    var loadingTitle = document.getElementById('psyLoadingTitle');
    var loadingMessage = document.getElementById('psyLoadingMessage');
    var loadingErrorBox = document.getElementById('psyLoadingErrorBox');
    var loadingRetryBtn = document.getElementById('psyLoadingRetryBtn');

    function showOverlay(title, message) {
        loadingTitle.textContent = title;
        loadingMessage.textContent = message;
        loadingErrorBox.classList.add('hidden');
        loadingRetryBtn.classList.add('hidden');
        overlay.classList.remove('hidden');
        overlay.setAttribute('aria-hidden', 'false');
    }
    function hideOverlay() {
        overlay.classList.add('hidden');
        overlay.setAttribute('aria-hidden', 'true');
    }
    function overlayError(message) {
        loadingErrorBox.textContent = message;
        loadingErrorBox.classList.remove('hidden');
        loadingRetryBtn.classList.remove('hidden');
    }
    loadingRetryBtn.addEventListener('click', hideOverlay);

    var chatHistory = [];
    var currentTurn = 1;
    var isInterviewActive = false;

    var mandatoryFields = [els.chiefComplaint, els.anamnesis];
    function checkFormValidity() {
        var allFilled = mandatoryFields.every(function (f) { return f.value.trim() !== ''; });
        els.startBtn.disabled = !allFilled;
        els.validationHint.style.display = allFilled ? 'none' : 'block';
    }
    mandatoryFields.forEach(function (f) {
        f.addEventListener('input', checkFormValidity);
    });
    checkFormValidity();

    function showFormError(msg) {
        els.formError.textContent = msg;
        els.formError.classList.remove('hidden');
    }
    function clearFormError() {
        els.formError.classList.add('hidden');
    }

    function appendChatBubble(role, text) {
        var wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;flex-direction:column;align-items:' + (role === 'ai' ? 'flex-start' : 'flex-end') + ';';
        var label = document.createElement('div');
        label.style.cssText = 'font-size:10px;font-weight:700;color:#64748b;margin-bottom:3px;text-transform:uppercase;letter-spacing:.03em;';
        label.textContent = role === 'ai' ? 'AI — Roxwood Specialist' : 'Pasien';
        var bubble = document.createElement('div');
        bubble.style.cssText = 'max-width:85%;padding:10px 14px;border-radius:14px;font-size:13px;line-height:1.5;' +
            (role === 'ai' ? 'background:#e0f2fe;color:#0c4a6e;' : 'background:#f1f5f9;color:#1e293b;');
        bubble.textContent = text;
        wrap.appendChild(label);
        wrap.appendChild(bubble);
        els.chatWindow.appendChild(wrap);
        els.chatWindow.scrollTop = els.chatWindow.scrollHeight;
    }

    function renderImpressions(impressions) {
        if (!impressions || !impressions.length) {
            els.impressions.innerHTML = '<p class="page-subtitle" style="font-size:12px;">Belum teranalisis.</p>';
            return;
        }
        var sorted = impressions.slice().sort(function (a, b) { return b.probability - a.probability; });
        els.impressions.innerHTML = sorted.map(function (imp) {
            return '' +
                '<div style="margin-bottom:14px;">' +
                '  <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">' +
                '    <span style="font-weight:700;">' + escapeHtml(imp.condition) + '</span>' +
                '    <span style="font-weight:800;color:#0ea5e9;">' + imp.probability + '%</span>' +
                '  </div>' +
                '  <div style="width:100%;background:#e2e8f0;border-radius:999px;height:8px;">' +
                '    <div style="background:#0ea5e9;height:8px;border-radius:999px;width:' + imp.probability + '%;"></div>' +
                '  </div>' +
                '</div>';
        }).join('');
    }

    function escapeHtml(text) {
        var d = document.createElement('div');
        d.textContent = text || '';
        return d.innerHTML;
    }

    window.psyQuickReply = function (text) {
        if (els.patientReply.disabled) return;
        els.patientReply.value = text;
        els.patientReply.focus();
    };

    function buildBaseFormData() {
        var fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('department', els.department.value);
        fd.append('assessment_type', els.assessmentType.value);
        fd.append('priority', els.priority.value);
        fd.append('chief_complaint', els.chiefComplaint.value);
        fd.append('anamnesis', els.anamnesis.value);
        fd.append('diagnosis_code', document.getElementById('psyDiagCodeHidden').value || '');
        return fd;
    }

    function callAction(action, extra) {
        var fd = buildBaseFormData();
        fd.append('action', action);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });

        return fetch('psychiatry_center_action.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        });
    }

    els.startBtn.addEventListener('click', function () {
        if (isInterviewActive) return;
        clearFormError();
        els.startBtn.disabled = true;
        showOverlay('Menginisialisasi Asesmen...', 'Menganalisis data klinis awal dan menyusun clinical impressions.');

        chatHistory = [];
        currentTurn = 1;
        els.chatWindow.innerHTML = '';

        callAction('start', {}).then(function (result) {
            hideOverlay();
            els.startBtn.disabled = false;
            if (!result.ok || !result.data.ok) {
                showFormError((result.data && result.data.message) || 'Gagal memulai asesmen.');
                return;
            }

            els.resultsArea.classList.remove('hidden');
            els.resultsArea.scrollIntoView({ behavior: 'smooth', block: 'start' });

            renderImpressions(result.data.clinical_impressions);
            appendChatBubble('ai', result.data.first_question);
            chatHistory.push({ role: 'ai', text: result.data.first_question });

            isInterviewActive = true;
            els.patientReply.disabled = false;
            els.nextBtn.disabled = false;
            els.skipBtn.disabled = false;
            els.progressBadge.textContent = 'Pertanyaan 1 / ' + TOTAL_TURNS;
        }).catch(function () {
            hideOverlay();
            els.startBtn.disabled = false;
            showFormError('Tidak dapat menghubungi server. Cek koneksi lalu coba lagi.');
        });
    });

    function getPatientReply() {
        var text = els.patientReply.value.trim();
        return text;
    }

    els.nextBtn.addEventListener('click', function () {
        var replyText = getPatientReply();
        if (!replyText) {
            showFormError('Tulis atau pilih respons pasien terlebih dahulu.');
            return;
        }
        clearFormError();
        appendChatBubble('user', replyText);
        chatHistory.push({ role: 'user', text: replyText });
        els.patientReply.value = '';

        currentTurn++;

        if (currentTurn > TOTAL_TURNS) {
            finalizeAssessment(false);
            return;
        }

        els.nextBtn.disabled = true;
        els.skipBtn.disabled = true;
        els.patientReply.disabled = true;
        showOverlay('Mengolah Jawaban...', 'AI menganalisis respons dan menyusun pertanyaan lanjutan.');

        callAction('next', { chat_history: JSON.stringify(chatHistory), turn: currentTurn }).then(function (result) {
            hideOverlay();
            els.nextBtn.disabled = false;
            els.skipBtn.disabled = false;
            els.patientReply.disabled = false;

            if (!result.ok || !result.data.ok) {
                showFormError((result.data && result.data.message) || 'Gagal mengolah jawaban.');
                return;
            }

            renderImpressions(result.data.clinical_impressions);
            appendChatBubble('ai', result.data.next_question);
            chatHistory.push({ role: 'ai', text: result.data.next_question });

            els.progressBadge.textContent = 'Pertanyaan ' + currentTurn + ' / ' + TOTAL_TURNS;
            if (currentTurn === TOTAL_TURNS) {
                els.nextBtn.querySelector('span').textContent = 'Tegakkan Diagnosis Akhir';
            }
        }).catch(function () {
            hideOverlay();
            els.nextBtn.disabled = false;
            els.skipBtn.disabled = false;
            els.patientReply.disabled = false;
            showFormError('Tidak dapat menghubungi server. Cek koneksi lalu coba lagi.');
        });
    });

    els.skipBtn.addEventListener('click', function () {
        finalizeAssessment(true);
    });

    function finalizeAssessment(skipped) {
        els.nextBtn.disabled = true;
        els.skipBtn.disabled = true;
        els.patientReply.disabled = true;
        showOverlay('Menyusun Rekam Medis...', 'Menegakkan diagnosis, MSE, penilaian risiko, dan rencana terapi.');

        callAction('finalize', {
            chat_history: JSON.stringify(chatHistory),
            skipped: skipped ? '1' : '0',
            patient_name: els.patientName.value,
            patient_dob: els.patientDob.value,
            patient_citizen_id: els.patientCitizenId.value,
            doctor_name: els.doctorName.value,
        }).then(function (result) {
            if (!result.ok || !result.data.ok || !result.data.report_id) {
                hideOverlay();
                overlayError((result.data && result.data.message) || 'Gagal menyusun laporan akhir.');
                els.nextBtn.disabled = false;
                els.skipBtn.disabled = false;
                els.patientReply.disabled = false;
                return;
            }
            loadingMessage.textContent = 'Selesai, membuka laporan...';
            window.location.href = 'psychiatry_report.php?id=' + encodeURIComponent(result.data.report_id);
        }).catch(function () {
            hideOverlay();
            overlayError('Tidak dapat menghubungi server (koneksi terputus atau timeout).');
            els.nextBtn.disabled = false;
            els.skipBtn.disabled = false;
            els.patientReply.disabled = false;
        });
    }

    // ===== Ambil dari Laporan Diagnosis (auto-fill via kode referensi) =====
    var lookupBtn = document.getElementById('psyLookupBtn');
    var lookupInput = document.getElementById('psyLookupCode');
    var lookupNote = document.getElementById('psyLookupNote');
    var diagCodeHidden = document.getElementById('psyDiagCodeHidden');

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

    lookupBtn.addEventListener('click', function () {
        var code = (lookupInput.value || '').trim();
        if (!code) {
            showLookupMsg('Masukkan kode referensi terlebih dahulu.', true);
            return;
        }
        lookupBtn.disabled = true;
        showLookupMsg('Mengambil data laporan diagnosis...', false);
        lookupNote.style.color = '';
        fetch('ai_diagnosis_report_lookup.php?code=' + encodeURIComponent(code) + '&target=ai_psychiatry_assessments', {
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
                if (data.patient_name) els.patientName.value = data.patient_name;
                if (data.patient_dob) els.patientDob.value = data.patient_dob;
                if (data.patient_citizen_id) els.patientCitizenId.value = data.patient_citizen_id;

                if (data.diagnosis_utama) els.chiefComplaint.value = data.diagnosis_utama;
                if (data.anamnesis) els.anamnesis.value = data.anamnesis;

                diagCodeHidden.value = data.report_code || '';

                checkFormValidity();

                var usedWarning = formatUsedOnTargetWarning(data);
                if (usedWarning) {
                    showLookupMsg(usedWarning, false, '#d97706');
                } else {
                    showLookupMsg('Data berhasil diambil dari laporan ' + data.report_code + '.', false);
                }
            })
            .catch(function () {
                lookupBtn.disabled = false;
                showLookupMsg('Gagal menghubungi server. Coba lagi.', true);
            });
    });

    // ===== Generate Ulang (dari riwayat, langsung finalize ulang tanpa interview) =====
    document.querySelectorAll('.psy-regenerate-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Generate ulang laporan akhir asesmen ini? Transcript wawancara & kode referensi yang sama akan dipakai lagi untuk minta hasil AI yang baru (tanpa mengulang sesi wawancara).')) {
                return;
            }
            btn.disabled = true;
            showOverlay('Menyusun Ulang Rekam Medis...', 'Menegakkan ulang diagnosis, MSE, penilaian risiko, dan rencana terapi.');

            var fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('regenerate_of', btn.getAttribute('data-id'));

            fetch('psychiatry_center_action.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
                .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
                .then(function (result) {
                    btn.disabled = false;
                    if (!result.ok || !result.data.ok || !result.data.report_id) {
                        hideOverlay();
                        overlayError((result.data && result.data.message) || 'Gagal generate ulang.');
                        return;
                    }
                    loadingMessage.textContent = 'Selesai, membuka laporan...';
                    window.location.href = 'psychiatry_report.php?id=' + encodeURIComponent(result.data.report_id);
                })
                .catch(function () {
                    btn.disabled = false;
                    hideOverlay();
                    overlayError('Tidak dapat menghubungi server (koneksi terputus atau timeout).');
                });
        });
    });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
