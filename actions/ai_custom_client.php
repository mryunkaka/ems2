<?php

require_once __DIR__ . '/ai_gemini_client.php';

function ems_ai_custom_completion_url(string $baseUrl): string
{
    $url = rtrim(trim($baseUrl), '/');
    if ($url !== '' && !preg_match('~/chat/completions$~i', $url)) {
        $url .= '/chat/completions';
    }
    return $url;
}

function ems_ai_custom_redact(string $value, string $secret): string
{
    return $secret !== '' ? str_replace($secret, '[REDACTED]', $value) : $value;
}

function ems_custom_chat_completion(
    PDO $pdo,
    array $settings,
    array $messages,
    ?string $model = null,
    string $featureKey = 'custom_chat',
    ?int $createdBy = null,
    bool $jsonMode = false
): array {
    $modelName = trim((string) ($model ?: ($settings['custom_default_model'] ?? '')));
    $url = ems_ai_custom_completion_url((string) ($settings['custom_base_url'] ?? ''));
    $apiKey = trim((string) ($settings['custom_api_key'] ?? ''));

    $urlParts = $url !== '' ? parse_url($url) : false;
    if (
        $url === ''
        || $modelName === ''
        || $urlParts === false
        || !in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['http', 'https'], true)
        || trim((string) ($urlParts['host'] ?? '')) === ''
        || isset($urlParts['user'], $urlParts['pass'], $urlParts['query'], $urlParts['fragment'])
    ) {
        throw new RuntimeException('Endpoint dan model custom wajib diisi; endpoint harus URL HTTP/HTTPS yang valid.');
    }

    $payload = [
        'model' => $modelName,
        'messages' => $messages,
        'temperature' => 0.2,
    ];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $headers = [];
    if ($apiKey !== '') {
        $headers['Authorization'] = 'Bearer ' . $apiKey;
    }

    $startedAt = microtime(true);
    $response = ems_ai_http_post_json($url, $payload, $headers, 120, (string) ($settings['custom_provider'] ?? 'Custom provider'));
    $responseJson = is_array($response['json'] ?? null) ? $response['json'] : [];
    $content = $responseJson['choices'][0]['message']['content'] ?? null;
    if (is_string($content)) {
        $content = ems_ai_custom_redact($content, $apiKey);
    }
    $success = $response['http_status'] >= 200
        && $response['http_status'] < 300
        && is_string($content)
        && trim($content) !== '';
    $errorMessage = $success
        ? null
        : (string) ($responseJson['error']['message'] ?? ('HTTP ' . $response['http_status']));
    if ($errorMessage !== null) {
        $errorMessage = ems_ai_custom_redact($errorMessage, $apiKey);
    }
    $responseBody = ems_ai_custom_redact((string) ($response['body'] ?? ''), $apiKey);

    ems_ai_log_request($pdo, [
        'feature_key' => $featureKey,
        'provider' => mb_substr((string) ($settings['custom_provider'] ?? 'custom'), 0, 50),
        'model_name' => $modelName,
        'request_hash' => hash('sha256', $modelName . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'response_payload' => $success ? mb_substr($responseBody, 0, 4000) : mb_substr($responseBody, 0, 1000),
        'http_status' => $response['http_status'],
        'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'success_flag' => $success,
        'error_message' => $errorMessage,
        'created_by' => $createdBy,
    ]);

    if (!$success) {
        throw new RuntimeException('Custom provider error: ' . $errorMessage);
    }

    return [
        'model' => $modelName,
        'content' => trim((string) $content),
        'http_status' => $response['http_status'],
        'usage' => is_array($responseJson) ? ($responseJson['usage'] ?? []) : [],
    ];
}

function ems_custom_test_connection(PDO $pdo, array $settings, ?int $createdBy = null): array
{
    return ems_custom_chat_completion(
        $pdo,
        $settings,
        [['role' => 'user', 'content' => 'Balas singkat: connection_test_success']],
        null,
        'ai_settings_custom_test_connection',
        $createdBy
    );
}
