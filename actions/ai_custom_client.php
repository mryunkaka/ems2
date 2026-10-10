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
        // Local resolvers can briefly return an empty answer during provider
        // traffic bursts. Retry the same lookup before rejecting a valid HTTPS
        // endpoint; the resulting address is still validated and pinned below.
        for ($dnsAttempt = 1; $dnsAttempt <= 3 && $addresses === []; $dnsAttempt++) {
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
            if ($addresses === [] && $dnsAttempt < 3) usleep(200000);
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

function ems_ai_custom_http_error_message(int $httpStatus, ?array $responseJson, string $responseBody, string $contentType = '', array $responseHeaders = []): string
{
    if (preg_match('/error\s*code\s*:\s*1033\b/i', $responseBody) === 1) {
        return 'HTTP 530 / Cloudflare Error 1033: Cloudflare Tunnel untuk hostname ini tidak memiliki connector cloudflared yang sehat. Periksa status tunnel dan service cloudflared di server router; URL dan API key EMS2 tidak dapat memperbaiki konektor tunnel yang terputus.';
    }
    if ($httpStatus === 502 && preg_match('/cloudflare|unable to reach the origin|origin service/i', $responseBody) === 1) {
        return 'HTTP 502 dari Cloudflare: tunnel tersambung, tetapi origin API tidak dapat dijangkau. Periksa service API dan kecocokan alamat/port origin pada route tunnel.';
    }
    $providerMessage = trim((string) ($responseJson['error']['message'] ?? ''));
    if ($providerMessage !== '') return $providerMessage;
    if ($httpStatus >= 200 && $httpStatus < 300) {
        if ($responseJson === null) {
            if (stripos($contentType, 'text/event-stream') !== false) {
                return 'Provider mengembalikan HTTP ' . $httpStatus . ' sebagai SSE (text/event-stream), tetapi stream tidak berisi teks assistant yang dapat dipakai. Periksa apakah router menutup stream sebelum content/finish_reason dikirim.';
            }
            $isHtml = stripos($contentType, 'text/html') !== false || preg_match('/<\s*!doctype\s+html|<\s*html\b/i', $responseBody) === 1;
            $isCloudflareChallenge = strtolower((string) ($responseHeaders['cf-mitigated'] ?? '')) === 'challenge'
                || preg_match('/cf-chl-|challenge-platform|just a moment\.\.\.|attention required.*cloudflare/i', $responseBody) === 1;
            $rayId = trim((string) ($responseHeaders['cf-ray'] ?? ''));
            $rayNote = $rayId !== '' ? ' CF-Ray: ' . $rayId . '.' : '';
            if ($isCloudflareChallenge) {
                return 'Cloudflare mengembalikan halaman challenge (HTTP ' . $httpStatus . ') sehingga model tidak menerima request. Buat aturan Cloudflare yang hanya mencakup hostname API dan path /v1/* untuk melewati browser/managed challenge pada route API; jangan menonaktifkan proteksi untuk seluruh domain.' . $rayNote;
            }
            $bodyKind = $isHtml ? 'HTML' : 'non-JSON';
            $typeNote = trim($contentType) !== '' ? ' Content-Type: ' . trim($contentType) . '.' : '';
            return 'Provider mengembalikan HTTP ' . $httpStatus . ', tetapi isi respons ' . $bodyKind . ', bukan JSON API. Pastikan base URL /v1 diarahkan ke origin 9Router dan hostname/path /v1/* tidak mengirim halaman browser/challenge.' . $typeNote . $rayNote;
        }
        $choice = $responseJson['choices'][0] ?? null;
        if (!is_array($choice)) {
            return 'Provider mengembalikan HTTP ' . $httpStatus . ' dengan JSON, tetapi formatnya bukan Chat Completions: field choices[0] tidak ditemukan.';
        }
        $message = $choice['message'] ?? null;
        if (!is_array($message)) {
            return 'Provider mengembalikan HTTP ' . $httpStatus . ', tetapi choices[0].message tidak ditemukan.';
        }
        $contentType = trim($contentType) !== '' ? trim($contentType) : 'tidak diketahui';
        return 'Provider mengembalikan HTTP ' . $httpStatus . ', tetapi choices[0].message.content kosong atau tidak didukung (Content-Type: ' . $contentType . ').';
    }
    return 'HTTP ' . $httpStatus;
}

/** Normalize standard OpenAI text and typed text-block content without using hidden reasoning fields. */
function ems_ai_custom_extract_message_content(mixed $content): string
{
    if (is_string($content)) return trim($content);
    if (!is_array($content)) return '';
    $texts = [];
    foreach ($content as $block) {
        if (is_string($block)) {
            $texts[] = $block;
        } elseif (is_array($block) && isset($block['text']) && is_string($block['text'])) {
            $texts[] = $block['text'];
        }
    }
    return trim(implode("\n", array_filter($texts, static fn(string $text): bool => trim($text) !== '')));
}

/** Read a normal Chat Completions object or an SSE stream returned by a compatible router. */
function ems_ai_custom_extract_completion(?array $responseJson, string $responseBody, string $contentType): array
{
    if (is_array($responseJson)) {
        return [
            'content' => ems_ai_custom_extract_message_content($responseJson['choices'][0]['message']['content'] ?? null),
            'finish_reason' => (string) ($responseJson['choices'][0]['finish_reason'] ?? ''),
            'usage' => is_array($responseJson['usage'] ?? null) ? $responseJson['usage'] : [],
            'format' => 'json',
        ];
    }
    if (stripos($contentType, 'text/event-stream') === false && !preg_match('/^\s*data:/m', $responseBody)) {
        return ['content' => '', 'finish_reason' => '', 'usage' => [], 'format' => 'unknown'];
    }

    $parts = [];
    $finishReason = '';
    $usage = [];
    foreach (preg_split('/\r?\n/', $responseBody) ?: [] as $line) {
        $line = trim($line);
        if (!str_starts_with($line, 'data:')) continue;
        $data = trim(substr($line, 5));
        if ($data === '' || $data === '[DONE]') continue;
        $event = json_decode($data, true);
        if (!is_array($event)) continue;
        if (is_array($event['usage'] ?? null)) $usage = $event['usage'];
        foreach (($event['choices'] ?? []) as $choice) {
            if (!is_array($choice)) continue;
            $text = ems_ai_custom_extract_message_content($choice['delta']['content'] ?? $choice['message']['content'] ?? null);
            if ($text !== '') $parts[] = $text;
            $reason = trim((string) ($choice['finish_reason'] ?? ''));
            if ($reason !== '') $finishReason = $reason;
        }
    }
    return ['content' => implode('', $parts), 'finish_reason' => $finishReason, 'usage' => $usage, 'format' => 'sse'];
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
        'temperature' => max(0, min(2, (float)($settings['temperature'] ?? 0.2))),
    ];
    if (isset($settings['top_p'])) $payload['top_p'] = max(0, min(1, (float)$settings['top_p']));
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
    $timeoutSeconds = max(5, min(120, (int)($settings['timeout_seconds'] ?? 120)));
    $response = ems_ai_http_post_json($url, $payload, $headers, $timeoutSeconds, (string) ($settings['custom_provider'] ?? 'Custom provider'), $curlResolve);
    $responseJson = is_array($response['json'] ?? null) ? $response['json'] : null;
    $completion = ems_ai_custom_extract_completion(
        $responseJson,
        (string) ($response['body'] ?? ''),
        (string) ($response['content_type'] ?? '')
    );
    $content = (string) $completion['content'];
    $finishReason = strtolower(trim((string) $completion['finish_reason']));
    $incompleteStream = ($completion['format'] ?? '') === 'sse' && $finishReason === '';
    if ($content !== '') {
        $content = ems_ai_custom_redact($content, $apiKey);
    }
    $success = $response['http_status'] >= 200
        && $response['http_status'] < 300
        && $content !== ''
        && $finishReason !== 'length'
        && !$incompleteStream;
    $errorMessage = $success
        ? null
        : ($finishReason === 'length'
            ? 'Respons model terpotong karena batas token keluaran (finish_reason=length).'
            : ($incompleteStream
                ? 'Stream provider terputus sebelum finish_reason; respons JSON mungkin tidak lengkap.'
            : ems_ai_custom_http_error_message(
                (int) $response['http_status'],
                $responseJson,
                (string) ($response['body'] ?? ''),
                (string) ($response['content_type'] ?? ''),
                is_array($response['response_headers'] ?? null) ? $response['response_headers'] : []
            )));
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
        'usage' => $completion['usage'],
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
