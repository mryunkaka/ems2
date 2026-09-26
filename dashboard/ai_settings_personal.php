<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/groq_settings.php';
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
$hasUserSettings = $userSettings !== [];
$apiKeyMasked = ems_ai_mask_api_key($userSettings['gemini_api_key'] ?? '');
$baseUrl = $hasUserSettings
    ? trim((string) ($userSettings['gemini_base_url'] ?? ''))
    : 'https://generativelanguage.googleapis.com/v1beta';
$defaultModel = $hasUserSettings
    ? trim((string) ($userSettings['default_model'] ?? ''))
    : 'gemini-3.5-flash-lite';
$savedAt = $userSettings['updated_at'] ?? $userSettings['created_at'] ?? null;

$groqKeyMasked = ems_groq_mask_key($userSettings['groq_api_key'] ?? '');
$groqModel = $hasUserSettings
    ? trim((string) ($userSettings['groq_default_model'] ?? ''))
    : 'openai/gpt-oss-120b';
$groqSavedAt = $userSettings['updated_at'] ?? null;
$customProvider = trim((string) ($userSettings['custom_provider'] ?? ''));
$customApiKeyMasked = ems_ai_mask_api_key($userSettings['custom_api_key'] ?? '');
$customBaseUrl = trim((string) ($userSettings['custom_base_url'] ?? ''));
$customModel = trim((string) ($userSettings['custom_default_model'] ?? ''));

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell-md">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div>
                <h1 class="page-title">Setting AI Saya</h1>
                <p class="page-subtitle">Provider AI pribadi — Gemini, Groq, atau custom OpenAI-compatible seperti 9Router untuk fitur teks. Radiology Center tetap membutuhkan Gemini untuk generate citra.</p>
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
                <span>Cara Mendapatkan API Key Gemini (Opsional, Gratis, ± 2 Menit)</span>
            </div>
            <div class="card-body space-y-3 text-sm text-slate-700">
                <p>
                    Gemini bersifat opsional. Fitur teks juga dapat memakai custom provider OpenAI-compatible
                    seperti 9Router; Radiology Center membutuhkan Gemini khusus untuk generate citra. Jika memilih
                    Gemini, gunakan API key <strong>milik sendiri</strong> dan jangan dibagikan. Cara membuatnya
                    <strong>gratis</strong> dan cukup pakai akun Google (Gmail) pribadi — tidak perlu kartu kredit.
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
                        <strong>"Gemini API Key"</strong> di bawah jika memilih Gemini.
                    </li>
                    <li>
                        Klik <strong>"Simpan Setting Gemini"</strong>, lalu klik
                        <strong>"Test Koneksi Gemini"</strong> jika memilih Gemini.
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
                <span>Konfigurasi Provider AI Pribadi</span>
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
                            <input id="default_model" name="default_model" type="text" value="<?= htmlspecialchars($defaultModel, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required>
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
                        <span>Simpan Setting Gemini</span>
                    </button>
                    <button type="submit" formaction="ai_settings_personal_action.php?action=test_connection" class="btn-success">
                        <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                        <span>Test Koneksi Gemini</span>
                    </button>
                    <button type="submit" formaction="ai_settings_personal_action.php?action=clear_all" formnovalidate class="btn-danger" onclick="return confirm('Kosongkan semua API key, provider, endpoint, dan model Gemini, Groq, serta custom? Semua provider AI pribadi akan dinonaktifkan.');">
                        <?= ems_icon('trash', 'h-4 w-4') ?>
                        <span>Hapus Semua API Key &amp; Nonaktifkan Model</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- TUTORIAL: CARA MENDAPATKAN API KEY GROQ (untuk chat bot Roxy) -->
        <div class="card mt-4 mb-4">
            <div class="card-header">
                <?= ems_icon('chat-bubble-left-right', 'h-5 w-5') ?>
                <span>Cara Mendapatkan API Key Groq — untuk Chat Bot Roxy (Gratis, ± 2 Menit)</span>
            </div>
            <div class="card-body space-y-3 text-sm text-slate-700">
                <p>
                    Chat bot internal <strong>Roxy</strong> butuh API key Groq <strong>milik Anda sendiri</strong>
                    (tidak boleh pinjam/pakai bareng punya orang lain) — ini terpisah total dari API key Gemini
                    di atas, dan providernya beda (Groq, bukan Google). Alasan kenapa harus per-orang: tier
                    gratis Groq cuma 1.000 request/hari per akun, jadi kalau dipakai bareng-bareng oleh semua
                    staff, jatahnya akan cepat habis.
                </p>

                <ol class="list-decimal ml-5 space-y-2">
                    <li>
                        Buka
                        <a href="https://console.groq.com" target="_blank" rel="noopener noreferrer" class="font-semibold underline" style="color:#0284c7;">https://console.groq.com</a>
                        di tab baru, lalu <strong>login/daftar</strong> (bisa pakai akun Google/GitHub/email
                        pribadi, tidak perlu kartu kredit).
                    </li>
                    <li>
                        Di dashboard, buka menu <strong>API Keys</strong> di sidebar kiri.
                    </li>
                    <li>
                        Klik <strong>Create API Key</strong>, beri nama bebas (mis. "Roxy"), lalu salin key
                        yang muncul (formatnya diawali <code>gsk_...</code>, <strong>hanya tampil sekali</strong>
                        — kalau lupa menyalin, tinggal buat key baru).
                    </li>
                    <li>
                        Kembali ke halaman ini, tempel key tadi ke kolom <strong>"Groq API Key"</strong> di
                        bawah, klik <strong>"Simpan"</strong>, lalu <strong>"Test Koneksi Groq"</strong>.
                    </li>
                </ol>

                <div class="alert alert-warning !mt-3">
                    <strong>Penting soal keamanan:</strong> perlakukan API key Groq sama seperti password
                    pribadi dan API key Gemini — jangan pernah dibagikan ke siapa pun.
                </div>
            </div>
        </div>

        <div class="card mb-0">
            <div class="card-header">
                <?= ems_icon('chat-bubble-left-right', 'h-5 w-5') ?>
                <span>Konfigurasi Groq (Chat Bot Roxy)</span>
            </div>

            <form method="post" action="ai_settings_personal_action.php?action=save_groq" class="space-y-4">
                <?= csrfField(); ?>

                <div>
                    <label class="text-sm font-semibold text-slate-900" for="groq_api_key">Groq API Key</label>
                    <input
                        id="groq_api_key"
                        name="groq_api_key"
                        type="password"
                        placeholder="<?= $groqKeyMasked !== '' ? htmlspecialchars($groqKeyMasked, ENT_QUOTES, 'UTF-8') : 'Masukkan Groq API Key (gsk_...)' ?>"
                        autocomplete="new-password">
                    <div class="helper-note mt-1">
                        Biarkan kosong jika tidak ingin mengganti key. Key aktif saat ini: <strong><?= $groqKeyMasked !== '' ? htmlspecialchars($groqKeyMasked, ENT_QUOTES, 'UTF-8') : 'belum diatur' ?></strong>
                        <?php if ($groqSavedAt && $groqKeyMasked !== ''): ?>
                            <span>(terakhir diperbarui <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $groqSavedAt)), ENT_QUOTES, 'UTF-8') ?>)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <label class="text-sm font-semibold text-slate-900" for="groq_model">Model</label>
                    <input id="groq_model" name="groq_model" type="text" value="<?= htmlspecialchars($groqModel, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required>
                </div>

                <div class="flex flex-wrap gap-3 pt-2">
                    <button type="submit" class="btn-primary">
                        <?= ems_icon('check', 'h-4 w-4') ?>
                        <span>Simpan</span>
                    </button>
                    <button type="submit" formaction="ai_settings_personal_action.php?action=test_connection_groq" class="btn-success">
                        <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                        <span>Test Koneksi Groq</span>
                    </button>
                </div>
            </form>
        </div>

        <?php if ($isProgrammer): ?>
            <div class="card mt-4 mb-0">
                <div class="card-header">
                    <?= ems_icon('globe-alt', 'h-5 w-5') ?>
                    <span>Custom Provider OpenAI-Compatible</span>
                </div>
                <form method="post" action="ai_settings_personal_action.php?action=save_custom" class="space-y-4">
                    <?= csrfField(); ?>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="custom_provider">Nama Provider</label>
                            <input id="custom_provider" name="custom_provider" type="text" value="<?= htmlspecialchars($customProvider, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" placeholder="9Router">
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="custom_default_model">Model</label>
                            <input id="custom_default_model" name="custom_default_model" type="text" value="<?= htmlspecialchars($customModel, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" placeholder="cx/gpt-5.6-luna">
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-900" for="custom_base_url">Base URL / Endpoint Chat Completions</label>
                        <input id="custom_base_url" name="custom_base_url" type="url" value="<?= htmlspecialchars($customBaseUrl, ENT_QUOTES, 'UTF-8') ?>" maxlength="255" placeholder="http://127.0.0.1:20128/v1">
                        <div class="helper-note mt-1">Boleh isi Base URL seperti <code>/v1</code> atau URL lengkap yang berakhir <code>/chat/completions</code>. Sistem menambahkan path endpoint otomatis jika perlu.</div>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-900" for="custom_api_key">API Key Custom</label>
                        <input id="custom_api_key" name="custom_api_key" type="password" placeholder="<?= $customApiKeyMasked !== '' ? htmlspecialchars($customApiKeyMasked, ENT_QUOTES, 'UTF-8') : 'Masukkan API key custom' ?>" autocomplete="new-password">
                        <div class="helper-note mt-1">Provider custom memakai format request OpenAI Chat Completions. Key aktif: <strong><?= $customApiKeyMasked !== '' ? htmlspecialchars($customApiKeyMasked, ENT_QUOTES, 'UTF-8') : 'belum diatur' ?></strong>. Kosongkan key jika endpoint lokal tidak memerlukan autentikasi.</div>
                    </div>
                    <div class="helper-note">Jika Nama Provider, Endpoint, dan Model terisi, custom provider menjadi provider utama fitur teks. Kosongkan konfigurasi custom untuk menonaktifkannya dan kembali ke provider lain.</div>
                    <div class="flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="btn-primary"><?= ems_icon('check', 'h-4 w-4') ?><span>Simpan Custom Provider</span></button>
                        <button type="submit" formaction="ai_settings_personal_action.php?action=test_connection_custom" class="btn-success"><?= ems_icon('arrow-path', 'h-4 w-4') ?><span>Test Custom Provider</span></button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/../partials/footer.php'; ?>
