<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/cloudflare_settings.php';
require_once __DIR__ . '/../actions/ai_guard.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_programmer_roxwood_access();
ems_cloudflare_ensure_tables($pdo);

$pageTitle = 'Setting Cloudflare AI | Farmasi EMS';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$settings = ems_cloudflare_get_settings($pdo);
$modelOptions = ems_cloudflare_model_options();
$tokenMasked = ems_cloudflare_mask_token($settings['api_token'] ?? '');
$savedAt = $settings['updated_at'] ?? $settings['created_at'] ?? null;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>
<section class="content">
    <div class="page page-shell-md">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div>
                <h1 class="page-title">Setting Cloudflare AI</h1>
                <p class="page-subtitle">Provider image-generation alternatif/gratis untuk Radiology Center (dipakai kalau Gemini image-generation belum bisa dipakai, mis. karena kuota).</p>
            </div>
            <div class="badge-info">Akses: Programmer Roxwood</div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success mb-3"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger mb-3"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
            <div class="card mb-0">
                <div class="card-header">
                    <?= ems_icon('cog-6-tooth', 'h-5 w-5') ?>
                    <span>Konfigurasi Cloudflare Workers AI</span>
                </div>

                <form method="post" action="cloudflare_settings_action.php?action=save" class="space-y-4">
                    <?= csrfField() ?>

                    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" name="is_enabled" value="1" <?= !empty($settings['is_enabled']) ? 'checked' : '' ?>>
                        <span>Aktifkan Cloudflare sebagai provider Radiology Center</span>
                    </label>

                    <div>
                        <label class="text-sm font-semibold text-slate-900" for="account_id">Account ID</label>
                        <input id="account_id" name="account_id" type="text" value="<?= htmlspecialchars((string) $settings['account_id'], ENT_QUOTES, 'UTF-8') ?>" placeholder="32 karakter hex dari dashboard Cloudflare">
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-900" for="api_token">API Token</label>
                        <input id="api_token" name="api_token" type="password" placeholder="<?= $tokenMasked !== '' ? htmlspecialchars($tokenMasked, ENT_QUOTES, 'UTF-8') : 'Masukkan Workers AI API Token' ?>" autocomplete="new-password">
                        <div class="helper-note mt-1">
                            Biarkan kosong jika tidak ingin mengganti token. Token aktif saat ini: <strong><?= $tokenMasked !== '' ? htmlspecialchars($tokenMasked, ENT_QUOTES, 'UTF-8') : 'belum diatur' ?></strong>
                            <?php if ($savedAt): ?>
                                <span>(terakhir diperbarui <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $savedAt)), ENT_QUOTES, 'UTF-8') ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-slate-900" for="default_model">Model Image Generation</label>
                        <select id="default_model" name="default_model">
                            <?php foreach ($modelOptions as $modelValue => $modelLabel): ?>
                                <option value="<?= htmlspecialchars($modelValue, ENT_QUOTES, 'UTF-8') ?>" <?= (string) $settings['default_model'] === $modelValue ? 'selected' : '' ?>><?= htmlspecialchars($modelLabel, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('check', 'h-4 w-4') ?>
                            <span>Simpan Setting</span>
                        </button>
                        <button type="submit" formaction="cloudflare_settings_action.php?action=test_connection" class="btn-success">
                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                            <span>Test Generate Gambar</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="space-y-4">
                <div class="card mb-0">
                    <div class="card-header">
                        <?= ems_icon('chart-bar', 'h-5 w-5') ?>
                        <span>Status</span>
                    </div>
                    <div class="space-y-3 text-sm text-slate-700">
                        <div>Status: <strong><?= !empty($settings['is_enabled']) ? 'Aktif' : 'Nonaktif' ?></strong></div>
                        <div>Account ID: <strong><?= htmlspecialchars((string) ($settings['account_id'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <div>Model default: <strong><?= htmlspecialchars((string) $settings['default_model'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                    </div>
                </div>

                <div class="card mb-0">
                    <div class="card-header">
                        <?= ems_icon('sparkles', 'h-5 w-5') ?>
                        <span>Cara Setup Cloudflare (Gratis)</span>
                    </div>
                    <div class="space-y-3 text-sm text-slate-700">
                        <div>1. Buat akun gratis di <a href="https://dash.cloudflare.com/sign-up" target="_blank" rel="noopener">dash.cloudflare.com</a> (tidak perlu kartu kredit untuk tier gratis Workers AI).</div>
                        <div>2. Login ke dashboard, buka menu <strong>Workers AI</strong> di sidebar kiri.</div>
                        <div>3. Di halaman itu, cari opsi <strong>Use REST API</strong> — klik <strong>Create a Workers AI API Token</strong>, konfirmasi, lalu salin token yang muncul (hanya tampil sekali).</div>
                        <div>4. Di halaman yang sama, salin nilai <strong>Account ID</strong>.</div>
                        <div>5. Tempel kedua nilai itu di form sebelah kiri, pilih model (default FLUX.1 [schnell] sudah paling direkomendasikan), centang <strong>Aktifkan</strong>, klik <strong>Simpan Setting</strong>, lalu <strong>Test Generate Gambar</strong>.</div>
                        <div>Tier gratis Cloudflare Workers AI: ~100.000 request/hari — lebih dari cukup untuk pemakaian internal EMS.</div>
                    </div>
                </div>

                <div class="card mb-0">
                    <div class="card-header">
                        <?= ems_icon('exclamation-triangle', 'h-5 w-5') ?>
                        <span>Catatan</span>
                    </div>
                    <div class="space-y-2 text-sm text-slate-700">
                        <div>Account ID &amp; API Token hanya dipakai di server PHP, tidak pernah dikirim ke browser.</div>
                        <div>Kalau provider ini Aktif, Radiology Center otomatis memakainya (bukan Gemini) untuk generate citra.</div>
                        <div>Kalau Nonaktif atau belum diisi, Radiology Center otomatis fallback ke Gemini seperti sebelumnya.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/../partials/footer.php'; ?>
