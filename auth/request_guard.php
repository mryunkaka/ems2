<?php

require_once __DIR__ . '/csrf.php';

function emsJsonAbort(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function emsRequestCsrfToken(): string
{
    $headerToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($headerToken !== '') {
        return $headerToken;
    }

    $postToken = trim((string)($_POST['csrf_token'] ?? ''));
    if ($postToken !== '') {
        return $postToken;
    }

    return trim((string)($_SERVER['HTTP_X_XSRF_TOKEN'] ?? ''));
}

function emsRequireJsonCsrf(string $message = 'Invalid CSRF token'): void
{
    if (!validateCsrfToken(emsRequestCsrfToken())) {
        emsJsonAbort(403, [
            'success' => false,
            'message' => $message,
        ]);
    }
}

function emsRateLimitCacheDir(): string
{
    $dir = __DIR__ . '/../storage/cache/request_rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function emsRateLimitKey(string $namespace, string $identifier): string
{
    return sha1($namespace . '|' . $identifier);
}

function emsRateLimitConsume(string $namespace, string $identifier, int $maxAttempts, int $windowSeconds): array
{
    $file = emsRateLimitCacheDir() . '/' . emsRateLimitKey($namespace, $identifier) . '.json';
    $now = time();
    $state = [
        'window_started_at' => $now,
        'attempts' => 0,
    ];

    if (is_file($file)) {
        $raw = json_decode((string)file_get_contents($file), true);
        if (is_array($raw)) {
            $state['window_started_at'] = (int)($raw['window_started_at'] ?? $now);
            $state['attempts'] = (int)($raw['attempts'] ?? 0);
        }
    }

    if (($now - $state['window_started_at']) >= $windowSeconds) {
        $state['window_started_at'] = $now;
        $state['attempts'] = 0;
    }

    $state['attempts']++;
    @file_put_contents($file, json_encode($state, JSON_UNESCAPED_SLASHES));

    $remaining = max(0, $maxAttempts - $state['attempts']);
    $retryAfter = max(0, $windowSeconds - ($now - $state['window_started_at']));

    return [
        'allowed' => $state['attempts'] <= $maxAttempts,
        'remaining' => $remaining,
        'retry_after' => $retryAfter,
        'attempts' => $state['attempts'],
    ];
}

function emsRequireRateLimit(string $namespace, string $identifier, int $maxAttempts, int $windowSeconds, string $message = 'Terlalu banyak request. Coba lagi nanti.'): void
{
    $result = emsRateLimitConsume($namespace, $identifier, $maxAttempts, $windowSeconds);
    if ($result['allowed']) {
        return;
    }

    header('Retry-After: ' . (string)$result['retry_after']);
    emsJsonAbort(429, [
        'success' => false,
        'message' => $message,
        'retry_after' => $result['retry_after'],
    ]);
}

function emsCurrentRequestIdentifier(?int $userId = null): string
{
    $userPart = $userId !== null && $userId > 0 ? 'user:' . $userId : 'guest';
    $ipPart = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $userPart . '|ip:' . $ipPart;
}
