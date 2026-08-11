<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_diagnosis_assistant.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

$pageTitle = 'AI Diagnosis Assistant | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$recentStmt = $pdo->prepare("
    SELECT r.id, r.anamnesis, r.status, r.created_at, u.full_name AS created_by_name
    FROM ai_diagnosis_reports r
    LEFT JOIN user_rh u ON u.id = r.user_id
    WHERE r.unit_code = ?
    ORDER BY r.id DESC
    LIMIT 15
");
$recentStmt->execute([$effectiveUnit]);
$recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$hasOwnApiKey = trim((string) (ems_ai_ds_get_user_settings($pdo, (int) ($user['id'] ?? 0))['gemini_api_key'] ?? '')) !== '';
$canDelete = ems_is_manager_plus_role($user['role'] ?? '');

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between mb-4">
            <div>
                <h1 class="page-title">AI Diagnosis Assistant</h1>
                <p class="page-subtitle">Masukkan anamnesis pasien untuk mendapatkan analisis dari model AI sesuai SOP Roxwood Hospital dan standar medis internasional.</p>
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
                Anda belum mengatur API key Gemini pribadi. <a href="ai_settings_personal.php" class="font-bold underline">Atur sekarang di Setting AI Saya</a> sebelum membuat diagnosis.
            </div>
        <?php endif; ?>

        <div id="aiDiagFormError" class="hidden alert alert-error"></div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
            <div class="card">
                <div class="card-header">Anamnesis / Temuan Medis</div>
                <form method="POST" action="ai_diagnosis_assistant_action.php" class="form" id="aiDiagForm">
                    <?= csrfField(); ?>
                    <label>Anamnesis / Temuan Medis / Kondisi Fisik</label>
                    <textarea name="anamnesis" rows="8" required placeholder="Contoh: pasien laki-laki kecelakaan kecepatan tinggi, luka robek dan lecet..."><?= htmlspecialchars($_POST['anamnesis'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary" id="aiDiagSubmitBtn">
                            <?= ems_icon('sparkles', 'h-4 w-4') ?>
                            <span>Analisis Diagnosis</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Riwayat Terbaru</div>
                <div class="table-wrapper">
                    <table id="aiDiagHistoryTable" class="table-custom" data-auto-datatable="true">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Anamnesis</th>
                                <th>Oleh</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRows as $row): ?>
                                <tr>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $row['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(mb_strimwidth((string) $row['anamnesis'], 0, 90, '...'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($row['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="<?= $row['status'] === 'done' ? 'badge-success' : 'badge-danger' ?>">
                                            <?= $row['status'] === 'done' ? 'Selesai' : 'Gagal' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="ai_diagnosis_report.php?id=<?= (int) $row['id'] ?>" class="btn-secondary btn-sm">
                                                <?= ems_icon('eye', 'h-4 w-4') ?>
                                                <span>Lihat</span>
                                            </a>
                                            <?php if ($canDelete): ?>
                                                <form method="POST" action="ai_diagnosis_report.php?id=<?= (int) $row['id'] ?>" onsubmit="return confirm('Hapus laporan diagnosis #<?= (int) $row['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
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
                                <tr><td colspan="5" class="text-center text-slate-400 py-6">Belum ada riwayat diagnosis.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<div id="aiDiagLoadingOverlay" class="global-upload-overlay hidden" aria-hidden="true">
    <div class="global-upload-overlay-box">
        <div class="global-upload-spinner" aria-hidden="true"></div>
        <div class="global-upload-title">Menganalisis diagnosis...</div>
        <div id="aiDiagLoadingMessage" class="global-upload-copy">Menyiapkan permintaan...</div>
        <div id="aiDiagLoadingBarWrap" style="margin-top:14px;height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;">
            <div id="aiDiagLoadingBar" style="height:100%;width:0%;border-radius:999px;background:#0ea5e9;transition:width .3s ease;"></div>
        </div>
        <div id="aiDiagLoadingPct" style="margin-top:6px;font-size:12px;font-weight:800;color:#0284c7;">0%</div>
        <div id="aiDiagLoadingErrorBox" class="hidden alert alert-error" style="margin-top:12px;text-align:left;"></div>
        <button type="button" id="aiDiagLoadingRetryBtn" class="btn-secondary hidden" style="margin-top:10px;">Tutup & Coba Lagi</button>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('aiDiagForm');
    if (!form) return;

    var overlay = document.getElementById('aiDiagLoadingOverlay');
    var spinner = overlay.querySelector('.global-upload-spinner');
    var messageEl = document.getElementById('aiDiagLoadingMessage');
    var bar = document.getElementById('aiDiagLoadingBar');
    var pct = document.getElementById('aiDiagLoadingPct');
    var errorBox = document.getElementById('aiDiagLoadingErrorBox');
    var retryBtn = document.getElementById('aiDiagLoadingRetryBtn');
    var submitBtn = document.getElementById('aiDiagSubmitBtn');

    var target = 0, shown = 0, creepTimer = null, stageTimers = [];

    var STAGES = [
        { at: 0, pct: 8, text: 'Menyiapkan & memvalidasi anamnesis...' },
        { at: 1500, pct: 20, text: 'Menerapkan guardrail SOP Roxwood Hospital...' },
        { at: 5000, pct: 35, text: 'Mengirim anamnesis ke model AI...' },
        { at: 15000, pct: 55, text: 'Model AI melakukan reasoning & analisis klinis...' },
        { at: 40000, pct: 75, text: 'Masih menganalisis, mohon tunggu...' },
        { at: 80000, pct: 88, text: 'Menyusun laporan medis...' },
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
        window.location.href = 'ai_diagnosis_report.php?id=' + encodeURIComponent(reportId);
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

        fetch('ai_diagnosis_assistant_action.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                if (!result.ok || !result.data.ok || !result.data.report_id) {
                    showError((result.data && result.data.message) || 'Gagal memproses diagnosis.');
                    return;
                }
                finishSuccess(result.data.report_id);
            })
            .catch(function () {
                showError('Tidak dapat menghubungi server (koneksi terputus atau timeout). Cek koneksi lalu coba lagi.');
            });
    });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
