<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_surgery_planner.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

$pageTitle = 'AI Surgery Planner | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$recentStmt = $pdo->prepare("
    SELECT p.id, p.jenis_operasi_kategori, p.kasus_tindakan, p.kompleksitas, p.status, p.created_at, u.full_name AS created_by_name
    FROM ai_surgery_plans p
    LEFT JOIN user_rh u ON u.id = p.user_id
    WHERE p.unit_code = ?
    ORDER BY p.id DESC
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
                <h1 class="page-title">AI Surgery Planner</h1>
                <p class="page-subtitle">Rancang rencana tindakan bedah (operative note) dan protokol farmakologi menggunakan model AI sesuai SOP Roxwood Hospital.</p>
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
                Anda belum mengatur API key Gemini pribadi. <a href="ai_settings_personal.php" class="font-bold underline">Atur sekarang di Setting AI Saya</a> sebelum membuat rencana operasi.
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-4">
            <div class="card">
                <div class="card-header">Data Rencana Operasi</div>
                <form method="POST" action="ai_surgery_planner_action.php" class="form" id="aiSurgForm">
                    <?= csrfField(); ?>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label>Jenis Operasi</label>
                            <select name="jenis_operasi" id="aiSurgJenisOperasi">
                                <option value="Mayor">Mayor</option>
                                <option value="Minor">Minor</option>
                            </select>
                            <p id="aiSurgJenisOperasiHint" class="page-subtitle" style="margin-top:6px;font-size:12px;">Mayor: risiko tinggi, anestesi umum/regional, butuh ICU/rawat inap.</p>
                        </div>
                        <div>
                            <label>Jenis Anestesi</label>
                            <select name="jenis_anestesi">
                                <option value="Umum (General)">Umum (General)</option>
                                <option value="Lokal">Lokal</option>
                                <option value="Spinal (Regional)">Spinal (Regional)</option>
                                <option value="Sedasi Sedang (Conscious Sedation)">Sedasi Sedang (Conscious Sedation)</option>
                            </select>
                        </div>
                    </div>

                    <label class="mt-4">Tingkat Kompleksitas (Jumlah Tahapan Prosedur)</label>
                    <select name="kompleksitas" id="aiSurgKompleksitas">
                        <option value="Mudah">Mudah - 10 tahapan</option>
                        <option value="Sedang" selected>Sedang - 20 tahapan</option>
                        <option value="Panjang">Panjang - 30 tahapan</option>
                    </select>
                    <p id="aiSurgKompleksitasHint" class="page-subtitle" style="margin-top:6px;font-size:12px;">Sedang: 20 langkah prosedur, durasi & detail proporsional dengan jumlah langkah.</p>

                    <label class="mt-4">Kasus Medis / Tindakan yang Diperlukan</label>
                    <textarea name="kasus_tindakan" rows="6" required placeholder="Contoh: operasi bypass arteri koroner pada pasien serangan jantung, riwayat hipertensi..."><?= htmlspecialchars($_POST['kasus_tindakan'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                    <div class="modal-actions mt-4">
                        <button type="submit" class="btn-primary" id="aiSurgSubmitBtn">
                            <?= ems_icon('clipboard-document-check', 'h-4 w-4') ?>
                            <span>Rancang Operasi & Farmakologi</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">Riwayat Terbaru</div>
                <div class="table-wrapper">
                    <table id="aiSurgHistoryTable" class="table-custom" data-auto-datatable="true">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Kasus</th>
                                <th>Oleh</th>
                                <th>Status</th>
                                <th class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentRows as $row): ?>
                                <tr>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $row['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars((string) $row['jenis_operasi_kategori'], ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string) $row['kompleksitas'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(mb_strimwidth((string) $row['kasus_tindakan'], 0, 80, '...'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="whitespace-nowrap"><?= htmlspecialchars((string) ($row['created_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="<?= $row['status'] === 'done' ? 'badge-success' : 'badge-danger' ?>">
                                            <?= $row['status'] === 'done' ? 'Selesai' : 'Gagal' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <a href="ai_surgery_report.php?id=<?= (int) $row['id'] ?>" class="btn-secondary btn-sm">
                                                <?= ems_icon('eye', 'h-4 w-4') ?>
                                                <span>Lihat</span>
                                            </a>
                                            <?php if ($canDelete): ?>
                                                <form method="POST" action="ai_surgery_report.php?id=<?= (int) $row['id'] ?>" onsubmit="return confirm('Hapus rencana operasi #<?= (int) $row['id'] ?> secara permanen? Tindakan ini tidak bisa dibatalkan.');">
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
                                <tr><td colspan="6" class="text-center text-slate-400 py-6">Belum ada riwayat rencana operasi.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<div id="aiSurgLoadingOverlay" class="global-upload-overlay hidden" aria-hidden="true">
    <div class="global-upload-overlay-box">
        <div class="global-upload-spinner" aria-hidden="true"></div>
        <div class="global-upload-title">Merancang rencana operasi...</div>
        <div id="aiSurgLoadingMessage" class="global-upload-copy">Menyiapkan permintaan...</div>
        <div id="aiSurgLoadingBarWrap" style="margin-top:14px;height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;">
            <div id="aiSurgLoadingBar" style="height:100%;width:0%;border-radius:999px;background:#0ea5e9;transition:width .3s ease;"></div>
        </div>
        <div id="aiSurgLoadingPct" style="margin-top:6px;font-size:12px;font-weight:800;color:#0284c7;">0%</div>
        <div id="aiSurgLoadingErrorBox" class="hidden alert alert-error" style="margin-top:12px;text-align:left;"></div>
        <button type="button" id="aiSurgLoadingRetryBtn" class="btn-secondary hidden" style="margin-top:10px;">Tutup & Coba Lagi</button>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('aiSurgForm');
    if (!form) return;

    var jenisOperasiSelect = document.getElementById('aiSurgJenisOperasi');
    var jenisOperasiHint = document.getElementById('aiSurgJenisOperasiHint');
    var OP_HINTS = {
        Mayor: 'Mayor: risiko tinggi, anestesi umum/regional, butuh ICU/rawat inap.',
        Minor: 'Minor: risiko rendah, anestesi lokal, durasi singkat, tanpa ICU.'
    };
    jenisOperasiSelect.addEventListener('change', function () {
        jenisOperasiHint.textContent = OP_HINTS[jenisOperasiSelect.value] || '';
    });

    var kompleksitasSelect = document.getElementById('aiSurgKompleksitas');
    var kompleksitasHint = document.getElementById('aiSurgKompleksitasHint');
    var KOMPLEKSITAS_HINTS = {
        Mudah: 'Mudah: 10 langkah prosedur, durasi & detail lebih ringkas.',
        Sedang: 'Sedang: 20 langkah prosedur, durasi & detail proporsional dengan jumlah langkah.',
        Panjang: 'Panjang: 30 langkah prosedur, durasi lebih lama & detail paling rinci.'
    };
    kompleksitasSelect.addEventListener('change', function () {
        kompleksitasHint.textContent = KOMPLEKSITAS_HINTS[kompleksitasSelect.value] || '';
    });

    var overlay = document.getElementById('aiSurgLoadingOverlay');
    var spinner = overlay.querySelector('.global-upload-spinner');
    var messageEl = document.getElementById('aiSurgLoadingMessage');
    var bar = document.getElementById('aiSurgLoadingBar');
    var pct = document.getElementById('aiSurgLoadingPct');
    var errorBox = document.getElementById('aiSurgLoadingErrorBox');
    var retryBtn = document.getElementById('aiSurgLoadingRetryBtn');
    var submitBtn = document.getElementById('aiSurgSubmitBtn');

    var target = 0, shown = 0, creepTimer = null, stageTimers = [];

    var STAGES = [
        { at: 0, pct: 8, text: 'Menyiapkan & memvalidasi data operasi...' },
        { at: 3000, pct: 24, text: 'Menerapkan referensi klasifikasi operasi & kewenangan...' },
        { at: 5000, pct: 34, text: 'Mengirim kasus ke model AI...' },
        { at: 12000, pct: 48, text: 'Model AI menyusun protokol farmakologi...' },
        { at: 30000, pct: 62, text: 'Model AI menyusun tahapan prosedur bedah...' },
        { at: 60000, pct: 75, text: 'Model AI masih menganalisis, mohon tunggu...' },
        { at: 90000, pct: 85, text: 'Menyusun risiko & laporan pasca-operasi...' },
        { at: 140000, pct: 93, text: 'Respons sebelumnya kurang sesuai, mencoba ulang otomatis...' },
        { at: 200000, pct: 96, text: 'Percobaan ulang sedang diproses...' }
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
    function finishSuccess(planId) {
        stopCreep(); clearStages();
        target = 100; shown = 100; renderProgress();
        messageEl.textContent = 'Selesai, membuka rencana operasi...';
        window.location.href = 'ai_surgery_report.php?id=' + encodeURIComponent(planId);
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

        fetch('ai_surgery_planner_action.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                if (!result.ok || !result.data.ok || !result.data.plan_id) {
                    showError((result.data && result.data.message) || 'Gagal memproses rencana operasi.');
                    return;
                }
                finishSuccess(result.data.plan_id);
            })
            .catch(function () {
                showError('Tidak dapat menghubungi server (koneksi terputus atau timeout). Cek koneksi lalu coba lagi.');
            });
    });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
