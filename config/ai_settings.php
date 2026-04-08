<?php

require_once __DIR__ . '/helpers.php';

function ems_ai_settings_defaults(): array
{
    return [
        'id' => 0,
        'provider' => 'gemini',
        'is_enabled' => 1,
        'gemini_api_key' => '',
        'gemini_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'default_model' => 'gemini-2.5-flash',
        'summary_model' => 'gemini-2.5-flash',
        'interview_question_model' => 'gemini-2.5-flash',
        'criteria_scoring_model' => 'gemini-2.5-flash',
        'temperature' => '0.40',
        'top_p' => '0.95',
        'top_k' => 40,
        'max_output_tokens' => 2048,
        'timeout_seconds' => 30,
        'daily_request_limit' => 200,
        'created_by' => null,
        'updated_by' => null,
        'created_at' => null,
        'updated_at' => null,
    ];
}

function ems_ai_model_options(): array
{
    return [
        'gemini-2.5-flash',
        'gemini-2.5-pro',
        'gemini-2.5-flash-lite',
    ];
}

function ems_ai_settings_table_exists(PDO $pdo): bool
{
    return ems_table_exists($pdo, 'system_ai_settings');
}

function ems_ai_request_logs_table_exists(PDO $pdo): bool
{
    return ems_table_exists($pdo, 'system_ai_request_logs');
}

function ems_ai_prompt_templates_table_exists(PDO $pdo): bool
{
    return ems_table_exists($pdo, 'system_ai_prompt_templates');
}

function ems_ai_get_settings(PDO $pdo): array
{
    $defaults = ems_ai_settings_defaults();

    if (!ems_ai_settings_table_exists($pdo)) {
        return $defaults;
    }

    $stmt = $pdo->query("
        SELECT *
        FROM system_ai_settings
        ORDER BY id ASC
        LIMIT 1
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return array_merge($defaults, $row);
}

function ems_ai_mask_api_key(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
}

function ems_ai_get_prompt_templates(PDO $pdo): array
{
    if (!ems_ai_prompt_templates_table_exists($pdo)) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT id, feature_key, title, version_label, is_active, updated_at
        FROM system_ai_prompt_templates
        ORDER BY feature_key ASC, id ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ems_ai_get_active_prompt_template(PDO $pdo, string $featureKey): ?array
{
    if (!ems_ai_prompt_templates_table_exists($pdo)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM system_ai_prompt_templates
        WHERE feature_key = ?
          AND is_active = 1
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$featureKey]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ems_ai_count_today_requests(PDO $pdo): int
{
    if (!ems_ai_request_logs_table_exists($pdo)) {
        return 0;
    }

    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM system_ai_request_logs
        WHERE DATE(created_at) = CURDATE()
    ");

    return (int)$stmt->fetchColumn();
}
