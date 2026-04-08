<?php

require_once __DIR__ . '/../config/ai_settings.php';

function ems_ai_log_request(PDO $pdo, array $data): void
{
    if (!ems_ai_request_logs_table_exists($pdo)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO system_ai_request_logs
        (
            feature_key,
            provider,
            model_name,
            request_hash,
            request_payload,
            response_payload,
            prompt_tokens,
            response_tokens,
            total_tokens,
            http_status,
            latency_ms,
            success_flag,
            error_message,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $data['feature_key'] ?? 'unknown',
        $data['provider'] ?? 'gemini',
        $data['model_name'] ?? '',
        $data['request_hash'] ?? '',
        $data['request_payload'] ?? null,
        $data['response_payload'] ?? null,
        $data['prompt_tokens'] ?? null,
        $data['response_tokens'] ?? null,
        $data['total_tokens'] ?? null,
        $data['http_status'] ?? null,
        $data['latency_ms'] ?? null,
        !empty($data['success_flag']) ? 1 : 0,
        $data['error_message'] ?? null,
        $data['created_by'] ?? null,
    ]);
}

function ems_ai_http_post_json(string $url, array $payload, array $headers, int $timeoutSeconds): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Gagal menginisialisasi koneksi cURL.');
    }

    $formattedHeaders = ['Content-Type: application/json'];
    foreach ($headers as $name => $value) {
        $formattedHeaders[] = $name . ': ' . $value;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $formattedHeaders,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => max(5, $timeoutSeconds),
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Request Gemini gagal: ' . $curlError);
    }

    $decoded = json_decode($body, true);

    return [
        'http_status' => $httpCode,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function ems_gemini_extract_text(?array $responseJson): string
{
    if (!$responseJson) {
        return '';
    }

    $parts = $responseJson['candidates'][0]['content']['parts'] ?? [];
    $texts = [];

    foreach ($parts as $part) {
        if (isset($part['text'])) {
            $texts[] = (string)$part['text'];
        }
    }

    return trim(implode("\n", $texts));
}

function ems_gemini_generate_content(PDO $pdo, array $settings, array $contents, ?string $model = null, string $featureKey = 'generic', ?int $createdBy = null): array
{
    $apiKey = trim((string)($settings['gemini_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('API key Gemini belum diisi.');
    }

    $provider = strtolower(trim((string)($settings['provider'] ?? 'gemini')));
    if ($provider !== 'gemini') {
        throw new RuntimeException('Provider AI aktif bukan Gemini.');
    }

    $dailyLimit = (int)($settings['daily_request_limit'] ?? 0);
    if ($dailyLimit > 0 && ems_ai_count_today_requests($pdo) >= $dailyLimit) {
        throw new RuntimeException('Batas request harian internal AI sudah tercapai.');
    }

    $modelName = trim((string)($model ?: ($settings['default_model'] ?? 'gemini-2.5-flash')));
    $baseUrl = rtrim((string)($settings['gemini_base_url'] ?? ''), '/');
    $timeoutSeconds = (int)($settings['timeout_seconds'] ?? 30);

    if ($baseUrl === '') {
        throw new RuntimeException('Base URL Gemini belum diisi.');
    }

    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => (float)($settings['temperature'] ?? 0.4),
            'topP' => (float)($settings['top_p'] ?? 0.95),
            'topK' => (int)($settings['top_k'] ?? 40),
            'maxOutputTokens' => (int)($settings['max_output_tokens'] ?? 2048),
            'responseMimeType' => 'application/json',
        ],
    ];

    $url = $baseUrl . '/models/' . rawurlencode($modelName) . ':generateContent';
    $requestHash = hash('sha256', $modelName . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $startedAt = microtime(true);

    try {
        $response = ems_ai_http_post_json($url, $payload, [
            'x-goog-api-key' => $apiKey,
        ], $timeoutSeconds);

        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);
        $responseJson = $response['json'] ?? null;
        $usage = $responseJson['usageMetadata'] ?? [];
        $success = $response['http_status'] >= 200 && $response['http_status'] < 300;

        ems_ai_log_request($pdo, [
            'feature_key' => $featureKey,
            'provider' => 'gemini',
            'model_name' => $modelName,
            'request_hash' => $requestHash,
            'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload' => $response['body'] ?? null,
            'prompt_tokens' => $usage['promptTokenCount'] ?? null,
            'response_tokens' => $usage['candidatesTokenCount'] ?? null,
            'total_tokens' => $usage['totalTokenCount'] ?? null,
            'http_status' => $response['http_status'],
            'latency_ms' => $latencyMs,
            'success_flag' => $success,
            'error_message' => $success ? null : (($responseJson['error']['message'] ?? 'Request Gemini gagal')),
            'created_by' => $createdBy,
        ]);

        if (!$success) {
            $errorMessage = (string)($responseJson['error']['message'] ?? ('HTTP ' . $response['http_status']));
            throw new RuntimeException('Gemini error: ' . $errorMessage);
        }

        return [
            'model' => $modelName,
            'text' => ems_gemini_extract_text($responseJson),
            'json' => $responseJson,
            'usage' => $usage,
            'http_status' => $response['http_status'],
        ];
    } catch (Throwable $e) {
        $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);
        ems_ai_log_request($pdo, [
            'feature_key' => $featureKey,
            'provider' => 'gemini',
            'model_name' => $modelName,
            'request_hash' => $requestHash,
            'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload' => null,
            'prompt_tokens' => null,
            'response_tokens' => null,
            'total_tokens' => null,
            'http_status' => null,
            'latency_ms' => $latencyMs,
            'success_flag' => 0,
            'error_message' => $e->getMessage(),
            'created_by' => $createdBy,
        ]);
        throw $e;
    }
}

function ems_gemini_test_connection(PDO $pdo, array $settings, ?int $createdBy = null): array
{
    return ems_gemini_generate_content(
        $pdo,
        $settings,
        [
            [
                'role' => 'user',
                'parts' => [
                    [
                        'text' => 'Balas dalam JSON valid dengan format {"status":"ok","provider":"gemini","message":"connection_test_success"}',
                    ],
                ],
            ],
        ],
        (string)($settings['default_model'] ?? 'gemini-2.5-flash'),
        'ai_settings_test_connection',
        $createdBy
    );
}
