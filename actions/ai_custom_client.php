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

/** Resolve and pin only publicly routable HTTPS destinations to prevent SSRF. */
function ems_ai_custom_public_endpoint_resolution(string $url): array
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        throw new RuntimeException('Custom provider hanya mendukung endpoint HTTPS.');
    }
    $urlHost = trim((string) ($parts['host'] ?? ''));
    $host = strtolower(rtrim($urlHost, '.'));
    $port = (int) ($parts['port'] ?? 443);
    if ($host === '' || $port < 1 || $port > 65535 || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Endpoint custom harus berupa URL HTTPS yang valid.');
    }
    if (preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid|example)$/i', $host) === 1) {
        throw new RuntimeException('Endpoint custom harus menggunakan hostname publik.');
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $addresses = [$host];
    } else {
        $addresses = [];
        $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A | DNS_AAAA) : false;
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                if ($address !== '') $addresses[] = $address;
            }
        }
        if ($addresses === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) $addresses = $ipv4;
        }
    }

    $addresses = array_values(array_unique($addresses));
    if ($addresses === []) {
        throw new RuntimeException('Hostname endpoint custom tidak dapat di-resolve.');
    }
    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Endpoint custom tidak boleh mengarah ke alamat jaringan privat atau khusus.');
        }
    }

    // IP-literal URLs are already pinned by their host component and do not
    // need CURLOPT_RESOLVE (whose host syntax is hostname-oriented).
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) return [];

    $ip = $addresses[0];
    $curlAddress = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
    return [$urlHost . ':' . $port . ':' . $curlAddress];
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
    bool $jsonMode = false,
    ?int $maxOutputTokens = null
): array {
    $modelName = trim((string) ($model ?: ($settings['custom_default_model'] ?? '')));
    $url = ems_ai_custom_completion_url((string) ($settings['custom_base_url'] ?? ''));
    $apiKey = trim((string) ($settings['custom_api_key'] ?? ''));

    $urlParts = $url !== '' ? parse_url($url) : false;
    if (
        $url === ''
        || $modelName === ''
        || $urlParts === false
        || strtolower((string) ($urlParts['scheme'] ?? '')) !== 'https'
        || trim((string) ($urlParts['host'] ?? '')) === ''
        || isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['query']) || isset($urlParts['fragment'])
    ) {
        throw new RuntimeException('Endpoint dan model custom wajib diisi; endpoint harus URL HTTPS publik yang valid.');
    }
    $curlResolve = ems_ai_custom_public_endpoint_resolution($url);

    $payload = [
        'model' => $modelName,
        'messages' => $messages,
        'temperature' => 0.2,
    ];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }
    if ($maxOutputTokens !== null && $maxOutputTokens > 0) {
        // OpenAI-compatible routers (including 9Router) otherwise may apply
        // a small provider default and truncate long structured plans.
        $payload['max_tokens'] = min(16384, max(256, $maxOutputTokens));
    }

    $headers = [];
    if ($apiKey !== '') {
        $headers['Authorization'] = 'Bearer ' . $apiKey;
    }

    $startedAt = microtime(true);
    // Surgery plans can take over 100 seconds on the configured router. Keep
    // enough room for a complete response while fitting one provider fallback
    // inside the app's bounded synchronous request budget.
    $timeoutSeconds = $featureKey === 'ai_surgery_planner' ? 105 : 120;
    $response = ems_ai_http_post_json($url, $payload, $headers, $timeoutSeconds, (string) ($settings['custom_provider'] ?? 'Custom provider'), $curlResolve);
    $responseJson = is_array($response['json'] ?? null) ? $response['json'] : [];
    $content = $responseJson['choices'][0]['message']['content'] ?? null;
    $finishReason = strtolower(trim((string) ($responseJson['choices'][0]['finish_reason'] ?? '')));
    if (is_string($content)) {
        $content = ems_ai_custom_redact($content, $apiKey);
    }
    $success = $response['http_status'] >= 200
        && $response['http_status'] < 300
        && is_string($content)
        && trim($content) !== ''
        && $finishReason !== 'length';
    $errorMessage = $success
        ? null
        : ($finishReason === 'length'
            ? 'Respons model terpotong karena batas token keluaran (finish_reason=length).'
            : (string) ($responseJson['error']['message'] ?? ('HTTP ' . $response['http_status'])));
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
