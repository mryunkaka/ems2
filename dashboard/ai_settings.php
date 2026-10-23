<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_settings.php';
require_once __DIR__ . '/../config/ai_queue.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../actions/ai_guard.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

ems_require_programmer_roxwood_access();

$pageTitle = 'Setting AI';
$messages = $_SESSION['flash_messages'] ?? [];
$errors = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$settings = ems_ai_get_settings($pdo);
$promptTemplates = ems_ai_get_prompt_templates($pdo);
$todayUsage = ems_ai_count_today_requests($pdo);
$queueMonitor = [];
try { if (ems_table_exists($pdo, 'system_ai_request_queue')) $queueMonitor = ems_ai_queue_monitor($pdo); } catch (Throwable) { $queueMonitor = []; }
$hasBaseAiTables = ems_ai_settings_table_exists($pdo) && ems_ai_request_logs_table_exists($pdo) && ems_ai_prompt_templates_table_exists($pdo);
$hasCustomProviderColumns = ems_column_exists($pdo, 'system_ai_settings', 'custom_provider')
    && ems_column_exists($pdo, 'system_ai_settings', 'custom_api_key')
    && ems_column_exists($pdo, 'system_ai_settings', 'custom_base_url')
    && ems_column_exists($pdo, 'system_ai_settings', 'custom_default_model');
