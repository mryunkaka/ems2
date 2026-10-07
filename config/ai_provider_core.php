<?php

/** Shared personal Gemini/OpenAI-compatible provider settings and JSON caller. */
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/ai_official_documents.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';
require_once __DIR__ . '/../actions/ai_custom_client.php';

function ems_ai_provider_ensure_settings_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    if (!ems_table_exists($pdo, 'user_ai_settings')) {
        $pdo->exec("CREATE TABLE `user_ai_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `gemini_api_key` VARCHAR(255) NOT NULL DEFAULT '',
            `gemini_base_url` VARCHAR(255) NOT NULL DEFAULT 'https://generativelanguage.googleapis.com/v1beta',
            `default_model` VARCHAR(100) NOT NULL DEFAULT 'gemini-3.5-flash-lite',
            `groq_api_key` VARCHAR(255) NULL,
            `groq_default_model` VARCHAR(100) NOT NULL DEFAULT 'openai/gpt-oss-120b',
            `custom_provider` VARCHAR(100) NULL,
            `custom_api_key` VARCHAR(255) NULL,
            `custom_base_url` VARCHAR(255) NULL,
            `custom_default_model` VARCHAR(100) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uniq_user_ai_settings_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
    foreach ([
        'groq_api_key' => 'VARCHAR(255) NULL',
        'groq_default_model' => "VARCHAR(100) NOT NULL DEFAULT 'openai/gpt-oss-120b'",
        'custom_provider' => 'VARCHAR(100) NULL',
        'custom_api_key' => 'VARCHAR(255) NULL',
        'custom_base_url' => 'VARCHAR(255) NULL',
        'custom_default_model' => 'VARCHAR(100) NULL',
    ] as $column => $definition) {
        if (!ems_column_exists($pdo, 'user_ai_settings', $column)) {
            $pdo->exec("ALTER TABLE `user_ai_settings` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

function ems_ai_provider_get_user_settings(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0 || !ems_table_exists($pdo, 'user_ai_settings')) return null;
    $stmt = $pdo->prepare('SELECT * FROM user_ai_settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ems_ai_provider_has_gemini(?array $settings): bool
{
    return $settings !== null && trim((string)($settings['gemini_api_key'] ?? '')) !== '';
}

function ems_ai_provider_has_custom(?array $settings): bool
{
    return $settings !== null && trim((string)($settings['custom_provider'] ?? '')) !== ''
        && trim((string)($settings['custom_base_url'] ?? '')) !== ''
        && trim((string)($settings['custom_default_model'] ?? '')) !== '';
}

function ems_ai_provider_has_any(?array $settings): bool
{
    return ems_ai_provider_has_gemini($settings) || ems_ai_provider_has_custom($settings);
}

function ems_ai_provider_save_gemini(PDO $pdo, int $userId, string $apiKey, string $baseUrl, string $model): void
{
    $stmt = $pdo->prepare("INSERT INTO user_ai_settings (user_id, gemini_api_key, gemini_base_url, default_model) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE gemini_api_key=VALUES(gemini_api_key), gemini_base_url=VALUES(gemini_base_url), default_model=VALUES(default_model), updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$userId, $apiKey, $baseUrl, $model]);
}

function ems_ai_provider_save_custom(PDO $pdo, int $userId, string $provider, string $apiKey, string $baseUrl, string $model): void
{
    $stmt = $pdo->prepare("INSERT INTO user_ai_settings (user_id, gemini_api_key, gemini_base_url, default_model, custom_provider, custom_api_key, custom_base_url, custom_default_model)
        VALUES (?, '', 'https://generativelanguage.googleapis.com/v1beta', 'gemini-3.5-flash-lite', ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE custom_provider=VALUES(custom_provider), custom_api_key=VALUES(custom_api_key), custom_base_url=VALUES(custom_base_url), custom_default_model=VALUES(custom_default_model), updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$userId, $provider, $apiKey, $baseUrl, $model]);
}

function ems_ai_provider_clear_settings(PDO $pdo, int $userId): void
{
    if ($userId <= 0) throw new InvalidArgumentException('ID pengguna tidak valid.');
    $stmt = $pdo->prepare("INSERT INTO user_ai_settings (user_id, gemini_api_key, gemini_base_url, default_model, groq_api_key, groq_default_model, custom_provider, custom_api_key, custom_base_url, custom_default_model)
        VALUES (?, '', '', '', '', '', '', '', '', '')
        ON DUPLICATE KEY UPDATE gemini_api_key='', gemini_base_url='', default_model='', groq_api_key='', groq_default_model='', custom_provider='', custom_api_key='', custom_base_url='', custom_default_model='', updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$userId]);
}

function ems_ai_provider_call_json(PDO $pdo, string $systemPrompt, string $userPrompt, string $featureKey, ?int $userId, ?array $schema = null, ?string $preferredProvider = null): array
{
    if (!$userId) return ['ok'=>false, 'error'=>'Sesi pengguna tidak valid. Silakan login ulang.'];
    $saved = ems_ai_provider_get_user_settings($pdo, $userId);
    $hasGemini = ems_ai_provider_has_gemini($saved);
    $hasCustom = ems_ai_provider_has_custom($saved);
    $force = strtolower(trim((string)$preferredProvider));
    if ($force === 'gemini' && !$hasGemini) return ['ok'=>false,'error'=>'Gemini pribadi belum dikonfigurasi lengkap.'];
    if ($force === 'custom' && !$hasCustom) return ['ok'=>false,'error'=>'Custom provider belum dikonfigurasi lengkap.'];
    if (!$hasGemini && !$hasCustom) return ['ok'=>false,'error'=>'Belum ada provider AI aktif. Atur di Setting AI Saya.'];

    if (!str_contains($systemPrompt, 'DOKUMEN RESMI ROXWOOD HOSPITAL') && !str_contains($userPrompt, 'DOKUMEN RESMI ROXWOOD HOSPITAL')) {
        $docs = ems_ai_official_document_context($pdo, ems_ai_official_document_unit($pdo, $userId), $userPrompt, $featureKey, 6);
        if ($docs !== '') $systemPrompt .= "\n\n" . $docs;
    }

    $callCustom = static function () use ($pdo, $saved, $systemPrompt, $userPrompt, $featureKey, $userId, $schema): array {
        try {
            $response = ems_custom_chat_completion($pdo, [
                'custom_provider'=>(string)($saved['custom_provider']??''),
                'custom_api_key'=>(string)($saved['custom_api_key']??''),
                'custom_base_url'=>(string)($saved['custom_base_url']??''),
                'custom_default_model'=>(string)($saved['custom_default_model']??''),
            ], [
                ['role'=>'system','content'=>$systemPrompt],
                ['role'=>'user','content'=>$userPrompt],
            ], (string)$saved['custom_default_model'], $featureKey, $userId, true, 8192);
            $data = ems_ai_decode_json_text(trim((string)($response['content']??'')));
            if (!is_array($data)) return ['ok'=>false,'error'=>'Respons custom provider bukan JSON valid.'];
            return ['ok'=>true,'data'=>$data,'usage'=>$response['usage']??[],'provider'=>(string)$saved['custom_provider'],'model'=>(string)$saved['custom_default_model']];
        } catch (Throwable $error) { return ['ok'=>false,'error'=>$error->getMessage()]; }
    };

    $useCustom = $force === 'custom' || (!$hasGemini && $hasCustom);
    if ($useCustom) return $callCustom();
    $settings = array_merge(ems_ai_settings_defaults(), [
        'provider'=>'gemini','is_enabled'=>1,
        'gemini_api_key'=>(string)$saved['gemini_api_key'],
        'gemini_base_url'=>trim((string)($saved['gemini_base_url']??'')) ?: 'https://generativelanguage.googleapis.com/v1beta',
        'default_model'=>trim((string)($saved['default_model']??'')) ?: 'gemini-3.5-flash-lite',
        'timeout_seconds'=>55,'max_output_tokens'=>8192,'daily_request_limit'=>0,
    ]);
    try {
        $contents = [['role'=>'user','parts'=>[['text'=>$systemPrompt],['text'=>$userPrompt]]]];
        $response = ems_gemini_generate_content($pdo, $settings, $contents, (string)$settings['default_model'], $featureKey, $userId, $schema);
        $data = ems_ai_decode_json_text(trim((string)($response['text']??'')));
        if (!is_array($data)) throw new RuntimeException('Respons Gemini bukan JSON valid.');
        return ['ok'=>true,'data'=>$data,'usage'=>$response['usage']??[],'provider'=>'Gemini','model'=>(string)$settings['default_model']];
    } catch (Throwable $error) {
        if ($force !== 'gemini' && $hasCustom) {
            $fallback = $callCustom();
            if (!empty($fallback['ok'])) { $fallback['fallback_from']='Gemini'; $fallback['fallback_reason']=$error->getMessage(); return $fallback; }
        }
        return ['ok'=>false,'error'=>$error->getMessage()];
    }
}
