<?php

require_once __DIR__ . '/helpers.php';

/**
 * Cloudflare Workers AI — provider image-generation alternatif/gratis untuk
 * Radiology Center, terpisah total dari sistem Gemini (auth-nya Account ID
 * + Bearer API Token, bukan satu API key seperti Gemini). Global, dikelola
 * Programmer Roxwood lewat dashboard/cloudflare_settings.php — bukan per-user
 * seperti user_ai_settings, karena bikin akun Cloudflare + token jauh lebih
 * ribet dibanding tempel API key Gemini biasa.
 */

function ems_cloudflare_settings_table_exists(PDO $pdo): bool
{
    return ems_table_exists($pdo, 'system_cloudflare_settings');
}

function ems_cloudflare_ensure_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    if (!ems_table_exists($pdo, 'system_cloudflare_settings')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `system_cloudflare_settings` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `account_id` VARCHAR(64) NOT NULL DEFAULT '',
                `api_token` VARCHAR(255) NOT NULL DEFAULT '',
                `default_model` VARCHAR(100) NOT NULL DEFAULT '@cf/black-forest-labs/flux-1-schnell',
                `created_by` INT NULL,
                `updated_by` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

function ems_cloudflare_settings_defaults(): array
{
    return [
        'id' => 0,
        'is_enabled' => 0,
        'account_id' => '',
        'api_token' => '',
        'default_model' => '@cf/black-forest-labs/flux-1-schnell',
        'created_by' => null,
        'updated_by' => null,
        'created_at' => null,
        'updated_at' => null,
    ];
}

/**
 * Sengaja TIDAK pre-check lewat ems_cloudflare_settings_table_exists() di sini
 * — ems_table_exists() punya static cache per-request yang di-set ke false
 * kalau dicek SEBELUM tabel dibuat (mis. dari dalam ems_cloudflare_ensure_tables()
 * sendiri), dan tidak pernah ter-invalidate lagi meski tabelnya sudah benar-benar
 * dibuat di request yang sama. Query langsung + try/catch lebih aman daripada
 * kena stale-cache itu (sempat menyebabkan setting yang baru disimpan "hilang"
 * saat dibaca ulang di request yang sama).
 */
function ems_cloudflare_get_settings(PDO $pdo): array
{
    $defaults = ems_cloudflare_settings_defaults();

    try {
        $stmt = $pdo->query("SELECT * FROM system_cloudflare_settings ORDER BY id ASC LIMIT 1");
    } catch (Throwable $e) {
        return $defaults;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return array_merge($defaults, $row);
}

function ems_cloudflare_save_settings(PDO $pdo, array $data, int $userId): void
{
    ems_cloudflare_ensure_tables($pdo);
    $existing = ems_cloudflare_get_settings($pdo);

    if ((int) $existing['id'] > 0) {
        $stmt = $pdo->prepare("
            UPDATE system_cloudflare_settings
            SET is_enabled = ?, account_id = ?, api_token = ?, default_model = ?, updated_by = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['is_enabled'] ? 1 : 0,
            $data['account_id'],
            $data['api_token'],
            $data['default_model'],
            $userId,
            (int) $existing['id'],
        ]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO system_cloudflare_settings (is_enabled, account_id, api_token, default_model, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['is_enabled'] ? 1 : 0,
        $data['account_id'],
        $data['api_token'],
        $data['default_model'],
        $userId,
        $userId,
    ]);
}

function ems_cloudflare_model_options(): array
{
    return [
        '@cf/black-forest-labs/flux-1-schnell' => 'FLUX.1 [schnell] — direkomendasikan, tercepat & terbaik',
        '@cf/bytedance/stable-diffusion-xl-lightning' => 'Stable Diffusion XL Lightning — sangat cepat, gaya realistis',
        '@cf/stabilityai/stable-diffusion-xl-base-1.0' => 'Stable Diffusion XL Base 1.0 — kualitas tinggi',
        '@cf/lykon/dreamshaper-8-lcm' => 'Dreamshaper 8 LCM — gaya artistik/stylized',
    ];
}

function ems_cloudflare_mask_token(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
}
