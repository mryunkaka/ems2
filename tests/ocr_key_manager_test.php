<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/ocr_key_manager.php';

function ocrAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ocrTestManager(string $secret = 'unit-test-encryption-secret-0123456789', ?Closure $transport = null): OcrKeyManager
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE ocr_api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        encrypted_key TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1,
        last_tested_at TEXT NULL,
        last_test_status TEXT NULL,
        last_error_category TEXT NULL,
        last_error_at TEXT NULL,
        last_success_at TEXT NULL,
        count_engine1 INTEGER NULL,
        count_engine2 INTEGER NULL,
        count_engine3 INTEGER NULL,
        usage_checked_at TEXT NULL,
        last_used_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE ocr_api_key_rotation (id INTEGER PRIMARY KEY, next_key_id INTEGER NULL)');
    $pdo->exec('CREATE TABLE ocr_api_key_bootstrap (id INTEGER PRIMARY KEY, legacy_imported INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('INSERT INTO ocr_api_key_bootstrap (id, legacy_imported) VALUES (1, 0)');

    return new OcrKeyManager($pdo, $secret, $transport);
}

function ocrTestManagerWithTransport(Closure $transport): OcrKeyManager
{
    return ocrTestManager('unit-test-encryption-secret-0123456789', $transport);
}

