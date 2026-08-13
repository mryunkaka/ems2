<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_ai_ds_ensure_tables($pdo);

$pageTitle = 'Setting AI Saya | Farmasi EMS';
$user = $_SESSION['user_rh'] ?? [];
$userId = (int) ($user['id'] ?? 0);
$isProgrammer = ems_current_user_is_programmer_roxwood();

$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$userSettings = ems_ai_ds_get_user_settings($pdo, $userId) ?? [];
$apiKeyMasked = ems_ai_mask_api_key($userSettings['gemini_api_key'] ?? '');
$baseUrl = trim((string) ($userSettings['gemini_base_url'] ?? '')) !== ''
    ? (string) $userSettings['gemini_base_url']
    : 'https://generativelanguage.googleapis.com/v1beta';
$defaultModel = trim((string) ($userSettings['default_model'] ?? '')) !== ''
    ? (string) $userSettings['default_model']
    : 'gemini-3.5-flash-lite';
$modelOptions = ems_ai_model_options();
$savedAt = $userSettings['updated_at'] ?? $userSettings['created_at'] ?? null;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell-md">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div>
                <h1 class="page-title">Setting AI Saya</h1>
                <p class="page-subtitle">API key Gemini pribadi Anda — dipakai untuk seluruh fitur Roxwood Hospital AI (AI Diagnosis Assistant, AI Surgery Planner, Radiology Center, Laboratory AI, Psychiatry Center, dan Rekam Medis AI).</p>
            </div>
            <div class="badge-info">Akses: Semua User</div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success mb-3"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger mb-3"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <!-- TUTORIAL: CARA MENDAPATKAN API KEY GEMINI -->
        <div class="card mb-4">
            <div class="card-header">
                <?= ems_icon('information-circle', 'h-5 w-5') ?>
                <span>Cara Mendapatkan API Key Gemini (Gratis, ± 2 Menit)</span>
            </div>
            <div class="card-body space-y-3 text-sm text-slate-700">
                <p>
                    Setiap medis wajib mengisi API key Gemini <strong>milik sendiri</strong> (tidak boleh
                    pinjam/pakai bareng punya orang lain) supaya bisa memakai seluruh fitur Roxwood
                    Hospital AI: AI Diagnosis Assistant, AI Surgery Planner, Radiology Center, Laboratory AI,
                    Psychiatry Center, dan Rekam Medis AI. Cara membuatnya <strong>gratis</strong> dan cukup
                    pakai akun Google (Gmail) pribadi — tidak perlu kartu kredit.
                </p>

                <ol class="list-decimal ml-5 space-y-2">
                    <li>
                        Buka
                        <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer" class="font-semibold underline" style="color:#0284c7;">https://aistudio.google.com/apikey</a>
                        di tab baru (klik link ini langsung, atau salin lalu tempel ke browser), kemudian
                        <strong>login pakai akun Google/Gmail pribadi</strong> Anda (bukan akun EMS2).
                    </li>
                    <li>
                        Setelah masuk ke halaman Google AI Studio, cari dan klik tombol
                        <strong>"Create API key"</strong> (kadang tampil sebagai "Buat kunci API").
                    </li>
                    <li>
                        Kalau muncul pilihan project, klik
                        <strong>"Create API key in new project"</strong> (buat di project baru) — tidak perlu
                        mengubah pengaturan apa pun, langsung klik saja sampai key-nya muncul.
                    </li>
                    <li>
                        Sebuah kode akan muncul, formatnya diawali <code>AIza...</code>. Klik ikon
                        <strong>salin (copy)</strong> di sebelah kode itu untuk menyalinnya.
                    </li>
                    <li>
                        Kembali ke halaman ini, tempel (paste) kode tadi ke kolom
                        <strong>"Gemini API Key"</strong> di bawah.
                    </li>
                    <li>
                        Klik <strong>"Simpan Setting AI Saya"</strong>, lalu klik
                        <strong>"Test Koneksi Gemini"</strong> untuk memastikan key berhasil tersambung
                        sebelum dipakai di fitur-fitur AI.
                    </li>
                </ol>

                <div class="alert alert-warning !mt-3">
                    <strong>Penting soal keamanan:</strong> perlakukan API key seperti password pribadi —
                    jangan pernah dibagikan ke siapa pun, termasuk sesama rekan medis. Kalau merasa key
                    bocor atau dipakai orang lain, buat key baru di
                    <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer" class="underline">aistudio.google.com/apikey</a>
                    lalu ganti key lama di halaman ini dengan yang baru.
                </div>

                <div class="helper-note">
                    Catatan: pembuatan API key dan pemakaian untuk fitur berbasis teks (AI Diagnosis
                    Assistant, AI Surgery Planner, Laboratory AI, Psychiatry Center, Rekam Medis AI, serta
                    laporan teks Radiology Center) sepenuhnya gratis. Khusus citra/gambar hasil scan di
                    Radiology Center, Google mensyaratkan billing aktif di akun Google Cloud pribadi — kalau
                    fitur itu gagal dengan pesan terkait kuota (quota), itu bukan berarti API key Anda salah,
                    laporkan saja ke Programmer Roxwood.
                </div>
            </div>
        </div>

        <div class="card mb-0">
            <div class="card-header">
                <?= ems_icon('cog-6-tooth', 'h-5 w-5') ?>
                <span>Konfigurasi Gemini Pribadi</span>
            </div>

            <form method="post" action="ai_settings_personal_action.php?action=save" class="space-y-4">
                <?= csrfField(); ?>

                <div>
                    <label class="text-sm font-semibold text-slate-900" for="gemini_api_key">Gemini API Key</label>
                    <input
                        id="gemini_api_key"
                        name="gemini_api_key"
                        type="password"
                        placeholder="<?= $apiKeyMasked !== '' ? htmlspecialchars($apiKeyMasked, ENT_QUOTES, 'UTF-8') : 'Masukkan API key Gemini Anda' ?>"
                        autocomplete="new-password">
                    <div class="helper-note mt-1">
                        Biarkan kosong jika tidak ingin mengganti API key. Key aktif saat ini: <strong><?= $apiKeyMasked !== '' ? htmlspecialchars($apiKeyMasked, ENT_QUOTES, 'UTF-8') : 'belum diatur' ?></strong>
                        <?php if ($savedAt): ?>
                            <span>(terakhir diperbarui <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $savedAt)), ENT_QUOTES, 'UTF-8') ?>)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isProgrammer): ?>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="gemini_base_url">Base URL</label>
                            <input id="gemini_base_url" name="gemini_base_url" type="text" value="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="default_model">Model</label>
                            <select id="default_model" name="default_model">
                                <?php foreach ($modelOptions as $modelName): ?>
                                    <option value="<?= htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8') ?>" <?= $defaultModel === $modelName ? 'selected' : '' ?>><?= htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="helper-note">Field ini hanya tampil untuk Programmer Roxwood. User lain otomatis memakai Base URL &amp; Model default (<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($defaultModel, ENT_QUOTES, 'UTF-8') ?>).</div>
                <?php else: ?>
                    <input type="hidden" name="gemini_base_url" value="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="default_model" value="<?= htmlspecialchars($defaultModel, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="helper-note">Base URL &amp; Model diatur oleh Programmer Roxwood. Anda cukup masukkan API key Gemini di atas (model yang dipakai saat ini: <strong><?= htmlspecialchars($defaultModel, ENT_QUOTES, 'UTF-8') ?></strong>).</div>
                <?php endif; ?>

                <div class="flex flex-wrap gap-3 pt-2">
                    <button type="submit" class="btn-primary">
                        <?= ems_icon('check', 'h-4 w-4') ?>
                        <span>Simpan Setting AI Saya</span>
                    </button>
                    <button type="submit" formaction="ai_settings_personal_action.php?action=test_connection" class="btn-success">
                        <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                        <span>Test Koneksi Gemini</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../partials/footer.php'; ?>
