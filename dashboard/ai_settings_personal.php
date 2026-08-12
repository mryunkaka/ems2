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
                <p class="page-subtitle">API key Gemini pribadi Anda, khusus untuk AI Diagnosis Assistant dan AI Surgery Planner.</p>
            </div>
            <div class="badge-info">Akses: Semua User</div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success mb-3"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger mb-3"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="alert alert-info mb-4">
            Setiap user mengisi API key Gemini miliknya sendiri untuk memakai AI Diagnosis Assistant &amp; AI Surgery Planner. Dapatkan API key gratis di Google AI Studio, lalu tempel di bawah ini.
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