$hasTables = $hasBaseAiTables && $hasCustomProviderColumns;
$modelOptions = ems_ai_model_options();
$apiKeyMasked = ems_ai_mask_api_key($settings['gemini_api_key'] ?? '');
$customApiKeyMasked = ems_ai_mask_api_key($settings['custom_api_key'] ?? '');
$isOpenAiCompatible = strtolower((string)$settings['provider'] ?? '') === 'openai_compatible';
$savedAt = $settings['updated_at'] ?? $settings['created_at'] ?? null;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div>
                <h1 class="page-title">Setting AI</h1>
                <p class="page-subtitle">Satu konfigurasi provider untuk seluruh fitur AI di EMS2.</p>
            </div>
            <div class="badge-info">Akses: Programmer Roxwood</div>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success mb-3"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <?php if (!$hasTables): ?>
            <div class="alert alert-warning mb-4">
                <?php if (!$hasBaseAiTables): ?>Tabel dasar AI belum lengkap. Jalankan <strong>`docs/sql/20_2026-04-08_gemini_ai_foundation.sql`</strong>.<?php else: ?>Kolom provider global belum lengkap. Jalankan <strong>`docs/sql/99_2026-10-10_global_openai_compatible_provider.sql`</strong>.<?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
            <div class="card mb-0">
                <div class="card-header">
                    <?= ems_icon('cog-6-tooth', 'h-5 w-5') ?>
                    <span>Konfigurasi Provider AI</span>
                </div>

                <form method="post" action="<?= htmlspecialchars(ems_url('/dashboard/ai_settings_action.php?action=save')) ?>" class="space-y-4">
                    <?= csrfField() ?>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="provider">Provider</label>
                            <select id="provider" name="provider">
                                <option value="gemini" <?= !$isOpenAiCompatible ? 'selected' : '' ?>>Gemini</option>
                                <option value="openai_compatible" <?= $isOpenAiCompatible ? 'selected' : '' ?>>Custom Provider OpenAI-Compatible</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                <input type="checkbox" name="is_enabled" value="1" <?= !empty($settings['is_enabled']) ? 'checked' : '' ?>>
                                <span>Aktifkan provider AI</span>
                            </label>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="ai_max_concurrent_requests">Model berjalan bersamaan</label>
                            <select id="ai_max_concurrent_requests" name="ai_max_concurrent_requests">
                                <?php foreach ([1,2,3] as $limit): ?><option value="<?= $limit ?>" <?= (int)$settings['ai_max_concurrent_requests'] === $limit ? 'selected' : '' ?>><?= $limit ?> pekerjaan</option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="ai_max_queue_size">Batas pekerjaan menunggu</label>
                            <input id="ai_max_queue_size" name="ai_max_queue_size" type="number" min="10" max="200" value="<?= (int)$settings['ai_max_queue_size'] ?>">
                        </div>
                    </div>

                    <div id="geminiProviderFields" class="<?= $isOpenAiCompatible ? 'hidden' : '' ?>">
                    <div>
                        <label class="text-sm font-semibold text-slate-900" for="gemini_api_key">Gemini API Key</label>
                        <input
                            id="gemini_api_key"
                            name="gemini_api_key"
                            type="password"
                            placeholder="<?= $apiKeyMasked !== '' ? htmlspecialchars($apiKeyMasked) : 'Masukkan API key Gemini' ?>"
                            autocomplete="new-password">
                        <div class="helper-note mt-1">
                            Biarkan kosong jika tidak ingin mengganti API key. Key aktif saat ini: <strong><?= $apiKeyMasked !== '' ? htmlspecialchars($apiKeyMasked) : 'belum diatur' ?></strong>
                        </div>
                    </div>
                    </div>

                    <div id="customProviderFields" class="space-y-4 <?= $isOpenAiCompatible ? '' : 'hidden' ?>">
                        <div class="card mb-0">
                            <div class="card-header">Custom Provider OpenAI-Compatible</div>
                            <div class="grid gap-4">
                                <div><label for="custom_provider">Nama Provider</label><input id="custom_provider" name="custom_provider" type="text" maxlength="100" value="<?= htmlspecialchars((string)($settings['custom_provider'] ?? 'OpenAI-Compatible'), ENT_QUOTES, 'UTF-8') ?>" placeholder="9Router"></div>
                                <div><label for="custom_base_url">Base URL API</label><input id="custom_base_url" name="custom_base_url" type="url" maxlength="255" value="<?= htmlspecialchars((string)($settings['custom_base_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://endpoint-publik/v1"><small>Masukkan endpoint OpenAI-compatible yang bisa dijangkau server EMS2 melalui HTTPS.</small></div>
                                <div><label for="custom_api_key">API Key</label><input id="custom_api_key" name="custom_api_key" type="password" autocomplete="new-password" placeholder="<?= $customApiKeyMasked !== '' ? htmlspecialchars($customApiKeyMasked, ENT_QUOTES, 'UTF-8') : 'Masukkan API key provider' ?>"><div class="helper-note mt-1">Kosongkan jika tidak ingin mengganti key. Key aktif: <strong><?= $customApiKeyMasked !== '' ? htmlspecialchars($customApiKeyMasked, ENT_QUOTES, 'UTF-8') : 'belum diatur' ?></strong></div></div>
                                <div><label for="custom_default_model">Model</label><input id="custom_default_model" name="custom_default_model" type="text" maxlength="100" value="<?= htmlspecialchars((string)($settings['custom_default_model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="contoh: openai/gpt-4o-mini"></div>
                            </div>
                            <p class="helper-note mt-3">Endpoint harus HTTPS publik. API key hanya digunakan di server dan alamat privat ditolak untuk mencegah SSRF.</p>
                        </div>
                    </div>

                    <div id="geminiBaseFields" class="grid gap-4 md:grid-cols-2 <?= $isOpenAiCompatible ? 'hidden' : '' ?>">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="gemini_base_url">Base URL</label>
                            <input id="gemini_base_url" name="gemini_base_url" type="text" value="<?= htmlspecialchars((string)$settings['gemini_base_url']) ?>">
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="timeout_seconds">Timeout (detik)</label>
                            <input id="timeout_seconds" name="timeout_seconds" type="number" min="5" max="120" value="<?= (int)$settings['timeout_seconds'] ?>">
                        </div>
                    </div>

                    <div id="geminiModelFields">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="default_model">Default Model</label>
                            <select class="gemini-model-input" data-model="default_model" name="default_model">
                                <?php foreach ($modelOptions as $modelName): ?>
                                    <option value="<?= htmlspecialchars($modelName) ?>" <?= (string)$settings['default_model'] === $modelName ? 'selected' : '' ?>><?= htmlspecialchars($modelName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="summary_model">Summary Model</label>
                            <select class="gemini-model-input" data-model="summary_model" name="summary_model">
                                <?php foreach ($modelOptions as $modelName): ?>
                                    <option value="<?= htmlspecialchars($modelName) ?>" <?= (string)$settings['summary_model'] === $modelName ? 'selected' : '' ?>><?= htmlspecialchars($modelName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="interview_question_model">Interview Question Model</label>
                            <select class="gemini-model-input" data-model="interview_question_model" name="interview_question_model">
                                <?php foreach ($modelOptions as $modelName): ?>
                                    <option value="<?= htmlspecialchars($modelName) ?>" <?= (string)$settings['interview_question_model'] === $modelName ? 'selected' : '' ?>><?= htmlspecialchars($modelName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="criteria_scoring_model">Criteria Guidance Model</label>
                            <select class="gemini-model-input" data-model="criteria_scoring_model" name="criteria_scoring_model">
                                <?php foreach ($modelOptions as $modelName): ?>
                                    <option value="<?= htmlspecialchars($modelName) ?>" <?= (string)$settings['criteria_scoring_model'] === $modelName ? 'selected' : '' ?>><?= htmlspecialchars($modelName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-4">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="temperature">Temperature</label>
                            <input id="temperature" name="temperature" type="number" step="0.01" min="0" max="2" value="<?= htmlspecialchars((string)$settings['temperature']) ?>">
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="top_p">Top P</label>
                            <input id="top_p" name="top_p" type="number" step="0.01" min="0" max="1" value="<?= htmlspecialchars((string)$settings['top_p']) ?>">
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="top_k">Top K</label>
                            <input id="top_k" name="top_k" type="number" min="1" max="100" value="<?= (int)$settings['top_k'] ?>">
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="max_output_tokens">Max Output Tokens</label>
                            <input id="max_output_tokens" name="max_output_tokens" type="number" min="128" max="8192" value="<?= (int)$settings['max_output_tokens'] ?>">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="daily_request_limit">Batas Request Harian Internal</label>
                            <input id="daily_request_limit" name="daily_request_limit" type="number" min="1" max="5000" value="<?= (int)$settings['daily_request_limit'] ?>">
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="btn-primary">
                            <?= ems_icon('check', 'h-4 w-4') ?>
                            <span>Simpan Setting</span>
                        </button>
                        <button id="testGeminiButton" type="submit" formaction="<?= htmlspecialchars(ems_url('/dashboard/ai_settings_action.php?action=test_connection')) ?>" class="btn-success <?= $isOpenAiCompatible ? 'hidden' : '' ?>">
                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                            <span>Test Connection Gemini</span>
                        </button>
                        <button id="testCustomButton" type="submit" formaction="<?= htmlspecialchars(ems_url('/dashboard/ai_settings_action.php?action=test_connection_custom')) ?>" class="btn-success <?= $isOpenAiCompatible ? '' : 'hidden' ?>">
                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                            <span>Test Custom Provider</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="space-y-4">
                <div class="card mb-0">
                    <div class="card-header"><span>Antrean AI</span></div>
                    <?php if ($queueMonitor): ?>
                        <div class="space-y-2 text-sm text-slate-700">
                            <div>Menunggu <strong><?= (int)$queueMonitor['counts']['queued'] ?></strong> · berjalan <strong><?= (int)$queueMonitor['counts']['running'] ?></strong> · gagal <strong><?= (int)$queueMonitor['counts']['failed'] ?></strong></div>
                            <?php if ($queueMonitor['active']): ?><ul class="list-disc pl-5"><?php foreach ($queueMonitor['active'] as $active): ?><li><?= htmlspecialchars((string)($active['requester_name'] ?: 'Pengguna')) ?> — <?= htmlspecialchars((string)$active['feature_label']) ?></li><?php endforeach; ?></ul><?php else: ?><p>Tidak ada pekerjaan AI aktif saat ini.</p><?php endif; ?>
                        </div>
                    <?php else: ?><div class="helper-note">Antrean belum tersedia. Jalankan migrasi 103.</div><?php endif; ?>
                </div>

                <div class="card mb-0">
                    <div class="card-header">
                        <?= ems_icon('chart-bar', 'h-5 w-5') ?>
                        <span>Status</span>
                    </div>
                    <div class="space-y-3 text-sm text-slate-700">
                        <div>Provider aktif: <strong><?= htmlspecialchars($isOpenAiCompatible ? (string)($settings['custom_provider'] ?? 'OpenAI-Compatible') : 'Gemini') ?></strong></div>
                        <div>Status AI: <strong><?= !empty($settings['is_enabled']) ? 'Aktif' : 'Nonaktif' ?></strong></div>
                        <div>Request hari ini: <strong><?= $todayUsage ?></strong> / <?= (int)$settings['daily_request_limit'] ?></div>
                        <div>Model default: <strong><?= htmlspecialchars($isOpenAiCompatible ? (string)($settings['custom_default_model'] ?: $settings['default_model']) : (string)$settings['default_model']) ?></strong></div>
                        <div>Last update: <strong><?= $savedAt ? htmlspecialchars(formatTanggalID($savedAt)) : '-' ?></strong></div>
                    </div>
                </div>

                <div class="card mb-0">
                    <div class="card-header">
                        <?= ems_icon('document-text', 'h-5 w-5') ?>
                        <span>Prompt Templates</span>
                    </div>
                    <?php if ($promptTemplates): ?>
                        <div class="space-y-3 text-sm text-slate-700">
                            <?php foreach ($promptTemplates as $template): ?>
                                <div class="rounded-xl border border-slate-200 p-3">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)$template['title']) ?></div>
                                    <div>Feature: <code><?= htmlspecialchars((string)$template['feature_key']) ?></code></div>
                                    <div>Version: <strong><?= htmlspecialchars((string)$template['version_label']) ?></strong></div>
                                    <div>Status: <strong><?= !empty($template['is_active']) ? 'Aktif' : 'Nonaktif' ?></strong></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="helper-note">Belum ada prompt template yang tersedia atau migration belum dijalankan.</div>
                    <?php endif; ?>
                </div>

                <div class="card mb-0">
                    <div class="card-header">
                        <?= ems_icon('exclamation-triangle', 'h-5 w-5') ?>
                        <span>Catatan</span>
                    </div>
                    <div class="space-y-2 text-sm text-slate-700">
                        <div>API key hanya dipakai di server PHP dan tidak pernah dikirim ke browser.</div>
                        <div>Test connection akan memakai model default aktif saat ini.</div>
                        <div>Fitur AI rekrutmen berikutnya tinggal memanggil service Gemini yang sudah disiapkan.</div>
                    </div>
                </div>

                <div class="card mb-0">
                    <div class="card-header">
                        <?= ems_icon('sparkles', 'h-5 w-5') ?>
                        <span>Cara Setup Gemini</span>
                    </div>
                    <div class="space-y-3 text-sm text-slate-700">
                        <div>1. Jalankan migration <code>docs/sql/20_2026-04-08_gemini_ai_foundation.sql</code> jika tabel AI belum tersedia.</div>
                        <div>2. Buka Google AI Studio dan login dengan akun Google: <a href="https://ai.google.dev/aistudio" target="_blank" rel="noopener">https://ai.google.dev/aistudio</a></div>
                        <div>3. Buat atau pilih project, lalu generate API key dari halaman API Keys: <a href="https://ai.google.dev/gemini-api/docs/api-key?hl=id" target="_blank" rel="noopener">panduan resmi Gemini API key</a></div>
                        <div>4. Kembali ke halaman ini, isi <code>Gemini API Key</code>, biarkan <code>Base URL</code> default <code>https://generativelanguage.googleapis.com/v1beta</code>, lalu pilih model seperti <code>gemini-2.5-flash</code>.</div>
                        <div>5. Aktifkan checkbox <strong>Aktifkan provider AI</strong>, klik <strong>Simpan Setting</strong>, lalu klik <strong>Test Connection Gemini</strong>.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const provider = document.getElementById('provider');
    function updateProviderFields() {
        const custom = provider && provider.value === 'openai_compatible';
        document.getElementById('geminiProviderFields')?.classList.toggle('hidden', custom);
        document.getElementById('geminiBaseFields')?.classList.toggle('hidden', custom);
        document.getElementById('customProviderFields')?.classList.toggle('hidden', !custom);
        document.getElementById('geminiModelFields')?.classList.toggle('hidden', custom);
        document.getElementById('testGeminiButton')?.classList.toggle('hidden', custom);
        document.getElementById('testCustomButton')?.classList.toggle('hidden', !custom);
        document.querySelectorAll('.gemini-model-input').forEach(function(input) { input.disabled = custom; });
    }
    provider?.addEventListener('change', updateProviderFields);
    updateProviderFields();
});
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
