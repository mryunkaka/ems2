<?php

require_once __DIR__ . '/../config/groq_settings.php';
require_once __DIR__ . '/ai_gemini_client.php';

/**
 * Client untuk Groq (`https://api.groq.com`) — provider "tingkat 1" chat bot
 * Roxy, lihat docs/AI_ASSISTANT_MODULE.md §3. API-nya OpenAI-compatible
 * (endpoint `/openai/v1/chat/completions`, format `messages: [{role,
 * content}]`), auth Bearer token tunggal (lebih sederhana dari Cloudflare
 * yang butuh Account ID + Token terpisah). Pola cURL/logging di bawah
 * meniru actions/cloudflare_client.php yang sudah teruji (reuse
 * ems_ai_log_request() + emsFindCaBundlePath() dari ai_gemini_client.php).
 *
 * $settings adalah baris `user_ai_settings` (atau array sejenis) — dibaca
 * pakai nama kolom aslinya (`groq_api_key`/`groq_default_model`), PER-USER
 * bukan global, lihat config/groq_settings.php untuk alasannya.
 */
function ems_groq_chat_completion(
    PDO $pdo,
    array $settings,
    array $messages,
    ?string $model = null,
    string $featureKey = 'generic_chat',
    ?int $createdBy = null,
    bool $jsonMode = false
): array {
    $apiKey = trim((string) ($settings['groq_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Groq API Key belum diisi. Atur dulu di menu Roxwood Hospital AI > Setting AI Saya.');
    }

    $modelName = trim((string) ($model ?: ($settings['groq_default_model'] ?? 'openai/gpt-oss-120b')));

    $payload = [
        'model' => $modelName,
        'messages' => $messages,
        'temperature' => 0.4,
    ];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $requestHash = hash('sha256', $modelName . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $startedAt = microtime(true);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Gagal menginisialisasi koneksi cURL ke Groq.');
    }

    $curlOptions = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . 'Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    $caBundle = emsFindCaBundlePath();
    if ($caBundle !== null) {
        $curlOptions[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $curlOptions);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

    if ($responseBody === false) {
        ems_ai_log_request($pdo, [
            'feature_key' => $featureKey, 'provider' => 'groq', 'model_name' => $modelName,
            'request_hash' => $requestHash, 'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'response_payload' => null, 'http_status' => null, 'latency_ms' => $latencyMs,
            'success_flag' => 0, 'error_message' => $curlError, 'created_by' => $createdBy,
        ]);
        throw new RuntimeException('Request Groq gagal: ' . $curlError);
    }

    $json = json_decode((string) $responseBody, true);
    $success = $httpCode >= 200 && $httpCode < 300 && is_array($json);

    $content = null;
    $errorMessage = null;
    $usage = is_array($json) ? ($json['usage'] ?? []) : [];

    if ($success) {
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            $success = false;
            $errorMessage = 'Response Groq tidak mengandung konten jawaban.';
        }
    } else {
        $errorMessage = is_array($json)
            ? (string) ($json['error']['message'] ?? ('HTTP ' . $httpCode))
            : ('HTTP ' . $httpCode . ': ' . mb_strimwidth((string) $responseBody, 0, 300, '...'));
    }

    ems_ai_log_request($pdo, [
        'feature_key' => $featureKey,
        'provider' => 'groq',
        'model_name' => $modelName,
        'request_hash' => $requestHash,
        'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'response_payload' => $success ? mb_strimwidth((string) $content, 0, 2000, '...') : mb_strimwidth((string) $responseBody, 0, 1000, '...'),
        'prompt_tokens' => $usage['prompt_tokens'] ?? null,
        'response_tokens' => $usage['completion_tokens'] ?? null,
        'total_tokens' => $usage['total_tokens'] ?? null,
        'http_status' => $httpCode,
        'latency_ms' => $latencyMs,
        'success_flag' => $success,
        'error_message' => $success ? null : $errorMessage,
        'created_by' => $createdBy,
    ]);

    if (!$success) {
        throw new RuntimeException('Groq error: ' . ($errorMessage ?? 'gagal tidak diketahui'));
    }

    return [
        'model' => $modelName,
        'content' => $content,
        'http_status' => $httpCode,
        'usage' => $usage,
    ];
}

function ems_groq_test_connection(PDO $pdo, array $settings, ?int $createdBy = null): array
{
    return ems_groq_chat_completion(
        $pdo,
        $settings,
        [
            ['role' => 'system', 'content' => 'Kamu adalah asisten pengujian koneksi. Jawab singkat.'],
            ['role' => 'user', 'content' => 'Balas dengan tepat satu kalimat singkat berbahasa Indonesia untuk konfirmasi koneksi berhasil.'],
        ],
        (string) ($settings['groq_default_model'] ?? 'openai/gpt-oss-120b'),
        'groq_test_connection',
        $createdBy
    );
}