$tests = [
    'key encrypts and decrypts with authenticated ciphertext' => static function (): void {
        $manager = ocrTestManager();
        $ciphertext = $manager->encryptKey('sample-provider-key');
        ocrAssert($ciphertext !== 'sample-provider-key', 'key must not be stored in plaintext');
        ocrAssert($manager->decryptKey($ciphertext) === 'sample-provider-key', 'encrypted key must round-trip');
    },
    'tampered ciphertext is rejected' => static function (): void {
        $manager = ocrTestManager();
        $ciphertext = $manager->encryptKey('sample-provider-key');
        $tampered = substr($ciphertext, 0, -2) . 'xx';
        try {
            $manager->decryptKey($tampered);
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException('tampered ciphertext must be rejected');
    },
    'empty encryption secret fails closed' => static function (): void {
        try {
            ocrTestManager('')->encryptKey('sample-provider-key');
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException('empty secret must be rejected');
    },
    'weak and placeholder encryption secrets fail closed' => static function (): void {
        foreach (['short-secret', 'replace-with-a-random-64-character-hex-secret'] as $secret) {
            try {
                ocrTestManager($secret)->encryptKey('sample-provider-key');
            } catch (RuntimeException) {
                continue;
            }
            throw new RuntimeException('weak or placeholder secret must be rejected');
        }
    },
    'health test reports TLS errors as connection failures' => static function (): void {
        $transport = static fn(): array => ['http_code' => 0, 'curl_error' => 'SSL certificate verify failed', 'error' => 'SSL certificate verify failed', 'body' => ''];
        $manager = ocrTestManagerWithTransport($transport);
        $id = $manager->saveKey('tls-test-key');
        $result = $manager->testKey($id);
        ocrAssert(($result['category'] ?? '') === 'transport_error', 'TLS must be categorized as transport failure');
        ocrAssert(str_contains((string)($result['message'] ?? ''), 'TLS'), 'TLS message should explain connectivity');
        ocrAssert(!str_contains((string)($result['message'] ?? ''), 'Key ditolak'), 'TLS must not be described as invalid key');
    },
    'listed keys are masked and never expose plaintext' => static function (): void {
        $manager = ocrTestManager();
        $manager->saveKey('sample-provider-key', null, 'Primary');
        $keys = $manager->listKeys();
        ocrAssert(count($keys) === 1, 'expected one saved key');
        ocrAssert(!str_contains(json_encode($keys, JSON_THROW_ON_ERROR), 'sample-provider-key'), 'listing must not expose key');
        ocrAssert(($keys[0]['masked_key'] ?? '') !== '', 'listing should provide a masked key');
    },
    'usage probe calculates engine allowances and records daily freshness' => static function (): void {
        $transport = static function (string $method, string $url, string $key, array $payload): array {
            ocrAssert($method === 'POST', 'usage endpoint must use POST');
            ocrAssert($url === 'https://myapi.ocr.space/conversions', 'usage endpoint URL must be official');
            ocrAssert($key === 'usage-test-key', 'transport receives decrypted key only internally');
            return ['http_code' => 200, 'error' => '', 'body' => json_encode([
                'count_total' => 120,
                'count_engine1' => 20,
                'count_engine2' => 100,
                'count_engine3' => 7,
            ], JSON_THROW_ON_ERROR)];
        };
        $manager = ocrTestManagerWithTransport($transport);
        $id = $manager->saveKey('usage-test-key', null, 'Usage test');
        $usage = $manager->probeUsage($id);
        ocrAssert(($usage['success'] ?? false) === true, 'valid counts should be accepted');
        ocrAssert(($usage['engine12_used'] ?? null) === 120, 'Engine 1+2 usage should be combined');
        ocrAssert(($usage['engine12_remaining_estimate'] ?? null) === 24880, 'Engine 1+2 remaining estimate should use 25,000 monthly limit');
        ocrAssert(($usage['engine3_used'] ?? null) === 7, 'Engine 3 usage should be separate');
        ocrAssert(($usage['usage_verified'] ?? false) === true, 'nonzero usage should prove the key is recognized');
        ocrAssert(str_contains((string)($usage['freshness_note'] ?? ''), 'akhir hari sebelumnya'), 'freshness must explain the snapshot date');
    },
    'zero usage for an untested key is marked unverified' => static function (): void {
        $transport = static fn(): array => ['http_code' => 200, 'error' => '', 'body' => '{"count_total":0,"count_engine1":0,"count_engine2":0,"count_engine3":0}'];
        $manager = ocrTestManagerWithTransport($transport);
        $id = $manager->saveKey('untested-zero-key');
        $usage = $manager->probeUsage($id);
        ocrAssert(($usage['success'] ?? false) === true, 'zero counts should parse');
        ocrAssert(($usage['usage_verified'] ?? true) === false, 'zero counts cannot distinguish unknown key from no use');
    },
    'provider failure classifier retries only explicit key and quota conditions' => static function (): void {
        $manager = ocrTestManager();
        ocrAssert($manager->classifyProviderFailure(['http_code' => 401, 'body' => '{}']) === 'auth', '401 should be auth failure');
        ocrAssert($manager->classifyProviderFailure(['http_code' => 403, 'body' => '{"message":"IP access restricted"}']) === null, 'generic access restrictions should not rotate keys');
        ocrAssert($manager->classifyProviderFailure(['http_code' => 200, 'body' => '{"ErrorMessage":"Access denied by network firewall"}']) === null, 'network firewall errors should not be mistaken for key rejection');
        ocrAssert($manager->classifyProviderFailure(['http_code' => 200, 'body' => '{"IsErroredOnProcessing":true,"ErrorMessage":"Monthly quota exceeded"}']) === 'quota', 'explicit quota response should retry');
        ocrAssert($manager->classifyProviderFailure(['http_code' => 200, 'body' => '{"IsErroredOnProcessing":true,"ErrorMessage":"Maximum number of requests per day per IP has been reached"}']) === 'quota', 'explicit daily request ceiling should retry');
        ocrAssert($manager->classifyProviderFailure(['http_code' => 200, 'body' => '{"IsErroredOnProcessing":true,"ErrorMessage":"Page limit exceeded for this image"}']) === null, 'document page limits must not trigger key rotation');
        ocrAssert($manager->classifyProviderFailure(['http_code' => 200, 'body' => '{"IsErroredOnProcessing":true,"ErrorMessage":"Rate limit exceeded"}']) === 'rate_limit', 'explicit rate limit should retry');
        ocrAssert($manager->classifyProviderFailure(['http_code' => 200, 'body' => '{"IsErroredOnProcessing":true,"ErrorMessage":"Unable to parse image"}']) === null, 'generic parse failure must not retry');
        ocrAssert($manager->classifyProviderFailure(['http_code' => 0, 'curl_error' => 'SSL certificate verify failed', 'body' => '']) === null, 'TLS failure must not retry');
    },
    'health test uses OCR.Space image URL GET endpoint' => static function (): void {
        $transport = static function (string $method, string $url, string $key, array $payload): array {
            ocrAssert($method === 'GET', 'image URL endpoint should use GET');
            ocrAssert(str_contains($url, '/parse/imageurl'), 'image URL endpoint should be selected');
            ocrAssert(($payload['url'] ?? '') === 'https://dl.a9t9.com/ocr/solarcell.jpg', 'sample image should be sent in the request');
            return ['http_code' => 200, 'error' => '', 'body' => '{"ParsedResults":[{"ParsedText":"Solar cell"}]}'];
        };
        $manager = ocrTestManagerWithTransport($transport);
        $manager->saveKey('health-test-key');
        $result = $manager->testEnabledKeys();
        ocrAssert(($result['success'] ?? false) === true, 'sample OCR text should confirm health');
    },
    'quota failure falls back once and stops at first success' => static function (): void {
        $manager = ocrTestManager();
        $firstId = $manager->saveKey('first-key', null, 'First');
        $secondId = $manager->saveKey('second-key', null, 'Second');
        $attempted = [];
        $result = $manager->withKeyFailover(static function (string $key, int $id) use (&$attempted): array {
            $attempted[] = $id;
            return $key === 'first-key'
                ? ['http_code' => 200, 'body' => '{"IsErroredOnProcessing":true,"ErrorMessage":"Monthly quota exceeded"}']
                : ['http_code' => 200, 'success' => true, 'body' => '{"ParsedResults":[{"ParsedText":"OK"}]}'];
        });
        ocrAssert($attempted === [$firstId, $secondId], 'each enabled key should be attempted once');
        ocrAssert(($result['success'] ?? false) === true && ($result['attempts'] ?? null) === 2, 'fallback success should return with bounded attempt count');
    },
    'explicit provider quota error overrides HTTP-level success for retry' => static function (): void {
        $manager = ocrTestManager();
        $manager->saveKey('quota-first-key');
        $manager->saveKey('quota-second-key');
        $attempted = 0;
        $result = $manager->withKeyFailover(static function (string $key) use (&$attempted): array {
            $attempted++;
            return $attempted === 1
                ? ['http_code' => 200, 'success' => true, 'body' => '{"IsErroredOnProcessing":true,"ErrorMessage":"Monthly quota exceeded"}']
                : ['http_code' => 200, 'success' => true, 'body' => '{"ParsedResults":[{"ParsedText":"valid"}]}'];
        });
        ocrAssert($attempted === 2 && !empty($result['success']), 'explicit quota message must trigger fallback despite HTTP 200');
    },
    'generic OCR and transport failures do not trigger fallback' => static function (): void {
        $manager = ocrTestManager();
        $manager->saveKey('first-key');
        $manager->saveKey('second-key');
        $attempted = 0;
        $result = $manager->withKeyFailover(static function (string $key, int $id) use (&$attempted): array {
            $attempted++;
            return ['http_code' => 200, 'body' => '{"IsErroredOnProcessing":true,"ErrorMessage":"Unable to parse image"}'];
        });
        ocrAssert($attempted === 1, 'generic parse errors must not retry another key');
        ocrAssert(($result['failure_category'] ?? '') === 'document_error', 'generic parse error should be described without claiming key failure');
    },
    'TLS error does not fall back to another key' => static function (): void {
        $manager = ocrTestManager();
        $manager->saveKey('first-key');
        $manager->saveKey('second-key');
        $attempted = 0;
        $result = $manager->withKeyFailover(static function (string $key, int $id) use (&$attempted): array {
            $attempted++;
            return ['http_code' => 0, 'curl_error' => 'SSL certificate verify failed', 'body' => ''];
        });
        ocrAssert($attempted === 1, 'TLS failure must not retry with another API key');
        ocrAssert(($result['failure_category'] ?? '') === 'transport_error', 'TLS failure should remain a transport error');
    },
    'configured key bootstrap is idempotent and key actions honor enabled state' => static function (): void {
        $manager = ocrTestManager();
        $first = $manager->importConfiguredKeyOnce('legacy-config-key');
        $again = $manager->importConfiguredKeyOnce('legacy-config-key');
        ocrAssert($first === $again, 'repeated import should reuse the existing key record');
        ocrAssert(count($manager->listKeys()) === 1, 'bootstrap must not duplicate the configured key');
        $manager->setEnabled((int)$first, false);
        ocrAssert($manager->listKeys(true) === [], 'disabled keys must not be returned as active');
        $manager->setEnabled((int)$first, true);
        ocrAssert(count($manager->listKeys(true)) === 1, 'enabled key should return to active list');
        $manager->removeKey((int)$first);
        ocrAssert($manager->listKeys() === [], 'removed key should not remain in storage');
    },
    'removed legacy key is not reimported after one-time bootstrap' => static function (): void {
        $manager = ocrTestManager();
        $id = $manager->importConfiguredKeyOnce('remove-me-legacy-key');
        $manager->removeKey((int)$id);
        ocrAssert($manager->importConfiguredKeyOnce('remove-me-legacy-key') === null, 'legacy bootstrap should not run again after deletion');
        ocrAssert($manager->listKeys() === [], 'deleted legacy key must stay deleted');
    },
    'successful scans rotate the first key between requests' => static function (): void {
        $manager = ocrTestManager();
        $firstId = $manager->saveKey('round-robin-one');
        $secondId = $manager->saveKey('round-robin-two');
        $starts = [];
        foreach ([1, 2] as $_) {
            $manager->withKeyFailover(static function (string $key, int $id) use (&$starts): array {
                $starts[] = $id;
                return ['success' => true, 'http_code' => 200, 'body' => '{"ParsedResults":[{"ParsedText":"ok"}]}'];
            });
        }
        ocrAssert($starts === [$firstId, $secondId], 'round-robin should advance the first attempted key between calls');
    },
    'failed scans rotate the first attempted key on the next request' => static function (): void {
        $manager = ocrTestManager();
        $firstId = $manager->saveKey('failed-round-robin-one');
        $secondId = $manager->saveKey('failed-round-robin-two');
        $attempts = [];
        foreach ([1, 2] as $_) {
            $manager->withKeyFailover(static function (string $key, int $id) use (&$attempts): array {
                $attempts[] = $id;
                return ['http_code' => 401, 'body' => '{}'];
            });
        }
        ocrAssert($attempts === [$firstId, $secondId, $secondId, $firstId], 'next scan should begin after the previous starting key even when all keys fail');
    },
    'disabled fallback key is rechecked before the next attempt' => static function (): void {
        $manager = ocrTestManager();
        $manager->saveKey('disable-race-first');
        $secondId = $manager->saveKey('disable-race-second');
        $attempted = 0;
        $result = $manager->withKeyFailover(static function (string $key) use ($manager, $secondId, &$attempted): array {
            $attempted++;
            $manager->setEnabled($secondId, false);
            return ['http_code' => 401, 'body' => '{}'];
        });
        ocrAssert($attempted === 1 && ($result['attempts'] ?? null) === 1, 'disabled snapshot key must not be attempted as fallback');
    },
    'replacement key is reloaded before a fallback attempt' => static function (): void {
        $manager = ocrTestManager();
        $manager->saveKey('replace-race-first');
        $secondId = $manager->saveKey('replace-race-old');
        $fallbackKey = null;
        $manager->withKeyFailover(static function (string $key, int $id) use ($manager, $secondId, &$fallbackKey): array {
            if ($key === 'replace-race-first') {
                $manager->saveKey('replace-race-new', $secondId, 'Replacement');
                return ['http_code' => 401, 'body' => '{}'];
            }
            $fallbackKey = $key;
            return ['success' => true, 'http_code' => 200, 'body' => '{}'];
        });
        ocrAssert($fallbackKey === 'replace-race-new', 'fallback must use newly replaced key ciphertext');
    },
    'blank and overlong provider keys are rejected' => static function (): void {
        $manager = ocrTestManager();
        foreach (['', str_repeat('k', 513)] as $key) {
            try {
                $manager->saveKey($key);
            } catch (InvalidArgumentException) {
                continue;
            }
            throw new RuntimeException('invalid API key input must be rejected');
        }
        ocrAssert($manager->listKeys() === [], 'invalid keys must not be persisted');
    },
    'management action policy requires admin POST and valid CSRF' => static function (): void {
        ocrAssert(ems_ocr_key_action_is_allowed(true, 'POST', true), 'authorized post with valid CSRF should pass');
        ocrAssert(!ems_ocr_key_action_is_allowed(false, 'POST', true), 'non-admin must not manage keys');
        ocrAssert(!ems_ocr_key_action_is_allowed(true, 'POST', false), 'invalid CSRF must be rejected');
        ocrAssert(!ems_ocr_key_action_is_allowed(true, 'GET', true), 'state changes must not use GET');
    },
];

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}

echo sprintf("%d/%d tests passed.\n", count($tests) - $failures, count($tests));
exit($failures === 0 ? 0 : 1);
