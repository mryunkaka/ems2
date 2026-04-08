<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_settings.php';
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
$hasTables = ems_ai_settings_table_exists($pdo) && ems_ai_request_logs_table_exists($pdo) && ems_ai_prompt_templates_table_exists($pdo);
$modelOptions = ems_ai_model_options();
$apiKeyMasked = ems_ai_mask_api_key($settings['gemini_api_key'] ?? '');
$savedAt = $settings['updated_at'] ?? $settings['created_at'] ?? null;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';
?>

<section class="content">
    <div class="page page-shell-md">
        <div class="flex items-center justify-between gap-4 mb-4">
            <div>
                <h1 class="page-title">Setting AI</h1>
                <p class="page-subtitle">Konfigurasi Gemini untuk pondasi fitur rekrutmen AI internal EMS.</p>
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
                Tabel AI belum lengkap. Jalankan migration <strong>`docs/sql/20_2026-04-08_gemini_ai_foundation.sql`</strong> terlebih dahulu.
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
                                <option value="gemini" <?= strtolower((string)$settings['provider']) === 'gemini' ? 'selected' : '' ?>>Gemini</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                                <input type="checkbox" name="is_enabled" value="1" <?= !empty($settings['is_enabled']) ? 'checked' : '' ?>>
                                <span>Aktifkan provider AI</span>
                            </label>
                        </div>
                    </div>

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

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="gemini_base_url">Base URL</label>
                            <input id="gemini_base_url" name="gemini_base_url" type="text" value="<?= htmlspecialchars((string)$settings['gemini_base_url']) ?>">
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="timeout_seconds">Timeout (detik)</label>
                            <input id="timeout_seconds" name="timeout_seconds" type="number" min="5" max="120" value="<?= (int)$settings['timeout_seconds'] ?>">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="default_model">Default Model</label>
                            <select id="default_model" name="default_model">
                                <?php foreach ($modelOptions as $modelName): ?>
                                    <option value="<?= htmlspecialchars($modelName) ?>" <?= (string)$settings['default_model'] === $modelName ? 'selected' : '' ?>><?= htmlspecialchars($modelName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="summary_model">Summary Model</label>
                            <select id="summary_model" name="summary_model">
                                <?php foreach ($modelOptions as $modelName): ?>
                                    <option value="<?= htmlspecialchars($modelName) ?>" <?= (string)$settings['summary_model'] === $modelName ? 'selected' : '' ?>><?= htmlspecialchars($modelName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="interview_question_model">Interview Question Model</label>
                            <select id="interview_question_model" name="interview_question_model">
                                <?php foreach ($modelOptions as $modelName): ?>
                                    <option value="<?= htmlspecialchars($modelName) ?>" <?= (string)$settings['interview_question_model'] === $modelName ? 'selected' : '' ?>><?= htmlspecialchars($modelName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-900" for="criteria_scoring_model">Criteria Guidance Model</label>
                            <select id="criteria_scoring_model" name="criteria_scoring_model">
                                <?php foreach ($modelOptions as $modelName): ?>
                                    <option value="<?= htmlspecialchars($modelName) ?>" <?= (string)$settings['criteria_scoring_model'] === $modelName ? 'selected' : '' ?>><?= htmlspecialchars($modelName) ?></option>
                                <?php endforeach; ?>
                            </select>
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
                        <button type="submit" formaction="<?= htmlspecialchars(ems_url('/dashboard/ai_settings_action.php?action=test_connection')) ?>" class="btn-success">
                            <?= ems_icon('arrow-path', 'h-4 w-4') ?>
                            <span>Test Connection Gemini</span>
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
                        <div>Provider aktif: <strong><?= htmlspecialchars(strtoupper((string)$settings['provider'])) ?></strong></div>
                        <div>Status AI: <strong><?= !empty($settings['is_enabled']) ? 'Aktif' : 'Nonaktif' ?></strong></div>
                        <div>Request hari ini: <strong><?= $todayUsage ?></strong> / <?= (int)$settings['daily_request_limit'] ?></div>
                        <div>Model default: <strong><?= htmlspecialchars((string)$settings['default_model']) ?></strong></div>
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
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
