<?php

require_once __DIR__ . '/../config/cloudflare_settings.php';
require_once __DIR__ . '/ai_gemini_client.php';

/**
 * Deteksi mime type gambar dari magic number bytes-nya sendiri, BUKAN
 * ditebak dari dokumentasi/nama model — beberapa model Workers AI (mis.
 * FLUX.1 schnell) mengembalikan field JSON "image" berisi JPEG, bukan PNG,
 * meski tidak ada indikasi format apa pun di response envelope-nya. Salah
 * tebak di sini bikin GD gagal buka file (overlay teks pasien jadi gagal
 * total tanpa error yang jelas) — jadi selalu percaya isi byte-nya sendiri.
 */
function ems_cloudflare_detect_image_mime(string $bytes): string
{
    if (str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A")) {
        return 'image/png';
    }
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
        return 'image/webp';
    }

    return 'image/png';
}

/**
 * Client untuk Cloudflare Workers AI (image generation) — dipakai Radiology
 * Center sebagai provider alternatif Gemini. Beda total dari Gemini: auth
 * pakai Account ID + Bearer API Token (bukan satu API key), endpoint
 * per-model (bukan satu endpoint generateContent), dan response BISA berupa
 * raw binary image ATAU JSON berisi base64 tergantung modelnya — jadi kode
 * di bawah mengecek Content-Type response, bukan asumsi salah satu format.
 * Referensi resmi: https://developers.cloudflare.com/workers-ai/get-started/rest-api/
 */
function ems_cloudflare_generate_image(PDO $pdo, array $settings, string $prompt, ?string $model = null, string $featureKey = 'generic_image', ?int $createdBy = null): array
{
    $accountId = trim((string) ($settings['account_id'] ?? ''));
    $apiToken = trim((string) ($settings['api_token'] ?? ''));
    if ($accountId === '' || $apiToken === '') {
        throw new RuntimeException('Cloudflare Account ID / API Token belum diisi.');
    }

    $modelName = trim((string) ($model ?: ($settings['default_model'] ?? '@cf/black-forest-labs/flux-1-schnell')));
    $timeoutSeconds = (int) ($settings['timeout_seconds'] ?? 60);

    $payload = ['prompt' => $prompt];
    $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($accountId) . '/ai/run/' . $modelName;
    $requestHash = hash('sha256', $modelName . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $startedAt = microtime(true);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Gagal menginisialisasi koneksi cURL ke Cloudflare.');
    }

    $curlOptions = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => max(5, $timeoutSeconds),
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    $caBundle = emsFindCaBundlePath();
    if ($caBundle !== null) {
        $curlOptions[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $curlOptions);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

    if ($raw === false) {
        ems_ai_log_request($pdo, [
            'feature_key' => $featureKey, 'provider' => 'cloudflare', 'model_name' => $modelName,
            'request_hash' => $requestHash, 'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'response_payload' => null, 'http_status' => null, 'latency_ms' => $latencyMs,
            'success_flag' => 0, 'error_message' => $curlError, 'created_by' => $createdBy,
        ]);
        throw new RuntimeException('Request Cloudflare gagal: ' . $curlError);
    }

    $responseHeaders = substr($raw, 0, $headerSize);
    $responseBody = substr($raw, $headerSize);
    $success = $httpCode >= 200 && $httpCode < 300;

    $image = null;
    $errorMessage = null;

    if ($success && str_starts_with($contentType, 'image/')) {
        // Beberapa model Workers AI (mis. Stable Diffusion XL) mengembalikan bytes gambar mentah.
        $image = ['mime_type' => $contentType, 'data' => base64_encode($responseBody)];
    } else {
        $json = json_decode($responseBody, true);
        if ($success && is_array($json)) {
            $resultImage = $json['result']['image'] ?? ($json['result'][0]['image'] ?? null);
            if (is_string($resultImage) && $resultImage !== '') {
                $decodedForSniff = base64_decode($resultImage, true);
                $detectedMime = $decodedForSniff !== false ? ems_cloudflare_detect_image_mime($decodedForSniff) : 'image/png';
                $image = ['mime_type' => $detectedMime, 'data' => $resultImage];
            } elseif (empty($json['success'])) {
                $errMsgs = array_map(static fn($e) => (string) ($e['message'] ?? 'unknown error'), $json['errors'] ?? []);
                $errorMessage = $errMsgs ? implode('; ', $errMsgs) : 'Cloudflare mengembalikan success=false tanpa detail error.';
            } else {
                $errorMessage = 'Response Cloudflare tidak mengandung data gambar yang dikenali.';
            }
        } elseif (!$success) {
            $errMsgs = is_array($json) ? array_map(static fn($e) => (string) ($e['message'] ?? 'unknown error'), $json['errors'] ?? []) : [];
            $errorMessage = $errMsgs ? implode('; ', $errMsgs) : ('HTTP ' . $httpCode . ': ' . mb_strimwidth($responseBody, 0, 300, '...'));
        } else {
            $errorMessage = 'Response Cloudflare tidak bisa diparse (bukan gambar maupun JSON valid).';
        }
    }

    ems_ai_log_request($pdo, [
        'feature_key' => $featureKey,
        'provider' => 'cloudflare',
        'model_name' => $modelName,
        'request_hash' => $requestHash,
        'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'response_payload' => $image ? '[image binary omitted, mime=' . $image['mime_type'] . ']' : mb_strimwidth($responseBody, 0, 1000, '...'),
        'http_status' => $httpCode,
        'latency_ms' => $latencyMs,
        'success_flag' => $image !== null,
        'error_message' => $image !== null ? null : $errorMessage,
        'created_by' => $createdBy,
    ]);

    if ($image === null) {
        throw new RuntimeException('Cloudflare error: ' . ($errorMessage ?? 'gagal tidak diketahui'));
    }

    return ['model' => $modelName, 'image' => $image, 'http_status' => $httpCode];
}

function ems_cloudflare_test_connection(PDO $pdo, array $settings, ?int $createdBy = null): array
{
    return ems_cloudflare_generate_image(
        $pdo,
        $settings,
        'a simple test image of a blue circle on white background',
        (string) ($settings['default_model'] ?? '@cf/black-forest-labs/flux-1-schnell'),
        'cloudflare_test_connection',
        $createdBy
    );
}
